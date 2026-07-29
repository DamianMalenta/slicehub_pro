<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR POS Webhook (Create Order URL)
// POST /api/integrations/choiceqr/webhook.php?t=SECRET_TOKEN
//
// ChoiceQR pushuje zatwierdzone zamówienia (status=approved) do tego endpointu.
// Payload: { order: OrderSchema, varSymbol: "ID" }
//
// Architektura:
//   1. Auth: token w query param ?t=SECRET_TOKEN (ChoiceQR nie wspiera HMAC)
//   2. Tenant mapping: varSymbol → tenant_id z sh_tenant_integrations
//   3. Idempotency: order._id w sh_external_order_refs (source='choiceqr')
//   4. Mapowanie OrderSchema → sh_orders + sh_order_lines (bez CartEngine)
//   5. Publish order.created event (transactional outbox)
//   6. Response: 200 OK empty body (wymóg ChoiceQR — brak 200 = anulowane)
//
// Uwaga: ufamy totalom ChoiceQR (oni są source of truth dla cen klienta).
// CartEngine jest pominięty — przeliczałby inaczej i odrzucałby przy niezgodności SKU.
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// -----------------------------------------------------------------------------
// Helpers — ChoiceQR wymaga 200 OK empty body, nie JSON error
// -----------------------------------------------------------------------------
function cqr_ok(): never
{
    http_response_code(200);
    echo '';
    exit;
}

function cqr_fail(int $code, string $msg): never
{
    http_response_code($code);
    error_log('[ChoiceQR Webhook] ' . $code . ' — ' . $msg);
    // ChoiceQR ignoruje body — zwracamy puste, tylko logujemy
    echo '';
    exit;
}

