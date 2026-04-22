-- =============================================================================
-- m032 · sh_assets.display_name — czytelna etykieta dla managera
-- -----------------------------------------------------------------------------
-- Po co:
--   ascii_key jest techniczny (np. 'veg_veg_6bd47b') i nie mówi managerowi
--   czym jest dany asset. Od teraz każdy asset ma display_name (np. "Pieczarki
--   plastry") edytowalny w Asset Studio. Ascii_key pozostaje stabilnym ID.
--
-- Backfill:
--   1. jeżeli sub_type ma coś sensownego → spróbuj 'Pieczarki' / 'Mozzarella'
--      z prostego słownika (PL); inaczej użyj ascii_key jako fallback.
--   2. Kolumna NULL-able — puste display_name oznacza "niepodpisany" i pojawia
--      się jako sugestia w Organize View.
--
-- Idempotent: INFORMATION_SCHEMA guard.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- 1. Dodanie kolumny (idempotentne)
SET @db := DATABASE();
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME   = 'sh_assets'
      AND COLUMN_NAME  = 'display_name'
);

SET @sql_add := IF(@has_col = 0,
    "ALTER TABLE sh_assets
        ADD COLUMN display_name VARCHAR(128) NULL
            COMMENT 'm032 · Czytelna nazwa dla managera (np. ''Pieczarki plastry''). NULL = jeszcze nie podpisane.'
            AFTER ascii_key",
    "SELECT 'sh_assets.display_name already exists' AS note");
PREPARE s FROM @sql_add; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Index: szybkie wyszukiwanie po nazwie
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME   = 'sh_assets'
      AND INDEX_NAME   = 'idx_assets_display_name'
);
SET @sql_idx := IF(@has_idx = 0,
    "CREATE INDEX idx_assets_display_name ON sh_assets (display_name)",
    "SELECT 'idx_assets_display_name already exists' AS note");
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Backfill — prosta heurystyka oparta o category+sub_type (PL dict).
--    Tylko gdy display_name IS NULL. Brak backfillu = zostaje NULL (sugestia w UI).
UPDATE sh_assets SET display_name = CASE
    -- sauce
    WHEN category = 'sauce' AND sub_type LIKE '%tomato%'    THEN 'Sos pomidorowy'
    WHEN category = 'sauce' AND sub_type LIKE '%garlic%'    THEN 'Sos czosnkowy'
    WHEN category = 'sauce' AND sub_type LIKE '%bbq%'       THEN 'Sos BBQ'
    WHEN category = 'sauce' AND sub_type LIKE '%bianca%'    THEN 'Baza biała'
    WHEN category = 'sauce' AND sub_type LIKE '%pesto%'     THEN 'Pesto'
    WHEN category = 'sauce' AND sub_type LIKE '%basil%'     THEN 'Bazyliowy'
    -- cheese
    WHEN category = 'cheese' AND sub_type LIKE '%mozzarella%' THEN 'Mozzarella'
    WHEN category = 'cheese' AND sub_type LIKE '%parmesan%'   THEN 'Parmezan'
    WHEN category = 'cheese' AND sub_type LIKE '%blue%'       THEN 'Ser pleśniowy'
    WHEN category = 'cheese' AND sub_type LIKE '%feta%'       THEN 'Feta'
    WHEN category = 'cheese' AND sub_type LIKE '%gorgonzola%' THEN 'Gorgonzola'
    WHEN category = 'cheese' AND sub_type LIKE '%ricotta%'    THEN 'Ricotta'
    -- meat
    WHEN category = 'meat' AND sub_type LIKE '%salami%'     THEN 'Salami'
    WHEN category = 'meat' AND sub_type LIKE '%pepperoni%'  THEN 'Pepperoni'
    WHEN category = 'meat' AND sub_type LIKE '%ham%'        THEN 'Szynka'
    WHEN category = 'meat' AND sub_type LIKE '%bacon%'      THEN 'Boczek'
    WHEN category = 'meat' AND sub_type LIKE '%chicken%'    THEN 'Kurczak'
    WHEN category = 'meat' AND sub_type LIKE '%prosciutto%' THEN 'Prosciutto'
    WHEN category = 'meat' AND sub_type LIKE '%sausage%'    THEN 'Kiełbasa'
    -- veg
    WHEN category = 'veg' AND sub_type LIKE '%mushroom%'   THEN 'Pieczarki'
    WHEN category = 'veg' AND sub_type LIKE '%pieczark%'   THEN 'Pieczarki'
    WHEN category = 'veg' AND sub_type LIKE '%onion%'      THEN 'Cebula'
    WHEN category = 'veg' AND sub_type LIKE '%olive%'      THEN 'Oliwki'
    WHEN category = 'veg' AND sub_type LIKE '%tomato%'     THEN 'Pomidorki'
    WHEN category = 'veg' AND sub_type LIKE '%pepper%'     THEN 'Papryka'
    WHEN category = 'veg' AND sub_type LIKE '%corn%'       THEN 'Kukurydza'
    WHEN category = 'veg' AND sub_type LIKE '%spinach%'    THEN 'Szpinak'
    WHEN category = 'veg' AND sub_type LIKE '%arugula%'    THEN 'Rukola'
    WHEN category = 'veg' AND sub_type LIKE '%rocket%'     THEN 'Rukola'
    WHEN category = 'veg' AND sub_type LIKE '%jalapeno%'   THEN 'Jalapeño'
    WHEN category = 'veg' AND sub_type LIKE '%garlic%'     THEN 'Czosnek'
    -- herb
    WHEN category = 'herb' AND sub_type LIKE '%basil%'    THEN 'Bazylia'
    WHEN category = 'herb' AND sub_type LIKE '%oregano%'  THEN 'Oregano'
    WHEN category = 'herb' AND sub_type LIKE '%parsley%'  THEN 'Pietruszka'
    -- base / board
    WHEN category = 'base'  AND sub_type LIKE '%classic%'  THEN 'Ciasto klasyczne'
    WHEN category = 'base'  AND sub_type LIKE '%thin%'     THEN 'Ciasto cienkie'
    WHEN category = 'base'  AND sub_type LIKE '%neapolit%' THEN 'Ciasto neapolitańskie'
    WHEN category = 'board' THEN 'Deska'
    -- fallback: kapitalizacja sub_type
    WHEN sub_type IS NOT NULL AND sub_type <> ''
        THEN CONCAT(UPPER(LEFT(sub_type, 1)), LOWER(SUBSTRING(sub_type, 2)))
    ELSE NULL
END
WHERE display_name IS NULL
  AND (category IS NOT NULL OR sub_type IS NOT NULL);

-- Koniec m032
