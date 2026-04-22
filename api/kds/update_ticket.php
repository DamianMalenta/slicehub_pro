<?php
// =============================================================================
// SliceHub Enterprise — KDS Ticket State Machine
// POST /api/kds/update_ticket.php
//
// Advances a single KDS ticket through: pending → preparing → done.
// On the LAST ticket reaching 'done', auto-transitions the parent order
// to 'ready' (the universal readiness rule from the blueprint).
//
// Schema: sh_kds_tickets, sh_orders, sh_order_audit
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

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

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

    $ticketId  = $input['ticket_id'] ?? null;
    $newStatus = $input['new_status'] ?? null;

    if (empty($ticketId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: ticket_id.']);
        exit;
    }

    $allowedStatuses = ['preparing', 'done'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Invalid new_status. Allowed values: " . implode(', ', $allowedStatuses) . ".",
        ]);
        exit;
    }

    // =========================================================================
    // 2. FETCH TICKET (Multi-tenant isolation barrier)
    // =========================================================================
    $stmtTicket = $pdo->prepare(
        "SELECT id, order_id, station_id, status
         FROM sh_kds_tickets
         WHERE id = :ticket_id AND tenant_id = :tid
         LIMIT 1"
    );
    $stmtTicket->execute([':ticket_id' => $ticketId, ':tid' => $tenant_id]);
    $ticket = $stmtTicket->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
        exit;
    }

    // =========================================================================
    // 3. VALIDATE STATE TRANSITION
    // =========================================================================
    $validTransitions = [
        'pending'   => ['preparing', 'done'],
        'preparing' => ['done'],
        'done'      => [],
    ];

    $currentStatus = $ticket['status'];
    $allowed       = $validTransitions[$currentStatus] ?? [];

    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Cannot transition ticket from '{$currentStatus}' to '{$newStatus}'."
                       . (empty($allowed)
                            ? " Status '{$currentStatus}' is terminal."
                            : " Allowed: ['" . implode("', '", $allowed) . "']."),
        ]);
        exit;
    }

    // =========================================================================
    // 4. TRANSACTIONAL PERSISTENCE
    // =========================================================================
    $orderId         = $ticket['order_id'];
    $now             = date('Y-m-d H:i:s');
    $orderBecameReady = false;

    $pdo->beginTransaction();

    try {
        // — 4a. Advance ticket status —————————————————————————————————————
        $stmtUpdate = $pdo->prepare(
            "UPDATE sh_kds_tickets
             SET status = :new_status
             WHERE id = :ticket_id AND tenant_id = :tid"
        );
        $stmtUpdate->execute([
            ':new_status' => $newStatus,
            ':ticket_id'  => $ticketId,
            ':tid'        => $tenant_id,
        ]);

        // — 4b. Auto-ready check (only when marking 'done') ——————————————
        if ($newStatus === 'done') {
            $stmtPending = $pdo->prepare(
                "SELECT COUNT(*) AS remaining
                 FROM sh_kds_tickets
                 WHERE order_id = :oid AND tenant_id = :tid AND status != 'done'
                 FOR UPDATE"
            );
            $stmtPending->execute([':oid' => $orderId, ':tid' => $tenant_id]);
            $remaining = (int)$stmtPending->fetchColumn();

            if ($remaining === 0) {
                // Fetch actual old status before overwriting
                $stmtOldStatus = $pdo->prepare(
                    "SELECT status FROM sh_orders WHERE id = :oid AND tenant_id = :tid LIMIT 1"
                );
                $stmtOldStatus->execute([':oid' => $orderId, ':tid' => $tenant_id]);
                $oldOrderStatus = $stmtOldStatus->fetchColumn() ?: 'accepted';

                // All stations done → order is ready
                $stmtOrderStatus = $pdo->prepare(
                    "UPDATE sh_orders
                     SET status = 'ready'
                     WHERE id = :oid AND tenant_id = :tid AND status IN ('accepted', 'preparing')"
                );
                $stmtOrderStatus->execute([':oid' => $orderId, ':tid' => $tenant_id]);

                if ($stmtOrderStatus->rowCount() > 0) {
                    $orderBecameReady = true;

                    $stmtAudit = $pdo->prepare(
                        "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                         VALUES (:oid, :uid, :old_status, 'ready', :now)"
                    );
                    $stmtAudit->execute([
                        ':oid'        => $orderId,
                        ':uid'        => $user_id,
                        ':old_status' => $oldOrderStatus,
                        ':now'        => $now,
                    ]);
                }
            }
        }

        // — 4c. COMMIT ————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 5. SUCCESS RESPONSE
    // =========================================================================
    $responseData = [
        'ticket_id'    => $ticketId,
        'station_id'   => $ticket['station_id'],
        'ticket_status' => $newStatus,
        'order_id'      => $orderId,
    ];

    if ($orderBecameReady) {
        $responseData['order_status'] = 'ready';
        $responseData['order_ready']  = true;
    }

    echo json_encode(['success' => true, 'data' => $responseData]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[KdsUpdateTicket] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[KdsUpdateTicket] ' . $e->getMessage());
}

exit;
