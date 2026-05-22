-- =============================================================================
-- SliceHub Pro — Migration 059: sh_product_mapping UNIQUE per dostawca (NIP)
-- Zastępuje uq_mapping (tenant + external_name) → (tenant + supplier_nip + external_name)
-- IDEMPOTENT (MariaDB 10.4+).
-- =============================================================================

SET NAMES utf8mb4;
SET @dbname = DATABASE();

UPDATE sh_product_mapping SET supplier_nip = '' WHERE supplier_nip IS NULL;

DELETE m1 FROM sh_product_mapping m1
INNER JOIN sh_product_mapping m2
  ON m1.tenant_id = m2.tenant_id
 AND COALESCE(m1.supplier_nip, '') = COALESCE(m2.supplier_nip, '')
 AND LOWER(m1.external_name) = LOWER(m2.external_name)
 AND m1.id < m2.id;

SELECT COUNT(*) INTO @has_new FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_product_mapping' AND INDEX_NAME = 'uq_mapping_supplier';

SET @sql_mod = IF(@has_new = 0,
  "ALTER TABLE sh_product_mapping MODIFY supplier_nip VARCHAR(15) NOT NULL DEFAULT ''",
  'SELECT 1');
PREPARE sm FROM @sql_mod; EXECUTE sm; DEALLOCATE PREPARE sm;

SET @sql_add = IF(@has_new = 0,
  "ALTER TABLE sh_product_mapping
     ADD UNIQUE KEY uq_mapping_supplier (tenant_id, supplier_nip, external_name(191))",
  'SELECT 1');
PREPARE sa FROM @sql_add; EXECUTE sa; DEALLOCATE PREPARE sa;

SELECT COUNT(*) INTO @has_old FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_product_mapping' AND INDEX_NAME = 'uq_mapping';
SET @sql_drop = IF(@has_old > 0,
  'ALTER TABLE sh_product_mapping DROP INDEX uq_mapping',
  'SELECT 1');
PREPARE sd FROM @sql_drop; EXECUTE sd; DEALLOCATE PREPARE sd;

SELECT 'm059 product_mapping_unique_supplier applied' AS migration_059;
