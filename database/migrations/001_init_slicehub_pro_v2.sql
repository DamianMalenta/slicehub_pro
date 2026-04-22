-- =============================================================================
-- SliceHub Pro V2 — Grand schema init (MySQL 8.0)
-- Reverse-engineered from core/*.php and api/**/*.php in this repository.
-- Run on an EMPTY database (or drop objects below first).
-- =============================================================================

CREATE DATABASE IF NOT EXISTS slicehub_pro_v2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE slicehub_pro_v2;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/* --- Drop (dependency-safe) ------------------------------------------------ */
DROP VIEW IF EXISTS sh_item_prices;
DROP TABLE IF EXISTS wh_stock_logs;
DROP TABLE IF EXISTS wh_document_lines;
DROP TABLE IF EXISTS wh_inventory_doc_items;
DROP TABLE IF EXISTS wh_inventory_docs;
DROP TABLE IF EXISTS wh_documents;
DROP TABLE IF EXISTS wh_stock;
DROP TABLE IF EXISTS sh_order_item_modifiers;
DROP TABLE IF EXISTS sh_order_payments;
DROP TABLE IF EXISTS sh_order_audit;
DROP TABLE IF EXISTS sh_order_lines;
DROP TABLE IF EXISTS sh_kds_tickets;
DROP TABLE IF EXISTS sh_orders;
DROP TABLE IF EXISTS sh_order_sequences;
DROP TABLE IF EXISTS sh_course_sequences;
DROP TABLE IF EXISTS sh_sla_breaches;
DROP TABLE IF EXISTS sh_dispatch_log;
DROP TABLE IF EXISTS sh_panic_log;
DROP TABLE IF EXISTS sh_driver_shifts;
DROP TABLE IF EXISTS sh_work_sessions;
DROP TABLE IF EXISTS sh_deductions;
DROP TABLE IF EXISTS sh_meals;
DROP TABLE IF EXISTS sh_drivers;
DROP TABLE IF EXISTS sh_item_modifiers;
DROP TABLE IF EXISTS sh_modifiers;
DROP TABLE IF EXISTS sh_modifier_groups;
DROP TABLE IF EXISTS sh_recipes;
DROP TABLE IF EXISTS sh_price_tiers;
DROP TABLE IF EXISTS sh_menu_items;
DROP TABLE IF EXISTS sh_categories;
DROP TABLE IF EXISTS sh_product_mapping;
DROP TABLE IF EXISTS sh_promo_codes;
DROP TABLE IF EXISTS sh_delivery_zones;
DROP TABLE IF EXISTS sh_doc_sequences;
DROP TABLE IF EXISTS sys_items;
DROP TABLE IF EXISTS sh_users;
DROP TABLE IF EXISTS sh_tenant_settings;
DROP TABLE IF EXISTS sh_tenant;

SET FOREIGN_KEY_CHECKS = 1;

