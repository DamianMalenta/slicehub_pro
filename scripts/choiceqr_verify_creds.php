<?php
require __DIR__ . '/../core/CredentialVault.php';
$pdo = new PDO('mysql:host=localhost;dbname=slicehub_pro_v2;charset=utf8mb4', 'root', '');
$row = $pdo->query("SELECT credentials FROM sh_tenant_integrations WHERE provider='choiceqr'")->fetch(PDO::FETCH_ASSOC);
$decrypted = CredentialVault::decrypt($row['credentials']);
$creds = json_decode($decrypted, true);
echo 'var_symbol: ' . ($creds['var_symbol'] ?? 'BRAK') . PHP_EOL;
echo 'token_prefix: ' . substr($creds['token'] ?? '', 0, 20) . '...' . PHP_EOL;
echo 'token_length: ' . strlen($creds['token'] ?? '') . PHP_EOL;
echo 'webhook_token: ' . substr($creds['webhook_token'] ?? '', 0, 12) . '...' . PHP_EOL;
echo 'domain: ' . ($creds['domain'] ?? 'BRAK') . PHP_EOL;
