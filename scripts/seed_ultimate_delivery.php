<?php
declare(strict_types=1);

/**
 * SLICEHUB — Ultimate Delivery Ecosystem Seeder
 * Generates realistic test data: drivers, delivery orders (paid & unpaid),
 * driver shifts, and GPS locations for immediate testing.
 *
 * Run: http://localhost/slicehub/scripts/seed_ultimate_delivery.php
 */

header('Content-Type: text/html; charset=utf-8');
echo '<html><body style="background:#0a0f1c;color:#f1f5f9;font-family:Inter,sans-serif;padding:40px">';
echo '<h1 style="color:#f97316">🚚 SliceHub — Ultimate Delivery Seed</h1>';

require_once __DIR__ . '/../core/db_config.php';

$tenantId = 1;

function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function seedLog(string $msg): void {
    echo '<p style="margin:4px 0;font-size:13px;color:#94a3b8">' . htmlspecialchars($msg) . '</p>';
}

// ── DRIVERS ──
$drivers = [
    ['username' => 'driver_marek',  'first_name' => 'Marek',  'last_name' => 'Kowalski', 'pin_code' => '1111'],
    ['username' => 'driver_kasia',  'first_name' => 'Kasia',  'last_name' => 'Nowak',    'pin_code' => '2222'],
    ['username' => 'driver_tomek',  'first_name' => 'Tomek',  'last_name' => 'Wiśniewski','pin_code' => '3333'],
    ['username' => 'driver_ania',   'first_name' => 'Ania',   'last_name' => 'Zielińska', 'pin_code' => '4444'],
];

$driverIds = [];
foreach ($drivers as $d) {
    $stmtCheck = $pdo->prepare("SELECT id FROM sh_users WHERE username = ? AND tenant_id = ?");
    $stmtCheck->execute([$d['username'], $tenantId]);
    $existing = $stmtCheck->fetchColumn();

    if ($existing) {
        $driverIds[] = (int)$existing;
        seedLog("Driver {$d['first_name']} already exists (ID: {$existing})");
    } else {
        $pdo->prepare(
            "INSERT INTO sh_users (tenant_id, username, password_hash, pin_code, first_name, last_name, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 'driver', 1)"
        )->execute([$tenantId, $d['username'], password_hash('driver123', PASSWORD_DEFAULT), $d['pin_code'], $d['first_name'], $d['last_name']]);
        $uid = (int)$pdo->lastInsertId();
        $driverIds[] = $uid;
        seedLog("Created driver: {$d['first_name']} {$d['last_name']} (ID: {$uid}, PIN: {$d['pin_code']})");
    }
}

foreach ($driverIds as $uid) {
    $pdo->prepare(
        "INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (?, ?, 'available')
         ON DUPLICATE KEY UPDATE status = 'available'"
    )->execute([$uid, $tenantId]);
}
seedLog("All drivers set to 'available'.");

// ── DRIVER SHIFTS ──
foreach ($driverIds as $uid) {
    $stmtS = $pdo->prepare("SELECT id FROM sh_driver_shifts WHERE driver_id = ? AND tenant_id = ? AND status = 'active'");
    $stmtS->execute([(string)$uid, $tenantId]);
    if (!$stmtS->fetch()) {
        $initCash = rand(5000, 15000);
        $pdo->prepare(
            "INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status) VALUES (?, ?, ?, 'active')"
        )->execute([$tenantId, (string)$uid, $initCash]);
        seedLog("Shift started for driver ID {$uid} with " . number_format($initCash / 100, 2) . " zł initial cash.");
    }
}

