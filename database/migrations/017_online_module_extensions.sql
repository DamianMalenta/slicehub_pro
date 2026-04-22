-- =============================================================================
-- SliceHub Pro — Migration 017: Online Module Extensions
--
-- Adds support for:
--   1. Optimistic locking on sh_visual_layers (version column)
--   2. Library metadata (library_category, library_sub_type) on sh_visual_layers
--   3. Idempotency / checkout locks (sh_checkout_locks table)
--   4. Guest order tracking (tracking_token on sh_orders)
--   5. Default tenant settings for online module
--
-- IDEMPOTENT: safe to re-run (INFORMATION_SCHEMA checks + IF NOT EXISTS).
-- Respects: tenant_id isolation, soft-delete, multi-tenancy.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. sh_visual_layers: version column for optimistic locking in composer
-- ---------------------------------------------------------------------------

SET @col_exists_vl_ver = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'version'
);
SET @sql_vl_ver = IF(@col_exists_vl_ver = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Optimistic locking — incremented on every save'' AFTER is_active',
  'SELECT ''version already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_ver; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. sh_visual_layers: library_category / library_sub_type for filtering UI
-- ---------------------------------------------------------------------------

SET @col_exists_vl_lc = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'library_category'
);
SET @sql_vl_lc = IF(@col_exists_vl_lc = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN library_category VARCHAR(64) NULL COMMENT ''Library filter: base/sauce/cheese/meat/veg/herb/etc'' AFTER layer_sku',
  'SELECT ''library_category already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_lc; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists_vl_lst = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'library_sub_type'
);
SET @sql_vl_lst = IF(@col_exists_vl_lst = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN library_sub_type VARCHAR(64) NULL COMMENT ''Library sub-filter: tomato/mozzarella/pepperoni/etc'' AFTER library_category',
  'SELECT ''library_sub_type already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_lst; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for library browse queries (tenant-scoped category filter)
SET @idx_exists_vl_lib = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND INDEX_NAME   = 'idx_vl_library'
);
SET @sql_vl_lib_idx = IF(@idx_exists_vl_lib = 0,
  'CREATE INDEX idx_vl_library ON sh_visual_layers (tenant_id, library_category, library_sub_type)',
  'SELECT ''idx_vl_library already exists'''
);
PREPARE stmt FROM @sql_vl_lib_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. sh_checkout_locks: idempotency for checkout (prevents double-tap orders)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_checkout_locks (
  lock_token        CHAR(36)        NOT NULL,
  tenant_id         INT UNSIGNED    NOT NULL,
  customer_phone    VARCHAR(32)     NULL,
  cart_hash         CHAR(64)        NOT NULL COMMENT 'SHA-256 of canonicalized cart payload',
  grand_total_grosze BIGINT UNSIGNED NOT NULL DEFAULT 0,
  channel           VARCHAR(16)     NOT NULL DEFAULT 'Delivery',
  expires_at        DATETIME        NOT NULL,
  consumed_at       DATETIME        NULL,
  consumed_order_id CHAR(36)        NULL COMMENT 'sh_orders.id when consumed',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lock_token),
  KEY idx_locks_expires (expires_at),
  KEY idx_locks_phone   (tenant_id, customer_phone),
  KEY idx_locks_hash    (tenant_id, cart_hash),
  CONSTRAINT fk_locks_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotency tokens for online checkout (TTL ~5min)';

-- ---------------------------------------------------------------------------
-- 4. sh_orders: tracking_token for guest order status lookup
-- ---------------------------------------------------------------------------

SET @col_exists_o_track = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_orders'
    AND COLUMN_NAME  = 'tracking_token'
);
SET @sql_o_track = IF(@col_exists_o_track = 0,
  'ALTER TABLE sh_orders ADD COLUMN tracking_token CHAR(16) NULL COMMENT ''Short token for guest tracker URLs (16 hex chars)'' AFTER customer_phone',
  'SELECT ''tracking_token already exists on sh_orders'''
);
PREPARE stmt FROM @sql_o_track; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for tracker lookup (token + phone match)
SET @idx_exists_o_track = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_orders'
    AND INDEX_NAME   = 'idx_orders_tracking'
);
SET @sql_o_track_idx = IF(@idx_exists_o_track = 0,
  'CREATE INDEX idx_orders_tracking ON sh_orders (tracking_token)',
  'SELECT ''idx_orders_tracking already exists'''
);
PREPARE stmt FROM @sql_o_track_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. sh_tenant_settings: default online module flags (per-tenant)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'online_min_order_value', '0.00' FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'online_min_order_value'
);

INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'online_default_eta_min', '30' FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'online_default_eta_min'
);

INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'online_guest_checkout', '1' FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'online_guest_checkout'
);

INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'online_apple_pay_enabled', '0' FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'online_apple_pay_enabled'
);

INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'online_promotion_banner', '' FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'online_promotion_banner'
);
