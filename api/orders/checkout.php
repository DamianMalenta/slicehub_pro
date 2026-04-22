<?php
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

$respond = static function (bool $ok, $data = null, ?string $message = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../cart/CartEngine.php';
    require_once __DIR__ . '/../../core/WzEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) {
        $respond(false, null, 'Invalid JSON payload.', 400);
    }

    $source = strtoupper(trim((string)($input['source'] ?? 'POS')));
    $customerName = isset($input['customer_name']) ? trim((string)$input['customer_name']) : null;
    $customerPhone = isset($input['customer_phone']) ? trim((string)$input['customer_phone']) : null;
    $deliveryAddress = isset($input['delivery_address']) ? trim((string)$input['delivery_address']) : null;
    $promisedTime = isset($input['requested_time']) ? trim((string)$input['requested_time']) : null;
    $warehouseId = trim((string)($input['warehouse_id'] ?? 'MAIN')) ?: 'MAIN';
    $lockToken = trim((string)($input['lock_token'] ?? ''));

    $hasCheckoutLocks = false;
    $hasOrdersTracking = false;
    try { $pdo->query('SELECT 1 FROM sh_checkout_locks LIMIT 0'); $hasCheckoutLocks = true; } catch (Throwable $e) {}
    try { $pdo->query('SELECT tracking_token FROM sh_orders LIMIT 0'); $hasOrdersTracking = true; } catch (Throwable $e) {}

    $calc = CartEngine::calculate($pdo, $tenant_id, $input);

    $generateUuidV4 = static function (): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    $fmtMoney = static fn(int $g): string => number_format($g / 100, 2, '.', '');
    $prefixMap = [
        'POS'        => 'ORD',
        'TAKEAWAY'   => 'ORD',
        'DELIVERY'   => 'ORD',
        'WWW'        => 'WWW',
        'ONLINE'     => 'WWW',
        'KIOSK'      => 'KIO',
        'AGGREGATOR' => 'AGG',
    ];
    $prefix = $prefixMap[$source] ?? 'ORD';

    if ($source === 'ONLINE') {
        if (!$hasCheckoutLocks) {
            $respond(false, null, 'Checkout idempotency unavailable (missing migration 017).', 400);
        }
        if (!$hasOrdersTracking) {
            $respond(false, null, 'Guest tracking unavailable (tracking_token missing).', 400);
        }
        if ($lockToken === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $lockToken)) {
            $respond(false, null, 'Invalid lock_token.', 400);
        }

        $stmtLock = $pdo->prepare(
            "SELECT cart_hash, grand_total_grosze, expires_at, consumed_at
             FROM sh_checkout_locks
             WHERE lock_token = :tok AND tenant_id = :tid
             LIMIT 1"
        );
        $stmtLock->execute([':tok' => $lockToken, ':tid' => $tenant_id]);
        $lock = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if (!$lock) {
            $respond(false, null, 'lock_token not found or expired.', 400);
        }
        if (!empty($lock['consumed_at'])) {
            $respond(false, null, 'This checkout token has already been consumed.', 409);
        }
        if (strtotime((string)$lock['expires_at']) < time()) {
            $respond(false, null, 'Checkout token expired.', 409);
        }

        $canonical = json_encode([
            'channel'    => $input['channel'] ?? null,
            'order_type' => $input['order_type'] ?? null,
            'lines'      => $input['lines'] ?? [],
            'promo_code' => $input['promo_code'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cartHash = hash('sha256', (string)$canonical);
        if ($cartHash !== (string)$lock['cart_hash']) {
            $respond(false, null, 'Cart changed after init_checkout.', 409);
        }
        if ((int)$lock['grand_total_grosze'] !== (int)$calc['grand_total_grosze']) {
            $respond(false, null, 'Cart total changed after init_checkout.', 409);
        }
    }

    $availability = WzEngine::checkAvailability($pdo, $tenant_id, $warehouseId, $calc['lines_raw'] ?? []);
    if (($availability['success'] ?? false) && ($availability['available'] ?? true) === false) {
        $respond(false, [
            'warehouse_id' => $availability['warehouse_id'] ?? $warehouseId,
            'shortages'    => $availability['shortages'] ?? [],
        ], 'Insufficient warehouse stock for checkout.', 409);
    }

    $trackingToken = ($source === 'ONLINE' && $hasOrdersTracking) ? bin2hex(random_bytes(8)) : null;

    $pdo->beginTransaction();
    try {
        $stmtSeq = $pdo->prepare(
            "INSERT INTO sh_order_sequences (tenant_id, date, seq)
             VALUES (:tid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
        );
        $stmtSeq->execute([':tid' => $tenant_id]);
        $seq = (int)$pdo->lastInsertId();

        $orderNumber = sprintf('%s/%s/%04d', $prefix, date('Ymd'), $seq);
        $orderId = $generateUuidV4();
        $now = date('Y-m-d H:i:s');

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

        $stmtOrder = $pdo->prepare(
            "INSERT INTO sh_orders
                (id, tenant_id, order_number, channel, order_type, source,
                 subtotal, discount_amount, delivery_fee, grand_total,
                 status, payment_status, loyalty_points_earned,
                 customer_name, customer_phone, tracking_token, delivery_address,
                 promised_time, user_id, created_at)
             VALUES
                (:id, :tid, :num, :channel, :order_type, :source,
                 :subtotal, :discount, :delivery, :grand,
                 'new', 'to_pay', :points,
                 :cust_name, :cust_phone, :tracking_token, :del_addr,
                 :promised, :uid, :now)"
        );
        $stmtOrder->execute([
            ':id'             => $orderId,
            ':tid'            => $tenant_id,
            ':num'            => $orderNumber,
            ':channel'        => $calc['channel'],
            ':order_type'     => $calc['order_type'],
            ':source'         => $source,
            ':subtotal'       => $calc['subtotal_grosze'],
            ':discount'       => $calc['discount_grosze'],
            ':delivery'       => $calc['delivery_fee_grosze'],
            ':grand'          => $calc['grand_total_grosze'],
            ':points'         => $calc['loyalty_points'],
            ':cust_name'      => $customerName,
            ':cust_phone'     => $customerPhone,
            ':tracking_token' => $trackingToken,
            ':del_addr'       => $deliveryAddress,
            ':promised'       => $promisedTime !== '' ? $promisedTime : null,
            ':uid'            => $user_id,
            ':now'            => $now,
        ]);

        $stmtLine = $pdo->prepare(
            "INSERT INTO sh_order_lines
                (id, order_id, item_sku, snapshot_name, unit_price,
                 quantity, line_total, vat_rate, vat_amount,
                 modifiers_json, removed_ingredients_json, comment)
             VALUES
                (:id, :oid, :sku, :name, :unit, :qty, :total, :vat_rate, :vat_amt,
                 :mods, :removed, :comment)"
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
                ':mods'     => $lr['modifiers_json'],
                ':removed'  => $lr['removed_ingredients_json'],
                ':comment'  => $lr['comment'],
            ]);
        }

        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, :uid, NULL, 'new', :now)"
        );
        $stmtAudit->execute([':oid' => $orderId, ':uid' => $user_id, ':now' => $now]);

        if ($source === 'ONLINE' && $lockToken !== '' && $hasCheckoutLocks) {
            $stmtConsume = $pdo->prepare(
                "UPDATE sh_checkout_locks
                 SET consumed_at = NOW(), consumed_order_id = :oid
                 WHERE lock_token = :tok AND tenant_id = :tid"
            );
            $stmtConsume->execute([':oid' => $orderId, ':tok' => $lockToken, ':tid' => $tenant_id]);
        }

        $pdo->commit();
    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txErr;
    }

    // Publish order.created dla ONLINE (symetria z guest_checkout w api/online/engine.php)
    if ($source === 'ONLINE') {
        try {
            require_once __DIR__ . '/../../core/OrderEventPublisher.php';
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenant_id, 'order.created', $orderId,
                [
                    'channel'       => $calc['channel'],
                    'order_type'    => $calc['order_type'],
                    'payment_method' => $calc['payment_method'] ?? null,
                    'tracking_token' => $trackingToken,
                ],
                ['source' => 'online', 'actorType' => 'guest', 'actorId' => $orderId]
            );
        } catch (Throwable $pubErr) {
            error_log('[Checkout] OrderEventPublisher failed: ' . $pubErr->getMessage());
        }
    }

    $response = [
        'order_id'              => $orderId,
        'order_number'          => $orderNumber,
        'status'                => 'new',
        'grand_total'           => $fmtMoney((int)$calc['grand_total_grosze']),
        'loyalty_points_earned' => $calc['loyalty_points'],
        'cart'                  => $calc['response'],
    ];
    if ($trackingToken !== null) {
        $response['tracking_token'] = $trackingToken;
        $response['source'] = 'ONLINE';
    }

    $respond(true, $response, 'OK');
} catch (CartEngineException $e) {
    $respond(false, null, $e->getMessage(), 400);
} catch (PDOException $e) {
    error_log('[Checkout] PDOException: ' . $e->getMessage());
    $respond(false, null, 'Database error. Please try again later.', 500);
} catch (Throwable $e) {
    error_log('[Checkout] ' . $e->getMessage());
    $respond(false, null, 'Internal server error.', 500);
}
