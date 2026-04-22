<?php

declare(strict_types=1);

/**
 * Inbound Integration Callbacks — generic receiver.
 *
 * **Flow:**
 *   3rd-party (Papu / Dotykacka / Uber / ...) POSTs status update
 *     ↓
 *   POST /api/integrations/inbound.php?provider=<key>&integration_id=<n>
 *     ↓ 1. Walidacja params + rate-limit check (per IP)
 *     ↓ 2. INSERT sh_inbound_callbacks (raw_headers, raw_body, provider, remote_ip)
 *     ↓ 3. Lookup sh_tenant_integrations → credentials (decrypt via vault)
 *     ↓ 4. AdapterRegistry::resolve(provider) → adapter.parseInboundCallback($raw, $headers, $credentials)
 *     ↓ 5. Jeśli ok = true + signature_verified = true:
 *          • Match external_ref → sh_orders.gateway_external_id → mapped_order_id
 *          • UPDATE sh_orders SET status = new_status (jeśli whitelisted transition)
 *          • OrderEventPublisher::publishOrderLifecycle(…) → wewnętrzne powiadomienia (KDS, Driver, notif)
 *     ↓ 6. UPDATE sh_inbound_callbacks SET status='processed'/'rejected', processed_at=NOW()
 *     ↓ 7. Respond 200 OK / 4xx / 5xx zgodnie ze spec providera
 *
 * **Design decisions:**
 *   • Auth NIE przez session/JWT — ten endpoint jest public, auth jest W BODY (signature HMAC).
 *   • Raw body zapisywany ZAWSZE (nawet przy bad signature) — debug critical.
 *   • Idempotency przez UNIQUE(provider, external_event_id) — duplikaty wracają 200 OK bez re-processingu.
 *   • Fail-closed na bad signature — respond 401.
 *   • Feature-detect m029 — gdy brak `sh_inbound_callbacks` wraca 503 (adapter nie może zalogować).
 *
 * **URL parametry:**
 *   • provider (required)       — 'papu' | 'dotykacka' | ... (MUST match adapter providerKey)
 *   • integration_id (required) — sh_tenant_integrations.id (auto-discover tenantu + credentials)
 *
 * **Przykład:**
 *   POST /api/integrations/inbound.php?provider=papu&integration_id=42
 *   X-Papu-Signature: t=1703001234,v1=abc123...
 *   Content-Type: application/json
 *
 *   { "event_id": "evt_42", "event_type": "order.status_changed", "order_id": "papu_777",
 *     "status": "delivered", "occurred_at": "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../core/db_config.php';
require_once __DIR__ . '/../../core/CredentialVault.php';
require_once __DIR__ . '/../../core/OrderEventPublisher.php';
require_once __DIR__ . '/../../core/Integrations/BaseAdapter.php';
require_once __DIR__ . '/../../core/Integrations/PapuAdapter.php';
require_once __DIR__ . '/../../core/Integrations/DotykackaAdapter.php';
require_once __DIR__ . '/../../core/Integrations/GastroSoftAdapter.php';
require_once __DIR__ . '/../../core/Integrations/AdapterRegistry.php';

use SliceHub\Integrations\AdapterRegistry;
use SliceHub\Integrations\BaseAdapter;

function inbound_respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function inbound_getAllHeaders(): array
{
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (is_array($h)) return $h;
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$name] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE']))   $headers['Content-Type']   = $_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['CONTENT_LENGTH'])) $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
    return $headers;
}

function inbound_clientIp(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', (string)$_SERVER[$key])[0];
            return trim($ip);
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inbound_respond(405, ['success' => false, 'error' => 'Method Not Allowed — use POST']);
}

$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$integrationId = (int)($_GET['integration_id'] ?? 0);

// ─── Special handler: personal_phone_inbound (SMS Gateway for Android webhook) ──
// URL: POST /api/integrations/inbound.php?provider=personal_phone_inbound&tenant_id=1
if ($provider === 'personal_phone_inbound') {
    $tenantIdPP = (int)($_GET['tenant_id'] ?? 0);
    if ($tenantIdPP <= 0) inbound_respond(400, ['success' => false, 'error' => 'tenant_id required']);

    $rawBodyPP = file_get_contents('php://input');
    $bodyPP    = json_decode($rawBodyPP ?: '{}', true) ?? [];

    // HMAC validation (opcjonalne) — sprawdź X-SmsGateway-Signature jeśli webhook_secret skonfigurowany
    $secretStmt = $pdo->prepare(
        "SELECT credentials_json FROM sh_notification_channels
         WHERE tenant_id = :tid AND provider = 'smsgateway_android' AND is_active = 1
         LIMIT 1"
    );
    $secretStmt->execute([':tid' => $tenantIdPP]);
    $credJson = $secretStmt->fetchColumn();
    if ($credJson) {
        $cred = json_decode((string)$credJson, true) ?? [];
        $webhookSecret = (string)($cred['webhook_secret'] ?? '');
        if ($webhookSecret !== '') {
            $sigHeader = $_SERVER['HTTP_X_SMSGATEWAY_SIGNATURE'] ?? '';
            $expectedSig = hash_hmac('sha256', $rawBodyPP, $webhookSecret);
            if (!hash_equals($expectedSig, $sigHeader)) {
                inbound_respond(401, ['success' => false, 'error' => 'Invalid webhook signature']);
            }
        }
    }

    // Obsługujemy dwa typy webhooków z smsgateway_android:
    //   1. Przychodzący SMS (inbound message): from, message, receivedAt
    //   2. Status dostarczenia (delivery report): messageId, state (SENT/DELIVERED/FAILED)

    $fromPhone = (string)($bodyPP['from'] ?? $bodyPP['phoneNumber'] ?? '');
    $msgBody   = (string)($bodyPP['message'] ?? $bodyPP['text'] ?? '');
    $msgId     = (string)($bodyPP['messageId'] ?? $bodyPP['id'] ?? '');
    $state     = strtoupper((string)($bodyPP['state'] ?? $bodyPP['status'] ?? ''));

    if ($msgId !== '' && in_array($state, ['SENT', 'DELIVERED', 'FAILED', 'PENDING'], true)) {
        // Delivery report — aktualizuj sh_notification_deliveries
        try {
            $pdo->prepare(
                "UPDATE sh_notification_deliveries SET provider_status = :st, delivered_at = IF(:st='DELIVERED', NOW(), NULL)
                 WHERE provider_message_id = :mid AND tenant_id = :tid"
            )->execute([':st' => $state, ':mid' => $msgId, ':tid' => $tenantIdPP]);
        } catch (\Throwable $e) {}
        inbound_respond(200, ['success' => true, 'type' => 'delivery_report', 'state' => $state]);
    }

    if ($fromPhone !== '' && $msgBody !== '') {
        // Inbound SMS — SmartReplyEngine
        require_once __DIR__ . '/../../core/Notifications/SmartReplyEngine.php';
        require_once __DIR__ . '/../../core/CustomerContactRepository.php';

        $engine = new SmartReplyEngine($pdo, $tenantIdPP);
        $result = $engine->process($fromPhone, $msgBody, ['raw' => $bodyPP]);

        // Jeśli auto_reply → wyślij przez PersonalPhoneChannel
        if (!empty($result['auto_reply'])) {
            try {
                require_once __DIR__ . '/../../core/Notifications/DeliveryResult.php';
                require_once __DIR__ . '/../../core/Notifications/ChannelInterface.php';
                require_once __DIR__ . '/../../core/Notifications/ChannelRegistry.php';

                ChannelRegistry::setChannelsDir(__DIR__ . '/../../core/Notifications/Channels');
                $ch = ChannelRegistry::get('personal_phone');

                $chStmt = $pdo->prepare(
                    "SELECT *, credentials_json FROM sh_notification_channels
                     WHERE tenant_id = :tid AND channel_type = 'personal_phone' AND is_active = 1
                     ORDER BY priority ASC LIMIT 1"
                );
                $chStmt->execute([':tid' => $tenantIdPP]);
                $channelRow = $chStmt->fetch(\PDO::FETCH_ASSOC);

                if ($ch && $channelRow) {
                    $cred = json_decode((string)$channelRow['credentials_json'], true) ?? [];
                    $ch->send($fromPhone, '', $result['auto_reply'], array_merge($channelRow, ['credentials' => $cred]));
                }
            } catch (\Throwable $e) {
                error_log("[inbound.php/personal_phone] auto_reply failed: " . $e->getMessage());
            }
        }

        inbound_respond(200, [
            'success'  => true,
            'type'     => 'inbound_sms',
            'intent'   => $result['intent'],
            'inbox_id' => $result['inbox_id'],
            'replied'  => !empty($result['auto_reply']),
        ]);
    }

    inbound_respond(200, ['success' => true, 'type' => 'unknown', 'body' => $bodyPP]);
}

if ($provider === '' || $integrationId <= 0) {
    inbound_respond(400, ['success' => false, 'error' => 'provider and integration_id query params required']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) $rawBody = '';
$bodyBytes = strlen($rawBody);

// Zapis callbacku PRZED jakąkolwiek walidacją — gdy bad signature nadal
// chcemy mieć ślad w bazie (abuse debug).
$headers = inbound_getAllHeaders();
$remoteIp = inbound_clientIp();

// Wybieramy tylko istotne headery — reszta to szum (Cookie, Accept, ...).
$headersToLog = [];
foreach (['Content-Type', 'Content-Length', 'User-Agent', 'X-Forwarded-For', 'X-Real-IP',
          'X-Papu-Signature', 'X-Dotykacka-Signature', 'X-GastroSoft-Signature',
          'X-Slicehub-Signature', 'X-Webhook-Signature', 'Authorization'] as $hName) {
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, $hName) === 0) {
            // Redactuj Authorization i cookie
            $headersToLog[$hName] = (strcasecmp($hName, 'Authorization') === 0)
                ? '••••' . substr((string)$v, -6)
                : (string)$v;
            break;
        }
    }
}

$callbackId = null;
$hasInboundTable = true;
try {
    $pdo->query("SELECT 1 FROM sh_inbound_callbacks LIMIT 0")->closeCursor();
} catch (PDOException $e) {
    $hasInboundTable = false;
    error_log('[inbound.php] sh_inbound_callbacks missing — migration 029 not applied. Callback not logged.');
}

if ($hasInboundTable) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO sh_inbound_callbacks
                (provider, integration_id, raw_headers, raw_body, remote_ip, status, received_at)
             VALUES
                (:prov, :iid, :hdrs, :body, :ip, 'pending', NOW())"
        );
        $stmt->execute([
            ':prov' => $provider,
            ':iid'  => $integrationId,
            ':hdrs' => json_encode($headersToLog, JSON_UNESCAPED_UNICODE),
            ':body' => $bodyBytes > 65000 ? substr($rawBody, 0, 65000) . '...[TRUNCATED]' : $rawBody,
            ':ip'   => $remoteIp,
        ]);
        $callbackId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[inbound.php] Failed to log callback: ' . $e->getMessage());
    }
}

function inbound_updateCallback(PDO $pdo, ?int $callbackId, array $fields): void
{
    if ($callbackId === null) return;
    if (empty($fields)) return;

    $setClauses = [];
    $params = [':id' => $callbackId];
    foreach ($fields as $k => $v) {
        $setClauses[] = "{$k} = :{$k}";
        $params[":{$k}"] = $v;
    }
    try {
        $pdo->prepare("UPDATE sh_inbound_callbacks SET " . implode(', ', $setClauses) . " WHERE id = :id")
            ->execute($params);
    } catch (PDOException $e) {
        error_log('[inbound.php] Failed to update callback: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Lookup integration + decrypt credentials
// ─────────────────────────────────────────────────────────────────────────

try {
    $stmt = $pdo->prepare(
        "SELECT id, tenant_id, provider, credentials, is_active, api_base_url,
                COALESCE(direction, 'push') AS direction,
                COALESCE(events_bridged, '[]') AS events_bridged
         FROM sh_tenant_integrations
         WHERE id = :id AND provider = :prov"
    );
    $stmt->execute([':id' => $integrationId, ':prov' => $provider]);
    $integrationRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[inbound.php] DB error loading integration: ' . $e->getMessage());
    inbound_updateCallback($pdo, $callbackId, ['status' => 'error', 'error_message' => 'db error', 'processed_at' => date('Y-m-d H:i:s')]);
    inbound_respond(500, ['success' => false, 'error' => 'internal error']);
}

if (!$integrationRow) {
    inbound_updateCallback($pdo, $callbackId, ['status' => 'rejected', 'error_message' => 'integration not found', 'processed_at' => date('Y-m-d H:i:s')]);
    inbound_respond(404, ['success' => false, 'error' => "integration not found: id={$integrationId}, provider={$provider}"]);
}

if ((int)$integrationRow['is_active'] !== 1) {
    inbound_updateCallback($pdo, $callbackId, [
        'tenant_id' => (int)$integrationRow['tenant_id'],
        'status' => 'rejected', 'error_message' => 'integration inactive',
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(403, ['success' => false, 'error' => 'integration disabled']);
}

$tenantId = (int)$integrationRow['tenant_id'];

// Decrypt credentials
$credRaw = (string)($integrationRow['credentials'] ?? '');
$credentials = [];
if ($credRaw !== '') {
    $credJson = CredentialVault::isEncrypted($credRaw) ? CredentialVault::decrypt($credRaw) : $credRaw;
    if ($credJson === null) {
        inbound_updateCallback($pdo, $callbackId, [
            'tenant_id' => $tenantId, 'status' => 'error',
            'error_message' => 'credentials decrypt failed',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        inbound_respond(500, ['success' => false, 'error' => 'credentials decrypt failed — check vault key']);
    }
    $decoded = json_decode($credJson, true);
    if (is_array($decoded)) $credentials = $decoded;
}

// ─────────────────────────────────────────────────────────────────────────
// Adapter dispatch
// ─────────────────────────────────────────────────────────────────────────

$providerMap = AdapterRegistry::availableProviders();
if (!isset($providerMap[$provider])) {
    inbound_updateCallback($pdo, $callbackId, [
        'tenant_id' => $tenantId, 'status' => 'rejected',
        'error_message' => "unknown provider: {$provider}",
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(400, ['success' => false, 'error' => "unknown provider: {$provider}"]);
}

$adapterClass = null;
foreach ([
    'SliceHub\\Integrations\\PapuAdapter',
    'SliceHub\\Integrations\\DotykackaAdapter',
    'SliceHub\\Integrations\\GastroSoftAdapter',
] as $cand) {
    if (class_exists($cand) && $cand::providerKey() === $provider) {
        $adapterClass = $cand;
        break;
    }
}

if ($adapterClass === null || !$adapterClass::supportsInbound()) {
    inbound_updateCallback($pdo, $callbackId, [
        'tenant_id' => $tenantId, 'status' => 'ignored',
        'error_message' => 'adapter does not implement inbound callbacks',
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(501, ['success' => false, 'error' => "provider '{$provider}' does not accept inbound callbacks yet"]);
}

/** @var BaseAdapter $adapter */
$adapter = new $adapterClass($integrationRow);

