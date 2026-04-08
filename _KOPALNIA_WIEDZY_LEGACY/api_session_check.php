<?php
// 🛡️ SLICEHUB - KONTROLER SESJI (FRONT-END INIT)
session_start();
header('Content-Type: application/json; charset=utf-8');

// Ten plik tylko odpowiada "TAK/NIE", czy sesja żyje, żeby odblokować front-end.
if (isset($_SESSION['user_id'])) {
    echo json_encode(['success' => true, 'user_id' => $_SESSION['user_id'], 'role' => $_SESSION['user_role']]);
} else if (isset($_SESSION['kiosk_user_id'])) {
    echo json_encode(['success' => true, 'kiosk_user_id' => $_SESSION['kiosk_user_id']]);
} else {
    echo json_encode(['success' => false, 'error' => 'Brak aktywnej sesji']);
}
exit;
?>