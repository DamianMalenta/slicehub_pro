<?php
// =============================================================================
// SliceHub Enterprise — ChoiceQR Areas Export (Get Areas URL)
// GET /api/integrations/choiceqr/areas.php?t=SECRET_TOKEN&varSymbol=ID
//
// ChoiceQR wywołuje ten endpoint GET aby pobrać strefy/stoliki z SliceHub.
// Zwraca array obiektów: { posID, name }
//
// Strefy pochodzą z sh_zones (sala/ogródek/bar/VIP), stoliki z sh_tables.
// Dodatkowo dodawane są wirtualne strefy: Odbiór własny, Dostawa.
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

function cqr_areas_error(int $code, string $msg): never
{
    http_response_code($code);
    error_log('[ChoiceQR Areas] ' . $code . ' — ' . $msg);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    require_once __DIR__ . '/../../../core/db_config.php';

    if (!isset($pdo)) {
        cqr_areas_error(500, 'Database connection unavailable');
    }

    // -------------------------------------------------------------------------
    // 1. AUTH + TENANT MAPPING (shared pattern with menu.php / webhook.php)
    // -------------------------------------------------------------------------
    $providedToken = trim((string)($_GET['t'] ?? ''));
    $varSymbol = trim((string)($_GET['varSymbol'] ?? ''));

    if ($providedToken === '') {
        cqr_areas_error(401, 'Missing token');
    }
    if ($varSymbol === '') {
        cqr_areas_error(400, 'Missing varSymbol');
    }

    $stmtTi = $pdo->prepare(
        "SELECT id, tenant_id, credentials, is_active
         FROM sh_tenant_integrations
         WHERE provider = 'choiceqr' AND is_active = 1"
    );
    $stmtTi->execute();
    $integrations = $stmtTi->fetchAll(PDO::FETCH_ASSOC);

    $tenantId = 0;
    $webhookToken = null;

    foreach ($integrations as $ti) {
        $creds = json_decode((string)$ti['credentials'], true);
        if (!is_array($creds)) {
            $creds = [];
        }
        if ((string)($creds['var_symbol'] ?? '') === $varSymbol) {
            $tenantId = (int)$ti['tenant_id'];
            $webhookToken = (string)($creds['webhook_token'] ?? '');
            break;
        }
    }

    if ($tenantId <= 0) {
        cqr_areas_error(403, "No tenant integration for varSymbol='{$varSymbol}'");
    }
    if ($webhookToken === '' || !hash_equals($webhookToken, $providedToken)) {
        cqr_areas_error(401, 'Invalid token');
    }

    // -------------------------------------------------------------------------
    // 2. LOAD ZONES + TABLES
    // -------------------------------------------------------------------------
    $output = [];

    // Zones (sh_zones — migration 037)
    $hasZones = false;
    try {
        $pdo->query("SELECT 1 FROM sh_zones LIMIT 0");
        $hasZones = true;
    } catch (PDOException $e) {
        $hasZones = false;
    }

    if ($hasZones) {
        $stmtZones = $pdo->prepare(
            "SELECT id, name, is_active
             FROM sh_zones
             WHERE tenant_id = :tid AND is_active = 1
             ORDER BY display_order, id"
        );
        $stmtZones->execute([':tid' => $tenantId]);
        $zones = $stmtZones->fetchAll(PDO::FETCH_ASSOC);

        foreach ($zones as $zone) {
            $output[] = [
                'posID' => 'zone_' . $zone['id'],
                'name'  => $zone['name'],
            ];
        }

        // Tables (sh_tables — migration 037)
        $hasTables = false;
        try {
            $pdo->query("SELECT 1 FROM sh_tables LIMIT 0");
            $hasTables = true;
        } catch (PDOException $e) {
            $hasTables = false;
        }

        if ($hasTables) {
            $stmtTables = $pdo->prepare(
                "SELECT id, table_number, zone_id, is_active
                 FROM sh_tables
                 WHERE tenant_id = :tid AND is_active = 1
                 ORDER BY zone_id, table_number"
            );
            $stmtTables->execute([':tid' => $tenantId]);
            foreach ($stmtTables->fetchAll(PDO::FETCH_ASSOC) as $table) {
                $output[] = [
                    'posID' => 'table_' . $table['id'],
                    'name'  => 'Stolik ' . $table['table_number'],
                ];
            }
        }
    }

    // -------------------------------------------------------------------------
    // 3. VIRTUAL AREAS — takeaway + delivery (zawsze dostępne)
    // -------------------------------------------------------------------------
    $output[] = [
        'posID' => 'takeaway',
        'name'  => 'Odbiór własny',
    ];
    $output[] = [
        'posID' => 'delivery',
        'name'  => 'Dostawa',
    ];

    // -------------------------------------------------------------------------
    // 4. OUTPUT
    // -------------------------------------------------------------------------
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[ChoiceQR Areas] FATAL: ' . $e->getMessage());
    cqr_areas_error(500, 'Internal server error');
}
