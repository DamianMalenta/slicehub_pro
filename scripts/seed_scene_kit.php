<?php
declare(strict_types=1);

/**
 * SliceHub — Scene Kit Seeder (Faza 2.2)
 *
 * Wgrywa systemowe (tenant_id = 0) assety Scene Kit do sh_assets + generuje
 * SVG placeholdery do uploads/assets/0/library/ + wypełnia
 * sh_scene_templates.scene_kit_assets_json dla wszystkich 8 system templates.
 *
 * 53 assety total:
 *   - 10 backgrounds (blaty, deski, marmur, łupek, metal, etc.)
 *   - 30 props (butelki, sztućce, szkło, zioła, dekoracje, deski)
 *   - 8 lights (presety oświetlenia jako SVG gradient masks)
 *   - 5 badges (promocje — amber/gold/red/neon/vintage)
 *
 * Strategia: SVG placeholdery z kolorem + label per asset. Manager może podmienić
 * dowolny placeholder na realne zdjęcie w Asset Studio (tab Assets) —
 * link sh_asset_links.asset_id zostanie, tylko storage_url się zmieni.
 *
 * IDEMPOTENT: INSERT IGNORE + UPDATE WHERE. Safe re-run.
 *
 * Uruchomienie:
 *   http://localhost/slicehub/scripts/seed_scene_kit.php
 *   lub: php scripts/seed_scene_kit.php
 */

require_once __DIR__ . '/../core/db_config.php';

if (!isset($pdo)) { die('DB FAIL'); }

$uploadsDir = __DIR__ . '/../uploads/assets/0/library';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

// =============================================================================
// Definicja 53 assetów
// Format: [ascii_key, category, sub_type, color_hex, label, z_order_hint, role_hint]
// =============================================================================

$backgrounds = [
    ['bg_rustic_wood_warm',  'board', 'wood_warm',   '#6b4423', 'Rustic Wood Warm',    10, 'surface'],
    ['bg_rustic_wood_dark',  'board', 'wood_dark',   '#2d1810', 'Rustic Wood Dark',    10, 'surface'],
    ['bg_marble_white',      'board', 'marble_white','#f3efe7', 'Marble White',        10, 'surface'],
    ['bg_marble_black',      'board', 'marble_black','#0e0e11', 'Marble Black',        10, 'surface'],
    ['bg_slate_dark',        'board', 'slate_dark',  '#1b1b1f', 'Slate Dark',          10, 'surface'],
    ['bg_linen_beige',       'board', 'linen_beige', '#d9cdb4', 'Linen Beige',         10, 'surface'],
    ['bg_concrete_gray',     'board', 'concrete',    '#6e6e6e', 'Concrete Gray',       10, 'surface'],
    ['bg_metal_brushed',     'board', 'metal',       '#7a7e84', 'Metal Brushed',       10, 'surface'],
    ['bg_chalkboard',        'board', 'chalkboard',  '#1f2a1b', 'Chalkboard',          10, 'surface'],
    ['bg_newspaper_vintage', 'board', 'newspaper',   '#e8dfc6', 'Newspaper Vintage',   10, 'surface'],
];

