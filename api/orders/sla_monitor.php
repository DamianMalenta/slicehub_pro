<?php
// =============================================================================
// STATUS: ORPHAN (audit 2026-04-19) — not wired from any frontend module.
// Planned consumer: admin_hub dashboard (Faza 3) + cron polling for SLA alerts.
// Writes to sh_sla_breaches (UPSERT). Keep as standalone — Dispatcher already
// has per-order SLA badges; this is the aggregate health endpoint.
// =============================================================================
// SliceHub Enterprise — Delivery SLA Monitor
// GET /api/orders/sla_monitor.php
//
// Real-time health check for all active delivery orders. Computes each order's
// distance from its promised_time and classifies it into an SLA tier:
//   ON_TRACK → AT_RISK → CRITICAL → BREACHED
//
// ARCHITECTURAL FIXES vs. naive implementation:
//   1. Breach logging uses UPSERT (ON DUPLICATE KEY UPDATE) so
//      breach_minutes stays DYNAMIC — every poll refreshes the delay
//      instead of freezing it at first detection via INSERT IGNORE.
//   2. driver_id / course_id are passed as nullable parameters so
//      kitchen-breached orders (no driver assigned yet) log correctly
//      instead of crashing on a NOT NULL constraint.
//
// Schema: sh_tenant_settings, sh_orders, sh_sla_breaches
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // =========================================================================
    // 1. FETCH SLA THRESHOLDS (with safe defaults)
    // =========================================================================
    $stmtSettings = $pdo->prepare(
        "SELECT sla_green_min, sla_yellow_min
         FROM sh_tenant_settings
         WHERE tenant_id = :tid AND setting_key = ''
         LIMIT 1"
    );
    $stmtSettings->execute([':tid' => $tenant_id]);
    $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

    $greenThreshold  = (int)($settings['sla_green_min']  ?? 10);
    $yellowThreshold = (int)($settings['sla_yellow_min'] ?? 5);

    // =========================================================================
    // 2. FETCH ACTIVE DELIVERY ORDERS WITH PROMISED TIME
    // =========================================================================
    $stmtOrders = $pdo->prepare(
        "SELECT id, promised_time, driver_id, course_id
         FROM sh_orders
         WHERE tenant_id = :tid
           AND status NOT IN ('completed', 'cancelled')
           AND order_type = 'delivery'
           AND promised_time IS NOT NULL"
    );
    $stmtOrders->execute([':tid' => $tenant_id]);
    $activeOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // 3. CLASSIFICATION LOOP
    // =========================================================================
    $tz  = new DateTimeZone('Europe/Warsaw');
    $now = new DateTime('now', $tz);

    $slaReport      = [];
    $breachedOrders = [];

    $generateUuidV4 = function (): string {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    };

    foreach ($activeOrders as $order) {
        $promised = new DateTime($order['promised_time'], $tz);
        $diffMin  = (int)floor(($promised->getTimestamp() - $now->getTimestamp()) / 60);

        if ($diffMin > $greenThreshold) {
            $tier = 'ON_TRACK';
        } elseif ($diffMin > $yellowThreshold) {
            $tier = 'AT_RISK';
        } elseif ($diffMin > 0) {
            $tier = 'CRITICAL';
        } else {
            $tier = 'BREACHED';
            $breachedOrders[] = [
                'order_id'  => $order['id'],
                'late_min'  => abs($diffMin),
                'driver_id' => $order['driver_id'],
                'course_id' => $order['course_id'],
            ];
        }

        $slaReport[] = [
            'order_id'           => $order['id'],
            'promised_time'      => $promised->format(DateTime::ATOM),
            'current_time'       => $now->format(DateTime::ATOM),
            'time_remaining_min' => $diffMin,
            'sla_tier'           => $tier,
            'driver_id'          => $order['driver_id'],
            'course_id'          => $order['course_id'],
        ];
    }

    // =========================================================================
    // 4. DYNAMIC BREACH LOGGING (UPSERT — keeps breach_minutes fresh)
    // =========================================================================
    if (count($breachedOrders) > 0) {
        $pdo->beginTransaction();

        try {
            $stmtBreach = $pdo->prepare(
                "INSERT INTO sh_sla_breaches
                    (id, tenant_id, order_id, breach_minutes, driver_id, course_id, logged_at)
                 VALUES
                    (:id, :tid, :oid, :late_min, :did, :cid, :now)
                 ON DUPLICATE KEY UPDATE
                    breach_minutes = VALUES(breach_minutes),
                    driver_id      = VALUES(driver_id),
                    course_id      = VALUES(course_id),
                    logged_at      = VALUES(logged_at)"
            );

            $nowStr = $now->format('Y-m-d H:i:s');

            foreach ($breachedOrders as $b) {
                $stmtBreach->execute([
                    ':id'       => $generateUuidV4(),
                    ':tid'      => $tenant_id,
                    ':oid'      => $b['order_id'],
                    ':late_min' => $b['late_min'],
                    ':did'      => $b['driver_id'],
                    ':cid'      => $b['course_id'],
                    ':now'      => $nowStr,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
    }

    // =========================================================================
    // 5. RESPONSE
    // =========================================================================
    echo json_encode([
        'success' => true,
        'data'    => [
            'orders' => $slaReport,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[SLA Monitor] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[SLA Monitor] ' . $e->getMessage());
}

exit;
