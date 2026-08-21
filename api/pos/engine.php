<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function posResponse(bool $ok, $data = null, ?string $msg = null): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function inputStr(array $input, string $key, string $default = ''): string {
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/OrderStateMachine.php';
    require_once __DIR__ . '/../../core/SettlementEngine.php';
    require_once __DIR__ . '/../../core/AssetResolver.php';
    require_once __DIR__ . '/../../core/StaffFleetPresence.php';
    require_once __DIR__ . '/../../core/SlaThresholds.php';
    require_once __DIR__ . '/../../core/Uuid.php';

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true) ?? [];
    $action = inputStr($input, 'action');

    // [FF-HOOK] Load tenant feature flags once per request.
    // Currently returns [] (strict mode). When the Global Settings Matrix
    // is built, this will drive all state-skipping behavior automatically.
    $tenantFlags = OrderStateMachine::loadTenantFlags($pdo, $tenant_id);

    // Schema detection (same pattern as api_menu_studio.php)
    $schemaV2 = false;
    try { $pdo->query("SELECT vat_rate_dine_in FROM sh_menu_items LIMIT 0"); $schemaV2 = true; } catch (\PDOException $e) {}
    $hasPriceTiers = false;
    try { $pdo->query("SELECT 1 FROM sh_price_tiers LIMIT 0"); $hasPriceTiers = true; } catch (\PDOException $e) {}
    $hasModifiersTable = false;
    try { $pdo->query("SELECT 1 FROM sh_modifiers LIMIT 0"); $hasModifiersTable = true; } catch (\PDOException $e) {}
    $hasSysItems = false;
    try { $pdo->query("SELECT 1 FROM sys_items LIMIT 0"); $hasSysItems = true; } catch (\PDOException $e) {}
    $catHasIsDeleted = false;
    try { $pdo->query("SELECT is_deleted FROM sh_categories LIMIT 0"); $catHasIsDeleted = true; } catch (\PDOException $e) {}

    // Auto-migration: driver_action_type on sh_order_lines
    try {
        $pdo->query("SELECT driver_action_type FROM sh_order_lines LIMIT 0");
    } catch (\Throwable $e) {
        try { $pdo->exec("ALTER TABLE sh_order_lines ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none' AFTER comment"); } catch (\Throwable $ignore) {}
    }

    // Auto-migration: ensure POS-specific columns exist (column probe, not flag file)
    $hasReceiptPrinted = false;
    try { $pdo->query("SELECT receipt_printed FROM sh_orders LIMIT 0"); $hasReceiptPrinted = true; } catch (\PDOException $e) {}
    if (!$hasReceiptPrinted) {
        $ddls = [
            "ALTER TABLE sh_orders ADD COLUMN receipt_printed TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sh_orders ADD COLUMN kitchen_ticket_printed TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sh_orders ADD COLUMN kitchen_changes TEXT NULL",
            "ALTER TABLE sh_orders ADD COLUMN cart_json JSON NULL",
            "ALTER TABLE sh_orders ADD COLUMN nip VARCHAR(32) NULL",
        ];
        foreach ($ddls as $ddl) {
            try { $pdo->exec($ddl); } catch (\Throwable) {}
        }
    }

    // Auto-migration: fiscal_receipt_number (migration 062)
    try { $pdo->query("SELECT fiscal_receipt_number FROM sh_orders LIMIT 0"); } catch (\PDOException $e) {
        try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN fiscal_receipt_number VARCHAR(20) DEFAULT NULL"); } catch (\Throwable) {}
    }

    // =========================================================================
    // GET_INIT_DATA — Categories, items+prices, ingredients, drivers, waiters
    // =========================================================================
    if ($action === 'get_init_data') {
        // -- Categories --
        $catDel = $catHasIsDeleted ? "AND is_deleted = 0" : "";
        $stmtCat = $pdo->prepare(
            "SELECT id, name FROM sh_categories
             WHERE tenant_id = ? AND is_menu = 1 $catDel
             ORDER BY display_order ASC, name ASC"
        );
        $stmtCat->execute([$tenant_id]);
        $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

        // -- Items --
        // F-S1 (2026-05-11): variant columns probe (graceful fallback gdy migracja 048 brak).
        $hasVariantCols = false;
        try { $pdo->query("SELECT variant_scale_id FROM sh_menu_items LIMIT 0"); $hasVariantCols = true; }
        catch (\PDOException $e) {}

        if ($schemaV2) {
            // F-S1 + F5-D + F-S4 — wspólne klauzule:
            //   * is_variant_parent=0 (parents niesprzedawalne, F-S1)
            //   * Temporal Tables filter (Live + valid_from/to, F5-D)
            //   * F-S4: kanoniczny słownik publication_status = 'Live' (był 'published')
            //     — fallback do 'published' dla baz bez migracji 051.
            if ($hasVariantCols) {
                $stmtItems = $pdo->prepare(
                    "SELECT mi.id, mi.category_id, mi.name, mi.ascii_key, mi.is_active, mi.image_url, mi.description,
                            mi.vat_rate_dine_in, mi.vat_rate_takeaway,
                            mi.parent_item_id, mi.variant_option_id,
                            parent_mi.ascii_key AS parent_ascii_key,
                            parent_mi.name AS parent_name,
                            opt.name AS variant_option_name,
                            opt.key_ascii AS variant_option_key,
                            opt.display_order AS variant_option_order,
                            opt.multiplier AS variant_multiplier
                       FROM sh_menu_items mi
                       LEFT JOIN sh_menu_items parent_mi
                            ON parent_mi.id = mi.parent_item_id AND parent_mi.tenant_id = mi.tenant_id
                       LEFT JOIN sh_variant_scale_options opt
                            ON opt.id = mi.variant_option_id AND opt.tenant_id = mi.tenant_id
                      WHERE mi.tenant_id = ?
                        AND mi.is_deleted = 0
                        AND mi.is_variant_parent = 0
                        AND (mi.publication_status IS NULL OR mi.publication_status IN ('Live','published'))
                        AND (mi.valid_from IS NULL OR mi.valid_from <= NOW())
                        AND (mi.valid_to   IS NULL OR mi.valid_to   >= NOW())
                      ORDER BY mi.display_order ASC, mi.name ASC"
                );
            } else {
                $stmtItems = $pdo->prepare(
                    "SELECT id, category_id, name, ascii_key, is_active, image_url, description,
                            vat_rate_dine_in, vat_rate_takeaway
                       FROM sh_menu_items
                      WHERE tenant_id = ?
                        AND is_deleted = 0
                        AND (publication_status IS NULL OR publication_status IN ('Live','published'))
                        AND (valid_from IS NULL OR valid_from <= NOW())
                        AND (valid_to   IS NULL OR valid_to   >= NOW())
                      ORDER BY display_order ASC, name ASC"
                );
            }
        } else {
            $stmtItems = $pdo->prepare(
                // F-S4: kanoniczny 'Live' (z fallback 'published' dla starych baz). DATETIME-safe via NOW().
                "SELECT id, category_id, name, ascii_key, is_active, NULL AS image_url, description,
                        vat_rate AS vat_rate_dine_in, vat_rate AS vat_rate_takeaway, price
                 FROM sh_menu_items
                 WHERE tenant_id = ?
                   AND is_deleted = 0
                   AND (publication_status IS NULL OR publication_status IN ('Live','published'))
                   AND (valid_from IS NULL OR valid_from <= NOW())
                   AND (valid_to   IS NULL OR valid_to   >= NOW())
                 ORDER BY display_order ASC, name ASC"
            );
        }
        $stmtItems->execute([$tenant_id]);
        $itemsRaw = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // -- Price tiers --
        $priceMap = [];
        if ($hasPriceTiers) {
            $stmtPrices = $pdo->prepare(
                "SELECT target_sku, channel, price FROM sh_price_tiers
                 WHERE (tenant_id = ? OR tenant_id = 0) AND target_type = 'ITEM'
                 ORDER BY target_sku, channel, tenant_id DESC"
            );
            $stmtPrices->execute([$tenant_id]);
            foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if (!isset($priceMap[$p['target_sku']][$p['channel']])) {
                    $priceMap[$p['target_sku']][$p['channel']] = $p['price'];
                }
            }
        }

        $items = [];
        foreach ($itemsRaw as $it) {
            $sku = $it['ascii_key'] ?? '';
            $tiers = [];
            if (isset($priceMap[$sku])) {
                foreach ($priceMap[$sku] as $ch => $pr) {
                    $tiers[] = ['channel' => $ch, 'price' => $pr];
                }
            } elseif (!$hasPriceTiers && isset($it['price'])) {
                $lp = (string)$it['price'];
                $tiers = [
                    ['channel' => 'POS', 'price' => $lp],
                    ['channel' => 'Takeaway', 'price' => $lp],
                    ['channel' => 'Delivery', 'price' => $lp],
                ];
            }
            $itemRow = [
                'id'          => (int)$it['id'],
                'categoryId'  => (int)$it['category_id'],
                'name'        => $it['name'],
                'asciiKey'    => $sku,
                'imageUrl'    => $it['image_url'] ?? '',
                'description' => $it['description'] ?? '',
                'vatDineIn'   => (float)($it['vat_rate_dine_in'] ?? 8),
                'vatTakeaway' => (float)($it['vat_rate_takeaway'] ?? 5),
                'priceTiers'  => $tiers,
            ];
            // F-S1 — variant meta (NULL dla zwyklych itemow).
            if (!empty($it['parent_item_id'])) {
                $itemRow['parentItemId']      = (int)$it['parent_item_id'];
                $itemRow['parentAsciiKey']    = $it['parent_ascii_key'] ?? null;
                $itemRow['parentName']        = $it['parent_name'] ?? null;
                $itemRow['variantOptionId']   = (int)($it['variant_option_id'] ?? 0);
                $itemRow['variantOptionName'] = $it['variant_option_name'] ?? null;
                $itemRow['variantOptionKey']  = $it['variant_option_key'] ?? null;
                $itemRow['variantMultiplier'] = (float)($it['variant_multiplier'] ?? 1.0);
            }
            $items[] = $itemRow;
        }

        // F-S1 — variant groups: zgrupuj children po parentAsciiKey dla UI POS.
        // Frontend moze pokazac jeden kafelek „Pizza Margherita" → submenu wyboru rozmiaru.
        $variantGroups = [];
        foreach ($items as $row) {
            if (!empty($row['parentAsciiKey'])) {
                $pKey = $row['parentAsciiKey'];
                if (!isset($variantGroups[$pKey])) {
                    $variantGroups[$pKey] = [
                        'parentAsciiKey' => $pKey,
                        'parentName'     => $row['parentName'] ?? $pKey,
                        'categoryId'     => $row['categoryId'],
                        'variants'       => [],
                    ];
                }
                $variantGroups[$pKey]['variants'][] = [
                    'asciiKey'      => $row['asciiKey'],
                    'optionName'    => $row['variantOptionName'] ?? '',
                    'optionKey'     => $row['variantOptionKey'] ?? '',
                    'multiplier'    => $row['variantMultiplier'] ?? 1.0,
                    'priceTiers'    => $row['priceTiers'],
                    'imageUrl'      => $row['imageUrl'],
                ];
            }
        }
        // Sortuj variants per group po display_order opcji
        foreach ($variantGroups as &$vg) {
            usort($vg['variants'], static function($a, $b) {
                return strnatcmp((string)$a['optionKey'], (string)$b['optionKey']);
            });
        }
        unset($vg);
        $variantGroups = array_values($variantGroups);

        // m021 Asset Studio override — hero z sh_asset_links ma priorytet
        AssetResolver::injectHeros($pdo, (int)$tenant_id, $items, 'asciiKey', 'imageUrl');

        // -- Ingredients (sys_items) --
        $ingredients = [];
        if ($hasSysItems) {
            $stmtIngredients = $pdo->prepare(
                "SELECT sku, name, base_unit AS unit FROM sys_items WHERE tenant_id = ?"
            );
            $stmtIngredients->execute([$tenant_id]);
            $ingredients = $stmtIngredients->fetchAll(PDO::FETCH_ASSOC);
        }

        // -- Drivers: tylko zalogowani w aplikacji (last_seen) lub w trasie (busy) —
        $drivers = [];
        try {
            $ttl = slicehubFleetPresenceTtlSeconds();
            $stmtDrivers = $pdo->prepare(
                "SELECT u.id, d.status,
                        COALESCE(NULLIF(TRIM(u.name), ''), COALESCE(NULLIF(TRIM(u.first_name),''), u.username)) AS display_name
                 FROM sh_drivers d
                 JOIN sh_users u ON d.user_id = u.id
                 WHERE u.tenant_id = ?
                   AND u.is_deleted = 0
                   AND (
                     d.status = 'busy'
                     OR (u.last_seen IS NOT NULL AND u.last_seen >= DATE_SUB(NOW(), INTERVAL " . (int)$ttl . " SECOND))
                   )"
            );
            $stmtDrivers->execute([$tenant_id]);
            $drivers = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}

        // -- Waiters: tylko zalogowani w aplikacji kelnera (ostatni poll / login) --
        $waiters = [];
        try {
            $ttlW = slicehubFleetPresenceTtlSeconds();
            $stmtWaiters = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(name),''), COALESCE(NULLIF(TRIM(first_name),''), username)) AS display_name
                 FROM sh_users
                 WHERE tenant_id = ?
                   AND role = 'waiter'
                   AND is_active = 1
                   AND is_deleted = 0
                   AND last_seen IS NOT NULL
                   AND last_seen >= DATE_SUB(NOW(), INTERVAL " . (int)$ttlW . " SECOND)"
            );
            $stmtWaiters->execute([$tenant_id]);
            $waiters = $stmtWaiters->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}

        // -- Modifier groups --
        $modifierGroups = [];
        try {
            $modDelWhere = "AND is_deleted = 0";
            $stmtModGroups = $pdo->prepare(
                "SELECT id, name, ascii_key, min_selection, max_selection
                 FROM sh_modifier_groups
                 WHERE tenant_id = ? $modDelWhere ORDER BY name ASC"
            );
            $stmtModGroups->execute([$tenant_id]);
            $modGroupsRaw = $stmtModGroups->fetchAll(PDO::FETCH_ASSOC);

            $modsRaw = [];
            if ($hasModifiersTable) {
                $stmtMods = $pdo->prepare(
                    "SELECT id, group_id, name, ascii_key, action_type
                     FROM sh_modifiers WHERE group_id IN (
                        SELECT id FROM sh_modifier_groups WHERE tenant_id = ? $modDelWhere
                     ) AND is_deleted = 0 ORDER BY name ASC"
                );
                $stmtMods->execute([$tenant_id]);
                $modsRaw = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
            }

            $modPriceMap = [];
            if ($hasPriceTiers) {
                $stmtModPrices = $pdo->prepare(
                    "SELECT target_sku, channel, price FROM sh_price_tiers
                     WHERE (tenant_id = ? OR tenant_id = 0) AND target_type = 'MODIFIER'
                     ORDER BY target_sku, channel, tenant_id DESC"
                );
                $stmtModPrices->execute([$tenant_id]);
                foreach ($stmtModPrices->fetchAll(PDO::FETCH_ASSOC) as $mp) {
                    if (!isset($modPriceMap[$mp['target_sku']][$mp['channel']])) {
                        $modPriceMap[$mp['target_sku']][$mp['channel']] = $mp['price'];
                    }
                }
            }

            $itemIdsByGroup = [];
            try {
                $stmtItemMods = $pdo->prepare(
                    "SELECT im.item_id, im.group_id
                     FROM sh_item_modifiers im
                     JOIN sh_menu_items mi ON mi.id = im.item_id
                     WHERE mi.tenant_id = ?"
                );
                $stmtItemMods->execute([$tenant_id]);
                foreach ($stmtItemMods->fetchAll(PDO::FETCH_ASSOC) as $lnk) {
                    $itemIdsByGroup[(int)$lnk['group_id']][] = (int)$lnk['item_id'];
                }
            } catch (\PDOException $e) {}

            $modsByGroup = [];
            foreach ($modsRaw as $m) {
                $modsByGroup[(int)$m['group_id']][] = $m;
            }

            foreach ($modGroupsRaw as $mg) {
                $gid = (int)$mg['id'];
                $mods = [];
                foreach (($modsByGroup[$gid] ?? []) as $m) {
                    $msk = $m['ascii_key'] ?? '';
                    $pr = $modPriceMap[$msk] ?? [];
                    $mods[] = [
                        'id'       => (int)$m['id'],
                        'name'     => $m['name'],
                        'asciiKey' => $msk,
                        'prices'   => $pr,
                    ];
                }
                $modifierGroups[] = [
                    'id'           => $gid,
                    'name'         => $mg['name'],
                    'asciiKey'     => $mg['ascii_key'] ?? '',
                    'minSelection' => (int)($mg['min_selection'] ?? 0),
                    'maxSelection' => (int)($mg['max_selection'] ?? 10),
                    'itemIds'      => $itemIdsByGroup[$gid] ?? [],
                    'modifiers'    => $mods,
                ];
            }
        } catch (\PDOException $e) {
            // Legacy: sh_modifier_groups without is_deleted/ascii_key
            try {
                $stmtModGroups = $pdo->prepare(
                    "SELECT id, name, min_selection, max_selection
                     FROM sh_modifier_groups WHERE tenant_id = ? ORDER BY name ASC"
                );
                $stmtModGroups->execute([$tenant_id]);
                foreach ($stmtModGroups->fetchAll(PDO::FETCH_ASSOC) as $mg) {
                    $modifierGroups[] = [
                        'id' => (int)$mg['id'], 'name' => $mg['name'], 'asciiKey' => '',
                        'minSelection' => (int)$mg['min_selection'], 'maxSelection' => (int)$mg['max_selection'],
                        'itemIds' => [], 'modifiers' => [],
                    ];
                }
            } catch (\PDOException $e2) {}
        }

        // F-S3 (2026-05-11): meal_packages — combo/bundle/meal.
        $mealPackages = [];
        try {
            $stmtMeals = $pdo->prepare(
                "SELECT id, ascii_key, name, description, category_id, type,
                        final_price_grosze, discount_percent, image_url
                   FROM sh_meal_packages
                  WHERE tenant_id = :tid
                    AND is_deleted = 0
                    AND is_active = 1
                    AND (publication_status IS NULL OR publication_status IN ('Live','published'))
                    AND (valid_from IS NULL OR valid_from <= NOW())
                    AND (valid_to   IS NULL OR valid_to   >= NOW())
                  ORDER BY display_order ASC, name ASC"
            );
            $stmtMeals->execute([':tid' => $tenant_id]);
            $mealPackages = $stmtMeals->fetchAll(PDO::FETCH_ASSOC);
            if ($mealPackages) {
                $mealIds = array_column($mealPackages, 'id');
                $phM = implode(',', array_fill(0, count($mealIds), '?'));
                $stmtComps = $pdo->prepare(
                    "SELECT id, meal_id, component_type, item_sku, category_id, qty,
                            allow_upgrade, surcharge_grosze, display_order
                       FROM sh_meal_components
                      WHERE meal_id IN ({$phM}) AND tenant_id = ?
                      ORDER BY meal_id, display_order"
                );
                $stmtComps->execute(array_merge($mealIds, [$tenant_id]));
                $byMeal = [];
                foreach ($stmtComps->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $byMeal[$c['meal_id']][] = $c;
                }
                foreach ($mealPackages as &$m) {
                    $m['components'] = $byMeal[$m['id']] ?? [];
                }
                unset($m);
            }
        } catch (\Throwable $mealErr) {
            error_log('[POS get_init_data] meal_packages query failed: ' . $mealErr->getMessage());
            $mealPackages = [];
        }

        posResponse(true, [
            'categories'     => $categories,
            'items'          => $items,
            'ingredients'    => $ingredients,
            'drivers'        => $drivers,
            'waiters'        => $waiters,
            'modifierGroups' => $modifierGroups,
            // F-S1: variantGroups dla UI POS — kafelek parenta z submenu rozmiarów.
            'variantGroups'  => $variantGroups ?? [],
            // F-S3: combo/bundle/meal packages.
            'mealPackages'   => $mealPackages,
        ]);
    }

    // =========================================================================
    // GET_ITEM_DETAILS — Recipe ingredients for a dish card
    // =========================================================================
    if ($action === 'get_item_details') {
        $itemId  = (int)($input['item_id'] ?? 0);
        $halfBId = (int)($input['half_b_id'] ?? 0);

        // 048: JEDNA receptura na parent — dzieci wariantów dziedziczą recipe.
        $stmtSku = $pdo->prepare(
            "SELECT ascii_key, parent_item_id FROM sh_menu_items WHERE id = ? AND tenant_id = ?"
        );
        $stmtParentSku = $pdo->prepare(
            "SELECT ascii_key FROM sh_menu_items WHERE id = ? AND tenant_id = ?"
        );

        $stmtRecipe = $pdo->prepare(
            "SELECT r.warehouse_sku AS sku, si.name, si.base_unit AS unit
             FROM sh_recipes r
             LEFT JOIN sys_items si ON si.sku = r.warehouse_sku AND si.tenant_id = r.tenant_id
             WHERE r.menu_item_sku = ? AND r.tenant_id = ?"
        );

        // Zwraca recipe składniki dla item_id — jeśli dziecko wariantu, szuka po parent ascii_key.
        function _resolveRecipe($pdoItemId, $tenant_id, $stmtSku, $stmtParentSku, $stmtRecipe) {
            $stmtSku->execute([$pdoItemId, $tenant_id]);
            $row = $stmtSku->fetch(PDO::FETCH_ASSOC);
            if (!$row) return [];
            $sku = $row['ascii_key'];
            // Jeśli dziecko wariantu — szukaj recipe po parent ascii_key.
            if (!empty($row['parent_item_id'])) {
                $stmtParentSku->execute([$row['parent_item_id'], $tenant_id]);
                $parentSku = $stmtParentSku->fetchColumn();
                if ($parentSku) $sku = $parentSku;
            }
            $stmtRecipe->execute([$sku, $tenant_id]);
            return $stmtRecipe->fetchAll(PDO::FETCH_ASSOC);
        }

        $ingredients = [];

        $ingsA = _resolveRecipe($itemId, $tenant_id, $stmtSku, $stmtParentSku, $stmtRecipe);
        foreach ($ingsA as $ing) {
            $ing['half'] = 'A';
            $ingredients[] = $ing;
        }

        if ($halfBId > 0) {
            $ingsB = _resolveRecipe($halfBId, $tenant_id, $stmtSku, $stmtParentSku, $stmtRecipe);
            foreach ($ingsB as $ing) {
                $ing['half'] = 'B';
                $ingredients[] = $ing;
            }
        }

        posResponse(true, ['ingredients' => $ingredients]);
    }

    // =========================================================================
    // GET_ORDERS — Active orders for the Battlefield view
    // =========================================================================
    if ($action === 'get_orders') {
        $stmt = $pdo->prepare("
            SELECT o.*,
                   COALESCE(NULLIF(TRIM(u.name),''), COALESCE(NULLIF(TRIM(u.first_name),''), u.username)) AS creator_name
            FROM sh_orders o
            LEFT JOIN sh_users u ON o.user_id = u.id
            WHERE o.tenant_id = ? AND o.status NOT IN ('completed','cancelled')
            ORDER BY COALESCE(o.promised_time, o.created_at) ASC
        ");
        $stmt->execute([$tenant_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$o) {
            $stmtLines = $pdo->prepare(
                "SELECT item_sku, snapshot_name, quantity, unit_price, line_total,
                        modifiers_json, removed_ingredients_json, comment
                 FROM sh_order_lines
                 WHERE order_id = ? AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
            );
            $stmtLines->execute([$o['id'], $tenant_id]);
            $o['lines'] = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            $o['grand_total_formatted'] = number_format(((int)$o['grand_total']) / 100, 2, '.', '');
        }
        unset($o);

        // Fresh driver statuses (so fleet panel updates on every poll)
        $drivers = [];
        try {
            $ttlPoll = slicehubFleetPresenceTtlSeconds();
            $stmtDrv = $pdo->prepare(
                "SELECT u.id, d.status,
                        COALESCE(NULLIF(TRIM(u.name), ''), COALESCE(NULLIF(TRIM(u.first_name),''), u.username)) AS display_name
                 FROM sh_drivers d
                 JOIN sh_users u ON d.user_id = u.id
                 WHERE u.tenant_id = ?
                   AND u.is_deleted = 0
                   AND (
                     d.status = 'busy'
                     OR (u.last_seen IS NOT NULL AND u.last_seen >= DATE_SUB(NOW(), INTERVAL " . (int)$ttlPoll . " SECOND))
                   )"
            );
            $stmtDrv->execute([$tenant_id]);
            $drivers = $stmtDrv->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}

        // SLA thresholds (Phase A) — pos_ui fmtTime czyta yellow_min (single-boundary)
        // zamiast hardcoded per-type 15/59 i 6/14. SSOT: core/SlaThresholds.php.
        $slaThresholds = slicehubSlaThresholds($pdo, (int)$tenant_id);

        posResponse(true, ['orders' => $orders, 'drivers' => $drivers, 'sla_thresholds' => $slaThresholds]);
    }

    // =========================================================================
    // PROCESS_ORDER — Create or edit an order (full legacy logic)
    // =========================================================================
    if ($action === 'process_order') {
        $pdo->beginTransaction();
        try {
            $editId  = inputStr($input, 'edit_order_id');
            $cart    = $input['cart'] ?? [];
            $source  = (string)($input['source'] ?? 'POS');
            $orderType    = (string)($input['order_type'] ?? 'dine_in');
            $payMethodRaw = (string)($input['payment_method'] ?? 'unpaid');
            $payStatusRaw = (string)($input['payment_status'] ?? 'unpaid');

            // Guard: pusty koszyk = nie twórz zamówienia (0 lines → KdsAcceptRouting
            // rzuca "Order has no lines to route" przy akceptacji).
            if (!is_array($cart) || count($cart) === 0) {
                $pdo->rollBack();
                posResponse(false, null, 'Koszyk jest pusty — dodaj pozycje przed złożeniem zamówienia.');
            }

            // Map legacy values to 3-pillar model
            $payMethodMap = ['unpaid' => null, 'cash' => 'cash', 'card' => 'card', 'online' => 'online'];
            $payMethod = $payMethodMap[$payMethodRaw] ?? $payMethodRaw;

            if ($payStatusRaw === 'unpaid' || $payStatusRaw === 'to_pay') {
                $payStatus = ($payMethodRaw === 'online') ? 'online_unpaid' : 'to_pay';
            } elseif ($payStatusRaw === 'paid' || in_array($payStatusRaw, ['cash','card','online_paid'], true)) {
                if (in_array($payStatusRaw, ['cash','card','online_paid'], true)) {
                    $payStatus = $payStatusRaw;
                } else {
                    $payStatus = match($payMethodRaw) { 'cash' => 'cash', 'card' => 'card', 'online' => 'online_paid', default => 'to_pay' };
                }
            } else {
                $payStatus = $payStatusRaw;
            }
            $totalGrosze  = (int)round((float)($input['total_price'] ?? 0) * 100);
            $address      = isset($input['address']) ? (string)$input['address'] : null;
            $phone        = isset($input['customer_phone']) ? (string)$input['customer_phone'] : null;
            $custName     = isset($input['customer_name']) ? (string)$input['customer_name'] : null;
            $nip          = isset($input['nip']) ? (string)$input['nip'] : null;

            // F5-B / F-S7 (2026-05-11): SERVER-AUTHORITATIVE CART REVALIDATION.
            // Konstytucja v5 § Prawo IV (Zero Zaufania) — frontend NIGDY nie wysyła cen
            // jako autorytatywne. Przeliczamy przez CartEngine::calculate i nadpisujemy
            // ceny + total. Soft override: jeśli różnica > 1 grosz, logujemy warning ale
            // używamy serwerowych wartości. Hard fail (PRICE_MISMATCH) zostawiamy na
            // później po sandbox testach (planowane F5.5).
            $serverPrices = null; // map: cart_index → unit_price_grosze (serwerowe)
            $serverTotal = null;
            try {
                require_once __DIR__ . '/../cart/CartEngine.php';
                $channelMap = ['dine_in' => 'POS', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'];
                $cartLinesForEngine = [];
                foreach ($cart as $idx => $item) {
                    $isk = (string) ($item['ascii_key'] ?? $item['id'] ?? '');
                    if ($isk === '') continue;
                    $addedSkus = [];
                    if (!empty($item['added']) && is_array($item['added'])) {
                        foreach ($item['added'] as $mod) {
                            $modSku = is_array($mod) ? (string)($mod['sku'] ?? $mod['ascii_key'] ?? '') : (string)$mod;
                            if ($modSku !== '') $addedSkus[] = $modSku;
                        }
                    }
                    $cartLinesForEngine[] = [
                        'line_id'             => $item['line_id'] ?? $item['cart_id'] ?? null,
                        'item_sku'            => $isk,
                        'quantity'            => (int)($item['qty'] ?? $item['quantity'] ?? 1),
                        'added_modifier_skus' => $addedSkus,
                        'is_half'             => !empty($item['is_half']),
                        'half_a'              => $item['half_a'] ?? null,
                        'half_b'              => $item['half_b'] ?? null,
                    ];
                }
                $ceResult = CartEngine::calculate($pdo, $tenant_id, [
                    'channel'    => $channelMap[$orderType] ?? 'POS',
                    'order_type' => $orderType,
                    'lines'      => $cartLinesForEngine,
                ]);
                // Wynik CartEngine: response.total_grosze + lines z item_sku + line_total_grosze
                $authoritativeTotal = (int)($ceResult['response']['totals']['total_grosze']
                    ?? $ceResult['totals']['total_grosze']
                    ?? $ceResult['total_grosze']
                    ?? 0);
                if ($authoritativeTotal > 0) {
                    $diff = abs($authoritativeTotal - $totalGrosze);
                    if ($diff > 1) {
                        // F-S7 (2026-05-11): tenant flag `price_mismatch_mode` ∈ {soft|hard}.
                        // soft (default): log + override + kontynuuj
                        // hard: 409 Conflict — POS musi przeładować menu i ponowić.
                        $stmtMM = $pdo->prepare(
                            "SELECT setting_value FROM sh_tenant_settings
                              WHERE tenant_id = ? AND setting_key = 'price_mismatch_mode' LIMIT 1"
                        );
                        try {
                            $stmtMM->execute([$tenant_id]);
                            $mismatchMode = (string)($stmtMM->fetchColumn() ?: 'soft');
                        } catch (\Throwable $e) {
                            $mismatchMode = 'soft';
                        }

                        error_log(sprintf(
                            '[POS process_order] PRICE_MISMATCH tenant=%d total_grosze frontend=%d server=%d diff=%d mode=%s',
                            $tenant_id, $totalGrosze, $authoritativeTotal, $diff, $mismatchMode
                        ));

                        if ($mismatchMode === 'hard') {
                            http_response_code(409);
                            posResponse(false, [
                                'error_code'         => 'PRICE_MISMATCH',
                                'client_total_grosze' => $totalGrosze,
                                'server_total_grosze' => $authoritativeTotal,
                                'diff_grosze'         => $diff,
                                'hint'                => 'Odśwież menu w POS — ceny się zmieniły.',
                            ], 'Cena po stronie klienta różni się od serwerowej. Odśwież menu.');
                        }
                    }
                    $totalGrosze = $authoritativeTotal;
                    $serverTotal = $authoritativeTotal;
                }
                // Per-line override
                $ceLines = $ceResult['response']['lines'] ?? $ceResult['lines'] ?? [];
                if (is_array($ceLines) && $ceLines !== []) {
                    $serverPrices = [];
                    foreach ($ceLines as $i => $ceL) {
                        $serverPrices[$i] = (int) (
                            $ceL['unit_price_grosze']
                            ?? $ceL['unit_total_grosze']
                            ?? 0
                        );
                    }
                }
            } catch (\Throwable $ceErr) {
                // CartEngine failure NIE blokuje zamówienia — używamy cen z payloadu (legacy fallback).
                // Loggujemy: na produkcji warning pojawi się w error_log uti.pl.
                error_log('[POS process_order] CartEngine revalidation failed (using client prices): ' . $ceErr->getMessage());
            }

            // Warehouse preflight — block order creation if stock is insufficient.
            // Absorbed from api/orders/checkout.php (Konstytucja Prawo II — Bliźniak Cyfrowy).
            $wzPath = __DIR__ . '/../../core/WzEngine.php';
            if (file_exists($wzPath)) {
                require_once $wzPath;
                if (class_exists('WzEngine') && isset($serverPrices) && $serverTotal !== null) {
                    $posWarehouseId = trim((string)($input['warehouse_id'] ?? 'MAIN')) ?: 'MAIN';
                    try {
                        $wzLines = [];
                        foreach ($cart as $item) {
                            $isk = (string)($item['ascii_key'] ?? $item['id'] ?? '');
                            if ($isk === '') continue;
                            $wzLines[] = ['item_sku' => $isk, 'quantity' => (float)($item['qty'] ?? $item['quantity'] ?? 1)];
                        }
                        if (!empty($wzLines)) {
                            $availability = WzEngine::checkAvailability($pdo, $tenant_id, $posWarehouseId, $wzLines);
                            if (($availability['success'] ?? false) && ($availability['available'] ?? true) === false) {
                                $pdo->rollBack();
                                posResponse(false, [
                                    'warehouse_id' => $availability['warehouse_id'] ?? $posWarehouseId,
                                    'shortages'    => $availability['shortages'] ?? [],
                                ], 'Insufficient warehouse stock for this order.');
                            }
                        }
                    } catch (\Throwable $wzErr) {
                        error_log('[POS process_order] WzEngine::checkAvailability failed: ' . $wzErr->getMessage());
                    }
                }
            }

            $promisedRaw  = isset($input['custom_datetime']) ? (string)$input['custom_datetime'] : null;
            $printKitchen = (int)($input['print_kitchen'] ?? 0);
            $printReceipt = (int)($input['print_receipt'] ?? 0);
            $status = (string)($input['status'] ?? 'new');
            if ($status === 'pending') {
                $status = 'new';
            }

            $cartJson  = json_encode($cart, JSON_UNESCAPED_UNICODE) ?: '[]';
            $promisedTs = ($promisedRaw !== null && $promisedRaw !== '') ? strtotime($promisedRaw) : false;
            $promised  = ($promisedTs !== false) ? date('Y-m-d H:i:s', $promisedTs) : date('Y-m-d H:i:s');

            $channelMap = ['dine_in' => 'POS', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'];
            $channel = $channelMap[$orderType] ?? 'POS';

            $tableIdParam  = isset($input['table_id']) ? (int)$input['table_id'] : null;
            $waiterIdParam = isset($input['waiter_id']) ? (int)$input['waiter_id'] : null;
            $guestCount    = isset($input['guest_count']) ? (int)$input['guest_count'] : null;
            if ($tableIdParam === 0) $tableIdParam = null;
            if ($waiterIdParam === 0) $waiterIdParam = null;
            if ($guestCount === 0) $guestCount = null;

            if ($orderType === 'dine_in' && $waiterIdParam === null) {
                $waiterIdParam = $user_id;
            }

            if ($source === 'waiter' && $status === 'new') {
                $printKitchen = 0;
            }
            $orderId = '';

            if ($editId !== '' && $editId !== '0') {
                // ---- EDIT existing order ----
                $stmtOld = $pdo->prepare(
                    "SELECT cart_json, edited_since_print, kitchen_changes FROM sh_orders WHERE id = ? AND tenant_id = ?"
                );
                $stmtOld->execute([$editId, $tenant_id]);
                $oldOrder = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $editedFlag     = (int)($oldOrder['edited_since_print'] ?? 0);
                $kitchenChanges = $oldOrder['kitchen_changes'] ?? '';

                if ($oldOrder && !empty($oldOrder['cart_json'])) {
                    $oldCart = json_decode($oldOrder['cart_json'], true) ?: [];
                    if ($oldOrder['cart_json'] !== $cartJson && !empty($oldCart)) {
                        $editedFlag = 1;
                        $diffArr = [];
                        $oldMap = []; foreach ($oldCart as $c) { $key = $c['cart_id'] ?? $c['line_id'] ?? ''; $oldMap[$key] = $c; }
                        $newMap = []; foreach ($cart  as $c) { $key = $c['cart_id'] ?? $c['line_id'] ?? ''; $newMap[$key] = $c; }

                        foreach ($newMap as $cid => $c) {
                            if (!isset($oldMap[$cid])) {
                                $diffArr[] = "DODANO: " . ($c['qty'] ?? $c['quantity'] ?? 1) . "x " . $c['name'];
                            } else {
                                $oq = $oldMap[$cid]['qty'] ?? $oldMap[$cid]['quantity'] ?? 1;
                                $nq = $c['qty'] ?? $c['quantity'] ?? 1;
                                if ($oq != $nq) $diffArr[] = "ZMIENIONO ILOŚĆ: " . $c['name'] . " ($oq -> $nq)";
                                if (($oldMap[$cid]['comment'] ?? '') !== ($c['comment'] ?? ''))
                                    $diffArr[] = "ZMIENIONO UWAGI: " . $c['name'];
                            }
                        }
                        foreach ($oldMap as $cid => $oc) {
                            if (!isset($newMap[$cid]))
                                $diffArr[] = "USUNIĘTO: " . ($oc['qty'] ?? $oc['quantity'] ?? 1) . "x " . $oc['name'];
                        }
                        $kitchenChanges = implode(' | ', $diffArr);
                    }
                }

                if ($printKitchen === 1 && $source === 'local') {
                    $editedFlag = 0;
                    $kitchenChanges = '';
                }

                // Soft-delete fired items: items already sent to KDS (fired_at IS NOT NULL)
                // get marked as cancelled instead of being hard-deleted, so the KDS
                // can display the cancellation strike-through.
                $hasFiredAt = false;
                try { $pdo->query("SELECT fired_at FROM sh_order_lines LIMIT 0"); $hasFiredAt = true; } catch (\Throwable $ignore) {}

                if ($hasFiredAt) {
                    try { $pdo->query("SELECT line_status FROM sh_order_lines LIMIT 0"); } catch (\Throwable $ignore) {
                        try { $pdo->exec("ALTER TABLE sh_order_lines ADD COLUMN line_status VARCHAR(16) NOT NULL DEFAULT 'active'"); } catch (\Throwable $ignore2) {}
                    }

                    $pdo->prepare(
                        "UPDATE sh_order_lines SET line_status = 'cancelled', quantity = 0
                         WHERE order_id = ? AND fired_at IS NOT NULL AND line_status != 'cancelled'
                         AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
                    )->execute([$editId, $tenant_id]);

                    try {
                        $pdo->prepare(
                            "DELETE oim FROM sh_order_item_modifiers oim
                             JOIN sh_order_lines ol ON oim.order_item_id = ol.id
                             WHERE ol.order_id = ? AND ol.fired_at IS NULL
                             AND ol.order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
                        )->execute([$editId, $tenant_id]);
                    } catch (\PDOException $e) {}
                    $pdo->prepare(
                        "DELETE FROM sh_order_lines WHERE order_id = ? AND fired_at IS NULL
                         AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
                    )->execute([$editId, $tenant_id]);
                } else {
                    try {
                        $pdo->prepare(
                            "DELETE oim FROM sh_order_item_modifiers oim
                             JOIN sh_order_lines ol ON oim.order_item_id = ol.id
                             WHERE ol.order_id = ?
                             AND ol.order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
                        )->execute([$editId, $tenant_id]);
                    } catch (\PDOException $e) {}
                    $pdo->prepare(
                        "DELETE FROM sh_order_lines WHERE order_id = ?
                         AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)"
                    )->execute([$editId, $tenant_id]);
                }

                $pdo->prepare(
                    "UPDATE sh_orders SET
                        order_type=?, channel=?, payment_method=?, payment_status=?,
                        grand_total=?, subtotal=?, delivery_address=?, customer_phone=?,
                        customer_name=?, nip=?, cart_json=?, promised_time=?,
                        edited_since_print=?, kitchen_changes=?, kitchen_delta=NULL,
                        kitchen_ticket_printed = IF(? = 1, 1, kitchen_ticket_printed),
                        receipt_printed = IF(? = 1, 1, receipt_printed),
                        table_id = COALESCE(?, table_id),
                        waiter_id = COALESCE(?, waiter_id),
                        guest_count = COALESCE(?, guest_count),
                        updated_at = NOW()
                     WHERE id = ? AND tenant_id = ?"
                )->execute([
                    $orderType, $channel, $payMethod, $payStatus,
                    $totalGrosze, $totalGrosze, $address, $phone,
                    $custName, $nip, $cartJson, $promised,
                    $editedFlag, $kitchenChanges,
                    $printKitchen, $printReceipt,
                    $tableIdParam, $waiterIdParam, $guestCount,
                    $editId, $tenant_id
                ]);
                $orderId = $editId;

            } else {
                // ---- NEW order ----
                $stmtSeq = $pdo->prepare(
                    "INSERT INTO sh_order_sequences (tenant_id, `date`, seq)
                     VALUES (?, CURDATE(), LAST_INSERT_ID(1))
                     ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
                );
                $stmtSeq->execute([$tenant_id]);
                $seq = (int)$pdo->lastInsertId();
                $orderNumber = sprintf('ORD/%s/%04d', date('Ymd'), $seq);

                $orderId = Uuid::v4();

                $deliveryStatus = ($orderType === 'delivery') ? 'unassigned' : null;

                // F6 (2026-05-11): Geokodowanie adresu dla zamówień DELIVERY.
                // Dispatcher dostaje real lat/lng zamiast losowego pinu na mapie.
                // Konstytucja v5 § Prawo II — fizyczny bliźniak adresu.
                $deliveryLat = null;
                $deliveryLng = null;
                $geocodeProvider = null;
                $geocodeQuality  = null;
                $geocodedAt      = null;
                if ($orderType === 'delivery' && $address !== null && trim($address) !== '') {
                    try {
                        require_once __DIR__ . '/../../core/Geocoder.php';
                        $geo = Geocoder::geocodeOrCache($pdo, $tenant_id, $address);
                        $deliveryLat     = $geo['lat'];
                        $deliveryLng     = $geo['lng'];
                        $geocodeProvider = $geo['provider'];
                        $geocodeQuality  = $geo['quality'];
                        $geocodedAt      = ($geo['lat'] !== null) ? date('Y-m-d H:i:s') : null;
                    } catch (\Throwable $geoErr) {
                        error_log('[POS process_order] Geocoder failed: ' . $geoErr->getMessage());
                    }
                }

                // F6: detekcja schema (kolumny dodaje migracja 047). Graceful degrade gdy
                // serwer wisi na starszej wersji bazy.
                static $hasGeocodeColumns = null;
                if ($hasGeocodeColumns === null) {
                    try {
                        $pdo->query("SELECT delivery_lat FROM sh_orders LIMIT 0");
                        $hasGeocodeColumns = true;
                    } catch (\PDOException $e) {
                        $hasGeocodeColumns = false;
                    }
                }

                if ($hasGeocodeColumns) {
                    $pdo->prepare(
                        "INSERT INTO sh_orders
                            (id, tenant_id, order_number, channel, order_type, source, status,
                             payment_method, payment_status, subtotal, grand_total,
                             delivery_address, delivery_lat, delivery_lng,
                             geocode_provider, geocode_quality, geocoded_at,
                             customer_phone, customer_name, nip,
                             cart_json, promised_time, kitchen_ticket_printed, receipt_printed,
                             delivery_status, user_id, table_id, waiter_id, guest_count,
                             created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
                    )->execute([
                        $orderId, $tenant_id, $orderNumber, $channel, $orderType, $source, $status,
                        $payMethod, $payStatus, $totalGrosze, $totalGrosze,
                        $address, $deliveryLat, $deliveryLng,
                        $geocodeProvider, $geocodeQuality, $geocodedAt,
                        $phone, $custName, $nip,
                        $cartJson, $promised, $printKitchen, $printReceipt,
                        $deliveryStatus, $user_id, $tableIdParam, $waiterIdParam, $guestCount
                    ]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO sh_orders
                            (id, tenant_id, order_number, channel, order_type, source, status,
                             payment_method, payment_status, subtotal, grand_total,
                             delivery_address, customer_phone, customer_name, nip,
                             cart_json, promised_time, kitchen_ticket_printed, receipt_printed,
                             delivery_status, user_id, table_id, waiter_id, guest_count,
                             created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
                    )->execute([
                        $orderId, $tenant_id, $orderNumber, $channel, $orderType, $source, $status,
                        $payMethod, $payStatus, $totalGrosze, $totalGrosze,
                        $address, $phone, $custName, $nip,
                        $cartJson, $promised, $printKitchen, $printReceipt,
                        $deliveryStatus, $user_id, $tableIdParam, $waiterIdParam, $guestCount
                    ]);
                }

                // Mark table as occupied when a dine-in order is created
                if ($orderType === 'dine_in' && $tableIdParam !== null) {
                    try {
                        $pdo->prepare(
                            "UPDATE sh_tables SET physical_status = 'occupied', updated_at = NOW()
                             WHERE id = ? AND tenant_id = ? AND physical_status IN ('free', 'reserved', 'dirty')"
                        )->execute([$tableIdParam, $tenant_id]);
                    } catch (\Throwable $ignore) {}
                }
            }

            // Insert order lines from cart
            // F-S3.2 (2026-05-11): schema-aware INSERT — z combo_meta_json gdy migracja 054 zaaplikowana.
            $hasComboMetaInsert = false;
            try { $pdo->query("SELECT combo_meta_json FROM sh_order_lines LIMIT 0"); $hasComboMetaInsert = true; }
            catch (\PDOException $e) {}

            if ($hasComboMetaInsert) {
                $stmtLine = $pdo->prepare(
                    "INSERT INTO sh_order_lines
                        (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total,
                         vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment,
                         driver_action_type, combo_meta_json)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
            } else {
                $stmtLine = $pdo->prepare(
                    "INSERT INTO sh_order_lines
                        (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total,
                         vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment, driver_action_type)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
            }
            $hasOIM = false;
            $stmtOIM = null;
            try {
                $pdo->query("SELECT 1 FROM sh_order_item_modifiers LIMIT 0");
                $stmtOIM = $pdo->prepare(
                    "INSERT INTO sh_order_item_modifiers (order_item_id, modifier_type, modifier_sku) VALUES (?,?,?)"
                );
                $hasOIM = true;
            } catch (\PDOException $e) {}

            // Pre-fetch driver_action_type for all menu items used in this cart
            $driverActionMap = [];
            try {
                $cartSkus = array_filter(array_map(fn($c) => (string)($c['ascii_key'] ?? $c['id'] ?? ''), $cart));
                if (count($cartSkus) > 0) {
                    $skuPh = []; $skuPrm = [':tid' => $tenant_id];
                    foreach (array_values(array_unique($cartSkus)) as $si => $sv) {
                        $k = ":sk{$si}"; $skuPh[] = $k; $skuPrm[$k] = $sv;
                    }
                    $stmtDat = $pdo->prepare(
                        "SELECT ascii_key, COALESCE(driver_action_type, 'none') AS driver_action_type
                         FROM sh_menu_items WHERE ascii_key IN (" . implode(',', $skuPh) . ") AND tenant_id = :tid"
                    );
                    $stmtDat->execute($skuPrm);
                    foreach ($stmtDat->fetchAll(PDO::FETCH_ASSOC) as $datRow) {
                        $driverActionMap[$datRow['ascii_key']] = $datRow['driver_action_type'];
                    }
                }
            } catch (\Throwable $ignore) {}

            foreach ($cart as $item) {
                $lineId = Uuid::v4();
                $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
                $clientPrice = (int)round(((float)($item['price'] ?? 0)) * 100);
                // F5-B: użyj serwerowej ceny jeśli CartEngine policzył, inaczej fallback do payloadu.
                $cartIdx = array_search($item, $cart, true);
                $price = ($serverPrices !== null && isset($serverPrices[$cartIdx]) && $serverPrices[$cartIdx] > 0)
                    ? (int)$serverPrices[$cartIdx]
                    : $clientPrice;
                $lineTotal = $price * $qty;
                $sku = $item['ascii_key'] ?? $item['id'] ?? '';

                $modsJson    = !empty($item['added']) ? json_encode($item['added'], JSON_UNESCAPED_UNICODE) : null;
                $removedJson = !empty($item['removed']) ? json_encode($item['removed'], JSON_UNESCAPED_UNICODE) : null;

                $vatRate = (float)($item['vat_rate'] ?? ($orderType === 'dine_in' ? 8.00 : 5.00));
                $vatAmount = (int)round($lineTotal * $vatRate / (100 + $vatRate));

                $itemSku = (string)($item['ascii_key'] ?? $item['id'] ?? '');
                $driverActionType = $driverActionMap[$itemSku] ?? ($item['driver_action_type'] ?? 'none');
                if (!in_array($driverActionType, ['none','pack_cold','pack_separate','check_id'], true)) {
                    $driverActionType = 'none';
                }

                // F-S3.2 (2026-05-11): combo meta JSON (jeśli klient wysłał).
                // Payload format: { meal_id, picks: [{component_id, sku, qty?}], fixed_items: [{sku, qty}] }
                $comboMetaJson = null;
                if (!empty($item['combo_meta']) && is_array($item['combo_meta']) && isset($item['combo_meta']['meal_id'])) {
                    $comboMetaJson = json_encode($item['combo_meta'], JSON_UNESCAPED_UNICODE);
                }

                if ($hasComboMetaInsert) {
                    $stmtLine->execute([
                        $lineId, $orderId, (string)$sku,
                        $item['name'] ?? '', $price, $qty, $lineTotal,
                        $vatRate, $vatAmount, $modsJson, $removedJson, $item['comment'] ?? null,
                        $driverActionType, $comboMetaJson
                    ]);
                } else {
                    $stmtLine->execute([
                        $lineId, $orderId, (string)$sku,
                        $item['name'] ?? '', $price, $qty, $lineTotal,
                        $vatRate, $vatAmount, $modsJson, $removedJson, $item['comment'] ?? null,
                        $driverActionType
                    ]);
                }

                if ($hasOIM && $stmtOIM) {
                    if (!empty($item['added']) && is_array($item['added'])) {
                        foreach ($item['added'] as $mod) {
                            $modSku = is_array($mod) ? ($mod['ascii_key'] ?? $mod['sku'] ?? (string)$mod) : (string)$mod;
                            if ($modSku !== '') {
                                $stmtOIM->execute([$lineId, 'ADDED', $modSku]);
                            }
                        }
                    }
                    if (!empty($item['removed']) && is_array($item['removed'])) {
                        foreach ($item['removed'] as $rem) {
                            $remSku = is_array($rem) ? ($rem['sku'] ?? (string)$rem) : (string)$rem;
                            if ($remSku !== '') {
                                $stmtOIM->execute([$lineId, 'REMOVED', $remSku]);
                            }
                        }
                    }
                }
            }

            // --- [m026] Publish order.created / order.edited do outboxu ---
            $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
            if (file_exists($publisherPath)) {
                require_once $publisherPath;
                if (class_exists('OrderEventPublisher')) {
                    $isEdit = ($editId !== '' && $editId !== '0');
                    $eventType = $isEdit ? 'order.edited' : 'order.created';
                    OrderEventPublisher::publishOrderLifecycle(
                        $pdo, $tenant_id, $eventType, $orderId,
                        [
                            'channel'         => $channel,
                            'order_type'      => $orderType,
                            'payment_method'  => $payMethod,
                            'payment_status'  => $payStatus,
                            'kitchen_changes' => $isEdit ? $kitchenChanges : null,
                        ],
                        ['source' => 'pos', 'actorType' => 'staff', 'actorId' => (string)$user_id]
                    );
                }
            }

            $pdo->commit();

            // --- Papu.io integration (fire-and-forget, post-commit) ---
            if ($editId === '' || $editId === '0') {
                // Papu.io sync push removed (D2 drift fix).
                // POS already publishes order.created to outbox (above).
                // PapuAdapter (async, via worker_integrations.php) handles push.
            }

            posResponse(true, ['order_id' => $orderId]);

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] ' . $e->getMessage());
            posResponse(false, null, 'Operation failed. Please try again.');
        }
    }

    // =========================================================================
    // ACCEPT_ORDER — Accept incoming online order with time
    //
    // Transitions new → accepted (canonical status visible in KDS + tracker).
    // Sets accepted_at timestamp + promised_time, publishes order.accepted event.
    // [FF-006] When 'skip_acceptance' is active, this step can be bypassed
    // entirely — checkout.php would create orders as 'accepted' directly.
    // =========================================================================
    if ($action === 'accept_order') {
        $oid  = inputStr($input, 'order_id');
        $time = inputStr($input, 'custom_time');
        $ts = ($time !== '') ? strtotime($time) : false;
        // Faza B — gdy kasjer nie poda custom_time: PromisedTimeEngine (inteligentny default)
        // zamiast now(). Ręczny input nadal wygrywa. Poniżej: $parsedTime ustawiane po SELECT
        // (potrzebny order_type dla kanału silnika).
        $parsedTime = ($ts !== false) ? date('Y-m-d H:i:s', $ts) : null;

        if ($oid === '') {
            posResponse(false, null, 'order_id is required.');
        }

        // Pre-check: fetch current status + order_type (dla kanału PromisedTimeEngine)
        $stmtOrder = $pdo->prepare(
            "SELECT status, order_type FROM sh_orders WHERE id = :oid AND tenant_id = :tid LIMIT 1"
        );
        $stmtOrder->execute([':oid' => $oid, ':tid' => $tenant_id]);
        $orderRow = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$orderRow) {
            posResponse(false, null, 'Order not found.');
        }

        // Faza B — default promised_time przez silnik gdy kasjer nie podał czasu
        if ($parsedTime === null) {
            require_once __DIR__ . '/../../core/PromisedTimeEngine.php';
            $ptChannel = strtolower((string)($orderRow['order_type'] ?? 'delivery'));
            try {
                $ptCalc = PromisedTimeEngine::calculate($pdo, $tenant_id, 'asap', $ptChannel);
                $parsedTime = (new DateTime($ptCalc['promised_time'], new DateTimeZone('Europe/Warsaw')))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                error_log('[POS.accept_order.promised] ' . $e->getMessage());
                $parsedTime = date('Y-m-d H:i:s'); // fallback — nie blokuj akceptu
            }
        }

        if (!OrderStateMachine::canTransition($orderRow['status'], 'accepted', $tenantFlags)) {
            http_response_code(409);
            posResponse(false, null, "Cannot accept order. Current status is '{$orderRow['status']}', transition to 'accepted' not allowed.");
        }

        require_once __DIR__ . '/../../core/KdsAcceptRouting.php';

        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $result = OrderStateMachine::transitionOrder(
                $pdo, $oid, $tenant_id, $user_id, 'accepted', $tenantFlags,
                ['promised_time' => $parsedTime, 'kitchen_ticket_printed' => 1, 'accepted_at' => $now]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            $kdsTickets = [];
            if (empty($tenantFlags['disable_kds'])) {
                try {
                    $kdsTickets = KdsAcceptRouting::createTicketsForAcceptedOrder($pdo, $tenant_id, $oid);
                } catch (\InvalidArgumentException $e) {
                    $pdo->rollBack();
                    posResponse(false, null, $e->getMessage());
                }
            }

            // Publish order.accepted event do outboxu (feeds NotificationDispatcher + webhooks)
            require_once __DIR__ . '/../../core/OrderEventPublisher.php';
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenant_id, 'order.accepted', $oid,
                ['promised_time' => $parsedTime, 'accepted_by_user_id' => $user_id],
                ['source' => 'pos', 'actorType' => 'staff', 'actorId' => (string)$user_id]
            );

            $pdo->commit();

            // ============================================================
            // F1 (2026-05-11) — Pętla zużycia magazynu (post-commit hook).
            // Konstytucja v5 § Prawo II (Bliźniak Cyfrowy).
            //
            // Po pomyślnej tranzycji `→ accepted` + commit-cie outer transakcji
            // (tickety KDS już zapisane, event order.accepted już opublikowany)
            // wywołujemy synchroniczny hook konsumpcji magazynu w niezależnej
            // transakcji WzEngine. Failure hook NIE blokuje akceptu — zamówienie
            // jest już zaakceptowane semantycznie. Loggujemy alert + zwracamy
            // info w response, manager może naprawić korektą (KOR engine).
            // ============================================================
            require_once __DIR__ . '/../../core/WarehouseConsumeHook.php';
            $consumeWarehouseId = isset($input['warehouse_id']) ? trim((string) $input['warehouse_id']) : null;
            if ($consumeWarehouseId === '') {
                $consumeWarehouseId = null;
            }
            $consumeResult = WarehouseConsumeHook::onOrderAccepted(
                $pdo, $tenant_id, $oid, $user_id, $consumeWarehouseId
            );

            $out = ['promised_time' => $parsedTime];
            if ($kdsTickets !== []) {
                $out['kds_tickets'] = $kdsTickets;
            }
            $out['warehouse_consume'] = $consumeResult;
            posResponse(true, $out);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[POS Engine] accept_order: ' . $e->getMessage());
            posResponse(false, null, 'Accept failed. Please try again.');
        }
    }

    // =========================================================================
    // UPDATE_STATUS — Guarded by OrderStateMachine
    //
    // [FF-HOOK] Transition validation uses $tenantFlags. When a flag like
    // 'skip_kitchen' is active, the state machine automatically permits
    // shortcuts (e.g., pending → ready). No changes needed here.
    // =========================================================================
    if ($action === 'update_status') {
        $oid = inputStr($input, 'order_id');
        $newStatus = inputStr($input, 'status');

        if ($oid === '') {
            posResponse(false, null, 'order_id is required.');
        }

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::transitionOrder(
                $pdo, $oid, $tenant_id, $user_id, $newStatus, $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            $pdo->commit();
            posResponse(true, [
                'old_status' => $result['old_status'],
                'new_status' => $result['new_status'],
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] update_status: ' . $e->getMessage());
            posResponse(false, null, 'Status update failed. Please try again.');
        }
    }

    // =========================================================================
    // PRINT_KITCHEN
    // =========================================================================
    if ($action === 'print_kitchen') {
        $oid = inputStr($input, 'order_id');
        $pdo->prepare(
            "UPDATE sh_orders SET kitchen_ticket_printed=1, edited_since_print=0, kitchen_changes=NULL, kitchen_delta=NULL, updated_at=NOW()
             WHERE id=? AND tenant_id=?"
        )->execute([$oid, $tenant_id]);
        posResponse(true);
    }

    // =========================================================================
    // PRINT_RECEIPT
    // =========================================================================
    if ($action === 'print_receipt') {
        $oid    = inputStr($input, 'order_id');
        $method = inputStr($input, 'payment_method');
        $sql = "UPDATE sh_orders SET receipt_printed=1, updated_at=NOW()";
        $params = [];
        if ($method !== '') {
            $sql .= ", payment_method=?";
            $params[] = $method;
        }
        $sql .= " WHERE id=? AND tenant_id=?";
        $params[] = $oid;
        $params[] = $tenant_id;
        $pdo->prepare($sql)->execute($params);
        posResponse(true);
    }

    // =========================================================================
    // FISCAL_PRINT — Wydrukuj paragon fiskalny przez Elzab Zeta Online
    // =========================================================================
    if ($action === 'fiscal_print') {
        require_once __DIR__ . '/../../core/Elzab/ThermalProtocol.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabPrinter.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabFiscalEngine.php';

        $oid = inputStr($input, 'order_id');
        if ($oid === '') {
            posResponse(false, null, 'Brak order_id');
        }

        $force = !empty($input['force']);
        $result = \SliceHub\Elzab\ElzabFiscalEngine::fiscalizeOrder($pdo, $oid, $tenant_id, $user_id, $force);
        posResponse($result['success'], $result, $result['error'] ?? null);
    }

    // =========================================================================
    // FISCAL_DAILY_REPORT — Raport dobowy (zamknięcie doby fiskalnej)
    // =========================================================================
    if ($action === 'fiscal_daily_report') {
        require_once __DIR__ . '/../../core/Elzab/ThermalProtocol.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabPrinter.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabFiscalEngine.php';

        $result = \SliceHub\Elzab\ElzabFiscalEngine::printDailyReport($pdo, $tenant_id);
        posResponse($result['success'], $result, $result['error'] ?? null);
    }

    // =========================================================================
    // FISCAL_STATUS — Sprawdź połączenie z drukarką fiskalną
    // =========================================================================
    if ($action === 'fiscal_status' || $action === 'fiscal_test') {
        require_once __DIR__ . '/../../core/Elzab/ThermalProtocol.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabPrinter.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabFiscalEngine.php';

        if ($action === 'fiscal_test') {
            $host = inputStr($input, 'host');
            $port = (int)inputStr($input, 'port', '1001');
            if ($host === '') {
                posResponse(false, null, 'Podaj adres IP drukarki');
            }
            $printer = new \SliceHub\Elzab\ElzabPrinter($host, $port, 3, 5);
            if (!$printer->ping()) {
                posResponse(false, null, "Brak połączenia z {$host}:{$port}");
            }
            posResponse(true, ['host' => $host, 'port' => $port], 'Drukarka online');
        }

        $result = \SliceHub\Elzab\ElzabFiscalEngine::checkStatus($pdo, $tenant_id);
        posResponse($result['success'], $result, $result['error'] ?? null);
    }

    // =========================================================================
    // FISCAL_GET_CONFIG — Pobierz konfigurację drukarki fiskalnej
    // =========================================================================
    if ($action === 'fiscal_get_config') {
        require_once __DIR__ . '/../../core/Elzab/ThermalProtocol.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabPrinter.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabFiscalEngine.php';

        $result = \SliceHub\Elzab\ElzabFiscalEngine::getConfig($pdo, $tenant_id);
        posResponse($result['success'] ?? false, $result, $result['error'] ?? null);
    }

    // =========================================================================
    // FISCAL_SAVE_CONFIG — Zapisz konfigurację drukarki fiskalnej
    // =========================================================================
    if ($action === 'fiscal_save_config') {
        require_once __DIR__ . '/../../core/Elzab/ThermalProtocol.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabPrinter.php';
        require_once __DIR__ . '/../../core/Elzab/ElzabFiscalEngine.php';

        $config = [
            'host' => inputStr($input, 'host'),
            'port' => inputStr($input, 'port', '1001'),
            'cashbox' => inputStr($input, 'cashbox', 'POS1'),
            'footer_line_1' => inputStr($input, 'footer_line_1'),
            'footer_line_2' => inputStr($input, 'footer_line_2'),
            'footer_line_3' => inputStr($input, 'footer_line_3'),
        ];

        if ($config['host'] === '') {
            posResponse(false, null, 'Adres IP drukarki jest wymagany');
        }

        \SliceHub\Elzab\ElzabFiscalEngine::saveConfig($pdo, $tenant_id, $config);
        posResponse(true, $config, 'Konfiguracja zapisana');
    }

    // =========================================================================
    // SETTLE_AND_CLOSE — Settle payment + mark completed via State Machine
    //
    // [FF-HOOK] Uses OrderStateMachine::fastComplete() which respects
    // tenant flags. With 'auto_complete' flag, this can close orders from
    // any non-terminal status (e.g., pending → completed for fast food).
    // =========================================================================
    if ($action === 'settle_and_close') {
        $oid    = inputStr($input, 'order_id');
        $print  = (int)($input['print_receipt'] ?? 0);
        $rawPayments = $input['payments'] ?? null;

        if ($oid === '') {
            posResponse(false, null, 'order_id is required.');
        }

        $payments = [];
        if (is_array($rawPayments) && count($rawPayments) > 0) {
            foreach ($rawPayments as $p) {
                if (!is_array($p)) {
                    posResponse(false, null, 'Invalid payments entry.');
                }
                $payments[] = [
                    'method'         => trim((string)($p['method'] ?? $p['payment_method'] ?? '')),
                    'amount'         => $p['amount'] ?? null,
                    'tendered'       => $p['tendered'] ?? null,
                    'transaction_id' => $p['transaction_id'] ?? null,
                ];
            }
        } else {
            $method = inputStr($input, 'payment_method');
            if ($method === '') {
                posResponse(false, null, 'payment_method or payments[] is required.');
            }
            $stmtGt = $pdo->prepare(
                "SELECT grand_total FROM sh_orders WHERE id = :oid AND tenant_id = :tid"
            );
            $stmtGt->execute([':oid' => $oid, ':tid' => $tenant_id]);
            $grandTotalGrosze = (int)$stmtGt->fetchColumn();
            $stmtGt->closeCursor();

            $payments = [[
                'method' => $method,
                'amount' => $grandTotalGrosze / 100,
            ]];
        }

        $pdo->beginTransaction();
        try {
            $result = SettlementEngine::settleAndClose(
                $pdo,
                $oid,
                $tenant_id,
                $user_id,
                $payments,
                ['print_receipt' => ($print === 1), 'settled_via' => 'pos'],
                $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            if (empty($result['idempotent'])) {
                require_once __DIR__ . '/../../core/OrderEventPublisher.php';
                OrderEventPublisher::publishOrderLifecycle(
                    $pdo, $tenant_id, 'order.completed', $oid,
                    [
                        'from_status'    => $result['old_status'],
                        'payment_method' => $result['payment_method'] ?? 'cash',
                        'split_tender'   => !empty($result['split_tender']),
                        'completed_at'   => date('Y-m-d H:i:s'),
                        'settled_via'    => 'pos',
                    ],
                    [
                        'source'    => 'pos',
                        'actorType' => 'staff',
                        'actorId'   => (string)$user_id,
                    ]
                );
            }

            $pdo->commit();
            posResponse(true, [
                'old_status'     => $result['old_status'],
                'idempotent'     => !empty($result['idempotent']),
                'split_tender'   => !empty($result['split_tender']),
                'change_due'     => number_format(((int)($result['change_due_grosze'] ?? 0)) / 100, 2, '.', ''),
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] settle_and_close: ' . $e->getMessage());
            posResponse(false, null, 'Settlement failed. Please try again.');
        }
    }

    // =========================================================================
    // FAST_COMPLETE — One-shot: create-and-close or settle-from-any-state
    //
    // [FF-005] This action is the primary hook for the future 'fast_complete'
    // and 'auto_complete' feature flags. When the Global Settings Matrix
    // enables 'auto_complete' for a tenant, this endpoint will accept orders
    // in ANY non-terminal status and atomically:
    //   1. Transition status → completed
    //   2. Set payment_method + payment_status
    //   3. Optionally mark receipt_printed
    //   4. Write audit trail
    //
    // Payload: { action: "fast_complete", order_id, payment_method, print_receipt? }
    // =========================================================================
    if ($action === 'fast_complete') {
        $oid    = inputStr($input, 'order_id');
        $method = inputStr($input, 'payment_method');
        $print  = (int)($input['print_receipt'] ?? 0);

        if ($oid === '' || $method === '') {
            posResponse(false, null, 'order_id and payment_method are required.');
        }

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::fastComplete(
                $pdo, $oid, $tenant_id, $user_id, $method, $tenantFlags,
                ['print_receipt' => ($print === 1)]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            require_once __DIR__ . '/../../core/OrderEventPublisher.php';
            OrderEventPublisher::publishOrderLifecycle(
                $pdo, $tenant_id, 'order.completed', $oid,
                [
                    'from_status'    => $result['old_status'],
                    'payment_method' => $method,
                    'completed_at'   => date('Y-m-d H:i:s'),
                ],
                [
                    'source'    => 'pos',
                    'actorType' => 'staff',
                    'actorId'   => (string)$user_id,
                ]
            );

            $pdo->commit();
            posResponse(true, [
                'order_id'   => $oid,
                'old_status' => $result['old_status'],
                'new_status' => 'completed',
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] fast_complete: ' . $e->getMessage());
            posResponse(false, null, 'Fast completion failed. Please try again.');
        }
    }

    // =========================================================================
    // CANCEL_ORDER — Cancel with optional stock return, via State Machine
    // =========================================================================
    if ($action === 'cancel_order') {
        $oid         = inputStr($input, 'order_id');
        $returnStock = (int)($input['return_stock'] ?? 0);

        if ($oid === '') {
            posResponse(false, null, 'order_id is required.');
        }

        // F5-C: snapshot order_type i table_id PRZED tranzycją (gdyby OSM coś nullował).
        $stmtSnap = $pdo->prepare(
            "SELECT order_type, table_id, status FROM sh_orders
              WHERE id = :oid AND tenant_id = :tid LIMIT 1"
        );
        $stmtSnap->execute([':oid' => $oid, ':tid' => $tenant_id]);
        $orderSnap = $stmtSnap->fetch(PDO::FETCH_ASSOC) ?: [];
        $wasAccepted = in_array((string)($orderSnap['status'] ?? ''), ['accepted','preparing','ready','dispatched'], true);
        $orderTypeSnap = (string)($orderSnap['order_type'] ?? '');
        $tableIdSnap   = $orderSnap['table_id'] ?? null;

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::transitionOrder(
                $pdo, $oid, $tenant_id, $user_id, 'cancelled', $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            // F5-C: zwolnij stolik dla dine_in (Konstytucja v5 § Prawo II — Bliźniak fizyczny).
            $tableFreed = false;
            if ($orderTypeSnap === 'dine_in' && $tableIdSnap) {
                try {
                    $pdo->prepare(
                        "UPDATE sh_tables
                            SET physical_status = 'free',
                                updated_at = NOW()
                          WHERE id = :tid_tab AND tenant_id = :tid
                            AND physical_status IN ('occupied','reserved','dirty','merged')"
                    )->execute([':tid_tab' => $tableIdSnap, ':tid' => $tenant_id]);
                    $tableFreed = true;
                } catch (\Throwable $tabErr) {
                    error_log('[POS Engine] cancel_order: free table failed: ' . $tabErr->getMessage());
                }
            }

            $pdo->commit();

            // F5-C: reverse magazynu POST-COMMIT (analogicznie do F1 consume hook).
            // Tylko jeśli zamówienie zostało wcześniej `accepted` (lub dalej) — wtedy WZ istnieje.
            $reverseResult = null;
            if ($wasAccepted) {
                require_once __DIR__ . '/../../core/WarehouseReverseHook.php';
                $reverseResult = WarehouseReverseHook::onOrderCancelled(
                    $pdo, $tenant_id, $oid, $user_id
                );
            }

            posResponse(true, [
                'old_status'        => $result['old_status'],
                'table_freed'       => $tableFreed,
                'warehouse_reverse' => $reverseResult,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] cancel_order: ' . $e->getMessage());
            posResponse(false, null, 'Cancellation failed. Please try again.');
        }
    }

    // =========================================================================
    // PANIC_MODE — shift promised_time on all active orders (debounce + configurable delay)
    // Absorbed from api/orders/panic.php → core/PanicEngine.php
    // =========================================================================
    if ($action === 'panic_mode') {
        require_once __DIR__ . '/../../core/PanicEngine.php';
        $delayMinutes = (int)($input['delay_minutes'] ?? 20);
        try {
            $result = PanicEngine::execute($pdo, $tenant_id, isset($user_id) ? (string)$user_id : null, $delayMinutes);
            posResponse(true, $result);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'debounce') || str_contains($e->getMessage(), 'less than') ? 429 : 400;
            http_response_code($code);
            posResponse(false, null, $e->getMessage());
        }
    }

    // =========================================================================
    // ASSIGN_ROUTE — K-System + L-Queues
    //
    // [FF-HOOK] HARD BLOCKER: Validates open kitchen statuses incl. legacy
    // `pending` and canonical `new`/`accepted`. When 'skip_kitchen' is active,
    // orders may sit at `new`/`pending` with no kitchen step — this is already
    // handled. When 'skip_dispatch' flag is active, this entire action can
    // be bypassed and settle_and_close / fast_complete used directly.
    // =========================================================================
    if ($action === 'assign_route') {
        require_once __DIR__ . '/../../core/OrderEventPublisher.php';
        $driverId = inputStr($input, 'driver_id');
        $orderIds = $input['order_ids'] ?? [];

        if ($driverId === '' || empty($orderIds)) {
            posResponse(false, null, 'Wybierz kierowcę i zamówienia.');
        }

        $pdo->beginTransaction();
        try {
            // Validate driver exists and is available
            $stmtDrv = $pdo->prepare(
                "SELECT d.status FROM sh_drivers d
                 JOIN sh_users u ON d.user_id = u.id AND d.tenant_id = u.tenant_id
                 WHERE d.user_id = ? AND d.tenant_id = ? AND u.is_deleted = 0"
            );
            $stmtDrv->execute([$driverId, $tenant_id]);
            $drvRow = $stmtDrv->fetch(PDO::FETCH_ASSOC);
            if (!$drvRow) {
                $pdo->rollBack();
                posResponse(false, null, 'Kierowca nie istnieje.');
            }
            if ($drvRow['status'] === 'busy') {
                $pdo->rollBack();
                posResponse(false, null, 'Kierowca jest w trasie. Poczekaj na zakończenie kursu.');
            }

            // Validate all orders are delivery + ready
            $phO = []; $prmO = [':tid' => $tenant_id];
            foreach ($orderIds as $i => $oid) {
                $k = ":o{$i}"; $phO[] = $k; $prmO[$k] = (string)$oid;
            }
            $stmtVal = $pdo->prepare(
                "SELECT id, status, order_type FROM sh_orders
                 WHERE id IN (" . implode(',', $phO) . ") AND tenant_id = :tid"
            );
            $stmtVal->execute($prmO);
            $validOrders = $stmtVal->fetchAll(PDO::FETCH_ASSOC);

            if (count($validOrders) !== count($orderIds)) {
                $pdo->rollBack();
                posResponse(false, null, 'Jedno lub więcej zamówień nie istnieje.');
            }
            foreach ($validOrders as $vo) {
                if ($vo['order_type'] !== 'delivery') {
                    $pdo->rollBack();
                    posResponse(false, null, "Zamówienie {$vo['id']} nie jest dostawą.");
                }
                if (!in_array($vo['status'], ['ready', 'new', 'accepted', 'pending', 'preparing'], true)) {
                    $pdo->rollBack();
                    posResponse(false, null, "Zamówienie {$vo['id']} ma status '{$vo['status']}' — wymagane: new/accepted/preparing/ready (lub legacy pending).");
                }
            }
            $statusMap = array_column($validOrders, 'status', 'id');

            // Course sequence (K-number)
            $stmtK = $pdo->prepare(
                "INSERT INTO sh_course_sequences (tenant_id, `date`, seq)
                 VALUES (?, CURDATE(), LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
            );
            $stmtK->execute([$tenant_id]);
            $nextK = (int)$pdo->lastInsertId();
            $courseId = 'K' . $nextK;

            $stmtUpdate = $pdo->prepare(
                "UPDATE sh_orders SET delivery_status='in_delivery', driver_id=?, course_id=?, stop_number=?, updated_at=NOW()
                 WHERE id=? AND tenant_id=?"
            );
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status)
                 VALUES (?,?,?,'in_delivery')"
            );

            $lNum = 1;
            foreach ($orderIds as $oid) {
                $trimmedOid = trim((string)$oid);
                $stopNum = 'L' . $lNum;
                $stmtUpdate->execute([$driverId, $courseId, $stopNum, $trimmedOid, $tenant_id]);
                try {
                    $stmtAudit->execute([$trimmedOid, $user_id, $statusMap[$trimmedOid] ?? 'ready']);
                } catch (\Throwable $ignore) {}
                OrderEventPublisher::publishOrderLifecycle($pdo, $tenant_id, 'order.in_delivery', $trimmedOid,
                    ['source'=>'pos_assign_route', 'course_id'=>$courseId, 'driver_id'=>$driverId, 'stop'=>$stopNum]);
                $lNum++;
            }

            // Mark driver as busy
            $pdo->prepare(
                "UPDATE sh_drivers SET status='busy' WHERE user_id=? AND tenant_id=?"
            )->execute([$driverId, $tenant_id]);

            // Dispatch log
            $dispatchId = Uuid::v4();
            $pdo->prepare(
                "INSERT INTO sh_dispatch_log (id, tenant_id, course_id, driver_id, order_ids_json, dispatched_by)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $dispatchId, $tenant_id, $courseId, $driverId,
                json_encode($orderIds), $user_id
            ]);

            $pdo->commit();
            posResponse(true, ['course_id' => $courseId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] ' . $e->getMessage());
            posResponse(false, null, 'Operation failed. Please try again.');
        }
    }

    posResponse(false, null, 'Unknown action: ' . $action);

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[POS Engine] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    posResponse(false, null, 'Internal server error. Please try again.');
}