$props = [
    // Butelki (5)
    ['prop_bottle_oil',        'misc', 'bottle_oil',      '#8b6f2a', 'Oil Bottle',        60, 'companion'],
    ['prop_bottle_vinegar',    'misc', 'bottle_vinegar',  '#4a2012', 'Vinegar Bottle',    60, 'companion'],
    ['prop_bottle_tabasco',    'misc', 'bottle_tabasco',  '#b7191f', 'Tabasco Bottle',    60, 'companion'],
    ['prop_bottle_coca_cola',  'drink','bottle_cola',     '#3e0a0a', 'Coca-Cola Bottle',  65, 'companion'],
    ['prop_bottle_water',      'drink','bottle_water',    '#a7d8e6', 'Water Bottle',      65, 'companion'],

    // Sztućce (5)
    ['prop_fork_silver',       'misc', 'cutlery_fork',    '#c0c4c8', 'Silver Fork',       55, 'companion'],
    ['prop_knife_silver',      'misc', 'cutlery_knife',   '#c0c4c8', 'Silver Knife',      55, 'companion'],
    ['prop_spoon_silver',      'misc', 'cutlery_spoon',   '#c0c4c8', 'Silver Spoon',      55, 'companion'],
    ['prop_chopsticks_wood',   'misc', 'cutlery_chopstk', '#8b5a2b', 'Wood Chopsticks',   55, 'companion'],
    ['prop_chopsticks_metal',  'misc', 'cutlery_chopstk', '#8a8d93', 'Metal Chopsticks',  55, 'companion'],

    // Serwetki / tekstylia (5)
    ['prop_napkin_white',      'misc', 'napkin_white',    '#f4efe4', 'White Napkin',      40, 'companion'],
    ['prop_napkin_kraft',      'misc', 'napkin_kraft',    '#c4a478', 'Kraft Napkin',      40, 'companion'],
    ['prop_napkin_linen',      'misc', 'napkin_linen',    '#d8cdb4', 'Linen Napkin',      40, 'companion'],
    ['prop_napkin_red_check',  'misc', 'napkin_check',    '#a02b2b', 'Red Check Napkin',  40, 'companion'],
    ['prop_towel_waffle',      'misc', 'towel_waffle',    '#e5d8bd', 'Waffle Towel',      40, 'companion'],

    // Szkło (5)
    ['prop_glass_tall',        'drink','glass_tall',      '#d4ecf6', 'Tall Glass',        55, 'companion'],
    ['prop_glass_low',         'drink','glass_low',       '#d4ecf6', 'Low Glass',         55, 'companion'],
    ['prop_wine_glass',        'drink','glass_wine',      '#c9d9e6', 'Wine Glass',        55, 'companion'],
    ['prop_coffee_cup',        'drink','cup_coffee',      '#fafaf7', 'Coffee Cup',        55, 'companion'],
    ['prop_beer_bottle',       'drink','bottle_beer',     '#3e2711', 'Beer Bottle',       65, 'companion'],

    // Zioła / dekoracje (5)
    ['prop_basil_leaf',        'herb', 'herb_basil',      '#3a7d2b', 'Basil Leaf',        50, 'companion'],
    ['prop_rosemary_sprig',    'herb', 'herb_rosemary',   '#4a6a2d', 'Rosemary Sprig',    50, 'companion'],
    ['prop_pepper_shaker',     'misc', 'shaker_pepper',   '#1b1b1b', 'Pepper Shaker',     55, 'companion'],
    ['prop_salt_shaker',       'misc', 'shaker_salt',     '#f5f5f0', 'Salt Shaker',       55, 'companion'],
    ['prop_candle_glass',      'misc', 'candle_glass',    '#f2c566', 'Glass Candle',      55, 'companion'],

    // Deski / platery (5)
    ['prop_board_round',       'board','plate_round',     '#8b5a2b', 'Round Wood Board',  15, 'companion'],
    ['prop_board_rect',        'board','plate_rect',      '#8b5a2b', 'Rect Wood Board',   15, 'companion'],
    ['prop_board_bamboo',      'board','plate_bamboo',    '#c2a062', 'Bamboo Board',      15, 'companion'],
    ['prop_board_slate',       'board','plate_slate',     '#28282e', 'Slate Board',       15, 'companion'],
    ['prop_tray_metal',        'board','plate_metal',     '#9ba0a8', 'Metal Tray',        15, 'companion'],
];

$lights = [
    ['light_warm_top',         'misc', 'light_warm_top',     '#f3b360', 'Warm Top',           90, 'layer'],
    ['light_warm_rim',         'misc', 'light_warm_rim',     '#e89343', 'Warm Rim',           90, 'layer'],
    ['light_cold_side',        'misc', 'light_cold_side',    '#85b6e0', 'Cold Side',          90, 'layer'],
    ['light_soft_box',         'misc', 'light_soft_box',     '#ece5d3', 'Soft Box',           90, 'layer'],
    ['light_candle_glow',      'misc', 'light_candle',       '#e7a04b', 'Candle Glow',        90, 'layer'],
    ['light_golden_hour',      'misc', 'light_golden_hour',  '#d87f3e', 'Golden Hour',        90, 'layer'],
    ['light_dramatic_rim',     'misc', 'light_dramatic',     '#f16a4b', 'Dramatic Rim',       90, 'layer'],
    ['light_neon_pink',        'misc', 'light_neon_pink',    '#ec4899', 'Neon Pink',          90, 'layer'],
];

