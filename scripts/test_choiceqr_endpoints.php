<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Endpoints Smoke Test
// =============================================================================
// Weryfikuje że wszystkie 7 endpointów ChoiceQR odpowiada poprawnie:
//   • webhook.php, events.php, pay.php — POST, oczekiwane 200 empty body / 401 bez tokenu
//   • menu.php, areas.php, table_orders.php — GET, oczekiwane 200 JSON / 401 bez tokenu
//   • oauth_callback.php — GET bez code, oczekiwany HTML z błędem "Brak kodu"
//
// Uruchomienie:
//   php scripts/test_choiceqr_endpoints.php [base_url] [webhook_token]
//
// Domyślnie:
//   base_url     = http://localhost/slicehub/api/integrations/choiceqr
//   webhook_token = (pusty — testy auth-fail)
//
// Przykład z tokenem:
//   php scripts/test_choiceqr_endpoints.php http://localhost/slicehub/api/integrations/choiceqr b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
//
// Exit codes:
//   0 — wszystkie testy PASS
//   1 — co najmniej 1 FAIL
// =============================================================================

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$baseUrl    = $argv[1] ?? 'http://localhost/slicehub/api/integrations/choiceqr';
$webhookTok = $argv[2] ?? '';

// Normalizuj base URL (bez trailing slash)
$baseUrl = rtrim($baseUrl, '/');

$pass = 0;
$fail = 0;
$tests = [];

function httpCall(string $url, string $method = 'GET', ?string $body = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    if ($ch === false) return ['code' => 0, 'body' => '', 'error' => 'curl_init failed'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HEADER         => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

function record(string $name, bool $ok, string $detail): void
{
    global $pass, $fail, $tests;
    if ($ok) $pass++; else $fail++;
    $tests[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    $status = $ok ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %s — %s\n", $status, $name, $detail);
}

// =============================================================================
// TESTY BEZ TOKENU — auth-fail (oczekiwane 401 lub 200-masked-as-200)
// =============================================================================

// 1. webhook.php bez tokenu — oczekiwane 401 (pre-auth failure)
$r = httpCall($baseUrl . '/webhook.php', 'POST', json_encode(['order' => ['_id' => 'test'], 'varSymbol' => 'x']));
record(
    'webhook.php bez tokenu → 401',
    $r['code'] === 401,
    "HTTP {$r['code']}" . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 2. events.php bez tokenu — oczekiwane 200 empty body (cqr_ev_fail maskuje jako 200
//    żeby ChoiceQR nie anuluje zamówienia po 3 brakach 200 — świadoma decyzja projektowa)
$r = httpCall($baseUrl . '/events.php', 'POST', json_encode(['id' => 'evt1', 'type' => 'order.closed', 'varSymbol' => 'x']));
record(
    'events.php bez tokenu → 200 empty body (masked)',
    $r['code'] === 200 && $r['body'] === '',
    "HTTP {$r['code']}, body_len=" . strlen($r['body']) . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 3. pay.php bez tokenu — oczekiwane 200 empty body (cqr_pay_fail maskuje jako 200)
$r = httpCall($baseUrl . '/pay.php', 'POST', json_encode(['order' => ['_id' => 'test'], 'varSymbol' => 'x']));
record(
    'pay.php bez tokenu → 200 empty body (masked)',
    $r['code'] === 200 && $r['body'] === '',
    "HTTP {$r['code']}, body_len=" . strlen($r['body']) . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 4. menu.php bez tokenu — oczekiwane 401
$r = httpCall($baseUrl . '/menu.php', 'GET');
record(
    'menu.php bez tokenu → 401',
    $r['code'] === 401,
    "HTTP {$r['code']}" . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 5. areas.php bez tokenu — oczekiwane 401
$r = httpCall($baseUrl . '/areas.php', 'GET');
record(
    'areas.php bez tokenu → 401',
    $r['code'] === 401,
    "HTTP {$r['code']}" . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 6. table_orders.php bez tokenu — oczekiwane 401
$r = httpCall($baseUrl . '/table_orders.php', 'GET');
record(
    'table_orders.php bez tokenu → 401',
    $r['code'] === 401,
    "HTTP {$r['code']}" . ($r['error'] !== '' ? " (curl: {$r['error']})" : '')
);

// 7. oauth_callback.php bez code — oczekiwany HTML z błędem (HTTP 400)
$r = httpCall($baseUrl . '/oauth_callback.php', 'GET');
$hasBrakKodu = str_contains($r['body'], 'Brak kodu') || str_contains($r['body'], 'code');
record(
    'oauth_callback.php bez code → HTML błąd',
    $r['code'] === 400 && $hasBrakKodu,
    "HTTP {$r['code']}, body zawiera 'Brak kodu': " . ($hasBrakKodu ? 'tak' : 'nie')
);

// =============================================================================
// TESTY Z TOKENEM — gdy podano webhook_token w argumencie
// =============================================================================
if ($webhookTok !== '') {
    echo "\n--- Testy z tokenem ({$webhookTok}) ---\n";

    // varSymbol z bazy (PLACEHOLDER dopóki OAuth nie przejdzie)
    $varSymbol = $argv[3] ?? 'PLACEHOLDER';

    // 8. menu.php z tokenem + varSymbol — oczekiwane 200 + JSON array
    $r = httpCall($baseUrl . '/menu.php?t=' . urlencode($webhookTok) . '&varSymbol=' . urlencode($varSymbol), 'GET');
    $isJsonArray = str_starts_with(trim($r['body']), '[');
    record(
        'menu.php z tokenem + varSymbol → 200 + JSON array',
        $r['code'] === 200 && $isJsonArray,
        "HTTP {$r['code']}, body[0:30]=" . substr($r['body'], 0, 30)
    );

    // 9. areas.php z tokenem + varSymbol — oczekiwane 200 + JSON array
    $r = httpCall($baseUrl . '/areas.php?t=' . urlencode($webhookTok) . '&varSymbol=' . urlencode($varSymbol), 'GET');
    $isJsonArray = str_starts_with(trim($r['body']), '[');
    record(
        'areas.php z tokenem + varSymbol → 200 + JSON array',
        $r['code'] === 200 && $isJsonArray,
        "HTTP {$r['code']}, body[0:30]=" . substr($r['body'], 0, 30)
    );

    // 10. webhook.php z tokenem + mock order — oczekiwane 200 empty body
    $mockOrder = json_encode([
        'order' => [
            '_id' => 'smoketest_' . time(),
            'type' => 'delivery',
            'subTotal' => 1000,
            'total' => 1200,
            'delivery' => ['cost' => 200, 'customer' => ['name' => 'Test', 'phone' => '+48123456789']],
        ],
        'varSymbol' => $varSymbol,
    ]);
    $r = httpCall($baseUrl . '/webhook.php?t=' . urlencode($webhookTok), 'POST', $mockOrder);
    // Z PLACEHOLDER varSymbol → 403 (no tenant integration). Po OAuth → 200 empty body.
    record(
        'webhook.php z tokenem + mock → 200/403',
        in_array($r['code'], [200, 403], true),
        "HTTP {$r['code']}, body_len=" . strlen($r['body'])
    );
}

// =============================================================================
// PODSUMOWANIE
// =============================================================================
echo "\n========================================\n";
echo sprintf("PASS: %d / FAIL: %d / TOTAL: %d\n", $pass, $fail, $pass + $fail);
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
