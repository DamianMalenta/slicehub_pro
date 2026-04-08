<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$host = 'localhost'; $db = 'baza_slicehub'; $user = 'damian_admin'; $pass = 'Dammalq123123@';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$uid = $_SESSION['user_id']; $tid = $_SESSION['tenant_id'];
$action = $_GET['action'] ?? '';

// POBIERZ LISTĘ EKIPY (Dla Managera)
if ($action === 'get_manager_data') {
    if ($_SESSION['user_role'] == 'employee') exit;
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, position FROM sh_users WHERE tenant_id = ? AND role != 'owner'");
    $stmt->execute([$tid]);
    echo json_encode(['success' => true, 'team' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// WYŚLIJ WNIOSEK O ZALICZKĘ/PREMIĘ
if ($action === 'submit_finance') {
    $target_uid = $_POST['target_user_id'];
    $amount = $_POST['amount'];
    $type = $_POST['type']; // 'advance', 'bonus', 'meal'
    $desc = $_POST['description'];
    
    $stmt = $pdo->prepare("INSERT INTO sh_deductions (user_id, tenant_id, amount, type, description, status, created_by) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
    echo json_encode(['success' => $stmt->execute([$target_uid, $tid, $amount, $type, $desc, $uid])]);
}
// ... reszta (start_work/stop_work) zostaje