// =============================================================================
// MAIN FLOW
// =============================================================================
try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/Uuid.php';
    require_once __DIR__ . '/../../../core/GatewayAuth.php';

    if (!isset($pdo)) {
        cqr_fail(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH — token w query param
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    if ($providedToken === '') {
        cqr_fail(401, 'Missing token');
    }

    // -------------------------------------------------------------------------
    // 2. PARSE PAYLOAD
    // -------------------------------------------------------------------------
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        cqr_fail(400, 'Invalid JSON payload');
    }

    $order = $input['order'] ?? null;
    $varSymbol = trim((string)($input['varSymbol'] ?? ''));

    if (!is_array($order) || $varSymbol === '') {
        cqr_fail(400, 'Missing order or varSymbol');
    }

    $externalId = trim((string)($order['_id'] ?? ''));
    if ($externalId === '') {
        cqr_fail(400, 'Missing order._id');
    }

    // -------------------------------------------------------------------------
    // 3. TENANT MAPPING — varSymbol → tenant_id via sh_tenant_integrations
    // -------------------------------------------------------------------------
    $stmtTi = $pdo->prepare(
        "SELECT id, tenant_id, credentials, is_active
         FROM sh_tenant_integrations
         WHERE provider = 'choiceqr' AND is_active = 1"
    );
    $stmtTi->execute();
    $integrations = $stmtTi->fetchAll(PDO::FETCH_ASSOC);

    $tenantId = 0;
    $webhookToken = null;

    foreach ($integrations as $ti) {
        $creds = json_decode((string)$ti['credentials'], true);
        if (!is_array($creds)) {
            $creds = [];
        }
        $tiVarSymbol = (string)($creds['var_symbol'] ?? '');
        $tiWebhookToken = (string)($creds['webhook_token'] ?? '');

        if ($tiVarSymbol === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = $tiWebhookToken;
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_fail(403, "No tenant integration found for varSymbol='{$varSymbol}'");
    }

    // Verify webhook token
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_fail(401, 'Invalid webhook token');
    }

    // -------------------------------------------------------------------------
    // 4. IDEMPOTENCY — check if order._id already processed
    // -------------------------------------------------------------------------
    $existingRef = GatewayAuth::lookupExternalRef($pdo, $tenantId, 'choiceqr', $externalId);
    if ($existingRef !== null) {
        // Duplicate — return 200 OK (ChoiceQR retry safe)
        cqr_ok();
    }

    // -------------------------------------------------------------------------
    // 5. MAP OrderSchema → SliceHub
    // -------------------------------------------------------------------------
    $orderTypeMap = [
        'delivery' => 'delivery',
        'takeaway' => 'takeaway',
        'table'    => 'dine_in',
    ];
    $cqrType = trim((string)($order['type'] ?? 'delivery'));
    $orderType = $orderTypeMap[$cqrType] ?? 'delivery';
    $channel = match ($orderType) {
        'delivery' => 'Delivery',
        'takeaway' => 'Takeaway',
        'dine_in'  => 'POS',
        default    => 'Delivery',
    };

    // Totals — ChoiceQR używa grosze (1/100 PLN)
    $subtotal       = (int)($order['subTotal'] ?? 0);
    $discountAmount = (int)($order['discount'] ?? 0);
    $tipAmount      = (int)($order['tips'] ?? 0);
    $deliveryFee    = (int)($order['delivery']['cost'] ?? 0);
    $grandTotal     = (int)($order['total'] ?? 0);
    $costOfPack     = (int)($order['costOfPack'] ?? 0);

    // Customer
    $customerName  = trim((string)($order['delivery']['customer']['name'] ?? ''));
    $customerPhone = trim((string)($order['delivery']['customer']['phone'] ?? ''));

    // Address — złożyć z komponentów
    $addr = $order['delivery']['customer']['address'] ?? [];
    $addrParts = array_filter([
        trim((string)($addr['streetName'] ?? '')),
        trim((string)($addr['streetNumber'] ?? '')),
        trim((string)($addr['city'] ?? '')),
    ]);
    $deliveryAddress = implode(', ', $addrParts);

    // Coordinates — ChoiceQR: [lng, lat] (GeoJSON order)
    $lat = null;
    $lng = null;
    $coords = $addr['location']['coordinates'] ?? null;
    if (is_array($coords) && count($coords) >= 2) {
        $lng = (float)$coords[0];
        $lat = (float)$coords[1];
    }

    // Promised time
    $promisedTime = null;
    $whenStr = trim((string)($order['delivery']['when'] ?? ''));
    if ($whenStr !== '' && strtoupper($whenStr) !== 'ASAP') {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $whenStr, new DateTimeZone('Europe/Warsaw'));
        if ($parsed === false) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $whenStr, new DateTimeZone('Europe/Warsaw'));
        }
        if ($parsed === false) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $whenStr, new DateTimeZone('Europe/Warsaw'));
        }
        if ($parsed !== false) {
            $promisedTime = $parsed->format('Y-m-d H:i:s');
        }
    }
    if ($promisedTime === null) {
        $promisedTime = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
            ->modify('+30 minutes')->format('Y-m-d H:i:s');
    }

    // Payment method
    $payBy = trim((string)($order['payBy'] ?? ''));
    $paymentMethodMap = [
        'cash'    => 'cash',
        'card'    => 'card',
        'online'  => 'online',
    ];
    $paymentMethod = $paymentMethodMap[$payBy] ?? null;
    $paymentStatus = match ($payBy) {
        'online' => 'paid',
        default  => 'unpaid',
    };

    // Order comment
    $orderComment = trim((string)($order['delivery']['comment'] ?? ''));

    // -------------------------------------------------------------------------
    // 6. ATOMIC PERSIST + EVENT PUBLISH
    // -------------------------------------------------------------------------
    $tz = new DateTimeZone('Europe/Warsaw');
    $now = new DateTimeImmutable('now', $tz);
    $nowDb = $now->format('Y-m-d H:i:s');
    $requestHash = hash('sha256', $raw);

    // Feature detect: czy sh_orders ma kolumny gateway_*?
    $hasGatewayColumns = false;
    try {
        $pdo->query("SELECT gateway_source FROM sh_orders LIMIT 0");
        $hasGatewayColumns = true;
    } catch (PDOException $e) {
        $hasGatewayColumns = false;
    }

    $pdo->beginTransaction();

    try {
        // Order number sequence
        $stmtSeq = $pdo->prepare(
            "INSERT INTO sh_order_sequences (tenant_id, date, seq)
             VALUES (:tid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
        );
        $stmtSeq->execute([':tid' => $tenantId]);
        $seq = (int)$pdo->lastInsertId();

        $orderNumber = sprintf('CQR/%s/%04d', $now->format('Ymd'), $seq);
        $orderId = Uuid::v4();

        // INSERT sh_orders
        if ($hasGatewayColumns) {
            $stmtOrder = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     gateway_source, gateway_external_id,
                     subtotal, discount_amount, delivery_fee, grand_total,
                     status, payment_status, payment_method, tip_amount,
                     customer_name, customer_phone, delivery_address,
                     lat, lng, promised_time, created_at)
                 VALUES
                    (:id, :tid, :num, :channel, :order_type, :src,
                     :gw_src, :gw_eid,
                     :subtotal, :discount, :delivery, :grand,
                     'new', :pay_status, :pay_method, :tips,
                     :cust_name, :cust_phone, :del_addr,
                     :lat, :lng, :promised, :now)"
            );
            $stmtOrder->execute([
                ':id'         => $orderId,
                ':tid'        => $tenantId,
                ':num'        => $orderNumber,
                ':channel'    => $channel,
                ':order_type' => $orderType,
                ':src'        => 'CQR',
                ':gw_src'     => 'choiceqr',
                ':gw_eid'     => $externalId,
                ':subtotal'   => $subtotal,
                ':discount'   => $discountAmount,
                ':delivery'   => $deliveryFee,
                ':grand'      => $grandTotal,
                ':pay_status' => $paymentStatus,
                ':pay_method' => $paymentMethod,
                ':tips'       => $tipAmount,
                ':cust_name'  => $customerName ?: null,
                ':cust_phone' => $customerPhone ?: null,
                ':del_addr'   => $deliveryAddress ?: null,
                ':lat'        => $lat,
                ':lng'        => $lng,
                ':promised'   => $promisedTime,
                ':now'        => $nowDb,
            ]);
        } else {
            $stmtOrder = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     subtotal, discount_amount, delivery_fee, grand_total,
                     status, payment_status, payment_method, tip_amount,
                     customer_name, customer_phone, delivery_address,
                     lat, lng, promised_time, created_at)
                 VALUES
                    (:id, :tid, :num, :channel, :order_type, :src,
                     :subtotal, :discount, :delivery, :grand,
                     'new', :pay_status, :pay_method, :tips,
                     :cust_name, :cust_phone, :del_addr,
                     :lat, :lng, :promised, :now)"
            );
            $stmtOrder->execute([
                ':id'         => $orderId,
                ':tid'        => $tenantId,
                ':num'        => $orderNumber,
                ':channel'    => $channel,
                ':order_type' => $orderType,
                ':src'        => 'CQR',
                ':subtotal'   => $subtotal,
                ':discount'   => $discountAmount,
                ':delivery'   => $deliveryFee,
                ':grand'      => $grandTotal,
                ':pay_status' => $paymentStatus,
                ':pay_method' => $paymentMethod,
                ':tips'       => $tipAmount,
                ':cust_name'  => $customerName ?: null,
                ':cust_phone' => $customerPhone ?: null,
                ':del_addr'   => $deliveryAddress ?: null,
                ':lat'        => $lat,
                ':lng'        => $lng,
                ':promised'   => $promisedTime,
                ':now'        => $nowDb,
            ]);
        }

        // INSERT sh_order_lines
        $items = $order['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('Order has no items');
        }

        $stmtLine = $pdo->prepare(
            "INSERT INTO sh_order_lines
                (id, order_id, item_sku, snapshot_name, unit_price,
                 quantity, line_total, vat_rate, vat_amount,
                 modifiers_json, comment)
             VALUES
                (:id, :oid, :sku, :name, :unit, :qty, :total,
                 :vat_rate, :vat_amt, :mods, :comment)"
        );

        foreach ($items as $item) {
            $itemSku = trim((string)($item['posID'] ?? ''));
            if ($itemSku === '') {
                $itemSku = 'UNKNOWN';
            }

            // Name — ChoiceQR używa zagnieżdżone name.en.name
            $itemName = '';
            if (isset($item['name']['en']['name'])) {
                $itemName = trim((string)$item['name']['en']['name']);
            } elseif (isset($item['name']) && is_string($item['name'])) {
                $itemName = trim($item['name']);
            }
            if ($itemName === '') {
                $itemName = $itemSku;
            }

            $unitPrice = (int)($item['price'] ?? 0);
            $quantity  = (int)($item['count'] ?? 1);
            $lineTotal = (int)($item['total'] ?? ($unitPrice * $quantity));
            $vatRate   = (float)($item['vat'] ?? 0);
            $vatAmount = (int)round($lineTotal * $vatRate / (100 + $vatRate));

            // Modifiers (menuOptions)
            $modifiersJson = null;
            $menuOptions = $item['menuOptions'] ?? [];
            if (is_array($menuOptions) && count($menuOptions) > 0) {
                $modifiersJson = json_encode($menuOptions, JSON_UNESCAPED_UNICODE);
            }

            $lineComment = trim((string)($item['comment'] ?? ''));

            $stmtLine->execute([
                ':id'       => Uuid::v4(),
                ':oid'      => $orderId,
                ':sku'      => $itemSku,
                ':name'     => $itemName,
                ':unit'     => $unitPrice,
                ':qty'      => $quantity,
                ':total'    => $lineTotal,
                ':vat_rate' => $vatRate,
                ':vat_amt'  => $vatAmount,
                ':mods'     => $modifiersJson,
                ':comment'  => $lineComment ?: null,
            ]);
        }

        // Audit trail
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, NULL, NULL, 'new', :now)"
        );
        $stmtAudit->execute([':oid' => $orderId, ':now' => $nowDb]);

        // Publish order.created event (transactional outbox)
        $publisherPath = __DIR__ . '/../../../core/OrderEventPublisher.php';
        if (file_exists($publisherPath)) {
            require_once $publisherPath;
            if (class_exists('OrderEventPublisher')) {
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo, $tenantId, 'order.created', $orderId,
                    [
                        'channel'             => $channel,
                        'order_type'          => $orderType,
                        'gateway_source'      => 'choiceqr',
                        'gateway_external_id' => $externalId,
                        'var_symbol'          => $varSymbol,
                        'cost_of_pack'        => $costOfPack,
                        'order_comment'       => $orderComment,
                    ],
                    [
                        'source'         => 'gateway',
                        'actorType'      => 'external_api',
                        'actorId'        => 'choiceqr:' . $varSymbol,
                        'idempotencyKey' => $orderId . ':order.created',
                    ]
                );
            }
        }

        // Store external ref (idempotency) — w tej samej transakcji
        GatewayAuth::storeExternalRef(
            $pdo, $tenantId, 'choiceqr', $externalId, $orderId,
            null, $requestHash
        );

        $pdo->commit();

    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txErr;
    }

    // -------------------------------------------------------------------------
    // 7. SUCCESS — 200 OK empty body (wymóg ChoiceQR)
    // -------------------------------------------------------------------------
    cqr_ok();

} catch (Throwable $e) {
    error_log('[ChoiceQR Webhook] FATAL: ' . $e->getMessage());
    cqr_fail(500, 'Internal server error: ' . $e->getMessage());
}
