-- =============================================================================
-- SliceHub Pro — Migration 041: HR & Payroll Foundation · Employees + Rates
-- -----------------------------------------------------------------------------
-- Fundament silosu Backoffice / HR & Payroll (Faza 3A).
-- Spec: _docs/18_BACKOFFICE_HR_LOGIC.md
--
-- Zakres:
--   1. sh_employees          — profil HR pracownika (aggregate root)
--   2. sh_employee_rates     — temporalne stawki (effective_from / effective_to)
--   3. BACKFILL              — rzutuj istniejących sh_users (z hourly_rate > 0
--                              LUB rolami operacyjnymi) na sh_employees + wpis w
--                              sh_employee_rates (reason='hiring').
--
-- Expand-Contract: kolumna sh_users.hourly_rate POZOSTAJE (deprecated), żeby
-- stary PayrollEngine działał do czasu przejścia na v2. Usunięcie — osobna
-- migracja po 2× zamknięciu miesiąca.
--
-- Konwencje (§2.4 spec):
--   - Klucze techniczne / statusy / waluty → CHARACTER SET ascii COLLATE ascii_general_ci
--   - Kwoty pieniężne → INT UNSIGNED (grosze) + currency CHAR(3) ASCII
--   - Opisy / imiona / notatki → utf8mb4_unicode_ci
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

SET @dbname = DATABASE();

