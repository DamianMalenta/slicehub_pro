<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Dine-In Foundation: Enterprise Table Architecture
// scripts/setup_enterprise_tables.php
//
// ⚠ LEGACY HELPER (od migracji m037 ten skrypt jest zduplikowany przez SQL).
//    Kanonem jest teraz `database/migrations/037_pos_foundation.sql` uruchamiany
//    przez `scripts/apply_migrations_chain.php`. Ten plik zostaje tylko jako
//    awaryjny "one-click fix" dla baz, które z jakiegoś powodu nie przeszły
//    przez chain (np. reset bazy bez uruchomienia pełnego łańcucha migracji).
//
// Creates:   sh_zones, sh_tables, sh_order_logs
// Enhances:  sh_order_payments (add created_at if missing)
//            sh_orders (add table_id, waiter_id, guest_count, split_type, qr_session_token)
//            sh_order_lines (add course_number, fired_at)
//
// SAFE TO RE-RUN (idempotent — all DDL guarded by information_schema checks).
// =============================================================================

require_once __DIR__ . '/../core/db_config.php';

$dbname = 'slicehub_pro_v2';

function columnExists(PDO $pdo, string $db, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col"
    );
    $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl"
    );
    $stmt->execute([':db' => $db, ':tbl' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $db, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :idx"
    );
    $stmt->execute([':db' => $db, ':tbl' => $table, ':idx' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

$log = [];

// =========================================================================
// 1. sh_zones — Physical restaurant zones (sala, ogródek, bar, VIP)
// =========================================================================
if (!tableExists($pdo, $dbname, 'sh_zones')) {
    $pdo->exec("
        CREATE TABLE sh_zones (
          id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          tenant_id      INT UNSIGNED NOT NULL,
          name           VARCHAR(128) NOT NULL,
          display_order  INT NOT NULL DEFAULT 0,
          is_active      TINYINT(1) NOT NULL DEFAULT 1,
          created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_zones_tenant (tenant_id),
          UNIQUE KEY uq_zone_name (tenant_id, name),
          CONSTRAINT fk_zones_tenant
            FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
            ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = 'CREATED sh_zones';
} else {
    $log[] = 'SKIP sh_zones (exists)';
}

// =========================================================================
// 2. sh_tables — Physical tables with floor-plan coordinates, QR, merging
// =========================================================================
if (!tableExists($pdo, $dbname, 'sh_tables')) {
    $pdo->exec("
        CREATE TABLE sh_tables (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          tenant_id        INT UNSIGNED NOT NULL,
          zone_id          BIGINT UNSIGNED NULL,
          table_number     VARCHAR(16) NOT NULL,
          seats            TINYINT UNSIGNED NOT NULL DEFAULT 4,
          shape            VARCHAR(16) NOT NULL DEFAULT 'square' COMMENT 'square|round|rectangle',
          pos_x            SMALLINT NOT NULL DEFAULT 0 COMMENT 'Floor-plan X coordinate (px)',
          pos_y            SMALLINT NOT NULL DEFAULT 0 COMMENT 'Floor-plan Y coordinate (px)',
          qr_hash          VARCHAR(128) NULL COMMENT 'Unique QR code hash for self-order',
          parent_table_id  BIGINT UNSIGNED NULL COMMENT 'Non-NULL = merged into parent',
          physical_status  VARCHAR(32) NOT NULL DEFAULT 'free' COMMENT 'free|occupied|reserved|dirty|merged',
          is_active        TINYINT(1) NOT NULL DEFAULT 1,
          created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_tables_tenant (tenant_id),
          KEY idx_tables_zone (zone_id),
          KEY idx_tables_parent (parent_table_id),
          UNIQUE KEY uq_table_number (tenant_id, table_number),
          UNIQUE KEY uq_table_qr (qr_hash),
          CONSTRAINT fk_tables_tenant
            FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
            ON UPDATE CASCADE ON DELETE CASCADE,
          CONSTRAINT fk_tables_zone
            FOREIGN KEY (zone_id) REFERENCES sh_zones (id)
            ON UPDATE CASCADE ON DELETE SET NULL,
          CONSTRAINT fk_tables_parent
            FOREIGN KEY (parent_table_id) REFERENCES sh_tables (id)
            ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = 'CREATED sh_tables';
} else {
    $log[] = 'SKIP sh_tables (exists)';
}

// =========================================================================
// 3. sh_order_logs — Detailed audit trail for every state change
// =========================================================================
if (!tableExists($pdo, $dbname, 'sh_order_logs')) {
    $pdo->exec("
        CREATE TABLE sh_order_logs (
          id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id    CHAR(36) NULL COMMENT 'NULL for table-level ops (merge/unmerge)',
          tenant_id   INT UNSIGNED NOT NULL,
          user_id     BIGINT UNSIGNED NULL,
          action      VARCHAR(64) NOT NULL COMMENT 'state_change|payment|merge|split|fire_course|etc',
          detail_json JSON NULL COMMENT 'Structured payload of the action',
          created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_logs_order (order_id),
          KEY idx_order_logs_tenant_time (tenant_id, created_at),
          CONSTRAINT fk_order_logs_tenant
            FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
            ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = 'CREATED sh_order_logs';
} else {
    // FIX-6: Make order_id nullable and drop the FK that blocks table-level log entries
    try {
        $pdo->exec("ALTER TABLE sh_order_logs DROP FOREIGN KEY fk_order_logs_order");
        $log[] = 'DROPPED FK fk_order_logs_order';
    } catch (\PDOException $e) {
        $log[] = 'SKIP DROP FK fk_order_logs_order (already gone or never existed)';
    }
    try {
        $pdo->exec("ALTER TABLE sh_order_logs MODIFY order_id CHAR(36) NULL");
        $log[] = 'MODIFIED sh_order_logs.order_id → nullable';
    } catch (\PDOException $e) {
        $log[] = 'SKIP MODIFY sh_order_logs.order_id (' . $e->getMessage() . ')';
    }
}

// =========================================================================
// 4. Enhance sh_order_payments — add created_at if missing
// =========================================================================
if (tableExists($pdo, $dbname, 'sh_order_payments')) {
    if (!columnExists($pdo, $dbname, 'sh_order_payments', 'created_at')) {
        $pdo->exec("ALTER TABLE sh_order_payments ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $log[] = 'ADDED sh_order_payments.created_at';
    }
    if (!columnExists($pdo, $dbname, 'sh_order_payments', 'payment_method')) {
        $pdo->exec("ALTER TABLE sh_order_payments ADD COLUMN payment_method VARCHAR(32) NULL AFTER method");
        $log[] = 'ADDED sh_order_payments.payment_method';
    }
    if (!columnExists($pdo, $dbname, 'sh_order_payments', 'user_id')) {
        $pdo->exec("ALTER TABLE sh_order_payments ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER tenant_id");
        $pdo->exec("CREATE INDEX idx_pay_user ON sh_order_payments (tenant_id, user_id, method)");
        $log[] = 'ADDED sh_order_payments.user_id + index';
    }
} else {
    $log[] = 'WARN sh_order_payments does not exist — run 001_init first';
}

// =========================================================================
// 5. Enhance sh_orders — dine-in columns
// =========================================================================
$orderAlters = [
    'table_id'         => "ADD COLUMN table_id BIGINT UNSIGNED NULL AFTER order_type",
    'waiter_id'        => "ADD COLUMN waiter_id BIGINT UNSIGNED NULL AFTER table_id",
    'guest_count'      => "ADD COLUMN guest_count TINYINT UNSIGNED NULL DEFAULT NULL AFTER waiter_id",
    'split_type'       => "ADD COLUMN split_type VARCHAR(32) NULL DEFAULT NULL COMMENT 'equal|by_item|custom' AFTER guest_count",
    'qr_session_token' => "ADD COLUMN qr_session_token VARCHAR(128) NULL AFTER split_type",
];

foreach ($orderAlters as $col => $ddl) {
    if (!columnExists($pdo, $dbname, 'sh_orders', $col)) {
        $pdo->exec("ALTER TABLE sh_orders {$ddl}");
        $log[] = "ADDED sh_orders.{$col}";
    } else {
        $log[] = "SKIP sh_orders.{$col} (exists)";
    }
}

// FK: sh_orders.table_id -> sh_tables.id
if (!indexExists($pdo, $dbname, 'sh_orders', 'fk_orders_table')) {
    try {
        $pdo->exec("ALTER TABLE sh_orders ADD CONSTRAINT fk_orders_table
                     FOREIGN KEY (table_id) REFERENCES sh_tables (id)
                     ON UPDATE CASCADE ON DELETE SET NULL");
        $log[] = 'ADDED FK sh_orders.table_id -> sh_tables.id';
    } catch (\PDOException $e) {
        $log[] = 'SKIP FK sh_orders.table_id (error: ' . $e->getMessage() . ')';
    }
}

// FK: sh_orders.waiter_id -> sh_users.id
if (!indexExists($pdo, $dbname, 'sh_orders', 'fk_orders_waiter')) {
    try {
        $pdo->exec("ALTER TABLE sh_orders ADD CONSTRAINT fk_orders_waiter
                     FOREIGN KEY (waiter_id) REFERENCES sh_users (id)
                     ON UPDATE CASCADE ON DELETE SET NULL");
        $log[] = 'ADDED FK sh_orders.waiter_id -> sh_users.id';
    } catch (\PDOException $e) {
        $log[] = 'SKIP FK sh_orders.waiter_id (error: ' . $e->getMessage() . ')';
    }
}

// Index for dine-in table lookups
if (!indexExists($pdo, $dbname, 'sh_orders', 'idx_orders_table')) {
    $pdo->exec("CREATE INDEX idx_orders_table ON sh_orders (tenant_id, table_id)");
    $log[] = 'ADDED INDEX idx_orders_table';
}

// =========================================================================
// 6. Enhance sh_order_lines — multi-course pacing
// =========================================================================
$lineAlters = [
    'course_number' => "ADD COLUMN course_number INT NOT NULL DEFAULT 1 COMMENT 'Course sequence for pacing'",
    'fired_at'      => "ADD COLUMN fired_at DATETIME NULL COMMENT 'Timestamp when course was fired to KDS'",
];

foreach ($lineAlters as $col => $ddl) {
    if (!columnExists($pdo, $dbname, 'sh_order_lines', $col)) {
        $pdo->exec("ALTER TABLE sh_order_lines {$ddl}");
        $log[] = "ADDED sh_order_lines.{$col}";
    } else {
        $log[] = "SKIP sh_order_lines.{$col} (exists)";
    }
}

if (!indexExists($pdo, $dbname, 'sh_order_lines', 'idx_lines_course')) {
    $pdo->exec("CREATE INDEX idx_lines_course ON sh_order_lines (order_id, course_number)");
    $log[] = 'ADDED INDEX idx_lines_course';
}

// =========================================================================
// 7. FIX-2: Anti-ghosting — prevent two active orders on the same table
//    Uses a STORED generated column that is non-NULL only for active+tabled
//    orders, then a UNIQUE index on (tenant_id, _active_table_guard).
//    MySQL ignores NULLs in unique indexes → completed/cancelled/non-table
//    orders are excluded automatically.
// =========================================================================
if (!columnExists($pdo, $dbname, 'sh_orders', '_active_table_guard')) {
    try {
        $pdo->exec("
            ALTER TABLE sh_orders
              ADD COLUMN _active_table_guard BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                  CASE WHEN status NOT IN ('completed','cancelled') AND table_id IS NOT NULL
                       THEN table_id
                       ELSE NULL
                  END
                ) STORED
        ");
        $log[] = 'ADDED sh_orders._active_table_guard (generated column)';
    } catch (\PDOException $e) {
        $log[] = 'SKIP _active_table_guard (' . $e->getMessage() . ')';
    }
}
if (!indexExists($pdo, $dbname, 'sh_orders', 'uq_one_active_order_per_table')) {
    try {
        $pdo->exec("
            CREATE UNIQUE INDEX uq_one_active_order_per_table
              ON sh_orders (tenant_id, _active_table_guard)
        ");
        $log[] = 'ADDED UNIQUE INDEX uq_one_active_order_per_table';
    } catch (\PDOException $e) {
        $log[] = 'SKIP uq_one_active_order_per_table (' . $e->getMessage() . ')';
    }
}

// =========================================================================
// Summary
// =========================================================================
echo json_encode([
    'success' => true,
    'message' => 'Enterprise Dine-In foundation setup complete.',
    'log'     => $log,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
