-- =============================================================================
-- SliceHub Pro — Migration 031: Baked Variants (Faza 2 · M3 #7)
--
-- Dodaje kolumnę `cook_state` do sh_assets — pozwala mieć wiele wersji tego
-- samego składnika: surowy (raw), upieczony (cooked), przypalony (charred)
-- oraz neutralny (either — domyślnie, kiedy stan nie ma znaczenia).
--
-- Cel biznesowy:
--   • Hero menu / karta produktu — składniki SUROWE ('raw'/'either') — apetyczne,
--     kolorowe, "studio"
--   • Warstwa layer_top_down (pieczona pizza) — składniki UPIECZONE ('cooked') —
--     realistyczne, z delikatnym przyrumienieniem, stopionym serem, podpieczonym
--     pepperoni zwijającym się w kubeczki
--
-- Prawo VII — Innowacja: żaden inny storefront nie rozróżnia surowych od
-- upieczonych warstw. Klient który widzi pepperoni takie samo jak w chłodziarce
-- i na gotowej pizzy — gubi poczucie autentyczności.
--
-- Konwencja:
--   • 'either'  → domyślne, asset uniwersalny (backward-compat dla wszystkich
--                 istniejących zasobów, 0 regresji)
--   • 'raw'     → surowy, apetyczny, do list i karty
--   • 'cooked'  → upieczony, realistyczny, do sceny pizzy top-down
--   • 'charred' → przypalony (ewentualne promocje „extra crispy") — rezerwa na
--                 Fazę 3
--
-- Idempotentność: IF NOT EXISTS na INFORMATION_SCHEMA.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- ADD COLUMN cook_state (idempotent)
-- -----------------------------------------------------------------------------
SET @has_col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND COLUMN_NAME  = 'cook_state'
);
SET @sql_add = IF(@has_col = 0,
    'ALTER TABLE sh_assets
       ADD COLUMN cook_state ENUM(''either'',''raw'',''cooked'',''charred'')
                   NOT NULL DEFAULT ''either''
                   COMMENT ''M031 · stan pieczenia: either=domyślny, raw=surowy (hero/card), cooked=upieczony (layer_top_down pizzy), charred=mocno przypalony (promocje)''
       AFTER sub_type',
    'SELECT ''sh_assets.cook_state already exists'''
);
PREPARE s FROM @sql_add; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- INDEX idx_assets_cook_state (tenant_id, category, cook_state)
-- Wspiera szybki SELECT przy resolve warstw — resolver filtruje po category
-- (meat/veg/cheese) + cook_state per rola.
-- -----------------------------------------------------------------------------
SET @has_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_assets'
      AND INDEX_NAME   = 'idx_assets_cook_state'
);
SET @sql_idx = IF(@has_idx = 0,
    'ALTER TABLE sh_assets
       ADD INDEX idx_assets_cook_state (tenant_id, category, cook_state, is_active)',
    'SELECT ''idx_assets_cook_state already exists'''
);
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- SANITY: wszystkie istniejące zasoby mają cook_state='either' (default z ADD
-- COLUMN), więc żaden SELECT nie zmieni wyniku dopóki manager nie sklasyfikuje
-- assetów w Asset Studio.
-- -----------------------------------------------------------------------------

-- END OF MIGRATION 031
