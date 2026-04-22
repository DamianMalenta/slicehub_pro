-- =============================================================================
-- SliceHub Pro — Migration 021a: Atelier Performance
--
-- Adds generated columns + indexes to sh_atelier_scenes and introduces
-- sh_asset_registry for precomputed asset metadata used by SharedSceneRenderer.
--
-- IDEMPOTENT.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- ── sh_atelier_scenes generated columns ───────────────────────────────────────
SET @has_scenes = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
);

SET @has_layers_count = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
    AND COLUMN_NAME = 'layers_count'
);
SET @sql = IF(@has_scenes = 1 AND @has_layers_count = 0,
  'ALTER TABLE sh_atelier_scenes
     ADD COLUMN layers_count SMALLINT
       GENERATED ALWAYS AS (COALESCE(JSON_LENGTH(JSON_EXTRACT(spec_json, ''$.pizza.layers'')), 0)) STORED
       COMMENT ''021a: cached layer count for scene render / audits''
       AFTER spec_json',
  'SELECT ''skip layers_count'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_spec_hash = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
    AND COLUMN_NAME = 'spec_hash'
);
SET @sql = IF(@has_scenes = 1 AND @has_spec_hash = 0,
  'ALTER TABLE sh_atelier_scenes
     ADD COLUMN spec_hash CHAR(64)
       GENERATED ALWAYS AS (SHA2(CAST(spec_json AS CHAR(65535)), 256)) STORED
       COMMENT ''021a: ETag / cache-buster for merged scene spec''
       AFTER layers_count',
  'SELECT ''skip spec_hash'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_updated_at = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
    AND COLUMN_NAME = 'updated_at'
);
SET @sql = IF(@has_scenes = 1 AND @has_updated_at = 0,
  'ALTER TABLE sh_atelier_scenes
     ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
       ON UPDATE CURRENT_TIMESTAMP
       AFTER version',
  'SELECT ''skip updated_at'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_scene_version = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
    AND INDEX_NAME = 'idx_atelier_scene_version'
);
SET @sql = IF(@has_scenes = 1 AND @idx_scene_version = 0,
  'CREATE INDEX idx_atelier_scene_version ON sh_atelier_scenes (tenant_id, item_sku, version)',
  'SELECT ''skip idx_atelier_scene_version'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_scene_hash = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_atelier_scenes'
    AND INDEX_NAME = 'idx_atelier_scene_hash'
);
SET @sql = IF(@has_scenes = 1 AND @idx_scene_hash = 0,
  'CREATE INDEX idx_atelier_scene_hash ON sh_atelier_scenes (tenant_id, spec_hash)',
  'SELECT ''skip idx_atelier_scene_hash'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── sh_asset_registry ─────────────────────────────────────────────────────────
SET @tbl_registry = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sh_asset_registry'
);
SET @sql = IF(@tbl_registry = 0,
  'CREATE TABLE sh_asset_registry (
      asset_filename   VARCHAR(255) NOT NULL,
      content_hash     CHAR(64)     NULL,
      width            INT UNSIGNED NULL,
      height           INT UNSIGNED NULL,
      bytes            BIGINT UNSIGNED NULL,
      mime_type        VARCHAR(64)  NULL,
      palette_json     JSON         NULL COMMENT ''021a: top palette swatches (#hex)'',
      avg_luminance    TINYINT UNSIGNED NULL COMMENT ''021a: 0..255 average luminance'',
      last_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
      created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (asset_filename),
      KEY idx_asset_registry_hash (content_hash),
      KEY idx_asset_registry_size (width, height)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''sh_asset_registry already exists'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
