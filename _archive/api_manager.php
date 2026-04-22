<?php
// 🛡️ SLICEHUB MANAGER API v3.2 (Ultimate) - ZMODULARYZOWANY
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

// 🔒 1. Dostęp tylko dla kadry zarządzającej
if (function_exists('require_role')) {
    require_role(['manager', 'admin', 'owner']);
}

// 🛡️ 2. Zasilanie zmiennych z Sesji i JSON
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$user_id = $_SESSION['user_id'] ?? 1;
$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

// 🛡️ 3. Standard odpowiedzi API
if (!function_exists('sendResponse')) {
    function sendResponse($status, $payload = [], $errorMsg = null) {
        echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
        exit;
    }
}

try {
    // 🟢 1. POBIERANIE ZESPOŁU
    if ($action === 'get_team') {
        $stmt = $pdo->prepare("SELECT id, username, first_name, last_name, position, role FROM sh_users WHERE tenant_id = ? AND is_active = 1 AND role != 'owner'");
        $stmt->execute([$tenant_id]);
        sendResponse('success', ['team' => $stmt->fetchAll()]);
    }

    // 🟢 2. ZAPISYWANIE WNIOSKÓW FINANSOWYCH (Premie/Zaliczki/Kary)
    if ($action === 'submit_finance') {
        $target_id = (int)($input['target_id'] ?? 0);
        $type = trim($input['type'] ?? ''); // ASCII techniczne (np. "bonus", "advance")
        $amount = (float)($input['amount'] ?? 0);
        $desc = trim($input['desc'] ?? ''); // UTF-8 opis od menedżera
        $is_paid = (int)($input['is_paid'] ?? 0);

        if ($target_id === 0 || $amount <= 0 || empty($type)) {
            sendResponse('error', null, 'Brakujące lub nieprawidłowe dane wniosku finansowego.');
        }

        $stmt = $pdo->prepare("INSERT INTO sh_finance_requests (tenant_id, target_user_id, created_by_id, type, amount, description, is_paid_cash) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $target_id, $user_id, $type, $amount, $desc, $is_paid]);
        sendResponse('success', ['message' => 'Wniosek został zapisany pomyślnie.']);
    }

    if (!empty($action)) {
        sendResponse('error', null, "Nieznana akcja API: [" . htmlspecialchars($action) . "]");
    }

} catch (Throwable $e) {
    sendResponse('error', null, 'Błąd serwera: ' . $e->getMessage() . ' w linii ' . $e->getLine());
}
?>