-- =============================================================================
-- SliceHub Pro — Migration 058: KSeF linie — cache normalizacji qty do base_unit
-- + opcjonalne pack_* na sh_product_mapping (learn z accept)
-- IDEMPOTENT (MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;
SET @dbname = DATABASE();

-- ── sh_ksef_invoice_lines ───────────────────────────────────────────────────
SELECT COUNT(*) INTO @col_qn FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_ksef_invoice_lines' AND COLUMN_NAME = 'qty_normalized';
SET @sql_qn = IF(@col_qn = 0,
  "ALTER TABLE sh_ksef_invoice_lines
     ADD COLUMN qty_normalized DECIMAL(15,6) NULL COMMENT 'Ilość w sys_items.base_unit' AFTER vat_rate,
     ADD COLUMN unit_net_normalized DECIMAL(15,4) NULL COMMENT 'Cena netto za 1 base_unit' AFTER qty_normalized,
     ADD COLUMN normalization_status VARCHAR(16) NULL COMMENT 'ok|warn|blocked' AFTER unit_net_normalized,
     ADD COLUMN normalization_meta JSON NULL COMMENT 'Źródło przeliczenia, kroki' AFTER normalization_status",
  'SELECT 1');
PREPARE stmt_qn FROM @sql_qn; EXECUTE stmt_qn; DEALLOCATE PREPARE stmt_qn;

-- ── sh_product_mapping — pack learn ─────────────────────────────────────────
SELECT COUNT(*) INTO @col_pb FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_product_mapping' AND COLUMN_NAME = 'pack_qty_base';
SET @sql_pb = IF(@col_pb = 0,
  "ALTER TABLE sh_product_mapping
     ADD COLUMN supplier_nip VARCHAR(15) NULL COMMENT 'Opcjonalny kontekst dostawcy' AFTER internal_sku,
     ADD COLUMN pack_qty_base DECIMAL(15,6) NULL COMMENT 'Ilość base_unit na 1 jednostkę FA' AFTER supplier_nip,
     ADD COLUMN pack_invoice_unit VARCHAR(32) NULL COMMENT 'Jednostka FA dla pack (np. szt)' AFTER pack_qty_base",
  'SELECT 1');
PREPARE stmt_pb FROM @sql_pb; EXECUTE stmt_pb; DEALLOCATE PREPARE stmt_pb;

SELECT 'm058 ksef_line_qty_normalization applied' AS migration_058;
