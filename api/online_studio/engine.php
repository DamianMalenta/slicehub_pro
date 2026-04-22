<?php
declare(strict_types=1);

/**
 * SliceHub Enterprise — Online Studio (Manager Visual Composer) API
 * api/online_studio/engine.php
 *
 * POST JSON { "action": "...", ... }
 * Auth: session OR Bearer JWT (core/auth_guard.php) — owner | admin | manager
 *
 * Actions:
 *   library_list              — REMOVED 2026-04-19 (SSOT = api/assets/engine.php action=list)
 *   library_update            — metadata edit (id)
 *   library_delete            — soft-delete is_active=0 (optional force)
 *   composer_load_dish        — item + visual layers + companions + surface setting
 *   composer_save_layers      — upsert layers (+ optional replaceAll)
 *   composer_calibrate        — single layer cal_scale / cal_rotate
 *   composer_clone            — copy visual stack source → target dish
 *   composer_autofit_suggest  — avg calibration for same sub_type on dish
 *   companions_list           — sh_board_companions for item
 *   companions_save           — replace companions for item (transaction)
 *   surface_apply             — set storefront_surface_bg tenant setting
 *   preview_url               — iframe URL for live customer preview
 *   menu_set_product_image    — ustaw sh_menu_items.image_url z już wgranego pliku (ścieżka uploads)
 */

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

