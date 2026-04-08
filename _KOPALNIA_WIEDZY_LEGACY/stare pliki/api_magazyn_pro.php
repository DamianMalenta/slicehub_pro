<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Zaloguj się!']);
    exit;
}

$host = 'localhost'; $db = 'baza_slicehub'; $user = 'damian_admin'; $pass = 'Dammalq123123@';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tid = $_SESSION['tenant_id'] ?? 1; 
$uid = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// =========================================================================================
// 📂 TWOJE ORYGINALNE FUNKCJE (NIENARUSZONE)
// =========================================================================================

if ($action === 'get_products') {
    $stmt = $pdo->prepare("SELECT p.*, u.first_name as last_editor FROM sh_products p LEFT JOIN sh_users u ON p.last_edited_by = u.id WHERE p.tenant_id = ? ORDER BY p.name ASC");
    $stmt->execute([$tid]);
    echo json_encode(['success' => true, 'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'update_quantity') {
    $pid = $_POST['product_id']; $new = (float)$_POST['new_quantity'];
    $stmt = $pdo->prepare("SELECT quantity FROM sh_products WHERE id = ?"); $stmt->execute([$pid]);
    $old = (float)$stmt->fetch(PDO::FETCH_ASSOC)['quantity'];
    
    $pdo->prepare("UPDATE sh_products SET quantity = ?, last_edited_by = ? WHERE id = ?")->execute([$new, $uid, $pid]);
    $pdo->prepare("INSERT INTO sh_inventory_logs (tenant_id, product_id, user_id, action_type, quantity_changed) VALUES (?, ?, ?, 'set', ?)")->execute([$tid, $pid, $uid, $new - $old]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_product') {
    $name = $_POST['name']; $qty = (float)$_POST['quantity']; $low = (float)$_POST['low_stock'];
    $pdo->prepare("INSERT INTO sh_products (tenant_id, name, quantity, low_stock_threshold, last_edited_by) VALUES (?, ?, ?, ?, ?)")->execute([$tid, $name, $qty, $low, $uid]);
    echo json_encode(['success' => true]);
    exit;
}

// =========================================================================================
// 🚀 NOWA SEKCJA ERP: WIELOMAGAZYNOWOŚĆ I DOKUMENTY (PZ, WZ, MM)
// =========================================================================================

// 1. POBIERANIE DANYCH ERP (Magazyny, produkty i stany)
if ($action === 'get_erp_data') {
    try {
        $warehouses = $pdo->query("SELECT id, name_utf8, ascii_key, type FROM sh_warehouses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        // ZMIANA: p.external_key zamiast p.ascii_key (dopasowane do Twojej bazy)
        $stocks = $pdo->query("
            SELECT s.warehouse_id, s.product_id, s.quantity, p.name as name_utf8, p.external_key as ascii_key, p.category_ascii
            FROM sh_stock_levels s
            JOIN sh_products p ON s.product_id = p.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $products = $pdo->query("SELECT id, name, external_key FROM sh_products WHERE tenant_id = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'warehouses' => $warehouses, 'stocks' => $stocks, 'products' => $products]);
    } catch(Throwable $e) {
        // Wysyłamy bardzo dokładny błąd z bazy danych
        echo json_encode(['success' => false, 'error' => 'Błąd SQL: ' . $e->getMessage()]);
    }
    exit;
}

// 2. TWARDY DOKUMENT: PRZESUNIĘCIE MIĘDZYMAGAZYNOWE (MM)
if ($action === 'erp_transfer_mm') {
    $src_w = (int)$_POST['source_warehouse_id'];
    $tgt_w = (int)$_POST['target_warehouse_id'];
    $p_id = (int)$_POST['product_id'];
    $qty = (float)$_POST['quantity'];
    
    if ($src_w === $tgt_w || $qty <= 0) {
        echo json_encode(['success' => false, 'error' => 'Błędne dane przesunięcia.']); exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity - ? WHERE warehouse_id = ? AND product_id = ?")->execute([$qty, $src_w, $p_id]);
        $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?")->execute([$tgt_w, $p_id, $qty, $qty]);

        $doc_number = 'MM/' . date('Y/m/d/His');
        $pdo->prepare("INSERT INTO sh_documents (tenant_id, document_type, document_number, source_warehouse_id, target_warehouse_id, created_by) VALUES (?, 'MM', ?, ?, ?, ?)")->execute([$tid, $doc_number, $src_w, $tgt_w, $uid]);
        $doc_id = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO sh_document_items (document_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$doc_id, $p_id, $qty]);

        $pdo->commit();
        echo json_encode(['success' => true, 'doc_number' => $doc_number]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. ZATOWAROWANIE STARTOWE (Jednorazowe)
if ($action === 'erp_init_stock') {
    try {
        $pdo->exec("INSERT IGNORE INTO sh_stock_levels (warehouse_id, product_id, quantity) SELECT 1, id, quantity FROM sh_products WHERE quantity > 0");
        echo json_encode(['success' => true, 'msg' => 'Stany przeniesione bezpiecznie do Magazynu Głównego']);
    } catch(Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Błąd SQL: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================================
// 🚀 SEKCJA KASY POS: AUTOMATYCZNE ZDEJMOWANIE SKŁADNIKÓW (WZ)
// =========================================================================================

// =========================================================================================
// 🚀 SEKCJA KASY POS: AUTOMATYCZNE ZDEJMOWANIE SKŁADNIKÓW (WZ) - WERSJA Z WYKLUCZENIAMI
// =========================================================================================

if ($action === 'erp_pos_checkout') {
    $cart = json_decode($_POST['cart'], true);
    $order_id = $_POST['order_id'] ?? null;
    $warehouse_kuchnia_id = 2; // Magazyn Kuchnia
    
    if (empty($cart)) {
        echo json_encode(['success' => false, 'error' => 'Pusty koszyk.']); exit;
    }

    try {
        $pdo->beginTransaction();

        $ingredients_needed = [];
        
        foreach ($cart as $item) {
            $m_qty = (float)($item['qty'] ?? 1);
            // Pobieramy tablicę wykluczeń (np. ["Salami", "Pieczarki"])
            $removed = $item['removed'] ?? []; 
            
            // Obsługa zarówno standardowych dań, jak i połówek (jeśli POS tak je podzielił)
            $items_to_check = [];
            if (!empty($item['is_half'])) {
                if (!empty($item['half1_id'])) $items_to_check[] = ['id' => (int)$item['half1_id'], 'qty' => $m_qty * 0.5];
                if (!empty($item['half2_id'])) $items_to_check[] = ['id' => (int)$item['half2_id'], 'qty' => $m_qty * 0.5];
            } else {
                if (!empty($item['menu_item_id'])) $items_to_check[] = ['id' => (int)$item['menu_item_id'], 'qty' => $m_qty];
            }

            foreach ($items_to_check as $check_item) {
                // Wyciągamy recepturę POŁĄCZONĄ z nazwą produktu (UTF-8), by móc ją porównać z tym, co odklikał kelner
                $stmt = $pdo->prepare("
                    SELECT r.product_id, r.quantity, p.name 
                    FROM sh_recipes r 
                    JOIN sh_products p ON r.product_id = p.id 
                    WHERE r.menu_item_id = ?
                ");
                $stmt->execute([$check_item['id']]);
                $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($recipes as $r) {
                    $product_name = mb_strtolower(trim($r['name']), 'UTF-8');
                    $is_removed = false;
                    
                    // SKANER WYKLUCZEŃ: Patrzymy czy ten składnik jest na czarnej liście "BEZ"
                    foreach ($removed as $rem_name) {
                        if (mb_strtolower(trim($rem_name), 'UTF-8') === $product_name) {
                            $is_removed = true;
                            break;
                        }
                    }

                    // Jeśli kelner NIE wykluczył składnika, dodajemy go do pobrania
                    if (!$is_removed) {
                        $p_id = $r['product_id'];
                        $req_qty = $r['quantity'] * $check_item['qty'];
                        
                        if (!isset($ingredients_needed[$p_id])) {
                            $ingredients_needed[$p_id] = 0;
                        }
                        $ingredients_needed[$p_id] += $req_qty;
                    }
                }
            }
        }

        // Reszta dokumentu WZ bez zmian (generowanie twardego dowodu)
        if (empty($ingredients_needed)) {
            $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'Brak towarów do zdjęcia.']);
            exit;
        }

        $doc_number = 'WZ/' . date('Y/m/d/His');
        $stmt = $pdo->prepare("INSERT INTO sh_documents (tenant_id, document_type, document_number, source_warehouse_id, order_id, created_by) VALUES (?, 'WZ', ?, ?, ?, ?)");
        $stmt->execute([$tid, $doc_number, $warehouse_kuchnia_id, $order_id, $uid]);
        $doc_id = $pdo->lastInsertId();

        foreach ($ingredients_needed as $p_id => $total_qty) {
            $stmt = $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity - ? WHERE warehouse_id = ? AND product_id = ?");
            $stmt->execute([$total_qty, $warehouse_kuchnia_id, $p_id]);
            
            if ($stmt->rowCount() === 0) {
                 $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) VALUES (?, ?, -?)")->execute([$warehouse_kuchnia_id, $p_id, $total_qty]);
            }

            $pdo->prepare("INSERT INTO sh_document_items (document_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$doc_id, $p_id, $total_qty]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'doc_number' => $doc_number]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// =========================================================================================
// 🔄 BIEG WSTECZNY: ANULOWANIE WYDANIA (KOREKTA WZ)
// =========================================================================================

if ($action === 'erp_pos_void') {
    $order_id = (int)$_POST['order_id'];
    $warehouse_kuchnia_id = 2; // Magazyn Kuchnia

    try {
        $pdo->beginTransaction();

        // 1. Szukamy dokumentu WZ przypisanego do tego zamówienia
        $stmt = $pdo->prepare("SELECT id, document_number FROM sh_documents WHERE order_id = ? AND document_type = 'WZ' LIMIT 1");
        $stmt->execute([$order_id]);
        $old_doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$old_doc) {
            // Jeśli nie ma WZ, nie mamy czego korygować (np. zamówienie nie miało receptur)
            echo json_encode(['success' => true, 'msg' => 'Brak towarów do zwrotu.']);
            exit;
        }

        $old_doc_id = $old_doc['id'];

        // 2. Tworzymy dokument Korekty (KOR) - numeracja bazuje na starym WZ
        $kor_number = 'KOR/' . $old_doc['document_number'];
        $stmt = $pdo->prepare("INSERT INTO sh_documents (tenant_id, document_type, document_number, source_warehouse_id, order_id, created_by) VALUES (?, 'KOR', ?, ?, ?, ?)");
        $stmt->execute([$tid, $kor_number, $warehouse_kuchnia_id, $order_id, $uid]);
        $new_doc_id = $pdo->lastInsertId();

        // 3. Pobieramy składniki, które wtedy zeszły i zwracamy je na stan
        $stmt = $pdo->prepare("SELECT product_id, quantity FROM sh_document_items WHERE document_id = ?");
        $stmt->execute([$old_doc_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $p_id = $item['product_id'];
            $qty = $item['quantity'];

            // Zwrot na Magazyn Kuchnia
            $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity + ? WHERE warehouse_id = ? AND product_id = ?")
                ->execute([$qty, $warehouse_kuchnia_id, $p_id]);

            // Zapis pozycji w dokumencie korekty
            $pdo->prepare("INSERT INTO sh_document_items (document_id, product_id, quantity) VALUES (?, ?, ?)")
                ->execute([$new_doc_id, $p_id, $qty]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'msg' => "Towar wrócił na stan (Korekta: $kor_number)"]);
    }   catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
    }
		// =========================================================================================
// 📊 MATRYCA STANÓW: POBIERANIE DANYCH DO PANELU SZEFA
// =========================================================================================

if ($action === 'get_matrix') {
    try {
        // 1. Pobieramy wszystkie produkty (Słownik ASCII + UTF-8)
        $stmt = $pdo->query("SELECT id, name, ascii_key FROM sh_products ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Pobieramy fizyczne stany na magazynach
        $stmt = $pdo->query("SELECT warehouse_id, product_id, quantity FROM sh_stock_levels");
        $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Łączymy to w jeden inteligentny ładunek danych
        $matrix = [];
        foreach ($products as $p) {
            $p_id = $p['id'];
            $matrix[$p_id] = [
                'id' => $p['id'],
                'name' => $p['name'],         // Nazwa dla Ciebie (UTF-8)
                'ascii' => $p['ascii_key'],   // Klucz dla maszyny (ASCII)
                'w1' => 0.000,                // Magazyn Główny (ID: 1)
                'w2' => 0.000                 // Kuchnia (ID: 2)
            ];
        }

        foreach ($stocks as $s) {
            $p_id = $s['product_id'];
            $w_id = $s['warehouse_id'];
            if (isset($matrix[$p_id])) {
                if ($w_id == 1) $matrix[$p_id]['w1'] = (float)$s['quantity'];
                if ($w_id == 2) $matrix[$p_id]['w2'] = (float)$s['quantity'];
            }
        }

        // Zwracamy czysty format JSON do naszej nowej Matrycy
        echo json_encode(['success' => true, 'data' => array_values($matrix)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
        // GENEROWANIE DOKUMENTU WZ I ZDJĘCIE ZE STANÓW
        $doc_number = 'WZ/' . date('Y/m/d/His');
        $stmt = $pdo->prepare("INSERT INTO sh_documents (tenant_id, document_type, document_number, source_warehouse_id, order_id, created_by) VALUES (?, 'WZ', ?, ?, ?, ?)");
        $stmt->execute([$tid, $doc_number, $warehouse_kuchnia_id, $order_id, $uid]);
        $doc_id = $pdo->lastInsertId();

        foreach ($ingredients_needed as $p_id => $total_qty) {
            $stmt = $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity - ? WHERE warehouse_id = ? AND product_id = ?");
            $stmt->execute([$total_qty, $warehouse_kuchnia_id, $p_id]);
            
            if ($stmt->rowCount() === 0) {
                 $pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) VALUES (?, ?, -?)")->execute([$warehouse_kuchnia_id, $p_id, $total_qty]);
            }
            $pdo->prepare("INSERT INTO sh_document_items (document_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$doc_id, $p_id, $total_qty]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'doc_number' => $doc_number, 'msg' => 'Zaktualizowano magazyn.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}