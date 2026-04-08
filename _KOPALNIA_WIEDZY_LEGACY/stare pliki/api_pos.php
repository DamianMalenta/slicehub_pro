<?php
// 🛡️ SLICEHUB POS API v6.5 ULTIMATE - THE MONSTER ENGINE
require_once 'db_connect.php';

function sendResponse($status, $payload = [], $errorMsg = null) {
    echo json_encode(['status' => $status, 'payload' => $payload, 'error' => $errorMsg]);
    exit;
}

// 🚨 POPRAWKA 1: Pozwalamy też roli 'driver' na dostęp do POS
require_role(['waiter', 'admin', 'manager', 'owner', 'driver']);

$user_id = $_SESSION['user_id'] ?? 0;
$tenant_id = $_SESSION['tenant_id'] ?? 0;

// 🚨 POPRAWKA 2: Pobieranie akcji z inputu (TEGO BRAKOWAŁO!)
$action = $input['action'] ?? $_GET['action'] ?? '';

function generate_uuid() {
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}

// 🚀 AUTO-MIGRACJA BAZY DANYCH (Niezbędne dla K-Systemu, L-Kolejek i Połówek)
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN receipt_printed TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN kitchen_ticket_printed TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN edited_since_print TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN is_half TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_order_items ADD COLUMN is_half TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_order_items ADD COLUMN half_a_id INT DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_order_items ADD COLUMN half_b_id INT DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN course_id VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE sh_orders ADD COLUMN stop_number VARCHAR(10) DEFAULT NULL"); } catch (Exception $e) {}

if ($action === 'get_init_data') {
    $data = [
        'categories' => $pdo->prepare("SELECT id, name AS name_utf8 FROM sh_categories WHERE tenant_id = ? AND is_menu = 1 ORDER BY display_order ASC"),
        'items' => $pdo->prepare("SELECT id, category_id, name, price, type FROM sh_menu_items WHERE tenant_id = ? AND is_deleted = 0"),
        'tables' => $pdo->prepare("SELECT id, table_number FROM sh_tables WHERE tenant_id = ?"),
        'ingredients' => $pdo->prepare("SELECT id, name, unit FROM sh_products WHERE tenant_id = ? AND is_active = 1"),
        
        // 🚨 NAPRAWA ID KIEROWCY (u.id) ORAZ IMIENIA (NULLIF)
        'drivers' => $pdo->prepare("SELECT u.id, d.status, d.initial_cash, COALESCE(NULLIF(u.first_name, ''), u.username) AS first_name FROM sh_drivers d JOIN sh_users u ON d.user_id = u.id WHERE u.tenant_id = ?"),
        
        // 🚨 NAPRAWA IMIENIA KELNERA
        'waiters' => $pdo->prepare("SELECT id, COALESCE(NULLIF(first_name, ''), username) AS first_name FROM sh_users WHERE tenant_id = ? AND role = 'waiter' AND is_active = 1")
    ];
    foreach($data as $k => $stmt) { $stmt->execute([$tenant_id]); $data[$k] = $stmt->fetchAll(); }
    sendResponse('success', $data);
}

