<?php

declare(strict_types=1);

/**
 * SliceHub — Install Panel
 * ============================================================================
 * Jeden URL do zarządzania świeżą instalacją bazy na hostingu (uti.pl).
 *
 * URL produkcyjny:  https://slicehub.net/scripts/install_panel.php
 * URL lokalny:      http://localhost/slicehub/scripts/install_panel.php
 *
 * AUTORYZACJA (KRYTYCZNE)
 *   Panel jest zablokowany dopóki w `core/local_secrets.php` nie ustawisz:
 *      <?php define('SLICEHUB_SCRIPT_KEY', 'losowy_dlugi_string_min_32_znaki');
 *   Bez tego klucza zwraca 403. Klucz wpisujesz raz w panelu (zapisywany w
 *   sessionStorage przeglądarki) i jest wysyłany jako nagłówek X-Script-Key.
 *
 * MOŻLIWOŚCI
 *   - Health-check połączenia DB (env-y, host/baza/user/pass-mask, MySQL ver, ilość tabel).
 *   - Drop wszystkich obiektów (tabele + widoki + procedury + funkcje + eventy).
 *   - Wgranie schematu 001 (z automatycznym strip CREATE DATABASE/USE).
 *   - Wykonanie pełnego łańcucha migracji 004–044 (źródło: _migrations_chain.php).
 *   - "Pełny reset" — drop + 001 + chain w jednym kliknięciu (z double-confirm).
 *   - Lista tenantów + lista użytkowników per tenant.
 *   - Utworzenie tenanta.
 *   - Utworzenie ownera/admina (bcrypt hashowany SERWEROWO — wpisujesz plain).
 *   - Zmiana hasła istniejącego użytkownika (bcrypt też serwerowo).
 *
 * BEZPIECZEŃSTWO
 *   - Wszystkie cross-silo zapytania = klucze znakowe, nie ID (Konstytucja §9).
 *   - Każde zapytanie SQL trzyma barierę tenant_id (Konstytucja §2).
 *   - Połączenie DB realizowane LOKALNIE w panelu (NIE require core/db_config.php),
 *     bo db_config.php robi die() przy błędzie połączenia — chcemy zobaczyć powód.
 *   - JSON API w stylu Konstytucji §5: {success, message, data}, switch($action).
 * ============================================================================
 */

// -----------------------------------------------------------------------------
// 0. Auth: load script key
// -----------------------------------------------------------------------------
$localSecrets = __DIR__ . '/../core/local_secrets.php';
if (is_file($localSecrets)) {
    require_once $localSecrets;
}
$expectedKey = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';

// -----------------------------------------------------------------------------
// 1. Helpers
// -----------------------------------------------------------------------------

function panel_json(bool $ok, string $message, $data = null): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        ['success' => $ok, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function panel_read_key(): string
{
    $h = $_SERVER['HTTP_X_SCRIPT_KEY'] ?? '';
    if ($h !== '') {
        return (string) $h;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($body) && isset($body['key'])) {
        return (string) $body['key'];
    }
    return (string) ($_POST['key'] ?? $_GET['key'] ?? '');
}

function panel_require_auth(string $expected): void
{
    $given = panel_read_key();
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        panel_json(false, 'Brak/zły klucz dostępu (SLICEHUB_SCRIPT_KEY).');
    }
}

function panel_post_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return $_POST ?: [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ($_POST ?: []);
}

function panel_env_db(): array
{
    $envHost = getenv('SLICEHUB_DB_HOST');
    $envDb   = getenv('SLICEHUB_DB_NAME');
    $envUser = getenv('SLICEHUB_DB_USER');
    $envPass = getenv('SLICEHUB_DB_PASS');
    $envJwt  = getenv('JWT_SECRET');

    return [
        'host' => is_string($envHost) && $envHost !== '' ? $envHost : 'localhost',
        'db'   => is_string($envDb)   && $envDb   !== '' ? $envDb   : 'slicehub_pro_v2',
        'user' => is_string($envUser) && $envUser !== '' ? $envUser : 'root',
        'pass' => is_string($envPass) ? $envPass : '',
        'env_set' => [
            'SLICEHUB_DB_HOST' => is_string($envHost) && $envHost !== '',
            'SLICEHUB_DB_NAME' => is_string($envDb)   && $envDb   !== '',
            'SLICEHUB_DB_USER' => is_string($envUser) && $envUser !== '',
            'SLICEHUB_DB_PASS' => is_string($envPass) && $envPass !== '',
            'JWT_SECRET'       => is_string($envJwt)  && $envJwt  !== '',
        ],
    ];
}

