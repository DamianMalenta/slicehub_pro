<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order History (RFC-001, Faza 1)
// POST /api/orders/history.php
//
// Read-only lista wszystkich zamówień tenanta (w tym completed/cancelled)
// z filtrami, paginacją i sortowaniem. Zero mutacji — czysty SELECT.
//
// Kontrakt: RFC_001 §4.1. RBAC: owner | admin | manager.
// Agreguje: sh_orders + sh_order_lines (count) + sh_order_audit (last action).
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Kanoniczne etykiety PL dla statusów zamówienia (RFC §3.2 — subset dla listy).
 * Używane do `last_action_label` na podstawie sh_order_audit.new_status.
 */
const HIST_STATUS_LABELS = [
    'new'          => 'Utworzono zamówienie',
    'accepted'     => 'Zaakceptowano zamówienie',
    'preparing'    => 'Rozpoczęto przygotowanie',
    'ready'        => 'Zamówienie gotowe',
    'dispatched'   => 'Wysłano dostawcą',
    'in_delivery'  => 'W trakcie dostawy',
    'delivered'    => 'Dostarczone',
    'completed'    => 'Zamówienie zakończone',
    'cancelled'    => 'Zamówienie anulowane',
];

/** Whitelist dozwolonych statusów (zapobiega SQL injection przez filtr). */
const HIST_VALID_STATUS = [
    'new', 'accepted', 'preparing', 'ready',
    'dispatched', 'in_delivery', 'delivered',
    'completed', 'cancelled',
];

/** Kanoniczny słownik form płatności (RFC §4.1). */
const HIST_VALID_PAY = ['to_pay', 'online_unpaid', 'cash', 'card', 'online_paid'];

/** Whitelist pól sortowania. */
const HIST_VALID_SORT = ['created_at', 'grand_total', 'status'];