// ── GPS LOCATIONS (scatter around Poznań) ──
$baseLat = 52.4064;
$baseLng = 16.9252;
foreach ($driverIds as $uid) {
    $lat = $baseLat + (mt_rand(-200, 200) / 10000);
    $lng = $baseLng + (mt_rand(-200, 200) / 10000);
    $pdo->prepare(
        "INSERT INTO sh_driver_locations (driver_id, tenant_id, lat, lng, heading, speed_kmh, accuracy_m, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), heading=VALUES(heading), speed_kmh=VALUES(speed_kmh), updated_at=NOW()"
    )->execute([$uid, $tenantId, $lat, $lng, mt_rand(0, 359), mt_rand(10, 60) / 10.0, mt_rand(3, 20)]);
}
seedLog("GPS locations seeded for all drivers.");

// ── DELIVERY ORDERS ──
$addresses = [
    ['addr' => 'ul. Półwiejska 42, Poznań',        'lat' => 52.4034, 'lng' => 16.9340],
    ['addr' => 'ul. Św. Marcin 80/82, Poznań',     'lat' => 52.4082, 'lng' => 16.9210],
    ['addr' => 'os. Piastowskie 16/4, Poznań',     'lat' => 52.4110, 'lng' => 16.9385],
    ['addr' => 'ul. Głogowska 112, Poznań',        'lat' => 52.3950, 'lng' => 16.9010],
    ['addr' => 'ul. Ratajczaka 20, Poznań',        'lat' => 52.4060, 'lng' => 16.9270],
    ['addr' => 'ul. Jeżycka 8, Poznań',            'lat' => 52.4155, 'lng' => 16.9120],
    ['addr' => 'ul. Grunwaldzka 182, Poznań',      'lat' => 52.4200, 'lng' => 16.8850],
    ['addr' => 'os. Tysiąclecia 11/30, Poznań',    'lat' => 52.3880, 'lng' => 16.9550],
    ['addr' => 'ul. Winogrady 150, Poznań',         'lat' => 52.4230, 'lng' => 16.9450],
    ['addr' => 'ul. Piątkowska 70, Poznań',         'lat' => 52.4280, 'lng' => 16.8780],
    ['addr' => 'ul. Dąbrowskiego 55, Poznań',       'lat' => 52.4000, 'lng' => 16.9150],
    ['addr' => 'ul. Solna 4/12, Poznań',            'lat' => 52.4085, 'lng' => 16.9320],
];

$phones = ['+48 500 100 200', '+48 512 345 678', '+48 601 222 333', '+48 666 777 888', '+48 503 111 999', '+48 510 888 444', '+48 600 300 500', '+48 795 123 456', '+48 722 555 666', '+48 508 432 100', '+48 530 901 234', '+48 660 345 890'];
$names = ['Jan Kowalski', 'Anna Nowak', 'Piotr Wiśniewski', 'Marta Kamińska', 'Tomasz Lewandowski', 'Katarzyna Zielińska', 'Adam Szymański', 'Monika Wójcik', 'Krzysztof Dąbrowski', 'Ewa Kozłowska', 'Michał Jankowski', 'Agnieszka Mazur'];

$paymentConfigs = [
    ['method' => 'cash',   'status' => 'unpaid'],
    ['method' => 'card',   'status' => 'unpaid'],
    ['method' => 'online', 'status' => 'paid'],
    ['method' => 'cash',   'status' => 'unpaid'],
    ['method' => 'online', 'status' => 'paid'],
    ['method' => 'cash',   'status' => 'unpaid'],
    ['method' => 'card',   'status' => 'unpaid'],
    ['method' => 'online', 'status' => 'paid'],
    ['method' => 'cash',   'status' => 'unpaid'],
    ['method' => 'card',   'status' => 'unpaid'],
    ['method' => 'online', 'status' => 'paid'],
    ['method' => 'cash',   'status' => 'unpaid'],
];

$orderStatuses = ['ready', 'ready', 'ready', 'ready', 'pending', 'pending', 'ready', 'ready', 'preparing', 'ready', 'ready', 'ready'];

$menuItems = [
    ['name' => 'Margherita 32cm',    'price' => 2800],
    ['name' => 'Pepperoni 32cm',     'price' => 3200],
    ['name' => 'Capricciosa 32cm',   'price' => 3400],
    ['name' => 'Kebab XL',           'price' => 2600],
    ['name' => 'Burger Classic',     'price' => 2200],
    ['name' => 'Sałatka Cezar',      'price' => 1800],
    ['name' => 'Frytki duże',        'price' => 1200],
    ['name' => 'Cola 0.5L',          'price' => 600],
];

