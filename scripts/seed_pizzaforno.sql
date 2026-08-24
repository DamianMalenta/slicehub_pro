-- =============================================================================
-- seed_pizzaforno.sql — SliceHub Pro
-- Wygenerowane: 2026-08-24 04:02:03
-- Źródło: _docs/menu_pizzaforno/menu (14).xlsx + additions.xlsx
-- Tryb: FULL (menu + wh_stock + PZ + KSeF + zamówienia)
-- Rodzin pizzy: 32 | Panini: 11 | Pojedyncze: 101
-- Składniki sys_items: 67 SKU
-- Modifier groups: 19
-- =============================================================================
-- IDEMPOTENTNY — można uruchamiać wielokrotnie (cleanup na początku).
-- Zmień @tid przed uruchomieniem jeśli inny tenant.
-- Wymaga: istniejącego tenant w sh_tenant (np. utworzonego przez install_panel.php)
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @tid := 2;
SET @wh  := 'MAIN';


-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 1: CLEANUP (idempotency)
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

DELETE FROM sh_modifier_pricing WHERE tenant_id=@tid AND modifier_id IN
  (SELECT id FROM sh_modifiers WHERE group_id IN
   (SELECT id FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key IN ('SOS_BAZOWY', 'DODA_WARZYWA', 'DODA_MIESA', 'DODA_SERY', 'POZOSTALE_PIZZA', 'SOSY_DODATKOWE', 'HALF_HALF', 'DODA_OGOLNE', 'SOSY_PANINI', 'GAZ_NIEGAZ', 'RODZAJ_SOKU', 'GALKI_LODOW', 'USUN_SKLADNIK', 'RODZAJ_CIASTA', 'SER_FOCACCI', 'DODA_PIWA', 'DODA_GYROS', 'PROMOCJA', 'RODZAJ_CALZONE')));

DELETE FROM sh_item_modifiers WHERE group_id IN
  (SELECT id FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key IN ('SOS_BAZOWY', 'DODA_WARZYWA', 'DODA_MIESA', 'DODA_SERY', 'POZOSTALE_PIZZA', 'SOSY_DODATKOWE', 'HALF_HALF', 'DODA_OGOLNE', 'SOSY_PANINI', 'GAZ_NIEGAZ', 'RODZAJ_SOKU', 'GALKI_LODOW', 'USUN_SKLADNIK', 'RODZAJ_CIASTA', 'SER_FOCACCI', 'DODA_PIWA', 'DODA_GYROS', 'PROMOCJA', 'RODZAJ_CALZONE'));

DELETE FROM sh_modifiers WHERE group_id IN
  (SELECT id FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key IN ('SOS_BAZOWY', 'DODA_WARZYWA', 'DODA_MIESA', 'DODA_SERY', 'POZOSTALE_PIZZA', 'SOSY_DODATKOWE', 'HALF_HALF', 'DODA_OGOLNE', 'SOSY_PANINI', 'GAZ_NIEGAZ', 'RODZAJ_SOKU', 'GALKI_LODOW', 'USUN_SKLADNIK', 'RODZAJ_CIASTA', 'SER_FOCACCI', 'DODA_PIWA', 'DODA_GYROS', 'PROMOCJA', 'RODZAJ_CALZONE'));

DELETE FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key IN ('SOS_BAZOWY', 'DODA_WARZYWA', 'DODA_MIESA', 'DODA_SERY', 'POZOSTALE_PIZZA', 'SOSY_DODATKOWE', 'HALF_HALF', 'DODA_OGOLNE', 'SOSY_PANINI', 'GAZ_NIEGAZ', 'RODZAJ_SOKU', 'GALKI_LODOW', 'USUN_SKLADNIK', 'RODZAJ_CIASTA', 'SER_FOCACCI', 'DODA_PIWA', 'DODA_GYROS', 'PROMOCJA', 'RODZAJ_CALZONE');

DELETE FROM sh_recipes WHERE tenant_id=@tid AND menu_item_sku IN
  (SELECT ascii_key FROM sh_menu_items WHERE tenant_id=@tid AND
   (ascii_key LIKE '%_30CM' OR ascii_key LIKE '%_37CM' OR ascii_key LIKE '%_MALE'
   OR ascii_key LIKE '%_DUZE' OR category_id IN
   (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name IN
   ('PIZZE','PANINI','CALZONE','MAKARONY','FOCCACIA','ZAPIEKANKI','GYROSY',
   'SAŁATKI','SOSY','DESERY','DLA DZIECI','NAPOJE','PIWA','POZOSTAŁE','NOWOŚCI','ZIMOWE MENU'))));

DELETE FROM sh_price_tiers WHERE tenant_id=@tid AND target_type='ITEM' AND target_sku IN
  (SELECT ascii_key FROM sh_menu_items WHERE tenant_id=@tid AND category_id IN
   (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name IN
   ('PIZZE','PANINI','CALZONE','MAKARONY','FOCCACIA','ZAPIEKANKI','GYROSY',
   'SAŁATKI','SOSY','DESERY','DLA DZIECI','NAPOJE','PIWA','POZOSTAŁE','NOWOŚCI','ZIMOWE MENU')));

DELETE FROM sh_menu_items WHERE tenant_id=@tid AND category_id IN
  (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name IN
   ('PIZZE','PANINI','CALZONE','MAKARONY','FOCCACIA','ZAPIEKANKI','GYROSY',
   'SAŁATKI','SOSY','DESERY','DLA DZIECI','NAPOJE','PIWA','POZOSTAŁE','NOWOŚCI','ZIMOWE MENU'));
-- Also delete variant parents (is_variant_parent=1)
DELETE FROM sh_menu_items WHERE tenant_id=@tid AND is_variant_parent=1
  AND ascii_key NOT LIKE 'TEST%' AND ascii_key NOT LIKE 'DEMO%';

DELETE FROM sh_categories WHERE tenant_id=@tid AND name IN
  ('PIZZE','PANINI','CALZONE','MAKARONY','FOCCACIA','ZAPIEKANKI','GYROSY',
  'SAŁATKI','SOSY','DESERY','DLA DZIECI','NAPOJE','PIWA','POZOSTAŁE','NOWOŚCI','ZIMOWE MENU');

DELETE FROM wh_stock WHERE tenant_id=@tid AND sku IN ('MAKA_TYP_00', 'MAKA_SEZAMOWA', 'SOS_POMIDOROWY', 'SOS_SMIETANKOWY', 'SOS_CZOSNKOWY', 'SOS_BBQ', 'SOS_MEKSYKANSKI', 'SOS_1000_WYSP', 'TABASCO', 'MAJONEZ', 'KETCHUP', 'KREM_BALSAMICZNY', 'MOZZ_FIOR', 'MOZZ_BUFFALO', 'RICOTTA', 'PARMEZAN', 'GORGONZOLA', 'FETA', 'EDAMSKI', 'SALAMI_PICANTE', 'SALAMI', 'NDUJA', 'KIELB_WLOSKA', 'SZYNKA_PARM', 'SZYNKA', 'KURCZAK', 'STEK_WOLOWY', 'BOCZEK', 'RWANA_WIEPRZ', 'KEBAB_DROBIOWY', 'MIESO_WOLOWE', 'ANCHOIS', 'TUNCZYK', 'GYROS_MIESO', 'PIECZARKI', 'PIECZARKI_SMAZONE', 'CEBULA_CZERW', 'PAPRYKA', 'OLIWKI', 'POMIDORKI_KOKAT', 'POMIDORKI_SUSZE', 'KUKURYDZA', 'RUKOLA', 'SZPINAK', 'CHILLI', 'JALAPENO', 'KAP_PEKINSKA', 'OGUREK_KONS', 'SALATA', 'CUKINIA', 'BRUKOLY', 'BAZYLIA', 'GRUSZKA', 'ANANAS', 'FRYTKI', 'CHIPSY_ZIEMN', 'OREGANO', 'OLIWA', 'MAKARON', 'CIASTO_GYROS', 'BUŁKA_PANINI', 'COCA_COLA', 'SPRITE', 'WODA_NIEGAZ', 'WODA_GAZ', 'PIWO_BUTELKA', 'LODY_GALKA');

DELETE FROM sh_payroll_ledger WHERE tenant_id=@tid AND employee_id IN
  (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%');
DELETE FROM sh_work_sessions WHERE tenant_id=@tid AND employee_id IN
  (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%');
DELETE FROM sh_employee_rates WHERE tenant_id=@tid AND employee_id IN
  (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%');
DELETE FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%';

DELETE FROM sh_driver_shifts WHERE driver_id IN
  (SELECT user_id FROM sh_drivers WHERE tenant_id=@tid);
DELETE FROM sh_drivers WHERE tenant_id=@tid;

DELETE FROM sh_users WHERE tenant_id=@tid AND username IN
  ('forno_owner','forno_manager','forno_waiter','forno_cook','forno_driver');

DELETE FROM sh_tenant_settings WHERE tenant_id=@tid AND setting_key IN
  ('', 'currency', 'default_vat_dine_in', 'default_vat_takeaway', 'half_half_surcharge');

DELETE FROM sys_items WHERE tenant_id=@tid AND sku IN ('MAKA_TYP_00', 'MAKA_SEZAMOWA', 'SOS_POMIDOROWY', 'SOS_SMIETANKOWY', 'SOS_CZOSNKOWY', 'SOS_BBQ', 'SOS_MEKSYKANSKI', 'SOS_1000_WYSP', 'TABASCO', 'MAJONEZ', 'KETCHUP', 'KREM_BALSAMICZNY', 'MOZZ_FIOR', 'MOZZ_BUFFALO', 'RICOTTA', 'PARMEZAN', 'GORGONZOLA', 'FETA', 'EDAMSKI', 'SALAMI_PICANTE', 'SALAMI', 'NDUJA', 'KIELB_WLOSKA', 'SZYNKA_PARM', 'SZYNKA', 'KURCZAK', 'STEK_WOLOWY', 'BOCZEK', 'RWANA_WIEPRZ', 'KEBAB_DROBIOWY', 'MIESO_WOLOWE', 'ANCHOIS', 'TUNCZYK', 'GYROS_MIESO', 'PIECZARKI', 'PIECZARKI_SMAZONE', 'CEBULA_CZERW', 'PAPRYKA', 'OLIWKI', 'POMIDORKI_KOKAT', 'POMIDORKI_SUSZE', 'KUKURYDZA', 'RUKOLA', 'SZPINAK', 'CHILLI', 'JALAPENO', 'KAP_PEKINSKA', 'OGUREK_KONS', 'SALATA', 'CUKINIA', 'BRUKOLY', 'BAZYLIA', 'GRUSZKA', 'ANANAS', 'FRYTKI', 'CHIPSY_ZIEMN', 'OREGANO', 'OLIWA', 'MAKARON', 'CIASTO_GYROS', 'BUŁKA_PANINI', 'COCA_COLA', 'SPRITE', 'WODA_NIEGAZ', 'WODA_GAZ', 'PIWO_BUTELKA', 'LODY_GALKA');

DELETE FROM sh_variant_scale_options WHERE tenant_id=@tid AND scale_id IN
  (SELECT id FROM sh_variant_scales WHERE tenant_id=@tid AND key_ascii IN ('SCALE_PIZZA','SCALE_PANINI'));
DELETE FROM sh_variant_scales WHERE tenant_id=@tid AND key_ascii IN ('SCALE_PIZZA','SCALE_PANINI');

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 2: INSERT DATA
-- ═══════════════════════════════════════════════════════════════════════
START TRANSACTION;

-- ── 2.1 sys_items (słownik surowców) ───────────────────────────────────
INSERT INTO sys_items (tenant_id, sku, name, base_unit, is_active, is_deleted) VALUES
(@tid, 'MAKA_TYP_00', 'Mąka pszenna typ 00', 'kg', 1, 0),
(@tid, 'MAKA_SEZAMOWA', 'Mąka sezamowa (ciasto)', 'kg', 1, 0),
(@tid, 'SOS_POMIDOROWY', 'Sos pomidorowy passata', 'kg', 1, 0),
(@tid, 'SOS_SMIETANKOWY', 'Sos śmietankowy', 'kg', 1, 0),
(@tid, 'SOS_CZOSNKOWY', 'Sos czosnkowy', 'kg', 1, 0),
(@tid, 'SOS_BBQ', 'Sos BBQ', 'kg', 1, 0),
(@tid, 'SOS_MEKSYKANSKI', 'Sos meksykański ostry', 'kg', 1, 0),
(@tid, 'SOS_1000_WYSP', 'Sos 1000 wysp', 'kg', 1, 0),
(@tid, 'TABASCO', 'Tabasco', 'l', 1, 0),
(@tid, 'MAJONEZ', 'Majonez', 'kg', 1, 0),
(@tid, 'KETCHUP', 'Ketchup', 'kg', 1, 0),
(@tid, 'KREM_BALSAMICZNY', 'Krem balsamiczny', 'l', 1, 0),
(@tid, 'MOZZ_FIOR', 'Mozzarella fior di latte', 'kg', 1, 0),
(@tid, 'MOZZ_BUFFALO', 'Mozzarella di buffalo', 'kg', 1, 0),
(@tid, 'RICOTTA', 'Ricotta', 'kg', 1, 0),
(@tid, 'PARMEZAN', 'Parmezan', 'kg', 1, 0),
(@tid, 'GORGONZOLA', 'Gorgonzola', 'kg', 1, 0),
(@tid, 'FETA', 'Feta', 'kg', 1, 0),
(@tid, 'EDAMSKI', 'Ser Edamski', 'kg', 1, 0),
(@tid, 'SALAMI_PICANTE', 'Salami picante', 'kg', 1, 0),
(@tid, 'SALAMI', 'Salami', 'kg', 1, 0),
(@tid, 'NDUJA', 'Nduja (włoska kiełbasa)', 'kg', 1, 0),
(@tid, 'KIELB_WLOSKA', 'Włoska kiełbasa', 'kg', 1, 0),
(@tid, 'SZYNKA_PARM', 'Szynka parmeńska', 'kg', 1, 0),
(@tid, 'SZYNKA', 'Szynka', 'kg', 1, 0),
(@tid, 'KURCZAK', 'Kurczak (pierś)', 'kg', 1, 0),
(@tid, 'STEK_WOLOWY', 'Stek wołowy', 'kg', 1, 0),
(@tid, 'BOCZEK', 'Boczek', 'kg', 1, 0),
(@tid, 'RWANA_WIEPRZ', 'Rwana wieprzowina', 'kg', 1, 0),
(@tid, 'KEBAB_DROBIOWY', 'Drobiowy kebab', 'kg', 1, 0),
(@tid, 'MIESO_WOLOWE', 'Mięso wołowe', 'kg', 1, 0),
(@tid, 'ANCHOIS', 'Fileciki anchois', 'kg', 1, 0),
(@tid, 'TUNCZYK', 'Tuńczyk (puszka)', 'kg', 1, 0),
(@tid, 'GYROS_MIESO', 'Gyros (mięso mieszane)', 'kg', 1, 0),
(@tid, 'PIECZARKI', 'Pieczarki', 'kg', 1, 0),
(@tid, 'PIECZARKI_SMAZONE', 'Smażone pieczarki', 'kg', 1, 0),
(@tid, 'CEBULA_CZERW', 'Czerwona cebulka', 'kg', 1, 0),
(@tid, 'PAPRYKA', 'Papryka', 'kg', 1, 0),
(@tid, 'OLIWKI', 'Oliwki', 'kg', 1, 0),
(@tid, 'POMIDORKI_KOKAT', 'Pomidorki koktajlowe', 'kg', 1, 0),
(@tid, 'POMIDORKI_SUSZE', 'Suszone pomidorki', 'kg', 1, 0),
(@tid, 'KUKURYDZA', 'Kukurydza', 'kg', 1, 0),
(@tid, 'RUKOLA', 'Rukola', 'kg', 1, 0),
(@tid, 'SZPINAK', 'Szpinak', 'kg', 1, 0),
(@tid, 'CHILLI', 'Chilli (świeże)', 'kg', 1, 0),
(@tid, 'JALAPENO', 'Jalapeño', 'kg', 1, 0),
(@tid, 'KAP_PEKINSKA', 'Kapusta pekińska', 'kg', 1, 0),
(@tid, 'OGUREK_KONS', 'Ogórek konserwowy', 'kg', 1, 0),
(@tid, 'SALATA', 'Sałata lodowa', 'kg', 1, 0),
(@tid, 'CUKINIA', 'Cukinia', 'kg', 1, 0),
(@tid, 'BRUKOLY', 'Brokuły', 'kg', 1, 0),
(@tid, 'BAZYLIA', 'Świeża bazylia', 'kg', 1, 0),
(@tid, 'GRUSZKA', 'Gruszka (do pizzy)', 'kg', 1, 0),
(@tid, 'ANANAS', 'Ananas', 'kg', 1, 0),
(@tid, 'FRYTKI', 'Frytki (zamrożone)', 'kg', 1, 0),
(@tid, 'CHIPSY_ZIEMN', 'Chipsy ziemniaczane', 'kg', 1, 0),
(@tid, 'OREGANO', 'Oregano suszone', 'kg', 1, 0),
(@tid, 'OLIWA', 'Oliwa z oliwek EVO', 'l', 1, 0),
(@tid, 'MAKARON', 'Makaron (spaghetti)', 'kg', 1, 0),
(@tid, 'CIASTO_GYROS', 'Ciasto do gyrosa (pita)', 'kg', 1, 0),
(@tid, 'BUŁKA_PANINI', 'Bułka panini', 'szt', 1, 0),
(@tid, 'COCA_COLA', 'Coca-Cola 0.33l', 'szt', 1, 0),
(@tid, 'SPRITE', 'Sprite 0.33l', 'szt', 1, 0),
(@tid, 'WODA_NIEGAZ', 'Woda mineralna niegazowana', 'szt', 1, 0),
(@tid, 'WODA_GAZ', 'Woda mineralna gazowana', 'szt', 1, 0),
(@tid, 'PIWO_BUTELKA', 'Piwo butelkowe 0.5l', 'szt', 1, 0),
(@tid, 'LODY_GALKA', 'Lody (gałka)', 'szt', 1, 0);

-- ── 2.2 sh_variant_scales + sh_variant_scale_options ───────────────────
INSERT INTO sh_variant_scales (tenant_id, name, key_ascii, description, is_active)
VALUES (@tid, 'Rozmiary pizzy', 'SCALE_PIZZA', 'Skala pizzy 30cm / 37cm', 1),
       (@tid, 'Rozmiary panini', 'SCALE_PANINI', 'Skala panini małe / duże', 1);

SET @scale_pizza_id  = (SELECT id FROM sh_variant_scales WHERE tenant_id=@tid AND key_ascii='SCALE_PIZZA');
SET @scale_panini_id = (SELECT id FROM sh_variant_scales WHERE tenant_id=@tid AND key_ascii='SCALE_PANINI');

INSERT INTO sh_variant_scale_options (scale_id, tenant_id, name, key_ascii, display_order, multiplier, diameter_cm, is_default)
VALUES (@scale_pizza_id,  @tid, '30 cm', '30CM', 0, 1.000, 30, 1),
       (@scale_pizza_id,  @tid, '37 cm', '37CM', 1, 1.500, 37, 0),
       (@scale_panini_id, @tid, 'MAŁE',  'MALE', 0, 1.000, NULL, 1),
       (@scale_panini_id, @tid, 'DUŻE',  'DUZE', 1, 1.500, NULL, 0);

SET @opt_30cm  = (SELECT id FROM sh_variant_scale_options WHERE scale_id=@scale_pizza_id  AND key_ascii='30CM');
SET @opt_37cm  = (SELECT id FROM sh_variant_scale_options WHERE scale_id=@scale_pizza_id  AND key_ascii='37CM');
SET @opt_male  = (SELECT id FROM sh_variant_scale_options WHERE scale_id=@scale_panini_id AND key_ascii='MALE');
SET @opt_duze  = (SELECT id FROM sh_variant_scale_options WHERE scale_id=@scale_panini_id AND key_ascii='DUZE');

-- ── 2.3 sh_categories ──────────────────────────────────────────────────
INSERT INTO sh_categories (tenant_id, name, is_menu, display_order, is_deleted) VALUES
(@tid, 'PIZZE', 1, 0, 0),
(@tid, 'PANINI', 1, 1, 0),
(@tid, 'CALZONE', 1, 2, 0),
(@tid, 'MAKARONY', 1, 3, 0),
(@tid, 'ZAPIEKANKI', 1, 4, 0),
(@tid, 'GYROSY', 1, 5, 0),
(@tid, 'FOCCACIA', 1, 6, 0),
(@tid, 'SAŁATKI', 1, 7, 0),
(@tid, 'SOSY', 1, 8, 0),
(@tid, 'DESERY', 1, 9, 0),
(@tid, 'DLA DZIECI', 1, 10, 0),
(@tid, 'NAPOJE', 1, 11, 0),
(@tid, 'PIWA', 1, 12, 0),
(@tid, 'POZOSTAŁE', 1, 13, 0),
(@tid, 'NOWOŚCI', 1, 14, 0),
(@tid, 'ZIMOWE MENU', 1, 15, 0);

SET @cat_pizze = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='PIZZE');
SET @cat_panini = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='PANINI');
SET @cat_calzone = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='CALZONE');
SET @cat_makarony = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='MAKARONY');
SET @cat_zapiekanki = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='ZAPIEKANKI');
SET @cat_gyrosy = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='GYROSY');
SET @cat_foccacia = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='FOCCACIA');
SET @cat_salatki = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='SAŁATKI');
SET @cat_sosy = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='SOSY');
SET @cat_desery = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='DESERY');
SET @cat_dladzieci = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='DLA DZIECI');
SET @cat_napoje = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='NAPOJE');
SET @cat_piwa = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='PIWA');
SET @cat_pozostale = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='POZOSTAŁE');
SET @cat_nowosci = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='NOWOŚCI');
SET @cat_zimowemenu = (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='ZIMOWE MENU');

-- ── 2.4 sh_menu_items — pizza parent items (is_variant_parent=1) ───────
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL GYROSO', 'AL_GYROSO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'drobiowy kebab / kapusta / pomidorki / kukurydza / ogórek konserwowy / sos czosnkowy', 0, 'Live');
SET @p_AL_GYROSO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL.TONNO', 'AL_TONNO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'tuńczyk / fileciki anchois / czerwona cebula / pomidorki', 1, 'Live');
SET @p_AL_TONNO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO', 'AMERICANO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos bbq / salami / kukurydza / kurczak / czerwona cebulka', 2, 'Live');
SET @p_AMERICANO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO PICANTE', 'AMERICANO_PICANTE', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos bbq / pieczarki / kurczak / boczek / jalapeno', 3, 'Live');
SET @p_AMERICANO_PICANTE = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'BOB BUDOWNICZY', 'BOB_BUDOWNICZY', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'ciasto / sos do wyboru / ser', 4, 'Live');
SET @p_BOB_BUDOWNICZY = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CAPRICCIOSA', 'CAPRICCIOSA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'buffalo mozzarella / pieczarki / jajko / oliwki / szynka / papryka', 5, 'Live');
SET @p_CAPRICCIOSA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CARBONARA', 'CARBONARA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / boczek / parmezan / pieprz / jajko', 6, 'Live');
SET @p_CARBONARA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CEZAR', 'CEZAR', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'biały sos / kurczak / sałata lodowa / pomidorki / parmezan', 7, 'Live');
SET @p_CEZAR = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CON BROCCOLI', 'CON_BROCCOLI', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / kurczak / brokuł / chipsy ziemniaczane / ricotta / suszone pomidorki', 8, 'Live');
SET @p_CON_BROCCOLI = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI CARNE', 'DI_CARNE', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'drobiowy kebab / salami / szynka / ragu wołowe', 9, 'Live');
SET @p_DI_CARNE = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI PARMA', 'DI_PARMA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'szynka parmeńska / rukola / pomidorki / parmezan', 10, 'Live');
SET @p_DI_PARMA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DIAVOLA', 'DIAVOLA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'salami / papryka / pieczarki / chilli', 11, 'Live');
SET @p_DIAVOLA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DUE SALAMI', 'DUE_SALAMI', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'salami / chipsy ziemniaczane / salami picante', 12, 'Live');
SET @p_DUE_SALAMI = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'ETNA', 'ETNA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'salami picante / pieczarki / chlli / Nduja / ricotta / tabasco', 13, 'Live');
SET @p_ETNA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FRITTE FORMAGGIO', 'FRITTE_FORMAGGIO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'mozzarella / feta / salami / ser edam / frytki', 14, 'Live');
SET @p_FRITTE_FORMAGGIO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FUNGHI CON SZYNKA', 'FUNGHI_CON_SZYNKA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'szynka / pieczarki / rukola', 15, 'Live');
SET @p_FUNGHI_CON_SZYNKA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MAFIA', 'MAFIA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos bbq / salami picante / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 16, 'Live');
SET @p_MAFIA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA', 'MARGHERITA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos / ser', 17, 'Live');
SET @p_MARGHERITA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO', 'MARGHERITA_ITALIANO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'buffalo mozzarella / świeża bazylia / oliwa / parmezan / oregano / rozmaryn', 18, 'Live');
SET @p_MARGHERITA_ITALIANO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO BIANCA', 'MARGHERITA_ITALIANO_BIANCA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'biały sos / buffalo mozzarella / bazylia / oregano / rozmaryn / parmezan', 19, 'Live');
SET @p_MARGHERITA_ITALIANO_BIANCA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MONTANARA', 'MONTANARA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'buffalo mozzarella / smażone pieczarki / włoska kiełbasa / Nduja / suszone pomidorki / parmezan', 20, 'Live');
SET @p_MONTANARA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'NEW YORK', 'NEW_YORK', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos bbq / kukurydza / rwana wieprzowina / pieczarki / papryka', 21, 'Live');
SET @p_NEW_YORK = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POLLO', 'POLLO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'kurczak / pieczarki / kukurydza / czerwona cebulka', 22, 'Live');
SET @p_POLLO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POPEY', 'POPEY', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'kurczak / brokuły / szpinak / suszone pomidorki / feta', 23, 'Live');
SET @p_POPEY = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'PÓL NA PÓŁ', 'POL_NA_POL', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'Wybierz 2 połówki', 24, 'Live');
SET @p_POL_NA_POL = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'QUATTRO FORMAGGI', 'QUATTRO_FORMAGGI', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos śmietanowy / gorgonzola / feta / edamski / ricotta', 25, 'Live');
SET @p_QUATTRO_FORMAGGI = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'RAGU', 'RAGU', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'mięso wołowe / pieczarki / czerwona cebulka / parmezan', 26, 'Live');
SET @p_RAGU = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'STEAKY', 'STEAKY', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'chipsy ziemniaczane / pieczarka / stek wołowy / rukola / krem balsamiczny / sezamowe ciasto / parmezan', 27, 'Live');
SET @p_STEAKY = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERDURA', 'VERDURA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'pieczarki / cukinia / pomidorki / papryka / czerwona cebulka', 28, 'Live');
SET @p_VERDURA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERONA', 'VERONA', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'kurczak / pomidorki / rukola / parmezan', 29, 'Live');
SET @p_VERONA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VINCI', 'VINCI', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / pieczarki / włoska kiełbasa / Nduja / czerwona cebulka', 30, 'Live');
SET @p_VINCI = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VULCANO', 'VULCANO', 'variant_parent', 1,
  1, @scale_pizza_id, NULL, NULL,
  8.0, 5.0, 'salami picante / kurczak / jalapeno / chilli', 31, 'Live');
SET @p_VULCANO = LAST_INSERT_ID();

