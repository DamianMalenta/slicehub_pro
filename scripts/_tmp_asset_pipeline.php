<?php
// =============================================================================
// SliceHub — Asset Pipeline: Delete → Resize → Rename → SQL Seed
// Run with: C:\xampp\php\php.exe -d extension=gd scripts/_tmp_asset_pipeline.php
//
// Performs:
//   1. Deletes excluded files (opaque junk, reference photos, cheese strip)
//   2. Resizes remaining .webp to target px (1000 for board/sauce, 500 for toppings)
//   3. Renames to ASCII technical keys
//   4. Generates SQL INSERT for sh_global_assets + runs against DB
// =============================================================================

$dir = realpath(__DIR__ . '/../uploads/global_assets');
if (!$dir) die("ERROR: uploads/global_assets not found.\n");

echo "=== SliceHub Asset Pipeline ===\n";
echo "Directory: {$dir}\n\n";

// =========================================================================
// STEP 0: MANIFEST — Approved mapping from analysis
// =========================================================================

$DELETE = [
    'pizza_base_gluten_new_wynik_wynik.webp',
    '_0012_cheese_wynik_wynik.webp',
    'green-olives-with-leaves-isolated-white-background_wynik_wynik.webp',
    '__0022_01_wynik_wynik.webp',
    'c0631b73-1f41-4d0b-83ec-217381e6d33a_wynik_wynik.webp',
];

$RENAME_MAP = [
    '__0000_plate_05_wynik_wynik.webp'   => ['key' => 'board_plate_layer_1',     'cat' => 'board',  'sub' => 'plate',      'target' => 1000, 'z' => 5],
    '__0005_sous_25_wynik_wynik.webp'    => ['key' => 'sauce_tomato_layer_1',     'cat' => 'sauce',  'sub' => 'tomato',     'target' => 1000, 'z' => 10],
    '__0021_sous_09_wynik_wynik.webp'    => ['key' => 'sauce_tomato_layer_2',     'cat' => 'sauce',  'sub' => 'tomato',     'target' => 1000, 'z' => 10],
    '_0013_cheese_wynik_wynik.webp'      => ['key' => 'cheese_mozzarella_layer_1','cat' => 'cheese', 'sub' => 'mozzarella', 'target' => 500,  'z' => 20],
    '_0000_bacon_wynik_wynik.webp'       => ['key' => 'meat_bacon_layer_1',       'cat' => 'meat',   'sub' => 'bacon',      'target' => 500,  'z' => 40],
    '_0005_salami_wynik_wynik.webp'      => ['key' => 'meat_salami_layer_1',      'cat' => 'meat',   'sub' => 'salami',     'target' => 500,  'z' => 40],
    'corn_02_wynik_wynik.webp'           => ['key' => 'veg_corn_layer_1',         'cat' => 'veg',    'sub' => 'corn',       'target' => 500,  'z' => 50],
    '_0002_mushroom_wynik_wynik.webp'    => ['key' => 'veg_mushroom_layer_1',     'cat' => 'veg',    'sub' => 'mushroom',   'target' => 500,  'z' => 50],
    '_0004_mushroom_wynik_wynik.webp'    => ['key' => 'veg_mushroom_layer_2',     'cat' => 'veg',    'sub' => 'mushroom',   'target' => 500,  'z' => 50],
    '_0004_olive_wynik_wynik.webp'       => ['key' => 'veg_olive_layer_1',        'cat' => 'veg',    'sub' => 'olive',      'target' => 500,  'z' => 50],
    '_0000_onion_wynik_wynik.webp'       => ['key' => 'veg_onion_layer_1',        'cat' => 'veg',    'sub' => 'onion',      'target' => 500,  'z' => 50],
    '_0064_onion_wynik_wynik.webp'       => ['key' => 'veg_onion_layer_2',        'cat' => 'veg',    'sub' => 'onion',      'target' => 500,  'z' => 50],
    '_0000_pepper (2)_wynik_wynik.webp'  => ['key' => 'veg_pepper_layer_1',       'cat' => 'veg',    'sub' => 'pepper',     'target' => 500,  'z' => 50],
    '_0003_pepper (2)_wynik_wynik.webp'  => ['key' => 'veg_pepper_layer_2',       'cat' => 'veg',    'sub' => 'pepper',     'target' => 500,  'z' => 50],
    '_0001_tomato_02_wynik_wynik.webp'   => ['key' => 'veg_tomato_layer_1',       'cat' => 'veg',    'sub' => 'tomato',     'target' => 500,  'z' => 50],
    '_0026_tomato_27_wynik_wynik.webp'   => ['key' => 'veg_tomato_layer_2',       'cat' => 'veg',    'sub' => 'tomato',     'target' => 500,  'z' => 50],
    '_0008_basil_wynik_wynik.webp'       => ['key' => 'herb_basil_layer_1',       'cat' => 'herb',   'sub' => 'basil',      'target' => 500,  'z' => 60],
    '_0023_basil_wynik_wynik.webp'       => ['key' => 'herb_basil_layer_2',       'cat' => 'herb',   'sub' => 'basil',      'target' => 500,  'z' => 60],
];

