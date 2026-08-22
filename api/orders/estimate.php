<?php
// =============================================================================
// SliceHub Enterprise — Promised Time Estimate Endpoint
// GET /api/orders/estimate.php
//
// Returns estimated/validated promised_time for a given mode & channel.
// Wraps PromisedTimeEngine::calculate() with HTTP error handling.
//
// Modes:
//   asap           — returns estimated time (prep × load + channel buffer)
//   scheduled      — validates requested_time (lead-time + business hours)
//   slots          — returns array of available time slots from earliest possible
//
// Params (query string):
//   mode           — "asap" | "scheduled" | "slots"
//   channel        — "dine_in" | "takeaway" | "delivery"
//   requested_time — ISO 8601 string (required when mode=scheduled)
//   interval       — slot interval in minutes (default: 15, for mode=slots)
//   count          — number of slots to return (default: 12, for mode=slots)
//
// Schema: sh_tenant_settings, sh_orders
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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/PromisedTimeEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    // =========================================================================
    // 1. READ & VALIDATE QUERY PARAMS
    // =========================================================================
    $mode          = trim($_GET['mode'] ?? '');
    $channel       = trim($_GET['channel'] ?? '');
    $requestedTime = isset($_GET['requested_time']) ? trim($_GET['requested_time']) : null;

    if ($mode === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: mode.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($channel === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: channel.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // 2. CALCULATE
    // =========================================================================
    if ($mode === 'slots') {
        // Generate available time slots starting from ASAP estimate
        $interval = max(5, min(60, (int)($_GET['interval'] ?? 15)));
        $count    = max(4, min(24, (int)($_GET['count'] ?? 12)));

        $asap = PromisedTimeEngine::calculate($pdo, $tenant_id, 'asap', $channel);
        $tz   = new DateTimeZone('Europe/Warsaw');
        $earliest = new DateTime($asap['promised_time'], $tz);

        // Round up to next interval boundary
        $mins = (int)$earliest->format('i');
        $nextSlot = (int)ceil($mins / $interval) * $interval;
        $earliest->setTime((int)$earliest->format('H'), $nextSlot, 0);

        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $slotTime = (clone $earliest)->modify("+".($i * $interval)." minutes");
            $slots[] = [
                'time'  => $slotTime->format('H:i'),
                'iso'   => $slotTime->format('Y-m-d\TH:i'),
                'label' => $slotTime->format('H:i'),
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'asap_estimate'    => $asap,
                'slots'            => $slots,
                'interval_minutes' => $interval,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $result = PromisedTimeEngine::calculate($pdo, $tenant_id, $mode, $channel, $requestedTime);

        echo json_encode([
            'success' => true,
            'data'    => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[Estimate] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[Estimate] ' . $e->getMessage());
}

exit;