-- ── 2.5 sh_menu_items — pizza variant children ─────────────────────────
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL GYROSO 30cm', 'AL_GYROSO_30CM', 'variant', 1,
  0, NULL, @p_AL_GYROSO, @opt_30cm,
  8.0, 5.0, 'drobiowy kebab / kapusta / pomidorki / kukurydza / ogórek konserwowy / sos czosnkowy', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL GYROSO 37cm', 'AL_GYROSO_37CM', 'variant', 1,
  0, NULL, @p_AL_GYROSO, @opt_37cm,
  8.0, 5.0, 'drobiowy kebab / kapusta / pomidorki / kukurydza / ogórek konserwowy / sos czosnkowy', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL.TONNO 30cm', 'AL_TONNO_30CM', 'variant', 1,
  0, NULL, @p_AL_TONNO, @opt_30cm,
  8.0, 5.0, 'tuńczyk / fileciki anchois / czerwona cebula / pomidorki', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AL.TONNO 37cm', 'AL_TONNO_37CM', 'variant', 1,
  0, NULL, @p_AL_TONNO, @opt_37cm,
  8.0, 5.0, 'tuńczyk / fileciki anchois / czerwona cebula / pomidorki', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO 30cm', 'AMERICANO_30CM', 'variant', 1,
  0, NULL, @p_AMERICANO, @opt_30cm,
  8.0, 5.0, 'sos bbq / salami / kukurydza / kurczak / czerwona cebulka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO 37cm', 'AMERICANO_37CM', 'variant', 1,
  0, NULL, @p_AMERICANO, @opt_37cm,
  8.0, 5.0, 'sos bbq / salami / kukurydza / kurczak / czerwona cebulka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO PICANTE 30cm', 'AMERICANO_PICANTE_30CM', 'variant', 1,
  0, NULL, @p_AMERICANO_PICANTE, @opt_30cm,
  8.0, 5.0, 'sos bbq / pieczarki / kurczak / boczek / jalapeno', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'AMERICANO PICANTE 37cm', 'AMERICANO_PICANTE_37CM', 'variant', 1,
  0, NULL, @p_AMERICANO_PICANTE, @opt_37cm,
  8.0, 5.0, 'sos bbq / pieczarki / kurczak / boczek / jalapeno', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'BOB BUDOWNICZY 30cm', 'BOB_BUDOWNICZY_30CM', 'variant', 1,
  0, NULL, @p_BOB_BUDOWNICZY, @opt_30cm,
  8.0, 5.0, 'ciasto / sos do wyboru / ser', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'BOB BUDOWNICZY 37cm', 'BOB_BUDOWNICZY_37CM', 'variant', 1,
  0, NULL, @p_BOB_BUDOWNICZY, @opt_37cm,
  8.0, 5.0, 'ciasto / sos do wyboru / ser', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CAPRICCIOSA 30cm', 'CAPRICCIOSA_30CM', 'variant', 1,
  0, NULL, @p_CAPRICCIOSA, @opt_30cm,
  8.0, 5.0, 'buffalo mozzarella / pieczarki / jajko / oliwki / szynka / papryka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CAPRICCIOSA 37cm', 'CAPRICCIOSA_37CM', 'variant', 1,
  0, NULL, @p_CAPRICCIOSA, @opt_37cm,
  8.0, 5.0, 'buffalo mozzarella / pieczarki / jajko / oliwki / szynka / papryka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CARBONARA 30cm', 'CARBONARA_30CM', 'variant', 1,
  0, NULL, @p_CARBONARA, @opt_30cm,
  8.0, 5.0, 'sos śmietankowy / boczek / parmezan / pieprz / jajko', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CARBONARA 37cm', 'CARBONARA_37CM', 'variant', 1,
  0, NULL, @p_CARBONARA, @opt_37cm,
  8.0, 5.0, 'sos śmietankowy / boczek / parmezan / pieprz / jajko', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CEZAR 30cm', 'CEZAR_30CM', 'variant', 1,
  0, NULL, @p_CEZAR, @opt_30cm,
  8.0, 5.0, 'biały sos / kurczak / sałata lodowa / pomidorki / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CEZAR 37cm', 'CEZAR_37CM', 'variant', 1,
  0, NULL, @p_CEZAR, @opt_37cm,
  8.0, 5.0, 'biały sos / kurczak / sałata lodowa / pomidorki / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CON BROCCOLI 30cm', 'CON_BROCCOLI_30CM', 'variant', 1,
  0, NULL, @p_CON_BROCCOLI, @opt_30cm,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / kurczak / brokuł / chipsy ziemniaczane / ricotta / suszone pomidorki', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'CON BROCCOLI 37cm', 'CON_BROCCOLI_37CM', 'variant', 1,
  0, NULL, @p_CON_BROCCOLI, @opt_37cm,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / kurczak / brokuł / chipsy ziemniaczane / ricotta / suszone pomidorki', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI CARNE 30cm', 'DI_CARNE_30CM', 'variant', 1,
  0, NULL, @p_DI_CARNE, @opt_30cm,
  8.0, 5.0, 'drobiowy kebab / salami / szynka / ragu wołowe', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI CARNE 37cm', 'DI_CARNE_37CM', 'variant', 1,
  0, NULL, @p_DI_CARNE, @opt_37cm,
  8.0, 5.0, 'drobiowy kebab / salami / szynka / ragu wołowe', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI PARMA 30cm', 'DI_PARMA_30CM', 'variant', 1,
  0, NULL, @p_DI_PARMA, @opt_30cm,
  8.0, 5.0, 'szynka parmeńska / rukola / pomidorki / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DI PARMA 37cm', 'DI_PARMA_37CM', 'variant', 1,
  0, NULL, @p_DI_PARMA, @opt_37cm,
  8.0, 5.0, 'szynka parmeńska / rukola / pomidorki / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DIAVOLA 30cm', 'DIAVOLA_30CM', 'variant', 1,
  0, NULL, @p_DIAVOLA, @opt_30cm,
  8.0, 5.0, 'salami / papryka / pieczarki / chilli', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DIAVOLA 37cm', 'DIAVOLA_37CM', 'variant', 1,
  0, NULL, @p_DIAVOLA, @opt_37cm,
  8.0, 5.0, 'salami / papryka / pieczarki / chilli', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DUE SALAMI 30cm', 'DUE_SALAMI_30CM', 'variant', 1,
  0, NULL, @p_DUE_SALAMI, @opt_30cm,
  8.0, 5.0, 'salami / chipsy ziemniaczane / salami picante', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'DUE SALAMI 37cm', 'DUE_SALAMI_37CM', 'variant', 1,
  0, NULL, @p_DUE_SALAMI, @opt_37cm,
  8.0, 5.0, 'salami / chipsy ziemniaczane / salami picante', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'ETNA 30cm', 'ETNA_30CM', 'variant', 1,
  0, NULL, @p_ETNA, @opt_30cm,
  8.0, 5.0, 'salami picante / pieczarki / chlli / Nduja / ricotta / tabasco', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'ETNA 37cm', 'ETNA_37CM', 'variant', 1,
  0, NULL, @p_ETNA, @opt_37cm,
  8.0, 5.0, 'salami picante / pieczarki / chlli / Nduja / ricotta / tabasco', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FRITTE FORMAGGIO 30cm', 'FRITTE_FORMAGGIO_30CM', 'variant', 1,
  0, NULL, @p_FRITTE_FORMAGGIO, @opt_30cm,
  8.0, 5.0, 'mozzarella / feta / salami / ser edam / frytki', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FRITTE FORMAGGIO 37cm', 'FRITTE_FORMAGGIO_37CM', 'variant', 1,
  0, NULL, @p_FRITTE_FORMAGGIO, @opt_37cm,
  8.0, 5.0, 'mozzarella / feta / salami / ser edam / frytki', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FUNGHI CON SZYNKA 30cm', 'FUNGHI_CON_SZYNKA_30CM', 'variant', 1,
  0, NULL, @p_FUNGHI_CON_SZYNKA, @opt_30cm,
  8.0, 5.0, 'szynka / pieczarki / rukola', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'FUNGHI CON SZYNKA 37cm', 'FUNGHI_CON_SZYNKA_37CM', 'variant', 1,
  0, NULL, @p_FUNGHI_CON_SZYNKA, @opt_37cm,
  8.0, 5.0, 'szynka / pieczarki / rukola', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MAFIA 30cm', 'MAFIA_30CM', 'variant', 1,
  0, NULL, @p_MAFIA, @opt_30cm,
  8.0, 5.0, 'sos bbq / salami picante / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MAFIA 37cm', 'MAFIA_37CM', 'variant', 1,
  0, NULL, @p_MAFIA, @opt_37cm,
  8.0, 5.0, 'sos bbq / salami picante / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA 30cm', 'MARGHERITA_30CM', 'variant', 1,
  0, NULL, @p_MARGHERITA, @opt_30cm,
  8.0, 5.0, 'sos / ser', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA 37cm', 'MARGHERITA_37CM', 'variant', 1,
  0, NULL, @p_MARGHERITA, @opt_37cm,
  8.0, 5.0, 'sos / ser', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO 30cm', 'MARGHERITA_ITALIANO_30CM', 'variant', 1,
  0, NULL, @p_MARGHERITA_ITALIANO, @opt_30cm,
  8.0, 5.0, 'buffalo mozzarella / świeża bazylia / oliwa / parmezan / oregano / rozmaryn', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO 37cm', 'MARGHERITA_ITALIANO_37CM', 'variant', 1,
  0, NULL, @p_MARGHERITA_ITALIANO, @opt_37cm,
  8.0, 5.0, 'buffalo mozzarella / świeża bazylia / oliwa / parmezan / oregano / rozmaryn', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO BIANCA 30cm', 'MARGHERITA_ITALIANO_BIANCA_30CM', 'variant', 1,
  0, NULL, @p_MARGHERITA_ITALIANO_BIANCA, @opt_30cm,
  8.0, 5.0, 'biały sos / buffalo mozzarella / bazylia / oregano / rozmaryn / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MARGHERITA ITALIANO BIANCA 37cm', 'MARGHERITA_ITALIANO_BIANCA_37CM', 'variant', 1,
  0, NULL, @p_MARGHERITA_ITALIANO_BIANCA, @opt_37cm,
  8.0, 5.0, 'biały sos / buffalo mozzarella / bazylia / oregano / rozmaryn / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MONTANARA 30cm', 'MONTANARA_30CM', 'variant', 1,
  0, NULL, @p_MONTANARA, @opt_30cm,
  8.0, 5.0, 'buffalo mozzarella / smażone pieczarki / włoska kiełbasa / Nduja / suszone pomidorki / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'MONTANARA 37cm', 'MONTANARA_37CM', 'variant', 1,
  0, NULL, @p_MONTANARA, @opt_37cm,
  8.0, 5.0, 'buffalo mozzarella / smażone pieczarki / włoska kiełbasa / Nduja / suszone pomidorki / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'NEW YORK 30cm', 'NEW_YORK_30CM', 'variant', 1,
  0, NULL, @p_NEW_YORK, @opt_30cm,
  8.0, 5.0, 'sos bbq / kukurydza / rwana wieprzowina / pieczarki / papryka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'NEW YORK 37cm', 'NEW_YORK_37CM', 'variant', 1,
  0, NULL, @p_NEW_YORK, @opt_37cm,
  8.0, 5.0, 'sos bbq / kukurydza / rwana wieprzowina / pieczarki / papryka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POLLO 30cm', 'POLLO_30CM', 'variant', 1,
  0, NULL, @p_POLLO, @opt_30cm,
  8.0, 5.0, 'kurczak / pieczarki / kukurydza / czerwona cebulka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POLLO 37cm', 'POLLO_37CM', 'variant', 1,
  0, NULL, @p_POLLO, @opt_37cm,
  8.0, 5.0, 'kurczak / pieczarki / kukurydza / czerwona cebulka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POPEY 30cm', 'POPEY_30CM', 'variant', 1,
  0, NULL, @p_POPEY, @opt_30cm,
  8.0, 5.0, 'kurczak / brokuły / szpinak / suszone pomidorki / feta', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'POPEY 37cm', 'POPEY_37CM', 'variant', 1,
  0, NULL, @p_POPEY, @opt_37cm,
  8.0, 5.0, 'kurczak / brokuły / szpinak / suszone pomidorki / feta', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'PÓL NA PÓŁ', 'POL_NA_POL_37CM', 'variant', 1,
  0, NULL, @p_POL_NA_POL, @opt_37cm,
  8.0, 5.0, 'Wybierz 2 połówki', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'QUATTRO FORMAGGI 30cm', 'QUATTRO_FORMAGGI_30CM', 'variant', 1,
  0, NULL, @p_QUATTRO_FORMAGGI, @opt_30cm,
  8.0, 5.0, 'sos śmietanowy / gorgonzola / feta / edamski / ricotta', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'QUATTRO FORMAGGI 37cm', 'QUATTRO_FORMAGGI_37CM', 'variant', 1,
  0, NULL, @p_QUATTRO_FORMAGGI, @opt_37cm,
  8.0, 5.0, 'sos śmietanowy / gorgonzola / feta / edamski / ricotta', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'RAGU 30cm', 'RAGU_30CM', 'variant', 1,
  0, NULL, @p_RAGU, @opt_30cm,
  8.0, 5.0, 'mięso wołowe / pieczarki / czerwona cebulka / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'RAGU 37cm', 'RAGU_37CM', 'variant', 1,
  0, NULL, @p_RAGU, @opt_37cm,
  8.0, 5.0, 'mięso wołowe / pieczarki / czerwona cebulka / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'STEAKY 30cm', 'STEAKY_30CM', 'variant', 1,
  0, NULL, @p_STEAKY, @opt_30cm,
  8.0, 5.0, 'chipsy ziemniaczane / pieczarka / stek wołowy / rukola / krem balsamiczny / sezamowe ciasto / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'STEAKY 37cm', 'STEAKY_37CM', 'variant', 1,
  0, NULL, @p_STEAKY, @opt_37cm,
  8.0, 5.0, 'chipsy ziemniaczane / pieczarka / stek wołowy / rukola / krem balsamiczny / sezamowe ciasto / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERDURA 30cm', 'VERDURA_30CM', 'variant', 1,
  0, NULL, @p_VERDURA, @opt_30cm,
  8.0, 5.0, 'pieczarki / cukinia / pomidorki / papryka / czerwona cebulka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERDURA 37cm', 'VERDURA_37CM', 'variant', 1,
  0, NULL, @p_VERDURA, @opt_37cm,
  8.0, 5.0, 'pieczarki / cukinia / pomidorki / papryka / czerwona cebulka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERONA 30cm', 'VERONA_30CM', 'variant', 1,
  0, NULL, @p_VERONA, @opt_30cm,
  8.0, 5.0, 'kurczak / pomidorki / rukola / parmezan', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VERONA 37cm', 'VERONA_37CM', 'variant', 1,
  0, NULL, @p_VERONA, @opt_37cm,
  8.0, 5.0, 'kurczak / pomidorki / rukola / parmezan', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VINCI 30cm', 'VINCI_30CM', 'variant', 1,
  0, NULL, @p_VINCI, @opt_30cm,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / pieczarki / włoska kiełbasa / Nduja / czerwona cebulka', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VINCI 37cm', 'VINCI_37CM', 'variant', 1,
  0, NULL, @p_VINCI, @opt_37cm,
  8.0, 5.0, 'sos śmietankowy / buffalo mozzarella / pieczarki / włoska kiełbasa / Nduja / czerwona cebulka', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VULCANO 30cm', 'VULCANO_30CM', 'variant', 1,
  0, NULL, @p_VULCANO, @opt_30cm,
  8.0, 5.0, 'salami picante / kurczak / jalapeno / chilli', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_pizze, 'VULCANO 37cm', 'VULCANO_37CM', 'variant', 1,
  0, NULL, @p_VULCANO, @opt_37cm,
  8.0, 5.0, 'salami picante / kurczak / jalapeno / chilli', 1, 'Live');

-- ── 2.6 sh_menu_items — panini parent items ───────────────────────────
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO', 'PANINI_AMERICANO', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / kurczak / salami / czerwona cebulka / kukurydza', 0, 'Live');
SET @p_PANINI_AMERICANO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO PICANTE', 'PANINI_AMERICANO_PICANTE', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / kurczak / boczek / kukurydza / jalapeno', 1, 'Live');
SET @p_PANINI_AMERICANO_PICANTE = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI Al. GYROSO', 'PANINI_AL_GYROSO', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / drobiowy kebab / kapusta pekińska / kukurydza / pomidorki / czerwona cebula / ogórek konserwowy', 2, 'Live');
SET @p_PANINI_AL_GYROSO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI CEZAR', 'PANINI_CEZAR', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / kurczak / pomidorki / czerwona cebula / sałata lodowa', 3, 'Live');
SET @p_PANINI_CEZAR = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI DI CARNE', 'PANINI_DI_CARNE', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / kurczak / salami / szynka / boczek', 4, 'Live');
SET @p_PANINI_DI_CARNE = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI ITALIANO', 'PANINI_ITALIANO', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / szynka parmeńska / pomidorki / rukola', 5, 'Live');
SET @p_PANINI_ITALIANO = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI MAFIA', 'PANINI_MAFIA', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / salami / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 6, 'Live');
SET @p_PANINI_MAFIA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI POLLO PICANTE', 'PANINI_POLLO_PICANTE', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / kurczak / boczek / rukola / chilli', 7, 'Live');
SET @p_PANINI_POLLO_PICANTE = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI STEKY', 'PANINI_STEKY', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / stek wołowy / rukola / czerwona cebula / pomidorki', 8, 'Live');
SET @p_PANINI_STEKY = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VERDURA', 'PANINI_VERDURA', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / pomidorki / smażone pieczarki / papryka / cukinia', 9, 'Live');
SET @p_PANINI_VERDURA = LAST_INSERT_ID();
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VEZUVIO', 'PANINI_VEZUVIO', 'variant_parent', 1,
  1, @scale_panini_id, NULL, NULL,
  8.0, 5.0, 'sos / ser / salami / szynka / czerwona cebulka / kukurydza', 10, 'Live');
SET @p_PANINI_VEZUVIO = LAST_INSERT_ID();

-- ── 2.7 sh_menu_items — panini variant children ───────────────────────
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO - MAŁE', 'PANINI_AMERICANO_MALE', 'variant', 1,
  0, NULL, @p_PANINI_AMERICANO, @opt_male,
  8.0, 5.0, 'sos / ser / kurczak / salami / czerwona cebulka / kukurydza', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO - DUŻE', 'PANINI_AMERICANO_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_AMERICANO, @opt_duze,
  8.0, 5.0, 'sos / ser / kurczak / salami / czerwona cebulka / kukurydza', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO PICANTE - MAŁE', 'PANINI_AMERICANO_PICANTE_MALE', 'variant', 1,
  0, NULL, @p_PANINI_AMERICANO_PICANTE, @opt_male,
  8.0, 5.0, 'sos / ser / kurczak / boczek / kukurydza / jalapeno', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI AMERICANO PICANTE - DUŻE', 'PANINI_AMERICANO_PICANTE_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_AMERICANO_PICANTE, @opt_duze,
  8.0, 5.0, 'sos / ser / kurczak / boczek / kukurydza / jalapeno', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI Al. GYROSO - MAŁE', 'PANINI_AL_GYROSO_MALE', 'variant', 1,
  0, NULL, @p_PANINI_AL_GYROSO, @opt_male,
  8.0, 5.0, 'sos / ser / drobiowy kebab / kapusta pekińska / kukurydza / pomidorki / czerwona cebula / ogórek konserwowy', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI Al. GYROSO - DUŻE', 'PANINI_AL_GYROSO_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_AL_GYROSO, @opt_duze,
  8.0, 5.0, 'sos / ser / drobiowy kebab / kapusta pekińska / kukurydza / pomidorki / czerwona cebula / ogórek konserwowy', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI CEZAR - MAŁE', 'PANINI_CEZAR_MALE', 'variant', 1,
  0, NULL, @p_PANINI_CEZAR, @opt_male,
  8.0, 5.0, 'sos / ser / kurczak / pomidorki / czerwona cebula / sałata lodowa', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI CEZAR - DUŻE', 'PANINI_CEZAR_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_CEZAR, @opt_duze,
  8.0, 5.0, 'sos / ser / kurczak / pomidorki / czerwona cebula / sałata lodowa', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI DI CARNE - MAŁE', 'PANINI_DI_CARNE_MALE', 'variant', 1,
  0, NULL, @p_PANINI_DI_CARNE, @opt_male,
  8.0, 5.0, 'sos / ser / kurczak / salami / szynka / boczek', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI DI CARNE - DUŻE', 'PANINI_DI_CARNE_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_DI_CARNE, @opt_duze,
  8.0, 5.0, 'sos / ser / kurczak / salami / szynka / boczek', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI ITALIANO - MAŁE', 'PANINI_ITALIANO_MALE', 'variant', 1,
  0, NULL, @p_PANINI_ITALIANO, @opt_male,
  8.0, 5.0, 'sos / ser / szynka parmeńska / pomidorki / rukola', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI ITALIANO - DUŻE', 'PANINI_ITALIANO_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_ITALIANO, @opt_duze,
  8.0, 5.0, 'sos / ser / szynka parmeńska / pomidorki / rukola', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI MAFIA - MAŁE', 'PANINI_MAFIA_MALE', 'variant', 1,
  0, NULL, @p_PANINI_MAFIA, @opt_male,
  8.0, 5.0, 'sos / salami / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI MAFIA - DUŻE', 'PANINI_MAFIA_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_MAFIA, @opt_duze,
  8.0, 5.0, 'sos / salami / rwana wieprzowina / kukurydza / czerwona cebula / jalapeno', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI POLLO PICANTE - MAŁE', 'PANINI_POLLO_PICANTE_MALE', 'variant', 1,
  0, NULL, @p_PANINI_POLLO_PICANTE, @opt_male,
  8.0, 5.0, 'sos / ser / kurczak / boczek / rukola / chilli', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI POLLO PICANTE - DUŻE', 'PANINI_POLLO_PICANTE_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_POLLO_PICANTE, @opt_duze,
  8.0, 5.0, 'sos / ser / kurczak / boczek / rukola / chilli', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI STEKY - MAŁE', 'PANINI_STEKY_MALE', 'variant', 1,
  0, NULL, @p_PANINI_STEKY, @opt_male,
  8.0, 5.0, 'sos / ser / stek wołowy / rukola / czerwona cebula / pomidorki', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI STEKY - DUŻE', 'PANINI_STEKY_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_STEKY, @opt_duze,
  8.0, 5.0, 'sos / ser / stek wołowy / rukola / czerwona cebula / pomidorki', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VERDURA - MAŁE', 'PANINI_VERDURA_MALE', 'variant', 1,
  0, NULL, @p_PANINI_VERDURA, @opt_male,
  8.0, 5.0, 'sos / ser / pomidorki / smażone pieczarki / papryka / cukinia', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VERDURA - DUŻE', 'PANINI_VERDURA_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_VERDURA, @opt_duze,
  8.0, 5.0, 'sos / ser / pomidorki / smażone pieczarki / papryka / cukinia', 1, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VEZUVIO - MAŁE', 'PANINI_VEZUVIO_MALE', 'variant', 1,
  0, NULL, @p_PANINI_VEZUVIO, @opt_male,
  8.0, 5.0, 'sos / ser / salami / szynka / czerwona cebulka / kukurydza', 0, 'Live');
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status)
VALUES (@tid, @cat_panini, 'PANINI VEZUVIO - DUŻE', 'PANINI_VEZUVIO_DUZE', 'variant', 1,
  0, NULL, @p_PANINI_VEZUVIO, @opt_duze,
  8.0, 5.0, 'sos / ser / salami / szynka / czerwona cebulka / kukurydza', 1, 'Live');

-- ── 2.8 sh_menu_items — single items (no variants) ───────────────────
-- FOCCACIA
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_foccacia, 'FOCCACIA ROSMARINO', 'FOCCACIA_ROSMARINO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'oregano / rozmaryn / sól morska / oliwa czosnkowa', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_foccacia, 'FOCCACIA NOCCI', 'FOCCACIA_NOCCI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'orzechy / słonecznik / czerwona cebulka / ser gorgonzola / rukola / ocet balsamiczny / gruszka', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_foccacia, 'FOCCACIA POMODORO', 'FOCCACIA_POMODORO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'pomidorki / bazylia / cebulka / oregano / rozmaryn / sól morska / oliwa czosnkowa', 2, 'Live',
  NULL, NULL, NULL);

-- CALZONE
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_calzone, 'CALZONE DI CARNE', 'CALZONE_DI_CARNE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'kurczak / salami / szynka / boczek', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE VEZUVIO', 'CALZONE_VEZUVIO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'kurczak / salami / szynka / czerwona cebulka / kukurydza', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE POPEY', 'CALZONE_POPEY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'kurczak / boczek / szpinak / ser feta / papryka', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE VEGETARIANO', 'CALZONE_VEGETARIANO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'papryka / cukinia / smażone pieczarki / chipsy ziemniaczane / oliwki', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE CONTADINO', 'CALZONE_CONTADINO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'salami picante / chipsy ziemniaczane / smażone pieczarki / feta / papryka', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE POLLO PICANTE', 'CALZONE_POLLO_PICANTE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'kurczak / smażone pieczarki / kukurydza / czerwona cebulka / chilli', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE ITALIANO', 'CALZONE_ITALIANO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'włoska kiełbasa / smażone pieczarki / brokuł / czerwona cebulka', 6, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE TEXAS', 'CALZONE_TEXAS', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos bbq / kurczak / boczek / jalapeno / kukurydza / papryka', 7, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_calzone, 'CALZONE MAFIA', 'CALZONE_MAFIA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos bbq / salami picante / rwana wieprzowina / jalapeno / kukurydza', 8, 'Live',
  NULL, NULL, NULL);

-- MAKARONY
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_makarony, 'TAGLIATELLE STEAKY', 'TAGLIATELLE_STEAKY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'stek wołowy / czerwona cebula / pieczarki / pomidorki / rukola / parmezan / krem balsamiczny', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'SPAGHETTI POMODORO', 'SPAGHETTI_POMODORO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / pomidorki / świeża bazylia / parmezan', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'SPAGHETTI BOLOGNESE', 'SPAGHETTI_BOLOGNESE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / mięso wołowe / parmezan', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'SPAGHETTI CARBONARA', 'SPAGHETTI_CARBONARA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / boczek / parmezan', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE TOSCANA', 'PENNE_TOSCANA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / włoska kiełbasa / pieczarki / chilli / parmezan', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE DI CAPO', 'PENNE_DI_CAPO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / szynka / pieczarki / rukola / suszone pomidorki / parmezan', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE AMATRICIANA', 'PENNE_AMATRICIANA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowo-pomidorowy / pieczarki / włoska kiełbasa / jalapeno / boczek / parmezan', 6, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'GNOCCHI ALLA SORRENTINA', 'GNOCCHI_ALLA_SORRENTINA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / kurczak / bazylia / buffalo mozzarella / oregano / czosnek', 7, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'ZAPIEKANKA MAKARONOWA', 'ZAPIEKANKA_MAKARONOWA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'dowolny makaron z menu / zapiekany w cieście z serem', 8, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE ARABIATA', 'PENNE_ARABIATA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / chilli / pomidorki / parmezan', 9, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE SPINACI', 'PENNE_SPINACI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / kurczak / szpinak / pieczarki / parmezan', 10, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'PENNE POLLO CON BROCCOLI', 'PENNE_POLLO_CON_BROCCOLI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / kurczak / brokuły / parmezan', 11, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'TAGLIATELLE AL. TONNO', 'TAGLIATELLE_AL_TONNO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / tuńczyk / fileciki anchois / pietruszka / czerwona cebula / pomidorki / parmezan', 12, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'TAGLIATELLE VERDE', 'TAGLIATELLE_VERDE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / pieczarki / cukinia / pomidorki / parmezan / rukola', 13, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_makarony, 'GNOCCHI ALLA TOSCANA', 'GNOCCHI_ALLA_TOSCANA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy / kurczak / szpinak / suszone pomidorki / czosnek / parmezan', 14, 'Live',
  NULL, NULL, NULL);

-- SAŁATKI
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_salatki, 'SAŁATKA STEAKY', 'SALATKA_STEAKY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'Pomidorki / Krem balsamiczny / Rukola / Stek wołowy / Cebula czerwona / Sezam / Parmezan', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_salatki, 'SAŁATKA CEZAR', 'SALATKA_CEZAR', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sałata / kurczak / parmezan / grzanki / sos czosnkowy', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_salatki, 'SAŁATKA FORNO', 'SALATKA_FORNO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'rukola / gruszka / orzechy włoskie / nasiona słonecznika / pomidorki / czerwona cebulka / oliwki / oliwa czosnkowa / parmezan / ocet balsamiczny', 2, 'Live',
  NULL, NULL, NULL);

-- ZAPIEKANKI
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_zapiekanki, 'ZAPIEKANKA KLASYCZNA', 'ZAPIEKANKA_KLASYCZNA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'ser / farsz / cebula prażona / sos', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_zapiekanki, 'ZAPIEKANKA AMERICANO', 'ZAPIEKANKA_AMERICANO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'ser / farsz / rwana wieprzowina / kukurydza / jalapeno / cebula prażona / sos', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_zapiekanki, 'ZAPIEKANKA FORNO', 'ZAPIEKANKA_FORNO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'ser / farsz / drobiowy kebab / rukola / ogórek / pomidorki / kukurydza / cebula prażona / sos', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_zapiekanki, 'ZAPIEKANKA Z SZYNKĄ', 'ZAPIEKANKA_Z_SZYNKA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'ser / farsz / szynka / cebula prażona / sos', 3, 'Live',
  NULL, NULL, NULL);

-- POZOSTAŁE
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_pozostale, 'ZUPA KREM', 'ZUPA_KREM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'krem pomidorowy / grzanki / parmezan / bazylia', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'PIKANTNE SKRZYDEŁKA 8szt.', 'PIKANTNE_SKRZYDELKA_8SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'skrzydełka', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'KREWETKI W TEMPURZE 8szt.', 'KREWETKI_W_TEMPURZE_8SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'z sosem słodkie chilli', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'KRĄŻKI CEBULOWE 10szt.', 'KRAZKI_CEBULOWE_10SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'krążki cebulowe / sos', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'ŻEBERKO BBQ', 'ZEBERKO_BBQ', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'SURÓWKA', 'SUROWKA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'kapusta pekińska / pomidor / ogórek konserwowy / czerwona cebula / kukurydza / sos czosnkowy', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_pozostale, 'FRYTKI', 'FRYTKI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 6, 'Live',
  NULL, NULL, NULL);

-- DLA DZIECI
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_dladzieci, 'MAKARON KIDS POMODORO', 'MAKARON_KIDS_POMODORO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / pomidorki / świeża bazylia / parmezan', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_dladzieci, 'POLĘDWICE W TEMPURZE 4szt.', 'POLEDWICE_W_TEMPURZE_4SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'polędwiczki / sos', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_dladzieci, 'KIDS FRYTKI', 'KIDS_FRYTKI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_dladzieci, 'MAKARON KIDS BOLOGNESE', 'MAKARON_KIDS_BOLOGNESE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos napoli / mięso wołowe / parmezan', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_dladzieci, 'SZYSZKI ZIEMNIACZANE 10szt.', 'SZYSZKI_ZIEMNIACZANE_10SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_dladzieci, 'NUGGETSY 8szt.', 'NUGGETSY_8SZT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 5, 'Live',
  NULL, NULL, NULL);

-- NAPOJE
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_napoje, 'ROCKSTAR', 'ROCKSTAR', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'PEPSI', 'PEPSI', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'LIPTON', 'LIPTON', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, '7UP', '7UP', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'WODA', 'WODA', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'MIRINDA', 'MIRINDA', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'SOK TOMA', 'SOK_TOMA', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 6, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_napoje, 'LEMONIADA', 'LEMONIADA', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 7, 'Live',
  NULL, NULL, NULL);

-- DESERY
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_desery, 'GAŁKA ŚMIETANKOW-WANILIOWA', 'GALKA_SMIETANKOW_WANILIOWA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_desery, 'GAŁKA ŚMIETANKOWO-JAGODOWA', 'GALKA_SMIETANKOWO_JAGODOWA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_desery, 'GAŁKA ŚMIETANKOWO-CZEKOLADOWA', 'GALKA_SMIETANKOWO_CZEKOLADOWA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_desery, 'MINI CALZONE Z nutellą i mascarpone', 'MINI_CALZONE_Z_NUTELLA_I_MASCARPONE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_desery, 'MINI CALZONE z malinami i mascarpone', 'MINI_CALZONE_Z_MALINAMI_I_MASCARPONE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_desery, 'MINI CALZONE z jabłkiem i cynamonem', 'MINI_CALZONE_Z_JABLKIEM_I_CYNAMONEM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 5, 'Live',
  NULL, NULL, NULL);

-- SOSY
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_sosy, 'SOS CZOSNKOWY', 'SOS_CZOSNKOWY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'SOS POMIDOROWY', 'SOS_POMIDOROWY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'SOS MEKSYKAŃSKI', 'SOS_MEKSYKANSKI', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'SOS 1000-WYSP', 'SOS_1000_WYSP', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'SOS OSTRY', 'SOS_OSTRY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'SOS BBQ', 'SOS_BBQ', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'KETCHUP', 'KETCHUP', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 6, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_sosy, 'MAJONEZ', 'MAJONEZ', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, '', 7, 'Live',
  NULL, NULL, NULL);

-- GYROSY
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_gyrosy, 'GYROS ROLLO XXL', 'GYROS_ROLLO_XXL', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos / drobiowy kebab / kapusta pekińska / kukurydza / pomidor / ogórek konserwowy / czerwona cebula', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_gyrosy, 'VEGE ROLLO XXL', 'VEGE_ROLLO_XXL', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos / frytki / kapusta pekińska / pomidor / ogórek konserwowy / kukurydza / czerwona cebula', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_gyrosy, 'GYROS NA TALERZU', 'GYROS_NA_TALERZU', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos / drobiowy kebab / frytki / kapusta pekińska / pomidor / ogórek konserwowy / kukurydza / czerwona cebula', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_gyrosy, 'GYROS FORNO', 'GYROS_FORNO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos / drobiowy kebab /frytki / kapusta pekińska / kukurydza / pomidor / ogórek konserwowy / czerwona cebula / ZAPIEKANE Z SEREM W PIECU', 3, 'Live',
  NULL, NULL, NULL);

-- PIWA
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_piwa, 'TYSKIE Z KIJA', 'TYSKIE_Z_KIJA', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 0, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'HARDMADE  400ml', 'HARDMADE_400ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, 'Rodzaje do wyboru na miejscu', 1, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'PERONI 330ml', 'PERONI_330ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 2, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'LECH pils 500ml', 'LECH_PILS_500ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 3, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'LECH premium 500ml', 'LECH_PREMIUM_500ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 4, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'LECH FREE 00%', 'LECH_FREE_00', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, 'Rodzaje do wyboru na miejscu', 5, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'PERONI 00% 330ml', 'PERONI_00_330ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 6, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'KSIĄŻĘCE 500ml', 'KSIAZECE_500ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, 'Rodzaje do wyboru na miejscu', 7, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'PILSNER URQUELL 500ml', 'PILSNER_URQUELL_500ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, '', 8, 'Live',
  NULL, NULL, NULL),
(@tid, @cat_piwa, 'HARDMADE free 400ml', 'HARDMADE_FREE_400ML', 'standard', 1,
  0, NULL, NULL, NULL,
  23.0, 23.0, 'Rodzaje do wyboru na miejscu', 9, 'Live',
  NULL, NULL, NULL);

-- NOWOŚCI
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_nowosci, 'PIZZERINKA - CEBULARZ', 'PIZZERINKA_CEBULARZ', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'śmietankowy sos / karmelizowana cebula / ser', 0, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'PIZZERINKA - PIECZARA', 'PIZZERINKA_PIECZARA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos pomidorowy / farsz pieczarkowy / ser', 1, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'PIZZA EL Polako 30cm', 'PIZZA_EL_POLAKO_30CM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos pomidorowy, ser, farsz pieczarkowy, podsmażana kiełbasa, kukurydza, cebulka prażona', 2, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'PIZZA EL Polako 37cm', 'PIZZA_EL_POLAKO_37CM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos pomidorowy, ser, farsz pieczarkowy, podsmażana kiełbasa, kukurydza, cebulka prażona', 3, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'Zestaw małego PIZZAIOLO', 'ZESTAW_MALEGO_PIZZAIOLO', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'Zrób własną pizzę w domu! W zestaw wchodzą dwie kulki ciasta, sos i ser', 4, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'HAWAJSKI DIABEŁEK', 'HAWAJSKI_DIABELEK', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'makaron penne z boczkiem, kurczakiem, chilli, ananas, sos pomidorowy, parmezan', 5, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'Herbata - Malinowa rozgrzewka', 'HERBATA_MALINOWA_ROZGRZEWKA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'Maliny, miód, cytryna i goździki — naturalna bomba ciepła i aromatu, która postawi Cię na nogi nawet w najbardziej mroźny dzień.', 6, 'Live',
  'new', NULL, NULL),
(@tid, @cat_nowosci, 'DZBANEK TŁOCZONEGO SOKU', 'DZBANEK_TLOCZONEGO_SOKU', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'dzbanek naturalnych, tłoczonych soków
z lokalnych sadów
bez sztucznych dodatków – tylko czysta natura i pełnia smaku.', 7, 'Live',
  'new', NULL, NULL);

-- ZIMOWE MENU
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, `type`, is_active,
  is_variant_parent, variant_scale_id, parent_item_id, variant_option_id,
  vat_rate_dine_in, vat_rate_takeaway, description, display_order, publication_status,
  badge_type, valid_from, valid_to)