// =========================================================================
// STEP 1: DELETE excluded files
// =========================================================================
echo "--- STEP 1: Deleting excluded files ---\n";
foreach ($DELETE as $f) {
    $path = $dir . DIRECTORY_SEPARATOR . $f;
    if (file_exists($path)) {
        if (unlink($path)) {
            echo "  DELETED: {$f}\n";
        } else {
            echo "  FAILED TO DELETE: {$f}\n";
        }
    } else {
        echo "  SKIP (not found): {$f}\n";
    }
}
echo "\n";

// =========================================================================
// STEP 2 + 3: Resize + Rename
// =========================================================================
echo "--- STEP 2+3: Resize & Rename ---\n";

$processed = [];

foreach ($RENAME_MAP as $origName => $meta) {
    $origPath = $dir . DIRECTORY_SEPARATOR . $origName;
    $newFilename = $meta['key'] . '.webp';
    $newPath = $dir . DIRECTORY_SEPARATOR . $newFilename;
    $target = $meta['target'];

    if (!file_exists($origPath)) {
        echo "  SKIP (missing): {$origName}\n";
        continue;
    }

    // Load WebP
    $img = @imagecreatefromwebp($origPath);
    if (!$img) {
        echo "  ERROR (load failed): {$origName}\n";
        continue;
    }

    $origW = imagesx($img);
    $origH = imagesy($img);
    $longest = max($origW, $origH);

    if ($longest > $target) {
        // Downscale proportionally
        $scale = $target / $longest;
        $newW = (int)round($origW * $scale);
        $newH = (int)round($origH * $scale);

        $resized = imagecreatetruecolor($newW, $newH);

        // Preserve alpha
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        // High-quality resample
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($img);
        $img = $resized;

        echo "  RESIZED: {$origW}x{$origH} -> {$newW}x{$newH} ({$origName})\n";
    } else {
        $newW = $origW;
        $newH = $origH;
        echo "  OK (no resize needed): {$origW}x{$origH} ({$origName})\n";
    }

    // Save as WebP (quality 82 — good balance of size vs clarity)
    imagesavealpha($img, true);
    $saved = imagewebp($img, $newPath, 82);
    imagedestroy($img);

    if (!$saved) {
        echo "  ERROR (save failed): {$newFilename}\n";
        continue;
    }

    // Delete original if different name
    if ($origName !== $newFilename && file_exists($origPath)) {
        unlink($origPath);
    }

    $finalSize = filesize($newPath);

    // Verify alpha on new file
    $hasAlpha = checkAlphaFromHeader($newPath);

    echo "  RENAMED: {$origName} -> {$newFilename} ({$newW}x{$newH}, " . round($finalSize / 1024, 1) . " KB)\n";

    $processed[] = [
        'ascii_key'  => $meta['key'],
        'category'   => $meta['cat'],
        'sub_type'   => $meta['sub'],
        'filename'   => $newFilename,
        'width'      => $newW,
        'height'     => $newH,
        'has_alpha'  => $hasAlpha ? 1 : 0,
        'filesize'   => $finalSize,
        'z_order'    => $meta['z'],
        'target_px'  => $meta['target'],
    ];
}

echo "\nProcessed: " . count($processed) . " / " . count($RENAME_MAP) . " assets\n\n";

// =========================================================================
// STEP 4: Generate SQL + Execute
// =========================================================================
echo "--- STEP 4: SQL Seed ---\n";

$sqlLines = [];
$sqlLines[] = "-- Auto-generated by _tmp_asset_pipeline.php on " . date('Y-m-d H:i:s');
$sqlLines[] = "-- Clears and re-seeds sh_global_assets with processed assets";
$sqlLines[] = "";
$sqlLines[] = "TRUNCATE TABLE sh_global_assets;";
$sqlLines[] = "";
$sqlLines[] = "INSERT INTO sh_global_assets (tenant_id, ascii_key, category, sub_type, filename, width, height, has_alpha, filesize_bytes, z_order, target_px, is_active) VALUES";