function studioResponse(bool $ok, $data = null, ?string $msg = null, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function inputStr(array $input, string $key, string $default = ''): string {
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

function inputInt(array $input, string $key, int $default = 0): int {
    if (!isset($input[$key])) {
        return $default;
    }
    return (int)$input[$key];
}

function inputBool(array $input, string $key, bool $default = false): bool {
    if (!isset($input[$key])) {
        return $default;
    }
    $v = $input[$key];
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

function safeSku(string $s): string {
    $s = trim($s);
    if ($s === '' || strlen($s) > 255) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_+\-]+$/', $s)) {
        return '';
    }
    return $s;
}

/** Filename only, safe for uploads (no path traversal). */
function safeImageBasename(string $name, array $allowedExt): string {
    $name = str_replace(["\0", '\\'], '', $name);
    $base = basename($name);
    if ($base === '' || str_contains($base, '..')) {
        return '';
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return '';
    }
    return $base;
}

/** Only local upload paths (no external URLs) for menu product card images. */
function isAllowedMenuImageUrl(string $url): bool
{
    if ($url === '' || strlen($url) > 512) {
        return false;
    }
    if (str_contains($url, '..') || str_contains($url, "\0")) {
        return false;
    }
    if (preg_match('#^/slicehub/uploads/global_assets/[A-Za-z0-9._-]+$#', $url)) {
        return true;
    }
    if (preg_match('#^/slicehub/uploads/visual/\d+/[A-Za-z0-9._-]+$#', $url)) {
        return true;
    }

    return false;
}

function detectBaseUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.+)/api/online_studio/#', $script, $m)) {
        return $proto . '://' . $host . $m[1];
    }
    return $proto . '://' . $host . '/slicehub';
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    $stmtRole = $pdo->prepare(
        "SELECT role FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 AND is_active = 1 LIMIT 1"
    );
    $stmtRole->execute([':uid' => $user_id, ':tid' => $tenant_id]);
    $roleRow = $stmtRole->fetch(PDO::FETCH_ASSOC);
    $role    = $roleRow ? strtolower((string)$roleRow['role']) : '';
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        studioResponse(false, null, 'Brak uprawnien do Online Studio (wymagany owner/admin/manager).');
    }

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true) ?? [];
    if (!is_array($input)) {
        studioResponse(false, null, 'Nieprawidlowy JSON.');
    }

    $action = inputStr($input, 'action');
    if ($action === '') {
        studioResponse(false, null, 'Brak parametru action.');
    }

    // --- Schema probes ---
    $hasGlobalAssets    = false;
    $hasVisualLayers    = false;
    $hasBoardCompanions = false;
    $hasTenantSettings  = false;
    $hasVlVersion       = false;
    $hasVlLibCat        = false;
    $hasVlHero          = false;
    $hasVlCal           = false;
    $hasVlOffset        = false;
    $hasBcHero          = false;

    try { $pdo->query('SELECT 1 FROM sh_global_assets LIMIT 0'); $hasGlobalAssets = true; } catch (\PDOException $e) {}
    try { $pdo->query('SELECT 1 FROM sh_visual_layers LIMIT 0'); $hasVisualLayers = true; } catch (\PDOException $e) {}
    try { $pdo->query('SELECT 1 FROM sh_board_companions LIMIT 0'); $hasBoardCompanions = true; } catch (\PDOException $e) {}
    try { $pdo->query('SELECT 1 FROM sh_tenant_settings LIMIT 0'); $hasTenantSettings = true; } catch (\PDOException $e) {}
    if ($hasVisualLayers) {
        try { $pdo->query('SELECT version FROM sh_visual_layers LIMIT 0'); $hasVlVersion = true; } catch (\PDOException $e) {}
        try { $pdo->query('SELECT library_category FROM sh_visual_layers LIMIT 0'); $hasVlLibCat = true; } catch (\PDOException $e) {}
        try { $pdo->query('SELECT product_filename FROM sh_visual_layers LIMIT 0'); $hasVlHero = true; } catch (\PDOException $e) {}
        try { $pdo->query('SELECT cal_scale FROM sh_visual_layers LIMIT 0'); $hasVlCal = true; } catch (\PDOException $e) {}
        try { $pdo->query('SELECT offset_x FROM sh_visual_layers LIMIT 0'); $hasVlOffset = true; } catch (\PDOException $e) {}
    }
    if ($hasBoardCompanions) {
        try { $pdo->query('SELECT product_filename FROM sh_board_companions LIMIT 0'); $hasBcHero = true; } catch (\PDOException $e) {}
    }

    $getSetting = function (string $key, ?string $default = null) use ($pdo, $tenant_id, $hasTenantSettings) {
        if (!$hasTenantSettings) {
            return $default;
        }
        try {
            $st = $pdo->prepare(
                'SELECT setting_value FROM sh_tenant_settings WHERE tenant_id = :tid AND setting_key = :k LIMIT 1'
            );
            $st->execute([':tid' => $tenant_id, ':k' => $key]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['setting_value'] !== null && $r['setting_value'] !== '') {
                return $r['setting_value'];
            }
        } catch (\PDOException $e) {
        }

        return $default;
    };

    $setSetting = function (string $key, string $value) use ($pdo, $tenant_id, $hasTenantSettings): bool {
        if (!$hasTenantSettings) {
            return false;
        }
        $st = $pdo->prepare(
            'INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
             VALUES (:tid, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $st->execute([':tid' => $tenant_id, ':k' => $key, ':v' => $value]);

        return true;
    };

    // -------------------------------------------------------------------------
    // whoami — minimal identity probe (used by boot / tenant badge)
    // -------------------------------------------------------------------------
    if ($action === 'whoami') {
        $who = [
            'tenantId' => $tenant_id,
            'userId'   => $user_id,
            'role'     => $role,
        ];
        try {
            $stT = $pdo->prepare('SELECT name FROM sh_tenant WHERE id = :tid LIMIT 1');
            $stT->execute([':tid' => $tenant_id]);
            $who['tenantName'] = (string)($stT->fetchColumn() ?: '');
        } catch (\PDOException $e) {
            $who['tenantName'] = '';
        }
        studioResponse(true, $who, 'OK');
    }

    // -------------------------------------------------------------------------
    // menu_list — list items + modifiers (for Composer / Companions dropdowns)
    // -------------------------------------------------------------------------
    if ($action === 'menu_list') {
        $rows = [];
        try {
            $sql = "SELECT mi.id, mi.ascii_key, mi.name, mi.description, mi.image_url,
                           mi.category_id, COALESCE(mc.name,'—') AS category_name,
                           mi.is_active, mi.display_order
                    FROM sh_menu_items mi
                    LEFT JOIN sh_categories mc ON mc.id = mi.category_id
                    WHERE mi.tenant_id = :tid AND mi.is_deleted = 0
                    ORDER BY mc.display_order ASC, mi.display_order ASC, mi.name ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':tid' => $tenant_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sku = (string)$r['ascii_key'];
                $rows[] = [
                    'id'            => (int)$r['id'],
                    'sku'           => $sku,
                    'name'          => $r['name'],
                    'description'   => $r['description'],
                    'imageUrl'      => $r['image_url'],
                    'categoryId'    => (int)($r['category_id'] ?? 0),
                    'categoryName'  => $r['category_name'],
                    'isActive'      => (bool)$r['is_active'],
                    'displayOrder'  => (int)$r['display_order'],
                    'isPizza'       => (stripos($sku, 'PIZZA') !== false),
                ];
            }
        } catch (\PDOException $e) {
            studioResponse(false, null, 'Blad odczytu menu: ' . $e->getMessage());
        }

        $mods = [];
        try {
            $pdo->query('SELECT 1 FROM sh_modifiers LIMIT 0');
            $stM = $pdo->prepare(
                'SELECT ascii_key, name, `type` FROM sh_modifiers
                 WHERE tenant_id = :tid AND is_deleted = 0
                 ORDER BY name ASC'
            );
            $stM->execute([':tid' => $tenant_id]);
            foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mods[] = [
                    'sku'  => $m['ascii_key'],
                    'name' => $m['name'],
                    'type' => $m['type'] ?? null,
                ];
            }
        } catch (\PDOException $e) {
        }

        studioResponse(true, ['items' => $rows, 'modifiers' => $mods], 'OK');
    }

    // -------------------------------------------------------------------------
    // menu_set_product_image — ustaw image_url z pliku już wgranym (biblioteka / visual)
    // -------------------------------------------------------------------------
    if ($action === 'menu_set_product_image') {
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        $imageUrl = inputStr($input, 'imageUrl');
        if ($itemSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku.');
        }
        if (!isAllowedMenuImageUrl($imageUrl)) {
            studioResponse(false, null, 'Nieprawidłowy adres obrazka (dozwolone: /slicehub/uploads/global_assets/... lub .../visual/{tenant}/...).');
        }
        try {
            $st = $pdo->prepare(
                'UPDATE sh_menu_items SET image_url = :img, updated_at = NOW()
                 WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0'
            );
            $st->execute([':img' => $imageUrl, ':tid' => $tenant_id, ':sku' => $itemSku]);
            if ($st->rowCount() === 0) {
                studioResponse(false, null, 'Nie znaleziono produktu lub brak zmian.');
            }
            studioResponse(true, ['itemSku' => $itemSku, 'imageUrl' => $imageUrl], 'Zapisano zdjęcie produktu.');
        } catch (\Throwable $e) {
            studioResponse(false, null, 'Błąd zapisu: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // library_list — REMOVED 2026-04-19 (Faza B · SSOT biblioteki)
    // -------------------------------------------------------------------------
    // Zastąpione przez api/assets/engine.php action=list (Unified Asset Library m021).
    // Jeśli jakiś klient jeszcze wywoła `library_list` — zwracamy gone-410 aby debug
    // pokazał dokładnie gdzie siedzi zapomniany caller, zamiast cichej regresji.
    if ($action === 'library_list') {
        studioResponse(
            false,
            null,
            'library_list usunięty — użyj api/assets/engine.php action=list (SSOT 2026-04-19).'
        );
    }

    // -------------------------------------------------------------------------
    // library_update
    // -------------------------------------------------------------------------
    if ($action === 'library_update') {
        if (!$hasGlobalAssets) {
            studioResponse(false, null, 'sh_global_assets niedostepna.');
        }
        $id = inputInt($input, 'id', 0);
        if ($id <= 0) {
            studioResponse(false, null, 'Wymagane: id (global_assets).');
        }
        $st = $pdo->prepare(
            'SELECT * FROM sh_global_assets WHERE id = :id AND (tenant_id = 0 OR tenant_id = :tid) LIMIT 1'
        );
        $st->execute([':id' => $id, ':tid' => $tenant_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            studioResponse(false, null, 'Nie znaleziono assetu lub brak dostepu.');
        }
        if ((int)$row['tenant_id'] === 0) {
            studioResponse(false, null, 'Asset globalny (tenant_id=0) nie moze byc edytowany z panelu lokalu.');
        }

        $fields = [];
        $params = [':id' => $id];
        if (array_key_exists('ascii_key', $input)) {
            $ak = safeSku((string)$input['ascii_key']);
            if ($ak === '') {
                studioResponse(false, null, 'Nieprawidlowy ascii_key.');
            }
            $fields[] = 'ascii_key = :ascii_key';
            $params[':ascii_key'] = $ak;
        }
        if (array_key_exists('category', $input)) {
            $cat = inputStr($input, 'category');
            $allowed = ['board', 'base', 'sauce', 'cheese', 'meat', 'veg', 'herb', 'misc'];
            if (!in_array($cat, $allowed, true)) {
                studioResponse(false, null, 'Nieprawidlowa category.');
            }
            $fields[] = 'category = :category';
            $params[':category'] = $cat;
        }
        if (array_key_exists('sub_type', $input)) {
            $fields[] = 'sub_type = :sub_type';
            $params[':sub_type'] = substr(inputStr($input, 'sub_type'), 0, 64);
        }
        if (array_key_exists('z_order', $input)) {
            $fields[] = 'z_order = :z_order';
            $params[':z_order'] = inputInt($input, 'z_order', 50);
        }
        if (array_key_exists('is_active', $input)) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = inputBool($input, 'is_active', true) ? 1 : 0;
        }

        if (empty($fields)) {
            studioResponse(false, null, 'Brak pol do aktualizacji.');
        }

        $sql = 'UPDATE sh_global_assets SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $up = $pdo->prepare($sql);
        $up->execute($params);
        studioResponse(true, ['id' => $id, 'affected' => $up->rowCount()], 'OK');
    }

    // -------------------------------------------------------------------------
    // library_delete (soft)
    // -------------------------------------------------------------------------
    if ($action === 'library_delete') {
        if (!$hasGlobalAssets) {
            studioResponse(false, null, 'sh_global_assets niedostepna.');
        }
        $id = inputInt($input, 'id', 0);
        if ($id <= 0) {
            studioResponse(false, null, 'Wymagane: id.');
        }
        $st = $pdo->prepare(
            'SELECT id, tenant_id, ascii_key FROM sh_global_assets WHERE id = :id LIMIT 1'
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['tenant_id'] !== $tenant_id) {
            studioResponse(false, null, 'Nie znaleziono assetu lub brak dostepu.');
        }
        $force = inputBool($input, 'force', false);
        if ($hasVisualLayers && !$force) {
            $ac = $pdo->prepare(
                'SELECT COUNT(*) FROM sh_visual_layers WHERE tenant_id = :tid AND layer_sku = :lk AND is_active = 1'
            );
            $ac->execute([':tid' => $tenant_id, ':lk' => $row['ascii_key']]);
            if ((int)$ac->fetchColumn() > 0) {
                studioResponse(false, null, 'Asset jest przypisany do dan — ustaw force=1 aby zdezaktywowac mimo to.');
            }
        }
        $up = $pdo->prepare('UPDATE sh_global_assets SET is_active = 0 WHERE id = :id AND tenant_id = :tid');
        $up->execute([':id' => $id, ':tid' => $tenant_id]);
        studioResponse(true, ['id' => $id, 'affected' => $up->rowCount()], 'OK');
    }

    // -------------------------------------------------------------------------
    // composer_load_dish
    // -------------------------------------------------------------------------
    if ($action === 'composer_load_dish') {
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku.');
        }
        $st = $pdo->prepare(
            'SELECT ascii_key, name, description, image_url, category_id
             FROM sh_menu_items
             WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0 LIMIT 1'
        );
        $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            studioResponse(false, null, 'Nie znaleziono produktu.');
        }

        $layers = [];
        if ($hasVisualLayers) {
            $cols = 'vl.id, vl.layer_sku, vl.asset_filename, vl.z_index, vl.is_base';
            if ($hasVlHero)     { $cols .= ', vl.product_filename'; }
            if ($hasVlCal)      { $cols .= ', vl.cal_scale, vl.cal_rotate'; }
            if ($hasVlOffset)   { $cols .= ', vl.offset_x, vl.offset_y'; }
            if ($hasVlVersion)  { $cols .= ', vl.version'; }
            if ($hasVlLibCat)   { $cols .= ', vl.library_category, vl.library_sub_type'; }
            $gaJoin = $hasGlobalAssets
                ? "LEFT JOIN sh_global_assets ga
                     ON ga.ascii_key = vl.layer_sku
                    AND (ga.tenant_id = 0 OR ga.tenant_id = vl.tenant_id)
                    AND ga.is_active = 1"
                : '';
            $gaCol  = $hasGlobalAssets ? ', ga.filename AS ga_filename' : '';
            $stL = $pdo->prepare(
                "SELECT {$cols}{$gaCol} FROM sh_visual_layers vl
                 {$gaJoin}
                 WHERE vl.tenant_id = :tid AND vl.item_sku = :sku AND vl.is_active = 1
                 ORDER BY vl.z_index ASC, vl.id ASC"
            );
            $stL->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $vl) {
                $gaFile = $vl['ga_filename'] ?? null;
                $assetUrl = $gaFile
                    ? ('/slicehub/uploads/global_assets/' . $gaFile)
                    : ('/slicehub/uploads/visual/' . $tenant_id . '/' . $vl['asset_filename']);
                $layers[] = [
                    'id'              => (int)$vl['id'],
                    'layerSku'        => $vl['layer_sku'],
                    'assetFilename'   => $vl['asset_filename'],
                    'assetUrl'        => $assetUrl,
                    'productFilename' => $hasVlHero ? ($vl['product_filename'] ?? null) : null,
                    'zIndex'          => (int)$vl['z_index'],
                    'isBase'          => (bool)$vl['is_base'],
                    'calScale'        => $hasVlCal ? (float)$vl['cal_scale'] : 1.0,
                    'calRotate'       => $hasVlCal ? (int)$vl['cal_rotate'] : 0,
                    'offsetX'         => $hasVlOffset ? (float)$vl['offset_x'] : 0.0,
                    'offsetY'         => $hasVlOffset ? (float)$vl['offset_y'] : 0.0,
                    'version'         => $hasVlVersion ? (int)$vl['version'] : 0,
                    'libraryCategory' => $hasVlLibCat ? ($vl['library_category'] ?? null) : null,
                    'librarySubType'  => $hasVlLibCat ? ($vl['library_sub_type'] ?? null) : null,
                ];
            }
        }

        $companions = [];
        if ($hasBoardCompanions) {
            $heroSel = $hasBcHero ? ', bc.product_filename' : ', NULL AS product_filename';
            $stC = $pdo->prepare(
                "SELECT bc.id, bc.companion_sku, bc.companion_type, bc.board_slot, bc.asset_filename,
                        bc.display_order, bc.is_active {$heroSel}, mi.name
                 FROM sh_board_companions bc
                 JOIN sh_menu_items mi ON mi.ascii_key = bc.companion_sku AND mi.tenant_id = bc.tenant_id
                 WHERE bc.tenant_id = :tid AND bc.item_sku = :sku
                 ORDER BY bc.display_order ASC, bc.board_slot ASC"
            );
            $stC->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $companions[] = [
                    'id'             => (int)$c['id'],
                    'companionSku'   => $c['companion_sku'],
                    'name'           => $c['name'],
                    'type'           => $c['companion_type'],
                    'boardSlot'      => (int)$c['board_slot'],
                    'assetFilename'  => $c['asset_filename'],
                    'productFilename'=> $hasBcHero ? ($c['product_filename'] ?? null) : null,
                    'displayOrder'   => (int)$c['display_order'],
                    'isActive'       => (bool)$c['is_active'],
                ];
            }
        }

        $libraryPreview = [];
        if ($hasGlobalAssets) {
            $stG = $pdo->prepare(
                'SELECT id, ascii_key, category, sub_type, filename, z_order
                 FROM sh_global_assets
                 WHERE (tenant_id = 0 OR tenant_id = :tid) AND is_active = 1
                 ORDER BY z_order ASC LIMIT 500'
            );
            $stG->execute([':tid' => $tenant_id]);
            foreach ($stG->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $libraryPreview[] = [
                    'id'       => (int)$g['id'],
                    'asciiKey' => $g['ascii_key'],
                    'category' => $g['category'],
                    'subType'  => $g['sub_type'],
                    'filename' => $g['filename'],
                    'url'      => '/slicehub/uploads/global_assets/' . $g['filename'],
                    'zOrder'   => (int)$g['z_order'],
                ];
            }
        }

        studioResponse(true, [
            'item' => [
                'sku'         => $item['ascii_key'],
                'name'        => $item['name'],
                'description' => $item['description'],
                'imageUrl'    => $item['image_url'],
                'categoryId'  => (int)($item['category_id'] ?? 0),
            ],
            'visualLayers'   => $layers,
            'companions'     => $companions,
            'libraryPreview' => $libraryPreview,
            'surfaceBg'      => $getSetting('storefront_surface_bg', null),
            'schema'         => [
                'hasVlVersion' => $hasVlVersion,
                'hasVlLibCat'  => $hasVlLibCat,
                'hasVlHero'    => $hasVlHero,
                'hasVlCal'     => $hasVlCal,
                'hasVlOffset'  => $hasVlOffset,
            ],
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // composer_save_layers
    // -------------------------------------------------------------------------
    if ($action === 'composer_save_layers') {
        if (!$hasVisualLayers) {
            studioResponse(false, null, 'sh_visual_layers niedostepna.');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku.');
        }
        $stItem = $pdo->prepare(
            'SELECT 1 FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0 LIMIT 1'
        );
        $stItem->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
        if (!$stItem->fetchColumn()) {
            studioResponse(false, null, 'Nie znaleziono produktu.');
        }

        $layersIn = $input['layers'] ?? null;
        if (!is_array($layersIn)) {
            studioResponse(false, null, 'Wymagane: layers (array).');
        }

        $replaceAll = inputBool($input, 'replaceAll', false);

        $pdo->beginTransaction();
        try {
            if ($replaceAll) {
                $del = $pdo->prepare(
                    'DELETE FROM sh_visual_layers WHERE tenant_id = :tid AND item_sku = :sku'
                );
                $del->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            }

            $saved = [];
            foreach ($layersIn as $L) {
                if (!is_array($L)) {
                    continue;
                }
                $layerSku = safeSku((string)($L['layerSku'] ?? ''));
                if ($layerSku === '') {
                    throw new \RuntimeException('Nieprawidlowy layerSku w warstwie.');
                }
                $zIndex = isset($L['zIndex']) ? (int)$L['zIndex'] : 0;
                $isBase = !empty($L['isBase']) ? 1 : 0;
                $af = safeImageBasename((string)($L['assetFilename'] ?? ''), ['webp', 'png']);
                if ($af === '') {
                    throw new \RuntimeException('Brak lub nieprawidlowy assetFilename dla ' . $layerSku);
                }
                $pf = '';
                if ($hasVlHero && !empty($L['productFilename'])) {
                    $pf = safeImageBasename((string)$L['productFilename'], ['webp', 'png']);
                }
                $calScale  = $hasVlCal ? max(0.5, min(2.0, (float)($L['calScale'] ?? 1.0))) : 1.0;
                $calRotate = $hasVlCal ? max(-180, min(180, (int)($L['calRotate'] ?? 0))) : 0;
                $offsetX   = $hasVlOffset ? max(-0.5, min(0.5, (float)($L['offsetX'] ?? 0.0))) : 0.0;
                $offsetY   = $hasVlOffset ? max(-0.5, min(0.5, (float)($L['offsetY'] ?? 0.0))) : 0.0;
                $libCat    = $hasVlLibCat ? substr(trim((string)($L['libraryCategory'] ?? '')), 0, 64) : null;
                $libSub    = $hasVlLibCat ? substr(trim((string)($L['librarySubType'] ?? '')), 0, 64) : null;
                if ($libCat === '') {
                    $libCat = null;
                }
                if ($libSub === '') {
                    $libSub = null;
                }

                $rowVer = isset($L['version']) ? (int)$L['version'] : null;

                if ($replaceAll) {
                    $insParts = ['tenant_id', 'item_sku', 'layer_sku', 'asset_filename', 'z_index', 'is_base'];
                    $insVals  = [':tid', ':item', ':layer', ':af', ':zi', ':ib'];
                    $params   = [
                        ':tid'  => $tenant_id,
                        ':item' => $itemSku,
                        ':layer'=> $layerSku,
                        ':af'   => $af,
                        ':zi'   => $zIndex,
                        ':ib'   => $isBase,
                    ];
                    if ($hasVlHero) {
                        $insParts[] = 'product_filename';
                        $insVals[]  = ':pf';
                        $params[':pf'] = $pf !== '' ? $pf : null;
                    }
                    if ($hasVlCal) {
                        $insParts[] = 'cal_scale';
                        $insParts[] = 'cal_rotate';
                        $insVals[]  = ':cs';
                        $insVals[]  = ':cr';
                        $params[':cs'] = $calScale;
                        $params[':cr'] = $calRotate;
                    }
                    if ($hasVlOffset) {
                        $insParts[] = 'offset_x';
                        $insParts[] = 'offset_y';
                        $insVals[]  = ':ox';
                        $insVals[]  = ':oy';
                        $params[':ox'] = $offsetX;
                        $params[':oy'] = $offsetY;
                    }
                    if ($hasVlLibCat) {
                        $insParts[] = 'library_category';
                        $insParts[] = 'library_sub_type';
                        $insVals[]  = ':lcat';
                        $insVals[]  = ':lsub';
                        $params[':lcat'] = $libCat;
                        $params[':lsub'] = $libSub;
                    }
                    if ($hasVlVersion) {
                        $insParts[] = 'version';
                        $insVals[]  = '1';
                    }
                    $sqlIns = 'INSERT INTO sh_visual_layers (' . implode(', ', $insParts) . ')
                               VALUES (' . implode(', ', $insVals) . ')';
                    $pdo->prepare($sqlIns)->execute($params);
                    $newId = (int)$pdo->lastInsertId();
                    $saved[] = ['layerSku' => $layerSku, 'id' => $newId];
                    continue;
                }

                // Merge mode: upsert with optimistic lock
                $verCol = $hasVlVersion ? ', version' : '';
                $chk = $pdo->prepare(
                    "SELECT id{$verCol} FROM sh_visual_layers
                     WHERE tenant_id = :tid AND item_sku = :item AND layer_sku = :layer LIMIT 1"
                );
                $chk->execute([':tid' => $tenant_id, ':item' => $itemSku, ':layer' => $layerSku]);
                $ex = $chk->fetch(PDO::FETCH_ASSOC);

                if ($ex) {
                    $ver = $hasVlVersion ? (int)($ex['version'] ?? 0) : 0;
                    if ($hasVlVersion && $rowVer !== null && $rowVer !== $ver) {
                        throw new \RuntimeException('Konflikt wersji warstwy ' . $layerSku . ' (oczekiwano ' . $ver . ').');
                    }
                    $sets = [
                        'asset_filename = :af',
                        'z_index = :zi',
                        'is_base = :ib',
                    ];
                    $params = [
                        ':af'  => $af,
                        ':zi'  => $zIndex,
                        ':ib'  => $isBase,
                        ':tid' => $tenant_id,
                        ':item'=> $itemSku,
                        ':layer'=> $layerSku,
                    ];
                    if ($hasVlHero) {
                        $sets[] = 'product_filename = :pf';
                        $params[':pf'] = $pf !== '' ? $pf : null;
                    }
                    if ($hasVlCal) {
                        $sets[] = 'cal_scale = :cs';
                        $sets[] = 'cal_rotate = :cr';
                        $params[':cs'] = $calScale;
                        $params[':cr'] = $calRotate;
                    }
                    if ($hasVlOffset) {
                        $sets[] = 'offset_x = :ox';
                        $sets[] = 'offset_y = :oy';
                        $params[':ox'] = $offsetX;
                        $params[':oy'] = $offsetY;
                    }
                    if ($hasVlLibCat) {
                        $sets[] = 'library_category = :lcat';
                        $sets[] = 'library_sub_type = :lsub';
                        $params[':lcat'] = $libCat;
                        $params[':lsub'] = $libSub;
                    }
                    if ($hasVlVersion) {
                        $sets[] = 'version = version + 1';
                    }
                    $sqlU = 'UPDATE sh_visual_layers SET ' . implode(', ', $sets) . '
                             WHERE tenant_id = :tid AND item_sku = :item AND layer_sku = :layer';
                    if ($hasVlVersion && $rowVer !== null) {
                        $sqlU .= ' AND version = :expect';
                        $params[':expect'] = $rowVer;
                    }
                    $up = $pdo->prepare($sqlU);
                    $up->execute($params);
                    if ($up->rowCount() === 0 && $hasVlVersion && $rowVer !== null) {
                        throw new \RuntimeException('Konflikt wersji przy zapisie warstwy ' . $layerSku . '.');
                    }
                    $saved[] = ['layerSku' => $layerSku, 'id' => (int)$ex['id']];
                } else {
                    $insParts = ['tenant_id', 'item_sku', 'layer_sku', 'asset_filename', 'z_index', 'is_base'];
                    $insVals  = [':tid', ':item', ':layer', ':af', ':zi', ':ib'];
                    $params   = [
                        ':tid'  => $tenant_id,
                        ':item' => $itemSku,
                        ':layer'=> $layerSku,
                        ':af'   => $af,
                        ':zi'   => $zIndex,
                        ':ib'   => $isBase,
                    ];
                    if ($hasVlHero) {
                        $insParts[] = 'product_filename';
                        $insVals[]  = ':pf';
                        $params[':pf'] = $pf !== '' ? $pf : null;
                    }
                    if ($hasVlCal) {
                        $insParts[] = 'cal_scale';
                        $insParts[] = 'cal_rotate';
                        $insVals[]  = ':cs';
                        $insVals[]  = ':cr';
                        $params[':cs'] = $calScale;
                        $params[':cr'] = $calRotate;
                    }
                    if ($hasVlOffset) {
                        $insParts[] = 'offset_x';
                        $insParts[] = 'offset_y';
                        $insVals[]  = ':ox';
                        $insVals[]  = ':oy';
                        $params[':ox'] = $offsetX;
                        $params[':oy'] = $offsetY;
                    }
                    if ($hasVlLibCat) {
                        $insParts[] = 'library_category';
                        $insParts[] = 'library_sub_type';
                        $insVals[]  = ':lcat';
                        $insVals[]  = ':lsub';
                        $params[':lcat'] = $libCat;
                        $params[':lsub'] = $libSub;
                    }
                    if ($hasVlVersion) {
                        $insParts[] = 'version';
                        $insVals[]  = '1';
                    }
                    $sqlIns = 'INSERT INTO sh_visual_layers (' . implode(', ', $insParts) . ')
                               VALUES (' . implode(', ', $insVals) . ')';
                    $pdo->prepare($sqlIns)->execute($params);
                    $saved[] = ['layerSku' => $layerSku, 'id' => (int)$pdo->lastInsertId()];
                }
            }

            $pdo->commit();
            studioResponse(true, ['saved' => $saved, 'itemSku' => $itemSku], 'OK');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            studioResponse(false, null, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // composer_calibrate
    // -------------------------------------------------------------------------
    if ($action === 'composer_calibrate') {
        if (!$hasVisualLayers || !$hasVlCal) {
            studioResponse(false, null, 'Kalibracja niedostepna (brak kolumn cal_*).');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        $layerSku = safeSku(inputStr($input, 'layerSku'));
        if ($itemSku === '' || $layerSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku, layerSku.');
        }
        $calScale  = max(0.5, min(2.0, (float)inputStr($input, 'calScale', '1')));
        $calRotate = max(-180, min(180, inputInt($input, 'calRotate', 0)));
        $rowVer    = array_key_exists('version', $input) ? (int)$input['version'] : null;

        $sets = 'cal_scale = :cs, cal_rotate = :cr';
        $params = [
            ':cs' => $calScale,
            ':cr' => $calRotate,
            ':tid' => $tenant_id,
            ':item' => $itemSku,
            ':layer' => $layerSku,
        ];
        if ($hasVlVersion) {
            $sets .= ', version = version + 1';
        }
        $sql = "UPDATE sh_visual_layers SET {$sets}
                WHERE tenant_id = :tid AND item_sku = :item AND layer_sku = :layer";
        if ($hasVlVersion && $rowVer !== null) {
            $sql .= ' AND version = :ver';
            $params[':ver'] = $rowVer;
        }
        $up = $pdo->prepare($sql);
        $up->execute($params);
        if ($up->rowCount() === 0) {
            studioResponse(false, null, 'Brak warstwy lub konflikt wersji.');
        }
        studioResponse(true, ['itemSku' => $itemSku, 'layerSku' => $layerSku], 'OK');
    }

    // -------------------------------------------------------------------------
    // composer_clone
    // -------------------------------------------------------------------------
    if ($action === 'composer_clone') {
        if (!$hasVisualLayers) {
            studioResponse(false, null, 'sh_visual_layers niedostepna.');
        }
        $src = safeSku(inputStr($input, 'sourceSku'));
        $dst = safeSku(inputStr($input, 'targetSku'));
        if ($src === '' || $dst === '') {
            studioResponse(false, null, 'Wymagane: sourceSku, targetSku.');
        }
        if ($src === $dst) {
            studioResponse(false, null, 'source i target musza byc rozne.');
        }
        foreach ([$src, $dst] as $sku) {
            $c = $pdo->prepare(
                'SELECT 1 FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0'
            );
            $c->execute([':tid' => $tenant_id, ':sku' => $sku]);
            if (!$c->fetchColumn()) {
                studioResponse(false, null, "Produkt nie istnieje: {$sku}");
            }
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare(
                'DELETE FROM sh_visual_layers WHERE tenant_id = :tid AND item_sku = :sku'
            );
            $del->execute([':tid' => $tenant_id, ':sku' => $dst]);

            $cols = 'layer_sku, asset_filename, z_index, is_base';
            if ($hasVlHero) {
                $cols .= ', product_filename';
            }
            if ($hasVlCal) {
                $cols .= ', cal_scale, cal_rotate';
            }
            if ($hasVlLibCat) {
                $cols .= ', library_category, library_sub_type';
            }

            $sel = $pdo->prepare(
                "SELECT {$cols} FROM sh_visual_layers
                 WHERE tenant_id = :tid AND item_sku = :src AND is_active = 1"
            );
            $sel->execute([':tid' => $tenant_id, ':src' => $src]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            $n = 0;
            foreach ($rows as $r) {
                $insParts = ['tenant_id', 'item_sku', 'layer_sku', 'asset_filename', 'z_index', 'is_base'];
                $insVals  = [':tid', ':dst', ':layer', ':af', ':zi', ':ib'];
                $params   = [
                    ':tid' => $tenant_id,
                    ':dst' => $dst,
                    ':layer' => $r['layer_sku'],
                    ':af' => $r['asset_filename'],
                    ':zi' => (int)$r['z_index'],
                    ':ib' => (int)$r['is_base'],
                ];
                if ($hasVlHero) {
                    $insParts[] = 'product_filename';
                    $insVals[]  = ':pf';
                    $params[':pf'] = $r['product_filename'] ?? null;
                }
                if ($hasVlCal) {
                    $insParts[] = 'cal_scale';
                    $insParts[] = 'cal_rotate';
                    $insVals[]  = ':cs';
                    $insVals[]  = ':cr';
                    $params[':cs'] = $r['cal_scale'];
                    $params[':cr'] = $r['cal_rotate'];
                }
                if ($hasVlLibCat) {
                    $insParts[] = 'library_category';
                    $insParts[] = 'library_sub_type';
                    $insVals[]  = ':lcat';
                    $insVals[]  = ':lsub';
                    $params[':lcat'] = $r['library_category'] ?? null;
                    $params[':lsub'] = $r['library_sub_type'] ?? null;
                }
                if ($hasVlVersion) {
                    $insParts[] = 'version';
                    $insVals[]  = '1';
                }
                $sqlIns = 'INSERT INTO sh_visual_layers (' . implode(', ', $insParts) . ')
                           VALUES (' . implode(', ', $insVals) . ')';
                $pdo->prepare($sqlIns)->execute($params);
                $n++;
            }
            $pdo->commit();
            studioResponse(true, ['targetSku' => $dst, 'copiedLayers' => $n], 'OK');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            studioResponse(false, null, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // composer_autofit_suggest
    // -------------------------------------------------------------------------
    if ($action === 'composer_autofit_suggest') {
        if (!$hasVisualLayers || !$hasVlCal) {
            studioResponse(false, null, 'Autofit niedostepny.');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        $layerSku = safeSku(inputStr($input, 'layerSku'));
        if ($itemSku === '' || $layerSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku, layerSku (nowa warstwa).');
        }

        $subType = null;
        if ($hasGlobalAssets) {
            $g = $pdo->prepare(
                'SELECT sub_type FROM sh_global_assets
                 WHERE ascii_key = :k AND (tenant_id = 0 OR tenant_id = :tid) LIMIT 1'
            );
            $g->execute([':k' => $layerSku, ':tid' => $tenant_id]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            if ($gr && $gr['sub_type']) {
                $subType = $gr['sub_type'];
            }
        }

        if ($subType === null) {
            studioResponse(true, [
                'suggestedCalScale'  => 1.0,
                'suggestedCalRotate' => 0,
                'basedOn'            => 'default',
                'sampleSize'         => 0,
            ], 'OK (brak sub_type w bibliotece — domyslne)');
        }

        $sql = 'SELECT AVG(cal_scale) AS acs, AVG(cal_rotate) AS acr, COUNT(*) AS cnt
                FROM sh_visual_layers
                WHERE tenant_id = :tid AND item_sku = :item AND is_active = 1
                  AND layer_sku IN (
                    SELECT ascii_key FROM sh_global_assets
                    WHERE (tenant_id = 0 OR tenant_id = :tid2) AND sub_type = :st
                  )
                  AND layer_sku <> :excl';
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tid' => $tenant_id,
            ':item' => $itemSku,
            ':tid2' => $tenant_id,
            ':st' => $subType,
            ':excl' => $layerSku,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cnt = (int)($row['cnt'] ?? 0);
        if ($cnt === 0) {
            studioResponse(true, [
                'suggestedCalScale'  => 1.0,
                'suggestedCalRotate' => 0,
                'basedOn'            => 'sub_type:' . $subType,
                'sampleSize'         => 0,
            ], 'OK (brak innych warstw tego sub_type na tym daniu)');
        }

        studioResponse(true, [
            'suggestedCalScale'  => round((float)$row['acs'], 2),
            'suggestedCalRotate' => (int)round((float)$row['acr']),
            'basedOn'            => 'sub_type:' . $subType,
            'sampleSize'         => $cnt,
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // companions_list
    // -------------------------------------------------------------------------
    if ($action === 'companions_list') {
        if (!$hasBoardCompanions) {
            studioResponse(false, null, 'sh_board_companions niedostepna.');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku.');
        }
        $heroSel = $hasBcHero ? ', bc.product_filename' : ', NULL AS product_filename';
        $st = $pdo->prepare(
            "SELECT bc.*, mi.name AS companion_name {$heroSel}
             FROM sh_board_companions bc
             JOIN sh_menu_items mi ON mi.ascii_key = bc.companion_sku AND mi.tenant_id = bc.tenant_id
             WHERE bc.tenant_id = :tid AND bc.item_sku = :sku
             ORDER BY bc.display_order ASC"
        );
        $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'companionSku'    => $r['companion_sku'],
                'name'            => $r['companion_name'],
                'companionType'   => $r['companion_type'],
                'boardSlot'       => (int)$r['board_slot'],
                'assetFilename'   => $r['asset_filename'],
                'productFilename' => $hasBcHero ? ($r['product_filename'] ?? null) : null,
                'displayOrder'    => (int)$r['display_order'],
                'isActive'        => (bool)$r['is_active'],
            ];
        }
        studioResponse(true, ['itemSku' => $itemSku, 'companions' => $out], 'OK');
    }

    // -------------------------------------------------------------------------
    // companions_save
    // -------------------------------------------------------------------------
    if ($action === 'companions_save') {
        if (!$hasBoardCompanions) {
            studioResponse(false, null, 'sh_board_companions niedostepna.');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'Wymagane: itemSku.');
        }
        $stItem = $pdo->prepare(
            'SELECT 1 FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0'
        );
        $stItem->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
        if (!$stItem->fetchColumn()) {
            studioResponse(false, null, 'Nie znaleziono produktu bazowego.');
        }

        $comps = $input['companions'] ?? null;
        if (!is_array($comps)) {
            studioResponse(false, null, 'Wymagane: companions (array).');
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare(
                'DELETE FROM sh_board_companions WHERE tenant_id = :tid AND item_sku = :sku'
            );
            $del->execute([':tid' => $tenant_id, ':sku' => $itemSku]);

            $ord = 0;
            foreach ($comps as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $cSku = safeSku((string)($c['companionSku'] ?? ''));
                if ($cSku === '') {
                    continue;
                }
                $chk = $pdo->prepare(
                    'SELECT 1 FROM sh_menu_items WHERE tenant_id = :tid AND ascii_key = :sku AND is_deleted = 0'
                );
                $chk->execute([':tid' => $tenant_id, ':sku' => $cSku]);
                if (!$chk->fetchColumn()) {
                    throw new \RuntimeException('Nieznany companion SKU: ' . $cSku);
                }
                $type = inputStr($c, 'companionType', 'extra');
                if (!in_array($type, ['sauce', 'drink', 'side', 'dessert', 'extra'], true)) {
                    $type = 'extra';
                }
                $slot = isset($c['boardSlot']) ? (int)$c['boardSlot'] : $ord;
                $slot = max(0, min(5, $slot));
                $af = '';
                if (!empty($c['assetFilename'])) {
                    $af = safeImageBasename((string)$c['assetFilename'], ['webp', 'png']);
                }
                $pf = '';
                if ($hasBcHero && !empty($c['productFilename'])) {
                    $pf = safeImageBasename((string)$c['productFilename'], ['webp', 'png']);
                }

                if ($hasBcHero) {
                    $sql = 'INSERT INTO sh_board_companions
                        (tenant_id, item_sku, companion_sku, companion_type, board_slot, asset_filename, product_filename, display_order, is_active)
                        VALUES (:tid, :item, :csku, :ctype, :slot, :af, :pf, :ord, 1)';
                    $pdo->prepare($sql)->execute([
                        ':tid' => $tenant_id,
                        ':item' => $itemSku,
                        ':csku' => $cSku,
                        ':ctype' => $type,
                        ':slot' => $slot,
                        ':af' => $af !== '' ? $af : null,
                        ':pf' => $pf !== '' ? $pf : null,
                        ':ord' => $ord,
                    ]);
                } else {
                    $sql = 'INSERT INTO sh_board_companions
                        (tenant_id, item_sku, companion_sku, companion_type, board_slot, asset_filename, display_order, is_active)
                        VALUES (:tid, :item, :csku, :ctype, :slot, :af, :ord, 1)';
                    $pdo->prepare($sql)->execute([
                        ':tid' => $tenant_id,
                        ':item' => $itemSku,
                        ':csku' => $cSku,
                        ':ctype' => $type,
                        ':slot' => $slot,
                        ':af' => $af !== '' ? $af : null,
                        ':ord' => $ord,
                    ]);
                }
                $ord++;
            }
            $pdo->commit();
            studioResponse(true, ['itemSku' => $itemSku, 'count' => $ord], 'OK');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            studioResponse(false, null, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // surface_apply
    // -------------------------------------------------------------------------
    if ($action === 'surface_apply') {
        $fn = inputStr($input, 'filename');
        if ($fn === '') {
            studioResponse(false, null, 'Wymagane: filename (np. surface_stone_x.webp w uploads/global_assets/).');
        }
        $safe = safeImageBasename($fn, ['webp', 'png', 'jpg', 'jpeg']);
        if ($safe === '') {
            studioResponse(false, null, 'Nieprawidlowa nazwa pliku tla.');
        }
        if (!$setSetting('storefront_surface_bg', $safe)) {
            studioResponse(false, null, 'Nie mozna zapisac ustawienia.');
        }
        studioResponse(true, [
            'filename' => $safe,
            'url'      => '/slicehub/uploads/global_assets/' . $safe,
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // storefront_settings_get — wszystkie ustawienia sklepu w jednym response
    // -------------------------------------------------------------------------
    // Faza D · 2026-04-19. UI w `modules/online_studio/js/tabs/storefront.js`
    // korzysta z tego jednego endpointu żeby załadować pełny stan (adres,
    // godziny, kanały, marka, mapa). Storefront używa tych samych kluczy
    // przez `get_doorway` i `get_storefront_settings` w `api/online/engine.php`.
    if ($action === 'storefront_settings_get') {
        // Brand + contact
        $tagline = (string)$getSetting('storefront_tagline', '');
        $address = (string)$getSetting('storefront_address', '');
        $city    = (string)$getSetting('storefront_city', '');
        $phone   = (string)$getSetting('storefront_phone', '');
        $email   = (string)$getSetting('storefront_email', '');
        $latRaw  = $getSetting('storefront_lat', null);
        $lngRaw  = $getSetting('storefront_lng', null);
        $surfaceBg = (string)$getSetting('storefront_surface_bg', '');

        // Opening hours — osobna kolumna opening_hours_json na wierszu setting_key=''
        $openingHoursRaw = null;
        try {
            $stH = $pdo->prepare(
                "SELECT opening_hours_json FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key = '' LIMIT 1"
            );
            $stH->execute([':tid' => $tenant_id]);
            $openingHoursRaw = $stH->fetchColumn();
        } catch (\PDOException $e) { /* legacy tenants may lack column */ }

        $openingHours = [];
        if ($openingHoursRaw) {
            $decoded = json_decode((string)$openingHoursRaw, true);
            if (is_array($decoded)) $openingHours = $decoded;
        }

        // Kanały i preorder
        $channelsRaw = $getSetting('storefront_channels_json', null);
        $channels = ['delivery', 'takeaway']; // domyślnie
        if ($channelsRaw) {
            $d = json_decode((string)$channelsRaw, true);
            if (is_array($d) && $d) $channels = array_values(array_intersect(
                array_map('strval', $d),
                ['delivery', 'takeaway', 'dine_in']
            ));
        }
        $preorderEnabled  = (int)$getSetting('storefront_preorder_enabled', '0') === 1;
        $preorderLeadMin  = max(0, (int)$getSetting('storefront_preorder_min_lead_minutes', '30'));

        // Brand name (czytamy z sh_tenant — NIE zmieniamy tu)
        $stB = $pdo->prepare('SELECT name FROM sh_tenant WHERE id = :tid LIMIT 1');
        $stB->execute([':tid' => $tenant_id]);
        $brandName = (string)($stB->fetchColumn() ?: '');

        studioResponse(true, [
            'brand' => [
                'name'    => $brandName,
                'tagline' => $tagline,
            ],
            'contact' => [
                'address' => $address,
                'city'    => $city,
                'phone'   => $phone,
                'email'   => $email,
            ],
            'map' => [
                'lat' => is_numeric($latRaw) ? (float)$latRaw : null,
                'lng' => is_numeric($lngRaw) ? (float)$lngRaw : null,
            ],
            'openingHours' => (object)$openingHours,   // JS zobaczy obiekt (albo pusty)
            'channels' => [
                'active'          => $channels,
                'preorderEnabled' => $preorderEnabled,
                'preorderLeadMin' => $preorderLeadMin,
            ],
            'visual' => [
                'surfaceBg' => $surfaceBg,
                'surfaceUrl' => $surfaceBg ? ('/slicehub/uploads/global_assets/' . $surfaceBg) : null,
            ],
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // storefront_settings_save — zapis wybranych sekcji (atomowy)
    // -------------------------------------------------------------------------
    // Akceptuje częściowe payload'y (np. tylko `openingHours`, tylko `contact`)
    // żeby UI mógł save'ować sekcja po sekcji. Walidacja:
    //   - email RFC lite, phone tylko +/spacje/nawiasy/cyfry, lat/lng jako liczba.
    //   - channels ⊆ {'delivery','takeaway','dine_in'}, min 1
    //   - openingHours: klucz dnia ∈ {monday..sunday}, open/close HH:MM lub 'closed'
    //   - tagline/address/city/phone → max 255; email max 180
    // Wszystkie mutacje zapisane przez $setSetting (transakcja jawna dla spójności).
    if ($action === 'storefront_settings_save') {
        $errors = [];

        $setStr = function (string $key, $val, int $maxLen, string $label) use ($setSetting, &$errors) {
            if ($val === null) return; // pomijamy (UI nie prosił o zmianę)
            $v = trim((string)$val);
            if (mb_strlen($v) > $maxLen) {
                $errors[] = "{$label}: max {$maxLen} znaków.";
                return;
            }
            $setSetting($key, $v);
        };

        // Brand
        if (isset($input['brand']['tagline'])) {
            $setStr('storefront_tagline', $input['brand']['tagline'], 180, 'Tagline');
        }

        // Contact
        if (isset($input['contact']) && is_array($input['contact'])) {
            $c = $input['contact'];
            $setStr('storefront_address', $c['address'] ?? null, 255, 'Adres');
            $setStr('storefront_city',    $c['city']    ?? null, 120, 'Miasto');
            if (isset($c['phone'])) {
                $p = trim((string)$c['phone']);
                if ($p !== '' && !preg_match('/^[\d\s+()\-\.]{5,32}$/', $p)) {
                    $errors[] = 'Telefon: dozwolone cyfry, spacje, + ( ) - .';
                } else {
                    $setSetting('storefront_phone', $p);
                }
            }
            if (isset($c['email'])) {
                $e = trim((string)$c['email']);
                if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Email: nieprawidłowy format.';
                } else {
                    $setSetting('storefront_email', $e);
                }
            }
        }

        // Map (lat/lng)
        if (isset($input['map']) && is_array($input['map'])) {
            $m = $input['map'];
            if (array_key_exists('lat', $m)) {
                if ($m['lat'] === null || $m['lat'] === '') {
                    $setSetting('storefront_lat', '');
                } elseif (is_numeric($m['lat']) && (float)$m['lat'] >= -90 && (float)$m['lat'] <= 90) {
                    $setSetting('storefront_lat', (string)((float)$m['lat']));
                } else {
                    $errors[] = 'Lat: liczba w zakresie -90..90.';
                }
            }
            if (array_key_exists('lng', $m)) {
                if ($m['lng'] === null || $m['lng'] === '') {
                    $setSetting('storefront_lng', '');
                } elseif (is_numeric($m['lng']) && (float)$m['lng'] >= -180 && (float)$m['lng'] <= 180) {
                    $setSetting('storefront_lng', (string)((float)$m['lng']));
                } else {
                    $errors[] = 'Lng: liczba w zakresie -180..180.';
                }
            }
        }

        // Channels
        if (isset($input['channels']) && is_array($input['channels'])) {
            $ch = $input['channels'];
            if (isset($ch['active']) && is_array($ch['active'])) {
                $allowed = ['delivery', 'takeaway', 'dine_in'];
                $clean = array_values(array_unique(array_intersect(
                    array_map('strval', $ch['active']),
                    $allowed
                )));
                if (empty($clean)) {
                    $errors[] = 'Kanały: wymagany co najmniej 1 (delivery / takeaway / dine_in).';
                } else {
                    $setSetting('storefront_channels_json', json_encode($clean, JSON_UNESCAPED_UNICODE));
                }
            }
            if (array_key_exists('preorderEnabled', $ch)) {
                $setSetting('storefront_preorder_enabled', !empty($ch['preorderEnabled']) ? '1' : '0');
            }
            if (array_key_exists('preorderLeadMin', $ch)) {
                $lead = max(0, min(720, (int)$ch['preorderLeadMin'])); // 0–12h
                $setSetting('storefront_preorder_min_lead_minutes', (string)$lead);
            }
        }

        // Opening hours — zapis do kolumny opening_hours_json (setting_key='')
        if (isset($input['openingHours']) && (is_array($input['openingHours']) || is_object($input['openingHours']))) {
            $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            $clean = [];
            $oh = (array)$input['openingHours'];
            foreach ($days as $d) {
                $row = $oh[$d] ?? null;
                if (!is_array($row)) continue;
                $open  = isset($row['open'])  ? trim((string)$row['open'])  : '';
                $close = isset($row['close']) ? trim((string)$row['close']) : '';
                $closed = !empty($row['closed']);
                if ($closed || ($open === '' && $close === '')) {
                    $clean[$d] = ['closed' => true];
                    continue;
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
                    $errors[] = "Godziny ({$d}): format HH:MM.";
                    continue;
                }
                if ($open >= $close) {
                    $errors[] = "Godziny ({$d}): open musi być przed close.";
                    continue;
                }
                $clean[$d] = ['open' => $open, 'close' => $close];
            }

            try {
                $stOH = $pdo->prepare(
                    "INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value, opening_hours_json)
                     VALUES (:tid, '', NULL, :oj)
                     ON DUPLICATE KEY UPDATE opening_hours_json = VALUES(opening_hours_json)"
                );
                $stOH->execute([
                    ':tid' => $tenant_id,
                    ':oj'  => json_encode($clean, JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\PDOException $e) {
                $errors[] = 'Godziny otwarcia: nie udało się zapisać (' . $e->getMessage() . ').';
            }
        }

        if (!empty($errors)) {
            studioResponse(false, ['errors' => $errors], implode(' · ', $errors));
        }
        studioResponse(true, ['saved' => true], 'Ustawienia zapisane.');
    }

    // -------------------------------------------------------------------------
    // [M025 · Cleanup] Legacy Magic Dictionary (magic_list / magic_save /
    // magic_clear / magic_auto_match) zostało usunięte wraz z tabelą
    // `sh_modifier_visual_map`. Zastąpione przez edytor
    // "Surface — wizualne sloty" w Menu Studio
    // (api/backoffice/api_menu_studio.php → $syncModifierVisualAssetLinks),
    // który zapisuje dane do sh_asset_links (role layer_top_down / modifier_hero)
    // + sh_modifiers.has_visual_impact. Frontend czyta przez
    // SceneResolver::resolveModifierVisuals → pole `modifierVisuals` w API online.
    // -------------------------------------------------------------------------
    if ($action === 'magic_list' || $action === 'magic_save'
        || $action === 'magic_clear' || $action === 'magic_auto_match'
    ) {
        studioResponse(false, [
            'deprecated'  => true,
            'replacement' => 'Menu Studio → Modifier Editor → "Surface — wizualne sloty"',
        ], 'Magic Dictionary zostało usunięte (m025). Użyj Menu Studio → Modifier Editor.');
    }

    // -------------------------------------------------------------------------
    // composer_auto_match_dishes — for each pizza, suggest a base layer stack
    //   built from sh_global_assets that semantically match the dish
    //   (by name tokens + linked recipe ingredients/modifiers).
    //
    // Output: { suggestions: [{ itemSku, layers: [{ asciiKey, zIndex, ... }], score }], appliedCount, note }
    //
    // Args:
    //   itemSku     (optional) — only match this dish; otherwise ALL pizzas
    //   apply       (bool)     — if true, write suggested layers via composer_save_layers logic
    //                            (skip dishes that already have ≥ 2 active layers)
    // -------------------------------------------------------------------------
    if ($action === 'composer_auto_match_dishes') {
        if (!$hasGlobalAssets || !$hasVisualLayers) {
            studioResponse(false, null, 'Brak wymaganych tabel (sh_global_assets / sh_visual_layers).');
        }

        $apply       = inputBool($input, 'apply', false);
        $onlyEmpty   = inputBool($input, 'onlyEmpty', true);
        $forcedSku   = safeSku(inputStr($input, 'itemSku'));
        $maxLayers   = max(1, min(20, (int)($input['maxLayers'] ?? 8)));

        $params = [':tid' => $tenant_id];
        $whereSku = '';
        if ($forcedSku !== '') {
            $whereSku = ' AND mi.ascii_key = :sku';
            $params[':sku'] = $forcedSku;
        }
        // Pizza-style items: detect via category name 'pizza' OR linked sh_recipes flag if exists
        $stD = $pdo->prepare(
            "SELECT mi.ascii_key, mi.name, mi.description, c.name AS cat_name
             FROM sh_menu_items mi
             LEFT JOIN sh_categories c ON c.id = mi.category_id AND c.tenant_id = mi.tenant_id
             WHERE mi.tenant_id = :tid
               AND mi.is_deleted = 0
               AND (LOWER(c.name) LIKE '%pizz%' OR LOWER(mi.name) LIKE '%pizz%')
               {$whereSku}
             ORDER BY mi.name ASC"
        );
        $stD->execute($params);
        $dishes = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Asset library: prefer non-board categories for ingredients; board/base assets are reserved as base.
        $assets = $pdo->query(
            "SELECT id, ascii_key, category, sub_type, filename, z_order
             FROM sh_global_assets
             WHERE (tenant_id = 0 OR tenant_id = {$tenant_id})
               AND is_active = 1
             ORDER BY z_order ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Existing layer counts per dish (for onlyEmpty / skip logic)
        $existingCounts = [];
        $stExist = $pdo->prepare(
            'SELECT item_sku, COUNT(*) AS n FROM sh_visual_layers
             WHERE tenant_id = :tid AND is_active = 1 GROUP BY item_sku'
        );
        $stExist->execute([':tid' => $tenant_id]);
        foreach ($stExist->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existingCounts[$r['item_sku']] = (int)$r['n'];
        }

        $normalize = static function (string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            $s = strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
            $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?: '';
            return trim(preg_replace('/\s+/', ' ', $s) ?? '');
        };
        $tokenize = static function (string $s) use ($normalize): array {
            return array_values(array_filter(explode(' ', $normalize($s)), fn($t) => strlen($t) >= 3));
        };

        // Pre-tokenize assets (key tokens + category)
        $assetIndex = []; // [ token => [ {ascii_key, category, z_order} ] ]
        foreach ($assets as $a) {
            $toks = $tokenize(($a['sub_type'] ?? '') . ' ' . $a['ascii_key']);
            foreach ($toks as $t) {
                $assetIndex[$t][] = $a;
            }
        }

        // For each dish: pull recipe ingredients (sh_recipes optional) + linked modifiers via sh_item_modifiers
        $hasRecipes = false;
        try { $pdo->query('SELECT 1 FROM sh_recipes LIMIT 0'); $hasRecipes = true; } catch (\PDOException $e) {}

        $suggestionsAll = [];
        $applied = 0;
        $skipped = 0;

        foreach ($dishes as $d) {
            $itemSku = $d['ascii_key'];
            if ($onlyEmpty && ($existingCounts[$itemSku] ?? 0) >= 2) { $skipped++; continue; }

            $tokens = $tokenize($d['name'] . ' ' . ($d['description'] ?? ''));

            // Recipe ingredient tokens (best signal)
            if ($hasRecipes) {
                try {
                    $rIng = $pdo->prepare(
                        "SELECT DISTINCT wi.name
                         FROM sh_recipes r
                         INNER JOIN sh_warehouse_items wi ON wi.ascii_key = r.input_sku AND wi.tenant_id = r.tenant_id
                         WHERE r.tenant_id = :tid AND r.target_sku = :sku"
                    );
                    $rIng->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                    foreach ($rIng->fetchAll(PDO::FETCH_COLUMN) as $ingName) {
                        $tokens = array_merge($tokens, $tokenize((string)$ingName));
                    }
                } catch (\PDOException $e) { /* best-effort */ }
            }

            // Modifier name tokens (less weight)
            try {
                $mNames = $pdo->prepare(
                    "SELECT DISTINCT m.name FROM sh_item_modifiers im
                     INNER JOIN sh_modifiers m ON m.id = im.modifier_id
                     INNER JOIN sh_modifier_groups mg ON mg.id = m.group_id
                     WHERE im.tenant_id = :tid AND im.item_sku = :sku
                       AND m.is_deleted = 0 AND m.is_active = 1"
                );
                $mNames->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                foreach ($mNames->fetchAll(PDO::FETCH_COLUMN) as $mn) {
                    $tokens = array_merge($tokens, $tokenize((string)$mn));
                }
            } catch (\PDOException $e) { /* best-effort */ }

            // Score: for each token, pick best-matching asset; deduplicate by ascii_key
            $picked = [];
            $picksByKey = [];
            $tokens = array_unique($tokens);
            foreach ($tokens as $t) {
                if (!isset($assetIndex[$t])) continue;
                foreach ($assetIndex[$t] as $a) {
                    $k = $a['ascii_key'];
                    if (!isset($picksByKey[$k])) {
                        $picksByKey[$k] = ['asset' => $a, 'score' => 0, 'tokens' => []];
                    }
                    $picksByKey[$k]['score']++;
                    $picksByKey[$k]['tokens'][] = $t;
                }
            }
            // Sort: score desc, then z_order asc; cap to maxLayers
            uasort($picksByKey, function ($a, $b) {
                if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
                return ((int)$a['asset']['z_order']) - ((int)$b['asset']['z_order']);
            });
            $picksByKey = array_slice($picksByKey, 0, $maxLayers, true);

            // Always include a base "board" or "base" asset if available and dish has none yet
            $bases = array_values(array_filter($assets, fn($a) => in_array(strtolower((string)$a['category']), ['board','base'], true)));
            $hasBaseInPicks = false;
            foreach ($picksByKey as $p) {
                if (in_array(strtolower((string)$p['asset']['category']), ['board','base'], true)) { $hasBaseInPicks = true; break; }
            }
            if (!$hasBaseInPicks && $bases) {
                $picksByKey['__BASE__' . $bases[0]['ascii_key']] = ['asset' => $bases[0], 'score' => 99, 'tokens' => ['__base__']];
            }

            // Build layers proposal
            $layers = [];
            $z = 10;
            foreach ($picksByKey as $p) {
                $isBase = in_array(strtolower((string)$p['asset']['category']), ['board','base'], true);
                $layers[] = [
                    'layerSku'        => $p['asset']['ascii_key'],
                    'assetFilename'   => $p['asset']['filename'],
                    'isBase'          => $isBase,
                    'zIndex'          => $isBase ? 0 : $z,
                    'calScale'        => 1.0,
                    'calRotate'       => 0,
                    'offsetX'         => 0.0,
                    'offsetY'         => 0.0,
                    'libraryCategory' => $p['asset']['category'],
                    'librarySubType'  => $p['asset']['sub_type'],
                    'matchedTokens'   => array_values(array_unique($p['tokens'])),
                    'score'           => $p['score'],
                ];
                if (!$isBase) $z += 10;
            }
            if (!$layers) continue;

            $suggestionsAll[] = [
                'itemSku' => $itemSku,
                'name'    => $d['name'],
                'layers'  => $layers,
            ];

            // Apply if requested (use composer_save_layers logic, replaceAll = false to merge unless onlyEmpty)
            if ($apply) {
                $pdo->beginTransaction();
                try {
                    if ($onlyEmpty) {
                        $del = $pdo->prepare(
                            'DELETE FROM sh_visual_layers WHERE tenant_id = :tid AND item_sku = :sku'
                        );
                        $del->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                    }
                    foreach ($layers as $L) {
                        $insParts = ['tenant_id', 'item_sku', 'layer_sku', 'asset_filename', 'z_index', 'is_base'];
                        $insVals  = [':tid', ':item', ':layer', ':af', ':zi', ':ib'];
                        $par      = [
                            ':tid'  => $tenant_id,
                            ':item' => $itemSku,
                            ':layer'=> $L['layerSku'],
                            ':af'   => $L['assetFilename'],
                            ':zi'   => (int)$L['zIndex'],
                            ':ib'   => $L['isBase'] ? 1 : 0,
                        ];
                        if ($hasVlCal) {
                            $insParts[] = 'cal_scale'; $insVals[] = ':cs'; $par[':cs'] = 1.0;
                            $insParts[] = 'cal_rotate'; $insVals[] = ':cr'; $par[':cr'] = 0;
                        }
                        if ($hasVlOffset) {
                            $insParts[] = 'offset_x'; $insVals[] = ':ox'; $par[':ox'] = 0.0;
                            $insParts[] = 'offset_y'; $insVals[] = ':oy'; $par[':oy'] = 0.0;
                        }
                        if ($hasVlLibCat) {
                            $insParts[] = 'library_category'; $insVals[] = ':lcat'; $par[':lcat'] = $L['libraryCategory'];
                            $insParts[] = 'library_sub_type'; $insVals[] = ':lsub'; $par[':lsub'] = $L['librarySubType'];
                        }
                        if ($hasVlVersion) { $insParts[] = 'version'; $insVals[] = '1'; }
                        $sqlIns = 'INSERT INTO sh_visual_layers (' . implode(', ', $insParts) . ')
                                   VALUES (' . implode(', ', $insVals) . ')
                                   ON DUPLICATE KEY UPDATE z_index = VALUES(z_index)';
                        $pdo->prepare($sqlIns)->execute($par);
                    }
                    $pdo->commit();
                    $applied++;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    // continue with other dishes
                }
            }
        }

        studioResponse(true, [
            'suggestions' => $suggestionsAll,
            'applied'     => $applied,
            'skipped'     => $skipped,
            'note'        => $apply
                ? "Zastosowano {$applied} dań (pominięto {$skipped} z istniejącymi warstwami)."
                : 'Sugestie wyznaczone — kliknij „Zastosuj" aby zapisać.',
        ], 'OK');
    }

    // -------------------------------------------------------------------------
    // preview_url
    // -------------------------------------------------------------------------
    if ($action === 'preview_url') {
        $base = detectBaseUrl();
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        $q = 'tenantId=' . $tenant_id;
        if ($itemSku !== '') {
            $q .= '&dish=' . rawurlencode($itemSku);
        }
        studioResponse(true, [
            'iframeUrl'  => $base . '/modules/online/index.html?' . $q,
            'note'       => 'Frontend modules/online/index.html — do zbudowania w kolejnym etapie.',
        ], 'OK');
    }

    // =========================================================================
    // DIRECTOR — scene load/save for Hollywood Director's Suite
    // =========================================================================

    $hasAtelierScenes = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sh_atelier_scenes'");
        $hasAtelierScenes = $chk && $chk->fetchColumn();
    } catch (\Throwable $e) {}

    // ── director_load_scene ────────────────────────────────────
    if ($action === 'director_load_scene') {
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') studioResponse(false, null, 'itemSku wymagany');

        $sceneSpec = null;
        $sceneRowId = null;
        $sceneVersion = 0;
        $activeCamera = null;
        $activeLut = null;
        $atmosphericEffects = [];
        if ($hasAtelierScenes) {
            try {
                // M3 #4 · Auto-perspective — dociągamy active_camera_preset (kolumny m022)
                //         żeby Director mógł pokazać ten sam kadr co storefront.
                $st = $pdo->prepare(
                    "SELECT id, spec_json, version,
                            active_camera_preset, active_lut,
                            atmospheric_effects_enabled_json
                     FROM sh_atelier_scenes
                     WHERE tenant_id=:tid AND item_sku=:sku LIMIT 1"
                );
                $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $sceneSpec = json_decode($row['spec_json'], true);
                    $sceneRowId = (int)$row['id'];
                    $sceneVersion = (int)($row['version'] ?? 0);
                    $activeCamera = $row['active_camera_preset'] ?? null;
                    $activeLut    = $row['active_lut'] ?? null;
                    $aeRaw = $row['atmospheric_effects_enabled_json'] ?? null;
                    if ($aeRaw) {
                        $ae = json_decode((string)$aeRaw, true);
                        if (is_array($ae)) $atmosphericEffects = array_values($ae);
                    }
                }
            } catch (\Throwable $e) {}
        }

        studioResponse(true, [
            'sceneSpec'          => $sceneSpec,
            'hasScene'           => $sceneSpec !== null,
            'sceneId'            => $sceneRowId,
            'version'            => $sceneVersion,
            'activeCamera'       => $activeCamera,
            'activeLut'          => $activeLut,
            'atmosphericEffects' => $atmosphericEffects,
        ], 'OK');
    }

    // ── director_save_scene ────────────────────────────────────
    if ($action === 'director_save_scene') {
        if (!$hasAtelierScenes) studioResponse(false, null, 'Tabela sh_atelier_scenes nie istnieje. Uruchom migrację 020.');

        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') studioResponse(false, null, 'itemSku wymagany');

        $specJson = $input['specJson'] ?? null;
        if (!$specJson || !is_string($specJson)) studioResponse(false, null, 'specJson wymagany (JSON string)');

        $decoded = json_decode($specJson, true);
        if (!$decoded) studioResponse(false, null, 'Nieprawidłowy JSON specJson');

        $snapshotLabel = inputStr($input, 'snapshotLabel') ?: null;
        $expectedVersion = isset($input['expectedVersion']) ? (int)$input['expectedVersion'] : null;
        $forceSave = !empty($input['forceSave']);

        // M3 #4 · Auto-perspective — opcjonalny preset kamery do zapisu w sh_atelier_scenes.active_camera_preset.
        // Biała lista musi być zgodna z CAMERA_PRESETS w core/js/scene_renderer.js (SSOT).
        $allowedCameras = ['top_down', 'hero_three_quarter', 'macro_close', 'wide_establishing', 'dutch_angle', 'rack_focus'];
        $activeCameraIn = $input['activeCamera'] ?? null;
        $activeCamera = null;
        if (is_string($activeCameraIn) && $activeCameraIn !== '') {
            if (!in_array($activeCameraIn, $allowedCameras, true)) {
                studioResponse(false, null, 'Nieprawidłowy activeCamera. Dozwolone: ' . implode(', ', $allowedCameras));
            }
            $activeCamera = $activeCameraIn;
        }

        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT id, version FROM sh_atelier_scenes WHERE tenant_id=:tid AND item_sku=:sku LIMIT 1 FOR UPDATE");
            $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            $existing = $st->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $currentVersion = (int)$existing['version'];
                if (!$forceSave && $expectedVersion !== null && $expectedVersion !== $currentVersion) {
                    $pdo->rollBack();
                    studioResponse(false, [
                        'sceneId' => (int)$existing['id'],
                        'currentVersion' => $currentVersion,
                        'expectedVersion' => $expectedVersion,
                    ], 'Konflikt wersji sceny — odswiez widok przed zapisem.', 409);
                }
                $newVer = (int)$existing['version'] + 1;
                if ($activeCamera !== null) {
                    $up = $pdo->prepare("UPDATE sh_atelier_scenes SET spec_json=:spec, version=:ver, active_camera_preset=:cam WHERE id=:id");
                    $up->execute([':spec' => $specJson, ':ver' => $newVer, ':cam' => $activeCamera, ':id' => $existing['id']]);
                } else {
                    $up = $pdo->prepare("UPDATE sh_atelier_scenes SET spec_json=:spec, version=:ver WHERE id=:id");
                    $up->execute([':spec' => $specJson, ':ver' => $newVer, ':id' => $existing['id']]);
                }
                $sceneId = (int)$existing['id'];
            } else {
                if ($activeCamera !== null) {
                    $ins = $pdo->prepare("INSERT INTO sh_atelier_scenes (tenant_id, item_sku, spec_json, version, active_camera_preset) VALUES (:tid, :sku, :spec, 1, :cam)");
                    $ins->execute([':tid' => $tenant_id, ':sku' => $itemSku, ':spec' => $specJson, ':cam' => $activeCamera]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO sh_atelier_scenes (tenant_id, item_sku, spec_json, version) VALUES (:tid, :sku, :spec, 1)");
                    $ins->execute([':tid' => $tenant_id, ':sku' => $itemSku, ':spec' => $specJson]);
                }
                $sceneId = (int)$pdo->lastInsertId();
            }

            $hist = $pdo->prepare("INSERT INTO sh_atelier_scene_history (scene_id, spec_json, snapshot_label) VALUES (:sid, :spec, :lbl)");
            $hist->execute([':sid' => $sceneId, ':spec' => $specJson, ':lbl' => $snapshotLabel]);

            $pdo->commit();
            studioResponse(true, ['sceneId' => $sceneId, 'version' => $existing ? ((int)$existing['version'] + 1) : 1], 'Scena zapisana');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            studioResponse(false, null, 'Błąd zapisu: ' . $e->getMessage());
        }
    }

    // ── director_list_presets ──────────────────────────────────
    if ($action === 'director_list_presets') {
        studioResponse(true, ['presets' => []], 'Presets are client-side only (JS ScenePresets.js)');
    }

    // =========================================================================
    // G4 · HARMONY SCORE — cache metryki jakości sceny (m030)
    // =========================================================================
    $hasSceneMetrics = false;
    try {
        $pdo->query('SELECT 1 FROM sh_scene_metrics LIMIT 0');
        $hasSceneMetrics = true;
    } catch (\Throwable $e) {
    }

    if ($action === 'scene_harmony_save') {
        if (!$hasAtelierScenes) {
            studioResponse(false, null, 'Brak tabeli sh_atelier_scenes (migracja 020 wymagana).');
        }
        if (!$hasSceneMetrics) {
            studioResponse(true, ['saved' => false, 'note' => 'Migracja 030 nieuruchomiona — score działa tylko lokalnie.'], 'OK (cache disabled)');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'itemSku wymagany.');
        }
        $score      = max(0, min(100, inputInt($input, 'harmonyScore', 0)));
        $layerCount = max(0, min(500, inputInt($input, 'layerCount', 0)));
        $outliers      = $input['outliers']      ?? null;   // actionable hints (UI)
        $layerOutliers = $input['layerOutliers'] ?? null;   // per-layer odstające (Magic Harmonize)
        $breakdown     = $input['breakdown']     ?? null;   // completeness/polish/consistency (Harmony v2)
        $variance      = $input['variance']      ?? null;

        // 2026-04-19 (Harmony v2): pakujemy wszystko w jeden outliers_json jako obiekt.
        // Back-compat: starsze frontendy wysyłały tablicę — nadal odkodują ten JSON,
        //               ale zobaczą obiekt zamiast tablicy — UI musi to obsłużyć.
        $pack = [
            'version'       => 2,
            'outliers'      => is_array($outliers)      ? $outliers      : [],
            'layerOutliers' => is_array($layerOutliers) ? $layerOutliers : [],
            'breakdown'     => is_array($breakdown)     ? $breakdown     : null,
        ];
        $outliersJson = json_encode($pack, JSON_UNESCAPED_UNICODE);
        $varianceJson = is_array($variance) ? json_encode($variance, JSON_UNESCAPED_UNICODE) : null;

        $st = $pdo->prepare('SELECT id FROM sh_atelier_scenes WHERE tenant_id=:tid AND item_sku=:sku LIMIT 1');
        $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
        $sceneId = (int)($st->fetchColumn() ?: 0);
        if ($sceneId <= 0) {
            studioResponse(true, ['saved' => false, 'note' => 'Scena nie została jeszcze zapisana (save najpierw).'], 'OK (no scene row)');
        }

        $up = $pdo->prepare(
            'INSERT INTO sh_scene_metrics (scene_id, tenant_id, harmony_score, layer_count, outliers_json, variance_json, last_computed_at)
             VALUES (:sid, :tid, :sc, :lc, :oj, :vj, NOW())
             ON DUPLICATE KEY UPDATE
                harmony_score = VALUES(harmony_score),
                layer_count   = VALUES(layer_count),
                outliers_json = VALUES(outliers_json),
                variance_json = VALUES(variance_json),
                last_computed_at = NOW()'
        );
        $up->execute([
            ':sid' => $sceneId,
            ':tid' => $tenant_id,
            ':sc'  => $score,
            ':lc'  => $layerCount,
            ':oj'  => $outliersJson,
            ':vj'  => $varianceJson,
        ]);
        studioResponse(true, [
            'saved'        => true,
            'sceneId'      => $sceneId,
            'harmonyScore' => $score,
        ], 'OK');
    }

    if ($action === 'scene_harmony_get') {
        if (!$hasSceneMetrics || !$hasAtelierScenes) {
            studioResponse(true, ['metrics' => []], 'Cache disabled (brak m030 / m020).');
        }
        $scope = inputStr($input, 'scope', 'dish');
        if ($scope === 'dish') {
            $itemSku = safeSku(inputStr($input, 'itemSku'));
            if ($itemSku === '') studioResponse(false, null, 'itemSku wymagany.');
            $st = $pdo->prepare(
                'SELECT sm.scene_id, sm.harmony_score, sm.layer_count, sm.outliers_json, sm.variance_json, sm.last_computed_at,
                        s.item_sku
                 FROM sh_scene_metrics sm
                 INNER JOIN sh_atelier_scenes s ON s.id = sm.scene_id
                 WHERE sm.tenant_id = :tid AND s.item_sku = :sku LIMIT 1'
            );
            $st->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                studioResponse(true, ['metrics' => null], 'OK (no metric yet)');
            }
            // Harmony v2: outliers_json to obiekt { version, outliers, layerOutliers, breakdown }
            // v1 back-compat: jeżeli decoded to tablica → rozpakowujemy jako outliers v1.
            $pack = $row['outliers_json'] ? json_decode((string)$row['outliers_json'], true) : null;
            $outliers      = [];
            $layerOutliers = [];
            $breakdown     = null;
            if (is_array($pack)) {
                if (isset($pack['version']) && (int)$pack['version'] >= 2) {
                    $outliers      = is_array($pack['outliers']      ?? null) ? $pack['outliers']      : [];
                    $layerOutliers = is_array($pack['layerOutliers'] ?? null) ? $pack['layerOutliers'] : [];
                    $breakdown     = is_array($pack['breakdown']     ?? null) ? $pack['breakdown']     : null;
                } else {
                    // legacy v1: tablica outlierów (bez breakdown)
                    $outliers = $pack;
                }
            }
            studioResponse(true, [
                'metrics' => [
                    'sceneId'        => (int)$row['scene_id'],
                    'itemSku'        => $row['item_sku'],
                    'harmonyScore'   => (int)$row['harmony_score'],
                    'layerCount'     => (int)$row['layer_count'],
                    'outliers'       => $outliers,
                    'layerOutliers'  => $layerOutliers,
                    'breakdown'      => $breakdown,
                    'variance'       => $row['variance_json'] ? json_decode((string)$row['variance_json'], true) : null,
                    'lastComputedAt' => $row['last_computed_at'],
                ],
            ], 'OK');
        }
        // scope = 'all' / 'tenant' → lista wszystkich scen z metryką (dla Style Conductor)
        $stAll = $pdo->prepare(
            'SELECT sm.scene_id, sm.harmony_score, sm.layer_count, sm.last_computed_at,
                    s.item_sku, mi.name AS item_name, mi.category_id,
                    COALESCE(c.name, "—") AS category_name
             FROM sh_scene_metrics sm
             INNER JOIN sh_atelier_scenes s ON s.id = sm.scene_id
             LEFT JOIN sh_menu_items mi ON mi.ascii_key = s.item_sku AND mi.tenant_id = sm.tenant_id
             LEFT JOIN sh_categories  c ON c.id = mi.category_id
             WHERE sm.tenant_id = :tid
             ORDER BY sm.harmony_score ASC, s.item_sku ASC'
        );
        $stAll->execute([':tid' => $tenant_id]);
        $out = [];
        foreach ($stAll->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'sceneId'      => (int)$r['scene_id'],
                'itemSku'      => $r['item_sku'],
                'itemName'     => $r['item_name'],
                'categoryId'   => (int)($r['category_id'] ?? 0),
                'categoryName' => $r['category_name'],
                'harmonyScore' => (int)$r['harmony_score'],
                'layerCount'   => (int)$r['layer_count'],
                'lastComputedAt' => $r['last_computed_at'],
            ];
        }
        studioResponse(true, ['metrics' => $out], 'OK');
    }

    // =========================================================================
    // G1/G2 · STYLE ENGINE — bulk apply style_preset do kategorii / całego menu
    // =========================================================================
    $hasStylePresets  = false;
    $hasCategoryStyles = false;
    try { $pdo->query('SELECT 1 FROM sh_style_presets LIMIT 0');  $hasStylePresets  = true; } catch (\Throwable $e) {}
    try { $pdo->query('SELECT 1 FROM sh_category_styles LIMIT 0'); $hasCategoryStyles = true; } catch (\Throwable $e) {}

    /** Merge style_preset (m022) do sceny (sh_atelier_scenes.spec_json). */
    $applyStyleToSpec = function (array $spec, array $preset): array {
        if (!isset($spec['stage']) || !is_array($spec['stage'])) $spec['stage'] = [];
        if (!isset($spec['infoBlock']) || !is_array($spec['infoBlock'])) $spec['infoBlock'] = [];
        if (!isset($spec['_style']) || !is_array($spec['_style'])) $spec['_style'] = [];

        $lut = (string)($preset['default_lut'] ?? 'none');
        if ($lut !== '') $spec['stage']['lutName'] = $lut;

        $palette = null;
        if (!empty($preset['color_palette_json'])) {
            $palette = is_array($preset['color_palette_json'])
                ? $preset['color_palette_json']
                : (json_decode((string)$preset['color_palette_json'], true) ?: null);
        }
        if (is_array($palette)) {
            $spec['_style']['palette'] = $palette;
            if (!empty($palette['bg']))     $spec['stage']['tintBg']     = (string)$palette['bg'];
            if (!empty($palette['accent'])) $spec['infoBlock']['accent'] = (string)$palette['accent'];
            if (!empty($palette['text']))   $spec['infoBlock']['textColor'] = (string)$palette['text'];
        }
        if (!empty($preset['font_family']))    $spec['infoBlock']['fontFamily']   = (string)$preset['font_family'];
        if (!empty($preset['motion_preset']))  $spec['_style']['motionPreset']    = (string)$preset['motion_preset'];
        if (!empty($preset['ambient_audio_ascii_key'])) $spec['_style']['ambientAudio'] = (string)$preset['ambient_audio_ascii_key'];

        $spec['_style']['appliedPresetKey']  = (string)($preset['ascii_key'] ?? '');
        $spec['_style']['appliedPresetName'] = (string)($preset['name'] ?? '');
        $spec['_style']['appliedAt']         = date('c');
        return $spec;
    };

    /** Jedna wspólna funkcja apply stylu do kolekcji itemSku (G1 i G2). */
    $applyStyleBulk = function (int $tenantId, int $presetId, array $itemSkus, ?int $categoryId = null)
        use ($pdo, $hasAtelierScenes, $hasStylePresets, $hasCategoryStyles, $applyStyleToSpec, $user_id): array
    {
        if (!$hasStylePresets) throw new \RuntimeException('Brak tabeli sh_style_presets (m022).');
        if (!$hasAtelierScenes) throw new \RuntimeException('Brak tabeli sh_atelier_scenes (m020).');
        if ($presetId <= 0)    throw new \RuntimeException('Nieprawidłowy styl.');

        $stP = $pdo->prepare(
            'SELECT id, tenant_id, ascii_key, name, color_palette_json, font_family,
                    motion_preset, default_lut, ambient_audio_ascii_key
             FROM sh_style_presets
             WHERE id = :id AND (tenant_id = 0 OR tenant_id = :tid) AND is_active = 1 LIMIT 1'
        );
        $stP->execute([':id' => $presetId, ':tid' => $tenantId]);
        $preset = $stP->fetch(\PDO::FETCH_ASSOC);
        if (!$preset) throw new \RuntimeException('Styl nie istnieje lub nieaktywny.');

        $updated = 0; $created = 0; $skipped = 0; $errors = [];

        foreach ($itemSkus as $sku) {
            $sku = safeSku((string)$sku);
            if ($sku === '') { $skipped++; continue; }
            try {
                $st = $pdo->prepare('SELECT id, spec_json, version FROM sh_atelier_scenes WHERE tenant_id = :tid AND item_sku = :sku LIMIT 1 FOR UPDATE');
                $pdo->beginTransaction();
                $st->execute([':tid' => $tenantId, ':sku' => $sku]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $spec = json_decode((string)$row['spec_json'], true) ?: [];
                    $spec = $applyStyleToSpec($spec, $preset);
                    $newJson = json_encode($spec, JSON_UNESCAPED_UNICODE);
                    $up = $pdo->prepare('UPDATE sh_atelier_scenes SET spec_json = :spec, version = version + 1 WHERE id = :id');
                    $up->execute([':spec' => $newJson, ':id' => (int)$row['id']]);
                    $hist = $pdo->prepare('INSERT INTO sh_atelier_scene_history (scene_id, spec_json, snapshot_label) VALUES (:sid, :spec, :lbl)');
                    $hist->execute([':sid' => (int)$row['id'], ':spec' => $newJson, ':lbl' => 'Styl: ' . ($preset['name'] ?? $preset['ascii_key'])]);
                    $updated++;
                } else {
                    $skipped++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = ['itemSku' => $sku, 'error' => $e->getMessage()];
            }
        }

        // Update sh_category_styles (jeśli mamy category_id — oznaczamy aktywny styl)
        if ($categoryId !== null && $categoryId > 0 && $hasCategoryStyles) {
            try {
                $pdo->prepare('UPDATE sh_category_styles SET is_active = 0 WHERE tenant_id = :tid AND category_id = :cat')
                    ->execute([':tid' => $tenantId, ':cat' => $categoryId]);
                $pdo->prepare(
                    'INSERT INTO sh_category_styles (tenant_id, category_id, style_preset_id, applied_by_user_id, is_active)
                     VALUES (:tid, :cat, :spid, :uid, 1)'
                )->execute([':tid' => $tenantId, ':cat' => $categoryId, ':spid' => $presetId, ':uid' => $user_id]);
            } catch (\Throwable $e) { /* best-effort audit */ }
        }

        return [
            'updated'   => $updated,
            'created'   => $created,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'presetKey' => (string)$preset['ascii_key'],
            'presetName'=> (string)$preset['name'],
        ];
    };

    if ($action === 'style_presets_list') {
        if (!$hasStylePresets) studioResponse(true, ['presets' => []], 'Brak m022.');
        $st = $pdo->prepare(
            'SELECT id, ascii_key, name, cinema_reference, color_palette_json, font_family,
                    motion_preset, default_lut, is_system
             FROM sh_style_presets
             WHERE (tenant_id = 0 OR tenant_id = :tid) AND is_active = 1
             ORDER BY is_system DESC, name ASC'
        );
        $st->execute([':tid' => $tenant_id]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'asciiKey'        => (string)$r['ascii_key'],
                'name'            => (string)$r['name'],
                'cinemaReference' => $r['cinema_reference'],
                'palette'         => $r['color_palette_json'] ? json_decode((string)$r['color_palette_json'], true) : null,
                'fontFamily'      => $r['font_family'],
                'motionPreset'    => $r['motion_preset'],
                'defaultLut'      => $r['default_lut'],
                'isSystem'        => (bool)$r['is_system'],
            ];
        }
        studioResponse(true, ['presets' => $out], 'OK');
    }

    if ($action === 'category_style_apply') {
        if (!$hasStylePresets || !$hasAtelierScenes) {
            studioResponse(false, null, 'Migracje m020 + m022 są wymagane.');
        }
        $categoryId = inputInt($input, 'categoryId', 0);
        $presetId   = inputInt($input, 'stylePresetId', 0);
        $dryRun     = inputBool($input, 'dryRun', false);
        if ($categoryId <= 0 || $presetId <= 0) {
            studioResponse(false, null, 'Wymagane: categoryId, stylePresetId.');
        }

        $stI = $pdo->prepare(
            'SELECT ascii_key FROM sh_menu_items WHERE tenant_id = :tid AND category_id = :cat AND is_deleted = 0'
        );
        $stI->execute([':tid' => $tenant_id, ':cat' => $categoryId]);
        $skus = $stI->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (!$skus) studioResponse(false, null, 'Kategoria nie zawiera aktywnych pozycji.');

        if ($dryRun) {
            studioResponse(true, [
                'dryRun'       => true,
                'categoryId'   => $categoryId,
                'itemsInScope' => count($skus),
                'sampleSkus'   => array_slice($skus, 0, 6),
            ], 'OK (dry run)');
        }

        try {
            $res = $applyStyleBulk($tenant_id, $presetId, $skus, $categoryId);
            studioResponse(true, array_merge($res, [
                'categoryId' => $categoryId,
                'itemsInScope' => count($skus),
            ]), sprintf('Zastosowano "%s" do %d dań (pominięto %d bez sceny).', $res['presetName'], $res['updated'], $res['skipped']));
        } catch (\Throwable $e) {
            studioResponse(false, null, 'Błąd bulk: ' . $e->getMessage());
        }
    }

    if ($action === 'menu_style_apply') {
        if (!$hasStylePresets || !$hasAtelierScenes) {
            studioResponse(false, null, 'Migracje m020 + m022 są wymagane.');
        }
        $presetId    = inputInt($input, 'stylePresetId', 0);
        $categoryIds = $input['categoryIds'] ?? null; // optional filter
        $dryRun      = inputBool($input, 'dryRun', false);
        if ($presetId <= 0) studioResponse(false, null, 'Wymagane: stylePresetId.');

        $where = 'mi.tenant_id = :tid AND mi.is_deleted = 0';
        $params = [':tid' => $tenant_id];
        if (is_array($categoryIds) && count($categoryIds) > 0) {
            $placeholders = [];
            foreach ($categoryIds as $i => $cid) {
                $placeholders[] = ':c' . $i;
                $params[':c' . $i] = (int)$cid;
            }
            $where .= ' AND mi.category_id IN (' . implode(',', $placeholders) . ')';
        }
        $stI = $pdo->prepare("SELECT mi.ascii_key, mi.category_id FROM sh_menu_items mi WHERE {$where}");
        $stI->execute($params);
        $rows = $stI->fetchAll(\PDO::FETCH_ASSOC);
        $skus = array_column($rows, 'ascii_key');
        if (!$skus) studioResponse(false, null, 'Brak pozycji w zakresie.');

        if ($dryRun) {
            studioResponse(true, [
                'dryRun'       => true,
                'itemsInScope' => count($skus),
                'sampleSkus'   => array_slice($skus, 0, 8),
            ], 'OK (dry run)');
        }

        try {
            $res = $applyStyleBulk($tenant_id, $presetId, $skus, null);
            // Update sh_category_styles dla KAŻDEJ kategorii objętej skanem
            if ($hasCategoryStyles) {
                $cats = array_values(array_unique(array_map('intval', array_column($rows, 'category_id'))));
                foreach ($cats as $cid) {
                    if ($cid <= 0) continue;
                    try {
                        $pdo->prepare('UPDATE sh_category_styles SET is_active = 0 WHERE tenant_id = :tid AND category_id = :cat')
                            ->execute([':tid' => $tenant_id, ':cat' => $cid]);
                        $pdo->prepare(
                            'INSERT INTO sh_category_styles (tenant_id, category_id, style_preset_id, applied_by_user_id, is_active)
                             VALUES (:tid, :cat, :spid, :uid, 1)'
                        )->execute([':tid' => $tenant_id, ':cat' => $cid, ':spid' => $presetId, ':uid' => $user_id]);
                    } catch (\Throwable $e) {}
                }
            }
            studioResponse(true, array_merge($res, [
                'itemsInScope' => count($skus),
            ]), sprintf('Zastosowano "%s" globalnie do %d dań (pominięto %d).', $res['presetName'], $res['updated'], $res['skipped']));
        } catch (\Throwable $e) {
            studioResponse(false, null, 'Błąd bulk: ' . $e->getMessage());
        }
    }

    if ($action === 'category_styles_list') {
        if (!$hasCategoryStyles) studioResponse(true, ['styles' => []], 'Brak m022.');
        $st = $pdo->prepare(
            'SELECT cs.category_id, cs.style_preset_id, cs.applied_at, cs.ai_cost_zl,
                    sp.ascii_key AS preset_key, sp.name AS preset_name,
                    c.name AS category_name
             FROM sh_category_styles cs
             INNER JOIN sh_style_presets sp ON sp.id = cs.style_preset_id
             LEFT JOIN sh_categories c ON c.id = cs.category_id
             WHERE cs.tenant_id = :tid AND cs.is_active = 1
             ORDER BY c.display_order ASC, c.name ASC'
        );
        $st->execute([':tid' => $tenant_id]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'categoryId'   => (int)$r['category_id'],
                'categoryName' => $r['category_name'],
                'presetId'     => (int)$r['style_preset_id'],
                'presetKey'    => $r['preset_key'],
                'presetName'   => $r['preset_name'],
                'appliedAt'    => $r['applied_at'],
                'aiCostZl'     => (float)$r['ai_cost_zl'],
            ];
        }
        studioResponse(true, ['styles' => $out], 'OK');
    }

    // =========================================================================
    // PROMOTIONS (M022 sh_promotions + sh_scene_promotion_slots)
    // =========================================================================
    $hasPromoTables = false;
    try {
        $pdo->query('SELECT 1 FROM sh_promotions LIMIT 0');
        $pdo->query('SELECT 1 FROM sh_scene_promotion_slots LIMIT 0');
        $hasPromoTables = true;
    } catch (\Throwable $e) {
    }

    if ($action === 'promotions_list') {
        if (!$hasPromoTables) {
            studioResponse(true, ['promotions' => []], 'OK (brak tabel M022)');
        }
        $st = $pdo->prepare(
            'SELECT id, ascii_key, name, rule_kind, rule_json, badge_text, badge_style,
                    valid_from, valid_to, is_active, updated_at
             FROM sh_promotions WHERE tenant_id = ? ORDER BY name ASC'
        );
        $st->execute([$tenant_id]);
        $list = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rule = json_decode((string)$r['rule_json'], true);
            $list[] = [
                'id'          => (int)$r['id'],
                'asciiKey'    => (string)$r['ascii_key'],
                'name'        => (string)$r['name'],
                'ruleKind'    => (string)$r['rule_kind'],
                'rule'        => is_array($rule) ? $rule : [],
                'badgeText'   => $r['badge_text'],
                'badgeStyle'  => $r['badge_style'] ?? 'amber',
                'validFrom'   => $r['valid_from'],
                'validTo'     => $r['valid_to'],
                'isActive'    => (bool)$r['is_active'],
                'updatedAt'   => $r['updated_at'],
            ];
        }
        studioResponse(true, ['promotions' => $list], 'OK');
    }

    if ($action === 'promotion_save') {
        if (!$hasPromoTables) {
            studioResponse(false, null, 'Brak tabel promocji (M022).');
        }
        $ascii = safeSku(inputStr($input, 'asciiKey'));
        if ($ascii === '') {
            studioResponse(false, null, 'asciiKey jest wymagany (np. PROMO_HAPPY_HOUR).');
        }
        $name = inputStr($input, 'name');
        if ($name === '') {
            studioResponse(false, null, 'Nazwa promocji jest wymagana.');
        }
        $allowedKinds = ['discount_percent', 'discount_amount', 'combo_half_price', 'free_item_if_threshold', 'bundle'];
        $ruleKind = inputStr($input, 'ruleKind') ?: 'discount_percent';
        if (!in_array($ruleKind, $allowedKinds, true)) {
            $ruleKind = 'discount_percent';
        }
        $ruleArr = $input['rule'] ?? [];
        if (!is_array($ruleArr)) {
            $ruleArr = [];
        }
        $badgeText = inputStr($input, 'badgeText') ?: null;
        $badgeStyle = inputStr($input, 'badgeStyle') ?: 'amber';
        $validFrom = $input['validFrom'] ?? null;
        $validTo = $input['validTo'] ?? null;
        $isActive = inputBool($input, 'isActive', true) ? 1 : 0;
        $promoId = inputInt($input, 'id');

        $ruleJson = json_encode($ruleArr, JSON_UNESCAPED_UNICODE);
        if ($ruleJson === false) {
            studioResponse(false, null, 'Nieprawidłowy rule JSON.');
        }

        if ($promoId > 0) {
            $chk = $pdo->prepare('SELECT id FROM sh_promotions WHERE id = ? AND tenant_id = ? LIMIT 1');
            $chk->execute([$promoId, $tenant_id]);
            if (!$chk->fetch()) {
                studioResponse(false, null, 'Promocja nie istnieje.');
            }
            $up = $pdo->prepare(
                'UPDATE sh_promotions SET ascii_key = ?, name = ?, rule_kind = ?, rule_json = ?,
                 badge_text = ?, badge_style = ?, valid_from = ?, valid_to = ?, is_active = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $up->execute([
                $ascii, $name, $ruleKind, $ruleJson,
                $badgeText, $badgeStyle,
                $validFrom ?: null, $validTo ?: null, $isActive,
                $promoId, $tenant_id,
            ]);
            studioResponse(true, ['id' => $promoId], 'Zaktualizowano promocję.');
        }

        $ins = $pdo->prepare(
            'INSERT INTO sh_promotions
                (tenant_id, ascii_key, name, rule_kind, rule_json, badge_text, badge_style, valid_from, valid_to, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), rule_kind = VALUES(rule_kind), rule_json = VALUES(rule_json),
                badge_text = VALUES(badge_text), badge_style = VALUES(badge_style),
                valid_from = VALUES(valid_from), valid_to = VALUES(valid_to), is_active = VALUES(is_active)'
        );
        $ins->execute([
            $tenant_id, $ascii, $name, $ruleKind, $ruleJson,
            $badgeText, $badgeStyle, $validFrom ?: null, $validTo ?: null, $isActive,
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($newId === 0) {
            $st = $pdo->prepare('SELECT id FROM sh_promotions WHERE tenant_id = ? AND ascii_key = ? LIMIT 1');
            $st->execute([$tenant_id, $ascii]);
            $newId = (int)($st->fetchColumn() ?: 0);
        }
        studioResponse(true, ['id' => $newId], 'Zapisano promocję.');
    }

    if ($action === 'promotion_delete') {
        if (!$hasPromoTables) {
            studioResponse(false, null, 'Brak tabel promocji.');
        }
        $promoId = inputInt($input, 'promotionId');
        if ($promoId <= 0) {
            studioResponse(false, null, 'promotionId wymagane.');
        }
        $chk = $pdo->prepare('SELECT id FROM sh_promotions WHERE id = ? AND tenant_id = ? LIMIT 1');
        $chk->execute([$promoId, $tenant_id]);
        if (!$chk->fetch()) {
            studioResponse(false, null, 'Promocja nie istnieje.');
        }
        try {
            $pdo->prepare('DELETE FROM sh_scene_promotion_slots WHERE promotion_id = ?')->execute([$promoId]);
        } catch (\Throwable $e) {
        }
        $pdo->prepare('UPDATE sh_promotions SET is_active = 0 WHERE id = ? AND tenant_id = ?')->execute([$promoId, $tenant_id]);
        studioResponse(true, ['promotionId' => $promoId], 'Promocja wyłączona i sloty usunięte.');
    }

    if ($action === 'scene_promotion_slots_get') {
        if (!$hasPromoTables || !$hasAtelierScenes) {
            studioResponse(true, ['sceneId' => null, 'slots' => []], 'OK');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'itemSku wymagany.');
        }
        $st = $pdo->prepare(
            'SELECT id FROM sh_atelier_scenes WHERE tenant_id = ? AND item_sku = ? LIMIT 1'
        );
        $st->execute([$tenant_id, $itemSku]);
        $sidRow = $st->fetch(\PDO::FETCH_ASSOC);
        $sceneId = $sidRow ? (int)$sidRow['id'] : null;
        if (!$sceneId) {
            studioResponse(true, ['sceneId' => null, 'slots' => []], 'OK');
        }
        $st2 = $pdo->prepare(
            "SELECT sps.id AS slot_id, sps.promotion_id, sps.slot_x, sps.slot_y, sps.slot_z_index, sps.display_order,
                    p.ascii_key, p.name, p.badge_text, p.badge_style, p.rule_kind
             FROM sh_scene_promotion_slots sps
             INNER JOIN sh_promotions p ON p.id = sps.promotion_id AND p.tenant_id = ?
             WHERE sps.scene_id = ? AND sps.is_active = 1
             ORDER BY sps.display_order ASC, sps.slot_z_index ASC"
        );
        $st2->execute([$tenant_id, $sceneId]);
        $slots = [];
        foreach ($st2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $slots[] = [
                'slotId'       => (int)$r['slot_id'],
                'promotionId'  => (int)$r['promotion_id'],
                'asciiKey'     => (string)$r['ascii_key'],
                'name'         => (string)$r['name'],
                'badgeText'    => $r['badge_text'],
                'badgeStyle'   => $r['badge_style'] ?? 'amber',
                'ruleKind'     => (string)$r['rule_kind'],
                'slotX'        => (float)$r['slot_x'],
                'slotY'        => (float)$r['slot_y'],
                'slotZIndex'   => (int)$r['slot_z_index'],
                'displayOrder' => (int)$r['display_order'],
            ];
        }
        studioResponse(true, ['sceneId' => $sceneId, 'slots' => $slots], 'OK');
    }

    if ($action === 'scene_promotion_slots_save') {
        if (!$hasPromoTables || !$hasAtelierScenes) {
            studioResponse(false, null, 'Brak tabel sceny/promocji.');
        }
        $itemSku = safeSku(inputStr($input, 'itemSku'));
        if ($itemSku === '') {
            studioResponse(false, null, 'itemSku wymagany.');
        }
        $st = $pdo->prepare(
            'SELECT id FROM sh_atelier_scenes WHERE tenant_id = ? AND item_sku = ? LIMIT 1'
        );
        $st->execute([$tenant_id, $itemSku]);
        $sidRow = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$sidRow) {
            studioResponse(false, null, 'Brak zapisanej sceny dla tego dania — użyj Zapisz scenę (Ctrl+S) w Scene Studio.');
        }
        $sceneId = (int)$sidRow['id'];

        $slotsIn = $input['slots'] ?? [];
        if (!is_array($slotsIn)) {
            studioResponse(false, null, 'slots musi być tablicą.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM sh_scene_promotion_slots WHERE scene_id = ?')->execute([$sceneId]);

            $ins = $pdo->prepare(
                'INSERT INTO sh_scene_promotion_slots
                    (scene_id, promotion_id, slot_x, slot_y, slot_z_index, display_order, is_active)
                 VALUES (?,?,?,?,?,?,1)'
            );
            $ord = 0;
            foreach ($slotsIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pid = (int)($row['promotionId'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $chk = $pdo->prepare('SELECT id FROM sh_promotions WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1');
                $chk->execute([$pid, $tenant_id]);
                if (!$chk->fetch()) {
                    continue;
                }
                $sx = isset($row['slotX']) ? max(0.0, min(100.0, (float)$row['slotX'])) : 50.0;
                $sy = isset($row['slotY']) ? max(0.0, min(100.0, (float)$row['slotY'])) : 50.0;
                $sz = isset($row['slotZIndex']) ? max(0, min(500, (int)$row['slotZIndex'])) : 100;
                $ins->execute([$sceneId, $pid, $sx, $sy, $sz, $ord]);
                $ord++;
            }
            $pdo->commit();
            studioResponse(true, ['sceneId' => $sceneId, 'saved' => $ord], 'Zapisano sloty promocji.');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            studioResponse(false, null, 'Błąd zapisu slotów: ' . $e->getMessage());
        }
    }

    studioResponse(false, null, "Nieznana akcja: {$action}");
} catch (\Throwable $e) {
    studioResponse(false, null, 'Blad serwera: ' . $e->getMessage());
}
