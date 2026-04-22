<?php

declare(strict_types=1);

/**
 * POST /api/warehouse/add_item.php
 * Body JSON: { name, base_unit, sku } — dopisuje pozycję do sys_items (V2).
 */

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
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: 'null', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.', 'data' => null]);
        exit;
    }

    $name     = trim((string)($input['name'] ?? ''));
    $baseUnit = trim((string)($input['base_unit'] ?? 'pcs'));
    $sku      = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string)($input['sku'] ?? '')));

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Pole name jest wymagane.', 'data' => null]);
        exit;
    }
    if ($sku === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Pole sku jest wymagane.', 'data' => null]);
        exit;
    }

    $stmtCheck = $pdo->prepare(
        'SELECT sku FROM sys_items WHERE tenant_id = :tid AND sku = :sku LIMIT 1'
    );
    $stmtCheck->execute([':tid' => $tenant_id, ':sku' => $sku]);
    if ($stmtCheck->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "SKU [{$sku}] jest już zajęte w tym tenantcie.", 'data' => null]);
        exit;
    }

    $stmtInsert = $pdo->prepare(
        'INSERT INTO sys_items (tenant_id, sku, name, base_unit) VALUES (:tid, :sku, :name, :bu)'
    );
    $stmtInsert->execute([
        ':tid'  => $tenant_id,
        ':sku'  => $sku,
        ':name' => $name,
        ':bu'   => $baseUnit !== '' ? $baseUnit : 'pcs',
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Surowiec [{$name}] dodany do słownika magazynowego.",
        'data'    => ['sku' => $sku, 'name' => $name, 'base_unit' => $baseUnit],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/add_item] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
