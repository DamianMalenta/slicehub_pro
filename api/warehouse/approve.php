<?php

declare(strict_types=1);

/**
 * POST /api/warehouse/approve.php
 * Approve/reject an INW document in pending_approval state.
 * Body: { document_id, decision: "approve" | "reject" }
 * Approve delegates to InwEngine::applyApproval (stock + compensating RW/PW).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.', 'data' => null]);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/InwEngine.php';

    if (!isset($pdo)) throw new RuntimeException('Database connection unavailable.');

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: 'null', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.', 'data' => null]);
        exit;
    }

    $docId    = (int)($input['document_id'] ?? 0);
    $decision = strtolower(trim((string)($input['decision'] ?? '')));

    if ($docId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wymagane: document_id i decision (approve/reject).', 'data' => null]);
        exit;
    }

    $stmtDoc = $pdo->prepare("
        SELECT id, type, status, warehouse_id FROM wh_documents
        WHERE id = :id AND tenant_id = :tid
    ");
    $stmtDoc->execute([':id' => $docId, ':tid' => $tenant_id]);
    $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Dokument nie znaleziony.', 'data' => null]);
        exit;
    }
    if ($doc['type'] !== 'INW') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Akceptacja dotyczy tylko dokumentów INW, a ten jest typu '{$doc['type']}'.", 'data' => null]);
        exit;
    }
    if ($doc['status'] !== 'pending_approval') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "Dokument ma status '{$doc['status']}', nie pending_approval.", 'data' => null]);
        exit;
    }

    if ($decision === 'reject') {
        $pdo->prepare("UPDATE wh_documents SET status = 'rejected' WHERE id = :id AND tenant_id = :tid")
            ->execute([':id' => $docId, ':tid' => $tenant_id]);
        echo json_encode(['success' => true, 'message' => 'Dokument odrzucony.', 'data' => ['status' => 'rejected']]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $result = InwEngine::applyApproval($pdo, $tenant_id, $docId, $doc['warehouse_id'], $user_id);
        $pdo->commit();

        $data = ['status' => 'completed'];
        if ($result['rw_doc_id']) $data['compensating_rw_id'] = $result['rw_doc_id'];
        if ($result['pw_doc_id']) $data['compensating_pw_id'] = $result['pw_doc_id'];

        echo json_encode(['success' => true, 'message' => 'Inwentaryzacja zatwierdzona, stany wyrównane.', 'data' => $data]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/approve] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'data' => null], JSON_UNESCAPED_UNICODE);
}
