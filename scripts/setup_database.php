<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Database Setup (Schema Migrations Only)
//
// Run via browser: http://localhost/slicehub/scripts/setup_database.php
//
// Ten plik łączy: (A) wbudowane KOPIE migracji 006/007/008, (B) import wybranych
// plików SQL z database/migrations/, (C) fragmenty 016/017/022 w PHP z guardami
// INFORMATION_SCHEMA. Zakres wykonania: 006–008, 012–014, 016–017, 020–023,
// 024–029 (pełne pliki SQL). Migracja 018 została usunięta (cleanup w 025).
//
// Pełny łańcuch plików 004–034 (z pominięciem 015 domyślnie): scripts/apply_migrations_chain.php
// Dla danych testowych: seed_demo_all.php (tam też są KOPIE 006–008).
// =============================================================================

require_once __DIR__ . '/../core/db_config.php';

if (!isset($pdo)) {
    die('<h1 style="color:red;">FATAL: Database connection failed. Check db_config.php</h1>');
}

$results = [];

function runMigration(PDO $pdo, string $label, array $statements): array {
    $out = [];
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
            $out[] = ['ok' => true, 'sql' => substr($sql, 0, 80) . '...', 'msg' => 'OK'];
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column name') || str_contains($msg, 'already exists')) {
                $out[] = ['ok' => true, 'sql' => substr($sql, 0, 80) . '...', 'msg' => 'SKIP (already exists)'];
            } else {
                $out[] = ['ok' => false, 'sql' => substr($sql, 0, 80) . '...', 'msg' => $msg];
            }
        }
    }
    return $out;
}

// Migration 006 — Studio Mission Control columns
$results['Migration 006 — Studio Mission Control'] = runMigration($pdo, '006', [
    "ALTER TABLE sh_categories ADD COLUMN default_vat_dine_in DECIMAL(5,2) NOT NULL DEFAULT 8.00",
    "ALTER TABLE sh_categories ADD COLUMN default_vat_takeaway DECIMAL(5,2) NOT NULL DEFAULT 5.00",
    "ALTER TABLE sh_categories ADD COLUMN default_vat_delivery DECIMAL(5,2) NOT NULL DEFAULT 5.00",
    "ALTER TABLE sh_menu_items ADD COLUMN printer_group VARCHAR(64) NULL DEFAULT NULL",
    "ALTER TABLE sh_menu_items ADD COLUMN plu_code VARCHAR(32) NULL DEFAULT NULL",
    "ALTER TABLE sh_menu_items ADD COLUMN available_days VARCHAR(32) NULL DEFAULT '1,2,3,4,5,6,7'",
    "ALTER TABLE sh_menu_items ADD COLUMN available_start TIME NULL DEFAULT NULL",
    "ALTER TABLE sh_menu_items ADD COLUMN available_end TIME NULL DEFAULT NULL",
]);

// Migration 007 — POS Engine columns
$results['Migration 007 — POS Engine Columns'] = runMigration($pdo, '007', [
    "ALTER TABLE sh_orders ADD COLUMN receipt_printed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE sh_orders ADD COLUMN kitchen_ticket_printed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE sh_orders ADD COLUMN kitchen_changes TEXT NULL",
    "ALTER TABLE sh_orders ADD COLUMN cart_json JSON NULL",
    "ALTER TABLE sh_orders ADD COLUMN nip VARCHAR(32) NULL",
]);

