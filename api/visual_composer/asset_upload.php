<?php
// =============================================================================
// SliceHub Visual Composer — Asset Upload (with auto-detect & DB insert)
// api/visual_composer/asset_upload.php
//
// Smarter wrapper over api_visual_studio.php:
//   - Validates files per type (layer/hero/surface/companion/thumbnail)
//   - Auto-detects sub_type from filename (tomato → tomato_veg, salami → meat)
//   - Inserts/updates sh_global_assets record (for layer/hero)
//   - Inserts sh_visual_layers record if item_sku provided (for layer)
//   - Supports DUAL-PHOTO: upload layer + hero in single request
//
// Multipart fields:
//   asset_layer   (optional file) — ingredient scatter layer (.webp/.png)
//   asset_hero    (optional file) — hero product photo (.webp/.png)
//   asset_type    (required)      — layer | hero | surface | companion | thumbnail
//   category      (optional)      — auto-detected from filename if not provided
//   sub_type      (optional)      — auto-detected from filename if not provided
//   item_sku      (optional)      — if set, also creates sh_visual_layers entry
//   z_index       (optional)      — starting z-index (auto-calculated if omitted)
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$response = ['success' => false, 'data' => null, 'message' => 'Server error.'];

// Per-type validation limits
$LIMITS = [
    'layer'     => ['max_bytes' => 3 * 1024 * 1024,   'min_w' => 1000, 'min_h' => 1000, 'max_w' => 3000, 'max_h' => 3000],
    'hero'      => ['max_bytes' => 1536 * 1024,       'min_w' => 400,  'min_h' => 400,  'max_w' => 1200, 'max_h' => 1200],
    'thumbnail' => ['max_bytes' => 800 * 1024,        'min_w' => 300,  'min_h' => 300,  'max_w' => 800,  'max_h' => 800],
    'surface'   => ['max_bytes' => 5 * 1024 * 1024,   'min_w' => 1920, 'min_h' => 1080, 'max_w' => 3840, 'max_h' => 2400],
    'companion' => ['max_bytes' => 1536 * 1024,       'min_w' => 400,  'min_h' => 400,  'max_w' => 1200, 'max_h' => 1200],
];

// Auto-detect sub_type and category from filename
$SUB_MAP = [
    'salami'     => ['cat' => 'meat', 'sub' => 'salami'],
    'pepperoni'  => ['cat' => 'meat', 'sub' => 'salami'],
    'bacon'      => ['cat' => 'meat', 'sub' => 'bacon'],
    'boczek'     => ['cat' => 'meat', 'sub' => 'bacon'],
    'szynka'     => ['cat' => 'meat', 'sub' => 'bacon'],
    'ham'        => ['cat' => 'meat', 'sub' => 'bacon'],
    'prosciutto' => ['cat' => 'meat', 'sub' => 'bacon'],
    'chorizo'    => ['cat' => 'meat', 'sub' => 'bacon'],
    'mozzarella' => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'ser'        => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'cheese'     => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'parmezan'   => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'gouda'      => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'cheddar'    => ['cat' => 'cheese', 'sub' => 'mozzarella'],
    'pomidor'    => ['cat' => 'veg', 'sub' => 'tomato_veg'],
    'tomato'     => ['cat' => 'sauce', 'sub' => 'tomato'],
    'sos'        => ['cat' => 'sauce', 'sub' => 'tomato'],
    'sauce'      => ['cat' => 'sauce', 'sub' => 'tomato'],
    'cherry'     => ['cat' => 'veg', 'sub' => 'tomato_veg'],
    'bazylia'    => ['cat' => 'herb', 'sub' => 'basil'],
    'basil'      => ['cat' => 'herb', 'sub' => 'basil'],
    'rukola'     => ['cat' => 'herb', 'sub' => 'basil'],
    'oregano'    => ['cat' => 'herb', 'sub' => 'basil'],
    'szpinak'    => ['cat' => 'herb', 'sub' => 'basil'],
    'pieczark'   => ['cat' => 'veg', 'sub' => 'mushroom'],
    'grzyb'      => ['cat' => 'veg', 'sub' => 'mushroom'],
    'mushroom'   => ['cat' => 'veg', 'sub' => 'mushroom'],
    'cebul'      => ['cat' => 'veg', 'sub' => 'onion'],
    'onion'      => ['cat' => 'veg', 'sub' => 'onion'],
    'oliwk'      => ['cat' => 'veg', 'sub' => 'olive'],
    'olive'      => ['cat' => 'veg', 'sub' => 'olive'],
    'papryk'     => ['cat' => 'veg', 'sub' => 'pepper'],
    'pepper'     => ['cat' => 'veg', 'sub' => 'pepper'],
    'chili'      => ['cat' => 'veg', 'sub' => 'pepper'],
    'jalapeno'   => ['cat' => 'veg', 'sub' => 'pepper'],
    'kukurydz'   => ['cat' => 'veg', 'sub' => 'corn'],
    'corn'       => ['cat' => 'veg', 'sub' => 'corn'],
    'ogor'       => ['cat' => 'veg', 'sub' => 'cucumber'],
    'cucumber'   => ['cat' => 'veg', 'sub' => 'cucumber'],
    'ciasto'     => ['cat' => 'base', 'sub' => 'dough'],
    'dough'      => ['cat' => 'base', 'sub' => 'dough'],
    'base'       => ['cat' => 'base', 'sub' => 'dough'],
    'gluten'     => ['cat' => 'base', 'sub' => 'dough'],
    'crust'      => ['cat' => 'base', 'sub' => 'dough'],
    'deska'      => ['cat' => 'board', 'sub' => 'plate'],
    'talerz'     => ['cat' => 'board', 'sub' => 'plate'],
    'plate'      => ['cat' => 'board', 'sub' => 'plate'],
    'board'      => ['cat' => 'board', 'sub' => 'plate'],
];

