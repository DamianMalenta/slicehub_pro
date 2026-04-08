<?php
// 🛡️ SLICEHUB DRIVER API v3.1 - ZGODNY Z BAZĄ V6
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

// Zabezpieczenie dostępu
require_role(['driver', 'admin', 'manager', 'owner']);
$action = trim($action ?? $_GET['action'] ?? '');

// 🟢 1. POBIERANIE AKTUALNYCH KURSÓW KIEROWCY
if ($action === 'get_my_routes') {
    // Pobieramy zamówienia przypisane do tego kierowcy, które nie są jeszcze zakończone
    $stmt = $pdo->prepare("
        SELECT id, order_number, status, payment_method, payment_status, total_price, address, customer_phone, customer_name 
        FROM sh_orders 
        WHERE tenant_id = ? AND driver_id = ? AND status IN ('ready', 'in_delivery') 
        ORDER BY status DESC, created_at ASC
    ");
    $stmt->execute([$tenant_id, $user_id]);
    
    // Obliczamy ile gotówki kierowca ma aktualnie "w kieszeni" z dzisiejszych dostaw
    $stmtCash = $pdo->prepare("
        SELECT SUM(total_price) as cash_in_hand 
        FROM sh_orders 
        WHERE tenant_id = ? AND driver_id = ? AND payment_method = 'cash' AND status = 'completed' AND DATE(created_at) = CURDATE()
    ");
    $stmtCash->execute([$tenant_id, $user_id]);
    $cash = $stmtCash->fetchColumn() ?: 0.00;

    sendResponse('success', [
        'routes' => $stmt->fetchAll(),
        'cash_in_hand' => number_format((float)$cash, 2, '.', '')
    ]);
}

// 🟢 2. ZMIANA STATUSU ZAMÓWIENIA (W drodze / Dostarczone)
if ($action === 'update_route_status') {
    $order_id = (int)($input['order_id'] ?? 0);
    $new_status = trim($input['new_status'] ?? '');

    if ($order_id === 0 || !in_array($new_status, ['in_delivery', 'completed'])) {
        sendResponse('error', null, 'Nieprawidłowe dane.');
    }

    try {
        $pdo->beginTransaction();

        // Zmiana statusu w zamówieniu
        $stmtUpdate = $pdo->prepare("UPDATE sh_orders SET status = ? WHERE id = ? AND driver_id = ? AND tenant_id = ?");
        $stmtUpdate->execute([$new_status, $order_id, $user_id, $tenant_id]);

        // Dodanie wpisu do audytu (śledzenie akcji)
        $stmtAudit = $pdo->prepare("INSERT INTO sh_order_audit (order_id, user_id, action, new_value) VALUES (?, ?, 'status_change', ?)");
        $stmtAudit->execute([$order_id, $user_id, $new_status]);

        // Jeśli kierowca zamyka zamówienie, ustawiamy go z powrotem na 'available'
        if ($new_status === 'completed') {
            $pdo->prepare("UPDATE sh_drivers SET status = 'available' WHERE user_id = ?")->execute([$user_id]);
        } else if ($new_status === 'in_delivery') {
            $pdo->prepare("UPDATE sh_drivers SET status = 'busy' WHERE user_id = ?")->execute([$user_id]);
        }

        $pdo->commit();
        sendResponse('success', ['message' => 'Status zaktualizowany!']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', null, 'Błąd bazy danych: ' . $e->getMessage());
    }
}

sendResponse('error', null, 'Nieznana akcja API.');
?>