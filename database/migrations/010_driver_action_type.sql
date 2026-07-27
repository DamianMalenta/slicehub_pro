-- =============================================================================
-- Migration 010: Driver Action Type on Order Lines
-- Adds ENUM column for driver-specific handling instructions per order line.
-- Values: 'none' (default), 'pack_cold', 'pack_separate', 'check_id'
-- IDEMPOTENT: information_schema guards on both ALTERs.
-- =============================================================================

SET @dbname = DATABASE();

-- sh_order_lines.driver_action_type
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_order_lines' AND COLUMN_NAME = 'driver_action_type';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_order_lines ADD COLUMN driver_action_type ENUM(''none'',''pack_cold'',''pack_separate'',''check_id'') NOT NULL DEFAULT ''none'' AFTER comment',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sh_menu_items.driver_action_type
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_menu_items' AND COLUMN_NAME = 'driver_action_type';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_menu_items ADD COLUMN driver_action_type ENUM(''none'',''pack_cold'',''pack_separate'',''check_id'') NOT NULL DEFAULT ''none''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
