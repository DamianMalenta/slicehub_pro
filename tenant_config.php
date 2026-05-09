<?php
/**
 * Globalny konfig tenant — serwowany jako JavaScript.
 * Wczytaj jako: <script src="/tenant_config.php"></script>
 *
 * Na hostingu ustaw zmienną środowiskową SLICEHUB_TENANT_ID=3 (Plesk → PHP Settings).
 * Na XAMPP (localhost) nie ustawiaj nic — fallback do 1.
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$tenantId = (int)(getenv('SLICEHUB_TENANT_ID') ?: 1);

// Nadpisuje meta tag sh-tenant-id zanim uruchomi się jakikolwiek app JS.
// Udostępnia też globalną zmienną window.__SH_TENANT_ID__ jako backup.
echo "(function(){\n";
echo "  var tid = $tenantId;\n";
echo "  var m = document.querySelector('meta[name=\"sh-tenant-id\"]');\n";
echo "  if (m) m.content = String(tid);\n";
echo "  window.__SH_TENANT_ID__ = tid;\n";
echo "})();\n";
