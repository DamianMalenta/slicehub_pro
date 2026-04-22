-- =============================================================================
-- SliceHub Pro — Migration 013: Board Companions (Cross-sell / Upsell)
--
-- Maps a primary item (pizza) to standalone companion products (drinks, sauces,
-- sides) that appear on the Cinematic Board around the main product.
--
-- Hybrid upsell strategy:
--   1. Modifier groups linked via sh_item_modifiers are auto-detected (zero config)
--   2. This table adds EXPLICIT companions (independent SKUs like Coca-Cola)
--   Both sources are merged by the API into one unified response.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sh_board_companions (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED     NOT NULL,
  item_sku       VARCHAR(255)     NOT NULL COMMENT 'Primary product ascii_key (e.g. PIZZA_DI_PARMA)',
  companion_sku  VARCHAR(255)     NOT NULL COMMENT 'Cross-sell product ascii_key (e.g. COCA_COLA_330)',
  companion_type ENUM('sauce','drink','side','dessert','extra') NOT NULL DEFAULT 'extra',
  board_slot     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Position 0-5 around the pizza on the board',
  asset_filename VARCHAR(255)     NULL     COMMENT 'Optional board-specific top-down image in uploads/visual/{tenant_id}/',
  display_order  INT              NOT NULL DEFAULT 0,
  is_active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME         NULL     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bc_tenant_item_comp (tenant_id, item_sku, companion_sku),
  KEY idx_bc_tenant_item (tenant_id, item_sku),
  CONSTRAINT fk_bc_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
