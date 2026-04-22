<?php
declare(strict_types=1);

/**
 * SLICEHUB — Nuclear Reset & Reseed
 * ============================================================
 * Bezpieczny reset środowiska testowego dla tenant_id = 1.
 *
 * Co robi:
 *   1. Testuje połączenie z bazą
 *   2. Usuwa WSZYSTKIE zamówienia, logi, zmiany, dyspozycje
 *   3. Usuwa sesje pracy i zmiany kierowców
 *   4. Usuwa wszystkich użytkowników i rejestracje kierowców
 *   5. Resetuje sekwencje (ORDER, COURSE) do 0
 *   6. Tworzy 6 świeżych kont testowych z czytelnym PIN-em
 *   7. Rejestruje 2 kierowców i aktywuje ich zmiany
 *   8. Aktualizuje pozycje GPS kierowców
 *
 * URL: http://localhost/slicehub/scripts/nuclear_reset.php
 * ============================================================
 */

$T = 1; // tenant_id — TYLKO dev/test
$steps = [];
$errors = [];

function step(string $label, callable $fn): void {
    global $steps, $errors;
    try {
        $info = $fn();
        $steps[] = ['ok' => true, 'label' => $label, 'info' => $info ?? ''];
    } catch (\Throwable $e) {
        $steps[] = ['ok' => false, 'label' => $label, 'info' => $e->getMessage()];
        $errors[] = $e->getMessage();
    }
}

// ──────────────────────────────────────────────────────────────
// 0. POŁĄCZENIE
// ──────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'slicehub_pro_v2';
$user = 'root';
$pass = '';

$connOk = false;
$connMsg = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $row = $pdo->query("SELECT VERSION() AS v, DATABASE() AS d")->fetch(PDO::FETCH_ASSOC);
    $connOk  = true;
    $connMsg = "MySQL {$row['v']} | DB: {$row['d']} | Host: $host";
} catch (\Throwable $e) {
    $connMsg = $e->getMessage();
    // Renderuj błąd połączenia i wyjdź
    renderPage($steps, $errors, $connOk, $connMsg, []);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 1. WYŁĄCZ FK + WYCZYŚĆ DANE TRANSAKCYJNE
// ──────────────────────────────────────────────────────────────
step('Wyłącz klucze FK (chwilowo)', function () use ($pdo) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    return 'FK=OFF';
});

step('Usuń linie zamówień', function () use ($pdo, $T) {
    // Usuń linie powiązane z zamówieniami tego tenanta
    $n = $pdo->exec("DELETE ol FROM sh_order_lines ol INNER JOIN sh_orders o ON o.id = ol.order_id WHERE o.tenant_id = $T");
    return "$n wierszy";
});

step('Usuń logi audytu zamówień', function () use ($pdo, $T) {
    $n = $pdo->exec("DELETE oa FROM sh_order_audit oa INNER JOIN sh_orders o ON o.id = oa.order_id WHERE o.tenant_id = $T");
    return "$n wierszy";
});

step('Usuń zamówienia', function () use ($pdo, $T) {
    $n = $pdo->exec("DELETE FROM sh_orders WHERE tenant_id = $T");
    return "$n zamówień";
});

step('Usuń dyspozycje (dispatch_log)', function () use ($pdo, $T) {
    try {
        $n = $pdo->exec("DELETE FROM sh_dispatch_log WHERE tenant_id = $T");
        return "$n wpisów";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) return 'tabela nieistnieje — skip';
        throw $e;
    }
});

step('Usuń zmiany kierowców (shifts)', function () use ($pdo, $T) {
    $n = $pdo->exec("DELETE FROM sh_driver_shifts WHERE tenant_id = $T");
    return "$n zmian";
});

step('Usuń lokalizacje GPS', function () use ($pdo, $T) {
    try {
        $n = $pdo->exec("DELETE FROM sh_driver_locations WHERE tenant_id = $T");
        return "$n pozycji";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) return 'tabela nieistnieje — skip';
        throw $e;
    }
});

