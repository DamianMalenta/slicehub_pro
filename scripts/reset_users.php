<?php
declare(strict_types=1);

/**
 * SLICEHUB — Tactical User Reset Script
 * Wipes all users for tenant_id=1 and seeds an exact 5-person roster.
 *
 * Usage: Open in browser → http://localhost/slicehub/scripts/reset_users.php
 */

require_once __DIR__ . '/../core/db_config.php';

$tenantId = 1;
$error    = null;
$users    = [];

$roster = [
    ['username' => 'manager_szef',   'pin' => '9999', 'first_name' => 'Manager', 'last_name' => 'Szef',  'name' => 'Manager Szef',   'role' => 'admin',  'is_driver' => false],
    ['username' => 'kelnerka_ania',  'pin' => '1111', 'first_name' => 'Ania',    'last_name' => null,     'name' => 'Kelnerka Ania',  'role' => 'waiter', 'is_driver' => false],
    ['username' => 'kelner_piotr',   'pin' => '2222', 'first_name' => 'Piotr',   'last_name' => null,     'name' => 'Kelner Piotr',   'role' => 'waiter', 'is_driver' => false],
    ['username' => 'kierowca_tomek', 'pin' => '3333', 'first_name' => 'Tomek',   'last_name' => null,     'name' => 'Kierowca Tomek', 'role' => 'driver', 'is_driver' => true],
    ['username' => 'kierowca_marek', 'pin' => '4444', 'first_name' => 'Marek',   'last_name' => null,     'name' => 'Kierowca Marek', 'role' => 'driver', 'is_driver' => true],
];

try {
    // Phase 1: Cleanup (DDL-free transaction)
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM sh_driver_shifts WHERE tenant_id = ?")->execute([$tenantId]);
    try { $pdo->prepare("DELETE FROM sh_driver_locations WHERE tenant_id = ?")->execute([$tenantId]); } catch (\Throwable $ignore) {}
    $pdo->prepare("DELETE FROM sh_users WHERE tenant_id = ?")->execute([$tenantId]);

    $pdo->commit();

    // Phase 2: DDL runs outside transaction (ALTER TABLE auto-commits in MariaDB)
    try { $pdo->exec("ALTER TABLE sh_users AUTO_INCREMENT = 1"); } catch (\Throwable $ignore) {}

    // Phase 3: Seed new roster
    $pdo->beginTransaction();

    $stmtUser = $pdo->prepare(
        "INSERT INTO sh_users (tenant_id, username, pin_code, first_name, last_name, name, role, status, is_active, is_deleted, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, 0, NOW())"
    );
    $stmtDriver = $pdo->prepare(
        "INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (?, ?, 'available')"
    );

    foreach ($roster as $r) {
        $stmtUser->execute([
            $tenantId, $r['username'], $r['pin'], $r['first_name'], $r['last_name'], $r['name'], $r['role'],
        ]);
        $userId = (int)$pdo->lastInsertId();

        if ($r['is_driver']) {
            $stmtDriver->execute([$userId, $tenantId]);
        }

        $users[] = [
            'id'   => $userId,
            'name' => $r['name'],
            'role' => $r['role'],
            'pin'  => $r['pin'],
        ];
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

$isOk = $error === null;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SliceHub — User Reset</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0e1a;--surface:#111827;--border:rgba(255,255,255,.08);--text:#f1f5f9;--muted:#64748b;--green:#22c55e;--red:#ef4444;--blue:#3b82f6;--orange:#f97316;--radius:14px;--font:'Segoe UI',system-ui,-apple-system,sans-serif}
html{height:100%}
body{min-height:100%;background:var(--bg);color:var(--text);font-family:var(--font);display:flex;align-items:center;justify-content:center;padding:24px}
.card{width:100%;max-width:640px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.card-header{padding:32px 32px 24px;text-align:center;border-bottom:1px solid var(--border)}
.logo{width:56px;height:56px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#fff;margin-bottom:16px}
.logo.ok{background:var(--green);box-shadow:0 0 30px rgba(34,197,94,.4)}
.logo.err{background:var(--red);box-shadow:0 0 30px rgba(239,68,68,.4)}
.title{font-size:22px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}
.subtitle{font-size:12px;color:var(--muted);margin-top:6px;font-weight:600}
.card-body{padding:24px 32px 32px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);text-align:left;padding:10px 12px;border-bottom:1px solid var(--border)}
td{font-size:14px;font-weight:600;padding:12px;border-bottom:1px solid var(--border)}
tr:last-child td{border-bottom:none}
.id-col{color:var(--muted);font-variant-numeric:tabular-nums;font-size:12px}
.pin{font-family:'Courier New',monospace;font-size:16px;font-weight:900;letter-spacing:.15em;color:var(--orange);background:rgba(249,115,22,.1);padding:3px 10px;border-radius:6px;display:inline-block}
.role{font-size:10px;font-weight:900;text-transform:uppercase;padding:3px 8px;border-radius:5px;display:inline-block;letter-spacing:.05em}
.role-admin{background:rgba(168,85,247,.15);color:#a855f7}
.role-waiter{background:rgba(59,130,246,.15);color:var(--blue)}
.role-driver{background:rgba(249,115,22,.15);color:var(--orange)}
.err-box{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:16px;color:var(--red);font-size:13px;font-weight:700;word-break:break-all}
.footer{text-align:center;padding:16px 32px 24px;font-size:10px;color:var(--muted);font-weight:700}
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="logo <?= $isOk ? 'ok' : 'err' ?>"><?= $isOk ? '&#10003;' : '&#10007;' ?></div>
        <div class="title"><?= $isOk ? 'Reset Complete' : 'Reset Failed' ?></div>
        <div class="subtitle">tenant_id = <?= $tenantId ?> &bull; slicehub_pro_v2 &bull; <?= date('Y-m-d H:i:s') ?></div>
    </div>
    <div class="card-body">
<?php if ($isOk): ?>
        <table>
            <thead>
                <tr><th>ID</th><th>Name</th><th>Role</th><th>PIN</th></tr>
            </thead>
            <tbody>
<?php foreach ($users as $u): ?>
                <tr>
                    <td class="id-col"><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><span class="role role-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
                    <td><span class="pin"><?= $u['pin'] ?></span></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php else: ?>
        <div class="err-box"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
    </div>
    <div class="footer">SLICEHUB ENTERPRISE OS &bull; TACTICAL RESET &bull; <?= count($users) ?> users seeded</div>
</div>
</body>
</html>
