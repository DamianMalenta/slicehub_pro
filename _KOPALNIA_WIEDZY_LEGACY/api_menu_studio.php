<?php
// 🛡️ SLICEHUB MENU STUDIO API v8.7 - CZYSTA INTEGRACJA
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

// 🔒 1. Odpalamy Twoją funkcję z db_connect.php (Tylko szefostwo ma dostęp)
if (function_exists('require_role')) {
    require_role(['admin', 'manager', 'owner']);
}

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// 🛡️ 2. Zabezpieczenie przed błędem "Cannot redeclare"
if (!function_exists('sendResponse')) {
    function sendResponse($status, $payload = [], $error = null) {
        echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $error]);
        exit;
    }
}

try {
    if ($action === 'get_menu_tree') {
        $cats = $pdo->prepare("SELECT * FROM sh_categories WHERE tenant_id = ? ORDER BY display_order ASC");
        $cats->execute([$tenant_id]);
        $items = $pdo->prepare("SELECT * FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0 ORDER BY display_order ASC, id ASC");
        $items->execute([$tenant_id]);
        sendResponse('success', ['categories' => $cats->fetchAll(), 'items' => $items->fetchAll()]);
    }

    if ($action === 'get_item_details') {
        $id = $input['item_id'];
        $variants = $pdo->prepare("SELECT * FROM sh_item_variants WHERE item_id = ? ORDER BY display_order ASC, id ASC");
        $variants->execute([$id]);
        sendResponse('success', ['variants' => $variants->fetchAll()]);
    }

    if ($action === 'update_item_full') {
        $pdo->beginTransaction();
        $sql = "UPDATE sh_menu_items SET 
                name = ?, ascii_key = ?, is_active = ?, 
                vat_id = ?, printer_group = ?, plu_code = ?, 
                prep_time = ?, unit = ?, description = ?,
                stock_count = ?, badge_type = ?, is_secret = ?
                WHERE id = ? AND tenant_id = ?";
        
        $pdo->prepare($sql)->execute([
            $input['name'], $input['ascii'], $input['is_active'],
            $input['vat_id'], $input['printer_group'], $input['plu_code'],
            $input['prep_time'], $input['unit'], $input['description'],
            $input['stock_count'], $input['badge_type'], $input['is_secret'],
            $input['item_id'], $tenant_id
        ]);

        $vids = array_filter(array_column($input['variants'] ?? []), fn($id) => $id !== 'new');
        if(!empty($vids)) { 
            $pdo->prepare("DELETE FROM sh_item_variants WHERE item_id=? AND id NOT IN (".implode(',',$vids).")")->execute([$input['item_id']]); 
        } else { 
            $pdo->prepare("DELETE FROM sh_item_variants WHERE item_id=?")->execute([$input['item_id']]); 
        }

        if (!empty($input['variants'])) {
            foreach($input['variants'] as $v) {
                if($v['id'] === 'new') { 
                    $pdo->prepare("INSERT INTO sh_item_variants (item_id,name,ascii_key,price,is_active) VALUES (?,?,'VAR_AUTO',?,?)")->execute([$input['item_id'],$v['name'],$v['price'],$v['is_active']]); 
                } else { 
                    $pdo->prepare("UPDATE sh_item_variants SET name=?, price=?, is_active=? WHERE id=?")->execute([$v['name'],$v['price'],$v['is_active'],$v['id']]); 
                }
            }
        }
        $pdo->commit(); sendResponse('success');
    }

    if ($action === 'bulk_update') {
        $item_ids = $input['item_ids'] ?? [];
        if (empty($item_ids)) sendResponse('error', null, 'Brak dań.');
        
        $pdo->beginTransaction();
        $updates = []; $params = [];
        $fields = ['vat_id','printer_group','is_active','badge_type','is_secret'];
        foreach($fields as $f) { if(isset($input[$f]) && $input[$f]!=='') { $updates[] = "$f = ?"; $params[] = $input[$f]; } }
        
        if (!empty($updates)) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $sql = "UPDATE sh_menu_items SET " . implode(', ', $updates) . " WHERE tenant_id = ? AND id IN ($placeholders)";
            $pdo->prepare($sql)->execute(array_merge([$tenant_id], $params, $item_ids));
        }

        if (!empty($input['price_action']) && isset($input['price_value']) && $input['price_value'] !== '') {
            $val = (float)$input['price_value'];
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $math = $input['price_action'] === 'add' ? "price + ?" : ($input['price_action'] === 'sub' ? "GREATEST(0, price - ?)" : "?");
            $pdo->prepare("UPDATE sh_item_variants SET price = $math WHERE item_id IN ($placeholders)")->execute(array_merge([$val], $item_ids));
        }
        $pdo->commit(); sendResponse('success');
    }

    if ($action === 'get_modifier_groups') {
        $g = $pdo->prepare("SELECT * FROM sh_modifier_groups WHERE tenant_id = ? ORDER BY id DESC");
        $g->execute([$tenant_id]);
        sendResponse('success', ['groups' => $g->fetchAll()]);
    }

    if ($action === 'add_category') { $pdo->prepare("INSERT INTO sh_categories (tenant_id,name,ascii_key) VALUES (?,?,'CAT_AUTO')")->execute([$tenant_id,$input['name']]); sendResponse('success'); }
    if ($action === 'add_item') { $pdo->prepare("INSERT INTO sh_menu_items (tenant_id,category_id,name,type) VALUES (?,?,?,'standard')")->execute([$tenant_id,$input['category_id'],$input['name']]); sendResponse('success',['id'=>$pdo->lastInsertId()]); }

} catch (Throwable $e) { 
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); 
    sendResponse('error', null, $e->getMessage() . ' w linii ' . $e->getLine()); 
}
?>