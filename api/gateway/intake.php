<?php
// =============================================================================
// SliceHub Enterprise — Gateway v2 (Unified Order Intake)
// POST /api/gateway/intake.php
//
// Jedna publiczna bramka dla wszystkich zewnętrznych źródeł zamówień:
//   • web          — własne frontendy (fallback legacy)
//   • aggregator_* — Uber Eats / Glovo / Pyszne.pl / Wolt (każdy osobny source)
//   • kiosk        — samoobsługowy kiosk w lokalu
//   • pos_3rd      — 3rd-party POS push (Papu wysyła do nas)
//   • mobile_app   — własna aplikacja mobilna tenanta
//   • public_api   — tenant-owned integracja (np. system lojalnościowy)
//
// Architektura (patrz _docs/10_GATEWAY_API.md):
//   1. Autoryzacja kluczem X-API-Key (core/GatewayAuth::authenticateKey)
//   2. Rate limiting per key (minute + day, sliding window)
//   3. Idempotency przez external_id (sh_external_order_refs)
//   4. JSON schema validation per source (różne wymagane pola)
//   5. CartEngine recalculate (server-authoritative)
//   6. Atomic INSERT sh_orders + sh_order_lines + sh_order_audit + publish event
//   7. Response z order_id / order_number / was_duplicate flag
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST.']);
    exit;
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function gw_reject(int $httpCode, string $errorCode, string $message, array $data = []): never
{
    http_response_code($httpCode);
    $body = ['success' => false, 'error' => $errorCode, 'message' => $message];
    if (!empty($data)) $body['data'] = $data;
    echo json_encode($body);
    exit;
}

