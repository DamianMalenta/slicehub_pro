<?php

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
    require_once __DIR__ . '/../../core/AuthEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        exit;
    }

    try {
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.']);
        exit;
    }

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.']);
        exit;
    }

    $mode = strtolower(trim((string)($body['mode'] ?? '')));
    $jwt  = JWT_SECRET;

    if ($mode === 'system') {
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        $data      = AuthEngine::loginSystem($pdo, $username, $password, $jwt);
    } elseif ($mode === 'kiosk') {
        $tenantId = (int)($body['tenant_id'] ?? 0);
        $pinCode  = (string)($body['pin_code'] ?? '');
        $data     = AuthEngine::loginKiosk($pdo, $tenantId, $pinCode, $jwt);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing mode.']);
        exit;
    }

    http_response_code(200);
    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (AuthForbiddenException) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
} catch (AuthFailureException) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
}

exit;
