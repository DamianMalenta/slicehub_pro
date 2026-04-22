<?php
// =============================================================================
// STATUS: ORPHAN (audit 2026-04-19) — not wired from any frontend module.
// Overlaps with `api/pos/engine.php#settle_and_close`, but this version has
// richer split-tender logic (integer grosze math, sh_order_payments rows).
// DECISION PENDING: promote this to canonical settlement or merge into
// pos/engine.php. Do NOT delete without moving the split-tender logic.
// =============================================================================
// SliceHub Enterprise — Payment Settlement (Split Tender)
// POST /api/payments/settle.php
//
// Settles an order's payment with full split-tender support.
// All monetary arithmetic uses integer grosze to eliminate floating-point drift.
//
// Schema: sh_orders, sh_order_payments, sh_order_audit
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

    $orderId = $input['order_id'] ?? null;
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required field: order_id.']);
        exit;
    }

    $payments = $input['payments'] ?? null;
    if (!is_array($payments) || count($payments) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or empty payments array.']);
        exit;
    }

    $toGrosze = fn($val): int => (int)round((float)$val * 100);

    $tipAmountGrosze = $toGrosze($input['tip_amount'] ?? '0.00');
    $printReceipt    = (bool)($input['print_receipt'] ?? false);

    // =========================================================================
    // 2. FETCH ORDER (Multi-tenant isolation barrier)
    // =========================================================================
    $stmtOrder = $pdo->prepare(
        "SELECT id, grand_total, payment_status, status, channel, source
         FROM sh_orders
         WHERE id = :order_id AND tenant_id = :tenant_id
         LIMIT 1"
    );
    $stmtOrder->execute([
        ':order_id'  => $orderId,
        ':tenant_id' => $tenant_id,
    ]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    // Idempotent: already settled → return gracefully
    if ($order['payment_status'] === 'paid') {
        echo json_encode([
            'success' => true,
            'data'    => [
                'payment_status'  => 'paid',
                'change_due'      => '0.00',
                'receipt_printed' => false,
            ],
        ]);
        exit;
    }

    $grandTotalGrosze = (int)$order['grand_total'];

    // =========================================================================
    // 3. VALIDATION ENGINE (Split Tender Math)
    // =========================================================================
    $totalAppliedGrosze  = 0;
    $totalTenderedGrosze = 0;
    $receiptPrinted      = $printReceipt;
    $validMethods        = ['cash', 'card', 'online'];

    foreach ($payments as $idx => $p) {
        $method = $p['method'] ?? '';
        if (!in_array($method, $validMethods, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Invalid payment method '{$method}' at index {$idx}.",
            ]);
            exit;
        }

        $amountGrosze   = $toGrosze($p['amount'] ?? '0.00');
        $tenderedGrosze = $toGrosze($p['tendered'] ?? $p['amount'] ?? '0.00');

        if ($amountGrosze <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Payment amount must be positive at index {$idx}.",
            ]);
            exit;
        }

        if ($method === 'online' && empty($p['transaction_id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Online payment at index {$idx} requires a transaction_id.",
            ]);
            exit;
        }

        // Polish tax law: card payments mandate a printed fiscal receipt
        if ($method === 'card') {
            $receiptPrinted = true;
        }

        $totalAppliedGrosze  += $amountGrosze;
        $totalTenderedGrosze += $tenderedGrosze;
    }

    // Strict tolerance: applied total must equal grand_total ±1 grosz
    if (abs($totalAppliedGrosze - $grandTotalGrosze) > 1) {
        $fmtExpected = number_format($grandTotalGrosze / 100, 2, '.', '');
        $fmtApplied  = number_format($totalAppliedGrosze / 100, 2, '.', '');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Payment total ({$fmtApplied}) does not match order total ({$fmtExpected}). Tolerance: ±0.01 PLN.",
        ]);
        exit;
    }

    // =========================================================================
    // 4. BUSINESS LOGIC CALCULATIONS
    // =========================================================================
    $changeDueGrosze = max(0, $totalTenderedGrosze - $totalAppliedGrosze - $tipAmountGrosze);

    $methods       = array_unique(array_column($payments, 'method'));
    $paymentMethod = count($methods) === 1 ? $methods[0] : 'mixed';

    // STATE GUARD: auto-complete ONLY if status is exactly 'ready'
    // AND the order is NOT a delivery or aggregator channel.
    $oldStatus       = $order['status'];
    $canAutoComplete = ($oldStatus === 'ready')
        && !in_array($order['source'] ?? '', ['AGGREGATOR'], true)
        && !in_array($order['channel'] ?? '', ['Delivery'], true);
    $newStatus = $canAutoComplete ? 'completed' : $oldStatus;

    // =========================================================================
    // 5. TRANSACTIONAL PERSISTENCE
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
        // — 5a. Insert payment entries ————————————————————————————————————
        $stmtPayment = $pdo->prepare(
            "INSERT INTO sh_order_payments
                (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
             VALUES
                (:id, :order_id, :tenant_id, :method, :amount, :tendered, :txn_id)"
        );

        foreach ($payments as $p) {
            $stmtPayment->execute([
                ':id'        => $generateUuidV4(),
                ':order_id'  => $orderId,
                ':tenant_id' => $tenant_id,
                ':method'    => $p['method'],
                ':amount'    => $toGrosze($p['amount'] ?? '0.00'),
                ':tendered'  => $toGrosze($p['tendered'] ?? $p['amount'] ?? '0.00'),
                ':txn_id'    => $p['transaction_id'] ?? null,
            ]);
        }

        // — 5b. Update order header ———————————————————————————————————————
        $psMap = ['cash' => 'cash', 'card' => 'card', 'online' => 'online_paid'];
        $newPayStatus = $psMap[$paymentMethod] ?? $paymentMethod;
        $sql = "UPDATE sh_orders
                SET payment_status = '{$newPayStatus}',
                    payment_method = :pay_method,
                    tip_amount     = :tip"
             . ($canAutoComplete ? ", status = 'completed'" : "")
             . " WHERE id = :oid AND tenant_id = :tid";

        $stmtUpdate = $pdo->prepare($sql);
        $stmtUpdate->execute([
            ':pay_method' => $paymentMethod,
            ':tip'        => $tipAmountGrosze,
            ':oid'        => $orderId,
            ':tid'        => $tenant_id,
        ]);

        // — 5c. Audit trail (only on status transition) ——————————————————
        if ($canAutoComplete) {
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit
                    (order_id, user_id, old_status, new_status, timestamp)
                 VALUES
                    (:oid, :uid, :old_status, :new_status, :now)"
            );
            $stmtAudit->execute([
                ':oid'        => $orderId,
                ':uid'        => $user_id,
                ':old_status' => $oldStatus,
                ':new_status' => 'completed',
                ':now'        => $now,
            ]);
        }

        // — 5d. COMMIT ————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 6. SUCCESS RESPONSE
    // =========================================================================
    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'payment_status'  => 'paid',
            'change_due'      => $fmtMoney($changeDueGrosze),
            'receipt_printed' => $receiptPrinted,
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
