<?php
// 🛡️ SLICEHUB INVENTORY API - V3.1 ZGODNY Z BAZĄ V6
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

require_role(['admin', 'manager', 'owner']);
$action = trim($action ?? '');

// 🟢 1. INICJALIZACJA DANYCH DLA PANELU PZ (Ze Słownikiem Mapowania)
if ($action === 'get_pz_init') {
    $warehouses = $pdo->prepare("SELECT id, name FROM sh_warehouses WHERE tenant_id = ?");
    $warehouses->execute([$tenant_id]);

    $products = $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
    $products->execute([$tenant_id]);

    // Pobranie słownika mapowania dla tego tenanta
    $mappings = $pdo->prepare("SELECT external_name, product_id FROM sh_product_mapping WHERE tenant_id = ?");
    $mappings->execute([$tenant_id]);
    
    sendResponse('success', [
        'warehouses' => $warehouses->fetchAll(), 
        'products' => $products->fetchAll(),
        'mappings' => $mappings->fetchAll() // <--- Wysyłamy mapy do frontendu
    ]);
}

// 🟢 2. ZAPIS DOKUMENTU PZ
if ($action === 'save_pz') {
    $doc_num = trim($input['document_number'] ?? '');
    $contractor = trim($input['contractor_name'] ?? '');
    $wh_id = (int)($input['warehouse_id'] ?? 0);
    $items = $input['items'] ?? [];

    if (empty($doc_num) || $wh_id === 0 || empty($items)) {
        sendResponse('error', null, 'Brakuje danych PZ.');
    }

    try {
        $pdo->beginTransaction();
        
        // 1. Nagłówek dokumentu (Ta tabela ma tenant_id)
        $stmtDoc = $pdo->prepare("INSERT INTO sh_inventory_docs (tenant_id, warehouse_id, doc_number, doc_type, supplier_name, created_by) VALUES (?, ?, ?, 'PZ', ?, ?)");
        $stmtDoc->execute([$tenant_id, $wh_id, $doc_num, $contractor, $user_id]);
        $doc_id = $pdo->lastInsertId();

        foreach ($items as $item) {
            $p_id = (int)$item['product_id'];
            $qty = (float)$item['quantity'];

            if ($p_id > 0 && $qty > 0) {
                // 2. Pozycja dokumentu
                $stmtItem = $pdo->prepare("INSERT INTO sh_inventory_doc_items (doc_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmtItem->execute([$doc_id, $p_id, $qty]);

                // 3. Stan magazynowy (Tabela sh_stock_levels nie ma tenant_id w v6)
                $stmtStock = $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) 
                                            VALUES (?, ?, ?) 
                                            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
                $stmtStock->execute([$wh_id, $p_id, $qty]);

                // 4. Logowanie (Tabela sh_inventory_logs nie ma tenant_id w v6)
                $stmtLog = $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'PZ')");
                $stmtLog->execute([$p_id, $user_id, $qty]);
            }
        }
        $pdo->commit();
        sendResponse('success', ['message' => 'PZ Zatwierdzone.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', null, 'Błąd: ' . $e->getMessage());
    }
}

// 🟢 3. POBIERANIE MATRYCY STANÓW MAGAZYNOWYCH (DO TESTÓW DLA settings_magazyn.html)
if ($action === 'get_stock_matrix') {
    $stmtProd = $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
    $stmtProd->execute([$tenant_id]);
    
    $stmtWh = $pdo->prepare("SELECT id, name FROM sh_warehouses WHERE tenant_id = ? ORDER BY id ASC");
    $stmtWh->execute([$tenant_id]);
    
    $stmtStock = $pdo->query("SELECT warehouse_id, product_id, quantity FROM sh_stock_levels");
    
    sendResponse('success', [
        'products' => $stmtProd->fetchAll(),
        'warehouses' => $stmtWh->fetchAll(),
        'stocks' => $stmtStock->fetchAll()
    ]);
}

// 🟢 4. ZABEZPIECZENIE NA KONIEC
sendResponse('error', null, "Nieznana akcja: [" . htmlspecialchars($action) . "]");
?>