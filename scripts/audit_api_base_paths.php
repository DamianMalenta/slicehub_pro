<?php
/**
 * Audyt API Base Paths (SSOT sh_api_base.js) — CLI + opcjonalny HTTP smoke.
 *
 * Uruchomienie:
 *   C:\xampp\php\php.exe scripts/audit_api_base_paths.php
 *   C:\xampp\php\php.exe scripts/audit_api_base_paths.php --http
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$runHttp = in_array('--http', $argv ?? [], true);
$verbose = in_array('-v', $argv ?? [], true) || in_array('--verbose', $argv ?? [], true);
$baseUrl = 'http://localhost/slicehub';

$results = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'lines' => []];

function out(string $level, string $msg): void
{
    global $results, $verbose;
    $results['lines'][] = [$level, $msg];
    if ($level === 'PASS') {
        $results['pass']++;
    } elseif ($level === 'FAIL') {
        $results['fail']++;
    } elseif ($level === 'WARN') {
        $results['warn']++;
    }
    if ($verbose || $level === 'FAIL' || $level === 'WARN') {
        $prefix = match ($level) {
            'PASS' => '  ✓',
            'FAIL' => '  ✗',
            'WARN' => '  ⚠',
            default => '  ·',
        };
        echo "$prefix $msg\n";
    }
}

function check(bool $ok, string $label, string $detail = ''): void
{
    out($ok ? 'PASS' : 'FAIL', $label . ($detail !== '' ? " — $detail" : ''));
}

function warn(string $label, string $detail = ''): void
{
    out('WARN', $label . ($detail !== '' ? " — $detail" : ''));
}

function info(string $msg): void
{
    out('INFO', $msg);
}

/** Port logiki getApiBase() z core/js/sh_api_base.js (bez DOM). */
function simulateGetApiBase(string $pathname, ?string $metaBase = null, ?string $envBase = null): string
{
    if ($metaBase !== null && trim($metaBase) !== '') {
        return rtrim(trim($metaBase), '/');
    }
    if ($envBase !== null && trim($envBase) !== '') {
        return rtrim(trim($envBase), '/');
    }
    $path = $pathname;
    $marker = '/modules/';
    $idx = strpos($path, $marker);
    if ($idx !== false && $idx > 0) {
        return substr($path, 0, $idx) . '/api';
    }
    if ($idx === 0) {
        return '/api';
    }
    if (preg_match('#^/([^/]+)(?:/|$)#', $path, $m) && $m[1] !== '' && $m[1] !== 'api') {
        return '/' . $m[1] . '/api';
    }
    $hasSlicehub = str_contains($path, '/slicehub/')
        || $path === '/slicehub'
        || str_starts_with($path, '/slicehub');
    return $hasSlicehub ? '/slicehub/api' : '/api';
}

function simulateGetAppBase(string $apiBase, string $pathname): string
{
    if (str_ends_with($apiBase, '/api')) {
        $app = substr($apiBase, 0, -4);
        return $app === '/' ? '' : $app;
    }
    $hasSlicehub = str_contains($pathname, '/slicehub/')
        || $pathname === '/slicehub'
        || str_starts_with($pathname, '/slicehub');
    return $hasSlicehub ? '/slicehub' : '';
}

function scanDirRecursive(string $dir, string $ext, callable $cb): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) !== ltrim($ext, '.')) {
            continue;
        }
        $cb($file->getPathname());
    }
}

function httpJson(string $url, ?array $payload = null, string $token = ''): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'error' => 'curl missing', 'json' => []];
    }
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return [
        'ok' => $raw !== false && $http > 0,
        'http' => $http,
        'error' => $err,
        'json' => is_array($json) ? $json : [],
        'raw' => (string)$raw,
    ];
}

echo "SLICEHUB — API Base Paths Audit\n";
echo str_repeat('=', 60) . "\n\n";

// ─── 1. SSOT file exists ───────────────────────────────────────────────────
$ssot = $root . '/core/js/sh_api_base.js';
check(is_file($ssot), 'SSOT file exists', 'core/js/sh_api_base.js');
$ssotSrc = is_file($ssot) ? file_get_contents($ssot) : '';
check(
    str_contains($ssotSrc, 'getApiBase') && str_contains($ssotSrc, 'apiUrl'),
    'SSOT exports getApiBase + apiUrl'
);

