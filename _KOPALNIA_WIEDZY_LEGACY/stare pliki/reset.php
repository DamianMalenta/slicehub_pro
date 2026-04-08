<?php
// Skrypt jednorazowy do ustawienia nowego hasła dla konta Boss
require_once 'db_connect.php';

$nowe_haslo = 'admin123'; // Tutaj wpisz hasło, jakiego chcesz używać
$hash = password_hash($nowe_haslo, PASSWORD_BCRYPT);

try {
    $stmt = $pdo->prepare("UPDATE sh_users SET password_hash = ? WHERE username = 'Boss'");
    $stmt->execute([$hash]);
    echo "Hasło dla konta Boss zostało zmienione na: <b>$nowe_haslo</b>. Możesz teraz skasować ten plik z serwera i się zalogować.";
} catch (Exception $e) {
    echo "Błąd: " . $e->getMessage();
}
?>