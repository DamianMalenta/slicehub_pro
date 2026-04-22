<?php
/**
 * SliceHub — Smart Asset Importer
 * scripts/smart_importer.php
 *
 * Scans /uploads/global_assets/ for .webp files, categorizes them via fuzzy
 * keyword matching, renames to strict ASCII keys, and seeds sh_global_assets.
 *
 * Run:  php scripts/smart_importer.php
 *   or  http://localhost/slicehub/scripts/smart_importer.php
 */

declare(strict_types=1);
set_time_limit(120);
error_reporting(E_ALL);

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: text/html; charset=utf-8');

// ─── Config ──────────────────────────────────────────────────────────────────
$assetDir = realpath(__DIR__ . '/../uploads/global_assets');
if (!$assetDir || !is_dir($assetDir)) {
    die("FATAL: Asset directory not found. Expected: /uploads/global_assets/\n");
}

require_once __DIR__ . '/../core/db_config.php';
if (!isset($pdo)) die("FATAL: No database connection.\n");

// ─── Keyword → Category / Sub-type / Z-order mapping ────────────────────────
// Order matters: first match wins. More specific keywords come before generic.
$RULES = [
    // Board
    ['keywords' => ['plate', 'board', 'wooden', 'talerz', 'deska'],     'category' => 'board',  'zOrder' => 0],
    // Base
    ['keywords' => ['dough', 'base', 'crust', 'ciasto'],                'category' => 'base',   'zOrder' => 10],
    // Sauce
    ['keywords' => ['sous', 'sauce', 'ketchup', 'pesto', 'bbq_sauce'],  'category' => 'sauce',  'zOrder' => 20],
    // Cheese
    ['keywords' => ['cheese', 'mozzarella', 'parmesan', 'cheddar', 'gouda', 'feta', 'ser'],
                                                                         'category' => 'cheese', 'zOrder' => 30],
    // Meat
    ['keywords' => ['bacon', 'salami', 'ham', 'pepperoni', 'sausage', 'prosciutto',
                    'chorizo', 'chicken', 'beef', 'meat', 'anchov'],
                                                                         'category' => 'meat',   'zOrder' => 40],
    // Veg (before herb so "pepper" doesn't accidentally match herb)
    ['keywords' => ['mushroom', 'onion', 'pepper', 'tomato', 'olive', 'pea',
                    'jalapeno', 'jalapeño', 'corn', 'broccoli', 'artichoke',
                    'pineapple', 'cucumber', 'zucchini', 'arugula', 'spinach',
                    'lettuce', 'cabbage', 'garlic', 'pickle'],
                                                                         'category' => 'veg',    'zOrder' => 50],
    // Herb (spices, herbs, leafy garnish)
    ['keywords' => ['basil', 'spiece', 'spice', 'layer', 'oregano', 'thyme',
                    'rosemary', 'parsley', 'cilantro', 'dill', 'mint', 'herb',
                    'chili_flake', 'sesame', 'seed'],
                                                                         'category' => 'herb',   'zOrder' => 60],
];

// ─── Helpers ─────────────────────────────────────────────────────────────────

function matchFile(string $filename, array $rules): ?array
{
    $lower = strtolower($filename);
    // Strip numeric prefixes like "0000_", "001-", etc.
    $cleaned = preg_replace('/^\d+[_\-\s]*/', '', $lower);
    // Also strip extension for matching
    $cleaned = preg_replace('/\.webp$/i', '', $cleaned);
    // Normalize separators
    $cleaned = str_replace(['-', '(', ')', '.', ','], ['_', '', '', '_', ''], $cleaned);

    foreach ($rules as $rule) {
        foreach ($rule['keywords'] as $kw) {
            if (stripos($cleaned, $kw) !== false) {
                return [
                    'category' => $rule['category'],
                    'subType'  => $kw,
                    'zOrder'   => $rule['zOrder'],
                ];
            }
        }
    }
    return null;
}

