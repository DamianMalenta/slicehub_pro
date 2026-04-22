<?php

declare(strict_types=1);

/**
 * INW Engine — Physical Inventory Count
 *
 * Atomic engine that processes a physical stock count against system quantities,
 * applies a tiered variance-approval workflow, and generates compensating
 * documents (RW for losses, PW for surplus) when auto-approved.
 *
 * AVCO is never modified by an inventory count — only quantities change.
 *
 * Target schema: wh_documents, wh_document_lines, wh_stock, wh_stock_logs.
 */
class InwEngine
{
    /**
     * @param array<int, array{sku: string, counted_qty: float|string}> $countedLines
     * @param array{auto_approve_pct: float, critical_pct: float}       $tolerances
     *
     * @return array{success: true, inw_document: array}
     *
     * @throws \InvalidArgumentException on validation failures
     * @throws \Throwable                on unrecoverable DB errors (tx rolled back)
     */
    public static function processCount(
        PDO    $pdo,
        int    $tenantId,
        string $warehouseId,
        array  $countedLines,
        array  $tolerances,
        int    $userId
    ): array {

        if (empty($countedLines)) {
            throw new \InvalidArgumentException('Inventory count must contain at least one line.');
        }

        $autoApprovePct = (float)($tolerances['auto_approve_pct'] ?? 2.0);
        $criticalPct    = (float)($tolerances['critical_pct'] ?? 10.0);

        if ($autoApprovePct < 0 || $criticalPct < 0 || $autoApprovePct >= $criticalPct) {
            throw new \InvalidArgumentException(
                'Invalid tolerances: auto_approve_pct must be >= 0, critical_pct must be > auto_approve_pct.'
            );
        }

        foreach ($countedLines as $idx => $line) {
            $sku = trim((string)($line['sku'] ?? ''));
            if ($sku === '') {
                throw new \InvalidArgumentException("Line #{$idx}: missing SKU.");
            }
            $cQty = (float)($line['counted_qty'] ?? -1);
            if ($cQty < 0) {
                throw new \InvalidArgumentException(
                    "Line #{$idx} (SKU: {$sku}): counted_qty cannot be negative."
                );
            }
        }

        // =================================================================
        // ATOMIC EXECUTION
        // =================================================================
        $pdo->beginTransaction();

        try {
            $stmtLockStock = $pdo->prepare("
                SELECT quantity, current_avco_price
                FROM wh_stock
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                FOR UPDATE
            ");

            // --- PHASE 1: Scan all lines, compute variances, determine approval ---
            $highestApproval = 'none';
            $processedLines  = [];

            foreach ($countedLines as $line) {
                $sku       = trim((string)$line['sku']);
                $countedQty = round((float)$line['counted_qty'], 3);

                $stmtLockStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $warehouseId,
                    ':sku' => $sku,
                ]);
                $stockRow = $stmtLockStock->fetch(PDO::FETCH_ASSOC);

                $systemQty = $stockRow ? (float)$stockRow['quantity']           : 0.0;
                $avco      = $stockRow ? (float)$stockRow['current_avco_price'] : 0.0;

                $variance = round($countedQty - $systemQty, 3);

                if ($systemQty == 0.0) {
                    $variancePct = ($variance != 0.0) ? 100.0 : 0.0;
                } else {
                    $variancePct = round(abs($variance / $systemQty) * 100, 2);
                }

                if ($variancePct > $criticalPct) {
                    $highestApproval = 'owner';
                } elseif ($variancePct > $autoApprovePct && $highestApproval !== 'owner') {
                    $highestApproval = 'manager';
                }

                $costImpact = round($variance * $avco, 2);

                $processedLines[] = [
                    'sku'          => $sku,
                    'system_qty'   => $systemQty,
                    'counted_qty'  => $countedQty,
                    'variance'     => $variance,
                    'variance_pct' => $variancePct,
                    'avco'         => $avco,
                    'cost_impact'  => $costImpact,
                    'stock_exists' => (bool)$stockRow,
                ];
            }

            $status = ($highestApproval === 'none') ? 'completed' : 'pending_approval';

            // --- PHASE 2: Create INW document header ---
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, status,
                     required_approval_level, warehouse_id, created_by)
                VALUES
                    (:tid, '', 'INW', :status,
                     :approval, :wid, :uid)
            ");
            $stmtDoc->execute([
                ':tid'      => $tenantId,
                ':status'   => $status,
                ':approval' => $highestApproval,
                ':wid'      => $warehouseId,
                ':uid'      => $userId,
            ]);
            $inwDocId = (int)$pdo->lastInsertId();