// ─── 2. Hardcoded /slicehub/api in modules ─────────────────────────────────
$hardcodedApi = [];
scanDirRecursive($root . '/modules', 'js', function (string $path) use (&$hardcodedApi) {
    $rel = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (str_contains($line, '/slicehub/api')) {
            $hardcodedApi[] = "$rel:" . ($i + 1);
        }
    }
});
scanDirRecursive($root . '/modules', 'html', function (string $path) use (&$hardcodedApi) {
    $rel = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (str_contains($line, '/slicehub/api')) {
            $hardcodedApi[] = "$rel:" . ($i + 1);
        }
    }
});
check(count($hardcodedApi) === 0, 'No /slicehub/api in modules/**', count($hardcodedApi) ? implode(', ', $hardcodedApi) : '0 hits');

// ─── 3. HTML inventory ───────────────────────────────────────────────────────
$knownNoApi = [
    'modules/warehouse/index.html',
    'modules/pos/offline.html',
    'modules/online/offline.html',
];
$knownExceptions = [
    'modules/online_studio/index.html' => 'sh_api_base without tenant_config (JWT/session)',
];

$htmlFiles = [];
$htmlIt = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/modules', FilesystemIterator::SKIP_DOTS)
);
foreach ($htmlIt as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
        $htmlFiles[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
sort($htmlFiles);

$missingSsot = [];
$missingTenant = [];
$inlineApiWithoutSsot = [];

foreach ($htmlFiles as $rel) {
    if (in_array($rel, $knownNoApi, true)) {
        continue;
    }
    $full = $root . '/' . $rel;
    $html = file_get_contents($full);
    $hasSsot = str_contains($html, 'sh_api_base.js');
    $hasTenant = str_contains($html, 'tenant_config.php');
    $hasApiInline = (bool)preg_match('/fetch\s*\(|ApiClient\.|apiUrl\s*\(/', $html);

    if (!$hasSsot && $rel !== 'modules/warehouse/index.html') {
        $missingSsot[] = $rel;
    }
    if (!$hasTenant && !isset($knownExceptions[$rel]) && $hasSsot) {
        $missingTenant[] = $rel;
    }
    if ($hasApiInline && !$hasSsot) {
        $inlineApiWithoutSsot[] = $rel;
    }
}

check(count($missingSsot) === 0, 'All module HTML (except hub/offline) load sh_api_base.js', implode(', ', $missingSsot) ?: 'ok');
if (count($missingTenant) > 0) {
    foreach ($missingTenant as $m) {
        warn("No tenant_config.php", $m . (isset($knownExceptions[$m]) ? ' (known)' : ''));
    }
} else {
    check(true, 'tenant_config.php present where expected');
}
check(count($inlineApiWithoutSsot) === 0, 'Inline API HTML always paired with sh_api_base.js', implode(', ', $inlineApiWithoutSsot) ?: 'ok');

// ─── 4. getApiBase() simulation ──────────────────────────────────────────────
info('getApiBase() path simulation:');
$cases = [
    ['/slicehub/modules/pos/index.html', null, null, '/slicehub/api'],
    ['/modules/pos/index.html', null, null, '/api'],
    ['/slicehub/modules/online/track.html', null, null, '/slicehub/api'],
    ['/modules/settings/index.html', null, null, '/api'],
    ['/slicehub/', null, null, '/slicehub/api'],
    ['/api/auth/login.php', null, null, '/api'],
    ['/slicehub/foo/bar', null, null, '/slicehub/api'],
    ['/custom/modules/x/', null, null, '/custom/api'],
    ['/modules/x/', '/forced/api', null, '/forced/api'],
    ['/modules/x/', null, '/env/api', '/env/api'],
];
foreach ($cases as [$path, $meta, $env, $expected]) {
    $got = simulateGetApiBase($path, $meta, $env);
    check($got === $expected, "getApiBase('$path')", "expected $expected, got $got");
}

// ─── 5. getAppBase() simulation ────────────────────────────────────────────
info('getAppBase() path simulation:');
$appCases = [
    ['/slicehub/api', '/slicehub/modules/pos/', '/slicehub'],
    ['/api', '/modules/pos/', ''],
];
foreach ($appCases as [$apiBase, $path, $expected]) {
    $got = simulateGetAppBase($apiBase, $path);
    check($got === $expected, "getAppBase(api=$apiBase)", "expected '$expected', got '$got'");
}

// ─── 6. Studio ../../api/ + api_client resolve contract ────────────────────
$apiClient = $root . '/core/js/api_client.js';
check(is_file($apiClient), 'api_client.js exists');
$acSrc = is_file($apiClient) ? file_get_contents($apiClient) : '';
check(str_contains($acSrc, 'resolveEndpoint') && str_contains($acSrc, 'sh.apiUrl'), 'api_client delegates to SliceHub.apiUrl');

$studioRelApi = 0;
scanDirRecursive($root . '/modules/studio/js', 'js', function (string $path) use (&$studioRelApi) {
    if (preg_match_all('#\.\./\.\./api/#', file_get_contents($path), $m)) {
        $studioRelApi += count($m[0]);
    }
});
check($studioRelApi > 0, 'Studio retains ../../api/ literals (resolved by api_client)', "$studioRelApi occurrences");

// ─── 7. Fallback /slicehub/ when SliceHub missing (documented exceptions) ──
$fallbackHits = [];
$allowedFallbackFiles = [
    'modules/settings/js/settings_app.js',
    'modules/settings/js/notifications.js',
    'modules/backoffice/hr/js/hr_app.js',
    'modules/online/js/online_app.js',
    'modules/online/js/online_checkout.js',
    'modules/online_studio/js/tabs/surface.js',
];
scanDirRecursive($root . '/modules', 'js', function (string $path) use (&$fallbackHits, $root, $allowedFallbackFiles) {
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $src = file_get_contents($path);
    if (preg_match("/['\"`]\\/slicehub\\//", $src) && !in_array($rel, $allowedFallbackFiles, true)) {
        if (!str_contains($rel, '/sw.js') && !str_contains($rel, 'pos_sw_register')) {
            $fallbackHits[] = $rel;
        }
    }
});
if (count($fallbackHits) > 0) {
    foreach ($fallbackHits as $h) {
        warn('Unexpected /slicehub/ fallback in JS', $h);
    }
} else {
    check(true, 'No unexpected /slicehub/ fallbacks outside known files');
}

// ─── 8. test_runner test count ─────────────────────────────────────────────
$runner = $root . '/tests/test_runner.html';
$runnerSrc = is_file($runner) ? file_get_contents($runner) : '';
preg_match_all("/id:\s*'T\d+'/", $runnerSrc, $tids);
$testCount = count($tids[0]);
check($testCount === 62, 'test_runner defines 62 tests', "found $testCount");

// ─── 9. tenant_config.php structure (static read — no include/DB) ─────────
$tcFile = $root . '/tenant_config.php';
check(is_file($tcFile), 'tenant_config.php exists');
$tcPhp = is_file($tcFile) ? file_get_contents($tcFile) : '';
check(str_contains($tcPhp, '__SH_TENANT_ID__'), 'tenant_config source emits __SH_TENANT_ID__');
check(str_contains($tcPhp, 'SLICEHUB_API_BASE'), 'tenant_config reads SLICEHUB_API_BASE env');
check(str_contains($tcPhp, '__SH_API_BASE__'), 'tenant_config can emit __SH_API_BASE__');
check(str_contains($tcPhp, 'sh-tenant-id'), 'tenant_config updates meta sh-tenant-id');
check(str_contains($tcPhp, "get('tenant')"), 'tenant_config supports ?tenant=N override');
check(str_contains($tcPhp, 'sh_users'), 'tenant_config discovers tenant via active sh_users');

// ─── 10. HTTP smoke (optional) ─────────────────────────────────────────────
if ($runHttp) {
    echo "\n" . str_repeat('-', 60) . "\nHTTP smoke ($baseUrl)\n" . str_repeat('-', 60) . "\n";

    $tcHttp = httpJson($baseUrl . '/tenant_config.php');
    check($tcHttp['ok'] && $tcHttp['http'] === 200, 'GET tenant_config.php', 'HTTP ' . $tcHttp['http']);

    $login = null;
    $managerLogin = null;
    $pins = ['1111', '0000', '2222', '3333', '4444', '5555', '6666'];
    for ($tid = 1; $tid <= 10; $tid++) {
        foreach ($pins as $pin) {
            $r = httpJson($baseUrl . '/api/auth/login.php', [
                'mode' => 'kiosk',
                'tenant_id' => $tid,
                'pin_code' => $pin,
            ]);
            if (($r['json']['success'] ?? false) && !empty($r['json']['data']['token'])) {
                if ($login === null) {
                    $login = ['tid' => $tid, 'pin' => $pin, 'token' => $r['json']['data']['token']];
                }
                if ($pin === '0000' && $managerLogin === null) {
                    $managerLogin = ['tid' => $tid, 'pin' => $pin, 'token' => $r['json']['data']['token']];
                }
            }
        }
    }
    check($login !== null, 'PIN auto-discovery (test_runner pattern)', $login ? "tenant={$login['tid']} pin={$login['pin']}" : 'no PIN worked');

    // Wrong path must 404 — proves we hit the right API mount
    $bad = httpJson($baseUrl . '/slicehub/slicehub/api/auth/login.php', ['mode' => 'kiosk', 'tenant_id' => 1, 'pin_code' => '1111']);
    check($bad['http'] === 404 || $bad['http'] === 0, 'Wrong API path not reachable', 'HTTP ' . $bad['http']);

    if ($login) {
        $token = $login['token'];
        $mgrToken = $managerLogin['token'] ?? $token;

        /** Path smoke: HTTP 200 + JSON body (success=false OK — to nie test RBAC). */
        $pathSmoke = function (string $label, string $method, string $path, ?array $payload, string $tok) use ($baseUrl): void {
            $url = $baseUrl . $path;
            $r = $method === 'GET' ? httpJson($url, null, $tok) : httpJson($url, $payload, $tok);
            $isJson = is_array($r['json']) && array_key_exists('success', $r['json']);
            check($r['http'] === 200 && $isJson, $label, 'HTTP ' . $r['http'] . ' json=' . ($isJson ? 'yes' : 'no'));
        };

        $pathSmoke('API path POST /api/pos/engine.php', 'POST', '/api/pos/engine.php', ['action' => 'get_init_data'], $token);
        $pathSmoke('API path POST /api/kds/engine.php', 'POST', '/api/kds/engine.php', ['action' => 'get_board'], $token);
        $pathSmoke('API path POST /api/settings/engine.php', 'POST', '/api/settings/engine.php', ['action' => 'health_summary'], $mgrToken);
        $pathSmoke('API path POST /api/backoffice/hr/engine.php', 'POST', '/api/backoffice/hr/engine.php', ['action' => 'employees_list'], $mgrToken);
        $pathSmoke('API path GET /api/warehouse/stock_list.php', 'GET', '/api/warehouse/stock_list.php?warehouse_id=MAIN', null, $token);
    } else {
        warn('Skipped API endpoint smoke — no auth token');
    }

    $sampleModules = [
        '/modules/pos/index.html',
        '/modules/settings/index.html',
        '/modules/marketing/index.html',
        '/modules/backoffice/hr/index.html',
    ];
    foreach ($sampleModules as $modPath) {
        $url = $baseUrl . $modPath;
        if (!function_exists('curl_init')) {
            break;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $hasSsot = is_string($body) && str_contains($body, 'sh_api_base.js');
        check($http === 200 && $hasSsot, "Module HTML serves sh_api_base ref: $modPath", "HTTP $http ssot=" . ($hasSsot ? 'yes' : 'no'));
    }
} else {
    info('HTTP smoke skipped — run with --http for live XAMPP checks');
}

// ─── Summary ───────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "PASS: {$results['pass']}  FAIL: {$results['fail']}  WARN: {$results['warn']}\n";
if ($results['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($results['lines'] as [$lvl, $msg]) {
        if ($lvl === 'FAIL') {
            echo "  ✗ $msg\n";
        }
    }
    exit(1);
}
echo "All critical checks passed.\n";
exit(0);
