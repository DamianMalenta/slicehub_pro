-- =============================================================================
-- SliceHub Pro — Migration 044: HR & Payroll · Advances + Installments
-- -----------------------------------------------------------------------------
-- Zaliczki pracownicze z pełnym workflow (request → approve → pay → settle).
-- Spec: _docs/18_BACKOFFICE_HR_LOGIC.md §3.5
--
-- Zakres:
--   1. sh_advances               — wniosek/zaliczka (aggregate root)
--   2. sh_advance_installments   — harmonogram spłat (child)
--   3. FK back-fill do sh_payroll_ledger (ref_advance_id / ref_installment_id)
--
-- Workflow statusów:
--   requested → approved → paid → (repayments accrue) → settled
--        │         │                                │
--        └─ rejected  └─ void                       └─ void
--
-- Reguła: advance_payout NIE dotyka net (amount_minor = 0 w ledgerze),
-- advance_repayment potrąca net (amount_minor < 0 w ledgerze).
--
-- IDEMPOTENT. Safe to re-run on MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

-- =============================================================================
-- 1. sh_advances — wniosek o zaliczkę (aggregate root)
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_advances (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advance_uuid           CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                         COMMENT 'UUID v4 — stabilny klucz dla API i integracji',
  tenant_id              INT UNSIGNED    NOT NULL,
  employee_id            BIGINT UNSIGNED NOT NULL,

  -- MONEY (INT UNSIGNED — zaliczka NIGDY ujemna)
  amount_minor           INT UNSIGNED    NOT NULL
                         COMMENT 'Kwota zaliczki w groszach',
  currency               CHAR(3) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'PLN',

  -- STATUS MACHINE (ASCII dictionary)
  status                 VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'requested'
                         COMMENT 'requested | approved | paid | settled | rejected | void',

  -- REPAYMENT PLAN
  repayment_plan         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'single'
                         COMMENT 'single | monthly_installments | weekly_installments',
  installments_count     TINYINT UNSIGNED NOT NULL DEFAULT 1
                         COMMENT 'Ile rat (1 dla single)',

  reason                 VARCHAR(255) NULL
                         COMMENT 'UTF-8 opis powodu wniosku (pole pracownika)',

  -- WORKFLOW TIMESTAMPS + AKTORZY
  requested_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  requested_by_user_id   BIGINT UNSIGNED NULL
                         COMMENT 'Wnioskodawca: zwykle sam pracownik; może być manager w imieniu',

  approved_at            DATETIME NULL,
  approved_by_user_id    BIGINT UNSIGNED NULL,

  rejected_at            DATETIME NULL,
  rejection_reason       VARCHAR(255) NULL COMMENT 'UTF-8',

  paid_at                DATETIME NULL,
  paid_method            VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NULL
                         COMMENT 'cash | transfer | payroll_deduction',
  paid_by_user_id        BIGINT UNSIGNED NULL,

  settled_at             DATETIME NULL COMMENT 'Gdy SUM(installments.applied) = amount_minor',
  void_at                DATETIME NULL,

  -- META
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_advance_uuid (advance_uuid),
  KEY idx_adv_emp_status     (tenant_id, employee_id, status),
  KEY idx_adv_status_created (tenant_id, status, created_at),
  KEY idx_adv_paid_at        (tenant_id, paid_at),

  CONSTRAINT fk_adv_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_adv_emp
    FOREIGN KEY (employee_id) REFERENCES sh_employees (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_adv_requested_by
    FOREIGN KEY (requested_by_user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_adv_approved_by
    FOREIGN KEY (approved_by_user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_adv_paid_by
    FOREIGN KEY (paid_by_user_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='HR · zaliczki pracownicze (aggregate root · Faza 3A)';

-- =============================================================================
-- 2. sh_advance_installments — harmonogram spłat
-- -----------------------------------------------------------------------------
-- Wygenerowany automatycznie przez AdvanceEngine::projectInstallments(advance_id)
-- w momencie paid_at. Każda rata przypisana do okresu bilansowego (year, month).
-- Gdy okres zamyka PayrollPeriodEngine → rata 'pending' → 'applied', tworzy
-- wpis w sh_payroll_ledger (entry_type='advance_repayment', amount_minor < 0).
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_advance_installments (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                INT UNSIGNED    NOT NULL,
  advance_id               BIGINT UNSIGNED NOT NULL,
  seq_no                   TINYINT UNSIGNED NOT NULL
                           COMMENT '1..installments_count, kolejność chronologiczna',

  -- MONEY
  amount_minor             INT UNSIGNED NOT NULL
                           COMMENT 'Kwota raty w groszach (SUM ze wszystkich rat == advance.amount_minor)',
  currency                 CHAR(3) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'PLN',

  -- HARMONOGRAM
  scheduled_period_year    SMALLINT UNSIGNED NOT NULL,
  scheduled_period_month   TINYINT  UNSIGNED NOT NULL,

  -- STATUS (ASCII dictionary)
  status                   VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'pending'
                           COMMENT 'pending | applied | skipped | void',

  -- APLIKACJA → LEDGER
  applied_ledger_entry_id  BIGINT UNSIGNED NULL
                           COMMENT 'FK sh_payroll_ledger po aplikacji raty (nie NULL gdy status=applied)',
  applied_at               DATETIME NULL,

  -- META
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_inst_seq (tenant_id, advance_id, seq_no),
  KEY idx_inst_period    (tenant_id, scheduled_period_year, scheduled_period_month, status),
  KEY idx_inst_advance   (advance_id, status),
  KEY idx_inst_applied   (applied_ledger_entry_id),

  CONSTRAINT fk_inst_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_inst_advance
    FOREIGN KEY (advance_id) REFERENCES sh_advances (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_inst_ledger
    FOREIGN KEY (applied_ledger_entry_id) REFERENCES sh_payroll_ledger (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='HR · harmonogram spłat zaliczek (Faza 3A)';

-- =============================================================================
-- 3. Back-fill FK w sh_payroll_ledger (m043 zostawił miejsce)
-- -----------------------------------------------------------------------------
-- Dopiero teraz, gdy istnieją sh_advances + sh_advance_installments, możemy
-- dodać FK na ref_advance_id i ref_installment_id.
-- =============================================================================
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_payroll_ledger' AND CONSTRAINT_NAME = 'fk_ledger_ref_adv';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_payroll_ledger ADD CONSTRAINT fk_ledger_ref_adv FOREIGN KEY (ref_advance_id) REFERENCES sh_advances (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @fk_exists FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_payroll_ledger' AND CONSTRAINT_NAME = 'fk_ledger_ref_inst';
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE sh_payroll_ledger ADD CONSTRAINT fk_ledger_ref_inst FOREIGN KEY (ref_installment_id) REFERENCES sh_advance_installments (id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Koniec migracji 044.
-- Silos HR & Payroll (Faza 3A) — tabele gotowe.
-- Następne kroki (poza scope'em migracji):
--   - core/AdvanceEngine.php        (request/approve/pay/void + projectInstallments)
--   - core/PayrollLedger.php        (append-only writer z walidacją inwariantów)
--   - core/PayrollEngine v2         (czyta z ledger zamiast raw sessions+deductions)
--   - api/backoffice/hr/engine.php  (action-based: clock_in, clock_out, clock_status, ...)
--   - scripts/worker_payroll_accrual.php (konsumuje employee.clocked_out → hours_accrual)
--   - scripts/worker_driver_fanout.php   (konsumuje employee.clocked_in  → sh_drivers)
-- =============================================================================
