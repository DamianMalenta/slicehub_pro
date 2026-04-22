-- =============================================================================
-- SliceHub Pro — Migration 012: Visual Layers (Exploded View, Pizza MVP)
--
-- Relational 1:1 mapping:  modifier_sku  →  webp asset  →  z_index
-- One row per visual layer per menu item per tenant. No JSON blobs.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sh_visual_layers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED    NOT NULL,
  item_sku        VARCHAR(255)    NOT NULL COMMENT 'sh_menu_items.ascii_key — the product being configured',
  layer_sku       VARCHAR(255)    NOT NULL COMMENT 'item_sku for base layer, sh_modifiers.ascii_key for toppings',
  asset_filename  VARCHAR(255)    NOT NULL COMMENT 'File in uploads/visual/{tenant_id}/',
  z_index         INT             NOT NULL DEFAULT 0 COMMENT 'Stacking order: higher = on top',
  is_base         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = base product layer (dough), 0 = modifier layer',
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vl_tenant_item_layer (tenant_id, item_sku, layer_sku),
  KEY idx_vl_tenant_item (tenant_id, item_sku),
  CONSTRAINT fk_vl_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