VALUES
(@tid, @cat_zimowemenu, 'PIZZA ZIMOWA 30cm', 'PIZZA_ZIMOWA_30CM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy, owoce 
kaki, krewetki, parmezan 
i pietruszka', 0, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'PIZZA ZIMOWA 37cm', 'PIZZA_ZIMOWA_37CM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'sos śmietankowy, owoce 
kaki, krewetki, parmezan 
i pietruszka', 1, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'CAMEMBERT W ŚWIEŻO WYPIEKANYM CHLEBIE', 'CAMEMBERT_W_SWIEZO_WYPIEKANYM_CHLEBIE', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'z chrupiącymi 
paluchami
i sosem
żurawinowym', 2, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'SCHAB ZE ŚLIWKĄ W BOCZKU', 'SCHAB_ZE_SLIWKA_W_BOCZKU', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'w sosie kremowo-
musztardowym
podawany z 
ziemniaczanymi
szyszkami 
i zimową sałatą', 3, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'FOCACCIA Z ŻURAWINĄ I CAMEMBERT', 'FOCACCIA_Z_ZURAWINA_I_CAMEMBERT', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'z rukolą, 
orzechami włoskimi i sosem żurawinowym', 4, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'ZIMOWA SAŁATKA', 'ZIMOWA_SALATKA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'z rukolą, pomidorkami, 
orzechami włoskimi, 
żurawiną, camembertem 
i czerwoną cebulą', 5, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'CALZONE Z BARSZCZEM', 'CALZONE_Z_BARSZCZEM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'barszcz z dwoma 
mini calzone 
z farszem 
grzybowym / 
mięsnym.', 6, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'GNOCCHI ZE SCHABEM', 'GNOCCHI_ZE_SCHABEM', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'w kremowym 
sosie
kremowo-
musztardowym 
z nutą suszonej 
żurawiny', 7, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'HERBATA Z POKRZYWY', 'HERBATA_Z_POKRZYWY', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'Rozgrzewająca 
kompozycja 
z dodatkiem miodu 
i rozmarynu.
Aromatyczna, 
słodko-ziołowa', 8, 'Live',
  NULL, '2025-10-01', '2026-03-31'),
(@tid, @cat_zimowemenu, 'GRZANIEC Z ZURAWINĄ', 'GRZANIEC_Z_ZURAWINA', 'standard', 1,
  0, NULL, NULL, NULL,
  8.0, 5.0, 'z miodem, cynamonem 
i wanilią', 9, 'Live',
  NULL, '2025-10-01', '2026-03-31');

-- ── 2.9 sh_price_tiers ─────────────────────────────────────────────────
INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES
(@tid, 'ITEM', 'AL_GYROSO_30CM', 'POS',      39.0),
(@tid, 'ITEM', 'AL_GYROSO_30CM', 'Takeaway', 39.0),
(@tid, 'ITEM', 'AL_GYROSO_30CM', 'Delivery', 39.0),
(@tid, 'ITEM', 'AL_GYROSO_37CM', 'POS',      46.0),
(@tid, 'ITEM', 'AL_GYROSO_37CM', 'Takeaway', 46.0),
(@tid, 'ITEM', 'AL_GYROSO_37CM', 'Delivery', 46.0),
(@tid, 'ITEM', 'AL_TONNO_30CM', 'POS',      40.0),
(@tid, 'ITEM', 'AL_TONNO_30CM', 'Takeaway', 40.0),
(@tid, 'ITEM', 'AL_TONNO_30CM', 'Delivery', 40.0),
(@tid, 'ITEM', 'AL_TONNO_37CM', 'POS',      47.0),
(@tid, 'ITEM', 'AL_TONNO_37CM', 'Takeaway', 47.0),
(@tid, 'ITEM', 'AL_TONNO_37CM', 'Delivery', 47.0),
(@tid, 'ITEM', 'AMERICANO_30CM', 'POS',      34.0),
(@tid, 'ITEM', 'AMERICANO_30CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'AMERICANO_30CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'AMERICANO_37CM', 'POS',      41.0),
(@tid, 'ITEM', 'AMERICANO_37CM', 'Takeaway', 41.0),
(@tid, 'ITEM', 'AMERICANO_37CM', 'Delivery', 41.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'AMERICANO_PICANTE_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_30CM', 'POS',      27.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_30CM', 'Takeaway', 27.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_30CM', 'Delivery', 27.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_37CM', 'POS',      34.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_37CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'BOB_BUDOWNICZY_37CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'CAPRICCIOSA_30CM', 'POS',      36.0),
(@tid, 'ITEM', 'CAPRICCIOSA_30CM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CAPRICCIOSA_30CM', 'Delivery', 36.0),
(@tid, 'ITEM', 'CAPRICCIOSA_37CM', 'POS',      43.0),
(@tid, 'ITEM', 'CAPRICCIOSA_37CM', 'Takeaway', 43.0),
(@tid, 'ITEM', 'CAPRICCIOSA_37CM', 'Delivery', 43.0),
(@tid, 'ITEM', 'CARBONARA_30CM', 'POS',      36.0),
(@tid, 'ITEM', 'CARBONARA_30CM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CARBONARA_30CM', 'Delivery', 36.0),
(@tid, 'ITEM', 'CARBONARA_37CM', 'POS',      43.0),
(@tid, 'ITEM', 'CARBONARA_37CM', 'Takeaway', 43.0),
(@tid, 'ITEM', 'CARBONARA_37CM', 'Delivery', 43.0),
(@tid, 'ITEM', 'CEZAR_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'CEZAR_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'CEZAR_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'CEZAR_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'CEZAR_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'CEZAR_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'CON_BROCCOLI_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'CON_BROCCOLI_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'CON_BROCCOLI_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'CON_BROCCOLI_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'CON_BROCCOLI_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'CON_BROCCOLI_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'DI_CARNE_30CM', 'POS',      38.0),
(@tid, 'ITEM', 'DI_CARNE_30CM', 'Takeaway', 38.0),
(@tid, 'ITEM', 'DI_CARNE_30CM', 'Delivery', 38.0),
(@tid, 'ITEM', 'DI_CARNE_37CM', 'POS',      45.0),
(@tid, 'ITEM', 'DI_CARNE_37CM', 'Takeaway', 45.0),
(@tid, 'ITEM', 'DI_CARNE_37CM', 'Delivery', 45.0),
(@tid, 'ITEM', 'DI_PARMA_30CM', 'POS',      36.0),
(@tid, 'ITEM', 'DI_PARMA_30CM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'DI_PARMA_30CM', 'Delivery', 36.0),
(@tid, 'ITEM', 'DI_PARMA_37CM', 'POS',      43.0),
(@tid, 'ITEM', 'DI_PARMA_37CM', 'Takeaway', 43.0),
(@tid, 'ITEM', 'DI_PARMA_37CM', 'Delivery', 43.0),
(@tid, 'ITEM', 'DIAVOLA_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'DIAVOLA_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'DIAVOLA_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'DIAVOLA_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'DIAVOLA_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'DIAVOLA_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'DUE_SALAMI_30CM', 'POS',      34.0),
(@tid, 'ITEM', 'DUE_SALAMI_30CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'DUE_SALAMI_30CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'DUE_SALAMI_37CM', 'POS',      41.0),
(@tid, 'ITEM', 'DUE_SALAMI_37CM', 'Takeaway', 41.0),
(@tid, 'ITEM', 'DUE_SALAMI_37CM', 'Delivery', 41.0),
(@tid, 'ITEM', 'ETNA_30CM', 'POS',      38.0),
(@tid, 'ITEM', 'ETNA_30CM', 'Takeaway', 38.0),
(@tid, 'ITEM', 'ETNA_30CM', 'Delivery', 38.0),
(@tid, 'ITEM', 'ETNA_37CM', 'POS',      45.0),
(@tid, 'ITEM', 'ETNA_37CM', 'Takeaway', 45.0),
(@tid, 'ITEM', 'ETNA_37CM', 'Delivery', 45.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'FRITTE_FORMAGGIO_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_30CM', 'POS',      34.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_30CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_30CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_37CM', 'POS',      41.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_37CM', 'Takeaway', 41.0),
(@tid, 'ITEM', 'FUNGHI_CON_SZYNKA_37CM', 'Delivery', 41.0),
(@tid, 'ITEM', 'MAFIA_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'MAFIA_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'MAFIA_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'MAFIA_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'MAFIA_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'MAFIA_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'MARGHERITA_30CM', 'POS',      27.0),
(@tid, 'ITEM', 'MARGHERITA_30CM', 'Takeaway', 27.0),
(@tid, 'ITEM', 'MARGHERITA_30CM', 'Delivery', 27.0),
(@tid, 'ITEM', 'MARGHERITA_37CM', 'POS',      34.0),
(@tid, 'ITEM', 'MARGHERITA_37CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'MARGHERITA_37CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_30CM', 'POS',      33.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_30CM', 'Takeaway', 33.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_30CM', 'Delivery', 33.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_37CM', 'POS',      40.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_37CM', 'Takeaway', 40.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_37CM', 'Delivery', 40.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_30CM', 'POS',      34.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_30CM', 'Takeaway', 34.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_30CM', 'Delivery', 34.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_37CM', 'POS',      41.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_37CM', 'Takeaway', 41.0),
(@tid, 'ITEM', 'MARGHERITA_ITALIANO_BIANCA_37CM', 'Delivery', 41.0),
(@tid, 'ITEM', 'MONTANARA_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'MONTANARA_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'MONTANARA_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'MONTANARA_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'MONTANARA_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'MONTANARA_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'NEW_YORK_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'NEW_YORK_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'NEW_YORK_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'NEW_YORK_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'NEW_YORK_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'NEW_YORK_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'POLLO_30CM', 'POS',      36.0),
(@tid, 'ITEM', 'POLLO_30CM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'POLLO_30CM', 'Delivery', 36.0),
(@tid, 'ITEM', 'POLLO_37CM', 'POS',      43.0),
(@tid, 'ITEM', 'POLLO_37CM', 'Takeaway', 43.0),
(@tid, 'ITEM', 'POLLO_37CM', 'Delivery', 43.0),
(@tid, 'ITEM', 'POPEY_30CM', 'POS',      36.0),
(@tid, 'ITEM', 'POPEY_30CM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'POPEY_30CM', 'Delivery', 36.0),
(@tid, 'ITEM', 'POPEY_37CM', 'POS',      43.0),
(@tid, 'ITEM', 'POPEY_37CM', 'Takeaway', 43.0),
(@tid, 'ITEM', 'POPEY_37CM', 'Delivery', 43.0),
(@tid, 'ITEM', 'POL_NA_POL_37CM', 'POS',      0.0),
(@tid, 'ITEM', 'POL_NA_POL_37CM', 'Takeaway', 0.0),
(@tid, 'ITEM', 'POL_NA_POL_37CM', 'Delivery', 0.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_30CM', 'POS',      38.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_30CM', 'Takeaway', 38.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_30CM', 'Delivery', 38.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_37CM', 'POS',      45.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_37CM', 'Takeaway', 45.0),
(@tid, 'ITEM', 'QUATTRO_FORMAGGI_37CM', 'Delivery', 45.0),
(@tid, 'ITEM', 'RAGU_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'RAGU_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'RAGU_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'RAGU_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'RAGU_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'RAGU_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'STEAKY_30CM', 'POS',      40.0),
(@tid, 'ITEM', 'STEAKY_30CM', 'Takeaway', 40.0),
(@tid, 'ITEM', 'STEAKY_30CM', 'Delivery', 40.0),
(@tid, 'ITEM', 'STEAKY_37CM', 'POS',      47.0),
(@tid, 'ITEM', 'STEAKY_37CM', 'Takeaway', 47.0),
(@tid, 'ITEM', 'STEAKY_37CM', 'Delivery', 47.0),
(@tid, 'ITEM', 'VERDURA_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'VERDURA_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'VERDURA_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'VERDURA_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'VERDURA_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'VERDURA_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'VERONA_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'VERONA_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'VERONA_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'VERONA_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'VERONA_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'VERONA_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'VINCI_30CM', 'POS',      37.0),
(@tid, 'ITEM', 'VINCI_30CM', 'Takeaway', 37.0),
(@tid, 'ITEM', 'VINCI_30CM', 'Delivery', 37.0),
(@tid, 'ITEM', 'VINCI_37CM', 'POS',      44.0),
(@tid, 'ITEM', 'VINCI_37CM', 'Takeaway', 44.0),
(@tid, 'ITEM', 'VINCI_37CM', 'Delivery', 44.0),
(@tid, 'ITEM', 'VULCANO_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'VULCANO_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'VULCANO_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'VULCANO_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'VULCANO_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'VULCANO_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_AMERICANO_PICANTE_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_AL_GYROSO_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_CEZAR_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_CEZAR_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_CEZAR_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_CEZAR_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_CEZAR_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_CEZAR_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_DI_CARNE_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_ITALIANO_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_MAFIA_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_MAFIA_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_MAFIA_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_MAFIA_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_MAFIA_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_MAFIA_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_POLLO_PICANTE_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_STEKY_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_STEKY_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_STEKY_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_STEKY_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_STEKY_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_STEKY_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_VERDURA_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_VERDURA_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_VERDURA_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_VERDURA_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_VERDURA_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_VERDURA_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_MALE', 'POS',      16.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_MALE', 'Takeaway', 16.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_MALE', 'Delivery', 16.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_DUZE', 'POS',      24.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_DUZE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PANINI_VEZUVIO_DUZE', 'Delivery', 24.0),
(@tid, 'ITEM', 'FOCCACIA_ROSMARINO', 'POS',      24.0),
(@tid, 'ITEM', 'FOCCACIA_ROSMARINO', 'Takeaway', 24.0),
(@tid, 'ITEM', 'FOCCACIA_ROSMARINO', 'Delivery', 24.0),
(@tid, 'ITEM', 'FOCCACIA_NOCCI', 'POS',      34.0),
(@tid, 'ITEM', 'FOCCACIA_NOCCI', 'Takeaway', 34.0),
(@tid, 'ITEM', 'FOCCACIA_NOCCI', 'Delivery', 34.0),
(@tid, 'ITEM', 'FOCCACIA_POMODORO', 'POS',      28.0),
(@tid, 'ITEM', 'FOCCACIA_POMODORO', 'Takeaway', 28.0),
(@tid, 'ITEM', 'FOCCACIA_POMODORO', 'Delivery', 28.0),
(@tid, 'ITEM', 'CALZONE_DI_CARNE', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_DI_CARNE', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_DI_CARNE', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_VEZUVIO', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_VEZUVIO', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_VEZUVIO', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_POPEY', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_POPEY', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_POPEY', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_VEGETARIANO', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_VEGETARIANO', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_VEGETARIANO', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_CONTADINO', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_CONTADINO', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_CONTADINO', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_POLLO_PICANTE', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_POLLO_PICANTE', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_POLLO_PICANTE', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_ITALIANO', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_ITALIANO', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_ITALIANO', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_TEXAS', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_TEXAS', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_TEXAS', 'Delivery', 36.0),
(@tid, 'ITEM', 'CALZONE_MAFIA', 'POS',      36.0),
(@tid, 'ITEM', 'CALZONE_MAFIA', 'Takeaway', 36.0),
(@tid, 'ITEM', 'CALZONE_MAFIA', 'Delivery', 36.0),
(@tid, 'ITEM', 'TAGLIATELLE_STEAKY', 'POS',      34.0),
(@tid, 'ITEM', 'TAGLIATELLE_STEAKY', 'Takeaway', 34.0),
(@tid, 'ITEM', 'TAGLIATELLE_STEAKY', 'Delivery', 34.0),
(@tid, 'ITEM', 'SPAGHETTI_POMODORO', 'POS',      26.0),
(@tid, 'ITEM', 'SPAGHETTI_POMODORO', 'Takeaway', 26.0),
(@tid, 'ITEM', 'SPAGHETTI_POMODORO', 'Delivery', 26.0),
(@tid, 'ITEM', 'SPAGHETTI_BOLOGNESE', 'POS',      30.0),
(@tid, 'ITEM', 'SPAGHETTI_BOLOGNESE', 'Takeaway', 30.0),
(@tid, 'ITEM', 'SPAGHETTI_BOLOGNESE', 'Delivery', 30.0),
(@tid, 'ITEM', 'SPAGHETTI_CARBONARA', 'POS',      30.0),
(@tid, 'ITEM', 'SPAGHETTI_CARBONARA', 'Takeaway', 30.0),
(@tid, 'ITEM', 'SPAGHETTI_CARBONARA', 'Delivery', 30.0),
(@tid, 'ITEM', 'PENNE_TOSCANA', 'POS',      30.0),
(@tid, 'ITEM', 'PENNE_TOSCANA', 'Takeaway', 30.0),
(@tid, 'ITEM', 'PENNE_TOSCANA', 'Delivery', 30.0),
(@tid, 'ITEM', 'PENNE_DI_CAPO', 'POS',      30.0),
(@tid, 'ITEM', 'PENNE_DI_CAPO', 'Takeaway', 30.0),
(@tid, 'ITEM', 'PENNE_DI_CAPO', 'Delivery', 30.0),
(@tid, 'ITEM', 'PENNE_AMATRICIANA', 'POS',      30.0),
(@tid, 'ITEM', 'PENNE_AMATRICIANA', 'Takeaway', 30.0),
(@tid, 'ITEM', 'PENNE_AMATRICIANA', 'Delivery', 30.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_SORRENTINA', 'POS',      35.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_SORRENTINA', 'Takeaway', 35.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_SORRENTINA', 'Delivery', 35.0),
(@tid, 'ITEM', 'ZAPIEKANKA_MAKARONOWA', 'POS',      42.0),
(@tid, 'ITEM', 'ZAPIEKANKA_MAKARONOWA', 'Takeaway', 42.0),
(@tid, 'ITEM', 'ZAPIEKANKA_MAKARONOWA', 'Delivery', 42.0),
(@tid, 'ITEM', 'PENNE_ARABIATA', 'POS',      28.0),
(@tid, 'ITEM', 'PENNE_ARABIATA', 'Takeaway', 28.0),
(@tid, 'ITEM', 'PENNE_ARABIATA', 'Delivery', 28.0),
(@tid, 'ITEM', 'PENNE_SPINACI', 'POS',      30.0),
(@tid, 'ITEM', 'PENNE_SPINACI', 'Takeaway', 30.0),
(@tid, 'ITEM', 'PENNE_SPINACI', 'Delivery', 30.0),
(@tid, 'ITEM', 'PENNE_POLLO_CON_BROCCOLI', 'POS',      30.0),
(@tid, 'ITEM', 'PENNE_POLLO_CON_BROCCOLI', 'Takeaway', 30.0),
(@tid, 'ITEM', 'PENNE_POLLO_CON_BROCCOLI', 'Delivery', 30.0),
(@tid, 'ITEM', 'TAGLIATELLE_AL_TONNO', 'POS',      36.0),
(@tid, 'ITEM', 'TAGLIATELLE_AL_TONNO', 'Takeaway', 36.0),
(@tid, 'ITEM', 'TAGLIATELLE_AL_TONNO', 'Delivery', 36.0),
(@tid, 'ITEM', 'TAGLIATELLE_VERDE', 'POS',      30.0),
(@tid, 'ITEM', 'TAGLIATELLE_VERDE', 'Takeaway', 30.0),
(@tid, 'ITEM', 'TAGLIATELLE_VERDE', 'Delivery', 30.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_TOSCANA', 'POS',      35.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_TOSCANA', 'Takeaway', 35.0),
(@tid, 'ITEM', 'GNOCCHI_ALLA_TOSCANA', 'Delivery', 35.0),
(@tid, 'ITEM', 'SALATKA_STEAKY', 'POS',      34.0),
(@tid, 'ITEM', 'SALATKA_STEAKY', 'Takeaway', 34.0),
(@tid, 'ITEM', 'SALATKA_STEAKY', 'Delivery', 34.0),
(@tid, 'ITEM', 'SALATKA_CEZAR', 'POS',      29.0),
(@tid, 'ITEM', 'SALATKA_CEZAR', 'Takeaway', 29.0),
(@tid, 'ITEM', 'SALATKA_CEZAR', 'Delivery', 29.0),
(@tid, 'ITEM', 'SALATKA_FORNO', 'POS',      31.0),
(@tid, 'ITEM', 'SALATKA_FORNO', 'Takeaway', 31.0),
(@tid, 'ITEM', 'SALATKA_FORNO', 'Delivery', 31.0),
(@tid, 'ITEM', 'ZAPIEKANKA_KLASYCZNA', 'POS',      19.0),
(@tid, 'ITEM', 'ZAPIEKANKA_KLASYCZNA', 'Takeaway', 19.0),
(@tid, 'ITEM', 'ZAPIEKANKA_KLASYCZNA', 'Delivery', 19.0),
(@tid, 'ITEM', 'ZAPIEKANKA_AMERICANO', 'POS',      25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_AMERICANO', 'Takeaway', 25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_AMERICANO', 'Delivery', 25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_FORNO', 'POS',      25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_FORNO', 'Takeaway', 25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_FORNO', 'Delivery', 25.0),
(@tid, 'ITEM', 'ZAPIEKANKA_Z_SZYNKA', 'POS',      22.0),
(@tid, 'ITEM', 'ZAPIEKANKA_Z_SZYNKA', 'Takeaway', 22.0),
(@tid, 'ITEM', 'ZAPIEKANKA_Z_SZYNKA', 'Delivery', 22.0),
(@tid, 'ITEM', 'ZUPA_KREM', 'POS',      16.0),
(@tid, 'ITEM', 'ZUPA_KREM', 'Takeaway', 16.0),
(@tid, 'ITEM', 'ZUPA_KREM', 'Delivery', 16.0),
(@tid, 'ITEM', 'PIKANTNE_SKRZYDELKA_8SZT', 'POS',      24.0),
(@tid, 'ITEM', 'PIKANTNE_SKRZYDELKA_8SZT', 'Takeaway', 24.0),
(@tid, 'ITEM', 'PIKANTNE_SKRZYDELKA_8SZT', 'Delivery', 24.0),
(@tid, 'ITEM', 'KREWETKI_W_TEMPURZE_8SZT', 'POS',      36.0),
(@tid, 'ITEM', 'KREWETKI_W_TEMPURZE_8SZT', 'Takeaway', 36.0),
(@tid, 'ITEM', 'KREWETKI_W_TEMPURZE_8SZT', 'Delivery', 36.0),
(@tid, 'ITEM', 'KRAZKI_CEBULOWE_10SZT', 'POS',      20.0),
(@tid, 'ITEM', 'KRAZKI_CEBULOWE_10SZT', 'Takeaway', 20.0),
(@tid, 'ITEM', 'KRAZKI_CEBULOWE_10SZT', 'Delivery', 20.0),
(@tid, 'ITEM', 'ZEBERKO_BBQ', 'POS',      36.0),
(@tid, 'ITEM', 'ZEBERKO_BBQ', 'Takeaway', 36.0),
(@tid, 'ITEM', 'ZEBERKO_BBQ', 'Delivery', 36.0),
(@tid, 'ITEM', 'SUROWKA', 'POS',      8.0),
(@tid, 'ITEM', 'SUROWKA', 'Takeaway', 8.0),
(@tid, 'ITEM', 'SUROWKA', 'Delivery', 8.0),
(@tid, 'ITEM', 'FRYTKI', 'POS',      12.0),
(@tid, 'ITEM', 'FRYTKI', 'Takeaway', 12.0),
(@tid, 'ITEM', 'FRYTKI', 'Delivery', 12.0),
(@tid, 'ITEM', 'MAKARON_KIDS_POMODORO', 'POS',      18.0),
(@tid, 'ITEM', 'MAKARON_KIDS_POMODORO', 'Takeaway', 18.0),
(@tid, 'ITEM', 'MAKARON_KIDS_POMODORO', 'Delivery', 18.0),
(@tid, 'ITEM', 'POLEDWICE_W_TEMPURZE_4SZT', 'POS',      24.0),
(@tid, 'ITEM', 'POLEDWICE_W_TEMPURZE_4SZT', 'Takeaway', 24.0),
(@tid, 'ITEM', 'POLEDWICE_W_TEMPURZE_4SZT', 'Delivery', 24.0),
(@tid, 'ITEM', 'KIDS_FRYTKI', 'POS',      9.0),
(@tid, 'ITEM', 'KIDS_FRYTKI', 'Takeaway', 9.0),
(@tid, 'ITEM', 'KIDS_FRYTKI', 'Delivery', 9.0),
(@tid, 'ITEM', 'MAKARON_KIDS_BOLOGNESE', 'POS',      20.0),
(@tid, 'ITEM', 'MAKARON_KIDS_BOLOGNESE', 'Takeaway', 20.0),
(@tid, 'ITEM', 'MAKARON_KIDS_BOLOGNESE', 'Delivery', 20.0),
(@tid, 'ITEM', 'SZYSZKI_ZIEMNIACZANE_10SZT', 'POS',      10.0),
(@tid, 'ITEM', 'SZYSZKI_ZIEMNIACZANE_10SZT', 'Takeaway', 10.0),
(@tid, 'ITEM', 'SZYSZKI_ZIEMNIACZANE_10SZT', 'Delivery', 10.0),
(@tid, 'ITEM', 'NUGGETSY_8SZT', 'POS',      20.0),
(@tid, 'ITEM', 'NUGGETSY_8SZT', 'Takeaway', 20.0),
(@tid, 'ITEM', 'NUGGETSY_8SZT', 'Delivery', 20.0),
(@tid, 'ITEM', 'ROCKSTAR', 'POS',      9.0),
(@tid, 'ITEM', 'ROCKSTAR', 'Takeaway', 9.0),
(@tid, 'ITEM', 'ROCKSTAR', 'Delivery', 9.0),
(@tid, 'ITEM', 'PEPSI', 'POS',      8.0),
(@tid, 'ITEM', 'PEPSI', 'Takeaway', 8.0),
(@tid, 'ITEM', 'PEPSI', 'Delivery', 8.0),
(@tid, 'ITEM', 'LIPTON', 'POS',      8.0),
(@tid, 'ITEM', 'LIPTON', 'Takeaway', 8.0),
(@tid, 'ITEM', 'LIPTON', 'Delivery', 8.0),
(@tid, 'ITEM', '7UP', 'POS',      8.0),
(@tid, 'ITEM', '7UP', 'Takeaway', 8.0),
(@tid, 'ITEM', '7UP', 'Delivery', 8.0),
(@tid, 'ITEM', 'WODA', 'POS',      6.0),
(@tid, 'ITEM', 'WODA', 'Takeaway', 6.0),
(@tid, 'ITEM', 'WODA', 'Delivery', 6.0),
(@tid, 'ITEM', 'MIRINDA', 'POS',      8.0),
(@tid, 'ITEM', 'MIRINDA', 'Takeaway', 8.0),
(@tid, 'ITEM', 'MIRINDA', 'Delivery', 8.0),
(@tid, 'ITEM', 'SOK_TOMA', 'POS',      6.0),
(@tid, 'ITEM', 'SOK_TOMA', 'Takeaway', 6.0),
(@tid, 'ITEM', 'SOK_TOMA', 'Delivery', 6.0),
(@tid, 'ITEM', 'LEMONIADA', 'POS',      20.0),
(@tid, 'ITEM', 'LEMONIADA', 'Takeaway', 20.0),
(@tid, 'ITEM', 'LEMONIADA', 'Delivery', 20.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOW_WANILIOWA', 'POS',      5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOW_WANILIOWA', 'Takeaway', 5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOW_WANILIOWA', 'Delivery', 5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_JAGODOWA', 'POS',      5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_JAGODOWA', 'Takeaway', 5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_JAGODOWA', 'Delivery', 5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_CZEKOLADOWA', 'POS',      5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_CZEKOLADOWA', 'Takeaway', 5.0),
(@tid, 'ITEM', 'GALKA_SMIETANKOWO_CZEKOLADOWA', 'Delivery', 5.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_NUTELLA_I_MASCARPONE', 'POS',      14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_NUTELLA_I_MASCARPONE', 'Takeaway', 14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_NUTELLA_I_MASCARPONE', 'Delivery', 14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_MALINAMI_I_MASCARPONE', 'POS',      14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_MALINAMI_I_MASCARPONE', 'Takeaway', 14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_MALINAMI_I_MASCARPONE', 'Delivery', 14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_JABLKIEM_I_CYNAMONEM', 'POS',      14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_JABLKIEM_I_CYNAMONEM', 'Takeaway', 14.0),
(@tid, 'ITEM', 'MINI_CALZONE_Z_JABLKIEM_I_CYNAMONEM', 'Delivery', 14.0),
(@tid, 'ITEM', 'SOS_CZOSNKOWY', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_CZOSNKOWY', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_CZOSNKOWY', 'Delivery', 3.5),
(@tid, 'ITEM', 'SOS_POMIDOROWY', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_POMIDOROWY', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_POMIDOROWY', 'Delivery', 3.5),
(@tid, 'ITEM', 'SOS_MEKSYKANSKI', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_MEKSYKANSKI', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_MEKSYKANSKI', 'Delivery', 3.5),
(@tid, 'ITEM', 'SOS_1000_WYSP', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_1000_WYSP', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_1000_WYSP', 'Delivery', 3.5),
(@tid, 'ITEM', 'SOS_OSTRY', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_OSTRY', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_OSTRY', 'Delivery', 3.5),
(@tid, 'ITEM', 'SOS_BBQ', 'POS',      3.5),
(@tid, 'ITEM', 'SOS_BBQ', 'Takeaway', 3.5),
(@tid, 'ITEM', 'SOS_BBQ', 'Delivery', 3.5),
(@tid, 'ITEM', 'KETCHUP', 'POS',      3.5),
(@tid, 'ITEM', 'KETCHUP', 'Takeaway', 3.5),
(@tid, 'ITEM', 'KETCHUP', 'Delivery', 3.5),
(@tid, 'ITEM', 'MAJONEZ', 'POS',      3.5),
(@tid, 'ITEM', 'MAJONEZ', 'Takeaway', 3.5),
(@tid, 'ITEM', 'MAJONEZ', 'Delivery', 3.5),
(@tid, 'ITEM', 'GYROS_ROLLO_XXL', 'POS',      26.0),
(@tid, 'ITEM', 'GYROS_ROLLO_XXL', 'Takeaway', 26.0),
(@tid, 'ITEM', 'GYROS_ROLLO_XXL', 'Delivery', 26.0),
(@tid, 'ITEM', 'VEGE_ROLLO_XXL', 'POS',      21.0),
(@tid, 'ITEM', 'VEGE_ROLLO_XXL', 'Takeaway', 21.0),
(@tid, 'ITEM', 'VEGE_ROLLO_XXL', 'Delivery', 21.0),
(@tid, 'ITEM', 'GYROS_NA_TALERZU', 'POS',      26.0),
(@tid, 'ITEM', 'GYROS_NA_TALERZU', 'Takeaway', 26.0),
(@tid, 'ITEM', 'GYROS_NA_TALERZU', 'Delivery', 26.0),
(@tid, 'ITEM', 'GYROS_FORNO', 'POS',      29.0),
(@tid, 'ITEM', 'GYROS_FORNO', 'Takeaway', 29.0),
(@tid, 'ITEM', 'GYROS_FORNO', 'Delivery', 29.0),
(@tid, 'ITEM', 'TYSKIE_Z_KIJA', 'POS',      12.0),
(@tid, 'ITEM', 'TYSKIE_Z_KIJA', 'Takeaway', 12.0),
(@tid, 'ITEM', 'TYSKIE_Z_KIJA', 'Delivery', 12.0),
(@tid, 'ITEM', 'HARDMADE_400ML', 'POS',      12.0),
(@tid, 'ITEM', 'HARDMADE_400ML', 'Takeaway', 12.0),
(@tid, 'ITEM', 'HARDMADE_400ML', 'Delivery', 12.0),
(@tid, 'ITEM', 'PERONI_330ML', 'POS',      12.0),
(@tid, 'ITEM', 'PERONI_330ML', 'Takeaway', 12.0),
(@tid, 'ITEM', 'PERONI_330ML', 'Delivery', 12.0),
(@tid, 'ITEM', 'LECH_PILS_500ML', 'POS',      11.0),
(@tid, 'ITEM', 'LECH_PILS_500ML', 'Takeaway', 11.0),
(@tid, 'ITEM', 'LECH_PILS_500ML', 'Delivery', 11.0),
(@tid, 'ITEM', 'LECH_PREMIUM_500ML', 'POS',      11.0),
(@tid, 'ITEM', 'LECH_PREMIUM_500ML', 'Takeaway', 11.0),
(@tid, 'ITEM', 'LECH_PREMIUM_500ML', 'Delivery', 11.0),
(@tid, 'ITEM', 'LECH_FREE_00', 'POS',      12.0),
(@tid, 'ITEM', 'LECH_FREE_00', 'Takeaway', 12.0),
(@tid, 'ITEM', 'LECH_FREE_00', 'Delivery', 12.0),
(@tid, 'ITEM', 'PERONI_00_330ML', 'POS',      12.0),
(@tid, 'ITEM', 'PERONI_00_330ML', 'Takeaway', 12.0),
(@tid, 'ITEM', 'PERONI_00_330ML', 'Delivery', 12.0),
(@tid, 'ITEM', 'KSIAZECE_500ML', 'POS',      16.0),
(@tid, 'ITEM', 'KSIAZECE_500ML', 'Takeaway', 16.0),
(@tid, 'ITEM', 'KSIAZECE_500ML', 'Delivery', 16.0),
(@tid, 'ITEM', 'PILSNER_URQUELL_500ML', 'POS',      18.0),
(@tid, 'ITEM', 'PILSNER_URQUELL_500ML', 'Takeaway', 18.0),
(@tid, 'ITEM', 'PILSNER_URQUELL_500ML', 'Delivery', 18.0),
(@tid, 'ITEM', 'HARDMADE_FREE_400ML', 'POS',      12.0),
(@tid, 'ITEM', 'HARDMADE_FREE_400ML', 'Takeaway', 12.0),
(@tid, 'ITEM', 'HARDMADE_FREE_400ML', 'Delivery', 12.0),
(@tid, 'ITEM', 'PIZZERINKA_CEBULARZ', 'POS',      10.0),
(@tid, 'ITEM', 'PIZZERINKA_CEBULARZ', 'Takeaway', 10.0),
(@tid, 'ITEM', 'PIZZERINKA_CEBULARZ', 'Delivery', 10.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_30CM', 'POS',      41.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_30CM', 'Takeaway', 41.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_30CM', 'Delivery', 41.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_37CM', 'POS',      47.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_37CM', 'Takeaway', 47.0),
(@tid, 'ITEM', 'PIZZA_ZIMOWA_37CM', 'Delivery', 47.0),
(@tid, 'ITEM', 'PIZZERINKA_PIECZARA', 'POS',      10.0),
(@tid, 'ITEM', 'PIZZERINKA_PIECZARA', 'Takeaway', 10.0),
(@tid, 'ITEM', 'PIZZERINKA_PIECZARA', 'Delivery', 10.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_30CM', 'POS',      35.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_30CM', 'Takeaway', 35.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_30CM', 'Delivery', 35.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_37CM', 'POS',      42.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_37CM', 'Takeaway', 42.0),
(@tid, 'ITEM', 'PIZZA_EL_POLAKO_37CM', 'Delivery', 42.0),
(@tid, 'ITEM', 'ZESTAW_MALEGO_PIZZAIOLO', 'POS',      40.0),
(@tid, 'ITEM', 'ZESTAW_MALEGO_PIZZAIOLO', 'Takeaway', 40.0),
(@tid, 'ITEM', 'ZESTAW_MALEGO_PIZZAIOLO', 'Delivery', 40.0),
(@tid, 'ITEM', 'HAWAJSKI_DIABELEK', 'POS',      30.0),
(@tid, 'ITEM', 'HAWAJSKI_DIABELEK', 'Takeaway', 30.0),
(@tid, 'ITEM', 'HAWAJSKI_DIABELEK', 'Delivery', 30.0),
(@tid, 'ITEM', 'HERBATA_MALINOWA_ROZGRZEWKA', 'POS',      16.0),
(@tid, 'ITEM', 'HERBATA_MALINOWA_ROZGRZEWKA', 'Takeaway', 16.0),
(@tid, 'ITEM', 'HERBATA_MALINOWA_ROZGRZEWKA', 'Delivery', 16.0),
(@tid, 'ITEM', 'DZBANEK_TLOCZONEGO_SOKU', 'POS',      30.0),
(@tid, 'ITEM', 'DZBANEK_TLOCZONEGO_SOKU', 'Takeaway', 30.0),
(@tid, 'ITEM', 'DZBANEK_TLOCZONEGO_SOKU', 'Delivery', 30.0),
(@tid, 'ITEM', 'CAMEMBERT_W_SWIEZO_WYPIEKANYM_CHLEBIE', 'POS',      24.0),
(@tid, 'ITEM', 'CAMEMBERT_W_SWIEZO_WYPIEKANYM_CHLEBIE', 'Takeaway', 24.0),
(@tid, 'ITEM', 'CAMEMBERT_W_SWIEZO_WYPIEKANYM_CHLEBIE', 'Delivery', 24.0),
(@tid, 'ITEM', 'SCHAB_ZE_SLIWKA_W_BOCZKU', 'POS',      39.0),
(@tid, 'ITEM', 'SCHAB_ZE_SLIWKA_W_BOCZKU', 'Takeaway', 39.0),
(@tid, 'ITEM', 'SCHAB_ZE_SLIWKA_W_BOCZKU', 'Delivery', 39.0),
(@tid, 'ITEM', 'FOCACCIA_Z_ZURAWINA_I_CAMEMBERT', 'POS',      29.0),
(@tid, 'ITEM', 'FOCACCIA_Z_ZURAWINA_I_CAMEMBERT', 'Takeaway', 29.0),
(@tid, 'ITEM', 'FOCACCIA_Z_ZURAWINA_I_CAMEMBERT', 'Delivery', 29.0),
(@tid, 'ITEM', 'ZIMOWA_SALATKA', 'POS',      30.0),
(@tid, 'ITEM', 'ZIMOWA_SALATKA', 'Takeaway', 30.0),
(@tid, 'ITEM', 'ZIMOWA_SALATKA', 'Delivery', 30.0),
(@tid, 'ITEM', 'CALZONE_Z_BARSZCZEM', 'POS',      23.0),
(@tid, 'ITEM', 'CALZONE_Z_BARSZCZEM', 'Takeaway', 23.0),
(@tid, 'ITEM', 'CALZONE_Z_BARSZCZEM', 'Delivery', 23.0),
(@tid, 'ITEM', 'GNOCCHI_ZE_SCHABEM', 'POS',      36.0),
(@tid, 'ITEM', 'GNOCCHI_ZE_SCHABEM', 'Takeaway', 36.0),
(@tid, 'ITEM', 'GNOCCHI_ZE_SCHABEM', 'Delivery', 36.0),
(@tid, 'ITEM', 'HERBATA_Z_POKRZYWY', 'POS',      14.0),
(@tid, 'ITEM', 'HERBATA_Z_POKRZYWY', 'Takeaway', 14.0),
(@tid, 'ITEM', 'HERBATA_Z_POKRZYWY', 'Delivery', 14.0),
(@tid, 'ITEM', 'GRZANIEC_Z_ZURAWINA', 'POS',      18.0),
(@tid, 'ITEM', 'GRZANIEC_Z_ZURAWINA', 'Takeaway', 18.0),
(@tid, 'ITEM', 'GRZANIEC_Z_ZURAWINA', 'Delivery', 18.0);

-- ── 2.10 sh_modifier_groups ────────────────────────────────────────────
INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Sos bazowy', 'SOS_BAZOWY', 1, 1, 1, 1, 0);
SET @grp_SOS_BAZOWY = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatkowe warzywa', 'DODA_WARZYWA', 0, 0, 0, 1, 0);
SET @grp_DODA_WARZYWA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatkowe mięsa', 'DODA_MIESA', 0, 0, 0, 1, 0);
SET @grp_DODA_MIESA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatkowe sery', 'DODA_SERY', 0, 0, 0, 1, 0);
SET @grp_DODA_SERY = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Pozostałe pizza', 'POZOSTALE_PIZZA', 0, 0, 0, 1, 0);
SET @grp_POZOSTALE_PIZZA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Sosy dodatkowe', 'SOSY_DODATKOWE', 0, 3, 0, 1, 0);
SET @grp_SOSY_DODATKOWE = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Wybierz 2 połówki', 'HALF_HALF', 2, 2, 1, 1, 0);
SET @grp_HALF_HALF = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatki ogólne', 'DODA_OGOLNE', 0, 0, 0, 1, 0);
SET @grp_DODA_OGOLNE = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Sosy do panini', 'SOSY_PANINI', 1, 1, 1, 1, 0);
SET @grp_SOSY_PANINI = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Gazowanie', 'GAZ_NIEGAZ', 0, 1, 0, 1, 0);
SET @grp_GAZ_NIEGAZ = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Rodzaj soku', 'RODZAJ_SOKU', 1, 1, 1, 1, 0);
SET @grp_RODZAJ_SOKU = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Gałki lodów', 'GALKI_LODOW', 0, 3, 0, 1, 0);
SET @grp_GALKI_LODOW = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Usuń składnik', 'USUN_SKLADNIK', 0, 0, 0, 1, 0);
SET @grp_USUN_SKLADNIK = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Rodzaj ciasta', 'RODZAJ_CIASTA', 0, 1, 0, 1, 0);
SET @grp_RODZAJ_CIASTA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Ser do focacci', 'SER_FOCACCI', 0, 1, 0, 1, 0);
SET @grp_SER_FOCACCI = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatki do piwa', 'DODA_PIWA', 0, 1, 0, 1, 0);
SET @grp_DODA_PIWA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Dodatki do gyrosa', 'DODA_GYROS', 0, 0, 0, 1, 0);
SET @grp_DODA_GYROS = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Promocja', 'PROMOCJA', 0, 1, 0, 1, 0);
SET @grp_PROMOCJA = LAST_INSERT_ID();

INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection,
  allow_multi_qty, is_active, is_deleted)
VALUES (@tid, 'Rodzaj calzone', 'RODZAJ_CALZONE', 1, 1, 1, 1, 0);
SET @grp_RODZAJ_CALZONE = LAST_INSERT_ID();

-- ── 2.11 sh_modifiers ───────────────────────────────────────────────────
-- Modifiers for group: Sos bazowy
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_SOS_BAZOWY, 'pomidorowy', 'POMIDOROWY', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOS_BAZOWY, 'BBQ', 'BBQ', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOS_BAZOWY, 'śmietankowy', 'SMIETANKOWY', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- Modifiers for group: Dodatkowe warzywa
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_WARZYWA, 'pieczarki', 'PIECZARKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'czerwona cebulka', 'CZERWONA_CEBULKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'papryka', 'PAPRYKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'oliwki', 'OLIWKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'pomidorki', 'POMIDORKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'kukurydza', 'KUKURYDZA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'smażone pieczarki', 'SMAZONE_PIECZARKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'ananas', 'ANANAS', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'chilli', 'CHILLI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'jalapeno', 'JALAPENO', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'ogórek konserwowy', 'OGOREK_KONSERWOWY', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'szpinak', 'SZPINAK', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'brokuły', 'BROKULY', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'rukola', 'RUKOLA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'chipsy ziemniaczane', 'CHIPSY_ZIEMNIACZANE', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'cukinia', 'CUKINIA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'sałata', 'SALATA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'kapusta pekińska', 'KAPUSTA_PEKINSKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'świeża bazylia', 'SWIEZA_BAZYLIA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'gruszka', 'GRUSZKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_WARZYWA, 'frytki', 'FRYTKI', 'ADD', NULL, 0, 0, 3.0, 0, 1);

-- Modifiers for group: Dodatkowe mięsa
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_MIESA, 'kurczak', 'KURCZAK', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'drobiowy kebab', 'DROBIOWY_KEBAB', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'szynka', 'SZYNKA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'salami', 'SALAMI', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'boczek', 'BOCZEK', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'włoska kiełbasa', 'WLOSKA_KIELBASA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'rwana wieprzowina', 'RWANA_WIEPRZOWINA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'mięso wołowe', 'MIESO_WOLOWE', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_MIESA, 'salami picante', 'SALAMI_PICANTE', 'ADD', NULL, 0, 0, 4.0, 0, 1);

-- Modifiers for group: Dodatkowe sery
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_SERY, 'mozzarella', 'MOZZARELLA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_SERY, 'ricotta', 'RICOTTA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_SERY, 'feta', 'FETA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_SERY, 'edamski', 'EDAMSKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_SERY, 'parmezan', 'PARMEZAN', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_SERY, 'gorgonzola', 'GORGONZOLA', 'ADD', NULL, 0, 0, 3.0, 0, 1);

-- Modifiers for group: Pozostałe pizza
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_POZOSTALE_PIZZA, 'mozzarella di buffalo', 'MOZZARELLA_DI_BUFFALO', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'Nduja', 'NDUJA', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'stek wołowy', 'STEK_WOLOWY', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'suszone pomidorki', 'SUSZONE_POMIDORKI', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'fileciki anchois', 'FILECIKI_ANCHOIS', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'szynka parmeńska', 'SZYNKA_PARMENSKA', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_POZOSTALE_PIZZA, 'tuńczyk', 'TUNCZYK', 'ADD', NULL, 0, 0, 5.0, 0, 1);

-- Modifiers for group: Sosy dodatkowe
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_SOSY_DODATKOWE, 'Pomidorowy', 'POMIDOROWY', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, 'Czosnkowy', 'CZOSNKOWY', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, 'Meksykański', 'MEKSYKANSKI', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, 'Ostry', 'OSTRY', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, 'BBQ', 'BBQ', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, '1000wysp', '1000WYSP', 'ADD', NULL, 0, 0, 3.5, 0, 1),
(@grp_SOSY_DODATKOWE, 'Ketchup', 'KETCHUP', 'ADD', NULL, 0, 0, 3.5, 0, 1);

-- Modifiers for group: Wybierz 2 połówki
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_HALF_HALF, 'MARGHERITA', 'MARGHERITA', 'ADD', NULL, 0, 0, 27.0, 0, 1),
(@grp_HALF_HALF, 'MARGHERITA ITALIANO', 'MARGHERITA_ITALIANO', 'ADD', NULL, 0, 0, 33.0, 0, 1),
(@grp_HALF_HALF, 'DI PARMA', 'DI_PARMA', 'ADD', NULL, 0, 0, 36.0, 0, 1),
(@grp_HALF_HALF, 'DIAVOLA', 'DIAVOLA', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'FUNGHI CON SZYNKA', 'FUNGHI_CON_SZYNKA', 'ADD', NULL, 0, 0, 34.0, 0, 1),
(@grp_HALF_HALF, 'DUE SALAMI', 'DUE_SALAMI', 'ADD', NULL, 0, 0, 34.0, 0, 1),
(@grp_HALF_HALF, 'MONTANARA', 'MONTANARA', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'CAPRICCIOSA', 'CAPRICCIOSA', 'ADD', NULL, 0, 0, 36.0, 0, 1),
(@grp_HALF_HALF, 'VULCANO', 'VULCANO', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'DI CARNE', 'DI_CARNE', 'ADD', NULL, 0, 0, 38.0, 0, 1),
(@grp_HALF_HALF, 'POPEY', 'POPEY', 'ADD', NULL, 0, 0, 36.0, 0, 1),
(@grp_HALF_HALF, 'AL.TONNO', 'AL_TONNO', 'ADD', NULL, 0, 0, 40.0, 0, 1),
(@grp_HALF_HALF, 'POLLO', 'POLLO', 'ADD', NULL, 0, 0, 36.0, 0, 1),
(@grp_HALF_HALF, 'VERONA', 'VERONA', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'AL GYROSO', 'AL_GYROSO', 'ADD', NULL, 0, 0, 39.0, 0, 1),
(@grp_HALF_HALF, 'RAGU', 'RAGU', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'FRITTE FORMAGGIO', 'FRITTE_FORMAGGIO', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'VERDURA', 'VERDURA', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'STEAKY', 'STEAKY', 'ADD', NULL, 0, 0, 40.0, 0, 1),
(@grp_HALF_HALF, 'ETNA', 'ETNA', 'ADD', NULL, 0, 0, 38.0, 0, 1),
(@grp_HALF_HALF, 'MARGHERITA ITALIANO BIANCA', 'MARGHERITA_ITALIANO_BIANCA', 'ADD', NULL, 0, 0, 34.0, 0, 1),
(@grp_HALF_HALF, 'QUATTRO FORMAGGI', 'QUATTRO_FORMAGGI', 'ADD', NULL, 0, 0, 38.0, 0, 1),
(@grp_HALF_HALF, 'CARBONARA', 'CARBONARA', 'ADD', NULL, 0, 0, 36.0, 0, 1),
(@grp_HALF_HALF, 'CON BROCCOLI', 'CON_BROCCOLI', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'VINCI', 'VINCI', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'CEZAR', 'CEZAR', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'MAFIA', 'MAFIA', 'ADD', NULL, 0, 0, 37.0, 0, 1),
(@grp_HALF_HALF, 'NEW YORK', 'NEW_YORK', 'ADD', NULL, 0, 0, 35.0, 0, 1),
(@grp_HALF_HALF, 'AMERICANO', 'AMERICANO', 'ADD', NULL, 0, 0, 34.0, 0, 1),
(@grp_HALF_HALF, 'AMERICANO PICANTE', 'AMERICANO_PICANTE', 'ADD', NULL, 0, 0, 35.0, 0, 1);

-- Modifiers for group: Dodatki ogólne
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_OGOLNE, 'pieczarki', 'PIECZARKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'czerwona cebulka', 'CZERWONA_CEBULKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'papryka', 'PAPRYKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'oliwki', 'OLIWKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'pomidorki', 'POMIDORKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'kukurydza', 'KUKURYDZA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'smażone pieczarki', 'SMAZONE_PIECZARKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'ananas', 'ANANAS', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'chilli', 'CHILLI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'jalapeno', 'JALAPENO', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'ogórek konserwowy', 'OGOREK_KONSERWOWY', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'szpinak', 'SZPINAK', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'brokuły', 'BROKULY', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'rukola', 'RUKOLA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'chipsy ziemniaczane', 'CHIPSY_ZIEMNIACZANE', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'cukinia', 'CUKINIA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'sałata', 'SALATA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'kapusta pekińska', 'KAPUSTA_PEKINSKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'świeża bazylia', 'SWIEZA_BAZYLIA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'gruszka', 'GRUSZKA', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'frytki', 'FRYTKI', 'ADD', NULL, 0, 0, 3.0, 0, 1),
(@grp_DODA_OGOLNE, 'kurczak', 'KURCZAK', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'drobiowy kebab', 'DROBIOWY_KEBAB', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'szynka', 'SZYNKA', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'salami', 'SALAMI', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'boczek', 'BOCZEK', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'włoska kiełbasa', 'WLOSKA_KIELBASA', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'rwana wieprzowina', 'RWANA_WIEPRZOWINA', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'mięso wołowe', 'MIESO_WOLOWE', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'salami picante', 'SALAMI_PICANTE', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_DODA_OGOLNE, 'mozzarella', 'MOZZARELLA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'ricotta', 'RICOTTA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'feta', 'FETA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'edamski', 'EDAMSKI', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'parmezan', 'PARMEZAN', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'gorgonzola', 'GORGONZOLA', 'ADD', NULL, 0, 0, 4.0, 0, 1),
(@grp_DODA_OGOLNE, 'mozzarella di buffalo', 'MOZZARELLA_DI_BUFFALO', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'Nduja', 'NDUJA', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'stek wołowy', 'STEK_WOLOWY', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'suszone pomidorki', 'SUSZONE_POMIDORKI', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'fileciki anchois', 'FILECIKI_ANCHOIS', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'szynka parmeńska', 'SZYNKA_PARMENSKA', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_OGOLNE, 'tuńczyk', 'TUNCZYK', 'ADD', NULL, 0, 0, 6.0, 0, 1);

-- Modifiers for group: Sosy do panini
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_SOSY_PANINI, 'czosnkowy', 'CZOSNKOWY', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'pomidorowy', 'POMIDOROWY', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, '1000 wysp', '1000_WYSP', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'meksykański', 'MEKSYKANSKI', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'ostry', 'OSTRY', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'BBQ', 'BBQ', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'ketchup', 'KETCHUP', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'majonez', 'MAJONEZ', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_SOSY_PANINI, 'bez sosu', 'BEZ_SOSU', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- Modifiers for group: Gazowanie
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_GAZ_NIEGAZ, 'gaz', 'GAZ', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_GAZ_NIEGAZ, 'niegaz', 'NIEGAZ', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- Modifiers for group: Rodzaj soku
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_RODZAJ_SOKU, 'POMARAŃCZA', 'POMARANCZA', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_RODZAJ_SOKU, 'JABŁKO', 'JABLKO', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_RODZAJ_SOKU, 'Jabłko & Rabarbar', 'JABLKO_RABARBAR', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_RODZAJ_SOKU, 'Grzane Jabłko - Cynamon - Gożdziki', 'GRZANE_JABLKO_CYNAMON_GOZDZIKI', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- Modifiers for group: Gałki lodów
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_GALKI_LODOW, 'DODATKOWA GAŁKA LODU', 'DODATKOWA_GALKA_LODU', 'ADD', NULL, 0, 0, 5.0, 0, 1);

-- Modifiers for group: Usuń składnik
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_USUN_SKLADNIK, 'bez salami', 'BEZ_SALAMI', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_USUN_SKLADNIK, 'bez papryka', 'BEZ_PAPRYKA', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_USUN_SKLADNIK, 'bez pieczarki', 'BEZ_PIECZARKI', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_USUN_SKLADNIK, 'bez chilli', 'BEZ_CHILLI', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- Modifiers for group: Rodzaj ciasta
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_RODZAJ_CIASTA, 'Na grubym cieście', 'NA_GRUBYM_CIESCIE', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_RODZAJ_CIASTA, 'Na sezamowym cieście', 'NA_SEZAMOWYM_CIESCIE', 'ADD', NULL, 0, 0, 5.0, 0, 1),
(@grp_RODZAJ_CIASTA, 'W kształcie serca', 'W_KSZTALCIE_SERCA', 'ADD', NULL, 0, 0, 5.0, 0, 1);

-- Modifiers for group: Ser do focacci
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_SER_FOCACCI, 'mozzarella', 'MOZZARELLA', 'ADD', NULL, 0, 0, 3.0, 0, 1);

-- Modifiers for group: Dodatki do piwa
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_PIWA, 'sok', 'SOK', 'ADD', NULL, 0, 0, 1.0, 0, 1),
(@grp_DODA_PIWA, 'butelka', 'BUTELKA', 'ADD', NULL, 0, 0, 1.0, 0, 1);

-- Modifiers for group: Dodatki do gyrosa
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_DODA_GYROS, 'frytki', 'FRYTKI', 'ADD', NULL, 0, 0, 6.0, 0, 1),
(@grp_DODA_GYROS, 'mięso', 'MIESO', 'ADD', NULL, 0, 0, 8.0, 0, 1),
(@grp_DODA_GYROS, 'ser', 'SER', 'ADD', NULL, 0, 0, 4.0, 0, 1);

-- Modifiers for group: Promocja
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_PROMOCJA, 'MARGHERITA 30cm', 'MARGHERITA_30CM', 'ADD', NULL, 0, 0, 13.5, 0, 1);