/* --- CORE & AUTH ----------------------------------------------------------- */
CREATE TABLE sh_tenant (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_tenant_settings (
  tenant_id               INT UNSIGNED NOT NULL,
  setting_key             VARCHAR(64)  NOT NULL DEFAULT '',
  is_active               TINYINT(1)   NULL DEFAULT 1,
  min_order_value         INT          NULL DEFAULT 0 COMMENT 'Grosze',
  opening_hours_json      JSON         NULL,
  min_prep_time_minutes   INT          NULL DEFAULT 30,
  sla_green_min           INT          NULL DEFAULT 10,
  sla_yellow_min          INT          NULL DEFAULT 5,
  base_prep_minutes       INT          NULL DEFAULT 25,
  min_lead_time_minutes   INT          NULL DEFAULT 30,
  setting_value           VARCHAR(255) NULL COMMENT 'KV rows (e.g. half_half_surcharge)',
  PRIMARY KEY (tenant_id, setting_key),
  CONSTRAINT fk_tenant_settings_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_users (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  username       VARCHAR(128) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL DEFAULT '',
  pin_code       VARCHAR(32)  NULL,
  name           VARCHAR(255) NULL,
  first_name     VARCHAR(128) NULL,
  last_name      VARCHAR(128) NULL,
  role           VARCHAR(32)  NOT NULL DEFAULT 'team',
  status         VARCHAR(32)  NOT NULL DEFAULT 'active',
  hourly_rate    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  last_seen      DATETIME     NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  is_deleted     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --- MENU & STUDIO --------------------------------------------------------- */
CREATE TABLE sh_categories (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  is_menu       TINYINT(1)   NOT NULL DEFAULT 1,
  display_order INT          NOT NULL DEFAULT 0,
  is_deleted    TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cat_tenant (tenant_id),
  CONSTRAINT fk_categories_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_menu_items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  category_id        BIGINT UNSIGNED NULL,
  name               VARCHAR(255) NOT NULL,
  ascii_key          VARCHAR(255) NOT NULL,
  `type`             VARCHAR(32)  NOT NULL DEFAULT 'standard',
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  is_deleted         TINYINT(1)   NOT NULL DEFAULT 0,
  display_order      INT          NOT NULL DEFAULT 0,
  vat_rate_dine_in   DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  vat_rate_takeaway  DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  kds_station_id     VARCHAR(64)  NULL,
  publication_status VARCHAR(32)  NULL,
  valid_from         DATE         NULL,
  valid_to           DATE         NULL,
  description        TEXT         NULL,
  image_url          VARCHAR(512) NULL,
  marketing_tags     VARCHAR(512) NULL,
  barcode_ean        VARCHAR(64)  NULL,
  parent_sku         VARCHAR(255) NULL,
  allergens_json     JSON         NULL,
  badge_type         VARCHAR(32)  NULL,
  is_secret          TINYINT(1)   NOT NULL DEFAULT 0,
  stock_count        INT          NULL DEFAULT 0,
  is_locked_by_hq    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_tenant_ascii (tenant_id, ascii_key),
  KEY idx_menu_category (category_id),
  CONSTRAINT fk_menu_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_menu_category
    FOREIGN KEY (category_id) REFERENCES sh_categories (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_modifier_groups (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          INT UNSIGNED NOT NULL,
  name               VARCHAR(255) NOT NULL,
  ascii_key          VARCHAR(255) NULL,
  min_selection      INT NOT NULL DEFAULT 0,
  max_selection      INT NOT NULL DEFAULT 0,
  free_limit         INT NOT NULL DEFAULT 0,
  allow_multi_qty    TINYINT(1) NOT NULL DEFAULT 0,
  publication_status VARCHAR(32) NULL,
  valid_from         DATE NULL,
  valid_to           DATE NULL,
  is_locked_by_hq    TINYINT(1) NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted         TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_modgrp_tenant (tenant_id),
  CONSTRAINT fk_modgrp_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_modifiers (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id              BIGINT UNSIGNED NOT NULL,
  name                  VARCHAR(255) NOT NULL,
  ascii_key             VARCHAR(255) NOT NULL,
  action_type           VARCHAR(32)  NOT NULL DEFAULT 'ADD',
  linked_warehouse_sku  VARCHAR(128) NULL,
  linked_quantity       DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  linked_waste_percent  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  price                 DECIMAL(10,2) NULL COMMENT 'Optional denorm; tiers are canonical',
  is_default            TINYINT(1) NOT NULL DEFAULT 0,
  is_deleted            TINYINT(1) NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_mod_group (group_id),
  KEY idx_mod_ascii (ascii_key),
  CONSTRAINT fk_mod_group
    FOREIGN KEY (group_id) REFERENCES sh_modifier_groups (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_item_modifiers (
  item_id  BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (item_id, group_id),
  CONSTRAINT fk_im_item
    FOREIGN KEY (item_id) REFERENCES sh_menu_items (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_im_group
    FOREIGN KEY (group_id) REFERENCES sh_modifier_groups (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_price_tiers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global / HQ default',
  target_type VARCHAR(16)  NOT NULL,
  target_sku  VARCHAR(255) NOT NULL,
  channel     VARCHAR(32)  NOT NULL,
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_tier (target_type, target_sku, channel, tenant_id),
  KEY idx_price_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW sh_item_prices AS
SELECT
  tenant_id,
  target_sku  AS item_sku,
  channel,
  price
FROM sh_price_tiers
WHERE target_type = 'ITEM';

CREATE TABLE sh_recipes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  menu_item_sku  VARCHAR(255) NOT NULL COMMENT 'Logical item_sku (ascii_key); FK below',
  warehouse_sku  VARCHAR(128) NOT NULL,
  quantity_base  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  waste_percent    DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  is_packaging     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_line (tenant_id, menu_item_sku, warehouse_sku),
  KEY idx_recipe_tenant_item (tenant_id, menu_item_sku),
  CONSTRAINT fk_recipes_menu_ascii
    FOREIGN KEY (tenant_id, menu_item_sku)
    REFERENCES sh_menu_items (tenant_id, ascii_key)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  sku        VARCHAR(128) NOT NULL,
  name       VARCHAR(255) NOT NULL,
  base_unit  VARCHAR(32)  NOT NULL DEFAULT 'pcs',
  PRIMARY KEY (id),
  UNIQUE KEY uq_sys_items_tenant_sku (tenant_id, sku),
  CONSTRAINT fk_sys_items_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_product_mapping (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  external_name VARCHAR(255) NOT NULL,
  internal_sku  VARCHAR(128) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mapping (tenant_id, external_name(191)),
  CONSTRAINT fk_mapping_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_promo_codes (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        INT UNSIGNED NOT NULL,
  code             VARCHAR(64) NOT NULL,
  type             VARCHAR(32) NOT NULL,
  value            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  min_order_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_uses         INT NOT NULL DEFAULT 0,
  current_uses     INT NOT NULL DEFAULT 0,
  valid_from       DATETIME NOT NULL,
  valid_to         DATETIME NOT NULL,
  allowed_channels TEXT NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo (tenant_id, code),
  CONSTRAINT fk_promo_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --- ORDERS & FLEET -------------------------------------------------------- */
CREATE TABLE sh_orders (
  id                    CHAR(36) NOT NULL,
  tenant_id             INT UNSIGNED NOT NULL,
  order_number          VARCHAR(64) NOT NULL,
  channel               VARCHAR(32) NOT NULL,
  order_type            VARCHAR(32) NOT NULL,
  source                VARCHAR(32) NULL,
  subtotal              INT NOT NULL DEFAULT 0 COMMENT 'Grosze',
  discount_amount       INT NOT NULL DEFAULT 0,
  delivery_fee          INT NOT NULL DEFAULT 0,
  grand_total           INT NOT NULL DEFAULT 0,
  status                VARCHAR(32) NOT NULL DEFAULT 'new',
  payment_status        VARCHAR(32) NOT NULL DEFAULT 'unpaid',
  payment_method        VARCHAR(32) NULL,
  loyalty_points_earned INT NOT NULL DEFAULT 0,
  customer_name         VARCHAR(255) NULL,
  customer_phone        VARCHAR(32) NULL,
  delivery_address      TEXT NULL,
  lat                   DECIMAL(10,7) NULL,
  lng                   DECIMAL(10,7) NULL,
  promised_time         DATETIME NULL,
  user_id               BIGINT UNSIGNED NULL,
  driver_id             VARCHAR(64) NULL,
  course_id             VARCHAR(32) NULL,
  stop_number           VARCHAR(16) NULL,
  tip_amount            INT NOT NULL DEFAULT 0,
  edited_since_print    TINYINT(1) NOT NULL DEFAULT 0,
  kitchen_delta         JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_orders_tenant_status (tenant_id, status),
  CONSTRAINT fk_orders_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_kds_tickets (
  id          CHAR(36) NOT NULL,
  tenant_id   INT UNSIGNED NOT NULL,
  order_id    CHAR(36) NOT NULL,
  station_id  VARCHAR(64) NOT NULL,
  status      VARCHAR(32) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (id),
  KEY idx_kds_order (order_id),
  CONSTRAINT fk_kds_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_kds_order
    FOREIGN KEY (order_id) REFERENCES sh_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_order_lines (
  id                       CHAR(36) NOT NULL,
  order_id                 CHAR(36) NOT NULL,
  item_sku                 VARCHAR(255) NOT NULL,
  snapshot_name            VARCHAR(255) NOT NULL,
  unit_price               INT NOT NULL DEFAULT 0 COMMENT 'Grosze',
  quantity                 INT NOT NULL DEFAULT 1,
  line_total               INT NOT NULL DEFAULT 0,
  vat_rate                 DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  vat_amount               INT NOT NULL DEFAULT 0,
  modifiers_json           JSON NULL,
  removed_ingredients_json JSON NULL,
  comment                  VARCHAR(512) NULL,
  kds_ticket_id            CHAR(36) NULL,
  PRIMARY KEY (id),
  KEY idx_lines_order (order_id),
  KEY idx_lines_ticket (kds_ticket_id),
  CONSTRAINT fk_lines_order
    FOREIGN KEY (order_id) REFERENCES sh_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_lines_kds
    FOREIGN KEY (kds_ticket_id) REFERENCES sh_kds_tickets (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_order_audit (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    CHAR(36) NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  old_status  VARCHAR(32) NULL,
  new_status  VARCHAR(32) NOT NULL,
  timestamp   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_order (order_id),
  CONSTRAINT fk_audit_order
    FOREIGN KEY (order_id) REFERENCES sh_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_order_payments (
  id               CHAR(36) NOT NULL,
  order_id         CHAR(36) NOT NULL,
  tenant_id        INT UNSIGNED NOT NULL,
  method           VARCHAR(32) NOT NULL,
  amount_grosze    INT NOT NULL,
  tendered_grosze  INT NOT NULL,
  transaction_id   VARCHAR(128) NULL,
  PRIMARY KEY (id),
  KEY idx_pay_order (order_id),
  CONSTRAINT fk_pay_order
    FOREIGN KEY (order_id) REFERENCES sh_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pay_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_order_sequences (
  tenant_id INT UNSIGNED NOT NULL,
  `date`    DATE NOT NULL,
  seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, `date`),
  CONSTRAINT fk_order_seq_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_course_sequences (
  tenant_id INT UNSIGNED NOT NULL,
  `date`    DATE NOT NULL,
  seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, `date`),
  CONSTRAINT fk_course_seq_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_doc_sequences (
  tenant_id INT UNSIGNED NOT NULL,
  doc_type  VARCHAR(16) NOT NULL,
  doc_date  DATE NOT NULL,
  seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, doc_type, doc_date),
  CONSTRAINT fk_docseq_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_delivery_zones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  name          VARCHAR(128) NULL,
  zone_polygon  POLYGON NOT NULL COMMENT 'Use SRID consistent with ST_GeomFromText in app (MySQL 8+ can add SRID 4326 via ALTER)',
  PRIMARY KEY (id),
  SPATIAL KEY idx_zone_poly (zone_polygon),
  KEY idx_zone_tenant (tenant_id),
  CONSTRAINT fk_zone_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_sla_breaches (
  id              CHAR(36) NOT NULL,
  tenant_id       INT UNSIGNED NOT NULL,
  order_id        CHAR(36) NOT NULL,
  breach_minutes  INT NOT NULL,
  driver_id       VARCHAR(64) NULL,
  course_id       VARCHAR(32) NULL,
  logged_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sla_order (tenant_id, order_id),
  CONSTRAINT fk_sla_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sla_order
    FOREIGN KEY (order_id) REFERENCES sh_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_panic_log (
  id             CHAR(36) NOT NULL,
  tenant_id      INT UNSIGNED NOT NULL,
  triggered_by   BIGINT UNSIGNED NULL,
  delay_minutes  INT NOT NULL,
  affected_count INT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_panic_tenant_time (tenant_id, created_at),
  CONSTRAINT fk_panic_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_dispatch_log (
  id             CHAR(36) NOT NULL,
  tenant_id      INT UNSIGNED NOT NULL,
  course_id      VARCHAR(32) NOT NULL,
  driver_id      VARCHAR(64) NOT NULL,
  order_ids_json JSON NOT NULL,
  dispatched_by  BIGINT UNSIGNED NOT NULL,
  dispatched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dispatch_tenant (tenant_id),
  CONSTRAINT fk_dispatch_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_order_item_modifiers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id  CHAR(36) NOT NULL,
  modifier_type  VARCHAR(16) NOT NULL,
  modifier_sku   VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_oim_line (order_item_id),
  CONSTRAINT fk_oim_line
    FOREIGN KEY (order_item_id) REFERENCES sh_order_lines (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --- STAFF & HR ------------------------------------------------------------ */
CREATE TABLE sh_drivers (
  user_id   BIGINT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  status    VARCHAR(32) NOT NULL DEFAULT 'offline',
  PRIMARY KEY (tenant_id, user_id),
  CONSTRAINT fk_drivers_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_drivers_user
    FOREIGN KEY (user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_work_sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_uuid  CHAR(36) NOT NULL,
  tenant_id     INT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  start_time    DATETIME NOT NULL,
  end_time      DATETIME NULL,
  total_hours   DECIMAL(10,4) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_uuid (session_uuid),
  KEY idx_ws_user_open (tenant_id, user_id, end_time),
  CONSTRAINT fk_ws_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ws_user
    FOREIGN KEY (user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_deductions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       VARCHAR(64) NOT NULL,
  amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ded_user (tenant_id, user_id, created_at),
  CONSTRAINT fk_ded_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ded_user
    FOREIGN KEY (user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_meals (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  employee_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_meals_user (tenant_id, user_id, created_at),
  CONSTRAINT fk_meals_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_meals_user
    FOREIGN KEY (user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_driver_shifts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  driver_id     VARCHAR(64) NOT NULL,
  initial_cash  INT NOT NULL DEFAULT 0 COMMENT 'Grosze',
  counted_cash  INT NULL,
  variance      INT NULL,
  status        VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shift_driver (tenant_id, driver_id, status),
  CONSTRAINT fk_shift_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --- WAREHOUSE V2 + legacy inventory docs (WzEngine / api_warehouse) -------- */
CREATE TABLE wh_stock (
  tenant_id          INT UNSIGNED NOT NULL,
  warehouse_id       VARCHAR(64) NOT NULL,
  sku                VARCHAR(128) NOT NULL,
  quantity           DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  current_avco_price DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  unit_net_cost      DECIMAL(10,4) NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, warehouse_id, sku),
  CONSTRAINT fk_wh_stock_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wh_documents (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id               INT UNSIGNED NOT NULL,
  doc_number              VARCHAR(64) NOT NULL,
  type                    VARCHAR(16) NOT NULL,
  warehouse_id            VARCHAR(64) NULL,
  target_warehouse_id     VARCHAR(64) NULL,
  order_id                CHAR(36) NULL,
  references_wz           VARCHAR(64) NULL,
  status                  VARCHAR(32) NOT NULL DEFAULT 'completed',
  required_approval_level VARCHAR(32) NULL,
  supplier_name           VARCHAR(255) NULL,
  supplier_invoice        VARCHAR(128) NULL,
  notes                   TEXT NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by              BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_whdoc_tenant_type (tenant_id, type),
  CONSTRAINT fk_whdoc_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wh_document_lines (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id    BIGINT UNSIGNED NOT NULL,
  sku            VARCHAR(128) NOT NULL,
  quantity       DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  system_qty       DECIMAL(12,4) NULL,
  counted_qty      DECIMAL(12,4) NULL,
  variance         DECIMAL(12,4) NULL,
  unit_net_cost    DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  line_net_value   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate         DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  old_avco         DECIMAL(10,4) NULL,
  new_avco         DECIMAL(10,4) NULL,
  PRIMARY KEY (id),
  KEY idx_whline_doc (document_id),
  CONSTRAINT fk_whline_doc
    FOREIGN KEY (document_id) REFERENCES wh_documents (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wh_stock_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  warehouse_id   VARCHAR(64) NOT NULL,
  sku            VARCHAR(128) NOT NULL,
  change_qty     DECIMAL(12,4) NOT NULL,
  after_qty      DECIMAL(12,4) NOT NULL,
  document_type  VARCHAR(16) NOT NULL,
  document_id    BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_whlog_doc (document_type, document_id),
  CONSTRAINT fk_whlog_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wh_inventory_docs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   INT UNSIGNED NOT NULL,
  doc_number  VARCHAR(64) NOT NULL,
  doc_type    VARCHAR(16) NOT NULL,
  status      VARCHAR(32) NOT NULL DEFAULT 'COMPLETED',
  notes       TEXT NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_whinv_tenant (tenant_id),
  CONSTRAINT fk_whinv_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wh_inventory_doc_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_id      BIGINT UNSIGNED NOT NULL,
  sku         VARCHAR(128) NOT NULL,
  qty         DECIMAL(12,4) NOT NULL,
  unit_price  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_whinvitem_doc (doc_id),
  CONSTRAINT fk_whinvitem_doc
    FOREIGN KEY (doc_id) REFERENCES wh_inventory_docs (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* --- Minimal seed (optional demo tenant) ----------------------------------- */
INSERT INTO sh_tenant (id, name) VALUES (1, 'Demo Tenant');
INSERT INTO sh_tenant_settings (
  tenant_id, setting_key, is_active, min_order_value, opening_hours_json,
  min_prep_time_minutes, sla_green_min, sla_yellow_min,
  base_prep_minutes, min_lead_time_minutes, setting_value
) VALUES (
  1, '', 1, 0, JSON_OBJECT('monday', JSON_OBJECT('closed', false, 'open', '10:00', 'close', '22:00')),
  30, 10, 5, 25, 30, NULL
);
INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
VALUES (1, 'half_half_surcharge', '2.00');
