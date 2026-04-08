<?php
// 🛡️ SLICEHUB MENU STUDIO API v6.5 ULTIMATE - INTEGRATED
require_once 'db_connect.php';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

if ($action === 'get_menu_tree') {
    try {
        $stmtCats = $pdo->prepare("SELECT * FROM sh_categories WHERE tenant_id = ? ORDER BY display_order ASC");
        $stmtCats->execute([$tenant_id]);
        $stmtItems = $pdo->prepare("SELECT * FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $stmtItems->execute([$tenant_id]);
        sendResponse('success', ['categories' => $stmtCats->fetchAll(PDO::FETCH_ASSOC), 'items' => $stmtItems->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { sendResponse('error', null, $e->getMessage()); }
}

// 📦 NOWOŚĆ: Pobieranie kompletu danych o produkcie
if ($action === 'get_item_details') {
    $id = $input['item_id'] ?? null;
    try {
        // Warianty
        $v = $pdo->prepare("SELECT * FROM sh_item_variants WHERE item_id = ? ORDER BY display_order ASC");
        $v->execute([$id]);
        // Grupy modyfikatorów
        $m = $pdo->prepare("SELECT mg.* FROM sh_modifier_groups mg JOIN sh_item_modifiers im ON mg.id = im.group_id WHERE im.item_id = ?");
        $m->execute([$id]);
        sendResponse('success', ['variants' => $v->fetchAll(PDO::FETCH_ASSOC), 'modifiers' => $m->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { sendResponse('error', null, $e->getMessage()); }
}

if ($action === 'update_item_full') {
    $id = $input['item_id'];
    try {
        $pdo->beginTransaction();
        // 1. Główne dane
        $pdo->prepare("UPDATE sh_menu_items SET name = ?, ascii_key = ?, is_active = ? WHERE id = ?")
            ->execute([$input['name'], $input['ascii'], $input['is_active'], $id]);

        // 2. Warianty - Obsługa Kosza
        $sent_ids = array_filter(array_column($input['variants'], 'id'), fn($vid) => $vid !== 'new');
        if (!empty($sent_ids)) {
            $pdo->prepare("DELETE FROM sh_item_variants WHERE item_id = ? AND id NOT IN (".implode(',', $sent_ids).")")->execute([$id]);
        } else {
            $pdo->prepare("DELETE FROM sh_item_variants WHERE item_id = ?")->execute([$id]);
        }

        // 3. Warianty - Update/Insert
        $stUpd = $pdo->prepare("UPDATE sh_item_variants SET name=?, ascii_key=?, price=?, is_active=? WHERE id=?");
        $stIns = $pdo->prepare("INSERT INTO sh_item_variants (item_id, name, ascii_key, price, is_active) VALUES (?,?,?,?,?)");
        foreach ($input['variants'] as $v) {
            if ($v['id'] === 'new') {
                $stIns->execute([$id, $v['name'], $v['ascii'], $v['price'], $v['is_active']]);
            } else {
                $stUpd->execute([$v['name'], $v['ascii'], $v['price'], $v['is_active'], $v['id']]);
            }
        }
        $pdo->commit();
        sendResponse('success');
    } catch (Exception $e) { $pdo->rollBack(); sendResponse('error', null, $e->getMessage()); }
}

// Fabryka nowości (Kategorie/Dania)
if ($action === 'add_category') {
    $pdo->prepare("INSERT INTO sh_categories (tenant_id, name, ascii_key) VALUES (?,?,?)")
        ->execute([$tenant_id, $input['name'], 'CAT_'.strtoupper(preg_replace('/[^a-z0-9]/i', '_', $input['name']))]);
    sendResponse('success');
}
if ($action === 'add_item') {
    $pdo->prepare("INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key) VALUES (?,?,?,?)")
        ->execute([$tenant_id, $input['category_id'], $input['name'], 'ITM_'.strtoupper(preg_replace('/[^a-z0-9]/i', '_', $input['name']))]);
    sendResponse('success', ['id' => $pdo->lastInsertId()]);
}
?>