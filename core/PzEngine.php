<?php

declare(strict_types=1);

require_once __DIR__ . '/AutoScanEngine.php';

/**
 * PZ Engine — Goods Receipt & AVCO Valuation
 *
 * Atomic engine that processes a supplier delivery (Przyjęcie Zewnętrzne),
 * resolves external product names to internal SKUs, computes weighted-average
 * cost (AVCO) for each line, upserts warehouse stock, and creates a full
 * audit trail.
 *
 * Target schema: wh_documents, wh_document_lines, wh_stock, wh_stock_logs,
 *                sh_product_mapping, sys_items.
 *
 * F2.5 (2026-05-11): mapping resolution upgraded from EXACT-only
 * (sh_product_mapping LOWER match) to shared AutoScanEngine z 4-stopniowym
 * confidence (EXACT/ALIAS/NAME/FUZZY/NONE) + threshold auto-accept.
 * Auto-learn ALIAS matches po pomyślnym commit-cie dokumentu PZ
 * (network effect — kolejny PZ z tej samej nazwy ma EXACT 100%).
 */

class PzMappingException extends \RuntimeException
{
    private string $externalName;
    private array  $candidates;
    private int    $confidence;
    private string $matchType;

    /**
     * @param list<array{sku:string,name:string,confidence:int,match_type:string}> $candidates
     */
    public function __construct(
        string $externalName,
        array $candidates = [],
        int $confidence = 0,
        string $matchType = 'NONE'
    ) {
        $this->externalName = $externalName;
        $this->candidates   = $candidates;
        $this->confidence   = $confidence;
        $this->matchType    = $matchType;
        $msg = "Unmapped external product: {$externalName}";
        if ($candidates !== []) {
            $top = $candidates[0];
            $msg .= " (best guess: {$top['sku']} '{$top['name']}' @ {$top['confidence']}% {$top['match_type']}, below auto-accept threshold)";
        }
        parent::__construct($msg);
    }

    public function getExternalName(): string { return $this->externalName; }
    public function getCandidates(): array { return $this->candidates; }
    public function getConfidence(): int { return $this->confidence; }
    public function getMatchType(): string { return $this->matchType; }
}

class PzEngine
{
    /**
     * @param array{
     *   supplier_name?: string,
     *   supplier_invoice?: string,
     *   lines: list<array{
     *     external_name?: string,
     *     resolved_sku?: string,
     *     quantity: float|string,
     *     unit_net_cost: float|string,
     *     vat_rate?: float|string
     *   }>
     * } $payload
     *
     * @return array{success: true, pz_document: array}
     *
     * @throws PzMappingException        when an external_name has no mapping
     * @throws \InvalidArgumentException on validation failures
     * @throws \Throwable                on unrecoverable DB errors (tx rolled back)
     */
    public static function processReceipt(
        PDO    $pdo,
        int    $tenantId,
        string $warehouseId,
        array  $payload,
        string $userId
    ): array {

        $supplierName    = trim($payload['supplier_name'] ?? '');
        $supplierInvoice = trim($payload['supplier_invoice'] ?? '');
        $lines           = $payload['lines'] ?? [];

        if (empty($lines) || !is_array($lines)) {
            throw new \InvalidArgumentException('Payload contains no receipt lines.');
        }

        // =================================================================
        // PHASE 1: MAPPING & VALIDATION  (pre-transaction, read-only)
        //
        // F2.5 (2026-05-11): używa shared AutoScanEngine zamiast samego
        // exact-match. Threshold auto-accept respektowany (default 70).
        // Linie które match-ują ALIAS są kolekcjonowane do PHASE 3 auto-learn.
        // =================================================================

        $mappedLines = [];
        $aliasLearnQueue = []; // [external_name => sku] dla post-commit auto-learn

        foreach ($lines as $idx => $line) {
            $resolvedSku  = trim($line['resolved_sku'] ?? '');
            $externalName = trim($line['external_name'] ?? '');

            if ($resolvedSku === '') {
                if ($externalName === '') {
                    throw new \InvalidArgumentException(
                        "Line #{$idx}: missing both resolved_sku and external_name."
                    );
                }

                // Shared AutoScan — EXACT (100) → ALIAS (85) → NAME (60-80) → FUZZY (≤59) → NONE
                $scan = AutoScanEngine::match($pdo, $tenantId, $externalName);

                if (empty($scan['should_auto_accept']) || $scan['sku'] === null) {
                    // Confidence below threshold albo NONE — frontend dostaje propozycje
                    // i decyduje (UI: pokazuje suggest button, manual select)
                    throw new PzMappingException(
                        $externalName,
                        $scan['candidates'] ?? [],
                        (int) ($scan['confidence'] ?? 0),
                        (string) ($scan['match_type'] ?? 'NONE')
                    );
                }

                $resolvedSku = (string) $scan['sku'];

                // ALIAS auto-learn — kolejka po commit-cie. Zapisuje do
                // sh_product_mapping żeby kolejne PZ z tej samej nazwy
                // miały EXACT 100% (network effect Konstytucja v5 § II).
                if (($scan['match_type'] ?? '') === AutoScanEngine::MATCH_ALIAS) {
                    $aliasLearnQueue[$externalName] = $resolvedSku;
                }
            }

            $quantity    = (float)($line['quantity'] ?? 0);
            $unitNetCost = (float)($line['unit_net_cost'] ?? 0);
            $vatRate     = (float)($line['vat_rate'] ?? 0);

            if ($quantity <= 0) {
                throw new \InvalidArgumentException(
                    "Line #{$idx} (SKU: {$resolvedSku}): quantity must be > 0."
                );
            }
            if ($unitNetCost < 0) {
                throw new \InvalidArgumentException(
                    "Line #{$idx} (SKU: {$resolvedSku}): unit_net_cost cannot be negative."
                );
            }

            $mappedLines[] = [
                'external_name'  => $externalName,
                'resolved_sku'   => $resolvedSku,
                'quantity'       => $quantity,
                'unit_net_cost'  => $unitNetCost,
                'line_net_value' => round($quantity * $unitNetCost, 2),
                'vat_rate'       => $vatRate,
            ];
        }

        // =================================================================
        // PHASE 2: ATOMIC EXECUTION
        // =================================================================
        $pdo->beginTransaction();

        try {
            // --- Document header (insert with empty doc_number, fill after ID) ---
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id,
                     supplier_name, supplier_invoice, created_by)
                VALUES
                    (:tid, '', 'PZ', :wid, :supplier, :invoice, :uid)
            ");
            $stmtDoc->execute([
                ':tid'      => $tenantId,
                ':wid'      => $warehouseId,
                ':supplier' => $supplierName !== '' ? $supplierName : null,
                ':invoice'  => $supplierInvoice !== '' ? $supplierInvoice : null,
                ':uid'      => $userId,
            ]);
            $docId = (int)$pdo->lastInsertId();

            $docNumber = sprintf('PZ/%s/%05d', date('Y/m/d'), $docId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
                ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenantId]);