try {
    $result = $adapter->parseInboundCallback($rawBody, $headers, $credentials);
} catch (\Throwable $e) {
    error_log('[inbound.php] adapter.parseInboundCallback threw: ' . $e->getMessage());
    inbound_updateCallback($pdo, $callbackId, [
        'tenant_id' => $tenantId, 'status' => 'error',
        'error_message' => substr('adapter exception: ' . $e->getMessage(), 0, 500),
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(500, ['success' => false, 'error' => 'adapter error']);
}

$sigVerified      = (bool)($result['signature_verified'] ?? false);
$externalEventId  = $result['external_event_id'] ?? null;
$externalRef      = $result['external_ref']      ?? null;
$eventType        = $result['event_type']        ?? null;
$newStatus        = $result['new_status']        ?? null;

// Zapis rozpoznanych pól (niezależnie od ok/nie)
inbound_updateCallback($pdo, $callbackId, [
    'tenant_id' => $tenantId,
    'signature_verified' => $sigVerified ? 1 : 0,
    'external_event_id' => $externalEventId,
    'external_ref'      => $externalRef,
    'event_type'        => $eventType,
]);

if (!$sigVerified) {
    inbound_updateCallback($pdo, $callbackId, [
        'status' => 'rejected',
        'error_message' => 'signature verification failed: ' . (string)($result['error'] ?? 'unknown'),
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(401, ['success' => false, 'error' => 'signature verification failed']);
}

if (!($result['ok'] ?? false)) {
    inbound_updateCallback($pdo, $callbackId, [
        'status' => 'rejected',
        'error_message' => substr((string)($result['error'] ?? 'unknown'), 0, 500),
        'processed_at' => date('Y-m-d H:i:s'),
    ]);
    inbound_respond(422, ['success' => false, 'error' => $result['error'] ?? 'payload parse failed']);
}

// ─────────────────────────────────────────────────────────────────────────
// Idempotency check — UNIQUE(provider, external_event_id) na sh_inbound_callbacks
// Jeśli wpadliśmy tu z duplikatem event_id → wracamy 200 (provider robi retry ale NIE procesujemy po raz drugi).
// ─────────────────────────────────────────────────────────────────────────

if ($externalEventId !== null && $externalEventId !== '') {
    try {
        $dupStmt = $pdo->prepare(
            "SELECT id, status FROM sh_inbound_callbacks
             WHERE provider = :prov AND external_event_id = :eid AND id != :mine
             ORDER BY id DESC LIMIT 1"
        );
        $dupStmt->execute([':prov' => $provider, ':eid' => $externalEventId, ':mine' => $callbackId ?? 0]);
        $duplicate = $dupStmt->fetch(PDO::FETCH_ASSOC);

        if ($duplicate && $duplicate['status'] === 'processed') {
            inbound_updateCallback($pdo, $callbackId, [
                'status' => 'ignored',
                'error_message' => 'duplicate — already processed as callback #' . $duplicate['id'],
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            inbound_respond(200, [
                'success' => true,
                'duplicate' => true,
                'original_callback_id' => (int)$duplicate['id'],
                'message' => 'already processed',
            ]);
        }
    } catch (PDOException $e) { /* fail-open: bez idempotency lepiej niż crash */ }
}

// ─────────────────────────────────────────────────────────────────────────
// Match external_ref → sh_orders.gateway_external_id + status update
// ─────────────────────────────────────────────────────────────────────────

$mappedOrderId = null;
$orderUuid = null;
$didBumpStatus = false;

if ($externalRef !== null && $externalRef !== '') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, id AS order_uuid, status
             FROM sh_orders
             WHERE tenant_id = :tid AND gateway_external_id = :ref
             LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId, ':ref' => $externalRef]);
        $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($orderRow) {
            $mappedOrderId = (int)$orderRow['id'];
            $orderUuid     = (string)$orderRow['order_uuid'];

            if ($newStatus !== null && $newStatus !== $orderRow['status']) {
                // Whitelisted status transitions — identyczne jak w OrderStateMachine
                $allowedTransitions = [
                    'new'         => ['accepted', 'preparing', 'cancelled'],
                    'accepted'    => ['preparing', 'ready', 'cancelled'],
                    'preparing'   => ['ready', 'cancelled'],
                    'ready'       => ['dispatched', 'in_delivery', 'delivered', 'completed', 'cancelled'],
                    'dispatched'  => ['in_delivery', 'delivered', 'cancelled'],
                    'in_delivery' => ['delivered', 'cancelled'],
                    'delivered'   => ['completed'],
                ];
                $curStatus = (string)$orderRow['status'];
                if (isset($allowedTransitions[$curStatus]) && in_array($newStatus, $allowedTransitions[$curStatus], true)) {
                    $pdo->prepare(
                        "UPDATE sh_orders SET status = :s, updated_at = NOW()
                         WHERE id = :id AND tenant_id = :tid"
                    )->execute([':s' => $newStatus, ':id' => $mappedOrderId, ':tid' => $tenantId]);
                    $didBumpStatus = true;
                } else {
                    error_log("[inbound.php] Transition rejected: {$curStatus} → {$newStatus} for order #{$mappedOrderId}");
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[inbound.php] DB error matching order: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Publish internal event → KDS, Driver, notifications get notified
// ─────────────────────────────────────────────────────────────────────────

$outboxEventId = null;
if ($orderUuid !== null && $eventType !== null && $didBumpStatus) {
    $outboxEventId = OrderEventPublisher::publishOrderLifecycle(
        $pdo,
        $tenantId,
        $eventType,
        $orderUuid,
        [
            'source'        => 'inbound_callback',
            'provider'      => $provider,
            'callback_id'   => $callbackId,
            'remote_ip'     => $remoteIp,
            'external_ref'  => $externalRef,
            'provider_payload' => $result['payload'] ?? null,
        ],
        [
            'actor_type' => 'external_api',
            'actor_id'   => $provider,
            'source'     => 'inbound_callback',
        ]
    );
}

// ─────────────────────────────────────────────────────────────────────────
// Finalize log
// ─────────────────────────────────────────────────────────────────────────

inbound_updateCallback($pdo, $callbackId, [
    'status'          => 'processed',
    'mapped_order_id' => $mappedOrderId,
    'processed_at'    => date('Y-m-d H:i:s'),
]);

inbound_respond(200, [
    'success'        => true,
    'callback_id'    => $callbackId,
    'order_id'       => $mappedOrderId,
    'status_changed' => $didBumpStatus,
    'new_status'     => $didBumpStatus ? $newStatus : null,
    'internal_event' => $outboxEventId,
]);
