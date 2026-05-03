<?php
declare(strict_types=1);

/**
 * SliceHub — Restore sh_assets from uploads/global_assets/*.webp
 *
 * Kontekst: po resecie bazy `sh_assets` jest puste, ale fizyczne pliki w
 * `uploads/global_assets/` często zostają na dysku (nie są w Git).
 *
 * Ten skrypt:
 *   • skanuje `uploads/global_assets/*.webp`
 *   • wyprowadza category / sub_type / role_hint / display_name z prefiksu nazwy
 *     (jak starszy pipeline: base_dough_*, sauce_sauce_*, …)
 *   • liczy SHA-256, wymiary (getimagesize), rozmiar pliku
 *   • INSERT … ON DUPLICATE KEY UPDATE po (tenant_id, ascii_key)
 *
 * Idempotentny — bezpieczny wielokrotny run.
 *
 * Uruchomienie:
 *   php scripts/restore_assets_from_disk.php
 *   php scripts/restore_assets_from_disk.php --dry-run
 *   http://localhost/slicehub/scripts/restore_assets_from_disk.php?dry_run=1
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

$cli = PHP_SAPI === 'cli';
$dryRun = false;
if ($cli) {
    $dryRun = in_array('--dry-run', $argv, true);
} else {
    $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] !== '' && $_GET['dry_run'] !== '0';
}

require_once __DIR__ . '/../core/db_config.php';
if (!isset($pdo)) {
    exit($cli ? "DB FAIL\n" : '<pre>DB FAIL</pre>');
}

$tenantId = 1;
$globalDir = realpath(__DIR__ . '/../uploads/global_assets');
if ($globalDir === false || !is_dir($globalDir)) {
    $msg = "Folder nie istnieje: uploads/global_assets\n";
    exit($cli ? $msg : '<pre>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</pre>');
}

/**
 * Klasyfikacja jak w bulk_import_zdjecia / legacy global library.
 *
 * @return array{category:string,sub_type:?string,role_hint:?string,display_name:?string,z_order_hint:int}
 */
function classify_asset(string $asciiKey): array
{
    $lower = strtolower($asciiKey);

    if (str_starts_with($lower, 'board___')) {
        return [
            'category' => 'board',
            'sub_type' => 'custom',
            'role_hint' => 'surface',
            'display_name' => 'Board',
            'z_order_hint' => 0,
        ];
    }

    $parts = explode('_', $asciiKey);
    $first = strtolower($parts[0] ?? 'misc');
    $second = strtolower($parts[1] ?? '');

    $map = [
        'board' => ['category' => 'board', 'sub' => 'plate', 'role' => 'surface', 'dn' => 'Board', 'z' => 0],
        'base' => ['category' => 'base', 'sub' => 'dough', 'role' => 'layer', 'dn' => 'Base', 'z' => 10],
        'sauce' => ['category' => 'sauce', 'sub' => 'sauce', 'role' => 'layer', 'dn' => 'Sauce', 'z' => 20],
        'cheese' => ['category' => 'cheese', 'sub' => 'cheese', 'role' => 'layer', 'dn' => 'Cheese', 'z' => 30],
        'meat' => ['category' => 'meat', 'sub' => 'meat', 'role' => 'layer', 'dn' => 'Meat', 'z' => 40],
        'veg' => ['category' => 'veg', 'sub' => 'veg', 'role' => 'layer', 'dn' => 'Veg', 'z' => 50],
        'herb' => ['category' => 'herb', 'sub' => 'herb', 'role' => 'layer', 'dn' => 'Herb', 'z' => 60],
        'drink' => ['category' => 'drink', 'sub' => 'drink', 'role' => 'layer', 'dn' => 'Drink', 'z' => 65],
        'extra' => ['category' => 'misc', 'sub' => $second !== '' ? $second : 'item', 'role' => 'layer', 'dn' => 'Extra', 'z' => 70],
    ];

    if (!isset($map[$first])) {
        return [
            'category' => 'misc',
            'sub_type' => $second !== '' ? $second : null,
            'role_hint' => 'layer',
            'display_name' => 'Asset',
            'z_order_hint' => 50,
        ];
    }

    $m = $map[$first];
    $sub = $m['sub'];
    if ($second !== '' && $second !== $first) {
        $sub = $second;
    }
    if ($first === $second && isset($parts[2])) {
        $sub = $second;
    }

    return [
        'category' => $m['category'],
        'sub_type' => $sub,
        'role_hint' => $m['role'],
        'display_name' => $m['dn'],
        'z_order_hint' => $m['z'],
    ];
}