$badges = [
    ['badge_amber_discount',   'misc', 'badge_discount', '#f59e0b', '-50%',          95, 'icon'],
    ['badge_gold_limited',     'misc', 'badge_limited',  '#eab308', 'LIMITED',       95, 'icon'],
    ['badge_red_burst',        'misc', 'badge_burst',    '#dc2626', 'NEW!',          95, 'icon'],
    ['badge_neon_pink',        'misc', 'badge_neon',     '#ec4899', 'HOT',           95, 'icon'],
    ['badge_vintage_stamp',    'misc', 'badge_vintage',  '#78350f', 'POLECAMY',      95, 'icon'],
];

// =============================================================================
// SVG generator
// =============================================================================

function generateSvg(string $asciiKey, string $color, string $label, string $category): string {
    // Background — prosty gradient + noise illusion przez circles
    // Prop/light/badge — kolorowy kwadrat z labelem
    $w = 400;
    $h = 400;
    if ($category === 'board') {
        // Gradient background
        $c2 = darken($color, 0.3);
        $c3 = lighten($color, 0.15);
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}">
  <defs>
    <radialGradient id="g" cx="50%" cy="40%" r="70%">
      <stop offset="0%" stop-color="{$c3}"/>
      <stop offset="70%" stop-color="{$color}"/>
      <stop offset="100%" stop-color="{$c2}"/>
    </radialGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#g)"/>
  <text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle"
        fill="rgba(255,255,255,0.25)" font-size="22" font-family="sans-serif"
        font-weight="700" letter-spacing="2">{$label}</text>
  <text x="50%" y="58%" text-anchor="middle" dominant-baseline="middle"
        fill="rgba(255,255,255,0.12)" font-size="11" font-family="monospace">{$asciiKey}</text>
</svg>
SVG;
    }
    // Prop/light/badge — cienka ramka + kolorowe wypełnienie + label
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}">
  <rect x="20" y="20" width="360" height="360" rx="24" fill="{$color}" stroke="rgba(0,0,0,0.2)" stroke-width="2"/>
  <text x="50%" y="48%" text-anchor="middle" dominant-baseline="middle"
        fill="rgba(255,255,255,0.85)" font-size="28" font-family="sans-serif"
        font-weight="800" letter-spacing="1">{$label}</text>
  <text x="50%" y="60%" text-anchor="middle" dominant-baseline="middle"
        fill="rgba(255,255,255,0.4)" font-size="11" font-family="monospace">{$asciiKey}</text>
  <text x="50%" y="92%" text-anchor="middle" dominant-baseline="middle"
        fill="rgba(0,0,0,0.3)" font-size="9" font-family="sans-serif" font-weight="600"
        text-transform="uppercase" letter-spacing="2">SliceHub · Scene Kit placeholder</text>
</svg>
SVG;
}

