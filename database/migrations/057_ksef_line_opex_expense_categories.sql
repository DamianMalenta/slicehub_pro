-- =============================================================================
-- SliceHub Pro — Migration 057: KSeF linie INVENTORY vs EXPENSE + kategorie OPEX
-- -----------------------------------------------------------------------------
-- INVENTORY → PzEngine (bez zmian). EXPENSE → tag w bazie, bez magazynu / AVCO.
-- sh_orders.commission_amount — fundament BI (agregatory).
-- IDEMPOTENT (MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;
SET @dbname = DATABASE();

-- ── 1. sh_expense_categories ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sh_expense_categories (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    INT UNSIGNED NOT NULL,
    name         VARCHAR(128) NOT NULL,
    is_system    TINYINT(1) NOT NULL DEFAULT 0,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    is_deleted   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exp_cat_tenant (tenant_id, is_deleted, is_active),
    KEY idx_exp_cat_tenant_name (tenant_id, name),
    CONSTRAINT fk_exp_cat_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kategorie kosztów OPEX (KSeF linie EXPENSE)';

-- ── 2. sh_ksef_invoice_lines — line_type + expense_category_id ────────────
SELECT COUNT(*) INTO @col_lt FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_ksef_invoice_lines' AND COLUMN_NAME = 'line_type';
SET @sql_lt = IF(@col_lt = 0,
  "ALTER TABLE sh_ksef_invoice_lines
     ADD COLUMN line_type ENUM('INVENTORY','EXPENSE') NOT NULL DEFAULT 'INVENTORY' AFTER resolved_by_user_id,
     ADD COLUMN expense_category_id BIGINT UNSIGNED NULL COMMENT 'FK sh_expense_categories (EXPENSE)' AFTER line_type,
     ADD KEY idx_ksef_line_type (ksef_invoice_id, line_type),
     ADD CONSTRAINT fk_ksef_line_exp_cat FOREIGN KEY (expense_category_id)
       REFERENCES sh_expense_categories (id) ON DELETE SET NULL ON UPDATE CASCADE",
  'SELECT 1');
PREPARE stmt_lt FROM @sql_lt; EXECUTE stmt_lt; DEALLOCATE PREPARE stmt_lt;

-- ── 3. sh_orders — commission_amount (grosze) ─────────────────────────────
SELECT COUNT(*) INTO @col_ca FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'commission_amount';
SET @sql_ca = IF(@col_ca = 0,
  "ALTER TABLE sh_orders ADD COLUMN commission_amount INT NOT NULL DEFAULT 0 COMMENT 'Prowizja agregatora / BI — grosze'",
  'SELECT 1');
PREPARE stmt_ca FROM @sql_ca; EXECUTE stmt_ca; DEALLOCATE PREPARE stmt_ca;

-- ── 4. Domyślne kategorie systemowe (tenant_id = 1) ─────────────────────────
INSERT INTO sh_expense_categories (tenant_id, name, is_system, is_active, is_deleted)
SELECT 1, v.name, 1, 1, 0
FROM (
    SELECT 'Food Cost' AS name UNION ALL SELECT 'Packaging' UNION ALL SELECT 'Logistics'
    UNION ALL SELECT 'Facility' UNION ALL SELECT 'Sales & Marketing' UNION ALL SELECT 'IT & Admin'
) AS v
WHERE EXISTS (SELECT 1 FROM sh_tenant t WHERE t.id = 1)
  AND NOT EXISTS (
      SELECT 1 FROM sh_expense_categories ec
       WHERE ec.tenant_id = 1 AND ec.name = v.name AND ec.is_deleted = 0
  );

SELECT 'm057 ksef_line_opex_expense_categories applied' AS migration_057;
