-- =============================================================================
-- 052_valid_from_to_datetime.sql — F-S4 (2026-05-11)
--
-- UI Studio wysyła `datetime-local` (np. 2026-05-11T14:00), ale w bazie kolumny
-- `valid_from`/`valid_to` to DATE. Tracimy godziny — happy hours 14:00-17:00
-- nie zadziała. Migracja zamienia DATE → DATETIME.
--
-- Konstytucja v5 § Prawo III (Temporal Tables).
-- =============================================================================

ALTER TABLE sh_menu_items
    MODIFY COLUMN valid_from DATETIME NULL,
    MODIFY COLUMN valid_to   DATETIME NULL;

ALTER TABLE sh_modifier_groups
    MODIFY COLUMN valid_from DATETIME NULL,
    MODIFY COLUMN valid_to   DATETIME NULL;
