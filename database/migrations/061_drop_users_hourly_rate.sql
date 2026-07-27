-- =============================================================================
-- SliceHub Pro — Migration 061: HR & Payroll · Contract — DROP sh_users.hourly_rate
-- -----------------------------------------------------------------------------
-- Faza 4 (_docs/18_BACKOFFICE_HR_LOGIC.md §13.4 pkt 7): faza CONTRACT wzorca
-- Expand-Contract rozpoczętego w migracji 041.
--
-- Warunki spełnione:
--   - PayrollEngine / TeamPayrollEngine / HrClockEngine / worker_payroll_accrual
--     czytają stawki WYŁĄCZNIE z temporalnej sh_employee_rates.
--   - Dane historyczne przeniesione: backfill 041 (rates) +
--     scripts/migrate_deductions_to_ledger.php (deductions/meals/sessions).
--   - Seedy (seed_demo_all.php, nuclear_reset.php) i testy
--     (payroll_engine_rewrite_parity.php) nie dotykają już kolumny.
--   - Migracja 041 ma dynamic-SQL-guard na hourly_rate — re-run łańcucha
--     po tej migracji jest bezpieczny.
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_users' AND COLUMN_NAME = 'hourly_rate';
SET @sql = IF(@col_exists = 1,
  'ALTER TABLE sh_users DROP COLUMN hourly_rate',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 061.
-- Walidacja post-run:
--   SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_users'
--       AND COLUMN_NAME = 'hourly_rate';   -- oczekiwane: 0
-- =============================================================================