            $inwDocNumber = sprintf('INW/%s/%05d', date('Y/m/d'), $inwDocId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
                ->execute([':dn' => $inwDocNumber, ':id' => $inwDocId, ':tid' => $tenantId]);

            // --- PHASE 3: Insert INW document lines ---
            $stmtInwLine = $pdo->prepare("
                INSERT INTO wh_document_lines
                    (document_id, sku, system_qty, counted_qty, variance,
                     quantity, unit_net_cost, line_net_value, vat_rate,
                     old_avco, new_avco)
                VALUES
                    (:docId, :sku, :sysQty, :cntQty, :variance,
                     :qty, :unc, :lnv, 0,
                     :avco, :avco)
            ");

            foreach ($processedLines as $pl) {
                $stmtInwLine->execute([
                    ':docId'    => $inwDocId,
                    ':sku'      => $pl['sku'],
                    ':sysQty'   => $pl['system_qty'],
                    ':cntQty'   => $pl['counted_qty'],
                    ':variance' => $pl['variance'],
                    ':qty'      => abs($pl['variance']),
                    ':unc'      => $pl['avco'],
                    ':lnv'      => abs($pl['cost_impact']),
                    ':avco'     => $pl['avco'],
                ]);
            }

            // --- PHASE 4: If auto-approved → apply stock + compensating docs ---
            $rwDocId = null;
            $pwDocId = null;

            if ($status === 'completed') {
                $lossLines   = [];
                $surplusLines = [];

                foreach ($processedLines as $pl) {
                    if ($pl['variance'] < 0) {
                        $lossLines[] = $pl;
                    } elseif ($pl['variance'] > 0) {
                        $surplusLines[] = $pl;
                    }
                }

                // --- Update wh_stock to counted_qty ---
                $stmtSetStock = $pdo->prepare("
                    UPDATE wh_stock
                    SET quantity = :countedQty
                    WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                ");

                $stmtInsertStock = $pdo->prepare("
                    INSERT INTO wh_stock
                        (tenant_id, warehouse_id, sku, quantity, current_avco_price)
                    VALUES
                        (:tid, :wid, :sku, :qty, 0)
                ");

                foreach ($processedLines as $pl) {
                    if ($pl['variance'] == 0.0) {
                        continue;
                    }
                    if ($pl['stock_exists']) {
                        $stmtSetStock->execute([
                            ':countedQty' => $pl['counted_qty'],
                            ':tid'        => $tenantId,
                            ':wid'        => $warehouseId,
                            ':sku'        => $pl['sku'],
                        ]);
                    } else {
                        $stmtInsertStock->execute([
                            ':tid' => $tenantId,
                            ':wid' => $warehouseId,
                            ':sku' => $pl['sku'],
                            ':qty' => $pl['counted_qty'],
                        ]);
                    }
                }

                // --- RW compensating document (losses / negative variance) ---
                if (!empty($lossLines)) {
                    $rwDocId = self::createCompensatingDoc(
                        $pdo, $tenantId, $warehouseId, 'RW',
                        $inwDocNumber, $lossLines, $userId
                    );
                }

                // --- PW compensating document (surplus / positive variance) ---
                if (!empty($surplusLines)) {
                    $pwDocId = self::createCompensatingDoc(
                        $pdo, $tenantId, $warehouseId, 'PW',
                        $inwDocNumber, $surplusLines, $userId
                    );
                }
            }

            $pdo->commit();

            // --- Build response ---
            $resultLines = [];
            foreach ($processedLines as $pl) {
                $resultLines[] = [
                    'sku'               => $pl['sku'],
                    'system_qty'        => $pl['system_qty'],
                    'counted_qty'       => $pl['counted_qty'],
                    'variance'          => $pl['variance'],
                    'variance_pct'      => $pl['variance'] >= 0
                                            ? $pl['variance_pct']
                                            : -$pl['variance_pct'],
                    'approval_required' => $pl['variance_pct'] > $autoApprovePct,
                    'cost_impact'       => number_format($pl['cost_impact'], 2, '.', ''),
                ];
            }

            $result = [
                'success'      => true,
                'inw_document' => [
                    'doc_id'                   => $inwDocId,
                    'doc_number'               => $inwDocNumber,
                    'warehouse_id'             => $warehouseId,
                    'status'                   => $status,
                    'required_approval_level'  => $highestApproval,
                    'lines'                    => $resultLines,
                    'summary'                  => [
                        'total_lines'    => count($processedLines),
                        'loss_lines'     => count(array_filter($processedLines, fn($p) => $p['variance'] < 0)),
                        'surplus_lines'  => count(array_filter($processedLines, fn($p) => $p['variance'] > 0)),
                        'no_change'      => count(array_filter($processedLines, fn($p) => $p['variance'] == 0.0)),
                        'total_cost_impact' => number_format(
                            array_sum(array_column($processedLines, 'cost_impact')), 2, '.', ''
                        ),
                    ],
                    'compensating_documents'   => [],
                    'counted_by'               => $userId,
                    'created_at'               => date('c'),
                ],
            ];

            if ($rwDocId !== null) {
                $result['inw_document']['compensating_documents'][] = [
                    'type' => 'RW', 'doc_id' => $rwDocId,
                ];
            }
            if ($pwDocId !== null) {
                $result['inw_document']['compensating_documents'][] = [
                    'type' => 'PW', 'doc_id' => $pwDocId,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // =====================================================================
    // PUBLIC: Apply a previously pending_approval INW document
    // =====================================================================

    /**
     * Called by approve.php when a manager/owner approves a pending INW.
     * Replicates Phase 4: sets stock to counted_qty, creates RW/PW compensating docs.
     * Must be called inside caller's transaction or will create its own.
     */
    public static function applyApproval(
        PDO    $pdo,
        int    $tenantId,
        int    $docId,
        string $warehouseId,
        int    $userId
    ): array {

        $stmtLines = $pdo->prepare("
            SELECT dl.sku, dl.system_qty, dl.counted_qty, dl.variance, dl.old_avco
            FROM wh_document_lines dl
            JOIN wh_documents d ON d.id = dl.document_id
            WHERE dl.document_id = :id AND d.tenant_id = :tid
        ");
        $stmtLines->execute([':id' => $docId, ':tid' => $tenantId]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lines)) {
            throw new \RuntimeException('INW document has no lines.');
        }

        $stmtGetStock = $pdo->prepare("
            SELECT quantity FROM wh_stock
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
            FOR UPDATE
        ");
        $stmtSetStock = $pdo->prepare("
            UPDATE wh_stock SET quantity = :qty
            WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
        ");
        $stmtInsertStock = $pdo->prepare("
            INSERT INTO wh_stock (tenant_id, warehouse_id, sku, quantity, current_avco_price)
            VALUES (:tid, :wid, :sku, :qty, 0)
        ");

        $lossLines   = [];
        $surplusLines = [];
        $inwDocNumber = '';

        $stmtDocNum = $pdo->prepare("SELECT doc_number FROM wh_documents WHERE id = :id AND tenant_id = :tid");
        $stmtDocNum->execute([':id' => $docId, ':tid' => $tenantId]);
        $inwDocNumber = (string)$stmtDocNum->fetchColumn();

        foreach ($lines as $ln) {
            $sku       = $ln['sku'];
            $countedQty = (float)$ln['counted_qty'];
            $variance   = (float)$ln['variance'];
            $avco       = (float)$ln['old_avco'];

            $stmtGetStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
            $exists = $stmtGetStock->fetchColumn() !== false;

            if ($exists) {
                $stmtSetStock->execute([':qty' => $countedQty, ':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
            } else {
                $stmtInsertStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku, ':qty' => $countedQty]);
            }

            $pl = [
                'sku' => $sku, 'system_qty' => (float)$ln['system_qty'],
                'counted_qty' => $countedQty, 'variance' => $variance,
                'avco' => $avco, 'stock_exists' => $exists,
            ];

            if ($variance < 0) $lossLines[] = $pl;
            elseif ($variance > 0) $surplusLines[] = $pl;
        }

        $pdo->prepare("UPDATE wh_documents SET status = 'completed' WHERE id = :id AND tenant_id = :tid")
            ->execute([':id' => $docId, ':tid' => $tenantId]);

        $rwDocId = null;
        $pwDocId = null;

        if (!empty($lossLines)) {
            $rwDocId = self::createCompensatingDoc($pdo, $tenantId, $warehouseId, 'RW', $inwDocNumber, $lossLines, $userId);
        }
        if (!empty($surplusLines)) {
            $pwDocId = self::createCompensatingDoc($pdo, $tenantId, $warehouseId, 'PW', $inwDocNumber, $surplusLines, $userId);
        }

        return ['rw_doc_id' => $rwDocId, 'pw_doc_id' => $pwDocId];
    }

    // =====================================================================
    // PRIVATE: Compensating Document Creator (RW / PW)
    // =====================================================================

    /**
     * Creates a compensating document (RW for losses, PW for surplus) with
     * its own header, line items, and stock log entries.
     *
     * @param array<int, array{sku: string, variance: float, avco: float, counted_qty: float, system_qty: float}> $lines
     * @return int  The created document ID
     */
    private static function createCompensatingDoc(
        PDO    $pdo,
        int    $tenantId,
        string $warehouseId,
        string $type,
        string $inwDocNumber,
        array  $lines,
        int    $userId
    ): int {

        $stmtDoc = $pdo->prepare("
            INSERT INTO wh_documents
                (tenant_id, doc_number, type, status, warehouse_id, notes, created_by)
            VALUES
                (:tid, '', :type, 'completed', :wid, :notes, :uid)
        ");
        $stmtDoc->execute([
            ':tid'   => $tenantId,
            ':type'  => $type,
            ':wid'   => $warehouseId,
            ':notes' => "Auto-generated from {$inwDocNumber}",
            ':uid'   => $userId,
        ]);
        $docId = (int)$pdo->lastInsertId();

        $docNumber = sprintf('%s/%s/%05d', $type, date('Y/m/d'), $docId);
        $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
            ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenantId]);

        $stmtLine = $pdo->prepare("
            INSERT INTO wh_document_lines
                (document_id, sku, quantity, unit_net_cost, line_net_value,
                 vat_rate, old_avco, new_avco)
            VALUES
                (:docId, :sku, :qty, :unc, :lnv,
                 0, :avco, :avco)
        ");

        $stmtLog = $pdo->prepare("
            INSERT INTO wh_stock_logs
                (tenant_id, warehouse_id, sku, change_qty, after_qty,
                 document_type, document_id, created_by)
            VALUES
                (:tid, :wid, :sku, :changeQty, :afterQty,
                 :docType, :docId, :uid)
        ");

        foreach ($lines as $pl) {
            $absVariance = abs($pl['variance']);
            $lineValue   = round($absVariance * $pl['avco'], 2);

            $stmtLine->execute([
                ':docId' => $docId,
                ':sku'   => $pl['sku'],
                ':qty'   => $absVariance,
                ':unc'   => $pl['avco'],
                ':lnv'   => $lineValue,
                ':avco'  => $pl['avco'],
            ]);

            // RW = loss (negative change), PW = surplus (positive change)
            $changeQty = ($type === 'RW') ? -$absVariance : $absVariance;

            $stmtLog->execute([
                ':tid'       => $tenantId,
                ':wid'       => $warehouseId,
                ':sku'       => $pl['sku'],
                ':changeQty' => $changeQty,
                ':afterQty'  => $pl['counted_qty'],
                ':docType'   => $type,
                ':docId'     => $docId,
                ':uid'       => $userId,
            ]);
        }

        return $docId;
    }
}
