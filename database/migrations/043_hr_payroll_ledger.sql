-- =============================================================================
-- SliceHub Pro — Migration 043: HR & Payroll · Payroll Ledger (append-only)
-- -----------------------------------------------------------------------------
-- Księga zdarzeń wynagrodzeń. Single Source of Truth dla payroll.
-- Spec: _docs/18_BACKOFFICE_HR_LOGIC.md §3.4
--
-- Zasady:
--   1. APPEND-ONLY. Żaden UPDATE poza is_locked/locked_at/applied_ledger_entry_id.
--      Korekty = nowy wpis z reverses_entry_id, NIGDY edycja istniejącego.
--   2. amount_minor jest SIGNED (INT, nie INT UNSIGNED!) — dodatnie zwiększa net,
--      ujemne zmniejsza. Net = SUM(amount_minor).
--   3. rate_applied_minor to SNAPSHOT stawki z momentu naliczenia — nawet jeśli
--      sh_employee_rates zmieni się wstecznie, ledger pokazuje kwotę wypłaconą.
--   4. Kolumny is_locked/locked_at — infrastruktura locka okresu. Logika
--      PayrollPeriodEngine::close() — Faza 3C (nie w tej migracji).
--   5. Slownik entry_type to ASCII keys (§2.4 spec):
--      hours_accrual      (+)  — godziny × stawka (z sh_work_sessions)
--      bonus              (+)  — decyzja managera
--      correction_plus    (+)  — manualna korekta in-plus
--      correction_minus   (-)  — manualna korekta in-minus
--      advance_payout     (0)  — informacyjnie, NIE dotyka net
--      advance_repayment  (-)  — spłata raty zaliczki
--      meal_charge        (-)  — posiłek pracowniczy
--      penalty            (-)  — potrącenie karne
--      tax_withholding    (-)  — zaliczka PIT (przyszłość, Faza 3B)
--      zus_withholding    (-)  — ZUS (przyszłość, Faza 3B)
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sh_payroll_ledger (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_uuid           CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                       COMMENT 'UUID v4, natural key dla idempotencji z workerami',
  tenant_id            INT UNSIGNED    NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,

  -- OKRES BILANSOWY
  period_year          SMALLINT UNSIGNED NOT NULL COMMENT 'np. 2026',
  period_month         TINYINT  UNSIGNED NOT NULL COMMENT '1..12',

  -- TYP ZDARZENIA (ASCII dictionary)
  entry_type           VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                       COMMENT 'hours_accrual | bonus | correction_plus | correction_minus | advance_payout | advance_repayment | meal_charge | penalty | tax_withholding | zus_withholding',

  -- MONEY (SIGNED + explicit currency)
  amount_minor         INT NOT NULL
                       COMMENT 'SIGNED w groszach: (+) zwiększa net, (-) zmniejsza; dla advance_payout = 0 (informacyjny)',
  currency             CHAR(3) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'PLN',

  -- HOURS (wypełnione TYLKO dla entry_type = 'hours_accrual')
  hours_qty            DECIMAL(10,4) NULL COMMENT 'Godziny naliczone (ROUND 0.36s)',
  rate_applied_minor   INT UNSIGNED  NULL
                       COMMENT 'Snapshot stawki (grosze/h). Wykorzystany do obliczenia amount_minor',

  -- REFERENCJE (każda NULL, wypełniana zależnie od entry_type)
  ref_work_session_id  BIGINT UNSIGNED NULL COMMENT 'FK sh_work_sessions (hours_accrual)',
  ref_advance_id       BIGINT UNSIGNED NULL COMMENT 'FK sh_advances (advance_payout / advance_repayment)',
  ref_installment_id   BIGINT UNSIGNED NULL COMMENT 'FK sh_advance_installments (advance_repayment)',
  ref_meal_id          BIGINT UNSIGNED NULL COMMENT 'FK sh_meals (meal_charge)',
  reverses_entry_id    BIGINT UNSIGNED NULL COMMENT 'Wskazuje wpis, który ten koryguje (correction_*)',

  -- AUDIT
  description          VARCHAR(255) NULL COMMENT 'UTF-8 notatka operatora',
  created_by_user_id   BIGINT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- LOCK OKRESU (Faza 3C logika, tu tylko kolumny)
  is_locked            TINYINT(1) NOT NULL DEFAULT 0
                       COMMENT 'Faza 3C: gdy okres zamknięty → 1',
  locked_at            DATETIME   NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_uuid (entry_uuid),

  -- Kluczowe indeksy — okresowe agregaty + lookup per-referencja
  KEY idx_ledger_emp_period   (tenant_id, employee_id, period_year, period_month),
  KEY idx_ledger_type_period  (tenant_id, entry_type, period_year, period_month),
  KEY idx_ledger_period       (tenant_id, period_year, period_month, is_locked),
  KEY idx_ledger_ref_ws       (ref_work_session_id),
  KEY idx_ledger_ref_adv      (ref_advance_id),
  KEY idx_ledger_ref_inst     (ref_installment_id),
  KEY idx_ledger_ref_meal     (ref_meal_id),
  KEY idx_ledger_reverses     (reverses_entry_id),

  CONSTRAINT fk_ledger_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ledger_emp
    FOREIGN KEY (employee_id) REFERENCES sh_employees (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ledger_ws
    FOREIGN KEY (ref_work_session_id) REFERENCES sh_work_sessions (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_ledger_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_ledger_reverses
    FOREIGN KEY (reverses_entry_id) REFERENCES sh_payroll_ledger (id)
    ON UPDATE CASCADE ON DELETE SET NULL
  -- FK do sh_advances / sh_advance_installments / sh_meals dodane zostaną w
  -- momencie powstania tamtych tabel (m044 + istniejąca sh_meals). Dla
  -- sh_meals FK dodajemy w tej migracji (patrz niżej), dla advances/installments
  -- w m044.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='HR · append-only księga zdarzeń wynagrodzeń (Faza 3A)';

-- =============================================================================
-- FK do sh_meals (istnieje od m001)
-- -----------------------------------------------------------------------------
-- Dodajemy z information_schema-checkiem, bo MariaDB 10.4 nie wspiera
-- "ADD CONSTRAINT IF NOT EXISTS".
-- =============================================================================
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_payroll_ledger' AND CONSTRAINT_NAME = 'fk_ledger_ref_meal';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_payroll_ledger ADD CONSTRAINT fk_ledger_ref_meal FOREIGN KEY (ref_meal_id) REFERENCES sh_meals (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 043.
-- Faza 3A → ledger jest pusty. Worker payroll (scripts/worker_payroll_accrual.php
-- — do stworzenia) będzie konsumował event 'employee.clocked_out' i tworzył
-- wpisy hours_accrual. Stare API (PayrollEngine v1) nadal czyta sh_work_sessions
-- + sh_deductions + sh_meals bezpośrednio — dopóki PayrollEngine v2 nie przejmie.
-- =============================================================================
