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
 *
 * **Order date window (P&L):** revenue, VAT, and COGS-from-WZ use `sh_orders.created_at`, not
 * `updated_at`, so late edits (notes, metadata) do not shift revenue across accounting periods.
 *
 * **Stock value (snapshot):** `wh_stock` AVCO valuation in grosze — point-in-time, **not** filtered by the P&L date range.
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
     *   gross_revenue_minor: int,
     *   output_vat_minor: int,
     *   gross_profit_minor: int,
     *   operating_profit_minor: int,
     *   prime_cost_minor: int,
     *   prime_cost_pct_bp: int|null,
     *   opex_pct_net_sales_bp: int|null,
     *   operating_margin_bp: int|null,
     *   opex_by_category: list<array{expense_category_id: int|null, category_name: string, total_net_minor: int}>,
     *   capital_flow: list<array{label: string, delta_minor: int, balance_minor: int}>,
     *   stock_value_minor: int
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

        $grossRevenue = self::aggregateGrossRevenueMinor($pdo, $tenantId, $startTs, $endTs);
        $outputVat = self::aggregateOutputVatMinor($pdo, $tenantId, $startTs, $endTs);
        $netSales = $grossRevenue - $outputVat;
        $cogs = self::aggregateCogsMinor($pdo, $tenantId, $startTs, $endTs);
        $labor = self::aggregateLaborMinor($pdo, $tenantId, $startTs, $endTs);
        $opexRows = self::aggregateOpexByCategory($pdo, $tenantId, $startTs, $endTs);
        $opex = 0;
        foreach ($opexRows as $row) {
            $opex += (int) $row['total_net_minor'];
        }
        $stockValue = self::aggregateStockValueMinor($pdo, $tenantId);

        $grossProfit = $netSales - $cogs;
        $operatingProfit = $grossProfit - $labor - $opex;
        $primeCost = $cogs + $labor;

        return [
            'period' => [
                'date_from' => $from,
                'date_to'   => $to,
                'start_ts'  => $startTs,
                'end_ts'    => $endTs,
            ],
            'gross_revenue_minor'    => $grossRevenue,
            'output_vat_minor'       => $outputVat,
            'net_sales_minor'        => $netSales,
            'cogs_minor'             => $cogs,
            'labor_minor'            => $labor,
            'opex_minor'             => $opex,
            'gross_profit_minor'     => $grossProfit,
            'operating_profit_minor' => $operatingProfit,
            'prime_cost_minor'       => $primeCost,
            'prime_cost_pct_bp'      => self::ratioToBasisPoints($netSales, $primeCost),
            'opex_pct_net_sales_bp'  => self::ratioToBasisPoints($netSales, $opex),
            'operating_margin_bp'    => self::ratioToBasisPoints($netSales, $operatingProfit),
            'opex_by_category'       => $opexRows,
            'capital_flow'           => self::buildCapitalFlow($netSales, $cogs, $labor, $opex, $operatingProfit),
            'stock_value_minor'      => $stockValue,
        ];
    }

    /**
     * Wartość magazynu (AVCO × ilość) w groszach — stan bieżący, bez filtra dat.
     * `current_avco_price` jest DECIMAL (PLN); konwersja jak w COGS: ROUND(...*100).
     */
    private static function aggregateStockValueMinor(PDO $pdo, int $tenantId): int
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(ROUND(quantity * current_avco_price * 100)), 0) AS v
FROM wh_stock
WHERE tenant_id = :tid
  AND quantity > 0
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([':tid' => $tenantId]);

        return (int) $st->fetchColumn();
    }

    private static function aggregateGrossRevenueMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sqlGross = <<<'SQL'
SELECT COALESCE(SUM(o.grand_total), 0) AS v
FROM sh_orders o
WHERE o.tenant_id = :tid
  AND o.status = 'completed'
  AND o.created_at >= :start_ts
  AND o.created_at <= :end_ts
