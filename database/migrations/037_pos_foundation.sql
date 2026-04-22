-- =============================================================================
-- SliceHub Pro — Migration 037: POS / Dine-In Foundation
-- -----------------------------------------------------------------------------
-- Cel: przeniesienie całej logiki dine-in z `scripts/setup_enterprise_tables.php`
--      do kanonicznej migracji SQL. Po zastosowaniu tej migracji świeża
--      instalacja u nowego klienta dostaje te struktury przez normalny
--      `apply_migrations_chain.php`, bez potrzeby ręcznego odpalania skryptu PHP.
--
-- Zakres:
--   1. sh_zones        — strefy restauracji (sala/ogródek/bar/VIP)
--   2. sh_tables       — stoliki (floor-plan coords, QR, merging)
--   3. sh_order_logs   — audit trail stanów zamówień (używane przez OrderStateMachine)
--   4. sh_order_payments  — ext: created_at, payment_method, user_id + indeks
--   5. sh_orders       — ext: table_id, waiter_id, guest_count, split_type,
--                        qr_session_token + 2 FK + indeks
--   6. sh_order_lines  — ext: course_number, fired_at + indeks (multi-course pacing)
--   7. Anti-ghosting   — generated column `_active_table_guard` + unique index
--                        gwarantuje max 1 aktywne zamówienie per stolik
--
-- IDEMPOTENT. Safe to re-run. Kompatybilne z MariaDB 10.4+ (bez `ADD CONSTRAINT
-- IF NOT EXISTS` i `SKIP LOCKED`, które wymagają 10.6+).
--
-- Uwaga: `scripts/setup_enterprise_tables.php` zostaje jako legacy helper dla
-- baz sprzed ery migracji; nowy kanon to ten plik.
-- =============================================================================

SET @dbname = DATABASE();

-- =============================================================================
-- 1. sh_zones — Physical restaurant zones
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_zones (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. sh_tables — Physical tables with floor-plan, QR, merging
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_tables (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. sh_order_logs — Audit trail for state changes
--    Uwaga: order_id jest NULL-able (pozwala logować operacje table-level:
--    merge/unmerge) i BEZ FK do sh_orders (bo zamówienia UUID, kasowanie po SLA).
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_order_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. sh_order_payments — rozszerzenie o created_at, payment_method, user_id
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_payments' AND COLUMN_NAME = 'created_at';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_order_payments ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_payments' AND COLUMN_NAME = 'payment_method';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_order_payments ADD COLUMN payment_method VARCHAR(32) NULL AFTER method',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_payments' AND COLUMN_NAME = 'user_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_order_payments ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER tenant_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_payments' AND INDEX_NAME = 'idx_pay_user';
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_pay_user ON sh_order_payments (tenant_id, user_id, method)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 5. sh_orders — dine-in kolumny
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'table_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN table_id BIGINT UNSIGNED NULL AFTER order_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'waiter_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN waiter_id BIGINT UNSIGNED NULL AFTER table_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'guest_count';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN guest_count TINYINT UNSIGNED NULL DEFAULT NULL AFTER waiter_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'split_type';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_orders ADD COLUMN split_type VARCHAR(32) NULL DEFAULT NULL COMMENT 'equal|by_item|custom' AFTER guest_count",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'qr_session_token';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN qr_session_token VARCHAR(128) NULL AFTER split_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK: sh_orders.table_id -> sh_tables.id
SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND CONSTRAINT_NAME = 'fk_orders_table';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_orders ADD CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES sh_tables (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK: sh_orders.waiter_id -> sh_users.id
SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND CONSTRAINT_NAME = 'fk_orders_waiter';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_orders ADD CONSTRAINT fk_orders_waiter FOREIGN KEY (waiter_id) REFERENCES sh_users (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index: dine-in table lookup
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND INDEX_NAME = 'idx_orders_table';
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_orders_table ON sh_orders (tenant_id, table_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 6. sh_order_lines — multi-course pacing
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_lines' AND COLUMN_NAME = 'course_number';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_order_lines ADD COLUMN course_number INT NOT NULL DEFAULT 1 COMMENT 'Course sequence for pacing'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_lines' AND COLUMN_NAME = 'fired_at';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_order_lines ADD COLUMN fired_at DATETIME NULL COMMENT 'Timestamp when course was fired to KDS'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_lines' AND INDEX_NAME = 'idx_lines_course';
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_lines_course ON sh_order_lines (order_id, course_number)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 7. Anti-ghosting — zagwarantowanie max 1 aktywnego zamówienia per stolik
--    Rozwiązanie: STORED generated column która jest non-NULL tylko dla
--    aktywnych+stolikowych zamówień, plus UNIQUE INDEX. MySQL/MariaDB ignorują
--    NULL-e w unique indexach → completed/cancelled/non-table są wykluczone.
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = '_active_table_guard';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_orders ADD COLUMN _active_table_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status NOT IN ('completed','cancelled') AND table_id IS NOT NULL THEN table_id ELSE NULL END) STORED",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND INDEX_NAME = 'uq_one_active_order_per_table';
SET @sql = IF(@idx_exists = 0,
  'CREATE UNIQUE INDEX uq_one_active_order_per_table ON sh_orders (tenant_id, _active_table_guard)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
