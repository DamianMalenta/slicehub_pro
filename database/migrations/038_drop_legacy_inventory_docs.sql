-- =============================================================================
-- SliceHub Pro — Migration 038: Drop Legacy Inventory Docs
-- -----------------------------------------------------------------------------
-- Cel: usunięcie dwóch martwych tabel z fazy legacy:
--   - `wh_inventory_docs`       (dokumenty inwentaryzacji — stara wersja)
--   - `wh_inventory_doc_items`  (linie tych dokumentów)
--
-- Kanonem inwentaryzacji w SliceHub Pro są `wh_documents` (type=INW)
-- + `wh_document_lines`. Wszystkie silniki magazynu (`WzEngine`, `PzEngine`,
-- `InwEngine`, `KorEngine`, `MmEngine`) używają kanonu. Legacy tabele
-- `wh_inventory_*` zostały utworzone w m001 ale nigdy nie były
-- referencowane przez kod PHP, pozostały puste.
--
-- Weryfikacja przed tą migracją:
--   - grep w repo: 0 użyć w api/ core/ modules/ scripts/
--   - dump z 22.04.2026: 0 wierszy w obu tabelach
--
-- IDEMPOTENT (DROP TABLE IF EXISTS). Safe to re-run.
-- Kolejność: najpierw dziecko z FK (`wh_inventory_doc_items` → `wh_inventory_docs`),
-- potem rodzic.
-- =============================================================================

DROP TABLE IF EXISTS `wh_inventory_doc_items`;
DROP TABLE IF EXISTS `wh_inventory_docs`;