function darken(string $hex, float $amount): string {
    $r = max(0, (int)(hexdec(substr($hex, 1, 2)) * (1 - $amount)));
    $g = max(0, (int)(hexdec(substr($hex, 3, 2)) * (1 - $amount)));
    $b = max(0, (int)(hexdec(substr($hex, 5, 2)) * (1 - $amount)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
function lighten(string $hex, float $amount): string {
    $r = min(255, (int)(hexdec(substr($hex, 1, 2)) + (255 - hexdec(substr($hex, 1, 2))) * $amount));
    $g = min(255, (int)(hexdec(substr($hex, 3, 2)) + (255 - hexdec(substr($hex, 3, 2))) * $amount));
    $b = min(255, (int)(hexdec(substr($hex, 5, 2)) + (255 - hexdec(substr($hex, 5, 2))) * $amount));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// =============================================================================
// Seed — generuj SVG + INSERT sh_assets
// =============================================================================

$stats = ['new' => 0, 'skip' => 0, 'err' => 0, 'files_written' => 0];

function seedAsset(PDO $pdo, string $uploadsDir, array $def, array &$stats): ?int {
    [$asciiKey, $category, $subType, $color, $label, $zOrder, $roleHint] = $def;
    $filename = $asciiKey . '.svg';
    $path = $uploadsDir . '/' . $filename;

    // Generuj SVG jeśli nie istnieje (idempotencja)
    if (!file_exists($path)) {
        $svg = generateSvg($asciiKey, $color, $label, $category);
        file_put_contents($path, $svg);
        $stats['files_written']++;
    }

    $storageUrl = 'uploads/assets/0/library/' . $filename;
    $size = filesize($path) ?: 0;
    $checksum = hash_file('sha256', $path);

    try {
        // Najpierw sprawdź czy już jest
        $chk = $pdo->prepare("SELECT id FROM sh_assets WHERE tenant_id = 0 AND ascii_key = ?");
        $chk->execute([$asciiKey]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            $stats['skip']++;
            return (int)$existingId;
        }
        $ins = $pdo->prepare(
            "INSERT INTO sh_assets
               (tenant_id, ascii_key, storage_url, storage_bucket,
                mime_type, width_px, height_px, filesize_bytes, has_alpha,
                checksum_sha256, role_hint, category, sub_type, z_order_hint,
                is_active, created_by_user)
             VALUES
               (0, ?, ?, 'library',
                'image/svg+xml', 400, 400, ?, 1,
                ?, ?, ?, ?, ?,
                1, 'scene_kit_seeder')"
        );
        $ins->execute([$asciiKey, $storageUrl, $size, $checksum, $roleHint, $category, $subType, $zOrder]);
        $stats['new']++;
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $stats['err']++;
        error_log("[seed_scene_kit] {$asciiKey}: " . $e->getMessage());
        return null;
    }
}

$idMap = ['backgrounds' => [], 'props' => [], 'lights' => [], 'badges' => []];

foreach ($backgrounds as $def) { $id = seedAsset($pdo, $uploadsDir, $def, $stats); if ($id) $idMap['backgrounds'][$def[0]] = $id; }
foreach ($props       as $def) { $id = seedAsset($pdo, $uploadsDir, $def, $stats); if ($id) $idMap['props'][$def[0]]       = $id; }
foreach ($lights      as $def) { $id = seedAsset($pdo, $uploadsDir, $def, $stats); if ($id) $idMap['lights'][$def[0]]      = $id; }
foreach ($badges      as $def) { $id = seedAsset($pdo, $uploadsDir, $def, $stats); if ($id) $idMap['badges'][$def[0]]      = $id; }

// =============================================================================
// Mapping: który template dostaje które assety w scene_kit_assets_json
// =============================================================================

// Helper — wybierz po ascii_key z listy idMap
function pick(array $map, array $keys): array {
    $out = [];
    foreach ($keys as $k) {
        if (isset($map[$k])) $out[] = $map[$k];
    }
    return $out;
}
// Wszystkie IDs z kategorii
function allIds(array $map): array { return array_values($map); }

$templatesKit = [
    'pizza_top_down' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_rustic_wood_warm','bg_rustic_wood_dark','bg_marble_white','bg_slate_dark','bg_newspaper_vintage']),
        'props'       => pick($idMap['props'], [
            'prop_basil_leaf','prop_rosemary_sprig','prop_bottle_oil','prop_bottle_vinegar','prop_pepper_shaker',
            'prop_napkin_white','prop_napkin_red_check','prop_fork_silver','prop_knife_silver',
            'prop_board_round','prop_board_rect'
        ]),
        'lights'      => pick($idMap['lights'], ['light_warm_top','light_golden_hour','light_soft_box','light_warm_rim']),
        'badges'      => allIds($idMap['badges']),
    ],
    'static_hero' => [
        'backgrounds' => allIds($idMap['backgrounds']),
        'props'       => allIds($idMap['props']),
        'lights'      => allIds($idMap['lights']),
        'badges'      => allIds($idMap['badges']),
    ],
    'pasta_bowl_placeholder' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_linen_beige','bg_marble_white','bg_rustic_wood_warm','bg_concrete_gray']),
        'props'       => pick($idMap['props'], [
            'prop_fork_silver','prop_spoon_silver','prop_basil_leaf','prop_rosemary_sprig',
            'prop_bottle_oil','prop_pepper_shaker','prop_napkin_linen','prop_napkin_kraft',
            'prop_glass_tall','prop_wine_glass'
        ]),
        'lights'      => pick($idMap['lights'], ['light_warm_top','light_golden_hour','light_soft_box']),
        'badges'      => allIds($idMap['badges']),
    ],
    'beverage_bottle_placeholder' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_slate_dark','bg_marble_black','bg_concrete_gray','bg_metal_brushed']),
        'props'       => pick($idMap['props'], [
            'prop_glass_tall','prop_glass_low','prop_wine_glass','prop_beer_bottle',
            'prop_coffee_cup','prop_napkin_white'
        ]),
        'lights'      => pick($idMap['lights'], ['light_cold_side','light_soft_box','light_neon_pink','light_dramatic_rim']),
        'badges'      => allIds($idMap['badges']),
    ],
    'burger_three_quarter_placeholder' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_rustic_wood_dark','bg_slate_dark','bg_metal_brushed','bg_chalkboard']),
        'props'       => pick($idMap['props'], [
            'prop_napkin_kraft','prop_napkin_red_check','prop_bottle_tabasco',
            'prop_fork_silver','prop_knife_silver','prop_tray_metal','prop_beer_bottle'
        ]),
        'lights'      => pick($idMap['lights'], ['light_warm_rim','light_dramatic_rim','light_golden_hour','light_warm_top']),
        'badges'      => allIds($idMap['badges']),
    ],
    'sushi_top_down_placeholder' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_slate_dark','bg_marble_black','bg_chalkboard','bg_rustic_wood_dark']),
        'props'       => pick($idMap['props'], [
            'prop_chopsticks_wood','prop_chopsticks_metal','prop_board_slate','prop_board_bamboo',
            'prop_napkin_linen','prop_pepper_shaker'
        ]),
        'lights'      => pick($idMap['lights'], ['light_cold_side','light_soft_box','light_warm_top']),
        'badges'      => allIds($idMap['badges']),
    ],
    'category_flat_table' => [
        'backgrounds' => allIds($idMap['backgrounds']),
        'props'       => pick($idMap['props'], [
            'prop_napkin_white','prop_napkin_linen','prop_candle_glass','prop_basil_leaf'
        ]),
        'lights'      => pick($idMap['lights'], ['light_warm_top','light_soft_box','light_candle_glow']),
        'badges'      => allIds($idMap['badges']),
    ],
    'category_hero_wall' => [
        'backgrounds' => pick($idMap['backgrounds'], ['bg_rustic_wood_dark','bg_slate_dark','bg_marble_black','bg_concrete_gray','bg_chalkboard']),
        'props'       => [],
        'lights'      => pick($idMap['lights'], ['light_dramatic_rim','light_golden_hour','light_neon_pink','light_warm_rim']),
        'badges'      => allIds($idMap['badges']),
    ],
];