-- Modifiers for group: Rodzaj calzone
INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku,
  linked_quantity, linked_waste_percent, price, is_default, is_active) VALUES
(@grp_RODZAJ_CALZONE, 'Z MIĘSEM', 'Z_MIESEM', 'ADD', NULL, 0, 0, 0.0, 0, 1),
(@grp_RODZAJ_CALZONE, 'Z KAPUSTĄ I GRZYBAMI', 'Z_KAPUSTA_I_GRZYBAMI', 'ADD', NULL, 0, 0, 0.0, 0, 1);

-- ── 2.12 sh_modifier_pricing (F-S2 size pricing) ───────────────────────
-- Size pricing for group: Dodatkowe warzywa
INSERT INTO sh_modifier_pricing (tenant_id, modifier_id, variant_option_id, price_grosze, is_deleted)
VALUES
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='PIECZARKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='PIECZARKI'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CZERWONA_CEBULKA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CZERWONA_CEBULKA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='PAPRYKA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='PAPRYKA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='OLIWKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='OLIWKI'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='POMIDORKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='POMIDORKI'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='KUKURYDZA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='KUKURYDZA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SMAZONE_PIECZARKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SMAZONE_PIECZARKI'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='ANANAS'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='ANANAS'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CHILLI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CHILLI'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='JALAPENO'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='JALAPENO'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='OGOREK_KONSERWOWY'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='OGOREK_KONSERWOWY'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SZPINAK'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SZPINAK'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='BROKULY'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='BROKULY'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='RUKOLA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='RUKOLA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CHIPSY_ZIEMNIACZANE'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CHIPSY_ZIEMNIACZANE'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CUKINIA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='CUKINIA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SALATA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SALATA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='KAPUSTA_PEKINSKA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='KAPUSTA_PEKINSKA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SWIEZA_BAZYLIA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='SWIEZA_BAZYLIA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='GRUSZKA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='GRUSZKA'), @opt_37cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='FRYTKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_WARZYWA AND ascii_key='FRYTKI'), @opt_37cm, 500, 0);

-- Size pricing for group: Dodatkowe mięsa
INSERT INTO sh_modifier_pricing (tenant_id, modifier_id, variant_option_id, price_grosze, is_deleted)
VALUES
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='KURCZAK'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='KURCZAK'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='DROBIOWY_KEBAB'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='DROBIOWY_KEBAB'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SZYNKA'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SZYNKA'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SALAMI'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SALAMI'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='BOCZEK'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='BOCZEK'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='WLOSKA_KIELBASA'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='WLOSKA_KIELBASA'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='RWANA_WIEPRZOWINA'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='RWANA_WIEPRZOWINA'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='MIESO_WOLOWE'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='MIESO_WOLOWE'), @opt_37cm, 600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SALAMI_PICANTE'), @opt_30cm, 400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_MIESA AND ascii_key='SALAMI_PICANTE'), @opt_37cm, 600, 0);

-- Size pricing for group: Dodatkowe sery
INSERT INTO sh_modifier_pricing (tenant_id, modifier_id, variant_option_id, price_grosze, is_deleted)
VALUES
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='MOZZARELLA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='MOZZARELLA'), @opt_37cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='RICOTTA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='RICOTTA'), @opt_37cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='FETA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='FETA'), @opt_37cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='EDAMSKI'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='EDAMSKI'), @opt_37cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='PARMEZAN'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='PARMEZAN'), @opt_37cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='GORGONZOLA'), @opt_30cm, 300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_DODA_SERY AND ascii_key='GORGONZOLA'), @opt_37cm, 300, 0);

-- Size pricing for group: Pozostałe pizza
INSERT INTO sh_modifier_pricing (tenant_id, modifier_id, variant_option_id, price_grosze, is_deleted)
VALUES
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='MOZZARELLA_DI_BUFFALO'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='MOZZARELLA_DI_BUFFALO'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='NDUJA'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='NDUJA'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='STEK_WOLOWY'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='STEK_WOLOWY'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='SUSZONE_POMIDORKI'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='SUSZONE_POMIDORKI'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='FILECIKI_ANCHOIS'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='FILECIKI_ANCHOIS'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='SZYNKA_PARMENSKA'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='SZYNKA_PARMENSKA'), @opt_37cm, 700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='TUNCZYK'), @opt_30cm, 500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_POZOSTALE_PIZZA AND ascii_key='TUNCZYK'), @opt_37cm, 700, 0);

-- Size pricing for group: Wybierz 2 połówki
INSERT INTO sh_modifier_pricing (tenant_id, modifier_id, variant_option_id, price_grosze, is_deleted)
VALUES
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA'), @opt_30cm, 2700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA'), @opt_37cm, 1700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA_ITALIANO'), @opt_30cm, 3300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA_ITALIANO'), @opt_37cm, 2000, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DI_PARMA'), @opt_30cm, 3600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DI_PARMA'), @opt_37cm, 2150, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DIAVOLA'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DIAVOLA'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='FUNGHI_CON_SZYNKA'), @opt_30cm, 3400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='FUNGHI_CON_SZYNKA'), @opt_37cm, 2050, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DUE_SALAMI'), @opt_30cm, 3400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DUE_SALAMI'), @opt_37cm, 2050, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MONTANARA'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MONTANARA'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CAPRICCIOSA'), @opt_30cm, 3600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CAPRICCIOSA'), @opt_37cm, 2150, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VULCANO'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VULCANO'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DI_CARNE'), @opt_30cm, 3800, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='DI_CARNE'), @opt_37cm, 2250, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='POPEY'), @opt_30cm, 3600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='POPEY'), @opt_37cm, 2150, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AL_TONNO'), @opt_30cm, 4000, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AL_TONNO'), @opt_37cm, 2350, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='POLLO'), @opt_30cm, 3600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='POLLO'), @opt_37cm, 2150, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VERONA'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VERONA'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AL_GYROSO'), @opt_30cm, 3900, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AL_GYROSO'), @opt_37cm, 2300, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='RAGU'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='RAGU'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='FRITTE_FORMAGGIO'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='FRITTE_FORMAGGIO'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VERDURA'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VERDURA'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='STEAKY'), @opt_30cm, 4000, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='STEAKY'), @opt_37cm, 2350, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='ETNA'), @opt_30cm, 3800, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='ETNA'), @opt_37cm, 2250, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA_ITALIANO_BIANCA'), @opt_30cm, 3400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MARGHERITA_ITALIANO_BIANCA'), @opt_37cm, 2050, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='QUATTRO_FORMAGGI'), @opt_30cm, 3800, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='QUATTRO_FORMAGGI'), @opt_37cm, 2250, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CARBONARA'), @opt_30cm, 3600, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CARBONARA'), @opt_37cm, 2150, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CON_BROCCOLI'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CON_BROCCOLI'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VINCI'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='VINCI'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CEZAR'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='CEZAR'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MAFIA'), @opt_30cm, 3700, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='MAFIA'), @opt_37cm, 2200, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='NEW_YORK'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='NEW_YORK'), @opt_37cm, 2100, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AMERICANO'), @opt_30cm, 3400, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AMERICANO'), @opt_37cm, 2050, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AMERICANO_PICANTE'), @opt_30cm, 3500, 0),
(@tid, (SELECT id FROM sh_modifiers WHERE group_id=@grp_HALF_HALF AND ascii_key='AMERICANO_PICANTE'), @opt_37cm, 2100, 0);

-- ── 2.13 sh_item_modifiers (link items to modifier groups) ─────────────
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_GYROSO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AL_TONNO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='AMERICANO_PICANTE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='BOB_BUDOWNICZY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CAPRICCIOSA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CARBONARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CEZAR_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CON_BROCCOLI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_CARNE_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DI_PARMA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DIAVOLA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='DUE_SALAMI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ETNA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FRITTE_FORMAGGIO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FUNGHI_CON_SZYNKA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MAFIA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MARGHERITA_ITALIANO_BIANCA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MONTANARA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='NEW_YORK_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POLLO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POPEY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='POL_NA_POL_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='QUATTRO_FORMAGGI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='RAGU_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='STEAKY_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERDURA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VERONA_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VINCI_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_30CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOS_BAZOWY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_WARZYWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_MIESA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_SERY';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='POZOSTALE_PIZZA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_DODATKOWE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='HALF_HALF';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CIASTA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VULCANO_37CM'
  AND mg.tenant_id=@tid AND mg.ascii_key='USUN_SKLADNIK';

INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AMERICANO_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AMERICANO_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AMERICANO_PICANTE_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AMERICANO_PICANTE_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AL_GYROSO_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_AL_GYROSO_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_CEZAR_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_CEZAR_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_DI_CARNE_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_DI_CARNE_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_ITALIANO_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_ITALIANO_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_MAFIA_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_MAFIA_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_POLLO_PICANTE_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_POLLO_PICANTE_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_STEKY_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_STEKY_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_VERDURA_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_VERDURA_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_VEZUVIO_MALE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PANINI_VEZUVIO_DUZE'
  AND mg.tenant_id=@tid AND mg.ascii_key='SOSY_PANINI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FOCCACIA_ROSMARINO'
  AND mg.tenant_id=@tid AND mg.ascii_key='SER_FOCACCI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FOCCACIA_NOCCI'
  AND mg.tenant_id=@tid AND mg.ascii_key='SER_FOCACCI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='FOCCACIA_POMODORO'
  AND mg.tenant_id=@tid AND mg.ascii_key='SER_FOCACCI';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_DI_CARNE'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_VEZUVIO'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_POPEY'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_VEGETARIANO'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_CONTADINO'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_POLLO_PICANTE'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_ITALIANO'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_TEXAS'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='CALZONE_MAFIA'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_CALZONE';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ROCKSTAR'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='ROCKSTAR'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PEPSI'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PEPSI'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LIPTON'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LIPTON'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='7UP'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='7UP'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='WODA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='WODA'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MIRINDA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MIRINDA'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='SOK_TOMA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='SOK_TOMA'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LEMONIADA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GAZ_NIEGAZ';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LEMONIADA'
  AND mg.tenant_id=@tid AND mg.ascii_key='RODZAJ_SOKU';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GALKA_SMIETANKOW_WANILIOWA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GALKA_SMIETANKOWO_JAGODOWA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GALKA_SMIETANKOWO_CZEKOLADOWA'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MINI_CALZONE_Z_NUTELLA_I_MASCARPONE'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MINI_CALZONE_Z_MALINAMI_I_MASCARPONE'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='MINI_CALZONE_Z_JABLKIEM_I_CYNAMONEM'
  AND mg.tenant_id=@tid AND mg.ascii_key='GALKI_LODOW';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GYROS_ROLLO_XXL'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_GYROS';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='VEGE_ROLLO_XXL'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_GYROS';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GYROS_NA_TALERZU'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_GYROS';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='GYROS_FORNO'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_GYROS';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='TYSKIE_Z_KIJA'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='HARDMADE_400ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PERONI_330ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LECH_PILS_500ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LECH_PREMIUM_500ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='LECH_FREE_00'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PERONI_00_330ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='KSIAZECE_500ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='PILSNER_URQUELL_500ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';
INSERT IGNORE INTO sh_item_modifiers (item_id, group_id)
  SELECT mi.id, mg.id FROM sh_menu_items mi, sh_modifier_groups mg
  WHERE mi.tenant_id=@tid AND mi.ascii_key='HARDMADE_FREE_400ML'
  AND mg.tenant_id=@tid AND mg.ascii_key='DODA_PIWA';