function panel_connect(): PDO
{
    $cfg = panel_env_db();
    return new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function panel_strip_db_context(string $sql): string
{
    $sql = preg_replace('/^\s*CREATE\s+DATABASE[^;]+;/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\s+[^;]+;/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*SET\s+NAMES\s+[^;]+;/mi', '', $sql) ?? $sql;
    return trim($sql);
}

// -----------------------------------------------------------------------------
// 2. Action handlers
// -----------------------------------------------------------------------------

function action_health(): void
{
    $cfg = panel_env_db();
    $masked = [
        'host' => $cfg['host'],
        'db'   => $cfg['db'],
        'user' => $cfg['user'],
        'pass_len' => strlen($cfg['pass']),
        'env_set'  => $cfg['env_set'],
    ];

    try {
        $pdo = panel_connect();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $tables  = (int)    $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();

        $tenants = 0;
        $users   = 0;
        try {
            $tenants = (int) $pdo->query('SELECT COUNT(*) FROM sh_tenant')->fetchColumn();
            $users   = (int) $pdo->query('SELECT COUNT(*) FROM sh_users WHERE is_deleted = 0')->fetchColumn();
        } catch (Throwable $e) {
            // tabel jeszcze nie ma — to jest OK przed instalacją
        }

        panel_json(true, 'Połączenie OK.', [
            'config'    => $masked,
            'mysql'     => $version,
            'tables'    => $tables,
            'tenants'   => $tenants,
            'users'     => $users,
        ]);
    } catch (Throwable $e) {
        panel_json(false, 'Błąd połączenia: ' . $e->getMessage(), ['config' => $masked]);
    }
}

function action_drop_all(array $body): void
{
    if (($body['confirm'] ?? '') !== 'USUWAM') {
        panel_json(false, 'Drop anulowany — wymagane potwierdzenie "USUWAM".');
    }

    try {
        $pdo = panel_connect();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $log = [];

        // 1) widoki
        $views = $pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE()")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $v) {
            $pdo->exec('DROP VIEW IF EXISTS `' . $v . '`');
            $log[] = "DROP VIEW {$v}";
        }

        // 2) tabele
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
            $log[] = "DROP TABLE {$t}";
        }

        // 3) procedury / funkcje (na hostingu są częściej blokowane uprawnieniami,
        //    więc każdy fail łapiemy i raportujemy, ale nie przerywamy reszty)
        foreach (['PROCEDURE', 'FUNCTION'] as $kind) {
            $col = $kind === 'PROCEDURE' ? 'PROCEDURE' : 'FUNCTION';
            $rows = $pdo->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_type = '{$col}'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $r) {
                try {
                    $pdo->exec("DROP {$kind} IF EXISTS `{$r}`");
                    $log[] = "DROP {$kind} {$r}";
                } catch (Throwable $e) {
                    $log[] = "SKIP {$kind} {$r} (" . $e->getMessage() . ')';
                }
            }
        }

        // 4) eventy
        try {
            $events = $pdo->query("SELECT event_name FROM information_schema.events WHERE event_schema = DATABASE()")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($events as $e) {
                $pdo->exec("DROP EVENT IF EXISTS `{$e}`");
                $log[] = "DROP EVENT {$e}";
            }
        } catch (Throwable $e) {
            $log[] = 'SKIP events (' . $e->getMessage() . ')';
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $count = count($tables);
        panel_json(true, "Usunięto {$count} tabel(i). Baza pusta — gotowa na instalację.", [
            'dropped_tables' => $tables,
            'log' => $log,
        ]);
    } catch (Throwable $e) {
        panel_json(false, 'Drop nieudany: ' . $e->getMessage());
    }
}

function action_init_schema(): void
{
    $path = dirname(__DIR__) . '/database/migrations/001_init_slicehub_pro_v2.sql';
    if (!is_readable($path)) {
        panel_json(false, "Brak pliku: {$path}");
    }

    try {
        $pdo = panel_connect();
        $sql = panel_strip_db_context((string) file_get_contents($path));
        if ($sql === '') {
            panel_json(false, 'Plik 001 jest pusty po strip CREATE DATABASE/USE.');
        }
        $pdo->exec($sql);

        $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        panel_json(true, "Schemat 001 wgrany. Tabel w bazie: {$tables}.", ['tables' => $tables]);
    } catch (Throwable $e) {
        panel_json(false, '001 nieudane: ' . $e->getMessage());
    }
}

function action_run_chain(): void
{
    $migrationsDir = dirname(__DIR__) . '/database/migrations';
    $chain = require __DIR__ . '/_migrations_chain.php';
    if (!is_array($chain) || $chain === []) {
        panel_json(false, 'Łańcuch migracji jest pusty (_migrations_chain.php).');
    }

    try {
        $pdo = panel_connect();
        $log = [];
        $okCount = 0;
        $failCount = 0;
        $expectedFails = []; // znane nieszkodliwe FAIL-e

        foreach ($chain as $rel) {
            $path = $migrationsDir . '/' . $rel;
            if (!is_readable($path)) {
                $log[] = ['file' => $rel, 'status' => 'MISSING', 'msg' => 'Plik nieczytelny.'];
                $failCount++;
                continue;
            }
            $sql = panel_strip_db_context((string) file_get_contents($path));
            if ($sql === '') {
                $log[] = ['file' => $rel, 'status' => 'SKIP', 'msg' => 'Pusty po strip.'];
                continue;
            }
            try {
                $pdo->exec($sql);
                $log[] = ['file' => $rel, 'status' => 'OK', 'msg' => ''];
                $okCount++;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $isExpected = (
                    ($rel === '010_driver_action_type.sql' && str_contains($msg, 'Duplicate column name')) ||
                    ($rel === '037_pos_foundation.sql'     && str_contains($msg, '1901'))
                );
                $log[] = [
                    'file'   => $rel,
                    'status' => $isExpected ? 'WARN' : 'FAIL',
                    'msg'    => $msg,
                ];
                if ($isExpected) {
                    $expectedFails[] = $rel;
                } else {
                    $failCount++;
                }
            }
        }

        panel_json(true, "Łańcuch zakończony. OK: {$okCount}, FAIL: {$failCount}, znane WARN: " . count($expectedFails) . '.', [
            'log'       => $log,
            'ok'        => $okCount,
            'fail'      => $failCount,
            'expected'  => $expectedFails,
        ]);
    } catch (Throwable $e) {
        panel_json(false, 'Chain nieudane: ' . $e->getMessage());
    }
}

