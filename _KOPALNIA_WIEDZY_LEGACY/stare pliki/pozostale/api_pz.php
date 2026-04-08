<?php
// 🛡️ Twarda walidacja - Strażnik
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Brak autoryzacji! Zaloguj się.']);
    exit;
}

$host = 'localhost'; $db = 'baza_slicehub'; $user = 'damian_admin'; $pass = 'Dammalq123123@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die(json_encode(['status' => 'error', 'message' => 'Błąd serwera bazy danych.']));
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// 🟢 POBIERANIE PRODUKTÓW
if ($action === 'get_products') {
    // Pobieramy też aktualny stan, żeby ładnie wyglądało w interfejsie
    $stmt = $pdo->query("SELECT id, name, external_key FROM sh_products WHERE tenant_id = 1 ORDER BY name ASC");
    $products = $stmt->fetchAll();
    echo json_encode(['status' => 'success', 'data' => $products]);
    exit;
}

// 🟢 ZAPIS DO BAZY (Transakcja)
if ($action === 'save_pz') {
    try {
        $pdo->beginTransaction();
        
        $doc_number = $input['doc_number'];
        $warehouse_id = $input['warehouse_id']; 
        $user_id = $_SESSION['user_id']; // ID bezpośrednio z bezpiecznej sesji!
        $items = $input['items']; 

        // 1. Zapis nagłówka w sh_documents
        $stmt = $pdo->prepare("INSERT INTO sh_documents (tenant_id, document_type, document_number, target_warehouse_id, created_by, status) VALUES (1, 'PZ', ?, ?, ?, 'approved')");
        $stmt->execute([$doc_number, $warehouse_id, $user_id]);
        $document_id = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("INSERT INTO sh_document_items (document_id, product_id, quantity, purchase_price) VALUES (?, ?, ?, ?)");
        $stockStmt = $pdo->prepare("
            INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");

        // 2. Rozbicie i pętla dla każdego produktu
        foreach ($items as $item) {
            $itemStmt->execute([$document_id, $item['product_id'], $item['quantity'], $item['price']]);
            $stockStmt->execute([$warehouse_id, $item['product_id'], $item['quantity'], $item['quantity']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Dokument PZ został przyjęty na stan.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Błąd zapisu PZ: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Nieznana akcja.']);
?>