function checkAlphaFromHeader(string $path): bool
{
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

function getWebpDimensions(string $path): array
{
    $info = @getimagesize($path);
    if ($info && $info[0] > 0 && $info[1] > 0) {
        return [(int)$info[0], (int)$info[1]];
    }
    return [800, 800]; // fallback for bulk-converted 800x800
}

// ─── Phase 1: Scan ──────────────────────────────────────────────────────────
$files = glob($assetDir . DIRECTORY_SEPARATOR . '*.webp');
if (!$files) {
    die("No .webp files found in {$assetDir}\n");
}

$totalFiles = count($files);
$mapped   = [];
$unmapped = [];

// Track auto-increment counters per category_subtype
$counters = [];

foreach ($files as $filePath) {
    $originalName = basename($filePath);
    $match = matchFile($originalName, $RULES);

    if ($match === null) {
        $unmapped[] = $originalName;
        continue;
    }

    $cat = $match['category'];
    $sub = $match['subType'];
    $counterKey = $cat . '_' . $sub;
    $counters[$counterKey] = ($counters[$counterKey] ?? 0) + 1;
    $idx = $counters[$counterKey];

    $newFilename = "{$cat}_{$sub}_{$idx}.webp";
    $asciiKey    = "{$cat}_{$sub}_{$idx}";

    $mapped[] = [
        'originalPath' => $filePath,
        'originalName' => $originalName,
        'newFilename'  => $newFilename,
        'asciiKey'     => $asciiKey,
        'category'     => $cat,
        'subType'      => $sub,
        'zOrder'       => $match['zOrder'],
    ];
}

// ─── Phase 2: Rename files ──────────────────────────────────────────────────
$renameOk    = 0;
$renameFail  = 0;
$renameLog   = [];

foreach ($mapped as &$entry) {
    $src = $entry['originalPath'];
    $dst = $assetDir . DIRECTORY_SEPARATOR . $entry['newFilename'];

    // Don't overwrite if source = destination (already correct name)
    if (realpath($src) === realpath($dst)) {
        $renameOk++;
        $entry['finalPath'] = $dst;
        continue;
    }

    // Collision avoidance: if destination already exists, append extra suffix
    $attempt = 0;
    while (file_exists($dst) && realpath($src) !== realpath($dst)) {
        $attempt++;
        $base = pathinfo($entry['newFilename'], PATHINFO_FILENAME);
        $entry['newFilename'] = "{$base}_{$attempt}.webp";
        $entry['asciiKey']    = "{$base}_{$attempt}";
        $dst = $assetDir . DIRECTORY_SEPARATOR . $entry['newFilename'];
    }

    if (@rename($src, $dst)) {
        $renameOk++;
        $entry['finalPath'] = $dst;
    } else {
        $renameFail++;
        $entry['finalPath'] = $src; // keep original on failure
        $renameLog[] = "RENAME FAIL: {$entry['originalName']} → {$entry['newFilename']}";
    }
}
unset($entry);

// ─── Phase 3: Gather metadata ───────────────────────────────────────────────
foreach ($mapped as &$entry) {
    $fp = $entry['finalPath'];
    [$w, $h]  = getWebpDimensions($fp);
    $entry['width']         = $w;
    $entry['height']        = $h;
    $entry['hasAlpha']      = checkAlphaFromHeader($fp) ? 1 : 0;
    $entry['filesizeBytes'] = (int)@filesize($fp);
    $entry['targetPx']      = max($w, $h);
}
unset($entry);

// ─── Phase 4: Database reset & seed ─────────────────────────────────────────
$pdo->exec("TRUNCATE TABLE sh_global_assets");

$insertSql = "INSERT INTO sh_global_assets
    (tenant_id, ascii_key, category, sub_type, filename, width, height,
     has_alpha, filesize_bytes, z_order, target_px, is_active)
    VALUES
    (0, :ascii_key, :category, :sub_type, :filename, :width, :height,
     :has_alpha, :filesize_bytes, :z_order, :target_px, 1)";

$stmt = $pdo->prepare($insertSql);
$seeded    = 0;
$seedFails = [];

foreach ($mapped as $entry) {
    try {
        $stmt->execute([
            ':ascii_key'      => $entry['asciiKey'],
            ':category'       => $entry['category'],
            ':sub_type'       => $entry['subType'],
            ':filename'       => $entry['newFilename'],
            ':width'          => $entry['width'],
            ':height'         => $entry['height'],
            ':has_alpha'      => $entry['hasAlpha'],
            ':filesize_bytes' => $entry['filesizeBytes'],
            ':z_order'        => $entry['zOrder'],
            ':target_px'      => $entry['targetPx'],
        ]);
        $seeded++;
    } catch (PDOException $e) {
        $seedFails[] = "{$entry['asciiKey']}: {$e->getMessage()}";
    }
}

// ─── Phase 5: Report ────────────────────────────────────────────────────────

$unmappedCount = count($unmapped);
$seedFailCount = count($seedFails);

if ($isCli) {
    // ── Plain text report ──
    echo str_repeat('=', 70) . "\n";
    echo " SliceHub Smart Importer — Results\n";
    echo str_repeat('=', 70) . "\n\n";

    echo "  Total .webp files scanned:   {$totalFiles}\n";
    echo "  Matched & renamed:           {$renameOk}\n";
    echo "  Rename failures:             {$renameFail}\n";
    echo "  Successfully seeded to DB:   {$seeded}\n";
    echo "  DB insert failures:          {$seedFailCount}\n";
    echo "  Unmapped / Skipped:          {$unmappedCount}\n\n";

    if ($seeded > 0) {
        echo "── Seeded Assets (by category) " . str_repeat('─', 39) . "\n";
        $byCat = [];
        foreach ($mapped as $e) $byCat[$e['category']][] = $e;
        ksort($byCat);
        foreach ($byCat as $cat => $entries) {
            echo "\n  [{$cat}] — " . count($entries) . " assets\n";
            foreach ($entries as $e) {
                $size = round($e['filesizeBytes'] / 1024, 1);
                echo "    {$e['asciiKey']}  ({$e['width']}×{$e['height']}, {$size}KB, z:{$e['zOrder']})\n";
            }
        }
    }

    if ($unmappedCount > 0) {
        echo "\n── Unmapped Files (skipped, NOT renamed, NOT in DB) " . str_repeat('─', 18) . "\n";
        foreach ($unmapped as $u) echo "    ⚠ {$u}\n";
    }

    if ($seedFailCount > 0) {
        echo "\n── DB Insert Failures " . str_repeat('─', 48) . "\n";
        foreach ($seedFails as $f) echo "    ✗ {$f}\n";
    }

    if (!empty($renameLog)) {
        echo "\n── Rename Failures " . str_repeat('─', 51) . "\n";
        foreach ($renameLog as $r) echo "    ✗ {$r}\n";
    }

    echo "\n" . str_repeat('=', 70) . "\n";
    echo " Done.\n";

} else {
    // ── HTML report ──
    $catBreakdown = [];
    foreach ($mapped as $e) $catBreakdown[$e['category']][] = $e;
    ksort($catBreakdown);

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Smart Importer</title>';
    echo '<style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Inter",system-ui,sans-serif;background:#050505;color:#e0e0e0;padding:32px;line-height:1.6}
        h1{font-size:24px;font-weight:900;color:#FF8C00;margin-bottom:4px}
        h2{font-size:16px;font-weight:800;color:#ccc;margin:24px 0 8px;text-transform:uppercase;letter-spacing:0.05em}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:20px 0}
        .stat{background:#0e0e0e;border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:16px;text-align:center}
        .stat-val{font-size:28px;font-weight:900;color:#FF8C00}
        .stat-label{font-size:10px;font-weight:700;text-transform:uppercase;color:#555;letter-spacing:0.06em;margin-top:4px}
        .cat-block{background:#0a0a0a;border:1px solid rgba(255,255,255,.05);border-radius:12px;padding:16px;margin:8px 0}
        .cat-title{font-size:13px;font-weight:800;text-transform:uppercase;color:#FF8C00;margin-bottom:8px}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th{text-align:left;font-weight:700;color:#555;text-transform:uppercase;font-size:10px;letter-spacing:0.04em;padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.06)}
        td{padding:5px 8px;border-bottom:1px solid rgba(255,255,255,.03);font-family:"Courier New",monospace;font-size:11px}
        .warn{color:#f59e0b;font-weight:700}
        .err{color:#ef4444;font-weight:700}
        .ok{color:#22c55e;font-weight:700}
        .dim{color:#444}
        .thumb{width:48px;height:48px;object-fit:contain;border-radius:6px;background:#111;vertical-align:middle}
    </style></head><body>';

    echo '<h1>SliceHub Smart Importer</h1>';
    echo '<p style="color:#555;font-size:12px;font-weight:600;">Pipeline: Scan → Categorize → Rename → Seed DB</p>';

    echo '<div class="stat-grid">';
    echo "<div class='stat'><div class='stat-val'>{$totalFiles}</div><div class='stat-label'>Files Scanned</div></div>";
    echo "<div class='stat'><div class='stat-val ok'>{$seeded}</div><div class='stat-label'>Seeded to DB</div></div>";
    echo "<div class='stat'><div class='stat-val warn'>{$unmappedCount}</div><div class='stat-label'>Unmapped / Skipped</div></div>";
    echo "<div class='stat'><div class='stat-val err'>{$seedFailCount}</div><div class='stat-label'>DB Failures</div></div>";
    echo '</div>';

    foreach ($catBreakdown as $cat => $entries) {
        $n = count($entries);
        echo "<div class='cat-block'>";
        echo "<div class='cat-title'>{$cat} ({$n})</div>";
        echo '<table><tr><th></th><th>ASCII Key</th><th>Sub-type</th><th>Size</th><th>Alpha</th><th>File KB</th><th>z</th><th>Original</th></tr>';
        foreach ($entries as $e) {
            $sizeStr = "{$e['width']}×{$e['height']}";
            $alpha   = $e['hasAlpha'] ? '<span class="ok">✓</span>' : '<span class="err">✗</span>';
            $kb      = round($e['filesizeBytes'] / 1024, 1);
            $url     = '/slicehub/uploads/global_assets/' . rawurlencode($e['newFilename']);
            echo "<tr>";
            echo "<td><img src='{$url}' class='thumb' loading='lazy'></td>";
            echo "<td>{$e['asciiKey']}</td>";
            echo "<td>{$e['subType']}</td>";
            echo "<td>{$sizeStr}</td>";
            echo "<td>{$alpha}</td>";
            echo "<td>{$kb}</td>";
            echo "<td>{$e['zOrder']}</td>";
            echo "<td class='dim'>{$e['originalName']}</td>";
            echo "</tr>";
        }
        echo '</table></div>';
    }

    if ($unmappedCount > 0) {
        echo '<h2 style="color:#f59e0b">Unmapped Files (Skipped)</h2>';
        echo '<div class="cat-block">';
        echo '<table><tr><th>Original Filename</th><th>Status</th></tr>';
        foreach ($unmapped as $u) {
            echo "<tr><td>{$u}</td><td class='warn'>SKIPPED — no keyword match</td></tr>";
        }
        echo '</table></div>';
    }

    if ($seedFailCount > 0) {
        echo '<h2 style="color:#ef4444">DB Insert Failures</h2>';
        echo '<div class="cat-block">';
        foreach ($seedFails as $f) echo "<p class='err'>{$f}</p>";
        echo '</div>';
    }

    echo '</body></html>';
}
