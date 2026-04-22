<?php
declare(strict_types=1);

/*──────────────────────────────────────────────────────────────────────────────
 * import_scene_creator.php
 *
 * Imports the BEST webp layer from each category of the "Pizza Scene Creator"
 * pack into /uploads/global_assets/, then re-syncs the sh_global_assets table.
 *
 * Also seeds sh_board_companions with demo companion products
 * so the Cinematic Board has something to display.
 *
 * Run once: http://localhost/slicehub/scripts/import_scene_creator.php
 *──────────────────────────────────────────────────────────────────────────────*/

set_time_limit(120);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$packBase = 'C:/Users/Damian/Downloads/Pizza-Scene-Creator/png/png';
$destDir  = __DIR__ . '/../uploads/global_assets/';

if (!is_dir($packBase)) {
    die("FATAL: Pizza Scene Creator pack not found at: {$packBase}");
}
if (!is_dir($destDir)) {
    mkdir($destDir, 0777, true);
}

$pdo = new PDO(
    "mysql:host=localhost;dbname=slicehub_pro_v2;charset=utf8mb4",
    "root", "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── ASSET SELECTION ─────────────────────────────────────────────────────────
// Pick the best webp file from each category folder.
// Strategy: for each category, pick ONE small-indexed _wynik.webp (variant 0)
// and for ingredients with multi-qty, pick 2-3 variants for visual diversity.

$picks = [
    // [ source_folder, filename_pattern (glob), category, sub_type, z_order, max_picks ]
    ['plate',       '*plate*_wynik.webp',       'board',  'plate',     0,  1],
    ['pizza dough', '*pizza-dough*_wynik.webp',  'base',   'dough',    10,  1],
    ['sous',        '*sous*_wynik.webp',         'sauce',  'tomato',   20,  1],
    ['cheese',      '*cheese*_wynik.webp',       'cheese', 'mozzarella', 30, 1],
    ['baconsalami', '*bacon*_wynik.webp',        'meat',   'bacon',    40,  1],
    ['baconsalami', '*salami*_wynik.webp',       'meat',   'salami',   41,  1],
    ['mushroom',    '*mushroom*_wynik.webp',     'veg',    'mushroom', 50,  1],
    ['onion',       '*onion*_wynik.webp',        'veg',    'onion',    51,  1],
    ['olive',       '*olive*_wynik.webp',        'veg',    'olive',    52,  1],
    ['pepper',      '*pepper*_wynik.webp',       'veg',    'pepper',   53,  1],
    ['corn',        '*corn*_wynik.webp',         'veg',    'corn',     54,  1],
    ['cucumberg',   '*cucumber*_wynik.webp',     'veg',    'cucumber', 55,  1],
    ['tomato',      '*tomato_02*_wynik.webp',    'veg',    'tomato',   56,  1],
    ['basil',       '_0000_basil_wynik.webp',    'herb',   'basil',    60,  1],
    ['spiece',      '*spiece*_wynik.webp',       'herb',   'spiece',   61,  1],
    ['pea',         '*pea*_wynik.webp',          'veg',    'pea',      57,  1],
];

// ── CLEAN DESTINATION ───────────────────────────────────────────────────────
$existing = glob($destDir . '*.webp');
foreach ($existing as $f) {
    unlink($f);
}

// ── COPY BEST ASSETS ────────────────────────────────────────────────────────
$imported = [];
$errors   = [];

foreach ($picks as [$folder, $pattern, $cat, $sub, $z, $maxPicks]) {
    $srcDir = $packBase . '/' . $folder;
    if (!is_dir($srcDir)) {
        $errors[] = "Folder not found: {$folder}";
        continue;
    }

    $matches = glob($srcDir . '/' . $pattern);
    if (!$matches) {
        $errors[] = "No files matching {$pattern} in {$folder}";
        continue;
    }

    sort($matches);
    $picked = array_slice($matches, 0, $maxPicks);

    foreach ($picked as $idx => $srcFile) {
        $uid = substr(bin2hex(random_bytes(3)), 0, 6);
        $newName = "{$cat}_{$sub}_{$uid}.webp";
        $destPath = $destDir . $newName;

        if (copy($srcFile, $destPath)) {
            $imported[] = [
                'src'  => basename($srcFile),
                'file' => $newName,
                'key'  => pathinfo($newName, PATHINFO_FILENAME),
                'cat'  => $cat,
                'sub'  => $sub,
                'z'    => $z + $idx,
                'size' => filesize($destPath),
            ];
        } else {
            $errors[] = "Copy failed: {$srcFile} → {$destPath}";
        }
    }
}

// ── SYNC DATABASE ───────────────────────────────────────────────────────────
$pdo->exec("ALTER TABLE sh_global_assets
    MODIFY COLUMN category ENUM('board','base','sauce','cheese','meat','veg','herb','extra','misc')
    NOT NULL DEFAULT 'misc'");

$urlCol = $pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sh_global_assets'
      AND COLUMN_NAME = 'url'
")->fetchColumn();
if (!$urlCol) {
    $pdo->exec("ALTER TABLE sh_global_assets ADD COLUMN url VARCHAR(512) NULL AFTER filename");
}

$pdo->exec('TRUNCATE TABLE sh_global_assets');

$stmt = $pdo->prepare("
    INSERT INTO sh_global_assets
        (ascii_key, category, sub_type, filename, url, z_order, tenant_id)
    VALUES (:key, :cat, :sub, :file, :url, :z, 1)
");

$pdo->beginTransaction();
foreach ($imported as $r) {
    $stmt->execute([
        ':key'  => $r['key'],
        ':cat'  => $r['cat'],
        ':sub'  => $r['sub'],
        ':file' => $r['file'],
        ':url'  => '/slicehub/uploads/global_assets/' . $r['file'],
        ':z'    => $r['z'],
    ]);
}
$pdo->commit();

// ── SEED BOARD COMPANIONS ───────────────────────────────────────────────────
// Create demo companions IF the table exists and is empty.

$companionSeeded = false;
$companionErrors = [];

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'sh_board_companions'")->rowCount();
    if (!$tableCheck) {
        // Create the table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sh_board_companions (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id      INT UNSIGNED    NOT NULL,
                item_sku       VARCHAR(255)    NOT NULL,
                companion_sku  VARCHAR(255)    NOT NULL,
                companion_type ENUM('sauce','drink','side','dessert','extra') NOT NULL DEFAULT 'extra',
                board_slot     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                asset_filename VARCHAR(255)    NULL,
                display_order  INT             NOT NULL DEFAULT 0,
                is_active      TINYINT(1)      NOT NULL DEFAULT 1,
                created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bc (tenant_id, item_sku, companion_sku),
                KEY idx_bc_item (tenant_id, item_sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Check if any pizza items exist
    $pizzaItems = $pdo->query("
        SELECT ascii_key FROM sh_menu_items
        WHERE tenant_id = 1 AND ascii_key LIKE 'PIZZA%' AND is_deleted = 0
        ORDER BY display_order
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Define the ideal companion products for the Cinematic Board
    $demoCompanions = [
        ['sku' => 'DRINK_PEPSI_500',      'name' => 'Pepsi 0.5L',         'type' => 'drink',   'slot' => 0, 'price' => 6.00],
        ['sku' => 'SAUCE_GARLIC',          'name' => 'Sos czosnkowy',      'type' => 'sauce',   'slot' => 1, 'price' => 3.50],
        ['sku' => 'SIDE_FRIES',            'name' => 'Frytki',             'type' => 'side',    'slot' => 2, 'price' => 9.00],
        ['sku' => 'DESSERT_TIRAMISU',      'name' => 'Tiramisu',           'type' => 'dessert', 'slot' => 3, 'price' => 14.00],
        ['sku' => 'DRINK_WATER_500',       'name' => 'Woda mineralna 0.5L','type' => 'drink',   'slot' => 4, 'price' => 4.00],
        ['sku' => 'SAUCE_BBQ',             'name' => 'Sos BBQ',            'type' => 'sauce',   'slot' => 5, 'price' => 3.50],
    ];

    // Ensure these items exist in sh_menu_items
    $insertItem = $pdo->prepare("
        INSERT IGNORE INTO sh_menu_items
            (tenant_id, ascii_key, name, description, is_active, is_deleted, display_order)
        VALUES (1, :sku, :name, :desc, 1, 0, :ord)
    ");
    $insertPrice = $pdo->prepare("
        INSERT IGNORE INTO sh_price_tiers
            (tenant_id, target_type, target_sku, channel, price)
        VALUES (1, 'ITEM', :sku, 'Delivery', :price)
    ");

    foreach ($demoCompanions as $idx => $dc) {
        try {
            $insertItem->execute([
                ':sku'  => $dc['sku'],
                ':name' => $dc['name'],
                ':desc' => 'Companion product for Cinematic Board',
                ':ord'  => 900 + $idx,
            ]);
            $insertPrice->execute([':sku' => $dc['sku'], ':price' => $dc['price']]);
        } catch (Exception $e) {
            // Item may already exist - fine
        }
    }

    if (!empty($pizzaItems)) {
        $pdo->exec("DELETE FROM sh_board_companions WHERE tenant_id = 1");

        $compStmt = $pdo->prepare("
            INSERT INTO sh_board_companions
                (tenant_id, item_sku, companion_sku, companion_type, board_slot, display_order, is_active)
            VALUES (1, :pizza, :comp, :type, :slot, :ord, 1)
        ");

        foreach ($pizzaItems as $pizzaSku) {
            foreach ($demoCompanions as $idx => $dc) {
                try {
                    $compStmt->execute([
                        ':pizza' => $pizzaSku,
                        ':comp'  => $dc['sku'],
                        ':type'  => $dc['type'],
                        ':slot'  => $dc['slot'],
                        ':ord'   => $idx,
                    ]);
                } catch (Exception $e) {
                    // Duplicate - skip
                }
            }
        }
        $companionSeeded = true;
    } else {
        $companionErrors[] = 'No PIZZA items found in sh_menu_items (tenant_id=1)';
    }
} catch (Exception $e) {
    $companionErrors[] = $e->getMessage();
}

// ── HTML REPORT ─────────────────────────────────────────────────────────────
$totalImported = count($imported);
$cats = [];
foreach ($imported as $r) {
    $cats[$r['cat']] = ($cats[$r['cat']] ?? 0) + 1;
}
ksort($cats);
$totalSize = array_sum(array_column($imported, 'size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Import Scene Creator — Report</title>
<style>
  :root{--bg:#0c0c0c;--card:#161616;--border:#262626;--green:#00e676;--orange:#FF8C00;--dim:#666;--text:#d4d4d4;--red:#ef4444}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:32px}
  .wrap{max-width:960px;margin:0 auto}
  h1{font-size:1.4rem;color:var(--green);font-weight:700;margin-bottom:4px}
  h2{font-size:1.1rem;color:var(--orange);font-weight:700;margin:28px 0 12px;border-bottom:1px solid var(--border);padding-bottom:8px}
  .sub{font-size:.85rem;color:var(--dim);margin-bottom:20px}
  .pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
  .pill{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 14px;font-size:.85rem}
  .pill b{color:#4fc3f7;margin-left:4px}
  .pill.total{border-color:var(--green);color:var(--green)}
  table{width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:20px}
  thead th{text-align:left;color:var(--dim);font-weight:500;padding:8px;border-bottom:1px solid var(--border)}
  tbody td{padding:6px 8px;border-bottom:1px solid #1a1a1a;vertical-align:middle}
  .src{color:var(--dim);word-break:break-all}
  .new{color:var(--green);font-family:'Cascadia Code',monospace;word-break:break-all}
  .cat{color:#fdd835;font-weight:600}
  .z{text-align:center;color:#90a4ae}
  .sz{text-align:right;color:var(--dim)}
  .ok{color:var(--green);font-weight:700}
  .warn{color:var(--orange);font-weight:600}
  .err{color:var(--red);font-size:.85rem;margin:6px 0}
  .foot{margin-top:20px;font-size:.75rem;color:var(--dim);text-align:center}
  .preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin:16px 0}
  .preview-card{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;text-align:center}
  .preview-card img{width:100%;aspect-ratio:1;object-fit:contain;background:#0a0a0a}
  .preview-card p{font-size:.7rem;color:var(--dim);padding:6px 4px}
</style>
</head>
<body>
<div class="wrap">

<h1>PIZZA SCENE CREATOR — IMPORT COMPLETE</h1>
<p class="sub">Imported best layers from pack → /uploads/global_assets/ → sh_global_assets</p>

<div class="pills">
    <div class="pill total"><b><?= $totalImported ?> assets</b></div>
    <div class="pill"><?= number_format($totalSize / 1024, 0) ?> KB total</div>
    <?php foreach ($cats as $c => $n): ?>
        <div class="pill"><?= $c ?>:<b><?= $n ?></b></div>
    <?php endforeach; ?>
</div>

<?php if ($errors): ?>
    <h2>Import Warnings</h2>
    <?php foreach ($errors as $e): ?>
        <p class="err"><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Imported Assets</h2>
<table>
    <thead><tr><th>#</th><th>Source</th><th>→ Filename</th><th>Cat</th><th>Sub</th><th>z</th><th>Size</th></tr></thead>
    <tbody>
    <?php foreach ($imported as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td class="src"><?= htmlspecialchars($r['src']) ?></td>
            <td class="new"><?= $r['file'] ?></td>
            <td class="cat"><?= $r['cat'] ?></td>
            <td><?= $r['sub'] ?></td>
            <td class="z"><?= $r['z'] ?></td>
            <td class="sz"><?= number_format($r['size'] / 1024, 1) ?> KB</td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Asset Preview</h2>
<div class="preview">
<?php foreach ($imported as $r): ?>
    <div class="preview-card">
        <img src="/slicehub/uploads/global_assets/<?= $r['file'] ?>" alt="<?= $r['sub'] ?>" loading="lazy">
        <p><?= $r['cat'] ?> / <?= $r['sub'] ?></p>
    </div>
<?php endforeach; ?>
</div>

<h2>Cinematic Board — Companions</h2>
<?php if ($companionSeeded): ?>
    <p class="ok">✓ Board companions seeded successfully</p>
    <p class="sub">Companions assigned to pizza items using auto-detected types (drink/sauce/side/dessert).</p>
<?php else: ?>
    <p class="warn">⚠ Companions NOT seeded</p>
    <?php foreach ($companionErrors as $ce): ?>
        <p class="err"><?= htmlspecialchars($ce) ?></p>
    <?php endforeach; ?>
    <p class="sub" style="margin-top:8px">
        To see the Cinematic Board, you need both PIZZA items and non-pizza items (drinks, sauces, sides) in sh_menu_items.
        Run your seed script first, then re-run this import.
    </p>
<?php endif; ?>

<p class="foot">
    tenant_id = 1 · generated <?= date('Y-m-d H:i:s') ?><br>
    Next: open <a href="/slicehub/modules/online/index.html?tenant=1&sku=PIZZA_MARGHERITA" style="color:var(--orange)">the Cinematic Board</a>
</p>

</div>
</body>
</html>
