<?php

declare(strict_types=1);

/**
 * POST /api/warehouse/internal_rw.php
 * Rozchód wewnętrzny (strata) — jedna pozycja SKU, jeden magazyn.
 * Body: { warehouse_id, sku, qty, reason? }
 * Zapis: wh_documents (type RW), wh_document_lines, wh_stock, wh_stock_logs.
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

    $warehouseId = trim((string)($input['warehouse_id'] ?? 'MAIN'));
    if ($warehouseId === '') {
        $warehouseId = 'MAIN';
    }
    $sku    = trim((string)($input['sku'] ?? ''));
    $qty    = (float)($input['qty'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));

    if ($sku === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Brakuje pola sku.', 'data' => null]);
        exit;
    }
    if ($qty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ilość musi być > 0.', 'data' => null]);
        exit;
    }

    $pdo->beginTransaction();

    try {
        $stmtSelect = $pdo->prepare("
            SELECT quantity, current_avco_price
            FROM wh_stock
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
            FOR UPDATE
        ");
        $stmtSelect->execute([':tid' => $tenant_id, ':wid' => $warehouseId, ':sku' => $sku]);
        $stockRow = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        $currentQty  = $stockRow ? (float) $stockRow['quantity'] : 0.0;
        $currentAvco = $stockRow ? (float) $stockRow['current_avco_price'] : 0.0;

        if ($currentQty < $qty) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Niewystarczający stan magazynowy: {$sku} ma {$currentQty}, a żądano {$qty}.",
                'data'    => ['sku' => $sku, 'available' => $currentQty, 'requested' => $qty],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $totalValue  = round($qty * $currentAvco, 2);

        $stmtDoc = $pdo->prepare("
            INSERT INTO wh_documents
                (tenant_id, doc_number, type, warehouse_id, notes, status, created_by)
            VALUES
                (:tid, '', 'RW', :wid, :notes, 'completed', :uid)
        ");
        $stmtDoc->execute([
            ':tid'   => $tenant_id,
            ':wid'   => $warehouseId,
            ':notes' => $reason !== '' ? $reason : null,
            ':uid'   => $user_id,
        ]);
        $docId = (int) $pdo->lastInsertId();

        $docNumber = sprintf('RW/%s/%05d', date('Y/m/d'), $docId);
        $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
            ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenant_id]);

        $stmtDocItem = $pdo->prepare("
            INSERT INTO wh_document_lines
                (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, old_avco, new_avco)
            VALUES
                (:docId, :sku, :qty, :unc, :lnv, 0, :oldAvco, :newAvco)
        ");
        $stmtDocItem->execute([
            ':docId'   => $docId,
            ':sku'     => $sku,
            ':qty'     => $qty,
            ':unc'     => $currentAvco,
            ':lnv'     => $totalValue,
            ':oldAvco' => $currentAvco,
            ':newAvco' => $currentAvco,
        ]);

        $stmtUpdate = $pdo->prepare("
            UPDATE wh_stock
            SET quantity = quantity - :qty
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
        ");
        $stmtUpdate->execute([
            ':qty' => $qty,
            ':tid' => $tenant_id,
            ':wid' => $warehouseId,
            ':sku' => $sku,
        ]);

        $afterQty = round($currentQty - $qty, 3);
        $stmtLog = $pdo->prepare("
            INSERT INTO wh_stock_logs
                (tenant_id, warehouse_id, sku, change_qty, after_qty,
                 document_type, document_id, created_by)
            VALUES
                (:tid, :wid, :sku, :changeQty, :afterQty, 'RW', :docId, :uid)
        ");
        $stmtLog->execute([
            ':tid'       => $tenant_id,
            ':wid'       => $warehouseId,
            ':sku'       => $sku,
            ':changeQty' => -$qty,
            ':afterQty'  => $afterQty,
            ':docId'     => $docId,
            ':uid'       => $user_id,
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Dokument {$docNumber} zaksięgowany. Wartość straty: "
                . number_format($totalValue, 2, ',', ' ') . ' zł.',
            'data'    => [
                'doc_id'      => $docId,
                'doc_number'  => $docNumber,
                'total_value' => $totalValue,
                'after_qty'   => $afterQty,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/internal_rw] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
