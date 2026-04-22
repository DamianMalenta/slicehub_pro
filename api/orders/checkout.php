<?php
// =============================================================================
// SliceHub Enterprise — Order Checkout (Finalization) Endpoint
// POST /api/orders/checkout.php
//
// Receives the same cart payload as calculate.php, but instead of previewing,
// it PERSISTS the order inside a strict database transaction.
//
// Flow:
//   1. Recalculate via CartEngine (never trust client prices)
//   2. Begin transaction
//   3. Generate atomic order number via sh_order_sequences
//   4. Insert sh_orders, sh_order_lines, sh_order_audit
//   5. Increment promo uses (state mutation only here, never in calculate)
//   6. Commit
//
// Schema: sh_orders, sh_order_lines, sh_order_sequences,
//         sh_order_audit, sh_promo_codes
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
    require_once __DIR__ . '/../cart/CartEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // =========================================================================
    // 1. PARSE INPUT
    // =========================================================================
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $source = trim($input['source'] ?? 'POS');

    // =========================================================================
    // 2. SECURE RECALCULATION (single source of truth)
    // =========================================================================
    $calc = CartEngine::calculate($pdo, $tenant_id, $input);

    // =========================================================================
    // 3. HELPERS
    // =========================================================================
    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    $prefixMap = [
        'POS'        => 'ORD',
        'Takeaway'   => 'ORD',
        'Delivery'   => 'ORD',
        'WWW'        => 'WWW',
        'KIOSK'      => 'KIO',
        'AGGREGATOR' => 'AGG',
    ];
    $prefix = $prefixMap[$source] ?? 'ORD';

    // =========================================================================
    // 4. ATOMIC TRANSACTION
    // =========================================================================
    $pdo->beginTransaction();

    try {
        // — 4a. Atomic sequence number ————————————————————————————————————
        $stmtSeq = $pdo->prepare(
            "INSERT INTO sh_order_sequences (tenant_id, date, seq)
             VALUES (:tid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
        );
        $stmtSeq->execute([':tid' => $tenant_id]);
        $seq = (int)$pdo->lastInsertId();

        $orderNumber = sprintf('%s/%s/%04d', $prefix, date('Ymd'), $seq);
        $orderId     = $generateUuidV4();
        $now         = date('Y-m-d H:i:s');

        // — 4b. Promo code state mutation —————————————————————————————————
        if ($calc['applied_promo_code'] !== null) {
            $stmtPromoInc = $pdo->prepare(
                "UPDATE sh_promo_codes
                 SET current_uses = current_uses + 1
                 WHERE code = :code AND tenant_id = :tid"
            );
            $stmtPromoInc->execute([
                ':code' => $calc['applied_promo_code'],
                ':tid'  => $tenant_id,
            ]);
        }

        // — 4c. Insert order header ———————————————————————————————————————
        $stmtOrder = $pdo->prepare(
            "INSERT INTO sh_orders
                (id, tenant_id, order_number, channel, order_type, source,
                 subtotal, discount_amount, delivery_fee, grand_total,
                 status, loyalty_points_earned, created_at)
             VALUES
                (:id, :tid, :num, :channel, :order_type, :source,
                 :subtotal, :discount, :delivery, :grand,
                 'new', :points, :now)"
        );
        $stmtOrder->execute([
            ':id'         => $orderId,
            ':tid'        => $tenant_id,
            ':num'        => $orderNumber,
            ':channel'    => $calc['channel'],
            ':order_type' => $calc['order_type'],
            ':source'     => $source,
            ':subtotal'   => $calc['subtotal_grosze'],
            ':discount'   => $calc['discount_grosze'],
            ':delivery'   => $calc['delivery_fee_grosze'],
            ':grand'      => $calc['grand_total_grosze'],
            ':points'     => $calc['loyalty_points'],
            ':now'        => $now,
        ]);

        // — 4d. Insert order lines ————————————————————————————————————————
        $stmtLine = $pdo->prepare(
            "INSERT INTO sh_order_lines
                (id, order_id, item_sku, snapshot_name, unit_price,
                 quantity, line_total, vat_rate, vat_amount)
             VALUES
                (:id, :oid, :sku, :name, :unit, :qty, :total, :vat_rate, :vat_amt)"
        );

        foreach ($calc['lines_raw'] as $lr) {
            $stmtLine->execute([
                ':id'       => $generateUuidV4(),
                ':oid'      => $orderId,
                ':sku'      => $lr['item_sku'],
                ':name'     => $lr['snapshot_name'],
                ':unit'     => $lr['unit_price_grosze'],
                ':qty'      => $lr['quantity'],
                ':total'    => $lr['line_total_grosze'],
                ':vat_rate' => $lr['vat_rate'],
                ':vat_amt'  => $lr['vat_amount_grosze'],
            ]);
        }

        // — 4e. Audit trail ———————————————————————————————————————————————
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, new_status, timestamp)
             VALUES (:oid, 'new', :now)"
        );
        $stmtAudit->execute([':oid' => $orderId, ':now' => $now]);

        // — 4f. COMMIT ————————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 5. SUCCESS RESPONSE
    // =========================================================================
    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');

    echo json_encode([
        'success' => true,
        'data'    => [
            'order_id'              => $orderId,
            'order_number'          => $orderNumber,
            'status'                => 'new',
            'grand_total'           => $fmtMoney($calc['grand_total_grosze']),
            'loyalty_points_earned' => $calc['loyalty_points'],
            'cart'                  => $calc['response'],
        ],
    ]);

} catch (CartEngineException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[Checkout] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Checkout] ' . $e->getMessage());
}

exit;
