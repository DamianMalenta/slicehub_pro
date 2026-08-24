<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Order Audit Timeline (RFC-001, Faza 1)
// POST /api/orders/audit.php
//
// Read-only oś czasu per zamówienie — agregacja w locie z 3 tabel:
//   A) sh_order_audit  — status transitions (old → new)
//   B) sh_order_logs   — structured actions (state_change, payment, ...)
//   C) sh_event_outbox — lifecycle events z pełnymi snapshotami
//
// Zero mutacji, zero nowych tabel. RFC §3.1 (algorytm) & §4.2 (kontrakt).
// RBAC: owner | admin | manager.
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
 * Kanoniczny słownik etykiet PL (RFC §3.2).
 * Klucz = event_type (z outbox) lub action (z logs) lub status (z audit).
 */
const AUDIT_LABELS = [
    // Outbox lifecycle events
    'order.created'       => 'Utworzono zamówienie',
    'order.accepted'      => 'Zaakceptowano zamówienie',
    'order.preparing'     => 'Rozpoczęto przygotowanie',
    'order.ready'         => 'Zamówienie gotowe',
    'order.completed'     => 'Zamówienie zakończone',
    'order.cancelled'     => 'Zamówienie anulowane',
    'order.edited'        => 'Edycja pozycji zamówienia',
    'order.recalled'      => 'Wycofanie z kuchni (recall)',
    'order.dispatched'    => 'Wysłano dostawcą',
    'order.in_delivery'   => 'W trakcie dostawy',
    'order.delivered'     => 'Dostarczone',
    'order.delayed'       => 'Przesunięcie obiecanego czasu',
    'order.fiscalized'    => 'Fiskalizacja zamówienia',
    'payment.settled'     => 'Rozliczenie płatności',
    'payment.refunded'    => 'Zwrot płatności',
    // RFC-001 Faza 2 — edycja metadanych zamkniętych zamówień
    'order.metadata_edited' => 'Edycja metadanych (klient/telefon/adres)',
    'order.payment_changed' => 'Zmiana formy płatności (cash ↔ card)',
    // Logs actions
    'state_change'        => 'Zmiana statusu',
    'payment'             => 'Operacja płatności',
    'payment.change'      => 'Zmiana formy płatności',
    'metadata.edit'       => 'Edycja metadanych (klient/telefon/adres)',
    'force_edit'          => 'Wymuszona korekta pozycji (zamknięte)',
    'reopen'              => 'Ponowne otwarcie zamówienia',
    'revert'              => 'Cofnięcie do stanu ze snapshotu',
    'fiscal.print'        => 'Wydruk paragonu fiskalnego',
    'fiscal.daily_report' => 'Raport dobowy (Z-report)',
    'wh.consume'          => 'Konsumpcja magazynowa (WZ)',
    'wh.reverse'          => 'Korekta magazynowa (KOR)',
    // Audit fallback
    'status_change'       => 'Zmiana statusu',
];

/** Mapowanie new_status → kanoniczny event_type outbox (dla audit rows). */
const AUDIT_STATUS_TO_EVENT = [
    'new'          => 'order.created',
    'accepted'     => 'order.accepted',
    'preparing'    => 'order.preparing',
    'ready'        => 'order.ready',
    'dispatched'   => 'order.dispatched',
    'in_delivery'  => 'order.in_delivery',
    'delivered'    => 'order.delivered',
    'completed'    => 'order.completed',
    'cancelled'    => 'order.cancelled',
];

