-- =============================================================================
-- SliceHub Pro — Migration 014: Ingredient Asset Library
--
-- Global per-tenant library of top-down ingredient images. Upload each
-- ingredient image ONCE and it auto-applies across ALL pizzas that use
-- that modifier. Eliminates per-pizza manual image uploads.
--
-- Z-category auto-maps to z-index for stacking:
--   base=0, sauce=10, cheese=20, meat=30, veggie=40, herb=50, finishing=60
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sh_ingredient_assets (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED     NOT NULL,
  ascii_key      VARCHAR(255)     NOT NULL COMMENT 'Matches sh_modifiers.ascii_key or sh_menu_items.ascii_key (for base)',
  asset_filename VARCHAR(255)     NOT NULL COMMENT 'Top-down transparent .webp/.png in uploads/visual/{tenant_id}/',
  z_category     ENUM('base','sauce','cheese','meat','veggie','herb','finishing') NOT NULL DEFAULT 'finishing',
  is_active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME         NULL     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ia_tenant_key (tenant_id, ascii_key),
  KEY idx_ia_tenant (tenant_id),
  CONSTRAINT fk_ia_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
