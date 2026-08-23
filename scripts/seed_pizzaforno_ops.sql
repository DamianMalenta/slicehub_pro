-- =============================================================================
-- seed_pizzaforno_ops.sql — SliceHub Pro
-- Wygenerowane: 2026-08-23 21:12:45
-- Tryb: OPS-ONLY (dane operacyjne: users, wh_stock, PZ, KSeF, zamówienia)
-- Wymaga: najpierw zaimportuj seed_pizzaforno_menu.sql (katalog jedzenia)
-- Wymaga: istniejącego tenant 2 w sh_tenant (np. utworzonego przez install_panel.php)
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @tid := 2;
SET @wh  := 'MAIN';


-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 0: CLEANUP (dane operacyjne — NIE dotyka menu)
-- ═══════════════════════════════════════════════════════════════════════
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM sh_order_payments WHERE order_id IN
  (SELECT id FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%');
DELETE FROM sh_order_audit WHERE order_id IN
  (SELECT id FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%');
DELETE FROM sh_order_lines WHERE order_id IN
  (SELECT id FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%');
DELETE FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%';

DELETE FROM sh_ksef_invoice_lines WHERE ksef_invoice_id IN
  (SELECT id FROM sh_ksef_invoices WHERE tenant_id=@tid AND invoice_number LIKE 'FA/FORNO/%');
DELETE FROM sh_ksef_invoices WHERE tenant_id=@tid AND invoice_number LIKE 'FA/FORNO/%';

DELETE FROM wh_document_lines WHERE document_id IN
  (SELECT id FROM wh_documents WHERE tenant_id=@tid AND doc_number LIKE 'PZ-2026/%/FORNO%');
DELETE FROM wh_documents WHERE tenant_id=@tid AND doc_number LIKE 'PZ-2026/%/FORNO%';

DELETE FROM wh_stock WHERE tenant_id=@tid AND sku IN ('MAKA_TYP_00', 'MAKA_SEZAMOWA', 'SOS_POMIDOROWY', 'SOS_SMIETANKOWY', 'SOS_CZOSNKOWY', 'SOS_BBQ', 'SOS_MEKSYKANSKI', 'SOS_1000_WYSP', 'TABASCO', 'MAJONEZ', 'KETCHUP', 'KREM_BALSAMICZNY', 'MOZZ_FIOR', 'MOZZ_BUFFALO', 'RICOTTA', 'PARMEZAN', 'GORGONZOLA', 'FETA', 'EDAMSKI', 'SALAMI_PICANTE', 'SALAMI', 'NDUJA', 'KIELB_WLOSKA', 'SZYNKA_PARM', 'SZYNKA', 'KURCZAK', 'STEK_WOLOWY', 'BOCZEK', 'RWANA_WIEPRZ', 'KEBAB_DROBIOWY', 'MIESO_WOLOWE', 'ANCHOIS', 'TUNCZYK', 'GYROS_MIESO', 'PIECZARKI', 'PIECZARKI_SMAZONE', 'CEBULA_CZERW', 'PAPRYKA', 'OLIWKI', 'POMIDORKI_KOKAT', 'POMIDORKI_SUSZE', 'KUKURYDZA', 'RUKOLA', 'SZPINAK', 'CHILLI', 'JALAPENO', 'KAP_PEKINSKA', 'OGUREK_KONS', 'SALATA', 'CUKINIA', 'BRUKOLY', 'BAZYLIA', 'GRUSZKA', 'ANANAS', 'FRYTKI', 'CHIPSY_ZIEMN', 'OREGANO', 'OLIWA', 'MAKARON', 'CIASTO_GYROS', 'BUŁKA_PANINI', 'COCA_COLA', 'SPRITE', 'WODA_NIEGAZ', 'WODA_GAZ', 'PIWO_BUTELKA', 'LODY_GALKA');

DELETE FROM sh_driver_shifts WHERE driver_id IN
  (SELECT user_id FROM sh_drivers WHERE tenant_id=@tid);
DELETE FROM sh_drivers WHERE tenant_id=@tid;

DELETE FROM sh_users WHERE tenant_id=@tid AND username IN
  ('forno_owner','forno_manager','forno_waiter','forno_cook','forno_driver');

DELETE FROM sh_tenant_settings WHERE tenant_id=@tid AND setting_key IN
  ('', 'currency', 'default_vat_dine_in', 'default_vat_takeaway', 'half_half_surcharge');

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 1-8: INSERT DATA (operacyjne)
-- ═══════════════════════════════════════════════════════════════════════
START TRANSACTION;

-- ── 1 sh_tenant_settings (5 wierszy) ───────────────────────────────────
INSERT INTO sh_tenant_settings (tenant_id, setting_key, is_active, min_order_value,
  min_prep_time_minutes, sla_green_min, sla_yellow_min, base_prep_minutes,
  min_lead_time_minutes, setting_value)
VALUES (@tid, '', 1, 0, 30, 10, 5, 25, 30, NULL)
ON DUPLICATE KEY UPDATE is_active=1;

INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
VALUES (@tid, 'currency', 'PLN'),
       (@tid, 'default_vat_dine_in', '8'),
       (@tid, 'default_vat_takeaway', '5'),
       (@tid, 'half_half_surcharge', '200')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ── 2 sh_users (5 kont testowych z PIN) ────────────────────────────────
INSERT INTO sh_users (tenant_id, username, password_hash, pin_code, name,
  first_name, last_name, role, status, is_active, is_deleted)
VALUES
  (@tid, 'forno_owner',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0000', 'Owner Forno',    'Owner',  'Forno',   'owner',   'active', 1, 0),
  (@tid, 'forno_manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '1000', 'Manager Forno',  'Anna',   'Manager', 'manager', 'active', 1, 0),
  (@tid, 'forno_waiter',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '1111', 'Kelner Forno',   'Marek',  'Kelner',  'waiter',  'active', 1, 0),
  (@tid, 'forno_cook',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3333', 'Kucharz Forno',  'Piotr',  'Kucharz', 'cook',    'active', 1, 0),
  (@tid, 'forno_driver',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '4444', 'Kierowca Forno', 'Tomek',  'Kierowca','driver',  'active', 1, 0)
ON DUPLICATE KEY UPDATE is_active=1;

SET @uid_owner   = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_owner');
SET @uid_manager = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_manager');
SET @uid_waiter  = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_waiter');
SET @uid_cook    = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_cook');
SET @uid_driver  = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_driver');

INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (@uid_driver, @tid, 'available')
ON DUPLICATE KEY UPDATE status='available';
INSERT INTO sh_driver_shifts (tenant_id, driver_id, initial_cash, status)
VALUES (@tid, @uid_driver, 10000, 'active')
ON DUPLICATE KEY UPDATE status='active';

-- ── 3 wh_stock (stany początkowe — 67 SKU) ─────────────────────────────
INSERT INTO wh_stock (tenant_id, warehouse_id, sku, quantity, current_avco_price, unit_net_cost) VALUES
(@tid, @wh, 'MAKA_TYP_00', 120.0, 3.5, 3.5),
(@tid, @wh, 'MAKA_SEZAMOWA', 15.0, 8.0, 8.0),
(@tid, @wh, 'SOS_POMIDOROWY', 45.0, 4.2, 4.2),
(@tid, @wh, 'SOS_SMIETANKOWY', 10.0, 6.2, 6.2),
(@tid, @wh, 'SOS_CZOSNKOWY', 8.0, 5.8, 5.8),
(@tid, @wh, 'SOS_BBQ', 6.0, 7.5, 7.5),
(@tid, @wh, 'SOS_MEKSYKANSKI', 5.0, 7.5, 7.5),
(@tid, @wh, 'SOS_1000_WYSP', 5.0, 6.9, 6.9),
(@tid, @wh, 'TABASCO', 2.0, 24.0, 24.0),
(@tid, @wh, 'MAJONEZ', 8.0, 4.8, 4.8),
(@tid, @wh, 'KETCHUP', 8.0, 3.8, 3.8),
(@tid, @wh, 'KREM_BALSAMICZNY', 3.0, 28.0, 28.0),
(@tid, @wh, 'MOZZ_FIOR', 30.0, 28.5, 28.5),
(@tid, @wh, 'MOZZ_BUFFALO', 8.0, 52.0, 52.0),
(@tid, @wh, 'RICOTTA', 10.0, 18.0, 18.0),
(@tid, @wh, 'PARMEZAN', 6.0, 65.0, 65.0),
(@tid, @wh, 'GORGONZOLA', 3.0, 48.0, 48.0),
(@tid, @wh, 'FETA', 4.0, 32.0, 32.0),
(@tid, @wh, 'EDAMSKI', 5.0, 28.0, 28.0),
(@tid, @wh, 'SALAMI_PICANTE', 10.0, 42.0, 42.0),
(@tid, @wh, 'SALAMI', 8.0, 38.0, 38.0),
(@tid, @wh, 'NDUJA', 5.0, 55.0, 55.0),
(@tid, @wh, 'KIELB_WLOSKA', 7.0, 38.0, 38.0),
(@tid, @wh, 'SZYNKA_PARM', 4.0, 89.0, 89.0),
(@tid, @wh, 'SZYNKA', 8.0, 28.0, 28.0),
(@tid, @wh, 'KURCZAK', 10.0, 18.5, 18.5),
(@tid, @wh, 'STEK_WOLOWY', 5.0, 48.0, 48.0),
(@tid, @wh, 'BOCZEK', 6.0, 24.0, 24.0),
(@tid, @wh, 'RWANA_WIEPRZ', 4.0, 28.0, 28.0),
(@tid, @wh, 'KEBAB_DROBIOWY', 5.0, 19.5, 19.5),
(@tid, @wh, 'MIESO_WOLOWE', 4.0, 35.0, 35.0),
(@tid, @wh, 'ANCHOIS', 2.0, 45.0, 45.0),
(@tid, @wh, 'TUNCZYK', 6.0, 22.0, 22.0),
(@tid, @wh, 'GYROS_MIESO', 8.0, 22.0, 22.0),
(@tid, @wh, 'PIECZARKI', 15.0, 7.5, 7.5),
(@tid, @wh, 'PIECZARKI_SMAZONE', 5.0, 9.5, 9.5),
(@tid, @wh, 'CEBULA_CZERW', 10.0, 3.2, 3.2),
(@tid, @wh, 'PAPRYKA', 8.0, 6.0, 6.0),
(@tid, @wh, 'OLIWKI', 5.0, 12.0, 12.0),
(@tid, @wh, 'POMIDORKI_KOKAT', 12.0, 8.5, 8.5),
(@tid, @wh, 'POMIDORKI_SUSZE', 4.0, 35.0, 35.0),
(@tid, @wh, 'KUKURYDZA', 6.0, 3.8, 3.8),
(@tid, @wh, 'RUKOLA', 10.0, 14.0, 14.0),
(@tid, @wh, 'SZPINAK', 6.0, 9.0, 9.0),
(@tid, @wh, 'CHILLI', 2.0, 18.0, 18.0),
(@tid, @wh, 'JALAPENO', 2.0, 22.0, 22.0),
(@tid, @wh, 'KAP_PEKINSKA', 4.0, 4.8, 4.8),
(@tid, @wh, 'OGUREK_KONS', 3.0, 4.8, 4.8),
(@tid, @wh, 'SALATA', 5.0, 8.0, 8.0),
(@tid, @wh, 'CUKINIA', 4.0, 5.2, 5.2),
(@tid, @wh, 'BRUKOLY', 3.0, 6.8, 6.8),
(@tid, @wh, 'BAZYLIA', 2.0, 25.0, 25.0),
(@tid, @wh, 'GRUSZKA', 5.0, 6.5, 6.5),
(@tid, @wh, 'ANANAS', 5.0, 7.8, 7.8),
(@tid, @wh, 'FRYTKI', 8.0, 4.5, 4.5),
(@tid, @wh, 'CHIPSY_ZIEMN', 6.0, 8.0, 8.0),
(@tid, @wh, 'OREGANO', 3.0, 22.0, 22.0),
(@tid, @wh, 'OLIWA', 10.0, 18.9, 18.9),
(@tid, @wh, 'MAKARON', 10.0, 4.8, 4.8),
(@tid, @wh, 'CIASTO_GYROS', 8.0, 5.8, 5.8),
(@tid, @wh, 'BUŁKA_PANINI', 250.0, 0.85, 0.85),
(@tid, @wh, 'COCA_COLA', 120.0, 2.2, 2.2),
(@tid, @wh, 'SPRITE', 60.0, 2.2, 2.2),
(@tid, @wh, 'WODA_NIEGAZ', 60.0, 1.1, 1.1),
(@tid, @wh, 'WODA_GAZ', 60.0, 1.1, 1.1),
(@tid, @wh, 'PIWO_BUTELKA', 180.0, 3.5, 3.5),
(@tid, @wh, 'LODY_GALKA', 100.0, 1.5, 1.5);

-- ── 4 wh_documents + wh_document_lines (5 PZ) ──────────────────────────
INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-001', 'PZ', @wh, 'completed',
  'HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o.', 'PZ-2026/05/FORNO-001',
  'NIP: 5252311234', '2026-08-09 21:12:45');
SET @pz_id = LAST_INSERT_ID();

INSERT INTO wh_document_lines (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, system_qty, counted_qty)
VALUES
(@pz_id, 'MAKA_TYP_00', 50.0, 3.5, 175.0, 8.0, 0, 0),
(@pz_id, 'SOS_POMIDOROWY', 30.0, 4.2, 126.0, 5.0, 0, 0),
(@pz_id, 'MOZZ_FIOR', 20.0, 28.5, 570.0, 8.0, 0, 0),
(@pz_id, 'OREGANO', 2.0, 22.0, 44.0, 23.0, 0, 0),
(@pz_id, 'OLIWA', 10.0, 18.9, 189.0, 23.0, 0, 0),
(@pz_id, 'SALAMI_PICANTE', 8.0, 42.0, 336.0, 8.0, 0, 0),
(@pz_id, 'KIELB_WLOSKA', 5.0, 38.0, 190.0, 8.0, 0, 0),
(@pz_id, 'NDUJA', 4.0, 55.0, 220.0, 8.0, 0, 0);

UPDATE wh_stock SET
  quantity = quantity + 50.0,
  current_avco_price = (current_avco_price * quantity + 50.0 * 3.5) / (quantity + 50.0),
  unit_net_cost = 3.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='MAKA_TYP_00';
UPDATE wh_stock SET
  quantity = quantity + 30.0,
  current_avco_price = (current_avco_price * quantity + 30.0 * 4.2) / (quantity + 30.0),
  unit_net_cost = 4.2
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SOS_POMIDOROWY';
UPDATE wh_stock SET
  quantity = quantity + 20.0,
  current_avco_price = (current_avco_price * quantity + 20.0 * 28.5) / (quantity + 20.0),
  unit_net_cost = 28.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='MOZZ_FIOR';
UPDATE wh_stock SET
  quantity = quantity + 2.0,
  current_avco_price = (current_avco_price * quantity + 2.0 * 22.0) / (quantity + 2.0),
  unit_net_cost = 22.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='OREGANO';
UPDATE wh_stock SET
  quantity = quantity + 10.0,
  current_avco_price = (current_avco_price * quantity + 10.0 * 18.9) / (quantity + 10.0),
  unit_net_cost = 18.9
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='OLIWA';
UPDATE wh_stock SET
  quantity = quantity + 8.0,
  current_avco_price = (current_avco_price * quantity + 8.0 * 42.0) / (quantity + 8.0),
  unit_net_cost = 42.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SALAMI_PICANTE';
UPDATE wh_stock SET
  quantity = quantity + 5.0,
  current_avco_price = (current_avco_price * quantity + 5.0 * 38.0) / (quantity + 5.0),
  unit_net_cost = 38.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='KIELB_WLOSKA';
UPDATE wh_stock SET
  quantity = quantity + 4.0,
  current_avco_price = (current_avco_price * quantity + 4.0 * 55.0) / (quantity + 4.0),
  unit_net_cost = 55.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='NDUJA';

INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-002', 'PZ', @wh, 'completed',
  'FRUKTUS — Warzywa i Owoce', 'PZ-2026/05/FORNO-002',
  'NIP: 7780012345', '2026-08-16 21:12:45');
SET @pz_id = LAST_INSERT_ID();

INSERT INTO wh_document_lines (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, system_qty, counted_qty)
VALUES
(@pz_id, 'RUKOLA', 8.0, 14.0, 112.0, 5.0, 0, 0),
(@pz_id, 'POMIDORKI_KOKAT', 10.0, 8.5, 85.0, 5.0, 0, 0),
(@pz_id, 'PAPRYKA', 6.0, 6.0, 36.0, 5.0, 0, 0),
(@pz_id, 'PIECZARKI', 12.0, 7.5, 90.0, 5.0, 0, 0),
(@pz_id, 'SZPINAK', 5.0, 9.0, 45.0, 5.0, 0, 0),
(@pz_id, 'CEBULA_CZERW', 8.0, 3.2, 25.6, 5.0, 0, 0),
(@pz_id, 'BAZYLIA', 2.0, 25.0, 50.0, 5.0, 0, 0),
(@pz_id, 'CHILLI', 1.0, 18.0, 18.0, 5.0, 0, 0),
(@pz_id, 'SALATA', 4.0, 8.0, 32.0, 5.0, 0, 0);

UPDATE wh_stock SET
  quantity = quantity + 8.0,
  current_avco_price = (current_avco_price * quantity + 8.0 * 14.0) / (quantity + 8.0),
  unit_net_cost = 14.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='RUKOLA';
UPDATE wh_stock SET
  quantity = quantity + 10.0,
  current_avco_price = (current_avco_price * quantity + 10.0 * 8.5) / (quantity + 10.0),
  unit_net_cost = 8.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='POMIDORKI_KOKAT';
UPDATE wh_stock SET
  quantity = quantity + 6.0,
  current_avco_price = (current_avco_price * quantity + 6.0 * 6.0) / (quantity + 6.0),
  unit_net_cost = 6.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='PAPRYKA';
UPDATE wh_stock SET
  quantity = quantity + 12.0,
  current_avco_price = (current_avco_price * quantity + 12.0 * 7.5) / (quantity + 12.0),
  unit_net_cost = 7.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='PIECZARKI';
UPDATE wh_stock SET
  quantity = quantity + 5.0,
  current_avco_price = (current_avco_price * quantity + 5.0 * 9.0) / (quantity + 5.0),
  unit_net_cost = 9.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SZPINAK';
UPDATE wh_stock SET
  quantity = quantity + 8.0,
  current_avco_price = (current_avco_price * quantity + 8.0 * 3.2) / (quantity + 8.0),
  unit_net_cost = 3.2
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='CEBULA_CZERW';
UPDATE wh_stock SET
  quantity = quantity + 2.0,
  current_avco_price = (current_avco_price * quantity + 2.0 * 25.0) / (quantity + 2.0),
  unit_net_cost = 25.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='BAZYLIA';
UPDATE wh_stock SET
  quantity = quantity + 1.0,
  current_avco_price = (current_avco_price * quantity + 1.0 * 18.0) / (quantity + 1.0),
  unit_net_cost = 18.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='CHILLI';
UPDATE wh_stock SET
  quantity = quantity + 4.0,
  current_avco_price = (current_avco_price * quantity + 4.0 * 8.0) / (quantity + 4.0),
  unit_net_cost = 8.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SALATA';

INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-003', 'PZ', @wh, 'completed',
  'DI MARCO — Włoskie Specjały', 'PZ-2026/05/FORNO-003',
  'NIP: 1230012345', '2026-08-20 21:12:45');
SET @pz_id = LAST_INSERT_ID();

INSERT INTO wh_document_lines (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, system_qty, counted_qty)
VALUES
(@pz_id, 'MOZZ_BUFFALO', 6.0, 52.0, 312.0, 8.0, 0, 0),
(@pz_id, 'PARMEZAN', 4.0, 65.0, 260.0, 8.0, 0, 0),
(@pz_id, 'RICOTTA', 5.0, 18.0, 90.0, 8.0, 0, 0),
(@pz_id, 'SZYNKA_PARM', 3.0, 89.0, 267.0, 8.0, 0, 0),
(@pz_id, 'KREM_BALSAMICZNY', 2.0, 28.0, 56.0, 23.0, 0, 0),
(@pz_id, 'ANCHOIS', 1.0, 45.0, 45.0, 8.0, 0, 0),
(@pz_id, 'TUNCZYK', 4.0, 22.0, 88.0, 8.0, 0, 0),
(@pz_id, 'POMIDORKI_SUSZE', 3.0, 35.0, 105.0, 5.0, 0, 0);

UPDATE wh_stock SET
  quantity = quantity + 6.0,
  current_avco_price = (current_avco_price * quantity + 6.0 * 52.0) / (quantity + 6.0),
  unit_net_cost = 52.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='MOZZ_BUFFALO';
UPDATE wh_stock SET
  quantity = quantity + 4.0,
  current_avco_price = (current_avco_price * quantity + 4.0 * 65.0) / (quantity + 4.0),
  unit_net_cost = 65.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='PARMEZAN';
UPDATE wh_stock SET
  quantity = quantity + 5.0,
  current_avco_price = (current_avco_price * quantity + 5.0 * 18.0) / (quantity + 5.0),
  unit_net_cost = 18.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='RICOTTA';
UPDATE wh_stock SET
  quantity = quantity + 3.0,
  current_avco_price = (current_avco_price * quantity + 3.0 * 89.0) / (quantity + 3.0),
  unit_net_cost = 89.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SZYNKA_PARM';
UPDATE wh_stock SET
  quantity = quantity + 2.0,
  current_avco_price = (current_avco_price * quantity + 2.0 * 28.0) / (quantity + 2.0),
  unit_net_cost = 28.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='KREM_BALSAMICZNY';
UPDATE wh_stock SET
  quantity = quantity + 1.0,
  current_avco_price = (current_avco_price * quantity + 1.0 * 45.0) / (quantity + 1.0),
  unit_net_cost = 45.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='ANCHOIS';
UPDATE wh_stock SET
  quantity = quantity + 4.0,
  current_avco_price = (current_avco_price * quantity + 4.0 * 22.0) / (quantity + 4.0),
  unit_net_cost = 22.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='TUNCZYK';
UPDATE wh_stock SET
  quantity = quantity + 3.0,
  current_avco_price = (current_avco_price * quantity + 3.0 * 35.0) / (quantity + 3.0),
  unit_net_cost = 35.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='POMIDORKI_SUSZE';

INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-004', 'PZ', @wh, 'completed',
  'MŁYNY POLSKIE S.A.', 'PZ-2026/05/FORNO-004',
  'NIP: 8870012345', '2026-08-13 21:12:45');
SET @pz_id = LAST_INSERT_ID();

INSERT INTO wh_document_lines (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, system_qty, counted_qty)
VALUES
(@pz_id, 'MAKA_TYP_00', 80.0, 3.5, 280.0, 8.0, 0, 0),
(@pz_id, 'MAKA_SEZAMOWA', 10.0, 8.0, 80.0, 8.0, 0, 0),
(@pz_id, 'BUŁKA_PANINI', 200.0, 0.85, 170.0, 8.0, 0, 0);

UPDATE wh_stock SET
  quantity = quantity + 80.0,
  current_avco_price = (current_avco_price * quantity + 80.0 * 3.5) / (quantity + 80.0),
  unit_net_cost = 3.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='MAKA_TYP_00';
UPDATE wh_stock SET
  quantity = quantity + 10.0,
  current_avco_price = (current_avco_price * quantity + 10.0 * 8.0) / (quantity + 10.0),
  unit_net_cost = 8.0
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='MAKA_SEZAMOWA';
UPDATE wh_stock SET
  quantity = quantity + 200.0,
  current_avco_price = (current_avco_price * quantity + 200.0 * 0.85) / (quantity + 200.0),
  unit_net_cost = 0.85
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='BUŁKA_PANINI';

INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-005', 'PZ', @wh, 'completed',
  'BROWAR REGIONALNY TYSKIE', 'PZ-2026/05/FORNO-005',
  'NIP: 6450012345', '2026-08-22 21:12:45');
SET @pz_id = LAST_INSERT_ID();

INSERT INTO wh_document_lines (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, system_qty, counted_qty)
VALUES
(@pz_id, 'PIWO_BUTELKA', 144.0, 3.5, 504.0, 23.0, 0, 0),
(@pz_id, 'COCA_COLA', 96.0, 2.2, 211.2, 23.0, 0, 0),
(@pz_id, 'SPRITE', 48.0, 2.2, 105.6, 23.0, 0, 0),
(@pz_id, 'WODA_NIEGAZ', 48.0, 1.1, 52.8, 23.0, 0, 0),
(@pz_id, 'WODA_GAZ', 48.0, 1.1, 52.8, 23.0, 0, 0);

UPDATE wh_stock SET
  quantity = quantity + 144.0,
  current_avco_price = (current_avco_price * quantity + 144.0 * 3.5) / (quantity + 144.0),
  unit_net_cost = 3.5
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='PIWO_BUTELKA';
UPDATE wh_stock SET
  quantity = quantity + 96.0,
  current_avco_price = (current_avco_price * quantity + 96.0 * 2.2) / (quantity + 96.0),
  unit_net_cost = 2.2
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='COCA_COLA';
UPDATE wh_stock SET
  quantity = quantity + 48.0,
  current_avco_price = (current_avco_price * quantity + 48.0 * 2.2) / (quantity + 48.0),
  unit_net_cost = 2.2
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='SPRITE';
UPDATE wh_stock SET
  quantity = quantity + 48.0,
  current_avco_price = (current_avco_price * quantity + 48.0 * 1.1) / (quantity + 48.0),
  unit_net_cost = 1.1
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='WODA_NIEGAZ';
UPDATE wh_stock SET
  quantity = quantity + 48.0,
  current_avco_price = (current_avco_price * quantity + 48.0 * 1.1) / (quantity + 48.0),
  unit_net_cost = 1.1
WHERE tenant_id=@tid AND warehouse_id=@wh AND sku='WODA_GAZ';

-- ── 5 sh_ksef_invoices + sh_ksef_invoice_lines (3 faktury) ─────────────
INSERT INTO sh_ksef_invoices (tenant_id, supplier_nip, supplier_name, supplier_address,
  buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
  total_net_minor, total_vat_minor, total_gross_minor, status, linked_wh_document_id,
  fetched_at, processed_at)
VALUES (@tid, '5252311234', 'HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o.', 'ul. Warmińska 14, 10-100 Olsztyn',
  NULL, 'Pizzeria Forno', 'FA/FORNO/2026/001',
  '2026-08-21', '2026-08-20', '2026-09-20',
  91500, 7602, 99102,
  'new', NULL,
  '2026-08-14 21:12:45', NULL);
SET @ksef_id = LAST_INSERT_ID();

INSERT INTO sh_ksef_invoice_lines (ksef_invoice_id, line_no, external_name, external_description,
  unit, qty, unit_net, line_net_minor, vat_rate, resolved_sku, match_type, match_confidence)
VALUES
(@ksef_id, 1, 'Mąka pszenna typ 00 (25 kg worek)', NULL, 'kg', 50.0, 3.5, 17500, 8.0,
   NULL, NULL, NULL),
(@ksef_id, 2, 'Passata pomidorowa do pizzy', NULL, 'kg', 30.0, 4.2, 12600, 5.0,
   NULL, NULL, NULL),
(@ksef_id, 3, 'Mozzarella fior di latte 1 kg', NULL, 'kg', 20.0, 28.5, 57000, 8.0,
   NULL, NULL, NULL),
(@ksef_id, 4, 'Oregano suszone premium', NULL, 'kg', 2.0, 22.0, 4400, 23.0,
   NULL, NULL, NULL);

INSERT INTO sh_ksef_invoices (tenant_id, supplier_nip, supplier_name, supplier_address,
  buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
  total_net_minor, total_vat_minor, total_gross_minor, status, linked_wh_document_id,
  fetched_at, processed_at)
VALUES (@tid, '7780012345', 'FRUKTUS — Warzywa i Owoce', 'ul. Tęczowa 3, 54-125 Wrocław',
  NULL, 'Pizzeria Forno', 'FA/FORNO/2026/002',
  '2026-08-15', '2026-08-15', '2026-09-14',
  36800, 1840, 38640,
  'accepted', (SELECT id FROM wh_documents WHERE tenant_id=@tid AND doc_number='PZ-2026/05/FORNO-002'),
  '2026-08-08 21:12:45', '2026-08-15');
SET @ksef_id = LAST_INSERT_ID();

INSERT INTO sh_ksef_invoice_lines (ksef_invoice_id, line_no, external_name, external_description,
  unit, qty, unit_net, line_net_minor, vat_rate, resolved_sku, match_type, match_confidence)
VALUES
(@ksef_id, 1, 'Rukola świeża 500g', NULL, 'kg', 8.0, 14.0, 11200, 5.0,
   'RUKOLA', 'EXACT', 99),
(@ksef_id, 2, 'Pomidorki koktajlowe 1 kg', NULL, 'kg', 10.0, 8.5, 8500, 5.0,
   'POMIDORKI_KOKAT', 'EXACT', 99),
(@ksef_id, 3, 'Papryka czerwona', NULL, 'kg', 6.0, 6.0, 3600, 5.0,
   'PAPRYKA', 'ALIAS', 95),
(@ksef_id, 4, 'Pieczarki świeże 1 kg', NULL, 'kg', 12.0, 7.5, 9000, 5.0,
   'PIECZARKI', 'EXACT', 99),
(@ksef_id, 5, 'Szpinak baby liście', NULL, 'kg', 5.0, 9.0, 4500, 5.0,
   'SZPINAK', 'EXACT', 99);

INSERT INTO sh_ksef_invoices (tenant_id, supplier_nip, supplier_name, supplier_address,
  buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
  total_net_minor, total_vat_minor, total_gross_minor, status, linked_wh_document_id,
  fetched_at, processed_at)
VALUES (@tid, '1230012345', 'DI MARCO — Włoskie Specjały', 'ul. Włoska 8, 00-001 Warszawa',
  NULL, 'Pizzeria Forno', 'FA/FORNO/2026/003',
  '2026-08-19', '2026-08-19', '2026-09-18',
  100400, 8032, 108432,
  'processing', NULL,
  '2026-08-12 21:12:45', NULL);
SET @ksef_id = LAST_INSERT_ID();

INSERT INTO sh_ksef_invoice_lines (ksef_invoice_id, line_no, external_name, external_description,
  unit, qty, unit_net, line_net_minor, vat_rate, resolved_sku, match_type, match_confidence)
VALUES
(@ksef_id, 1, 'Mozzarella di bufala 250g', NULL, 'kg', 6.0, 52.0, 31200, 8.0,
   'MOZZ_BUFFALO', 'EXACT', 99),
(@ksef_id, 2, 'Parmigiano Reggiano DOP 1 kg', NULL, 'kg', 4.0, 65.0, 26000, 8.0,
   'PARMEZAN', 'ALIAS', 88),
(@ksef_id, 3, 'Salsiccia Piccante 500g', NULL, 'kg', 3.0, 55.0, 16500, 8.0,
   NULL, 'FUZZY', 62),
(@ksef_id, 4, 'Prosciutto di Parma 1 kg', NULL, 'kg', 3.0, 89.0, 26700, 8.0,
   NULL, NULL, NULL);

-- ── 6 sh_orders + sh_order_lines (8 zamówień) ──────────────────────────
INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('7c111ac5-ffb3-4371-a631-b531d6f551ac', @tid, 'FORNO-001',
  'delivery', 'delivery', 'seed',
  9500, 800, 10300,
  'accepted', 'card', 'card',
  'unassigned', 'Jan Kowalski', '+48 512 345 678', 'ul. Zielona 15, 10-900 Olsztyn', 53.7784, 20.4801,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 90 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 90 MINUTE), @uid_driver);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('45fab2c1-4e32-42be-a4a7-ffbb8288b964', '7c111ac5-ffb3-4371-a631-b531d6f551ac', 'MARGHERITA_30CM', 'Margherita 30cm',
  2700, 1, 2700, 8.0, 200, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('3fc46b18-a2b7-4da6-8864-272a85a93dc6', '7c111ac5-ffb3-4371-a631-b531d6f551ac', 'DI_PARMA_37CM', 'Di Parma 37cm',
  5400, 1, 5400, 8.0, 400, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('b13753d8-2c5a-48e2-a2a5-9ccba5d4884e', '7c111ac5-ffb3-4371-a631-b531d6f551ac', 'COCA_COLA', 'Coca-Cola 0.33l',
  700, 2, 1400, 23.0, 262, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('2d4a41b1-4c20-4638-9e79-fd0f51092661', @tid, 'FORNO-002',
  'delivery', 'delivery', 'seed',
  7500, 800, 8300,
  'preparing', 'online_paid', 'online',
  'unassigned', 'Anna Nowak', '+48 601 234 567', 'ul. Lipowa 7, 10-500 Olsztyn', 53.772, 20.4925,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 45 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 45 MINUTE), @uid_driver);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('e3cd1712-3a06-43da-850a-c7a173cb86c7', '2d4a41b1-4c20-4638-9e79-fd0f51092661', 'ETNA_30CM', 'Etna 30cm',
  3800, 1, 3800, 8.0, 281, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('48f4db26-b192-4e19-84c6-afe9e9e6a872', '2d4a41b1-4c20-4638-9e79-fd0f51092661', 'VINCI_30CM', 'Vinci 30cm',
  3700, 1, 3700, 8.0, 274, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('b6eeee51-262d-4f6f-b221-73c61f66ef4f', @tid, 'FORNO-003',
  'takeaway', 'takeaway', 'seed',
  4050, 0, 4050,
  'new', 'to_pay', 'cash',
  NULL, 'Marcin Wójcik', '+48 789 123 456', NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 15 MINUTE), NULL);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('648388a3-c77b-4873-9c10-61e1edd0f1ed', 'b6eeee51-262d-4f6f-b221-73c61f66ef4f', 'MARGHERITA_37CM', 'Margherita 37cm',
  4050, 1, 4050, 8.0, 300, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', @tid, 'FORNO-004',
  'pos', 'dine_in', 'seed',
  17750, 0, 17750,
  'preparing', 'to_pay', NULL,
  NULL, 'Stolik 4', NULL, NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 30 MINUTE), @uid_waiter);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('992159a0-c523-4c9d-96df-2c26643db92f', '3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', 'MONTANARA_37CM', 'Montanara 37cm',
  5550, 1, 5550, 8.0, 411, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('ee789e8f-3dd6-4bfc-b6e8-9d24104758d0', '3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', 'STEAKY_37CM', 'Steaky 37cm',
  6000, 1, 6000, 8.0, 444, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('3779d397-083e-4eef-bd9e-ccbf56f48140', '3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', 'CAPRICCIOSA_30CM', 'Capricciosa 30cm',
  3600, 1, 3600, 8.0, 267, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('968ac6db-1da9-4018-9215-7d6c388336d3', '3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', 'PIWO_BUTELKA', 'Piwo 0.5l',
  1300, 2, 2600, 23.0, 486, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('c2b15d88-8462-4807-bf92-260d0b34ba0f', @tid, 'FORNO-005',
  'delivery', 'delivery', 'seed',
  10300, 800, 11100,
  'completed', 'card', 'card',
  'delivered', 'Kasia Zalewska', '+48 698 765 432', 'ul. Mickiewicza 33, 10-230 Olsztyn', 53.7801, 20.4756,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 168 HOUR), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 168 HOUR), @uid_driver);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('b9b9d774-7612-44c8-97c7-6bda1ba2fc99', 'c2b15d88-8462-4807-bf92-260d0b34ba0f', 'QUATTRO_FORMAGGI_37CM', 'Quattro Formaggi 37cm',
  5700, 1, 5700, 8.0, 422, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('6324cb15-e68d-4526-9205-baee15531e39', 'c2b15d88-8462-4807-bf92-260d0b34ba0f', 'DI_PARMA_30CM', 'Di Parma 30cm',
  3600, 1, 3600, 8.0, 267, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('9fe9eae2-792b-43a1-9728-913d9d3fe024', 'c2b15d88-8462-4807-bf92-260d0b34ba0f', 'WODA_NIEGAZ', 'Woda niegazowana',
  500, 2, 1000, 23.0, 187, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('5a8871cd-d915-4d60-8961-c85a49faa1bc', @tid, 'FORNO-006',
  'delivery', 'delivery', 'seed',
  8600, 800, 9400,
  'ready', 'online_paid', 'online',
  'in_delivery', 'Piotr Nowicki', '+48 504 321 987', 'ul. Słoneczna 21, 10-710 Olsztyn', 53.765, 20.51,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 75 MINUTE), INTERVAL 35 MINUTE), LOWER(SUBSTRING(REPLACE('5a8871cd-d915-4d60-8961-c85a49faa1bc','-',''), 1, 16)), DATE_SUB(NOW(), INTERVAL 75 MINUTE), @uid_driver);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('3de18043-a4a6-4566-9afc-f693e8753259', '5a8871cd-d915-4d60-8961-c85a49faa1bc', 'DIAVOLA_30CM', 'Diavola 30cm',
  3500, 1, 3500, 8.0, 259, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('ff82bd69-311b-492b-aacb-f2396f2a65dc', '5a8871cd-d915-4d60-8961-c85a49faa1bc', 'AMERICANA_37CM', 'Americano 37cm',
  5100, 1, 5100, 8.0, 378, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('5a5ec70b-fcf8-4b36-8df9-75646a7c0010', @tid, 'FORNO-007',
  'online', 'delivery', 'seed',
  4000, 800, 4800,
  'new', 'online_paid', 'online',
  'unassigned', 'Tomek Bąk', '+48 666 555 444', 'ul. Kościuszki 5, 10-100 Olsztyn', 53.7754, 20.4818,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 6 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 6 MINUTE), @uid_driver);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('ac949cc3-d79a-4056-b2f8-8d2507a568da', '5a5ec70b-fcf8-4b36-8df9-75646a7c0010', 'MARGHERITA_ITALIANO_30CM', 'Margherita Italiano 30cm',
  3300, 1, 3300, 8.0, 244, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('aa70e25b-0e23-4684-97b8-b4b3da900e55', '5a5ec70b-fcf8-4b36-8df9-75646a7c0010', 'SPRITE', 'Sprite 0.33l',
  700, 1, 700, 23.0, 131, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at, user_id)
VALUES ('b176f659-3b2b-45b3-a8cd-93a21c1618b3', @tid, 'FORNO-008',
  'pos', 'dine_in', 'seed',
  24450, 0, 24450,
  'completed', 'card', 'card',
  NULL, 'Stolik 8', NULL, NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 48 HOUR), @uid_waiter);

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('927a81c5-ca2f-4790-a519-57fe46034903', 'b176f659-3b2b-45b3-a8cd-93a21c1618b3', 'CARBONARA_30CM', 'Carbonara 30cm',
  3600, 2, 7200, 8.0, 533, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('68d2bb78-ea5e-47f2-9912-4b8f165cac20', 'b176f659-3b2b-45b3-a8cd-93a21c1618b3', 'VERDURA_37CM', 'Verdura 37cm',
  5250, 1, 5250, 8.0, 389, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('80e0d83e-1506-4244-a2f6-682a148dfabf', 'b176f659-3b2b-45b3-a8cd-93a21c1618b3', 'CAPRICCIOSA_37CM', 'Capricciosa 37cm',
  5400, 1, 5400, 8.0, 400, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('03d4d03b-9797-42d2-9953-2873dbadc984', 'b176f659-3b2b-45b3-a8cd-93a21c1618b3', 'PIWO_BUTELKA', 'Piwo 0.5l',
  1300, 4, 5200, 23.0, 972, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('23dd46c5-8fd3-4083-9a86-b0d9b9fa0792', 'b176f659-3b2b-45b3-a8cd-93a21c1618b3', 'COCA_COLA', 'Coca-Cola 0.33l',
  700, 2, 1400, 23.0, 262, NULL);

-- ── 7 sh_order_audit (8 wierszy) ───────────────────────────────────────
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('7c111ac5-ffb3-4371-a631-b531d6f551ac', @uid_driver, 'new', 'accepted', DATE_SUB(NOW(), INTERVAL 90 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('2d4a41b1-4c20-4638-9e79-fd0f51092661', @uid_driver, 'new', 'preparing', DATE_SUB(NOW(), INTERVAL 45 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('b6eeee51-262d-4f6f-b221-73c61f66ef4f', NULL, 'new', 'new', DATE_SUB(NOW(), INTERVAL 15 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('3b13a9f5-7bc9-4c2b-be7e-24e56f221ee1', @uid_waiter, 'new', 'preparing', DATE_SUB(NOW(), INTERVAL 30 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('c2b15d88-8462-4807-bf92-260d0b34ba0f', @uid_driver, 'new', 'completed', DATE_SUB(NOW(), INTERVAL 168 HOUR));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('5a8871cd-d915-4d60-8961-c85a49faa1bc', @uid_driver, 'new', 'ready', DATE_SUB(NOW(), INTERVAL 75 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('5a5ec70b-fcf8-4b36-8df9-75646a7c0010', @uid_driver, 'new', 'new', DATE_SUB(NOW(), INTERVAL 6 MINUTE));
INSERT INTO sh_order_audit (order_id, user_id, old_status, new_status, timestamp)
VALUES ('b176f659-3b2b-45b3-a8cd-93a21c1618b3', @uid_waiter, 'new', 'completed', DATE_SUB(NOW(), INTERVAL 48 HOUR));

COMMIT;

-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 8: WALIDACJA (quick check)
-- ═══════════════════════════════════════════════════════════════════════
SELECT 'tenant_settings' AS entity, COUNT(*) AS cnt FROM sh_tenant_settings WHERE tenant_id=@tid
UNION ALL
SELECT 'users', COUNT(*) FROM sh_users WHERE tenant_id=@tid AND username LIKE 'forno_%'
UNION ALL
SELECT 'wh_stock', COUNT(*) FROM wh_stock WHERE tenant_id=@tid
UNION ALL
SELECT 'pz_docs', COUNT(*) FROM wh_documents WHERE tenant_id=@tid AND doc_number LIKE 'PZ-2026/%/FORNO%'
UNION ALL
SELECT 'ksef_invoices', COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=@tid AND invoice_number LIKE 'FA/FORNO/%'
UNION ALL
SELECT 'orders', COUNT(*) FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%';

-- ✅ Seed Pizza Forno OPS załadowany pomyślnie (dane operacyjne)!