step('Usuń sesje pracy', function () use ($pdo, $T) {
    try {
        $n = $pdo->exec("DELETE FROM sh_work_sessions WHERE tenant_id = $T");
        return "$n sesji";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) return 'tabela nieistnieje — skip';
        throw $e;
    }
});

step('Usuń rejestracje kierowców', function () use ($pdo, $T) {
    $n = $pdo->exec("DELETE FROM sh_drivers WHERE tenant_id = $T");
    return "$n kierowców";
});

step('Usuń wszystkich użytkowników', function () use ($pdo, $T) {
    $n = $pdo->exec("DELETE FROM sh_users WHERE tenant_id = $T");
    return "$n użytkowników";
});

step('Przywróć klucze FK', function () use ($pdo) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    return 'FK=ON';
});

// ──────────────────────────────────────────────────────────────
// 2. RESET SEKWENCJI
// ──────────────────────────────────────────────────────────────
step('Resetuj AUTO_INCREMENT użytkowników', function () use ($pdo) {
    try { $pdo->exec("ALTER TABLE sh_users AUTO_INCREMENT = 1"); } catch (\Throwable $ignore) {}
    return 'AUTO_INCREMENT → 1';
});

step('Resetuj sekwencje zamówień (ORDER)', function () use ($pdo, $T) {
    try {
        $n = $pdo->exec("UPDATE sh_order_sequences SET seq = 0 WHERE tenant_id = $T");
        if ($n === 0) {
            $pdo->exec("INSERT INTO sh_order_sequences (tenant_id, `date`, seq) VALUES ($T, CURDATE(), 0) ON DUPLICATE KEY UPDATE seq = 0");
        }
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) return 'tabela nieistnieje — skip';
        throw $e;
    }
    return 'seq → 0';
});

step('Resetuj sekwencje kursów (COURSE)', function () use ($pdo, $T) {
    try {
        $n = $pdo->exec("UPDATE sh_course_sequences SET seq = 0 WHERE tenant_id = $T");
        if ($n === 0) {
            $pdo->exec("INSERT INTO sh_course_sequences (tenant_id, `date`, seq) VALUES ($T, CURDATE(), 0) ON DUPLICATE KEY UPDATE seq = 0");
        }
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) return 'tabela nieistnieje — skip';
        throw $e;
    }
    return 'seq → 0';
});

// ──────────────────────────────────────────────────────────────
// 3. SEED KONT TESTOWYCH
// ──────────────────────────────────────────────────────────────
// Bcrypt "password" — używany dla kont systemowych
$PW = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$roster = [
    // id, username,    pin,    display_name,           first_name, last_name,    role,      rate
    [1, 'manager',  '0000', 'Kierownik Anna',         'Anna',     'Nowak',      'manager', 28.00],
    [2, 'waiter1',  '1111', 'Kelner Marek',            'Marek',    'Zieliński',  'waiter',  22.00],
    [3, 'waiter2',  '2222', 'Kelnerka Ola',            'Ola',      'Wójcik',     'waiter',  22.00],
    [4, 'cook1',    '3333', 'Kucharz Piotr',           'Piotr',    'Mazur',      'cook',    25.00],
    [5, 'driver1',  '4444', 'Kierowca Tomek',          'Tomek',    'Kaczmarek',  'driver',  20.00],
    [6, 'driver2',  '5555', 'Kierowca Kasia',          'Kasia',    'Wiśniewska', 'driver',  20.00],
];

$seededUsers = [];

