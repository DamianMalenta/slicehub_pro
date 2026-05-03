<?php
// =============================================================================
// HTTP POST alias dla przyjęcia zamówienia — ta sama logika co `api/pos/engine.php`
// action `accept_order` (KDS routing w core/KdsAcceptRouting.php). Frontend POS
// woła engine.php; ten endpoint zostaje dla integracji zewnętrznych / testów.
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
    require_once __DIR__ . '/../../core/KdsAcceptRouting.php';

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
    // 3–4. ATOMIC: transition + KDS tickets (unless disable_kds) + outbox event
    // =========================================================================
    $now = date('Y-m-d H:i:s');
    $ticketsCreated = [];

    $pdo->beginTransaction();

    try {
        $transResult = OrderStateMachine::transitionOrder(
            $pdo, $orderId, $tenant_id, $user_id, 'accepted', $tenantFlags,
            [
                'promised_time'          => $promisedTime,
                'kitchen_ticket_printed' => 1,
                'accepted_at'            => $now,
            ]
        );

        if (!$transResult['success']) {
            throw new RuntimeException($transResult['message']);
        }

        if (empty($tenantFlags['disable_kds'])) {
            try {
                $ticketsCreated = KdsAcceptRouting::createTicketsForAcceptedOrder($pdo, $tenant_id, $orderId);
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
        }

        require_once __DIR__ . '/../../core/OrderEventPublisher.php';
        OrderEventPublisher::publishOrderLifecycle(
            $pdo, $tenant_id, 'order.accepted', $orderId,
            ['promised_time' => $promisedTime, 'accepted_by_user_id' => $user_id],
            ['source' => 'orders_accept', 'actorType' => 'staff', 'actorId' => (string)$user_id]
        );

        $pdo->commit();

    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