// Migration 008 — Delivery Ecosystem (driver locations)
try {
    $chk = $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0");
    $chk->closeCursor();
    $results['Migration 008 — Driver Locations'][] = ['ok' => true, 'sql' => 'sh_driver_locations', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sh_driver_locations (
            driver_id BIGINT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
            lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL,
            heading SMALLINT NULL, speed_kmh DECIMAL(5,1) NULL, accuracy_m DECIMAL(6,1) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id, driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $results['Migration 008 — Driver Locations'][] = ['ok' => true, 'sql' => 'CREATE TABLE sh_driver_locations', 'msg' => 'OK'];
    } catch (PDOException $e2) {
        $results['Migration 008 — Driver Locations'][] = ['ok' => false, 'sql' => 'sh_driver_locations', 'msg' => $e2->getMessage()];
    }
}

// Migration 012 — Visual Layers table
try {
    $chk = $pdo->query("SELECT 1 FROM sh_visual_layers LIMIT 0");
    $chk->closeCursor();
    $results['Migration 012 — Visual Layers'][] = ['ok' => true, 'sql' => 'sh_visual_layers', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    $sqlPath = __DIR__ . '/../database/migrations/012_visual_layers.sql';
    if (file_exists($sqlPath)) {
        try {
            $sql012 = file_get_contents($sqlPath);
            $sql012 = preg_replace('/^USE\s+\w+;/mi', '', $sql012);
            $sql012 = preg_replace('/^SET\s+NAMES\s+\w+;/mi', '', $sql012);
            $pdo->exec($sql012);
            $results['Migration 012 — Visual Layers'][] = ['ok' => true, 'sql' => 'CREATE TABLE sh_visual_layers', 'msg' => 'OK'];
        } catch (PDOException $e2) {
            $results['Migration 012 — Visual Layers'][] = ['ok' => false, 'sql' => 'sh_visual_layers', 'msg' => $e2->getMessage()];
        }
    }
}

// Migration 013 — Board Companions table
try {
    $chk = $pdo->query("SELECT 1 FROM sh_board_companions LIMIT 0");
    $chk->closeCursor();
    $results['Migration 013 — Board Companions'][] = ['ok' => true, 'sql' => 'sh_board_companions', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    $sqlPath = __DIR__ . '/../database/migrations/013_board_companions.sql';
    if (file_exists($sqlPath)) {
        try {
            $sql013 = file_get_contents($sqlPath);
            $sql013 = preg_replace('/^USE\s+\w+;/mi', '', $sql013);
            $sql013 = preg_replace('/^SET\s+NAMES\s+\w+;/mi', '', $sql013);
            $pdo->exec($sql013);
            $results['Migration 013 — Board Companions'][] = ['ok' => true, 'sql' => 'CREATE TABLE sh_board_companions', 'msg' => 'OK'];
        } catch (PDOException $e2) {
            $results['Migration 013 — Board Companions'][] = ['ok' => false, 'sql' => 'sh_board_companions', 'msg' => $e2->getMessage()];
        }
    }
}

// Migration 014a — Global Assets table
try {
    $chk = $pdo->query("SELECT 1 FROM sh_global_assets LIMIT 0");
    $chk->closeCursor();
    $results['Migration 014 — Global Assets'][] = ['ok' => true, 'sql' => 'sh_global_assets', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    $sqlPath = __DIR__ . '/../database/migrations/014_global_assets.sql';
    if (file_exists($sqlPath)) {
        try {
            $sql014a = file_get_contents($sqlPath);
            $sql014a = preg_replace('/^USE\s+\w+;/mi', '', $sql014a);
            $sql014a = preg_replace('/^SET\s+NAMES\s+\w+;/mi', '', $sql014a);
            $pdo->exec($sql014a);
            $results['Migration 014 — Global Assets'][] = ['ok' => true, 'sql' => 'CREATE TABLE sh_global_assets', 'msg' => 'OK'];
        } catch (PDOException $e2) {
            $results['Migration 014 — Global Assets'][] = ['ok' => false, 'sql' => 'sh_global_assets', 'msg' => $e2->getMessage()];
        }
    }
}

// [M025 · cleanup] Migracja 014b (sh_ingredient_assets) została usunięta.
// Tabela jest dropowana w m025; nowe instalacje nie powinny jej już tworzyć.

// Migration 016 — Visual Compositor Upgrade (product_filename, cal_scale, cal_rotate)
$results['Migration 016 — Visual Compositor Upgrade'] = runMigration($pdo, '016', [
    "SET @col_ex = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_visual_layers' AND COLUMN_NAME = 'product_filename')",
]);
$vl016Cols = [
    ['sh_visual_layers', 'product_filename', "ADD COLUMN product_filename VARCHAR(255) NULL COMMENT 'Hero product photo' AFTER asset_filename"],
    ['sh_visual_layers', 'cal_scale', "ADD COLUMN cal_scale DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT 'Visual calibration scale 0.50-2.00' AFTER product_filename"],
    ['sh_visual_layers', 'cal_rotate', "ADD COLUMN cal_rotate SMALLINT NOT NULL DEFAULT 0 COMMENT 'Visual calibration rotation -180 to 180' AFTER cal_scale"],
    ['sh_board_companions', 'product_filename', "ADD COLUMN product_filename VARCHAR(255) NULL COMMENT 'Hero product photo for surface' AFTER asset_filename"],
];
$m016Results = [];
foreach ($vl016Cols as [$table, $col, $ddl]) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col");
        $chk->execute([':tbl' => $table, ':col' => $col]);
        if ((int)$chk->fetchColumn() > 0) {
            $m016Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'SKIP (exists)'];
        } else {
            $pdo->exec("ALTER TABLE {$table} {$ddl}");
            $m016Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'ADDED'];
        }
    } catch (PDOException $e) {
        $m016Results[] = ['ok' => false, 'sql' => "{$table}.{$col}", 'msg' => $e->getMessage()];
    }
}
try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM sh_tenant_settings WHERE setting_key = 'storefront_surface_bg'");
    $chk->execute();
    if ((int)$chk->fetchColumn() > 0) {
        $m016Results[] = ['ok' => true, 'sql' => 'storefront_surface_bg setting', 'msg' => 'SKIP (exists)'];
    } else {
        $pdo->exec("INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
            SELECT t.id, 'storefront_surface_bg', NULL FROM sh_tenant t
            WHERE NOT EXISTS (SELECT 1 FROM sh_tenant_settings ts WHERE ts.tenant_id = t.id AND ts.setting_key = 'storefront_surface_bg')");
        $m016Results[] = ['ok' => true, 'sql' => 'storefront_surface_bg setting', 'msg' => 'INSERTED'];
    }
} catch (PDOException $e) {
    $m016Results[] = ['ok' => false, 'sql' => 'storefront_surface_bg setting', 'msg' => $e->getMessage()];
}
$results['Migration 016 — Visual Compositor Upgrade'] = $m016Results;

// =============================================================================
// Migration 017 — Online Module Extensions
// =============================================================================
$m017Results = [];

// 017a — sh_visual_layers: version, library_category, library_sub_type
$vl017Cols = [
    ['sh_visual_layers', 'version',          "ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Optimistic locking' AFTER is_active"],
    ['sh_visual_layers', 'library_category', "ADD COLUMN library_category VARCHAR(64) NULL COMMENT 'Library filter category' AFTER layer_sku"],
    ['sh_visual_layers', 'library_sub_type', "ADD COLUMN library_sub_type VARCHAR(64) NULL COMMENT 'Library filter sub-type' AFTER library_category"],
    ['sh_orders',        'tracking_token',   "ADD COLUMN tracking_token CHAR(16) NULL COMMENT 'Guest tracker token (16 hex)' AFTER customer_phone"],
];
foreach ($vl017Cols as [$table, $col, $ddl]) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col");
        $chk->execute([':tbl' => $table, ':col' => $col]);
        if ((int)$chk->fetchColumn() > 0) {
            $m017Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'SKIP (exists)'];
        } else {
            $pdo->exec("ALTER TABLE {$table} {$ddl}");
            $m017Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'ADDED'];
        }
    } catch (PDOException $e) {
        $m017Results[] = ['ok' => false, 'sql' => "{$table}.{$col}", 'msg' => $e->getMessage()];
    }
}

// 017b — Indexes
$indexes017 = [
    ['sh_visual_layers', 'idx_vl_library',     'CREATE INDEX idx_vl_library ON sh_visual_layers (tenant_id, library_category, library_sub_type)'],
    ['sh_orders',        'idx_orders_tracking', 'CREATE INDEX idx_orders_tracking ON sh_orders (tracking_token)'],
];
foreach ($indexes017 as [$table, $idxName, $ddl]) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND INDEX_NAME = :idx");
        $chk->execute([':tbl' => $table, ':idx' => $idxName]);
        if ((int)$chk->fetchColumn() > 0) {
            $m017Results[] = ['ok' => true, 'sql' => "{$table}.{$idxName}", 'msg' => 'SKIP (index exists)'];
        } else {
            $pdo->exec($ddl);
            $m017Results[] = ['ok' => true, 'sql' => "{$table}.{$idxName}", 'msg' => 'CREATED'];
        }
    } catch (PDOException $e) {
        $m017Results[] = ['ok' => false, 'sql' => "{$table}.{$idxName}", 'msg' => $e->getMessage()];
    }
}