step('Utwórz konta testowe (' . count($roster) . ' użytkowników)', function () use ($pdo, $T, $PW, $roster, &$seededUsers) {
    $stmt = $pdo->prepare(
        "INSERT INTO sh_users
            (id, tenant_id, username, password_hash, pin_code, name, first_name, last_name, role, status, hourly_rate, is_active, is_deleted, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 1, 0, NOW())"
    );
    foreach ($roster as $u) {
        $stmt->execute([$u[0], $T, $u[1], $PW, $u[2], $u[3], $u[4], $u[5], $u[6], $u[7]]);
        $seededUsers[] = ['id' => $u[0], 'name' => $u[3], 'role' => $u[6], 'pin' => $u[2]];
    }
    return count($roster) . ' kont';
});

step('Zarejestruj kierowców (sh_drivers)', function () use ($pdo, $T) {
    // Użytkownicy o ID 5 i 6 to kierowcy
    $stmt = $pdo->prepare("INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (?, ?, 'available')");
    $stmt->execute([5, $T]);
    $stmt->execute([6, $T]);
    return '2 kierowców (ID 5, 6)';
});

step('Aktywuj zmiany kierowców (shift + kasa startowa 100 zł)', function () use ($pdo, $T) {
    $stmt = $pdo->prepare(
        "INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status, created_at)
         VALUES (?, ?, 10000, 'active', NOW())"
    );
    $stmt->execute([$T, 5]);
    $stmt->execute([$T, 6]);
    return '2 aktywne zmiany, kasa = 100.00 zł';
});

