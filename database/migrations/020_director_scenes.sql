-- =============================================================================
-- SliceHub Pro — Migration 020: Director Scene Specs
--
-- Stores DishSceneSpec JSON for the Hollywood Director's Suite.
-- Each dish can have one scene spec per tenant.
-- History table tracks every save for undo/rollback.
--
-- IDEMPOTENT.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- ── sh_atelier_scenes ──────────────────────────────────────────
SET @tbl1 = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
);
SET @sql1 = IF(@tbl1 = 0,
  'CREATE TABLE sh_atelier_scenes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    item_sku VARCHAR(64) NOT NULL,
    spec_json JSON NOT NULL,
    version INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_item (tenant_id, item_sku),
    INDEX idx_tenant (tenant_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''sh_atelier_scenes already exists'''
);
PREPARE s FROM @sql1; EXECUTE s; DEALLOCATE PREPARE s;

-- ── sh_atelier_scene_history ───────────────────────────────────
SET @tbl2 = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scene_history'
);
SET @sql2 = IF(@tbl2 = 0,
  'CREATE TABLE sh_atelier_scene_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scene_id INT NOT NULL,
    spec_json JSON NOT NULL,
    snapshot_label VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scene_id) REFERENCES sh_atelier_scenes(id) ON DELETE CASCADE,
    INDEX idx_scene (scene_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''sh_atelier_scene_history already exists'''
);
PREPARE s FROM @sql2; EXECUTE s; DEALLOCATE PREPARE s;