$valueRows = [];
foreach ($processed as $p) {
    $valueRows[] = sprintf(
        "  (0, '%s', '%s', '%s', '%s', %d, %d, %d, %d, %d, %d, 1)",
        addslashes($p['ascii_key']),
        addslashes($p['category']),
        addslashes($p['sub_type']),
        addslashes($p['filename']),
        $p['width'],
        $p['height'],
        $p['has_alpha'],
        $p['filesize'],
        $p['z_order'],
        $p['target_px']
    );
}

$sqlLines[] = implode(",\n", $valueRows) . ";";

$sql = implode("\n", $sqlLines);

// Save SQL file
$sqlPath = __DIR__ . '/_tmp_asset_seed.sql';
file_put_contents($sqlPath, $sql);
echo "SQL saved to: {$sqlPath}\n\n";

echo $sql . "\n\n";

// Execute against DB
echo "--- Executing against database ---\n";
try {
    require_once __DIR__ . '/../core/db_config.php';
    if (!isset($pdo)) throw new Exception('No $pdo');

    // Create table if not exists
    $migrationSql = file_get_contents(__DIR__ . '/../database/migrations/014_global_assets.sql');
    // Strip USE and SET lines for PDO execution
    $migrationSql = preg_replace('/^USE\s+.*$/m', '', $migrationSql);
    $migrationSql = preg_replace('/^SET\s+.*$/m', '', $migrationSql);
    $pdo->exec($migrationSql);
    echo "  Table sh_global_assets: ensured.\n";

    // Truncate + Insert
    $pdo->exec("TRUNCATE TABLE sh_global_assets");
    echo "  Table truncated.\n";

    $stmt = $pdo->prepare(
        "INSERT INTO sh_global_assets (tenant_id, ascii_key, category, sub_type, filename, width, height, has_alpha, filesize_bytes, z_order, target_px, is_active)
         VALUES (0, :key, :cat, :sub, :file, :w, :h, :alpha, :size, :z, :tpx, 1)"
    );

    foreach ($processed as $p) {
        $stmt->execute([
            ':key'   => $p['ascii_key'],
            ':cat'   => $p['category'],
            ':sub'   => $p['sub_type'],
            ':file'  => $p['filename'],
            ':w'     => $p['width'],
            ':h'     => $p['height'],
            ':alpha' => $p['has_alpha'],
            ':size'  => $p['filesize'],
            ':z'     => $p['z_order'],
            ':tpx'   => $p['target_px'],
        ]);
    }
    echo "  Inserted " . count($processed) . " rows.\n";

    // Verify
    $count = $pdo->query("SELECT COUNT(*) FROM sh_global_assets")->fetchColumn();
    echo "  Verification: {$count} rows in sh_global_assets.\n";

} catch (Exception $e) {
    echo "  DB ERROR: " . $e->getMessage() . "\n";
}

// =========================================================================
// FINAL REPORT
// =========================================================================
echo "\n--- FINAL DIRECTORY STATE ---\n";
$remaining = glob($dir . DIRECTORY_SEPARATOR . '*.webp');
$totalKB = 0;
foreach ($remaining as $f) {
    $name = basename($f);
    $size = filesize($f);
    $totalKB += $size;
    $info = @getimagesize($f);
    $dims = $info ? "{$info[0]}x{$info[1]}" : 'N/A';
    printf("  %-40s  %s  %s KB\n", $name, $dims, round($size / 1024, 1));
}
echo "\n  Total: " . count($remaining) . " files, " . round($totalKB / 1024 / 1024, 2) . " MB\n";
echo "\n=== PIPELINE COMPLETE ===\n";

// =========================================================================
// HELPER: Read alpha from WebP binary header (no GD needed)
// =========================================================================
function checkAlphaFromHeader(string $path): bool {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $header = fread($fh, 30);
    fclose($fh);
    if (strlen($header) < 21) return false;
    if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') return false;

    $chunkType = substr($header, 12, 4);
    if ($chunkType === 'VP8X') {
        return (bool)(ord($header[20]) & 0x10);
    }
    if ($chunkType === 'VP8L' && strlen($header) >= 25) {
        $bits = ord($header[21]) | (ord($header[22]) << 8) | (ord($header[23]) << 16) | (ord($header[24]) << 24);
        return (bool)(($bits >> 28) & 0x1);
    }
    return false;
}
