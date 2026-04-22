-- =============================================================================
-- SliceHub Studio Mission Control — Schema Extension (idempotent)
-- Adds columns required by the new item editor UI
-- Safe to re-run: skips columns that already exist
-- =============================================================================

-- sh_categories: VAT defaults per channel
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_categories' AND COLUMN_NAME = 'default_vat_dine_in';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_categories ADD COLUMN default_vat_dine_in DECIMAL(5,2) NOT NULL DEFAULT 8.00',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_categories' AND COLUMN_NAME = 'default_vat_takeaway';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_categories ADD COLUMN default_vat_takeaway DECIMAL(5,2) NOT NULL DEFAULT 5.00',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_categories' AND COLUMN_NAME = 'default_vat_delivery';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_categories ADD COLUMN default_vat_delivery DECIMAL(5,2) NOT NULL DEFAULT 5.00',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sh_menu_items: logistics & scheduling fields
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'printer_group';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items ADD COLUMN printer_group VARCHAR(64) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'plu_code';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items ADD COLUMN plu_code VARCHAR(32) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'available_days';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_menu_items ADD COLUMN available_days VARCHAR(32) NULL DEFAULT '1,2,3,4,5,6,7'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'available_start';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items ADD COLUMN available_start TIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'available_end';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items ADD COLUMN available_end TIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
