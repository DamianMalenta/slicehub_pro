<?php

declare(strict_types=1);

/**
 * GET  /api/warehouse/mapping.php
 *      → { success, data: { mappings: [...], items: [...] } }
 *
 * POST /api/warehouse/mapping.php
 *      Zapis: { "external_name": "...", "internal_sku": "SKU" }
 *      Usuń:  { "delete_id": 123 }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmtItems = $pdo->prepare(
            'SELECT sku, name, base_unit FROM sys_items WHERE tenant_id = :tid ORDER BY name ASC'
        );
        $stmtItems->execute([':tid' => $tenant_id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtMap = $pdo->prepare(
            'SELECT m.id, m.external_name, m.internal_sku, s.name AS internal_name
             FROM sh_product_mapping m
             INNER JOIN sys_items s
               ON s.tenant_id = m.tenant_id AND s.sku = m.internal_sku
             WHERE m.tenant_id = :tid
             ORDER BY m.id DESC'
        );
        $stmtMap->execute([':tid' => $tenant_id]);
        $mappings = $stmtMap->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'OK',
            'data'    => ['mappings' => $mappings, 'items' => $items],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
        exit;
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: 'null', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.', 'data' => null]);
        exit;
    }

    $deleteId = (int)($input['delete_id'] ?? 0);
    if ($deleteId > 0) {
        $stmt = $pdo->prepare(
            'DELETE FROM sh_product_mapping WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([':id' => $deleteId, ':tid' => $tenant_id]);
        echo json_encode([
            'success' => true,
            'message' => 'Mapowanie usunięte.',
            'data'    => ['deleted_id' => $deleteId],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $external = trim((string)($input['external_name'] ?? ''));
    $sku      = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string)($input['internal_sku'] ?? '')));

    if ($external === '' || $sku === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wymagane: external_name i internal_sku.', 'data' => null]);
        exit;
    }

    $stmtSku = $pdo->prepare(
        'SELECT sku FROM sys_items WHERE tenant_id = :tid AND sku = :sku LIMIT 1'
    );
    $stmtSku->execute([':tid' => $tenant_id, ':sku' => $sku]);
    if (!$stmtSku->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nieznany SKU w sys_items.', 'data' => null]);
        exit;
    }

    $stmtUpsert = $pdo->prepare(
        'INSERT INTO sh_product_mapping (tenant_id, external_name, internal_sku)
         VALUES (:tid, :ext, :sku)
         ON DUPLICATE KEY UPDATE internal_sku = VALUES(internal_sku)'
    );
    $stmtUpsert->execute([
        ':tid' => $tenant_id,
        ':ext' => $external,
        ':sku' => $sku,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Mapowanie zapisane.',
        'data'    => ['external_name' => $external, 'internal_sku' => $sku],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/mapping] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
