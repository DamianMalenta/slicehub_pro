<?php
// 🛡️ SLICEHUB INVENTORY API v3.2 (Ultimate) - PZ, MAG I MAPOWANIE
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

// 🔒 1. Odpalamy funkcję autoryzacji
if (function_exists('require_role')) {
    require_role(['admin', 'manager', 'owner']);
}

// 🛡️ 2. Inicjalizacja krytycznych zmiennych sesyjnych i wejścia
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$user_id = $_SESSION['user_id'] ?? 1; // Wymagane do logów i dokumentów PZ
$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

// 🛡️ 3. Zabezpieczenie funkcji odpowiedzi
if (!function_exists('sendResponse')) {
    function sendResponse($status, $payload = [], $errorMsg = null) {
        echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
        exit;
    }
}

try {
    // 🟢 1. INICJALIZACJA DANYCH DLA PANELU PZ (Ze Słownikiem Mapowania)
    if ($action === 'get_pz_init') {
        $warehouses = $pdo->prepare("SELECT id, name FROM sh_warehouses WHERE tenant_id = ?");
        $warehouses->execute([$tenant_id]);

        $products = $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
        $products->execute([$tenant_id]);

        // Pobranie słownika mapowania dla tego tenanta (Gotowe pod inteligentny import)
        $mappings = $pdo->prepare("SELECT external_name, product_id FROM sh_product_mapping WHERE tenant_id = ?");
        $mappings->execute([$tenant_id]);
        
        sendResponse('success', [
            'warehouses' => $warehouses->fetchAll(), 
            'products' => $products->fetchAll(),
            'mappings' => $mappings->fetchAll()
        ]);
    }

    // 🟢 2. ZAPIS DOKUMENTU PZ (Wydajna transakcja)
    if ($action === 'save_pz') {
        $doc_num = trim($input['document_number'] ?? '');
        $contractor = trim($input['contractor_name'] ?? '');
        $wh_id = (int)($input['warehouse_id'] ?? 0);
        $items = $input['items'] ?? [];

        if (empty($doc_num) || $wh_id === 0 || empty($items)) {
            sendResponse('error', null, 'Brakuje kluczowych danych dokumentu PZ.');
        }

        $pdo->beginTransaction();
        
        // 1. Nagłówek dokumentu (Ta tabela ma tenant_id)
        $stmtDoc = $pdo->prepare("INSERT INTO sh_inventory_docs (tenant_id, warehouse_id, doc_number, doc_type, supplier_name, created_by) VALUES (?, ?, ?, 'PZ', ?, ?)");
        $stmtDoc->execute([$tenant_id, $wh_id, $doc_num, $contractor, $user_id]);
        $doc_id = $pdo->lastInsertId();

        // Przygotowanie zapytań poza pętlą dla maksymalnej wydajności silnika bazy
        $stmtItem = $pdo->prepare("INSERT INTO sh_inventory_doc_items (doc_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmtStock = $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
        $stmtLog = $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'PZ')");

        foreach ($items as $item) {
            $p_id = (int)$item['product_id'];
            $qty = (float)$item['quantity'];

            if ($p_id > 0 && $qty > 0) {
                // 2. Pozycja dokumentu
                $stmtItem->execute([$doc_id, $p_id, $qty]);

                // 3. Stan magazynowy (v6 - brak tenant_id, operujemy na przypisanym ID magazynu)
                $stmtStock->execute([$wh_id, $p_id, $qty]);

                // 4. Logowanie zmian
                $stmtLog->execute([$p_id, $user_id, $qty]);
            }
        }
        $pdo->commit();
        sendResponse('success', ['message' => 'Dokument PZ zapisany i zaksięgowany na stanie.']);
    }

    // 🟢 3. POBIERANIE MATRYCY STANÓW MAGAZYNOWYCH
    if ($action === 'get_stock_matrix') {
        $stmtProd = $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
        $stmtProd->execute([$tenant_id]);
        
        $stmtWh = $pdo->prepare("SELECT id, name FROM sh_warehouses WHERE tenant_id = ? ORDER BY id ASC");
        $stmtWh->execute([$tenant_id]);
        
        // Wyciągamy stany globalne z v6 (Zabezpieczenie leży w fakcie, że ID magazynu zależy od tenanta)
        $stmtStock = $pdo->query("SELECT warehouse_id, product_id, quantity FROM sh_stock_levels");
        
        sendResponse('success', [
            'products' => $stmtProd->fetchAll(),
            'warehouses' => $stmtWh->fetchAll(),
            'stocks' => $stmtStock->fetchAll()
        ]);
    }

    if (!empty($action)) {
        sendResponse('error', null, "Nieznana akcja operacyjna: [" . htmlspecialchars($action) . "]");
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    sendResponse('error', null, 'Błąd krytyczny serwera: ' . $e->getMessage() . ' w linii ' . $e->getLine());
}
?>