// 017c — sh_checkout_locks table
try {
    $chk = $pdo->query("SELECT 1 FROM sh_checkout_locks LIMIT 0");
    $chk->closeCursor();
    $m017Results[] = ['ok' => true, 'sql' => 'sh_checkout_locks', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sh_checkout_locks (
              lock_token         CHAR(36)        NOT NULL,
              tenant_id          INT UNSIGNED    NOT NULL,
              customer_phone     VARCHAR(32)     NULL,
              cart_hash          CHAR(64)        NOT NULL COMMENT 'SHA-256 of canonicalized cart',
              grand_total_grosze BIGINT UNSIGNED NOT NULL DEFAULT 0,
              channel            VARCHAR(16)     NOT NULL DEFAULT 'Delivery',
              expires_at         DATETIME        NOT NULL,
              consumed_at        DATETIME        NULL,
              consumed_order_id  CHAR(36)        NULL,
              created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (lock_token),
              KEY idx_locks_expires (expires_at),
              KEY idx_locks_phone   (tenant_id, customer_phone),
              KEY idx_locks_hash    (tenant_id, cart_hash),
              CONSTRAINT fk_locks_tenant
                FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
                ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $m017Results[] = ['ok' => true, 'sql' => 'sh_checkout_locks', 'msg' => 'CREATED'];
    } catch (PDOException $e2) {
        $m017Results[] = ['ok' => false, 'sql' => 'sh_checkout_locks', 'msg' => $e2->getMessage()];
    }
}

// 017d — Default tenant settings for online module
$onlineSettings = [
    ['online_min_order_value',  '0.00'],
    ['online_default_eta_min',  '30'],
    ['online_guest_checkout',   '1'],
    ['online_apple_pay_enabled','0'],
    ['online_promotion_banner', ''],
];
foreach ($onlineSettings as [$key, $val]) {
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
             SELECT t.id, :k, :v FROM sh_tenant t
             WHERE NOT EXISTS (
                SELECT 1 FROM sh_tenant_settings ts
                WHERE ts.tenant_id = t.id AND ts.setting_key = :k2
             )"
        );
        $stmt->execute([':k' => $key, ':v' => $val, ':k2' => $key]);
        $m017Results[] = ['ok' => true, 'sql' => "setting: {$key}", 'msg' => 'OK'];
    } catch (PDOException $e) {
        $m017Results[] = ['ok' => false, 'sql' => "setting: {$key}", 'msg' => $e->getMessage()];
    }
}
$results['Migration 017 — Online Module Extensions'] = $m017Results;

// [M025 · cleanup] Migracja 018 (sh_modifier_visual_map) została usunięta.
// Tabela jest dropowana w m025 — nowe instalacje jej nie tworzą.

// =============================================================================
// Migration 024 — Modifier has_visual_impact (Surface / asset slots)
// =============================================================================
$m024Results = [];
$sql024Path = __DIR__ . '/../database/migrations/024_modifier_visual_impact.sql';
if (!file_exists($sql024Path)) {
    $m024Results[] = ['ok' => false, 'sql' => '024 SQL file', 'msg' => 'NOT FOUND: ' . $sql024Path];
} else {
    try {
        $sql024 = file_get_contents($sql024Path);
        $pdo->exec($sql024);
        $m024Results[] = ['ok' => true, 'sql' => 'sh_modifiers.has_visual_impact', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'already exists')) {
            $m024Results[] = ['ok' => true, 'sql' => '024 DDL', 'msg' => 'SKIP (column exists)'];
        } else {
            $m024Results[] = ['ok' => false, 'sql' => '024 DDL', 'msg' => $msg];
        }
    }
}
try {
    $chk024 = $pdo->query("SELECT has_visual_impact FROM sh_modifiers LIMIT 0");
    $chk024->closeCursor();
    $m024Results[] = ['ok' => true, 'sql' => 'verify column', 'msg' => 'has_visual_impact OK'];
} catch (PDOException $e) {
    $m024Results[] = ['ok' => false, 'sql' => 'verify column', 'msg' => $e->getMessage()];
}
$results['Migration 024 — Modifier visual impact flag'] = $m024Results;

// =============================================================================
// Migration 025 — Drop Legacy Magic Dictionary + Ingredient Assets
// =============================================================================
// Idempotentnie: DROP sh_modifier_visual_map, DROP sh_ingredient_assets,
// DROP VIEW v_modifier_icon, DELETE z sh_asset_links rekordów role='modifier_icon'.
$m025Results = [];
$sql025Path = __DIR__ . '/../database/migrations/025_drop_legacy_magic_dict.sql';
if (!file_exists($sql025Path)) {
    $m025Results[] = ['ok' => false, 'sql' => '025 SQL file', 'msg' => 'NOT FOUND: ' . $sql025Path];
} else {
    try {
        $sql025 = file_get_contents($sql025Path);
        $pdo->exec($sql025);
        $m025Results[] = ['ok' => true, 'sql' => '025 cleanup DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $m025Results[] = ['ok' => false, 'sql' => '025 cleanup DDL', 'msg' => $e->getMessage()];
    }
}
// Verify: tabele NIE istnieją, role NIE istnieją
foreach (['sh_modifier_visual_map', 'sh_ingredient_assets'] as $droppedTbl) {
    try {
        $pdo->query("SELECT 1 FROM {$droppedTbl} LIMIT 0")->closeCursor();
        $m025Results[] = ['ok' => false, 'sql' => "DROP {$droppedTbl}", 'msg' => 'STILL EXISTS (cleanup failed)'];
    } catch (PDOException $e) {
        $m025Results[] = ['ok' => true, 'sql' => "DROP {$droppedTbl}", 'msg' => 'DROPPED'];
    }
}
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_asset_links WHERE role = 'modifier_icon'")->fetchColumn();
    $m025Results[] = ['ok' => $cnt === 0, 'sql' => "sh_asset_links role='modifier_icon'", 'msg' => "{$cnt} rows left"];
} catch (PDOException $e) {
    $m025Results[] = ['ok' => false, 'sql' => "sh_asset_links role='modifier_icon'", 'msg' => $e->getMessage()];
}
$results['Migration 025 — Drop Legacy Magic Dictionary'] = $m025Results;

