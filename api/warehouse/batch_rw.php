<?php

declare(strict_types=1);

/**
 * POST /api/warehouse/batch_rw.php
 * Zbiorczy RW — 1 dokument (nagłówek wh_documents) + N linii.
 * Body: { warehouse_id, reason, lines: [{ sku, qty }] }
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

    if (!isset($pdo)) throw new RuntimeException('Database connection unavailable.');

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: 'null', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.', 'data' => null]);
        exit;
    }

    $warehouseId = trim((string)($input['warehouse_id'] ?? 'MAIN'));
    $reason      = trim((string)($input['reason'] ?? ''));
    $lines       = $input['lines'] ?? [];

    if (!is_array($lines) || count($lines) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wymagana co najmniej 1 linia.', 'data' => null]);
        exit;
    }

    $validated = [];
    foreach ($lines as $idx => $ln) {
        $sku = trim((string)($ln['sku'] ?? ''));
        $qty = (float)($ln['qty'] ?? 0);
        if ($sku === '' || $qty <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Linia #{$idx}: sku puste lub qty <= 0.", 'data' => null]);
            exit;
        }
        $validated[] = ['sku' => $sku, 'qty' => $qty];
    }

    $pdo->beginTransaction();
    try {
        $stmtDoc = $pdo->prepare("
            INSERT INTO wh_documents
                (tenant_id, doc_number, type, warehouse_id, notes, created_by)
            VALUES
                (:tid, '', 'RW', :wid, :notes, :uid)
        ");
        $stmtDoc->execute([
            ':tid'   => $tenant_id,
            ':wid'   => $warehouseId,
            ':notes' => $reason,
            ':uid'   => $user_id,
        ]);
        $docId = (int)$pdo->lastInsertId();

        $docNumber = sprintf('RW/%s/%05d', date('Y/m/d'), $docId);
        $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
            ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenant_id]);

        $stmtLock = $pdo->prepare("
            SELECT quantity, current_avco_price
            FROM wh_stock
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
            FOR UPDATE
        ");
        $stmtLine = $pdo->prepare("
            INSERT INTO wh_document_lines
                (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, old_avco, new_avco)
            VALUES
                (:docId, :sku, :qty, :unc, :lnv, 0, :oldAvco, :newAvco)
        ");
        $stmtUpdate = $pdo->prepare("
            UPDATE wh_stock SET quantity = quantity - :qty
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
        ");
        $stmtLog = $pdo->prepare("
            INSERT INTO wh_stock_logs
                (tenant_id, warehouse_id, sku, change_qty, after_qty, document_type, document_id, created_by)
            VALUES
                (:tid, :wid, :sku, :changeQty, :afterQty, 'RW', :docId, :uid)
        ");

        $totalValue = 0;
        $resultLines = [];

        foreach ($validated as $vl) {
            $sku = $vl['sku'];
            $qty = $vl['qty'];

            $stmtLock->execute([':tid' => $tenant_id, ':wid' => $warehouseId, ':sku' => $sku]);
            $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

            $curQty  = $row ? (float)$row['quantity'] : 0.0;
            $curAvco = $row ? (float)$row['current_avco_price'] : 0.0;

            if ($curQty < $qty) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => "Niewystarczający stan magazynowy: {$sku} ma {$curQty}, a żądano {$qty}.",
                    'data'    => ['sku' => $sku, 'available' => $curQty, 'requested' => $qty],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $lineVal = round($qty * $curAvco, 2);
            $totalValue += $lineVal;

            $stmtLine->execute([
                ':docId'   => $docId,
                ':sku'     => $sku,
                ':qty'     => $qty,
                ':unc'     => $curAvco,
                ':lnv'     => $lineVal,
                ':oldAvco' => $curAvco,
                ':newAvco' => $curAvco,
            ]);

            $stmtUpdate->execute([':qty' => $qty, ':tid' => $tenant_id, ':wid' => $warehouseId, ':sku' => $sku]);

            $afterQty = round($curQty - $qty, 3);
            $stmtLog->execute([
                ':tid' => $tenant_id, ':wid' => $warehouseId, ':sku' => $sku,
                ':changeQty' => -$qty, ':afterQty' => $afterQty, ':docId' => $docId, ':uid' => $user_id,
            ]);

            $resultLines[] = ['sku' => $sku, 'qty' => $qty, 'value' => $lineVal, 'after_qty' => $afterQty];
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Dokument {$docNumber} — strata " . number_format($totalValue, 2, ',', ' ') . ' zł.',
            'data'    => [
                'doc_id'      => $docId,
                'doc_number'  => $docNumber,
                'total_value' => $totalValue,
                'lines'       => $resultLines,
            ],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[warehouse/batch_rw] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error.', 'data' => null], JSON_UNESCAPED_UNICODE);
}