try {
    require_once '../../core/db_config.php';
    require_once '../../core/auth_guard.php';

    $assetType = trim($_POST['asset_type'] ?? 'layer');
    if (!isset($LIMITS[$assetType])) {
        throw new Exception("Nieznany typ assetu: {$assetType}");
    }

    $itemSku = trim($_POST['item_sku'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $subType  = trim($_POST['sub_type'] ?? '');
    $zIndex   = isset($_POST['z_index']) ? (int)$_POST['z_index'] : null;

    // ---------- Helper: auto-detect category/sub_type from filename ----------
    $detect = function ($filename) use ($SUB_MAP) {
        $low = strtolower($filename);
        $low = preg_replace('/[^a-z0-9_]/', '_', $low);
        foreach ($SUB_MAP as $keyword => $map) {
            if (str_contains($low, $keyword)) {
                return $map;
            }
        }
        return ['cat' => 'misc', 'sub' => 'unknown'];
    };

    // ---------- Helper: validate & save single file ----------
    $saveFile = function ($file, $type) use ($LIMITS, $tenant_id) {
        $limits = $LIMITS[$type];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Błąd uploadu pliku. Kod: ' . $file['error']);
        }
        if ($file['size'] > $limits['max_bytes']) {
            $mb = round($limits['max_bytes'] / (1024 * 1024), 1);
            throw new Exception("Plik za duży. Max dla \"{$type}\": {$mb} MB. Przesłano: " . round($file['size'] / (1024 * 1024), 2) . ' MB');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['webp', 'png'];
        if ($type === 'surface') { $allowedExt[] = 'jpg'; $allowedExt[] = 'jpeg'; }
        if (!in_array($ext, $allowedExt, true)) {
            throw new Exception('Dozwolone formaty: .' . implode(', .', $allowedExt) . '. Przesłano: .' . $ext);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMime = ['image/webp', 'image/png'];
        if ($type === 'surface') { $allowedMime[] = 'image/jpeg'; }
        if (!in_array($mime, $allowedMime, true)) {
            throw new Exception("Nieprawidłowy typ MIME: {$mime}");
        }

        $info = @getimagesize($file['tmp_name']);
        if (!$info) throw new Exception('Plik nie jest obrazem (złamany nagłówek).');

        [$w, $h] = $info;
        if ($w < $limits['min_w'] || $h < $limits['min_h']) {
            throw new Exception("Zdjęcie za małe dla \"{$type}\". Min: {$limits['min_w']}×{$limits['min_h']} px. Masz: {$w}×{$h} px.");
        }
        if ($w > $limits['max_w'] || $h > $limits['max_h']) {
            throw new Exception("Zdjęcie za duże dla \"{$type}\". Max: {$limits['max_w']}×{$limits['max_h']} px. Masz: {$w}×{$h} px.");
        }

        return ['width' => $w, 'height' => $h, 'ext' => $ext, 'size' => $file['size']];
    };

    // ---------- Helper: move file with unique hashed name ----------
    $commitFile = function ($file, $type, $safeCat, $safeSub, $ext) use ($tenant_id) {
        $hash = substr(hash_file('sha256', $file['tmp_name']), 0, 6);
        $name = "{$safeCat}_{$safeSub}_{$hash}.{$ext}";

        $dir = ($type === 'surface')
            ? realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global_assets'
            : realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'visual' . DIRECTORY_SEPARATOR . $tenant_id;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception('Nie można utworzyć katalogu assetów.');
        }

        $target = $dir . DIRECTORY_SEPARATOR . $name;
        $c = 0;
        while (file_exists($target)) {
            $c++;
            $name = "{$safeCat}_{$safeSub}_{$hash}_{$c}.{$ext}";
            $target = $dir . DIRECTORY_SEPARATOR . $name;
        }

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new Exception('Nie udało się zapisać pliku na dysku.');
        }
        return $name;
    };

    // ---------- Determine which slots are filled ----------
    $hasLayer = isset($_FILES['asset_layer']);
    $hasHero  = isset($_FILES['asset_hero']);
    $hasAsset = isset($_FILES['asset']); // backwards compat

    if (!$hasLayer && !$hasHero && !$hasAsset) {
        throw new Exception('Brak plików. Wyślij asset_layer lub asset_hero (lub asset dla starej logiki).');
    }

    // If single "asset" field, treat it as the primary type
    if ($hasAsset && !$hasLayer && !$hasHero) {
        if ($assetType === 'hero')       { $_FILES['asset_hero']  = $_FILES['asset']; $hasHero = true; }
        elseif ($assetType === 'layer')  { $_FILES['asset_layer'] = $_FILES['asset']; $hasLayer = true; }
        else { $_FILES['asset_layer'] = $_FILES['asset']; $hasLayer = true; } // surface/companion/thumbnail
    }

    // ---------- Validate both files first (atomic — fail early) ----------
    $layerInfo = null;
    $heroInfo  = null;
    if ($hasLayer) $layerInfo = $saveFile($_FILES['asset_layer'], $assetType === 'surface' ? 'surface' : ($assetType === 'companion' ? 'companion' : 'layer'));
    if ($hasHero)  $heroInfo  = $saveFile($_FILES['asset_hero'], 'hero');

    // ---------- Auto-detect category/sub_type if not provided ----------
    if ($category === '' || $subType === '') {
        $srcFile = $hasLayer ? $_FILES['asset_layer']['name'] : $_FILES['asset_hero']['name'];
        $detected = $detect($srcFile);
        if ($category === '') $category = $detected['cat'];
        if ($subType === '')  $subType  = $detected['sub'];
    }

    $safeCat = preg_replace('/[^a-z0-9]/', '', strtolower($category)) ?: 'misc';
    $safeSub = preg_replace('/[^a-z0-9_]/', '', strtolower($subType)) ?: 'unknown';

    // ---------- Commit files to disk ----------
    $layerFilename = null;
    $heroFilename  = null;
    if ($hasLayer) {
        $commitType = ($assetType === 'surface') ? 'surface' : 'layer';
        $layerFilename = $commitFile($_FILES['asset_layer'], $commitType, $safeCat, $safeSub, $layerInfo['ext']);
    }
    if ($hasHero) {
        $heroFilename = $commitFile($_FILES['asset_hero'], 'hero', $safeCat, $safeSub, $heroInfo['ext']);
    }

    // ---------- DB: sh_global_assets (for layers stored globally) ----------
    $globalAssetId = null;
    if ($layerFilename && in_array($assetType, ['layer', 'surface'], true) && $assetType !== 'surface') {
        // Only shared/global layers go into sh_global_assets. Surface goes to tenant_settings.
        try {
            $asciiKey = "{$safeCat}_{$safeSub}_" . substr(hash('sha256', $layerFilename), 0, 8);
            $zOrder = $zIndex ?? (int)[
                'board' => 0, 'base' => 10, 'sauce' => 20, 'cheese' => 30,
                'meat' => 40, 'veg' => 50, 'herb' => 60, 'misc' => 70,
            ][$safeCat] ?? 50;

            $stmt = $pdo->prepare(
                "INSERT INTO sh_global_assets
                 (tenant_id, ascii_key, category, sub_type, filename,
                  width, height, has_alpha, filesize_bytes, z_order, is_active)
                 VALUES (:tid, :ak, :cat, :sub, :fn, :w, :h, 1, :sz, :zo, 1)
                 ON DUPLICATE KEY UPDATE
                    filename = VALUES(filename),
                    width    = VALUES(width),
                    height   = VALUES(height),
                    filesize_bytes = VALUES(filesize_bytes),
                    z_order  = VALUES(z_order),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                ':tid' => $tenant_id,
                ':ak'  => $asciiKey,
                ':cat' => $safeCat === 'misc' ? 'misc' : $safeCat,
                ':sub' => $safeSub,
                ':fn'  => $layerFilename,
                ':w'   => $layerInfo['width'],
                ':h'   => $layerInfo['height'],
                ':sz'  => $layerInfo['size'],
                ':zo'  => $zOrder,
            ]);
            $globalAssetId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[VisualComposer] upload→sh_global_assets: ' . $e->getMessage());
        }
    }

    // ---------- DB: sh_visual_layers (if item_sku provided) ----------
    $visualLayerId = null;
    if ($itemSku !== '' && ($layerFilename || $heroFilename)) {
        try {
            $layerSku = strtoupper($safeCat . '_' . $safeSub);

            // Auto z_index: max existing + 10 if not specified
            if ($zIndex === null) {
                $mx = $pdo->prepare(
                    "SELECT MAX(z_index) FROM sh_visual_layers
                     WHERE tenant_id = :tid AND item_sku = :sku"
                );
                $mx->execute([':tid' => $tenant_id, ':sku' => $itemSku]);
                $maxZ = (int)$mx->fetchColumn();
                $zIndex = $maxZ + 10;
            }

            $isBase = in_array($safeCat, ['board', 'base'], true) ? 1 : 0;

            $stmt = $pdo->prepare(
                "INSERT INTO sh_visual_layers
                 (tenant_id, item_sku, layer_sku, asset_filename, product_filename,
                  z_index, is_base, cal_scale, cal_rotate, is_active)
                 VALUES (:tid, :item, :layer, :al, :ph, :zi, :ib, 1.00, 0, 1)
                 ON DUPLICATE KEY UPDATE
                    asset_filename  = COALESCE(VALUES(asset_filename),  asset_filename),
                    product_filename= COALESCE(VALUES(product_filename),product_filename),
                    z_index         = VALUES(z_index),
                    is_active       = 1,
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                ':tid'   => $tenant_id,
                ':item'  => $itemSku,
                ':layer' => $layerSku,
                ':al'    => $layerFilename,
                ':ph'    => $heroFilename,
                ':zi'    => $zIndex,
                ':ib'    => $isBase,
            ]);
            $visualLayerId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[VisualComposer] upload→sh_visual_layers: ' . $e->getMessage());
        }
    }

    $response['success'] = true;
    $response['message'] = 'Asset wgrany pomyślnie.';
    $response['data'] = [
        'assetType'       => $assetType,
        'category'        => $safeCat,
        'subType'         => $safeSub,
        'layerFilename'   => $layerFilename,
        'heroFilename'    => $heroFilename,
        'layerUrl'        => $layerFilename
            ? ($assetType === 'surface'
                ? '/slicehub/uploads/global_assets/' . $layerFilename
                : '/slicehub/uploads/visual/' . $tenant_id . '/' . $layerFilename)
            : null,
        'heroUrl'         => $heroFilename
            ? '/slicehub/uploads/visual/' . $tenant_id . '/' . $heroFilename
            : null,
        'globalAssetId'   => $globalAssetId,
        'visualLayerId'   => $visualLayerId,
        'dimensions'      => $layerInfo ?: $heroInfo,
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('[VisualComposer] upload: ' . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
