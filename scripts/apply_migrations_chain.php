<?php
declare(strict_types=1);

/**
 * Kanoniczna kolejność plików SQL z database/migrations/ (poza 001 i archiwami).
 *
 * Uruchom PO imporcie 001_init_slicehub_pro_v2.sql (lub równoważnym schemacie).
 * Pliki są wykonywane w jednym exec() na plik — jak w setup_database.php.
 *
 * Uwaga: 006/007/008 nadal są DUPLIKOWANE w setup_database.php i seed_demo_all.php
 * jako kopie — ten skrypt jest źródłem kolejności „pełnego łańcucha”, nie usuwa tamtych kopii.
 *
 * CLI:  php scripts/apply_migrations_chain.php
 *       php scripts/apply_migrations_chain.php --dry-run
 *       php scripts/apply_migrations_chain.php --include-015
 *       php scripts/apply_migrations_chain.php --audit
 *
 * Opcja --include-015: uruchamia 015_normalize_three_drivers.sql (DELETE/UPDATE na tenant 1)
 * — tylko środowisko developerskie z oczekiwanym seedem.
 *
 * Opcja --audit: sprawdza, czy każdy plik *.sql w migrations/ (poza 001 i _archive_*) jest
 * w \$chain — bez połączenia z bazą. Kod wyjścia 0 = OK, 1 = rozbieżność.
 */

$baseDir = dirname(__DIR__);
$migrationsDir = $baseDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

$args = array_slice($argv ?? [], 1);
$dryRun = in_array('--dry-run', $args, true);
$include015 = in_array('--include-015', $args, true);
$auditOnly = in_array('--audit', $args, true);

/** @var list<string> Relative paths under database/migrations/ */
$chain = [
    '004_expand_search_aliases.sql',
    '006_studio_mission_control.sql',
    '007_pos_engine_columns.sql',
    '008_delivery_ecosystem.sql',
    '009_delivery_state_machine.sql',
    '010_driver_action_type.sql',
    '011_integration_logs.sql',
    '012_visual_layers.sql',
    '013_board_companions.sql',
    '014_global_assets.sql',
    '016_visual_compositor_upgrade.sql',
    '017_online_module_extensions.sql',
    '019_layer_positioning.sql',
    '020_director_scenes.sql',
    '021_unified_asset_library.sql',
    '022_scene_kit.sql',
    '023_scene_templates_content.sql',
    '024_modifier_visual_impact.sql',
    '025_drop_legacy_magic_dict.sql',
    '026_event_system.sql',
    '027_gateway_v2.sql',
    '028_integration_deliveries.sql',
    '029_infrastructure_completion.sql',
    '030_scene_harmony_cache.sql',
    '031_baked_variants.sql',
    '032_asset_library_organizer.sql',
    '033_notification_director.sql',
    '034_faza7_gdpr_security.sql',
    '035_atelier_performance.sql',
    '036_asset_display_name.sql',
    '037_pos_foundation.sql',
    '038_drop_legacy_inventory_docs.sql',
];

if ($include015) {
    array_splice($chain, 10, 0, ['015_normalize_three_drivers.sql']);
}

/**
 * @param list<string> $chain
 * @return list<string> komunikaty błędów (pusto = zgodność z dyskiem)
 */
function collectChainIntegrityIssues(string $migrationsDir, array $chain, bool $include015): array
{
    $issues = [];
    foreach ($chain as $rel) {
        $path = $migrationsDir . DIRECTORY_SEPARATOR . $rel;
        if (!is_readable($path)) {
            $issues[] = "Łańcuch wymienia brakujący lub nieczytelny plik: {$rel}";
        }
    }

    $glob = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
    if ($glob === false) {
        $issues[] = 'glob(migrations/*.sql) nie powiodło się.';
        return $issues;
    }

    foreach ($glob as $fullPath) {
        $base = basename($fullPath);
        if ($base === '001_init_slicehub_pro_v2.sql') {
            continue;
        }
        if (str_starts_with($base, '_archive_')) {
            continue;
        }
        if ($base === '015_normalize_three_drivers.sql' && !$include015) {
            continue;
        }
        if (!in_array($base, $chain, true)) {
            $issues[] = "Na dysku jest plik spoza \$chain — dopisz go do apply_migrations_chain.php: {$base}";
        }
    }

    return $issues;
}