SQL;
        $st = $pdo->prepare($sqlGross);
        $st->execute([
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        return (int) $st->fetchColumn();
    }

    private static function aggregateOutputVatMinor(PDO $pdo, int $tenantId, string $startTs, string $endTs): int
    {
        $sqlVat = <<<'SQL'
SELECT COALESCE(SUM(ol.vat_amount), 0) AS v
FROM sh_order_lines ol
INNER JOIN sh_orders o ON o.id = ol.order_id AND o.tenant_id = :tid_join
WHERE o.tenant_id = :tid
  AND o.status = 'completed'
  AND o.created_at >= :start_ts
  AND o.created_at <= :end_ts
SQL;
        $st = $pdo->prepare($sqlVat);
        $st->execute([
            ':tid_join' => $tenantId,
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        return (int) $st->fetchColumn();
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
  AND o.created_at >= :start_ts
  AND o.created_at <= :end_ts
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
        // Logika daty spójna z PayrollEngine::aggregateLedger — używa
        // COALESCE(ws.start_time, pl.created_at) jako punktu w czasie,
        // nie samego created_at (clock-out time). Bez tego zmiany nocne,
        // sesje splitowane (HR-6) i wpisy deferred dają rozjazd między
        // BI P&L a raportem płacowym.
        $sql = <<<'SQL'
SELECT COALESCE(SUM(pl.amount_minor), 0) AS v
FROM sh_payroll_ledger pl
LEFT JOIN sh_work_sessions ws
       ON ws.id = pl.ref_work_session_id
      AND ws.tenant_id = pl.tenant_id
WHERE pl.tenant_id = :tid
  AND pl.entry_type = 'work_earnings'
  AND COALESCE(ws.start_time, pl.created_at) >= :start_ts
  AND COALESCE(ws.start_time, pl.created_at) <= :end_ts
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
     * OPEX po kategoriach — ten sam filtr dat co suma OPEX (processed_at / updated_at faktury).
     *
     * @return list<array{expense_category_id: int|null, category_name: string, total_net_minor: int}>
     */
    private static function aggregateOpexByCategory(PDO $pdo, int $tenantId, string $startTs, string $endTs): array
    {
        $sql = <<<'SQL'
SELECT l.expense_category_id AS cid,
       MAX(COALESCE(ec.name, :uncat)) AS category_name,
       CAST(COALESCE(SUM(l.line_net_minor), 0) AS SIGNED) AS total_net_minor
  FROM sh_ksef_invoice_lines l
 INNER JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id AND i.tenant_id = :tid_join
  LEFT JOIN sh_expense_categories ec
         ON ec.id = l.expense_category_id AND ec.tenant_id = :tid_ec
 WHERE i.tenant_id = :tid
   AND i.status = 'accepted'
   AND l.line_type = 'EXPENSE'
   AND COALESCE(i.processed_at, i.updated_at) >= :start_ts
   AND COALESCE(i.processed_at, i.updated_at) <= :end_ts
 GROUP BY l.expense_category_id
 ORDER BY total_net_minor DESC, category_name ASC
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([
            ':uncat'    => 'Niesklasyfikowane',
            ':tid_join' => $tenantId,
            ':tid_ec'   => $tenantId,
            ':tid'      => $tenantId,
            ':start_ts' => $startTs,
            ':end_ts'   => $endTs,
        ]);

        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cid = $row['cid'];
            $out[] = [
                'expense_category_id' => $cid !== null ? (int) $cid : null,
                'category_name'       => (string) $row['category_name'],
                'total_net_minor'     => (int) $row['total_net_minor'],
            ];
        }

        return $out;
    }

    private static function ratioToBasisPoints(int $denominatorMinor, int $numeratorMinor): ?int
    {
        if ($denominatorMinor <= 0) {
            return null;
        }

        return (int) round($numeratorMinor * 10000 / $denominatorMinor);
    }

    /**
     * @return list<array{label: string, delta_minor: int, balance_minor: int}>
     */
    private static function buildCapitalFlow(
        int $netSales,
        int $cogs,
        int $labor,
        int $opex,
        int $operatingProfit
    ): array {
        $grossMargin = $netSales - $cogs;
        $afterLabor = $grossMargin - $labor;

        return [
            [
                'label'         => 'Przychód netto (po VAT)',
                'delta_minor'   => $netSales,
                'balance_minor' => $netSales,
            ],
            [
                'label'         => '− COGS (WZ, koszt historyczny)',
                'delta_minor'   => -$cogs,
                'balance_minor' => $grossMargin,
            ],
            [
                'label'         => '− Koszty pracy (payroll ledger)',
                'delta_minor'   => -$labor,
                'balance_minor' => $afterLabor,
            ],
            [
                'label'         => '− OPEX (KSeF, linie EXPENSE)',
                'delta_minor'   => -$opex,
                'balance_minor' => $operatingProfit,
            ],
        ];
    }
}