function action_full_install(array $body): void
{
    if (($body['confirm'] ?? '') !== 'USUWAM I INSTALUJĘ') {
        panel_json(false, 'Pełna instalacja anulowana — wymagane potwierdzenie "USUWAM I INSTALUJĘ".');
    }

    $steps = [];
    try {
        // 1. drop all
        $pdo = panel_connect();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $views = $pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $v) {
            $pdo->exec('DROP VIEW IF EXISTS `' . $v . '`');
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
        }
        foreach (['PROCEDURE', 'FUNCTION'] as $kind) {
            $rows = $pdo->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_type = '{$kind}'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $r) {
                try {
                    $pdo->exec("DROP {$kind} IF EXISTS `{$r}`");
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $steps[] = 'DROP: ' . count($tables) . ' tabel(i), ' . count($views) . ' widok(ów).';

        // 2. init 001
        $sql001 = panel_strip_db_context((string) file_get_contents(dirname(__DIR__) . '/database/migrations/001_init_slicehub_pro_v2.sql'));
        $pdo->exec($sql001);
        $tables001 = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $steps[] = "INIT 001: {$tables001} tabel po imporcie.";

        // 3. chain
        $migrationsDir = dirname(__DIR__) . '/database/migrations';
        $chain = require __DIR__ . '/_migrations_chain.php';
        $okCount = 0;
        $failCount = 0;
        $hardFails = [];
        foreach ($chain as $rel) {
            $path = $migrationsDir . '/' . $rel;
            if (!is_readable($path)) {
                $hardFails[] = "{$rel}: brak pliku";
                $failCount++;
                continue;
            }
            $sql = panel_strip_db_context((string) file_get_contents($path));
            if ($sql === '') {
                continue;
            }
            try {
                $pdo->exec($sql);
                $okCount++;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $expected = (
                    ($rel === '010_driver_action_type.sql' && str_contains($msg, 'Duplicate column name')) ||
                    ($rel === '037_pos_foundation.sql'     && str_contains($msg, '1901'))
                );
                if (!$expected) {
                    $hardFails[] = "{$rel}: {$msg}";
                    $failCount++;
                }
            }
        }
        $steps[] = "CHAIN: {$okCount} OK, {$failCount} hard-fail.";
        if ($hardFails !== []) {
            panel_json(false, 'Pełna instalacja zakończona z błędami chain.', ['steps' => $steps, 'hard_fails' => $hardFails]);
        }

        $finalTables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        panel_json(true, "Gotowe. Baza ma {$finalTables} tabel. Następny krok: utwórz tenanta + ownera.", [
            'steps'       => $steps,
            'final_tables' => $finalTables,
        ]);
    } catch (Throwable $e) {
        panel_json(false, 'Pełna instalacja przerwana: ' . $e->getMessage(), ['steps' => $steps]);
    }
}

function action_list_tenants(): void
{
    try {
        $pdo = panel_connect();
        $rows = $pdo->query('SELECT id, name, created_at FROM sh_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        panel_json(true, 'OK', ['tenants' => $rows]);
    } catch (Throwable $e) {
        panel_json(false, 'Lista tenantów: ' . $e->getMessage());
    }
}

function action_list_users(array $body): void
{
    $tenantId = (int) ($body['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        panel_json(false, 'Wymagany tenant_id.');
    }
    try {
        $pdo = panel_connect();
        $st = $pdo->prepare(
            "SELECT id, username, role, name, status, is_active, is_deleted
               FROM sh_users
              WHERE tenant_id = :tid
              ORDER BY is_deleted ASC, role, id"
        );
        $st->execute([':tid' => $tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        panel_json(true, 'OK', ['users' => $rows, 'tenant_id' => $tenantId]);
    } catch (Throwable $e) {
        panel_json(false, 'Lista użytkowników: ' . $e->getMessage());
    }
}

function action_create_tenant(array $body): void
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        panel_json(false, 'Wymagana nazwa tenanta.');
    }
    try {
        $pdo = panel_connect();
        $st = $pdo->prepare('INSERT INTO sh_tenant (name) VALUES (:n)');
        $st->execute([':n' => $name]);
        $id = (int) $pdo->lastInsertId();
        panel_json(true, "Tenant utworzony (id={$id}).", ['tenant_id' => $id, 'name' => $name]);
    } catch (Throwable $e) {
        panel_json(false, 'Tworzenie tenanta: ' . $e->getMessage());
    }
}

function action_create_owner(array $body): void
{
    $tenantId = (int)    ($body['tenant_id'] ?? 0);
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $role     = (string) ($body['role'] ?? 'owner');
    $name     = trim((string) ($body['name'] ?? ''));
    $first    = trim((string) ($body['first_name'] ?? ''));
    $last     = trim((string) ($body['last_name'] ?? ''));

    if ($tenantId <= 0)       panel_json(false, 'Wymagany tenant_id.');
    if ($username === '')     panel_json(false, 'Wymagany login.');
    if (strlen($password) < 8) panel_json(false, 'Hasło musi mieć min. 8 znaków (zalecane 12+).');
    if (!in_array($role, ['owner', 'admin', 'manager'], true)) {
        panel_json(false, 'Dozwolone role w tym panelu: owner, admin, manager.');
    }
    if ($name === '')  $name  = $username;
    if ($first === '') $first = $username;
    if ($last === '')  $last  = '-';

    try {
        $pdo = panel_connect();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            panel_json(false, 'password_hash() zwrócił nie-string.');
        }
        $st = $pdo->prepare(
            "INSERT INTO sh_users
                (tenant_id, username, password_hash, pin_code, name,
                 first_name, last_name, role, status, is_active, is_deleted)
             VALUES
                (:tid, :un, :ph, NULL, :nm, :fn, :ln, :rl, 'active', 1, 0)"
        );
        $st->execute([
            ':tid' => $tenantId,
            ':un'  => $username,
            ':ph'  => $hash,
            ':nm'  => $name,
            ':fn'  => $first,
            ':ln'  => $last,
            ':rl'  => $role,
        ]);
        $id = (int) $pdo->lastInsertId();
        panel_json(true, "Konto utworzone (id={$id}, role={$role}). Logujesz się: {$username}.", [
            'user_id'  => $id,
            'username' => $username,
            'role'     => $role,
        ]);
    } catch (Throwable $e) {
        panel_json(false, 'Tworzenie konta: ' . $e->getMessage());
    }
}

function action_change_password(array $body): void
{
    $tenantId = (int)    ($body['tenant_id'] ?? 0);
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($tenantId <= 0)       panel_json(false, 'Wymagany tenant_id.');
    if ($username === '')     panel_json(false, 'Wymagany login.');
    if (strlen($password) < 8) panel_json(false, 'Hasło musi mieć min. 8 znaków.');

    try {
        $pdo = panel_connect();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $pdo->prepare(
            "UPDATE sh_users
                SET password_hash = :ph
              WHERE tenant_id = :tid AND username = :un AND is_deleted = 0"
        );
        $st->execute([':ph' => $hash, ':tid' => $tenantId, ':un' => $username]);
        $n = $st->rowCount();
        if ($n === 0) {
            panel_json(false, "Nie znaleziono aktywnego użytkownika {$username} w tenant {$tenantId}.");
        }
        panel_json(true, "Hasło zmienione dla {$username}.", ['updated' => $n]);
    } catch (Throwable $e) {
        panel_json(false, 'Zmiana hasła: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// 3. Router
// -----------------------------------------------------------------------------

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = panel_post_body();
    $action = (string) ($body['action'] ?? '');
} else {
    $body = panel_post_body();
}

if ($action !== '') {
    panel_require_auth($expectedKey);
    try {
        switch ($action) {
            case 'health':           action_health(); break;
            case 'drop_all':         action_drop_all($body); break;
            case 'init_schema':      action_init_schema(); break;
            case 'run_chain':        action_run_chain(); break;
            case 'full_install':     action_full_install($body); break;
            case 'list_tenants':     action_list_tenants(); break;
            case 'list_users':       action_list_users($body); break;
            case 'create_tenant':    action_create_tenant($body); break;
            case 'create_owner':     action_create_owner($body); break;
            case 'change_password':  action_change_password($body); break;
            default:
                panel_json(false, "Nieznana akcja: {$action}");
        }
    } catch (Throwable $e) {
        panel_json(false, 'Wyjątek: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// 4. UI (vanilla JS + Tailwind CDN, dark glass per Konstytucja §6)
// -----------------------------------------------------------------------------

$keyConfigured = $expectedKey !== '';
$selfUrl = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? 'install_panel.php', ENT_QUOTES);

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SliceHub — Install Panel</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    body { background: radial-gradient(1200px 800px at 20% -10%, #1e293b 0%, #0a0e14 60%); color: #e2e8f0; min-height: 100dvh; }
    .glass { background: rgba(255,255,255,0.04); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; }
    .glass-strong { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; }
    .danger { background: linear-gradient(135deg, rgba(220,38,38,0.18), rgba(190,18,60,0.10)); border: 1px solid rgba(248,113,113,0.35); border-radius: 14px; }
    .alert86 { color: #ef4444; font-weight: 700; }
    .btn { padding: .55rem .95rem; border-radius: 10px; font-weight: 600; transition: transform .04s ease, background .15s ease; }
    .btn:active { transform: translateY(1px); }
    .btn-primary { background: #2563eb; color: white; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-soft { background: rgba(255,255,255,0.08); color: #e2e8f0; }
    .btn-soft:hover { background: rgba(255,255,255,0.14); }
    .btn-danger { background: #dc2626; color: white; }
    .btn-danger:hover { background: #b91c1c; }
    .btn-ok { background: #16a34a; color: white; }
    .btn-ok:hover { background: #15803d; }
    input, select { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); color: #e2e8f0; padding: .5rem .7rem; border-radius: 8px; width: 100%; }
    input:focus, select:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 2px rgba(96,165,250,0.25); }
    .label { font-size: .8rem; color: #94a3b8; margin-bottom: .25rem; display: block; }
    pre.log { background: rgba(0,0,0,0.45); border-radius: 10px; padding: .8rem; max-height: 360px; overflow: auto; font-size: 12px; color: #cbd5e1; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .pill-ok { background: rgba(22,163,74,0.20); color: #4ade80; }
    .pill-warn { background: rgba(202,138,4,0.20); color: #facc15; }
    .pill-fail { background: rgba(220,38,38,0.22); color: #f87171; }
    .pill-info { background: rgba(59,130,246,0.20); color: #93c5fd; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 13px; }
    th { color: #94a3b8; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .grid-2 { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 900px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto">

<header class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-2xl md:text-3xl font-bold tracking-tight">SliceHub — Install Panel</h1>
        <p class="text-sm text-slate-400 mt-1">
            Czysta instalacja bazy + utworzenie tenanta i ownera.
            Konfiguracja DB pochodzi z env-ów (<code>SLICEHUB_DB_*</code>).
        </p>
    </div>
    <div class="flex items-center gap-2">
        <span id="auth-pill" class="pill pill-warn">Nie zalogowano</span>
        <button id="btn-logout" class="btn btn-soft text-sm">Wyloguj</button>
    </div>
</header>

<?php if (!$keyConfigured): ?>
    <div class="danger p-4 mb-6">
        <p class="font-bold text-red-300">Panel jest WYŁĄCZONY (brak klucza dostępu).</p>
        <p class="text-sm text-slate-300 mt-2">
            Utwórz plik <code>core/local_secrets.php</code> z zawartością:
        </p>
        <pre class="log mt-2">&lt;?php
define('SLICEHUB_SCRIPT_KEY', 'losowy_dlugi_string_min_32_znaki');</pre>
        <p class="text-xs text-slate-400 mt-2">Bez tego pliku 100% akcji zwraca 403. Klucz wpisujesz potem raz w panelu.</p>
    </div>
<?php endif; ?>

<!-- AUTH MODAL -->
<div id="auth-card" class="glass p-5 mb-6 hidden">
    <h2 class="font-semibold text-lg mb-2">Klucz dostępu</h2>
    <p class="text-sm text-slate-400 mb-3">Wklej wartość <code>SLICEHUB_SCRIPT_KEY</code> z <code>core/local_secrets.php</code>.</p>
    <div class="flex gap-2">
        <input id="auth-key" type="password" placeholder="SLICEHUB_SCRIPT_KEY" autocomplete="current-password">
        <button id="auth-submit" class="btn btn-primary whitespace-nowrap">Zaloguj</button>
    </div>
    <p id="auth-msg" class="text-sm text-red-400 mt-2 hidden"></p>
</div>

<!-- MAIN PANEL (visible after auth) -->
<main id="panel" class="hidden grid gap-5">

    <!-- HEALTH -->
    <section class="glass p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-lg">1. Stan połączenia</h2>
            <button id="btn-health" class="btn btn-soft text-sm">Sprawdź</button>
        </div>
        <div id="health-out" class="text-sm text-slate-400">Kliknij „Sprawdź" żeby zobaczyć env-y, wersję MySQL i ilość tabel.</div>
    </section>

    <!-- INSTALL -->
    <section class="grid-2">
        <div class="glass p-5">
            <h2 class="font-semibold text-lg mb-3">2. Instalacja krokowa</h2>
            <p class="text-sm text-slate-400 mb-3">Każdy krok osobno — w razie błędu zobaczysz dokładne miejsce.</p>
            <div class="flex flex-col gap-2">
                <button id="btn-init"  class="btn btn-soft text-left">A. Wgraj schemat <code>001</code> (CREATE TABLE)</button>
                <button id="btn-chain" class="btn btn-soft text-left">B. Uruchom łańcuch migracji <code>004–044</code></button>
                <a class="btn btn-soft text-left" href="setup_database.php" target="_blank" rel="noopener">C. Otwórz <code>setup_database.php</code> ↗</a>
            </div>
            <div id="install-out" class="mt-3"></div>
        </div>

        <div class="danger p-5">
            <h2 class="font-semibold text-lg mb-2 text-red-200">3. Pełny reset (DESTRUKCYJNE)</h2>
            <p class="text-sm text-red-200/80 mb-3">
                Drop wszystkich tabel + <code>001</code> + chain — w jednym kliknięciu.
                Po tym baza jest pusta i czysta — musisz dodać tenanta + ownera.
            </p>
            <div class="flex flex-col gap-2">
                <button id="btn-drop"  class="btn btn-danger text-left">Drop wszystkich tabel</button>
                <button id="btn-full"  class="btn btn-danger text-left">Pełny reset (drop + 001 + chain)</button>
            </div>
            <div id="reset-out" class="mt-3"></div>
        </div>
    </section>

    <!-- TENANT + OWNER -->
    <section class="grid-2">
        <div class="glass p-5">
            <h2 class="font-semibold text-lg mb-3">4. Tenant</h2>
            <div class="flex gap-2 mb-3">
                <input id="t-name" placeholder="Nazwa pizzerii (np. SliceHub Test)">
                <button id="btn-create-tenant" class="btn btn-ok whitespace-nowrap">Utwórz</button>
            </div>
            <div class="mb-2 flex items-center justify-between">
                <span class="label" style="margin:0">Istniejące tenanty</span>
                <button id="btn-refresh-tenants" class="text-xs text-slate-400 hover:text-slate-200">↻ odśwież</button>
            </div>
            <div id="tenants-list" class="text-sm text-slate-400">—</div>
        </div>

        <div class="glass p-5">
            <h2 class="font-semibold text-lg mb-3">5. Owner / admin</h2>
            <div class="grid grid-cols-2 gap-2">
                <div><span class="label">Tenant</span><select id="o-tenant"></select></div>
                <div><span class="label">Rola</span>
                    <select id="o-role">
                        <option value="owner" selected>owner</option>
                        <option value="admin">admin</option>
                        <option value="manager">manager</option>
                    </select>
                </div>
                <div><span class="label">Login</span><input id="o-username" placeholder="np. owner_t1"></div>
                <div><span class="label">Hasło (plain, ≥8)</span><input id="o-password" type="password" placeholder="min. 8 znaków"></div>
                <div><span class="label">Display name</span><input id="o-name" placeholder="Właściciel"></div>
                <div><span class="label">Imię / Nazwisko</span>
                    <div class="flex gap-2">
                        <input id="o-first" placeholder="Imię">
                        <input id="o-last" placeholder="Nazwisko">
                    </div>
                </div>
            </div>
            <button id="btn-create-owner" class="btn btn-ok mt-3 w-full">Utwórz konto (bcrypt po stronie serwera)</button>
            <div id="owner-out" class="mt-3"></div>
        </div>
    </section>

    <!-- USERS -->
    <section class="glass p-5">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="font-semibold text-lg">6. Użytkownicy w tenancie</h2>
            <div class="flex items-center gap-2">
                <span class="label" style="margin:0">Tenant</span>
                <select id="u-tenant" class="!w-40"></select>
                <button id="btn-list-users" class="btn btn-soft text-sm">Pokaż</button>
            </div>
        </div>
        <div id="users-list" class="text-sm text-slate-400">—</div>

        <div class="mt-5 pt-5 border-t border-white/10">
            <h3 class="font-semibold mb-2">Zmiana hasła</h3>
            <div class="grid grid-cols-3 gap-2">
                <input id="cp-username" placeholder="login (np. owner_t1)">
                <input id="cp-password" type="password" placeholder="nowe hasło ≥8 znaków">
                <button id="btn-change-password" class="btn btn-ok">Zmień hasło</button>
            </div>
            <div id="cp-out" class="mt-2 text-sm"></div>
        </div>
    </section>

    <footer class="text-xs text-slate-500 text-center mt-2 mb-6">
        Konstytucja §2 (tenant_id), §5 (JSON API), §6 (dark glass), §9 (silosy SKU).
        Klucz <code>SLICEHUB_SCRIPT_KEY</code> trzymany tylko w sessionStorage.
    </footer>
</main>

</div>

<script>
(function(){
    'use strict';
    const KEY_STORAGE = 'sh_install_panel_key';
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));

    function getKey() { return sessionStorage.getItem(KEY_STORAGE) || ''; }
    function setKey(k) { sessionStorage.setItem(KEY_STORAGE, k); }
    function clearKey() { sessionStorage.removeItem(KEY_STORAGE); }

    function showAuth() {
        $('#panel').classList.add('hidden');
        $('#auth-card').classList.remove('hidden');
        $('#auth-pill').textContent = 'Nie zalogowano';
        $('#auth-pill').className = 'pill pill-warn';
    }
    function showPanel() {
        $('#auth-card').classList.add('hidden');
        $('#panel').classList.remove('hidden');
        $('#auth-pill').textContent = 'Zalogowany';
        $('#auth-pill').className = 'pill pill-ok';
        refreshTenants();
    }

    async function api(action, body = {}) {
        const key = getKey();
        const res = await fetch(<?= json_encode($selfUrl) ?>, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Script-Key': key },
            body: JSON.stringify({ action, ...body })
        });
        let json;
        try { json = await res.json(); }
        catch (e) { return { success: false, message: 'Niepoprawna odpowiedź serwera (HTTP ' + res.status + ').', data: null }; }
        if (res.status === 403) {
            clearKey();
            showAuth();
            $('#auth-msg').textContent = json.message || 'Brak/zły klucz.';
            $('#auth-msg').classList.remove('hidden');
        }
        return json;
    }

    function renderResult(target, json, extraHtml = '') {
        const pill = json.success
            ? '<span class="pill pill-ok">OK</span>'
            : '<span class="pill pill-fail">FAIL</span>';
        target.innerHTML = pill + ' <span class="text-sm">' + escapeHtml(json.message || '') + '</span>' + (extraHtml || '');
    }
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // --- AUTH ---
    $('#auth-submit').addEventListener('click', async () => {
        const k = $('#auth-key').value.trim();
        if (!k) return;
        setKey(k);
        const r = await api('health');
        if (r.success) {
            $('#auth-msg').classList.add('hidden');
            showPanel();
            renderHealth(r);
        }
    });
    $('#auth-key').addEventListener('keydown', e => { if (e.key === 'Enter') $('#auth-submit').click(); });
    $('#btn-logout').addEventListener('click', () => { clearKey(); showAuth(); });

    // --- HEALTH ---
    function renderHealth(r) {
        const out = $('#health-out');
        if (!r.success) { renderResult(out, r); return; }
        const d = r.data || {};
        const c = d.config || {};
        const env = c.env_set || {};
        const envRow = (k, v) => '<span class="pill ' + (v ? 'pill-ok':'pill-warn') + '">' + escapeHtml(k) + (v ? ' ✓' : ' BRAK') + '</span>';
        out.innerHTML =
            '<div class="grid grid-cols-2 gap-3 text-sm">' +
                '<div><span class="label">MySQL</span><div>' + escapeHtml(d.mysql || '?') + '</div></div>' +
                '<div><span class="label">Tabele w bazie</span><div class="' + (d.tables === 0 ? 'alert86' : '') + '">' + escapeHtml(d.tables) + '</div></div>' +
                '<div><span class="label">Tenanty</span><div class="' + (d.tenants === 0 ? 'alert86' : '') + '">' + escapeHtml(d.tenants) + '</div></div>' +
                '<div><span class="label">Aktywni użytkownicy</span><div class="' + (d.users === 0 ? 'alert86' : '') + '">' + escapeHtml(d.users) + '</div></div>' +
                '<div class="col-span-2"><span class="label">Połączenie (z env)</span>' +
                    '<div class="text-sm">host=<code>' + escapeHtml(c.host) + '</code> · db=<code>' + escapeHtml(c.db) + '</code> · user=<code>' + escapeHtml(c.user) + '</code> · pass_len=<code>' + escapeHtml(c.pass_len) + '</code></div>' +
                '</div>' +
                '<div class="col-span-2 flex flex-wrap gap-2 mt-1">' +
                    envRow('SLICEHUB_DB_HOST', env.SLICEHUB_DB_HOST) +
                    envRow('SLICEHUB_DB_NAME', env.SLICEHUB_DB_NAME) +
                    envRow('SLICEHUB_DB_USER', env.SLICEHUB_DB_USER) +
                    envRow('SLICEHUB_DB_PASS', env.SLICEHUB_DB_PASS) +
                    envRow('JWT_SECRET',       env.JWT_SECRET) +
                '</div>' +
            '</div>';
    }
    $('#btn-health').addEventListener('click', async () => {
        $('#health-out').innerHTML = '<span class="pill pill-info">…</span>';
        renderHealth(await api('health'));
    });

    // --- INSTALL STEPS ---
    function renderChainLog(log) {
        if (!Array.isArray(log) || log.length === 0) return '';
        const rows = log.map(e => {
            const cls = e.status === 'OK' ? 'pill-ok' : (e.status === 'WARN' ? 'pill-warn' : (e.status === 'SKIP' ? 'pill-info' : 'pill-fail'));
            return '<div><span class="pill ' + cls + '">' + escapeHtml(e.status) + '</span> <code>' + escapeHtml(e.file) + '</code>' + (e.msg ? '<div class="text-xs text-slate-400 ml-1 mt-0.5">' + escapeHtml(e.msg) + '</div>' : '') + '</div>';
        }).join('');
        return '<pre class="log mt-2">' + rows + '</pre>';
    }

    $('#btn-init').addEventListener('click', async () => {
        $('#install-out').innerHTML = '<span class="pill pill-info">Importuję 001…</span>';
        renderResult($('#install-out'), await api('init_schema'));
    });
    $('#btn-chain').addEventListener('click', async () => {
        $('#install-out').innerHTML = '<span class="pill pill-info">Uruchamiam chain (może potrwać kilkadziesiąt sekund)…</span>';
        const r = await api('run_chain');
        renderResult($('#install-out'), r, renderChainLog(r.data && r.data.log));
    });

    // --- DROP / FULL RESET ---
    $('#btn-drop').addEventListener('click', async () => {
        const t = prompt('To USUNIE WSZYSTKIE TABELE.\nWpisz "USUWAM" żeby potwierdzić:');
        if (t !== 'USUWAM') return;
        $('#reset-out').innerHTML = '<span class="pill pill-info">Dropuję…</span>';
        renderResult($('#reset-out'), await api('drop_all', { confirm: 'USUWAM' }));
    });
    $('#btn-full').addEventListener('click', async () => {
        const t = prompt('PEŁNY RESET — drop wszystkich tabel + import 001 + chain.\nWpisz dokładnie: USUWAM I INSTALUJĘ');
        if (t !== 'USUWAM I INSTALUJĘ') return;
        $('#reset-out').innerHTML = '<span class="pill pill-info">Pełny reset trwa…</span>';
        const r = await api('full_install', { confirm: 'USUWAM I INSTALUJĘ' });
        let extra = '';
        if (r.data && Array.isArray(r.data.steps)) {
            extra += '<pre class="log mt-2">' + r.data.steps.map(escapeHtml).join('\n') + '</pre>';
        }
        if (r.data && Array.isArray(r.data.hard_fails) && r.data.hard_fails.length) {
            extra += '<pre class="log mt-2 text-red-300">' + r.data.hard_fails.map(escapeHtml).join('\n') + '</pre>';
        }
        renderResult($('#reset-out'), r, extra);
        if (r.success) { refreshTenants(); }
    });

    // --- TENANTS ---
    async function refreshTenants() {
        const r = await api('list_tenants');
        const list = $('#tenants-list');
        const sel1 = $('#o-tenant');
        const sel2 = $('#u-tenant');
        sel1.innerHTML = '';
        sel2.innerHTML = '';
        if (!r.success) { renderResult(list, r); return; }
        const t = (r.data && r.data.tenants) || [];
        if (t.length === 0) {
            list.innerHTML = '<span class="alert86">Brak tenantów — utwórz pierwszego.</span>';
            return;
        }
        list.innerHTML = '<table><thead><tr><th>id</th><th>nazwa</th><th>utworzony</th></tr></thead><tbody>' +
            t.map(x => '<tr><td><code>' + escapeHtml(x.id) + '</code></td><td>' + escapeHtml(x.name) + '</td><td class="text-slate-400">' + escapeHtml(x.created_at || '') + '</td></tr>').join('') +
            '</tbody></table>';
        for (const x of t) {
            const o1 = document.createElement('option'); o1.value = x.id; o1.textContent = x.id + ' — ' + x.name; sel1.appendChild(o1);
            const o2 = document.createElement('option'); o2.value = x.id; o2.textContent = x.id + ' — ' + x.name; sel2.appendChild(o2);
        }
    }
    $('#btn-refresh-tenants').addEventListener('click', refreshTenants);
    $('#btn-create-tenant').addEventListener('click', async () => {
        const name = $('#t-name').value.trim();
        if (!name) return;
        const r = await api('create_tenant', { name });
        if (r.success) { $('#t-name').value = ''; refreshTenants(); }
        else { alert(r.message); }
    });

    // --- OWNER ---
    $('#btn-create-owner').addEventListener('click', async () => {
        const body = {
            tenant_id: parseInt($('#o-tenant').value, 10),
            role:      $('#o-role').value,
            username:  $('#o-username').value.trim(),
            password:  $('#o-password').value,
            name:      $('#o-name').value.trim(),
            first_name:$('#o-first').value.trim(),
            last_name: $('#o-last').value.trim(),
        };
        const r = await api('create_owner', body);
        renderResult($('#owner-out'), r);
        if (r.success) {
            $('#o-username').value = '';
            $('#o-password').value = '';
            $('#o-name').value = '';
            $('#o-first').value = '';
            $('#o-last').value = '';
        }
    });

    // --- USERS ---
    $('#btn-list-users').addEventListener('click', async () => {
        const tid = parseInt($('#u-tenant').value, 10);
        const r = await api('list_users', { tenant_id: tid });
        const out = $('#users-list');
        if (!r.success) { renderResult(out, r); return; }
        const u = (r.data && r.data.users) || [];
        if (u.length === 0) {
            out.innerHTML = '<span class="alert86">Brak użytkowników w tym tenancie.</span>';
            return;
        }
        out.innerHTML = '<table><thead><tr><th>id</th><th>login</th><th>rola</th><th>name</th><th>status</th><th>flagi</th></tr></thead><tbody>' +
            u.map(x => '<tr>' +
                '<td><code>' + escapeHtml(x.id) + '</code></td>' +
                '<td><code>' + escapeHtml(x.username) + '</code></td>' +
                '<td>' + escapeHtml(x.role) + '</td>' +
                '<td>' + escapeHtml(x.name || '') + '</td>' +
                '<td>' + escapeHtml(x.status || '') + '</td>' +
                '<td>' +
                    (parseInt(x.is_active, 10) ? '<span class="pill pill-ok">active</span> ' : '<span class="pill pill-warn">inactive</span> ') +
                    (parseInt(x.is_deleted, 10) ? '<span class="pill pill-fail">deleted</span>' : '') +
                '</td>' +
            '</tr>').join('') +
        '</tbody></table>';
    });

    $('#btn-change-password').addEventListener('click', async () => {
        const tid = parseInt($('#u-tenant').value, 10);
        const r = await api('change_password', {
            tenant_id: tid,
            username:  $('#cp-username').value.trim(),
            password:  $('#cp-password').value,
        });
        renderResult($('#cp-out'), r);
        if (r.success) { $('#cp-password').value = ''; }
    });

    // --- BOOT ---
    if (getKey()) {
        api('health').then(r => {
            if (r.success) { showPanel(); renderHealth(r); }
            else { showAuth(); }
        });
    } else {
        showAuth();
    }
})();
</script>
</body>
</html>
