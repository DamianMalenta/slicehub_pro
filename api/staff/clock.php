<?php
// =============================================================================
// STATUS: PLANNED (audit 2026-04-19) — not yet wired.
// Consumer: team/employee mobile app (Faza 3). Self-service clock-in/out
// using session identity. Backed by ClockEngine. Keep — engine exists.
// =============================================================================
// SliceHub Enterprise — Clock In / Out
// POST /api/staff/clock.php
//
// Body JSON: { "action": "in" | "out" }
// Uses session tenant_id and user_id (self-service clock).
//
// Success → 200  { success: true, data: { ... } }
// =============================================================================

declare(strict_types=1);

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
    require_once __DIR__ . '/../../core/ClockEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['in', 'out'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid action (use "in" or "out").']);
        exit;
    }

    if ($action === 'in') {
        $result = ClockEngine::clockIn($pdo, $tenant_id, (string)$user_id);
    } else {
        $result = ClockEngine::clockOut($pdo, $tenant_id, (string)$user_id);
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);

} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    $map  = [
        ClockEngine::ERR_ALREADY_CLOCKED_IN => 409,
        ClockEngine::ERR_NO_OPEN_SESSION    => 409,
        ClockEngine::ERR_ACTIVE_DELIVERIES  => 409,
        'USER_NOT_FOUND_OR_TENANT_MISMATCH' => 403,
    ];
    http_response_code($map[$code] ?? 400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code'    => $code,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[Staff Clock] PDOException: ' . $e->getMessage());

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[Staff Clock] ' . $e->getMessage());
}

exit;