            // --- Prepare reusable statements (outside loop) ---
            $stmtSelectStock = $pdo->prepare("
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
                     'PZ', :docId, :uid)
            ");

            // --- Per-line processing ---
            $resultLines = [];

            foreach ($mappedLines as $ml) {
                $sku         = $ml['resolved_sku'];
                $rcvQty      = $ml['quantity'];
                $unitNetCost = $ml['unit_net_cost'];

                // Lock & read current stock
                $stmtSelectStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $warehouseId,
                    ':sku' => $sku,
                ]);
                $stockRow = $stmtSelectStock->fetch(PDO::FETCH_ASSOC);

                $oldQty  = $stockRow ? (float)$stockRow['quantity']           : 0.0;
                $oldAvco = $stockRow ? (float)$stockRow['current_avco_price'] : 0.0;

                // Safe AVCO: guard against zero/negative denominator
                $denominator = $oldQty + $rcvQty;
                if ($oldQty <= 0 || $denominator == 0) {
                    $newAvco = $unitNetCost;
                } else {
                    $newAvco = (($oldQty * $oldAvco) + ($rcvQty * $unitNetCost))
                             / $denominator;
                }
                $newAvco = round($newAvco, 6);

                $afterQty = round($oldQty + $rcvQty, 3);

                // Upsert stock row
                $stmtUpsertStock->execute([
                    ':tid'  => $tenantId,
                    ':wid'  => $warehouseId,
                    ':sku'  => $sku,
                    ':qty'  => $rcvQty,
                    ':avco' => $newAvco,
                ]);

                // Document line (preserves old_avco → new_avco for full audit)
                $stmtDocLine->execute([
                    ':docId'   => $docId,
                    ':sku'     => $sku,
                    ':qty'     => $rcvQty,
                    ':unc'     => $unitNetCost,
                    ':lnv'     => $ml['line_net_value'],
                    ':vat'     => $ml['vat_rate'],
                    ':oldAvco' => round($oldAvco, 6),
                    ':newAvco' => $newAvco,
                ]);

                // Audit log — POSITIVE change_qty (goods inbound)
                $stmtLog->execute([
                    ':tid'       => $tenantId,
                    ':wid'       => $warehouseId,
                    ':sku'       => $sku,
                    ':changeQty' => $rcvQty,
                    ':afterQty'  => $afterQty,
                    ':docId'     => $docId,
                    ':uid'       => $userId,
                ]);

                $resultLines[] = [
                    'external_name'  => $ml['external_name'] !== '' ? $ml['external_name'] : null,
                    'resolved_sku'   => $sku,
                    'quantity'       => $rcvQty,
                    'unit_net_cost'  => number_format($unitNetCost, 2, '.', ''),
                    'line_net_value' => number_format($ml['line_net_value'], 2, '.', ''),
                    'vat_rate'       => number_format($ml['vat_rate'], 2, '.', ''),
                    'old_avco'       => number_format(round($oldAvco, 2), 2, '.', ''),
                    'new_avco'       => number_format(round($newAvco, 2), 2, '.', ''),
                ];
            }

            $pdo->commit();

            // =================================================================
            // PHASE 3: POST-COMMIT AUTO-LEARN (network effect / Prawo II)
            //
            // ALIAS matches z PHASE 1 zapisujemy do sh_product_mapping żeby
            // kolejne PZ z tej samej nazwy miały EXACT 100% (zero kliknięć).
            // Failure NIE blokuje sukcesu PZ — dokument już zapisany, AVCO
            // przeliczone. Loggujemy alert.
            // =================================================================
            $learnedCount = 0;
            foreach ($aliasLearnQueue as $extName => $sku) {
                $r = AutoScanEngine::learnMapping($pdo, $tenantId, (string) $extName, (string) $sku);
                if (!empty($r['learned'])) {
                    $learnedCount++;
                } elseif (!empty($r['error'])) {
                    error_log('[PzEngine.autoLearn] ' . $extName . ' → ' . $sku . ': ' . $r['error']);
                }
            }

            return [
                'success'      => true,
                'pz_document'  => [
                    'doc_id'           => $docId,
                    'doc_number'       => $docNumber,
                    'warehouse_id'     => $warehouseId,
                    'supplier_name'    => $supplierName,
                    'supplier_invoice' => $supplierInvoice,
                    'lines'            => $resultLines,
                    'auto_learned'     => $learnedCount,
                    'created_at'       => date('c'),
                    'created_by'       => $userId,
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
