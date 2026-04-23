<?php

declare(strict_types=1);

/**
 * Settings Engine — unified action dispatcher dla panelu admin.
 *
 * Obsługuje zarządzanie:
 *   • Integration adapters (sh_tenant_integrations)     — CRUD + test_ping
 *   • Webhook endpoints (sh_webhook_endpoints)          — CRUD + rotate_secret + test_ping
 *   • Gateway API keys (sh_gateway_api_keys)            — list + generate + revoke
 *   • DLQ management (sh_webhook_deliveries, sh_integration_deliveries) — list + replay
 *   • Health dashboard (outbox/deliveries stats)        — summary
 *
 * Wszystkie credentials są szyfrowane przez CredentialVault przy zapisie i
 * pokazywane jako `"••••"` w responsach listowych (redacted). Pełny dostęp do
 * rozszyfrowanej wartości zostaje tylko WEWNĄTRZ adapterów/workerów.
 *
 * Każda mutacja:
 *   • Wymaga jawnego tenant_id z sesji.
 *   • Loguje do sh_order_audit (reuse) lub error_log.
 *   • Używa prepared statements.
 *
 * Response envelope: {success: bool, data: mixed, message?: string}.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/db_config.php';
require_once __DIR__ . '/../../core/auth_guard.php';
require_once __DIR__ . '/../../core/CredentialVault.php';
require_once __DIR__ . '/../../core/GatewayAuth.php';
require_once __DIR__ . '/../../core/Integrations/BaseAdapter.php';
require_once __DIR__ . '/../../core/Integrations/PapuAdapter.php';
require_once __DIR__ . '/../../core/Integrations/DotykackaAdapter.php';
require_once __DIR__ . '/../../core/Integrations/GastroSoftAdapter.php';
require_once __DIR__ . '/../../core/Integrations/AdapterRegistry.php';

use SliceHub\Integrations\AdapterRegistry;

// tenant_id, user_id ustawione w auth_guard.
$input = $_POST;
if (empty($input) && !empty($_SERVER['CONTENT_TYPE']) && str_contains((string)$_SERVER['CONTENT_TYPE'], 'application/json')) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) $input = $decoded;
}
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

function settings_respond(bool $ok, $data = null, ?string $msg = null, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit;
}

function settings_redactCredentials(?string $stored): string
{
    if ($stored === null || $stored === '') return '';
    if (class_exists('CredentialVault') && CredentialVault::isEncrypted($stored)) return '••••(vault)';
    $decoded = json_decode($stored, true);
    if (is_array($decoded)) {
        $keys = array_keys($decoded);
        return '••••(' . implode(',', array_slice($keys, 0, 4)) . (count($keys) > 4 ? '…' : '') . ')';
    }
    return '••••';
}

function settings_requireTableOrFail(PDO $pdo, string $table): void
{
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 0")->closeCursor();
    } catch (PDOException $e) {
        settings_respond(false, null, "Table {$table} missing — run scripts/setup_database.php (migration outstanding)", 503);
    }
}

/**
 * CSRF — double-submit token.
 * Client GET'uje token przez action=csrf_token (zapis w sesji), wysyła z powrotem
 * w header `X-CSRF-Token` przy każdej mutacji. Serwer hash_equals porównuje.
 *
 * Whitelist akcji READ-ONLY (nie wymagają CSRF): *_list, health_summary, csrf_token.
 */
function settings_csrfCheck(string $action): void
{
    $readOnly = [
        'integrations_list', 'webhooks_list', 'api_keys_list',
        'dlq_list', 'inbound_list', 'health_summary', 'csrf_token',
        'notifications_channels_list', 'notifications_routes_get',
        'notifications_templates_get',
    ];
    if (in_array($action, $readOnly, true)) return;

    $sessionToken = $_SESSION['settings_csrf_token'] ?? '';
    $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers() ?: [];
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0) {
                $providedToken = (string)$v;
                break;
            }
        }
    }

    if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
        settings_respond(false, null, 'CSRF token missing or invalid. GET action=csrf_token first.', 403);
    }
}

/**
 * Rate limit dla action=*_test_ping — max 5 pings na minutę per tenant.
 * Wymuszane przez sh_settings_audit (last 60 sec count).
 */
function settings_rateLimitTestPing(PDO $pdo, int $tenantId): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sh_settings_audit
             WHERE tenant_id = :tid
             AND action LIKE '%_test_ping'
             AND created_at > NOW() - INTERVAL 60 SECOND"
        );
        $stmt->execute([':tid' => $tenantId]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= 5) {
            settings_respond(false, null,
                "Rate limit: max 5 test pings per minute per tenant (current: {$count}). Wait and retry.",
                429);
        }
    } catch (PDOException $e) {
        // m029 missing → silently skip limit (better than crash)
    }
}

/**
 * Audit log — zapis mutacji do sh_settings_audit.
 * Credentials/secrets zawsze redacted przed zapisem.
 */
