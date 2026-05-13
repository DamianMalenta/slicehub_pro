-- =============================================================================
-- 051_publication_status_normalize.sql — F-S4 (2026-05-11)
--
-- Naprawia drift: Studio zapisywał 'Live', POS filtrował 'published'.
-- Po F-S4 kanoniczny słownik to: 'Draft' | 'Live' | 'Archived' (Konstytucja v5).
--
-- Backward-compat: POS engine.php nadal akceptuje obie wartości
-- (`IN ('Live','published')`) — ta migracja jednorazowo normalizuje dane.
-- =============================================================================

-- sh_menu_items
UPDATE sh_menu_items SET publication_status = 'Live'
 WHERE publication_status = 'published';
UPDATE sh_menu_items SET publication_status = 'Draft'
 WHERE publication_status IN ('draft','DRAFT','SZKIC');
UPDATE sh_menu_items SET publication_status = 'Archived'
 WHERE publication_status IN ('archived','ARCHIVED','ZARCHIWIZOWANE');

-- sh_modifier_groups
UPDATE sh_modifier_groups SET publication_status = 'Live'
 WHERE publication_status = 'published';
UPDATE sh_modifier_groups SET publication_status = 'Draft'
 WHERE publication_status IN ('draft','DRAFT');
