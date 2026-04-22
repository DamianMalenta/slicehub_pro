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

// =============================================================================
// !! DEVELOPMENT MOCK — REMOVE BEFORE PRODUCTION !!
// Provides a fallback session so endpoints work before the login UI is wired.
// =============================================================================
$_SESSION['tenant_id'] = $_SESSION['tenant_id'] = 1;
$_SESSION['user_id']   = $_SESSION['user_id'] = 2;
// =============================================================================

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Session expired or invalid.',
        'data'    => null,
    ]));
}

$tenant_id = (int)$_SESSION['tenant_id'];
$user_id   = (int)$_SESSION['user_id'];
