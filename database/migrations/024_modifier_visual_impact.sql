-- =============================================================================
-- Migration 024 — sh_modifiers.has_visual_impact
-- =============================================================================
-- Idempotent (dynamic guard).
-- =============================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_modifiers'
    AND COLUMN_NAME  = 'has_visual_impact'
);

SET @sql_add = IF(@col_exists = 0,
  'ALTER TABLE sh_modifiers ADD COLUMN has_visual_impact TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
  'SELECT ''has_visual_impact already present'' AS note'
);
PREPARE stmt FROM @sql_add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
