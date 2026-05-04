<?php
declare(strict_types=1);

/**
 * Czyści konta testowe w tenancie: zostawia tylko użytkowników z rolami owner i admin
 * (soft-delete reszty + porządki HR / kierowcy).
 *
 * Po uruchomieniu, jeśli brakuje roli `admin` lub `owner`, dopina konto techniczne
 * (login: owner_t{N} / admin_t{N}, hasło: password) — N = tenant_id.
 *
 * Uruchomienie: php scripts/keep_only_owner_admin_users.php [tenant_id]
 * Przeglądarka:  http://localhost/slicehub/scripts/keep_only_owner_admin_users.php?tenant=1&key=SEKRET
 * Klucz HTTP: użyj CLI albo utwórz core/local_secrets.php z define('SLICEHUB_SCRIPT_KEY', '...');
 */
$localSecrets = __DIR__ . '/../core/local_secrets.php';
if (is_file($localSecrets)) {
    require_once $localSecrets;
}
require_once __DIR__ . '/../core/db_config.php';

$tenantId = (int)($argv[1] ?? ($_GET['tenant'] ?? 1));
if ($tenantId <= 0) {
    $tenantId = 1;
}

// Web tylko z kluczem — ustaw przed require db_config: define('SLICEHUB_SCRIPT_KEY', 'losowy_ciag');
// Lub uruchom wyłącznie z CLI: php scripts/keep_only_owner_admin_users.php 1
$webKey = $_GET['key'] ?? '';
if (php_sapi_name() !== 'cli') {
    $expected = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';
    if ($expected === '' || !hash_equals($expected, $webKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 — użyj CLI albo zdefiniuj SLICEHUB_SCRIPT_KEY i parametr ?key=...';
        exit;
    }
}

if (!isset($pdo)) {
    die('Brak połączenia z bazą.');
}

// Ten sam hash co w seed_demo_all — hasło: "password"
$passwordBcrypt = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$isWeb = php_sapi_name() !== 'cli';
if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font:14px/1.5 system-ui;max-width:900px;padding:16px;">';
}

function out(string $s): void
{
    global $isWeb;
    echo $s . ($isWeb ? "\n" : PHP_EOL);
}

out('=== SliceHub — tylko owner + admin (tenant ' . $tenantId . ") ===\n");

/**
 * Różnice w praktyce (sh_users.role):
 * - owner — właściciel lokalu / franczyzy; pełne decyzje biznesowe; Hub, Kadry, POS.
 * - admin   — konto techniczne / administracyjne (sieć, integracje); też Hub + Kadry.
 * W AuthEngine::getTargetModule() oba trafiają do modułu "dashboard" (kierunek po logowaniu hasłem).
 * Uprawnienia w wielu engine'ach: owner, admin, manager — często w jednym warunku.
 */
out('Info: w starym seedzie użytkownik o loginie "admin" miał czasem rolę "owner" — to tylko nazwa loginu, nie rola "admin".');
out('');

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare(
        "SELECT id, username, role FROM sh_users
         WHERE tenant_id = :tid AND is_deleted = 0
           AND LOWER(role) NOT IN ('owner', 'admin')"
    );
    $st->execute([':tid' => $tenantId]);
    $toKill = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn ($r) => (int) $r['id'], $toKill);

    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $pdo->prepare("DELETE FROM sh_drivers WHERE tenant_id = ? AND user_id IN ($in)")
            ->execute(array_merge([$tenantId], $ids));

        $pdo->prepare(
            "UPDATE sh_employees SET status = 'terminated', is_deleted = 1, termination_date = CURDATE(), updated_at = NOW()
             WHERE tenant_id = ? AND user_id IN ($in) AND user_id IS NOT NULL"
        )->execute(array_merge([$tenantId], $ids));

        $pdo->prepare(
            "UPDATE sh_users SET is_deleted = 1, is_active = 0, pin_code = NULL, status = 'inactive'
             WHERE tenant_id = ? AND id IN ($in)"
        )->execute(array_merge([$tenantId], $ids));

        out('Dezaktywowano (soft) kont testowych: ' . count($ids) . ' — ID: ' . implode(', ', $ids));
    } else {
        out('Brak kont do usunięcia (poza owner/admin).');
    }

    $cntOwner = (int) $pdo->query(
        "SELECT COUNT(*) FROM sh_users WHERE tenant_id = {$tenantId} AND is_deleted = 0 AND LOWER(role) = 'owner'"
    )->fetchColumn();
    $cntAdmin = (int) $pdo->query(
        "SELECT COUNT(*) FROM sh_users WHERE tenant_id = {$tenantId} AND is_deleted = 0 AND LOWER(role) = 'admin'"
    )->fetchColumn();

    $unameOwner = 'owner_t' . $tenantId;
    $unameAdmin = 'admin_t' . $tenantId;

    if ($cntOwner === 0) {
        $pdo->prepare(
            "INSERT INTO sh_users (tenant_id, username, password_hash, name, first_name, last_name, role, status, is_active, is_deleted)
             VALUES (:tid, :un, :ph, :nm, 'Właściciel', 'Lokalu', 'owner', 'active', 1, 0)"
        )->execute([
            ':tid' => $tenantId,
            ':un'  => $unameOwner,
            ':ph'  => $passwordBcrypt,
            ':nm'  => 'Właściciel (auto)',
        ]);
        out("Dodano konto owner: login {$unameOwner}, haslo password");
    }

    if ($cntAdmin === 0) {
        $pdo->prepare(
            "INSERT INTO sh_users (tenant_id, username, password_hash, name, first_name, last_name, role, status, is_active, is_deleted)
             VALUES (:tid, :un, :ph, :nm, 'Admin', 'Systemu', 'admin', 'active', 1, 0)"
        )->execute([
            ':tid' => $tenantId,
            ':un'  => $unameAdmin,
            ':ph'  => $passwordBcrypt,
            ':nm'  => 'Administrator (auto)',
        ]);
        out("Dodano konto admin: login {$unameAdmin}, haslo password");
    }

    $pdo->commit();

    out("\n--- Aktywne konta owner/admin (tenant {$tenantId}) ---");
    $rows = $pdo->query(
        "SELECT id, username, role, name, is_active, is_deleted FROM sh_users
         WHERE tenant_id = {$tenantId} AND is_deleted = 0 AND LOWER(role) IN ('owner','admin')
         ORDER BY role, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        out(sprintf('  id=%s  %-20s  role=%-8s  %s', $r['id'], $r['username'], $r['role'], $r['name'] ?? ''));
    }

    out("\nGotowe. Resztę personelu dodaj w module Kadry / Hub.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out('BŁĄD: ' . $e->getMessage());
    if (!$isWeb) {
        exit(1);
    }
}

if ($isWeb) {
    echo '</pre>';
}
