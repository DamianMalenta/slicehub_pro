<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Online Storefront Engine
// api/online/engine.php
//
// Unified REST API (POST, action-based) for the customer-facing storefront.
// PUBLIC endpoint: no session/JWT required. Tenant scope comes from POST body
// (`tenantId`) — storefront serves anonymous customers.
//
// Actions:
//   get_storefront_settings : surface_bg, half_half_surcharge, brand, online flags
//   get_menu                : full menu grouped by categories (accordion, legacy)
//   get_dish                : single dish payload — mods, companions, visual layers, assets (legacy)
//   get_popular             : top SKUs from last 30 days (homepage carousel)
//   cart_calculate          : server-authoritative cart price validation (CartEngine)
//   delivery_zones          : check if delivery address is in service area
//   init_checkout           : create idempotency lock_token (TTL 5min) before checkout
//   track_order             : guest order status + ETA + driver GPS (token + phone match)
//   ── Interaction Contract v1 (Faza 3.0, dla The Table) ──
//   get_scene_menu          : kategorie + items z mini-scene-contract (composition_profile,
//                             hero_url, active_style, has_scene) — SceneResolver::batchResolveForCategory
//   get_scene_dish          : pełny Scene Contract dania (SceneResolver::resolveDishVisualContract)
//                             + price + modifier_groups + companions (Surface Card / detail view)
//   get_scene_category      : scena kategorii dla layout_mode grouped/hybrid
//                             (SceneResolver::resolveCategoryScene) + items z ceną
//
// Conventions (Constitution):
//   - Tenant_id barrier in every query (Multi-Tenancy)
//   - Channel-aware pricing via sh_price_tiers (Pricing Matrix Law)
//   - JSON envelope: { success, data, message }
//   - Schema detection (defensive — graceful skip if optional table missing)
// =============================================================================

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

function onlineResponse(bool $ok, $data = null, ?string $msg = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function inputStr(array $input, string $key, string $default = ''): string
{
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

function inputInt(array $input, string $key, int $default = 0): int
{
    return (int)($input[$key] ?? $default);
}

function safeChannel(string $ch): string
{
    $ch = preg_replace('/[^a-zA-Z0-9_]/', '', $ch) ?: 'Delivery';
    return $ch;
}

function safeSku(string $sku): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $sku);
}