if ($action === 'get_orders') {
    // Zwraca zamówienia wraz z K-System i L-Kolejkami
    $stmt = $pdo->prepare("
        SELECT o.*, u.first_name as creator_name 
        FROM sh_orders o 
        LEFT JOIN sh_users u ON o.created_by = u.id 
        WHERE o.tenant_id = ? AND o.status NOT IN ('completed', 'cancelled') 
        ORDER BY COALESCE(o.promised_time, o.created_at) ASC
    ");
    $stmt->execute([$tenant_id]);
    sendResponse('success', ['orders' => $stmt->fetchAll()]);
}

if ($action === 'get_item_details') {
    $item_id = (int)($_GET['item_id'] ?? $input['item_id'] ?? 0);
    $half_b_id = (int)($_GET['half_b_id'] ?? $input['half_b_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT p.id as product_id, p.name as name_utf8 FROM sh_recipes r JOIN sh_products p ON r.product_id = p.id WHERE r.menu_item_id = ?");
    $stmt->execute([$item_id]); $ingA = $stmt->fetchAll(); foreach($ingA as &$i) $i['half'] = 'A';
    $ingredients = $ingA;
    if ($half_b_id > 0) { $stmt->execute([$half_b_id]); $ingB = $stmt->fetchAll(); foreach($ingB as &$i) $i['half'] = 'B'; $ingredients = array_merge($ingredients, $ingB); }
    sendResponse('success', ['ingredients' => $ingredients]);
}
if ($action === 'process_order') {
    $edit_id = (int)($input['edit_order_id'] ?? 0);
    $cart = $input['cart'] ?? [];
    $new_cart_json = json_encode($cart);
    $promised = $input['custom_datetime'] ? date('Y-m-d H:i:s', strtotime($input['custom_datetime'])) : date('Y-m-d H:i:s');
    $print_kitchen = (int)($input['print_kitchen'] ?? 0);
    
    $source = $input['source'] ?? 'local';
    
    // TWARDA REGUŁA: Zawsze pending na start, chyba że to kelner (new)
    $initial_status = $input['status'] ?? 'pending'; 
    if ($source === 'waiter' && $initial_status === 'new') { $print_kitchen = 0; }

    try {
        $pdo->beginTransaction();
        $warehouse_id = 2; 
        
        $stmtUnits = $pdo->prepare("SELECT id, unit FROM sh_products WHERE tenant_id = ?");
        $stmtUnits->execute([$tenant_id]);
        $productUnits = [];
        foreach($stmtUnits->fetchAll() as $row) { $productUnits[$row['id']] = strtolower(trim($row['unit'])); }
        $stmtRecipe = $pdo->prepare("SELECT product_id, quantity, waste_percent FROM sh_recipes WHERE menu_item_id = ?");

        if ($edit_id > 0) {
            // EDYCJA ZAMÓWIENIA (Wykrywanie Zmian na Kuchnię)
            $stmtOld = $pdo->prepare("SELECT cart_json, edited_since_print, kitchen_changes FROM sh_orders WHERE id = ? AND tenant_id = ?");
            $stmtOld->execute([$edit_id, $tenant_id]);
            $oldOrder = $stmtOld->fetch();
            
            $edited_flag = $oldOrder['edited_since_print'] ?? 0;
            $kitchen_changes = $oldOrder['kitchen_changes'] ?? '';
            
            if ($oldOrder && !empty($oldOrder['cart_json'])) {
                if ($oldOrder['cart_json'] !== $new_cart_json) {
                    $edited_flag = 1; // Wyzwala żółty alarm
                    $diff_arr = [];
                    $oldCart = json_decode($oldOrder['cart_json'], true);
                    $oldMap = []; foreach($oldCart as $c) { $oldMap[$c['cart_id']] = $c; }
                    $newMap = []; foreach($cart as $c) { $newMap[$c['cart_id']] = $c; }
                    
                    foreach($newMap as $cid => $c) {
                        if(!isset($oldMap[$cid])) { $diff_arr[] = "DODANO: " . $c['qty'] . "x " . $c['name']; } 
                        else {
                            if($oldMap[$cid]['qty'] != $c['qty']) { $diff_arr[] = "ZMIENIONO ILOŚĆ: " . $c['name'] . " (" . $oldMap[$cid]['qty'] . " -> " . $c['qty'] . ")"; }
                            if(($oldMap[$cid]['comment'] ?? '') != ($c['comment'] ?? '')) { $diff_arr[] = "ZMIENIONO UWAGI DO: " . $c['name']; }
                        }
                    }
                    foreach($oldMap as $cid => $oc) {
                        if(!isset($newMap[$cid])) { $diff_arr[] = "USUNIĘTO: " . $oc['qty'] . "x " . $oc['name']; }
                    }
                    $kitchen_changes = implode(" | ", $diff_arr);
                }

                // Zwracanie starego towaru (Logika magazynowa)
                $oldCart = json_decode($oldOrder['cart_json'], true);
                if (is_array($oldCart)) {
                    $stmtUpdateStockReturn = $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity + ? WHERE warehouse_id = ? AND product_id = ?");
                    $stmtLogReturn = $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'POS_EDIT_RETURN')");
                    foreach ($oldCart as $item) {
                        $qty_sold = (float)$item['qty']; $products_to_return = []; $removed_ids = $item['removed'] ?? [];
                        $calcRecipe = function($menu_id, $multiplier) use ($stmtRecipe, &$products_to_return, $removed_ids) {
                            if(!$menu_id) return; $stmtRecipe->execute([$menu_id]);
                            foreach ($stmtRecipe->fetchAll() as $ing) {
                                $pid = $ing['product_id']; if (in_array($pid, $removed_ids)) continue;
                                $needed = ($ing['quantity'] * (1 + ($ing['waste_percent'] / 100))) * $multiplier;
                                if (!isset($products_to_return[$pid])) $products_to_return[$pid] = 0; $products_to_return[$pid] += $needed;
                            }
                        };
                        if (!empty($item['is_half'])) { $calcRecipe($item['half_a'], 0.5 * $qty_sold); $calcRecipe($item['half_b'], 0.5 * $qty_sold); } 
                        else { $calcRecipe($item['id'], 1.0 * $qty_sold); }
                        if (!empty($item['added'])) {
                            foreach ($item['added'] as $added_pid) {
                                $unit = $productUnits[$added_pid] ?? 'szt'; $extra_qty = 1.0; 
                                if (in_array($unit, ['kg', 'litr', 'l'])) $extra_qty = 0.05;
                                if (!isset($products_to_return[$added_pid])) $products_to_return[$added_pid] = 0;
                                $products_to_return[$added_pid] += ($extra_qty * $qty_sold);
                            }
                        }
                        foreach ($products_to_return as $pid => $return_qty) {
                            $stmtUpdateStockReturn->execute([$return_qty, $warehouse_id, $pid]);
                            $stmtLogReturn->execute([$pid, $user_id, $return_qty]);
                        }
                    }
                }
            }
            $pdo->prepare("DELETE FROM sh_order_items WHERE order_id = ?")->execute([$edit_id]);
            
            if ($print_kitchen === 1 && $source === 'local') { $edited_flag = 0; $kitchen_changes = ''; }

            $stmt = $pdo->prepare("UPDATE sh_orders SET type=?, payment_method=?, payment_status=?, total_price=?, address=?, customer_phone=?, nip=?, cart_json=?, promised_time=?, edited_since_print=?, kitchen_changes=? WHERE id=? AND tenant_id=?");
            $stmt->execute([$input['order_type'], $input['payment_method'], $input['payment_status'], $input['total_price'], $input['address'], $input['customer_phone'], $input['nip'] ?? '', $new_cart_json, $promised, $edited_flag, $kitchen_changes, $edit_id, $tenant_id]);
            $order_id = $edit_id;
        } else {
            // NOWE ZAMÓWIENIE - CODZIENNY RESET NUMERACJI
            $uuid = generate_uuid();
            $stmtSeq = $pdo->prepare("SELECT COUNT(*) FROM sh_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE()");
            $stmtSeq->execute([$tenant_id]);
            $seq = $stmtSeq->fetchColumn() + 1;
            $order_number = 'ORD/' . date('Ymd') . '/' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("INSERT INTO sh_orders (tenant_id, uuid, order_number, source, type, status, payment_method, payment_status, total_price, address, customer_phone, nip, cart_json, promised_time, kitchen_ticket_printed, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$tenant_id, $uuid, $order_number, $source, $input['order_type'], $initial_status, $input['payment_method'], $input['payment_status'], $input['total_price'], $input['address'], $input['customer_phone'], $input['nip'] ?? '', $new_cart_json, $promised, $print_kitchen, $user_id]);
            $order_id = $pdo->lastInsertId();
        }

        // POBIERANIE NOWEGO TOWARU Z MAGAZYNU
        $stmtUpdateStock = $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity - ? WHERE warehouse_id = ? AND product_id = ?");
        $stmtInsertStock = $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmtLog = $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'POS_SALE')");
        foreach ($cart as $item) {
            $qty_sold = (float)$item['qty'];
            $stmtItem = $pdo->prepare("INSERT INTO sh_order_items (order_id, menu_item_id, snapshot_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            $stmtItem->execute([$order_id, $item['id'] ?? null, $item['name'], $qty_sold, $item['price']]);
            $products_to_deduct = []; $removed_ids = $item['removed'] ?? [];
            $calcRecipe = function($menu_id, $multiplier) use ($stmtRecipe, &$products_to_deduct, $removed_ids) {
                if(!$menu_id) return; $stmtRecipe->execute([$menu_id]);
                foreach ($stmtRecipe->fetchAll() as $ing) {
                    $pid = $ing['product_id']; if (in_array($pid, $removed_ids)) continue;
                    $needed = ($ing['quantity'] * (1 + ($ing['waste_percent'] / 100))) * $multiplier;
                    if (!isset($products_to_deduct[$pid])) $products_to_deduct[$pid] = 0; $products_to_deduct[$pid] += $needed;
                }
            };
            if (!empty($item['is_half'])) { $calcRecipe($item['half_a'], 0.5 * $qty_sold); $calcRecipe($item['half_b'], 0.5 * $qty_sold); } else { $calcRecipe($item['id'], 1.0 * $qty_sold); }
            if (!empty($item['added'])) {
                foreach ($item['added'] as $added_pid) {
                    $unit = $productUnits[$added_pid] ?? 'szt'; $extra_qty = 1.0; 
                    if (in_array($unit, ['kg', 'litr', 'l'])) $extra_qty = 0.05;
                    if (!isset($products_to_deduct[$added_pid])) $products_to_deduct[$added_pid] = 0; $products_to_deduct[$added_pid] += ($extra_qty * $qty_sold);
                }
            }
            foreach ($products_to_deduct as $pid => $final_qty) {
                $stmtUpdateStock->execute([$final_qty, $warehouse_id, $pid]);
                if ($stmtUpdateStock->rowCount() === 0) { $stmtInsertStock->execute([$warehouse_id, $pid, -$final_qty]); }
                $stmtLog->execute([$pid, $user_id, -$final_qty]);
            }
        }
        
        if (($input['print_receipt'] ?? 0) == 1) {
            $pdo->prepare("UPDATE sh_orders SET receipt_printed=1 WHERE id=?")->execute([$order_id]);
        }

        $pdo->commit(); sendResponse('success', ['order_id' => $order_id]);
    } catch (Exception $e) { $pdo->rollBack(); sendResponse('error', null, $e->getMessage()); }
}
if ($action === 'accept_order') {
    $parsed_time = date('Y-m-d H:i:s', strtotime($input['custom_time']));
    $pdo->prepare("UPDATE sh_orders SET status='pending', promised_time=?, kitchen_ticket_printed=1 WHERE id=? AND tenant_id=?")->execute([$parsed_time, $input['order_id'], $tenant_id]);
    sendResponse('success');
}

if ($action === 'update_status') {
    $pdo->prepare("UPDATE sh_orders SET status=? WHERE id=? AND tenant_id=?")->execute([$input['status'], $input['order_id'], $tenant_id]);
    sendResponse('success');
}

if ($action === 'print_kitchen') {
    $pdo->prepare("UPDATE sh_orders SET kitchen_ticket_printed=1, edited_since_print=0, kitchen_changes='' WHERE id=?")->execute([$input['order_id']]);
    sendResponse('success');
}

if ($action === 'print_receipt') {
    $method = $input['payment_method'] ?? 'unpaid';
    $pdo->prepare("UPDATE sh_orders SET receipt_printed=1, payment_method=? WHERE id=?")->execute([$method, $input['order_id']]);
    sendResponse('success');
}

if ($action === 'settle_and_close') {
    $print = (int)($input['print_receipt'] ?? 0);
    $method = $input['payment_method'] ?? '';
    $order_id = $input['order_id'] ?? 0;

    $stmtCheck = $pdo->prepare("SELECT receipt_printed FROM sh_orders WHERE id = ?");
    $stmtCheck->execute([$order_id]);
    $already_printed = $stmtCheck->fetchColumn();

    if (($method === 'card' || $method === 'online') && $print === 0 && $already_printed == 0) {
        sendResponse('error', null, 'Dla karty lub online wydruk paragonu jest obowiązkowy!');
    }

    $sql = "UPDATE sh_orders SET payment_status='paid', payment_method=?, status='completed'";
    if($print === 1) $sql .= ", receipt_printed=1";
    $sql .= " WHERE id=?";
    $pdo->prepare($sql)->execute([$method, $order_id]);
    sendResponse('success');
}

if ($action === 'cancel_order') {
    $order_id = (int)($input['order_id'] ?? 0);
    $return_stock = (int)($input['return_stock'] ?? 0);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE sh_orders SET status='cancelled' WHERE id=?")->execute([$order_id]);
        if ($return_stock === 1) {
            $stmtOld = $pdo->prepare("SELECT cart_json FROM sh_orders WHERE id = ? AND tenant_id = ?");
            $stmtOld->execute([$order_id, $tenant_id]);
            $oldOrder = $stmtOld->fetch();
            if ($oldOrder && !empty($oldOrder['cart_json'])) {
                $oldCart = json_decode($oldOrder['cart_json'], true);
                $stmtUpdateStockReturn = $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity + ? WHERE warehouse_id = 2 AND product_id = ?");
                $stmtLogReturn = $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'POS_CANCEL_RETURN')");
                $stmtRecipe = $pdo->prepare("SELECT product_id, quantity, waste_percent FROM sh_recipes WHERE menu_item_id = ?");
                $stmtUnits = $pdo->prepare("SELECT id, unit FROM sh_products WHERE tenant_id = ?");
                $stmtUnits->execute([$tenant_id]);
                $productUnits = [];
                foreach($stmtUnits->fetchAll() as $row) { $productUnits[$row['id']] = strtolower(trim($row['unit'])); }
                foreach ($oldCart as $item) {
                    $qty_sold = (float)$item['qty']; $products_to_return = []; $removed_ids = $item['removed'] ?? [];
                    $calcRecipe = function($menu_id, $multiplier) use ($stmtRecipe, &$products_to_return, $removed_ids) {
                        if(!$menu_id) return; $stmtRecipe->execute([$menu_id]);
                        foreach ($stmtRecipe->fetchAll() as $ing) {
                            $pid = $ing['product_id']; if (in_array($pid, $removed_ids)) continue;
                            $needed = ($ing['quantity'] * (1 + ($ing['waste_percent'] / 100))) * $multiplier;
                            if (!isset($products_to_return[$pid])) $products_to_return[$pid] = 0; $products_to_return[$pid] += $needed;
                        }
                    };
                    if (!empty($item['is_half'])) { $calcRecipe($item['half_a'], 0.5 * $qty_sold); $calcRecipe($item['half_b'], 0.5 * $qty_sold); } 
                    else { $calcRecipe($item['id'], 1.0 * $qty_sold); }
                    if (!empty($item['added'])) {
                        foreach ($item['added'] as $added_pid) {
                            $unit = $productUnits[$added_pid] ?? 'szt'; $extra_qty = 1.0; 
                            if (in_array($unit, ['kg', 'litr', 'l'])) $extra_qty = 0.05;
                            if (!isset($products_to_return[$added_pid])) $products_to_return[$added_pid] = 0; $products_to_return[$added_pid] += ($extra_qty * $qty_sold);
                        }
                    }
                    foreach ($products_to_return as $pid => $return_qty) {
                        $stmtUpdateStockReturn->execute([$return_qty, $pid]);
                        $stmtLogReturn->execute([$pid, $user_id, $return_qty]); 
                    }
                }
            }
        }
        $pdo->commit(); sendResponse('success');
    } catch (Exception $e) { $pdo->rollBack(); sendResponse('error', null, $e->getMessage()); }
}

// 🚀 ZAAWANSOWANA WYSYŁKA W TRASĘ (SYSTEM K & L)
if ($action === 'assign_route') {
    $driver_id = (int)($input['driver_id'] ?? 0);
    $order_ids = $input['order_ids'] ?? [];
    
    if (!$driver_id || empty($order_ids)) {
        sendResponse('error', null, 'Wybierz kierowcę i zamówienia.');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Obliczamy nowy unikalny numer kursu dla dnia dzisiejszego (K1, K2...)
        $stmtK = $pdo->prepare("SELECT COUNT(DISTINCT course_id) FROM sh_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND course_id IS NOT NULL");
        $stmtK->execute([$tenant_id]);
        $next_k = $stmtK->fetchColumn() + 1;
        $course_id = 'K' . $next_k;
        
        $stmtUpdate = $pdo->prepare("UPDATE sh_orders SET status='in_delivery', driver_id=?, course_id=?, stop_number=? WHERE id=? AND tenant_id=?");
        
        // Przypisanie numeracji kolejności wyjazdu (L1, L2...) do konkretnych zamówień
        $l_num = 1;
        foreach ($order_ids as $oid) {
            $stmtUpdate->execute([$driver_id, $course_id, 'L' . $l_num, $oid, $tenant_id]);
            $l_num++;
        }
        
        $pdo->commit();
        sendResponse('success', ['course_id' => $course_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse('error', null, $e->getMessage());
    }
}

if ($action === 'panic_mode') {
    $pdo->prepare("UPDATE sh_orders SET promised_time = DATE_ADD(COALESCE(promised_time, created_at), INTERVAL 20 MINUTE) WHERE status IN ('pending', 'ready') AND tenant_id = ?")->execute([$tenant_id]);
    sendResponse('success', ['message' => 'Wydłużono czasy o 20 minut!']);
}

sendResponse('error', null, 'Brak akcji.');
?>