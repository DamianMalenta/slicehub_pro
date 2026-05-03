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
    require_once __DIR__ . '/../../core/AssetResolver.php';

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
        if ($schemaV2) {
            $stmtItems = $pdo->prepare(
                "SELECT id, category_id, name, ascii_key, is_active, image_url, description,
                        vat_rate_dine_in, vat_rate_takeaway
                 FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0
                 ORDER BY display_order ASC, name ASC"
            );
        } else {
            $stmtItems = $pdo->prepare(
                "SELECT id, category_id, name, ascii_key, is_active, NULL AS image_url, description,
                        vat_rate AS vat_rate_dine_in, vat_rate AS vat_rate_takeaway, price
                 FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0
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
            $items[] = [
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
        }

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

        // -- Drivers --
        $drivers = [];
        try {
            $stmtDrivers = $pdo->prepare(
                "SELECT u.id, d.status,
                        COALESCE(NULLIF(TRIM(u.name), ''), COALESCE(NULLIF(TRIM(u.first_name),''), u.username)) AS display_name
                 FROM sh_drivers d
                 JOIN sh_users u ON d.user_id = u.id
                 WHERE u.tenant_id = ? AND u.is_deleted = 0"
            );
            $stmtDrivers->execute([$tenant_id]);
            $drivers = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}

        // -- Waiters --
        $waiters = [];
        try {
            $stmtWaiters = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(name),''), COALESCE(NULLIF(TRIM(first_name),''), username)) AS display_name
                 FROM sh_users
                 WHERE tenant_id = ? AND role = 'waiter' AND is_active = 1 AND is_deleted = 0"
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

        posResponse(true, [
            'categories'     => $categories,
            'items'          => $items,
            'ingredients'    => $ingredients,
            'drivers'        => $drivers,
            'waiters'        => $waiters,
            'modifierGroups' => $modifierGroups,
        ]);
    }

    // =========================================================================
    // GET_ITEM_DETAILS — Recipe ingredients for a dish card
    // =========================================================================
    if ($action === 'get_item_details') {
        $itemId  = (int)($input['item_id'] ?? 0);
        $halfBId = (int)($input['half_b_id'] ?? 0);

        $stmtSku = $pdo->prepare("SELECT ascii_key FROM sh_menu_items WHERE id = ? AND tenant_id = ?");

        $stmtRecipe = $pdo->prepare(
            "SELECT r.warehouse_sku AS sku, si.name, si.base_unit AS unit
             FROM sh_recipes r
             LEFT JOIN sys_items si ON si.sku = r.warehouse_sku AND si.tenant_id = r.tenant_id
             WHERE r.menu_item_sku = ? AND r.tenant_id = ?"
        );

        $ingredients = [];

        $stmtSku->execute([$itemId, $tenant_id]);
        $skuA = $stmtSku->fetchColumn();
        if ($skuA) {
            $stmtRecipe->execute([$skuA, $tenant_id]);
            foreach ($stmtRecipe->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                $ing['half'] = 'A';
                $ingredients[] = $ing;
            }
        }

        if ($halfBId > 0) {
            $stmtSku->execute([$halfBId, $tenant_id]);
            $skuB = $stmtSku->fetchColumn();
            if ($skuB) {
                $stmtRecipe->execute([$skuB, $tenant_id]);
                foreach ($stmtRecipe->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                    $ing['half'] = 'B';
                    $ingredients[] = $ing;
                }
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
                 FROM sh_order_lines WHERE order_id = ?"
            );
            $stmtLines->execute([$o['id']]);
            $o['lines'] = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            $o['grand_total_formatted'] = number_format(((int)$o['grand_total']) / 100, 2, '.', '');
        }
        unset($o);

        // Fresh driver statuses (so fleet panel updates on every poll)
        $drivers = [];
        try {
            $stmtDrv = $pdo->prepare(
                "SELECT u.id, d.status,
                        COALESCE(NULLIF(TRIM(u.name), ''), COALESCE(NULLIF(TRIM(u.first_name),''), u.username)) AS display_name
                 FROM sh_drivers d
                 JOIN sh_users u ON d.user_id = u.id
                 WHERE u.tenant_id = ? AND u.is_deleted = 0"
            );
            $stmtDrv->execute([$tenant_id]);
            $drivers = $stmtDrv->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}

        posResponse(true, ['orders' => $orders, 'drivers' => $drivers]);
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
                         WHERE order_id = ? AND fired_at IS NOT NULL AND line_status != 'cancelled'"
                    )->execute([$editId]);

                    try {
                        $pdo->prepare(
                            "DELETE oim FROM sh_order_item_modifiers oim
                             JOIN sh_order_lines ol ON oim.order_item_id = ol.id
                             WHERE ol.order_id = ? AND ol.fired_at IS NULL"
                        )->execute([$editId]);
                    } catch (\PDOException $e) {}
                    $pdo->prepare(
                        "DELETE FROM sh_order_lines WHERE order_id = ? AND fired_at IS NULL"
                    )->execute([$editId]);
                } else {
                    try {
                        $pdo->prepare(
                            "DELETE oim FROM sh_order_item_modifiers oim
                             JOIN sh_order_lines ol ON oim.order_item_id = ol.id
                             WHERE ol.order_id = ?"
                        )->execute([$editId]);
                    } catch (\PDOException $e) {}
                    $pdo->prepare("DELETE FROM sh_order_lines WHERE order_id = ?")->execute([$editId]);
                }

                $pdo->prepare(
                    "UPDATE sh_orders SET
                        order_type=?, channel=?, payment_method=?, payment_status=?,
                        grand_total=?, subtotal=?, delivery_address=?, customer_phone=?,
                        customer_name=?, nip=?, cart_json=?, promised_time=?,
                        edited_since_print=?, kitchen_changes=?,
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

                $orderId = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                    mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
                );

                $deliveryStatus = ($orderType === 'delivery') ? 'unassigned' : null;

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
            $stmtLine = $pdo->prepare(
                "INSERT INTO sh_order_lines
                    (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total,
                     vat_rate, vat_amount, modifiers_json, removed_ingredients_json, comment, driver_action_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
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
                $lineId = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                    mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
                );
                $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
                $price = (int)round(((float)($item['price'] ?? 0)) * 100);
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

                $stmtLine->execute([
                    $lineId, $orderId, (string)$sku,
                    $item['name'] ?? '', $price, $qty, $lineTotal,
                    $vatRate, $vatAmount, $modsJson, $removedJson, $item['comment'] ?? null,
                    $driverActionType
                ]);

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
                try {
                    $stmtPapu = $pdo->prepare(
                        "SELECT setting_value FROM sh_tenant_settings
                         WHERE tenant_id = ? AND setting_key = 'papu_api_key' LIMIT 1"
                    );
                    $stmtPapu->execute([$tenant_id]);
                    $papuKey = $stmtPapu->fetchColumn();

                    if (is_string($papuKey) && $papuKey !== '') {
                        require_once __DIR__ . '/../../core/Integrations/PapuClient.php';
                        $papuOrderData = [
                            'id'               => $orderId,
                            'order_number'     => $orderNumber,
                            'order_type'       => $orderType,
                            'channel'          => $channel,
                            'payment_method'   => $payMethod,
                            'payment_status'   => $payStatus,
                            'grand_total'      => $totalGrosze,
                            'customer_name'    => $custName,
                            'customer_phone'   => $phone,
                            'delivery_address' => $address,
                            'promised_time'    => $promised,
                        ];
                        (new PapuClient($pdo, (int)$tenant_id))
                            ->pushOrder($papuOrderData, $cart, $papuKey);
                    }
                } catch (\Throwable $papuEx) {
                    error_log('[POS Engine] Papu integration error (non-fatal): ' . $papuEx->getMessage());
                }
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
        $parsedTime = ($ts !== false) ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        if ($oid === '') {
            posResponse(false, null, 'order_id is required.');
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
            $out = ['promised_time' => $parsedTime];
            if ($kdsTickets !== []) {
                $out['kds_tickets'] = $kdsTickets;
            }
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
            "UPDATE sh_orders SET kitchen_ticket_printed=1, edited_since_print=0, kitchen_changes=NULL, updated_at=NOW()
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
    // SETTLE_AND_CLOSE — Settle payment + mark completed via State Machine
    //
    // [FF-HOOK] Uses OrderStateMachine::fastComplete() which respects
    // tenant flags. With 'auto_complete' flag, this can close orders from
    // any non-terminal status (e.g., pending → completed for fast food).
    // =========================================================================
    if ($action === 'settle_and_close') {
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
            posResponse(true, ['old_status' => $result['old_status']]);
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

        $pdo->beginTransaction();
        try {
            $result = OrderStateMachine::transitionOrder(
                $pdo, $oid, $tenant_id, $user_id, 'cancelled', $tenantFlags
            );

            if (!$result['success']) {
                $pdo->rollBack();
                posResponse(false, null, $result['message']);
            }

            // [FF-HOOK] Future: if 'auto_stock_return' flag is active,
            // trigger warehouse reversal here using returnStock flag.

            $pdo->commit();
            posResponse(true, ['old_status' => $result['old_status']]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[POS Engine] cancel_order: ' . $e->getMessage());
            posResponse(false, null, 'Cancellation failed. Please try again.');
        }
    }

    // =========================================================================
    // PANIC_MODE — +20 min to open pipeline orders (legacy `pending` + canonical)
    // =========================================================================
    if ($action === 'panic_mode') {
        $affected = $pdo->prepare(
            "UPDATE sh_orders SET promised_time = DATE_ADD(COALESCE(promised_time, created_at), INTERVAL 20 MINUTE), updated_at=NOW()
             WHERE status IN ('new','accepted','pending','preparing','ready') AND tenant_id = ?"
        );
        $affected->execute([$tenant_id]);
        $cnt = $affected->rowCount();

        $panicId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );
        $pdo->prepare(
            "INSERT INTO sh_panic_log (id, tenant_id, triggered_by, delay_minutes, affected_count)
             VALUES (?,?,?,20,?)"
        )->execute([$panicId, $tenant_id, $user_id, $cnt]);

        posResponse(true, ['message' => "Wydłużono czasy o 20 minut ($cnt zamówień)!"]);
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
                $stmtUpdate->execute([$driverId, $courseId, 'L' . $lNum, $trimmedOid, $tenant_id]);
                try {
                    $stmtAudit->execute([$trimmedOid, $user_id, $statusMap[$trimmedOid] ?? 'ready']);
                } catch (\Throwable $ignore) {}
                $lNum++;
            }

            // Mark driver as busy
            $pdo->prepare(
                "UPDATE sh_drivers SET status='busy' WHERE user_id=? AND tenant_id=?"
            )->execute([$driverId, $tenant_id]);

            // Dispatch log
            $dispatchId = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
            );
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
