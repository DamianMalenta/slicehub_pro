<?php
// =============================================================================
// SliceHub Enterprise — Auth Guard (Phase 2: Security & Multi-Tenancy)
// /core/auth_guard.php
//
// Include this file in every API endpoint AFTER db_config.php.
// It halts execution immediately with a JSON error if the session is invalid.
// Exposes: $tenant_id, $user_id
// =============================================================================

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// JWT takes precedence over session — critical for multi-tab scenarios where
// Dispatcher (manager session) and Driver App (driver JWT) share the same browser.
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

if ($authHeader === '' && function_exists('apache_request_headers')) {
    $apacheHeaders = apache_request_headers();
    $authHeader = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
}

$_authFromJwt = false;

if ($authHeader !== '') {
    $token = $authHeader;
    if (str_starts_with($token, 'Bearer ')) {
        $token = substr($token, 7);
    }
    $token = trim($token);

    if ($token !== '') {
        try {
            require_once __DIR__ . '/JwtProvider.php';
            $payload = JwtProvider::decode($token, JWT_SECRET);

            $tid = (int)($payload['tenant_id'] ?? 0);
            $uid = (int)($payload['user_id'] ?? 0);

            if ($tid <= 0 || $uid <= 0) {
                throw new \Exception('Invalid token payload');
            }

            $_SESSION['tenant_id'] = $tid;
            $_SESSION['user_id']   = $uid;
            $_authFromJwt = true;
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            die(json_encode([
                'success' => false,
                'message' => 'Invalid or expired token.',
                'data'    => null,
            ]));
        }
    }
}

if (!$_authFromJwt && (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id']))) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'message' => 'Unauthorized access. No token provided.',
        'data'    => null,
    ]));
}

$tenant_id = (int)$_SESSION['tenant_id'];
$user_id   = (int)$_SESSION['user_id'];