$updStmt = $pdo->prepare(
    "UPDATE sh_scene_templates
     SET scene_kit_assets_json = ?
     WHERE tenant_id = 0 AND ascii_key = ?"
);
$chkStmt = $pdo->prepare(
    "SELECT id FROM sh_scene_templates WHERE tenant_id = 0 AND ascii_key = ?"
);
$updateStats = ['updated' => 0, 'unchanged' => 0, 'missing' => 0];
foreach ($templatesKit as $tplKey => $kit) {
    $chkStmt->execute([$tplKey]);
    if (!$chkStmt->fetchColumn()) { $updateStats['missing']++; continue; }
    $json = json_encode($kit, JSON_UNESCAPED_UNICODE);
    $r = $updStmt->execute([$json, $tplKey]);
    if ($r && $updStmt->rowCount() > 0) $updateStats['updated']++;
    else $updateStats['unchanged']++;
}

// =============================================================================
// Report (HTML if via browser, plain if CLI)
// =============================================================================

$totalAssets = count($backgrounds) + count($props) + count($lights) + count($badges);

if (php_sapi_name() === 'cli') {
    echo "=== Scene Kit Seed ===\n";
    echo "Total definitions: {$totalAssets}\n";
    echo "  backgrounds: " . count($backgrounds) . "\n";
    echo "  props:       " . count($props) . "\n";
    echo "  lights:      " . count($lights) . "\n";
    echo "  badges:      " . count($badges) . "\n\n";
    echo "Assets DB: new={$stats['new']}  skip(existing)={$stats['skip']}  err={$stats['err']}\n";
    echo "SVG files written: {$stats['files_written']}\n\n";
    echo "Scene Templates kit JSON: updated={$updateStats['updated']}  unchanged={$updateStats['unchanged']}  missing={$updateStats['missing']}\n";
    echo "\n=== Kit mapping (asset counts per template) ===\n";
    foreach ($templatesKit as $tpl => $kit) {
        printf("  %-40s bg=%-2d props=%-2d lights=%-2d badges=%-2d\n",
            $tpl,
            count($kit['backgrounds']),
            count($kit['props']),
            count($kit['lights']),
            count($kit['badges'])
        );
    }
    echo "\n=== DONE ===\n";
    exit;
}

