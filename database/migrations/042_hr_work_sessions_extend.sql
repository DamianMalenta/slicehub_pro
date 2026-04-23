-- =============================================================================
-- SliceHub Pro — Migration 042: HR & Payroll · Work Sessions Extension
-- -----------------------------------------------------------------------------
-- Rozszerzenie sh_work_sessions (istnieje od m001) o atrybuty wymagane przez
-- Faza 3A (POS clock-in/out, geo, idempotencja, bi-kanałowy audit źródła).
-- Spec: _docs/18_BACKOFFICE_HR_LOGIC.md §3.3
--
-- Zakres:
--   1. ADD employee_id BIGINT UNSIGNED NULL   — nowy autorytatywny FK (HR-10)
--   2. ADD terminal_id BIGINT UNSIGNED NULL   — wiąże z sh_pos_terminals (m039)
--   3. ADD clock_in_source / clock_out_source VARCHAR ASCII
--   4. ADD adjusted_by_user_id + adjustment_reason  — manager override audit
--   5. ADD geo_{lat,lon}_{in,out} DECIMAL(10,7) NULL — lokalizacja clock-in/out
--   6. ADD generated column open_guard + UNIQUE (tenant_id, employee_id, open_guard)
--      — sprzętowa idempotencja "max 1 otwarta sesja per pracownik" (HR-5)
--   7. BACKFILL employee_id z sh_employees.user_id (m041)
--
-- UWAGA: kolumna user_id POZOSTAJE (backward-compat). Nowy kod pisze employee_id.
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

SET @dbname = DATABASE();

-- =============================================================================
-- 1. employee_id (NULLABLE — backfill po INSERTach)
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'employee_id';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN employee_id BIGINT UNSIGNED NULL COMMENT 'FK -> sh_employees.id (nowy autorytatywny identyfikator, HR-10)' AFTER user_id",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 2. terminal_id (NULLABLE — nie każda sesja pochodzi z POS-a)
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'terminal_id';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN terminal_id BIGINT UNSIGNED NULL COMMENT 'FK -> sh_pos_terminals.id (m039); NULL gdy source != pos/kiosk'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 3. clock_in_source / clock_out_source (ASCII dictionary)
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'clock_in_source';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN clock_in_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'kiosk' COMMENT 'kiosk | pos | mobile | manager_override | system_auto'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'clock_out_source';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN clock_out_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NULL COMMENT 'kiosk | pos | mobile | manager_override | system_auto'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 4. adjusted_by_user_id + adjustment_reason (manager override audit)
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'adjusted_by_user_id';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN adjusted_by_user_id BIGINT UNSIGNED NULL COMMENT 'Manager, który skorygował czas manualnie'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'adjustment_reason';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN adjustment_reason VARCHAR(255) NULL COMMENT 'UTF-8 uzasadnienie korekty'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 5. GEO (lat/lon clock-in + clock-out)
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'geo_lat_in';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN geo_lat_in DECIMAL(10,7) NULL COMMENT 'Szerokość geo przy clock-in'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'geo_lon_in';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN geo_lon_in DECIMAL(10,7) NULL COMMENT 'Długość geo przy clock-in'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'geo_lat_out';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN geo_lat_out DECIMAL(10,7) NULL COMMENT 'Szerokość geo przy clock-out'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'geo_lon_out';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN geo_lon_out DECIMAL(10,7) NULL COMMENT 'Długość geo przy clock-out'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 6. BACKFILL employee_id (uzupełnij istniejące sesje)
-- -----------------------------------------------------------------------------
-- Dla każdej sesji, gdzie user_id ma odpowiadający rekord w sh_employees,
-- wpisujemy employee_id. Sesje "sieroty" (user bez profilu HR) zostają
-- z employee_id = NULL — świadomie, nie blokuje starego kodu.
-- =============================================================================
UPDATE sh_work_sessions ws
JOIN sh_employees e
  ON e.user_id = ws.user_id
 AND e.tenant_id = ws.tenant_id
SET ws.employee_id = e.id
WHERE ws.employee_id IS NULL;

-- =============================================================================
-- 7. INDEKSY (employee + terminal)
-- =============================================================================
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND INDEX_NAME = 'idx_ws_employee';
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_ws_employee ON sh_work_sessions (tenant_id, employee_id, start_time)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND INDEX_NAME = 'idx_ws_terminal';
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_ws_terminal ON sh_work_sessions (tenant_id, terminal_id, start_time)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 8. FOREIGN KEY fk_ws_employee -> sh_employees.id
-- =============================================================================
SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND CONSTRAINT_NAME = 'fk_ws_employee';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_work_sessions ADD CONSTRAINT fk_ws_employee FOREIGN KEY (employee_id) REFERENCES sh_employees (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 9. ANTI-DOUBLE-SESSION (HR-5)
-- -----------------------------------------------------------------------------
-- Generated column open_guard:
--   = 1  gdy sesja otwarta (end_time IS NULL)
--   = NULL gdy zamknięta
--
-- UNIQUE INDEX (tenant_id, employee_id, open_guard) → MySQL/MariaDB traktują
-- NULL jako distinct, więc unikalność obowiązuje wyłącznie dla otwartych sesji.
--
-- STORED zamiast VIRTUAL — matching pattern z m037 (_active_table_guard).
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND COLUMN_NAME = 'open_guard';
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sh_work_sessions ADD COLUMN open_guard TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN end_time IS NULL THEN 1 ELSE NULL END) STORED COMMENT 'HR-5 idempotency guard'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_work_sessions' AND INDEX_NAME = 'uq_ws_single_open';
SET @sql = IF(@idx_exists = 0,
  'CREATE UNIQUE INDEX uq_ws_single_open ON sh_work_sessions (tenant_id, employee_id, open_guard)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 042.
-- Po wdrożeniu: kanoniczny kod (core/HrClockEngine.php — Faza 3A) pisze zarówno
-- user_id (legacy compat) jak i employee_id (nowy aggregate root HR).
-- Dropowanie user_id z sh_work_sessions — osobna migracja w Fazie 3C po 2×
-- zamknięciu okresu księgowego.
-- =============================================================================
