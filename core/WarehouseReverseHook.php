<?php

declare(strict_types=1);

/**
 * SliceHub — Warehouse Reverse Hook (F5-C, 2026-05-11)
 *
 * Wywoływany SYNCHRONICZNIE z `api/pos/engine.php#cancel_order` (i analogicznych)
 * gdy zamówienie zostaje anulowane PO `accepted`. Musi zwrócić składniki na stan.
 *
 * Architektura (Konstytucja v5 § Prawo II — Bliźniak Cyfrowy):
 *   POS klika "Anuluj" → tranzycja `accepted → cancelled` w OSM →
 *   COMMIT outer transakcji → Hook szuka istniejących WZ tego zamówienia →
 *   tworzy KOR (typ KOR) z notes referencjami WZ → wh_stock += qty →
 *   wh_documents WZ pierwotnego → status='reversed' → wh_stock_logs audit.
 *
 * Dlaczego PO commit-cie outer (analogicznie do WarehouseConsumeHook):
 *   1. KOR insertion ma własną transakcję.
 *   2. Failure reverse NIE blokuje anulacji (wahador biznesowy: zamówienie i tak
 *      ma być cancelled, manager poprawi ręczną korektą).
 *
 * Konstytucja v5 § Prawo VI (Snajper):
 *   - tenant_id bariera w każdym query.
 *   - Brak cross-silo JOIN-ów po ID — używamy SKU.
 */
class WarehouseReverseHook
{
    /**
     * Reverse magazynu dla anulowanego zamówienia.
     *
     * @param PDO    $pdo
     * @param int    $tenantId
     * @param string $orderId      UUID CHAR(36)
     * @param int    $userId       Aktor anulujący
     *
     * @return array{
     *   success:bool, doc_id?:int, doc_number?:string,
     *   reversed_wz?:list<int>, restocked?:array<string,float>,
     *   total_value?:float, warehouse_id?:string, error?:string, skipped?:bool
     * }
     */
    public static function onOrderCancelled(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        int $userId
    ): array {
        try {
            // 1. Znajdź wszystkie aktywne WZ tego zamówienia (status != reversed).
            $stmtWZ = $pdo->prepare(
                "SELECT id, warehouse_id
                   FROM wh_documents
                  WHERE tenant_id = :tid
                    AND order_id = :oid
                    AND type = 'WZ'
                    AND status = 'approved'"
            );
            $stmtWZ->execute([':tid' => $tenantId, ':oid' => $orderId]);
            $wzDocs = $stmtWZ->fetchAll(PDO::FETCH_ASSOC);

            if (!$wzDocs) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'No WZ found for this order — nothing to reverse.',
                ];
            }

            // Załóż 1 WZ na zamówienie (wzorzec WzEngine::consumeForOrder).
            // Jeśli pojawi się wiele (re-konsumpcja po edycji) — reverse wszystkie sumarycznie.
            $wzIds        = array_map(static fn($r) => (int)$r['id'], $wzDocs);
            $warehouseIds = array_values(array_unique(array_map(static fn($r) => (string)$r['warehouse_id'], $wzDocs)));
            if (count($warehouseIds) > 1) {
                error_log("[WarehouseReverseHook] order {$orderId} spans multiple warehouses — reversing only first.");
            }
            $warehouseId = $warehouseIds[0];