step('Ustaw pozycje GPS kierowców (centrum Poznania)', function () use ($pdo, $T) {
    try {
        $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0")->closeCursor();
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sh_driver_locations (
            driver_id   BIGINT UNSIGNED NOT NULL,
            tenant_id   INT UNSIGNED NOT NULL,
            lat         DECIMAL(10,7) NOT NULL,
            lng         DECIMAL(10,7) NOT NULL,
            heading     SMALLINT NULL,
            speed_kmh   DECIMAL(5,1) NULL,
            accuracy_m  DECIMAL(6,1) NULL,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id, driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sh_driver_locations (driver_id, tenant_id, lat, lng, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), updated_at=NOW()"
    );
    $stmt->execute([5, $T, 52.4080, 16.9210]);
    $stmt->execute([6, $T, 52.4020, 16.9300]);
    return 'Tomek: 52.4080,16.9210 | Kasia: 52.4020,16.9300';
});

// ──────────────────────────────────────────────────────────────
// 4. WERYFIKACJA KOŃCOWA
// ──────────────────────────────────────────────────────────────
step('Weryfikuj: liczba użytkowników', function () use ($pdo, $T) {
    $n = $pdo->query("SELECT COUNT(*) FROM sh_users WHERE tenant_id = $T")->fetchColumn();
    return "Znaleziono: $n użytkowników";
});

step('Weryfikuj: liczba zamówień', function () use ($pdo, $T) {
    $n = $pdo->query("SELECT COUNT(*) FROM sh_orders WHERE tenant_id = $T")->fetchColumn();
    if ((int)$n > 0) throw new \RuntimeException("Nadal $n zamówień — coś poszło nie tak!");
    return '0 zamówień — czysto!';
});

step('Weryfikuj: kierowcy i status', function () use ($pdo, $T) {
    $rows = $pdo->query(
        "SELECT u.first_name, d.status, ds.initial_cash
         FROM sh_users u
         JOIN sh_drivers d ON d.user_id = u.id AND d.tenant_id = u.tenant_id
         LEFT JOIN sh_driver_shifts ds ON ds.driver_id = u.id AND ds.tenant_id = u.tenant_id AND ds.status = 'active'
         WHERE u.tenant_id = $T"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $cash = $r['initial_cash'] !== null ? number_format((int)$r['initial_cash'] / 100, 2) . ' zł' : '—';
        $out[] = "{$r['first_name']} [{$r['status']}] kasa={$cash}";
    }
    return implode(', ', $out);
});

step('Weryfikuj: połączenie menu (sh_menu_items)', function () use ($pdo, $T) {
    $n = $pdo->query("SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id = $T AND is_active = 1")->fetchColumn();
    if ((int)$n === 0) throw new \RuntimeException('Brak pozycji menu! Uruchom seed_demo_all.php najpierw.');
    return "$n aktywnych pozycji menu";
});

step('Weryfikuj: ceny (sh_price_tiers)', function () use ($pdo, $T) {
    $n = $pdo->query("SELECT COUNT(*) FROM sh_price_tiers WHERE tenant_id = $T")->fetchColumn();
    if ((int)$n === 0) throw new \RuntimeException('Brak cen! Uruchom seed_demo_all.php najpierw.');
    return "$n rekordów cen";
});

// ──────────────────────────────────────────────────────────────
// RENDER
// ──────────────────────────────────────────────────────────────
renderPage($steps, $errors, $connOk, $connMsg, $seededUsers);

function renderPage(array $steps, array $errors, bool $connOk, string $connMsg, array $seededUsers): void {
    $ok   = count(array_filter($steps, fn($s) => $s['ok']));
    $fail = count(array_filter($steps, fn($s) => !$s['ok']));
    $allOk = $fail === 0;
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SliceHub — Nuclear Reset</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#050508;--surf:#0f1117;--surf2:#161a24;--border:rgba(255,255,255,.06);--text:#e2e8f0;--muted:#64748b;--green:#22c55e;--red:#ef4444;--blue:#3b82f6;--orange:#f97316;--purple:#a855f7;--radius:14px}
html{height:100%}
body{min-height:100%;background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;padding:32px 16px}
.wrap{max-width:760px;margin:0 auto}
.hero{text-align:center;padding:40px 0 32px}
.hero h1{font-size:26px;font-weight:900;letter-spacing:.04em;margin-bottom:6px}
.hero h1 span{color:var(--red)}
.hero p{color:var(--muted);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}
/* Conn strip */
.conn{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;margin-bottom:24px;font-size:12px;font-weight:700}
.conn.ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25)}
.conn.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);color:var(--red)}
.conn-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.conn-dot.ok{background:var(--green);box-shadow:0 0 6px var(--green)}
.conn-dot.err{background:var(--red)}
/* Summary */
.summary{display:flex;gap:12px;margin-bottom:24px}
.sum-card{flex:1;padding:14px 20px;border-radius:12px;border:1px solid var(--border);text-align:center;background:var(--surf)}
.sum-card .n{font-size:32px;font-weight:900;line-height:1}
.sum-card .l{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-top:4px}
.sum-card.ok .n{color:var(--green)}
.sum-card.fail .n{color:var(--red)}
/* Steps */
.steps{background:var(--surf);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:24px}
.step{display:flex;align-items:flex-start;gap:12px;padding:10px 18px;border-bottom:1px solid var(--border);font-size:12px}
.step:last-child{border-bottom:none}
.step-icon{width:18px;height:18px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;margin-top:1px}
.step-icon.ok{background:rgba(34,197,94,.15);color:var(--green)}
.step-icon.err{background:rgba(239,68,68,.15);color:var(--red)}
.step-label{flex:1;font-weight:600;color:var(--text)}
.step-info{color:var(--muted);font-size:11px;text-align:right;max-width:260px;word-break:break-all}
.step.err .step-label{color:var(--red)}
/* Credentials */
.creds{background:var(--surf);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:24px}
.creds-title{padding:14px 18px 10px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--purple);border-bottom:1px solid var(--border)}
.cred-row{display:flex;align-items:center;padding:10px 18px;border-bottom:1px solid var(--border);gap:12px;font-size:13px}
.cred-row:last-child{border-bottom:none}
.cred-id{width:24px;color:var(--muted);font-size:11px;text-align:center}
.cred-name{flex:1;font-weight:700}
.pin{font-family:'Courier New',monospace;font-size:16px;font-weight:900;letter-spacing:.2em;color:var(--orange);background:rgba(249,115,22,.1);padding:2px 10px;border-radius:6px}
.role-badge{font-size:9px;font-weight:900;text-transform:uppercase;padding:2px 8px;border-radius:5px;letter-spacing:.05em}
.role-manager{background:rgba(168,85,247,.15);color:var(--purple)}
.role-waiter{background:rgba(59,130,246,.15);color:var(--blue)}
.role-cook{background:rgba(234,179,8,.15);color:#eab308}
.role-driver{background:rgba(249,115,22,.15);color:var(--orange)}
/* Actions */
.actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-bottom:40px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#fff;transition:.15s}
.btn-blue{background:#3b82f6}.btn-purple{background:#a855f7}.btn-green{background:#22c55e;color:#000}
.btn-orange{background:#f97316}.btn-gray{background:#374151}
.badge-warn{display:block;text-align:center;font-size:11px;color:var(--red);font-weight:700;margin-bottom:16px;padding:10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px}
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <h1>☢ Nuclear <span>Reset</span></h1>
        <p>SliceHub Enterprise OS &bull; Tenant #1 &bull; <?= date('Y-m-d H:i:s') ?></p>
    </div>

    <!-- CONNECTION -->
    <div class="conn <?= $connOk ? 'ok' : 'err' ?>">
        <div class="conn-dot <?= $connOk ? 'ok' : 'err' ?>"></div>
        <?= htmlspecialchars($connMsg) ?>
    </div>

    <?php if (!$connOk): ?>
    <div class="badge-warn">BŁĄD POŁĄCZENIA — reset przerwany. Sprawdź czy XAMPP MySQL jest uruchomiony.</div>
    <?php else: ?>

    <!-- SUMMARY -->
    <div class="summary">
        <div class="sum-card ok"><div class="n"><?= $ok ?></div><div class="l">OK</div></div>
        <div class="sum-card fail"><div class="n"><?= $fail ?></div><div class="l">Błędy</div></div>
    </div>

    <?php if ($fail > 0): ?>
    <div class="badge-warn">⚠ <?= $fail ?> kroków zakończyło się błędem — sprawdź szczegóły poniżej</div>
    <?php endif; ?>

    <!-- STEPS -->
    <div class="steps">
        <?php foreach ($steps as $s): ?>
        <div class="step <?= $s['ok'] ? '' : 'err' ?>">
            <div class="step-icon <?= $s['ok'] ? 'ok' : 'err' ?>"><?= $s['ok'] ? '✓' : '✗' ?></div>
            <div class="step-label"><?= htmlspecialchars($s['label']) ?></div>
            <div class="step-info"><?= htmlspecialchars($s['info']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- CREDENTIALS -->
    <?php if (!empty($seededUsers)): ?>
    <div class="creds">
        <div class="creds-title">🔑 Konta testowe — cheat sheet</div>
        <?php foreach ($seededUsers as $u): ?>
        <div class="cred-row">
            <div class="cred-id">#<?= $u['id'] ?></div>
            <div class="cred-name"><?= htmlspecialchars($u['name']) ?></div>
            <span class="role-badge role-<?= $u['role'] ?>"><?= $u['role'] ?></span>
            <span class="pin"><?= htmlspecialchars($u['pin']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ACTIONS -->
    <div class="actions">
        <a href="/slicehub/modules/pos/" class="btn btn-blue">🍕 POS</a>
        <a href="/slicehub/modules/courses/" class="btn btn-purple">🚚 Dispatcher</a>
        <a href="/slicehub/modules/driver_app/" class="btn btn-green">🏍 Driver App</a>
        <a href="/slicehub/modules/warehouse/" class="btn btn-orange">📦 Magazyn</a>
        <a href="/slicehub/modules/studio/" class="btn btn-gray">🎨 Studio</a>
        <a href="/slicehub/tests/test_runner.html" class="btn btn-gray">🧪 Tests</a>
    </div>

    <?php endif; ?>
</div>
</body>
</html>
<?php
}