// HTML report
?><!DOCTYPE html>
<html lang="pl"><head>
<meta charset="UTF-8"><title>SliceHub — Scene Kit Seed</title>
<style>
 body { background:#05050a; color:#e2e8f0; font-family:system-ui; padding:40px; }
 h1 { color:#a78bfa; }
 .box { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:20px; margin:16px 0; }
 .ok { color:#22c55e; } .err { color:#ef4444; }
 table { width:100%; border-collapse:collapse; font-size:12px; }
 th,td { padding:6px 12px; border-bottom:1px solid rgba(255,255,255,0.05); text-align:left; }
 code { color:#a78bfa; font-size:11px; }
 .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px; margin-top:16px; }
 .sw { aspect-ratio:1; border-radius:8px; border:1px solid rgba(255,255,255,0.1); overflow:hidden; position:relative; }
 .sw img { width:100%; height:100%; object-fit:cover; display:block; }
 .sw span { position:absolute; bottom:0; left:0; right:0; padding:4px 6px; background:rgba(0,0,0,0.6); font-size:9px; font-family:monospace; text-align:center; }
</style>
</head><body>
<h1>🎬 SliceHub · Scene Kit Seed</h1>

<div class="box">
  <h3>Assets summary</h3>
  <ul>
    <li>Total definitions: <strong><?= $totalAssets ?></strong></li>
    <li class="ok">New rows in sh_assets: <strong><?= $stats['new'] ?></strong></li>
    <li>Skipped (already existed): <strong><?= $stats['skip'] ?></strong></li>
    <li class="<?= $stats['err']>0?'err':'ok' ?>">Errors: <strong><?= $stats['err'] ?></strong></li>
    <li>SVG files written on disk: <strong><?= $stats['files_written'] ?></strong></li>
  </ul>
</div>

<div class="box">
  <h3>Scene Templates kit mapping</h3>
  <table>
    <tr><th>Template</th><th>bg</th><th>props</th><th>lights</th><th>badges</th></tr>
    <?php foreach ($templatesKit as $t => $k): ?>
    <tr>
      <td><code><?= htmlspecialchars($t) ?></code></td>
      <td><?= count($k['backgrounds']) ?></td>
      <td><?= count($k['props']) ?></td>
      <td><?= count($k['lights']) ?></td>
      <td><?= count($k['badges']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p>Updated: <span class="ok"><strong><?= $updateStats['updated'] ?></strong></span> &nbsp; Unchanged: <strong><?= $updateStats['unchanged'] ?></strong> &nbsp; Missing: <strong><?= $updateStats['missing'] ?></strong></p>
</div>

<div class="box">
  <h3>Generated SVG placeholders (first 20)</h3>
  <div class="grid">
    <?php foreach (array_slice(array_merge($backgrounds, $props, $lights, $badges), 0, 20) as $def): ?>
    <div class="sw">
      <img src="/slicehub/uploads/assets/0/library/<?= htmlspecialchars($def[0]) ?>.svg" alt="<?= htmlspecialchars($def[4]) ?>">
      <span><?= htmlspecialchars($def[0]) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:20px; color:#64748b; font-size:11px;">Podgląd tylko 20 z <?= $totalAssets ?>. Reszta jest w <code>/slicehub/uploads/assets/0/library/</code>. Manager może podmienić dowolny placeholder na realne zdjęcie w Asset Studio (tab Assets w Online Studio).</p>
</div>

<p style="text-align:center; margin-top:40px;">
  <a href="/slicehub/modules/online_studio/" style="color:#22d3ee;">→ Open Scene Studio</a>
  &nbsp; | &nbsp;
  <a href="/slicehub/modules/studio/" style="color:#22d3ee;">→ Open Menu Studio</a>
</p>
</body></html>
