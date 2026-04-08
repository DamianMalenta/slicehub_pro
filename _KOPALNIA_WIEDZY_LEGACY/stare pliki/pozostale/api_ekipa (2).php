<?php
// 🛡️ SLICEHUB API EKIPA - WERSJA ZMODULARYZOWANA
require_once 'db_connect.php'; // Zaciągamy zbroję i połączenie

// Sprawdzamy, czy użytkownik ma prawo tu być (wymagamy logowania)
require_role(['employee', 'cook', 'waiter', 'driver', 'admin', 'owner', 'manager']);

// Pomocnicza funkcja anty-błędowa
function getVal($pdo, $sql) { 
    try { return (float)$pdo->query($sql)->fetchColumn(); } 
    catch(Throwable $e) { return 0; } 
}

// Funkcja zapisu obrazków Base64
function saveBase64Image($base64_string, $folder) {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_string, $type)) {
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1); 
        $type = strtolower($type[1]);
        if (in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
            $decoded = base64_decode(str_replace(' ', '+', $base64_string)); 
            $filename = uniqid('img_') . '.' . $type;
            if (!is_dir($folder)) mkdir($folder, 0777, true); // Upewniamy się, że folder istnieje
            file_put_contents($folder . '/' . $filename, $decoded); 
            return $folder . '/' . $filename;
        }
    } 
    return null;
}

if ($action === 'ping') { 
    $pdo->exec("UPDATE sh_users SET last_seen = NOW() WHERE id = $user_id"); 
    echo json_encode(['success' => true]); 
    exit; 
}

// 🟢 FEED & GRAFIK
if ($action === 'get_feed') {
    $u = $pdo->query("SELECT first_name, position, slice_coins, avatar_path FROM sh_users WHERE id = $user_id")->fetch();
    
    // Szukamy ostatniej nieprzeczytanej wiadomości (uwzględniając tenant_id)
    $stmt = $pdo->prepare("SELECT u.first_name as sender_name, m.message, m.id as msg_id FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE m.target_user_id = ? AND m.is_read = 0 AND m.tenant_id = ? ORDER BY m.created_at DESC LIMIT 1");
    $stmt->execute([$user_id, $tenant_id]);
    $latest_pm = $stmt->fetch();
    
    $pending_boss_missions = $pdo->query("SELECT COUNT(*) FROM sh_missions m WHERE m.is_active = 1 AND m.tenant_id = $tenant_id AND NOT EXISTS (SELECT 1 FROM sh_mission_proofs p WHERE p.mission_id = m.id AND p.user_id = $user_id)")->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(shift_date, '%w') as day_of_week, DATE_FORMAT(shift_date, '%d.%m') as date, DATE_FORMAT(start_time, '%H:%i') as start, DATE_FORMAT(end_time, '%H:%i') as end FROM sh_schedule WHERE user_id = ? AND tenant_id = ? AND YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1) ORDER BY shift_date ASC");
    $stmt->execute([$user_id, $tenant_id]);
    $schedule = $stmt->fetchAll();
    
    $trivia = ["Prawdziwa Pizza Napoletana piecze się w 60 sekund w 485°C!", "Słowo Pizza pochodzi z łaciny i oznacza 'zgniatać'.", "Najdroższa pizza świata kosztuje 12 000 dolarów!"];
    echo json_encode(['success' => true, 'user' => $u, 'latest_pm' => $latest_pm, 'pending_missions' => $pending_boss_missions, 'schedule' => $schedule, 'trivia' => $trivia[date('z') % count($trivia)]]); 
    exit;
}

