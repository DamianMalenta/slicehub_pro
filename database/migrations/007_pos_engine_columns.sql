-- SliceHub V2 — POS Engine Columns Migration (idempotent)
-- Adds columns needed for full POS operations (print tracking, NIP, cart snapshot)
-- Safe to re-run: skips columns that already exist

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'receipt_printed';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN receipt_printed TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'kitchen_ticket_printed';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN kitchen_ticket_printed TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'kitchen_changes';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN kitchen_changes TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'cart_json';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN cart_json JSON NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'nip';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders ADD COLUMN nip VARCHAR(32) NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
