<?php
// 🌐 SLICEHUB ONLINE STORE API v1.0 - PUBLIC GATEWAY
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$host = 'localhost';
$db   = 'baza_slicehub';
$user = 'damian_admin';
$pass = 'Dammalq123123@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Exception $e) {
    die(json_encode(['status' => 'error', 'error' => 'Błąd bazy danych (Online).']));
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? $_GET['action'] ?? '');

// UWAGA: Sklep przypisany na sztywno do lokalu ID 1 (SliceHub Base)
$tenant_id = 1; 

function generate_uuid() {
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}

// 🟢 1. POBIERZ MENU DLA KLIENTA
if ($action === 'get_menu') {
    $stmtCat = $pdo->prepare("SELECT id, name AS name_utf8 FROM sh_categories WHERE tenant_id = ? AND is_menu = 1 ORDER BY display_order ASC");
    $stmtCat->execute([$tenant_id]);
    $stmtItems = $pdo->prepare("SELECT id, category_id, name, price, type FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0");
    $stmtItems->execute([$tenant_id]);
    
    echo json_encode([
        'status' => 'success', 
        'payload' => [
            'categories' => $stmtCat->fetchAll(),
            'items' => $stmtItems->fetchAll()
        ]
    ]);
    exit;
}

// 🟢 2. ZŁÓŻ ZAMÓWIENIE (WPADA DO 'THE PULSE')
if ($action === 'submit_order') {
    $cart = $input['cart'] ?? [];
    $type = $input['order_type'] ?? 'delivery'; // takeaway lub delivery
    $payment_method = $input['payment_method'] ?? 'cash';
    $customer_name = trim($input['customer_name'] ?? 'Klient Online');
    $customer_phone = trim($input['customer_phone'] ?? '');
    $address = trim($input['address'] ?? '');
    $total_price = (float)($input['total_price'] ?? 0);
    $requested_time = trim($input['requested_time'] ?? ''); // ASAP lub godzina
    
    if (empty($cart) || empty($customer_phone)) {
        echo json_encode(['status' => 'error', 'error' => 'Brakujące dane zamówienia lub numer telefonu.']);
        exit;
    }

    // Jeśli klient płaci online (Blik/PayU - symulacja), status to paid, jeśli przy odbiorze to unpaid
    $payment_status = ($payment_method === 'online') ? 'paid' : 'unpaid';
    
    // Status 'new' to flaga, która sprawia, że POS widzi to w THE PULSE!
    $status = 'new'; 
    $cart_json = json_encode($cart);

    try {
        $pdo->beginTransaction();
        $uuid = generate_uuid();
        $order_number = 'WWW/' . date('Ymd/His');
        
        $promised_sql = "NOW()";
        if($requested_time !== 'asap') {
            // Jeśli klient podał godzinę np "18:30"
            $promised_sql = "'" . date('Y-m-d') . " " . $requested_time . ":00'";
        }

        $stmtOrder = $pdo->prepare("INSERT INTO sh_orders (tenant_id, uuid, order_number, source, type, status, payment_method, payment_status, total_price, customer_name, customer_phone, address, cart_json, promised_time) VALUES (?, ?, ?, 'online', ?, ?, ?, ?, ?, ?, ?, ?, ?, $promised_sql)");
        $stmtOrder->execute([$tenant_id, $uuid, $order_number, $type, $status, $payment_method, $payment_status, $total_price, $customer_name, $customer_phone, $address, $cart_json]);
        $order_id = $pdo->lastInsertId();

        // Dodajemy pozycje do bazy szczegółowej (Dla statystyk, nie ściągamy jeszcze ze stanu! Zrobi to POS przy akceptacji)
        foreach ($cart as $item) {
            $qty = (float)$item['qty'];
            $stmtItem = $pdo->prepare("INSERT INTO sh_order_items (order_id, menu_item_id, snapshot_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            $stmtItem->execute([$order_id, $item['id'] ?? null, $item['name'], $qty, $item['price']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'payload' => ['message' => 'Zamówienie przyjęte! Restauracja zaraz je potwierdzi.']]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'error' => 'Błąd zapisu zamówienia: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'error' => 'Nieznana akcja API Online.']);
?>