-- ── 2.14 sh_recipes (heuristic) ───────────────────────────────────────
INSERT IGNORE INTO sh_recipes (tenant_id, menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_packaging)
VALUES
(@tid, 'AL_GYROSO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'AL_GYROSO', 'KEBAB_DROBIOWY', 0.08, 0, 0),
(@tid, 'AL_GYROSO', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'AL_GYROSO', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'AL_GYROSO', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'AL_GYROSO', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'AL_TONNO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'AL_TONNO', 'TUNCZYK', 0.06, 0, 0),
(@tid, 'AL_TONNO', 'ANCHOIS', 0.02, 0, 0),
(@tid, 'AL_TONNO', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'AMERICANO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'AMERICANO', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'AMERICANO', 'SALAMI', 0.06, 0, 0),
(@tid, 'AMERICANO', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'AMERICANO', 'KURCZAK', 0.08, 0, 0),
(@tid, 'AMERICANO', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'KURCZAK', 0.08, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'BOCZEK', 0.06, 0, 0),
(@tid, 'AMERICANO_PICANTE', 'JALAPENO', 0.01, 0, 0),
(@tid, 'BOB_BUDOWNICZY', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'BOB_BUDOWNICZY', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'BOB_BUDOWNICZY', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'CAPRICCIOSA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'CAPRICCIOSA', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'CAPRICCIOSA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'CAPRICCIOSA', 'OLIWKI', 0.03, 0, 0),
(@tid, 'CAPRICCIOSA', 'SZYNKA', 0.06, 0, 0),
(@tid, 'CAPRICCIOSA', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'CARBONARA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'CARBONARA', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'CARBONARA', 'BOCZEK', 0.06, 0, 0),
(@tid, 'CARBONARA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'CEZAR', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'CEZAR', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'CEZAR', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CEZAR', 'SALATA', 0.04, 0, 0),
(@tid, 'CEZAR', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'CEZAR', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'CON_BROCCOLI', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'CON_BROCCOLI', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'CON_BROCCOLI', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'CON_BROCCOLI', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CON_BROCCOLI', 'CHIPSY_ZIEMN', 0.06, 0, 0),
(@tid, 'CON_BROCCOLI', 'RICOTTA', 0.06, 0, 0),
(@tid, 'CON_BROCCOLI', 'POMIDORKI_SUSZE', 0.03, 0, 0),
(@tid, 'DI_CARNE', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'DI_CARNE', 'KEBAB_DROBIOWY', 0.08, 0, 0),
(@tid, 'DI_CARNE', 'SALAMI', 0.06, 0, 0),
(@tid, 'DI_CARNE', 'SZYNKA', 0.06, 0, 0),
(@tid, 'DI_PARMA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'DI_PARMA', 'SZYNKA_PARM', 0.06, 0, 0),
(@tid, 'DI_PARMA', 'RUKOLA', 0.04, 0, 0),
(@tid, 'DI_PARMA', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'DI_PARMA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'DIAVOLA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'DIAVOLA', 'SALAMI', 0.06, 0, 0),
(@tid, 'DIAVOLA', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'DIAVOLA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'DIAVOLA', 'CHILLI', 0.01, 0, 0),
(@tid, 'DUE_SALAMI', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'DUE_SALAMI', 'SALAMI', 0.06, 0, 0),
(@tid, 'DUE_SALAMI', 'CHIPSY_ZIEMN', 0.06, 0, 0),
(@tid, 'DUE_SALAMI', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'ETNA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'ETNA', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'ETNA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'ETNA', 'NDUJA', 0.04, 0, 0),
(@tid, 'ETNA', 'RICOTTA', 0.06, 0, 0),
(@tid, 'ETNA', 'TABASCO', 0.005, 0, 0),
(@tid, 'FRITTE_FORMAGGIO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'FRITTE_FORMAGGIO', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'FRITTE_FORMAGGIO', 'SALAMI', 0.06, 0, 0),
(@tid, 'FUNGHI_CON_SZYNKA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'FUNGHI_CON_SZYNKA', 'SZYNKA', 0.06, 0, 0),
(@tid, 'FUNGHI_CON_SZYNKA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'FUNGHI_CON_SZYNKA', 'RUKOLA', 0.04, 0, 0),
(@tid, 'MAFIA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'MAFIA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'MAFIA', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'MAFIA', 'RWANA_WIEPRZ', 0.08, 0, 0),
(@tid, 'MAFIA', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'MAFIA', 'JALAPENO', 0.01, 0, 0),
(@tid, 'MARGHERITA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'MARGHERITA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'MARGHERITA', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'MARGHERITA_ITALIANO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'MARGHERITA_ITALIANO', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'MARGHERITA_ITALIANO', 'BAZYLIA', 0.01, 0, 0),
(@tid, 'MARGHERITA_ITALIANO', 'OLIWA', 0.02, 0, 0),
(@tid, 'MARGHERITA_ITALIANO', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'MARGHERITA_ITALIANO_BIANCA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'MARGHERITA_ITALIANO_BIANCA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'MARGHERITA_ITALIANO_BIANCA', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'MARGHERITA_ITALIANO_BIANCA', 'BAZYLIA', 0.01, 0, 0),
(@tid, 'MARGHERITA_ITALIANO_BIANCA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'MONTANARA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'MONTANARA', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'MONTANARA', 'PIECZARKI_SMAZONE', 0.05, 0, 0),
(@tid, 'MONTANARA', 'KIELB_WLOSKA', 0.06, 0, 0),
(@tid, 'MONTANARA', 'NDUJA', 0.04, 0, 0),
(@tid, 'MONTANARA', 'POMIDORKI_SUSZE', 0.03, 0, 0),
(@tid, 'MONTANARA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'NEW_YORK', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'NEW_YORK', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'NEW_YORK', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'NEW_YORK', 'RWANA_WIEPRZ', 0.08, 0, 0),
(@tid, 'NEW_YORK', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'NEW_YORK', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'POLLO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'POLLO', 'KURCZAK', 0.08, 0, 0),
(@tid, 'POLLO', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'POLLO', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'POLLO', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'POPEY', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'POPEY', 'KURCZAK', 0.08, 0, 0),
(@tid, 'POPEY', 'BRUKOLY', 0.04, 0, 0),
(@tid, 'POPEY', 'SZPINAK', 0.04, 0, 0),
(@tid, 'POPEY', 'POMIDORKI_SUSZE', 0.03, 0, 0),
(@tid, 'POL_NA_POL', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'QUATTRO_FORMAGGI', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'QUATTRO_FORMAGGI', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'QUATTRO_FORMAGGI', 'GORGONZOLA', 0.04, 0, 0),
(@tid, 'QUATTRO_FORMAGGI', 'RICOTTA', 0.06, 0, 0),
(@tid, 'RAGU', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'RAGU', 'MIESO_WOLOWE', 0.08, 0, 0),
(@tid, 'RAGU', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'RAGU', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'RAGU', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'STEAKY', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'STEAKY', 'CHIPSY_ZIEMN', 0.06, 0, 0),
(@tid, 'STEAKY', 'STEK_WOLOWY', 0.08, 0, 0),
(@tid, 'STEAKY', 'RUKOLA', 0.04, 0, 0),
(@tid, 'STEAKY', 'KREM_BALSAMICZNY', 0.02, 0, 0),
(@tid, 'STEAKY', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'VERDURA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'VERDURA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'VERDURA', 'CUKINIA', 0.04, 0, 0),
(@tid, 'VERDURA', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'VERDURA', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'VERDURA', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'VERONA', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'VERONA', 'KURCZAK', 0.08, 0, 0),
(@tid, 'VERONA', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'VERONA', 'RUKOLA', 0.04, 0, 0),
(@tid, 'VERONA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'VINCI', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'VINCI', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'VINCI', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'VINCI', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'VINCI', 'KIELB_WLOSKA', 0.06, 0, 0),
(@tid, 'VINCI', 'NDUJA', 0.04, 0, 0),
(@tid, 'VINCI', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'VULCANO', 'MAKA_TYP_00', 0.28, 0, 0),
(@tid, 'VULCANO', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'VULCANO', 'KURCZAK', 0.08, 0, 0),
(@tid, 'VULCANO', 'JALAPENO', 0.01, 0, 0),
(@tid, 'VULCANO', 'CHILLI', 0.01, 0, 0),
(@tid, 'CALZONE_DI_CARNE', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CALZONE_DI_CARNE', 'SALAMI', 0.06, 0, 0),
(@tid, 'CALZONE_DI_CARNE', 'SZYNKA', 0.06, 0, 0),
(@tid, 'CALZONE_DI_CARNE', 'BOCZEK', 0.06, 0, 0),
(@tid, 'CALZONE_VEZUVIO', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CALZONE_VEZUVIO', 'SALAMI', 0.06, 0, 0),
(@tid, 'CALZONE_VEZUVIO', 'SZYNKA', 0.06, 0, 0),
(@tid, 'CALZONE_VEZUVIO', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'CALZONE_VEZUVIO', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'CALZONE_POPEY', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CALZONE_POPEY', 'BOCZEK', 0.06, 0, 0),
(@tid, 'CALZONE_POPEY', 'SZPINAK', 0.04, 0, 0),
(@tid, 'CALZONE_POPEY', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'CALZONE_POPEY', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'CALZONE_VEGETARIANO', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'CALZONE_VEGETARIANO', 'CUKINIA', 0.04, 0, 0),
(@tid, 'CALZONE_VEGETARIANO', 'PIECZARKI_SMAZONE', 0.05, 0, 0),
(@tid, 'CALZONE_VEGETARIANO', 'CHIPSY_ZIEMN', 0.06, 0, 0),
(@tid, 'CALZONE_VEGETARIANO', 'OLIWKI', 0.03, 0, 0),
(@tid, 'CALZONE_CONTADINO', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'CALZONE_CONTADINO', 'CHIPSY_ZIEMN', 0.06, 0, 0),
(@tid, 'CALZONE_CONTADINO', 'PIECZARKI_SMAZONE', 0.05, 0, 0),
(@tid, 'CALZONE_CONTADINO', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'CALZONE_POLLO_PICANTE', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CALZONE_POLLO_PICANTE', 'PIECZARKI_SMAZONE', 0.05, 0, 0),
(@tid, 'CALZONE_POLLO_PICANTE', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'CALZONE_POLLO_PICANTE', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'CALZONE_POLLO_PICANTE', 'CHILLI', 0.01, 0, 0),
(@tid, 'CALZONE_ITALIANO', 'KIELB_WLOSKA', 0.06, 0, 0),
(@tid, 'CALZONE_ITALIANO', 'PIECZARKI_SMAZONE', 0.05, 0, 0),
(@tid, 'CALZONE_ITALIANO', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'CALZONE_TEXAS', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'CALZONE_TEXAS', 'KURCZAK', 0.08, 0, 0),
(@tid, 'CALZONE_TEXAS', 'BOCZEK', 0.06, 0, 0),
(@tid, 'CALZONE_TEXAS', 'JALAPENO', 0.01, 0, 0),
(@tid, 'CALZONE_TEXAS', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'CALZONE_TEXAS', 'PAPRYKA', 0.04, 0, 0),
(@tid, 'CALZONE_MAFIA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'CALZONE_MAFIA', 'SALAMI_PICANTE', 0.06, 0, 0),
(@tid, 'CALZONE_MAFIA', 'RWANA_WIEPRZ', 0.08, 0, 0),
(@tid, 'CALZONE_MAFIA', 'JALAPENO', 0.01, 0, 0),
(@tid, 'CALZONE_MAFIA', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'STEK_WOLOWY', 0.08, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'RUKOLA', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_STEAKY', 'KREM_BALSAMICZNY', 0.02, 0, 0),
(@tid, 'SPAGHETTI_POMODORO', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'SPAGHETTI_POMODORO', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'SPAGHETTI_POMODORO', 'BAZYLIA', 0.01, 0, 0),
(@tid, 'SPAGHETTI_POMODORO', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'SPAGHETTI_BOLOGNESE', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'SPAGHETTI_BOLOGNESE', 'MIESO_WOLOWE', 0.08, 0, 0),
(@tid, 'SPAGHETTI_BOLOGNESE', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'SPAGHETTI_CARBONARA', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'SPAGHETTI_CARBONARA', 'BOCZEK', 0.06, 0, 0),
(@tid, 'SPAGHETTI_CARBONARA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'PENNE_TOSCANA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'PENNE_TOSCANA', 'KIELB_WLOSKA', 0.06, 0, 0),
(@tid, 'PENNE_TOSCANA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'PENNE_TOSCANA', 'CHILLI', 0.01, 0, 0),
(@tid, 'PENNE_TOSCANA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'SZYNKA', 0.06, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'RUKOLA', 0.04, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'POMIDORKI_SUSZE', 0.03, 0, 0),
(@tid, 'PENNE_DI_CAPO', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'KIELB_WLOSKA', 0.06, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'JALAPENO', 0.01, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'BOCZEK', 0.06, 0, 0),
(@tid, 'PENNE_AMATRICIANA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'GNOCCHI_ALLA_SORRENTINA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'GNOCCHI_ALLA_SORRENTINA', 'KURCZAK', 0.08, 0, 0),
(@tid, 'GNOCCHI_ALLA_SORRENTINA', 'BAZYLIA', 0.01, 0, 0),
(@tid, 'GNOCCHI_ALLA_SORRENTINA', 'MOZZ_BUFFALO', 0.09, 0, 0),
(@tid, 'ZAPIEKANKA_MAKARONOWA', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'PENNE_ARABIATA', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'PENNE_ARABIATA', 'CHILLI', 0.01, 0, 0),
(@tid, 'PENNE_ARABIATA', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'PENNE_ARABIATA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'PENNE_SPINACI', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'PENNE_SPINACI', 'KURCZAK', 0.08, 0, 0),
(@tid, 'PENNE_SPINACI', 'SZPINAK', 0.04, 0, 0),
(@tid, 'PENNE_SPINACI', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'PENNE_SPINACI', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'PENNE_POLLO_CON_BROCCOLI', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'PENNE_POLLO_CON_BROCCOLI', 'KURCZAK', 0.08, 0, 0),
(@tid, 'PENNE_POLLO_CON_BROCCOLI', 'BRUKOLY', 0.04, 0, 0),
(@tid, 'PENNE_POLLO_CON_BROCCOLI', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_AL_TONNO', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'TAGLIATELLE_AL_TONNO', 'TUNCZYK', 0.06, 0, 0),
(@tid, 'TAGLIATELLE_AL_TONNO', 'ANCHOIS', 0.02, 0, 0),
(@tid, 'TAGLIATELLE_AL_TONNO', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'TAGLIATELLE_AL_TONNO', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'PIECZARKI', 0.05, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'CUKINIA', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'TAGLIATELLE_VERDE', 'RUKOLA', 0.04, 0, 0),
(@tid, 'GNOCCHI_ALLA_TOSCANA', 'SOS_SMIETANKOWY', 0.06, 0, 0),
(@tid, 'GNOCCHI_ALLA_TOSCANA', 'KURCZAK', 0.08, 0, 0),
(@tid, 'GNOCCHI_ALLA_TOSCANA', 'SZPINAK', 0.04, 0, 0),
(@tid, 'GNOCCHI_ALLA_TOSCANA', 'POMIDORKI_SUSZE', 0.03, 0, 0),
(@tid, 'GNOCCHI_ALLA_TOSCANA', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'SALATKA_STEAKY', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'SALATKA_STEAKY', 'KREM_BALSAMICZNY', 0.02, 0, 0),
(@tid, 'SALATKA_STEAKY', 'RUKOLA', 0.04, 0, 0),
(@tid, 'SALATKA_STEAKY', 'STEK_WOLOWY', 0.08, 0, 0),
(@tid, 'SALATKA_STEAKY', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'SALATKA_CEZAR', 'SALATA', 0.04, 0, 0),
(@tid, 'SALATKA_CEZAR', 'KURCZAK', 0.08, 0, 0),
(@tid, 'SALATKA_CEZAR', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'SALATKA_CEZAR', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'SALATKA_FORNO', 'RUKOLA', 0.04, 0, 0),
(@tid, 'SALATKA_FORNO', 'GRUSZKA', 0.05, 0, 0),
(@tid, 'SALATKA_FORNO', 'POMIDORKI_KOKAT', 0.05, 0, 0),
(@tid, 'SALATKA_FORNO', 'CEBULA_CZERW', 0.03, 0, 0),
(@tid, 'SALATKA_FORNO', 'OLIWKI', 0.03, 0, 0),
(@tid, 'SALATKA_FORNO', 'OLIWA', 0.02, 0, 0),
(@tid, 'SALATKA_FORNO', 'PARMEZAN', 0.04, 0, 0),
(@tid, 'GYROS_ROLLO_XXL', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'GYROS_ROLLO_XXL', 'KEBAB_DROBIOWY', 0.08, 0, 0),
(@tid, 'GYROS_ROLLO_XXL', 'KAP_PEKINSKA', 0.04, 0, 0),
(@tid, 'GYROS_ROLLO_XXL', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'GYROS_ROLLO_XXL', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'VEGE_ROLLO_XXL', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'VEGE_ROLLO_XXL', 'KAP_PEKINSKA', 0.04, 0, 0),
(@tid, 'VEGE_ROLLO_XXL', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'VEGE_ROLLO_XXL', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'GYROS_NA_TALERZU', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'GYROS_NA_TALERZU', 'KEBAB_DROBIOWY', 0.08, 0, 0),
(@tid, 'GYROS_NA_TALERZU', 'KAP_PEKINSKA', 0.04, 0, 0),
(@tid, 'GYROS_NA_TALERZU', 'MOZZ_FIOR', 0.1, 0, 0),
(@tid, 'GYROS_NA_TALERZU', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'GYROS_FORNO', 'SOS_POMIDOROWY', 0.08, 0, 0),
(@tid, 'GYROS_FORNO', 'KEBAB_DROBIOWY', 0.08, 0, 0),
(@tid, 'GYROS_FORNO', 'KAP_PEKINSKA', 0.04, 0, 0),
(@tid, 'GYROS_FORNO', 'KUKURYDZA', 0.04, 0, 0),
(@tid, 'GYROS_FORNO', 'MOZZ_FIOR', 0.1, 0, 0);

-- ── 2.15 wh_stock (stany początkowe) ──────────────────────────────────
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

-- ── 2.16 wh_documents + wh_document_lines (PZ receipts) ───────────────
INSERT INTO wh_documents (tenant_id, doc_number, type, warehouse_id, status,
  supplier_name, supplier_invoice, notes, created_at)
VALUES (@tid, 'PZ-2026/05/FORNO-001', 'PZ', @wh, 'completed',
  'HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o.', 'PZ-2026/05/FORNO-001',
  'NIP: 5252311234', '2026-08-10 04:02:03');
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
  'NIP: 7780012345', '2026-08-17 04:02:03');
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
  'NIP: 1230012345', '2026-08-21 04:02:03');
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
  'NIP: 8870012345', '2026-08-14 04:02:03');
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
  'NIP: 6450012345', '2026-08-23 04:02:03');
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

-- ── 2.17 sh_ksef_invoices + sh_ksef_invoice_lines ─────────────────────
INSERT INTO sh_ksef_invoices (tenant_id, supplier_nip, supplier_name, supplier_address,
  buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
  total_net_minor, total_vat_minor, total_gross_minor, status, linked_wh_document_id,
  fetched_at, processed_at)
VALUES (@tid, '5252311234', 'HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o.', 'ul. Warmińska 14, 10-100 Olsztyn',
  NULL, 'Pizzeria Forno', 'FA/FORNO/2026/001',
  '2026-08-22', '2026-08-21', '2026-09-21',
  91500, 7602, 99102,
  'new', NULL,
  '2026-08-16 04:02:03', NULL);
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
  '2026-08-16', '2026-08-16', '2026-09-15',
  36800, 1840, 38640,
  'accepted', (SELECT id FROM wh_documents WHERE tenant_id=@tid AND doc_number='PZ-2026/05/FORNO-002'),
  '2026-08-10 04:02:03', '2026-08-16');
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
  '2026-08-20', '2026-08-20', '2026-09-19',
  100400, 8032, 108432,
  'processing', NULL,
  '2026-08-14 04:02:03', NULL);
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

-- ── 2.18 sh_orders + sh_order_lines ───────────────────────────────────
INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('e4bcf41a-2527-48f3-911a-b7ce6a356b98', @tid, 'FORNO-001',
  'delivery', 'delivery', 'seed',
  9500, 800, 10300,
  'accepted', 'card', 'card',
  'unassigned', 'Jan Kowalski', '+48 512 345 678', 'ul. Zielona 15, 10-900 Olsztyn', 53.7784, 20.4801,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 90 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 90 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('cbfbd916-2759-40ce-ab9e-61142e818236', 'e4bcf41a-2527-48f3-911a-b7ce6a356b98', 'MARGHERITA_30CM', 'Margherita 30cm',
  2700, 1, 2700, 8.0, 200, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('5ee1c16c-2fe6-4f4e-9a4a-b0b6a3feec7b', 'e4bcf41a-2527-48f3-911a-b7ce6a356b98', 'DI_PARMA_37CM', 'Di Parma 37cm',
  5400, 1, 5400, 8.0, 400, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('a7e962f3-06fc-4d7c-8add-8143dd7dea95', 'e4bcf41a-2527-48f3-911a-b7ce6a356b98', 'COCA_COLA', 'Coca-Cola 0.33l',
  700, 2, 1400, 23.0, 262, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('b43c3875-39d2-4b2f-8d44-d368eadad937', @tid, 'FORNO-002',
  'delivery', 'delivery', 'seed',
  7500, 800, 8300,
  'preparing', 'online_paid', 'online',
  'unassigned', 'Anna Nowak', '+48 601 234 567', 'ul. Lipowa 7, 10-500 Olsztyn', 53.772, 20.4925,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 45 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 45 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('afd94b56-41ce-449c-b494-b638eed10fca', 'b43c3875-39d2-4b2f-8d44-d368eadad937', 'ETNA_30CM', 'Etna 30cm',
  3800, 1, 3800, 8.0, 281, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('0ef4cb75-4de5-4e68-92eb-e2cf5fc6af43', 'b43c3875-39d2-4b2f-8d44-d368eadad937', 'VINCI_30CM', 'Vinci 30cm',
  3700, 1, 3700, 8.0, 274, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('3d650526-b9fa-4b8b-8efa-d7597e499784', @tid, 'FORNO-003',
  'takeaway', 'takeaway', 'seed',
  4050, 0, 4050,
  'new', 'to_pay', 'cash',
  NULL, 'Marcin Wójcik', '+48 789 123 456', NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 15 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('550ea8f2-1467-4c98-add2-8270f9975eed', '3d650526-b9fa-4b8b-8efa-d7597e499784', 'MARGHERITA_37CM', 'Margherita 37cm',
  4050, 1, 4050, 8.0, 300, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('50b80551-599c-47f1-8e16-d741d5fb5042', @tid, 'FORNO-004',
  'pos', 'dine_in', 'seed',
  17750, 0, 17750,
  'preparing', 'to_pay', NULL,
  NULL, 'Stolik 4', NULL, NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 30 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('2446f031-9a2e-427a-bcf9-338d9e2cc18a', '50b80551-599c-47f1-8e16-d741d5fb5042', 'MONTANARA_37CM', 'Montanara 37cm',
  5550, 1, 5550, 8.0, 411, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('92bb4728-415f-4c98-b098-86c11bbea3ce', '50b80551-599c-47f1-8e16-d741d5fb5042', 'STEAKY_37CM', 'Steaky 37cm',
  6000, 1, 6000, 8.0, 444, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('5163ada6-1398-4df7-8aac-1d752238891f', '50b80551-599c-47f1-8e16-d741d5fb5042', 'CAPRICCIOSA_30CM', 'Capricciosa 30cm',
  3600, 1, 3600, 8.0, 267, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('a77b326f-543c-456a-83bc-d9f354df6424', '50b80551-599c-47f1-8e16-d741d5fb5042', 'PIWO_BUTELKA', 'Piwo 0.5l',
  1300, 2, 2600, 23.0, 486, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('c822638f-3c96-4778-b1c4-1b47ea60ec7e', @tid, 'FORNO-005',
  'delivery', 'delivery', 'seed',
  10300, 800, 11100,
  'completed', 'card', 'card',
  'delivered', 'Kasia Zalewska', '+48 698 765 432', 'ul. Mickiewicza 33, 10-230 Olsztyn', 53.7801, 20.4756,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 168 HOUR), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 168 HOUR));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('24463f52-1c6b-4683-a924-e56a545890e9', 'c822638f-3c96-4778-b1c4-1b47ea60ec7e', 'QUATTRO_FORMAGGI_37CM', 'Quattro Formaggi 37cm',
  5700, 1, 5700, 8.0, 422, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('80293fd5-f0b9-41e0-b601-117ce9aa4a6c', 'c822638f-3c96-4778-b1c4-1b47ea60ec7e', 'DI_PARMA_30CM', 'Di Parma 30cm',
  3600, 1, 3600, 8.0, 267, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('13e5b631-0571-458a-9a54-6d0b9321ed52', 'c822638f-3c96-4778-b1c4-1b47ea60ec7e', 'WODA_NIEGAZ', 'Woda niegazowana',
  500, 2, 1000, 23.0, 187, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('0b328603-6354-480c-9ad3-69be05387519', @tid, 'FORNO-006',
  'delivery', 'delivery', 'seed',
  8600, 800, 9400,
  'completed', 'online_paid', 'online',
  'delivered', 'Piotr Nowicki', '+48 504 321 987', 'ul. Słoneczna 21, 10-710 Olsztyn', 53.765, 20.51,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 75 MINUTE), INTERVAL 35 MINUTE), LOWER(SUBSTRING(REPLACE('0b328603-6354-480c-9ad3-69be05387519','-',''), 1, 16)), DATE_SUB(NOW(), INTERVAL 75 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('0cc6d629-bc3e-45af-8658-7568d4cc069b', '0b328603-6354-480c-9ad3-69be05387519', 'DIAVOLA_30CM', 'Diavola 30cm',
  3500, 1, 3500, 8.0, 259, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('00b3f399-70bb-43fa-a0a7-bd4027c1a629', '0b328603-6354-480c-9ad3-69be05387519', 'AMERICANA_37CM', 'Americano 37cm',
  5100, 1, 5100, 8.0, 378, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('211a22ef-673e-4168-b990-517faa99cf00', @tid, 'FORNO-007',
  'online', 'delivery', 'seed',
  4000, 800, 4800,
  'new', 'online_paid', 'online',
  'unassigned', 'Tomek Bąk', '+48 666 555 444', 'ul. Kościuszki 5, 10-100 Olsztyn', 53.7754, 20.4818,
  DATE_ADD(DATE_SUB(NOW(), INTERVAL 6 MINUTE), INTERVAL 35 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 6 MINUTE));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('a5e22afd-e6c7-444c-8b54-52fcf02a06de', '211a22ef-673e-4168-b990-517faa99cf00', 'MARGHERITA_ITALIANO_30CM', 'Margherita Italiano 30cm',
  3300, 1, 3300, 8.0, 244, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('417f71a9-d835-4fd7-82ca-5d07e2ad43eb', '211a22ef-673e-4168-b990-517faa99cf00', 'SPRITE', 'Sprite 0.33l',
  700, 1, 700, 23.0, 131, NULL);

INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, source,
  subtotal, delivery_fee, grand_total, status, payment_status, payment_method,
  delivery_status, customer_name, customer_phone, delivery_address, lat, lng,
  promised_time, tracking_token, created_at)
VALUES ('9f5c47bd-8a83-462c-b27b-5f59bb83117f', @tid, 'FORNO-008',
  'pos', 'dine_in', 'seed',
  24450, 0, 24450,
  'completed', 'card', 'card',
  NULL, 'Stolik 8', NULL, NULL, NULL, NULL,
  NULL, NULL, DATE_SUB(NOW(), INTERVAL 48 HOUR));

INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('fb199981-a24a-4f26-b8ee-1fde305e3ea7', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'CARBONARA_30CM', 'Carbonara 30cm',
  3600, 2, 7200, 8.0, 533, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('4edd7835-ccab-4df5-a26f-b34d37095561', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'VERDURA_37CM', 'Verdura 37cm',
  5250, 1, 5250, 8.0, 389, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('1a0dec34-5a30-4d68-a9f7-b6be35884606', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'CAPRICCIOSA_37CM', 'Capricciosa 37cm',
  5400, 1, 5400, 8.0, 400, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('571d2ede-24c5-4915-a434-d2d7d350307d', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'PIWO_BUTELKA', 'Piwo 0.5l',
  1300, 4, 5200, 23.0, 972, NULL);
INSERT INTO sh_order_lines (id, order_id, item_sku, snapshot_name,
  unit_price, quantity, line_total, vat_rate, vat_amount, modifiers_json)
VALUES ('70bf1097-fa24-408c-bdad-9e095ba94acd', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'COCA_COLA', 'Coca-Cola 0.33l',
  700, 2, 1400, 23.0, 262, NULL);

-- ── 2.19 sh_tenant_settings + sh_users + sh_drivers ──────────────────
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
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO sh_users (tenant_id, username, password_hash, pin_code, name,
  first_name, last_name, role, status, is_active, is_deleted)
VALUES
  (@tid, 'forno_owner',   '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqJZ4DpI1eKQZdJxKQKQKQKQKQKQK', '0000', 'Owner Forno',    'Owner',  'Forno',   'owner',   'active', 1, 0),
  (@tid, 'forno_manager', '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqJZ4DpI1eKQZdJxKQKQKQKQKQKQK', '1000', 'Manager Forno',  'Anna',   'Manager', 'manager', 'active', 1, 0),
  (@tid, 'forno_waiter',  '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqJZ4DpI1eKQZdJxKQKQKQKQKQKQK', '1111', 'Kelner Forno',   'Marek',  'Kelner',  'waiter',  'active', 1, 0),
  (@tid, 'forno_cook',    '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqJZ4DpI1eKQZdJxKQKQKQKQKQKQK', '3333', 'Kucharz Forno',  'Piotr',  'Kucharz', 'cook',    'active', 1, 0),
  (@tid, 'forno_driver',  '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqJZ4DpI1eKQZdJxKQKQKQKQKQKQK', '4444', 'Kierowca Forno', 'Tomek',  'Kierowca','driver',  'active', 1, 0)
ON DUPLICATE KEY UPDATE is_active=1, is_deleted=0;
SET @uid_owner   = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_owner');
SET @uid_manager = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_manager');
SET @uid_waiter  = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_waiter');
SET @uid_cook    = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_cook');
SET @uid_driver  = (SELECT id FROM sh_users WHERE tenant_id=@tid AND username='forno_driver');

INSERT INTO sh_drivers (user_id, tenant_id, status) VALUES (@uid_driver, @tid, 'offline')
ON DUPLICATE KEY UPDATE status='offline';
-- Note: no active shift in seed — driver must clock in via driver_app to become available

-- ── 2.20 sh_employees + rates + work_sessions + payroll_ledger ────────
INSERT INTO sh_employees
  (tenant_id, user_id, employee_code, display_name, first_name, last_name,
   hire_date, primary_role, status, default_currency)
VALUES
(@tid, @uid_owner, 'EMP-FORNO-001', 'Owner Forno', 'Owner', 'Forno', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 'owner', 'active', 'PLN'),
(@tid, @uid_manager, 'EMP-FORNO-002', 'Manager Forno', 'Anna', 'Manager', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 'manager', 'active', 'PLN'),
(@tid, @uid_waiter, 'EMP-FORNO-003', 'Kelner Forno', 'Marek', 'Kelner', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 'waiter', 'active', 'PLN'),
(@tid, @uid_cook, 'EMP-FORNO-004', 'Kucharz Forno', 'Piotr', 'Kucharz', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 'cook', 'active', 'PLN'),
(@tid, @uid_driver, 'EMP-FORNO-005', 'Kierowca Forno', 'Tomek', 'Kierowca', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), 'driver', 'active', 'PLN');
SET @eid_owner   = (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code='EMP-FORNO-001');
SET @eid_manager = (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code='EMP-FORNO-002');
SET @eid_waiter  = (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code='EMP-FORNO-003');
SET @eid_cook    = (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code='EMP-FORNO-004');
SET @eid_driver  = (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code='EMP-FORNO-005');

INSERT INTO sh_employee_rates
  (tenant_id, employee_id, rate_type, amount_minor, currency, effective_from, effective_to, reason)
VALUES
(@tid, @eid_manager, 'hourly', 2800, 'PLN', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), NULL, 'hiring'),
(@tid, @eid_waiter, 'hourly', 2200, 'PLN', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), NULL, 'hiring'),
(@tid, @eid_cook, 'hourly', 2500, 'PLN', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), NULL, 'hiring'),
(@tid, @eid_driver, 'hourly', 2000, 'PLN', DATE_SUB(CURDATE(), INTERVAL 6 MONTH), NULL, 'hiring');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('04682349-78b7-984e-c9eb-dee4ac3eddfa', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 91 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 91 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='04682349-78b7-984e-c9eb-dee4ac3eddfa');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1ec4d2b8-85bf-0e4d-8ac2-51d7493cca73', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 92 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 92 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1ec4d2b8-85bf-0e4d-8ac2-51d7493cca73');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('be463a58-6fc4-96b5-118d-0bb39164726a', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 93 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 93 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='be463a58-6fc4-96b5-118d-0bb39164726a');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('807e52e8-6f34-76da-6fdc-954224db7287', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 94 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 94 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='807e52e8-6f34-76da-6fdc-954224db7287');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4a4bad7b-e380-b9cb-9043-a8ee27a9cad8', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 95 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 95 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4a4bad7b-e380-b9cb-9043-a8ee27a9cad8');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('70a2af49-d8be-6262-fd2f-1af06bda9dc1', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 98 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 98 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='70a2af49-d8be-6262-fd2f-1af06bda9dc1');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('98fc1db6-ebc5-837c-a287-9ea2df34cc00', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 99 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 99 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='98fc1db6-ebc5-837c-a287-9ea2df34cc00');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('93fc9602-d58d-13d4-6789-0787cad8201d', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 100 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 100 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='93fc9602-d58d-13d4-6789-0787cad8201d');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4d837648-423d-923a-934f-e18f861acf5b', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 101 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 101 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4d837648-423d-923a-934f-e18f861acf5b');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fd8053f7-784b-581b-d7a0-3c81b99fe59d', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 102 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 102 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fd8053f7-784b-581b-d7a0-3c81b99fe59d');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f685a3f4-df54-ee71-19c6-b3dc5d5dab7e', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 105 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 105 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f685a3f4-df54-ee71-19c6-b3dc5d5dab7e');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('942be28e-6835-99c7-2c23-471f196c8e99', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 106 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 106 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='942be28e-6835-99c7-2c23-471f196c8e99');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1173b84c-4e12-50e9-8ef2-7458ac044fe3', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 107 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 107 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1173b84c-4e12-50e9-8ef2-7458ac044fe3');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('41172d6b-30c1-7fd6-4ea4-8504a84c5228', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 108 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 108 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='41172d6b-30c1-7fd6-4ea4-8504a84c5228');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('32bb68aa-c6bf-e4fb-1c49-6d2aeafe61b1', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 109 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 109 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='32bb68aa-c6bf-e4fb-1c49-6d2aeafe61b1');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e1e05db9-a1a1-4246-cfcc-3a78cbd65214', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 112 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e1e05db9-a1a1-4246-cfcc-3a78cbd65214');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4e81d573-24df-7906-1560-925f460eacfa', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 113 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 113 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4e81d573-24df-7906-1560-925f460eacfa');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('587dc209-e9e6-652e-478d-50fe4c8619e2', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 114 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 114 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='587dc209-e9e6-652e-478d-50fe4c8619e2');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f6e161f0-4b4b-be11-6d10-a527c9d3c382', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 115 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 115 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f6e161f0-4b4b-be11-6d10-a527c9d3c382');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e6709d5d-9589-c5d0-907d-68880d39d11e', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 116 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 116 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e6709d5d-9589-c5d0-907d-68880d39d11e');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8b1467a4-d84d-6d74-d706-f4a2671d93f3', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 63 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 63 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8b1467a4-d84d-6d74-d706-f4a2671d93f3');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('99dca3f1-4e23-9bfc-6323-485825394c46', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 64 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 64 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='99dca3f1-4e23-9bfc-6323-485825394c46');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1ef1691d-9a1a-7611-b803-e1f61f2adfb6', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 65 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 65 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1ef1691d-9a1a-7611-b803-e1f61f2adfb6');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('00a369d8-07ff-bd83-25d2-4de47700b64b', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 66 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 66 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='00a369d8-07ff-bd83-25d2-4de47700b64b');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0e40697b-b0ad-402a-16a8-42171214025e', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 67 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 67 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0e40697b-b0ad-402a-16a8-42171214025e');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0e1b1e2f-572d-cd57-c27e-054187ff7b9d', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 70 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 70 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0e1b1e2f-572d-cd57-c27e-054187ff7b9d');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3bac5b0d-8edc-f1f4-889e-7752965704fc', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 71 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 71 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3bac5b0d-8edc-f1f4-889e-7752965704fc');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('60fe92e4-8674-d72d-bb7a-fdc321e5dcf6', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 72 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 72 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='60fe92e4-8674-d72d-bb7a-fdc321e5dcf6');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8190b79b-3e86-a788-3dfe-181d6ba1a7bf', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 73 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 73 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8190b79b-3e86-a788-3dfe-181d6ba1a7bf');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a84f6156-29a1-2ab9-dd1b-d3a5aae3830f', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 74 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 74 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a84f6156-29a1-2ab9-dd1b-d3a5aae3830f');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a5907159-2280-cfff-118b-16f648dd1811', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 77 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 77 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a5907159-2280-cfff-118b-16f648dd1811');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a0c592f0-6641-1bf3-ab95-23e0e0314848', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 78 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 78 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a0c592f0-6641-1bf3-ab95-23e0e0314848');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d824c5ef-586a-3158-e68b-2f30d1c9e6e9', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 79 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 79 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d824c5ef-586a-3158-e68b-2f30d1c9e6e9');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a831aaa5-f97e-e838-5841-1186ae093b1e', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 80 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 80 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a831aaa5-f97e-e838-5841-1186ae093b1e');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1b337ace-20cf-639e-d99e-4017f5152b52', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 81 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 81 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1b337ace-20cf-639e-d99e-4017f5152b52');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f0d4386c-52f7-cbe3-ddb1-6c816ef329d5', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 84 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 84 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f0d4386c-52f7-cbe3-ddb1-6c816ef329d5');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1b2c90d9-7f75-90a6-4e40-def2499e0dc3', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 85 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 85 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1b2c90d9-7f75-90a6-4e40-def2499e0dc3');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7a175a8b-5a34-9c7f-dfaf-b54cf5852187', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 86 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 86 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7a175a8b-5a34-9c7f-dfaf-b54cf5852187');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7a6fa5c5-8259-87a5-4d33-428b1ef4fe7e', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 87 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 87 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7a6fa5c5-8259-87a5-4d33-428b1ef4fe7e');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d90d64ff-67e4-6100-db91-59859cb2f641', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 88 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d90d64ff-67e4-6100-db91-59859cb2f641');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f2024733-c567-d129-baad-a6a07f2a9b27', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 31 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 31 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f2024733-c567-d129-baad-a6a07f2a9b27');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b78dabdc-3ed9-91da-d182-534d711055d5', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 32 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 32 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b78dabdc-3ed9-91da-d182-534d711055d5');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('01aed262-c2f6-4732-01d8-8ab60a4eeaba', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 35 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='01aed262-c2f6-4732-01d8-8ab60a4eeaba');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ed20ac44-80b8-b6e2-af9b-58bd74773398', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 36 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 36 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ed20ac44-80b8-b6e2-af9b-58bd74773398');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7b83a39f-965c-4ed9-1995-4915c8b9caaa', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 37 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 37 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7b83a39f-965c-4ed9-1995-4915c8b9caaa');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('67f46392-766f-3602-6b7f-d39256f86fc7', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 38 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 38 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='67f46392-766f-3602-6b7f-d39256f86fc7');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3ea5d958-a78a-b1c2-295c-a3dd06330083', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 39 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 39 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3ea5d958-a78a-b1c2-295c-a3dd06330083');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7d8a594e-160c-007d-19cd-6c6bdd28af4f', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 42 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 42 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7d8a594e-160c-007d-19cd-6c6bdd28af4f');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e0db33cf-9c4c-a9f0-a28c-cbe307a45798', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 43 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 43 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e0db33cf-9c4c-a9f0-a28c-cbe307a45798');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d042fd3d-52f9-6f11-0f4a-b6a8c7431bb5', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 44 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 44 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d042fd3d-52f9-6f11-0f4a-b6a8c7431bb5');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f4dc13f1-7423-76ec-2f33-07a2b215075c', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 45 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 45 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f4dc13f1-7423-76ec-2f33-07a2b215075c');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5c8eb5ed-6fa7-af09-829c-786db714ffdc', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 46 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 46 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5c8eb5ed-6fa7-af09-829c-786db714ffdc');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7d7b0252-cfa4-93fb-033b-4f9cfaa508a9', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 49 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 49 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7d7b0252-cfa4-93fb-033b-4f9cfaa508a9');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8522ce22-feaf-3fc3-b830-902a65c863d1', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 50 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 50 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8522ce22-feaf-3fc3-b830-902a65c863d1');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e6b0aa51-58fe-85c4-0a4a-35168ee05230', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 51 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 51 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e6b0aa51-58fe-85c4-0a4a-35168ee05230');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('95e2c893-b9e0-fcae-56aa-661ba2842ce6', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 52 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 52 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='95e2c893-b9e0-fcae-56aa-661ba2842ce6');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('11c43f80-cfef-a89d-153a-583bb8d588c6', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 53 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 53 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='11c43f80-cfef-a89d-153a-583bb8d588c6');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('338a67e9-2709-46d1-5fdd-cf023e520ea5', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 56 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 56 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='338a67e9-2709-46d1-5fdd-cf023e520ea5');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9e22e71c-549e-b515-61b4-8f16cee0281b', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 57 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 57 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9e22e71c-549e-b515-61b4-8f16cee0281b');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b34a9d58-8d0c-69bb-8b73-10dccb8470c9', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 58 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 58 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b34a9d58-8d0c-69bb-8b73-10dccb8470c9');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('10c1ccc9-2868-aba2-3ae7-c44956ad10d8', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='10c1ccc9-2868-aba2-3ae7-c44956ad10d8');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1affa8fd-7b50-b6b5-e565-8c9cd7749830', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 2 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1affa8fd-7b50-b6b5-e565-8c9cd7749830');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cc2e04b3-7c96-69c1-7977-d334f389a830', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 3 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cc2e04b3-7c96-69c1-7977-d334f389a830');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0c7b521d-b6d5-d4a7-47cb-dc7b7f3d4715', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 4 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0c7b521d-b6d5-d4a7-47cb-dc7b7f3d4715');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b1df06de-7215-82fa-a8ee-df755c30d23d', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 7 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b1df06de-7215-82fa-a8ee-df755c30d23d');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5cb2fca3-ce62-26e8-ad91-a69cb6f82f7b', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 8 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 8 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5cb2fca3-ce62-26e8-ad91-a69cb6f82f7b');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7ca4b5c1-e73d-6307-b0a2-ceff60a329d9', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 9 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7ca4b5c1-e73d-6307-b0a2-ceff60a329d9');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f4552981-a55c-03c5-9e69-839174bb28fb', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 10 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 10 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f4552981-a55c-03c5-9e69-839174bb28fb');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6612c827-4a26-b384-fb93-df3281942876', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 11 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6612c827-4a26-b384-fb93-df3281942876');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cb331b2b-b619-984e-fc86-891db1977d72', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 14 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cb331b2b-b619-984e-fc86-891db1977d72');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ad69bdd9-2532-5c45-f83d-708c20f4c397', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 15 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 15 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ad69bdd9-2532-5c45-f83d-708c20f4c397');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c5ace101-d137-5a28-8ec2-e630aa250779', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 16 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c5ace101-d137-5a28-8ec2-e630aa250779');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5cdd1af3-118e-5e10-a1cf-98c7111b7eb0', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 17 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 17 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5cdd1af3-118e-5e10-a1cf-98c7111b7eb0');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('89d81cf7-8c7c-53bd-9774-a57fceb54d07', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 18 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='89d81cf7-8c7c-53bd-9774-a57fceb54d07');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0c51ddc1-05f6-9f4c-124b-bbdcdad5e2aa', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 21 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0c51ddc1-05f6-9f4c-124b-bbdcdad5e2aa');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('562ce68f-e306-dd18-2a88-c7c0b579cebe', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 22 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 22 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='562ce68f-e306-dd18-2a88-c7c0b579cebe');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('67d5fac9-d569-3286-1468-d3bd10d645b8', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 23 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 23 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='67d5fac9-d569-3286-1468-d3bd10d645b8');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bb3b40e4-5031-8a36-86ee-4910363ab793', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 24 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bb3b40e4-5031-8a36-86ee-4910363ab793');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ca832ce5-faa6-9e30-7a25-7618216a1833', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 25 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 25 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ca832ce5-faa6-9e30-7a25-7618216a1833');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('21579a7d-2635-0da3-120c-1766db318003', @tid, @uid_owner, @eid_owner,
  DATE_SUB(NOW(), INTERVAL 28 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 28 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='21579a7d-2635-0da3-120c-1766db318003');

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('19817429-8efe-45dd-c4a9-1fce6a7690b6', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 91 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 91 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='19817429-8efe-45dd-c4a9-1fce6a7690b6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('76b98408-3707-0507-ae9e-28ff5188d554', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 91 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 91 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 91 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b4028a4f-01bf-3b6a-bf9d-4cfe8b2ea31f', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 92 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 92 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b4028a4f-01bf-3b6a-bf9d-4cfe8b2ea31f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6b4cad31-249a-b123-1ac2-5541e38e2e77', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 92 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 92 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 92 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0c7887f5-5e3d-d519-a8ea-03f9e94fb23b', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 93 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 93 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0c7887f5-5e3d-d519-a8ea-03f9e94fb23b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('870588c0-8ad4-c65a-8188-d40595536d2d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 93 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 93 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 93 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9c4fbf7e-7889-18d1-00ab-6c816b9857c9', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 94 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 94 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9c4fbf7e-7889-18d1-00ab-6c816b9857c9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d3841bcd-aa71-fc4e-50c0-e54fb44404d7', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 94 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 94 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 94 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6002d422-9b18-d158-e3bc-c917a8e6ae2b', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 95 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 95 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6002d422-9b18-d158-e3bc-c917a8e6ae2b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7a4e5e79-a437-bfff-5d9d-18bea08731b3', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 95 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 95 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 95 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('39971a14-5e59-761f-7abf-b16d75b7bd75', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 98 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 98 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='39971a14-5e59-761f-7abf-b16d75b7bd75');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('75637124-298b-b338-c09b-43d6529678bf', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 98 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 98 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 98 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8e2ca4e2-2682-0021-d3c7-5117160cb7f4', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 99 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 99 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8e2ca4e2-2682-0021-d3c7-5117160cb7f4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4d9b3eae-421b-d6aa-a763-2dff9c4d2d04', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 99 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 99 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 99 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c533de46-dee7-eda3-cdb7-c8091a92d420', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 100 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 100 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c533de46-dee7-eda3-cdb7-c8091a92d420');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('29613541-2367-ee82-b5ce-d01764abfd1e', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 100 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 100 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 100 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('715463e2-9010-09cc-630b-06421cda9315', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 101 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 101 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='715463e2-9010-09cc-630b-06421cda9315');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('00f00224-b56e-49c0-a6ef-a5831e819b01', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 101 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 101 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 101 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0c566188-ee1d-500e-5ccf-7b89cd271783', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 102 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 102 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0c566188-ee1d-500e-5ccf-7b89cd271783');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('80df7fa6-f128-505d-e193-a4c27cf86af2', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 102 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 102 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 102 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7448d28e-5d78-58ec-1632-5e1cd1ce308e', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 105 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 105 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7448d28e-5d78-58ec-1632-5e1cd1ce308e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('23a9ba74-148f-2389-58cc-81cc0ccfcbc7', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 105 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 105 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 105 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('02dfca31-54f0-8d4c-36e5-c2ff53f72e4e', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 106 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 106 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='02dfca31-54f0-8d4c-36e5-c2ff53f72e4e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8e1b5cfa-02ed-eaaf-6e66-21b451a28a98', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 106 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 106 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 106 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('73d25bfe-031d-b497-ef38-c33c7b1f2050', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 107 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 107 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='73d25bfe-031d-b497-ef38-c33c7b1f2050');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f729884f-7053-d678-b52a-7da624698196', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 107 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 107 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 107 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2e03db9a-e793-c86e-c965-4d04ad0c2e07', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 108 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 108 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2e03db9a-e793-c86e-c965-4d04ad0c2e07');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b619640d-b859-4d74-e4a1-2c0ef6696973', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 108 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 108 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 108 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('113304cc-c570-ee04-69fe-354d0a725857', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 109 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 109 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='113304cc-c570-ee04-69fe-354d0a725857');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c41471a0-0b00-3d80-9a0a-c8238922d3d5', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 109 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 109 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 109 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('051b41ff-e765-ebd4-f960-385dd979848e', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 112 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='051b41ff-e765-ebd4-f960-385dd979848e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b34c2e6d-925b-6a2f-c2b4-25ecc19e5699', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 112 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 112 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 112 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c09f1edf-cfd8-61c7-c1c4-312d1d9f3e9d', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 113 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 113 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c09f1edf-cfd8-61c7-c1c4-312d1d9f3e9d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('24097a21-f030-5b74-30ed-340db004ad70', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 113 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 113 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 113 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7f06de80-fbe1-9505-8531-e0de8117aaaa', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 114 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 114 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7f06de80-fbe1-9505-8531-e0de8117aaaa');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('eb3808bb-c079-1767-3f40-e0c31d495969', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 114 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 114 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 114 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('16f5a38a-df5c-5a60-7ae5-e0070d0ba1a2', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 115 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 115 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='16f5a38a-df5c-5a60-7ae5-e0070d0ba1a2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ee0eb344-fe91-da2f-06fe-a18e0ca9f533', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 115 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 115 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 115 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8df1cf68-142b-0f5b-2abf-79623e739220', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 116 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 116 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8df1cf68-142b-0f5b-2abf-79623e739220');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5030ed88-b481-9ec9-b5db-22ac7e249943', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 116 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 116 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 116 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1e6168fa-ed2a-0f61-ef45-8a4579f59a67', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 63 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 63 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1e6168fa-ed2a-0f61-ef45-8a4579f59a67');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('48e43711-d7ac-1cef-6337-220e8ceef492', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 63 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 63 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 63 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6c9146ef-2943-9c13-727a-20eb3f7215f8', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 64 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 64 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6c9146ef-2943-9c13-727a-20eb3f7215f8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('004464f3-754d-5b08-22eb-a93d648f6ec7', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 64 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 64 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 64 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('aec3b952-018e-87ce-2eb2-5d4ba1bde3c4', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 65 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 65 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='aec3b952-018e-87ce-2eb2-5d4ba1bde3c4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4341fc7f-f565-498b-3593-dd1013d34bfa', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 65 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 65 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 65 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b038fa62-a5bf-9fca-761b-d9aa90893039', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 66 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 66 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b038fa62-a5bf-9fca-761b-d9aa90893039');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('901ce34b-2a08-cb7a-fe1a-707b2874e8de', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 66 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 66 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 66 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9f0dd89b-debd-760a-fb2a-2c0c04671ed1', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 67 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 67 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9f0dd89b-debd-760a-fb2a-2c0c04671ed1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c592e102-6846-2c3a-8ecb-081f6a1830ec', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 67 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 67 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 67 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7eb71b8a-1bcf-5e27-db71-3932a9c5aad3', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 70 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 70 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7eb71b8a-1bcf-5e27-db71-3932a9c5aad3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6b6f6c0d-5e02-436b-a98a-c74b5c4b61dd', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 70 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 70 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f8016e30-df8f-a526-cf42-46959f2c8ab1', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 71 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 71 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f8016e30-df8f-a526-cf42-46959f2c8ab1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('979d5cb5-994e-8cf8-1379-51f1586da29a', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 71 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 71 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 71 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e6c26e1e-525d-fc82-986a-ec5d2a553b9d', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 72 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 72 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e6c26e1e-525d-fc82-986a-ec5d2a553b9d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('cdc52c80-5a14-13bb-5b5b-df99fb225e89', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 72 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 72 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 72 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('33ec0b28-6a89-b28c-fc49-6f2f727707e9', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 73 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 73 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='33ec0b28-6a89-b28c-fc49-6f2f727707e9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5bb4240b-cd4a-91dc-ec07-8bfd1a6a079d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 73 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 73 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 73 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a76cb4e8-303d-4da6-b5ce-2e34661c1d72', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 74 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 74 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a76cb4e8-303d-4da6-b5ce-2e34661c1d72');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('eecc9928-7915-ccae-d566-990c7e0b5d3b', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 74 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 74 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 74 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0056e467-0f46-f2e9-cea4-13d4ccfa7fb9', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 77 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 77 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0056e467-0f46-f2e9-cea4-13d4ccfa7fb9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('69907475-eba0-1e55-7dd8-03fcf67fb7e1', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 77 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 77 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 77 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3133837d-57ef-7133-0ee2-ef7647cff53a', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 78 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 78 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3133837d-57ef-7133-0ee2-ef7647cff53a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3f1e388d-6d7e-8623-12b4-933754c7f806', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 78 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 78 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 78 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9bc0ac2f-075b-d7d3-97df-15c07c2dd781', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 79 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 79 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9bc0ac2f-075b-d7d3-97df-15c07c2dd781');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6db9787e-aab2-2b9a-ee37-1bccb006121e', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 79 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 79 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 79 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2f1d8ec3-17af-372f-f43b-04a73fd62aa7', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 80 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 80 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2f1d8ec3-17af-372f-f43b-04a73fd62aa7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e6805269-4f2d-34c6-c68b-adcc29447284', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 80 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 80 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 80 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fc68116a-12f4-b5ca-0a32-cd8216f4e1d5', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 81 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 81 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fc68116a-12f4-b5ca-0a32-cd8216f4e1d5');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('cda62a4d-b236-7ab9-cd40-fd56aaf6814f', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 81 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 81 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 81 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1d6e559e-5b08-4d8c-e864-a825756818f3', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 84 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 84 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1d6e559e-5b08-4d8c-e864-a825756818f3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c25b0b27-8a0b-3323-adef-0c5e71ad61d3', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 84 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 84 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 84 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3c4a4074-70ff-e8bd-9536-30084d865aac', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 85 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 85 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3c4a4074-70ff-e8bd-9536-30084d865aac');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b22d9c88-7d5e-fa97-eda1-daa52dbdf0d3', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 85 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 85 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 85 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('12308b3b-d960-2666-333b-3f9046e4eb0d', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 86 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 86 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='12308b3b-d960-2666-333b-3f9046e4eb0d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6ccf7b07-4ec2-0751-466b-c1cbe4b2bd76', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 86 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 86 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 86 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ecc00086-4dad-9652-3e03-bd6be75b11b7', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 87 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 87 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ecc00086-4dad-9652-3e03-bd6be75b11b7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6531ffd2-4e0d-a97f-29be-0965b0a10091', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 87 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 87 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 87 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3cd79167-199f-a6ae-e65f-f332f8b5d35a', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 88 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3cd79167-199f-a6ae-e65f-f332f8b5d35a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fa30a42c-1612-e06e-faec-0840ded9f630', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 88 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 88 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 88 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c6a2b444-6a02-8547-a0a1-21b5340a2cc7', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 31 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 31 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c6a2b444-6a02-8547-a0a1-21b5340a2cc7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('dd121d68-3621-5773-0cd0-248b53faf02d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 31 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 31 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 31 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cf8cbec6-86ee-f24e-d7e0-a3707b21f078', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 32 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 32 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cf8cbec6-86ee-f24e-d7e0-a3707b21f078');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d1b57e61-9faf-282c-7782-1fb29143a03f', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 32 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 32 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 32 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7fb01fa8-eb1e-9839-dace-ec4bdd8650d8', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 35 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7fb01fa8-eb1e-9839-dace-ec4bdd8650d8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d65bcfda-ff93-de3c-bcd3-c8f0936944bf', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 35 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 35 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 35 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6543597a-28ea-3ef5-d772-72acb05bf7ad', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 36 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 36 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6543597a-28ea-3ef5-d772-72acb05bf7ad');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('83799193-7f96-53b7-7987-483f0d713c88', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 36 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 36 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 36 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('713b1e8b-8ece-8855-d94d-dba47f7201d9', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 37 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 37 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='713b1e8b-8ece-8855-d94d-dba47f7201d9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e4a507ad-1ee9-24cf-12f7-e47752482e55', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 37 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 37 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 37 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a2eee1c4-53b9-e60c-8386-117b5df4570e', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 38 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 38 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a2eee1c4-53b9-e60c-8386-117b5df4570e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c36e0172-f45e-217c-896c-5fa7fdc20bdb', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 38 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 38 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 38 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5c522240-8d62-ea81-5d82-23dbe8808fc9', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 39 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 39 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5c522240-8d62-ea81-5d82-23dbe8808fc9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('dc5af0ca-a6ac-5d04-c5f2-be4130118f22', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 39 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 39 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 39 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9f7e444a-69e2-4f65-1d86-9c78c67c121a', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 42 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 42 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9f7e444a-69e2-4f65-1d86-9c78c67c121a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('51529a54-81cc-a388-9264-874549a153ec', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 42 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 42 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 42 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('843ef750-ab28-4899-e955-6fa5b59d8248', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 43 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 43 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='843ef750-ab28-4899-e955-6fa5b59d8248');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9074bdd7-85c5-ce75-67f3-6197dae48d76', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 43 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 43 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 43 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('77375233-78ec-edd7-ecad-1afa7eb32143', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 44 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 44 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='77375233-78ec-edd7-ecad-1afa7eb32143');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7518b0ec-d904-0e8c-4a25-b7bd97e09f19', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 44 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 44 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 44 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3f305c1f-6b31-558c-bcdd-7c05464fec02', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 45 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 45 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3f305c1f-6b31-558c-bcdd-7c05464fec02');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3988af9e-0fee-b846-3b44-6ba8ef6bba6a', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 45 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 45 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('de931048-5881-31f3-433a-0e8434135de4', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 46 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 46 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='de931048-5881-31f3-433a-0e8434135de4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f12f87bc-be39-d50a-ef77-c4a7c7186012', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 46 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 46 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 46 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('074bcd41-d102-2300-16c7-f495192d0dab', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 49 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 49 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='074bcd41-d102-2300-16c7-f495192d0dab');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ad06d40c-6b3d-7708-2f0e-d15f549f4ac5', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 49 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 49 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 49 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1a67298f-d661-9109-2173-a775c4fc3855', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 50 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 50 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1a67298f-d661-9109-2173-a775c4fc3855');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('88f1c2c7-e565-c3d0-8496-7ca8cf6aea74', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 50 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 50 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 50 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c0fb1355-cf87-4292-e697-5d1e3847285b', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 51 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 51 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c0fb1355-cf87-4292-e697-5d1e3847285b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2aa57f68-3390-45c6-d112-4592d88f3e5e', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 51 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 51 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 51 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7d7547d9-9b50-594c-c637-57c754bc2703', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 52 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 52 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7d7547d9-9b50-594c-c637-57c754bc2703');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f855e455-7efc-7e22-347f-d6858b9e797a', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 52 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 52 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 52 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('efa23973-7725-e13d-2776-ddbc20924f29', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 53 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 53 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='efa23973-7725-e13d-2776-ddbc20924f29');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('385cc44f-0021-b5bb-b313-75c7ebf6a1a0', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 53 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 53 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 53 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('988436be-5b4c-5c60-447b-47e076506371', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 56 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 56 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='988436be-5b4c-5c60-447b-47e076506371');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ecc63586-d666-5099-5724-aba336259053', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 56 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 56 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 56 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ed1541c5-4fe8-3e0a-dc97-903b2abba116', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 57 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 57 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ed1541c5-4fe8-3e0a-dc97-903b2abba116');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b128933c-7d40-9f4b-d43d-4879af39cb17', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 57 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 57 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 57 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0d84b617-1caf-45e7-c196-64797593b4cd', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 58 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 58 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0d84b617-1caf-45e7-c196-64797593b4cd');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4a822395-4ac9-588c-4d98-d20d178f0f5d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 58 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 58 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 58 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f78625f1-8fae-d3e6-5662-2c5da7be2a1f', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f78625f1-8fae-d3e6-5662-2c5da7be2a1f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7d000c7f-30d7-7c90-79ee-720cc72f6657', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 1 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 1 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('970bb861-c331-a3a3-05c2-0dee5909c5c2', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 2 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='970bb861-c331-a3a3-05c2-0dee5909c5c2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('70d43c47-478e-4400-0560-16615ee8baff', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 2 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 2 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b5ec8eff-d391-527e-2f44-4b7f86f42790', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 3 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b5ec8eff-d391-527e-2f44-4b7f86f42790');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bb0f2828-b9df-8c79-e54a-b6c37ece5933', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 3 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 3 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6df1597e-8dd1-8832-9aea-d26b803a4b94', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 4 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6df1597e-8dd1-8832-9aea-d26b803a4b94');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d6319ed4-a0a8-dc54-1642-2ca4ca69da5d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 4 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 4 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9b4fdace-4ef2-ca7c-8e8b-32951bd2f159', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 7 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9b4fdace-4ef2-ca7c-8e8b-32951bd2f159');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1166365a-55e1-a4b4-46b1-5a994634bdec', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 7 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 7 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 7 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8d9fa5ec-3f17-4f05-b45b-09c675a95723', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 8 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 8 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8d9fa5ec-3f17-4f05-b45b-09c675a95723');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('da559e02-260b-d674-3446-460082a2fd23', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 8 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 8 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 8 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b5810a40-93b2-c1e4-d42f-6890b0deb890', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 9 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b5810a40-93b2-c1e4-d42f-6890b0deb890');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0af5e192-c4c8-434e-8bf7-233eba5e0c6b', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 9 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 9 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 9 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ca5e817b-c92f-fcfb-9bad-e35d620dc706', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 10 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 10 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ca5e817b-c92f-fcfb-9bad-e35d620dc706');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ae3e7759-f837-731d-e695-7780c5ae4b91', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 10 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 10 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3d0b7042-485d-d102-5c44-eee1f6a9e704', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 11 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3d0b7042-485d-d102-5c44-eee1f6a9e704');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c405883d-d9b9-5f01-e662-4bf21a5a8592', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 11 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 11 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 11 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2441a658-19b2-59ab-1285-3f387e77f424', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 14 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2441a658-19b2-59ab-1285-3f387e77f424');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fff0c809-8b2d-2307-ce19-4575d7385110', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 14 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 14 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 14 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('595e7e2e-9f69-a51c-b72b-8f7a093babfe', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 15 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 15 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='595e7e2e-9f69-a51c-b72b-8f7a093babfe');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b687cfb7-045d-fd15-504b-a45873927891', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 15 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 15 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cf8a0d2e-a3eb-e02b-5ea6-613f6f94548d', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 16 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cf8a0d2e-a3eb-e02b-5ea6-613f6f94548d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c469cb04-45fe-e27d-5943-652eca0342fd', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 16 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 16 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 16 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7f38be9f-0189-34bd-5a05-b34906293330', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 17 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 17 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7f38be9f-0189-34bd-5a05-b34906293330');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c3b22519-69c1-bdd7-76ea-645dfdf186d2', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 17 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 17 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 17 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c2928781-e241-e8a4-b068-a4800d6f3cfb', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 18 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c2928781-e241-e8a4-b068-a4800d6f3cfb');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6bc7ff9f-b4d5-ab17-1ee4-938f4e426aea', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 18 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 18 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 18 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3781dcd9-ba8e-dd5d-455e-dd638ce161a3', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 21 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3781dcd9-ba8e-dd5d-455e-dd638ce161a3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1f3637f6-7514-6904-5267-7b1ed7fbfb4d', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 21 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 21 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 21 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('34de24f3-187f-7d43-74a5-e8b98c9576ea', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 22 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 22 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='34de24f3-187f-7d43-74a5-e8b98c9576ea');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b9fe48fa-bf19-8aea-f70e-62339da17510', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 22 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 22 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 22 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('894f300d-67f3-d6d2-0b27-86b67e09b377', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 23 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 23 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='894f300d-67f3-d6d2-0b27-86b67e09b377');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('892af0d9-5284-41d1-b725-7cb3763200b6', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 23 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 23 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 23 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d15f5e3f-1ba2-7604-38ca-bcf13b50ee06', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 24 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d15f5e3f-1ba2-7604-38ca-bcf13b50ee06');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c1ffec1b-a34f-85f3-23bd-c3b4f2802270', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 24 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 24 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 24 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c7885875-14db-37a2-a194-a7d460f00d4e', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 25 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 25 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c7885875-14db-37a2-a194-a7d460f00d4e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4a14e90e-60f4-dd44-69c5-42e79f7f4e5c', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 25 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 25 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 25 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('26849fc8-80bf-9f33-3a51-50ddf9be88bc', @tid, @uid_manager, @eid_manager,
  DATE_SUB(NOW(), INTERVAL 28 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 28 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='26849fc8-80bf-9f33-3a51-50ddf9be88bc');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('891b9bfd-dc3b-c9be-c3ca-2d8ca9ab867c', @tid, @eid_manager, YEAR(DATE_SUB(NOW(), INTERVAL 28 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 28 DAY)),
  'work_earnings', 22400, 'PLN', 8.0, 2800,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e351f78d-9444-e5d8-e74f-8c6066c63bf5', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 91 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 91 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e351f78d-9444-e5d8-e74f-8c6066c63bf5');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('94517107-3474-454f-3b42-926022bdef4e', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 91 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 91 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 91 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7ebb80de-756a-68d7-5917-fd448205939b', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 92 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 92 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7ebb80de-756a-68d7-5917-fd448205939b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a7522136-7432-0566-7dd6-a9a7d75547a7', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 92 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 92 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 92 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ab98c99e-18e0-0be7-9d00-c27b37ec9e19', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 93 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 93 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ab98c99e-18e0-0be7-9d00-c27b37ec9e19');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('210afd73-ae90-6888-9705-f7664d06a3c1', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 93 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 93 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 93 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5261a162-b892-5219-b329-8dd099b89f7a', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 94 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 94 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5261a162-b892-5219-b329-8dd099b89f7a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5e13b6d0-d410-3a80-b978-3fb7ab0be4da', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 94 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 94 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 94 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b1ceb0ab-5045-3cb2-0e21-1932facc5d84', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 95 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 95 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b1ceb0ab-5045-3cb2-0e21-1932facc5d84');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('973e4a28-1e85-c79d-6c4e-ad8cd3f4e9db', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 95 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 95 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 95 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2b239f70-fc63-198e-9bcb-7005e621b32b', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 98 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 98 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2b239f70-fc63-198e-9bcb-7005e621b32b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('35618200-74a2-0447-f3ef-1c0a38805c29', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 98 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 98 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 98 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('974c7ae6-04f4-c428-84ef-f903760b0932', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 99 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 99 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='974c7ae6-04f4-c428-84ef-f903760b0932');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a7feb17c-3779-f1b8-134a-34a1a50541f3', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 99 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 99 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 99 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4776839f-b89a-2b07-a102-74d36af13074', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 100 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 100 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4776839f-b89a-2b07-a102-74d36af13074');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('94ecad6a-f500-2500-c22f-4d6bc1ecfcaf', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 100 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 100 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 100 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('34fdf56d-0a3a-5ce6-6130-ed9fa8c03acd', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 101 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 101 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='34fdf56d-0a3a-5ce6-6130-ed9fa8c03acd');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fddd1cb4-3b62-499b-f3ce-cb10661bac88', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 101 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 101 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 101 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('14a36cd5-3a92-7114-c28b-410931288d19', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 102 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 102 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='14a36cd5-3a92-7114-c28b-410931288d19');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3af77eb4-28d4-f2b5-3a5e-057dfa1609c4', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 102 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 102 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 102 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c523b5e9-b37d-aea3-f58b-e5b50adf43ec', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 105 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 105 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c523b5e9-b37d-aea3-f58b-e5b50adf43ec');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f910b869-edfd-86dd-207d-b6c8ed5104b2', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 105 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 105 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 105 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bd1c64da-3abc-eb7c-5dcf-d5b99a79aae2', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 106 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 106 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bd1c64da-3abc-eb7c-5dcf-d5b99a79aae2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('51969d5a-86e3-348b-e79a-4c855c33b105', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 106 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 106 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 106 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f9521b08-e49c-47d0-9e9b-09b45f7bb138', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 107 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 107 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f9521b08-e49c-47d0-9e9b-09b45f7bb138');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('46b1a881-0b1f-3e40-fd8e-1cbe2ab28340', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 107 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 107 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 107 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f9b4e0f4-6e0c-9e43-e4e1-b7f28d00361f', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 108 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 108 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f9b4e0f4-6e0c-9e43-e4e1-b7f28d00361f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d55d25b7-7d95-433c-b87d-ca52b1580430', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 108 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 108 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 108 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1da7b3ec-fe1b-e426-dad2-9b20c1cc42b0', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 109 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 109 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1da7b3ec-fe1b-e426-dad2-9b20c1cc42b0');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('02558602-f375-ebd8-955b-d7e22f300a40', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 109 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 109 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 109 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7f6b800e-d378-84af-7c08-003673e3db1e', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 112 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7f6b800e-d378-84af-7c08-003673e3db1e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('85e993e2-0dda-6471-ccf7-3315e7ed715f', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 112 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 112 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 112 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('95a5c724-cfc7-dbd6-e62c-3d9a59eb3761', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 113 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 113 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='95a5c724-cfc7-dbd6-e62c-3d9a59eb3761');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e35444bc-3dad-ab7f-d083-deb7e387d2ea', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 113 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 113 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 113 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ed57bcd0-38a1-2751-77b5-559886bdccd6', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 114 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 114 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ed57bcd0-38a1-2751-77b5-559886bdccd6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('91a98a9a-9fa2-7a4a-22f0-722bdfd6a168', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 114 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 114 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 114 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('67e4c9f4-a997-6696-5687-ff9e360e95f2', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 115 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 115 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='67e4c9f4-a997-6696-5687-ff9e360e95f2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4a2c6242-61d9-61d1-d337-446aa6c4d6f0', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 115 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 115 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 115 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b44c67be-7f75-9900-0ed5-f53cbe28f231', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 116 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 116 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b44c67be-7f75-9900-0ed5-f53cbe28f231');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bd05c3ad-5a2d-563c-9b04-7faddc71d0b3', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 116 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 116 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 116 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2194bae7-89d2-4c8f-3b11-e0eec53b27b8', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 63 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 63 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2194bae7-89d2-4c8f-3b11-e0eec53b27b8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bc84ca17-53b8-89df-2732-9d9ac9e656b0', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 63 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 63 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 63 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3595a57f-0dd9-ec65-8c22-f5f62585009d', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 64 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 64 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3595a57f-0dd9-ec65-8c22-f5f62585009d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0d0c55e1-f8b5-f803-4876-f3656baf16c3', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 64 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 64 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 64 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a6d4b2b0-0e7c-7bfc-c0f1-0ae29cc9a1fe', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 65 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 65 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a6d4b2b0-0e7c-7bfc-c0f1-0ae29cc9a1fe');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bd071160-7549-f4cc-58c4-1fc4ac6a76bb', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 65 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 65 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 65 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('95a09755-045f-dc80-d97a-edbbf426cd2f', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 66 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 66 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='95a09755-045f-dc80-d97a-edbbf426cd2f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('74c0e958-ee8a-2f40-ebf7-000e3b272e3b', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 66 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 66 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 66 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5c3f6449-b8ad-bd90-6a4b-88f07fcb52f2', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 67 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 67 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5c3f6449-b8ad-bd90-6a4b-88f07fcb52f2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c1a00952-2e5c-0d20-c96e-94f8e5a6d612', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 67 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 67 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 67 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('97fc3634-6d41-36b0-70f5-9fe6f2b3b8f0', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 70 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 70 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='97fc3634-6d41-36b0-70f5-9fe6f2b3b8f0');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1f782cbd-a0e0-3a71-07f4-e1f254ac25bb', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 70 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 70 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('97c114f0-ce03-525e-8a29-45130afa082d', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 71 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 71 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='97c114f0-ce03-525e-8a29-45130afa082d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1929f17d-5b44-4006-fa18-f13ac05a98ab', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 71 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 71 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 71 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('04cff6a4-5378-9982-fb1b-f370e143d86c', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 72 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 72 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='04cff6a4-5378-9982-fb1b-f370e143d86c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bd73a94d-a885-a205-23bc-251b191e4a20', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 72 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 72 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 72 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b2ccc1f9-45b5-72a6-35c5-ddc9d2e6239d', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 73 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 73 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b2ccc1f9-45b5-72a6-35c5-ddc9d2e6239d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('81c2c722-df28-1ea5-21ba-c4a409946ac4', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 73 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 73 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 73 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6873ee2b-18c5-4a0f-ed0d-24eab3457b9e', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 74 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 74 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6873ee2b-18c5-4a0f-ed0d-24eab3457b9e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a1904ae6-f227-45cc-495d-2a5b71b66e2f', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 74 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 74 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 74 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('56a80b20-8c69-0913-5fe5-bd9ab8d15ca7', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 77 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 77 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='56a80b20-8c69-0913-5fe5-bd9ab8d15ca7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('da5de39a-2d75-ab70-d6ca-20dbbb69d265', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 77 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 77 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 77 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('78cd0544-3340-300d-419f-657cf55b6f28', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 78 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 78 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='78cd0544-3340-300d-419f-657cf55b6f28');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6aef0976-7dbf-043b-c346-2b7ca11c3aa6', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 78 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 78 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 78 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('68af356b-0a3d-cb48-9727-43d8f2f48e31', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 79 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 79 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='68af356b-0a3d-cb48-9727-43d8f2f48e31');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2580bddc-0e20-8abc-c832-e10e1f1968ac', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 79 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 79 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 79 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('75c5d65e-22bb-bdf9-7638-7199de5b20d0', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 80 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 80 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='75c5d65e-22bb-bdf9-7638-7199de5b20d0');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('333be7e9-b23a-5d07-866f-a358070f415e', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 80 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 80 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 80 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a04aa290-9bae-4ab2-639a-74bf07eb6995', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 81 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 81 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a04aa290-9bae-4ab2-639a-74bf07eb6995');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6b0256f3-9152-dd35-e642-4a1e7bf29b52', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 81 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 81 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 81 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0578b2c1-b905-4707-79cb-64a404170dd7', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 84 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 84 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0578b2c1-b905-4707-79cb-64a404170dd7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bf9878a2-20ff-ea36-9df6-a174e3dfe3fe', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 84 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 84 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 84 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a6f6cdee-357b-761a-cddd-ca36514cf44a', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 85 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 85 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a6f6cdee-357b-761a-cddd-ca36514cf44a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3d5eb5d1-a292-f4e7-362e-321585361350', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 85 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 85 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 85 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7622792a-ff85-fa07-66da-7d4f8f0a4dba', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 86 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 86 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7622792a-ff85-fa07-66da-7d4f8f0a4dba');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ba661960-7291-4dc1-cde4-c04019e1a573', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 86 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 86 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 86 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('79b76945-5502-10de-e9f2-68f2f458d511', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 87 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 87 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='79b76945-5502-10de-e9f2-68f2f458d511');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8124f93a-a151-ed83-5eef-3b1d1641ea10', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 87 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 87 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 87 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4be93fb7-9e9a-813c-8c43-80c52d1b7ecc', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 88 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4be93fb7-9e9a-813c-8c43-80c52d1b7ecc');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('41291833-c5a0-02b9-3e5c-6cc3f4f7d608', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 88 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 88 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 88 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9389b753-02db-d642-a7e0-df98531b4b3d', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 31 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 31 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9389b753-02db-d642-a7e0-df98531b4b3d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('00413b8d-faad-786a-1ff7-a0cbe89ed803', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 31 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 31 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 31 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('15de59de-2f96-dd33-cd5c-263bd5cd8e18', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 32 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 32 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='15de59de-2f96-dd33-cd5c-263bd5cd8e18');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ab864b82-281f-23bc-38da-288ae8ef6289', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 32 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 32 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 32 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ac441c97-de55-b8c3-5550-5663b630d4c4', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 35 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ac441c97-de55-b8c3-5550-5663b630d4c4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f927b69e-f009-587d-e1d5-d15a833028e4', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 35 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 35 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 35 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d4cdd730-2835-fa4b-e9b0-43b8c96a7889', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 36 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 36 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d4cdd730-2835-fa4b-e9b0-43b8c96a7889');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a48fb028-8c78-9a2d-842e-f19252823417', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 36 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 36 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 36 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3cd22a12-43a4-0af0-4fe7-242135472767', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 37 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 37 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3cd22a12-43a4-0af0-4fe7-242135472767');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6069160c-87b8-531b-0a95-c64d93527253', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 37 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 37 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 37 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d4339018-19d1-2350-6434-919c9f9ac718', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 38 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 38 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d4339018-19d1-2350-6434-919c9f9ac718');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8a94a5ca-3b77-458e-df3b-65ad6c9d5dd4', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 38 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 38 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 38 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d1dce29d-29de-655b-d2bc-93524a6c5f1c', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 39 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 39 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d1dce29d-29de-655b-d2bc-93524a6c5f1c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0c20c4d2-00c1-27b3-609d-7c0d1c0fac05', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 39 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 39 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 39 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bc63cf31-fcb0-f8fd-b91f-3c9c9d477223', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 42 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 42 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bc63cf31-fcb0-f8fd-b91f-3c9c9d477223');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('321d977a-0db7-5714-c037-87c3318a36f7', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 42 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 42 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 42 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('31865c87-8b31-c5b9-5df2-aeb394490d7f', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 43 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 43 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='31865c87-8b31-c5b9-5df2-aeb394490d7f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fa25bd9d-9ba2-a607-0689-d2ea8f15fd88', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 43 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 43 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 43 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ea138eb6-61ff-3bef-d372-452ac8f9fd63', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 44 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 44 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ea138eb6-61ff-3bef-d372-452ac8f9fd63');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('637cc695-eb1d-3b9e-e994-037a713ad275', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 44 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 44 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 44 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3f6387bc-a3c1-3bc5-4aa8-1c1775726dfc', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 45 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 45 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3f6387bc-a3c1-3bc5-4aa8-1c1775726dfc');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9cd28e3a-e6a8-f876-101e-03ab2e5dfdf7', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 45 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 45 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('dc6d7186-832f-1157-4987-d90a27e20632', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 46 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 46 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='dc6d7186-832f-1157-4987-d90a27e20632');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('876de80b-ccd3-afc9-74ba-4a513b76b4db', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 46 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 46 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 46 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('50ebd3c9-5ec8-a249-f284-86d92551639b', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 49 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 49 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='50ebd3c9-5ec8-a249-f284-86d92551639b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('45e8a9e9-5c2a-ff74-bd7c-b2c67fcb9cd5', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 49 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 49 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 49 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0a31ea7b-5881-052e-4246-5922f232bd8c', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 50 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 50 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0a31ea7b-5881-052e-4246-5922f232bd8c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('319af6fb-99b3-cf56-b51d-63e13b6bb20b', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 50 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 50 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 50 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('25a5b203-9ee0-7c81-35d4-fed9e97117a6', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 51 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 51 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='25a5b203-9ee0-7c81-35d4-fed9e97117a6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b01674b0-80b7-30e9-e770-7875665762df', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 51 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 51 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 51 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('02551bb9-2b32-9005-3dc2-2a1b3ba12a52', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 52 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 52 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='02551bb9-2b32-9005-3dc2-2a1b3ba12a52');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c80f6425-5157-2985-141f-4dc82526484c', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 52 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 52 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 52 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('37acb677-1564-6fa5-f1aa-16073b442650', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 53 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 53 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='37acb677-1564-6fa5-f1aa-16073b442650');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('80982eb0-55e2-322d-9c3e-6f0b244f1c73', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 53 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 53 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 53 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('65d5b88b-ce24-ccfd-853c-69cc4fc728df', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 56 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 56 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='65d5b88b-ce24-ccfd-853c-69cc4fc728df');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ef6f1184-8c59-2f12-0aa0-67ec87a48b9e', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 56 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 56 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 56 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('794b5c17-c403-ef8e-7024-ea668b18dc62', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 57 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 57 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='794b5c17-c403-ef8e-7024-ea668b18dc62');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b0f42981-875c-351a-7a8f-994314bafe3c', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 57 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 57 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 57 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('111e487c-8778-541c-9049-af92db43a0d1', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 58 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 58 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='111e487c-8778-541c-9049-af92db43a0d1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6e36e665-b58c-c4d0-09c9-def9baecc0a5', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 58 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 58 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 58 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0907acf0-f8d2-aea3-41d8-3758bfacadda', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0907acf0-f8d2-aea3-41d8-3758bfacadda');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7500b6bb-03a5-264b-a5ef-ba53ea3ae575', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 1 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 1 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('98bb1784-9f92-b351-fad3-4875fbd7092f', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 2 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='98bb1784-9f92-b351-fad3-4875fbd7092f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2a747f35-f6fa-4962-8f43-947edffd91e2', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 2 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 2 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0aee4c7b-1780-7953-51b6-b4584f57c0a4', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 3 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0aee4c7b-1780-7953-51b6-b4584f57c0a4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a060b9f2-2ffb-ef1c-32af-3363e65e3784', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 3 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 3 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0bbcf9a5-3047-82f1-64a2-e1b6599fe60c', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 4 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0bbcf9a5-3047-82f1-64a2-e1b6599fe60c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ec753634-1e64-0a41-7f63-72847cf89ad8', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 4 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 4 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c7f4f5ef-ed76-b517-cd74-3996772b31d9', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 7 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c7f4f5ef-ed76-b517-cd74-3996772b31d9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8b3d97ec-3da2-987d-1df1-9806a199f28d', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 7 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 7 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 7 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b3a28b75-0ead-ed0a-0a18-66214e5a269c', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 8 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 8 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b3a28b75-0ead-ed0a-0a18-66214e5a269c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('19101078-522b-38c7-9e90-fac193dbaac5', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 8 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 8 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 8 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('aa7568a6-a5d8-09aa-1619-997c1f4f6caf', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 9 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='aa7568a6-a5d8-09aa-1619-997c1f4f6caf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ced531e7-b5b9-c7b9-f69f-f3fe61ec2692', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 9 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 9 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 9 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a6944db5-8426-f6af-4817-a0e3d4037054', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 10 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 10 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a6944db5-8426-f6af-4817-a0e3d4037054');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3330224b-5c9b-2000-57a2-c00c1987db71', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 10 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 10 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fdbe4efa-2976-66f8-ca5d-a4da958c2cae', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 11 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fdbe4efa-2976-66f8-ca5d-a4da958c2cae');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('63bddc1f-d689-2b16-fd7c-fa42fc42bebc', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 11 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 11 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 11 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2dd9bfd4-16da-3441-9da5-0c75d65173b6', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 14 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2dd9bfd4-16da-3441-9da5-0c75d65173b6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ae25eaf1-f393-aa42-e51a-a11ca93ef03c', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 14 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 14 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 14 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('17843b9a-be60-db86-0209-1ca94d02f50d', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 15 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 15 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='17843b9a-be60-db86-0209-1ca94d02f50d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b187b55f-0307-f353-d616-950985a2ed36', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 15 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 15 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e11df74f-e92b-2812-a164-9be05290a1df', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 16 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e11df74f-e92b-2812-a164-9be05290a1df');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('23c0b5cd-0b11-0dca-eac7-18049d48e150', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 16 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 16 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 16 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8b936100-740a-aa7d-1cdc-9a71d6995384', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 17 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 17 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8b936100-740a-aa7d-1cdc-9a71d6995384');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a73c2197-a729-63df-268a-e65228f3bb63', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 17 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 17 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 17 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('72fb05ca-e801-a2ec-e061-f53657096981', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 18 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='72fb05ca-e801-a2ec-e061-f53657096981');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0c4f417e-ccac-5ed1-6009-fc27198d633e', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 18 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 18 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 18 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8dc059b4-e4a2-d315-85eb-b8fff624582f', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 21 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8dc059b4-e4a2-d315-85eb-b8fff624582f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ae42f329-32e3-df9a-8a08-091454fdf832', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 21 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 21 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 21 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b2f7db4e-af48-6dc0-cbf7-e724dea04522', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 22 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 22 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b2f7db4e-af48-6dc0-cbf7-e724dea04522');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6ec5116d-659e-5426-22b9-797a2ae1aaee', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 22 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 22 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 22 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('69289d6a-7937-a005-bb48-d9c3ca90665b', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 23 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 23 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='69289d6a-7937-a005-bb48-d9c3ca90665b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c66cec5c-f53a-6a8d-307e-16e64cbb1119', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 23 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 23 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 23 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e5ff26a1-14b0-fab3-d6ad-099b73aebe63', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 24 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e5ff26a1-14b0-fab3-d6ad-099b73aebe63');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('41646f9b-f9cb-d0b4-85d2-57c8391bbb66', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 24 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 24 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 24 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d2229ff0-470c-e867-172d-058388d1e150', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 25 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 25 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d2229ff0-470c-e867-172d-058388d1e150');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('70ae2a35-80a4-d5c4-54f0-ce1cefb4d901', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 25 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 25 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 25 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9d20f80d-2b9e-65ce-b6ba-cc72ba6e94a9', @tid, @uid_waiter, @eid_waiter,
  DATE_SUB(NOW(), INTERVAL 28 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 28 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9d20f80d-2b9e-65ce-b6ba-cc72ba6e94a9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a6ef9aa9-43a4-3072-b611-12fe772ab516', @tid, @eid_waiter, YEAR(DATE_SUB(NOW(), INTERVAL 28 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 28 DAY)),
  'work_earnings', 17600, 'PLN', 8.0, 2200,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c86b77bd-65d7-f02c-cc23-6460792b5a4f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 91 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 91 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c86b77bd-65d7-f02c-cc23-6460792b5a4f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a1242879-4dff-bac3-906f-0fee787d1756', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 91 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 91 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 91 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('474fb2e5-53e8-0fd0-2f50-76b55a383471', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 92 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 92 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='474fb2e5-53e8-0fd0-2f50-76b55a383471');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('141969e3-24a1-2f94-2ea0-199a86af131c', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 92 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 92 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 92 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('22468d04-3c8c-3690-4448-0637abeb14be', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 93 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 93 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='22468d04-3c8c-3690-4448-0637abeb14be');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4a2fef3a-5f4a-0a5b-bfae-6a49cebb53c7', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 93 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 93 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 93 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f3a39737-c114-bff0-5d7c-2ff3d4aa0caa', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 94 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 94 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f3a39737-c114-bff0-5d7c-2ff3d4aa0caa');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('84dd5eae-d4ce-4eb2-ce21-16c3741156d0', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 94 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 94 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 94 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4009fd83-37ad-f232-a441-c08069e8c6a6', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 95 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 95 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4009fd83-37ad-f232-a441-c08069e8c6a6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('681c8937-c483-86c0-98c8-04beb8033453', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 95 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 95 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 95 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ce955afd-9296-1f74-b331-947ba133d22c', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 98 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 98 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ce955afd-9296-1f74-b331-947ba133d22c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d39a3b3b-b765-970a-2c8f-eae6dc0901d9', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 98 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 98 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 98 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e3e67b8c-36ac-44c0-16a1-3cbdee9bf1f3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 99 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 99 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e3e67b8c-36ac-44c0-16a1-3cbdee9bf1f3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d5e3a16b-1f7b-d3ef-ea7f-1f27ce76c05f', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 99 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 99 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 99 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0c3b828b-16ec-caa8-ae93-d34b000e599f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 100 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 100 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0c3b828b-16ec-caa8-ae93-d34b000e599f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8c5f8df8-1c9e-5e8a-8ad8-6054707aa1ea', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 100 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 100 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 100 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2cf6588b-2d2e-d4c5-06f1-99796446930b', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 101 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 101 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2cf6588b-2d2e-d4c5-06f1-99796446930b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('badd6cf3-3b46-0412-8024-b41c424a3dee', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 101 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 101 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 101 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b79a4019-d4dc-5ae0-1dc8-b3df4b7e8ed3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 102 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 102 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b79a4019-d4dc-5ae0-1dc8-b3df4b7e8ed3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d3175c95-be96-f06c-5f92-205fe162a6a8', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 102 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 102 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 102 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f2383577-3af4-e1ed-5dca-70675324ea4f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 105 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 105 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f2383577-3af4-e1ed-5dca-70675324ea4f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('eda9b937-2409-8cea-663a-e7a24349f9e2', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 105 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 105 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 105 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4c8bd626-f321-4043-4945-525919e18d6f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 106 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 106 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4c8bd626-f321-4043-4945-525919e18d6f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9c24b45f-b586-b377-a0d9-51baee2e887e', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 106 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 106 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 106 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f5ed083b-6977-c112-bcbd-f1340915145b', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 107 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 107 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f5ed083b-6977-c112-bcbd-f1340915145b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f7eaea47-d280-f8d9-d81b-a5cd59bba021', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 107 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 107 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 107 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('25be289f-0491-d3d2-b8aa-353c1df0bb7e', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 108 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 108 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='25be289f-0491-d3d2-b8aa-353c1df0bb7e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('02115e55-e597-c311-326a-50ce027c7893', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 108 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 108 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 108 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bf2d177b-f6b7-c707-4776-8daaf6208c40', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 109 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 109 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bf2d177b-f6b7-c707-4776-8daaf6208c40');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6e728eaa-c504-c635-81d3-e7d7e079c201', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 109 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 109 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 109 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e1fac412-806a-5c8a-42d1-10094c198132', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 112 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e1fac412-806a-5c8a-42d1-10094c198132');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4f52c35c-2340-8341-0781-ecbdd5b4134f', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 112 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 112 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 112 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2a6e2720-266f-dae2-8617-5419c1c64a85', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 113 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 113 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2a6e2720-266f-dae2-8617-5419c1c64a85');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('43e5158a-de15-aeb9-4b58-ddad51159c59', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 113 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 113 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 113 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d6d1c370-9b2c-c980-c9f5-a0f2ffda3698', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 114 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 114 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d6d1c370-9b2c-c980-c9f5-a0f2ffda3698');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0aa89c41-d79d-c615-b4c7-2b9c8a82035f', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 114 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 114 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 114 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('70947edd-d00f-4d4d-0c2d-798cce460b89', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 115 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 115 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='70947edd-d00f-4d4d-0c2d-798cce460b89');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('48a1ed75-8e15-a851-79f6-62c96273e287', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 115 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 115 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 115 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a1b6ea6e-4327-f9c9-6c7e-17c9690910be', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 116 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 116 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a1b6ea6e-4327-f9c9-6c7e-17c9690910be');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e49efbce-2cf9-162c-6db0-131d0abae121', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 116 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 116 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 116 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6c700983-2848-99e9-1335-8261a8b1aa07', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 63 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 63 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6c700983-2848-99e9-1335-8261a8b1aa07');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('81df9db5-ebac-21f7-8a45-06e924a02689', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 63 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 63 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 63 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cc9dd147-6bd4-6c7c-f60b-3cb171c5b67f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 64 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 64 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cc9dd147-6bd4-6c7c-f60b-3cb171c5b67f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c1ebfe90-782f-27a7-f81b-e429a1e546ca', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 64 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 64 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 64 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4dde2943-75a1-c44e-e871-3aa88a705202', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 65 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 65 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4dde2943-75a1-c44e-e871-3aa88a705202');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('6fe23b09-124a-6b01-e8c2-aab7eb0ac6f4', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 65 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 65 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 65 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('abe7d391-2767-69aa-0247-d55e619dd5b3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 66 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 66 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='abe7d391-2767-69aa-0247-d55e619dd5b3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('af055801-1ef9-ab18-69cd-38b3a8e0ac96', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 66 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 66 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 66 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('935fc846-3f7e-4468-bcb7-24a23beed915', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 67 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 67 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='935fc846-3f7e-4468-bcb7-24a23beed915');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e71f82dd-d135-b545-d56c-62e22f8ed844', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 67 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 67 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 67 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b1e49adc-edab-f849-124d-36b0a7a3e289', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 70 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 70 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b1e49adc-edab-f849-124d-36b0a7a3e289');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2ac729d3-1d92-c81a-439e-f2cb88861f8e', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 70 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 70 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fb68675a-90db-fdd9-7e24-a97fe4f3698d', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 71 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 71 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fb68675a-90db-fdd9-7e24-a97fe4f3698d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('77e9a596-42be-5fb8-d7fc-9e28be213465', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 71 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 71 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 71 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9c17a0e0-cebf-3814-fee9-d5518800ffb8', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 72 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 72 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9c17a0e0-cebf-3814-fee9-d5518800ffb8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f902208e-8f06-0f6a-06cf-6fd0f936a6a8', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 72 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 72 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 72 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6b94047f-d91c-008a-612c-08ed2fdc85a3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 73 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 73 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6b94047f-d91c-008a-612c-08ed2fdc85a3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('aac6286d-26ca-46ce-0f30-e7ae555a9626', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 73 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 73 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 73 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('be621501-b978-3658-e14c-39ea7aeac235', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 74 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 74 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='be621501-b978-3658-e14c-39ea7aeac235');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('dbc53088-be57-aef0-9f99-018ee1bc7e77', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 74 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 74 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 74 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b55be1bd-0da9-ad0b-fe38-9cd25576ca36', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 77 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 77 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b55be1bd-0da9-ad0b-fe38-9cd25576ca36');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('10f47a04-3321-5ca9-a329-26ad5d86025a', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 77 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 77 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 77 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('dfe394cb-86cf-c906-d2c1-11e109557952', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 78 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 78 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='dfe394cb-86cf-c906-d2c1-11e109557952');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4c8bfb0a-e709-1c24-339d-2e4200ae5df5', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 78 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 78 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 78 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a7e31d04-0336-1789-83c5-7f536ac2a4c8', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 79 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 79 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a7e31d04-0336-1789-83c5-7f536ac2a4c8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3472f443-15ae-6c72-fcdc-16bd3dd9dcfd', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 79 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 79 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 79 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('69e06ee4-c337-0dce-8e2f-29a4d59f4500', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 80 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 80 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='69e06ee4-c337-0dce-8e2f-29a4d59f4500');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('49bdb2ed-8417-79d4-1db7-0ba61791e387', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 80 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 80 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 80 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a7cd7b62-2ea7-44d4-3d41-17afc03d8e7a', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 81 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 81 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a7cd7b62-2ea7-44d4-3d41-17afc03d8e7a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c4a28520-291b-f3c5-5988-55f5a5bc2cd3', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 81 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 81 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 81 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6332ed7a-f174-452c-6822-5b7a12ceb480', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 84 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 84 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6332ed7a-f174-452c-6822-5b7a12ceb480');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('01db23e3-0286-fa23-716c-368cf9104e8d', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 84 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 84 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 84 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ae493dd0-c3f5-d43a-532a-f2e9c887e430', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 85 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 85 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ae493dd0-c3f5-d43a-532a-f2e9c887e430');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('598f995d-e4f8-d83f-6869-345e988d22a7', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 85 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 85 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 85 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2caedd37-e407-1134-e204-13da80f300b8', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 86 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 86 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2caedd37-e407-1134-e204-13da80f300b8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('cc332a69-69a8-2b31-0c98-c482e8feb054', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 86 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 86 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 86 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a312c8a8-ae87-d06e-6b82-c1d79786c6b7', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 87 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 87 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a312c8a8-ae87-d06e-6b82-c1d79786c6b7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8d1d0bd6-bea8-7ce9-32be-c3209b8184e2', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 87 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 87 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 87 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8e57a96d-49bf-8f69-330f-91588aa09db2', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 88 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8e57a96d-49bf-8f69-330f-91588aa09db2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('91043373-3887-7c30-33c7-5839bf552dd5', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 88 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 88 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 88 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('463f093d-a9fa-265a-2d5d-c0c8b1d9bd55', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 31 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 31 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='463f093d-a9fa-265a-2d5d-c0c8b1d9bd55');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ec38d1a6-1413-a6d9-aec8-134cc4ff46f1', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 31 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 31 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 31 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fc2f44ac-45cd-1721-5214-e00029123dd3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 32 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 32 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fc2f44ac-45cd-1721-5214-e00029123dd3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('697d0e17-5ca3-d050-b06c-e9f3c6ac7e1e', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 32 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 32 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 32 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3b01efd1-8171-cf53-f121-2044520f8174', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 35 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3b01efd1-8171-cf53-f121-2044520f8174');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('efd91a68-0973-7e6e-98fe-211fd2d48817', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 35 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 35 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 35 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a372ddd6-d9b8-ddb4-5b3d-b5cd0a776725', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 36 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 36 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a372ddd6-d9b8-ddb4-5b3d-b5cd0a776725');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7d52e6df-db8a-e361-484e-970a3345e4d8', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 36 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 36 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 36 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ac606b4d-7029-1066-720f-63e33b7278ba', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 37 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 37 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ac606b4d-7029-1066-720f-63e33b7278ba');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b8b91b45-004e-fccf-c39e-46bee0c11f25', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 37 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 37 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 37 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('eb410abd-d87c-95ab-d216-bdcd5d61f152', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 38 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 38 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='eb410abd-d87c-95ab-d216-bdcd5d61f152');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1dd0ebce-2529-006a-db0e-3d2d11e2ebea', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 38 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 38 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 38 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5db6a869-9a03-aed4-d43c-77543e017997', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 39 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 39 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5db6a869-9a03-aed4-d43c-77543e017997');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('05c4249d-5198-803f-4b4a-fb591d8875ca', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 39 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 39 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 39 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f2f0bd57-af64-1989-9613-65e3f962cf9d', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 42 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 42 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f2f0bd57-af64-1989-9613-65e3f962cf9d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('df85ff2c-5038-0feb-3fe0-811d757ca628', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 42 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 42 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 42 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('742ed24a-ffe1-1ba1-0260-b0aa7e4e1f14', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 43 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 43 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='742ed24a-ffe1-1ba1-0260-b0aa7e4e1f14');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d2d92dc9-22f4-4476-9d18-5b3cd9cb0ad3', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 43 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 43 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 43 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bc763811-0759-4bfa-01ea-a74e8eb390c3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 44 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 44 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bc763811-0759-4bfa-01ea-a74e8eb390c3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e789265d-454e-55a6-3895-2d7f815deb46', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 44 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 44 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 44 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c2d969cd-6c24-941e-0a7f-f621c3b8e0e8', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 45 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 45 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c2d969cd-6c24-941e-0a7f-f621c3b8e0e8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('dd912365-a2cf-472a-78c2-1b9a307171c5', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 45 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 45 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('61b89450-e55b-161d-6290-674af00f062d', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 46 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 46 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='61b89450-e55b-161d-6290-674af00f062d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('baa7e111-9d13-a0cc-cfe0-d7e669470d7c', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 46 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 46 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 46 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fbb6e3c1-5b3b-da08-d52b-75b766923564', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 49 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 49 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fbb6e3c1-5b3b-da08-d52b-75b766923564');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8620b086-4905-9ccd-7b6c-1a220160299f', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 49 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 49 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 49 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b2c4acf1-ae5b-75c8-ddda-280e979d35ae', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 50 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 50 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b2c4acf1-ae5b-75c8-ddda-280e979d35ae');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f0091416-c06f-f72c-64a4-fef6f92feea9', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 50 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 50 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 50 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('da67c225-8680-4012-e89a-87af24ff404e', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 51 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 51 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='da67c225-8680-4012-e89a-87af24ff404e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5a6212b3-1ef0-ea44-cc55-63df8ff40071', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 51 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 51 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 51 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('aa122ffe-4bb2-2e95-e6bd-7c470025fc6c', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 52 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 52 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='aa122ffe-4bb2-2e95-e6bd-7c470025fc6c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('eb7bd24b-3715-74ed-33a9-bc85baf41f9d', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 52 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 52 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 52 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('31dd0c50-d895-03e6-776c-87d6d1060bd3', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 53 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 53 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='31dd0c50-d895-03e6-776c-87d6d1060bd3');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2a02b706-82cc-d991-687f-2551b6af61f5', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 53 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 53 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 53 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('98bc95e4-af64-4cea-9c33-4a889cee35eb', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 56 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 56 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='98bc95e4-af64-4cea-9c33-4a889cee35eb');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4b629b9b-4539-a3b1-fd8d-d2221dbd56d7', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 56 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 56 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 56 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('14edcd67-3391-2134-169a-4e845cd2d1df', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 57 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 57 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='14edcd67-3391-2134-169a-4e845cd2d1df');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7ebb848b-13d7-be74-5d01-75f335a164d4', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 57 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 57 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 57 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ea96b35c-68e9-69e8-d4a8-b63121a29c2c', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 58 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 58 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ea96b35c-68e9-69e8-d4a8-b63121a29c2c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8e89fd74-dce4-116d-7427-0fa65ec95a4d', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 58 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 58 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 58 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6ed78d31-3100-2b50-5fe0-94cb8049f513', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6ed78d31-3100-2b50-5fe0-94cb8049f513');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('919351af-07b5-0785-2c2b-28ab0a45a6c4', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 1 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 1 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('63d3b3db-1223-a8af-be1d-93a861d3979d', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 2 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='63d3b3db-1223-a8af-be1d-93a861d3979d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2140a093-2802-5b7a-d0c7-14392f89d43b', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 2 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 2 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b366ae72-6ed4-a6ea-7b06-684e131faa4b', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 3 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b366ae72-6ed4-a6ea-7b06-684e131faa4b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('0d5bb54a-452f-f49c-a146-ae65af979bca', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 3 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 3 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2b6e86b2-81d4-7ffe-0cbe-a79ed6194d6f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 4 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2b6e86b2-81d4-7ffe-0cbe-a79ed6194d6f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f1029b8e-c511-197a-4ccc-1c9f164dcc86', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 4 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 4 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6547b023-bf84-9f3c-e941-7a7cb53bc606', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 7 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6547b023-bf84-9f3c-e941-7a7cb53bc606');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c9baac53-68be-f8bf-f415-26b69f8b5644', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 7 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 7 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 7 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8a34e312-7210-8cbe-6ebf-67f872054d44', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 8 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 8 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8a34e312-7210-8cbe-6ebf-67f872054d44');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('95ed697c-2a2e-5e8b-c740-4a9f55adfe78', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 8 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 8 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 8 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9b165e5e-faf1-9909-8d8a-0a2a50c41961', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 9 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9b165e5e-faf1-9909-8d8a-0a2a50c41961');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b7e322e9-c6d8-ee0a-c7c7-80d5b20c2f44', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 9 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 9 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 9 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7135ba9e-56dd-e007-2233-b24449f21aa1', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 10 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 10 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7135ba9e-56dd-e007-2233-b24449f21aa1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fcc7e8c4-a24d-3e2e-933c-923af355d0e0', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 10 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 10 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bcc937d9-3365-1245-2983-0f0988942f46', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 11 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bcc937d9-3365-1245-2983-0f0988942f46');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a7eab751-4224-a46e-5a9b-33ad9d4ebc37', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 11 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 11 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 11 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('34a6c80a-fd71-60a1-cd7c-29db97e8a25c', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 14 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='34a6c80a-fd71-60a1-cd7c-29db97e8a25c');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9f286a6a-3e84-4ed0-fb59-588a8f08ae80', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 14 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 14 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 14 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c09fcb2f-d8ee-d1ae-2e4d-b8a7f057ef92', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 15 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 15 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c09fcb2f-d8ee-d1ae-2e4d-b8a7f057ef92');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3edab1c6-df08-ef36-38c2-42ce120e8cc5', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 15 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 15 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8a24e57b-9986-3cc6-b9ff-cd4f7bd81391', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 16 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8a24e57b-9986-3cc6-b9ff-cd4f7bd81391');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2d2f6a15-0438-b2e7-61e8-9ca969e5cb5c', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 16 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 16 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 16 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5d6e496b-fe98-5b3d-8676-fcba0c208b43', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 17 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 17 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5d6e496b-fe98-5b3d-8676-fcba0c208b43');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ff2fc283-c385-75e2-cfe6-a18e5be22b5b', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 17 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 17 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 17 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5735ff51-0a11-b40b-b962-508512009d0f', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 18 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5735ff51-0a11-b40b-b962-508512009d0f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ce21b882-5935-38ba-266e-fec8d57bfc08', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 18 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 18 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 18 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('44284025-57ac-44c2-fc61-721449685c93', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 21 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='44284025-57ac-44c2-fc61-721449685c93');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5f9b700e-85d7-7cbb-4244-5adcba835a32', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 21 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 21 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 21 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6072ecb0-6567-318b-932c-e12e8a2af2cf', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 22 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 22 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6072ecb0-6567-318b-932c-e12e8a2af2cf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b3d498f2-9498-ccd0-591e-a179e0044b6f', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 22 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 22 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 22 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4aa72537-582c-2a19-4daa-18ffc64de4c5', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 23 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 23 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4aa72537-582c-2a19-4daa-18ffc64de4c5');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b5f306db-f7d0-29e2-32ba-85ce0025ae0a', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 23 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 23 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 23 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3486d075-0811-c57f-edaf-86cd1143e470', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 24 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3486d075-0811-c57f-edaf-86cd1143e470');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('54405237-048d-d8d8-865c-9253e79a76a3', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 24 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 24 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 24 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c4d4f7c8-f6ca-afda-333d-94006a70ab37', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 25 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 25 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c4d4f7c8-f6ca-afda-333d-94006a70ab37');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('51d85489-807c-b326-5c11-6818bc357c65', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 25 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 25 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 25 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ad757f57-7236-2f73-e0a5-6dc60d7af809', @tid, @uid_cook, @eid_cook,
  DATE_SUB(NOW(), INTERVAL 28 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 28 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ad757f57-7236-2f73-e0a5-6dc60d7af809');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4acb32d2-aae8-f757-c23b-bb43f6b64568', @tid, @eid_cook, YEAR(DATE_SUB(NOW(), INTERVAL 28 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 28 DAY)),
  'work_earnings', 20000, 'PLN', 8.0, 2500,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6b403bd8-ff6e-84c7-bae3-0989ea7dd5af', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 91 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 91 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6b403bd8-ff6e-84c7-bae3-0989ea7dd5af');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ae6a718c-db1c-a584-1a6b-70dc6fd88e44', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 91 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 91 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 91 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c8ac0fe1-8b79-5163-b9b1-67bf971e2803', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 92 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 92 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c8ac0fe1-8b79-5163-b9b1-67bf971e2803');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9f26d0d7-6839-fa8e-96ee-667e546e91a7', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 92 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 92 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 92 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9ba53b8f-5f6e-0ce3-ee72-b8d68c722857', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 93 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 93 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9ba53b8f-5f6e-0ce3-ee72-b8d68c722857');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8e138c0f-6eed-28ef-ae6d-2c75788f3498', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 93 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 93 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 93 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('22023f2f-d26b-8868-26d4-b413f14aa0e7', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 94 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 94 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='22023f2f-d26b-8868-26d4-b413f14aa0e7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9b06623a-896a-d2e1-4334-890770dd9d80', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 94 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 94 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 94 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bd6dc73d-f4b3-9d46-300c-414e487b3c17', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 95 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 95 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bd6dc73d-f4b3-9d46-300c-414e487b3c17');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c6dafec3-99e6-9b52-3547-c5737db1e733', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 95 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 95 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 95 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('7441cac8-abda-4dff-80ce-854cedc6007a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 98 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 98 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='7441cac8-abda-4dff-80ce-854cedc6007a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('03a90ce3-60a0-2dda-9478-42503572a26e', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 98 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 98 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 98 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('66d3a4ee-8445-56f0-0751-e5f2b90b5f64', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 99 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 99 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='66d3a4ee-8445-56f0-0751-e5f2b90b5f64');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('32be033d-e7dd-2be2-c1a2-296be7f2cff1', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 99 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 99 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 99 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('1bbcedf3-44af-01a7-542f-fb771dee20c5', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 100 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 100 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='1bbcedf3-44af-01a7-542f-fb771dee20c5');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('957a054d-d8e2-806c-2aeb-f349583e69ab', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 100 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 100 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 100 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('19f6a6dc-d4f1-7608-909d-0d05c7a62b55', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 101 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 101 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='19f6a6dc-d4f1-7608-909d-0d05c7a62b55');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a65c9831-6301-a518-4b9c-c2ce77a049b3', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 101 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 101 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 101 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('62fc4cbd-b848-bbb8-9270-9a248ad918f6', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 102 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 102 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='62fc4cbd-b848-bbb8-9270-9a248ad918f6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('98c4bc3c-3bcf-6d95-5c30-735fc582acf1', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 102 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 102 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 102 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5066cd0a-e04c-d96c-d59c-02831959806b', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 105 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 105 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5066cd0a-e04c-d96c-d59c-02831959806b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('85d38ebb-199e-d561-0a0b-22347e2962ca', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 105 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 105 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 105 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('23be0789-70ec-e494-71ae-50741d537a11', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 106 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 106 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='23be0789-70ec-e494-71ae-50741d537a11');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('35bb576c-400c-c328-f152-8e80f706ea21', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 106 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 106 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 106 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('50b9b426-a645-e4a3-d48b-704ab9c7d634', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 107 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 107 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='50b9b426-a645-e4a3-d48b-704ab9c7d634');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e98e5b73-5ebc-f43c-321b-a1991d26f94d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 107 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 107 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 107 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('08daebaa-a494-a746-d917-21245a5cc3e6', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 108 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 108 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='08daebaa-a494-a746-d917-21245a5cc3e6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('efadfff5-aa7b-3325-757c-f93fb647026a', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 108 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 108 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 108 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ad4ada6b-5da9-ca09-25bc-3ea725d9feae', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 109 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 109 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ad4ada6b-5da9-ca09-25bc-3ea725d9feae');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('dcdf7922-0882-a883-399d-e84cbfa487d5', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 109 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 109 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 109 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ad55948a-d0ec-8da0-2678-19a30549afb9', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 112 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ad55948a-d0ec-8da0-2678-19a30549afb9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bc850b1d-53f1-cda9-b6d1-7fa6678a6fda', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 112 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 112 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 112 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3a03cc67-74e5-caed-7546-99e1c975fcb0', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 113 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 113 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3a03cc67-74e5-caed-7546-99e1c975fcb0');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3e462ab6-83c8-5c7a-f10c-07c723fb482e', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 113 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 113 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 113 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c4ffde60-0101-62df-469b-77ff5c2375a9', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 114 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 114 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c4ffde60-0101-62df-469b-77ff5c2375a9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('358de4a6-1853-fb44-da21-bcbb150756cb', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 114 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 114 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 114 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('fe309214-f836-79c0-e2a9-c1c14d66784a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 115 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 115 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='fe309214-f836-79c0-e2a9-c1c14d66784a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('8bdb9419-25d2-0d75-fa71-d04219928d35', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 115 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 115 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 115 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f41afcd1-60b4-6a10-2d4a-e4d53348464d', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 116 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 116 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f41afcd1-60b4-6a10-2d4a-e4d53348464d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('f39a19a3-ebad-a4df-41ab-b83977f53cf6', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 116 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 116 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 116 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a2c76c11-20a2-8cdd-e99b-f2341fb1bc6d', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 63 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 63 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a2c76c11-20a2-8cdd-e99b-f2341fb1bc6d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('77010571-9fb6-a2d3-f960-fd4c64f54f3a', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 63 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 63 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 63 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('bafec380-95d3-4323-10c6-6eee18fda516', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 64 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 64 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='bafec380-95d3-4323-10c6-6eee18fda516');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('eef540f0-7f3c-0d88-d574-25c47ef91127', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 64 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 64 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 64 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('921ee198-c923-1c44-9c07-19878afee302', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 65 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 65 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='921ee198-c923-1c44-9c07-19878afee302');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('9dcb77a7-a12e-ce6f-0a03-c79384934b9e', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 65 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 65 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 65 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('aba19590-bedc-5c2c-4e1f-ac4d5bc82730', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 66 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 66 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='aba19590-bedc-5c2c-4e1f-ac4d5bc82730');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c8e769ba-3c48-a661-5e4f-23115c18aaca', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 66 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 66 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 66 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d2c6311b-fe3e-f1ea-4e8c-c133afba2488', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 67 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 67 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d2c6311b-fe3e-f1ea-4e8c-c133afba2488');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b37f4207-3f26-c7d5-fb9d-fe89b478d0ac', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 67 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 67 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 67 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('53cc198f-267a-b8f1-d47b-41d833539cf4', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 70 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 70 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='53cc198f-267a-b8f1-d47b-41d833539cf4');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fc09cb5f-9bba-b8e1-c82f-9af665e6c5dd', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 70 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 70 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d0452668-5ab4-6a24-655f-63d706911edc', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 71 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 71 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d0452668-5ab4-6a24-655f-63d706911edc');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('a25e66ff-6b48-5a8d-19a7-5e6314c3e5f9', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 71 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 71 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 71 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4b47cbd2-2a7a-8178-71bf-b9f8cab479ae', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 72 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 72 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4b47cbd2-2a7a-8178-71bf-b9f8cab479ae');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7afca546-1f6e-1580-39bd-cf6f5b30838f', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 72 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 72 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 72 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('13deccf6-2ca8-ee78-0bdb-7750c3361a49', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 73 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 73 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='13deccf6-2ca8-ee78-0bdb-7750c3361a49');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('86119f44-81ff-4af6-f62a-7da22a10a202', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 73 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 73 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 73 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('769dadcf-94e3-7c14-38b1-94cda0cb74d7', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 74 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 74 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='769dadcf-94e3-7c14-38b1-94cda0cb74d7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('91fcc962-aca4-3a3c-8b71-f6adc2515599', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 74 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 74 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 74 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f9cec15a-5b6c-d593-c0f5-fd97b5a18c17', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 77 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 77 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f9cec15a-5b6c-d593-c0f5-fd97b5a18c17');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b8328c76-66a6-3b3b-c798-8ca0b8c0b8be', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 77 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 77 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 77 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f5ab56e1-a67b-8515-996a-ffe808ed5c16', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 78 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 78 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f5ab56e1-a67b-8515-996a-ffe808ed5c16');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d12b1def-6f0f-d690-7464-2207162a2533', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 78 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 78 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 78 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('56cd1a1f-9414-1ada-f99e-676ef9dc2edf', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 79 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 79 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='56cd1a1f-9414-1ada-f99e-676ef9dc2edf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('89ae4356-4529-3b7c-0c3b-877047401280', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 79 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 79 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 79 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('402a7d80-b508-a6c7-a172-1764d66050e0', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 80 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 80 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='402a7d80-b508-a6c7-a172-1764d66050e0');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ae671a4d-96d8-17cc-a628-4efa16621a5c', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 80 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 80 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 80 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a793e927-5d42-9d1c-182e-6c389c5666e9', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 81 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 81 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a793e927-5d42-9d1c-182e-6c389c5666e9');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c50ce676-151f-688d-592a-6a256ef56707', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 81 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 81 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 81 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2cb8784c-509a-179e-b244-b1a2e7dc736b', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 84 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 84 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2cb8784c-509a-179e-b244-b1a2e7dc736b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3fd45320-c216-bb93-16a4-3b0f1d69eed8', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 84 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 84 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 84 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('52498d9a-e05d-a466-7064-74035d203d0d', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 85 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 85 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='52498d9a-e05d-a466-7064-74035d203d0d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('575ddff2-9b6c-76b2-ea9d-8e8401baef99', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 85 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 85 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 85 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('48835b0f-e487-a3cd-0e96-162da5ffa2a8', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 86 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 86 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='48835b0f-e487-a3cd-0e96-162da5ffa2a8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('7a5dfe13-bb72-739f-4b41-c61bcb2ca98c', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 86 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 86 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 86 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('cdacf97f-bec0-5077-4bb7-d24405e7ff57', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 87 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 87 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='cdacf97f-bec0-5077-4bb7-d24405e7ff57');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1dca13bf-4be7-10c8-ab04-515cf835ce84', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 87 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 87 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 87 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('4f861d19-130e-5d3b-d502-00ccbfb1d73f', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 88 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='4f861d19-130e-5d3b-d502-00ccbfb1d73f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('76088274-da63-9368-2cb4-08c10a3542f2', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 88 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 88 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 88 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a447ff65-3d97-5b3b-6109-7212e5401f14', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 31 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 31 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a447ff65-3d97-5b3b-6109-7212e5401f14');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3d655032-33ca-d777-0cde-a38b21888d76', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 31 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 31 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 31 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('c11579b7-5b07-d6c6-b19d-75e714c33dd6', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 32 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 32 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='c11579b7-5b07-d6c6-b19d-75e714c33dd6');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4d74871c-493e-073f-31a5-b8b0d6f87c6d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 32 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 32 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 32 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('8cbc1efe-5e7e-95ed-a016-b0da1033b585', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 35 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='8cbc1efe-5e7e-95ed-a016-b0da1033b585');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('cfeca356-ae05-edb5-2ab8-5deda221c183', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 35 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 35 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 35 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d1c860a0-2b0e-3830-06e5-4a24b043463e', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 36 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 36 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d1c860a0-2b0e-3830-06e5-4a24b043463e');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('4cb3a0ee-dcc3-b9a9-9811-d48cb34d604f', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 36 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 36 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 36 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9e2630c7-be56-9dce-8aaa-d419820316cf', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 37 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 37 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9e2630c7-be56-9dce-8aaa-d419820316cf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('624a9540-c834-da55-b4f7-22a77f0d7539', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 37 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 37 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 37 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('a61260df-54d8-dad7-3bfc-2bb41012e084', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 38 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 38 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='a61260df-54d8-dad7-3bfc-2bb41012e084');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1890090f-c262-ffd2-45f1-76644702dc8c', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 38 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 38 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 38 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('2ddd5c85-98a8-e52b-ed7d-1e28a7cdcb18', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 39 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 39 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='2ddd5c85-98a8-e52b-ed7d-1e28a7cdcb18');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e47c4104-92d6-b8c6-926e-d1503b809033', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 39 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 39 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 39 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b57e325a-91b1-b4ea-64fb-7e5248a689a2', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 42 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 42 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b57e325a-91b1-b4ea-64fb-7e5248a689a2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('e476d216-59b8-5c6e-180b-9eb93a325ff4', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 42 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 42 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 42 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('0244a3bb-1b60-1c08-ce56-0a018ccae487', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 43 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 43 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='0244a3bb-1b60-1c08-ce56-0a018ccae487');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ea8d47be-3e03-aa5e-1e81-2cf6490f89ec', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 43 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 43 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 43 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('68d8244b-5ed1-26ba-033c-5f0dea7f2bbf', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 44 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 44 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='68d8244b-5ed1-26ba-033c-5f0dea7f2bbf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('32b43bf8-8dca-7ea5-7f6f-37ea794c7a14', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 44 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 44 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 44 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('25e77bb1-e6bf-d1bd-ba02-4fa737d4af3d', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 45 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 45 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='25e77bb1-e6bf-d1bd-ba02-4fa737d4af3d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b2fc85a1-400a-a1a4-e8c4-c35f2c6171b6', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 45 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 45 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 45 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('dd5e872e-ff27-e298-5260-9b36a3074a6a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 46 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 46 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='dd5e872e-ff27-e298-5260-9b36a3074a6a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('937cf0ac-06df-a0b0-5393-2a4c40982afe', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 46 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 46 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 46 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('6c80159c-8ca4-83fd-0685-07c23b579a53', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 49 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 49 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='6c80159c-8ca4-83fd-0685-07c23b579a53');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('bf425cb4-aa3b-e3ef-5ecb-d59e9e931757', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 49 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 49 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 49 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('68531c7b-a542-8b9e-ee98-941f4fb79ec1', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 50 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 50 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='68531c7b-a542-8b9e-ee98-941f4fb79ec1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('3214552d-e194-06fb-6c9d-1e742e55598d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 50 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 50 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 50 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('3bc5e191-469d-9121-cbfd-5b85bce2c515', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 51 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 51 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='3bc5e191-469d-9121-cbfd-5b85bce2c515');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d2f20471-d6fd-2f86-a3c2-651b8581aa6d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 51 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 51 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 51 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('582c85f9-d278-5c04-b22a-ff61adce8672', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 52 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 52 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='582c85f9-d278-5c04-b22a-ff61adce8672');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('303301fc-4e99-71a8-fabb-0fff061cab01', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 52 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 52 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 52 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('576b8ef2-4a35-0a7e-828d-ee7a128e60b7', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 53 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 53 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='576b8ef2-4a35-0a7e-828d-ee7a128e60b7');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b700ce25-9633-c14d-2028-e606f86a9e1b', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 53 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 53 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 53 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('ef280e58-9647-3df7-a193-788e144a2542', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 56 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 56 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='ef280e58-9647-3df7-a193-788e144a2542');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('fda46a7b-ee53-4910-4e7f-bbebea29ccb2', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 56 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 56 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 56 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('24d66d83-92b9-faed-3932-9213dc4d1f3a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 57 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 57 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='24d66d83-92b9-faed-3932-9213dc4d1f3a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('34c12bf6-0167-9f96-28e4-573f363cb3ac', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 57 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 57 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 57 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('47e74d4f-cb1f-c4bd-b4c8-c8bca7f283fe', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 58 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 58 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='47e74d4f-cb1f-c4bd-b4c8-c8bca7f283fe');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1c3704c8-fa2d-1369-802a-fc0fbc626cb7', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 58 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 58 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 58 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9cff9b0f-5a44-d35c-0970-199077462eff', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9cff9b0f-5a44-d35c-0970-199077462eff');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('5987a16a-c28f-a9f9-6442-b44e8663e69c', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 1 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 1 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('051fd013-b6a1-02ab-f0f9-a319bba97f6a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 2 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='051fd013-b6a1-02ab-f0f9-a319bba97f6a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('22e24d2d-7132-abad-101c-6b3c73d5fc1d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 2 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 2 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('82519976-0f36-498f-1bac-85bd0ccc90cf', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 3 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='82519976-0f36-498f-1bac-85bd0ccc90cf');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('d0343bba-906c-e4c3-d397-daaeabe11ba6', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 3 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 3 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5657ff69-31a0-4281-5a90-98c463f2d718', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 4 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5657ff69-31a0-4281-5a90-98c463f2d718');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('108cce44-d7cb-412c-160d-eb9cffdf5511', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 4 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 4 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e7c5a7e0-db1d-1235-9a8f-7fb1c5bdffd8', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 7 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e7c5a7e0-db1d-1235-9a8f-7fb1c5bdffd8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('62c108ca-926c-db68-2f24-b0a5d5407f38', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 7 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 7 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 7 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('538ca37c-5aab-7e0d-2e90-0388beb01264', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 8 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 8 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='538ca37c-5aab-7e0d-2e90-0388beb01264');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('b4c644a4-3c0b-76db-43f5-243ca2cc5f8d', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 8 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 8 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 8 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('b212dc55-1102-8935-8a99-14ed8249a746', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 9 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='b212dc55-1102-8935-8a99-14ed8249a746');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('999f8f9a-9cca-ab0b-45a5-196b9575e4e8', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 9 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 9 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 9 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('588da9e3-5968-ba5a-d78f-c4df798bd95f', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 10 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 10 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='588da9e3-5968-ba5a-d78f-c4df798bd95f');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('aa69e44b-4c7c-7944-4387-7991b2a05ce6', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 10 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 10 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('25d1760b-d6a4-1c7c-da94-200d62e8fb62', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 11 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='25d1760b-d6a4-1c7c-da94-200d62e8fb62');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ed3c86dd-3b4f-3eaa-e66a-a130563bce7a', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 11 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 11 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 11 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('5e71f3be-0c9e-85cf-9e60-7005a35774e1', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 14 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='5e71f3be-0c9e-85cf-9e60-7005a35774e1');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ff3a5241-43c9-d3b8-2648-4000de5a6521', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 14 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 14 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 14 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f5b3dc05-8fc1-c122-8927-02589fb1b6ac', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 15 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 15 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f5b3dc05-8fc1-c122-8927-02589fb1b6ac');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('c301035c-cbef-1a2e-6460-c2e484f7aea1', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 15 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 15 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('e5592414-9fa2-3982-7e78-b33d81de5298', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 16 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='e5592414-9fa2-3982-7e78-b33d81de5298');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('046fb153-ea23-3024-27f6-da569502cc28', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 16 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 16 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 16 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('15c7f899-87bb-4e16-1a39-2b9bad02fae2', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 17 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 17 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='15c7f899-87bb-4e16-1a39-2b9bad02fae2');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1b888a1f-ef2c-040a-9bbe-973b6ecc6178', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 17 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 17 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 17 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('252f8ae3-8639-f037-72fd-4ddb5709e486', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 18 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='252f8ae3-8639-f037-72fd-4ddb5709e486');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('51ff8d13-a88b-a0e9-696b-a276704c0266', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 18 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 18 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 18 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('f6e8998a-7fb7-7df1-cde2-c76b7c5e5071', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 21 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='f6e8998a-7fb7-7df1-cde2-c76b7c5e5071');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('81a5f061-04ac-47ee-7695-a4fa8e622344', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 21 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 21 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 21 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('d77063b6-d4e0-7df7-311b-d7114e5a7a3d', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 22 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 22 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='d77063b6-d4e0-7df7-311b-d7114e5a7a3d');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('2a53b81a-d903-ecb5-60dd-df9be4bcf590', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 22 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 22 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 22 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('9c2203f4-1111-85a7-70f3-dda6ffacf81b', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 23 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 23 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='9c2203f4-1111-85a7-70f3-dda6ffacf81b');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('1b53b8a8-f5db-64d5-9e43-7dec16b1c1e0', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 23 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 23 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 23 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('15356845-dd97-295f-3f81-b20e7d5384f8', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 24 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='15356845-dd97-295f-3f81-b20e7d5384f8');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('ec432362-d3d5-110c-676e-f24790106a79', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 24 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 24 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 24 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('928ebcec-2d3c-690c-3b95-6df17f2a4b2a', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 25 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 25 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='928ebcec-2d3c-690c-3b95-6df17f2a4b2a');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('50db089f-8b0e-557f-963d-2a3d43731d2b', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 25 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 25 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 25 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);

INSERT INTO sh_work_sessions
  (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
   total_hours, clock_in_source, clock_out_source)
VALUES ('06f34b60-33b4-cc7f-9cac-d0340584cbac', @tid, @uid_driver, @eid_driver,
  DATE_SUB(NOW(), INTERVAL 28 DAY) - INTERVAL 8 HOUR, DATE_SUB(NOW(), INTERVAL 28 DAY), 8.0, 'kiosk', 'kiosk')
ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), total_hours=VALUES(total_hours);
SET @ws_id = (SELECT id FROM sh_work_sessions WHERE session_uuid='06f34b60-33b4-cc7f-9cac-d0340584cbac');
INSERT INTO sh_payroll_ledger
  (entry_uuid, tenant_id, employee_id, period_year, period_month,
   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
   ref_work_session_id, description, created_at)
VALUES ('26e3b593-eb25-fe95-fb25-b7b2c5981054', @tid, @eid_driver, YEAR(DATE_SUB(NOW(), INTERVAL 28 DAY)), MONTH(DATE_SUB(NOW(), INTERVAL 28 DAY)),
  'work_earnings', 16000, 'PLN', 8.0, 2000,
  @ws_id, 'Demo shift', DATE_SUB(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE amount_minor=VALUES(amount_minor), hours_qty=VALUES(hours_qty);


-- ── 2.21 sh_order_audit + sh_order_payments ──────────────────────────
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('e4bcf41a-2527-48f3-911a-b7ce6a356b98', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('b43c3875-39d2-4b2f-8d44-d368eadad937', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('3d650526-b9fa-4b8b-8efa-d7597e499784', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('50b80551-599c-47f1-8e16-d741d5fb5042', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('c822638f-3c96-4778-b1c4-1b47ea60ec7e', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('0b328603-6354-480c-9ad3-69be05387519', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('211a22ef-673e-4168-b990-517faa99cf00', 'new', 'accepted', NOW());
INSERT INTO sh_order_audit (order_id, old_status, new_status, timestamp)
VALUES ('9f5c47bd-8a83-462c-b27b-5f59bb83117f', 'new', 'accepted', NOW());
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('70debf81-bd92-4387-9af7-aba936e64ea1', 'e4bcf41a-2527-48f3-911a-b7ce6a356b98', @tid, 'card', 10300, 10300,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('e4bcf41a-2527-48f3-911a-b7ce6a356b98','-',''), 1, 12))));
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('8ff6e4b1-1ba7-48c2-9585-7bc8c6e98f76', 'b43c3875-39d2-4b2f-8d44-d368eadad937', @tid, 'online', 8300, 8300,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('b43c3875-39d2-4b2f-8d44-d368eadad937','-',''), 1, 12))));
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('46fd2489-4c23-499d-a8b5-e25e394be535', 'c822638f-3c96-4778-b1c4-1b47ea60ec7e', @tid, 'card', 11100, 11100,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('c822638f-3c96-4778-b1c4-1b47ea60ec7e','-',''), 1, 12))));
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('471beb62-8529-445a-a66d-752c98787161', '0b328603-6354-480c-9ad3-69be05387519', @tid, 'online', 9400, 9400,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('0b328603-6354-480c-9ad3-69be05387519','-',''), 1, 12))));
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('4589b974-4312-49f8-89b2-edbcc158619a', '211a22ef-673e-4168-b990-517faa99cf00', @tid, 'online', 4800, 4800,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('211a22ef-673e-4168-b990-517faa99cf00','-',''), 1, 12))));
INSERT INTO sh_order_payments (id, order_id, tenant_id, method, amount_grosze, tendered_grosze, transaction_id)
VALUES ('b57d84b5-3f96-4b8e-93a1-64b88bb27eab', '9f5c47bd-8a83-462c-b27b-5f59bb83117f', @tid, 'card', 24450, 24450,
  CONCAT('SEED-', UPPER(SUBSTRING(REPLACE('9f5c47bd-8a83-462c-b27b-5f59bb83117f','-',''), 1, 12))));

COMMIT;

-- ═══════════════════════════════════════════════════════════════════════
-- SEKCJA 3: WALIDACJA (quick check)
-- ═══════════════════════════════════════════════════════════════════════
SELECT 'menu_items'   AS entity, COUNT(*) AS cnt FROM sh_menu_items   WHERE tenant_id=@tid
UNION ALL
SELECT 'pizze_variants', COUNT(*) FROM sh_menu_items WHERE tenant_id=@tid AND ascii_key LIKE '%_30CM'
UNION ALL
SELECT 'modifier_groups', COUNT(*) FROM sh_modifier_groups WHERE tenant_id=@tid
UNION ALL
SELECT 'modifiers',   COUNT(*) FROM sh_modifiers WHERE group_id IN (SELECT id FROM sh_modifier_groups WHERE tenant_id=@tid)
UNION ALL
SELECT 'mod_pricing', COUNT(*) FROM sh_modifier_pricing WHERE tenant_id=@tid
UNION ALL
SELECT 'recipes',     COUNT(*) FROM sh_recipes WHERE tenant_id=@tid
UNION ALL
SELECT 'sys_items',   COUNT(*) FROM sys_items WHERE tenant_id=@tid
UNION ALL
SELECT 'wh_stock',    COUNT(*) FROM wh_stock WHERE tenant_id=@tid
UNION ALL
SELECT 'pz_docs',     COUNT(*) FROM wh_documents WHERE tenant_id=@tid AND doc_number LIKE 'PZ-2026/%/FORNO%'
UNION ALL
SELECT 'ksef_invoices',COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=@tid AND invoice_number LIKE 'FA/FORNO/%'
UNION ALL
SELECT 'orders',      COUNT(*) FROM sh_orders WHERE tenant_id=@tid AND order_number LIKE 'FORNO-%'
UNION ALL
SELECT 'employees',   COUNT(*) FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%'
UNION ALL
SELECT 'emp_rates',   COUNT(*) FROM sh_employee_rates WHERE tenant_id=@tid AND employee_id IN (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%')
UNION ALL
SELECT 'work_sessions', COUNT(*) FROM sh_work_sessions WHERE tenant_id=@tid AND employee_id IN (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%')
UNION ALL
SELECT 'payroll_ledger', COUNT(*) FROM sh_payroll_ledger WHERE tenant_id=@tid AND employee_id IN (SELECT id FROM sh_employees WHERE tenant_id=@tid AND employee_code LIKE 'EMP-FORNO-%');

-- ✅ Seed Pizza Forno FULL załadowany pomyślnie!
