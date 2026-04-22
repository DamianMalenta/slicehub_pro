<?php
// =============================================================================
// STATUS: ORPHAN (audit 2026-04-19) — not wired from any frontend module.
// Planned consumer: storefront "Zaplanuj na później" flow + online scheduled
// order picker. Thin HTTP wrapper around PromisedTimeEngine::calculate().
// Keep until scheduled-order UI ships in modules/online/.
// =============================================================================
// SliceHub Enterprise — Promised Time Estimate Endpoint
// GET /api/orders/estimate.php
//
// Returns estimated/validated promised_time for a given mode & channel.
// Wraps PromisedTimeEngine::calculate() with HTTP error handling.
//
// Params (query string):
//   mode           — "asap" | "scheduled"
//   channel        — "dine_in" | "takeaway" | "delivery"
//   requested_time — ISO 8601 string (required when mode=scheduled)
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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
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
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: mode.']);
        exit;
    }

    if ($channel === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: channel.']);
        exit;
    }

    // =========================================================================
    // 2. CALCULATE
    // =========================================================================
    $result = PromisedTimeEngine::calculate($pdo, $tenant_id, $mode, $channel, $requestedTime);

    // =========================================================================
    // 3. SUCCESS
    // =========================================================================
    echo json_encode([
        'success' => true,
        'data'    => $result,
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
    error_log('[Estimate] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Estimate] ' . $e->getMessage());
}

exit;
