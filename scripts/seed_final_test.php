<?php
declare(strict_types=1);

/**
 * SLICEHUB — Final Test Seeder
 * Creates a perfect test environment for the 3-pillar delivery state machine.
 *
 * Creates:
 *   - 2 ready delivery orders (1 pre-paid online, 1 to_pay cash)
 *   - 1 preparing delivery order (NOT dispatchable — Ready Lock test)
 *   - 1 available driver with active shift
 *
 * Usage: php scripts/seed_final_test.php
 */

require_once __DIR__ . '/../core/db_config.php';

$tenantId = 1;
$now = date('Y-m-d H:i:s');
$promised = date('Y-m-d H:i:s', strtotime('+35 minutes'));

echo "=== SLICEHUB FINAL TEST SEEDER ===\n";
echo "Tenant: {$tenantId}\n";
echo "Time:   {$now}\n\n";

// Ensure delivery_status column exists (auto-migration)
try {
    $pdo->query("SELECT delivery_status FROM sh_orders LIMIT 0");
} catch (\PDOException $e) {
    $pdo->exec("ALTER TABLE sh_orders ADD COLUMN delivery_status VARCHAR(32) NULL DEFAULT NULL");
    echo "[MIGRATE] Added delivery_status column.\n";
}
try {
    $pdo->query("SELECT cancellation_reason FROM sh_orders LIMIT 0");
} catch (\PDOException $e) {
    $pdo->exec("ALTER TABLE sh_orders ADD COLUMN cancellation_reason TEXT NULL");
    echo "[MIGRATE] Added cancellation_reason column.\n";
}

// UUID helper
function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$pdo->beginTransaction();