// =============================================================================
// Migration 026 — Event System (Transactional Outbox + Webhooks + Integrations)
// =============================================================================
// Tworzy sh_event_outbox (lifecycle events), sh_webhook_endpoints (subscribers),
// sh_webhook_deliveries (retry history), sh_tenant_integrations (3rd-party registry).
// Fundament Sesji 7.1 — event-driven decoupling modułów.
$m026Results = [];
$sql026Path = __DIR__ . '/../database/migrations/026_event_system.sql';
if (!file_exists($sql026Path)) {
    $m026Results[] = ['ok' => false, 'sql' => '026 SQL file', 'msg' => 'NOT FOUND: ' . $sql026Path];
} else {
    try {
        $sql026 = file_get_contents($sql026Path);
        $pdo->exec($sql026);
        $m026Results[] = ['ok' => true, 'sql' => '026 event system DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
            $m026Results[] = ['ok' => true, 'sql' => '026 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m026Results[] = ['ok' => false, 'sql' => '026 DDL', 'msg' => $msg];
        }
    }
}
// Verify: tabele event systemu
foreach (['sh_event_outbox', 'sh_webhook_endpoints', 'sh_webhook_deliveries', 'sh_tenant_integrations'] as $eventTbl) {
    try {
        $pdo->query("SELECT 1 FROM {$eventTbl} LIMIT 0")->closeCursor();
        $m026Results[] = ['ok' => true, 'sql' => $eventTbl, 'msg' => 'EXISTS'];
    } catch (PDOException $e) {
        $m026Results[] = ['ok' => false, 'sql' => $eventTbl, 'msg' => 'MISSING: ' . $e->getMessage()];
    }
}
$results['Migration 026 — Event System'] = $m026Results;

// =============================================================================
// Migration 027 — Gateway v2 (Multi-source Intake Infrastructure)
// =============================================================================
// Tworzy sh_gateway_api_keys (multi-key auth), sh_rate_limits (sliding window),
// sh_external_order_refs (idempotency), rozszerza sh_orders o gateway_source +
// gateway_external_id. Sesja 7.2 — Unified Order Intake.
$m027Results = [];
$sql027Path = __DIR__ . '/../database/migrations/027_gateway_v2.sql';
if (!file_exists($sql027Path)) {
    $m027Results[] = ['ok' => false, 'sql' => '027 SQL file', 'msg' => 'NOT FOUND: ' . $sql027Path];
} else {
    try {
        $sql027 = file_get_contents($sql027Path);
        $pdo->exec($sql027);
        $m027Results[] = ['ok' => true, 'sql' => '027 gateway v2 DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column')) {
            $m027Results[] = ['ok' => true, 'sql' => '027 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m027Results[] = ['ok' => false, 'sql' => '027 DDL', 'msg' => $msg];
        }
    }
}
// Verify tables
foreach (['sh_gateway_api_keys', 'sh_rate_limits', 'sh_external_order_refs'] as $gwTbl) {
    try {
        $pdo->query("SELECT 1 FROM {$gwTbl} LIMIT 0")->closeCursor();
        $m027Results[] = ['ok' => true, 'sql' => $gwTbl, 'msg' => 'EXISTS'];
    } catch (PDOException $e) {
        $m027Results[] = ['ok' => false, 'sql' => $gwTbl, 'msg' => 'MISSING: ' . $e->getMessage()];
    }
}
// Verify sh_orders.gateway_source column
try {
    $pdo->query("SELECT gateway_source FROM sh_orders LIMIT 0")->closeCursor();
    $m027Results[] = ['ok' => true, 'sql' => 'sh_orders.gateway_source', 'msg' => 'EXISTS'];
} catch (PDOException $e) {
    $m027Results[] = ['ok' => false, 'sql' => 'sh_orders.gateway_source', 'msg' => 'MISSING'];
}
$results['Migration 027 — Gateway v2'] = $m027Results;

// =============================================================================
// Migration 028 — Integration Deliveries (async 3rd-party adapter log)
// =============================================================================
// Tworzy sh_integration_deliveries (per event×integration state) +
// sh_integration_attempts (audit) + rozszerza sh_tenant_integrations o pola
// health (consecutive_failures, max_retries, timeout_seconds). Sesja 7.4 —
// Integration Adapters (Papu / Dotykacka / GastroSoft).
$m028Results = [];
$sql028Path = __DIR__ . '/../database/migrations/028_integration_deliveries.sql';
if (!file_exists($sql028Path)) {
    $m028Results[] = ['ok' => false, 'sql' => '028 SQL file', 'msg' => 'NOT FOUND: ' . $sql028Path];
} else {
    try {
        $sql028 = file_get_contents($sql028Path);
        $pdo->exec($sql028);
        $m028Results[] = ['ok' => true, 'sql' => '028 integration_deliveries DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key')) {
            $m028Results[] = ['ok' => true, 'sql' => '028 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m028Results[] = ['ok' => false, 'sql' => '028 DDL', 'msg' => $msg];
        }
    }
}
foreach (['sh_integration_deliveries', 'sh_integration_attempts'] as $intTbl) {
    try {
        $pdo->query("SELECT 1 FROM {$intTbl} LIMIT 0")->closeCursor();
        $m028Results[] = ['ok' => true, 'sql' => $intTbl, 'msg' => 'EXISTS'];
    } catch (PDOException $e) {
        $m028Results[] = ['ok' => false, 'sql' => $intTbl, 'msg' => 'MISSING: ' . $e->getMessage()];
    }
}
try {
    $pdo->query("SELECT consecutive_failures, max_retries, timeout_seconds FROM sh_tenant_integrations LIMIT 0")->closeCursor();
    $m028Results[] = ['ok' => true, 'sql' => 'sh_tenant_integrations.health columns', 'msg' => 'EXISTS'];
} catch (PDOException $e) {
    $m028Results[] = ['ok' => false, 'sql' => 'sh_tenant_integrations.health columns', 'msg' => 'MISSING'];
}
$results['Migration 028 — Integration Deliveries'] = $m028Results;

// =============================================================================
// Migration 029 — Infrastructure Completion (Faza 7.6)
//   • sh_settings_audit       — audit log dla mutacji Settings Panelu
//   • sh_inbound_callbacks    — surowy log callbacków od 3rd-party (Papu, Dotykacka, …)
// =============================================================================
$m029Results = [];
$sql029Path = __DIR__ . '/../database/migrations/029_infrastructure_completion.sql';
if (!file_exists($sql029Path)) {
    $m029Results[] = ['ok' => false, 'sql' => '029 SQL file', 'msg' => 'NOT FOUND: ' . $sql029Path];
} else {
    try {
        $sql029 = file_get_contents($sql029Path);
        $pdo->exec($sql029);
        $m029Results[] = ['ok' => true, 'sql' => '029 infrastructure_completion DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key')) {
            $m029Results[] = ['ok' => true, 'sql' => '029 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m029Results[] = ['ok' => false, 'sql' => '029 DDL', 'msg' => $msg];
        }
    }
}
foreach (['sh_settings_audit', 'sh_inbound_callbacks'] as $m029Tbl) {
    try {
        $pdo->query("SELECT 1 FROM {$m029Tbl} LIMIT 0")->closeCursor();
        $m029Results[] = ['ok' => true, 'sql' => $m029Tbl, 'msg' => 'EXISTS'];
    } catch (PDOException $e) {
        $m029Results[] = ['ok' => false, 'sql' => $m029Tbl, 'msg' => 'MISSING: ' . $e->getMessage()];
    }
}
$results['Migration 029 — Infrastructure Completion'] = $m029Results;

// =============================================================================
// Migration 021 — Unified Asset Library (sh_assets + sh_asset_links + views)
// =============================================================================
// Plik migracji jest samodzielny i idempotentny (IF NOT EXISTS, INSERT IGNORE).
// Uruchamiamy go za każdym razem — gdy tabele/widoki istnieją, NOOP.
// Gdy w DB pojawią się nowe wiersze w starych tabelach (np. po seedzie),
// backfill automatycznie je załapie przy następnym uruchomieniu setupu.

$m021Results = [];
$sql021Path = __DIR__ . '/../database/migrations/021_unified_asset_library.sql';
if (!file_exists($sql021Path)) {
    $m021Results[] = ['ok' => false, 'sql' => '021 SQL file', 'msg' => 'NOT FOUND: ' . $sql021Path];
} else {
    try {
        $sql021 = file_get_contents($sql021Path);
        $pdo->exec($sql021);
        $m021Results[] = ['ok' => true, 'sql' => 'sh_assets + sh_asset_links + views + backfill', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
            $m021Results[] = ['ok' => true, 'sql' => '021 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m021Results[] = ['ok' => false, 'sql' => '021 DDL', 'msg' => $msg];
        }
    }
}

// Verify — counts po backfillu (daje feedback ile wierszy zmigrowano)
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_assets")->fetchColumn();
    $m021Results[] = ['ok' => true, 'sql' => 'sh_assets row count', 'msg' => number_format($cnt) . ' assets'];
} catch (PDOException $e) {
    $m021Results[] = ['ok' => false, 'sql' => 'sh_assets row count', 'msg' => $e->getMessage()];
}
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_asset_links")->fetchColumn();
    $m021Results[] = ['ok' => true, 'sql' => 'sh_asset_links row count', 'msg' => number_format($cnt) . ' links'];
} catch (PDOException $e) {
    $m021Results[] = ['ok' => false, 'sql' => 'sh_asset_links row count', 'msg' => $e->getMessage()];
}
// Verify views exist (v_modifier_icon usunięte w m025 razem z role='modifier_icon')
foreach (['v_menu_item_hero', 'v_visual_layer_asset'] as $view) {
    try {
        $pdo->query("SELECT 1 FROM {$view} LIMIT 0")->closeCursor();
        $m021Results[] = ['ok' => true, 'sql' => "VIEW {$view}", 'msg' => 'EXISTS'];
    } catch (PDOException $e) {
        $m021Results[] = ['ok' => false, 'sql' => "VIEW {$view}", 'msg' => 'MISSING: ' . $e->getMessage()];
    }
}
$results['Migration 021 — Unified Asset Library'] = $m021Results;

