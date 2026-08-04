<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Webhook Events Handler (P2.1)
// POST /api/integrations/choiceqr/events.php?t=SECRET_TOKEN
//
// ChoiceQR wysyła tu eventy (status changes, QR payments, menu changes, etc.)
// Payload: { id, type, langCode, data, varSymbol, timestramp }
//
// Architektura (P2.1 — cienka warstwa delegująca do adaptera):
//   1. Auth: token w query param ?t=SECRET_TOKEN (jak webhook.php)
//   2. Tenant mapping: varSymbol → tenant_id z sh_tenant_integrations
//   3. Log do sh_inbound_callbacks (feature-detect, fallback do sh_external_order_refs)
//   4. Idempotency: event.id w sh_inbound_callbacks (lub sh_external_order_refs fallback)
//   5. Delegacja: ChoiceQRAdapter::parseInboundCallback() parsuje event
//   6. Status update: OrderStateMachine::transitionOrder() (zamiast ręcznego UPDATE)
//   7. Delivery/Payment: OSM::canTransitionDelivery() / direct UPDATE
//   8. Publish: OrderEventPublisher::publishOrderLifecycle()
//   9. Response: 200 OK empty body (jak webhook.php)
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
// Helpers — ChoiceQR wymaga 200 OK empty body
// -----------------------------------------------------------------------------
function cqr_ev_ok(): never
{
    http_response_code(200);
    echo '';
    exit;
}

function cqr_ev_fail(int $code, string $msg): never
{
    // ChoiceQR wymaga 200 OK empty body nawet przy błędach (inaczej retry/anulowanie)
    http_response_code(200);
    error_log('[ChoiceQR Events] ' . $code . ' — ' . $msg);
    echo '';
    exit;
}

