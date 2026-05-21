<?php
/**
 * Globalny konfig tenant — serwowany jako JavaScript.
 * Wczytaj jako: <script src="/tenant_config.php"></script>
 *
 * Strategia rozwiązywania tenant_id (w kolejności):
 *   1. Env var SLICEHUB_TENANT_ID (Plesk → PHP Settings lub SetEnv w .htaccess)
 *   2. Sesja PHP — jeśli user się zalogował (loginSystem zapisuje tenant_id do sesji)
 *   3. Auto-discovery z bazy — pierwszy aktywny tenant z sh_tenant (działa out-of-the-box
 *      dla typowej instalacji single-tenant per hosting)
 *   4. Fallback do 1 (XAMPP / dev / pusty schema)
 *
 * Hostowanie multi-tenant na jednej domenie: ustaw SLICEHUB_TENANT_ID per vhost.
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$tenantId = 0;

// 1. Env var (najwyzszy priorytet — pozwala forsowac konkretnego tenanta)
$envTid = (int)(getenv('SLICEHUB_TENANT_ID') ?: 0);
if ($envTid > 0) {
    $tenantId = $envTid;
}

// 2. Sesja zalogowanego usera (login backoffice = pewny tenant)
if ($tenantId <= 0) {
    if (session_status() === PHP_SESSION_NONE) {
        // Cookie params jak w login.php, zeby sesja byla wspoldzielona.
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        @session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        @session_start();
    }
    $sessTid = (int)($_SESSION['tenant_id'] ?? 0);
    if ($sessTid > 0) {
        $tenantId = $sessTid;
    }
}

// 3. Auto-discovery z bazy (typowo: jeden tenant na hosting)
if ($tenantId <= 0) {
    try {
        require_once __DIR__ . '/core/db_config.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $row = $pdo->query("
                SELECT id FROM sh_tenant
                WHERE COALESCE(is_deleted, 0) = 0
                ORDER BY id ASC
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['id'] > 0) {
                $tenantId = (int)$row['id'];
            }
        }
    } catch (Throwable $e) {
        // Cisza — fallback do 1.
    }
}

// 4. Fallback
if ($tenantId <= 0) {
    $tenantId = 1;
}

// Nadpisuje meta tag sh-tenant-id zanim uruchomi się jakikolwiek app JS.
// Udostepnia tez globalna zmienna window.__SH_TENANT_ID__ jako backup.
// SLICEHUB_API_BASE (env) → window.__SH_API_BASE__ (nadpisuje heurystykę pathname).
$envApiBase = trim((string)(getenv('SLICEHUB_API_BASE') ?: ''));
echo "(function(){\n";
echo "  var tid = $tenantId;\n";
echo "  var m = document.querySelector('meta[name=\"sh-tenant-id\"]');\n";
echo "  if (m) m.content = String(tid);\n";
echo "  window.__SH_TENANT_ID__ = tid;\n";
if ($envApiBase !== '') {
    echo '  window.__SH_API_BASE__ = ' . json_encode($envApiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
}
echo "})();\n";