// =============================================================================
// Migration 020 PRE-CHECK — sh_atelier_scenes musi istnieć dla 022 ALTERów
// =============================================================================
// Migracja 020 nie była podpięta w setup_database.php historycznie.
// 022 ROZSZERZA sh_atelier_scenes — gwarantujemy że tabela istnieje.

$m020Results = [];
try {
    $chk = $pdo->query("SELECT 1 FROM sh_atelier_scenes LIMIT 0");
    $chk->closeCursor();
    $m020Results[] = ['ok' => true, 'sql' => 'sh_atelier_scenes', 'msg' => 'SKIP (already exists)'];
} catch (PDOException $e) {
    $sql020Path = __DIR__ . '/../database/migrations/020_director_scenes.sql';
    if (file_exists($sql020Path)) {
        try {
            $sql020 = file_get_contents($sql020Path);
            $sql020 = preg_replace('/^USE\s+\w+;/mi', '', $sql020);
            $sql020 = preg_replace('/^SET\s+NAMES\s+\w+;/mi', '', $sql020);
            $pdo->exec($sql020);
            $m020Results[] = ['ok' => true, 'sql' => 'sh_atelier_scenes + history', 'msg' => 'CREATED'];
        } catch (PDOException $e2) {
            $m020Results[] = ['ok' => false, 'sql' => 'sh_atelier_scenes', 'msg' => $e2->getMessage()];
        }
    } else {
        $m020Results[] = ['ok' => false, 'sql' => '020 SQL file', 'msg' => 'NOT FOUND'];
    }
}
$results['Migration 020 — Director Scene Specs (pre-check)'] = $m020Results;

