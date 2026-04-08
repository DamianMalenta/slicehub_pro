<?php
// 🛡️ SLICEHUB AUTH - KIOSK (TYLKO PIN)
require_once 'db_connect.php';

if ($action === 'login_kiosk') {
    $pin = trim($_POST['pin'] ?? $input['pin'] ?? '');

    // Kiosk wymaga autoryzacji kodem PIN (zakładam, że masz kolumnę pin/kiosk_pin)
    $stmt = $pdo->prepare("SELECT id, first_name, tenant_id FROM sh_users WHERE pin_code = ? AND is_active = 1 AND role != 'owner'");
    $stmt->execute([$pin]);
    $user = $stmt->fetch();

    if ($user) {
        // Ustawiamy dedykowaną, izolowaną sesję dla Kiosku
        $_SESSION['kiosk_user_id'] = $user['id'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['kiosk_user_name'] = $user['first_name'];
        
        echo json_encode(['success' => true, 'target' => 'kiosk_dashboard.html']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błędny kod PIN.']);
    }
    exit;
}
echo json_encode(['success' => false, 'error' => "Nieznana akcja Kiosku"]);
?>