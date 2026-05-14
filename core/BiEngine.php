<?php

declare(strict_types=1);

/**
 * BiEngine — read-only agregacja P&L (przychód, VAT, COGS, labor, OPEX).
 *
 * Konwencje pieniądza:
 *   - sh_orders.grand_total, sh_order_lines.vat_amount, sh_payroll_ledger.amount_minor,
 *     sh_ksef_invoice_lines.line_net_minor — natywnie w groszach (INT / BIGINT).
 *   - wh_document_lines.line_net_value — DECIMAL PLN (2 miejsca), snapshot z WzEngine;
 *     silnik magazynowy liczy koszt jako qty × AVCO w PLN; BiEngine konwertuje sumę do groszy
 *     przez zaokrąlenie całkowitej sumy × 100 (nie „mnożenie stawek VAT” ani ułamki dziesiętne w logice P&L).
 *
 * COGS: suma linii dokumentów WZ, jeden dokument na zamówienie (MAX(id) — mitigacja duplikatów WZ).
 * Przychód: status zamknięty sprzedażowo = `completed` (OrderStateMachine), data = created_at zamówienia.
 */
final class BiEngine
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function generateDashboard(
        PDO $pdo,
        int $tenantId,
        string $startDate,
        string $endDate
    ): array {
        self::assertValidYmd($startDate);
        self::assertValidYmd($endDate);
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start_date must be on or before end_date.');
        }

        require_once __DIR__ . '/PayrollLedger.php';

        $startDt = $startDate . ' 00:00:00';
        $endExclusive = (new DateTimeImmutable($endDate . ' 00:00:00'))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');

        $grossRevenueMinor = self::fetchGrossRevenueMinor($pdo, $tenantId, $startDt, $endExclusive);
        $outputVatMinor    = self::fetchOutputVatMinor($pdo, $tenantId, $startDt, $endExclusive);
        $cogsMinor         = self::fetchCogsMinor($pdo, $tenantId, $startDt, $endExclusive);
        $laborMinor        = self::fetchLaborMinor($pdo, $tenantId, $startDt, $endExclusive);
        $opexRows          = self::fetchOpexByCategory($pdo, $tenantId, $startDt, $endExclusive);

        $opexMinor = 0;
        foreach ($opexRows as $r) {
            $opexMinor += (int) $r['total_net_minor'];
        }

        $netSalesMinor = $grossRevenueMinor - $outputVatMinor;
        $primeCostMinor = $cogsMinor + $laborMinor;
        $operatingProfitMinor = $netSalesMinor - $cogsMinor - $laborMinor - $opexMinor;

        $primeCostPctBp = self::ratioToBasisPoints($netSalesMinor, $primeCostMinor);
        $opexPctNetBp   = self::ratioToBasisPoints($netSalesMinor, $opexMinor);
        $operatingMarginBp = self::ratioToBasisPoints($netSalesMinor, $operatingProfitMinor);

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
            'gross_revenue_minor'    => $grossRevenueMinor,
            'output_vat_minor'       => $outputVatMinor,
            'net_sales_minor'      => $netSalesMinor,
            'cogs_minor'           => $cogsMinor,
            'labor_minor'          => $laborMinor,
            'opex_minor'           => $opexMinor,
            'prime_cost_minor'     => $primeCostMinor,
            'operating_profit_minor' => $operatingProfitMinor,
            'prime_cost_pct_bp'    => $primeCostPctBp,
            'opex_pct_net_sales_bp' => $opexPctNetBp,
            'operating_margin_bp'  => $operatingMarginBp,
            'opex_by_category'     => $opexRows,
            'capital_flow'         => self::buildCapitalFlow(
                $netSalesMinor,
                $cogsMinor,
                $laborMinor,
                $opexMinor,
                $operatingProfitMinor
            ),
        ];
    }

    private static function assertValidYmd(string $d): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            throw new InvalidArgumentException('Invalid date format; expected YYYY-MM-DD.');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($dt === false || $dt->format('Y-m-d') !== $d) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }
    }

    private static function fetchGrossRevenueMinor(
        PDO $pdo,
        int $tenantId,
        string $startDt,
        string $endExclusive
    ): int {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(o.grand_total), 0) AS v
               FROM sh_orders o
              WHERE o.tenant_id = :tid
                AND o.status = :st_completed
                AND o.created_at >= :start
                AND o.created_at < :end_ex'
        );
        $st->execute([
            ':tid'          => $tenantId,
            ':st_completed' => 'completed',
            ':start'        => $startDt,
            ':end_ex'       => $endExclusive,
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * VAT należny ze sprzedaży: suma sh_order_lines.vat_amount dla linii zamówień zamkniętych w kohortcie daty nagłówka.
     */
    private static function fetchOutputVatMinor(
        PDO $pdo,
        int $tenantId,
        string $startDt,
        string $endExclusive
    ): int {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(ol.vat_amount), 0) AS v
               FROM sh_order_lines ol
               JOIN sh_orders o ON o.id = ol.order_id AND o.tenant_id = :tid
              WHERE o.tenant_id = :tid2
                AND o.status = :st_completed
                AND o.created_at >= :start
                AND o.created_at < :end_ex'
        );
        $st->execute([
            ':tid'           => $tenantId,
            ':tid2'          => $tenantId,
            ':st_completed'  => 'completed',
            ':start'         => $startDt,
            ':end_ex'        => $endExclusive,
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * COGS z WZ: line_net_value w PLN (2 dec.) → grosze po SUM (jeden WZ na order_id w kohortcie).
     */
    private static function fetchCogsMinor(
        PDO $pdo,
        int $tenantId,
        string $startDt,
        string $endExclusive
    ): int {
        $st = $pdo->prepare(
            'SELECT CAST(ROUND(COALESCE(SUM(wdl.line_net_value), 0) * 100) AS SIGNED) AS cogs_minor
               FROM wh_document_lines wdl
               JOIN wh_documents wd ON wd.id = wdl.document_id AND wd.tenant_id = :tid
               JOIN (
                    SELECT wd2.order_id, MAX(wd2.id) AS doc_id
                      FROM wh_documents wd2
                      JOIN sh_orders o2 ON o2.id = wd2.order_id AND o2.tenant_id = wd2.tenant_id
                     WHERE wd2.tenant_id = :tid_sub
                       AND wd2.type = :wz
                       AND wd2.order_id IS NOT NULL
                       AND o2.tenant_id = :tid_sub2
                       AND o2.status = :st_completed
                       AND o2.created_at >= :start
                       AND o2.created_at < :end_ex
                  GROUP BY wd2.order_id
               ) pick ON pick.doc_id = wd.id'
        );
        $st->execute([
            ':tid'          => $tenantId,
            ':tid_sub'      => $tenantId,
            ':tid_sub2'     => $tenantId,
            ':wz'           => 'WZ',
            ':st_completed' => 'completed',
            ':start'        => $startDt,
            ':end_ex'       => $endExclusive,
        ]);

        return (int) $st->fetchColumn();
    }

    private static function fetchLaborMinor(
        PDO $pdo,
        int $tenantId,
        string $startDt,
        string $endExclusive
    ): int {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(pl.amount_minor), 0) AS v
               FROM sh_payroll_ledger pl
              WHERE pl.tenant_id = :tid
                AND pl.entry_type = :etype
                AND pl.created_at >= :start
                AND pl.created_at < :end_ex'
        );
        $st->execute([
            ':tid'     => $tenantId,
            ':etype'   => PayrollLedger::TYPE_WORK_EARNINGS,
            ':start'   => $startDt,
            ':end_ex'  => $endExclusive,
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * @return list<array{expense_category_id: int|null, category_name: string, total_net_minor: int}>
     */
    private static function fetchOpexByCategory(
        PDO $pdo,
        int $tenantId,
        string $startDt,
        string $endExclusive
    ): array {
        $st = $pdo->prepare(
            'SELECT l.expense_category_id AS cid,
                    MAX(COALESCE(ec.name, :uncat)) AS category_name,
                    CAST(COALESCE(SUM(l.line_net_minor), 0) AS SIGNED) AS total_net_minor
               FROM sh_ksef_invoice_lines l
               JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id AND i.tenant_id = :tid
          LEFT JOIN sh_expense_categories ec
                 ON ec.id = l.expense_category_id AND ec.tenant_id = :tid2
              WHERE i.tenant_id = :tid3
                AND i.status = :accepted
                AND COALESCE(l.line_type, :inv) = :exp
                AND i.processed_at IS NOT NULL
                AND i.processed_at >= :start
                AND i.processed_at < :end_ex
           GROUP BY l.expense_category_id
           ORDER BY total_net_minor DESC, category_name ASC'
        );
        $st->execute([
            ':uncat'    => 'Niesklasyfikowane',
            ':tid'      => $tenantId,
            ':tid2'     => $tenantId,
            ':tid3'     => $tenantId,
            ':accepted' => 'accepted',
            ':inv'      => 'INVENTORY',
            ':exp'      => 'EXPENSE',
            ':start'    => $startDt,
            ':end_ex'   => $endExclusive,
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
     * Uproszczona drabinka kapitału (wartości w groszach).
     * `balance_minor` — saldo po kroku (przychód netto → po kolejnych odjęciach).
     *
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
        $afterLabor  = $grossMargin - $labor;

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
