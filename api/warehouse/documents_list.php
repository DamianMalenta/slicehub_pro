<?php

declare(strict_types=1);

/**
 * GET /api/warehouse/documents_list.php?type=PZ&limit=50&offset=0
 * Rejestr dokumentów magazynowych z wh_documents + count linii.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    if (!isset($pdo)) throw new RuntimeException('Database connection unavailable.');

    $type   = strtoupper(trim((string)($_GET['type'] ?? '')));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where  = 'd.tenant_id = :tid';
    $params = [':tid' => $tenant_id];

    if ($type !== '') {
        $where .= ' AND d.type = :type';
        $params[':type'] = $type;
    }
    if ($status !== '') {
        $where .= ' AND d.status = :status';
        $params[':status'] = $status;
    }

    $sql = "
        SELECT
            d.id, d.doc_number, d.type, d.status,
            d.warehouse_id, d.target_warehouse_id,
            d.supplier_name, d.supplier_invoice,
            d.order_id, d.references_wz,
            d.required_approval_level,
            d.notes, d.created_at, d.created_by,
            (SELECT COUNT(*) FROM wh_document_lines l WHERE l.document_id = d.id) AS line_count,
            (SELECT IFNULL(SUM(l2.line_net_value), 0) FROM wh_document_lines l2 WHERE l2.document_id = d.id) AS total_net_value
        FROM wh_documents d
        WHERE {$where}
        ORDER BY d.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM wh_documents d WHERE {$where}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data'    => [
            'documents' => $rows,
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/documents_list] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'data' => null], JSON_UNESCAPED_UNICODE);
}
