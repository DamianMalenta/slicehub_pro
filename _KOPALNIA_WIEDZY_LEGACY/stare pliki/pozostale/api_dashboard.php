<?php
// 🛡️ SLICEHUB DASHBOARD API - ZMODULARYZOWANY
require_once 'db_connect.php';
require_login();

// Pobieranie danych firmy (Tenanta)
$stmt = $pdo->prepare("SELECT name, primary_color FROM sh_tenants WHERE id = ?"); 
$stmt->execute([$tenant_id]);
$tenant_data = $stmt->fetch();

echo json_encode([
    'auth' => true, 
    'user' => [
        'name' => $_SESSION['user_name'] ?? 'Użytkownik', 
        'role' => $user_role
    ], 
    'tenant' => $tenant_data
]);
?>