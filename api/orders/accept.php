<?php
// =============================================================================
// STATUS: ORPHAN (audit 2026-04-19) — not wired from any frontend module.
// Functionally overlaps with `api/pos/engine.php#accept_order`. This variant
// additionally splits lines into sh_kds_tickets per station. Kept for future
// multi-station KDS (see sh_menu_items.kds_station_id from m006).
// DECISION PENDING: merge into pos/engine.php or delete if stations stay flat.
// =============================================================================
// SliceHub Enterprise — Order Acceptance & KDS Ticket Router
// POST /api/orders/accept.php
//
// Transitions an order from 'new' → 'accepted', splits its lines into
// per-station KDS tickets, and links each line to its ticket.
//
// Schema: sh_orders, sh_order_lines, sh_menu_items, sh_kds_tickets,
//         sh_order_audit
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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // [FF-HOOK] Load tenant feature flags
    $tenantFlags = OrderStateMachine::loadTenantFlags($pdo, $tenant_id);

    // =========================================================================
    // 1. PARSE & VALIDATE INPUT
    // =========================================================================
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $orderId     = $input['order_id'] ?? null;
    $promisedTime = isset($input['promised_time']) ? trim($input['promised_time']) : null;

    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: order_id.']);
        exit;
    }

    if (empty($promisedTime)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: promised_time.']);
        exit;
    }

    // =========================================================================
    // 2. FETCH ORDER (Multi-tenant isolation barrier)
    // =========================================================================
    $stmtOrder = $pdo->prepare(
        "SELECT id, order_number, status, order_type, promised_time
         FROM sh_orders
         WHERE id = :oid AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtOrder->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    // [FF-HOOK] State machine validates whether new → accepted is allowed.
    // With 'skip_acceptance' flag, orders bypass this endpoint entirely
    // (checkout.php creates them as 'pending' directly).
    if (!OrderStateMachine::canTransition($order['status'], 'accepted', $tenantFlags)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Cannot accept order. Current status is '{$order['status']}', transition to 'accepted' not allowed.",
        ]);
        exit;
    }

    // =========================================================================
    // 3. FETCH ORDER LINES & RESOLVE KDS STATIONS
    //
    // [FF-004] When 'disable_kds' flag is active, this section can be
    // skipped entirely. The order transitions from accepted → pending
    // without creating KDS tickets. The frontend hides the KDS display.
    // Implementation: wrap the stationGroups/ticket creation in:
    //   if (empty($tenantFlags['disable_kds'])) { ... }
    // =========================================================================
    $stmtLines = $pdo->prepare(
        "SELECT ol.id          AS line_id,
                ol.item_sku,
                ol.snapshot_name,
                ol.quantity,
                ol.modifiers_json,
                ol.removed_ingredients_json,
                ol.comment,
                COALESCE(NULLIF(mi.kds_station_id, ''), 'KITCHEN_MAIN') AS station_id
         FROM sh_order_lines ol
         LEFT JOIN sh_menu_items mi
                ON mi.ascii_key = ol.item_sku
               AND mi.tenant_id = :tid
         WHERE ol.order_id = :oid"
    );
    $stmtLines->execute([':oid' => $orderId, ':tid' => $tenant_id]);
    $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

    if (count($lines) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order has no lines to route.']);
        exit;
    }

    // Group line data by station_id
    $stationGroups = [];
    foreach ($lines as $line) {
        $sid = $line['station_id'];
        if (!isset($stationGroups[$sid])) {
            $stationGroups[$sid] = [];
        }
        $stationGroups[$sid][] = $line;
    }

    // =========================================================================
    // 4. ATOMIC TRANSACTION
    // =========================================================================
    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        // — 4a. Transition order: new → accepted via State Machine ————————
        $transResult = OrderStateMachine::transitionOrder(
            $pdo, $orderId, $tenant_id, $user_id, 'accepted', $tenantFlags,
            ['promised_time' => $promisedTime]
        );

        if (!$transResult['success']) {
            throw new RuntimeException($transResult['message']);
        }

        // — 4b. Create KDS tickets & link lines ——————————————————————————
        // [FF-004] When 'disable_kds' is active, skip ticket creation.
        // The order still transitions to 'accepted' but without KDS routing.
        $stmtInsertTicket = $pdo->prepare(
            "INSERT INTO sh_kds_tickets (id, tenant_id, order_id, station_id, status)
             VALUES (:id, :tid, :oid, :station, 'pending')"
        );

        $stmtLinkLine = $pdo->prepare(
            "UPDATE sh_order_lines SET kds_ticket_id = :ticket_id WHERE id = :line_id AND order_id = :oid"
        );

        $ticketsCreated = [];

        foreach ($stationGroups as $stationId => $stationLines) {
            $ticketId = $generateUuidV4();

            $stmtInsertTicket->execute([
                ':id'      => $ticketId,
                ':tid'     => $tenant_id,
                ':oid'     => $orderId,
                ':station' => $stationId,
            ]);

            $ticketLines = [];
            foreach ($stationLines as $sl) {
                $stmtLinkLine->execute([
                    ':ticket_id' => $ticketId,
                    ':line_id'   => $sl['line_id'],
                    ':oid'       => $orderId,
                ]);

                $mods    = json_decode($sl['modifiers_json'] ?? '[]', true) ?: [];
                $removed = json_decode($sl['removed_ingredients_json'] ?? '[]', true) ?: [];

                $ticketLines[] = [
                    'line_id'              => $sl['line_id'],
                    'snapshot_name'        => $sl['snapshot_name'],
                    'quantity'             => (int)$sl['quantity'],
                    'modifiers_added'      => array_column($mods, 'name'),
                    'ingredients_removed'  => array_column($removed, 'name'),
                    'comment'              => $sl['comment'],
                ];
            }

            $ticketsCreated[] = [
                'ticket_id'    => $ticketId,
                'station_id'   => $stationId,
                'status'       => 'pending',
                'lines'        => $ticketLines,
            ];
        }

        // Audit trail already written by OrderStateMachine::transitionOrder()

        // — 4c. COMMIT ————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 5. SUCCESS RESPONSE
    // =========================================================================
    echo json_encode([
        'success' => true,
        'data'    => [
            'order_id'      => $orderId,
            'order_number'  => $order['order_number'],
            'status'        => 'accepted',
            'promised_time' => $promisedTime,
            'kds_tickets'   => $ticketsCreated,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[OrderAccept] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[OrderAccept] ' . $e->getMessage());
}

exit;
