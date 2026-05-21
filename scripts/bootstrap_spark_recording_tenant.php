#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tworzy dedykowany tenant pod nagranie SPARK na localhost (VM).
 * Uruchom: php scripts/bootstrap_spark_recording_tenant.php
 *
 * Output: /opt/cursor/artifacts/spark_recording_env.json
 */
require_once __DIR__ . '/../core/db_config.php';

if (!isset($pdo)) {
    fwrite(STDERR, "❌ Brak połączenia z bazą. Uruchom MariaDB i slicehub_pro_v2.\n");
    exit(1);
}

$PW = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // password
$tenantName = 'Pizza Forno SPARK';
$slug = 'pizza-forno-spark';
$nip = '7390100021';

$out = [
    'base_url' => 'http://localhost/slicehub',
    'tenant_name' => $tenantName,
];

try {
    $pdo->beginTransaction();

    $hasSlug = (bool)$pdo->query("SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'sh_tenant' AND column_name = 'slug' LIMIT 1")->fetchColumn();

    if ($hasSlug) {
        $st = $pdo->prepare('INSERT INTO sh_tenant (name, slug, nip) VALUES (?, ?, ?)');
        $st->execute([$tenantName, $slug, $nip]);
    } else {
        $st = $pdo->prepare('INSERT INTO sh_tenant (name) VALUES (?)');
        $st->execute([$tenantName]);
    }
    $tid = (int)$pdo->lastInsertId();
    if ($tid <= 0) {
        throw new RuntimeException('Nie udało się utworzyć tenanta.');
    }

    $pdo->exec("INSERT INTO sh_tenant_settings (tenant_id, setting_key, is_active, min_order_value, min_prep_time_minutes, sla_green_min, sla_yellow_min, base_prep_minutes, min_lead_time_minutes, setting_value)
        VALUES ({$tid}, '', 1, 0, 30, 10, 5, 25, 30, NULL)
        ON DUPLICATE KEY UPDATE is_active=1");

    $users = [
        ['spark_owner', 'owner', 'Damian', 'Malenta', null],
        ['spark_driver', 'driver', 'Kasia', 'Kierowca', '4444'],
        ['spark_manager', 'manager', 'Anna', 'Manager', '0000'],
    ];
    $ownerId = 0;
    $driverId = 0;

    $ins = $pdo->prepare(
        'INSERT INTO sh_users (tenant_id, username, password_hash, pin_code, name, first_name, last_name, role, status, is_active, is_deleted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', 1, 0)'
    );
    foreach ($users as [$user, $role, $fn, $ln, $pin]) {
        $ins->execute([$tid, $user, $PW, $pin, "{$fn} {$ln}", $fn, $ln, $role]);
        $uid = (int)$pdo->lastInsertId();
        if ($role === 'owner') {
            $ownerId = $uid;
        }
        if ($role === 'driver') {
            $driverId = $uid;
            $pdo->prepare('INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (?, ?, \'available\')')
                ->execute([$uid, $tid]);
        }
    }

    $pdo->commit();

    $out['tenant_id'] = $tid;
    $out['owner'] = ['username' => 'spark_owner', 'password' => 'password', 'user_id' => $ownerId];
    $out['driver'] = ['username' => 'spark_driver', 'password' => 'password', 'user_id' => $driverId, 'pin' => '4444'];
    $out['pos_pin'] = '0000';
    $out['online_url'] = "http://localhost/slicehub/modules/online/index.html?tenant={$tid}";
    $out['hub_url'] = 'http://localhost/slicehub/modules/hub/index.html';

    // Seed Pizza Forno SQL z podmienionym @tid
    $seedFile = __DIR__ . '/seed_pizzaforno.sql';
    if (!is_readable($seedFile)) {
        throw new RuntimeException('Brak scripts/seed_pizzaforno.sql');
    }
    $sql = file_get_contents($seedFile);
    $sql = preg_replace('/SET\s+@tid\s*:=\s*\d+\s*;/i', "SET @tid := {$tid};", $sql, 1);

    $tmp = sys_get_temp_dir() . "/seed_pizzaforno_tid_{$tid}.sql";
    file_put_contents($tmp, $sql);

    $dbName = 'slicehub_pro_v2';
    $cmd = sprintf(
        'mysql -u root %s < %s 2>&1',
        escapeshellarg($dbName),
        escapeshellarg($tmp)
    );
    exec($cmd, $mysqlOut, $code);
    @unlink($tmp);

    if ($code !== 0) {
        $out['seed_warning'] = implode("\n", $mysqlOut);
    } else {
        $out['seed'] = 'seed_pizzaforno.sql OK';
    }

    // tracking_token dla FORNO-006
    $pdo->exec("UPDATE sh_orders SET tracking_token = LOWER(SUBSTRING(REPLACE(id,'-',''), 1, 16))
        WHERE tenant_id = {$tid} AND order_number = 'FORNO-006' AND (tracking_token IS NULL OR tracking_token = '')");
    $tok = $pdo->query("SELECT tracking_token, customer_phone FROM sh_orders WHERE tenant_id = {$tid} AND order_number = 'FORNO-006' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($tok) {
        $phone = urlencode((string)$tok['customer_phone']);
        $token = (string)$tok['tracking_token'];
        $out['track_url'] = "http://localhost/slicehub/modules/online/track.html?tenant={$tid}&token={$token}&phone={$phone}";
    }

    $artifact = '/opt/cursor/artifacts/spark_recording_env.json';
    if (!is_dir(dirname($artifact))) {
        $artifact = __DIR__ . '/../_docs/spark_recording_env.json';
    }
    file_put_contents($artifact, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo "✅ Tenant SPARK nagranie utworzony\n";
    echo "   tenant_id: {$tid}\n";
    echo "   owner: spark_owner / password\n";
    echo "   driver: spark_driver / password (PIN 4444)\n";
    echo "   online: {$out['online_url']}\n";
    echo "   env file: {$artifact}\n";
    if (!empty($out['seed_warning'])) {
        echo "⚠️  seed: {$out['seed_warning']}\n";
        exit(2);
    }
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '❌ ' . $e->getMessage() . "\n");
    exit(1);
}
