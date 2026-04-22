-- =============================================================================
-- SliceHub Pro — Migration 019: Layer positioning (visual editor offsets)
--
-- Adds free-form X/Y offsets for sh_visual_layers (range -0.5..+0.5,
-- expressed as fraction of the half-pizza radius). Lets the manager drag a
-- topping anywhere on the dish from the Online Studio composer canvas.
--
-- Range:  offset_x, offset_y ∈ [-0.50, +0.50]
--   0,0   = center
--   +x    = right
--   -x    = left
--   +y    = bottom
--   -y    = top
--
-- IDEMPOTENT.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- offset_x
SET @col_x = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_visual_layers'
    AND COLUMN_NAME = 'offset_x'
);
SET @sql_x = IF(@col_x = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN offset_x DECIMAL(4,3) NOT NULL DEFAULT 0.000 COMMENT ''Visual X offset (-0.5..+0.5 of half-pizza radius)'' AFTER cal_rotate',
  'SELECT ''offset_x already present'''
);
PREPARE s FROM @sql_x; EXECUTE s; DEALLOCATE PREPARE s;

-- offset_y
SET @col_y = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_visual_layers'
    AND COLUMN_NAME = 'offset_y'
);
SET @sql_y = IF(@col_y = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN offset_y DECIMAL(4,3) NOT NULL DEFAULT 0.000 COMMENT ''Visual Y offset (-0.5..+0.5 of half-pizza radius)'' AFTER offset_x',
  'SELECT ''offset_y already present'''
);
PREPARE s FROM @sql_y; EXECUTE s; DEALLOCATE PREPARE s;
