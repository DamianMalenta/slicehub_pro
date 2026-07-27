-- =============================================================================
-- 053_subrecipes.sql — F-S5 (2026-05-11)
--
-- Multi-stage recipes (półprodukty, iiko-style „заготовки").
--
-- Cel: składnik receptury może być INNĄ pozycją menu/półproduktem, nie tylko
-- bezpośrednim surowcem. Przykład:
--   Pizza Margherita zawiera sos pomidorowy (półprodukt).
--   Sos pomidorowy ma własną recepturę: pomidor, czosnek, oliwa.
--   Gdy WzEngine konsumuje Margherita → rekursywnie konsumuje SUROWCE sosu.
--
-- Strategia: minimalna inwazyjność.
--   - `sh_recipes.is_subrecipe` = 1 oznacza że `warehouse_sku` to ascii_key
--     INNEJ pozycji menu (a nie sys_items SKU).
--   - `sh_recipes.subrecipe_yield` = ile porcji półproduktu produkuje 1 batch
--     (np. 1 batch sosu = 20 porcji do pizzy).
--   - WzEngine rekursywnie expanduje subrecipes (max depth 3 — anti-cycle).
--
-- Konstytucja v5 § Prawo II — Bliźniak Cyfrowy (real dependencies).
-- =============================================================================

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_recipes' AND COLUMN_NAME = 'is_subrecipe';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_recipes
     ADD COLUMN is_subrecipe       TINYINT(1) NOT NULL DEFAULT 0
         COMMENT ''1 = warehouse_sku wskazuje na ascii_key innej pozycji (półprodukt)'',
     ADD COLUMN subrecipe_yield    DECIMAL(10,4) NOT NULL DEFAULT 1.0000
         COMMENT ''Liczba porcji z 1 batch półproduktu (np. 20 porcji sosu z 1 garnka)''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