function gw_success(array $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// -----------------------------------------------------------------------------
// Per-source schemas — required fields + source-specific rules
// -----------------------------------------------------------------------------
function gw_requiredFields(string $source): array
{
    // Uniwersalne wymagane dla wszystkich source
    $base = ['lines', 'channel', 'order_type', 'customer_phone'];

    switch ($source) {
        case 'aggregator_uber':
        case 'aggregator_glovo':
        case 'aggregator_pyszne':
        case 'aggregator_wolt':
        case 'pos_3rd':
            // Aggregator MUSI podać external_id (bez niego nie ma idempotency)
            return array_merge($base, ['external_id']);

        case 'kiosk':
            // Kiosk dzieli tenant + terminal — nie potrzeba phone (może być walk-in)
            return ['lines', 'channel', 'order_type'];

        case 'web':
        case 'mobile_app':
        case 'public_api':
        default:
            return $base;
    }
}

/**
 * Prefix dla order_number — per-source numeracja.
 */
function gw_orderNumberPrefix(string $source): string
{
    static $map = [
        'web'                => 'WWW',
        'mobile_app'         => 'MOB',
        'kiosk'              => 'KIO',
        'pos_3rd'            => 'EXT',
        'public_api'         => 'API',
        'aggregator_uber'    => 'UBR',
        'aggregator_glovo'   => 'GLV',
        'aggregator_pyszne'  => 'PYS',
        'aggregator_wolt'    => 'WLT',
        'aggregator'         => 'AGG',
    ];
    return $map[$source] ?? 'EXT';
}

// =============================================================================
// MAIN FLOW
// =============================================================================
try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/GatewayAuth.php';
    require_once __DIR__ . '/../cart/CartEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // -------------------------------------------------------------------------
    // 0. PARSE PAYLOAD
    // -------------------------------------------------------------------------
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        gw_reject(400, 'INVALID_JSON', 'Invalid JSON payload.');
    }

    $providedKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    $tenantFromPayload = (int)($input['tenant_id'] ?? 0);

    // -------------------------------------------------------------------------
    // 1. AUTHENTICATE KEY (supports new multi-key + legacy env fallback)
    // -------------------------------------------------------------------------
    $auth = GatewayAuth::authenticateKey($pdo, $providedKey, $tenantFromPayload ?: null);

    if (!$auth['ok']) {
        $reason  = $auth['reason'] ?? 'invalid';
        $httpMap = ['rate_limited' => 429, 'inactive' => 403, 'revoked' => 403, 'expired' => 403];
        $http    = $httpMap[$reason] ?? 401;
        gw_reject($http, strtoupper('AUTH_' . $reason), "API key authentication failed ({$reason}).");
    }

    $apiKeyId = (int)($auth['apiKeyId'] ?? 0);
    $tenantId = (int)($auth['tenantId'] ?? 0);
    $legacyKey = !empty($auth['legacy']);
    $keyScopes = $auth['scopes'] ?? [];
    $hasScope  = in_array('*', $keyScopes, true) || in_array('order:create', $keyScopes, true);

    if ($tenantId <= 0) {
        gw_reject(400, 'INVALID_TENANT', 'A valid tenant_id is required (from key or payload).');
    }

    if (!$hasScope) {
        gw_reject(403, 'SCOPE_DENIED', 'This API key does not have order:create scope.');
    }

    // -------------------------------------------------------------------------
    // 2. RATE LIMIT (skip for legacy — back-compat)
    // -------------------------------------------------------------------------
    if (!$legacyKey && $apiKeyId > 0) {
        $rl = GatewayAuth::checkAndIncrementRateLimit(
            $pdo,
            $apiKeyId,
            (int)($auth['rateLimitPerMin'] ?? 60),
            (int)($auth['rateLimitPerDay'] ?? 10000)
        );

        if (!$rl['ok']) {
            $retryAfter = (int)($rl['retryAfter'] ?? 60);
            header('Retry-After: ' . $retryAfter);
            gw_reject(429, 'RATE_LIMITED',
                "Rate limit exceeded. Retry after {$retryAfter} seconds.",
                ['retry_after_seconds' => $retryAfter, 'hits' => $rl['hits'] ?? null]
            );
        }
    }

    // -------------------------------------------------------------------------
    // 3. RESOLVE SOURCE (from payload OR inferred from key)
    // -------------------------------------------------------------------------
    $sourceFromPayload = trim((string)($input['source'] ?? ''));
    $source = $sourceFromPayload !== '' ? $sourceFromPayload : (string)($auth['source'] ?? 'web');

    // Walidacja source — whitelist
    $allowedSources = [
        'web', 'mobile_app', 'public_api', 'kiosk', 'pos_3rd', 'internal',
        'aggregator', 'aggregator_uber', 'aggregator_glovo',
        'aggregator_pyszne', 'aggregator_wolt',
    ];
    if (!in_array($source, $allowedSources, true)) {
        gw_reject(400, 'INVALID_SOURCE',
            "Unknown source '{$source}'. Allowed: " . implode(', ', $allowedSources)
        );
    }

    // Jeśli klucz wiąże się z konkretnym source — payload nie może go nadpisać
    // (zabezpieczenie przed key-reuse attack: klucz mobile_app nie puszcza
    // zamówień jako aggregator_uber).
    if (!$legacyKey && !empty($auth['source']) && $auth['source'] !== 'internal' && $source !== $auth['source']) {
        // Wyjątek: 'aggregator' jako generyczny parent dla aggregator_*
        $keySrc = (string)$auth['source'];
        if (!(str_starts_with($source, 'aggregator_') && $keySrc === 'aggregator')) {
            gw_reject(403, 'SOURCE_MISMATCH',
                "API key source '{$keySrc}' does not allow requests as '{$source}'."
            );
        }
    }

    // -------------------------------------------------------------------------
    // 4. SCHEMA VALIDATION per source
    // -------------------------------------------------------------------------
    $required = gw_requiredFields($source);
    foreach ($required as $field) {
        if ($field === 'lines') {
            if (!isset($input['lines']) || !is_array($input['lines']) || count($input['lines']) === 0) {
                gw_reject(400, 'EMPTY_CART', 'Missing or empty `lines` array.');
            }
        } elseif (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
            gw_reject(400, 'MISSING_FIELD', "Missing required field for source '{$source}': `{$field}`.",
                ['field' => $field, 'source' => $source]);
        }
    }

    $channel         = trim((string)$input['channel']);
    $orderType       = trim((string)$input['order_type']);
    $customerPhone   = trim((string)($input['customer_phone'] ?? ''));
    $customerAddress = trim((string)($input['customer_address'] ?? ''));
    $customerName    = trim((string)($input['customer_name'] ?? ''));
    $lat             = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng             = isset($input['lng']) ? (float)$input['lng'] : null;
    $requestedTime   = trim((string)($input['requested_time'] ?? 'ASAP'));
    $clientTotal     = $input['client_total'] ?? null;
    $externalId      = trim((string)($input['external_id'] ?? ''));

    // Channel / order_type whitelist
    if (!in_array($channel, ['POS','Takeaway','Delivery'], true)) {
        gw_reject(400, 'INVALID_CHANNEL', 'channel must be one of: POS, Takeaway, Delivery.');
    }
    if (!in_array($orderType, ['dine_in','takeaway','delivery'], true)) {
        gw_reject(400, 'INVALID_ORDER_TYPE', 'order_type must be one of: dine_in, takeaway, delivery.');
    }

    // Phone validation — tylko gdy nie-kiosk
    if ($source !== 'kiosk' && !preg_match('/^\+48\d{9}$/', $customerPhone)) {
        gw_reject(400, 'INVALID_PHONE', 'customer_phone must be Polish E.164: +48XXXXXXXXX.');
    }

    // Delivery wymaga adresu
    if ($channel === 'Delivery' && $customerAddress === '') {
        gw_reject(400, 'MISSING_ADDRESS', 'customer_address required for Delivery channel.');
    }

    // -------------------------------------------------------------------------
    // 5. IDEMPOTENCY CHECK (external_id)
    // -------------------------------------------------------------------------
    if ($externalId !== '') {
        $ref = GatewayAuth::lookupExternalRef($pdo, $tenantId, $source, $externalId);
        if ($ref !== null) {
            // Duplicate — zwróć oryginalny order_id
            gw_success([
                'order_id'      => $ref['orderId'],
                'status'        => 'duplicate',
                'was_duplicate' => true,
                'original_created_at' => $ref['createdAt'],
                'message'       => 'Order with this external_id already exists — returning original.',
            ], 200);
        }
    }

    // -------------------------------------------------------------------------
    // 6. TENANT ACTIVE + OPENING HOURS
    // -------------------------------------------------------------------------
    $stmtTenant = $pdo->prepare(
        "SELECT is_active, min_order_value, opening_hours_json, min_prep_time_minutes
         FROM sh_tenant_settings
         WHERE tenant_id = :tid AND setting_key = ''
         LIMIT 1"
    );
    $stmtTenant->execute([':tid' => $tenantId]);
    $tenantSettings = $stmtTenant->fetch(PDO::FETCH_ASSOC);

    if (!$tenantSettings || !(int)$tenantSettings['is_active']) {
        gw_reject(403, 'TENANT_INACTIVE', 'Tenant is currently not accepting online orders.');
    }

    $minOrderValueGrosze = (int)$tenantSettings['min_order_value'];
    $minPrepMinutes      = (int)($tenantSettings['min_prep_time_minutes'] ?: 30);
    $openingHoursJson    = $tenantSettings['opening_hours_json'];

    // -------------------------------------------------------------------------
    // 7. CART RECALCULATE (server-authoritative)
    // -------------------------------------------------------------------------
    $fmtMoney = fn(int $g): string => number_format($g / 100, 2, '.', '');
    $toGrosze = fn($v): int => (int)round((float)$v * 100);

    $priceMismatchWarning = false;

    try {
        $calc = CartEngine::calculate($pdo, $tenantId, $input);
    } catch (CartEngineException $e) {
        gw_reject(400, 'ITEM_UNAVAILABLE', $e->getMessage());
    }

    $serverTotalGrosze = (int)$calc['grand_total_grosze'];

    if ($clientTotal !== null) {
        $clientTotalGrosze = $toGrosze($clientTotal);
        if (abs($clientTotalGrosze - $serverTotalGrosze) > 1) {
            $priceMismatchWarning = true;
        }
    }

    // -------------------------------------------------------------------------
    // 8. MIN ORDER VALUE (skip for kiosk — dine-in + small orders OK)
    // -------------------------------------------------------------------------
    if ($source !== 'kiosk' && $minOrderValueGrosze > 0 && $serverTotalGrosze < $minOrderValueGrosze) {
        gw_reject(400, 'BELOW_MINIMUM',
            "Minimum order value is {$fmtMoney($minOrderValueGrosze)} PLN. "
            . "Cart total is {$fmtMoney($serverTotalGrosze)} PLN.",
            ['min_order_value' => $fmtMoney($minOrderValueGrosze), 'cart_total' => $fmtMoney($serverTotalGrosze)]
        );
    }

    // -------------------------------------------------------------------------
    // 9. BUSINESS HOURS (skip for pos_3rd — 3rd-party wie lepiej kiedy ma przyjąć)
    // -------------------------------------------------------------------------
    $tz  = new DateTimeZone('Europe/Warsaw');
    $now = new DateTimeImmutable('now', $tz);
    $closingTime = null;

    if ($source !== 'pos_3rd' && $source !== 'internal') {
        $openingHours = json_decode($openingHoursJson ?: '{}', true) ?: [];
        $todayKey     = strtolower($now->format('l'));
        $todayHours   = $openingHours[$todayKey] ?? null;

        if ($todayHours === null || ($todayHours['closed'] ?? false)) {
            gw_reject(400, 'STORE_CLOSED', 'The store is closed today.');
        }

        $openStr  = $todayHours['open']  ?? '00:00';
        $closeStr = $todayHours['close'] ?? '23:59';

        $openTime    = DateTimeImmutable::createFromFormat('H:i', $openStr, $tz)->setDate(
            (int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d')
        );
        $closingTime = DateTimeImmutable::createFromFormat('H:i', $closeStr, $tz)->setDate(
            (int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d')
        );

        if ($now < $openTime || $now > $closingTime) {
            gw_reject(400, 'STORE_CLOSED',
                "Store hours: {$openStr}–{$closeStr}. Try again during business hours."
            );
        }
    }

    // -------------------------------------------------------------------------
    // 10. REQUESTED TIME BOUNDS
    // -------------------------------------------------------------------------
    if (strtoupper($requestedTime) === 'ASAP' || $requestedTime === '') {
        $promisedTime = $now->modify("+{$minPrepMinutes} minutes")->format('Y-m-d H:i:s');
    } else {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $requestedTime, $tz)
              ?? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $requestedTime, $tz)
              ?? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $requestedTime, $tz);

        if (!$parsed) {
            gw_reject(400, 'INVALID_TIME', 'requested_time must be "ASAP" or ISO 8601 / MySQL datetime.');
        }

        $earliest = $now->modify("+{$minPrepMinutes} minutes");
        if ($parsed < $earliest) {
            gw_reject(400, 'INVALID_TIME',
                "requested_time must be at least {$minPrepMinutes} minutes from now."
            );
        }

        if ($closingTime !== null && $parsed > $closingTime) {
            gw_reject(400, 'INVALID_TIME', 'requested_time cannot exceed closing time.');
        }

        $promisedTime = $parsed->format('Y-m-d H:i:s');
    }

    // -------------------------------------------------------------------------
    // 11. GEOFENCING (Delivery only)
    // -------------------------------------------------------------------------
    if ($channel === 'Delivery' && $lat !== null && $lng !== null) {
        $pointWkt = sprintf('POINT(%F %F)', $lng, $lat);
        try {
            $stmtGeo = $pdo->prepare(
                "SELECT id FROM sh_delivery_zones
                 WHERE tenant_id = :tid
                   AND ST_Contains(zone_polygon, ST_GeomFromText(:point))
                 LIMIT 1"
            );
            $stmtGeo->execute([':tid' => $tenantId, ':point' => $pointWkt]);
            if (!$stmtGeo->fetchColumn()) {
                gw_reject(400, 'OUT_OF_ZONE', 'Delivery address is outside our delivery area.');
            }
        } catch (PDOException $e) {
            // Jeśli sh_delivery_zones nie istnieje — skip (legacy install).
            error_log('[GatewayV2] geofencing skipped: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 12. ATOMIC PERSIST + EVENT PUBLISH
    // -------------------------------------------------------------------------
    $generateUuidV4 = function (): string {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    };

    $nowDb = $now->format('Y-m-d H:i:s');
    $requestHash = hash('sha256', (string)$raw);

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
        $stmtSeq = $pdo->prepare(
            "INSERT INTO sh_order_sequences (tenant_id, date, seq)
             VALUES (:tid, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
        );
        $stmtSeq->execute([':tid' => $tenantId]);
        $seq = (int)$pdo->lastInsertId();

        $orderPrefix = gw_orderNumberPrefix($source);
        $orderNumber = sprintf('%s/%s/%04d', $orderPrefix, $now->format('Ymd'), $seq);
        $orderId     = $generateUuidV4();

        // INSERT sh_orders — z / bez kolumn gateway_*
        if ($hasGatewayColumns) {
            $stmtOrder = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     gateway_source, gateway_external_id,
                     subtotal, discount_amount, delivery_fee, grand_total,
                     status, payment_status, loyalty_points_earned,
                     customer_name, customer_phone, delivery_address,
                     lat, lng, promised_time, created_at)
                 VALUES
                    (:id, :tid, :num, :channel, :order_type, :src,
                     :gw_src, :gw_eid,
                     :subtotal, :discount, :delivery, :grand,
                     'new', 'unpaid', :points,
                     :cust_name, :cust_phone, :del_addr,
                     :lat, :lng, :promised, :now)"
            );
            $stmtOrder->execute([
                ':id'         => $orderId,
                ':tid'        => $tenantId,
                ':num'        => $orderNumber,
                ':channel'    => $calc['channel'],
                ':order_type' => $calc['order_type'],
                ':src'        => $orderPrefix, // legacy 'source' kolumna (WWW/AGG/KIO)
                ':gw_src'     => $source,
                ':gw_eid'     => $externalId !== '' ? $externalId : null,
                ':subtotal'   => $calc['subtotal_grosze'],
                ':discount'   => $calc['discount_grosze'],
                ':delivery'   => $calc['delivery_fee_grosze'],
                ':grand'      => $serverTotalGrosze,
                ':points'     => $calc['loyalty_points'],
                ':cust_name'  => $customerName ?: null,
                ':cust_phone' => $customerPhone ?: null,
                ':del_addr'   => $customerAddress ?: null,
                ':lat'        => $lat,
                ':lng'        => $lng,
                ':promised'   => $promisedTime,
                ':now'        => $nowDb,
            ]);
        } else {
            // Legacy fallback (m027 nie zainstalowana)
            $stmtOrder = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     subtotal, discount_amount, delivery_fee, grand_total,
                     status, payment_status, loyalty_points_earned,
                     customer_name, customer_phone, delivery_address,
                     lat, lng, promised_time, created_at)
                 VALUES
                    (:id, :tid, :num, :channel, :order_type, :src,
                     :subtotal, :discount, :delivery, :grand,
                     'new', 'unpaid', :points,
                     :cust_name, :cust_phone, :del_addr,
                     :lat, :lng, :promised, :now)"
            );
            $stmtOrder->execute([
                ':id'         => $orderId,
                ':tid'        => $tenantId,
                ':num'        => $orderNumber,
                ':channel'    => $calc['channel'],
                ':order_type' => $calc['order_type'],
                ':src'        => $orderPrefix,
                ':subtotal'   => $calc['subtotal_grosze'],
                ':discount'   => $calc['discount_grosze'],
                ':delivery'   => $calc['delivery_fee_grosze'],
                ':grand'      => $serverTotalGrosze,
                ':points'     => $calc['loyalty_points'],
                ':cust_name'  => $customerName ?: null,
                ':cust_phone' => $customerPhone ?: null,
                ':del_addr'   => $customerAddress ?: null,
                ':lat'        => $lat,
                ':lng'        => $lng,
                ':promised'   => $promisedTime,
                ':now'        => $nowDb,
            ]);
        }

        // INSERT sh_order_lines
        $stmtLine = $pdo->prepare(
            "INSERT INTO sh_order_lines
                (id, order_id, item_sku, snapshot_name, unit_price,
                 quantity, line_total, vat_rate, vat_amount,
                 modifiers_json, removed_ingredients_json, comment)
             VALUES
                (:id, :oid, :sku, :name, :unit, :qty, :total,
                 :vat_rate, :vat_amt, :mods, :removed, :comment)"
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
                ':removed' => $lr['removed_ingredients_json'],
                ':comment' => $lr['comment'],
            ]);
        }

        // Audit trail
        $stmtAudit = $pdo->prepare(
            "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
             VALUES (:oid, NULL, NULL, 'new', :now)"
        );
        $stmtAudit->execute([':oid' => $orderId, ':now' => $nowDb]);

        // [m026] Publish order.created
        $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
        if (file_exists($publisherPath)) {
            require_once $publisherPath;
            if (class_exists('OrderEventPublisher')) {
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo, $tenantId, 'order.created', $orderId,
                    [
                        'channel'          => $channel,
                        'order_type'       => $orderType,
                        'requested_time'   => $requestedTime,
                        'price_mismatch'   => $priceMismatchWarning,
                        'gateway_source'   => $source,
                        'gateway_external_id' => $externalId ?: null,
                        'api_key_id'       => $apiKeyId ?: null,
                    ],
                    [
                        'source'         => 'gateway',
                        'actorType'      => $legacyKey ? 'external_api' : 'external_api',
                        'actorId'        => $legacyKey ? 'legacy' : ('key_' . $apiKeyId),
                        'idempotencyKey' => $orderId . ':order.created',
                    ]
                );
            }
        }

        // Store external ref (idempotency) — PRZED commitem, w tej samej transakcji
        if ($externalId !== '') {
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, $source, $externalId, $orderId,
                $apiKeyId ?: null, $requestHash
            );
        }

        $pdo->commit();

    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txErr;
    }

    // -------------------------------------------------------------------------
    // 13. SUCCESS RESPONSE
    // -------------------------------------------------------------------------
    $responseData = [
        'order_id'      => $orderId,
        'order_number'  => $orderNumber,
        'status'        => 'new',
        'was_duplicate' => false,
        'grand_total'   => $fmtMoney($serverTotalGrosze),
        'promised_time' => $promisedTime,
        'source'        => $source,
    ];

    if ($externalId !== '') {
        $responseData['external_id'] = $externalId;
    }

    if ($priceMismatchWarning) {
        $responseData['warning']      = 'PRICE_MISMATCH';
        $responseData['server_total'] = $fmtMoney($serverTotalGrosze);
    }

    gw_success($responseData, 201);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DATABASE_ERROR', 'message' => 'Database error. Please try again later.']);
    error_log('[GatewayV2] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'INTERNAL_ERROR', 'message' => 'Internal server error.']);
    error_log('[GatewayV2] ' . $e->getMessage());
}

exit;
