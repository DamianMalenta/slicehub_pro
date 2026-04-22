<?php
declare(strict_types=1);

/*──────────────────────────────────────────────────────────────────────────────
 * bulk_import_zdjecia.php
 *
 * BULK IMPORTER for Manager's hand-crafted asset folder C:\xampp\htdocs\ZDJECIA.
 *
 * Pipeline:
 *   1) TRUNCATE sh_global_assets + wipe /uploads/global_assets/*.{webp,jpg,png}
 *   2) Scan C:\xampp\htdocs\ZDJECIA\*.webp (168 expected layers)
 *   3) Copy each file → /uploads/global_assets/<same name>
 *   4) INSERT row into sh_global_assets (parses category from filename prefix)
 *   5) Bonus: copy wooden-plank surface from
 *      C:\Users\Damian\Downloads\Pizza-Scene-Creator\__0022_01.jpg
 *      → /uploads/global_assets/surface_wood_plank_v1.jpg + DB row.
 *   6) Render HTML report w/ thumbnails, counters, errors.
 *
 * SAFE TO RE-RUN. Does not touch sh_menu_items, sh_visual_layers,
 * sh_board_companions, sh_recipes — only sh_global_assets.
 *
 * Usage: http://localhost/slicehub/scripts/bulk_import_zdjecia.php
 *──────────────────────────────────────────────────────────────────────────────*/

