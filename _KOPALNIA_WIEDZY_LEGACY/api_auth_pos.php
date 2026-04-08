<?php
// 🛡️ SLICEHUB AUTH - POS & EKIPA
require_once 'db_connect.php';

if ($action === 'login_pos') {
    $username = trim($_POST['username'] ?? $input['username'] ?? '');
    $password = trim($_POST['password'] ?? $input['password'] ?? '');

    $stmt = $pdo->prepare("SELECT id, username, role, password_hash, tenant_id FROM sh_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['tenant_id'] = $user['tenant_id'];

        // Dedykowane rozjazdy TYLKO dla pracowników operacyjnych
        if ($user['role'] === 'waiter') {
            $target = 'waiter.html';
        } else if ($user['role'] === 'driver') {
            $target = 'driver.html';
        } else {
            $target = 'app.html'; // Główny POS/Ekipa
        }
        
        echo json_encode(['success' => true, 'target' => $target]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błędne dane logowania.']);
    }
    exit;
}
echo json_encode(['success' => false, 'error' => "Nieznana akcja POS"]);
?>