function stripDatabaseContext(string $sql): string
{
    $sql = preg_replace('/^\s*USE\s+[^;]+;/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*SET\s+NAMES\s+[^;]+;/mi', '', $sql) ?? $sql;
    return trim($sql);
}

function runChain(string $migrationsDir, array $chain, bool $dryRun, bool $include015): int
{
    $isCli = PHP_SAPI === 'cli';
    $out = static function (string $msg) use ($isCli): void {
        if ($isCli) {
            echo $msg . PHP_EOL;
        } else {
            echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
        }
    };

    $integrity = collectChainIntegrityIssues($migrationsDir, $chain, $include015);
    foreach ($integrity as $msg) {
        $out('AUDIT: ' . $msg);
    }
    if ($integrity !== []) {
        $out('Przerwano: napraw zgodność listy migracji z plikami w database/migrations/.');
        return 1;
    }
    $out('AUDIT: pliki SQL w migrations/ zgadzają się z \$chain (001 i _archive_* wyłączone).');

    require_once dirname(__DIR__) . '/core/db_config.php';
    if (!isset($pdo)) {
        $out('FATAL: brak połączenia PDO (db_config.php).');
        return 1;
    }

    $out('SliceHub — apply_migrations_chain');
    $out('Baza: połączenie z db_config.php (USE z plików SQL jest usuwany).');
    if ($dryRun) {
        $out('[DRY-RUN] Żaden plik nie zostanie wykonany.');
    }
    if (!$include015) {
        $out('Pominięto 015_normalize_three_drivers.sql (destrukcyjne dane). Uruchom z --include-015 jeśli potrzebujesz.');
    } else {
        $out('UWAGA: włączono 015 — DELETE/UPDATE na użytkownikach/kierowcach tenant 1.');
    }

    $exit = 0;
    foreach ($chain as $rel) {
        $path = $migrationsDir . DIRECTORY_SEPARATOR . $rel;
        if (!is_readable($path)) {
            $out("MISSING FILE: {$rel}");
            $exit = 1;
            continue;
        }
        if ($dryRun) {
            $out("WOULD RUN: {$rel}");
            continue;
        }
        $sql = stripDatabaseContext((string)file_get_contents($path));
        if ($sql === '') {
            $out("SKIP (empty after strip): {$rel}");
            continue;
        }
        try {
            $pdo->exec($sql);
            $out("OK: {$rel}");
        } catch (Throwable $e) {
            $out("FAIL: {$rel} — " . $e->getMessage());
            $exit = 1;
        }
    }

    $out($exit === 0 ? 'Zakończono.' : 'Zakończono z błędami.');
    if (!$dryRun && $exit === 0) {
        $out('');
        $out('Następny krok (pełna zgodność z kodem UI / M022 fazą 2–3): uruchom scripts/setup_database.php');
        $out('— nakłada się na 006–008 i część migracji jako KOPIE (idempotentne); domyka ALTER-y na sh_menu_items / sh_categories / sh_atelier_scenes / sh_board_companions opisane w 022, ale wykonywane tylko w PHP setupu.');
    }
    return $exit;
}

if (PHP_SAPI === 'cli' && $auditOnly) {
    $issues = collectChainIntegrityIssues($migrationsDir, $chain, $include015);
    foreach ($issues as $msg) {
        echo $msg . PHP_EOL;
    }
    if ($issues === []) {
        echo "OK: łańcuch zgodny z plikami na dysku (include-015: " . ($include015 ? 'tak' : 'nie') . ")." . PHP_EOL;
    }
    exit($issues === [] ? 0 : 1);
}

if (PHP_SAPI === 'cli') {
    exit(runChain($migrationsDir, $chain, $dryRun, $include015));
}

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Migrations chain</title></head><body style="font-family:monospace;padding:1rem;">';
$code = runChain($migrationsDir, $chain, $dryRun, $include015);
echo '</body></html>';
exit($code);
