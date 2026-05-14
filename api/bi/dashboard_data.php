<?php

declare(strict_types=1);

/**
 * GET /api/bi/dashboard_data.php?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 * Agregat P&L (BI) — cztery niezależne zapytania (Frontline, Supply Chain, HR, Procurement),
 * łączenie matematyczne w PHP (DDD / izolacja silosów — brak JOIN między sh_* a wh_*).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array{start_d: string, end_d: string, end_exclusive_d: string}
 */
function biParseDateRange(string $startRaw, string $endRaw): array
{
    $startRaw = trim($startRaw);
    $endRaw = trim($endRaw);
    if ($startRaw === '' || $endRaw === '') {
        throw new InvalidArgumentException('Wymagane parametry: start_date i end_date (YYYY-MM-DD).');
    }
    $s = DateTimeImmutable::createFromFormat('!Y-m-d', $startRaw);
    $e = DateTimeImmutable::createFromFormat('!Y-m-d', $endRaw);
    if (!$s || !$e) {
        throw new InvalidArgumentException('Nieprawidłowy format daty — oczekiwano YYYY-MM-DD.');
    }
    if ($s > $e) {
        throw new InvalidArgumentException('start_date nie może być późniejsze niż end_date.');
    }
    $endExclusive = $e->modify('+1 day');

    return [
        'start_d'         => $s->format('Y-m-d'),
        'end_d'           => $e->format('Y-m-d'),
        'end_exclusive_d' => $endExclusive->format('Y-m-d'),
    ];
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $stRole = $pdo->prepare(
        'SELECT LOWER(TRIM(role)) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $stRole->execute([':uid' => $user_id, ':tid' => $tenant_id]);
    $actorRole = $stRole->fetchColumn();
    $actorRole = is_string($actorRole) ? $actorRole : '';
    if (!in_array($actorRole, ['owner', 'admin'], true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Wymagana rola: owner lub admin.',
            'data'    => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $range = biParseDateRange(
        (string)($_GET['start_date'] ?? ''),
        (string)($_GET['end_date'] ?? '')
    );
    $startDate = $range['start_d'];
    $endInclusiveDate = $range['end_d'];
    $endExclusiveDate = $range['end_exclusive_d'];

    $startTs = $startDate . ' 00:00:00';
    $endTsExclusive = $endExclusiveDate . ' 00:00:00';

    // ── 1) Frontline: przychód + prowizje (osobno VAT) ─────────────────────
    $qGross = $pdo->prepare(
        "SELECT COALESCE(SUM(o.grand_total), 0) AS gross_minor,
                COALESCE(SUM(COALESCE(o.commission_amount, 0)), 0) AS commissions_minor
           FROM sh_orders o
          WHERE o.tenant_id = :tid
            AND o.status IN ('completed', 'closed')
            AND o.created_at >= :start_ts
            AND o.created_at < :end_ts"
    );
    $qGross->execute([':tid' => $tenant_id, ':start_ts' => $startTs, ':end_ts' => $endTsExclusive]);
    $grossRow = $qGross->fetch(PDO::FETCH_ASSOC) ?: [];
    $grossRevenue = (int)($grossRow['gross_minor'] ?? 0);
    $commissions = (int)($grossRow['commissions_minor'] ?? 0);

    $qVat = $pdo->prepare(
        "SELECT COALESCE(SUM(l.vat_amount), 0) AS vat_minor
           FROM sh_order_lines l
           INNER JOIN sh_orders o ON o.id = l.order_id AND o.tenant_id = :tid
          WHERE o.tenant_id = :tid
            AND o.status IN ('completed', 'closed')
            AND o.created_at >= :start_ts
            AND o.created_at < :end_ts"
    );
    $qVat->execute([
        ':tid' => $tenant_id,
        ':start_ts' => $startTs,
        ':end_ts' => $endTsExclusive,
    ]);
    $vatAmount = (int)($qVat->fetchColumn());

    $netRevenue = $grossRevenue - $vatAmount;

    // ── 2) Supply Chain: COGS z WZ (tylko wh_) ───────────────────────────────
    $qCogs = $pdo->prepare(
        "SELECT COALESCE(SUM(ROUND(wl.line_net_value * 100)), 0) AS cogs_minor
           FROM wh_document_lines wl
           INNER JOIN wh_documents wd ON wd.id = wl.document_id AND wd.tenant_id = :tid
          WHERE wd.tenant_id = :tid
            AND wd.type = 'WZ'
            AND wd.created_at >= :start_ts
            AND wd.created_at < :end_ts"
    );
    $qCogs->execute([
        ':tid' => $tenant_id,
        ':start_ts' => $startTs,
        ':end_ts' => $endTsExclusive,
    ]);
    $cogs = (int)$qCogs->fetchColumn();

    // ── 3) HR: koszt pracy ───────────────────────────────────────────────────
    $qLabor = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_minor), 0) AS labor_minor
           FROM sh_payroll_ledger
          WHERE tenant_id = :tid
            AND entry_type = 'work_earnings'
            AND created_at >= :start_ts
            AND created_at < :end_ts"
    );
    $qLabor->execute([':tid' => $tenant_id, ':start_ts' => $startTs, ':end_ts' => $endTsExclusive]);
    $laborCost = (int)$qLabor->fetchColumn();

    // ── 4) Procurement: OPEX z linii EXPENSE (tylko sh_) ──────────────────────
    $qOpexLines = $pdo->prepare(
        "SELECT l.line_net_minor AS line_minor,
                l.expense_category_id AS category_id,
                COALESCE(ec.name, 'Bez kategorii') AS category_name
           FROM sh_ksef_invoice_lines l
           INNER JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id AND i.tenant_id = :tid
           LEFT JOIN sh_expense_categories ec
             ON ec.id = l.expense_category_id
            AND ec.tenant_id = :tid
            AND ec.is_deleted = 0
          WHERE i.tenant_id = :tid
            AND l.line_type = 'EXPENSE'
            AND i.status = 'accepted'
            AND COALESCE(i.sale_date, i.issue_date, DATE(i.processed_at), DATE(i.created_at)) >= :start_d
            AND COALESCE(i.sale_date, i.issue_date, DATE(i.processed_at), DATE(i.created_at)) < :end_d_exclusive"
    );
    $qOpexLines->execute([
        ':tid' => $tenant_id,
        ':start_d' => $startDate,
        ':end_d_exclusive' => $endExclusiveDate,
    ]);
    $opexRows = $qOpexLines->fetchAll(PDO::FETCH_ASSOC);

    $opexByCategory = [];
    $opexCost = 0;
    foreach ($opexRows as $row) {
        $minor = (int)($row['line_minor'] ?? 0);
        $opexCost += $minor;
        $cid = $row['category_id'] !== null ? (string)$row['category_id'] : '_none';
        $cname = (string)($row['category_name'] ?? 'Bez kategorii');
        if (!isset($opexByCategory[$cid])) {
            $opexByCategory[$cid] = [
                'category_id'   => $row['category_id'] !== null ? (int)$row['category_id'] : null,
                'category_name' => $cname,
                'total_minor'   => 0,
            ];
        }
        $opexByCategory[$cid]['total_minor'] += $minor;
    }
    $opexByCategoryList = array_values($opexByCategory);
    usort($opexByCategoryList, static fn ($a, $b) => ($b['total_minor'] <=> $a['total_minor']));

    $operatingProfit = $netRevenue - $cogs - $laborCost - $opexCost - $commissions;

    $primeCostPct = 0.0;
    if ($grossRevenue > 0) {
        $primeCostPct = round((($cogs + $laborCost) / $grossRevenue) * 100, 2);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Dane P&L pobrane.',
        'data'    => [
            'period' => [
                'start_date'           => $startDate,
                'end_date'             => $endInclusiveDate,
                'end_exclusive_next'   => $endExclusiveDate,
                'filter_start_ts'      => $startTs,
                'filter_end_ts_excl'   => $endTsExclusive,
            ],
            'amounts_minor' => [
                'gross_revenue'      => $grossRevenue,
                'output_vat'         => $vatAmount,
                'net_revenue'        => $netRevenue,
                'cogs'               => $cogs,
                'labor_cost'         => $laborCost,
                'opex_cost'          => $opexCost,
                'commissions'        => $commissions,
                'operating_profit'   => $operatingProfit,
            ],
            'prime_cost_pct'    => $primeCostPct,
            'opex_by_category'  => $opexByCategoryList,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[bi/dashboard_data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'data' => null], JSON_UNESCAPED_UNICODE);
}
