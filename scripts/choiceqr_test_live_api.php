<?php
require __DIR__ . '/../core/CredentialVault.php';
$pdo = new PDO('mysql:host=localhost;dbname=slicehub_pro_v2;charset=utf8mb4', 'root', '');
$row = $pdo->query("SELECT credentials FROM sh_tenant_integrations WHERE provider='choiceqr'")->fetch(PDO::FETCH_ASSOC);
$d = CredentialVault::decrypt($row['credentials']);
$c = json_decode($d, true);
$token = $c['token'];
$vs = $c['var_symbol'];

function call(string $method, string $path, ?array $body = null, string $token = ''): array {
    $url = "https://open-api.choiceqr.com" . $path;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $r = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $r, 'err' => $err];
}

echo "=== ChoiceQR API — test połączenia z prawdziwym tokenem ===\n";
echo "var_symbol: {$vs}\n";
echo "token_prefix: " . substr($token, 0, 20) . "...\n\n";

echo "--- Test 1: GET /orders/list (lista zamówień z ChoiceQR) ---\n";
$r = call('GET', '/orders/list?includeApproved=true&page=1&perPage=3', null, $token);
echo "HTTP={$r['code']}\n";
if ($r['err']) echo "curl_err: {$r['err']}\n";
echo "body[0:400]: " . substr($r['body'], 0, 400) . "\n\n";

echo "--- Test 2: GET /orders/list/archive (archiwum zamówień) ---\n";
$from = date('Y-m-d\TH:i:s.000\Z', strtotime('-30 days'));
$till = date('Y-m-d\TH:i:s.000\Z');
$r = call('GET', '/orders/list/archive?from=' . urlencode($from) . '&till=' . urlencode($till) . '&page=1&perPage=3', null, $token);
echo "HTTP={$r['code']}\n";
if ($r['err']) echo "curl_err: {$r['err']}\n";
echo "body[0:400]: " . substr($r['body'], 0, 400) . "\n\n";

echo "=== SliceHub endpointy — test przez Tailscale Funnel ===\n";
$funnel = 'https://desktop-i72peau.tail9e5f0e.ts.net';
$wt = 'b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3';

echo "--- Test 3: GET menu.php (SliceHub → ChoiceQR pobiera menu) ---\n";
$ch = curl_init("{$funnel}/slicehub/api/integrations/choiceqr/menu.php?t={$wt}&varSymbol={$vs}");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
$r = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP={$code}\n";
echo "body[0:200]: " . substr($r, 0, 200) . "\n\n";

echo "--- Test 4: GET areas.php (SliceHub → ChoiceQR pobiera strefy) ---\n";
$ch = curl_init("{$funnel}/slicehub/api/integrations/choiceqr/areas.php?t={$wt}&varSymbol={$vs}");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
$r = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP={$code}\n";
echo "body[0:200]: " . substr($r, 0, 200) . "\n\n";

echo "--- Test 5: GET table_orders.php (SliceHub → ChoiceQR pobiera zamówienia przy stoliku) ---\n";
$ch = curl_init("{$funnel}/slicehub/api/integrations/choiceqr/table_orders.php?t={$wt}&varSymbol={$vs}");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
$r = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP={$code}\n";
echo "body[0:200]: " . substr($r, 0, 200) . "\n";