$pattern = $globalDir . DIRECTORY_SEPARATOR . '*.webp';
$files = glob($pattern) ?: [];
sort($files);

$stats = ['files' => count($files), 'processed' => 0, 'dry' => 0, 'errors' => []];

$sql = <<<SQL
INSERT INTO sh_assets
  (tenant_id, ascii_key, display_name, tags_json,
   storage_url, storage_bucket, mime_type,
   width_px, height_px, filesize_bytes, has_alpha, checksum_sha256,
   role_hint, category, sub_type, cook_state, z_order_hint,
   metadata_json, is_active, created_by_user)
VALUES
  (:tid, :ak, :dn, NULL,
   :url, 'library', 'image/webp',
   :w, :h, :sz, :alpha, :sha,
   :rh, :cat, :sub, 'either', :z,
   :meta, 1, :cby)
ON DUPLICATE KEY UPDATE
  display_name    = VALUES(display_name),
  storage_url     = VALUES(storage_url),
  storage_bucket  = VALUES(storage_bucket),
  mime_type       = VALUES(mime_type),
  width_px        = VALUES(width_px),
  height_px       = VALUES(height_px),
  filesize_bytes  = VALUES(filesize_bytes),
  has_alpha       = VALUES(has_alpha),
  checksum_sha256 = VALUES(checksum_sha256),
  role_hint       = VALUES(role_hint),
  category        = VALUES(category),
  sub_type        = VALUES(sub_type),
  z_order_hint    = VALUES(z_order_hint),
  metadata_json   = VALUES(metadata_json),
  is_active       = VALUES(is_active),
  updated_at      = CURRENT_TIMESTAMP
SQL;

$stmt = $pdo->prepare($sql);

foreach ($files as $path) {
    $basename = basename($path);
    $asciiKey = pathinfo($basename, PATHINFO_FILENAME);

    $info = classify_asset($asciiKey);
    $relUrl = 'uploads/global_assets/' . $basename;

    $size = @filesize($path);
    if ($size === false) {
        $stats['errors'][] = $basename . ': filesize';
        continue;
    }

    $sha = @hash_file('sha256', $path);
    if ($sha === false) {
        $stats['errors'][] = $basename . ': sha256';
        continue;
    }

    $dims = @getimagesize($path);
    $w = $dims !== false ? (int)$dims[0] : null;
    $h = $dims !== false ? (int)$dims[1] : null;
    $alpha = 1;

    $meta = [
        'restored_from_disk' => true,
        'restored_at' => gmdate('c'),
        'source_file' => $basename,
    ];

    if ($dryRun) {
        $stats['dry']++;
        continue;
    }

    try {
        $stmt->execute([
            ':tid' => $tenantId,
            ':ak' => $asciiKey,
            ':dn' => $info['display_name'],
            ':url' => $relUrl,
            ':w' => $w,
            ':h' => $h,
            ':sz' => $size,
            ':alpha' => $alpha,
            ':sha' => $sha,
            ':rh' => $info['role_hint'],
            ':cat' => $info['category'],
            ':sub' => $info['sub_type'],
            ':z' => $info['z_order_hint'],
            ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':cby' => 'restore_assets_from_disk',
        ]);
        $stats['processed']++;
    } catch (Throwable $e) {
        $stats['errors'][] = $basename . ': ' . $e->getMessage();
    }
}

// MariaDB rowCount() dla INSERT..ONDUP może być 0 przy „no-op”; policz końcowy stan
$finalCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM sh_assets WHERE tenant_id = ' . (int)$tenantId
)->fetchColumn();

$report = [
    'dry_run' => $dryRun,
    'tenant_id' => $tenantId,
    'folder' => $globalDir,
    'webp_files_seen' => $stats['files'],
    'rows_upsert_attempts' => $stats['processed'],
    'skipped_dry' => $stats['dry'],
    'errors' => $stats['errors'],
    'sh_assets_total_for_tenant' => $finalCount,
];

if ($cli) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(count($stats['errors']) > 0 ? 1 : 0);
}

header('Content-Type: text/html; charset=utf-8');
echo '<pre>' . htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>';
