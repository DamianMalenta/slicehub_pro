-- =============================================================================
-- 054_order_line_combo_meta.sql — F-S3.2 (2026-05-11)
--
-- Strukturalne meta dla linii zamówienia będącej combo/bundle.
-- Pole zawiera JSON: { meal_id, picks: [{component_id, sku}], fixed_items: [...] }.
--
-- WzEngine używa tego do ekspansji combo → faktyczna konsumpcja składników magazynu
-- (zamiast szukać receptury dla `ascii_key` combo, którego nie ma).
--
-- Konstytucja v5 § Prawo II — Bliźniak Cyfrowy: combo to bundle, każdy składnik
-- realnie spada w magazynie.
-- =============================================================================

ALTER TABLE sh_order_lines
    ADD COLUMN combo_meta_json LONGTEXT NULL
        COMMENT 'F-S3.2: JSON z meal_id + picks + fixed_items dla linii combo';
