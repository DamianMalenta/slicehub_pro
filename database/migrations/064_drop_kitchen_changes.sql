-- =============================================================================
-- SliceHub Pro — Migration 064: POS · CONTRACT — DROP sh_orders.kitchen_changes
-- -----------------------------------------------------------------------------
-- Prawo VIII (Likwidacja Docs Drift / Dead Code): kolumna kitchen_changes
-- (TEXT diff string) jest martwa od wprowadzenia strukturalnego kitchen_delta
-- (JSON) jako SSOT dla KDS highlight (DeltaEngine, 2026-08-20+).
--
-- Warunki spełnione:
--   - api/pos/engine.php nie SELECTuje ani nie UPDATEuje kitchen_changes
--     (komentarze referencujące kolumnę usunięte w tej samej paczce audytu).
--   - Zdarzenie order.edited niesie pole kitchen_delta (nie kitchen_changes)
--     — patrz _docs/09_EVENT_SYSTEM.md.
--   - setup_database.php nie tworzy kolumny przy nowej instalacji.
--   - 001_init_slicehub_pro_v2.sql nie definiuje kolumny.
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- Sprawdzenie INFORMATION_SCHEMA przed DROP COLUMN gwarantuje bezpieczeństwo
-- na instalacjach, gdzie kolumna nigdy nie istniała.
-- =============================================================================

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'kitchen_changes';
SET @sql = IF(@col_exists = 1,
  'ALTER TABLE sh_orders DROP COLUMN kitchen_changes',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 064.
-- Walidacja post-run:
--   SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_orders'
--       AND COLUMN_NAME = 'kitchen_changes';   -- oczekiwane: 0
-- =============================================================================
