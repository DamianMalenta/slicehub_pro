<?php

declare(strict_types=1);

// =============================================================================
// STATUS: UTILITY (audit 2026-04-19) — thin HTTP wrapper around AsciiKeyEngine.
// Planned consumer: Menu Studio / Warehouse "new item" modal (live ascii_key
// preview + collision probe). Not yet called from any module. Keep.
// =============================================================================
// SliceHub — GET /api/studio/generate_key.php
// Section 29: ASCII key preview (transliterate + normalize + uniqueness check).
// Query: name (required) — optional table, column for collision probe.
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

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
    require_once __DIR__ . '/../../core/AuthGuard.php';
    require_once __DIR__ . '/../../core/AsciiKeyEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $payload = AuthGuard::protect(JWT_SECRET);
    $tenantId = (int) $payload['tenant_id'];

    $name = trim((string) ($_GET['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: name.']);
        exit;
    }

    $table  = trim((string) ($_GET['table'] ?? 'sh_menu_items'));
    $column = trim((string) ($_GET['column'] ?? 'ascii_key'));

    $data = AsciiKeyEngine::generate($pdo, $tenantId, $name, $table, $column);

    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[generate_key] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[generate_key] ' . $e->getMessage());
}

exit;