            // 2. Pobierz linie WZ (sku + qty + avco).
            $ph = implode(',', array_fill(0, count($wzIds), '?'));
            $stmtLines = $pdo->prepare(
                "SELECT sku, SUM(quantity) AS qty, MAX(new_avco) AS avco
                   FROM wh_document_lines
                  WHERE document_id IN ({$ph})
               GROUP BY sku"
            );
            $stmtLines->execute($wzIds);
            $reverseRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            if (!$reverseRows) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'WZ has no lines — nothing to reverse.',
                ];
            }

            // 3. KOR transakcja.
            $pdo->beginTransaction();

            $stmtKor = $pdo->prepare(
                "INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id, order_id, status, notes, created_by)
                 VALUES
                    (:tid, '', 'KOR', :wid, :oid, 'approved', :notes, :uid)"
            );
            $notes = 'REVERSAL of cancelled order — WZ ids: ' . implode(',', $wzIds);
            $stmtKor->execute([
                ':tid'   => $tenantId,
                ':wid'   => $warehouseId,
                ':oid'   => $orderId,
                ':notes' => $notes,
                ':uid'   => $userId,
            ]);
            $korId = (int)$pdo->lastInsertId();
            $korNumber = sprintf('KOR/%s/%05d', date('Y/m/d'), $korId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$korNumber, $korId, $tenantId]);

            // 4. Przygotuj prepared statements.
            $stmtDocLine = $pdo->prepare(
                "INSERT INTO wh_document_lines
                    (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, old_avco, new_avco)
                 VALUES (:did, :sku, :qty, :unc, :lnv, 0, :oldAvco, :newAvco)"
            );
            $stmtAddStock = $pdo->prepare(
                "UPDATE wh_stock
                    SET quantity = quantity + :qty
                  WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku"
            );
            $stmtInsertStock = $pdo->prepare(
                "INSERT INTO wh_stock (tenant_id, warehouse_id, sku, quantity, unit_net_cost, current_avco_price)
                 VALUES (:tid, :wid, :sku, :qty, :avco, :avco)"
            );
            $stmtSelectStock = $pdo->prepare(
                "SELECT quantity FROM wh_stock
                  WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                  FOR UPDATE"
            );
            $stmtLog = $pdo->prepare(
                "INSERT INTO wh_stock_logs
                    (tenant_id, warehouse_id, sku, change_qty, after_qty, document_type, document_id, created_by)
                 VALUES (:tid, :wid, :sku, :qty, :after, 'KOR', :did, :uid)"
            );

            $restocked = [];
            $totalValue = 0.0;
            foreach ($reverseRows as $row) {
                $sku   = (string)$row['sku'];
                $qty   = round((float)$row['qty'], 3);
                $avco  = round((float)$row['avco'], 4);
                if ($qty <= 0 || $sku === '') continue;

                $lineValue = round($qty * $avco, 2);
                $totalValue += $lineValue;

                $stmtDocLine->execute([
                    ':did'     => $korId,
                    ':sku'     => $sku,
                    ':qty'     => $qty,
                    ':unc'     => $avco,
                    ':lnv'     => $lineValue,
                    ':oldAvco' => $avco,
                    ':newAvco' => $avco,
                ]);

                $stmtSelectStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
                $stockRow = $stmtSelectStock->fetch(PDO::FETCH_ASSOC);

                if ($stockRow) {
                    $stmtAddStock->execute([':qty' => $qty, ':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
                    $after = round((float)$stockRow['quantity'] + $qty, 3);
                } else {
                    $stmtInsertStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku, ':qty' => $qty, ':avco' => $avco]);
                    $after = $qty;
                }

                $stmtLog->execute([
                    ':tid'   => $tenantId,
                    ':wid'   => $warehouseId,
                    ':sku'   => $sku,
                    ':qty'   => $qty,
                    ':after' => $after,
                    ':did'   => $korId,
                    ':uid'   => $userId,
                ]);

                $restocked[$sku] = $qty;
            }

            // 5. Oznacz pierwotne WZ jako reversed.
            // Positional only — PDO MySQL nie wspiera mixu named+positional w jednym query.
            $stmtMark = $pdo->prepare(
                "UPDATE wh_documents
                    SET status = 'reversed',
                        notes = CONCAT(COALESCE(notes,''), ' | reversed by KOR#', ?)
                  WHERE id IN ({$ph})
                    AND tenant_id = ?"
            );
            $stmtMark->execute(array_merge([$korId], $wzIds, [$tenantId]));

            $pdo->commit();

            return [
                'success'       => true,
                'doc_id'        => $korId,
                'doc_number'    => $korNumber,
                'reversed_wz'   => $wzIds,
                'restocked'     => $restocked,
                'total_value'   => round($totalValue, 2),
                'warehouse_id'  => $warehouseId,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log(sprintf(
                '[WarehouseReverseHook] reverse failed for order %s tenant %d: %s',
                $orderId, $tenantId, $e->getMessage()
            ));
            return [
                'success' => false,
                'error'   => 'Reverse threw: ' . $e->getMessage(),
            ];
        }
    }
}