function histOut(bool $ok, $data = null, ?string $msg = null, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        ['success' => $ok, 'data' => $data, 'message' => $msg],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function histLoadRole(PDO $pdo, int $tid, int $uid): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $uid, ':tid' => $tid]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // ── RBAC: owner / admin / manager ────────────────────────────────
    $role = histLoadRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        histOut(false, null, 'Forbidden: wymagana rola owner/admin/manager.', 403);
    }

    // ── Parse input ───────────────────────────────────────────────────
    $raw    = file_get_contents('php://input') ?: '{}';
    $input  = json_decode($raw, true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'list') {
        histOut(false, null, 'Nieznana akcja. Obsługiwana: "list".', 400);
    }

    $filters    = is_array($input['filters'] ?? null) ? $input['filters'] : [];
    $pagination = is_array($input['pagination'] ?? null) ? $input['pagination'] : [];
    $sort       = is_array($input['sort'] ?? null) ? $input['sort'] : [];

    // ── Build WHERE clauses (parameterized) ───────────────────────────
    $where = ['o.tenant_id = :tid'];
    $params = [':tid' => $tenant_id];

    // date_from / date_to (ISO date → datetime range)
    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    $dateTo   = trim((string)($filters['date_to'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'o.created_at >= :df';
        $params[':df'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'o.created_at <= :dt';
        $params[':dt'] = $dateTo . ' 23:59:59';
    }

    // status[] — whitelist filtr
    $statuses = $filters['status'] ?? null;
    if (is_array($statuses) && $statuses) {
        $clean = array_values(array_filter(
            array_map('strval', $statuses),
            fn ($s) => in_array($s, HIST_VALID_STATUS, true)
        ));
        if ($clean) {
            $ph = [];
            foreach ($clean as $i => $s) {
                $k = ':st' . $i;
                $ph[] = $k;
                $params[$k] = $s;
            }
            $where[] = 'o.status IN (' . implode(',', $ph) . ')';
        }
    }

    // order_type[]
    $types = $filters['order_type'] ?? null;
    if (is_array($types) && $types) {
        $clean = array_values(array_filter(array_map('strval', $types), fn ($s) => $s !== ''));
        if ($clean) {
            $ph = [];
            foreach ($clean as $i => $s) {
                $k = ':ot' . $i;
                $ph[] = $k;
                $params[$k] = $s;
            }
            $where[] = 'o.order_type IN (' . implode(',', $ph) . ')';
        }
    }

    // payment_status[] — kanoniczny słownik
    $pays = $filters['payment_status'] ?? null;
    if (is_array($pays) && $pays) {
        $clean = array_values(array_filter(
            array_map('strval', $pays),
            fn ($s) => in_array($s, HIST_VALID_PAY, true)
        ));
        if ($clean) {
            $ph = [];
            foreach ($clean as $i => $s) {
                $k = ':ps' . $i;
                $ph[] = $k;
                $params[$k] = $s;
            }
            $where[] = 'o.payment_status IN (' . implode(',', $ph) . ')';
        }
    }

    // source[]
    $sources = $filters['source'] ?? null;
    if (is_array($sources) && $sources) {
        $clean = array_values(array_filter(array_map('strval', $sources), fn ($s) => $s !== ''));
        if ($clean) {
            $ph = [];
            foreach ($clean as $i => $s) {
                $k = ':src' . $i;
                $ph[] = $k;
                $params[$k] = $s;
            }
            $where[] = 'o.source IN (' . implode(',', $ph) . ')';
        }
    }

    // search — order_number, customer_name, customer_phone
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(o.order_number LIKE :sq OR o.customer_name LIKE :sq OR o.customer_phone LIKE :sq)';
        $params[':sq'] = '%' . $search . '%';
    }

    // fiscalized: true = tylko z fiscal_receipt_number, false = bez, null/omitted = wszystkie
    $fiscalized = $filters['fiscalized'] ?? null;
    if ($fiscalized === true) {
        $where[] = 'o.fiscal_receipt_number IS NOT NULL';
    } elseif ($fiscalized === false) {
        $where[] = 'o.fiscal_receipt_number IS NULL';
    }

    $whereSql = implode(' AND ', $where);

    // ── Sort (whitelist field, whitelist dir) ─────────────────────────
    $sortField = (string)($sort['field'] ?? 'created_at');
    if (!in_array($sortField, HIST_VALID_SORT, true)) {
        $sortField = 'created_at';
    }
    $sortDir = strtolower((string)($sort['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    // Status sort — porządek lifecycle, nie alfabetyczny
    if ($sortField === 'status') {
        $sortExpr = "CASE o.status "
            . "WHEN 'new' THEN 1 WHEN 'accepted' THEN 2 WHEN 'preparing' THEN 3 "
            . "WHEN 'ready' THEN 4 WHEN 'dispatched' THEN 5 WHEN 'in_delivery' THEN 6 "
            . "WHEN 'delivered' THEN 7 WHEN 'completed' THEN 8 WHEN 'cancelled' THEN 9 "
            . "ELSE 10 END";
    } else {
        $sortExpr = 'o.' . $sortField;
    }

    // ── Pagination ────────────────────────────────────────────────────
    $page    = max(1, (int)($pagination['page'] ?? 1));
    $perPage = (int)($pagination['per_page'] ?? 50);
    if ($perPage < 1) {
        $perPage = 50;
    }
    if ($perPage > 200) {
        $perPage = 200;
    }
    $offset = ($page - 1) * $perPage;

    // ── Feature-detect: is_corrected column (Faza 3 migracja 068) ─────
    // Faza 1 nie dodaje kolumny — zwracamy false dopóki nie istnieje.
    $hasIsCorrected = false;
    try {
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_orders'
               AND COLUMN_NAME = 'is_corrected'"
        );
        $colCheck->execute();
        $hasIsCorrected = ((int)$colCheck->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        // information_schema niedostępny — fallback na false
    }

    // ── Count total ───────────────────────────────────────────────────
    $countSql = "SELECT COUNT(*) FROM sh_orders o WHERE {$whereSql}";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
    if ($totalPages < 1) {
        $totalPages = 1;
    }

    // ── Select page ───────────────────────────────────────────────────
    $isCorrectedSelect = $hasIsCorrected ? 'o.is_corrected' : '0';

    $sql = "SELECT o.id, o.order_number, o.status, o.order_type, o.channel, o.source,
                   o.payment_status, o.payment_method, o.grand_total,
                   o.customer_name, o.customer_phone, o.delivery_address,
                   o.fiscal_receipt_number, o.receipt_printed,
                   {$isCorrectedSelect} AS is_corrected,
                   o.created_at, o.updated_at,
                   (SELECT COUNT(*) FROM sh_order_lines ol WHERE ol.order_id = o.id) AS line_count,
                   (SELECT a.new_status FROM sh_order_audit a
                    WHERE a.order_id = o.id ORDER BY a.timestamp DESC, a.id DESC LIMIT 1) AS last_status,
                   (SELECT a.timestamp FROM sh_order_audit a
                    WHERE a.order_id = o.id ORDER BY a.timestamp DESC, a.id DESC LIMIT 1) AS last_action_ts
            FROM sh_orders o
            WHERE {$whereSql}
            ORDER BY {$sortExpr} {$sortDir}, o.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Map to response DTO (RFC §4.1) ────────────────────────────────
    $orders = [];
    foreach ($rows as $r) {
        $lastStatus = (string)($r['last_status'] ?? $r['status'] ?? '');
        $orders[] = [
            'id'                     => $r['id'],
            'order_number'           => $r['order_number'],
            'status'                 => $r['status'],
            'order_type'             => $r['order_type'],
            'channel'                => $r['channel'],
            'source'                 => $r['source'],
            'payment_status'         => $r['payment_status'],
            'payment_method'         => $r['payment_method'],
            'grand_total_formatted'  => number_format(((int)$r['grand_total']) / 100, 2, '.', ''),
            'customer_name'          => $r['customer_name'],
            'customer_phone'         => $r['customer_phone'],
            'fiscal_receipt_number'  => $r['fiscal_receipt_number'],
            'receipt_printed'        => ((int)$r['receipt_printed']) === 1,
            'is_corrected'           => ((int)$r['is_corrected']) === 1,
            'line_count'             => (int)$r['line_count'],
            'created_at'             => $r['created_at'],
            'updated_at'             => $r['updated_at'],
            'last_action_label'      => HIST_STATUS_LABELS[$lastStatus] ?? ($lastStatus !== '' ? $lastStatus : null),
            'last_action_ts'         => $r['last_action_ts'],
        ];
    }

    histOut(true, [
        'orders'     => $orders,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (PDOException $e) {
    error_log('[orders/history] PDOException: ' . $e->getMessage());
    histOut(false, null, 'Błąd bazy danych.', 500);
} catch (Throwable $e) {
    error_log('[orders/history] ' . $e->getMessage());
    histOut(false, null, 'Błąd serwera.', 500);
}
