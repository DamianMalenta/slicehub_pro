-- =============================================================================
-- Migration 010: Driver Action Type on Order Lines
-- Adds ENUM column for driver-specific handling instructions per order line.
-- Values: 'none' (default), 'pack_cold', 'pack_separate', 'check_id'
-- =============================================================================

ALTER TABLE sh_order_lines
  ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id')
  NOT NULL DEFAULT 'none'
  AFTER comment;

-- Also add to menu items so the flag can be pre-configured per product
ALTER TABLE sh_menu_items
  ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id')
  NOT NULL DEFAULT 'none';
