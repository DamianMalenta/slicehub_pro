-- =============================================================================
-- SliceHub Pro — Migration 056: KSeF inbox — kategoria kosztu
-- -----------------------------------------------------------------------------
-- Cel: rozdział kosztów pod przyszły moduł statystyk + akceptacja faktur
--      „operacyjnych” (prąd, media, usługi) bez powiązania z magazynem / PZ.
--      Wartość `magazyn` = dotychczasowy flow (wszystkie linie ze SKU → PZ).
--      Inne wartości = akceptacja bez PZ, tylko ewidencja kwot w nagłówku.
-- IDEMPOTENT (MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_ksef_invoices' AND COLUMN_NAME = 'cost_category';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_ksef_invoices
     ADD COLUMN cost_category VARCHAR(32) NOT NULL DEFAULT 'magazyn'
       COMMENT 'magazyn|media|uslugi|inne — magazyn wymaga PZ; inne bez PZ'
     AFTER status",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'm056 ksef_invoice_cost_category applied' AS migration_056;
