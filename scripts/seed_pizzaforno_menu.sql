-- =============================================================================
-- seed_pizzaforno_menu.sql — SliceHub Pro
-- Wygenerowane: 2026-08-23 21:18:17
-- Źródło: _docs/menu_pizzaforno/menu (14).xlsx + additions.xlsx
-- Tryb: MENU-ONLY (katalog jedzenia: sys_items, menu, modyfikatory, receptury)
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
;

-- ✅ Seed Pizza Forno MENU załadowany pomyślnie (katalog jedzenia)!
