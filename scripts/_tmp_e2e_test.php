<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$base = 'http://localhost/slicehub/api';

function api(string $url, array $payload, string $token = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $token ? "Authorization: Bearer $token" : '',
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $code, 'body' => json_decode((string)$body, true) ?? [], 'raw' => $body];
}

function check(string $label, bool $pass, string $detail = ''): void {
    $icon = $pass ? '<span style="color:#22c55e">✓ PASS</span>' : '<span style="color:#ef4444">✗ FAIL</span>';
    echo "  $icon  $label" . ($detail ? " — $detail" : '') . "\n";
}

echo "<pre style='background:#0a0a0f;color:#e2e8f0;padding:24px;font-family:Consolas,monospace;font-size:12px;line-height:1.6;'>";
echo "<b style='color:#a78bfa;font-size:14px;'>SLICEHUB — END-TO-END POS↔DRIVER TEST</b>\n";
echo str_repeat('═', 60) . "\n\n";

// ═══ 1. LOGIN (waiter) ═══
echo "<b style='color:#60a5fa'>1. LOGIN (waiter1 / PIN 1111)</b>\n";
$login = api("$base/auth/login.php", ['mode' => 'kiosk', 'tenant_id' => 1, 'pin_code' => '1111']);
$token = $login['body']['data']['token'] ?? '';
check('Waiter login', !empty($token), 'HTTP ' . $login['http']);

// ═══ 2. GET_INIT_DATA — check drivers loaded ═══
echo "\n<b style='color:#60a5fa'>2. GET_INIT_DATA — drivers</b>\n";
$init = api("$base/pos/engine.php", ['action' => 'get_init_data'], $token);
$drivers = $init['body']['data']['drivers'] ?? [];
check('Init data loaded', $init['body']['success'] ?? false);
check('Drivers found', count($drivers) > 0, count($drivers) . ' drivers');
foreach ($drivers as $d) {
    echo "    Driver #{$d['id']} {$d['display_name']} — status: <b>{$d['status']}</b>\n";
}

// ═══ 3. GET_ORDERS — check online/pulse orders ═══
echo "\n<b style='color:#60a5fa'>3. GET_ORDERS — pulse + battlefield</b>\n";
$ordRes = api("$base/pos/engine.php", ['action' => 'get_orders'], $token);
$allOrders = $ordRes['body']['data']['orders'] ?? [];
$freshDrivers = $ordRes['body']['data']['drivers'] ?? [];
check('Orders loaded', $ordRes['body']['success'] ?? false, count($allOrders) . ' total');
check('Drivers in get_orders response', count($freshDrivers) > 0, count($freshDrivers) . ' drivers');

$pulseOrders = array_filter($allOrders, fn($o) => ($o['source'] ?? '') !== 'local' && ($o['status'] ?? '') === 'new');
$deliveryReady = array_filter($allOrders, fn($o) => ($o['order_type'] ?? '') === 'delivery' && in_array($o['status'] ?? '', ['ready','pending','preparing']));
echo "  Pulse (online new): " . count($pulseOrders) . "\n";
echo "  Delivery dispatchable: " . count($deliveryReady) . "\n";

// ═══ 4. ACCEPT an online order (pulse → battlefield) ═══
echo "\n<b style='color:#60a5fa'>4. ACCEPT ORDER (Pulse → Battlefield)</b>\n";
$pulseOrder = reset($pulseOrders);
if ($pulseOrder) {
    $acceptRes = api("$base/pos/engine.php", [
        'action' => 'accept_order',
        'order_id' => $pulseOrder['id'],
        'custom_time' => date('Y-m-d\TH:i', time() + 2400),
    ], $token);
    check('Accept order', $acceptRes['body']['success'] ?? false, $pulseOrder['order_number'] ?? '?');
} else {
    echo "  <span style='color:#f59e0b'>⚠ No pulse orders to accept — skipping</span>\n";
}

// ═══ 5. STATUS FLOW: pending → preparing → ready ═══
echo "\n<b style='color:#60a5fa'>5. STATUS FLOW (pending → preparing → ready)</b>\n";
// Refresh orders
$ordRes2 = api("$base/pos/engine.php", ['action' => 'get_orders'], $token);
$allOrders2 = $ordRes2['body']['data']['orders'] ?? [];
$pendingDelivery = null;
foreach ($allOrders2 as $o) {
    if (($o['order_type'] ?? '') === 'delivery' && ($o['status'] ?? '') === 'pending') {
        $pendingDelivery = $o; break;
    }
}

if ($pendingDelivery) {
    // pending → preparing
    $r1 = api("$base/pos/engine.php", ['action' => 'update_status', 'order_id' => $pendingDelivery['id'], 'status' => 'preparing'], $token);
    check('pending → preparing', $r1['body']['success'] ?? false, $pendingDelivery['order_number'] ?? '?');

    // preparing → ready
    $r2 = api("$base/pos/engine.php", ['action' => 'update_status', 'order_id' => $pendingDelivery['id'], 'status' => 'ready'], $token);
    check('preparing → ready', $r2['body']['success'] ?? false);
    $dispatchOrderId = $pendingDelivery['id'];
} else {
    echo "  <span style='color:#f59e0b'>⚠ No pending delivery — looking for ready delivery</span>\n";
    $dispatchOrderId = null;
    foreach ($allOrders2 as $o) {
        if (($o['order_type'] ?? '') === 'delivery' && ($o['status'] ?? '') === 'ready') {
            $dispatchOrderId = $o['id']; break;
        }
    }
}

