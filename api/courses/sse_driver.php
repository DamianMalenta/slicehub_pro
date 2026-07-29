<?php
declare(strict_types=1);

/**
 * SliceHub — SSE (Server-Sent Events) endpoint dla Driver App.
 *
 * Kierowca (driver_app.js) podłącza się przez EventSource:
 *   new EventSource('/slicehub/api/courses/sse_driver.php?token=JWT')
 *
 * Flow (adaptowany z api/online/sse.php — ten sam pattern, inna autoryzacja):
 *   1. Walidacja JWT tokenu (auth_guard.php) — kierowca musi być zalogowany
 *   2. Stream pętli: co SSE_POLL_MS sprawdź sh_sse_broadcast dla tego usera
 *   3. Jeśli są nowe rekordy → wyślij jako SSE event, usuń je
 *   4. Wyślij keepalive co 30s (: komentarz SSE) żeby proxy nie ucięło
 *   5. Timeout po SSE_TIMEOUT_S → klient reconnectuje automatycznie
 *
 * Bezpieczeństwo:
 *   - JWT token wymagany (Authorization: Bearer lub ?token=)
 *   - Tylko role: driver, manager, admin, owner
 *   - Eventy filtrowane per tenant_id + user_id
 *
 * Uwaga: EventSource nie wspiera nagłówków, więc JWT przekazywany w ?token=
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

// SSE headers — muszą być przed jakimkolwiek outputem
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

if (ob_get_level()) ob_end_clean();

const SSE_POLL_MS      = 2000;
const SSE_TIMEOUT_S    = 280;
const SSE_KEEPALIVE_S  = 30;

function sseSend(string $event, string $data, ?string $id = null): void
{
    if ($id !== null) {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo 'data: ' . $data . "\n\n";
    flush();
}

function sseKeepalive(): void
{
    echo ': keepalive ' . time() . "\n\n";
    flush();
}

// ── Bootstrap: JWT auth ──────────────────────────────────────────────────────
$jwtToken = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $jwtToken = trim($m[1]);
}
if ($jwtToken === '') {
    $jwtToken = trim((string)($_GET['token'] ?? ''));
}

if ($jwtToken === '') {
    sseSend('error', json_encode(['message' => 'JWT token required']));
    exit;
}

// Ustaw token w środowisku dla auth_guard.php
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtToken;

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
} catch (\Throwable $e) {
    sseSend('error', json_encode(['message' => 'Auth or DB unavailable']));
    exit;
}

// auth_guard.php ustawia $user_id, $tenant_id, $user_role
if (!isset($user_id) || $user_id <= 0) {
    sseSend('error', json_encode(['message' => 'Invalid or expired token']));
    exit;
}

$allowedRoles = ['driver', 'manager', 'admin', 'owner'];
if (!in_array(strtolower((string)($user_role ?? '')), $allowedRoles, true)) {
    sseSend('error', json_encode(['message' => 'Role not authorized for driver SSE']));
    exit;
}

$driverUserId = (int)$user_id;
$tenantId     = (int)$tenant_id;

// ── Stream loop ──────────────────────────────────────────────────────────────
$startTime     = time();
$lastKeepalive = 0;
$lastEventId   = 0;

sseSend('connected', json_encode(['user_id' => $driverUserId, 'tenant_id' => $tenantId]));

while (true) {
    if ((time() - $startTime) >= SSE_TIMEOUT_S) {
        sseSend('timeout', json_encode(['reconnect' => true]));
        break;
    }

    if ((time() - $lastKeepalive) >= SSE_KEEPALIVE_S) {
        sseKeepalive();
        $lastKeepalive = time();
    }

    // Sprawdź sh_sse_broadcast dla eventów kierowcy
    // Pattern: tracking_token = 'driver:' + user_id (konwencja dla eventów driver)
    // Lub: wszystkie eventy dla tenant_id z event_type zaczynającym się od 'order.' lub 'driver.'
    try {
        $driverKey = 'driver:' . $driverUserId;
        $stmtB = $pdo->prepare(
            "SELECT id, event_type, payload_json
             FROM sh_sse_broadcast
             WHERE tenant_id = :tid AND id > :lid
               AND (tracking_token = :dkey OR tracking_token = :tbroadcast)
             ORDER BY id ASC
             LIMIT 10"
        );
        $stmtB->execute([
            ':tid'       => $tenantId,
            ':lid'       => $lastEventId,
            ':dkey'      => $driverKey,
            ':tbroadcast' => 'broadcast:' . $tenantId,
        ]);
        $rows = $stmtB->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            foreach ($rows as $row) {
                sseSend(
                    (string)$row['event_type'],
                    (string)$row['payload_json'],
                    (string)$row['id']
                );
                $lastEventId = max($lastEventId, (int)$row['id']);
            }

            $maxId = max(array_column($rows, 'id'));
            $pdo->prepare(
                "DELETE FROM sh_sse_broadcast WHERE id <= :mid AND (tracking_token = :dkey OR tracking_token = :tbroadcast)"
            )->execute([':mid' => $maxId, ':dkey' => $driverKey, ':tbroadcast' => 'broadcast:' . $tenantId]);
        }
    } catch (\Throwable $e) {
        sseKeepalive();
    }

    if (rand(1, 50) === 1) {
        try {
            $pdo->prepare(
                "DELETE FROM sh_sse_broadcast WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            )->execute();
        } catch (\Throwable $e) {}
    }

    if (connection_aborted()) {
        break;
    }

    usleep(SSE_POLL_MS * 1000);
}
