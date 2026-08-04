<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR OAuth Callback
// GET /api/integrations/choiceqr/oauth_callback.php?code=XXXX&state=...
//
// ChoiceQR po OAuth consent redirectuje tu z ?code=XXXX (authorization code).
// Ten endpoint:
//   1. Wymaga ?code + ?client_id + ?client_secret (przekazane w state lub env)
//   2. Wymienia code na JWT token przez POST https://open-api.choiceqr.com/auth/connect/token
//   3. Zapisuje {token, var_symbol, webhook_token} do sh_tenant_integrations
//      (credentials zaszyfrowane przez CredentialVault)
//   4. Jeśli wiersz choiceqr nie istnieje — tworzy go (provider=choiceqr, direction=bidirectional)
//   5. Zwraca HTML z podsumowaniem (sukces / błąd) — to nie API, to landing page po OAuth
//
// Bezpieczeństwo:
//   • client_secret NIE jest hardcodowany — przekazywany przez ?client_secret=... w URL
//     (OAuth flow jest jednorazowy, secret jest w URL tylko na czas wymiany code→token)
//   • Albo przez env SLICEHUB_CHOICEQR_CLIENT_ID / SLICEHUB_CHOICEQR_CLIENT_SECRET
//   • state (opcjonalny) — CSRF protection, sprawdzany tylko gdy ustawiony w env
//
// Dokumentacja: https://open-api.choiceqr.com/docs/content/authorization.md
// =============================================================================

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// -----------------------------------------------------------------------------
// HTML helper — prosty landing page po OAuth
// -----------------------------------------------------------------------------
function cqr_oauth_render(string $title, string $message, bool $ok = false, ?array $details = null): never
{
    $bg       = $ok ? '#e6f4ea' : '#fce8e6';
    $color    = $ok ? '#137333' : '#c5221f';
    $icon     = $ok ? '✓' : '✗';
    $detailsHtml = '';
    if (is_array($details) && !empty($details)) {
        $rows = '';
        foreach ($details as $k => $v) {
            $escK = htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8');
            $escV = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            // Maskuj tokeny w wartościach (pokaż tylko prefix)
            if (preg_match('/token|secret|key/i', $k) && strlen($escV) > 20) {
                $escV = substr($escV, 0, 12) . '…' . substr($escV, -4) . ' (ukryto)';
            }
            $rows .= "<tr><td><b>{$escK}</b></td><td><code>{$escV}</code></td></tr>";
        }
        $detailsHtml = "<h3>Szczegóły</h3><table style=\"border-collapse:collapse;margin-top:8px\">{$rows}</table>";
    }
    http_response_code($ok ? 200 : 400);
    echo <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>ChoiceQR OAuth — SliceHub</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;color:#202124}
.box{padding:24px;border-radius:8px;background:{$bg};border:1px solid {$color};}
h1{color:{$color};margin-top:0;font-size:22px}
h3{margin-top:20px;margin-bottom:4px;font-size:14px;color:#5f6368}
table td{padding:6px 12px;border:1px solid #ddd;font-size:13px}
table td:first-child{background:#f8f9fa}
code{font-family:'SF Mono',Consolas,monospace;font-size:12px;word-break:break-all}
a{color:#1a73e8}
</style>
</head>
<body>
<div class="box">
<h1>{$icon} {$title}</h1>
<p>{$message}</p>
{$detailsHtml}
</div>
<p style="margin-top:24px;color:#5f6368;font-size:12px">
SliceHub Enterprise — ChoiceQR OAuth Callback · <a href="/slicehub/modules/settings/">Wróć do Settings</a>
</p>
</body>
</html>
HTML;
    exit;
}

// =============================================================================
// MAIN FLOW
// =============================================================================
try {
    require_once __DIR__ . '/../../../core/db_config.php';
    require_once __DIR__ . '/../../../core/CredentialVault.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        cqr_oauth_render('Błąd konfiguracji', 'Brak połączenia z bazą danych.', false);
    }

    // -------------------------------------------------------------------------
    // 1. PARAMS — code, client_id, client_secret (z URL lub env)
    // -------------------------------------------------------------------------
    $code         = trim((string)($_GET['code'] ?? ''));
    $clientId     = trim((string)($_GET['client_id']     ?? (string)(getenv('SLICEHUB_CHOICEQR_CLIENT_ID')     ?: '')));
    $clientSecret = trim((string)($_GET['client_secret'] ?? (string)(getenv('SLICEHUB_CHOICEQR_CLIENT_SECRET') ?: '')));
    $state        = trim((string)($_GET['state'] ?? ''));
    $tenantId     = (int)($_GET['tenant_id'] ?? (int)(getenv('SLICEHUB_TENANT_ID') ?: 0));

    // Tenant fallback z auto-discovery (jak tenant_config.php)
    if ($tenantId <= 0) {
        try {
            $row = $pdo->query("
                SELECT t.id FROM sh_tenant t
                WHERE EXISTS (
                    SELECT 1 FROM sh_users u
                    WHERE u.tenant_id = t.id AND u.status = 'active' AND u.is_deleted = 0
                )
                ORDER BY t.id ASC LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['id'] > 0) $tenantId = (int)$row['id'];
        } catch (Throwable $e) {}
    }
    if ($tenantId <= 0) $tenantId = 1;

    // Opcjonalny CSRF state check (gdy admin ustawił SLICEHUB_CHOICEQR_OAUTH_STATE)
    $expectedState = (string)(getenv('SLICEHUB_CHOICEQR_OAUTH_STATE') ?: '');
    if ($expectedState !== '' && $state !== $expectedState) {
        cqr_oauth_render('Błąd CSRF', 'State mismatch — OAuth flow odrzucony (możliwy CSRF).', false);
    }

    if ($code === '') {
        cqr_oauth_render('Brak kodu', 'ChoiceQR nie przekazał <code>code</code> w URL. Spróbuj ponownie "Connect" w panelu ChoiceQR.', false);
    }
    if ($clientId === '' || $clientSecret === '') {
        cqr_oauth_render(
            'Brak clientId/secret',
            'Przekaż <code>?client_id=...&client_secret=...</code> w URL (razem z <code>code</code> z ChoiceQR) albo ustaw env <code>SLICEHUB_CHOICEQR_CLIENT_ID</code> + <code>SLICEHUB_CHOICEQR_CLIENT_SECRET</code>.',
            false
        );
    }

    // -------------------------------------------------------------------------
    // 2. EXCHANGE code → token (POST https://open-api.choiceqr.com/auth/connect/token)
    // -------------------------------------------------------------------------
    $tokenUrl = 'https://open-api.choiceqr.com/auth/connect/token';
    $payload = json_encode([
        'code'         => $code,
        'clientId'     => $clientId,
        'secret'       => $clientSecret,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($tokenUrl);
    if ($ch === false) {
        cqr_oauth_render('Błąd cURL', 'Nie udało się zainicjować żądania do ChoiceQR.', false);
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SliceHub-ChoiceQR-OAuth/1.0',
        ],
    ]);
    $response   = (string)curl_exec($ch);
    $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '' && $response === '') {
        cqr_oauth_render('Błąd transportu', 'cURL: ' . htmlspecialchars($curlErr, ENT_QUOTES, 'UTF-8'), false);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        cqr_oauth_render(
            'ChoiceQR odrzucił wymianę',
            "HTTP {$httpCode} z <code>https://open-api.choiceqr.com/auth/connect/token</code>. Body: <code>"
            . htmlspecialchars(substr($response, 0, 500), ENT_QUOTES, 'UTF-8') . '</code>',
            false
        );
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        cqr_oauth_render('Niepoprawna odpowiedź', 'ChoiceQR zwrócił nie-JSON: <code>' . htmlspecialchars(substr($response, 0, 200), ENT_QUOTES, 'UTF-8') . '</code>', false);
    }

    $token     = trim((string)($decoded['token']     ?? ''));
    $varSymbol = trim((string)($decoded['varSymbol'] ?? ''));
    $domain    = trim((string)($decoded['domain']    ?? ''));

    if ($token === '' || $varSymbol === '') {
        cqr_oauth_render(
            'Brak tokenu/varSymbol',
            'ChoiceQR zwrócił: <code>' . htmlspecialchars($response, ENT_QUOTES, 'UTF-8') . '</code>',
            false
        );
    }

    // -------------------------------------------------------------------------
    // 3. ZAPIS/UPDATE sh_tenant_integrations
    // -------------------------------------------------------------------------
    // Webhook token — z env (gdy admin wygenerował) albo generujemy nowy i zapisujemy.
    // Jeśli aktualizujemy istniejący wiersz — zachowujemy jego webhook_token.
    $existingStmt = $pdo->prepare(
        "SELECT id, credentials FROM sh_tenant_integrations
         WHERE tenant_id = :tid AND provider = 'choiceqr' LIMIT 1"
    );
    $existingStmt->execute([':tid' => $tenantId]);
    $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $webhookToken = '';
    if ($existingRow) {
        // Odczytaj istniejący webhook_token z credentials
        $existingCredsRaw = (string)($existingRow['credentials'] ?? '');
        $existingCredsJson = CredentialVault::isEncrypted($existingCredsRaw)
            ? (CredentialVault::decrypt($existingCredsRaw) ?? '')
            : $existingCredsRaw;
        $existingCreds = json_decode($existingCredsJson, true) ?: [];
        $webhookToken = (string)($existingCreds['webhook_token'] ?? '');
    }
    if ($webhookToken === '') {
        // Sprawdź env (admin może ustawić własny)
        $webhookToken = (string)(getenv('SLICEHUB_CHOICEQR_WEBHOOK_TOKEN') ?: '');
    }
    if ($webhookToken === '') {
        // Wygeneruj nowy (32 bytes hex = 64 znaki)
        $webhookToken = bin2hex(random_bytes(32));
    }

    $credsArray = [
        'token'         => $token,
        'webhook_token' => $webhookToken,
        'var_symbol'    => $varSymbol,
    ];
    if ($domain !== '') $credsArray['domain'] = $domain;

    $credsJson = json_encode($credsArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $credsStored = CredentialVault::encryptSoft($credsJson);

    $eventsBridged = json_encode([
        'order.cancelled',
        'order.ready',
        'order.delivered',
        'order.completed',
        'order.dispatched',
        'order.in_delivery',
    ], JSON_UNESCAPED_UNICODE);

    $apiBaseUrl = 'https://open-api.choiceqr.com';

    if ($existingRow) {
        // UPDATE istniejącego wiersza
        $updateStmt = $pdo->prepare(
            "UPDATE sh_tenant_integrations
             SET display_name = :name,
                 api_base_url = :url,
                 credentials  = :creds,
                 direction    = 'bidirectional',
                 events_bridged = :evts,
                 is_active    = 1,
                 consecutive_failures = 0,
                 last_sync_at = NOW(),
                 updated_at   = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $updateStmt->execute([
            ':name'  => 'ChoiceQR POS',
            ':url'   => $apiBaseUrl,
            ':creds' => $credsStored,
            ':evts'  => $eventsBridged,
            ':id'    => (int)$existingRow['id'],
            ':tid'   => $tenantId,
        ]);
        $integrationId = (int)$existingRow['id'];
    } else {
        // INSERT nowego wiersza
        $insertStmt = $pdo->prepare(
            "INSERT INTO sh_tenant_integrations
                (tenant_id, provider, display_name, api_base_url, credentials,
                 direction, events_bridged, is_active, last_sync_at, created_at, updated_at)
             VALUES
                (:tid, 'choiceqr', :name, :url, :creds,
                 'bidirectional', :evts, 1, NOW(), NOW(), NOW())"
        );
        $insertStmt->execute([
            ':tid'   => $tenantId,
            ':name'  => 'ChoiceQR POS',
            ':url'   => $apiBaseUrl,
            ':creds' => $credsStored,
            ':evts'  => $eventsBridged,
        ]);
        $integrationId = (int)$pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // 4. SUCCESS — landing page
    // -------------------------------------------------------------------------
    cqr_oauth_render(
        'ChoiceQR połączone!',
        'Integracja ChoiceQR została skonfigurowana. JWT token (ważny 5 lat) i varSymbol zapisane w bazie (zaszyfrowane vaultem).',
        true,
        [
            'tenant_id'      => (string)$tenantId,
            'integration_id' => (string)$integrationId,
            'var_symbol'     => $varSymbol,
            'domain'         => $domain !== '' ? $domain : '(brak)',
            'webhook_token'  => $webhookToken,
            'token_prefix'   => substr($token, 0, 12) . '…',
            'api_base_url'   => $apiBaseUrl,
            'events_bridged' => 'order.cancelled, order.ready, order.delivered, order.completed, order.dispatched, order.in_delivery',
        ]
    );

} catch (Throwable $e) {
    error_log('[ChoiceQR OAuth] FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    cqr_oauth_render(
        'Błąd krytyczny',
        'Wystąpił nieoczekiwany błąd: <code>'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</code>. Sprawdź <code>error_log</code> Apache.',
        false
    );
}
