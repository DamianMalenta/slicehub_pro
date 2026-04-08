<?php
error_reporting(0); ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

// Sprawdzamy, czy użytkownik jest zalogowany i czy ma rolę z naszej matrycy ekipy
$allowed_roles = ['employee', 'cook', 'waiter', 'driver', 'admin', 'owner'];
$current_role = $_SESSION['user_role'] ?? '';

// Nowe zabezpieczenie ról (wpuszcza całą ekipę zgodnie z matrycą)
$allowed_roles = ['employee', 'cook', 'waiter', 'driver', 'admin', 'owner'];
$current_role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($current_role, $allowed_roles)) { 
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']); 
    exit; 
}
$host = 'localhost'; $db = 'baza_slicehub'; $user = 'damian_admin'; $pass = 'Dammalq123123@';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]); } 
catch (Exception $e) { die(json_encode(['success' => false, 'error' => 'Błąd bazy'])); }

$uid = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Pomocnicza funkcja anty-błędowa
function getVal($pdo, $sql) { try { return (float)$pdo->query($sql)->fetchColumn(); } catch(Throwable $e) { return 0; } }

function saveBase64Image($base64_string, $folder) {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_string, $type)) {
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1); $type = strtolower($type[1]);
        if (in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
            $decoded = base64_decode(str_replace(' ', '+', $base64_string)); $filename = uniqid('img_') . '.' . $type;
            file_put_contents($folder . '/' . $filename, $decoded); return $folder . '/' . $filename;
        }
    } return null;
}

if ($action === 'ping') { $pdo->exec("UPDATE sh_users SET last_seen = NOW() WHERE id = $uid"); echo json_encode(['success' => true]); exit; }

