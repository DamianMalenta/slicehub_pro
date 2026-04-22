-- =============================================================================
-- SliceHub Pro — Migration 016: Visual Compositor Upgrade
--
-- Extends sh_visual_layers with:
--   - product_filename  : hero product photo (standalone, for surface/grid)
--   - cal_scale         : per-layer scale calibration (0.50–2.00)
--   - cal_rotate        : per-layer rotation calibration (-180 to 180 deg)
--
-- Extends sh_board_companions with:
--   - product_filename  : hero product photo for surface materialization
--
-- Adds storefront_surface_bg to tenant settings.
--
-- IDEMPOTENT: safe to re-run (IF NOT EXISTS / ADD COLUMN checks).
-- Respects: tenant_id isolation, soft-delete, DECIMAL money convention.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. sh_visual_layers: hero product photo + calibration
-- ---------------------------------------------------------------------------

-- product_filename: standalone hero photo (e.g. single tomato on transparent bg)
SET @col_exists_vl_pf = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'product_filename'
);
SET @sql_vl_pf = IF(@col_exists_vl_pf = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN product_filename VARCHAR(255) NULL COMMENT ''Hero product photo for surface display and grid thumbnails'' AFTER asset_filename',
  'SELECT ''product_filename already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_pf; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cal_scale: visual calibration scale factor (default 1.00 = no change)
SET @col_exists_vl_cs = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'cal_scale'
);
SET @sql_vl_cs = IF(@col_exists_vl_cs = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN cal_scale DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT ''Visual calibration: scale factor 0.50-2.00'' AFTER product_filename',
  'SELECT ''cal_scale already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_cs; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cal_rotate: visual calibration rotation in degrees
SET @col_exists_vl_cr = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'cal_rotate'
);
SET @sql_vl_cr = IF(@col_exists_vl_cr = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN cal_rotate SMALLINT NOT NULL DEFAULT 0 COMMENT ''Visual calibration: rotation degrees -180 to 180'' AFTER cal_scale',
  'SELECT ''cal_rotate already exists on sh_visual_layers'''
);
PREPARE stmt FROM @sql_vl_cr; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. sh_board_companions: hero product photo
-- ---------------------------------------------------------------------------

SET @col_exists_bc_pf = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_board_companions'
    AND COLUMN_NAME  = 'product_filename'
);
SET @sql_bc_pf = IF(@col_exists_bc_pf = 0,
  'ALTER TABLE sh_board_companions ADD COLUMN product_filename VARCHAR(255) NULL COMMENT ''Top-down hero product photo for surface materialization'' AFTER asset_filename',
  'SELECT ''product_filename already exists on sh_board_companions'''
);
PREPARE stmt FROM @sql_bc_pf; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. sh_tenant_settings: storefront surface background
-- ---------------------------------------------------------------------------

INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT t.id, 'storefront_surface_bg', NULL
FROM sh_tenant t
WHERE NOT EXISTS (
  SELECT 1 FROM sh_tenant_settings ts
  WHERE ts.tenant_id = t.id AND ts.setting_key = 'storefront_surface_bg'
);