try {
    // Ensure driver user exists
    $driverUserId = null;
    $stmtDriver = $pdo->prepare("SELECT id FROM sh_users WHERE tenant_id = ? AND role = 'driver' AND is_deleted = 0 LIMIT 1");
    $stmtDriver->execute([$tenantId]);
    $driverUserId = $stmtDriver->fetchColumn();

    if (!$driverUserId) {
        $pdo->prepare(
            "INSERT INTO sh_users (tenant_id, username, pin_code, first_name, last_name, role, status)
             VALUES (?, 'driver_test', '1111', 'Marek', 'Kowalski', 'driver', 'active')"
        )->execute([$tenantId]);
        $driverUserId = (int)$pdo->lastInsertId();
        echo "[CREATE] Driver user: id={$driverUserId} pin=1111\n";
    } else {
        echo "[EXISTS] Driver user: id={$driverUserId}\n";
    }

    // Ensure sh_drivers row
    $stmtDrv = $pdo->prepare("SELECT user_id FROM sh_drivers WHERE user_id = ? AND tenant_id = ?");
    $stmtDrv->execute([$driverUserId, $tenantId]);
    if (!$stmtDrv->fetch()) {
        $pdo->prepare("INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (?, ?, 'available')")
            ->execute([$driverUserId, $tenantId]);
        echo "[CREATE] sh_drivers row: available\n";
    } else {
        $pdo->prepare("UPDATE sh_drivers SET status = 'available' WHERE user_id = ? AND tenant_id = ?")
            ->execute([$driverUserId, $tenantId]);
        echo "[UPDATE] Driver status → available\n";
    }

    // Ensure active shift
    $stmtShift = $pdo->prepare("SELECT id FROM sh_driver_shifts WHERE driver_id = ? AND tenant_id = ? AND status = 'active' LIMIT 1");
    $stmtShift->execute([$driverUserId, $tenantId]);
    if (!$stmtShift->fetch()) {
        $pdo->prepare(
            "INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status, created_at)
             VALUES (?, ?, 5000, 'active', NOW())"
        )->execute([$tenantId, $driverUserId]);
        echo "[CREATE] Active shift: initial_cash=50.00 PLN\n";
    } else {
        echo "[EXISTS] Active shift\n";
    }

    // Order sequence
    $pdo->prepare(
        "INSERT INTO sh_order_sequences (tenant_id, `date`, seq) VALUES (?, CURDATE(), 0)
         ON DUPLICATE KEY UPDATE seq = seq"
    )->execute([$tenantId]);

    // Helper: create order + lines
    $createOrder = function(string $status, string $payStatus, ?string $payMethod, string $deliveryStatus, string $addr, ?string $phone, ?string $custName, array $cart, int $total) use ($pdo, $tenantId, $now, $promised) {
        $pdo->prepare(
            "UPDATE sh_order_sequences SET seq = LAST_INSERT_ID(seq + 1) WHERE tenant_id = ? AND `date` = CURDATE()"
        )->execute([$tenantId]);
        $seq = (int)$pdo->lastInsertId();
        $orderNum = sprintf('ORD/%s/%04d', date('Ymd'), $seq);
        $orderId = uuid();

        $pdo->prepare(
            "INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source, status,
                 payment_method, payment_status, subtotal, grand_total,
                 delivery_address, customer_phone, customer_name,
                 promised_time, delivery_status, user_id, created_at)
             VALUES (?,?,?,'Delivery','delivery','POS',?,?,?,?,?,?,?,?,?,?,1,?)"
        )->execute([
            $orderId, $tenantId, $orderNum, $status,
            $payMethod, $payStatus, $total, $total,
            $addr, $phone, $custName,
            $promised, $deliveryStatus, $now
        ]);

        $stmtLine = $pdo->prepare(
            "INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total, vat_rate, vat_amount)
             VALUES (?,?,?,?,?,?,?,8.00,?)"
        );
        foreach ($cart as $item) {
            $lineTotal = $item['price'] * $item['qty'];
            $vat = (int)round($lineTotal * 8 / 108);
            $stmtLine->execute([uuid(), $orderId, $item['sku'], $item['name'], $item['price'], $item['qty'], $lineTotal, $vat]);
        }

        return [$orderId, $orderNum];
    };

    // ── ORDER 1: Ready + online_paid (pre-paid, one-click deliver) ──
    [$id1, $num1] = $createOrder(
        'ready', 'online_paid', 'online', 'unassigned',
        'ul. Marszałkowska 12/5, Warszawa', '+48600111222', 'Anna Nowak',
        [
            ['sku' => 'MARG32', 'name' => 'Margherita 32cm', 'price' => 2800, 'qty' => 1],
            ['sku' => 'COLA05', 'name' => 'Cola 0.5L', 'price' => 600, 'qty' => 2],
        ],
        4000
    );
    echo "[ORDER 1] {$num1} → status=ready, payment=online_paid, delivery=unassigned  (40.00 PLN)\n";

    // ── ORDER 2: Ready + to_pay (cash at door — Payment Lock test) ──
    [$id2, $num2] = $createOrder(
        'ready', 'to_pay', null, 'unassigned',
        'ul. Puławska 87, Warszawa', '+48600333444', 'Jan Kowalski',
        [
            ['sku' => 'PEPP32', 'name' => 'Pepperoni 32cm', 'price' => 3200, 'qty' => 1],
            ['sku' => 'FRIES',  'name' => 'Frytki duże', 'price' => 1200, 'qty' => 1],
        ],
        4400
    );
    echo "[ORDER 2] {$num2} → status=ready, payment=to_pay, delivery=unassigned  (44.00 PLN)\n";

    // ── ORDER 3: Preparing (NOT dispatchable — Ready Lock test) ──
    [$id3, $num3] = $createOrder(
        'preparing', 'to_pay', null, 'unassigned',
        'ul. Nowy Świat 33, Warszawa', '+48600555666', 'Maria Wiśniewska',
        [
            ['sku' => 'HAWAI32', 'name' => 'Hawajska 32cm', 'price' => 3000, 'qty' => 2],
        ],
        6000
    );
    echo "[ORDER 3] {$num3} → status=preparing, payment=to_pay, delivery=unassigned  (60.00 PLN)  ⛔ NOT DISPATCHABLE\n";

    $pdo->commit();

    echo "\n✅ SEED COMPLETE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Driver PIN: 1111 (id: {$driverUserId})\n";
    echo "Order 1 (ready/online_paid):  {$id1}\n";
    echo "Order 2 (ready/to_pay):       {$id2}\n";
    echo "Order 3 (preparing/to_pay):   {$id3}  ← Ready Lock blocks this\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\nTest flow:\n";
    echo "  1. Open Dispatcher → Orders tab → Only Order 1 & 2 are selectable (Order 3 dimmed)\n";
    echo "  2. Select driver + orders 1&2 → Dispatch → Course K{n} created\n";
    echo "  3. Open Driver App (PIN 1111) → See 2 stops\n";
    echo "  4. Order 1: Click 'Dostarczono' directly (online_paid = no lock)\n";
    echo "  5. Order 2: 'Dostarcz' locked → Click 'Gotówka' first → Then 'Dostarczono'\n";
    echo "  6. After both delivered → Driver auto-returns to 'available'\n";
    echo "  7. Test cancel: Create another run, then use 'Anuluj zamówienie' on driver app\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