// =============================================================================
// Migration 035 — Atelier Performance (generated columns + asset registry)
// (wcześniej numerowane 021a; przenumerowane po rozwiązaniu kolizji z m021_unified_asset_library)
// =============================================================================
$m021aResults = [];
$sql021aPath = __DIR__ . '/../database/migrations/035_atelier_performance.sql';
if (!file_exists($sql021aPath)) {
    $m021aResults[] = ['ok' => false, 'sql' => '035 SQL file', 'msg' => 'NOT FOUND: ' . $sql021aPath];
} else {
    try {
        $sql021a = file_get_contents($sql021aPath);
        $sql021a = preg_replace('/^USE\s+\w+;/mi', '', $sql021a);
        $sql021a = preg_replace('/^SET\s+NAMES\s+\w+;/mi', '', $sql021a);
        $pdo->exec($sql021a);
        $m021aResults[] = ['ok' => true, 'sql' => '035 atelier performance DDL', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate') || str_contains($msg, 'skip')) {
            $m021aResults[] = ['ok' => true, 'sql' => '035 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m021aResults[] = ['ok' => false, 'sql' => '035 DDL', 'msg' => $msg];
        }
    }
}
try {
    $pdo->query("SELECT layers_count, spec_hash FROM sh_atelier_scenes LIMIT 0")->closeCursor();
    $m021aResults[] = ['ok' => true, 'sql' => 'sh_atelier_scenes generated columns', 'msg' => 'EXISTS'];
} catch (PDOException $e) {
    $m021aResults[] = ['ok' => false, 'sql' => 'sh_atelier_scenes generated columns', 'msg' => $e->getMessage()];
}
try {
    $pdo->query("SELECT 1 FROM sh_asset_registry LIMIT 0")->closeCursor();
    $m021aResults[] = ['ok' => true, 'sql' => 'sh_asset_registry', 'msg' => 'EXISTS'];
} catch (PDOException $e) {
    $m021aResults[] = ['ok' => false, 'sql' => 'sh_asset_registry', 'msg' => $e->getMessage()];
}
$results['Migration 035 — Atelier Performance'] = $m021aResults;

// =============================================================================
// Migration 022 — Scene Kit & The Table Foundation
// =============================================================================
// Etap 1/3: nowe tabele + seed (z pliku SQL)
// Etap 2/3: idempotentne ALTERy istniejących tabel (sh_menu_items, sh_categories,
//           sh_atelier_scenes, sh_board_companions)
// Etap 3/3: AI tenant settings + verify counts

$m022Results = [];

// --- Etap 1: SQL plik (CREATE TABLE IF NOT EXISTS + INSERT seedu) ---
$sql022Path = __DIR__ . '/../database/migrations/022_scene_kit.sql';
if (!file_exists($sql022Path)) {
    $m022Results[] = ['ok' => false, 'sql' => '022 SQL file', 'msg' => 'NOT FOUND: ' . $sql022Path];
} else {
    try {
        $sql022 = file_get_contents($sql022Path);
        $pdo->exec($sql022);
        $m022Results[] = ['ok' => true, 'sql' => '8 nowych tabel + seed (templates+styles)', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
            $m022Results[] = ['ok' => true, 'sql' => '022 DDL', 'msg' => 'SKIP (already exists)'];
        } else {
            $m022Results[] = ['ok' => false, 'sql' => '022 DDL', 'msg' => $msg];
        }
    }
}

// --- Etap 2: Idempotentne ALTERy istniejących tabel ---
$m022Cols = [
    // sh_menu_items
    ['sh_menu_items', 'composition_profile',
        "ADD COLUMN composition_profile VARCHAR(64) NULL DEFAULT 'static_hero' COMMENT 'M022: FK logiczny do sh_scene_templates.ascii_key' AFTER image_url"],

    // sh_categories
    ['sh_categories', 'default_composition_profile',
        "ADD COLUMN default_composition_profile VARCHAR(64) NULL DEFAULT 'static_hero' COMMENT 'M022: domyślny profil dla nowych dań w tej kategorii'"],
    ['sh_categories', 'layout_mode',
        "ADD COLUMN layout_mode ENUM('grouped','individual','hybrid','legacy_list') NOT NULL DEFAULT 'legacy_list' COMMENT 'M022: tryb wyświetlania kategorii w The Table'"],
    ['sh_categories', 'category_scene_id',
        "ADD COLUMN category_scene_id INT NULL DEFAULT NULL COMMENT 'M022: opcjonalna scena kategorii (sh_atelier_scenes.id)'"],

    // sh_atelier_scenes
    ['sh_atelier_scenes', 'scene_kind',
        "ADD COLUMN scene_kind ENUM('item','category') NOT NULL DEFAULT 'item' COMMENT 'M022: typ sceny'"],
    ['sh_atelier_scenes', 'template_id',
        "ADD COLUMN template_id INT UNSIGNED NULL COMMENT 'M022: FK do sh_scene_templates'"],
    ['sh_atelier_scenes', 'parent_category_id',
        "ADD COLUMN parent_category_id BIGINT UNSIGNED NULL COMMENT 'M022: dla scene_kind=category — FK do sh_categories.id'"],
    ['sh_atelier_scenes', 'active_style_id',
        "ADD COLUMN active_style_id INT UNSIGNED NULL COMMENT 'M022: FK do sh_style_presets'"],
    ['sh_atelier_scenes', 'active_camera_preset',
        "ADD COLUMN active_camera_preset VARCHAR(64) NULL COMMENT 'M022: top_down / hero_three_quarter / macro_close / wide_establishing / dutch_angle / rack_focus'"],
    ['sh_atelier_scenes', 'active_lut',
        "ADD COLUMN active_lut VARCHAR(64) NULL COMMENT 'M022: warm_summer_evening / golden_hour / film_noir_bw / etc.'"],
    ['sh_atelier_scenes', 'atmospheric_effects_enabled_json',
        "ADD COLUMN atmospheric_effects_enabled_json JSON NULL COMMENT 'M022: tablica włączonych atmospheric effects'"],

    // sh_board_companions
    ['sh_board_companions', 'cta_label',
        "ADD COLUMN cta_label VARCHAR(64) NULL DEFAULT 'Dodaj' COMMENT 'M022: label przycisku CTA na scenie'"],
    ['sh_board_companions', 'is_always_visible',
        "ADD COLUMN is_always_visible TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'M022: czy companion jest zawsze na stole, czy tylko warunkowo'"],
    ['sh_board_companions', 'slot_class',
        "ADD COLUMN slot_class VARCHAR(32) NULL DEFAULT 'companion' COMMENT 'M022: companion / promotion / recommendation'"],
];
foreach ($m022Cols as [$table, $col, $ddl]) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col");
        $chk->execute([':tbl' => $table, ':col' => $col]);
        if ((int)$chk->fetchColumn() > 0) {
            $m022Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'SKIP (exists)'];
        } else {
            $pdo->exec("ALTER TABLE {$table} {$ddl}");
            $m022Results[] = ['ok' => true, 'sql' => "{$table}.{$col}", 'msg' => 'ADDED'];
        }
    } catch (PDOException $e) {
        $m022Results[] = ['ok' => false, 'sql' => "{$table}.{$col}", 'msg' => $e->getMessage()];
    }
}