// ═══ 6. DISPATCH (assign_route) ═══
echo "\n<b style='color:#60a5fa'>6. DISPATCH (assign_route)</b>\n";
$availableDriver = null;
foreach ($drivers as $d) {
    if (($d['status'] ?? '') === 'available' || ($d['status'] ?? '') === 'offline') {
        $availableDriver = $d; break;
    }
}

if ($dispatchOrderId && $availableDriver) {
    // If driver is offline, try to set available first
    if ($availableDriver['status'] !== 'available') {
        api("$base/courses/engine.php", [
            'action' => 'set_driver_status',
            'driver_user_id' => (string)$availableDriver['id'],
            'status' => 'available',
        ], $token);
        echo "  Set driver #{$availableDriver['id']} to available\n";
    }

    $dispRes = api("$base/pos/engine.php", [
        'action' => 'assign_route',
        'driver_id' => (string)$availableDriver['id'],
        'order_ids' => [$dispatchOrderId],
    ], $token);
    $courseId = $dispRes['body']['data']['course_id'] ?? '?';
    check('Dispatch route', $dispRes['body']['success'] ?? false, "Course: $courseId, Driver: {$availableDriver['display_name']}");

    if (!($dispRes['body']['success'] ?? false)) {
        echo "  Error: " . ($dispRes['body']['message'] ?? 'unknown') . "\n";
        echo "  Raw: " . htmlspecialchars(substr((string)($dispRes['raw'] ?? ''), 0, 300)) . "\n";
    }

    // Check driver is now busy
    $ordRes3 = api("$base/pos/engine.php", ['action' => 'get_orders'], $token);
    $updatedDrivers = $ordRes3['body']['data']['drivers'] ?? [];
    $driverAfter = null;
    foreach ($updatedDrivers as $ud) {
        if ((string)$ud['id'] === (string)$availableDriver['id']) { $driverAfter = $ud; break; }
    }
    check('Driver status → busy', ($driverAfter['status'] ?? '') === 'busy', 'status: ' . ($driverAfter['status'] ?? '?'));

    // Check order is in_delivery
    $dispOrder = null;
    foreach ($ordRes3['body']['data']['orders'] ?? [] as $o) {
        if ($o['id'] === $dispatchOrderId) { $dispOrder = $o; break; }
    }
    check('Order status → in_delivery', ($dispOrder['status'] ?? '') === 'in_delivery', 'course: ' . ($dispOrder['course_id'] ?? '?'));
} else {
    echo "  <span style='color:#ef4444'>Cannot test dispatch: missing " . (!$dispatchOrderId ? 'delivery order' : 'driver') . "</span>\n";
}

// ═══ 7. DRIVER APP — login + get_driver_runs ═══
echo "\n<b style='color:#60a5fa'>7. DRIVER APP (login + get_driver_runs)</b>\n";
$driverLogin = api("$base/auth/login.php", ['mode' => 'kiosk', 'tenant_id' => 1, 'pin_code' => '4444']);
$driverToken = $driverLogin['body']['data']['token'] ?? '';
check('Driver login (PIN 4444)', !empty($driverToken));

if ($driverToken) {
    $runs = api("$base/courses/engine.php", ['action' => 'get_driver_runs'], $driverToken);
    $driverOrders = $runs['body']['data']['orders'] ?? [];
    check('get_driver_runs', $runs['body']['success'] ?? false, count($driverOrders) . ' orders');

    foreach ($driverOrders as $do) {
        echo "    {$do['order_number']} | {$do['status']} | {$do['course_id']} {$do['stop_number']} | {$do['delivery_address']}\n";
    }

    $wallet = $runs['body']['data']['wallet'] ?? [];
    echo "    Wallet: initial=" . (($wallet['initial_cash'] ?? 0) / 100) . " | collected=" . (($wallet['cash_collected'] ?? 0) / 100) . " | total=" . (($wallet['total_in_hand'] ?? 0) / 100) . "\n";

    // ═══ 8. DRIVER completes delivery ═══
    echo "\n<b style='color:#60a5fa'>8. DRIVER completes delivery</b>\n";
    if (count($driverOrders) > 0) {
        $firstRun = $driverOrders[0];
        $completeRes = api("$base/courses/engine.php", [
            'action' => 'update_order_status',
            'order_id' => $firstRun['id'],
            'new_status' => 'completed',
        ], $driverToken);
        check('Driver marks completed', $completeRes['body']['success'] ?? false, $firstRun['order_number']);

        // Check if driver released (if all orders in course done)
        $runs2 = api("$base/courses/engine.php", ['action' => 'get_driver_runs'], $driverToken);
        $remaining = count($runs2['body']['data']['orders'] ?? []);
        echo "    Remaining orders: $remaining\n";
    } else {
        echo "  <span style='color:#f59e0b'>⚠ No orders to complete</span>\n";
    }
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "<b style='color:#22c55e'>END-TO-END TEST COMPLETE</b>\n";
echo "</pre>";