// 🟢 FEED & GRAFIK
if ($action === 'get_feed') {
    $u = $pdo->query("SELECT first_name, position, slice_coins, avatar_path FROM sh_users WHERE id = $uid")->fetch(PDO::FETCH_ASSOC);
    $latest_pm = $pdo->query("SELECT u.first_name as sender_name, m.message, m.id as msg_id FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE m.target_user_id = $uid AND m.is_read = 0 ORDER BY m.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pending_boss_missions = $pdo->query("SELECT COUNT(*) FROM sh_missions m WHERE m.status = 'active' AND m.mission_type = 'obowiazkowa' AND NOT EXISTS (SELECT 1 FROM sh_mission_proofs p WHERE p.mission_id = m.id AND p.user_id = $uid)")->fetchColumn();
    $schedule = $pdo->query("SELECT DATE_FORMAT(shift_date, '%w') as day_of_week, DATE_FORMAT(shift_date, '%d.%m') as date, DATE_FORMAT(start_time, '%H:%i') as start, DATE_FORMAT(end_time, '%H:%i') as end FROM sh_schedule WHERE user_id = $uid AND YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1) ORDER BY shift_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $trivia = ["Prawdziwa Pizza Napoletana piecze się w 60 sekund w 485°C!", "Słowo Pizza pochodzi z łaciny i oznacza 'zgniatać'.", "Najdroższa pizza świata kosztuje 12 000 dolarów!", "W 2001 roku dostarczono pizzę na Międzynarodową Stację Kosmiczną."];
    echo json_encode(['success' => true, 'user' => $u, 'latest_pm' => $latest_pm, 'pending_missions' => $pending_boss_missions, 'schedule' => $schedule, 'trivia' => $trivia[date('z') % count($trivia)]]); exit;
}

// 🟢 CZAT (Zoptymalizowany)
if ($action === 'get_chat_data') {
    $team = $pdo->query("SELECT u.id, u.first_name, u.role, u.phone, u.avatar_path, IF(ws.id IS NOT NULL, 'blue', IF(u.last_seen > NOW() - INTERVAL 5 MINUTE, 'green', 'red')) as status_color FROM sh_users u LEFT JOIN sh_work_sessions ws ON u.id = ws.user_id AND ws.end_time IS NULL WHERE u.is_approved = 1 AND u.id != $uid")->fetchAll(PDO::FETCH_ASSOC);
    $rooms = $pdo->query("SELECT * FROM sh_chat_rooms")->fetchAll(PDO::FETCH_ASSOC);
    $rid = $_GET['room_id'] ?? null; $target_id = $_GET['target_id'] ?? null; $msgs = [];
    if ($target_id) {
        // Blokada zapisów na dysk - aktualizujemy tylko te faktycznie nieprzeczytane
        $pdo->exec("UPDATE sh_chat_messages SET is_read = 1 WHERE target_user_id = $uid AND user_id = $target_id AND is_read = 0");
        
        // Ultraszybkie pobieranie za pomocą indeksu PK (ORDER BY m.id DESC) i odwracanie w PHP
        $stmt = $pdo->prepare("SELECT m.*, u.first_name, u.avatar_path, DATE_FORMAT(m.created_at, '%H:%i') as time FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE (m.user_id = ? AND m.target_user_id = ?) OR (m.user_id = ? AND m.target_user_id = ?) ORDER BY m.id DESC LIMIT 50");
        $stmt->execute([$uid, $target_id, $target_id, $uid]); 
        $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($rid) {
        $stmt = $pdo->prepare("SELECT m.*, u.first_name, u.avatar_path, DATE_FORMAT(m.created_at, '%H:%i') as time FROM sh_chat_messages m JOIN sh_users u ON m.user_id = u.id WHERE m.room_id = ? ORDER BY m.id DESC LIMIT 50");
        $stmt->execute([$rid]); 
        $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    echo json_encode(['success' => true, 'rooms' => $rooms, 'team' => $team, 'messages' => $msgs]); exit;
}
if ($action === 'send_chat') {
    $msg = $_POST['message'] ?? ''; $rid = empty($_POST['room_id']) ? null : (int)$_POST['room_id']; $tid = empty($_POST['target_id']) ? null : (int)$_POST['target_id'];
    $imgPath = !empty($_POST['image']) ? saveBase64Image($_POST['image'], 'uploads/chat') : null;
    if ($msg !== '' || $imgPath !== null) { $pdo->prepare("INSERT INTO sh_chat_messages (room_id, target_user_id, user_id, message, image_path) VALUES (?, ?, ?, ?, ?)")->execute([$rid, $tid, $uid, $msg, $imgPath]); }
    echo json_encode(['success' => true]); exit;
}

// 🟢 ROZRYWKA & MISJE
if ($action === 'get_entertainment') {
    try {
        $m = $pdo->query("
            SELECT m.*, (SELECT status FROM sh_mission_proofs WHERE mission_id = m.id AND user_id = $uid ORDER BY id DESC LIMIT 1) as my_status 
            FROM sh_missions m 
            WHERE m.status = 'active' AND m.mission_type != 'hidden' 
            AND (m.mission_type = 'obowiazkowa' OR NOT EXISTS (SELECT 1 FROM sh_mission_proofs p WHERE p.mission_id = m.id AND p.user_id != $uid AND p.status != 'rejected'))
            ORDER BY (m.mission_type = 'obowiazkowa') DESC, m.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $top = $pdo->query("SELECT first_name, slice_coins, avatar_path FROM sh_users WHERE is_approved = 1 AND role != 'owner' ORDER BY slice_coins DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        $lootbox_claimed = $pdo->query("SELECT COUNT(*) FROM sh_daily_rewards WHERE user_id = $uid AND claimed_date = CURDATE()")->fetchColumn() > 0;
        
        $history = $pdo->query("
            SELECT m.title, u.first_name, p.status, DATE_FORMAT(p.created_at, '%d.%m %H:%i') as date 
            FROM sh_mission_proofs p 
            JOIN sh_missions m ON p.mission_id = m.id 
            JOIN sh_users u ON p.user_id = u.id 
            WHERE m.mission_type != 'hidden' 
            ORDER BY p.created_at DESC LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'missions' => $m, 'ranking' => $top, 'history' => $history, 'lootbox_claimed' => $lootbox_claimed]);
    } catch(Throwable $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}
if ($action === 'spin_wheel') {
    $claimed = $pdo->query("SELECT COUNT(*) FROM sh_daily_rewards WHERE user_id = $uid AND claimed_date = CURDATE()")->fetchColumn() > 0;
    if ($claimed) { echo json_encode(['success' => false, 'error' => 'Już losowano!']); exit; }
    $won = rand(2, 10);
    $pdo->prepare("INSERT INTO sh_daily_rewards (user_id, claimed_date, coins_won) VALUES (?, CURDATE(), ?)")->execute([$uid, $won]);
    $pdo->prepare("UPDATE sh_users SET slice_coins = slice_coins + ? WHERE id = ?")->execute([$won, $uid]);
    echo json_encode(['success' => true, 'won' => $won]); exit;
}
if ($action === 'submit_mission') {
    $mid = (int)$_POST['mission_id']; $type = $_POST['type'] ?? 'dodatkowa';
    
    if($type === 'obowiazkowa' && empty($_POST['image'])) { 
        $pdo->prepare("INSERT INTO sh_mission_proofs (mission_id, user_id, image_path, status) VALUES (?, ?, '', 'approved')")->execute([$mid, $uid]); 
        echo json_encode(['success' => true]); exit; 
    }
    
    $imgPath = '';
    if(!empty($_POST['image'])) { $imgPath = saveBase64Image($_POST['image'], 'uploads/missions') ?: ''; }
    
    $pdo->prepare("INSERT INTO sh_mission_proofs (mission_id, user_id, image_path) VALUES (?, ?, ?)")->execute([$mid, $uid, $imgPath]); 
    echo json_encode(['success' => true]); exit;
}

// 🟢 MOJE KONTO (PRECYZYJNA ANALITYKA MTD)
if ($action === 'upload_avatar') {
    $imgPath = saveBase64Image($_POST['image'], 'uploads/avatars');
    if($imgPath) { $pdo->prepare("UPDATE sh_users SET avatar_path = ? WHERE id = ?")->execute([$imgPath, $uid]); echo json_encode(['success' => true]); } exit;
}
if ($action === 'get_profile_data') {
    $u = $pdo->query("SELECT first_name, position, avatar_path, hourly_rate FROM sh_users WHERE id = $uid")->fetch(PDO::FETCH_ASSOC);
    $rate = (float)($u['hourly_rate'] ?? 0);
    $d = date('d'); $H = date('H:i:s');
    
    // CURRENT
    $st_c = date('Y-m-01 00:00:00'); $en_c = date('Y-m-d H:i:s');
    $hrs_c = getVal($pdo, "SELECT IFNULL(SUM(total_time), 0) FROM sh_work_sessions WHERE user_id = $uid AND start_time >= '$st_c' AND start_time <= '$en_c'");
    $active_hrs = getVal($pdo, "SELECT IFNULL(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 0) FROM sh_work_sessions WHERE user_id = $uid AND end_time IS NULL");
    $hrs_c += $active_hrs;
    $adv_c = getVal($pdo, "SELECT IFNULL(SUM(amount), 0) FROM sh_deductions WHERE user_id = $uid AND created_at >= '$st_c' AND created_at <= '$en_c'");
    $gross_c = $hrs_c * $rate; $net_c = $gross_c - $adv_c;

    // PREV
    $prev_time = strtotime('-1 month');
    $st_p = date('Y-m-01 00:00:00', $prev_time); 
    $days_in_prev = date('t', $prev_time);
    $target_day = $d > $days_in_prev ? $days_in_prev : $d;
    $en_p = date('Y-m-'.$target_day.' '.$H, $prev_time);

    $hrs_p = getVal($pdo, "SELECT IFNULL(SUM(total_time), 0) FROM sh_work_sessions WHERE user_id = $uid AND start_time >= '$st_p' AND start_time <= '$en_p'");
    $adv_p = getVal($pdo, "SELECT IFNULL(SUM(amount), 0) FROM sh_deductions WHERE user_id = $uid AND created_at >= '$st_p' AND created_at <= '$en_p'");
    $gross_p = $hrs_p * $rate; $net_p = $gross_p - $adv_p;

    echo json_encode(['success' => true, 'user' => $u, 'current' => ['hours' => round($hrs_c,1), 'net' => round($net_c,2), 'gross' => round($gross_c,2), 'advances' => round($adv_c,2)], 'prev' => ['hours' => round($hrs_p,1), 'net' => round($net_p,2), 'gross' => round($gross_p,2), 'advances' => round($adv_p,2)]]); 
    exit;
}

if ($action === 'get_advanced_report') {
    $type = $_GET['type'] ?? 'month'; $offset = (int)($_GET['offset'] ?? 0);
    $rate = (float)$pdo->query("SELECT hourly_rate FROM sh_users WHERE id = $uid")->fetchColumn();
    
    if ($type === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime("monday this week -$offset weeks"));
        $end = date('Y-m-d 23:59:59', strtotime("sunday this week -$offset weeks"));
    } elseif ($type === 'year') {
        $start = date('Y-01-01 00:00:00', strtotime("first day of january -$offset years"));
        $end = date('Y-12-31 23:59:59', strtotime("last day of december -$offset years"));
    } else { 
        $start = date('Y-m-01 00:00:00', strtotime("first day of -$offset months"));
        $end = date('Y-m-t 23:59:59', strtotime("last day of -$offset months"));
    }

    // Pobieramy zamknięte zmiany
    $hrs = getVal($pdo, "SELECT IFNULL(SUM(total_time), 0) FROM sh_work_sessions WHERE user_id = $uid AND start_time >= '$start' AND start_time <= '$end'");
    
    // Pobieramy trwającą zmianę (jeśli ktoś aktualnie pracuje)
    $active_hrs = getVal($pdo, "SELECT IFNULL(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 0) FROM sh_work_sessions WHERE user_id = $uid AND end_time IS NULL AND start_time >= '$start' AND start_time <= '$end'");
    $hrs += $active_hrs;

    $adv = getVal($pdo, "SELECT IFNULL(SUM(amount), 0) FROM sh_deductions WHERE user_id = $uid AND created_at >= '$start' AND created_at <= '$end'");
    $gross = $hrs * $rate; $net = $gross - $adv;
    
    echo json_encode(['success' => true, 'data' => ['hours' => round($hrs,1), 'net' => round($net,2), 'gross' => round($gross,2), 'advances' => round($adv,2)]]); exit;
}

if ($action === 'add_request') {
    $pdo->prepare("INSERT INTO sh_requests (user_id, type, amount, reason) VALUES (?, 'zaliczka', ?, ?)")->execute([$uid, $_POST['amount'], $_POST['reason']]); echo json_encode(['success' => true]); exit;
}
// 🟢 MAGAZYN
if ($action === 'get_inventory_simple') {
    $cats = []; $inventory = []; $logs = [];
    try { $cats = $pdo->query("SELECT * FROM sh_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {}
    try { $inventory = $pdo->query("SELECT id, name, quantity, category_ascii FROM sh_products WHERE tenant_id = 1 OR tenant_id IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {}
    try { $logs = $pdo->query("SELECT l.quantity_changed, DATE_FORMAT(l.created_at, '%d.%m %H:%i') as date, u.first_name, p.name as product_name, l.action_type FROM sh_inventory_logs l JOIN sh_users u ON l.user_id = u.id JOIN sh_products p ON l.product_id = p.id ORDER BY l.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {}
    echo json_encode(['success' => true, 'categories' => $cats, 'inventory' => $inventory, 'logs' => $logs]); exit;
}
if ($action === 'update_stock_exact') {
    try {
        $pid = (int)$_POST['id']; $new_val = (float)$_POST['val']; $old_val = $pdo->query("SELECT quantity FROM sh_products WHERE id = $pid")->fetchColumn(); $change = $new_val - $old_val;
        $pdo->prepare("UPDATE sh_products SET quantity = ? WHERE id = ?")->execute([$new_val, $pid]);
        try { $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'korekta')")->execute([$pid, $uid, $change]); } catch(Throwable $e){}
        echo json_encode(['success' => true]);
    } catch(Throwable $e) { echo json_encode(['success' => false]); } exit;
}
if ($action === 'submit_inventory_sheet') {
    try {
        $data = json_decode($_POST['inventory_data'], true); $pdo->beginTransaction();
        foreach($data as $pid => $new_val) {
            $old_val = $pdo->query("SELECT quantity FROM sh_products WHERE id = ".(int)$pid)->fetchColumn(); $change = (float)$new_val - (float)$old_val;
            if($change != 0) {
                $pdo->prepare("UPDATE sh_products SET quantity = ? WHERE id = ?")->execute([$new_val, $pid]);
                // ZABEZPIECZENIE: Ignorowanie błędów zapisu zepsutych logów, by stany się zapisały!
                try { $pdo->prepare("INSERT INTO sh_inventory_logs (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'inwentaryzacja')")->execute([$pid, $uid, $change]); } catch(Throwable $e) {}
            }
        }
        $rev_mission_id = $pdo->query("SELECT id FROM sh_missions WHERE title = 'Rewizja Magazynu' LIMIT 1")->fetchColumn();
        if($rev_mission_id) { $pdo->prepare("INSERT INTO sh_mission_proofs (mission_id, user_id, status) VALUES (?, ?, 'pending')")->execute([$rev_mission_id, $uid]); }
        $pdo->commit(); echo json_encode(['success' => true]);
    } catch(Throwable $e) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => $e->getMessage()]); } exit;
}