/** Ścieżki zapisane w Studio (menu / warstwy) → URL zrozumiały dla storefrontu (/slicehub/…). */
function normalizePublicAssetUrl(?string $url): ?string
{
    if ($url === null) {
        return null;
    }
    $u = trim($url);
    if ($u === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    if (strpos($u, '/slicehub/') === 0 || $u === '/slicehub') {
        return $u;
    }
    if (isset($u[0]) && $u[0] === '/') {
        return '/slicehub' . $u;
    }

    return '/slicehub/' . ltrim($u, '/');
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/AssetResolver.php';
    if (!isset($pdo)) {
        onlineResponse(false, null, 'Database connection failed.');
    }

    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw ?: '{}', true) ?? [];
    $action = inputStr($input, 'action');

    // -------------------------------------------------------------------------
    // Tenant scope (PUBLIC — comes from request, not session)
    // -------------------------------------------------------------------------
    $tenantId = max(0, inputInt($input, 'tenantId'));
    if ($tenantId <= 0) {
        onlineResponse(false, null, 'tenantId is required.');
    }

    $stmtT = $pdo->prepare("SELECT id, name FROM sh_tenant WHERE id = ? LIMIT 1");
    $stmtT->execute([$tenantId]);
    $tenantRow = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$tenantRow) {
        onlineResponse(false, null, 'Invalid tenant.');
    }

    $channel = safeChannel(inputStr($input, 'channel', 'Delivery'));

    // -------------------------------------------------------------------------
    // Schema detection (defensive — optional tables may not exist on old DBs)
    // -------------------------------------------------------------------------
    $hasGlobalAssets   = false;
    $hasVisualLayers   = false;
    $hasBoardCompanions= false;
    $hasTenantSettings = false;
    $hasPriceTiers     = false;
    $hasVlCalibration  = false;
    $hasVlHero         = false;
    $hasVlOffset       = false;
    $hasBcHero         = false;

    $hasOrderLines      = false;
    $hasDriverLocations = false;
    $hasDeliveryZones   = false;
    $hasCheckoutLocks   = false;
    $hasOrdersTracking  = false;

    try { $pdo->query("SELECT 1 FROM sh_global_assets LIMIT 0");    $hasGlobalAssets = true; }    catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_visual_layers LIMIT 0");    $hasVisualLayers = true; }    catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_board_companions LIMIT 0"); $hasBoardCompanions = true; } catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_tenant_settings LIMIT 0");  $hasTenantSettings = true; }  catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_price_tiers LIMIT 0");      $hasPriceTiers = true; }      catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_order_lines LIMIT 0");      $hasOrderLines = true; }      catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0"); $hasDriverLocations = true; } catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_delivery_zones LIMIT 0");   $hasDeliveryZones = true; }   catch (\PDOException $e) {}
    try { $pdo->query("SELECT 1 FROM sh_checkout_locks LIMIT 0");   $hasCheckoutLocks = true; }   catch (\PDOException $e) {}
    if ($hasVisualLayers) {
        try { $pdo->query("SELECT cal_scale FROM sh_visual_layers LIMIT 0"); $hasVlCalibration = true; } catch (\PDOException $e) {}
        try { $pdo->query("SELECT product_filename FROM sh_visual_layers LIMIT 0"); $hasVlHero = true; } catch (\PDOException $e) {}
        try { $pdo->query("SELECT offset_x FROM sh_visual_layers LIMIT 0"); $hasVlOffset = true; } catch (\PDOException $e) {}
    }
    if ($hasBoardCompanions) {
        try { $pdo->query("SELECT product_filename FROM sh_board_companions LIMIT 0"); $hasBcHero = true; } catch (\PDOException $e) {}
    }
    try { $pdo->query("SELECT tracking_token FROM sh_orders LIMIT 0"); $hasOrdersTracking = true; } catch (\PDOException $e) {}
    $hasAtelierScenes = false;
    try { $pdo->query("SELECT 1 FROM sh_atelier_scenes LIMIT 0"); $hasAtelierScenes = true; } catch (\PDOException $e) {}

    // -------------------------------------------------------------------------
    // Helper: tenant setting (single key)
    // -------------------------------------------------------------------------
    $getTenantSetting = function (string $key, $default = null) use ($pdo, $tenantId, $hasTenantSettings) {
        if (!$hasTenantSettings) return $default;
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key = :k LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row && $row['setting_value'] !== null && $row['setting_value'] !== '')
                ? $row['setting_value'] : $default;
        } catch (\PDOException $e) {
            return $default;
        }
    };

    // -------------------------------------------------------------------------
    // Helper: resolve price for a list of SKUs (channel-aware, POS fallback)
    // Returns: [sku => ['price' => float, 'channel' => string, 'fallback' => bool]]
    // -------------------------------------------------------------------------
    $resolvePrices = function (array $skus, string $targetType) use ($pdo, $tenantId, $channel, $hasPriceTiers) {
        if (!$hasPriceTiers || empty($skus)) return [];
        $skus = array_values(array_unique($skus));
        $ph = implode(',', array_fill(0, count($skus), '?'));
        $sql = "SELECT target_sku, price, channel FROM sh_price_tiers
                WHERE target_type = ? AND target_sku IN ({$ph})
                  AND channel IN (?, 'POS')
                  AND (tenant_id = ? OR tenant_id = 0)
                ORDER BY (channel = ?) DESC, tenant_id DESC";
        $params = array_merge([$targetType], $skus, [$channel, $tenantId, $channel]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($out[$row['target_sku']])) continue;
            $out[$row['target_sku']] = [
                'price'    => (float)$row['price'],
                'channel'  => $row['channel'],
                'fallback' => $row['channel'] !== $channel,
            ];
        }
        return $out;
    };

    /**
     * Warstwy wizualne dla dania — ta sama logika co w get_dish (sh_visual_layers + nadpisanie z Director / sh_atelier_scenes).
     */
    $resolveVisualLayersForSku = function (string $itemSku) use (
        $pdo,
        $tenantId,
        $hasVisualLayers,
        $hasGlobalAssets,
        $hasVlCalibration,
        $hasVlHero,
        $hasVlOffset,
        $hasAtelierScenes
    ): array {
        $visualLayers = [];
        if ($hasVisualLayers) {
            try {
                $extraCols = '';
                if ($hasVlHero) {
                    $extraCols .= ', vl.product_filename';
                }
                if ($hasVlCalibration) {
                    $extraCols .= ', vl.cal_scale, vl.cal_rotate';
                }
                if ($hasVlOffset) {
                    $extraCols .= ', vl.offset_x, vl.offset_y';
                }
                $gaJoin = $hasGlobalAssets
                    ? "LEFT JOIN sh_global_assets ga
                         ON ga.ascii_key = vl.layer_sku
                        AND (ga.tenant_id = 0 OR ga.tenant_id = vl.tenant_id)
                        AND ga.is_active = 1"
                    : '';
                $gaCols = $hasGlobalAssets ? ', ga.filename AS ga_filename, ga.id AS ga_id' : '';
                $stmtVL = $pdo->prepare(
                    "SELECT vl.layer_sku, vl.asset_filename, vl.z_index, vl.is_base
                            {$extraCols} {$gaCols}
                     FROM sh_visual_layers vl
                     {$gaJoin}
                     WHERE vl.tenant_id = :tid AND vl.item_sku = :sku AND vl.is_active = 1
                     ORDER BY vl.z_index ASC"
                );
                $stmtVL->execute([':tid' => $tenantId, ':sku' => $itemSku]);
                foreach ($stmtVL->fetchAll(PDO::FETCH_ASSOC) as $vl) {
                    $gaFile = $vl['ga_filename'] ?? null;
                    $assetUrl = $gaFile
                        ? ('/slicehub/uploads/global_assets/' . $gaFile)
                        : ('/slicehub/uploads/visual/' . $tenantId . '/' . $vl['asset_filename']);
                    $heroUrl = !empty($vl['product_filename'])
                        ? '/slicehub/uploads/visual/' . $tenantId . '/' . $vl['product_filename']
                        : null;
                    $visualLayers[] = [
                        'layerSku'        => $vl['layer_sku'],
                        'assetFilename'   => $vl['asset_filename'],
                        'assetUrl'        => normalizePublicAssetUrl($assetUrl),
                        'productFilename' => $vl['product_filename'] ?? null,
                        'heroUrl'         => $heroUrl ? normalizePublicAssetUrl($heroUrl) : null,
                        'zIndex'          => (int)$vl['z_index'],
                        'isBase'          => (bool)$vl['is_base'],
                        'calScale'        => isset($vl['cal_scale']) ? (float)$vl['cal_scale'] : 1.0,
                        'calRotate'       => isset($vl['cal_rotate']) ? (int)$vl['cal_rotate'] : 0,
                        'offsetX'         => isset($vl['offset_x']) ? (float)$vl['offset_x'] : 0.0,
                        'offsetY'         => isset($vl['offset_y']) ? (float)$vl['offset_y'] : 0.0,
                    ];
                }
            } catch (\PDOException $e) {
            }
        }

        if ($hasAtelierScenes) {
            try {
                $stA = $pdo->prepare(
                    'SELECT spec_json FROM sh_atelier_scenes WHERE tenant_id = :tid AND item_sku = :sku LIMIT 1'
                );
                $stA->execute([':tid' => $tenantId, ':sku' => $itemSku]);
                $rowA = $stA->fetch(PDO::FETCH_ASSOC);
                if ($rowA && !empty($rowA['spec_json'])) {
                    $spec = json_decode((string)$rowA['spec_json'], true);
                    $layers = (is_array($spec) && isset($spec['pizza']['layers']) && is_array($spec['pizza']['layers']))
                        ? $spec['pizza']['layers']
                        : [];
                    if (count($layers) > 0) {
                        $fromDirector = [];
                        foreach ($layers as $L) {
                            if (!is_array($L)) {
                                continue;
                            }
                            if (isset($L['visible']) && $L['visible'] === false) {
                                continue;
                            }
                            $aurl = normalizePublicAssetUrl($L['assetUrl'] ?? null);
                            if ($aurl === null || $aurl === '') {
                                continue;
                            }
                            $fromDirector[] = [
                                'layerSku'        => (string)($L['layerSku'] ?? ''),
                                'assetFilename'   => null,
                                'assetUrl'        => $aurl,
                                'productFilename' => null,
                                'heroUrl'         => null,
                                'zIndex'          => (int)($L['zIndex'] ?? 0),
                                'isBase'          => (bool)($L['isBase'] ?? false),
                                'calScale'        => isset($L['calScale']) ? (float)$L['calScale'] : 1.0,
                                'calRotate'       => isset($L['calRotate']) ? (int)$L['calRotate'] : 0,
                                'offsetX'         => isset($L['offsetX']) ? (float)$L['offsetX'] : 0.0,
                                'offsetY'         => isset($L['offsetY']) ? (float)$L['offsetY'] : 0.0,
                            ];
                        }
                        if (count($fromDirector) > 0) {
                            $visualLayers = $fromDirector;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return $visualLayers;
    };

    // =========================================================================
    // ACTION: get_storefront_settings
    // =========================================================================
    if ($action === 'get_storefront_settings') {
        $surfaceBg        = $getTenantSetting('storefront_surface_bg', null);
        $halfHalfSurcharge = (float)$getTenantSetting('half_half_surcharge', '2.00');

        onlineResponse(true, [
            'tenant' => [
                'id'   => (int)$tenantRow['id'],
                'name' => $tenantRow['name'],
            ],
            'channel'           => $channel,
            'surfaceBg'         => $surfaceBg,
            'halfHalfSurcharge' => $halfHalfSurcharge,
        ], 'OK');
    }

    // =========================================================================
    // ACTION: get_doorway  —  M4 · Scena Drzwi (hero entry)
    //
    // Zwraca komplet danych potrzebnych do wyrenderowania pierwszej sceny
    // sklepu (drzwi restauracji): marka, dane kontaktowe, godziny otwarcia,
    // status (open/closed/closing_soon), aktualny time-of-day bucket,
    // dostępne kanały i współrzędne mapy.
    //
    // Wszystkie pola wrażliwe na brak konfiguracji — zwracamy puste stringi
    // zamiast błędu, żeby storefront zawsze zdążył wyrenderować ilustrację.
    // =========================================================================
    if ($action === 'get_doorway') {
        // Brand & visual hooks
        $brandName    = (string)($tenantRow['name'] ?? 'SliceHub');
        $brandTagline = (string)$getTenantSetting('storefront_tagline', '');
        $surfaceBg    = $getTenantSetting('storefront_surface_bg', null);

        // Contact
        $address = (string)$getTenantSetting('storefront_address', '');
        $city    = (string)$getTenantSetting('storefront_city', '');
        $phone   = (string)$getTenantSetting('storefront_phone', '');
        $email   = (string)$getTenantSetting('storefront_email', '');
        $latRaw  = $getTenantSetting('storefront_lat', null);
        $lngRaw  = $getTenantSetting('storefront_lng', null);
        $lat = is_numeric($latRaw) ? (float)$latRaw : null;
        $lng = is_numeric($lngRaw) ? (float)$lngRaw : null;

        // Opening hours — kolumna opening_hours_json w sh_tenant_settings (setting_key='')
        $openingHoursRaw = null;
        if ($hasTenantSettings) {
            try {
                $stmtHours = $pdo->prepare(
                    "SELECT opening_hours_json FROM sh_tenant_settings
                     WHERE tenant_id = :tid AND setting_key = '' LIMIT 1"
                );
                $stmtHours->execute([':tid' => $tenantId]);
                $openingHoursRaw = $stmtHours->fetchColumn();
            } catch (\PDOException $e) { /* ignore */ }
        }
        $openingHours = [];
        if ($openingHoursRaw) {
            $decoded = json_decode((string)$openingHoursRaw, true);
            if (is_array($decoded)) $openingHours = $decoded;
        }

        // Status (open / closed / closing_soon) + najbliższy punkt otwarcia
        $tz   = new DateTimeZone('Europe/Warsaw');
        $now  = new DateTime('now', $tz);
        $dayEnNames = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $todayKey = strtolower($now->format('l'));
        $nowHHMM  = $now->format('H:i');

        $statusCode = 'open';           // 'open' | 'closing_soon' | 'closed'
        $statusLabel = 'Otwarte';
        $todayOpen  = null;
        $todayClose = null;
        $nextOpenAt = null;             // ISO string

        if (!empty($openingHours)) {
            $today = $openingHours[$todayKey] ?? null;
            if (is_array($today) && !empty($today['open']) && !empty($today['close'])) {
                $todayOpen  = (string)$today['open'];
                $todayClose = (string)$today['close'];
                if ($nowHHMM < $todayOpen) {
                    $statusCode  = 'closed';
                    $statusLabel = "Otwieramy dziś o {$todayOpen}";
                    $nextOpenAt  = $now->format('Y-m-d') . 'T' . $todayOpen . ':00';
                } elseif ($nowHHMM >= $todayClose) {
                    $statusCode  = 'closed';
                    $statusLabel = 'Zamknięte';
                } else {
                    // closing soon — w ciągu 30 min
                    $closeTs = strtotime($now->format('Y-m-d') . ' ' . $todayClose);
                    $nowTs   = $now->getTimestamp();
                    if ($closeTs - $nowTs <= 1800) {
                        $statusCode  = 'closing_soon';
                        $statusLabel = "Zamykamy o {$todayClose}";
                    } else {
                        $statusLabel = "Otwarte do {$todayClose}";
                    }
                }
            } else {
                $statusCode  = 'closed';
                $statusLabel = 'Zamknięte dziś';
            }

            if ($statusCode === 'closed' && $nextOpenAt === null) {
                $probe = clone $now;
                for ($i = 1; $i <= 7; $i++) {
                    $probe->modify('+1 day');
                    $k = strtolower($probe->format('l'));
                    $d = $openingHours[$k] ?? null;
                    if (is_array($d) && !empty($d['open'])) {
                        $nextOpenAt = $probe->format('Y-m-d') . 'T' . $d['open'] . ':00';
                        $statusLabel = "Otwieramy " . $probe->format('D') . ' ' . $d['open'];
                        break;
                    }
                }
            }
        }

        // Time-of-day bucket — do scenerii drzwi (dzień/wieczór/noc).
        $hour = (int)$now->format('G');
        if ($hour >= 5 && $hour < 11)      $timeOfDay = 'morning';
        elseif ($hour >= 11 && $hour < 17) $timeOfDay = 'day';
        elseif ($hour >= 17 && $hour < 21) $timeOfDay = 'evening';
        else                                $timeOfDay = 'night';

        // Kanały (delivery/takeaway/dine_in). Domyślnie delivery + takeaway.
        $channelsRaw = $getTenantSetting('storefront_channels_json', null);
        $channels = ['delivery' => true, 'takeaway' => true, 'dine_in' => false];
        if (is_string($channelsRaw) && $channelsRaw !== '') {
            $d = json_decode($channelsRaw, true);
            if (is_array($d)) $channels = array_merge($channels, $d);
        }

        // Pre-order toggle (nocny tryb z opcją zamówienia z wyprzedzeniem).
        $preOrderEnabled = ($getTenantSetting('storefront_preorder_enabled', '1') === '1');

        // Brand color (CSS --storefront-accent) — edytowalny w settings panel.
        $brandColor = (string)$getTenantSetting('storefront_brand_color', '#E8B04B');

        onlineResponse(true, [
            'tenant' => [
                'id'   => (int)$tenantRow['id'],
                'name' => $brandName,
                'tagline' => $brandTagline,
                'brandColor' => $brandColor,
            ],
            'contact' => [
                'address' => $address,
                'city'    => $city,
                'phone'   => $phone,
                'email'   => $email,
                'lat'     => $lat,
                'lng'     => $lng,
            ],
            'hours' => [
                'today_open'  => $todayOpen,
                'today_close' => $todayClose,
                'week'        => $openingHours,
            ],
            'status' => [
                'code'         => $statusCode,       // 'open' | 'closing_soon' | 'closed'
                'label'        => $statusLabel,
                'next_open_at' => $nextOpenAt,
            ],
            'channels'        => $channels,
            'timeOfDay'       => $timeOfDay,         // 'morning' | 'day' | 'evening' | 'night'
            'preOrderEnabled' => $preOrderEnabled,
            'surfaceBg'       => $surfaceBg,
            'serverTime'      => $now->format('c'),
        ], 'OK');
    }

    // =========================================================================
    // ACTION: get_menu — categories + items + prices (for accordion view)
    // =========================================================================
    if ($action === 'get_menu') {
        // Categories
        $stmtCats = $pdo->prepare(
            "SELECT id, name, display_order, is_menu
             FROM sh_categories
             WHERE tenant_id = :tid AND is_deleted = 0
             ORDER BY display_order ASC, name ASC"
        );
        $stmtCats->execute([':tid' => $tenantId]);
        $catRows = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

        // Items (active, non-deleted)
        $stmtItems = $pdo->prepare(
            "SELECT mi.ascii_key AS sku, mi.name, mi.description, mi.image_url,
                    mi.category_id, mi.display_order
             FROM sh_menu_items mi
             WHERE mi.tenant_id = :tid AND mi.is_active = 1 AND mi.is_deleted = 0
             ORDER BY mi.display_order ASC, mi.name ASC"
        );
        $stmtItems->execute([':tid' => $tenantId]);
        $itemRows = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Resolve prices for all items in one batch
        $allSkus = array_column($itemRows, 'sku');
        $priceMap = $resolvePrices($allSkus, 'ITEM');

        // G6 · Batch fetch atmospheric_effects per SKU (1 zapytanie dla całego menu)
        // M3 #4 · Camera preset per SKU (batched w tym samym SELECT — oszczędzamy round-trip)
        $effectsMap = [];
        $cameraMap  = [];
        try {
            $pdo->query("SELECT 1 FROM sh_atelier_scenes LIMIT 0");
            if (!empty($allSkus)) {
                $ph = implode(',', array_fill(0, count($allSkus), '?'));
                $stE = $pdo->prepare(
                    "SELECT item_sku, atmospheric_effects_enabled_json, active_camera_preset
                     FROM sh_atelier_scenes
                     WHERE tenant_id = ? AND item_sku IN ($ph)"
                );
                $stE->execute(array_merge([$tenantId], $allSkus));
                foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $er) {
                    $arr = json_decode((string)($er['atmospheric_effects_enabled_json'] ?? '[]'), true);
                    $effectsMap[(string)$er['item_sku']] = is_array($arr) ? array_values($arr) : [];
                    $cameraMap[(string)$er['item_sku']]  = $er['active_camera_preset'] ?: null;
                }
            }
        } catch (\PDOException $e) { /* m022 niezainstalowane — cicho */ }

        // Group items by category
        $itemsByCat = [];
        foreach ($itemRows as $mi) {
            $cid = (int)($mi['category_id'] ?? 0);
            if (!isset($itemsByCat[$cid])) $itemsByCat[$cid] = [];
            $p = $priceMap[$mi['sku']] ?? null;
            $itemsByCat[$cid][] = [
                'sku'                => $mi['sku'],
                'name'               => $mi['name'],
                'description'        => $mi['description'],
                'imageUrl'           => normalizePublicAssetUrl($mi['image_url'] ?? null),
                'visualLayers'       => $resolveVisualLayersForSku($mi['sku']),
                'atmosphericEffects' => $effectsMap[$mi['sku']] ?? [],
                'activeCamera'       => $cameraMap[$mi['sku']] ?? null,
                'price'              => $p ? $p['price'] : null,
                'priceFallback'      => $p ? $p['fallback'] : true,
            ];
        }

        // m021 Asset Studio override — jeśli w sh_asset_links jest aktywny hero
        // dla tego SKU, nadpisuje imageUrl (bez tego assety wgrane w Asset Studio
        // byłyby niewidoczne na storefront).
        foreach ($itemsByCat as $cid => &$list) {
            AssetResolver::injectHeros($pdo, $tenantId, $list, 'sku', 'imageUrl');
        }
        unset($list);

        // Assemble categories (only those with items)
        $categories = [];
        foreach ($catRows as $cat) {
            $cid = (int)$cat['id'];
            if (empty($itemsByCat[$cid])) continue;
            $categories[] = [
                'id'    => $cid,
                'name'  => $cat['name'],
                'isMenu'=> (bool)$cat['is_menu'],
                'items' => $itemsByCat[$cid],
            ];
        }

        // Items without category (orphans) — bucket "Inne"
        if (!empty($itemsByCat[0])) {
            $categories[] = ['id' => 0, 'name' => 'Inne', 'isMenu' => true, 'items' => $itemsByCat[0]];
        }

        onlineResponse(true, [
            'channel'    => $channel,
            'categories' => $categories,
            'totalItems' => count($itemRows),
        ], 'OK');
    }

    // =========================================================================
    // ACTION: get_dish — full Surface Card payload for one item
    // =========================================================================
    if ($action === 'get_dish') {
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            onlineResponse(false, null, 'itemSku is required.');
        }

        // Item metadata
        $stmtItem = $pdo->prepare(
            "SELECT mi.ascii_key, mi.name, mi.description, mi.image_url, mi.category_id
             FROM sh_menu_items mi
             WHERE mi.tenant_id = :tid AND mi.ascii_key = :sku
               AND mi.is_active = 1 AND mi.is_deleted = 0
             LIMIT 1"
        );
        $stmtItem->execute([':tid' => $tenantId, ':sku' => $itemSku]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            onlineResponse(false, null, "Nie znaleziono produktu: {$itemSku}");
        }

        // Item base price
        $itemPrice = $resolvePrices([$itemSku], 'ITEM')[$itemSku] ?? null;

        // Modifier groups + options
        $stmtMods = $pdo->prepare(
            "SELECT mg.id AS group_id, mg.name AS group_name, mg.ascii_key AS group_ascii_key,
                    mg.min_selection, mg.max_selection, mg.free_limit,
                    m.ascii_key AS mod_sku, m.name AS mod_name, m.is_default
             FROM sh_item_modifiers im
             JOIN sh_modifier_groups mg ON mg.id = im.group_id
                                        AND mg.tenant_id = :tid AND mg.is_active = 1 AND mg.is_deleted = 0
             JOIN sh_modifiers m ON m.group_id = mg.id AND m.is_active = 1 AND m.is_deleted = 0
             JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid2
             WHERE mi.ascii_key = :sku
             ORDER BY mg.name ASC, m.name ASC"
        );
        $stmtMods->execute([':tid' => $tenantId, ':tid2' => $tenantId, ':sku' => $itemSku]);
        $modRows = $stmtMods->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        $allModSkus = [];
        foreach ($modRows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'groupId'      => $gid,
                    'name'         => $r['group_name'],
                    'asciiKey'     => $r['group_ascii_key'],
                    'minSelection' => (int)$r['min_selection'],
                    'maxSelection' => (int)$r['max_selection'],
                    'freeLimit'    => (int)$r['free_limit'],
                    'options'      => [],
                ];
            }
            $groups[$gid]['options'][] = [
                'sku'       => $r['mod_sku'],
                'name'      => $r['mod_name'],
                'isDefault' => (bool)$r['is_default'],
                'price'     => null,
            ];
            $allModSkus[] = $r['mod_sku'];
        }

        // Resolve modifier prices in batch
        $modPrices = $resolvePrices($allModSkus, 'MODIFIER');
        foreach ($groups as &$g) {
            foreach ($g['options'] as &$o) {
                if (isset($modPrices[$o['sku']])) {
                    $o['price'] = $modPrices[$o['sku']]['price'];
                }
            }
            unset($o);
        }
        unset($g);

        // Companions (cross-sell) + their prices + hero photos
        $companions = [];
        if ($hasBoardCompanions) {
            try {
                $heroSelect = $hasBcHero ? ", bc.product_filename" : ", NULL AS product_filename";
                $stmtComp = $pdo->prepare(
                    "SELECT bc.companion_sku, bc.companion_type, bc.board_slot,
                            bc.asset_filename {$heroSelect},
                            mi.name, mi.image_url
                     FROM sh_board_companions bc
                     JOIN sh_menu_items mi ON mi.ascii_key = bc.companion_sku
                                           AND mi.tenant_id = bc.tenant_id
                                           AND mi.is_deleted = 0 AND mi.is_active = 1
                     WHERE bc.tenant_id = :tid AND bc.item_sku = :sku AND bc.is_active = 1
                     ORDER BY bc.display_order ASC"
                );
                $stmtComp->execute([':tid' => $tenantId, ':sku' => $itemSku]);
                $compRows = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

                $compPrices = $resolvePrices(array_column($compRows, 'companion_sku'), 'ITEM');
                foreach ($compRows as $cr) {
                    $assetUrl = !empty($cr['asset_filename'])
                        ? '/slicehub/uploads/visual/' . $tenantId . '/' . $cr['asset_filename']
                        : null;
                    $assetUrl = $assetUrl ? normalizePublicAssetUrl($assetUrl) : null;
                    $heroUrl = !empty($cr['product_filename'])
                        ? normalizePublicAssetUrl('/slicehub/uploads/visual/' . $tenantId . '/' . $cr['product_filename'])
                        : normalizePublicAssetUrl($cr['image_url'] ?? null);
                    $cp = $compPrices[$cr['companion_sku']] ?? null;
                    $companions[] = [
                        'sku'      => $cr['companion_sku'],
                        'name'     => $cr['name'],
                        'type'     => $cr['companion_type'],
                        'slot'     => (int)$cr['board_slot'],
                        'price'    => $cp ? $cp['price'] : null,
                        'assetUrl' => $assetUrl,
                        'heroUrl'  => $heroUrl,
                    ];
                }

                // m021 Asset Studio override — hero companions
                AssetResolver::injectHeros($pdo, $tenantId, $companions, 'sku', 'heroUrl');
            } catch (\PDOException $e) {
                // graceful skip
            }
        }

        // Visual layers (per-item) — wspólna ścieżka z karuzelą „popularne” i menu
        $visualLayers = $resolveVisualLayersForSku($itemSku);

        // Global photorealistic assets library (for fallback rendering)
        $globalAssets = [];
        if ($hasGlobalAssets) {
            try {
                $stmtGA = $pdo->prepare(
                    "SELECT ascii_key, category, sub_type, filename,
                            width, height, has_alpha, z_order
                     FROM sh_global_assets
                     WHERE (tenant_id = 0 OR tenant_id = :tid) AND is_active = 1
                     ORDER BY z_order ASC"
                );
                $stmtGA->execute([':tid' => $tenantId]);
                foreach ($stmtGA->fetchAll(PDO::FETCH_ASSOC) as $ga) {
                    $globalAssets[] = [
                        'asciiKey' => $ga['ascii_key'],
                        'category' => $ga['category'],
                        'subType'  => $ga['sub_type'],
                        'filename' => $ga['filename'],
                        'url'      => '/slicehub/uploads/global_assets/' . $ga['filename'],
                        'width'    => (int)$ga['width'],
                        'height'   => (int)$ga['height'],
                        'hasAlpha' => (bool)$ga['has_alpha'],
                        'zOrder'   => (int)$ga['z_order'],
                    ];
                }
            } catch (\PDOException $e) {}
        }

        // Half & Half surcharge from tenant settings
        $halfHalfSurcharge = (float)$getTenantSetting('half_half_surcharge', '2.00');

        // Surface background (the wooden plank / stone) from tenant settings
        $surfaceBg = (string)$getTenantSetting('storefront_surface_bg', '');
        $surfaceUrl = $surfaceBg ? ('/slicehub/uploads/global_assets/' . basename($surfaceBg)) : null;

        // m021 Asset Studio override — single item hero
        $resolvedHero = AssetResolver::resolveHero($pdo, $tenantId, $itemSku);
        $finalImageUrl = $resolvedHero['url']
            ?? normalizePublicAssetUrl($item['image_url'] ?? null);

        // m024 · Modifier visual slots — jedyne źródło prawdy (sh_asset_links + has_visual_impact).
        // Legacy Magic Dictionary (sh_modifier_visual_map) + `magicDict` usunięte w m025.
        require_once __DIR__ . '/../../core/SceneResolver.php';
        $modifierVisuals = SceneResolver::resolveModifierVisuals($pdo, $tenantId, $allModSkus);

        onlineResponse(true, [
            'channel'           => $channel,
            'halfHalfSurcharge' => $halfHalfSurcharge,
            'surfaceUrl'        => $surfaceUrl,
            'item' => [
                'sku'           => $item['ascii_key'],
                'name'          => $item['name'],
                'description'   => $item['description'],
                'imageUrl'      => $finalImageUrl,
                'categoryId'    => (int)($item['category_id'] ?? 0),
                'basePrice'     => $itemPrice ? $itemPrice['price'] : null,
                'priceFallback' => $itemPrice ? $itemPrice['fallback'] : true,
            ],
            'modifierGroups'  => array_values($groups),
            'companions'      => $companions,
            'visualLayers'    => $visualLayers,
            'globalAssets'    => $globalAssets,
            'modifierVisuals' => $modifierVisuals,
        ], 'OK');
    }

    // =========================================================================
    // ACTION: cart_calculate — server-authoritative price (delegates to CartEngine)
    // Implements Prawo Zera Zaufania: client preview is optimistic only.
    // CartEngine::calculate() is a static method — sig: (PDO, int tenantId, array input)
    // =========================================================================
    if ($action === 'cart_calculate') {
        $cartEnginePath = __DIR__ . '/../cart/CartEngine.php';
        if (!file_exists($cartEnginePath)) {
            onlineResponse(false, null, 'CartEngine niedostepny.');
        }
        require_once $cartEnginePath;

        if (!class_exists('CartEngine')) {
            onlineResponse(false, null, 'CartEngine class not found.');
        }

        // CartEngine expects channel in ['POS','Takeaway','Delivery']
        $cartChannel = in_array($channel, ['POS','Takeaway','Delivery'], true) ? $channel : 'Delivery';

        // CartEngine expects order_type in ['dine_in','takeaway','delivery']
        $orderType = strtolower(inputStr($input, 'order_type', 'delivery'));
        if (!in_array($orderType, ['dine_in','takeaway','delivery'], true)) {
            $orderType = 'delivery';
        }

        $lines     = $input['lines'] ?? [];
        $promoCode = inputStr($input, 'promo_code', '');

        if (!is_array($lines) || empty($lines)) {
            onlineResponse(false, null, 'Pusty koszyk — brak pozycji.');
        }

        try {
            $result = CartEngine::calculate($pdo, $tenantId, [
                'channel'    => $cartChannel,
                'order_type' => $orderType,
                'lines'      => $lines,
                'promo_code' => $promoCode,
            ]);
            onlineResponse(true, $result['response'], 'OK');
        } catch (\Throwable $e) {
            onlineResponse(false, null, 'Blad walidacji koszyka: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ACTION: get_popular — top SKUs from last 30 days (for homepage carousel)
    // Joins sh_orders + sh_order_lines, groups by item_sku, orders by qty desc.
    // Falls back to display_order if no order history.
    // =========================================================================
    if ($action === 'get_popular') {
        $limit = max(1, min(20, inputInt($input, 'limit', 8)));
        $items = [];

        if ($hasOrderLines) {
            try {
                $stmtPop = $pdo->prepare(
                    "SELECT ol.item_sku AS sku,
                            SUM(ol.quantity) AS total_qty,
                            COUNT(DISTINCT o.id) AS order_count
                     FROM sh_orders o
                     JOIN sh_order_lines ol ON ol.order_id = o.id
                     WHERE o.tenant_id = :tid
                       AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       AND o.status NOT IN ('cancelled','refunded')
                     GROUP BY ol.item_sku
                     ORDER BY total_qty DESC, order_count DESC
                     LIMIT {$limit}"
                );
                $stmtPop->execute([':tid' => $tenantId]);
                $rows = $stmtPop->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    $skus = array_column($rows, 'sku');
                    $ph   = implode(',', array_fill(0, count($skus), '?'));
                    $stmtMI = $pdo->prepare(
                        "SELECT mi.ascii_key AS sku, mi.name, mi.description, mi.image_url
                         FROM sh_menu_items mi
                         WHERE mi.tenant_id = ? AND mi.is_active = 1 AND mi.is_deleted = 0
                           AND mi.ascii_key IN ({$ph})"
                    );
                    $stmtMI->execute(array_merge([$tenantId], $skus));
                    $miMap = [];
                    foreach ($stmtMI->fetchAll(PDO::FETCH_ASSOC) as $mi) {
                        $miMap[$mi['sku']] = $mi;
                    }
                    $priceMap = $resolvePrices($skus, 'ITEM');

                    foreach ($rows as $r) {
                        $sku = $r['sku'];
                        if (!isset($miMap[$sku])) continue;
                        $p = $priceMap[$sku] ?? null;
                        $items[] = [
                            'sku'           => $sku,
                            'name'          => $miMap[$sku]['name'],
                            'description'   => $miMap[$sku]['description'],
                            'imageUrl'      => normalizePublicAssetUrl($miMap[$sku]['image_url'] ?? null),
                            'visualLayers'  => $resolveVisualLayersForSku($sku),
                            'price'         => $p ? $p['price'] : null,
                            'priceFallback' => $p ? $p['fallback'] : true,
                            'totalQty'      => (int)$r['total_qty'],
                            'orderCount'    => (int)$r['order_count'],
                        ];
                    }
                }
            } catch (\PDOException $e) {
                error_log('[OnlineEngine.get_popular] ' . $e->getMessage());
            }
        }

        // Fallback: top by display_order if no history
        if (empty($items)) {
            try {
                $stmtFB = $pdo->prepare(
                    "SELECT mi.ascii_key AS sku, mi.name, mi.description, mi.image_url
                     FROM sh_menu_items mi
                     WHERE mi.tenant_id = :tid AND mi.is_active = 1 AND mi.is_deleted = 0
                     ORDER BY mi.display_order ASC, mi.id ASC
                     LIMIT {$limit}"
                );
                $stmtFB->execute([':tid' => $tenantId]);
                $fbRows = $stmtFB->fetchAll(PDO::FETCH_ASSOC);
                $fbSkus = array_column($fbRows, 'sku');
                $priceMap = $resolvePrices($fbSkus, 'ITEM');
                foreach ($fbRows as $mi) {
                    $p = $priceMap[$mi['sku']] ?? null;
                    $items[] = [
                        'sku'           => $mi['sku'],
                        'name'          => $mi['name'],
                        'description'   => $mi['description'],
                        'imageUrl'      => normalizePublicAssetUrl($mi['image_url'] ?? null),
                        'visualLayers'  => $resolveVisualLayersForSku($mi['sku']),
                        'price'         => $p ? $p['price'] : null,
                        'priceFallback' => $p ? $p['fallback'] : true,
                        'totalQty'      => 0,
                        'orderCount'    => 0,
                    ];
                }
            } catch (\PDOException $e) {
                error_log('[OnlineEngine.get_popular fallback] ' . $e->getMessage());
            }
        }

        // m021 Asset Studio override — popular items hero
        AssetResolver::injectHeros($pdo, $tenantId, $items, 'sku', 'imageUrl');

        onlineResponse(true, [
            'channel' => $channel,
            'items'   => $items,
            'source'  => empty($items) ? 'empty' : ($items[0]['orderCount'] > 0 ? 'history' : 'fallback'),
        ], 'OK');
    }

    // =========================================================================
    // ACTION: delivery_zones — check if address (lat/lng) is in service area
    // Uses MySQL spatial: ST_Contains(zone_polygon, POINT(lng, lat))
    // Returns: in_zone, zone name, default ETA from tenant settings
    // =========================================================================
    if ($action === 'delivery_zones') {
        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;
        $address = inputStr($input, 'address');

        $defaultEta = (int)$getTenantSetting('online_default_eta_min', '30');
        $minOrder   = (float)$getTenantSetting('online_min_order_value', '0.00');

        if ($lat === null || $lng === null || $lat === 0.0 || $lng === 0.0) {
            onlineResponse(true, [
                'in_zone'      => null,
                'zone'         => null,
                'eta_min'      => $defaultEta,
                'min_order'    => $minOrder,
                'address'      => $address,
                'lat'          => $lat,
                'lng'          => $lng,
                'note'         => 'Brak geokodowania adresu — checkout przejdzie do manualnej weryfikacji strefy.',
            ], 'OK (address only)');
        }

        $inZone   = false;
        $zoneId   = null;
        $zoneName = null;

        if ($hasDeliveryZones) {
            try {
                // ST_Contains expects POINT(lng, lat) — note the order!
                $stmtZ = $pdo->prepare(
                    "SELECT id, name FROM sh_delivery_zones
                     WHERE tenant_id = :tid
                       AND ST_Contains(zone_polygon, ST_GeomFromText(:pt))
                     LIMIT 1"
                );
                $point = sprintf('POINT(%F %F)', $lng, $lat);
                $stmtZ->execute([':tid' => $tenantId, ':pt' => $point]);
                $row = $stmtZ->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $inZone   = true;
                    $zoneId   = (int)$row['id'];
                    $zoneName = $row['name'];
                }
            } catch (\PDOException $e) {
                error_log('[OnlineEngine.delivery_zones] ' . $e->getMessage());
                // Graceful: jeśli spatial nie działa (stare MySQL/MariaDB), zwracamy "unknown"
                onlineResponse(true, [
                    'in_zone'      => null,
                    'zone'         => null,
                    'eta_min'      => $defaultEta,
                    'min_order'    => $minOrder,
                    'address'      => $address,
                    'lat'          => $lat,
                    'lng'          => $lng,
                    'note'         => 'Geofencing niedostepne — zezwalamy na zamowienie z manualnem.',
                ], 'OK (no geofence)');
            }
        }

        onlineResponse(true, [
            'in_zone'   => $inZone,
            'zone'      => $zoneName ? ['id' => $zoneId, 'name' => $zoneName] : null,
            'eta_min'   => $defaultEta,
            'min_order' => $minOrder,
            'address'   => $address,
            'lat'       => $lat,
            'lng'       => $lng,
        ], $inZone ? 'OK — w strefie dostawy' : 'Adres poza strefa dostawy (mozesz zamowic odbior).');
    }

    // =========================================================================
    // ACTION: guest_checkout — atomic order creation for public storefront (Faza 5.1)
    //
    // Wymaga: lock_token z init_checkout (weryfikuje że koszyk nie został zmieniony)
    //         customer.phone (guest tracking)
    //         order_type (delivery → wymaga delivery.address)
    //
    // Flow:
    //   1. Walidacja lock_token (exists, not consumed, not expired)
    //   2. CartEngine::calculate — re-authoritative recalc
    //   3. Sprawdź że grand_total nadal == lock.grand_total_grosze
    //   4. BEGIN TRANSACTION
    //      a. Generate order_id (UUID), tracking_token (16 hex), order_number (seq)
    //      b. INSERT sh_orders (status='new', payment_status='to_pay', source='WWW')
    //      c. INSERT sh_order_lines z lines_raw
    //      d. INSERT sh_order_audit (NULL → 'new')
    //      e. UPDATE sh_checkout_locks consumed_at=NOW, consumed_order_id=...
    //      f. Bump sh_promo_codes.current_uses jeśli applied
    //   5. COMMIT, zwróć { order_id, order_number, tracking_token, grand_total }
    // =========================================================================
    if ($action === 'guest_checkout') {
        if (!$hasCheckoutLocks) {
            onlineResponse(false, null, 'Checkout niedostepny (brak migracji 017).');
        }
        if (!$hasOrdersTracking) {
            onlineResponse(false, null, 'Guest tracking niedostepny (brak tracking_token w sh_orders).');
        }

        require_once __DIR__ . '/../cart/CartEngine.php';
        if (!class_exists('CartEngine')) {
            onlineResponse(false, null, 'CartEngine class not found.');
        }

        $lockToken = inputStr($input, 'lock_token');
        if ($lockToken === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $lockToken)) {
            onlineResponse(false, null, 'Nieprawidlowy lock_token.');
        }

        $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
        $customerName    = trim((string)($customer['name']  ?? ''));
        $customerPhone   = trim((string)($customer['phone'] ?? ''));
        $customerEmail   = trim((string)($customer['email'] ?? ''));
        $smsConsent      = !empty($customer['sms_consent'])       ? 1 : 0;
        $marketingConsent = !empty($customer['marketing_consent']) ? 1 : 0;

        if ($customerPhone === '' || strlen($customerPhone) < 6) {
            onlineResponse(false, null, 'Wymagany numer telefonu (min. 6 znakow).');
        }

        $delivery = is_array($input['delivery'] ?? null) ? $input['delivery'] : [];
        $deliveryAddress = trim((string)($delivery['address']    ?? ''));
        $deliveryLat     = isset($delivery['lat']) ? (float)$delivery['lat'] : null;
        $deliveryLng     = isset($delivery['lng']) ? (float)$delivery['lng'] : null;
        $deliveryNotes   = trim((string)($delivery['notes']      ?? ''));
        $requestedTime   = trim((string)($input['requested_time'] ?? ''));

        $paymentMethod = strtolower(trim((string)($input['payment_method'] ?? 'cash_on_delivery')));
        $paymentAllowed = ['cash_on_delivery', 'card_on_delivery', 'online_transfer'];
        if (!in_array($paymentMethod, $paymentAllowed, true)) {
            $paymentMethod = 'cash_on_delivery';
        }

        $cartChannel = in_array($channel, ['POS','Takeaway','Delivery'], true) ? $channel : 'Delivery';
        $orderType   = strtolower(inputStr($input, 'order_type', 'delivery'));
        if (!in_array($orderType, ['dine_in','takeaway','delivery'], true)) {
            $orderType = 'delivery';
        }
        $checkoutWarehouse = trim((string)($input['warehouse_id'] ?? $getTenantSetting('orders_default_warehouse_id', 'MAIN')));
        if ($checkoutWarehouse === '') {
            $checkoutWarehouse = 'MAIN';
        }

        if ($orderType === 'delivery' && $deliveryAddress === '') {
            onlineResponse(false, null, 'Dostawa wymaga adresu.');
        }

        $lines     = $input['lines'] ?? [];
        $promoCode = inputStr($input, 'promo_code', '');
        if (!is_array($lines) || empty($lines)) {
            onlineResponse(false, null, 'Pusty koszyk.');
        }

        // 1. Verify lock_token
        try {
            $stmtLock = $pdo->prepare(
                "SELECT lock_token, tenant_id, cart_hash, grand_total_grosze,
                        channel, expires_at, consumed_at
                 FROM sh_checkout_locks
                 WHERE lock_token = :tok AND tenant_id = :tid LIMIT 1"
            );
            $stmtLock->execute([':tok' => $lockToken, ':tid' => $tenantId]);
            $lock = $stmtLock->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            onlineResponse(false, null, 'Blad weryfikacji koszyka.');
        }

        if (!$lock) {
            onlineResponse(false, null, 'Lock_token nie istnieje lub wygasl. Odswiez koszyk.');
        }
        if (!empty($lock['consumed_at'])) {
            onlineResponse(false, null, 'To zamowienie zostalo juz zlozone. Odswiez strone.');
        }
        if (strtotime($lock['expires_at']) < time()) {
            onlineResponse(false, null, 'Sesja zakupowa wygasla (5 min). Odswiez koszyk.');
        }

        // 2. Re-authoritative recalc
        try {
            $calc = CartEngine::calculate($pdo, $tenantId, [
                'channel'    => $cartChannel,
                'order_type' => $orderType,
                'lines'      => $lines,
                'promo_code' => $promoCode,
            ]);
        } catch (\Throwable $e) {
            onlineResponse(false, null, 'Blad walidacji koszyka: ' . $e->getMessage());
        }

        $grandTotalGrosze = (int)$calc['grand_total_grosze'];

        // 3. Race-condition check — kosztowny porownawczy hash kanonicznego cartu
        $canonical = json_encode([
            'channel'    => $cartChannel,
            'order_type' => $orderType,
            'lines'      => $lines,
            'promo_code' => $promoCode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cartHash = hash('sha256', (string)$canonical);
        if ($cartHash !== $lock['cart_hash']) {
            onlineResponse(false, null, 'Koszyk zmienil sie od init_checkout. Odswiez i sprobuj ponownie.');
        }
        if ($grandTotalGrosze !== (int)$lock['grand_total_grosze']) {
            onlineResponse(false, null, 'Cena koszyka zmienila sie. Odswiez koszyk.');
        }

        // 3.5. Warehouse preflight — stop online checkout if stock is already gone.
        $wzPath = __DIR__ . '/../../core/WzEngine.php';
        if (file_exists($wzPath)) {
            require_once $wzPath;
            if (class_exists('WzEngine')) {
                try {
                    $availability = WzEngine::checkAvailability(
                        $pdo,
                        $tenantId,
                        $checkoutWarehouse,
                        $calc['lines_raw'] ?? []
                    );
                    if (($availability['success'] ?? false) && ($availability['available'] ?? true) === false) {
                        onlineResponse(false, [
                            'shortages'    => $availability['shortages'] ?? [],
                            'warehouse_id' => $availability['warehouse_id'] ?? $checkoutWarehouse,
                        ], 'Brak stanu magazynowego dla czesci skladnikow. Odswiez koszyk lub zmien pozycje.');
                    }
                } catch (\Throwable $e) {
                    error_log('[GuestCheckout.availability] ' . $e->getMessage());
                }
            }
        }

        // 4. Atomic transaction
        $generateUuid = function (): string {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };
        $trackingToken = bin2hex(random_bytes(8)); // 16 hex chars

        try {
            $pdo->beginTransaction();

            // 4a. Atomic sequence
            $stmtSeq = $pdo->prepare(
                "INSERT INTO sh_order_sequences (tenant_id, date, seq)
                 VALUES (:tid, CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
            );
            $stmtSeq->execute([':tid' => $tenantId]);
            $seq = (int)$pdo->lastInsertId();
            $orderNumber = sprintf('WWW/%s/%04d', date('Ymd'), $seq);
            $orderId = $generateUuid();
            $nowTs = date('Y-m-d H:i:s');

            // 4b. Bump promo uses (if applicable)
            if (!empty($calc['applied_promo_code'])) {
                $stmtPromoInc = $pdo->prepare(
                    "UPDATE sh_promo_codes SET current_uses = current_uses + 1
                     WHERE code = :code AND tenant_id = :tid"
                );
                $stmtPromoInc->execute([':code' => $calc['applied_promo_code'], ':tid' => $tenantId]);
            }

            // 4c. Insert order header
            $stmtOrder = $pdo->prepare(
                "INSERT INTO sh_orders
                    (id, tenant_id, order_number, channel, order_type, source,
                     subtotal, discount_amount, delivery_fee, grand_total,
                     status, payment_status, payment_method,
                     loyalty_points_earned,
                     customer_name, customer_phone, customer_email,
                     sms_consent, marketing_consent,
                     tracking_token,
                     delivery_address, lat, lng,
                     promised_time, created_at)
                 VALUES
                    (:id, :tid, :num, :channel, :order_type, 'ONLINE',
                     :subtotal, :discount, :delivery, :grand,
                     'new', 'to_pay', :pmethod,
                     :points,
                     :cname, :cphone, :cemail,
                     :sms_consent, :marketing_consent,
                     :ttok,
                     :addr, :lat, :lng,
                     :promised, :now)"
            );
            $stmtOrder->execute([
                ':id'               => $orderId,
                ':tid'              => $tenantId,
                ':num'              => $orderNumber,
                ':channel'          => $calc['channel'],
                ':order_type'       => $calc['order_type'],
                ':subtotal'         => $calc['subtotal_grosze'],
                ':discount'         => $calc['discount_grosze'],
                ':delivery'         => $calc['delivery_fee_grosze'],
                ':grand'            => $grandTotalGrosze,
                ':pmethod'          => $paymentMethod,
                ':points'           => $calc['loyalty_points'],
                ':cname'            => $customerName !== '' ? $customerName : null,
                ':cphone'           => $customerPhone,
                ':cemail'           => $customerEmail !== '' ? $customerEmail : null,
                ':sms_consent'      => $smsConsent,
                ':marketing_consent'=> $marketingConsent,
                ':ttok'             => $trackingToken,
                ':addr'             => $deliveryAddress !== '' ? $deliveryAddress : null,
                ':lat'              => $deliveryLat,
                ':lng'              => $deliveryLng,
                ':promised'         => $requestedTime !== '' ? $requestedTime : null,
                ':now'              => $nowTs,
            ]);

            // 4d. Insert lines
            $stmtLine = $pdo->prepare(
                "INSERT INTO sh_order_lines
                    (id, order_id, item_sku, snapshot_name, unit_price,
                     quantity, line_total, vat_rate, vat_amount,
                     modifiers_json, removed_ingredients_json, comment)
                 VALUES
                    (:id, :oid, :sku, :name, :unit, :qty, :total,
                     :vrate, :vamt, :mods, :removed, :comment)"
            );
            foreach ($calc['lines_raw'] as $lr) {
                $stmtLine->execute([
                    ':id'      => $generateUuid(),
                    ':oid'     => $orderId,
                    ':sku'     => $lr['item_sku'],
                    ':name'    => $lr['snapshot_name'],
                    ':unit'    => $lr['unit_price_grosze'],
                    ':qty'     => $lr['quantity'],
                    ':total'   => $lr['line_total_grosze'],
                    ':vrate'   => $lr['vat_rate'],
                    ':vamt'    => $lr['vat_amount_grosze'],
                    ':mods'    => $lr['modifiers_json'],
                    ':removed' => $lr['removed_ingredients_json'],
                    ':comment' => !empty($deliveryNotes)
                        ? trim(($lr['comment'] ?? '') . ($lr['comment'] ? ' | ' : '') . 'Uwagi klienta: ' . $deliveryNotes)
                        : $lr['comment'],
                ]);
            }

            // 4e. Audit
            $stmtAudit = $pdo->prepare(
                "INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
                 VALUES (:oid, NULL, NULL, 'new', :now)"
            );
            $stmtAudit->execute([':oid' => $orderId, ':now' => $nowTs]);

            // 4f. Consume lock
            $stmtConsume = $pdo->prepare(
                "UPDATE sh_checkout_locks
                 SET consumed_at = NOW(), consumed_order_id = :oid
                 WHERE lock_token = :tok AND tenant_id = :tid"
            );
            $stmtConsume->execute([':oid' => $orderId, ':tok' => $lockToken, ':tid' => $tenantId]);

            // 4g. [m026] Publish order.created do transactional outbox.
            //      W TEJ SAMEJ transakcji — albo outbox + order razem, albo nic.
            $publisherPath = __DIR__ . '/../../core/OrderEventPublisher.php';
            if (file_exists($publisherPath)) {
                require_once $publisherPath;
                if (class_exists('OrderEventPublisher')) {
                    OrderEventPublisher::publishOrderLifecycle(
                        $pdo, $tenantId, 'order.created', $orderId,
                        [
                            'payment_method' => $paymentMethod,
                            'lock_token'     => $lockToken,
                            'requested_time' => $requestedTime ?: 'ASAP',
                        ],
                        ['source' => 'online', 'actorType' => 'guest', 'actorId' => $customerPhone]
                    );
                }
            }

            $pdo->commit();
        } catch (\Throwable $txErr) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[GuestCheckout] TX ERR: ' . $txErr->getMessage());
            onlineResponse(false, null, 'Blad podczas zapisu zamowienia. Sprobuj ponownie.');
        }

        $fmtMoney = fn(int $g): string => number_format($g / 100, 2, ',', '');

        onlineResponse(true, [
            'orderId'        => $orderId,
            'orderNumber'    => $orderNumber,
            'trackingToken'  => $trackingToken,
            'status'         => 'new',
            'paymentMethod'  => $paymentMethod,
            'paymentStatus'  => 'to_pay',
            'grandTotal'     => $fmtMoney($grandTotalGrosze),
            'grandTotalGrosze'=> $grandTotalGrosze,
            'loyaltyPointsEarned' => (int)$calc['loyalty_points'],
            'trackingUrl'    => '/slicehub/modules/online/track.html?tenant=' . $tenantId
                              . '&token=' . $trackingToken
                              . '&phone=' . rawurlencode($customerPhone),
            '_meta'          => [
                'contractVersion' => 1,
                'generator'       => 'guest_checkout',
            ],
        ], 'OK');
    }

    // =========================================================================
    // ACTION: init_checkout — create lock_token to prevent double-checkout
    // Validates cart, creates entry in sh_checkout_locks (TTL 5 min).
    // Client must include this token in subsequent POST /api/orders/checkout.php
    // =========================================================================
    if ($action === 'init_checkout') {
        if (!$hasCheckoutLocks) {
            onlineResponse(false, null, 'Idempotency niedostepne (uruchom migracje 017).');
        }

        $cartEnginePath = __DIR__ . '/../cart/CartEngine.php';
        if (!file_exists($cartEnginePath)) {
            onlineResponse(false, null, 'CartEngine niedostepny.');
        }
        require_once $cartEnginePath;
        if (!class_exists('CartEngine')) {
            onlineResponse(false, null, 'CartEngine class not found.');
        }

        $cartChannel = in_array($channel, ['POS','Takeaway','Delivery'], true) ? $channel : 'Delivery';
        $orderType   = strtolower(inputStr($input, 'order_type', 'delivery'));
        if (!in_array($orderType, ['dine_in','takeaway','delivery'], true)) {
            $orderType = 'delivery';
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || empty($lines)) {
            onlineResponse(false, null, 'Pusty koszyk.');
        }

        $promoCode     = inputStr($input, 'promo_code', '');
        $customerPhone = inputStr($input, 'customer_phone', '');

        try {
            // 1. Recalculate authoritatively
            $calc = CartEngine::calculate($pdo, $tenantId, [
                'channel'    => $cartChannel,
                'order_type' => $orderType,
                'lines'      => $lines,
                'promo_code' => $promoCode,
            ]);

            $grandTotalGrosze = (int)($calc['grand_total_grosze'] ?? 0);

            // 2. Canonicalize cart for hash (deterministic — sorted by sku)
            $canonical = json_encode([
                'channel'    => $cartChannel,
                'order_type' => $orderType,
                'lines'      => $lines,
                'promo_code' => $promoCode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $cartHash = hash('sha256', (string)$canonical);

            // 3. Check if recent unconsumed lock exists for same hash + phone (idempotent)
            $stmtChk = $pdo->prepare(
                "SELECT lock_token, grand_total_grosze, expires_at FROM sh_checkout_locks
                 WHERE tenant_id = :tid AND cart_hash = :hash
                   AND consumed_at IS NULL AND expires_at > NOW()
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmtChk->execute([':tid' => $tenantId, ':hash' => $cartHash]);
            $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                onlineResponse(true, [
                    'lock_token'         => $existing['lock_token'],
                    'grand_total_grosze' => (int)$existing['grand_total_grosze'],
                    'grand_total'        => round((int)$existing['grand_total_grosze'] / 100, 2),
                    'expires_at'         => $existing['expires_at'],
                    'response'           => $calc['response'] ?? null,
                    'reused'             => true,
                ], 'OK — lock reused');
            }

            // 4. Generate UUID v4 for new lock
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            $lockToken = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

            $stmtIns = $pdo->prepare(
                "INSERT INTO sh_checkout_locks
                    (lock_token, tenant_id, customer_phone, cart_hash,
                     grand_total_grosze, channel, expires_at)
                 VALUES (:tok, :tid, :phone, :hash, :total, :ch,
                     DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
            );
            $stmtIns->execute([
                ':tok'   => $lockToken,
                ':tid'   => $tenantId,
                ':phone' => $customerPhone !== '' ? $customerPhone : null,
                ':hash'  => $cartHash,
                ':total' => $grandTotalGrosze,
                ':ch'    => $cartChannel,
            ]);

            // 5. Best-effort cleanup of expired locks (soft GC, 1% chance)
            if (random_int(1, 100) === 1) {
                try { $pdo->exec("DELETE FROM sh_checkout_locks WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"); } catch (\PDOException $e) {}
            }

            onlineResponse(true, [
                'lock_token'         => $lockToken,
                'grand_total_grosze' => $grandTotalGrosze,
                'grand_total'        => round($grandTotalGrosze / 100, 2),
                'expires_at'         => date('Y-m-d H:i:s', time() + 300),
                'response'           => $calc['response'] ?? null,
                'reused'             => false,
            ], 'OK — lock created');
        } catch (\Throwable $e) {
            onlineResponse(false, null, 'Blad init_checkout: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ACTION: track_order — guest tracker (token + phone match)
    // Returns: status, ETA, driver GPS (if assigned).
    // Security: requires BOTH tracking_token AND matching customer_phone.
    // =========================================================================
    if ($action === 'track_order') {
        if (!$hasOrdersTracking) {
            onlineResponse(false, null, 'Tracker niedostepny (uruchom migracje 017).');
        }

        $token = inputStr($input, 'tracking_token');
        $phone = inputStr($input, 'customer_phone');

        if ($token === '' || $phone === '') {
            onlineResponse(false, null, 'Wymagane: tracking_token + customer_phone.');
        }

        // Detekcja czy mamy dodatkową kolumnę delivery_status (dodawana przez courses engine).
        $hasDeliveryStatus = false;
        try { $pdo->query("SELECT delivery_status FROM sh_orders LIMIT 0"); $hasDeliveryStatus = true; }
        catch (\PDOException $e) {}

        try {
            $extraCols = $hasDeliveryStatus ? ', delivery_status' : '';
            $stmtO = $pdo->prepare(
                "SELECT id, order_number, channel, order_type, status, payment_status,
                        grand_total, customer_name, customer_phone, delivery_address,
                        promised_time, driver_id, course_id, created_at, updated_at
                        {$extraCols}
                 FROM sh_orders
                 WHERE tenant_id = :tid AND tracking_token = :tok AND customer_phone = :phone
                 LIMIT 1"
            );
            $stmtO->execute([':tid' => $tenantId, ':tok' => $token, ':phone' => $phone]);
            $order = $stmtO->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                onlineResponse(false, null, 'Nie znaleziono zamowienia (sprawdz token + telefon).');
            }

            // Unified status: skleja status + delivery_status w jeden logiczny stan dla klienta.
            // Reguly:
            //   - 'pending' to legacy alias 'accepted' (POS transition może wciąż używać pending).
            //   - Jesli delivery_status='delivered' → logical 'completed'.
            //   - Jesli delivery_status='in_delivery' → logical 'in_delivery'.
            //   - 'cancelled' → terminal state z osobną obsługą w UI.
            //   - W przeciwnym razie: logical = status.
            $dStatus = $order['delivery_status'] ?? null;
            $rawStatus = (string)$order['status'];
            // Normalizacja: pending traktowany jak accepted (backward compat)
            $logicalStatus = ($rawStatus === 'pending') ? 'accepted' : $rawStatus;
            if ($dStatus === 'delivered') {
                $logicalStatus = 'completed';
            } elseif ($dStatus === 'in_delivery') {
                $logicalStatus = 'in_delivery';
            }

            // Anulowane zamówienie — specjalny terminal state
            $isCancelled = ($rawStatus === 'cancelled');

            // Status timeline (frontend renders progress bar from this)
            $completedLabel = $order['order_type'] === 'delivery' ? 'Dostarczone' : 'Odebrane';
            $stages = [
                ['key' => 'new',          'label' => 'Otrzymane',        'reached' => true],
                ['key' => 'accepted',     'label' => 'Zaakceptowane',    'reached' => false],
                ['key' => 'preparing',    'label' => 'Przygotowanie',    'reached' => false],
                ['key' => 'ready',        'label' => 'Gotowe',           'reached' => false],
                ['key' => 'in_delivery',  'label' => $order['order_type'] === 'delivery' ? 'W drodze' : 'Do odbioru', 'reached' => false],
                ['key' => 'completed',    'label' => $completedLabel,    'reached' => false],
            ];
            // Dla pickup/takeaway skip "in_delivery" stage (nie dotyczy).
            if ($order['order_type'] !== 'delivery') {
                $stages = array_values(array_filter($stages, fn($s) => $s['key'] !== 'in_delivery'));
            }
            $statusOrder = ['new'=>0,'accepted'=>1,'preparing'=>2,'ready'=>3,'in_delivery'=>4,'completed'=>5];
            $currentRank = $isCancelled ? -1 : ($statusOrder[$logicalStatus] ?? 0);
            foreach ($stages as &$s) {
                $s['reached'] = !$isCancelled && ($statusOrder[$s['key']] ?? 99) <= $currentRank;
            }
            unset($s);

            // Driver GPS if order is in_delivery and driver assigned
            $driverGps = null;
            $driverInfo = null;
            if ($hasDriverLocations && !empty($order['driver_id']) && $logicalStatus === 'in_delivery') {
                try {
                    $stmtD = $pdo->prepare(
                        "SELECT dl.lat, dl.lng, dl.heading, dl.speed_kmh, dl.updated_at,
                                u.first_name, u.last_name
                         FROM sh_driver_locations dl
                         LEFT JOIN sh_users u ON u.id = dl.driver_id
                         WHERE dl.tenant_id = :tid AND dl.driver_id = :did
                         LIMIT 1"
                    );
                    $stmtD->execute([':tid' => $tenantId, ':did' => $order['driver_id']]);
                    $d = $stmtD->fetch(PDO::FETCH_ASSOC);
                    if ($d) {
                        $driverGps = [
                            'lat'        => (float)$d['lat'],
                            'lng'        => (float)$d['lng'],
                            'heading'    => $d['heading'] !== null ? (int)$d['heading'] : null,
                            'speed_kmh'  => $d['speed_kmh'] !== null ? (float)$d['speed_kmh'] : null,
                            'updated_at' => $d['updated_at'],
                        ];
                        $driverName = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                        $driverInfo = ['name' => $driverName !== '' ? $driverName : 'Kierowca'];
                    }
                } catch (\PDOException $e) {}
            }

            // --- Items preview (2026-04-19 · Faza E) --------------------------
            // Track screen pokazuje miniaturę pierwszego dania (hero) + listę pozycji.
            // Tabela: sh_order_lines (MM022 stack). Limit 8 żeby nie wywalić payloadu.
            // UWAGA: sh_order_lines nie ma tenant_id — tenant filtering przez order_id.
            $items = [];
            $heroImage = null;
            if ($hasOrderLines) {
                try {
                    $stmtI = $pdo->prepare(
                        "SELECT ol.item_sku, ol.snapshot_name AS name, ol.quantity AS qty, mi.image_url
                         FROM sh_order_lines ol
                         LEFT JOIN sh_menu_items mi
                           ON mi.tenant_id = :tid AND mi.ascii_key = ol.item_sku
                         WHERE ol.order_id = :oid
                         ORDER BY ol.id ASC
                         LIMIT 8"
                    );
                    $stmtI->execute([':tid' => $tenantId, ':oid' => $order['id']]);
                    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $items[] = [
                            'sku'      => $row['item_sku'],
                            'name'     => $row['name'],
                            'qty'      => (int)$row['qty'],
                            'imageUrl' => $row['image_url'] ?: null,
                        ];
                        if ($heroImage === null && !empty($row['image_url'])) {
                            $heroImage = $row['image_url'];
                        }
                    }
                } catch (\PDOException $e) { /* brak order_lines — OK, items pozostaje pusta */ }
            }

            // --- Store (origin) coords dla mapy -------------------------------
            // Używamy tych samych kluczy co Faza D (storefront_settings_save).
            $storeCoords = null;
            try {
                $stLat = $pdo->prepare("SELECT setting_value FROM sh_tenant_settings WHERE tenant_id=:tid AND setting_key='storefront_lat' LIMIT 1");
                $stLat->execute([':tid' => $tenantId]);
                $slat = $stLat->fetchColumn();
                $stLng = $pdo->prepare("SELECT setting_value FROM sh_tenant_settings WHERE tenant_id=:tid AND setting_key='storefront_lng' LIMIT 1");
                $stLng->execute([':tid' => $tenantId]);
                $slng = $stLng->fetchColumn();
                if (is_numeric($slat) && is_numeric($slng)) {
                    $storeCoords = ['lat' => (float)$slat, 'lng' => (float)$slng];
                }
            } catch (\PDOException $e) {}

            // --- ETA seconds left (z promised_time) ---------------------------
            $etaSeconds = null;
            if (!empty($order['promised_time'])) {
                try {
                    $promised = new \DateTime((string)$order['promised_time']);
                    $now = new \DateTime('now');
                    $etaSeconds = $promised->getTimestamp() - $now->getTimestamp();
                } catch (\Throwable $e) {}
            }

            onlineResponse(true, [
                'order' => [
                    'id'              => $order['id'],
                    'orderNumber'     => $order['order_number'],
                    'status'          => $logicalStatus,
                    'rawStatus'       => $rawStatus,
                    'deliveryStatus'  => $dStatus,
                    'paymentStatus'   => $order['payment_status'],
                    'channel'         => $order['channel'],
                    'orderType'       => $order['order_type'],
                    'grandTotal'      => round((int)$order['grand_total'] / 100, 2),
                    'customerName'    => $order['customer_name'],
                    'deliveryAddress' => $order['delivery_address'],
                    'promisedTime'    => $order['promised_time'],
                    'etaSeconds'      => $etaSeconds,
                    'heroImage'       => $heroImage,
                    'createdAt'       => $order['created_at'],
                    'updatedAt'       => $order['updated_at'],
                ],
                'items'       => $items,
                'storeCoords' => $storeCoords,
                'stages'      => $stages,
                'driver'      => $driverInfo,
                'gps'         => $driverGps,
            ], 'OK');
        } catch (\Throwable $e) {
            onlineResponse(false, null, 'Blad track_order: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ACTION: get_scene_menu — Faza 3.0 Interaction Contract v1
    //
    // Zwraca kategorie + items WZBOGACONE o mini-scene-contract per pozycja
    // (composition_profile, hero_url, active_style_preset, has_scene).
    // NIE łamie istniejącego `get_menu`; to osobna akcja dla storefrontu The Table.
    // =========================================================================
    if ($action === 'get_scene_menu') {
        require_once __DIR__ . '/../../core/SceneResolver.php';

        $catsHave022 = false;
        try {
            $pdo->query("SELECT layout_mode, category_scene_id, default_composition_profile FROM sh_categories LIMIT 0");
            $catsHave022 = true;
        } catch (\PDOException $e) {}

        $catCols = $catsHave022
            ? "id, name, display_order, is_menu, layout_mode, default_composition_profile, category_scene_id"
            : "id, name, display_order, is_menu";

        $stmtCats = $pdo->prepare(
            "SELECT {$catCols}
             FROM sh_categories
             WHERE tenant_id = :tid AND is_deleted = 0
             ORDER BY display_order ASC, name ASC"
        );
        $stmtCats->execute([':tid' => $tenantId]);
        $catRows = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

        $categories = [];
        $totalItems = 0;
        foreach ($catRows as $cat) {
            $cid = (int)$cat['id'];
            $items = SceneResolver::batchResolveForCategory($pdo, $tenantId, $cid);
            if (empty($items)) continue;

            // Ceny kanałowo-zależne (channel-aware) przez wspólny helper
            $skus = array_column($items, 'sku');
            $priceMap = $resolvePrices($skus, 'ITEM');

            $enriched = array_map(function ($it) use ($priceMap) {
                $sku = (string)($it['sku'] ?? '');
                $p   = $priceMap[$sku] ?? null;
                $sm  = $it['scene_meta'] ?? [];
                return [
                    'sku'                => $sku,
                    'name'               => (string)($it['name'] ?? ''),
                    'description'        => $it['description'] ?? null,
                    'heroUrl'            => $it['hero_url'] ?? null,
                    'compositionProfile' => (string)($it['composition_profile'] ?? 'static_hero'),
                    'hasScene'           => !empty($sm['scene_id']),
                    'activeStyle'        => $sm['active_style'] ?? null,
                    // G6 · Living Scene — klucze atmospheric_effects dla storefrontu
                    'atmosphericEffects' => is_array($sm['atmospheric_effects'] ?? null)
                                              ? array_values($sm['atmospheric_effects'])
                                              : [],
                    // M3 #4 · Auto-perspective — active_camera_preset z sceny
                    'activeCamera'       => $sm['active_camera'] ?? null,
                    'price'              => $p ? $p['price'] : null,
                    'priceFallback'      => $p ? $p['fallback'] : true,
                ];
            }, $items);

            $totalItems += count($enriched);

            $categories[] = [
                'id'                        => $cid,
                'name'                      => (string)$cat['name'],
                'isMenu'                    => (bool)($cat['is_menu'] ?? 1),
                'layoutMode'                => (string)($cat['layout_mode'] ?? 'legacy_list'),
                'defaultCompositionProfile' => (string)($cat['default_composition_profile'] ?? 'static_hero'),
                'hasCategoryScene'          => !empty($cat['category_scene_id']),
                'items'                     => $enriched,
            ];
        }

        onlineResponse(true, [
            'tenantId'   => $tenantId,
            'channel'    => $channel,
            'categories' => $categories,
            'totalItems' => $totalItems,
            '_meta'      => [
                'contractVersion' => 1,
                'resolver'        => 'SceneResolver::batchResolveForCategory',
            ],
        ], 'OK');
    }

    // =========================================================================
    // ACTION: get_scene_dish — pełny Scene Contract dla jednego dania
    //
    // Zwraca output z SceneResolver::resolveDishVisualContract PLUS:
    //  - price (channel-aware)
    //  - modifier_groups (min/max/free + opcje z cenami)
    //  - companions (cross-sell z hero_url)
    //
    // To jest kontrakt dla Surface Card / The Table detail view.
    // =========================================================================
    if ($action === 'get_scene_dish') {
        require_once __DIR__ . '/../../core/SceneResolver.php';

        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            onlineResponse(false, null, 'itemSku is required.');
        }

        $contract = SceneResolver::resolveDishVisualContract($pdo, $tenantId, $itemSku, $channel);
        if (!$contract) {
            onlineResponse(false, null, "Nie znaleziono produktu: {$itemSku}");
        }

        $priceInfo = $resolvePrices([$itemSku], 'ITEM')[$itemSku] ?? null;

        // Modifier groups (mirror logiki z get_dish, ale w compact formacie)
        $stmtMods = $pdo->prepare(
            "SELECT mg.id AS group_id, mg.name AS group_name, mg.ascii_key AS group_ascii_key,
                    mg.min_selection, mg.max_selection, mg.free_limit,
                    m.ascii_key AS mod_sku, m.name AS mod_name, m.is_default
             FROM sh_item_modifiers im
             JOIN sh_modifier_groups mg ON mg.id = im.group_id
                                        AND mg.tenant_id = :tid AND mg.is_active = 1 AND mg.is_deleted = 0
             JOIN sh_modifiers m ON m.group_id = mg.id AND m.is_active = 1 AND m.is_deleted = 0
             JOIN sh_menu_items mi ON mi.id = im.item_id AND mi.tenant_id = :tid2
             WHERE mi.ascii_key = :sku
             ORDER BY mg.name ASC, m.name ASC"
        );
        $stmtMods->execute([':tid' => $tenantId, ':tid2' => $tenantId, ':sku' => $itemSku]);
        $modRows = $stmtMods->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        $allModSkus = [];
        foreach ($modRows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'groupId'      => $gid,
                    'name'         => $r['group_name'],
                    'asciiKey'     => $r['group_ascii_key'],
                    'minSelection' => (int)$r['min_selection'],
                    'maxSelection' => (int)$r['max_selection'],
                    'freeLimit'    => (int)$r['free_limit'],
                    'options'      => [],
                ];
            }
            $groups[$gid]['options'][] = [
                'sku'       => $r['mod_sku'],
                'name'      => $r['mod_name'],
                'isDefault' => (bool)$r['is_default'],
                'price'     => null,
            ];
            $allModSkus[] = $r['mod_sku'];
        }
        $modPrices = $resolvePrices($allModSkus, 'MODIFIER');
        foreach ($groups as &$g) {
            foreach ($g['options'] as &$o) {
                if (isset($modPrices[$o['sku']])) {
                    $o['price'] = $modPrices[$o['sku']]['price'];
                }
            }
            unset($o);
        }
        unset($g);

        // Companions (board cross-sell)
        $companions = [];
        if ($hasBoardCompanions) {
            try {
                $stmtComp = $pdo->prepare(
                    "SELECT bc.companion_sku, bc.companion_type, bc.board_slot,
                            bc.asset_filename, mi.name, mi.image_url
                     FROM sh_board_companions bc
                     JOIN sh_menu_items mi ON mi.ascii_key = bc.companion_sku
                                           AND mi.tenant_id = bc.tenant_id
                                           AND mi.is_deleted = 0 AND mi.is_active = 1
                     WHERE bc.tenant_id = :tid AND bc.host_item_sku = :sku AND bc.is_active = 1
                     ORDER BY bc.display_order ASC"
                );
                $stmtComp->execute([':tid' => $tenantId, ':sku' => $itemSku]);
                $rows = $stmtComp->fetchAll(PDO::FETCH_ASSOC);
                $compSkus = array_column($rows, 'companion_sku');
                $compPrices = $resolvePrices($compSkus, 'ITEM');
                foreach ($rows as $r) {
                    $sku2 = (string)$r['companion_sku'];
                    $companions[] = [
                        'sku'         => $sku2,
                        'name'        => $r['name'],
                        'type'        => $r['companion_type'],
                        'boardSlot'   => $r['board_slot'],
                        'heroUrl'     => normalizePublicAssetUrl($r['image_url'] ?? null),
                        'price'       => isset($compPrices[$sku2]) ? $compPrices[$sku2]['price'] : null,
                    ];
                }
                AssetResolver::injectHeros($pdo, $tenantId, $companions, 'sku', 'heroUrl');
            } catch (\Throwable $e) {}
        }

        // Pomocnicze pola do back-compatu ze starym `get_dish` (UI półki).
        $halfHalfSurcharge = (float)$getTenantSetting('half_half_surcharge', '2.00');
        $surfaceBg         = $getTenantSetting('storefront_surface_bg', null);
        $surfaceUrl        = $surfaceBg ? ('/slicehub/uploads/global_assets/' . basename($surfaceBg)) : null;

        // m024 · Modifier visual slots — jedyne źródło prawdy (sh_asset_links).
        // Legacy Magic Dictionary (sh_modifier_visual_map) + pole `magicDict` usunięte w m025.
        $modifierVisuals = SceneResolver::resolveModifierVisuals($pdo, $tenantId, $allModSkus);

        onlineResponse(true, [
            'tenantId'         => $tenantId,
            'channel'          => $channel,
            'sceneContract'    => $contract, // sku, name, hero_url, scene_spec, scene_meta, layers, promotions
            'price'            => $priceInfo ? $priceInfo['price'] : null,
            'priceFallback'    => $priceInfo ? $priceInfo['fallback'] : true,
            'modifierGroups'   => array_values($groups),
            'companions'       => $companions,
            'halfHalfSurcharge' => $halfHalfSurcharge,
            'surfaceUrl'       => normalizePublicAssetUrl($surfaceUrl),
            'modifierVisuals'  => $modifierVisuals,
            '_meta'            => [
                'contractVersion' => 1,
                'resolver'        => 'SceneResolver::resolveDishVisualContract',
            ],
        ], 'OK');
    }

    // =========================================================================
    // ACTION: get_scene_category — pełna scena kategorii (grouped/hybrid)
    //
    // Zwraca output `SceneResolver::resolveCategoryScene` + ceny per item.
    // Używa się dla layout_mode IN ('grouped','hybrid') gdy UI pokazuje
    // jedną wspólną scenę z placement dań (Category Table).
    // =========================================================================
    if ($action === 'get_scene_category') {
        require_once __DIR__ . '/../../core/SceneResolver.php';

        $categoryId = inputInt($input, 'categoryId');
        if ($categoryId <= 0) {
            onlineResponse(false, null, 'categoryId is required.');
        }

        $scene = SceneResolver::resolveCategoryScene($pdo, $tenantId, $categoryId);
        if (!$scene) {
            onlineResponse(false, null, "Nie znaleziono kategorii: {$categoryId}");
        }

        $items = $scene['items'] ?? [];
        $skus = array_column($items, 'sku');
        $priceMap = $resolvePrices($skus, 'ITEM');

        $enriched = array_map(function ($it) use ($priceMap) {
            $sku = (string)($it['sku'] ?? '');
            $p   = $priceMap[$sku] ?? null;
            $sm  = $it['scene_meta'] ?? [];
            return [
                'sku'                => $sku,
                'name'               => (string)($it['name'] ?? ''),
                'description'        => $it['description'] ?? null,
                'heroUrl'            => $it['hero_url'] ?? null,
                'compositionProfile' => (string)($it['composition_profile'] ?? 'static_hero'),
                'hasScene'           => !empty($sm['scene_id']),
                'activeStyle'        => $sm['active_style'] ?? null,
                // G6 · Living Scene — klucze atmospheric_effects (dla tile/card renderu)
                'atmosphericEffects' => is_array($sm['atmospheric_effects'] ?? null)
                                          ? array_values($sm['atmospheric_effects'])
                                          : [],
                // M3 #4 · Auto-perspective — active_camera_preset
                'activeCamera'       => $sm['active_camera'] ?? null,
                'price'              => $p ? $p['price'] : null,
                'priceFallback'      => $p ? $p['fallback'] : true,
            ];
        }, $items);

        onlineResponse(true, [
            'tenantId'                  => $tenantId,
            'channel'                   => $channel,
            'categoryId'                => (int)$scene['category_id'],
            'categoryName'              => (string)$scene['category_name'],
            'isMenu'                    => (bool)($scene['is_menu'] ?? true),
            'layoutMode'                => (string)$scene['layout_mode'],
            'defaultCompositionProfile' => (string)$scene['default_composition_profile'],
            'sceneSpec'                 => $scene['scene_spec'],
            'sceneMeta'                 => $scene['scene_meta'],
            'items'                     => $enriched,
            '_meta'                     => [
                'contractVersion' => 1,
                'resolver'        => 'SceneResolver::resolveCategoryScene',
            ],
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // Unknown action
    // -------------------------------------------------------------------------
    onlineResponse(false, null, "Nieznana akcja: {$action}");

} catch (\Throwable $e) {
    error_log('[OnlineEngine] ' . $e->getMessage());
    onlineResponse(false, null, 'Blad serwera: ' . $e->getMessage());
}