// --- Etap 3: AI tenant settings (INSERT IGNORE per tenant) ---
$aiSettings = [
    ['ai_monthly_budget_zl',      '50.00'],
    ['ai_current_month_spent_zl', '0.00'],
    ['ai_budget_reset_at',        ''],
];
foreach ($aiSettings as [$key, $val]) {
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
             SELECT t.id, :k, :v FROM sh_tenant t
             WHERE NOT EXISTS (
                SELECT 1 FROM sh_tenant_settings ts
                WHERE ts.tenant_id = t.id AND ts.setting_key = :k2
             )"
        );
        $stmt->execute([':k' => $key, ':v' => $val, ':k2' => $key]);
        $m022Results[] = ['ok' => true, 'sql' => "setting: {$key}", 'msg' => 'OK'];
    } catch (PDOException $e) {
        $m022Results[] = ['ok' => false, 'sql' => "setting: {$key}", 'msg' => $e->getMessage()];
    }
}

// --- Verify counts ---
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_scene_templates WHERE tenant_id=0")->fetchColumn();
    $m022Results[] = ['ok' => $cnt >= 8, 'sql' => 'sh_scene_templates seed count', 'msg' => "{$cnt} templates (expected ≥8)"];
} catch (PDOException $e) {
    $m022Results[] = ['ok' => false, 'sql' => 'sh_scene_templates count', 'msg' => $e->getMessage()];
}
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_style_presets WHERE tenant_id=0")->fetchColumn();
    $m022Results[] = ['ok' => $cnt >= 12, 'sql' => 'sh_style_presets seed count', 'msg' => "{$cnt} styles (expected ≥12)"];
} catch (PDOException $e) {
    $m022Results[] = ['ok' => false, 'sql' => 'sh_style_presets count', 'msg' => $e->getMessage()];
}
$results['Migration 022 — Scene Kit & The Table Foundation'] = $m022Results;

// =============================================================================
// Migration 023 — Scene Templates Content (Faza 2.1)
// =============================================================================
// UPDATE 4 placeholder templates + 2 category templates — wypełnienie
// metadanych (stage_preset, composition_schema, photographer_brief,
// pipeline_preset, available_cameras/luts, atmospheric_effects).
// Idempotentne — UPDATE ... WHERE jest safe re-run.

$m023Results = [];
$sql023Path = __DIR__ . '/../database/migrations/023_scene_templates_content.sql';
if (!file_exists($sql023Path)) {
    $m023Results[] = ['ok' => false, 'sql' => '023 SQL file', 'msg' => 'NOT FOUND: ' . $sql023Path];
} else {
    try {
        $sql023 = file_get_contents($sql023Path);
        $pdo->exec($sql023);
        $m023Results[] = ['ok' => true, 'sql' => '023 UPDATE templates content', 'msg' => 'EXECUTED'];
    } catch (PDOException $e) {
        $m023Results[] = ['ok' => false, 'sql' => '023 DDL', 'msg' => $e->getMessage()];
    }
}

// Verify: sprawdź że 6 system templates ma niepuste stage_preset_json
try {
    $cnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM sh_scene_templates
         WHERE tenant_id = 0 AND is_active = 1
           AND stage_preset_json IS NOT NULL
           AND JSON_LENGTH(stage_preset_json) > 0"
    )->fetchColumn();
    $m023Results[] = [
        'ok' => $cnt >= 8,
        'sql' => 'templates with full stage_preset',
        'msg' => "{$cnt} templates with metadata (expected ≥8)"
    ];
} catch (PDOException $e) {
    $m023Results[] = ['ok' => false, 'sql' => 'templates metadata count', 'msg' => $e->getMessage()];
}

// Verify: scene_kit_assets_json istnieje dla wszystkich
try {
    $cnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM sh_scene_templates
         WHERE tenant_id = 0 AND scene_kit_assets_json IS NOT NULL"
    )->fetchColumn();
    $m023Results[] = [
        'ok' => $cnt >= 8,
        'sql' => 'templates with scene_kit_assets slot',
        'msg' => "{$cnt} templates ready for Scene Kit content (Faza 2.2)"
    ];
} catch (PDOException $e) {
    $m023Results[] = ['ok' => false, 'sql' => 'scene_kit_assets count', 'msg' => $e->getMessage()];
}
$results['Migration 023 — Scene Templates Content'] = $m023Results;

