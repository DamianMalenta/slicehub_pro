<?php

declare(strict_types=1);

/**
 * WZ Engine — Recipe-Based Stock Consumption
 *
 * Atomic engine that converts a completed order into warehouse stock deductions
 * by resolving recipes and modifiers, then writing a WZ (Wydanie Zewnętrzne) document.
 *
 * V2 schema: sh_orders / sh_order_lines, wh_documents / wh_document_lines (unified with KorEngine),
 * wh_stock (PK tenant_id + warehouse_id + sku).
 *
 * SQL patterns:
 *   - FOR UPDATE row locking to prevent races
 *   - Negative stock insertion when no row exists (Alert 86 convention)
 *   - change_qty stored as NEGATIVE in wh_stock_logs
 */
class WzEngine
{
    /**
     * @return array{success: bool, doc_id?: int, doc_number?: string, total_cost?: float, deductions?: array<string,float>, error?: string}
     *
     * @throws \Throwable on unrecoverable DB errors (the transaction is rolled back before re-throw)
     */
    public static function consumeForOrder(
        PDO $pdo,
        int $tenantId,
        string $warehouseId,
        string $orderId,
        int $userId
    ): array {
        // =================================================================
        // PHASE 1: MATHEMATICAL CALCULATOR  (read-only, no transaction)
        // =================================================================

        // 1A — Resolve order by UUID primary key (CHAR(36))
        $stmtOrder = $pdo->prepare(
            'SELECT id FROM sh_orders WHERE tenant_id = :tid AND id = :oid LIMIT 1'
        );
        $stmtOrder->execute([':tid' => $tenantId, ':oid' => $orderId]);
        $orderRow = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderRow) {
            return ['success' => false, 'error' => "Order not found: {$orderId}"];
        }