function auditOut(bool $ok, $data = null, ?string $msg = null, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(
        ['success' => $ok, 'data' => $data, 'message' => $msg],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function auditLoadRole(PDO $pdo, int $tid, int $uid): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $uid, ':tid' => $tid]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

/** Konwersja DB DATETIME string → ISO 8601 z timezone (RFC §3.1 DTO `ts`). */
function auditIsoTs(string $dbTs): string
{
    $ts = strtotime($dbTs);
    return $ts !== false ? date('c', $ts) : $dbTs;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // ── RBAC: owner / admin / manager ────────────────────────────────
    $role = auditLoadRole($pdo, $tenant_id, $user_id);
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        auditOut(false, null, 'Forbidden: wymagana rola owner/admin/manager.', 403);
    }

    // ── Parse input ───────────────────────────────────────────────────
    $raw    = file_get_contents('php://input') ?: '{}';
    $input  = json_decode($raw, true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== 'timeline') {
        auditOut(false, null, 'Nieznana akcja. Obsługiwana: "timeline".', 400);
    }

    $orderId = trim((string)($input['order_id'] ?? ''));
    if ($orderId === '') {
        auditOut(false, null, 'order_id jest wymagane.', 400);
    }

    // ── Verify order belongs to tenant (Prawo VI) ─────────────────────
    $stmtOrder = $pdo->prepare(
        "SELECT id, order_number, status, order_type,
                customer_name, customer_phone, delivery_address, notes,
                payment_status, payment_method,
                fiscal_receipt_number, receipt_printed
         FROM sh_orders
         WHERE id = :id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        auditOut(false, null, 'Zamówienie nie istnieje.', 404);
    }

    // ── Bulk-load actor names (users map) ─────────────────────────────
    // Zbierz wszystkie user_id z 3 źródeł, potem jeden SELECT do sh_users.
    $userIdSet = [];

    // A) sh_order_audit
    $stmtAudit = $pdo->prepare(
        "SELECT id, user_id, old_status, new_status, timestamp
         FROM sh_order_audit
         WHERE order_id = :oid
         ORDER BY timestamp ASC, id ASC"
    );
    $stmtAudit->execute([':oid' => $orderId]);
    $auditRows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
    foreach ($auditRows as $a) {
        if (!empty($a['user_id'])) {
            $userIdSet[(int)$a['user_id']] = true;
        }
    }

    // B) sh_order_logs
    $stmtLogs = $pdo->prepare(
        "SELECT id, user_id, action, detail_json, created_at
         FROM sh_order_logs
         WHERE order_id = :oid AND tenant_id = :tid
         ORDER BY created_at ASC, id ASC"
    );
    $stmtLogs->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $logRows = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logRows as $l) {
        if (!empty($l['user_id'])) {
            $userIdSet[(int)$l['user_id']] = true;
        }
    }

    // C) sh_event_outbox (snapshoty)
    $stmtOutbox = $pdo->prepare(
        "SELECT id, event_type, payload, source, actor_type, actor_id, created_at
         FROM sh_event_outbox
         WHERE aggregate_id = :oid AND tenant_id = :tid
         ORDER BY created_at ASC, id ASC"
    );
    $stmtOutbox->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $outboxRows = $stmtOutbox->fetchAll(PDO::FETCH_ASSOC);
    foreach ($outboxRows as $e) {
        $aid = (string)($e['actor_id'] ?? '');
        if ($aid !== '' && ctype_digit($aid)) {
            $userIdSet[(int)$aid] = true;
        }
    }

    $usersMap = [];
    if ($userIdSet) {
        $ids = array_keys($userIdSet);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtUsers = $pdo->prepare(
            "SELECT id, COALESCE(NULLIF(name, ''), CONCAT_WS(' ', first_name, last_name)) AS display_name
             FROM sh_users WHERE id IN ({$placeholders})"
        );
        $stmtUsers->execute($ids);
        foreach ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $usersMap[(int)$u['id']] = trim((string)$u['display_name']) ?: ('user#' . $u['id']);
        }
    }

    // ── Build unified timeline events ─────────────────────────────────
    $events = [];

    // A) sh_order_audit → status_change events
    foreach ($auditRows as $a) {
        $oldS = (string)($a['old_status'] ?? '');
        $newS = (string)($a['new_status'] ?? '');
        $uid  = !empty($a['user_id']) ? (int)$a['user_id'] : null;

        // Edit.php zapisuje audit z old==new (marker edycji) — mapuj na order.edited
        if ($oldS === $newS && $oldS !== '') {
            $eventType = 'order.edited';
        } else {
            $eventType = AUDIT_STATUS_TO_EVENT[$newS] ?? 'status_change';
        }

        $events[] = [
            'event_id'    => 'audit_' . (int)$a['id'],
            'ts'          => auditIsoTs((string)$a['timestamp']),
            'ts_raw'      => (string)$a['timestamp'],
            'source'      => 'osm',
            'actor_type'  => $uid !== null ? 'staff' : 'system',
            'actor_id'    => $uid,
            'actor_name'  => $uid !== null ? ($usersMap[$uid] ?? null) : 'System',
            'event_type'  => $eventType,
            'label_pl'    => AUDIT_LABELS[$eventType]
                ?? ($oldS !== $newS
                    ? "Zmiana statusu: {$oldS} → {$newS}"
                    : AUDIT_LABELS['order.edited']),
            'old_status'  => $oldS !== '' ? $oldS : null,
            'new_status'  => $newS !== '' ? $newS : null,
            'detail'      => null,
            'snapshot'    => null,
            'delta'       => null,
            '_origin'     => 'audit',
        ];
    }

    // B) sh_order_logs → action events
    foreach ($logRows as $l) {
        $action = (string)($l['action'] ?? '');
        $uid    = !empty($l['user_id']) ? (int)$l['user_id'] : null;
        $detail = null;
        if (!empty($l['detail_json'])) {
            $decoded = json_decode((string)$l['detail_json'], true);
            $detail  = is_array($decoded) ? $decoded : null;
        }

        $label = AUDIT_LABELS[$action] ?? null;
        if ($label === null && $action === 'state_change' && is_array($detail)) {
            $oldS = (string)($detail['old_status'] ?? '');
            $newS = (string)($detail['new_status'] ?? '');
            $label = $oldS !== '' && $newS !== ''
                ? "Zmiana statusu: {$oldS} → {$newS}"
                : AUDIT_LABELS['state_change'];
        }
        if ($label === null) {
            $label = $action !== '' ? $action : 'Zdarzenie';
        }

        $events[] = [
            'event_id'    => 'log_' . (int)$l['id'],
            'ts'          => auditIsoTs((string)$l['created_at']),
            'ts_raw'      => (string)$l['created_at'],
            'source'      => 'system',
            'actor_type'  => $uid !== null ? 'staff' : 'system',
            'actor_id'    => $uid,
            'actor_name'  => $uid !== null ? ($usersMap[$uid] ?? null) : 'System',
            'event_type'  => $action !== '' ? $action : 'log_entry',
            'label_pl'    => $label,
            'old_status'  => is_array($detail) ? ($detail['old_status'] ?? null) : null,
            'new_status'  => is_array($detail) ? ($detail['new_status'] ?? null) : null,
            'detail'      => $detail,
            'snapshot'    => null,
            'delta'       => null,
            '_origin'     => 'logs',
        ];
    }

    // C) sh_event_outbox → lifecycle events z snapshotami
    foreach ($outboxRows as $e) {
        $eventType = (string)($e['event_type'] ?? '');
        $aid       = (string)($e['actor_id'] ?? '');
        $actorId   = ($aid !== '' && ctype_digit($aid)) ? (int)$aid : null;
        $actorType = (string)($e['actor_type'] ?? '');
        $source    = (string)($e['source'] ?? 'internal');

        // Decode payload → split snapshot (header + lines) + delta (_context.kitchen_delta)
        $snapshot = null;
        $delta    = null;
        $detail   = null;
        if (!empty($e['payload'])) {
            $payload = json_decode((string)$e['payload'], true);
            if (is_array($payload)) {
                $context = $payload['_context'] ?? [];
                $meta    = $payload['_meta'] ?? [];

                // Snapshot = header fields + lines (bez _context / _meta)
                $header = $payload;
                unset($header['_context'], $header['_meta']);
                $lines  = $header['lines'] ?? [];
                unset($header['lines']);
                $snapshot = ['header' => $header, 'lines' => $lines];

                $delta  = is_array($context) ? ($context['kitchen_delta'] ?? null) : null;
                $detail = is_array($context) ? $context : null;
                // Usuń kitchen_delta z detail (jest osobno w `delta`)
                if (is_array($detail) && isset($detail['kitchen_delta'])) {
                    unset($detail['kitchen_delta']);
                }
            }
        }

        $label = AUDIT_LABELS[$eventType] ?? ($eventType !== '' ? $eventType : 'Zdarzenie');

        $events[] = [
            'event_id'    => 'evt_' . (int)$e['id'],
            'ts'          => auditIsoTs((string)$e['created_at']),
            'ts_raw'      => (string)$e['created_at'],
            'source'      => $source,
            'actor_type'  => $actorType !== '' ? $actorType : ($actorId !== null ? 'staff' : 'system'),
            'actor_id'    => $actorId,
            'actor_name'  => $actorId !== null ? ($usersMap[$actorId] ?? null) : (
                $actorType === 'external_api' ? $source : 'System'
            ),
            'event_type'  => $eventType !== '' ? $eventType : 'event',
            'label_pl'    => $label,
            'old_status'  => is_array($detail) ? ($detail['old_status'] ?? null) : null,
            'new_status'  => is_array($detail) ? ($detail['new_status'] ?? null) : null,
            'detail'      => $detail,
            'snapshot'    => $snapshot,
            'delta'       => $delta,
            '_origin'     => 'outbox',
        ];
    }

    // ── Sort unified timeline by ts ASC (then by origin priority) ─────
    // Priorytet przy tym samym ts: outbox > logs > audit (outbox ma snapshot).
    $originOrder = ['outbox' => 0, 'logs' => 1, 'audit' => 2];
    usort($events, function ($a, $b) use ($originOrder) {
        if ($a['ts_raw'] !== $b['ts_raw']) {
            return strcmp($a['ts_raw'], $b['ts_raw']);
        }
        $oa = $originOrder[$a['_origin']] ?? 9;
        $ob = $originOrder[$b['_origin']] ?? 9;
        if ($oa !== $ob) {
            return $oa - $ob;
        }
        return strcmp((string)$a['event_id'], (string)$b['event_id']);
    });

    // ── Dedup: przy tym samym ts_raw + tym samym transition, preferuj outbox ─
    // Grupuj po ts_raw; jeśli w grupie jest outbox event odpowiadający audit/logs
    // transition, pomiń audit/logs duplikaty (outbox jest nadrzędny — ma snapshot).
    $deduped = [];
    $count = count($events);
    for ($i = 0; $i < $count; $i++) {
        $ev = $events[$i];
        if ($ev['_origin'] === 'outbox') {
            $deduped[] = $ev;
            continue;
        }
        // Dla audit/logs — sprawdź czy outbox w tej samej grupie ts_raw pokrywa to samo
        $skip = false;
        for ($j = 0; $j < $count; $j++) {
            if ($j === $i) {
                continue;
            }
            $other = $events[$j];
            if ($other['_origin'] !== 'outbox') {
                continue;
            }
            if ($other['ts_raw'] !== $ev['ts_raw']) {
                continue;
            }
            // Outbox pokrywa audit/logs jeśli event_type się zgadza
            if ($other['event_type'] === $ev['event_type']) {
                $skip = true;
                break;
            }
            // audit status_change pokryty przez outbox order.{new_status}
            if ($ev['_origin'] === 'audit'
                && isset(AUDIT_STATUS_TO_EVENT[$ev['new_status'] ?? ''])
                && AUDIT_STATUS_TO_EVENT[$ev['new_status']] === $other['event_type']
            ) {
                $skip = true;
                break;
            }
            // logs state_change pokryty przez outbox order.{new_status}
            if ($ev['_origin'] === 'logs'
                && $ev['event_type'] === 'state_change'
                && isset(AUDIT_STATUS_TO_EVENT[$ev['new_status'] ?? ''])
                && AUDIT_STATUS_TO_EVENT[$ev['new_status']] === $other['event_type']
            ) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $deduped[] = $ev;
        }
    }
    $events = $deduped;

    // ── Strip internal `_origin` field from output ────────────────────
    $timeline = array_map(function ($e) {
        unset($e['_origin'], $e['ts_raw']);
        return $e;
    }, $events);

    // ── Compute capability flags (RFC §4.2) ───────────────────────────
    // Faza 2: can_edit_metadata / can_change_payment aktywne dla owner/admin/manager.
    // Faza 3: can_revert / can_reopen / can_force_edit — endpointy jeszcze nie istnieją.
    $hasSnapshots = false;
    foreach ($timeline as $e) {
        if (!empty($e['snapshot'])) {
            $hasSnapshots = true;
            break;
        }
    }
    $isManagerRole = in_array($role, ['owner', 'admin', 'manager'], true);
    $isAdminRole   = in_array($role, ['owner', 'admin'], true);
    $orderStatus   = (string)($order['status'] ?? '');
    $isTerminal    = in_array($orderStatus, ['completed', 'cancelled'], true);
    $payStatus     = strtolower((string)($order['payment_status'] ?? ''));
    $isFiscalized  = !empty($order['fiscal_receipt_number']);

    // Faza 2: edycja metadanych — manager+ dla zamkniętych zamówień
    $canEditMetadata = $isManagerRole && $isTerminal;
    // Faza 2: zmiana cash↔card — manager+, tylko completed, tylko cash/card, nie sfiskalizowane
    $canChangePayment = $isManagerRole
        && $orderStatus === 'completed'
        && in_array($payStatus, ['cash', 'card'], true)
        && !$isFiscalized;

    // Faza 3 (placeholder — endpointy jeszcze nie istnieją)
    $canRevert     = $isAdminRole && $hasSnapshots;
    $canReopen     = $isAdminRole && $orderStatus === 'completed';
    $canForceEdit  = $isAdminRole && $isTerminal;

    auditOut(true, [
        'order_id'              => $orderId,
        'order_number'          => $order['order_number'],
        'status'                => $orderStatus,
        'order_type'            => $order['order_type'],
        'customer_name'         => $order['customer_name'],
        'customer_phone'        => $order['customer_phone'],
        'delivery_address'      => $order['delivery_address'],
        'notes'                 => $order['notes'],
        'payment_status'        => $payStatus,
        'payment_method'        => $order['payment_method'],
        'fiscal_receipt_number' => $order['fiscal_receipt_number'],
        'receipt_printed'       => ((int)$order['receipt_printed']) === 1,
        'timeline'              => $timeline,
        // Faza 2
        'can_edit_metadata'     => $canEditMetadata,
        'can_change_payment'    => $canChangePayment,
        // Faza 3 (placeholder)
        'can_revert'            => $canRevert,
        'can_reopen'            => $canReopen,
        'can_force_edit'        => $canForceEdit,
    ]);
} catch (PDOException $e) {
    error_log('[orders/audit] PDOException: ' . $e->getMessage());
    auditOut(false, null, 'Błąd bazy danych.', 500);
} catch (Throwable $e) {
    error_log('[orders/audit] ' . $e->getMessage());
    auditOut(false, null, 'Błąd serwera.', 500);
}