// Verify
$verifyResults = [];
$colChecks = [
    ['sh_categories', 'default_vat_dine_in', '006 — VAT on categories'],
    ['sh_menu_items', 'printer_group', '006 — printer_group'],
    ['sh_menu_items', 'available_days', '006 — available_days'],
    ['sh_orders', 'cart_json', '007 — cart_json'],
    ['sh_orders', 'nip', '007 — nip'],
    ['sh_visual_layers', 'product_filename', '016 — VL product_filename'],
    ['sh_visual_layers', 'cal_scale', '016 — VL cal_scale'],
    ['sh_visual_layers', 'cal_rotate', '016 — VL cal_rotate'],
    ['sh_board_companions', 'product_filename', '016 — BC product_filename'],
    ['sh_visual_layers', 'version', '017 — VL version (optimistic lock)'],
    ['sh_visual_layers', 'library_category', '017 — VL library_category'],
    ['sh_visual_layers', 'library_sub_type', '017 — VL library_sub_type'],
    ['sh_orders', 'tracking_token', '017 — orders tracking_token'],
    ['sh_menu_items', 'composition_profile', '022 — MI composition_profile'],
    ['sh_categories', 'default_composition_profile', '022 — CAT default_composition_profile'],
    ['sh_categories', 'layout_mode', '022 — CAT layout_mode'],
    ['sh_categories', 'category_scene_id', '022 — CAT category_scene_id'],
    ['sh_atelier_scenes', 'scene_kind', '022 — AS scene_kind'],
    ['sh_atelier_scenes', 'template_id', '022 — AS template_id'],
    ['sh_atelier_scenes', 'active_style_id', '022 — AS active_style_id'],
    ['sh_atelier_scenes', 'active_camera_preset', '022 — AS active_camera_preset'],
    ['sh_atelier_scenes', 'active_lut', '022 — AS active_lut'],
    ['sh_board_companions', 'cta_label', '022 — BC cta_label'],
    ['sh_board_companions', 'is_always_visible', '022 — BC is_always_visible'],
    ['sh_board_companions', 'slot_class', '022 — BC slot_class'],
];
foreach ($colChecks as [$table, $col, $label]) {
    try {
        $q = $pdo->query("SELECT {$col} FROM {$table} LIMIT 0");
        $q->closeCursor();
        $verifyResults[] = ['ok' => true, 'sql' => $label, 'msg' => "Column EXISTS"];
    } catch (PDOException $e) {
        $verifyResults[] = ['ok' => false, 'sql' => $label, 'msg' => "MISSING"];
    }
}
try {
    $q = $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0");
    $q->closeCursor();
    $verifyResults[] = ['ok' => true, 'sql' => '008 — sh_driver_locations', 'msg' => 'Table EXISTS'];
} catch (PDOException $e) {
    $verifyResults[] = ['ok' => false, 'sql' => '008 — sh_driver_locations', 'msg' => 'MISSING'];
}
$tableChecks = [
    ['sh_visual_layers', '012 — sh_visual_layers'],
    ['sh_board_companions', '013 — sh_board_companions'],
    ['sh_global_assets', '014 — sh_global_assets'],
    ['sh_checkout_locks', '017 — sh_checkout_locks'],
    ['sh_atelier_scenes', '020 — sh_atelier_scenes (Director scenes)'],
    ['sh_atelier_scene_history', '020 — sh_atelier_scene_history'],
    ['sh_assets', '021 — sh_assets (Unified Asset Library)'],
    ['sh_asset_links', '021 — sh_asset_links (entity↔asset n:m)'],
    ['sh_scene_templates', '022 — sh_scene_templates (Scene Kit)'],
    ['sh_promotions', '022 — sh_promotions'],
    ['sh_scene_promotion_slots', '022 — sh_scene_promotion_slots'],
    ['sh_style_presets', '022 — sh_style_presets (Style Engine)'],
    ['sh_category_styles', '022 — sh_category_styles'],
    ['sh_scene_triggers', '022 — sh_scene_triggers'],
    ['sh_scene_variants', '022 — sh_scene_variants'],
    ['sh_ai_jobs', '022 — sh_ai_jobs (AI queue)'],
];
foreach ($tableChecks as [$table, $label]) {
    try {
        $q = $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
        $q->closeCursor();
        $verifyResults[] = ['ok' => true, 'sql' => $label, 'msg' => 'Table EXISTS'];
    } catch (PDOException $e) {
        $verifyResults[] = ['ok' => false, 'sql' => $label, 'msg' => 'MISSING'];
    }
}
$results['Verify — Schema'] = $verifyResults;

$totalOk = 0; $totalFail = 0;
foreach ($results as $steps) { foreach ($steps as $s) { $s['ok'] ? $totalOk++ : $totalFail++; } }
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>SliceHub — Database Setup</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#05050a; color:#e2e8f0; font-family:'Segoe UI',system-ui,sans-serif; padding:40px; }
        .header { text-align:center; margin-bottom:40px; }
        .header h1 { color:#a78bfa; font-size:28px; }
        .header p { color:#64748b; font-size:13px; margin-top:8px; }
        .summary { display:flex; justify-content:center; gap:20px; margin-bottom:30px; }
        .summary .box { padding:16px 32px; border-radius:12px; text-align:center; border:1px solid rgba(255,255,255,0.06); }
        .summary .ok { background:rgba(34,197,94,0.1); border-color:rgba(34,197,94,0.3); }
        .summary .ok .num { color:#22c55e; font-size:28px; font-weight:900; }
        .summary .err { background:rgba(239,68,68,0.1); border-color:rgba(239,68,68,0.3); }
        .summary .err .num { color:#ef4444; font-size:28px; font-weight:900; }
        .section { margin-bottom:24px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:12px; overflow:hidden; }
        .section h3 { padding:12px 20px; border-bottom:1px solid rgba(255,255,255,0.04); font-size:13px; color:#a78bfa; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; }
        .row { display:flex; align-items:center; padding:8px 20px; border-bottom:1px solid rgba(255,255,255,0.02); font-size:12px; gap:12px; }
        .row:last-child { border-bottom:none; }
        .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .dot.ok { background:#22c55e; }
        .dot.err { background:#ef4444; }
        .row .sql { color:#94a3b8; flex:1; font-family:monospace; font-size:11px; }
        .row .msg { color:#e2e8f0; min-width:200px; text-align:right; }
        .links { text-align:center; margin-top:40px; display:flex; gap:16px; justify-content:center; flex-wrap:wrap; }
        .links a { display:inline-block; padding:14px 28px; border-radius:12px; text-decoration:none; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.05em; color:#fff; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SliceHub — Database Setup</h1>
        <p>Schema: 006–008 (kopie inline) + 012–014 + 016–017 + 020–023 + 024–029 + 021 unified + 021a + 022 (SQL + ALTER-y PHP). Pełny łańcuch plików SQL: apply_migrations_chain.php</p>
    </div>
    <div class="summary">
        <div class="box ok"><div class="num"><?= $totalOk ?></div><div style="color:#64748b;font-size:11px;">OK</div></div>
        <div class="box err"><div class="num"><?= $totalFail ?></div><div style="color:#64748b;font-size:11px;">ERRORS</div></div>
    </div>
    <?php foreach ($results as $sectionName => $steps): ?>
    <div class="section">
        <h3><?= htmlspecialchars($sectionName) ?></h3>
        <?php foreach ($steps as $s): ?>
        <div class="row">
            <div class="dot <?= $s['ok'] ? 'ok' : 'err' ?>"></div>
            <div class="sql"><?= htmlspecialchars($s['sql']) ?></div>
            <div class="msg"><?= htmlspecialchars($s['msg']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="links">
        <a href="/slicehub/scripts/apply_migrations_chain.php" style="background:#22c55e;">Łańcuch migracji SQL (004–034)</a>
        <a href="/slicehub/scripts/seed_demo_all.php" style="background:#a78bfa;">Załaduj dane testowe (Seed All)</a>
        <a href="/slicehub/scripts/seed_scene_kit.php" style="background:#f59e0b;">🎬 Scene Kit Seed (m023)</a>
        <a href="/slicehub/modules/pos/" style="background:#3b82f6;">POS</a>
        <a href="/slicehub/modules/studio/" style="background:#06b6d4;">Studio</a>
        <a href="/slicehub/modules/online_studio/" style="background:#ec4899;">Scene Studio</a>
        <a href="/slicehub/modules/courses/" style="background:#a855f7;">Kursy</a>
    </div>
</body>
</html>