$seqStmt = $pdo->prepare(
    "INSERT INTO sh_order_sequences (tenant_id, `date`, seq)
     VALUES (?, CURDATE(), LAST_INSERT_ID(1))
     ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)"
);

$orderStmt = $pdo->prepare(
    "INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source, status,
        payment_method, payment_status, subtotal, grand_total, delivery_address, customer_phone,
        customer_name, lat, lng, promised_time, user_id, created_at)
     VALUES (?, ?, ?, 'Delivery', 'delivery', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
);

$lineStmt = $pdo->prepare(
    "INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total, vat_rate, vat_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, 5.00, ?)"
);

$createdOrders = 0;
for ($i = 0; $i < count($addresses); $i++) {
    $a = $addresses[$i];
    $pay = $paymentConfigs[$i];
    $status = $orderStatuses[$i];
    $source = $i % 3 === 0 ? 'online' : 'POS';

    $numItems = mt_rand(1, 3);
    $total = 0;
    $cartItems = [];
    for ($j = 0; $j < $numItems; $j++) {
        $item = $menuItems[array_rand($menuItems)];
        $qty = mt_rand(1, 2);
        $lineTotal = $item['price'] * $qty;
        $total += $lineTotal;
        $cartItems[] = ['name' => $item['name'], 'price' => $item['price'], 'qty' => $qty, 'lineTotal' => $lineTotal];
    }

    $deliveryFee = 800;
    $total += $deliveryFee;

    $seqStmt->execute([$tenantId]);
    $seq = (int)$pdo->lastInsertId();
    $orderNumber = sprintf('ORD/%s/%04d', date('Ymd'), $seq);
    $orderId = uuid4();

    $promisedMin = mt_rand(15, 60);
    $promised = date('Y-m-d H:i:s', strtotime("+{$promisedMin} minutes"));
    $creatorId = $driverIds[0] ?? 1;

    $orderStmt->execute([
        $orderId, $tenantId, $orderNumber, $source, $status,
        $pay['method'], $pay['status'], $total, $total,
        $a['addr'], $phones[$i], $names[$i],
        $a['lat'], $a['lng'], $promised, $creatorId
    ]);

    foreach ($cartItems as $ci) {
        $lineId = uuid4();
        $vat = (int)round($ci['lineTotal'] * 5 / 105);
        $lineStmt->execute([$lineId, $orderId, 'seed_item', $ci['name'], $ci['price'], $ci['qty'], $ci['lineTotal'], $vat]);
    }

    $createdOrders++;
    seedLog("Order #{$orderNumber} — {$a['addr']} — " . number_format($total / 100, 2) . " zł ({$pay['method']}/{$pay['status']}) [{$status}]");
}

echo '<hr style="border-color:rgba(255,255,255,0.1)">';
echo "<h2 style='color:#22c55e'>✅ Seed Complete!</h2>";
echo "<p><strong>{$createdOrders}</strong> delivery orders created</p>";
echo "<p><strong>" . count($driverIds) . "</strong> drivers with active shifts and GPS</p>";
echo "<br><p style='color:#94a3b8'>Driver PINs: Marek=<strong>1111</strong>, Kasia=<strong>2222</strong>, Tomek=<strong>3333</strong>, Ania=<strong>4444</strong></p>";
echo "<p style='color:#94a3b8'>Dispatcher: Use any existing manager/owner PIN or create one.</p>";
echo '<br><p><a href="/slicehub/modules/courses/index.html" style="color:#3b82f6;font-weight:800">→ Open Dispatcher</a> | <a href="/slicehub/modules/driver_app/index.html" style="color:#f97316;font-weight:800">→ Open Driver App</a></p>';
echo '</body></html>';
