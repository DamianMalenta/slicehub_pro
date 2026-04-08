<?php
// 🛡️ SLICEHUB AUTH - MODUŁ LOGOWANIA V3.6 (Izolacja Sesji + Auto-Kierowcy)
require_once 'db_connect.php';

// 1. Sprawdzanie Sesji Systemowej (POS, Admin)
if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['success' => true, 'role' => $_SESSION['user_role'], 'user_name' => $_SESSION['user_name']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Brak sesji.']);
    }
    exit;
}

// 2. Sprawdzanie Sesji Kiosku (Pracownicy)
if ($action === 'check_kiosk_session') {
    if (isset($_SESSION['kiosk_user_id'])) {
        echo json_encode(['success' => true, 'user_name' => $_SESSION['kiosk_user_name'] ?? 'Pracownik']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Brak sesji kiosku.']);
    }
    exit;
}

// 3. Logowanie Systemowe (Formularz: POS, Admin)
if ($action === 'login') {
    $username = trim($input['username'] ?? $_POST['username'] ?? '');
    $password = trim($input['password'] ?? $_POST['password'] ?? '');

    $stmt = $pdo->prepare("SELECT id, username, role, password_hash, tenant_id FROM sh_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['user_name'] = $user['username'];

        // Dynamiczne przekierowanie
        $target = 'pos.html';
        if ($user['role'] === 'owner') $target = 'admin_app.html';
        if ($user['role'] === 'driver') $target = 'driver.html';
        
        echo json_encode(['success' => true, 'target' => $target]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błędne hasło lub login.']);
    }
    exit;
}

// 4. Logowanie Kiosk (PIN)
if ($action === 'login_kiosk') {
    $pin = trim($input['pin'] ?? '');
    $stmt = $pdo->prepare("SELECT id, username, tenant_id FROM sh_users WHERE pin = ? AND is_active = 1");
    $stmt->execute([$pin]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['kiosk_user_id'] = $user['id'];
        $_SESSION['kiosk_user_name'] = $user['username']; // Zapisujemy imię do wyświetlenia
        $_SESSION['tenant_id'] = $user['tenant_id'];
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Błędny PIN.']);
    }
    exit;
}

// 5. Czas Pracy (Rozpocznij/Zakończ) z inteligentnym dodawaniem kierowcy
if ($action === 'clock_action') {
    require_login('kiosk'); 
    $type = $input['type'] ?? $_POST['type'] ?? 'clock_in';
    $user_id = $_SESSION['kiosk_user_id'];
    $tenant_id = $_SESSION['tenant_id'];
    
    try {
        if ($type === 'clock_in') {
            $stmt = $pdo->prepare("INSERT INTO sh_work_sessions (tenant_id, user_id, start_time) VALUES (?, ?, NOW())");
            $stmt->execute([$tenant_id, $user_id]);
            
            // 🚨 SPRAWDZENIE I DODANIE KIEROWCY DO RADARU POS
            $stmtCheck = $pdo->prepare("SELECT id FROM sh_drivers WHERE user_id = ?");
            $stmtCheck->execute([$user_id]);
            if (!$stmtCheck->fetch()) {
                // Jeśli kierowcy nie ma w tabeli sh_drivers, dodaj go i ustaw na 'available'
                $pdo->prepare("INSERT INTO sh_drivers (user_id, status) VALUES (?, 'available')")->execute([$user_id]);
            } else {
                // Jeśli już tam jest, po prostu zmień status
                $pdo->prepare("UPDATE sh_drivers SET status = 'available' WHERE user_id = ?")->execute([$user_id]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE sh_work_sessions SET end_time = NOW(), total_time = TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60.0 WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
            $stmt->execute([$user_id]);
            
            // Zdejmij kierowcę z radaru
            $pdo->prepare("UPDATE sh_drivers SET status = 'offline' WHERE user_id = ?")->execute([$user_id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
    }
    exit;
}

// 6. Wylogowanie Systemowe
if ($action === 'logout') {
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    echo json_encode(['success' => true]);
    exit;
}

// 7. Wylogowanie Kiosk
if ($action === 'logout_kiosk') {
    unset($_SESSION['kiosk_user_id'], $_SESSION['kiosk_user_name']);
    echo json_encode(['success' => true]);
    exit;
}

// 8. Fallback
echo json_encode(['success' => false, 'error' => "Nieznana akcja API Auth"]);
exit;
?>