// =============================================================================
// MAIN FLOW
// =============================================================================
try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/GatewayAuth.php';
    require_once __DIR__ . '/../../../core/CredentialVault.php';
    require_once __DIR__ . '/../../../core/Integrations/BaseAdapter.php';
    require_once __DIR__ . '/../../../core/Integrations/ChoiceQRAdapter.php';
    require_once __DIR__ . '/../../../core/OrderStateMachine.php';
    require_once __DIR__ . '/../../../core/OrderEventPublisher.php';

    if (!isset($pdo)) {
        cqr_ev_fail(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH — token w query param
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    if ($providedToken === '') {
        cqr_ev_fail(401, 'Missing token');
    }

    // -------------------------------------------------------------------------
    // 2. PARSE PAYLOAD
    // -------------------------------------------------------------------------
    $raw = file_get_contents('php://input');
    $event = json_decode($raw, true);

    if (!is_array($event)) {
        cqr_ev_fail(400, 'Invalid JSON payload');
    }

    $eventId    = trim((string)($event['id'] ?? ''));
    $eventType  = trim((string)($event['type'] ?? ''));
    $varSymbol  = trim((string)($event['varSymbol'] ?? ''));
    $eventData  = is_array($event['data'] ?? null) ? $event['data'] : [];
    $timestamp  = trim((string)($event['timestramp'] ?? ''));

    if ($eventId === '' || $eventType === '' || $varSymbol === '') {
        cqr_ev_fail(400, 'Missing required fields: id, type, varSymbol');
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
    $integrationRow = null;
    $credentials = [];

    foreach ($integrations as $ti) {
        $credRaw = (string)$ti['credentials'];
        $credJson = CredentialVault::isEncrypted($credRaw) ? (CredentialVault::decrypt($credRaw) ?? '') : $credRaw;
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            $creds = [];
        }
        $tiVarSymbol = (string)($creds['var_symbol'] ?? '');
        $tiWebhookToken = (string)($creds['webhook_token'] ?? '');

        if ($tiVarSymbol === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = $tiWebhookToken;
            $integrationRow = $ti;
            $credentials = $creds;
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_ev_fail(403, "No tenant integration found for varSymbol='{$varSymbol}'");
    }

    // Verify webhook token
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_ev_fail(401, 'Invalid webhook token');
    }

    // -------------------------------------------------------------------------
    // 4. FEATURE-DETECT sh_inbound_callbacks table
    // -------------------------------------------------------------------------
    $hasInboundTable = true;
    try {
        $pdo->query("SELECT 1 FROM sh_inbound_callbacks LIMIT 0")->closeCursor();
    } catch (PDOException $e) {
        $hasInboundTable = false;
        error_log('[ChoiceQR Events] sh_inbound_callbacks missing — fallback to sh_external_order_refs');
    }

    // Helper: update callback log
    $callbackLogId = null;

    // -------------------------------------------------------------------------
    // 5. LOG TO sh_inbound_callbacks (if available)
    // -------------------------------------------------------------------------
    if ($hasInboundTable) {
        try {
            $stmtLog = $pdo->prepare(
                "INSERT INTO sh_inbound_callbacks
                    (provider, integration_id, raw_headers, raw_body, remote_ip, status, received_at)
                 VALUES
                    ('choiceqr', :iid, :hdrs, :body, :ip, 'pending', NOW())"
            );
            $headersJson = json_encode([
                'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? '',
                'User-Agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            $bodyBytes = strlen($raw);
            $stmtLog->execute([
                ':iid'  => (int)($integrationRow['id'] ?? 0),
                ':hdrs' => $headersJson,
                ':body' => $bodyBytes > 65000 ? substr($raw, 0, 65000) . '...[TRUNCATED]' : $raw,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $callbackLogId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[ChoiceQR Events] Failed to log callback: ' . $e->getMessage());
        }
    }

    // Helper to update callback log
    $cqrUpdateCallback = function (array $fields) use ($pdo, $callbackLogId): void {
        if ($callbackLogId === null || empty($fields)) return;
        $sets = [];
        $params = [':id' => $callbackLogId];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[":{$k}"] = $v;
        }
        try {
            $pdo->prepare("UPDATE sh_inbound_callbacks SET " . implode(', ', $sets) . " WHERE id = :id")
                ->execute($params);
        } catch (PDOException $e) {
            error_log('[ChoiceQR Events] Failed to update callback: ' . $e->getMessage());
        }
    };

    // -------------------------------------------------------------------------
    // 6. IDEMPOTENCY — check if event.id already processed
    // -------------------------------------------------------------------------
    if ($hasInboundTable && $callbackLogId !== null) {
        try {
            $dupStmt = $pdo->prepare(
                "SELECT id, status FROM sh_inbound_callbacks
                 WHERE provider = 'choiceqr' AND external_event_id = :eid AND id != :mine
                 ORDER BY id DESC LIMIT 1"
            );
            $dupStmt->execute([':eid' => $eventId, ':mine' => $callbackLogId]);
            $duplicate = $dupStmt->fetch(PDO::FETCH_ASSOC);
            if ($duplicate && $duplicate['status'] === 'processed') {
                $cqrUpdateCallback([
                    'status' => 'ignored',
                    'error_message' => 'duplicate — already processed as callback #' . $duplicate['id'],
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);
                cqr_ev_ok();
            }
        } catch (PDOException $e) { /* fail-open */ }
    } else {
        // Fallback: sh_external_order_refs
        $existingRef = GatewayAuth::lookupExternalRef($pdo, $tenantId, 'choiceqr_event', $eventId);
        if ($existingRef !== null) {
            cqr_ev_ok();
        }
    }

    // -------------------------------------------------------------------------
    // 7. DELEGATE TO ChoiceQRAdapter::parseInboundCallback()
    // -------------------------------------------------------------------------
    $adapter = new SliceHub\Integrations\ChoiceQRAdapter($integrationRow);
    $result = $adapter->parseInboundCallback($raw, [], $credentials);

    $cqrUpdateCallback([
        'tenant_id' => $tenantId,
        'external_event_id' => $result['external_event_id'] ?? null,
        'external_ref'      => $result['external_ref'] ?? null,
        'event_type'        => $result['event_type'] ?? null,
    ]);

    if (!($result['ok'] ?? false)) {
        $cqrUpdateCallback([
            'status' => 'rejected',
            'error_message' => substr((string)($result['error'] ?? 'unknown'), 0, 500),
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        // Still store ref for idempotency (fallback)
        if (!$hasInboundTable) {
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, 'choiceqr_event', $eventId,
                'event:' . $eventId, null, hash('sha256', $raw)
            );
        }
        cqr_ev_ok();
    }

    // If adapter returned no event_type (log-only / unknown event) — ack without DB changes
    $parsedEventType   = $result['event_type'] ?? null;
    $newStatus         = $result['new_status'] ?? null;
    $newDeliveryStatus = $result['new_delivery_status'] ?? null;
    $newPaymentStatus  = $result['new_payment_status'] ?? null;
    $externalOrderId   = $result['external_ref'] ?? null;

    if ($parsedEventType === null) {
        // Log-only event (menu changes, marketplace, qrPayment.error, unknown)
        error_log(sprintf(
            '[ChoiceQR Events] LOG-ONLY event: type=%s id=%s tenant=%d',
            $eventType, $eventId, $tenantId
        ));
        $cqrUpdateCallback([
            'status' => 'ignored',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$hasInboundTable) {
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, 'choiceqr_event', $eventId,
                'event:' . $eventId, null, hash('sha256', $raw)
            );
        }
        cqr_ev_ok();
    }

    // -------------------------------------------------------------------------
    // 8. MATCH external_ref → sh_orders.gateway_external_id
    // -------------------------------------------------------------------------
    if ($externalOrderId === null || $externalOrderId === '') {
        $cqrUpdateCallback([
            'status' => 'rejected',
            'error_message' => 'cannot extract order _id from event data',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$hasInboundTable) {
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, 'choiceqr_event', $eventId,
                'event:' . $eventId, null, hash('sha256', $raw)
            );
        }
        cqr_ev_ok();
    }

    // -------------------------------------------------------------------------
    // 9. STATUS / DELIVERY / PAYMENT UPDATES via OrderStateMachine
    // -------------------------------------------------------------------------
    $pdo->beginTransaction();

    try {
        // SELECT order inside transaction with FOR UPDATE to prevent race
        $stmtOrder = $pdo->prepare(
            "SELECT id, status, payment_status, delivery_status, order_type
             FROM sh_orders
             WHERE tenant_id = :tid AND gateway_external_id = :eid
             LIMIT 1 FOR UPDATE"
        );
        $stmtOrder->execute([':tid' => $tenantId, ':eid' => $externalOrderId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $pdo->rollBack();
            error_log(sprintf(
                '[ChoiceQR Events] Order not found: external_id=%s type=%s event=%s',
                $externalOrderId, $eventType, $eventId
            ));
            $cqrUpdateCallback([
                'status' => 'rejected',
                'error_message' => "order not found for external_id={$externalOrderId}",
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$hasInboundTable) {
                GatewayAuth::storeExternalRef(
                    $pdo, $tenantId, 'choiceqr_event', $eventId,
                    'event:' . $eventId, null, hash('sha256', $raw)
                );
            }
            cqr_ev_ok();
        }

        $orderId       = (string)$order['id'];
        $currentStatus = (string)$order['status'];

        $didBumpStatus   = false;
        $didBumpDelivery = false;
        $didBumpPayment  = false;

        // ── Status transition via OrderStateMachine ──
        if ($newStatus !== null && $newStatus !== $currentStatus) {
            $flags = OrderStateMachine::loadTenantFlags($pdo, $tenantId);
            $extraCols = [];
            if ($newStatus === 'cancelled' && !empty($result['payload']['cancellation_reason'])) {
                $extraCols['cancellation_reason'] = $result['payload']['cancellation_reason'];
            }
            $trResult = OrderStateMachine::transitionOrder(
                $pdo, $orderId, $tenantId, 0, $newStatus, $flags, $extraCols
            );
            if ($trResult['success']) {
                $didBumpStatus = true;
            } else {
                error_log(sprintf(
                    '[ChoiceQR Events] OSM transition rejected: %s → %s for order %s: %s',
                    $trResult['old_status'], $newStatus, $orderId, $trResult['message']
                ));
            }
        }

        // ── Delivery status update ──
        if ($newDeliveryStatus !== null && $newDeliveryStatus !== ($order['delivery_status'] ?? '')) {
            $curDelivery = (string)($order['delivery_status'] ?? '');
            if (OrderStateMachine::canTransitionDelivery($curDelivery, $newDeliveryStatus)) {
                $pdo->prepare(
                    "UPDATE sh_orders SET delivery_status = :ds, updated_at = NOW()
                     WHERE id = :oid AND tenant_id = :tid"
                )->execute([':ds' => $newDeliveryStatus, ':oid' => $orderId, ':tid' => $tenantId]);
                $didBumpDelivery = true;
            } else {
                error_log("[ChoiceQR Events] Delivery transition rejected: {$curDelivery} → {$newDeliveryStatus} for order {$orderId}");
            }
        }

        // ── Payment status update (no state machine — direct update) ──
        if ($newPaymentStatus !== null && $newPaymentStatus !== ($order['payment_status'] ?? '')) {
            $pdo->prepare(
                "UPDATE sh_orders SET payment_status = :ps, updated_at = NOW()
                 WHERE id = :oid AND tenant_id = :tid"
            )->execute([':ps' => $newPaymentStatus, ':oid' => $orderId, ':tid' => $tenantId]);
            $didBumpPayment = true;
        }

        // ── Publish internal event (transactional outbox) ──
        if ($parsedEventType !== null && ($didBumpStatus || $didBumpDelivery || $didBumpPayment)) {
            $context = array_merge(
                $result['payload'] ?? [],
                [
                    'source'              => 'choiceqr_event',
                    'gateway_external_id' => $externalOrderId,
                    'event_id'            => $eventId,
                ]
            );
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenantId, $parsedEventType, $orderId, $context,
                [
                    'source'         => 'gateway',
                    'actorType'      => 'external_api',
                    'actorId'        => 'choiceqr:' . $varSymbol,
                    'idempotencyKey' => $eventId . ':' . $parsedEventType,
                ]
            );
        }

        // ── Store event ref for idempotency (fallback when no sh_inbound_callbacks) ──
        if (!$hasInboundTable) {
            GatewayAuth::storeExternalRef(
                $pdo, $tenantId, 'choiceqr_event', $eventId,
                $orderId, null, hash('sha256', $raw)
            );
        }

        $pdo->commit();

    } catch (Throwable $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txErr;
    }

    // -------------------------------------------------------------------------
    // 10. FINALIZE — update callback log + 200 OK empty body
    // -------------------------------------------------------------------------
    $cqrUpdateCallback([
        'status'          => 'processed',
        'mapped_order_id' => $orderId,
        'processed_at'    => date('Y-m-d H:i:s'),
    ]);

    cqr_ev_ok();

} catch (Throwable $e) {
    error_log('[ChoiceQR Events] FATAL: ' . $e->getMessage());
    cqr_ev_fail(500, 'Internal server error: ' . $e->getMessage());
}
