-- =============================================================================
-- 055_recipe_display_order.sql — F-S9 (2026-05-11)
--
-- Drag-and-drop reorder w edytorze receptury — kolumna display_order pozwala
-- managerowi ułożyć składniki w preferowanej kolejności (UX).
--
-- Bez tej kolumny WzEngine i tak działa (kolejność deductions nie ma znaczenia
-- semantycznie). Ale UI Studio pokazuje składniki w order ID-discovered, co
-- nie odzwierciedla woli managera.
-- =============================================================================

ALTER TABLE sh_recipes
    ADD COLUMN display_order INT NOT NULL DEFAULT 0
        COMMENT 'F-S9: kolejność w UI Studio (drag-and-drop), 0=auto z ID';

-- Initial: dla istniejących wpisów = ID (zachowuje istniejący wzorzec).
UPDATE sh_recipes SET display_order = id WHERE display_order = 0;
