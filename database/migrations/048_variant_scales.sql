-- =============================================================================
-- 048_variant_scales.sql — Faza F-S1 (2026-05-11)
--
-- Model wariantów rozmiaru dla menu items (Pizza Mała / Średnia / Duża etc.).
--
-- Architektura iiko-style: REUŻYWALNA `Size Scale` z `multiplier` per opcja.
-- JEDNA receptura na parent → WzEngine mnoży przez `multiplier(option)`.
--
-- Bez tej migracji nadal działa stary model (osobne SKU per rozmiar +
-- nieformalny `parent_sku` tekstowy). Nowe pola są NULL-able, więc istniejące
-- itemy działają jak `standard` (Konstytucja v5 § Prawo II zachowane).
-- =============================================================================

-- 1. Reużywalna skala wariantów (per tenant, np. „Rozmiary pizzy", „Wielkość kawy")
CREATE TABLE IF NOT EXISTS sh_variant_scales (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL COMMENT 'Display: „Rozmiary pizzy"',
    key_ascii   VARCHAR(64)  NOT NULL COMMENT 'Klucz znakowy do mostów cross-silo, np. SCALE_PIZZA',
    description TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scale_tenant_key (tenant_id, key_ascii),
    KEY idx_scale_tenant (tenant_id, is_deleted),
    CONSTRAINT fk_scale_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Opcje skali (Mała, Średnia, Duża, XL...) z multiplier-em dla receptury.
CREATE TABLE IF NOT EXISTS sh_variant_scale_options (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scale_id      BIGINT UNSIGNED NOT NULL,
    tenant_id     INT UNSIGNED NOT NULL COMMENT 'Denormalized for tenant_id barrier in cross-silo joins',
    name          VARCHAR(255) NOT NULL COMMENT 'Display: „Mała", „32cm"',
    key_ascii     VARCHAR(64)  NOT NULL COMMENT 'Sufiks dla generated SKU: _S, _M, _L',
    display_order INT NOT NULL DEFAULT 0,
    diameter_cm   SMALLINT UNSIGNED NULL COMMENT 'Średnica pizzy w cm (opcjonalne UX)',
    multiplier    DECIMAL(6,3) NOT NULL DEFAULT 1.000 COMMENT 'Mnożnik receptury i bazowej ceny. Mała=0.7, Średnia=1.0, Duża=1.3',
    is_default    TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_option_scale_key (scale_id, key_ascii),
    KEY idx_option_scale (scale_id, display_order),
    KEY idx_option_tenant (tenant_id),
    CONSTRAINT fk_option_scale
        FOREIGN KEY (scale_id) REFERENCES sh_variant_scales (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_option_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Rozszerzenie sh_menu_items (idempotent — guard PER OBIEKT)
--
-- Wcześniej cały ALTER (4 kolumny + 3 indeksy + 3 FK) chował się za JEDNYM
-- guardem na `variant_scale_id`. Run przerwany w połowie (np. FK odrzucony przez
-- niezgodny typ) zostawiał kolumny bez więzów, a re-run milczał, bo kolumna już
-- była. Teraz każdy obiekt ma własny guard — wzorzec z migracji 010/047.
SET @dbname = DATABASE();

-- 3a. Kolumny
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'variant_scale_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD COLUMN variant_scale_id BIGINT UNSIGNED NULL COMMENT ''Tylko na PARENT items (is_variant_parent=1)'' AFTER parent_sku',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'is_variant_parent';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD COLUMN is_variant_parent TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = master, niesprzedawalny w POS; warianty są jego children'' AFTER variant_scale_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'parent_item_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD COLUMN parent_item_id BIGINT UNSIGNED NULL COMMENT ''FK do parent sh_menu_items (numeric, w obrębie silosu sh_ — Prawo VI dopuszcza)'' AFTER is_variant_parent',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'variant_option_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD COLUMN variant_option_id BIGINT UNSIGNED NULL COMMENT ''FK do sh_variant_scale_options (który rozmiar reprezentuje)'' AFTER parent_item_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. Indeksy
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND INDEX_NAME = 'idx_menu_variant_parent';
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE sh_menu_items ADD KEY idx_menu_variant_parent (tenant_id, parent_item_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND INDEX_NAME = 'idx_menu_is_variant_parent';
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE sh_menu_items ADD KEY idx_menu_is_variant_parent (tenant_id, is_variant_parent)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND INDEX_NAME = 'idx_menu_variant_option';
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE sh_menu_items ADD KEY idx_menu_variant_option (tenant_id, variant_option_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3c. Klucze obce
SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_menu_variant_scale';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD CONSTRAINT fk_menu_variant_scale
         FOREIGN KEY (variant_scale_id) REFERENCES sh_variant_scales (id)
         ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_menu_parent_item';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD CONSTRAINT fk_menu_parent_item
         FOREIGN KEY (parent_item_id) REFERENCES sh_menu_items (id)
         ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_menu_variant_option';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_menu_items
     ADD CONSTRAINT fk_menu_variant_option
         FOREIGN KEY (variant_option_id) REFERENCES sh_variant_scale_options (id)
         ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