-- =============================================================================
-- 1. sh_employees — profil HR (aggregate root)
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_employees (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED    NOT NULL,
  user_id              BIGINT UNSIGNED NULL
                       COMMENT '1:1 z sh_users (NULLABLE — pracownik bez loginu systemowego)',

  -- IDENTITY (ASCII handle + UTF-8 displayable)
  employee_code        VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                       COMMENT 'Stabilny ASCII handle per-tenant (np. EMP-00042)',
  display_name         VARCHAR(191) NOT NULL
                       COMMENT 'Ludzka nazwa do wyświetlania (UTF-8)',
  first_name           VARCHAR(96)  NOT NULL,
  last_name            VARCHAR(96)  NOT NULL,
  email                VARCHAR(191) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  phone                VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  birth_date           DATE         NULL,
  hire_date            DATE         NOT NULL,
  termination_date     DATE         NULL,

  -- AUTH (PIN dla Kiosk; system-login idzie przez sh_users.password_hash)
  auth_pin_hash        CHAR(60)     CHARACTER SET ascii COLLATE ascii_general_ci NULL
                       COMMENT 'bcrypt hash PIN-u 4-6 cyfrowego (HR-12: NIGDY plain-text)',
  auth_pin_updated_at  DATETIME     NULL,

  -- HR META (ASCII dictionary values)
  primary_role         VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                       COMMENT 'cook | waiter | driver | manager | cashier | cleaner | runner | shift_lead',
  status               VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'active'
                       COMMENT 'active | suspended | on_leave | terminated',
  default_currency     CHAR(3)      CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'PLN'
                       COMMENT 'ISO 4217',

  notes                TEXT         NULL
                       COMMENT 'Wolne pole UTF-8 (adnotacje HR)',

  -- META
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_deleted           TINYINT(1)   NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_code_tenant (tenant_id, employee_code),
  UNIQUE KEY uq_emp_user_tenant (tenant_id, user_id),
  KEY idx_emp_tenant_status (tenant_id, status, is_deleted),
  KEY idx_emp_tenant_role   (tenant_id, primary_role, is_deleted),
  KEY idx_emp_hire_date     (tenant_id, hire_date),

  CONSTRAINT fk_emp_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_emp_user
    FOREIGN KEY (user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='HR · profil pracownika (aggregate root · Faza 3A)';

-- =============================================================================
-- 2. sh_employee_rates — temporalne stawki (rozwiązanie HR-2)
-- -----------------------------------------------------------------------------
-- Inwariant: dla (tenant, employee, rate_type) max 1 wpis z effective_to=NULL.
-- Zmiana stawki = UPDATE starego (effective_to=NOW()) + INSERT nowego w tranzakcji.
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_employee_rates (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED    NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,

  rate_type            VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'hourly'
                       COMMENT 'hourly | monthly_flat | per_delivery | piecework',

  -- MONEY (INT UNSIGNED w groszach + explicit ISO 4217)
  amount_minor         INT UNSIGNED NOT NULL
                       COMMENT 'Kwota w jednostkach pomniejszonych (np. 2850 = 28.50 PLN/h)',
  currency             CHAR(3) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'PLN',

  -- TEMPORAL WINDOW
  effective_from       DATETIME NOT NULL,
  effective_to         DATETIME NULL
                       COMMENT 'NULL = nadal aktualna',

  -- AUDIT
  reason               VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NULL
                       COMMENT 'hiring | raise | demotion | correction | bulk_adjust | rehire',
  note                 VARCHAR(255) NULL
                       COMMENT 'UTF-8 notatka (np. powód podwyżki)',
  created_by_user_id   BIGINT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_rate_emp_window (tenant_id, employee_id, rate_type, effective_from, effective_to),
  KEY idx_rate_emp_current (tenant_id, employee_id, rate_type, effective_to),

  CONSTRAINT fk_rate_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rate_emp
    FOREIGN KEY (employee_id) REFERENCES sh_employees (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rate_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='HR · stawki pracownicze (temporal tables — HR-2)';

-- =============================================================================
-- 3. BACKFILL — sh_users → sh_employees
-- -----------------------------------------------------------------------------
-- Rzutujemy każdego istniejącego, niepusniętego i aktywnego użytkownika, który
-- spełnia choć jeden warunek:
--   (a) ma rolę operacyjną (cook/waiter/driver/manager/cashier/cleaner/team/runner),
--   (b) lub ma niezerową stawkę godzinową.
-- Właściciel (owner) również dostaje profil HR — może być również zatrudniony.
--
-- employee_code ← 'EMP-' + LPAD(user_id, 5, '0')
-- display_name  ← COALESCE(first_name+' '+last_name, name, username)
-- primary_role  ← sh_users.role (mapowanie: team → 'cashier', pozostałe 1:1)
-- hire_date     ← DATE(created_at)
-- auth_pin_hash ← NULL (legacy pin_code był plain-text, NIE migrujemy — manager musi nadać nowy PIN)
-- =============================================================================
INSERT INTO sh_employees (
  tenant_id, user_id,
  employee_code, display_name, first_name, last_name,
  hire_date,
  primary_role, status, default_currency,
  created_at, updated_at, is_deleted
)
SELECT
  u.tenant_id,
  u.id AS user_id,
  CONCAT('EMP-', LPAD(u.id, 5, '0')) AS employee_code,
  COALESCE(
    NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
    NULLIF(u.name, ''),
    u.username
  ) AS display_name,
  COALESCE(NULLIF(u.first_name, ''), NULLIF(u.name, ''), u.username) AS first_name,
  COALESCE(NULLIF(u.last_name,  ''), '-') AS last_name,
  DATE(u.created_at) AS hire_date,
  CASE
    WHEN u.role IN ('cook','waiter','driver','manager','cashier','cleaner','runner','shift_lead','owner') THEN u.role
    WHEN u.role = 'team'  THEN 'cashier'
    WHEN u.role = 'admin' THEN 'manager'
    ELSE 'cashier'
  END AS primary_role,
  CASE
    WHEN u.is_active = 0 OR u.is_deleted = 1 THEN 'terminated'
    WHEN u.status IN ('active','suspended','on_leave','terminated') THEN u.status
    ELSE 'active'
  END AS status,
  'PLN' AS default_currency,
  u.created_at,
  u.created_at,
  u.is_deleted
FROM sh_users u
LEFT JOIN sh_employees e
  ON e.user_id = u.id AND e.tenant_id = u.tenant_id
WHERE e.id IS NULL
  AND (
    u.role IN ('cook','waiter','driver','manager','cashier','cleaner','team','runner','shift_lead','owner','admin')
    OR u.hourly_rate > 0
  );

-- =============================================================================
-- 4. BACKFILL — sh_employees → sh_employee_rates
-- -----------------------------------------------------------------------------
-- Dla każdego świeżo utworzonego pracownika, który ma w sh_users.hourly_rate > 0
-- tworzymy początkowy rekord stawki z effective_from = hire_date (00:00:00) i
-- effective_to = NULL (aktualna).
--
-- Konwersja: DECIMAL(10,2) PLN → INT grosze = ROUND(rate × 100).
--
-- Pracownicy z hourly_rate = 0 dostaną stawkę ręcznie przez UI Szefa
-- (unikamy wpisu "0" który zakłamałby payroll — lepszy brak niż kłamstwo).
-- =============================================================================
INSERT INTO sh_employee_rates (
  tenant_id, employee_id,
  rate_type, amount_minor, currency,
  effective_from, effective_to,
  reason, note,
  created_by_user_id, created_at
)
SELECT
  e.tenant_id,
  e.id,
  'hourly',
  CAST(ROUND(u.hourly_rate * 100) AS UNSIGNED) AS amount_minor,
  'PLN',
  TIMESTAMP(e.hire_date, '00:00:00') AS effective_from,
  NULL AS effective_to,
  'hiring',
  'Auto-backfill z sh_users.hourly_rate (migracja 041)',
  NULL,
  CURRENT_TIMESTAMP
FROM sh_employees e
JOIN sh_users u
  ON u.id = e.user_id AND u.tenant_id = e.tenant_id
LEFT JOIN sh_employee_rates r
  ON r.employee_id = e.id
     AND r.tenant_id = e.tenant_id
     AND r.rate_type = 'hourly'
WHERE r.id IS NULL
  AND u.hourly_rate > 0;

-- =============================================================================
-- 5. Deprecation marker — sh_users.hourly_rate
-- -----------------------------------------------------------------------------
-- NIE dropujemy kolumny (Expand-Contract). Zmieniamy tylko COMMENT, żeby każdy
-- deweloper widział, że jest to DEPRECATED i payroll nowej generacji czyta z
-- sh_employee_rates.
-- =============================================================================
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_users' AND COLUMN_NAME = 'hourly_rate';
SET @sql = IF(@col_exists = 1,
  "ALTER TABLE sh_users MODIFY COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'DEPRECATED_HR_M041 — czytaj sh_employee_rates. Usunięcie po 2x zamknięciu okresu.'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 041.
-- Walidacja post-run (diagnostyka — zakomentowane, odblokuj lokalnie w razie potrzeby):
--   SELECT COUNT(*) AS emp_count FROM sh_employees;
--   SELECT COUNT(*) AS rate_count FROM sh_employee_rates WHERE effective_to IS NULL;
--   SELECT u.id, u.username, e.id AS emp_id, e.employee_code
--     FROM sh_users u LEFT JOIN sh_employees e ON e.user_id = u.id AND e.tenant_id = u.tenant_id
--     WHERE e.id IS NULL;
-- =============================================================================
