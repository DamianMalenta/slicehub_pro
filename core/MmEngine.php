<?php

declare(strict_types=1);

/**
 * MM Engine — Inter-Warehouse Transfer
 *
 * Atomic engine that moves stock between two warehouses within the same tenant.
 * The source warehouse's current AVCO carries over to the target, where a standard
 * weighted-average recalculation is applied.
 *
 * Target schema: wh_documents, wh_document_lines, wh_stock, wh_stock_logs.
 */
class MmEngine
{
    /**
     * @param array<int, array{sku: string, quantity: float|string}> $lines
     *
     * @return array{success: true, mm_document: array}
     *
     * @throws \InvalidArgumentException on validation failures
     * @throws \RuntimeException         on insufficient stock
     * @throws \Throwable                on unrecoverable DB errors (tx rolled back)
     */
    public static function processTransfer(
        PDO    $pdo,
        int    $tenantId,
        string $sourceWhId,
        string $targetWhId,
        array  $lines,
        int    $userId
    ): array {

        if ($sourceWhId === $targetWhId) {
            throw new \InvalidArgumentException(
                'Source and target warehouse must be different.'
            );
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Transfer must contain at least one line.');
        }

        $validatedLines = [];

        foreach ($lines as $idx => $line) {
            $sku = trim((string)($line['sku'] ?? ''));
            $qty = (float)($line['quantity'] ?? 0);

            if ($sku === '') {
                throw new \InvalidArgumentException("Line #{$idx}: missing SKU.");
            }
            if ($qty <= 0) {
                throw new \InvalidArgumentException(
                    "Line #{$idx} (SKU: {$sku}): quantity must be > 0."
                );
            }

            $validatedLines[] = ['sku' => $sku, 'quantity' => $qty];
        }

        // =================================================================
        // ATOMIC EXECUTION
        // =================================================================
        $pdo->beginTransaction();

        try {
            // --- MM document header ---
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id,
                     target_warehouse_id, created_by)
                VALUES
                    (:tid, '', 'MM', :sourceWh, :targetWh, :uid)
            ");
            $stmtDoc->execute([
                ':tid'      => $tenantId,
                ':sourceWh' => $sourceWhId,
                ':targetWh' => $targetWhId,
                ':uid'      => $userId,
            ]);
            $docId = (int)$pdo->lastInsertId();

            $docNumber = sprintf('MM/%s/%05d', date('Y/m/d'), $docId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
                ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenantId]);

            // --- Reusable statements ---
            $stmtLockStock = $pdo->prepare("
                SELECT quantity, current_avco_price
                FROM wh_stock
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                FOR UPDATE
            ");

            $stmtDeductSource = $pdo->prepare("
                UPDATE wh_stock
                SET quantity = quantity - :qty
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
            ");

            $stmtUpsertTarget = $pdo->prepare("
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
                     'MM', :docId, :uid)
            ");

            // --- Per-line processing ---
            $resultLines = [];

            foreach ($validatedLines as $vl) {
                $sku         = $vl['sku'];
                $transferQty = $vl['quantity'];

                // ---- SOURCE: lock, check, deduct ----
                $stmtLockStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $sourceWhId,
                    ':sku' => $sku,
                ]);
                $sourceRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);

                $sourceQty  = $sourceRow ? (float)$sourceRow['quantity']           : 0.0;
                $sourceAvco = $sourceRow ? (float)$sourceRow['current_avco_price'] : 0.0;

                if ($sourceQty < $transferQty) {
                    throw new \RuntimeException(
                        "Insufficient stock for SKU '{$sku}' in source warehouse. "
                        . "Available: {$sourceQty}, requested: {$transferQty}."
                    );
                }

                $stmtDeductSource->execute([
                    ':qty' => $transferQty,
                    ':tid' => $tenantId,
                    ':wid' => $sourceWhId,
                    ':sku' => $sku,
                ]);

                $sourceAfterQty = round($sourceQty - $transferQty, 3);

                $stmtLog->execute([
                    ':tid'       => $tenantId,
                    ':wid'       => $sourceWhId,
                    ':sku'       => $sku,
                    ':changeQty' => -$transferQty,
                    ':afterQty'  => $sourceAfterQty,
                    ':docId'     => $docId,
                    ':uid'       => $userId,
                ]);

                // ---- TARGET: lock, AVCO recalc, upsert ----
                $stmtLockStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $targetWhId,
                    ':sku' => $sku,
                ]);
                $targetRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);

                $targetQty  = $targetRow ? (float)$targetRow['quantity']           : 0.0;
                $targetAvco = $targetRow ? (float)$targetRow['current_avco_price'] : 0.0;

                $unitNetCost = $sourceAvco;

                $denominator = $targetQty + $transferQty;
                if ($targetQty <= 0 || $denominator == 0) {
                    $newAvco = $unitNetCost;
                } else {
                    $newAvco = (($targetQty * $targetAvco) + ($transferQty * $unitNetCost))
                             / $denominator;
                }
                $newAvco = round($newAvco, 6);

                $targetAfterQty = round($targetQty + $transferQty, 3);

                $stmtUpsertTarget->execute([
                    ':tid'  => $tenantId,
                    ':wid'  => $targetWhId,
                    ':sku'  => $sku,
                    ':qty'  => $transferQty,
                    ':avco' => $newAvco,
                ]);

                $stmtLog->execute([
                    ':tid'       => $tenantId,
                    ':wid'       => $targetWhId,
                    ':sku'       => $sku,
                    ':changeQty' => $transferQty,
                    ':afterQty'  => $targetAfterQty,
                    ':docId'     => $docId,
                    ':uid'       => $userId,
                ]);

                $lineNetValue = round($transferQty * $unitNetCost, 2);

                $stmtDocLine->execute([
                    ':docId'   => $docId,
                    ':sku'     => $sku,
                    ':qty'     => $transferQty,
                    ':unc'     => $unitNetCost,
                    ':lnv'     => $lineNetValue,
                    ':vat'     => 0,
                    ':oldAvco' => round($targetAvco, 6),
                    ':newAvco' => $newAvco,
                ]);

                $resultLines[] = [
                    'sku'              => $sku,
                    'quantity'         => $transferQty,
                    'avco_at_transfer' => number_format(round($unitNetCost, 2), 2, '.', ''),
                    'target_old_avco'  => number_format(round($targetAvco, 2), 2, '.', ''),
                    'target_new_avco'  => number_format(round($newAvco, 2), 2, '.', ''),
                ];
            }

            $pdo->commit();

            return [
                'success'     => true,
                'mm_document' => [
                    'doc_id'              => $docId,
                    'doc_number'          => $docNumber,
                    'source_warehouse_id' => $sourceWhId,
                    'target_warehouse_id' => $targetWhId,
                    'lines'               => $resultLines,
                    'created_at'          => date('c'),
                    'created_by'          => $userId,
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
