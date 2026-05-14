<?php

declare(strict_types=1);

/**
 * BiEngine — P&L dashboard (operational BI).
 *
 * All aggregates are returned in **minor units (PLN grosze, signed INT)** internally.
 * COGS: `wh_document_lines.line_net_value` is DECIMAL (PLN); converted via SUM(ROUND(line_net_value * 100)).
 * Multiple WZ documents per order_id are all included (no MIN(id) / LIMIT 1).
 *
 * Cross-silo joins: wh_documents.order_id → sh_orders.id with tenant_id on both sides.
 */
final class BiEngine
{
    private const DATE_RX = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @return array{
     *   period: array{date_from: string, date_to: string, start_ts: string, end_ts: string},
     *   net_sales_minor: int,
     *   cogs_minor: int,
     *   labor_minor: int,
     *   opex_minor: int,
     *   gross_profit_minor: int,
     *   operating_profit_minor: int
     * }
     */
    public static function generateDashboard(PDO $pdo, int $tenantId, string $dateFromYmd, string $dateToYmd): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenant_id must be positive.');
        }
        $from = trim($dateFromYmd);
        $to = trim($dateToYmd);
        if ($from === '' || $to === '') {
            throw new InvalidArgumentException('date_from and date_to are required (YYYY-MM-DD).');
        }
        if (!preg_match(self::DATE_RX, $from) || !preg_match(self::DATE_RX, $to)) {
            throw new InvalidArgumentException('Invalid date format; use YYYY-MM-DD.');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('date_from must be on or before date_to.');
        }

        $startTs = $from . ' 00:00:00';
        $endTs = $to . ' 23:59:59';

        $netSales = self::aggregateNetSalesMinor($pdo, $tenantId, $startTs, $endTs);
        $cogs = self::aggregateCogsMinor($pdo, $tenantId, $startTs, $endTs);
        $labor = self::aggregateLaborMinor($pdo, $tenantId, $startTs, $endTs);
        $opex = self::aggregateOpexMinor($pdo, $tenantId, $startTs, $endTs);

        $grossProfit = $netSales - $cogs;
        $operatingProfit = $grossProfit - $labor - $opex;

        return [
            'period' => [
                'date_from' => $from,
                'date_to'   => $to,
                'start_ts'  => $startTs,
                'end_ts'    => $endTs,
            ],
            'net_sales_minor'        => $netSales,
            'cogs_minor'             => $cogs,
            'labor_minor'            => $labor,
            'opex_minor'             => $opex,
            'gross_profit_minor'     => $grossProfit,
            'operating_profit_minor' => $operatingProfit,
        ];
    }

    /**
     * Net sales = SUM(grand_total) − SUM(line VAT) for completed orders in the window (sh_orders / sh_order_lines in grosze).
     */
    private static function aggregateNetSalesMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sqlGross = <<<'SQL'
SELECT COALESCE(SUM(o.grand_total), 0) AS v
FROM sh_orders o
WHERE o.tenant_id = :tid
  AND o.status = 'completed'
  AND o.updated_at >= :start_ts
  AND o.updated_at <= :end_ts
SQL;
        $st = $pdo->prepare($sqlGross);
        $st->execute([
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);
        $gross = (int) $st->fetchColumn();

        $sqlVat = <<<'SQL'
SELECT COALESCE(SUM(ol.vat_amount), 0) AS v
FROM sh_order_lines ol
INNER JOIN sh_orders o ON o.id = ol.order_id AND o.tenant_id = :tid_join
WHERE o.tenant_id = :tid
  AND o.status = 'completed'
  AND o.updated_at >= :start_ts
  AND o.updated_at <= :end_ts
SQL;
        $st2 = $pdo->prepare($sqlVat);
        $st2->execute([
            ':tid_join' => $tenantId,
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);
        $vat = (int) $st2->fetchColumn();

        return $gross - $vat;
    }

    /**
     * COGS: all WZ line_net_value for WZ documents tied to completed orders in the window.
     */
    private static function aggregateCogsMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(ROUND(wdl.line_net_value * 100)), 0) AS v
FROM wh_document_lines wdl
INNER JOIN wh_documents wd
  ON wd.id = wdl.document_id AND wd.tenant_id = :tid_wd
INNER JOIN sh_orders o
  ON o.id = wd.order_id AND o.tenant_id = :tid_ord
WHERE wd.tenant_id = :tid_wd2
  AND wd.type = 'WZ'
  AND wd.order_id IS NOT NULL
  AND o.status = 'completed'
  AND o.updated_at >= :start_ts
  AND o.updated_at <= :end_ts
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tid_wd'   => $tenantId,
            ':tid_ord' => $tenantId,
            ':tid_wd2' => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        return (int) $st->fetchColumn();
    }

    private static function aggregateLaborMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(pl.amount_minor), 0) AS v
FROM sh_payroll_ledger pl
WHERE pl.tenant_id = :tid
  AND pl.entry_type = 'work_earnings'
  AND pl.created_at >= :start_ts
  AND pl.created_at <= :end_ts
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * OPEX: accepted KSeF invoice lines classified as EXPENSE (grosze in line_net_minor).
     */
    private static function aggregateOpexMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(l.line_net_minor), 0) AS v
FROM sh_ksef_invoice_lines l
INNER JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id AND i.tenant_id = :tid_join
WHERE i.tenant_id = :tid
  AND i.status = 'accepted'
  AND l.line_type = 'EXPENSE'
  AND COALESCE(i.processed_at, i.updated_at) >= :start_ts
  AND COALESCE(i.processed_at, i.updated_at) <= :end_ts
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tid_join' => $tenantId,
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        return (int) $st->fetchColumn();
    }
}
