<?php
// =============================================================================
// STATUS: LEGACY DUPLICATE (audit 2026-04-19) — not wired from any frontend.
// Functionality already exposed via `api/pos/engine.php#panic_mode`.
// DECISION PENDING: confirm engine.php version has debounce + sh_panic_log,
// then delete this file. No external callers found.
// =============================================================================
// SliceHub Enterprise — Panic Mode (Global Delay)
// POST /api/orders/panic.php
//
// Shifts promised_time forward on ALL active orders for the tenant.
// Includes a 2-minute debounce guard to prevent panic stacking from
// double-clicks or network lag.
//
// Schema: sh_orders, sh_panic_log
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

    $delayMinutes = (int)($input['delay_minutes'] ?? 20);

    if ($delayMinutes < 5 || $delayMinutes > 60) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'delay_minutes must be between 5 and 60.',
        ]);
        exit;
    }

    // =========================================================================
    // 2. ANTI-SPAM / DEBOUNCE GUARD (2-minute cooldown)
    // =========================================================================
    $stmtDebounce = $pdo->prepare(
        "SELECT COUNT(*) FROM sh_panic_log
         WHERE tenant_id = :tid AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );
    $stmtDebounce->execute([':tid' => $tenant_id]);

    if ((int)$stmtDebounce->fetchColumn() > 0) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Panic mode was triggered less than 2 minutes ago. Please wait before retrying.',
        ]);
        exit;
    }

    // =========================================================================
    // 3. HELPERS
    // =========================================================================
    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    $now = date('Y-m-d H:i:s');

    // =========================================================================
    // 4. TRANSACTIONAL UPDATE
    // =========================================================================
    $pdo->beginTransaction();

    try {
        // — 4a. Bulk-shift promised_time on all active orders ————————————
        $stmtShift = $pdo->prepare(
            "UPDATE sh_orders
             SET promised_time = DATE_ADD(
                     COALESCE(promised_time, created_at),
                     INTERVAL :delay MINUTE
                 )
             WHERE tenant_id = :tid
               AND status IN ('accepted', 'preparing', 'ready')"
        );
        $stmtShift->execute([':delay' => $delayMinutes, ':tid' => $tenant_id]);
        $affectedCount = $stmtShift->rowCount();

        // — 4b. Audit log ————————————————————————————————————————————————
        $stmtLog = $pdo->prepare(
            "INSERT INTO sh_panic_log (id, tenant_id, triggered_by, delay_minutes, affected_count, created_at)
             VALUES (:id, :tid, :uid, :delay, :affected, :now)"
        );
        $stmtLog->execute([
            ':id'       => $generateUuidV4(),
            ':tid'      => $tenant_id,
            ':uid'      => $user_id,
            ':delay'    => $delayMinutes,
            ':affected' => $affectedCount,
            ':now'      => $now,
        ]);

        // — 4c. COMMIT ———————————————————————————————————————————————————
        $pdo->commit();

    } catch (Throwable $txErr) {
        $pdo->rollBack();
        throw $txErr;
    }

    // =========================================================================
    // 5. TODO: BROADCAST REAL-TIME EVENT
    // =========================================================================
    // When a real-time transport is wired (Redis Pub/Sub, SSE, WebSocket relay),
    // push this payload so every connected POS/KDS/Driver screen refreshes its
    // promised-time timers without a page reload.
    //
    // $realtimePayload = json_encode([
    //     'action'        => 'panic_mode',
    //     'tenant_id'     => $tenant_id,
    //     'delay_minutes' => $delayMinutes,
    //     'affected_orders' => $affectedCount,
    //     'triggered_at'  => $now,
    // ]);
    //
    // Example integrations:
    //   Redis:   $redis->publish("tenant:{$tenant_id}:events", $realtimePayload);
    //   Webhook: file_get_contents('http://internal-bus/broadcast', false,
    //              stream_context_create(['http' => ['method' => 'POST',
    //              'header' => 'Content-Type: application/json',
    //              'content' => $realtimePayload]]));

    // =========================================================================
    // 6. SUCCESS RESPONSE
    // =========================================================================
    $triggeredAt = (new DateTimeImmutable($now, new DateTimeZone('Europe/Warsaw')))
        ->format(DateTimeInterface::ATOM);

    echo json_encode([
        'success' => true,
        'data'    => [
            'affected_orders'       => $affectedCount,
            'delay_applied_minutes' => $delayMinutes,
            'triggered_at'          => $triggeredAt,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[PanicMode] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[PanicMode] ' . $e->getMessage());
}

exit;
