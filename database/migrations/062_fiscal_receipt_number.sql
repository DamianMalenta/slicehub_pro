-- 062_fiscal_receipt_number.sql
-- Kolumna na numer paragonu fiskalnego z drukarki Elzab Zeta Online.
-- Wypełniana przez ElzabFiscalEngine::fiscalizeOrder() po udanym wydruku.

ALTER TABLE sh_orders
  ADD COLUMN IF NOT EXISTS fiscal_receipt_number VARCHAR(20) NULL
  COMMENT 'Numer paragonu fiskalnego z drukarki (Elzab Zeta Online)' AFTER receipt_printed;

-- Indeks dla szybkiego wyszukiwania po numerze paragonu
CREATE INDEX IF NOT EXISTS idx_orders_fiscal_receipt
  ON sh_orders (tenant_id, fiscal_receipt_number);
