-- =============================================================================
-- SliceHub Pro — Migration 014: Global Assets Library
--
-- Stores metadata for photorealistic .webp layer assets used by the
-- Omnichannel Visual Configurator. Assets live on disk at
-- /uploads/global_assets/{filename} and are shared across tenants
-- (tenant_id = 0 for global, or scoped to a specific tenant).
--
-- Categories: board, base, sauce, cheese, meat, veg, herb
-- The rendering engine stacks layers by z_order (ascending).
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sh_global_assets (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '0 = global shared asset',
  ascii_key      VARCHAR(255)     NOT NULL COMMENT 'Technical key, e.g. meat_salami_layer_1',
  category       ENUM('board','base','sauce','cheese','meat','veg','herb','misc')
                                  NOT NULL DEFAULT 'misc',
  sub_type       VARCHAR(64)      NULL     COMMENT 'Ingredient sub-type: salami, bacon, mozzarella...',
  filename       VARCHAR(255)     NOT NULL COMMENT 'File on disk in /uploads/global_assets/',
  width          INT UNSIGNED     NOT NULL DEFAULT 0,
  height         INT UNSIGNED     NOT NULL DEFAULT 0,
  has_alpha      TINYINT(1)       NOT NULL DEFAULT 1,
  filesize_bytes INT UNSIGNED     NOT NULL DEFAULT 0,
  z_order        INT              NOT NULL DEFAULT 50 COMMENT 'Default stacking order (lower = behind)',
  target_px      INT UNSIGNED     NOT NULL DEFAULT 500 COMMENT 'Longest-edge target resolution',
  is_active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME         NULL     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ga_tenant_key (tenant_id, ascii_key),
  KEY idx_ga_category (category),
  KEY idx_ga_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
