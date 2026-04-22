-- =============================================================================
-- SliceHub Pro — Migration 032: Asset Library Organizer (Faza 2 · M5)
--
-- Dodaje kolumny `display_name` i `tags_json` do sh_assets oraz indexy wspierające
-- nowe narzędzie porządkujące Asset Library.
--
-- Cel biznesowy:
--   • Manager widzi po ludzku "Pieczarki plasterki" zamiast `veg_veg_6bd47b`.
--   • Tagowanie dowolne (np. ["włoska", "mięsiste", "sezonowe"]) umożliwia szybkie
--     filtrowanie i katalogowanie.
--   • ascii_key pozostaje maszynową nazwą (unikalną per tenant), display_name
--     jest wyłącznie etykietą UI — manager może przemianować wg uznania bez
--     zrywania linków.
--
-- Idempotentność: IF NOT EXISTS + INFORMATION_SCHEMA guards.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. display_name — ludzka nazwa assetu (opcjonalna)
-- -----------------------------------------------------------------------------
SET @has_col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND COLUMN_NAME  = 'display_name'
);
SET @sql_add = IF(@has_col = 0,
    'ALTER TABLE sh_assets
       ADD COLUMN display_name VARCHAR(191) NULL
                   COMMENT "M032 · ludzka nazwa (np. Pieczarki plasterki). NULL = fallback do ascii_key."
       AFTER ascii_key',
    'SELECT "sh_assets.display_name already exists"'
);
PREPARE s FROM @sql_add; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 2. tags_json — dowolne tagi (array stringów)
-- -----------------------------------------------------------------------------
SET @has_col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND COLUMN_NAME  = 'tags_json'
);
SET @sql_add = IF(@has_col = 0,
    'ALTER TABLE sh_assets
       ADD COLUMN tags_json JSON NULL
                   COMMENT ''M032 · array stringow (np. [wloska, miesiste])''
       AFTER display_name',
    'SELECT ''sh_assets.tags_json already exists'''
);
PREPARE s FROM @sql_add; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3. INDEX idx_assets_display_name (tenant_id, display_name)
--    Wspiera LIKE search po ludzkiej nazwie w Asset Studio.
-- -----------------------------------------------------------------------------
SET @has_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND INDEX_NAME   = 'idx_assets_display_name'
);
SET @sql_idx = IF(@has_idx = 0,
    'ALTER TABLE sh_assets
       ADD INDEX idx_assets_display_name (tenant_id, display_name(64))',
    'SELECT "idx_assets_display_name already exists"'
);
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. INDEX idx_assets_category_active — wspiera scan_health (group by category)
-- -----------------------------------------------------------------------------
SET @has_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND INDEX_NAME   = 'idx_assets_cat_active'
);
SET @sql_idx = IF(@has_idx = 0,
    'ALTER TABLE sh_assets
       ADD INDEX idx_assets_cat_active (tenant_id, category, is_active)',
    'SELECT "idx_assets_cat_active already exists"'
);
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 5. INDEX idx_assets_checksum — wspiera wykrywanie duplikatów po SHA-256
-- -----------------------------------------------------------------------------
SET @has_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND INDEX_NAME   = 'idx_assets_checksum_tenant'
);
SET @sql_idx = IF(@has_idx = 0,
    'ALTER TABLE sh_assets
       ADD INDEX idx_assets_checksum_tenant (tenant_id, checksum_sha256)',
    'SELECT "idx_assets_checksum_tenant already exists"'
);
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;

-- END OF MIGRATION 032