set_time_limit(300);
ini_set('memory_limit', '256M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/db_config.php';
/** @var PDO $pdo */

$srcDir   = 'C:/xampp/htdocs/ZDJECIA';
$destDir  = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global_assets';
$destUrl  = '/slicehub/uploads/global_assets';
$surfaceSrc = 'C:/Users/Damian/Downloads/Pizza-Scene-Creator/__0022_01.jpg';
$surfaceName = 'surface_wood_plank_v1.jpg';

if (!is_dir($srcDir)) {
    die("FATAL: Source folder not found: {$srcDir}");
}
if (!is_dir($destDir)) {
    mkdir($destDir, 0777, true);
}

// Z-order policy by category (lower = behind)
$Z_ORDER = [
    'board'  => 0,
    'base'   => 10,
    'sauce'  => 20,
    'cheese' => 30,
    'meat'   => 40,
    'veg'    => 50,
    'herb'   => 60,
    'extra'  => 70,
    'misc'   => 99,
];

// ─── STEP 1: ENSURE SCHEMA ──────────────────────────────────────────────────
$pdo->exec("ALTER TABLE sh_global_assets
    MODIFY COLUMN category ENUM('board','base','sauce','cheese','meat','veg','herb','extra','misc')
    NOT NULL DEFAULT 'misc'");

$urlCol = (int)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sh_global_assets'
      AND COLUMN_NAME = 'url'
")->fetchColumn();
if ($urlCol === 0) {
    $pdo->exec("ALTER TABLE sh_global_assets ADD COLUMN url VARCHAR(512) NULL AFTER filename");
}

// ─── STEP 2: WIPE OLD ASSETS ────────────────────────────────────────────────
$wipedFiles = 0;
foreach (['*.webp', '*.jpg', '*.jpeg', '*.png'] as $glob) {
    foreach (glob($destDir . DIRECTORY_SEPARATOR . $glob) ?: [] as $f) {
        if (@unlink($f)) {
            $wipedFiles++;
        }
    }
}
$wipedRows = $pdo->exec('TRUNCATE TABLE sh_global_assets');

// ─── STEP 3: SCAN SOURCE ────────────────────────────────────────────────────
$files = glob($srcDir . DIRECTORY_SEPARATOR . '*.webp') ?: [];
sort($files);

$imported = [];
$skipped  = [];
$errors   = [];

$insertStmt = $pdo->prepare("
    INSERT INTO sh_global_assets
        (tenant_id, ascii_key, category, sub_type, filename, url, width, height,
         has_alpha, filesize_bytes, z_order, target_px, is_active, created_at)
    VALUES
        (1, :key, :cat, :sub, :file, :url, :w, :h, 1, :sz, :z, 500, 1, NOW())
");

$pdo->beginTransaction();

foreach ($files as $srcPath) {
    $base = pathinfo($srcPath, PATHINFO_FILENAME);
    $parts = explode('_', $base);
    if (count($parts) < 2) {
        $skipped[] = ['file' => basename($srcPath), 'reason' => 'unparseable name'];
        continue;
    }

    $category = strtolower($parts[0]);
    $subType  = strtolower($parts[1] ?? 'misc');

    if (!isset($Z_ORDER[$category])) {
        $skipped[] = ['file' => basename($srcPath), 'reason' => "unknown category '{$category}'"];
        continue;
    }

    $destPath = $destDir . DIRECTORY_SEPARATOR . basename($srcPath);

    if (!@copy($srcPath, $destPath)) {
        $errors[] = "Copy failed: " . basename($srcPath);
        continue;
    }

    $size = (int)@filesize($destPath);
    [$w, $h] = @getimagesize($destPath) ?: [0, 0];

    try {
        $insertStmt->execute([
            ':key'  => $base,
            ':cat'  => $category,
            ':sub'  => substr($subType, 0, 64),
            ':file' => basename($destPath),
            ':url'  => $destUrl . '/' . basename($destPath),
            ':w'    => (int)$w,
            ':h'    => (int)$h,
            ':sz'   => $size,
            ':z'    => $Z_ORDER[$category],
        ]);
        $imported[] = [
            'file' => basename($destPath),
            'key'  => $base,
            'cat'  => $category,
            'sub'  => $subType,
            'w'    => (int)$w,
            'h'    => (int)$h,
            'size' => $size,
            'z'    => $Z_ORDER[$category],
        ];
    } catch (Throwable $e) {
        $errors[] = 'INSERT failed for ' . basename($srcPath) . ': ' . $e->getMessage();
        @unlink($destPath);
    }
}

$pdo->commit();

// ─── STEP 4: SURFACE BACKGROUND (wooden plank) ──────────────────────────────
$surfaceImported = false;
$surfaceMessage  = '';

if (is_file($surfaceSrc)) {
    $surfDest = $destDir . DIRECTORY_SEPARATOR . $surfaceName;
    if (@copy($surfaceSrc, $surfDest)) {
        [$sw, $sh] = @getimagesize($surfDest) ?: [0, 0];
        $sz = (int)@filesize($surfDest);

        try {
            $insertStmt->execute([
                ':key'  => 'surface_wood_plank_v1',
                ':cat'  => 'misc',
                ':sub'  => 'surface',
                ':file' => $surfaceName,
                ':url'  => $destUrl . '/' . $surfaceName,
                ':w'    => (int)$sw,
                ':h'    => (int)$sh,
                ':sz'   => $sz,
                ':z'    => 999, // background — should never overlap stack
            ]);
            $surfaceImported = true;
            $surfaceMessage  = "OK — {$sw}×{$sh}px, " . round($sz / 1024) . " KB";
            $imported[] = [
                'file' => $surfaceName,
                'key'  => 'surface_wood_plank_v1',
                'cat'  => 'misc',
                'sub'  => 'surface',
                'w'    => (int)$sw,
                'h'    => (int)$sh,
                'size' => $sz,
                'z'    => 999,
            ];
        } catch (Throwable $e) {
            $surfaceMessage = 'INSERT failed: ' . $e->getMessage();
        }
    } else {
        $surfaceMessage = 'Copy failed.';
    }
} else {
    $surfaceMessage = "File not found: {$surfaceSrc}";
}

// ─── REPORT DATA ────────────────────────────────────────────────────────────
$catCounts = [];
$totalSize = 0;
foreach ($imported as $r) {
    $catCounts[$r['cat']] = ($catCounts[$r['cat']] ?? 0) + 1;
    $totalSize += $r['size'];
}
ksort($catCounts);

?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Bulk Import ZDJECIA — Raport</title>
<style>
  :root{--bg:#0c0c0c;--card:#161616;--border:#262626;--green:#00e676;--orange:#FF8C00;--dim:#666;--text:#d4d4d4;--red:#ef4444;--blue:#4fc3f7;--yellow:#fdd835}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:32px}
  .wrap{max-width:1280px;margin:0 auto}
  h1{font-size:1.4rem;color:var(--green);font-weight:700;margin-bottom:4px;letter-spacing:.5px}
  h2{font-size:1.1rem;color:var(--orange);font-weight:700;margin:28px 0 12px;border-bottom:1px solid var(--border);padding-bottom:8px}
  .sub{font-size:.85rem;color:var(--dim);margin-bottom:20px}
  .pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
  .pill{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 14px;font-size:.85rem}
  .pill b{color:var(--blue);margin-left:4px}
  .pill.total{border-color:var(--green);color:var(--green)}
  .pill.warn{border-color:var(--orange);color:var(--orange)}
  table{width:100%;border-collapse:collapse;font-size:.78rem;margin-bottom:20px}
  thead th{text-align:left;color:var(--dim);font-weight:500;padding:8px;border-bottom:1px solid var(--border)}
  tbody td{padding:5px 8px;border-bottom:1px solid #1a1a1a;vertical-align:middle}
  .new{color:var(--green);font-family:'Cascadia Code',monospace;word-break:break-all}
  .cat{color:var(--yellow);font-weight:600}
  .z{text-align:center;color:#90a4ae}
  .sz{text-align:right;color:var(--dim)}
  .ok{color:var(--green);font-weight:700}
  .warn{color:var(--orange);font-weight:600}
  .err{color:var(--red);font-size:.85rem;margin:6px 0}
  .foot{margin-top:24px;font-size:.75rem;color:var(--dim);text-align:center}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin:12px 0}
  .card{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;text-align:center}
  .card img{width:100%;aspect-ratio:1;object-fit:contain;background:#0a0a0a;padding:4px}
  .card p{font-size:.65rem;color:var(--dim);padding:4px 4px 6px;line-height:1.3;word-break:break-all}
  .card p b{color:var(--text);display:block;font-size:.7rem}
  details{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:8px 14px;margin:8px 0}
  summary{cursor:pointer;color:var(--orange);font-weight:600;font-size:.9rem}
</style>
</head>
<body>
<div class="wrap">

<h1>BULK IMPORT — RAPORT</h1>
<p class="sub">
    źródło: <code><?= htmlspecialchars($srcDir) ?></code><br>
    cel: <code><?= htmlspecialchars($destDir) ?></code> · DB: <code>sh_global_assets</code> (tenant_id=1)
</p>

<div class="pills">
    <div class="pill total"><b><?= count($imported) ?> assets</b></div>
    <div class="pill"><?= number_format($totalSize / 1024, 0) ?> KB total</div>
    <div class="pill warn">wiped: <b><?= $wipedFiles ?> files</b></div>
    <?php foreach ($catCounts as $c => $n): ?>
        <div class="pill"><?= htmlspecialchars($c) ?>:<b><?= $n ?></b></div>
    <?php endforeach; ?>
</div>

<h2>Surface background (deska)</h2>
<?php if ($surfaceImported): ?>
    <p class="ok">✓ Zaimportowano <code><?= htmlspecialchars($surfaceName) ?></code> — <?= htmlspecialchars($surfaceMessage) ?></p>
    <div style="max-width:600px;margin:12px 0;border:1px solid var(--border);border-radius:8px;overflow:hidden">
        <img src="<?= $destUrl . '/' . $surfaceName ?>" style="width:100%;display:block">
    </div>
<?php else: ?>
    <p class="warn">⚠ Surface NIE zaimportowany</p>
    <p class="err"><?= htmlspecialchars($surfaceMessage) ?></p>
    <p class="sub">Możesz wgrać deskę później przez Online Studio (zakładka Surface).</p>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <h2>Błędy (<?= count($errors) ?>)</h2>
    <?php foreach ($errors as $e): ?>
        <p class="err"><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($skipped)): ?>
    <details>
        <summary>Pominięte pliki (<?= count($skipped) ?>)</summary>
        <table style="margin-top:8px">
            <thead><tr><th>Plik</th><th>Powód</th></tr></thead>
            <tbody>
            <?php foreach ($skipped as $s): ?>
                <tr><td><?= htmlspecialchars($s['file']) ?></td><td class="warn"><?= htmlspecialchars($s['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
<?php endif; ?>

<h2>Podgląd biblioteki (<?= count($imported) ?>)</h2>

<?php
$byCategory = [];
foreach ($imported as $r) {
    $byCategory[$r['cat']][] = $r;
}
ksort($byCategory);
?>

<?php foreach ($byCategory as $cat => $rows): ?>
    <details<?= $cat === 'misc' ? ' open' : '' ?>>
        <summary><?= strtoupper($cat) ?> · <?= count($rows) ?> szt. (z_order=<?= $Z_ORDER[$cat] ?? '?' ?>)</summary>
        <div class="grid">
        <?php foreach ($rows as $r): ?>
            <div class="card">
                <img src="<?= $destUrl . '/' . $r['file'] ?>" alt="<?= htmlspecialchars($r['key']) ?>" loading="lazy">
                <p>
                    <b><?= htmlspecialchars($r['sub']) ?></b>
                    <?= htmlspecialchars($r['key']) ?><br>
                    <?= $r['w'] ?>×<?= $r['h'] ?>px · <?= number_format($r['size'] / 1024, 0) ?> KB
                </p>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
<?php endforeach; ?>

<details>
    <summary>Pełna tabela (<?= count($imported) ?> wierszy)</summary>
    <table style="margin-top:8px">
        <thead><tr><th>#</th><th>ascii_key / filename</th><th>Cat</th><th>Sub</th><th>z</th><th>WxH</th><th>Size</th></tr></thead>
        <tbody>
        <?php foreach ($imported as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="new"><?= htmlspecialchars($r['file']) ?></td>
                <td class="cat"><?= htmlspecialchars($r['cat']) ?></td>
                <td><?= htmlspecialchars($r['sub']) ?></td>
                <td class="z"><?= $r['z'] ?></td>
                <td class="z"><?= $r['w'] ?>×<?= $r['h'] ?></td>
                <td class="sz"><?= number_format($r['size'] / 1024, 1) ?> KB</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>

<p class="foot">
    tenant_id = 1 · wygenerowano <?= date('Y-m-d H:i:s') ?><br>
    Następny krok: zbuduj Online Studio i przejrzyj bibliotekę → przypisz `ascii_key` (np. <code>veg_olive_black</code>) i edytuj <code>sub_type</code>.
</p>

</div>
</body>
</html>
