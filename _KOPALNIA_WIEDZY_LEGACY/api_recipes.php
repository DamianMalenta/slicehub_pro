<?php
// 🛡️ SLICEHUB RECIPES API - V3.1 CZYSTY JSON
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

require_role(['admin', 'manager', 'owner']);
$action = trim($action ?? '');

if ($action === 'get_recipes_init') {
    // Pobieramy produkty do wyboru
    $products = $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? ORDER BY name ASC");
    $products->execute([$tenant_id]);
    
    // Pobieramy pozycje z menu
    $menuItems = $pdo->prepare("SELECT id, name FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY name ASC");
    $menuItems->execute([$tenant_id]);

    // Pobieramy wszystkie zapisane receptury
    $recipes = $pdo->prepare("
        SELECT r.menu_item_id, r.product_id, r.quantity, r.waste_percent 
        FROM sh_recipes r
        JOIN sh_menu_items m ON r.menu_item_id = m.id
        WHERE m.tenant_id = ?
    ");
    $recipes->execute([$tenant_id]);

    sendResponse('success', [
        'products' => $products->fetchAll(),
        'menu_items' => $menuItems->fetchAll(),
        'recipes' => $recipes->fetchAll()
    ]);
}

if ($action === 'save_recipe') {
    $menu_item_id = (int)($input['menu_item_id'] ?? 0);
    $ingredients = $input['ingredients'] ?? [];

    if ($menu_item_id === 0) sendResponse('error', null, 'Nie wybrano dania.');

    try {
        $pdo->beginTransaction();

        // 1. Usuwamy stare składniki tego dania
        $stmtDelete = $pdo->prepare("DELETE FROM sh_recipes WHERE menu_item_id = ?");
        $stmtDelete->execute([$menu_item_id]);

        // 2. Wstawiamy nowe składniki z tablicy JSON
        if (!empty($ingredients)) {
            $stmtInsert = $pdo->prepare("INSERT INTO sh_recipes (menu_item_id, product_id, quantity, waste_percent) VALUES (?, ?, ?, ?)");
            foreach ($ingredients as $ing) {
                $prod_id = (int)$ing['product_id'];
                $qty = (float)$ing['quantity'];
                $waste = (float)($ing['waste_percent'] ?? 0);
                
                if ($prod_id > 0 && $qty > 0) {
                    $stmtInsert->execute([$menu_item_id, $prod_id, $qty, $waste]);
                }
            }
        }

        $pdo->commit();
        sendResponse('success', ['message' => 'Receptura zapisana.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', null, 'Błąd bazy: ' . $e->getMessage());
    }
}

sendResponse('error', null, "Nieznana akcja: [" . $action . "]");