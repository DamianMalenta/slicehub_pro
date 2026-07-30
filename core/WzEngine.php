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
     * Konsumuje surowce dla zaakceptowanego zamówienia.
     *
     * WPIĘTE w produkcyjny przepływ (sesja F1 · 2026-05-11):
     *   `core/WarehouseConsumeHook::onOrderAccepted` → wołane synchronicznie
     *   PO commit-cie outer transakcji w:
     *     - `api/pos/engine.php#accept_order` (manager klika "PRZYGOTUJ" w POS)
     *     - `api/orders/accept.php` (REST endpoint accept dla ścieżek backoffice)
     *
     * Wszystkie kanały (POS, online, kelner, kiosk) idą przez `accept_order` /
     * `orders/accept.php` → jeden punkt wpięcia = pełne pokrycie.
     *
     * Formuła zgodna z Konstytucją v5 § Prawo II (Bliźniak Cyfrowy):
     *   `needed = recipe_qty × (1 + waste%/100) × multiplier`
     *   gdzie multiplier = 0.5 dla half-half / kompozycji "A+B", 1.0 dla normalnych.
     *
     * Test E2E (potwierdzony 2026-05-11):
     *   - PIZZA_PEPPERONI: 5 składników skonsumowane, food cost 11.56 PLN, doc WZ/2026/05/11/00005
     *   - PIZZA_4FORMAGGI: 6 składników skonsumowane, food cost 12.51 PLN, doc WZ/2026/05/11/00006
     *   - Każdy spadek `wh_stock.quantity` matematycznie zgodny z formułą.
     *
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

        // 1B — Order lines + menu metadata + VARIANT resolution.
        //
        // F-S1 (2026-05-11): Jeśli `ol.item_sku` to wariant (mi.parent_item_id != NULL),
        // RECEPTURA jest na parent (pizza_master), a faktyczna konsumpcja = recipe × multiplier(option).
        // Dla zwykłych itemów (`parent_item_id` NULL): recipe_sku = item_sku, multiplier = 1.0.
        //
        // Konstytucja v5 § Prawo II — Bliźniak Cyfrowy: jedna receptura, wiele rozmiarów.
        //
        // Schema-aware fetch — jeśli kolumny F-S1 nie istnieją (stara baza bez migracji 048),
        // używamy fallback bez variant info.
        $hasVariantColumns = false;
        try {
            $pdo->query("SELECT variant_scale_id FROM sh_menu_items LIMIT 0");
            $hasVariantColumns = true;
        } catch (\PDOException $e) {
            $hasVariantColumns = false;
        }

        // F-S3.2 (2026-05-11): probe combo_meta_json (graceful gdy migracja 054 brak).
        $hasComboMeta = false;
        try {
            $pdo->query("SELECT combo_meta_json FROM sh_order_lines LIMIT 0");
            $hasComboMeta = true;
        } catch (\PDOException $e) {}

        $comboMetaSelect = $hasComboMeta ? "ol.combo_meta_json," : "NULL AS combo_meta_json,";

        if ($hasVariantColumns) {
            $stmtLines = $pdo->prepare("
                SELECT
                    ol.id              AS line_id,
                    ol.item_sku,
                    ol.quantity        AS line_qty,
                    ol.modifiers_json,
                    ol.removed_ingredients_json,
                    {$comboMetaSelect}
                    mi.type            AS item_type,
                    mi.parent_item_id,
                    mi.variant_option_id,
                    parent_mi.ascii_key AS parent_recipe_sku,
                    opt.multiplier      AS variant_multiplier
                FROM sh_order_lines ol
                INNER JOIN sh_orders o
                    ON o.id = ol.order_id
                   AND o.tenant_id = :tid
                LEFT JOIN sh_menu_items mi
                    ON mi.ascii_key = ol.item_sku
                   AND mi.tenant_id = :tid
                LEFT JOIN sh_menu_items parent_mi
                    ON parent_mi.id = mi.parent_item_id
                   AND parent_mi.tenant_id = :tid
                LEFT JOIN sh_variant_scale_options opt
                    ON opt.id = mi.variant_option_id
                   AND opt.tenant_id = :tid
                WHERE ol.order_id = :oid
            ");
        } else {
            $stmtLines = $pdo->prepare("
                SELECT
                    ol.id              AS line_id,
                    ol.item_sku,
                    ol.quantity        AS line_qty,
                    ol.modifiers_json,
                    ol.removed_ingredients_json,
                    {$comboMetaSelect}
                    mi.type            AS item_type,
                    NULL AS parent_item_id,
                    NULL AS variant_option_id,
                    NULL AS parent_recipe_sku,
                    NULL AS variant_multiplier
                FROM sh_order_lines ol
                INNER JOIN sh_orders o
                    ON o.id = ol.order_id
                   AND o.tenant_id = :tid
                LEFT JOIN sh_menu_items mi
                    ON mi.ascii_key = ol.item_sku
                   AND mi.tenant_id = :tid
                WHERE ol.order_id = :oid
            ");
        }
        $stmtLines->execute([':tid' => $tenantId, ':oid' => $orderId]);
        $orderLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        if ($orderLines === [] || $orderLines === false) {
            return ['success' => false, 'error' => 'Order has no line items.'];
        }

        // F-S3.2 (2026-05-11): expand combo lines.
        // Linia z combo_meta_json zostaje wymieniona na N "wirtualnych" linii — po jednej
        // dla każdego składnika combo (fixed_items + picks z category_choice). Każda linia
        // dostaje swój item_sku + qty pomnożone przez line_qty combo.
        //
        // Wirtualne linie używają tych samych pól (modifiers_json, removed) puste,
        // bo modyfikatory combo dotyczą całego bundle (nie pojedynczego składnika).
        //
        // Variant columns: jeśli składnik to wariant (np. PIZZA_FAMILY_L), to LEFT JOIN
        // wykona się po expansion — robimy drugi fetch dla nowych SKU.
        $expandedLines = [];
        $comboExpandedSkus = [];
        foreach ($orderLines as $line) {
            $comboMetaJson = $line['combo_meta_json'] ?? null;
            $meta = null;
            if ($comboMetaJson) {
                $meta = json_decode((string)$comboMetaJson, true);
            }
            if (!is_array($meta) || !isset($meta['meal_id'])) {
                $expandedLines[] = $line;
                continue;
            }

            // Zbierz wszystkie SKU składników combo.
            $components = [];
            if (!empty($meta['fixed_items']) && is_array($meta['fixed_items'])) {
                foreach ($meta['fixed_items'] as $fi) {
                    $sku = trim((string)($fi['sku'] ?? ''));
                    $qty = max(1, (int)($fi['qty'] ?? 1));
                    if ($sku !== '') $components[] = ['sku' => $sku, 'qty' => $qty];
                }
            }
            if (!empty($meta['picks']) && is_array($meta['picks'])) {
                foreach ($meta['picks'] as $p) {
                    $sku = trim((string)($p['sku'] ?? ''));
                    $qty = max(1, (int)($p['qty'] ?? 1));
                    if ($sku !== '') $components[] = ['sku' => $sku, 'qty' => $qty];
                }
            }
            if (!$components) {
                // Brak składników w meta — zachowaj linię combo (legacy / niewspierane)
                $expandedLines[] = $line;
                continue;
            }

            $comboQty = (float)$line['line_qty'];
            foreach ($components as $c) {
                $virtSku = $c['sku'];
                $virtQty = $comboQty * (float)$c['qty'];
                $expandedLines[] = [
                    'line_id'                  => $line['line_id'] . '__c_' . $virtSku,
                    'item_sku'                 => $virtSku,
                    'line_qty'                 => $virtQty,
                    'modifiers_json'           => null,
                    'removed_ingredients_json' => null,
                    'combo_meta_json'          => null,
                    // pozostałe pola wypełnimy z drugiego fetcha
                    'item_type'                => null,
                    'parent_item_id'           => null,
                    'variant_option_id'        => null,
                    'parent_recipe_sku'        => null,
                    'variant_multiplier'       => null,
                    '_is_combo_virtual'        => true,
                    '_combo_meal_id'           => (int)$meta['meal_id'],
                ];
                $comboExpandedSkus[$virtSku] = true;
            }
        }

        // Drugi fetch: dla wirtualnych linii combo wypełnij metadane menu (variant info etc.)
        if ($comboExpandedSkus) {
            $virtSkus = array_keys($comboExpandedSkus);
            $phV = self::placeholders($virtSkus);
            if ($hasVariantColumns) {
                $stmtVirt = $pdo->prepare(
                    "SELECT mi.ascii_key,
                            mi.type AS item_type,
                            mi.parent_item_id,
                            mi.variant_option_id,
                            parent_mi.ascii_key AS parent_recipe_sku,
                            opt.multiplier AS variant_multiplier
                       FROM sh_menu_items mi
                       LEFT JOIN sh_menu_items parent_mi
                            ON parent_mi.id = mi.parent_item_id AND parent_mi.tenant_id = mi.tenant_id
                       LEFT JOIN sh_variant_scale_options opt
                            ON opt.id = mi.variant_option_id AND opt.tenant_id = mi.tenant_id
                      WHERE mi.tenant_id = ? AND mi.ascii_key IN ({$phV})"
                );
            } else {
                $stmtVirt = $pdo->prepare(
                    "SELECT ascii_key, type AS item_type,
                            NULL AS parent_item_id, NULL AS variant_option_id,
                            NULL AS parent_recipe_sku, NULL AS variant_multiplier
                       FROM sh_menu_items
                      WHERE tenant_id = ? AND ascii_key IN ({$phV})"
                );
            }
            $stmtVirt->execute(array_merge([$tenantId], $virtSkus));
            $virtMetaByKey = [];
            foreach ($stmtVirt->fetchAll(PDO::FETCH_ASSOC) as $vm) {
                $virtMetaByKey[$vm['ascii_key']] = $vm;
            }
            foreach ($expandedLines as &$el) {
                if (!empty($el['_is_combo_virtual']) && isset($virtMetaByKey[$el['item_sku']])) {
                    $vm = $virtMetaByKey[$el['item_sku']];
                    $el['item_type']           = $vm['item_type'];
                    $el['parent_item_id']      = $vm['parent_item_id'];
                    $el['variant_option_id']   = $vm['variant_option_id'];
                    $el['parent_recipe_sku']   = $vm['parent_recipe_sku'];
                    $el['variant_multiplier']  = $vm['variant_multiplier'];
                }
            }
            unset($el);
        }

        $orderLines = $expandedLines;

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
                // F5-A (2026-05-11): back-compat reader.
                // POS przed F5 zapisywał `ascii_key` zamiast `sku` w modifiers_json
                // (online checkout / CartEngine zawsze pisał `sku`). Aby ten WzEngine
                // konsumował magazyn dla historycznych zamówień z POS-a, akceptujemy oba klucze.
                // Konstytucja v5 § Prawo II (Bliźniak Cyfrowy) — magazyn musi spadać też dla modów ADD.
                $modSku = trim((string) ($modEntry['sku'] ?? $modEntry['ascii_key'] ?? ''));
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
                // F5-A: back-compat reader (akceptuje `sku` i `ascii_key`).
                $remSku = trim((string) ($remEntry['sku'] ?? $remEntry['ascii_key'] ?? ''));
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
            // PDO MySQL nie pozwala mieszać named (:tid) i positional (?) parameters
            // w jednym query. SQLSTATE[HY093] przy execute. Używamy wyłącznie positional.
            $stmtChildren = $pdo->prepare("
                SELECT ascii_key, parent_sku
                FROM sh_menu_items
                WHERE tenant_id = ?
                  AND parent_sku IN ({$phHH})
                  AND is_deleted = 0
                ORDER BY display_order ASC
            ");
            $stmtChildren->execute(array_merge([$tenantId], array_values($halfHalfParentSkus)));
            foreach ($stmtChildren->fetchAll(PDO::FETCH_ASSOC) as $child) {
                $childSkuMap[$child['parent_sku']][] = $child['ascii_key'];
            }
        }

        // 1E — Collect all menu SKUs that need recipe rows + variant resolution
        // F-S1: jeśli linia to wariant, używamy parent_recipe_sku do pobrania receptury.
        $recipeSkuSet = [];
        foreach ($orderLines as $line) {
            $itemSku        = (string) $line['item_sku'];
            $type           = $line['item_type'] ?? null;
            $parentRecipeSku = $line['parent_recipe_sku'] ?? null;

            // Effective recipe sku: parent dla wariantów, inaczej własny.
            $recipeSku = ($parentRecipeSku !== null && $parentRecipeSku !== '')
                ? (string) $parentRecipeSku
                : $itemSku;

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
                $recipeSkuSet[$recipeSku] = true;
            }
        }

        $recipeSkus = array_keys($recipeSkuSet);
        if ($recipeSkus === []) {
            return ['success' => false, 'error' => 'Order has no line items.'];
        }

        // F-S5 (2026-05-11): wykryj kolumny subrecipe (graceful gdy migracja 053 brak).
        $hasSubrecipeCols = false;
        try { $pdo->query("SELECT is_subrecipe FROM sh_recipes LIMIT 0"); $hasSubrecipeCols = true; }
        catch (\PDOException $e) {}

        $phR = self::placeholders($recipeSkus);
        // PDO MySQL: positional parameters only when mixing with IN(...).
        if ($hasSubrecipeCols) {
            $stmtRecipes = $pdo->prepare("
                SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent,
                       is_subrecipe, subrecipe_yield
                FROM sh_recipes
                WHERE tenant_id = ?
                  AND menu_item_sku IN ({$phR})
            ");
        } else {
            $stmtRecipes = $pdo->prepare("
                SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent,
                       0 AS is_subrecipe, 1.0 AS subrecipe_yield
                FROM sh_recipes
                WHERE tenant_id = ?
                  AND menu_item_sku IN ({$phR})
            ");
        }
        $stmtRecipes->execute(array_merge([$tenantId], $recipeSkus));

        $recipesByItem = [];
        foreach ($stmtRecipes->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recipesByItem[$r['menu_item_sku']][] = [
                'warehouse_sku' => $r['warehouse_sku'],
                'quantity_base' => (float) $r['quantity_base'],
                'waste_percent' => (float) $r['waste_percent'],
                'is_subrecipe'  => (int) ($r['is_subrecipe'] ?? 0) === 1,
                'subrecipe_yield' => (float) ($r['subrecipe_yield'] ?? 1.0),
            ];
        }

        // F-S5: pre-fetch wszystkich subrecipe linii rekursywnie (max depth 3).
        // Lazy resolver: gdy aggregateRecipes natrafi na is_subrecipe=true, używa $subrecipesCache.
        if ($hasSubrecipeCols) {
            $subrecipesCache = self::resolveSubrecipesRecursive($pdo, $tenantId, $recipesByItem, 3);
        } else {
            $subrecipesCache = [];
        }

        // 1F — Aggregate deductions: warehouse_sku => total quantity to deduct
        $deductions = [];

        foreach ($orderLines as $line) {
            $lineQty           = (float) $line['line_qty'];
            $lineId            = (string) $line['line_id'];
            $removedSkus       = $removedByLine[$lineId] ?? [];
            $itemSku           = (string) $line['item_sku'];
            $type              = $line['item_type'] ?? null;
            $parentRecipeSku   = $line['parent_recipe_sku'] ?? null;
            // F-S1: variant multiplier (default 1.0 dla zwykłych itemów / brakujących wpisów).
            $variantMultiplier = ($line['variant_multiplier'] !== null && $line['variant_multiplier'] !== '')
                ? (float) $line['variant_multiplier']
                : 1.0;
            // Effective recipe sku — z parent jeśli wariant, inaczej własny.
            $recipeSku = ($parentRecipeSku !== null && $parentRecipeSku !== '')
                ? (string) $parentRecipeSku
                : $itemSku;

            if ($type === 'half_half') {
                $children = $childSkuMap[$itemSku] ?? [];
                foreach ($children as $childSku) {
                    self::aggregateRecipes(
                        $recipesByItem[$childSku] ?? [],
                        $removedSkus,
                        0.5 * $variantMultiplier,
                        $lineQty,
                        $deductions,
                        $subrecipesCache
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
                        0.5 * $variantMultiplier,
                        $lineQty,
                        $deductions,
                        $subrecipesCache
                    );
                }
            } else {
                self::aggregateRecipes(
                    $recipesByItem[$recipeSku] ?? [],
                    $removedSkus,
                    1.0 * $variantMultiplier,
                    $lineQty,
                    $deductions,
                    $subrecipesCache
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

        $skus = array_values(array_keys($deductions));
        $ph = self::placeholders($skus);
        $stmt = $pdo->prepare("
            SELECT sku, quantity
            FROM wh_stock
            WHERE tenant_id = ?
              AND warehouse_id = ?
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
        // PDO MySQL: positional parameters only when mixing with IN(...).
        $stmtRecipes = $pdo->prepare("
            SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent
            FROM sh_recipes
            WHERE tenant_id = ?
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
        array &$deductions,
        array $subrecipesCache = []
    ): void {
        foreach ($recipes as $recipe) {
            if (in_array($recipe['warehouse_sku'], $removedSkus, true)) {
                continue;
            }
            $effectiveQty = $recipe['quantity_base']
                * (1 + ($recipe['waste_percent'] / 100))
                * $multiplier
                * $lineQty;

            // F-S5 (2026-05-11): jeśli linia jest subrecipe (półprodukt), expand rekurencyjnie.
            if (!empty($recipe['is_subrecipe']) && isset($subrecipesCache[$recipe['warehouse_sku']])) {
                $subYield = max(0.0001, (float)($recipe['subrecipe_yield'] ?? 1.0));
                // 1 porcja subrecipe = (1 / yield) batchu surowców.
                // qty głównej receptury × multiplier × lineQty × (1/yield) = batches do zużycia.
                $batchesNeeded = $effectiveQty / $subYield;
                self::aggregateRecipes(
                    $subrecipesCache[$recipe['warehouse_sku']],
                    [], // removed_skus nie propagują się do podreceptury
                    1.0, // multiplier juz zaaplikowany w batchesNeeded
                    $batchesNeeded,
                    $deductions,
                    $subrecipesCache
                );
                continue;
            }

            $deductions[$recipe['warehouse_sku']]
                = ($deductions[$recipe['warehouse_sku']] ?? 0.0) + $effectiveQty;
        }
    }

    /**
     * F-S5: rekurencyjne wczytanie subrecipes dla wszystkich półproduktów referencjowanych
     * przez kolekcję `$recipesByItem`. Max depth zapobiega cycli A→B→A.
     *
     * @param array<string, list<array<string, mixed>>> $recipesByItem
     * @return array<string, list<array<string, mixed>>> mapa menu_item_sku → linie receptury
     */
    private static function resolveSubrecipesRecursive(
        PDO $pdo, int $tenantId, array $recipesByItem, int $maxDepth = 3
    ): array {
        $cache = [];
        $toFetch = [];
        foreach ($recipesByItem as $itemSku => $lines) {
            foreach ($lines as $line) {
                if (!empty($line['is_subrecipe'])) {
                    $toFetch[$line['warehouse_sku']] = true;
                }
            }
        }
        $depth = 0;
        while ($toFetch && $depth < $maxDepth) {
            $toFetchKeys = array_keys($toFetch);
            $toFetch = [];
            $ph = implode(',', array_fill(0, count($toFetchKeys), '?'));
            $stmt = $pdo->prepare(
                "SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent,
                        is_subrecipe, subrecipe_yield
                   FROM sh_recipes
                  WHERE tenant_id = ? AND menu_item_sku IN ({$ph})"
            );
            $stmt->execute(array_merge([$tenantId], $toFetchKeys));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['menu_item_sku']][] = [
                    'warehouse_sku' => $r['warehouse_sku'],
                    'quantity_base' => (float)$r['quantity_base'],
                    'waste_percent' => (float)$r['waste_percent'],
                    'is_subrecipe'  => (int)$r['is_subrecipe'] === 1,
                    'subrecipe_yield' => (float)($r['subrecipe_yield'] ?? 1.0),
                ];
                // Kolejny poziom rekursji: jeśli ta linia też jest subrecipe.
                if ((int)$r['is_subrecipe'] === 1 && !isset($cache[$r['warehouse_sku']])) {
                    $toFetch[$r['warehouse_sku']] = true;
                }
            }
            $depth++;
        }
        return $cache;
    }

    /**
     * @param list<mixed> $items
     */
    private static function placeholders(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }
}
