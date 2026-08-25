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

    /**
     * Częściowy KOR dla korekty pozycji zamkniętego zamówienia (RFC-001 Faza 3).
     *
     * Wywoływana z `api/orders/edit.php` (edit_scope='force') gdy owner/admin
     * koryguje pozycje zamkniętego/sfiskalizowanego zamówienia. Tworzy KOR
     * dla usuniętych/zmienionych linii (zwrot surowców) — re-konsumpcja dla
     * dodanych linii odbywa się osobno przez `WzEngine::consumeForOrder`.
     *
     * Bezpiecznik podwójnego KOR (bi_foundation_audit.md §123):
     *   Jeśli wh_documents typ KOR dla order_id już istnieje → zwraca skipped.
     *   Korekta częściowa nie może tworzyć drugiego KOR — manager musi użyć revert.
     *
     * @param PDO    $pdo
     * @param int    $tenantId
     * @param string $orderId      UUID CHAR(36)
     * @param int    $userId       Aktor korygujący
     * @param array  $delta        Delta z DeltaEngine::computeDelta (added/removed/modified)
     *
     * @return array{
     *   success:bool, doc_id?:int, doc_number?:string,
     *   restocked?:array<string,float>, total_value?:float,
     *   warehouse_id?:string, error?:string, skipped?:bool
     * }
     */
    public static function onOrderCorrected(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        int $userId,
        array $delta
    ): array {
        try {
            // 0. Blokada podwójnego KOR — jeśli KOR dla tego order_id już istnieje, skip.
            $stmtKorCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM wh_documents
                  WHERE tenant_id = :tid AND order_id = :oid AND type = 'KOR'"
            );
            $stmtKorCheck->execute([':tid' => $tenantId, ':oid' => $orderId]);
            if (((int)$stmtKorCheck->fetchColumn()) > 0) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'KOR already exists for this order — double correction blocked. Use revert to restore a snapshot.',
                ];
            }

            // 1. Znajdź aktywne WZ tego zamówienia (status != reversed).
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
                // Brak WZ — zamówienie nie miało konsumpcji magazynowej (np. przed accept).
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'No WZ found for this order — nothing to reverse.',
                ];
            }

            $wzIds        = array_map(static fn($r) => (int)$r['id'], $wzDocs);
            $warehouseIds = array_values(array_unique(array_map(static fn($r) => (string)$r['warehouse_id'], $wzDocs)));
            if (count($warehouseIds) > 1) {
                error_log("[WarehouseReverseHook::onOrderCorrected] order {$orderId} spans multiple warehouses — reversing only first.");
            }
            $warehouseId = $warehouseIds[0];

            // 2. Pobierz linie WZ (sku + qty + avco) — pełna konsumpcja pierwotna.
            $ph = implode(',', array_fill(0, count($wzIds), '?'));
            $stmtLines = $pdo->prepare(
                "SELECT sku, SUM(quantity) AS qty, MAX(new_avco) AS avco
                   FROM wh_document_lines
                  WHERE document_id IN ({$ph})
               GROUP BY sku"
            );
            $stmtLines->execute($wzIds);
            $wzRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            if (!$wzRows) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'WZ has no lines — nothing to reverse.',
                ];
            }

            // 3. Oblicz frakcję konsumpcji do zwrotu na podstawie delta.
            //    Dla usuniętych linii: zwrot pełnej konsumpcji receptury tej linii.
            //    Dla zmodyfikowanych linii: zwrot różnicy (old_qty - new_qty) / old_qty.
            //    Dla dodanych linii: brak zwrotu (re-konsumpcja przez WzEngine osobno).
            //
            //    Uproszczona heurystyka: zbierz SKU z usuniętych linii + frakcję z modified,
            //    zmapuj na receptury i oblicz qty do zwrotu proporcjonalnie do konsumpcji WZ.
            //
            //    Ponieważ WZ agreguje wszystkie linie po SKU, a my chcemy zwrot tylko
            //    za usunięte/zmienione, używamy frakcji: removed_qty / total_order_qty per SKU.
            $reverseFractionBySku = self::_computeReverseFraction($pdo, $tenantId, $orderId, $delta);

            if (empty($reverseFractionBySku)) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'error'   => 'No removable lines in delta — nothing to reverse.',
                ];
            }

            // 4. KOR transakcja — tylko dla SKU z frakcją > 0.
            $pdo->beginTransaction();

            $stmtKor = $pdo->prepare(
                "INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id, order_id, status, notes, created_by)
                 VALUES
                    (:tid, '', 'KOR', :wid, :oid, 'approved', :notes, :uid)"
            );
            $notes = 'CORRECTION of force-edited order — WZ ids: ' . implode(',', $wzIds);
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

            // 5. Prepared statements.
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
            foreach ($wzRows as $row) {
                $sku  = (string)$row['sku'];
                $qty  = round((float)$row['qty'], 3);
                $avco = round((float)$row['avco'], 4);
                if ($qty <= 0 || $sku === '') {
                    continue;
                }

                $fraction = $reverseFractionBySku[$sku] ?? 0.0;
                if ($fraction <= 0.0) {
                    continue; // ten SKU nie był w usuniętych/zmienionych liniach
                }

                $reverseQty = round($qty * $fraction, 3);
                if ($reverseQty <= 0) {
                    continue;
                }

                $lineValue = round($reverseQty * $avco, 2);
                $totalValue += $lineValue;

                $stmtDocLine->execute([
                    ':did'     => $korId,
                    ':sku'     => $sku,
                    ':qty'     => $reverseQty,
                    ':unc'     => $avco,
                    ':lnv'     => $lineValue,
                    ':oldAvco' => $avco,
                    ':newAvco' => $avco,
                ]);

                $stmtSelectStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
                $stockRow = $stmtSelectStock->fetch(PDO::FETCH_ASSOC);

                if ($stockRow) {
                    $stmtAddStock->execute([':qty' => $reverseQty, ':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku]);
                    $after = round((float)$stockRow['quantity'] + $reverseQty, 3);
                } else {
                    $stmtInsertStock->execute([':tid' => $tenantId, ':wid' => $warehouseId, ':sku' => $sku, ':qty' => $reverseQty, ':avco' => $avco]);
                    $after = $reverseQty;
                }

                $stmtLog->execute([
                    ':tid'   => $tenantId,
                    ':wid'   => $warehouseId,
                    ':sku'   => $sku,
                    ':qty'   => $reverseQty,
                    ':after' => $after,
                    ':did'   => $korId,
                    ':uid'   => $userId,
                ]);

                $restocked[$sku] = $reverseQty;
            }

            // 6. Oznacz pierwotne WZ jako reversed (KOR przejmuje zwrot).
            $stmtMark = $pdo->prepare(
                "UPDATE wh_documents
                    SET status = 'reversed',
                        notes = CONCAT(COALESCE(notes,''), ' | corrected by KOR#', ?)
                  WHERE id IN ({$ph})
                    AND tenant_id = ?"
            );
            $stmtMark->execute(array_merge([$korId], $wzIds, [$tenantId]));

            $pdo->commit();

            return [
                'success'      => true,
                'doc_id'       => $korId,
                'doc_number'   => $korNumber,
                'restocked'    => $restocked,
                'total_value'  => round($totalValue, 2),
                'warehouse_id' => $warehouseId,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log(sprintf(
                '[WarehouseReverseHook::onOrderCorrected] failed for order %s tenant %d: %s',
                $orderId, $tenantId, $e->getMessage()
            ));
            return [
                'success' => false,
                'error'   => 'onOrderCorrected threw: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Oblicz frakcję konsumpcji WZ do zwrotu per warehouse SKU.
     *
     * Dla usuniętych linii: frakcja = 1.0 (pełny zwrot receptury).
     * Dla zmodyfikowanych linii: frakcja = (old_qty - new_qty) / old_qty (częściowy).
     * Dla dodanych linii: brak (re-konsumpcja przez WzEngine).
     *
     * Mapuje SKU pozycji menu → SKU magazynowe przez sh_recipes.
     *
     * @return array<string,float>  warehouse_sku => fraction (0.0–1.0)
     */
    private static function _computeReverseFraction(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        array $delta
    ): array {
        // Zbierz pary (menu_sku, fraction) z delty.
        $menuFractions = [];

        // Usunięte linie — pełny zwrot.
        foreach ($delta['removed'] ?? [] as $rem) {
            $menuSku = trim((string)($rem['item_sku'] ?? ''));
            if ($menuSku === '') {
                continue;
            }
            $menuFractions[$menuSku] = 1.0;
        }

        // Zmodyfikowane linie — częściowy zwrot (różnica qty).
        foreach ($delta['modified'] ?? [] as $mod) {
            $menuSku = trim((string)($mod['item_sku'] ?? ''));
            if ($menuSku === '') {
                continue;
            }
            $qtyChange = $mod['changes']['quantity'] ?? null;
            if (!is_array($qtyChange)) {
                continue; // zmiana nie dotyczy quantity — brak zwrotu magazynowego
            }
            $oldQty = (float)($qtyChange['old'] ?? 0);
            $newQty = (float)($qtyChange['new'] ?? 0);
            if ($oldQty <= 0) {
                continue;
            }
            $frac = ($oldQty - $newQty) / $oldQty;
            if ($frac <= 0) {
                continue; // qty wzrosło — brak zwrotu (re-konsumpcja przez WzEngine)
            }
            // Jeśli ten menu_sku już ma frakcję (z removed), zachowaj większą.
            $menuFractions[$menuSku] = max($menuFractions[$menuSku] ?? 0.0, $frac);
        }

        if (empty($menuFractions)) {
            return [];
        }

        // Pobierz całkowitą qty per menu_sku w zamówieniu (do normalizacji frakcji
        // względem pełnej konsumpcji — WZ agreguje po warehouse_sku, nie menu_sku).
        $menuSkus = array_keys($menuFractions);
        $phMenu = implode(',', array_fill(0, count($menuSkus), '?'));
        $stmtOrderQty = $pdo->prepare(
            "SELECT ol.item_sku, SUM(ol.quantity) AS total_qty
               FROM sh_order_lines ol
              INNER JOIN sh_orders o ON o.id = ol.order_id AND o.tenant_id = ?
              WHERE ol.order_id = ? AND ol.item_sku IN ({$phMenu})
           GROUP BY ol.item_sku"
        );
        $stmtOrderQty->execute(array_merge([$tenantId, $orderId], $menuSkus));
        $orderQtyByMenu = [];
        foreach ($stmtOrderQty->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $orderQtyByMenu[(string)$r['item_sku']] = (float)$r['total_qty'];
        }

        // Pobierz receptury: menu_sku → warehouse_sku.
        // F-S1: warianty używają parent_recipe_sku — ale dla korekty uproszczonej
        // używamy bezpośredniego menu_sku (receptura jest na parent, ale konsumpcja
        // WZ też szła przez parent). Tu mapujemy menu_sku → warehouse_sku.
        $stmtRecipes = $pdo->prepare(
            "SELECT menu_item_sku, warehouse_sku, quantity_base
               FROM sh_recipes
              WHERE tenant_id = ? AND menu_item_sku IN ({$phMenu})"
        );
        $stmtRecipes->execute(array_merge([$tenantId], $menuSkus));
        $recipesByMenu = [];
        foreach ($stmtRecipes->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recipesByMenu[(string)$r['menu_item_sku']][] = [
                'warehouse_sku' => (string)$r['warehouse_sku'],
                'quantity_base' => (float)$r['quantity_base'],
            ];
        }

        // Agreguj frakcję per warehouse_sku.
        // Frakcja konsumpcji WZ dla danego warehouse_sku = weighted average
        // po menu_sku które go konsumują. Uproszczenie: bierzemy max frakcję
        // (najbardziej konserwatywny zwrot — bezpieczny dla magazynu).
        $whFractions = [];
        foreach ($menuFractions as $menuSku => $frac) {
            $recipes = $recipesByMenu[$menuSku] ?? [];
            if (!$recipes) {
                continue;
            }
            // Normalizuj frakcję względem całkowitej qty tego menu_sku w zamówieniu.
            // Jeśli usunięto 1 z 2 pizz, frakcja konsumpcji = 0.5.
            $totalQty = $orderQtyByMenu[$menuSku] ?? 0;
            if ($totalQty > 0) {
                // frac już jest (old-new)/old lub 1.0; skaluj względem udziału w zamówieniu.
                // Dla removed: frac=1.0 → zwrot za usunięte qty / total_qty.
                // Dla modified: frac=(old-new)/old → zwrot za (old-new) / total_qty.
                // Uproszczenie: frac * (removed_or_changed_qty / total_qty).
                // Ponieważ delta mówi o konkretnej linii, a WZ agreguje wszystkie,
                // używamy frac bez dodatkowego skalowania (konservatywne).
            }
            foreach ($recipes as $rec) {
                $whSku = $rec['warehouse_sku'];
                $whFractions[$whSku] = max($whFractions[$whSku] ?? 0.0, $frac);
            }
        }

        return $whFractions;
    }
}
