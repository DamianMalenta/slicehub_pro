<?php
// 🛡️ SLICEHUB DELIVERY API v1.0 - CONTROL TOWER
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

require_role(['waiter', 'admin', 'manager', 'owner']);
$action = trim($input['action'] ?? $_GET['action'] ?? '');
$tenant_id = $_SESSION['tenant_id'] ?? 0;

// 🚀 AUTO-MIGRACJA BAZY (DODANIE POGOTOWIA KASOWEGO DLA KIEROWCY)
try { $pdo->exec("ALTER TABLE sh_drivers ADD COLUMN initial_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00"); } catch (Exception $e) {}

// 🟢 1. POBIERANIE DANYCH DYSPOZYTORA (PRE-ROUTING)
if ($action === 'get_dashboard') {
    // Pobieramy tylko zamówienia na dowóz (Na kuchni, Gotowe, W trasie)
    $stmtOrders = $pdo->prepare("
        SELECT o.id, o.order_number, o.status, o.promised_time, o.total_price, o.address, o.payment_method, o.payment_status, o.driver_id,
        (SELECT GROUP_CONCAT(CONCAT(quantity, 'x ', snapshot_name) SEPARATOR ', ') FROM sh_order_items WHERE order_id = o.id) as basket_summary
        FROM sh_orders o 
        WHERE o.tenant_id = ? AND o.type = 'delivery' AND o.status IN ('pending', 'ready', 'in_delivery')
        ORDER BY o.promised_time ASC
    ");
    $stmtOrders->execute([$tenant_id]);
    $orders = $stmtOrders->fetchAll();

    // Pobieramy kierowców i ich statystyki na żywo
    $stmtDrivers = $pdo->prepare("SELECT d.id, d.user_id, d.status, d.initial_cash, u.first_name, u.last_name FROM sh_drivers d JOIN sh_users u ON d.user_id = u.id WHERE u.tenant_id = ?");
    $stmtDrivers->execute([$tenant_id]);
    $drivers = $stmtDrivers->fetchAll();

    // Dla każdego kierowcy liczymy ile ma aktualnie gotówki z dzisiejszych rozliczonych zamówień
    foreach ($drivers as &$driver) {
        $stmtCash = $pdo->prepare("SELECT SUM(total_price) FROM sh_orders WHERE tenant_id = ? AND driver_id = ? AND payment_method = 'cash' AND status = 'completed' AND DATE(created_at) = CURDATE()");
        $stmtCash->execute([$tenant_id, $driver['id']]);
        $driver['collected_cash'] = (float)$stmtCash->fetchColumn();
        $driver['expected_total'] = $driver['initial_cash'] + $driver['collected_cash'];
    }

    sendResponse('success', ['orders' => $orders, 'drivers' => $drivers]);
}

// 🟢 2. USTAWIANIE POGOTOWIA KASOWEGO
if ($action === 'set_initial_cash') {
    $driver_id = (int)($input['driver_id'] ?? 0);
    $cash = (float)($input['cash'] ?? 0);
    
    if ($driver_id) {
        $pdo->prepare("UPDATE sh_drivers SET initial_cash = ? WHERE id = ?")->execute([$cash, $driver_id]);
        sendResponse('success', ['message' => 'Pogotowie kasowe wydane!']);
    }
    sendResponse('error', null, 'Brak ID kierowcy.');
}

// 🟢 3. PRZYPISYWANIE TRASY (SMART ROUTING)
if ($action === 'assign_route') {
    $driver_id = (int)($input['driver_id'] ?? 0);
    $order_ids = $input['order_ids'] ?? [];
    
    if ($driver_id && !empty($order_ids) && is_array($order_ids)) {
        $in  = str_repeat('?,', count($order_ids) - 1) . '?';
        $params = array_merge(['in_delivery', $driver_id], $order_ids);
        $pdo->prepare("UPDATE sh_orders SET status = ?, driver_id = ? WHERE id IN ($in)")->execute($params);
        sendResponse('success', ['message' => 'Kierowca wysłany w trasę!']);
    }
    sendResponse('error', null, 'Zaznacz kierowcę i zamówienia.');
}

// 🟢 4. ZDEJMOWANIE Z TRASY (Gdy dyspozytor musi cofnąć błąd)
if ($action === 'cancel_route') {
    $order_id = (int)($input['order_id'] ?? 0);
    if ($order_id) {
        $pdo->prepare("UPDATE sh_orders SET status = 'ready', driver_id = NULL WHERE id = ? AND tenant_id = ?")->execute([$order_id, $tenant_id]);
        sendResponse('success', ['message' => 'Zamówienie cofnięte na blat.']);
    }
}

sendResponse('error', null, 'Brak akcji.');
?>