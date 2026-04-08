<?php
// 🛡️ SLICEHUB AUTH - ADMIN & BACKOFFICE
require_once 'db_connect.php';

if ($action === 'login_admin') {
    $username = trim($_POST['username'] ?? $input['username'] ?? '');
    $password = trim($_POST['password'] ?? $input['password'] ?? '');

    // Twardy filtr - tylko szefostwo i admini
    $stmt = $pdo->prepare("SELECT id, username, role, password_hash, tenant_id FROM sh_users WHERE username = ? AND is_active = 1 AND role IN ('owner', 'admin', 'manager')");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        
        echo json_encode(['success' => true, 'target' => 'admin_app.html']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Brak dostępu lub błędne dane.']);
    }
    exit;
}
echo json_encode(['success' => false, 'error' => "Nieznana akcja Admina"]);
?>