<?php

declare(strict_types=1);

/**
 * KOR Engine — Void / Correction (Document-Based Stock Return)
 *
 * Atomic engine that reverses a completed WZ (stock consumption) by returning
 * the exact quantities from the original document lines back into warehouse stock.
 *
 * Critical design: NEVER re-derives quantities from current recipes. Uses the WZ
 * document as the single source of truth, eliminating the legacy bug where recipe
 * changes between sale and cancellation corrupted return quantities.
 *
 * Target schema: wh_documents, wh_document_lines, wh_stock, wh_stock_logs.
 */
class KorEngine
{
    /**
     * @return array{success: true, kor_document: array}
     *
     * @throws \InvalidArgumentException on validation failures
     * @throws \RuntimeException         when the referenced WZ cannot be found
     * @throws \Throwable                on unrecoverable DB errors (tx rolled back)
     */
    public static function processCorrection(
        PDO    $pdo,
        int    $tenantId,
        string $orderId,
        string $reason,
        int    $userId
    ): array {

        if (trim($orderId) === '') {
            throw new \InvalidArgumentException('Missing required field: order_id.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Missing required field: reason.');
        }

        // =================================================================
        // PHASE 1: LOCATE ORIGINAL WZ  (read-only, pre-transaction)
        // =================================================================

        $stmtFindWz = $pdo->prepare("
            SELECT id, doc_number, warehouse_id
            FROM wh_documents
            WHERE tenant_id = :tid
              AND order_id  = :oid
              AND type      = 'WZ'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmtFindWz->execute([':tid' => $tenantId, ':oid' => $orderId]);
        $wzDoc = $stmtFindWz->fetch(PDO::FETCH_ASSOC);

        if (!$wzDoc) {
            throw new \RuntimeException(
                "No WZ document found for order '{$orderId}'. Cannot generate correction."
            );
        }

        $wzDocId     = (int)$wzDoc['id'];
        $wzDocNumber = $wzDoc['doc_number'];
        $warehouseId = $wzDoc['warehouse_id'];

        // Fetch all lines from the original WZ
        $stmtWzLines = $pdo->prepare("
            SELECT sku, quantity, unit_net_cost, old_avco
            FROM wh_document_lines
            WHERE document_id = :docId
        ");
        $stmtWzLines->execute([':docId' => $wzDocId]);
        $wzLines = $stmtWzLines->fetchAll(PDO::FETCH_ASSOC);

        if (empty($wzLines)) {
            throw new \RuntimeException(
                "WZ document #{$wzDocId} has no line items. Cannot reverse."
            );
        }

        // Prevent duplicate corrections
        $stmtCheckKor = $pdo->prepare("
            SELECT id FROM wh_documents
            WHERE tenant_id   = :tid
              AND references_wz = :wzNum
              AND type         = 'KOR'
            LIMIT 1
        ");
        $stmtCheckKor->execute([':tid' => $tenantId, ':wzNum' => $wzDocNumber]);

        if ($stmtCheckKor->fetch()) {
            throw new \RuntimeException(
                "A KOR correction already exists for WZ '{$wzDocNumber}'."
            );
        }

        // =================================================================
        // PHASE 2: ATOMIC EXECUTION
        // =================================================================
        $pdo->beginTransaction();

        try {
            // --- KOR document header ---
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id,
                     order_id, references_wz, notes, created_by)
                VALUES
                    (:tid, '', 'KOR', :wid,
                     :oid, :wzNum, :notes, :uid)
            ");
            $stmtDoc->execute([
                ':tid'   => $tenantId,
                ':wid'   => $warehouseId,
                ':oid'   => $orderId,
                ':wzNum' => $wzDocNumber,
                ':notes' => $reason,
                ':uid'   => $userId,
            ]);
            $docId = (int)$pdo->lastInsertId();

            $docNumber = sprintf('KOR/%s/%05d', $wzDocNumber, $docId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
                ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenantId]);

            // --- Reusable statements ---
            $stmtLockStock = $pdo->prepare("
                SELECT quantity, current_avco_price
                FROM wh_stock
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                FOR UPDATE
            ");

            $stmtUpsertStock = $pdo->prepare("
                INSERT INTO wh_stock
                    (tenant_id, warehouse_id, sku, quantity, current_avco_price)
                VALUES
                    (:tid, :wid, :sku, :qty, :avco)
                ON DUPLICATE KEY UPDATE
                    quantity           = quantity + VALUES(quantity),
                    current_avco_price = VALUES(current_avco_price)
            ");

            $stmtDocLine = $pdo->prepare("
                INSERT INTO wh_document_lines
                    (document_id, sku, quantity, unit_net_cost,
                     line_net_value, vat_rate, old_avco, new_avco)
                VALUES
                    (:docId, :sku, :qty, :unc, :lnv, :vat, :oldAvco, :newAvco)
            ");

            $stmtLog = $pdo->prepare("
                INSERT INTO wh_stock_logs
                    (tenant_id, warehouse_id, sku, change_qty, after_qty,
                     document_type, document_id, created_by)
                VALUES
                    (:tid, :wid, :sku, :changeQty, :afterQty,
                     'KOR', :docId, :uid)
            ");

            // --- Reverse each WZ line ---
            $resultLines = [];

            foreach ($wzLines as $wl) {
                $sku       = $wl['sku'];
                $returnQty = (float)$wl['quantity'];

                // Historical cost from the original WZ line
                $returnCost = (float)($wl['unit_net_cost'] ?: $wl['old_avco']);

                // Lock & read current stock
                $stmtLockStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $warehouseId,
                    ':sku' => $sku,
                ]);
                $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);

                $currentQty  = $stockRow ? (float)$stockRow['quantity']           : 0.0;
                $currentAvco = $stockRow ? (float)$stockRow['current_avco_price'] : 0.0;

                // AVCO recalculation on stock increase (same formula as PZ receipt)
                $denominator = $currentQty + $returnQty;
                if ($currentQty <= 0 || $denominator == 0) {
                    $newAvco = $returnCost;
                } else {
                    $newAvco = (($currentQty * $currentAvco) + ($returnQty * $returnCost))
                             / $denominator;
                }
                $newAvco = round($newAvco, 6);

                $afterQty     = round($currentQty + $returnQty, 3);
                $lineNetValue = round($returnQty * $returnCost, 2);

                // Upsert stock
                $stmtUpsertStock->execute([
                    ':tid'  => $tenantId,
                    ':wid'  => $warehouseId,
                    ':sku'  => $sku,
                    ':qty'  => $returnQty,
                    ':avco' => $newAvco,
                ]);

                // KOR document line
                $stmtDocLine->execute([
                    ':docId'   => $docId,
                    ':sku'     => $sku,
                    ':qty'     => $returnQty,
                    ':unc'     => $returnCost,
                    ':lnv'     => $lineNetValue,
                    ':vat'     => 0,
                    ':oldAvco' => round($currentAvco, 6),
                    ':newAvco' => $newAvco,
                ]);

                // Positive change_qty (stock returning to warehouse)
                $stmtLog->execute([
                    ':tid'       => $tenantId,
                    ':wid'       => $warehouseId,
                    ':sku'       => $sku,
                    ':changeQty' => $returnQty,
                    ':afterQty'  => $afterQty,
                    ':docId'     => $docId,
                    ':uid'       => $userId,
                ]);

                $resultLines[] = [
                    'sku'               => $sku,
                    'quantity_returned' => $returnQty,
                    'avco_at_original'  => number_format(round($returnCost, 2), 2, '.', ''),
                    'stock_old_avco'    => number_format(round($currentAvco, 2), 2, '.', ''),
                    'stock_new_avco'    => number_format(round($newAvco, 2), 2, '.', ''),
                ];
            }

            $pdo->commit();

            return [
                'success'      => true,
                'kor_document' => [
                    'doc_id'       => $docId,
                    'doc_number'   => $docNumber,
                    'references_wz' => $wzDocNumber,
                    'order_id'     => $orderId,
                    'warehouse_id' => $warehouseId,
                    'reason'       => $reason,
                    'lines'        => $resultLines,
                    'created_at'   => date('c'),
                    'created_by'   => $userId,
                ],
            ];

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