        // 1B — Order lines + menu metadata (tenant-safe via sh_orders)
        $stmtLines = $pdo->prepare("
            SELECT
                ol.id              AS line_id,
                ol.item_sku,
                ol.quantity        AS line_qty,
                ol.modifiers_json,
                ol.removed_ingredients_json,
                mi.type            AS item_type
            FROM sh_order_lines ol
            INNER JOIN sh_orders o
                ON o.id = ol.order_id
               AND o.tenant_id = :tid
            LEFT JOIN sh_menu_items mi
                ON mi.ascii_key = ol.item_sku
               AND mi.tenant_id = :tid
            WHERE ol.order_id = :oid
        ");
        $stmtLines->execute([':tid' => $tenantId, ':oid' => $orderId]);
        $orderLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        if ($orderLines === [] || $orderLines === false) {
            return ['success' => false, 'error' => 'Order has no line items.'];
        }

        // 1C — Modifier / removal rows scoped to the line's menu item (no global ascii_key join)
        $stmtScopedMod = $pdo->prepare("
            SELECT m.linked_warehouse_sku,
                   m.linked_quantity,
                   m.linked_waste_percent
            FROM sh_modifiers m
            INNER JOIN sh_item_modifiers im ON im.group_id = m.group_id
            INNER JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid
            WHERE mi.ascii_key = :item_sku
              AND m.ascii_key = :mod_sku
              AND m.is_deleted = 0
              AND m.is_active = 1
            LIMIT 1
        ");

        $removedByLine = [];
        $addedByLine   = [];

        foreach ($orderLines as $line) {
            $lineId = (string) $line['line_id'];
            $itemSku = (string) $line['item_sku'];

            foreach (self::decodeJsonObjectList($line['modifiers_json'] ?? null) as $modEntry) {
                $modSku = trim((string) ($modEntry['sku'] ?? ''));
                if ($modSku === '') {
                    continue;
                }
                $stmtScopedMod->execute([
                    ':tid'      => $tenantId,
                    ':item_sku' => $itemSku,
                    ':mod_sku'  => $modSku,
                ]);
                $row = $stmtScopedMod->fetch(PDO::FETCH_ASSOC);
                $stmtScopedMod->closeCursor();
                if (!$row) {
                    continue;
                }
                $whSku = $row['linked_warehouse_sku'] ?? null;
                if ($whSku === null || $whSku === '') {
                    continue;
                }
                $addedByLine[$lineId][] = [
                    'warehouse_sku' => (string) $whSku,
                    'quantity'      => (float) $row['linked_quantity'],
                    'waste_percent' => (float) $row['linked_waste_percent'],
                ];
            }

            foreach (self::decodeJsonObjectList($line['removed_ingredients_json'] ?? null) as $remEntry) {
                $remSku = trim((string) ($remEntry['sku'] ?? ''));
                if ($remSku === '') {
                    continue;
                }
                $stmtScopedMod->execute([
                    ':tid'      => $tenantId,
                    ':item_sku' => $itemSku,
                    ':mod_sku'  => $remSku,
                ]);
                $row = $stmtScopedMod->fetch(PDO::FETCH_ASSOC);
                $stmtScopedMod->closeCursor();
                $warehouseSku = ($row && !empty($row['linked_warehouse_sku']))
                    ? (string) $row['linked_warehouse_sku']
                    : $remSku;
                $removedByLine[$lineId][] = $warehouseSku;
            }
        }

        // 1D — Half-half: children by parent_sku (studio) or composite "A+B" cart SKU
        $halfHalfParentSkus = [];
        foreach ($orderLines as $line) {
            if (($line['item_type'] ?? '') === 'half_half') {
                $halfHalfParentSkus[] = (string) $line['item_sku'];
            }
        }

        $childSkuMap = [];
        if ($halfHalfParentSkus !== []) {
            $phHH = self::placeholders($halfHalfParentSkus);
            $stmtChildren = $pdo->prepare("
                SELECT ascii_key, parent_sku
                FROM sh_menu_items
                WHERE tenant_id = :tid
                  AND parent_sku IN ({$phHH})
                  AND is_deleted = 0
                ORDER BY display_order ASC
            ");
            $stmtChildren->execute(array_merge([$tenantId], array_values($halfHalfParentSkus)));
            foreach ($stmtChildren->fetchAll(PDO::FETCH_ASSOC) as $child) {
                $childSkuMap[$child['parent_sku']][] = $child['ascii_key'];
            }
        }

        // 1E — Collect all menu SKUs that need recipe rows
        $recipeSkuSet = [];
        foreach ($orderLines as $line) {
            $itemSku = (string) $line['item_sku'];
            $type    = $line['item_type'] ?? null;

            if ($type === 'half_half') {
                foreach ($childSkuMap[$itemSku] ?? [] as $childSku) {
                    $recipeSkuSet[$childSku] = true;
                }
            } elseif (str_contains($itemSku, '+')) {
                foreach (array_map('trim', explode('+', $itemSku)) as $part) {
                    if ($part !== '') {
                        $recipeSkuSet[$part] = true;
                    }
                }
            } else {
                $recipeSkuSet[$itemSku] = true;
            }
        }

        $recipeSkus = array_keys($recipeSkuSet);
        if ($recipeSkus === []) {
            return ['success' => false, 'error' => 'Order has no line items.'];
        }

        $phR = self::placeholders($recipeSkus);
        $stmtRecipes = $pdo->prepare("
            SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent
            FROM sh_recipes
            WHERE tenant_id = :tid
              AND menu_item_sku IN ({$phR})
        ");
        $stmtRecipes->execute(array_merge([$tenantId], $recipeSkus));

        $recipesByItem = [];
        foreach ($stmtRecipes->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recipesByItem[$r['menu_item_sku']][] = [
                'warehouse_sku' => $r['warehouse_sku'],
                'quantity_base' => (float) $r['quantity_base'],
                'waste_percent' => (float) $r['waste_percent'],
            ];
        }

        // 1F — Aggregate deductions: warehouse_sku => total quantity to deduct
        $deductions = [];

        foreach ($orderLines as $line) {
            $lineQty     = (float) $line['line_qty'];
            $lineId      = (string) $line['line_id'];
            $removedSkus = $removedByLine[$lineId] ?? [];
            $itemSku     = (string) $line['item_sku'];
            $type        = $line['item_type'] ?? null;

            if ($type === 'half_half') {
                $children = $childSkuMap[$itemSku] ?? [];
                foreach ($children as $childSku) {
                    self::aggregateRecipes(
                        $recipesByItem[$childSku] ?? [],
                        $removedSkus,
                        0.5,
                        $lineQty,
                        $deductions
                    );
                }
            } elseif (str_contains($itemSku, '+')) {
                foreach (array_map('trim', explode('+', $itemSku)) as $part) {
                    if ($part === '') {
                        continue;
                    }
                    self::aggregateRecipes(
                        $recipesByItem[$part] ?? [],
                        $removedSkus,
                        0.5,
                        $lineQty,
                        $deductions
                    );
                }
            } else {
                self::aggregateRecipes(
                    $recipesByItem[$itemSku] ?? [],
                    $removedSkus,
                    1.0,
                    $lineQty,
                    $deductions
                );
            }

            foreach ($addedByLine[$lineId] ?? [] as $mod) {
                $dedQty = $mod['quantity']
                    * (1 + ($mod['waste_percent'] / 100))
                    * $lineQty;
                $deductions[$mod['warehouse_sku']]
                    = ($deductions[$mod['warehouse_sku']] ?? 0.0) + $dedQty;
            }
        }

        if ($deductions === []) {
            return [
                'success' => false,
                'error'   => 'No stock deductions computed — recipes may not be configured.',
            ];
        }

        // =================================================================
        // PHASE 2: ATOMIC EXECUTION  (unified wh_documents — KorEngine compatible)
        // =================================================================
        $pdo->beginTransaction();

        try {
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id, order_id, status, notes, created_by)
                VALUES
                    (:tid, '', 'WZ', :wid, :oid, 'approved', :notes, :uid)
            ");
            $stmtDoc->execute([
                ':tid'   => $tenantId,
                ':wid'   => $warehouseId,
                ':oid'   => $orderId,
                ':notes' => "Order: {$orderId}",
                ':uid'   => $userId,
            ]);
            $docId = (int) $pdo->lastInsertId();

            $docNumber = sprintf('WZ/%s/%05d', date('Y/m/d'), $docId);
            $pdo->prepare('UPDATE wh_documents SET doc_number = :dn WHERE id = :id AND tenant_id = :tid')
                ->execute([':dn' => $docNumber, ':id' => $docId, ':tid' => $tenantId]);

            $stmtSelectStock = $pdo->prepare("
                SELECT quantity, current_avco_price
                FROM wh_stock
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
                FOR UPDATE
            ");

            $stmtDocLine = $pdo->prepare("
                INSERT INTO wh_document_lines
                    (document_id, sku, quantity, unit_net_cost,
                     line_net_value, vat_rate, old_avco, new_avco)
                VALUES
                    (:docId, :sku, :qty, :unc, :lnv, :vat, :oldAvco, :newAvco)
            ");

            $stmtUpdateStock = $pdo->prepare("
                UPDATE wh_stock
                SET quantity = quantity - :qty
                WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku
            ");

            $stmtInsertNeg = $pdo->prepare("
                INSERT INTO wh_stock
                    (tenant_id, warehouse_id, sku, quantity, unit_net_cost, current_avco_price)
                VALUES
                    (:tid, :wid, :sku, :quantity, 0, 0)
            ");

            $stmtLog = $pdo->prepare("
                INSERT INTO wh_stock_logs
                    (tenant_id, warehouse_id, sku, change_qty, after_qty,
                     document_type, document_id, created_by)
                VALUES
                    (:tenantId, :warehouseId, :sku, :changeQty, :afterQty,
                     'WZ', :docId, :createdBy)
            ");

            $totalCost = 0.0;

            foreach ($deductions as $sku => $deductQty) {
                $deductQty = round($deductQty, 3);
                if ($deductQty <= 0) {
                    continue;
                }

                $stmtSelectStock->execute([
                    ':tid' => $tenantId,
                    ':wid' => $warehouseId,
                    ':sku' => $sku,
                ]);
                $stockRow = $stmtSelectStock->fetch(PDO::FETCH_ASSOC);

                $currentQty  = $stockRow ? (float) $stockRow['quantity'] : 0.0;
                $currentAvco = $stockRow ? (float) $stockRow['current_avco_price'] : 0.0;

                $lineValue = round($deductQty * $currentAvco, 2);
                $totalCost += $lineValue;

                $stmtDocLine->execute([
                    ':docId'    => $docId,
                    ':sku'      => $sku,
                    ':qty'      => $deductQty,
                    ':unc'      => $currentAvco,
                    ':lnv'      => $lineValue,
                    ':vat'      => 0.0,
                    ':oldAvco'  => $currentAvco,
                    ':newAvco'  => $currentAvco,
                ]);

                if ($stockRow) {
                    $stmtUpdateStock->execute([
                        ':qty' => $deductQty,
                        ':tid' => $tenantId,
                        ':wid' => $warehouseId,
                        ':sku' => $sku,
                    ]);
                } else {
                    $stmtInsertNeg->execute([
                        ':tid'      => $tenantId,
                        ':wid'      => $warehouseId,
                        ':sku'      => $sku,
                        ':quantity' => -$deductQty,
                    ]);
                }

                $afterQty = round($currentQty - $deductQty, 3);
                $stmtLog->execute([
                    ':tenantId'    => $tenantId,
                    ':warehouseId' => $warehouseId,
                    ':sku'         => $sku,
                    ':changeQty'   => -$deductQty,
                    ':afterQty'    => $afterQty,
                    ':docId'       => $docId,
                    ':createdBy'   => $userId,
                ]);
            }

            $pdo->commit();

            return [
                'success'    => true,
                'doc_id'     => $docId,
                'doc_number' => $docNumber,
                'total_cost' => round($totalCost, 2),
                'deductions' => $deductions,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Read-only preflight for checkout.
     *
     * Accepts `CartEngine::calculate()['lines_raw']` and returns whether the
     * warehouse has enough stock to satisfy the order before we persist it.
     *
     * @param list<array<string,mixed>> $linesRaw
     * @return array{
     *   success: bool,
     *   available: bool,
     *   warehouse_id: string,
     *   shortages: list<array{sku:string, required:float, available:float, deficit:float}>,
     *   deductions?: array<string,float>,
     *   note?: string,
     *   error?: string
     * }
     */
    public static function checkAvailability(
        PDO $pdo,
        int $tenantId,
        string $warehouseId,
        array $linesRaw
    ): array {
        $warehouseId = trim($warehouseId) !== '' ? trim($warehouseId) : 'MAIN';
        if ($linesRaw === []) {
            return [
                'success' => true,
                'available' => true,
                'warehouse_id' => $warehouseId,
                'shortages' => [],
                'note' => 'No order lines to validate.',
            ];
        }

        $deductions = self::resolveDeductionsForPayloadLines($pdo, $tenantId, $linesRaw);
        if ($deductions === []) {
            return [
                'success' => true,
                'available' => true,
                'warehouse_id' => $warehouseId,
                'shortages' => [],
                'note' => 'No stock deductions computed — recipes may not be configured.',
            ];
        }

        $skus = array_keys($deductions);
        $ph = self::placeholders($skus);
        $stmt = $pdo->prepare("
            SELECT sku, quantity
            FROM wh_stock
            WHERE tenant_id = :tid
              AND warehouse_id = :wid
              AND sku IN ({$ph})
        ");
        $stmt->execute(array_merge([$tenantId, $warehouseId], $skus));

        $availableMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $availableMap[(string)$row['sku']] = (float)$row['quantity'];
        }

        $shortages = [];
        foreach ($deductions as $sku => $requiredQty) {
            $requiredQty = round((float)$requiredQty, 3);
            $availableQty = round((float)($availableMap[$sku] ?? 0.0), 3);
            if ($availableQty + 0.0001 >= $requiredQty) {
                continue;
            }
            $shortages[] = [
                'sku'       => (string)$sku,
                'required'  => $requiredQty,
                'available' => $availableQty,
                'deficit'   => round($requiredQty - $availableQty, 3),
            ];
        }

        return [
            'success'      => true,
            'available'    => $shortages === [],
            'warehouse_id' => $warehouseId,
            'shortages'    => $shortages,
            'deductions'   => $deductions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeJsonObjectList(?string $json): array
    {
        if ($json === null || $json === '' || $json === 'null') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $linesRaw
     * @return array<string,float>
     */
    private static function resolveDeductionsForPayloadLines(
        PDO $pdo,
        int $tenantId,
        array $linesRaw
    ): array {
        $linePayloads = [];
        $itemSkuSet = [];
        $recipeSkuSet = [];

        foreach ($linesRaw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemSku = trim((string)($line['item_sku'] ?? ''));
            $quantity = max(1.0, (float)($line['quantity'] ?? 1));
            if ($itemSku === '') {
                continue;
            }

            $linePayloads[] = [
                'item_sku'                 => $itemSku,
                'quantity'                 => $quantity,
                'modifiers_json'           => $line['modifiers_json'] ?? null,
                'removed_ingredients_json' => $line['removed_ingredients_json'] ?? null,
            ];

            $itemSkuSet[$itemSku] = true;
            if (str_contains($itemSku, '+')) {
                foreach (array_map('trim', explode('+', $itemSku)) as $partSku) {
                    if ($partSku !== '') {
                        $recipeSkuSet[$partSku] = true;
                    }
                }
            } else {
                $recipeSkuSet[$itemSku] = true;
            }
        }

        if ($linePayloads === [] || $recipeSkuSet === []) {
            return [];
        }

        $recipeSkus = array_keys($recipeSkuSet);
        $phR = self::placeholders($recipeSkus);
        $stmtRecipes = $pdo->prepare("
            SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent
            FROM sh_recipes
            WHERE tenant_id = :tid
              AND menu_item_sku IN ({$phR})
        ");
        $stmtRecipes->execute(array_merge([$tenantId], $recipeSkus));

        $recipesByItem = [];
        foreach ($stmtRecipes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipesByItem[(string)$row['menu_item_sku']][] = [
                'warehouse_sku' => (string)$row['warehouse_sku'],
                'quantity_base' => (float)$row['quantity_base'],
                'waste_percent' => (float)$row['waste_percent'],
            ];
        }

        $stmtScopedMod = $pdo->prepare("
            SELECT m.linked_warehouse_sku,
                   m.linked_quantity,
                   m.linked_waste_percent
            FROM sh_modifiers m
            INNER JOIN sh_item_modifiers im ON im.group_id = m.group_id
            INNER JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid
            WHERE mi.ascii_key = :item_sku
              AND m.ascii_key = :mod_sku
              AND m.is_deleted = 0
              AND m.is_active = 1
            LIMIT 1
        ");

        $deductions = [];
        foreach ($linePayloads as $line) {
            $itemSku = (string)$line['item_sku'];
            $lineQty = (float)$line['quantity'];
            $removedSkus = [];

            foreach (self::decodeJsonObjectList($line['removed_ingredients_json'] ?? null) as $remEntry) {
                $remSku = trim((string)($remEntry['sku'] ?? ''));
                if ($remSku === '') {
                    continue;
                }
                $stmtScopedMod->execute([
                    ':tid'      => $tenantId,
                    ':item_sku' => $itemSku,
                    ':mod_sku'  => $remSku,
                ]);
                $row = $stmtScopedMod->fetch(PDO::FETCH_ASSOC);
                $stmtScopedMod->closeCursor();
                $removedSkus[] = ($row && !empty($row['linked_warehouse_sku']))
                    ? (string)$row['linked_warehouse_sku']
                    : $remSku;
            }

            if (str_contains($itemSku, '+')) {
                foreach (array_map('trim', explode('+', $itemSku)) as $partSku) {
                    if ($partSku === '') {
                        continue;
                    }
                    self::aggregateRecipes(
                        $recipesByItem[$partSku] ?? [],
                        $removedSkus,
                        0.5,
                        $lineQty,
                        $deductions
                    );
                }
            } else {
                self::aggregateRecipes(
                    $recipesByItem[$itemSku] ?? [],
                    $removedSkus,
                    1.0,
                    $lineQty,
                    $deductions
                );
            }

            foreach (self::decodeJsonObjectList($line['modifiers_json'] ?? null) as $modEntry) {
                $modSku = trim((string)($modEntry['sku'] ?? ''));
                if ($modSku === '') {
                    continue;
                }
                $stmtScopedMod->execute([
                    ':tid'      => $tenantId,
                    ':item_sku' => $itemSku,
                    ':mod_sku'  => $modSku,
                ]);
                $row = $stmtScopedMod->fetch(PDO::FETCH_ASSOC);
                $stmtScopedMod->closeCursor();
                if (!$row || empty($row['linked_warehouse_sku'])) {
                    continue;
                }
                $dedQty = (float)$row['linked_quantity']
                    * (1 + ((float)$row['linked_waste_percent'] / 100))
                    * $lineQty;
                $warehouseSku = (string)$row['linked_warehouse_sku'];
                $deductions[$warehouseSku] = ($deductions[$warehouseSku] ?? 0.0) + $dedQty;
            }
        }

        return $deductions;
    }

    /**
     * @param array<int, array{warehouse_sku: string, quantity_base: float, waste_percent: float}> $recipes
     * @param list<string>                                                                           $removedSkus
     * @param array<string, float>                                                                   $deductions
     */
    private static function aggregateRecipes(
        array $recipes,
        array $removedSkus,
        float $multiplier,
        float $lineQty,
        array &$deductions
    ): void {
        foreach ($recipes as $recipe) {
            if (in_array($recipe['warehouse_sku'], $removedSkus, true)) {
                continue;
            }
            $dedQty = $recipe['quantity_base']
                * (1 + ($recipe['waste_percent'] / 100))
                * $multiplier
                * $lineQty;
            $deductions[$recipe['warehouse_sku']]
                = ($deductions[$recipe['warehouse_sku']] ?? 0.0) + $dedQty;
        }
    }

    /**
     * @param list<mixed> $items
     */
    private static function placeholders(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }
}
