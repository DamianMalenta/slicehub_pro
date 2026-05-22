<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/AssetResolver.php';

/**
 * Studio + Online — generuje pliki hero SVG, sh_assets, sh_asset_links,
 * opisy dań, composition_profile, proste sh_atelier_scenes (pizza).
 * Wymaga wcześniejszego menu + (opcjonalnie) seed_scene_kit dla tenant 0.
 */

function seed_dish_hero_svg(string $dishName, string $categoryLabel, string $colorHex): string
{
    $safeName = htmlspecialchars($dishName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $safeCat = htmlspecialchars($categoryLabel, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $c2 = seed_dish_darken($colorHex, 0.25);
    $c3 = seed_dish_lighten($colorHex, 0.2);
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$c3}"/>
      <stop offset="55%" stop-color="{$colorHex}"/>
      <stop offset="100%" stop-color="{$c2}"/>
    </linearGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="12" stdDeviation="18" flood-color="#000" flood-opacity="0.35"/>
    </filter>
  </defs>
  <rect width="100%" height="100%" fill="url(#bg)"/>
  <ellipse cx="400" cy="520" rx="280" ry="48" fill="rgba(0,0,0,0.18)"/>
  <circle cx="400" cy="380" r="220" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.25)" stroke-width="4" filter="url(#shadow)"/>
  <text x="400" y="360" text-anchor="middle" fill="rgba(255,255,255,0.95)" font-size="42" font-family="system-ui,sans-serif" font-weight="800">{$safeName}</text>
  <text x="400" y="410" text-anchor="middle" fill="rgba(255,255,255,0.55)" font-size="22" font-family="system-ui,sans-serif" font-weight="600" letter-spacing="3">{$safeCat}</text>
  <text x="400" y="740" text-anchor="middle" fill="rgba(0,0,0,0.25)" font-size="14" font-family="system-ui,sans-serif">SliceHub · demo hero</text>
</svg>
SVG;
}

function seed_dish_darken(string $hex, float $amount): string
{
    $r = max(0, (int)(hexdec(substr($hex, 1, 2)) * (1 - $amount)));
    $g = max(0, (int)(hexdec(substr($hex, 3, 2)) * (1 - $amount)));
    $b = max(0, (int)(hexdec(substr($hex, 5, 2)) * (1 - $amount)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function seed_dish_lighten(string $hex, float $amount): string
{
    $r = min(255, (int)(hexdec(substr($hex, 1, 2)) + (255 - hexdec(substr($hex, 1, 2))) * $amount));
    $g = min(255, (int)(hexdec(substr($hex, 3, 2)) + (255 - hexdec(substr($hex, 3, 2))) * $amount));
    $b = min(255, (int)(hexdec(substr($hex, 5, 2)) + (255 - hexdec(substr($hex, 5, 2))) * $amount));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** @return array<int, array{profile:string,label:string,color:string}> */
function seed_category_visual_map(): array
{
    return [
        1 => ['profile' => 'pizza_top_down', 'label' => 'Pizza', 'color' => '#c0392b'],
        2 => ['profile' => 'burger_three_quarter_placeholder', 'label' => 'Burger', 'color' => '#8b4513'],
        3 => ['profile' => 'pasta_bowl_placeholder', 'label' => 'Makarony', 'color' => '#d4a574'],
        4 => ['profile' => 'category_flat_table', 'label' => 'Sałatka', 'color' => '#27ae60'],
        5 => ['profile' => 'beverage_bottle_placeholder', 'label' => 'Napój', 'color' => '#2980b9'],
        6 => ['profile' => 'static_hero', 'label' => 'Dodatek', 'color' => '#f39c12'],
        7 => ['profile' => 'static_hero', 'label' => 'Deser', 'color' => '#9b59b6'],
        8 => ['profile' => 'static_hero', 'label' => 'Zestaw', 'color' => '#7f8c8d'],
    ];
}

/** @return array<string, string> */
function seed_dish_descriptions(): array
{
    return [
        'PIZZA_MARGHERITA' => 'Klasyczna Margherita — sos San Marzano, mozzarella fior di latte, świeża bazylia.',
        'PIZZA_PEPPERONI' => 'Pikantna pepperoni na cienkim cieście — bestseller na dowóz.',
        'PIZZA_CAPRICCIOSA' => 'Szynka parmeńska, pieczarki i oliwki — włoski klasyk.',
        'PIZZA_HAWAJSKA' => 'Słodko-słona hawajska z ananasem i szynką.',
        'PIZZA_4FORMAGGI' => 'Cztery sery: mozzarella, gorgonzola, parmezan, cheddar.',
        'PIZZA_DIAVOLA' => 'Ostra diavola z salami i chili.',
        'PIZZA_VEGETARIANA' => 'Warzywa sezonowe na sosie pomidorowym.',
        'PIZZA_BBQ_CHICKEN' => 'Kurczak BBQ, cebula i sos barbecue.',
        'PIZZA_PROSC_FUNGHI' => 'Prosciutto e funghi — delikatna i aromatyczna.',
        'PIZZA_CALZONE' => 'Zawijana pizza z nadzieniem — idealna na wynos.',
        'BURGER_CLASSIC' => 'Wołowina 180 g, sałata, pomidor, sos domowy.',
        'BURGER_CHEESE' => 'Podwójny cheddar i grillowany placek wołowy.',
        'BURGER_BBQ' => 'Burger z sosem BBQ i cebulą karmelizowaną.',
        'BURGER_CHICKEN' => 'Chrupiący filet z kurczaka w bułce brioche.',
        'BURGER_VEGGIE' => 'Kotlet warzywny z hummusem i rukolą.',
        'PASTA_BOLOGNESE' => 'Spaghetti z sosem bolognese duszonym 4 godziny.',
        'PASTA_CARBONARA' => 'Carbonara z guanciale i żółtkiem — przepis rzymski.',
        'PASTA_LASAGNE' => 'Lasagne z beszamelem i serem parmezan.',
        'SALAD_CAESAR' => 'Sałata Cezar z kurczakiem i grana padano.',
        'SALAD_GREEK' => 'Sałatka grecka z fetą i oliwkami.',
        'DRINK_COLA_05' => 'Coca-Cola 0,5 l — schłodzona.',
        'DRINK_SPRITE_05' => 'Sprite 0,5 l.',
        'DRINK_WATER_05' => 'Woda mineralna gazowana 0,5 l.',
        'DRINK_JUICE_ORANGE' => 'Sok pomarańczowy 100%.',
        'DRINK_BEER_TYSKIE' => 'Piwo Tyskie 0,5 l — tylko 18+.',
        'SIDE_FRIES' => 'Frytki belgijskie — chrupiące.',
        'SIDE_GARLIC_SAUCE' => 'Sos czosnkowy domowy.',
        'SIDE_ONION_RINGS' => 'Krążki cebulowe w panierce.',
        'SIDE_NUGGETS_6' => '6 nuggetsów z kurczaka z sosem.',
        'DESSERT_TIRAMISU' => 'Tiramisu na mascarpone i espresso.',
        'DESSERT_PANNA_COTTA' => 'Panna cotta z sosem owocowym.',
        'SET_LUNCH_PIZZA' => 'Zestaw lunch: pizza średnia + napój.',
        'SET_BURGER_COMBO' => 'Burger, frytki i napój w promocyjnej cenie.',
    ];
}

/**
 * Uruchamia seed_scene_kit.php (tenant 0 library). Zwraca skrócony komunikat.
 */
function seed_invoke_scene_kit(): string
{
    $script = dirname(__DIR__) . '/seed_scene_kit.php';
    if (!is_file($script)) {
        return 'brak seed_scene_kit.php';
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $code);
    if ($code !== 0) {
        return 'scene_kit exit=' . $code;
    }
    foreach ($lines as $line) {
        if (str_contains($line, 'Assets DB:')) {
            return trim($line);
        }
    }
    return 'scene_kit OK';
}

/**
 * Pełny pakiet wizualny dla Menu Studio + Online.
 */
function seed_apply_studio_visuals(PDO $pdo, int $tenantId): string
{
    if (!\AssetResolver::isReady($pdo)) {
        return 'Pominięto — brak sh_assets (migracja 021)';
    }

    $sceneKitMsg = seed_invoke_scene_kit();

    $catMap = seed_category_visual_map();
    $descriptions = seed_dish_descriptions();

    // Kategorie — composition + layout (m022)
    $hasCatProfile = false;
    try {
        $pdo->query('SELECT default_composition_profile FROM sh_categories LIMIT 0');
        $hasCatProfile = true;
    } catch (Throwable $e) {
    }
    if ($hasCatProfile) {
        $stmtCat = $pdo->prepare(
            'UPDATE sh_categories SET default_composition_profile = ?, layout_mode = ? WHERE id = ? AND tenant_id = ?'
        );
        $layoutByCat = [
            1 => 'hybrid',
            2 => 'individual',
            3 => 'individual',
            4 => 'legacy_list',
            5 => 'grouped',
            6 => 'legacy_list',
            7 => 'legacy_list',
            8 => 'legacy_list',
        ];
        foreach ($catMap as $catId => $meta) {
            $layout = $layoutByCat[$catId] ?? 'legacy_list';
            $stmtCat->execute([$meta['profile'], $layout, $catId, $tenantId]);
        }
    }

    // Modyfikatory — Live + opisy wizualne (link do assetów systemowych)
    try {
        $pdo->exec(
            "UPDATE sh_modifier_groups SET publication_status = 'Live'
             WHERE tenant_id = {$tenantId}
               AND (publication_status IS NULL OR publication_status = '' OR publication_status = 'published')"
        );
    } catch (Throwable $e) {
    }

    $modifierLayerMap = [
        'EXTRA_CHEESE' => 'prop_board_round',
        'EXTRA_JALAP' => 'prop_pepper_shaker',
        'EXTRA_OLIVES' => 'prop_basil_leaf',
        'EXTRA_HAM' => 'prop_knife_silver',
        'SAUCE_GARLIC' => 'prop_bottle_oil',
        'SAUCE_BBQ' => 'prop_bottle_tabasco',
        'SAUCE_HOT' => 'prop_candle_glass',
    ];

    $stmtSysAsset = $pdo->prepare(
        'SELECT id FROM sh_assets WHERE tenant_id = 0 AND ascii_key = ? AND is_active = 1 LIMIT 1'
    );
    $stmtFindLink = $pdo->prepare(
        "SELECT id FROM sh_asset_links
         WHERE tenant_id = ? AND entity_type = 'modifier' AND entity_ref = ? AND role = 'layer_top_down'
         LIMIT 1"
    );
    $stmtReactivateLink = $pdo->prepare(
        "UPDATE sh_asset_links SET asset_id = ?, is_active = 1, deleted_at = NULL, sort_order = 0
         WHERE id = ?"
    );
    $stmtInsLink = $pdo->prepare(
        "INSERT INTO sh_asset_links (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_by_user)
         VALUES (?, ?, 'modifier', ?, 'layer_top_down', 0, 1, 'seed_demo')"
    );

    $modLinks = 0;
    foreach ($modifierLayerMap as $modSku => $propKey) {
        $stmtSysAsset->execute([$propKey]);
        $assetId = $stmtSysAsset->fetchColumn();
        if (!$assetId) {
            continue;
        }
        $assetId = (int)$assetId;
        $stmtFindLink->execute([$tenantId, $modSku]);
        $linkId = $stmtFindLink->fetchColumn();
        if ($linkId) {
            $stmtReactivateLink->execute([$assetId, (int)$linkId]);
        } else {
            $stmtInsLink->execute([$tenantId, $assetId, $modSku]);
        }
        $modLinks++;
    }

    // Storefront — kanały online
    try {
        $channels = json_encode(['delivery' => true, 'takeaway' => true, 'dine_in' => false], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
             VALUES (?, 'storefront_channels_json', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$tenantId, $channels]);
    } catch (Throwable $e) {
    }

    $heroDir = dirname(__DIR__, 2) . '/uploads/assets/' . $tenantId . '/hero';
    if (!is_dir($heroDir)) {
        mkdir($heroDir, 0755, true);
    }

    $stmtItems = $pdo->prepare(
        'SELECT id, ascii_key, name, category_id FROM sh_menu_items
         WHERE tenant_id = ? AND is_deleted = 0 ORDER BY display_order ASC'
    );
    $stmtItems->execute([$tenantId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $hasCp = false;
    try {
        $pdo->query('SELECT composition_profile FROM sh_menu_items LIMIT 0');
        $hasCp = true;
    } catch (Throwable $e) {
    }

    $hasDesc = true;
    try {
        $pdo->query('SELECT description FROM sh_menu_items LIMIT 0');
    } catch (Throwable $e) {
        $hasDesc = false;
    }

    $sets = ['image_url = ?'];
    if ($hasDesc) {
        $sets[] = 'description = ?';
    }
    if ($hasCp) {
        $sets[] = 'composition_profile = ?';
    }
    $stmtUpItem = $pdo->prepare(
        'UPDATE sh_menu_items SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND ascii_key = ?'
    );

    $stmtChkAsset = $pdo->prepare(
        'SELECT id FROM sh_assets WHERE tenant_id = ? AND ascii_key = ? LIMIT 1'
    );
    $stmtInsAsset = $pdo->prepare(
        "INSERT INTO sh_assets
            (tenant_id, ascii_key, storage_url, storage_bucket, mime_type, width_px, height_px,
             filesize_bytes, has_alpha, checksum_sha256, role_hint, category, is_active, created_by_user)
         VALUES (?, ?, ?, 'hero', 'image/svg+xml', 800, 800, ?, 1, ?, 'hero', 'hero', 1, 'seed_demo')"
    );
    $stmtUpAsset = $pdo->prepare(
        'UPDATE sh_assets SET storage_url = ?, filesize_bytes = ?, checksum_sha256 = ?, is_active = 1, deleted_at = NULL
         WHERE tenant_id = ? AND ascii_key = ?'
    );
    $stmtFindHeroLink = $pdo->prepare(
        "SELECT id FROM sh_asset_links
         WHERE tenant_id = ? AND entity_type = 'menu_item' AND entity_ref = ? AND role = 'hero'
         LIMIT 1"
    );
    $stmtReactivateHeroLink = $pdo->prepare(
        "UPDATE sh_asset_links SET asset_id = ?, is_active = 1, deleted_at = NULL, sort_order = 0 WHERE id = ?"
    );
    $stmtInsHeroLink = $pdo->prepare(
        "INSERT INTO sh_asset_links (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_by_user)
         VALUES (?, ?, 'menu_item', ?, 'hero', 0, 1, 'seed_demo')"
    );

    $heroes = 0;
    $scenes = 0;
    $hasAtelier = false;
    try {
        $pdo->query('SELECT 1 FROM sh_atelier_scenes LIMIT 0');
        $hasAtelier = true;
    } catch (Throwable $e) {
    }

    $stmtSceneChk = $hasAtelier
        ? $pdo->prepare('SELECT id FROM sh_atelier_scenes WHERE tenant_id = ? AND item_sku = ? LIMIT 1')
        : null;
    $stmtSceneIns = $hasAtelier
        ? $pdo->prepare('INSERT INTO sh_atelier_scenes (tenant_id, item_sku, spec_json, version) VALUES (?, ?, ?, 1)')
        : null;
    $stmtSceneUp = $hasAtelier
        ? $pdo->prepare(
            'UPDATE sh_atelier_scenes SET spec_json = ?, version = version + 1 WHERE tenant_id = ? AND item_sku = ?'
        )
        : null;

    foreach ($items as $item) {
        $sku = (string)$item['ascii_key'];
        $catId = (int)$item['category_id'];
        $meta = $catMap[$catId] ?? ['profile' => 'static_hero', 'label' => 'Danie', 'color' => '#5c6bc0'];
        $filename = $sku . '.svg';
        $path = $heroDir . '/' . $filename;
        if (!is_file($path)) {
            file_put_contents(
                $path,
                seed_dish_hero_svg((string)$item['name'], $meta['label'], $meta['color'])
            );
        }
        $storageUrl = 'uploads/assets/' . $tenantId . '/hero/' . $filename;
        $publicUrl = '/slicehub/' . $storageUrl;
        $size = filesize($path) ?: 0;
        $checksum = hash_file('sha256', $path) ?: '';
        $heroAscii = 'HERO__' . $sku;

        $desc = $descriptions[$sku] ?? ('Demo: ' . $item['name']);
        $params = [$publicUrl];
        if ($hasDesc) {
            $params[] = $desc;
        }
        if ($hasCp) {
            $params[] = $meta['profile'];
        }
        $params[] = $tenantId;
        $params[] = $sku;
        $stmtUpItem->execute($params);

        $stmtChkAsset->execute([$tenantId, $heroAscii]);
        $existingAssetId = $stmtChkAsset->fetchColumn();
        if ($existingAssetId) {
            $stmtUpAsset->execute([$storageUrl, $size, $checksum, $tenantId, $heroAscii]);
            $assetId = (int)$existingAssetId;
        } else {
            $stmtInsAsset->execute([$tenantId, $heroAscii, $storageUrl, $size, $checksum]);
            $assetId = (int)$pdo->lastInsertId();
        }

        $stmtFindHeroLink->execute([$tenantId, $sku]);
        $heroLinkId = $stmtFindHeroLink->fetchColumn();
        if ($heroLinkId) {
            $stmtReactivateHeroLink->execute([$assetId, (int)$heroLinkId]);
        } else {
            $stmtInsHeroLink->execute([$tenantId, $assetId, $sku]);
        }
        $heroes++;

        if ($hasAtelier && $catId === 1 && $stmtSceneIns !== null && $stmtSceneUp !== null) {
            $specJson = json_encode([
                'pizza' => [
                    'layers' => [[
                        'layerSku' => 'BASE_' . $heroAscii,
                        'assetUrl' => $publicUrl,
                        'zIndex' => 0,
                        'isBase' => true,
                        'calScale' => 1.0,
                        'calRotate' => 0,
                        'offsetX' => 0.0,
                        'offsetY' => 0.0,
                        'visible' => true,
                        'source' => 'seed_demo',
                    ]],
                ],
                'meta' => ['generatedBy' => 'seed_demo', 'generatedAt' => gmdate('c')],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmtSceneChk->execute([$tenantId, $sku]);
            if ($stmtSceneChk->fetchColumn()) {
                $stmtSceneUp->execute([$specJson, $tenantId, $sku]);
            } else {
                $stmtSceneIns->execute([$tenantId, $sku, $specJson]);
            }
            $scenes++;
        }
    }

    // Meal packages — hero SVG
    $mealHeroes = 0;
    try {
        $pdo->query('SELECT image_url FROM sh_meal_packages LIMIT 0');
        $stmtMeals = $pdo->prepare(
            'SELECT ascii_key, name FROM sh_meal_packages WHERE tenant_id = ? AND is_deleted = 0'
        );
        $stmtMeals->execute([$tenantId]);
        while ($meal = $stmtMeals->fetch(PDO::FETCH_ASSOC)) {
            $mSku = (string)$meal['ascii_key'];
            $mFile = 'meal_' . $mSku . '.svg';
            $mPath = $heroDir . '/' . $mFile;
            if (!is_file($mPath)) {
                file_put_contents(
                    $mPath,
                    seed_dish_hero_svg((string)$meal['name'], 'Zestaw', '#7f8c8d')
                );
            }
            $mUrl = '/slicehub/uploads/assets/' . $tenantId . '/hero/' . $mFile;
            $pdo->prepare('UPDATE sh_meal_packages SET image_url = ? WHERE tenant_id = ? AND ascii_key = ?')
                ->execute([$mUrl, $tenantId, $mSku]);
            $mealHeroes++;
        }
    } catch (Throwable $e) {
    }

    return "{$heroes} hero + {$scenes} scen pizza, {$modLinks} warstw mod, {$mealHeroes} zestawów; {$sceneKitMsg}";
}
