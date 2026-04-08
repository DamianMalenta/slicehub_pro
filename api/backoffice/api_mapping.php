<?php
// 🛡️ SLICEHUB MAPPING API v3.2 (Ultimate) - CZYSTY JSON I BEZPIECZEŃSTWO
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

// 🔒 1. Odpalamy funkcję autoryzacji
if (function_exists('require_role')) {
    require_role(['admin', 'manager', 'owner']);
}

// 🛡️ 2. Inicjalizacja krytycznych zmiennych sesyjnych i wejścia (Tarcza)
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

// 🛡️ 3. Zabezpieczenie funkcji odpowiedzi przed duplikacją
if (!function_exists('sendResponse')) {
    function sendResponse($status, $payload = [], $errorMsg = null) {
        echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
        exit;
    }
}

try {
    // 🟢 1. POBIERANIE DANYCH MAPOWANIA I PRODUKTÓW
    if ($action === 'get_mapping_data') {
        $products = $pdo->prepare("SELECT id, name FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
        $products->execute([$tenant_id]);
        
        $mappings = $pdo->prepare("
            SELECT m.id, m.external_name, p.name AS internal_name 
            FROM sh_product_mapping m
            JOIN sh_products p ON m.product_id = p.id
            WHERE m.tenant_id = ? ORDER BY m.id DESC
        ");
        $mappings->execute([$tenant_id]);
        
        sendResponse('success', [
            'products' => $products->fetchAll(), 
            'mappings' => $mappings->fetchAll()
        ]);
    }

    // 🟢 2. DODAWANIE NOWEGO ZMAPOWANIA (Zabezpieczone UTF-8 przez PDO)
    if ($action === 'add_mapping') {
        $external_name = trim($input['external_name'] ?? '');
        $product_id = (int)($input['internal_product_id'] ?? 0);

        if (empty($external_name) || $product_id === 0) {
            sendResponse('error', null, 'Brakuje danych do mapowania.');
        }

        $stmt = $pdo->prepare("INSERT INTO sh_product_mapping (tenant_id, external_name, product_id) VALUES (?, ?, ?)");
        $stmt->execute([$tenant_id, $external_name, $product_id]);
        
        sendResponse('success', ['inserted_id' => $pdo->lastInsertId()]);
    }

    // 🟢 3. USUWANIE ZMAPOWANIA
    if ($action === 'delete_mapping') {
        $mapping_id = (int)($input['mapping_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM sh_product_mapping WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$mapping_id, $tenant_id]);
        
        sendResponse('success', ['deleted_id' => $mapping_id]);
    }

    if (!empty($action)) {
        sendResponse('error', null, "Nieznana akcja: [" . htmlspecialchars($action) . "]");
    }

} catch (Throwable $e) {
    sendResponse('error', null, 'Błąd bazy danych: ' . $e->getMessage() . ' w linii ' . $e->getLine());
}
?>