function settings_audit(
    PDO $pdo,
    int $tenantId,
    ?int $userId,
    string $action,
    string $entityType,
    ?int $entityId,
    ?array $before = null,
    ?array $after = null
): void {
    try {
        $pdo->query("SELECT 1 FROM sh_settings_audit LIMIT 0")->closeCursor();
    } catch (PDOException $e) {
        return; // m029 missing — silent skip
    }

    $redactFields = ['credentials', 'secret', 'key_secret_hash', 'api_key', 'api_secret', 'refresh_token', 'access_token', 'full_key'];
    $redact = function (?array $row) use ($redactFields): ?array {
        if ($row === null) return null;
        foreach ($redactFields as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $row[$k] = '••••(redacted)';
            }
        }
        return $row;
    };

    try {
        $pdo->prepare(
            "INSERT INTO sh_settings_audit
                (tenant_id, user_id, actor_ip, action, entity_type, entity_id, before_json, after_json, created_at)
             VALUES
                (:tid, :uid, :ip, :a, :et, :eid, :bj, :aj, NOW())"
        )->execute([
            ':tid' => $tenantId,
            ':uid' => $userId,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':a'   => $action,
            ':et'  => $entityType,
            ':eid' => $entityId,
            ':bj'  => $before !== null ? json_encode($redact($before), JSON_UNESCAPED_UNICODE) : null,
            ':aj'  => $after  !== null ? json_encode($redact($after),  JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (PDOException $e) {
        error_log('[settings_audit] insert failed: ' . $e->getMessage());
    }
}

// CSRF check — przed jakąkolwiek mutacją
settings_csrfCheck($action);

try {
    switch ($action) {
        // ════════════════════════════════════════════════════════════════
        // CSRF token bootstrap — klient musi pobrać przed pierwszą mutacją.
        // ════════════════════════════════════════════════════════════════
        case 'csrf_token': {
            if (empty($_SESSION['settings_csrf_token'])) {
                $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(24));
            }
            settings_respond(true, [
                'token'   => $_SESSION['settings_csrf_token'],
                'header'  => 'X-CSRF-Token',
                'expires' => null,
            ]);
        }

        // ════════════════════════════════════════════════════════════════
        // INTEGRATIONS (sh_tenant_integrations) — 3rd-party POS adapters
        // ════════════════════════════════════════════════════════════════

        case 'integrations_list': {
            settings_requireTableOrFail($pdo, 'sh_tenant_integrations');

            $stmt = $pdo->prepare(
                "SELECT id, provider, display_name, api_base_url, credentials,
                        direction, events_bridged, is_active,
                        last_sync_at, created_at, updated_at,
                        COALESCE(consecutive_failures, 0) AS consecutive_failures,
                        COALESCE(last_failure_at, NULL)   AS last_failure_at,
                        COALESCE(max_retries, 6)          AS max_retries,
                        COALESCE(timeout_seconds, 8)      AS timeout_seconds
                 FROM sh_tenant_integrations
                 WHERE tenant_id = :tid
                 ORDER BY provider, id"
            );
            $stmt->execute([':tid' => $tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$r) {
                $r['credentials_redacted'] = settings_redactCredentials($r['credentials'] ?? null);
                $r['credentials_encrypted'] = class_exists('CredentialVault') && CredentialVault::isEncrypted((string)($r['credentials'] ?? ''));
                unset($r['credentials']);
            }

            settings_respond(true, [
                'integrations'        => $rows,
                'available_providers' => AdapterRegistry::availableProviders(),
                'vault_ready'         => CredentialVault::isReady(),
            ]);
        }

        case 'integrations_save': {
            settings_requireTableOrFail($pdo, 'sh_tenant_integrations');

            $id            = isset($input['id']) ? (int)$input['id'] : 0;
            $provider      = strtolower(trim((string)($input['provider'] ?? '')));
            $displayName   = trim((string)($input['display_name'] ?? ''));
            $apiBaseUrl    = trim((string)($input['api_base_url'] ?? ''));
            $direction     = in_array(($input['direction'] ?? 'push'), ['push','pull','bidirectional'], true)
                                ? $input['direction'] : 'push';
            $eventsBridged = $input['events_bridged'] ?? ['order.created'];
            $isActive      = !empty($input['is_active']) ? 1 : 0;
            $timeoutSec    = max(1, min(30, (int)($input['timeout_seconds'] ?? 8)));
            $maxRetries    = max(1, min(20, (int)($input['max_retries']     ?? 6)));

            if ($provider === '' || $displayName === '') {
                settings_respond(false, null, 'provider and display_name are required', 400);
            }

            $known = array_keys(AdapterRegistry::availableProviders());
            if (!in_array($provider, $known, true) && $provider !== 'webhook' && $provider !== 'custom') {
                settings_respond(false, null, "Unknown provider '{$provider}'. Available: " . implode(', ', $known), 400);
            }

            if (!is_array($eventsBridged)) $eventsBridged = [];
            $eventsBridged = array_values(array_filter(array_map('strval', $eventsBridged), fn($e) => $e !== ''));
            if (empty($eventsBridged)) $eventsBridged = ['order.created'];

            // Credentials — szyfrujemy przy zapisie (idempotent: jeśli już zaszyfrowane, zostaw).
            $credentialsStorage = null;
            if (isset($input['credentials'])) {
                $credRaw = $input['credentials'];
                if (is_array($credRaw)) {
                    $credJson = json_encode($credRaw, JSON_UNESCAPED_UNICODE);
                    if ($credJson !== false && $credJson !== '{}') {
                        $credentialsStorage = CredentialVault::encrypt($credJson);
                    }
                } elseif (is_string($credRaw) && $credRaw !== '') {
                    $credentialsStorage = class_exists('CredentialVault') && CredentialVault::isEncrypted($credRaw)
                        ? $credRaw
                        : CredentialVault::encrypt($credRaw);
                }
            }

            if ($id > 0) {
                // UPDATE — zachowaj credentials jeśli nie dostaliśmy nowych
                $beforeStmt = $pdo->prepare(
                    "SELECT id, provider, display_name, api_base_url, direction,
                            events_bridged, is_active, credentials
                     FROM sh_tenant_integrations WHERE id = :id AND tenant_id = :tid"
                );
                $beforeStmt->execute([':id' => $id, ':tid' => $tenant_id]);
                $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $fields = [
                    'provider'         => $provider,
                    'display_name'     => $displayName,
                    'api_base_url'     => $apiBaseUrl !== '' ? $apiBaseUrl : null,
                    'direction'        => $direction,
                    'events_bridged'   => json_encode($eventsBridged, JSON_UNESCAPED_UNICODE),
                    'is_active'        => $isActive,
                ];
                if ($credentialsStorage !== null) {
                    $fields['credentials'] = $credentialsStorage;
                }

                // Dopisujemy health columns tylko jeśli istnieją
                try {
                    $pdo->query("SELECT timeout_seconds FROM sh_tenant_integrations LIMIT 0")->closeCursor();
                    $fields['timeout_seconds'] = $timeoutSec;
                    $fields['max_retries']     = $maxRetries;
                } catch (PDOException $e) { /* pre-m028 schema, skip */ }

                $setSql = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
                $params = $fields;
                $params['id']  = $id;
                $params['tid'] = $tenant_id;
                $stmt = $pdo->prepare(
                    "UPDATE sh_tenant_integrations SET {$setSql}, updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tid"
                );
                $bindParams = [];
                foreach ($params as $k => $v) $bindParams[':' . $k] = $v;
                $stmt->execute($bindParams);

                $afterStmt = $pdo->prepare(
                    "SELECT id, provider, display_name, api_base_url, direction,
                            events_bridged, is_active, credentials
                     FROM sh_tenant_integrations WHERE id = :id"
                );
                $afterStmt->execute([':id' => $id]);
                settings_audit($pdo, $tenant_id, $user_id ?? null, 'integrations_save', 'integration', $id,
                    $beforeRow, $afterStmt->fetch(PDO::FETCH_ASSOC) ?: null);

                settings_respond(true, ['id' => $id, 'updated' => true], 'Integration updated.');
            }

            // INSERT
            $stmt = $pdo->prepare(
                "INSERT INTO sh_tenant_integrations
                    (tenant_id, provider, display_name, api_base_url, credentials,
                     direction, events_bridged, is_active, created_at, updated_at)
                 VALUES
                    (:tid, :prov, :name, :url, :creds, :dir, :evts, :active, NOW(), NOW())"
            );
            try {
                $stmt->execute([
                    ':tid'    => $tenant_id,
                    ':prov'   => $provider,
                    ':name'   => $displayName,
                    ':url'    => $apiBaseUrl !== '' ? $apiBaseUrl : null,
                    ':creds'  => $credentialsStorage,
                    ':dir'    => $direction,
                    ':evts'   => json_encode($eventsBridged, JSON_UNESCAPED_UNICODE),
                    ':active' => $isActive,
                ]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    settings_respond(false, null, "Integration for '{$provider}' already exists — edit existing instead.", 409);
                }
                throw $e;
            }
            $newId = (int)$pdo->lastInsertId();

            // Dopisz health columns po insert (jeśli schemat m028)
            try {
                $pdo->prepare(
                    "UPDATE sh_tenant_integrations
                     SET timeout_seconds = :t, max_retries = :r
                     WHERE id = :id"
                )->execute([':t' => $timeoutSec, ':r' => $maxRetries, ':id' => $newId]);
            } catch (PDOException $e) { /* skip on legacy schema */ }

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'integrations_save', 'integration', $newId,
                null, ['provider' => $provider, 'display_name' => $displayName, 'direction' => $direction, 'is_active' => $isActive]);

            settings_respond(true, ['id' => $newId, 'created' => true], 'Integration created.');
        }

        case 'integrations_toggle': {
            $id     = (int)($input['id']     ?? 0);
            $active = !empty($input['active']) ? 1 : 0;
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $pdo->prepare(
                "UPDATE sh_tenant_integrations SET is_active = :a, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':a' => $active, ':id' => $id, ':tid' => $tenant_id]);

            // Reset consecutive_failures gdy reaktywujemy (schema m028)
            if ($active) {
                try {
                    $pdo->prepare(
                        "UPDATE sh_tenant_integrations SET consecutive_failures = 0
                         WHERE id = :id AND tenant_id = :tid"
                    )->execute([':id' => $id, ':tid' => $tenant_id]);
                } catch (PDOException $e) { /* skip */ }
            }

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'integrations_toggle', 'integration', $id,
                null, ['is_active' => $active]);
            settings_respond(true, ['id' => $id, 'is_active' => $active]);
        }

        case 'integrations_delete': {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $beforeStmt = $pdo->prepare(
                "SELECT id, provider, display_name, direction, is_active
                 FROM sh_tenant_integrations WHERE id = :id AND tenant_id = :tid"
            );
            $beforeStmt->execute([':id' => $id, ':tid' => $tenant_id]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $pdo->prepare(
                "DELETE FROM sh_tenant_integrations WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $id, ':tid' => $tenant_id]);

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'integrations_delete', 'integration', $id,
                $beforeRow, null);
            settings_respond(true, ['id' => $id, 'deleted' => true]);
        }

        case 'integrations_test_ping': {
            settings_rateLimitTestPing($pdo, $tenant_id);
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $stmt = $pdo->prepare(
                "SELECT id, tenant_id, provider, display_name, api_base_url,
                        credentials, direction, events_bridged, is_active,
                        COALESCE(timeout_seconds, 8) AS timeout_seconds,
                        COALESCE(max_retries, 6)     AS max_retries
                 FROM sh_tenant_integrations
                 WHERE id = :id AND tenant_id = :tid"
            );
            $stmt->execute([':id' => $id, ':tid' => $tenant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) settings_respond(false, null, 'Integration not found.', 404);

            $report = settings_testIntegrationPing($row);
            settings_respond(true, $report);
        }

        // ════════════════════════════════════════════════════════════════
        // WEBHOOK ENDPOINTS (sh_webhook_endpoints)
        // ════════════════════════════════════════════════════════════════

        case 'webhooks_list': {
            settings_requireTableOrFail($pdo, 'sh_webhook_endpoints');

            $stmt = $pdo->prepare(
                "SELECT id, name, url, events_subscribed, is_active,
                        max_retries, timeout_seconds, secret,
                        last_success_at, last_failure_at, consecutive_failures,
                        created_at, updated_at
                 FROM sh_webhook_endpoints
                 WHERE tenant_id = :tid
                 ORDER BY id DESC"
            );
            $stmt->execute([':tid' => $tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$r) {
                $r['secret_redacted'] = settings_redactCredentials($r['secret'] ?? null);
                $r['secret_encrypted'] = class_exists('CredentialVault') && CredentialVault::isEncrypted((string)($r['secret'] ?? ''));
                unset($r['secret']);
            }

            settings_respond(true, ['endpoints' => $rows, 'vault_ready' => CredentialVault::isReady()]);
        }

        case 'webhooks_save': {
            settings_requireTableOrFail($pdo, 'sh_webhook_endpoints');

            $id          = isset($input['id']) ? (int)$input['id'] : 0;
            $name        = trim((string)($input['name'] ?? ''));
            $url         = trim((string)($input['url']  ?? ''));
            $events      = $input['events_subscribed'] ?? ['*'];
            $isActive    = !empty($input['is_active']) ? 1 : 0;
            $maxRetries  = max(1, min(20, (int)($input['max_retries']      ?? 5)));
            $timeoutSec  = max(1, min(30, (int)($input['timeout_seconds']  ?? 5)));
            $rotateSecret = !empty($input['rotate_secret']);

            if ($name === '' || $url === '') {
                settings_respond(false, null, 'name and url are required', 400);
            }
            if (!preg_match('#^https?://#i', $url)) {
                settings_respond(false, null, 'url must start with http(s)://', 400);
            }

            if (!is_array($events)) $events = ['*'];
            $events = array_values(array_filter(array_map('strval', $events), fn($e) => $e !== ''));
            if (empty($events)) $events = ['*'];

            $newSecret = null; // raw secret — zwracany 1× przy create/rotate

            if ($id > 0) {
                // UPDATE
                $fields = [
                    'name'              => $name,
                    'url'               => $url,
                    'events_subscribed' => json_encode($events, JSON_UNESCAPED_UNICODE),
                    'is_active'         => $isActive,
                    'max_retries'       => $maxRetries,
                    'timeout_seconds'   => $timeoutSec,
                ];
                if ($rotateSecret) {
                    $newSecret = bin2hex(random_bytes(32));
                    $fields['secret'] = CredentialVault::encrypt($newSecret);
                    $fields['consecutive_failures'] = 0;
                }

                $setSql = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
                $params = $fields;
                $params['id']  = $id;
                $params['tid'] = $tenant_id;
                $stmt = $pdo->prepare(
                    "UPDATE sh_webhook_endpoints SET {$setSql}, updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tid"
                );
                $bindParams = [];
                foreach ($params as $k => $v) $bindParams[':' . $k] = $v;
                $stmt->execute($bindParams);

                settings_audit($pdo, $tenant_id, $user_id ?? null, 'webhooks_save', 'webhook', $id,
                    null, ['name' => $name, 'url' => $url, 'is_active' => $isActive, 'rotated' => (bool)$rotateSecret]);
                settings_respond(true, [
                    'id' => $id,
                    'updated' => true,
                    'new_secret' => $newSecret,
                ], $newSecret ? 'Endpoint updated. Copy new secret NOW — shown once.' : 'Endpoint updated.');
            }

            // INSERT
            $newSecret = bin2hex(random_bytes(32));
            $storedSecret = CredentialVault::encrypt($newSecret);

            $stmt = $pdo->prepare(
                "INSERT INTO sh_webhook_endpoints
                    (tenant_id, name, url, secret, events_subscribed,
                     is_active, max_retries, timeout_seconds,
                     created_at, updated_at)
                 VALUES
                    (:tid, :name, :url, :sec, :evts, :active, :maxr, :tout, NOW(), NOW())"
            );
            $stmt->execute([
                ':tid'    => $tenant_id,
                ':name'   => $name,
                ':url'    => $url,
                ':sec'    => $storedSecret,
                ':evts'   => json_encode($events, JSON_UNESCAPED_UNICODE),
                ':active' => $isActive,
                ':maxr'   => $maxRetries,
                ':tout'   => $timeoutSec,
            ]);
            $newId = (int)$pdo->lastInsertId();

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'webhooks_save', 'webhook', $newId,
                null, ['name' => $name, 'url' => $url, 'is_active' => $isActive, 'events' => $events]);

            settings_respond(true, [
                'id' => $newId,
                'created' => true,
                'new_secret' => $newSecret,
            ], 'Endpoint created. Copy secret NOW — it will be hidden after this response.');
        }

        case 'webhooks_toggle': {
            $id     = (int)($input['id']     ?? 0);
            $active = !empty($input['active']) ? 1 : 0;
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $sql = "UPDATE sh_webhook_endpoints SET is_active = :a, updated_at = NOW()";
            if ($active) $sql .= ", consecutive_failures = 0";
            $sql .= " WHERE id = :id AND tenant_id = :tid";
            $pdo->prepare($sql)->execute([':a' => $active, ':id' => $id, ':tid' => $tenant_id]);

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'webhooks_toggle', 'webhook', $id,
                null, ['is_active' => $active]);
            settings_respond(true, ['id' => $id, 'is_active' => $active]);
        }

        case 'webhooks_delete': {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $beforeStmt = $pdo->prepare(
                "SELECT id, name, url, is_active FROM sh_webhook_endpoints WHERE id = :id AND tenant_id = :tid"
            );
            $beforeStmt->execute([':id' => $id, ':tid' => $tenant_id]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $pdo->prepare(
                "DELETE FROM sh_webhook_endpoints WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $id, ':tid' => $tenant_id]);

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'webhooks_delete', 'webhook', $id,
                $beforeRow, null);
            settings_respond(true, ['id' => $id, 'deleted' => true]);
        }

        case 'webhooks_test_ping': {
            settings_rateLimitTestPing($pdo, $tenant_id);
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $stmt = $pdo->prepare(
                "SELECT id, name, url, secret, timeout_seconds
                 FROM sh_webhook_endpoints
                 WHERE id = :id AND tenant_id = :tid"
            );
            $stmt->execute([':id' => $id, ':tid' => $tenant_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) settings_respond(false, null, 'Endpoint not found.', 404);

            $report = settings_testWebhookPing($row, $tenant_id);
            settings_respond(true, $report);
        }

        // ════════════════════════════════════════════════════════════════
        // GATEWAY API KEYS (sh_gateway_api_keys)
        // ════════════════════════════════════════════════════════════════

        case 'api_keys_list': {
            settings_requireTableOrFail($pdo, 'sh_gateway_api_keys');

            $stmt = $pdo->prepare(
                "SELECT id, key_prefix, name, source, scopes,
                        rate_limit_per_min, rate_limit_per_day,
                        is_active, last_used_at, last_used_ip,
                        expires_at, created_at, revoked_at
                 FROM sh_gateway_api_keys
                 WHERE tenant_id = :tid
                 ORDER BY revoked_at IS NULL DESC, id DESC"
            );
            $stmt->execute([':tid' => $tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            settings_respond(true, ['api_keys' => $rows]);
        }

        case 'api_keys_generate': {
            settings_requireTableOrFail($pdo, 'sh_gateway_api_keys');

            $name   = trim((string)($input['name']   ?? ''));
            $source = trim((string)($input['source'] ?? 'public_api'));
            $scopes = $input['scopes'] ?? ['order:create'];
            $perMin = max(1, min(10000, (int)($input['rate_limit_per_min'] ?? 60)));
            $perDay = max(1, min(1_000_000, (int)($input['rate_limit_per_day'] ?? 10000)));
            $expiresAt = !empty($input['expires_at']) ? (string)$input['expires_at'] : null;

            if ($name === '') settings_respond(false, null, 'name required', 400);
            if (!is_array($scopes)) $scopes = [$scopes];
            $scopes = array_values(array_filter(array_map('strval', $scopes), fn($s) => $s !== ''));

            $env = (defined('GATEWAY_ENV') ? GATEWAY_ENV : 'live');
            $gen = GatewayAuth::generateKey($env);
            // $gen -> ['prefix' => 'sh_live_abc', 'rawSecret' => '...', 'fullKey' => 'sh_live_abc.<rawSecret>', 'hash' => '<sha256>']

            $stmt = $pdo->prepare(
                "INSERT INTO sh_gateway_api_keys
                    (tenant_id, key_prefix, key_secret_hash, name, source, scopes,
                     rate_limit_per_min, rate_limit_per_day, is_active, expires_at,
                     created_at, created_by)
                 VALUES
                    (:tid, :pref, :hash, :name, :src, :scopes, :pm, :pd, 1, :exp, NOW(), :by)"
            );
            $stmt->execute([
                ':tid'    => $tenant_id,
                ':pref'   => $gen['prefix'],
                ':hash'   => $gen['hash'],
                ':name'   => $name,
                ':src'    => $source,
                ':scopes' => json_encode($scopes, JSON_UNESCAPED_UNICODE),
                ':pm'     => $perMin,
                ':pd'     => $perDay,
                ':exp'    => $expiresAt,
                ':by'     => $user_id ?? null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'api_keys_generate', 'api_key', $newId,
                null, ['name' => $name, 'source' => $source, 'scopes' => $scopes, 'prefix' => $gen['prefix']]);

            settings_respond(true, [
                'id'        => $newId,
                'full_key'  => $gen['fullKey'],
                'prefix'    => $gen['prefix'],
                'source'    => $source,
                'scopes'    => $scopes,
            ], 'API key created. Copy FULL key NOW — it will not be shown again.');
        }

        case 'api_keys_revoke': {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) settings_respond(false, null, 'id required', 400);

            $pdo->prepare(
                "UPDATE sh_gateway_api_keys
                 SET is_active = 0, revoked_at = NOW()
                 WHERE id = :id AND tenant_id = :tid AND revoked_at IS NULL"
            )->execute([':id' => $id, ':tid' => $tenant_id]);

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'api_keys_revoke', 'api_key', $id, null, ['revoked' => true]);
            settings_respond(true, ['id' => $id, 'revoked' => true]);
        }

        // ════════════════════════════════════════════════════════════════
        // DLQ MANAGEMENT (sh_webhook_deliveries DEAD + sh_integration_deliveries DEAD)
        // ════════════════════════════════════════════════════════════════

        case 'dlq_list': {
            $channel = (string)($input['channel'] ?? 'all'); // webhooks | integrations | all
            $limit   = max(1, min(200, (int)($input['limit'] ?? 50)));

            $webhooks = [];
            $integrations = [];

            if ($channel === 'all' || $channel === 'webhooks') {
                try {
                    $stmt = $pdo->prepare(
                        "SELECT eo.id, eo.event_type, eo.aggregate_id, eo.attempts,
                                eo.last_error, eo.created_at
                         FROM sh_event_outbox eo
                         WHERE eo.tenant_id = :tid AND eo.status = 'dead'
                         ORDER BY eo.id DESC LIMIT {$limit}"
                    );
                    $stmt->execute([':tid' => $tenant_id]);
                    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (PDOException $e) { /* outbox missing, skip */ }
            }

            if ($channel === 'all' || $channel === 'integrations') {
                try {
                    $stmt = $pdo->prepare(
                        "SELECT d.id, d.provider, d.event_type, d.aggregate_id,
                                d.attempts, d.last_error, d.http_code, d.created_at,
                                d.last_attempted_at, d.completed_at
                         FROM sh_integration_deliveries d
                         WHERE d.tenant_id = :tid AND d.status = 'dead'
                         ORDER BY d.id DESC LIMIT {$limit}"
                    );
                    $stmt->execute([':tid' => $tenant_id]);
                    $integrations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (PDOException $e) { /* integration tables missing, skip */ }
            }

            settings_respond(true, [
                'webhooks'     => $webhooks,
                'integrations' => $integrations,
                'counts' => [
                    'webhooks'     => count($webhooks),
                    'integrations' => count($integrations),
                ],
            ]);
        }

        case 'dlq_replay': {
            $channel = (string)($input['channel'] ?? '');
            $id      = (int)($input['id']      ?? 0);
            if ($id <= 0 || !in_array($channel, ['webhooks', 'integrations'], true)) {
                settings_respond(false, null, 'channel (webhooks|integrations) and id are required', 400);
            }

            if ($channel === 'webhooks') {
                $stmt = $pdo->prepare(
                    "UPDATE sh_event_outbox
                     SET status = 'pending', attempts = 0, next_attempt_at = NOW(),
                         last_error = CONCAT('REPLAY ', NOW(), ' | ', IFNULL(last_error,''))
                     WHERE id = :id AND tenant_id = :tid AND status = 'dead'"
                );
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE sh_integration_deliveries
                     SET status = 'pending', attempts = 0, next_attempt_at = NOW(),
                         last_error = CONCAT('REPLAY ', NOW(), ' | ', IFNULL(last_error,''))
                     WHERE id = :id AND tenant_id = :tid AND status = 'dead'"
                );
            }
            $stmt->execute([':id' => $id, ':tid' => $tenant_id]);
            $affected = $stmt->rowCount();

            if ($affected === 0) settings_respond(false, null, 'Record not found or already re-queued.', 404);

            settings_audit($pdo, $tenant_id, $user_id ?? null, 'dlq_replay', 'dlq', $id,
                null, ['channel' => $channel]);

            settings_respond(true, [
                'channel'  => $channel,
                'id'       => $id,
                'replayed' => true,
            ], "Event re-queued. Worker will pick it up next batch.");
        }

        // ════════════════════════════════════════════════════════════════
        // HEALTH DASHBOARD
        // ════════════════════════════════════════════════════════════════

        case 'health_summary': {
            $summary = [
                'vault_ready'       => CredentialVault::isReady(),
                'vault_has_sodium'  => CredentialVault::isSodiumAvailable(),
                'outbox' => null,
                'webhooks' => null,
                'integrations' => null,
                'api_keys' => null,
                'inbound' => null,
                'plaintext' => null,
            ];

            // Outbox stats
            try {
                $stmt = $pdo->prepare(
                    "SELECT status, COUNT(*) AS n FROM sh_event_outbox
                     WHERE tenant_id = :tid AND created_at > NOW() - INTERVAL 7 DAY
                     GROUP BY status"
                );
                $stmt->execute([':tid' => $tenant_id]);
                $summary['outbox'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (PDOException $e) {}

            // Webhook endpoints health
            try {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            SUM(is_active) AS active,
                            SUM(CASE WHEN consecutive_failures >= max_retries THEN 1 ELSE 0 END) AS paused
                     FROM sh_webhook_endpoints WHERE tenant_id = :tid"
                );
                $stmt->execute([':tid' => $tenant_id]);
                $summary['webhooks'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {}

            // Integrations health
            try {
                $stmt = $pdo->prepare(
                    "SELECT provider, is_active,
                            COALESCE(consecutive_failures, 0) AS consecutive_failures,
                            last_sync_at, last_failure_at
                     FROM sh_tenant_integrations WHERE tenant_id = :tid"
                );
                $stmt->execute([':tid' => $tenant_id]);
                $summary['integrations'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {}

            // Gateway API keys
            try {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            SUM(CASE WHEN is_active=1 AND revoked_at IS NULL THEN 1 ELSE 0 END) AS active
                     FROM sh_gateway_api_keys WHERE tenant_id = :tid"
                );
                $stmt->execute([':tid' => $tenant_id]);
                $summary['api_keys'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {}

            // Inbound callbacks (m029) — 24h snapshot per provider+status + signature failures.
            // Tabela może być jeszcze niezmigrowana na starych środowiskach → graceful fallback.
            try {
                $stmt = $pdo->prepare(
                    "SELECT provider, status, COUNT(*) AS n
                     FROM sh_inbound_callbacks
                     WHERE tenant_id = :tid AND received_at > NOW() - INTERVAL 24 HOUR
                     GROUP BY provider, status"
                );
                $stmt->execute([':tid' => $tenant_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $byProvider = [];
                $totals = ['pending' => 0, 'processed' => 0, 'rejected' => 0, 'ignored' => 0, 'error' => 0];
                foreach ($rows as $r) {
                    $p = (string)$r['provider'];
                    $s = (string)$r['status'];
                    $n = (int)$r['n'];
                    $byProvider[$p] = $byProvider[$p] ?? [];
                    $byProvider[$p][$s] = $n;
                    if (isset($totals[$s])) $totals[$s] += $n;
                }

                // Niezweryfikowane signature w ostatnich 24h — wczesny sygnał ataku / złej konfiguracji.
                $stmt2 = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_inbound_callbacks
                     WHERE tenant_id = :tid
                       AND received_at > NOW() - INTERVAL 24 HOUR
                       AND signature_verified = 0"
                );
                $stmt2->execute([':tid' => $tenant_id]);
                $badSig = (int)$stmt2->fetchColumn();

                $summary['inbound'] = [
                    'window_hours'         => 24,
                    'totals'               => $totals,
                    'by_provider'          => $byProvider,
                    'bad_signature_count'  => $badSig,
                ];
            } catch (PDOException $e) {
                $summary['inbound'] = ['error' => 'table_missing_or_legacy'];
            }

            // Plaintext credentials counter — driver banneru „rotate now".
            // Liczy integracje + webhooki gdzie credential/secret nie jest w formacie vault:v1:...
            // (pusty sekret = nie liczymy, niektóre webhooki nie mają secretu).
            try {
                // Wire-level prefix zdefiniowany w CredentialVault::encrypt() ("vault:v1:<base64>").
                // Stała sama jest private → duplikujemy format tutaj zgodnie z kontraktem klasy.
                $vaultPrefix = 'vault:v1:';

                $q1 = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_tenant_integrations
                     WHERE tenant_id = :tid
                       AND credentials IS NOT NULL AND credentials <> ''
                       AND credentials NOT LIKE :pfx"
                );
                $q1->execute([':tid' => $tenant_id, ':pfx' => $vaultPrefix . '%']);
                $intPlain = (int)$q1->fetchColumn();

                $q2 = $pdo->prepare(
                    "SELECT COUNT(*) FROM sh_webhook_endpoints
                     WHERE tenant_id = :tid
                       AND secret IS NOT NULL AND secret <> ''
                       AND secret NOT LIKE :pfx"
                );
                $q2->execute([':tid' => $tenant_id, ':pfx' => $vaultPrefix . '%']);
                $whPlain = (int)$q2->fetchColumn();

                $summary['plaintext'] = [
                    'integrations' => $intPlain,
                    'webhooks'     => $whPlain,
                    'total'        => $intPlain + $whPlain,
                    'rotate_cmd'   => 'php scripts/rotate_credentials_to_vault.php',
                ];
            } catch (PDOException $e) {
                $summary['plaintext'] = ['error' => 'query_failed'];
            }

            settings_respond(true, $summary);
        }

        // ════════════════════════════════════════════════════════════════
        // INBOUND CALLBACKS (m029) — read-only observability
        // ════════════════════════════════════════════════════════════════

        case 'inbound_list': {
            // Brak wymogu audytu — read-only. Tabela może nie istnieć na
            // środowiskach bez m029 → graceful "empty".
            $limit    = max(1, min(500, (int)($input['limit'] ?? 100)));
            $provider = trim((string)($input['provider'] ?? ''));
            $status   = trim((string)($input['status'] ?? ''));

            $where = ['tenant_id = :tid OR tenant_id IS NULL'];
            $params = [':tid' => $tenant_id];
            if ($provider !== '') {
                $where[] = 'provider = :prov';
                $params[':prov'] = $provider;
            }
            if ($status !== '' && in_array($status, ['pending','processed','rejected','ignored','error'], true)) {
                $where[] = 'status = :st';
                $params[':st'] = $status;
            }

            try {
                $sql = "SELECT id, tenant_id, integration_id, provider, external_event_id,
                               external_ref, event_type, mapped_order_id,
                               signature_verified, status, error_message,
                               remote_ip, received_at, processed_at,
                               LEFT(COALESCE(raw_body, ''), 400) AS raw_body_preview
                        FROM sh_inbound_callbacks
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY received_at DESC
                        LIMIT " . $limit;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Count per status dla headera UI.
                $cnt = $pdo->prepare(
                    "SELECT status, COUNT(*) AS n
                     FROM sh_inbound_callbacks
                     WHERE tenant_id = :tid
                       AND received_at > NOW() - INTERVAL 24 HOUR
                     GROUP BY status"
                );
                $cnt->execute([':tid' => $tenant_id]);
                $counts24h = $cnt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

                settings_respond(true, [
                    'rows'       => $rows,
                    'counts_24h' => $counts24h,
                    'table_ready'=> true,
                ]);
            } catch (PDOException $e) {
                settings_respond(true, [
                    'rows'       => [],
                    'counts_24h' => [],
                    'table_ready'=> false,
                    'note'       => 'Tabela sh_inbound_callbacks niedostępna — uruchom migration 029.',
                ]);
            }
        }

        // ════════════════════════════════════════════════════════════════
        default:
            // Let notifications_* fall through to handlers below the switch.
            // Every other unknown action is rejected at the end of the file.
            if (!str_starts_with((string)$action, 'notifications_')) {
                settings_respond(false, null, "Unknown action: '{$action}'", 400);
            }
            break;
    }
} catch (PDOException $e) {
    error_log('[api/settings/engine.php] PDOException: ' . $e->getMessage());
    settings_respond(false, null, 'Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_log('[api/settings/engine.php] Throwable: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    settings_respond(false, null, 'Server error: ' . $e->getMessage(), 500);
}

// ────────────────────────────────────────────────────────────────────────
// Helpers dla Test Ping (synthetic event → adapter → transport)
// ────────────────────────────────────────────────────────────────────────

/**
 * Test ping dla integration adaptera — buduje syntetyczny order.created envelope,
 * wywołuje adapter.buildRequest() + wysyła cURL z timeoutem, zwraca raport.
 */
function settings_testIntegrationPing(array $integrationRow): array
{
    $t0 = microtime(true);

    $provider = (string)($integrationRow['provider'] ?? '');
    $known = AdapterRegistry::availableProviders();
    if (!isset($known[$provider])) {
        return [
            'ok'       => false,
            'stage'    => 'resolve',
            'message'  => "No adapter class for provider '{$provider}'",
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
        ];
    }

    // Wzbogać wiersz o defaulty health columns
    $row = $integrationRow + [
        'timeout_seconds' => 8,
        'max_retries' => 6,
        'consecutive_failures' => 0,
    ];

    // Instancjonuj adapter poza AdapterRegistry by nie dotknąć cache.
    $providerMap = AdapterRegistry::availableProviders();
    $class = null;
    foreach ([
        'SliceHub\\Integrations\\PapuAdapter',
        'SliceHub\\Integrations\\DotykackaAdapter',
        'SliceHub\\Integrations\\GastroSoftAdapter',
    ] as $candidate) {
        if (class_exists($candidate) && $candidate::providerKey() === $provider) {
            $class = $candidate;
            break;
        }
    }
    if ($class === null) {
        return ['ok' => false, 'stage' => 'resolve', 'message' => "No adapter class matched '{$provider}'",
                'duration_ms' => (int)((microtime(true) - $t0) * 1000)];
    }

    $adapter = new $class($row);

    $envelope = settings_buildSyntheticEnvelope((int)$row['tenant_id']);

    try {
        $req = $adapter->buildRequest($envelope);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'stage' => 'buildRequest',
            'message' => $e->getMessage(),
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
        ];
    }

    $transportT0 = microtime(true);
    $http = settings_curlRequest(
        (string)$req['method'], (string)$req['url'], (array)$req['headers'], (string)$req['body'],
        max(1, (int)($row['timeout_seconds'] ?? 8))
    );
    $transportMs = (int)((microtime(true) - $transportT0) * 1000);

    $verdict = $adapter->parseResponse((int)$http['code'], (string)$http['body'], $http['error']);

    return [
        'ok'          => (bool)($verdict['ok'] ?? false),
        'stage'       => ($verdict['ok'] ?? false) ? 'delivered' : 'rejected',
        'http_code'   => (int)$http['code'],
        'transport_error' => $http['error'],
        'transient'   => (bool)($verdict['transient'] ?? false),
        'external_ref' => $verdict['externalRef'] ?? null,
        'error'       => $verdict['error'] ?? null,
        'request_preview' => [
            'method'  => $req['method'],
            'url'     => $req['url'],
            'headers_count' => count($req['headers']),
            'body_bytes' => strlen($req['body']),
            'body_preview' => substr($req['body'], 0, 300),
        ],
        'response_preview' => substr((string)$http['body'], 0, 500),
        'duration_ms' => (int)((microtime(true) - $t0) * 1000),
        'transport_ms' => $transportMs,
    ];
}

/**
 * Test ping dla webhook endpointu — signed HMAC POST z synthetic envelope.
 */
function settings_testWebhookPing(array $endpointRow, int $tenantId): array
{
    $t0 = microtime(true);

    $secret = (string)$endpointRow['secret'];
    if (class_exists('CredentialVault') && CredentialVault::isEncrypted($secret)) {
        $decrypted = CredentialVault::decrypt($secret);
        if ($decrypted === null) {
            return [
                'ok'      => false,
                'stage'   => 'decrypt',
                'message' => 'secret decrypt failed — check SLICEHUB_VAULT_KEY',
                'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            ];
        }
        $secret = $decrypted;
    }

    $envelope = settings_buildSyntheticEnvelope($tenantId, true);
    $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'stage' => 'encode', 'message' => 'json_encode failed',
                'duration_ms' => (int)((microtime(true) - $t0) * 1000)];
    }

    $ts = time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'User-Agent: SliceHub-Webhooks/1.0 (test-ping)',
        "X-Slicehub-Event: {$envelope['event_type']}",
        "X-Slicehub-Test: 1",
        "X-Slicehup-Timestamp: {$ts}",
        "X-Slicehub-Signature: t={$ts},v1={$sig}",
    ];

    $transportT0 = microtime(true);
    $http = settings_curlRequest('POST', (string)$endpointRow['url'], $headers, $body,
        max(1, (int)$endpointRow['timeout_seconds']));
    $transportMs = (int)((microtime(true) - $transportT0) * 1000);

    $isOk = ($http['code'] >= 200 && $http['code'] < 300);

    return [
        'ok'          => $isOk,
        'stage'       => $isOk ? 'delivered' : ($http['error'] ? 'transport' : 'http_error'),
        'http_code'   => (int)$http['code'],
        'transport_error' => $http['error'],
        'response_preview' => substr((string)$http['body'], 0, 500),
        'signature'   => "t={$ts},v1={$sig}",
        'duration_ms' => (int)((microtime(true) - $t0) * 1000),
        'transport_ms' => $transportMs,
    ];
}

function settings_buildSyntheticEnvelope(int $tenantId, bool $forWebhook = false): array
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $fakeOrderId = 'test-' . bin2hex(random_bytes(6));

    $envelope = [
        'event_id'       => 'test-' . bin2hex(random_bytes(8)),
        'event_type'     => 'order.created',
        'aggregate_id'   => $fakeOrderId,
        'aggregate_type' => 'order',
        'tenant_id'      => $tenantId,
        'source'         => 'test_ping',
        'actor_type'     => 'system',
        'actor_id'       => null,
        'occurred_at'    => $now,
        'attempt'        => 1,
        'payload' => [
            '_test_ping' => true,
            'order' => [
                'id'              => $fakeOrderId,
                'order_number'    => 'TST/' . date('Ymd') . '/0001',
                'tenant_id'       => $tenantId,
                'order_type'      => 'takeaway',
                'channel'         => 'Takeaway',
                'payment_method'  => 'cash',
                'payment_status'  => 'unpaid',
                'customer_name'   => 'Test Ping',
                'customer_phone'  => '+48 600 000 000',
                'delivery_address' => null,
                'subtotal_grosze'     => 3000,
                'grand_total_grosze'  => 3000,
                'discount_grosze'     => 0,
                'delivery_fee_grosze' => 0,
                'promised_time'   => null,
                'gateway_source'  => 'test_ping',
                'gateway_external_id' => null,
                'lines' => [
                    [
                        'item_sku'              => 'TEST_ITEM',
                        'snapshot_name'         => 'Test Pizza Margherita',
                        'quantity'              => 1,
                        'unit_price_grosze'     => 3000,
                        'line_total_grosze'     => 3000,
                        'vat_rate'              => 23.0,
                        'modifiers_json'        => '[]',
                        'removed_ingredients_json' => '[]',
                        'comment'               => null,
                    ],
                ],
            ],
            '_context' => [
                'test_ping' => true,
                'gateway_source' => 'test_ping',
            ],
        ],
    ];

    if ($forWebhook) {
        $envelope['delivery_id'] = 0; // placeholder
    }
    return $envelope;
}

function settings_curlRequest(string $method, string $url, array $headers, string $body, int $timeout): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== '' && $method !== 'GET' && $method !== 'DELETE') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $respBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'code'  => $httpCode,
        'body'  => is_string($respBody) ? $respBody : '',
        'error' => $err,
    ];
}

// =============================================================================
// NOTIFICATION DIRECTOR — channels, routes, templates, test-send
// =============================================================================

// ── notifications_channels_list ────────────────────────────────────────────
if ($action === 'notifications_channels_list') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, channel_type, provider, is_active, priority,
                    rate_limit_per_hour, rate_limit_per_day,
                    consecutive_failures, paused_until,
                    last_health_check_at, last_health_status,
                    created_at
             FROM sh_notification_channels
             WHERE tenant_id = :tid
             ORDER BY priority ASC, id ASC"
        );
        $stmt->execute([':tid' => $tenant_id]);
        settings_respond(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_channels_upsert ─────────────────────────────────────────
if ($action === 'notifications_channels_upsert') {
    $channelId   = (int)($input['id'] ?? 0);
    $name        = trim((string)($input['name']         ?? ''));
    $channelType = trim((string)($input['channel_type'] ?? ''));
    $provider    = trim((string)($input['provider']     ?? ''));
    $credRaw     = $input['credentials'] ?? null;
    $isActive    = isset($input['is_active'])         ? (int)(bool)$input['is_active']         : 1;
    $priority    = isset($input['priority'])           ? max(0, (int)$input['priority'])         : 10;
    $limitHour   = isset($input['rate_limit_per_hour']) && $input['rate_limit_per_hour'] !== ''
                   ? (int)$input['rate_limit_per_hour'] : null;
    $limitDay    = isset($input['rate_limit_per_day'])  && $input['rate_limit_per_day'] !== ''
                   ? (int)$input['rate_limit_per_day']  : null;

    $allowedTypes = ['in_app', 'email', 'personal_phone', 'sms_gateway'];
    if (!in_array($channelType, $allowedTypes, true)) {
        settings_respond(false, null, "Invalid channel_type: '{$channelType}'", 400);
    }

    $credJson = null;
    if ($credRaw !== null) {
        $credJson = is_array($credRaw) ? json_encode($credRaw) : (string)$credRaw;
        // PHP < 8.3: brak json_validate(); walidacja przez json_decode + json_last_error
        json_decode($credJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $credJson = '{}';
        }
    }

    try {
        if ($channelId > 0) {
            $sets  = "name=:name, channel_type=:ct, provider=:prov, is_active=:active, priority=:pri,
                      rate_limit_per_hour=:lh, rate_limit_per_day=:ld, updated_at=NOW()";
            $params = [':name'=>$name, ':ct'=>$channelType, ':prov'=>$provider, ':active'=>$isActive,
                       ':pri'=>$priority, ':lh'=>$limitHour, ':ld'=>$limitDay, ':id'=>$channelId, ':tid'=>$tenant_id];
            if ($credJson !== null) { $sets .= ', credentials_json=:cred'; $params[':cred'] = $credJson; }
            $pdo->prepare("UPDATE sh_notification_channels SET {$sets} WHERE id=:id AND tenant_id=:tid")->execute($params);
            settings_respond(true, ['id' => $channelId], 'Channel updated.');
        } else {
            $pdo->prepare(
                "INSERT INTO sh_notification_channels
                    (tenant_id, name, channel_type, provider, credentials_json, is_active, priority, rate_limit_per_hour, rate_limit_per_day)
                 VALUES (:tid, :name, :ct, :prov, :cred, :active, :pri, :lh, :ld)"
            )->execute([
                ':tid'=>$tenant_id, ':name'=>$name, ':ct'=>$channelType, ':prov'=>$provider,
                ':cred'=>$credJson ?? '{}', ':active'=>$isActive, ':pri'=>$priority, ':lh'=>$limitHour, ':ld'=>$limitDay,
            ]);
            settings_respond(true, ['id' => (int)$pdo->lastInsertId()], 'Channel created.');
        }
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_channels_delete ─────────────────────────────────────────
if ($action === 'notifications_channels_delete') {
    $channelId = (int)($input['id'] ?? 0);
    if ($channelId <= 0) settings_respond(false, null, 'id required', 400);
    try {
        $pdo->prepare("DELETE FROM sh_notification_channels WHERE id=:id AND tenant_id=:tid")
            ->execute([':id'=>$channelId, ':tid'=>$tenant_id]);
        settings_respond(true, null, 'Channel deleted.');
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_channels_test ───────────────────────────────────────────
if ($action === 'notifications_channels_test') {
    $channelId = (int)($input['id'] ?? 0);
    $recipient = trim((string)($input['recipient'] ?? ''));
    if ($channelId <= 0) settings_respond(false, null, 'id required', 400);
    if ($recipient === '') settings_respond(false, null, 'recipient (email or phone) required', 400);

    try {
        $stmt = $pdo->prepare("SELECT * FROM sh_notification_channels WHERE id=:id AND tenant_id=:tid");
        $stmt->execute([':id'=>$channelId, ':tid'=>$tenant_id]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$channel) settings_respond(false, null, 'Channel not found.', 404);

        require_once __DIR__ . '/../../core/Notifications/DeliveryResult.php';
        require_once __DIR__ . '/../../core/Notifications/ChannelInterface.php';
        require_once __DIR__ . '/../../core/Notifications/ChannelRegistry.php';

        ChannelRegistry::setChannelsDir(__DIR__ . '/../../core/Notifications/Channels');
        $impl = ChannelRegistry::get((string)$channel['channel_type']);
        if (!$impl) settings_respond(false, null, "No implementation for channel_type '{$channel['channel_type']}'.", 500);

        $cred = json_decode((string)($channel['credentials_json'] ?? '{}'), true) ?? [];
        $channelConfig = array_merge($channel, ['credentials' => $cred]);

        $result = $impl->send(
            $recipient,
            'SliceHub — test powiadomienia',
            "To jest testowa wiadomość z SliceHub. Kanał: {$channel['name']} ({$channel['channel_type']}). Działa! 🎉",
            $channelConfig,
            ['event_type' => 'test', 'tenant_id' => $tenant_id, 'pdo' => $pdo]
        );

        settings_respond($result->success, [
            'message_id'    => $result->messageId,
            'error_message' => $result->errorMessage,
        ], $result->success ? 'Wysłano pomyślnie!' : $result->errorMessage);
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_routes_get ───────────────────────────────────────────────
if ($action === 'notifications_routes_get') {
    try {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.event_type, r.channel_id, r.fallback_order,
                    r.requires_sms_consent, r.requires_marketing_consent, r.is_active,
                    c.name AS channel_name, c.channel_type, c.provider
             FROM sh_notification_routes r
             JOIN sh_notification_channels c ON c.id = r.channel_id
             WHERE r.tenant_id = :tid
             ORDER BY r.event_type ASC, r.fallback_order ASC"
        );
        $stmt->execute([':tid' => $tenant_id]);
        settings_respond(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_routes_set ───────────────────────────────────────────────
if ($action === 'notifications_routes_set') {
    // routes = [{event_type, channel_id, fallback_order, requires_sms_consent, requires_marketing_consent, is_active}, ...]
    $routes = $input['routes'] ?? [];
    if (!is_array($routes)) settings_respond(false, null, 'routes must be array', 400);

    $allowedEvents = ['order.created','order.accepted','order.preparing','order.ready',
                      'order.dispatched','order.in_delivery','order.delivered','order.completed',
                      'order.cancelled','marketing.campaign','reorder.nudge'];
    try {
        $pdo->beginTransaction();
        foreach ($routes as $r) {
            $eventType = (string)($r['event_type'] ?? '');
            $channelId = (int)($r['channel_id'] ?? 0);
            if (!in_array($eventType, $allowedEvents, true) || $channelId <= 0) continue;

            $pdo->prepare(
                "INSERT INTO sh_notification_routes
                    (tenant_id, event_type, channel_id, fallback_order, requires_sms_consent, requires_marketing_consent, is_active)
                 VALUES (:tid, :et, :cid, :fo, :rsms, :rmkt, :active)
                 ON DUPLICATE KEY UPDATE
                    fallback_order = VALUES(fallback_order),
                    requires_sms_consent = VALUES(requires_sms_consent),
                    requires_marketing_consent = VALUES(requires_marketing_consent),
                    is_active = VALUES(is_active)"
            )->execute([
                ':tid'    => $tenant_id,
                ':et'     => $eventType,
                ':cid'    => $channelId,
                ':fo'     => max(0, (int)($r['fallback_order'] ?? 0)),
                ':rsms'   => (int)!empty($r['requires_sms_consent']),
                ':rmkt'   => (int)!empty($r['requires_marketing_consent']),
                ':active' => isset($r['is_active']) ? (int)(bool)$r['is_active'] : 1,
            ]);
        }
        $pdo->commit();
        settings_respond(true, null, 'Routes saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_templates_get ────────────────────────────────────────────
if ($action === 'notifications_templates_get') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, event_type, channel_type, lang, subject, body, is_active, updated_at
             FROM sh_notification_templates
             WHERE tenant_id = :tid
             ORDER BY event_type ASC, channel_type ASC, lang ASC"
        );
        $stmt->execute([':tid' => $tenant_id]);
        settings_respond(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ── notifications_templates_set ────────────────────────────────────────────
if ($action === 'notifications_templates_set') {
    $tplId      = (int)($input['id'] ?? 0);
    $eventType  = trim((string)($input['event_type']  ?? ''));
    $channelType = trim((string)($input['channel_type'] ?? ''));
    $lang       = trim((string)($input['lang']        ?? 'pl'));
    $subject    = trim((string)($input['subject']     ?? ''));
    $body       = trim((string)($input['body']        ?? ''));
    $isActive   = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;

    if ($body === '') settings_respond(false, null, 'body is required', 400);

    try {
        if ($tplId > 0) {
            $pdo->prepare(
                "UPDATE sh_notification_templates SET subject=:sub, body=:body, is_active=:active, updated_at=NOW()
                 WHERE id=:id AND tenant_id=:tid"
            )->execute([':sub'=>$subject, ':body'=>$body, ':active'=>$isActive, ':id'=>$tplId, ':tid'=>$tenant_id]);
        } else {
            $pdo->prepare(
                "INSERT INTO sh_notification_templates (tenant_id, event_type, channel_type, lang, subject, body, is_active)
                 VALUES (:tid, :et, :ct, :lang, :sub, :body, :active)
                 ON DUPLICATE KEY UPDATE subject=VALUES(subject), body=VALUES(body), is_active=VALUES(is_active), updated_at=NOW()"
            )->execute([':tid'=>$tenant_id, ':et'=>$eventType, ':ct'=>$channelType, ':lang'=>$lang,
                        ':sub'=>$subject, ':body'=>$body, ':active'=>$isActive]);
            $tplId = (int)$pdo->lastInsertId();
        }
        settings_respond(true, ['id' => $tplId], 'Template saved.');
    } catch (Throwable $e) {
        settings_respond(false, null, $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Final fallback — tylko dla akcji notifications_* (switch powyżej fall-throughs).
// ═══════════════════════════════════════════════════════════════════════════
if (str_starts_with((string)$action, 'notifications_')) {
    settings_respond(false, null, "Unknown action: '{$action}'", 400);
}
