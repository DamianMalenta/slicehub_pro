<?php
declare(strict_types=1);

/**
 * SliceHub — SSE (Server-Sent Events) endpoint dla Tracker v2.
 *
 * Klient (online_track.js) podłącza się przez EventSource:
 *   new EventSource('/slicehub/api/online/sse.php?tenant=1&token=TOKEN&phone=PHONE')
 *
 * Flow:
 *   1. Walidacja tracking_token + customer_phone (jak track_order)
 *   2. Stream pętli: co SSE_POLL_MS sprawdź sh_sse_broadcast dla tego tokenu
 *   3. Jeśli są nowe rekordy → wyślij jako SSE event, usuń je
 *   4. Wyślij keepalive co 30s (: komentarz SSE) żeby proxy nie ucięło
 *   5. Timeout po SSE_TIMEOUT_S → klient reconnectuje automatycznie
 *
 * Bezpieczeństwo:
 *   - tracking_token + phone muszą pasować do sh_orders (jak track_order)
 *   - Brak auth tokenu pracownika — publiczny endpoint klienta
 *   - Ten plik NIE wymaga auth_guard.php
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

// SSE headers — muszą być przed jakimkolwiek outputem
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // nginx: wyłącz buforowanie
header('Access-Control-Allow-Origin: *');

// Wyłącz output buffering
if (ob_get_level()) ob_end_clean();

const SSE_POLL_MS      = 2000;   // ms między sprawdzeniami tabeli
const SSE_TIMEOUT_S    = 280;    // s po których serwer zamknie połączenie (klient reconnectuje)
const SSE_KEEPALIVE_S  = 30;     // s między keepalive comments

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

// ── Bootstrap ────────────────────────────────────────────────────────────────
$tenantId = (int)($_GET['tenant'] ?? 0);
$token    = trim((string)($_GET['token']  ?? ''));
$phone    = trim((string)($_GET['phone']  ?? ''));

if ($tenantId <= 0 || $token === '' || $phone === '') {
    sseSend('error', json_encode(['message' => 'tenant, token and phone are required']));
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
} catch (\Throwable $e) {
    sseSend('error', json_encode(['message' => 'DB unavailable']));
    exit;
}

// Walidacja: token + phone muszą pasować do sh_orders (jak track_order)
try {
    $stmtV = $pdo->prepare(
        "SELECT id FROM sh_orders
         WHERE tenant_id = :tid AND tracking_token = :tok AND customer_phone = :phone
           AND status NOT IN ('cancelled')
         LIMIT 1"
    );
    $stmtV->execute([':tid' => $tenantId, ':tok' => $token, ':phone' => $phone]);
    $orderId = $stmtV->fetchColumn();
} catch (\Throwable $e) {
    sseSend('error', json_encode(['message' => 'Validation error']));
    exit;
}

if (!$orderId) {
    sseSend('error', json_encode(['message' => 'Order not found or token invalid']));
    exit;
}

// ── Stream loop ──────────────────────────────────────────────────────────────
$startTime   = time();
$lastKeepalive = 0;
$lastEventId   = 0; // ID ostatniego odebranego rekordu z sh_sse_broadcast

// Wyślij initial connected event
sseSend('connected', json_encode(['order_id' => $orderId, 'token' => $token]));

while (true) {
    // Timeout — klient reconnectuje z Last-Event-ID
    if ((time() - $startTime) >= SSE_TIMEOUT_S) {
        sseSend('timeout', json_encode(['reconnect' => true]));
        break;
    }

    // Keepalive
    if ((time() - $lastKeepalive) >= SSE_KEEPALIVE_S) {
        sseKeepalive();
        $lastKeepalive = time();
    }

    // Sprawdź sh_sse_broadcast dla nowych eventów
    try {
        $stmtB = $pdo->prepare(
            "SELECT id, event_type, payload_json
             FROM sh_sse_broadcast
             WHERE tracking_token = :tok AND tenant_id = :tid AND id > :lid
             ORDER BY id ASC
             LIMIT 10"
        );
        $stmtB->execute([':tok' => $token, ':tid' => $tenantId, ':lid' => $lastEventId]);
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

            // Usuń dostarczone rekordy (TTL cleanup)
            $maxId = max(array_column($rows, 'id'));
            $pdo->prepare(
                "DELETE FROM sh_sse_broadcast WHERE tracking_token = :tok AND id <= :mid"
            )->execute([':tok' => $token, ':mid' => $maxId]);
        }
    } catch (\Throwable $e) {
        sseKeepalive(); // nie przerywaj streamu przy błędzie DB
    }

    // Cleanup starych rekordów (>10 min) żeby tabela nie rosła
    if (rand(1, 50) === 1) {
        try {
            $pdo->prepare(
                "DELETE FROM sh_sse_broadcast WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            )->execute();
        } catch (\Throwable $e) {}
    }

    // Sprawdź czy klient nadal podłączony
    if (connection_aborted()) {
        break;
    }

    usleep(SSE_POLL_MS * 1000);
}
