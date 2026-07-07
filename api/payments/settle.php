<?php
// =============================================================================
// SliceHub Enterprise — Payment Settlement (Phase 1 wrapper)
// POST /api/payments/settle.php
//
// Thin HTTP adapter over core/SettlementEngine.php (canonical settlement brain).
// Phase 1: exactly one payment line — split-tender UI is Phase 2.
//
// @see core/SettlementEngine.php
// @see _docs/sessions/2026-07-07_settlement_engine_phase1.md
// =============================================================================

declare(strict_types=1);

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
    require_once __DIR__ . '/../../core/SettlementEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $orderId = $input['order_id'] ?? null;
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: order_id.']);
        exit;
    }

    $payments = $input['payments'] ?? null;
    if (!is_array($payments) || count($payments) !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phase 1 requires exactly one payment entry.']);
        exit;
    }

    $toGrosze      = fn($val): int => (int)round((float)$val * 100);
    $tipAmountGrosze = $toGrosze($input['tip_amount'] ?? '0.00');
    $printReceipt    = (bool)($input['print_receipt'] ?? false);

    $stmtOrder = $pdo->prepare(
        "SELECT id, grand_total, payment_status, status, channel, source, order_type
         FROM sh_orders
         WHERE id = :order_id AND tenant_id = :tenant_id
         LIMIT 1"
    );
    $stmtOrder->execute([
        ':order_id'  => $orderId,
        ':tenant_id' => $tenant_id,
    ]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
    $stmtOrder->closeCursor();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $canAutoComplete = SettlementEngine::shouldAutoComplete($order);
    $tenantFlags     = OrderStateMachine::loadTenantFlags($pdo, $tenant_id);

    $pdo->beginTransaction();

    try {
        $result = SettlementEngine::settle(
            $pdo,
            (string)$orderId,
            $tenant_id,
            $user_id,
            $payments,
            [
                'complete_order' => $canAutoComplete,
                'print_receipt'  => $printReceipt,
                'tip_grosze'     => $tipAmountGrosze,
                'settled_via'    => 'payments_settle',
            ],
            $tenantFlags
        );

        if (!$result['success']) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $result['message']]);
            exit;
        }

        if (empty($result['idempotent'])) {
            require_once __DIR__ . '/../../core/OrderEventPublisher.php';
            $now = date('Y-m-d H:i:s');
            $ctx = [
                'split_tender'             => false,
                'payment_method_aggregate' => $result['payment_method'],
                'payment_lines'            => $payments,
                'tip_grosze'               => $tipAmountGrosze,
                'change_due_grosze'        => $result['change_due_grosze'] ?? 0,
                'settled_at'               => $now,
                'settled_via'              => 'payments_settle',
            ];

            if ($canAutoComplete) {
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo,
                    $tenant_id,
                    'order.completed',
                    (string)$orderId,
                    array_merge($ctx, [
                        'from_status' => $result['old_status'],
                        'to_status'   => 'completed',
                    ]),
                    [
                        'source'    => 'payments',
                        'actorType' => 'staff',
                        'actorId'   => (string)$user_id,
                    ]
                );
            } else {
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo,
                    $tenant_id,
                    'payment.settled',
                    (string)$orderId,
                    array_merge($ctx, [
                        'order_status' => $result['old_status'],
                    ]),
                    [
                        'source'         => 'payments',
                        'actorType'      => 'staff',
                        'actorId'        => (string)$user_id,
                        'idempotencyKey' => $orderId . ':payment.settled',
                    ]
                );
            }
        }

        $pdo->commit();
    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'payment_status'  => $result['payment_status'],
            'change_due'      => $fmtMoney((int)($result['change_due_grosze'] ?? 0)),
            'receipt_printed' => (bool)($result['receipt_printed'] ?? false),
            'idempotent'      => !empty($result['idempotent']),
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[PaymentSettle] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[PaymentSettle] ' . $e->getMessage());
}

exit;