// 🟢 CZAT
if ($action === 'get_chat_data') {
    $team = $pdo->query("SELECT u.id, IFNULL(u.first_name, u.username) as first_name, u.role, u.phone, u.avatar_path, IF(u.last_seen > NOW() - INTERVAL 5 MINUTE, 'green', 'red') as status_color FROM sh_users u WHERE u.is_active = 1 AND u.tenant_id = $tenant_id AND u.id != $user_id")->fetchAll();
    $rooms = $pdo->query("SELECT * FROM sh_chat_rooms WHERE tenant_id = $tenant_id")->fetchAll();
    
    $rid = $_GET['room_id'] ?? null; 
    $target_id = $_GET['target_id'] ?? null; 
    $msgs = [];
    
    if ($target_id) {
        $pdo->exec("UPDATE sh_chat_messages SET is_read = 1 WHERE target_user_id = $user_id AND user_id = ".(int)$target_id." AND is_read = 0");
        $stmt = $pdo->prepare("SELECT m.*, IFNULL(u.first_name, u.username) as first_name, u.avatar_path, DATE_FORMAT(m.created_at, '%H:%i') as time FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE m.tenant_id = ? AND ((m.user_id = ? AND m.target_user_id = ?) OR (m.user_id = ? AND m.target_user_id = ?)) ORDER BY m.id DESC LIMIT 50");
        $stmt->execute([$tenant_id, $user_id, $target_id, $target_id, $user_id]); 
        $msgs = array_reverse($stmt->fetchAll());
    } elseif ($rid) {
        $stmt = $pdo->prepare("SELECT m.*, IFNULL(u.first_name, u.username) as first_name, u.avatar_path, DATE_FORMAT(m.created_at, '%H:%i') as time FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE m.room_id = ? AND m.tenant_id = ? ORDER BY m.id DESC LIMIT 50");
        $stmt->execute([$rid, $tenant_id]); 
        $msgs = array_reverse($stmt->fetchAll());
    }
    echo json_encode(['success' => true, 'rooms' => $rooms, 'team' => $team, 'messages' => $msgs]); 
    exit;
}

if ($action === 'send_chat') {
    $msg = $_POST['message'] ?? ''; 
    $rid = empty($_POST['room_id']) ? null : (int)$_POST['room_id']; 
    $tid = empty($_POST['target_id']) ? null : (int)$_POST['target_id'];
    $imgPath = !empty($_POST['image']) ? saveBase64Image($_POST['image'], 'uploads/chat') : null;
    
    if ($msg !== '' || $imgPath !== null) { 
        $pdo->prepare("INSERT INTO sh_chat_messages (tenant_id, room_id, target_user_id, user_id, message, image_path) VALUES (?, ?, ?, ?, ?, ?)")->execute([$tenant_id, $rid, $tid, $user_id, $msg, $imgPath]); 
    }
    echo json_encode(['success' => true]); 
    exit;
}

// 🟢 MOJE KONTO I ZAROBKI
if ($action === 'get_profile_data') {
    $u = $pdo->query("SELECT first_name, position, avatar_path, hourly_rate FROM sh_users WHERE id = $user_id")->fetch();
    $rate = (float)($u['hourly_rate'] ?? 0);
    
    $st_c = date('Y-m-01 00:00:00'); $en_c = date('Y-m-d H:i:s');
    $hrs_c = getVal($pdo, "SELECT IFNULL(SUM(total_time), 0) FROM sh_work_sessions WHERE user_id = $user_id AND start_time >= '$st_c' AND start_time <= '$en_c'");
    $adv_c = getVal($pdo, "SELECT IFNULL(SUM(amount), 0) FROM sh_deductions WHERE user_id = $user_id AND created_at >= '$st_c' AND created_at <= '$en_c'");
    $gross_c = $hrs_c * $rate; $net_c = $gross_c - $adv_c;

    echo json_encode(['success' => true, 'user' => $u, 'current' => ['hours' => round($hrs_c,1), 'net' => round($net_c,2), 'gross' => round($gross_c,2), 'advances' => round($adv_c,2)], 'prev' => ['hours' => 0, 'net' => 0, 'gross' => 0, 'advances' => 0]]); 
    exit;
}

if ($action === 'add_request') {
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO sh_finance_requests (tenant_id, target_user_id, created_by_id, type, amount, description) VALUES (?, ?, ?, 'advance', ?, ?)");
        $stmt->execute([$tenant_id, $user_id, $user_id, $amount, $reason]);
    }
    echo json_encode(['success' => true]); 
    exit;
}

// 🟢 MAGAZYN (Zablokowany do czasu wdrożenia pełnego modułu)
if ($action === 'get_inventory_simple' || $action === 'update_stock_exact' || $action === 'submit_inventory_sheet') {
    echo json_encode(['success' => true, 'categories' => [], 'inventory' => [], 'logs' => [], 'message' => 'Moduł w przebudowie']); 
    exit;
}

// Domyślna odpowiedź
echo json_encode(['success' => false, 'error' => 'Nieznana akcja w API Ekipy.']);
?>