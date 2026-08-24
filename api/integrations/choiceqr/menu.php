<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Menu Export (Get Menu URL)
// GET /api/integrations/choiceqr/menu.php?t=SECRET_TOKEN&varSymbol=ID
//
// ChoiceQR wywołuje ten endpoint GET aby pobrać menu z SliceHub.
// Zwraca array kategorii z daniami w formacie ChoiceQR.
//
// posID = ascii_key (KLUCZOWE — musi się zgadzać z order.items[].posID)
// price w groszach (1/100 PLN — 25.00 PLN = 2500)
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

function cqr_menu_error(int $code, string $msg): never
{
    http_response_code($code);
    error_log('[ChoiceQR Menu] ' . $code . ' — ' . $msg);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/CredentialVault.php';
    require_once __DIR__ . '/../../../core/MenuVisibilityFilter.php';

    if (!isset($pdo)) {
        cqr_menu_error(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH + TENANT MAPPING
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    $varSymbol = trim((string)($_GET['varSymbol'] ?? ''));

    if ($providedToken === '') {
        cqr_menu_error(401, 'Missing token');
    }
    if ($varSymbol === '') {
        cqr_menu_error(400, 'Missing varSymbol');
    }

    $stmtTi = $pdo->prepare(
        "SELECT id, tenant_id, credentials, is_active
         FROM sh_tenant_integrations
         WHERE provider = 'choiceqr' AND is_active = 1"
    );
    $stmtTi->execute();
    $integrations = $stmtTi->fetchAll(PDO::FETCH_ASSOC);

    $tenantId = 0;
    $webhookToken = null;

    foreach ($integrations as $ti) {
        $credRaw = (string)$ti['credentials'];
        $credJson = CredentialVault::isEncrypted($credRaw) ? (CredentialVault::decrypt($credRaw) ?? '') : $credRaw;
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            $creds = [];
        }
        if ((string)($creds['var_symbol'] ?? '') === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = (string)($creds['webhook_token'] ?? '');
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_menu_error(403, "No tenant integration for varSymbol='{$varSymbol}'");
    }
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_menu_error(401, 'Invalid token');
    }

    // -------------------------------------------------------------------------
    // 2. FEATURE DETECT — sh_price_tiers
    // -------------------------------------------------------------------------
    $hasPriceTiers = false;
    try {
        $pdo->query("SELECT 1 FROM sh_price_tiers LIMIT 0");
        $hasPriceTiers = true;
    } catch (PDOException $e) {
        $hasPriceTiers = false;
    }

    // -------------------------------------------------------------------------
    // 3. LOAD CATEGORIES — public channel: is_menu = 1 AND is_deleted = 0
    // -------------------------------------------------------------------------
    $cqrCatWhere = MenuVisibilityFilter::publicCategoriesWhere('c');
    $stmtCats = $pdo->prepare(
        "SELECT c.id, c.name, c.display_order
         FROM sh_categories c
         WHERE c.tenant_id = :tid AND {$cqrCatWhere}
         ORDER BY c.display_order, c.id"
    );
    $stmtCats->execute([':tid' => $tenantId]);
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------------------
    // 4. LOAD MENU ITEMS — all non-deleted; filtered in PHP by visibility
    //    (publication_status + is_secret + temporal). PHP filter below uses
    //    MenuVisibilityFilter::PUBLISHED_STATUSES for canonical check.
    // -------------------------------------------------------------------------
    $cqrItemWhere = MenuVisibilityFilter::publicItemsWhere($pdo, 'mi');
    $stmtItems = $pdo->prepare(
        "SELECT mi.id, mi.category_id, mi.name, mi.ascii_key, mi.description,
                mi.is_active, mi.is_deleted, mi.is_secret,
                mi.vat_rate_dine_in, mi.allergens_json, mi.image_url,
                mi.badge_type, mi.publication_status,
                mi.valid_from, mi.valid_to
         FROM sh_menu_items mi
         WHERE mi.tenant_id = :tid AND {$cqrItemWhere}
         ORDER BY mi.display_order, mi.id"
    );
    $stmtItems->execute([':tid' => $tenantId]);
    $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Group items by category
    $itemsByCategory = [];
    foreach ($allItems as $item) {
        $catId = $item['category_id'];
        if ($catId === null) {
            continue;
        }
        $itemsByCategory[$catId][] = $item;
    }

    // -------------------------------------------------------------------------
    // 5. LOAD PRICES (if sh_price_tiers exists)
    // -------------------------------------------------------------------------
    $pricesBySku = [];
    if ($hasPriceTiers) {
        $stmtPrices = $pdo->prepare(
            "SELECT target_sku, channel, price
             FROM sh_price_tiers
             WHERE target_type = 'ITEM'
               AND (tenant_id = :tid OR tenant_id = 0)
             ORDER BY target_sku, tenant_id DESC"
        );
        $stmtPrices->execute([':tid' => $tenantId]);
        foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $sku = $pr['target_sku'];
            $ch = $pr['channel'];
            if (!isset($pricesBySku[$sku])) {
                $pricesBySku[$sku] = [];
            }
            // First occurrence wins (tenant_id DESC → tenant-specific first)
            if (!isset($pricesBySku[$sku][$ch])) {
                $pricesBySku[$sku][$ch] = (float)$pr['price'];
            }
        }
    }

    // -------------------------------------------------------------------------
    // 6. LOAD MODIFIER GROUPS + MODIFIERS + ITEM_MODIFIERS
    // -------------------------------------------------------------------------
    // Item → modifier groups mapping — unified visibility filter
    $cqrModGroupWhere = MenuVisibilityFilter::modifierGroupsWhere($pdo, 'g');
    $stmtItemMods = $pdo->prepare(
        "SELECT im.item_id, im.group_id,
                g.name AS group_name, g.ascii_key AS group_key,
                g.min_selection, g.max_selection, g.is_active AS group_active
         FROM sh_item_modifiers im
         JOIN sh_modifier_groups g ON g.id = im.group_id
         WHERE {$cqrModGroupWhere}
           AND g.tenant_id = :tid"
    );
    $stmtItemMods->execute([':tid' => $tenantId]);
    $itemModGroups = [];
    $groupMeta = [];
    foreach ($stmtItemMods->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int)$row['item_id'];
        $groupId = (int)$row['group_id'];
        $itemModGroups[$itemId][] = $groupId;
        $groupMeta[$groupId] = [
            'name' => $row['group_name'],
            'ascii_key' => $row['group_key'],
            'min_selection' => (int)$row['min_selection'],
            'max_selection' => (int)$row['max_selection'],
            'active' => (int)$row['group_active'] === 1,
        ];
    }

    // Modifier items per group — unified visibility filter
    $cqrModWhere = MenuVisibilityFilter::modifiersWhere('m');
    $stmtMods = $pdo->prepare(
        "SELECT m.id, m.group_id, m.name, m.ascii_key, m.price,
                m.is_active, m.is_deleted
         FROM sh_modifiers m
         JOIN sh_modifier_groups g ON g.id = m.group_id
         WHERE g.tenant_id = :tid AND {$cqrModWhere}"
    );
    $stmtMods->execute([':tid' => $tenantId]);
    $modsByGroup = [];
    foreach ($stmtMods->fetchAll(PDO::FETCH_ASSOC) as $mod) {
        $modsByGroup[(int)$mod['group_id']][] = $mod;
    }

    // Modifier prices from sh_price_tiers (target_type='MODIFIER')
    $modPricesBySku = [];
    if ($hasPriceTiers) {
        $stmtModPrices = $pdo->prepare(
            "SELECT target_sku, channel, price
             FROM sh_price_tiers
             WHERE target_type = 'MODIFIER'
               AND (tenant_id = :tid OR tenant_id = 0)
             ORDER BY target_sku, tenant_id DESC"
        );
        $stmtModPrices->execute([':tid' => $tenantId]);
        foreach ($stmtModPrices->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $sku = $pr['target_sku'];
            $ch = $pr['channel'];
            if (!isset($modPricesBySku[$sku])) {
                $modPricesBySku[$sku] = [];
            }
            if (!isset($modPricesBySku[$sku][$ch])) {
                $modPricesBySku[$sku][$ch] = (float)$pr['price'];
            }
        }
    }

    // -------------------------------------------------------------------------
    // 7. BUILD OUTPUT — ChoiceQR menu schema
    // -------------------------------------------------------------------------
    $output = [];

    foreach ($categories as $cat) {
        $catId = (int)$cat['id'];
        $catItems = $itemsByCategory[$catId] ?? [];

        $catOutput = [
            'posID'  => 'cat_' . $catId,
            'name'   => $cat['name'],
            'active' => true,
            'items'  => [],
        ];

        foreach ($catItems as $item) {
            // Visibility already filtered in SQL via MenuVisibilityFilter::publicItemsWhere
            // (is_deleted=0, is_secret=0, publication_status IN Live/published, temporal).
            $sku = $item['ascii_key'];

            // Resolve price — prefer POS channel, then any channel, then modifier.price
            $pricePln = null;
            if (isset($pricesBySku[$sku])) {
                $pricePln = $pricesBySku[$sku]['POS']
                    ?? $pricesBySku[$sku]['Takeaway']
                    ?? $pricesBySku[$sku]['Delivery']
                    ?? null;
                if ($pricePln === null && count($pricesBySku[$sku]) > 0) {
                    $pricePln = reset($pricesBySku[$sku]);
                }
            }

            // Price in grosze (1/100)
            $priceGrosze = $pricePln !== null ? (int)round($pricePln * 100) : 0;

            // Allergens
            $allergens = [];
            if ($item['allergens_json'] !== null) {
                $decoded = json_decode($item['allergens_json'], true);
                if (is_array($decoded)) {
                    $allergens = array_values($decoded);
                }
            }

            // Media
            $media = null;
            if ($item['image_url'] !== null && $item['image_url'] !== '') {
                $media = ['url' => $item['image_url']];
            }

            // Menu labels (badge_type → ChoiceQR menuLabels)
            $menuLabels = [];
            if ($item['badge_type'] !== null && $item['badge_type'] !== '') {
                $menuLabels[] = ['type' => $item['badge_type']];
            }

            // Dish options (modifier groups)
            $dishOptions = [];
            $itemId = (int)$item['id'];
            $groupIds = $itemModGroups[$itemId] ?? [];
            foreach ($groupIds as $gid) {
                $gMeta = $groupMeta[$gid] ?? null;
                if ($gMeta === null || !$gMeta['active']) {
                    continue;
                }

                $groupMods = $modsByGroup[$gid] ?? [];
                $optionList = [];
                foreach ($groupMods as $mod) {
                    if ((int)$mod['is_active'] !== 1) {
                        continue;
                    }

                    $modSku = $mod['ascii_key'];
                    $modPricePln = null;
                    if (isset($modPricesBySku[$modSku])) {
                        $modPricePln = $modPricesBySku[$modSku]['POS']
                            ?? $modPricesBySku[$modSku]['Takeaway']
                            ?? $modPricesBySku[$modSku]['Delivery']
                            ?? null;
                        if ($modPricePln === null && count($modPricesBySku[$modSku]) > 0) {
                            $modPricePln = reset($modPricesBySku[$modSku]);
                        }
                    }
                    // Fallback to denormalized price column
                    if ($modPricePln === null && $mod['price'] !== null) {
                        $modPricePln = (float)$mod['price'];
                    }
                    $modPriceGrosze = $modPricePln !== null ? (int)round($modPricePln * 100) : 0;

                    $optionList[] = [
                        'posID'  => $modSku,
                        'name'   => $mod['name'],
                        'price'  => $modPriceGrosze,
                        'active' => true,
                    ];
                }

                if (count($optionList) === 0) {
                    continue;
                }

                $maxSel = $gMeta['max_selection'];
                $dishOptions[] = [
                    'posID'    => $gMeta['ascii_key'] ?? ('modgroup_' . $gid),
                    'name'     => $gMeta['name'],
                    'type'     => $maxSel > 1 ? 'multiple' : 'single',
                    'active'   => true,
                    'required' => $gMeta['min_selection'] > 0,
                    'list'     => $optionList,
                ];
            }

            $itemOutput = [
                'posID'       => $sku,
                'name'        => $item['name'],
                'price'       => $priceGrosze,
                'description' => $item['description'] ?? '',
                'active'      => true,
                'vat'         => (float)$item['vat_rate_dine_in'],
            ];

            if (count($allergens) > 0) {
                $itemOutput['allergens'] = $allergens;
            }
            if ($media !== null) {
                $itemOutput['media'] = $media;
            }
            if (count($menuLabels) > 0) {
                $itemOutput['menuLabels'] = $menuLabels;
            }
            if (count($dishOptions) > 0) {
                $itemOutput['dishOptions'] = $dishOptions;
            }

            $catOutput['items'][] = $itemOutput;
        }

        $output[] = $catOutput;
    }

    // -------------------------------------------------------------------------
    // 8. OUTPUT
    // -------------------------------------------------------------------------
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[ChoiceQR Menu] FATAL: ' . $e->getMessage());
    cqr_menu_error(500, 'Internal server error');
}
