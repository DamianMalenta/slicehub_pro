-- ============================================================================
-- SliceHub — Demo Data Fixture do nagrywania video (SPARK 3.0)
-- ============================================================================
-- Wgranie: phpMyAdmin uti.pl → wybierz bazę → SQL → wklej całość → Wykonaj
-- Może być uruchamiane wielokrotnie (cleanup na początku — idempotentne)
--
-- ⚠️ TENANT_ID ⚠️
-- Sprawdź swój tenant_id ZANIM wkleisz! W phpMyAdmin → SQL:
--     SELECT id, name, tenant_id FROM sh_users WHERE email = 'twoj_email_owner';
-- Lub zobacz wszystkich userów: SELECT id, email, tenant_id FROM sh_users;
--
-- install_panel.php często tworzy tenant_id=1 jako "Demo Tenant" a ownera dodaje
-- jako tenant_id=2. Niżej zmień @tid := 1 na właściwą wartość.
-- ============================================================================

-- TUTAJ wpisz tenant_id swojego ownera (z SELECT powyżej):
SET @tid := 1;

-- ============================================================================
-- 0. CLEANUP starych danych testowych (jeśli istnieją)
-- ============================================================================
DELETE FROM sh_recipes WHERE tenant_id=@tid AND menu_item_sku IN ('PIZZA_MARGHERITA_DEMO','SOS_POMIDOROWY_DEMO');
DELETE FROM sh_item_modifiers WHERE item_id IN (SELECT id FROM sh_menu_items WHERE tenant_id=@tid AND ascii_key IN ('PIZZA_MARGHERITA_DEMO','SOS_POMIDOROWY_DEMO'));
DELETE FROM sh_modifiers WHERE ascii_key='SALAMI_DEMO';
DELETE FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key='GRP_MIESNE_DEMO';
DELETE FROM sh_price_tiers WHERE tenant_id=@tid AND target_sku IN ('PIZZA_MARGHERITA_DEMO','SOS_POMIDOROWY_DEMO');
DELETE FROM sh_menu_items WHERE tenant_id=@tid AND ascii_key IN ('PIZZA_MARGHERITA_DEMO','SOS_POMIDOROWY_DEMO');
DELETE FROM sh_categories WHERE tenant_id=@tid AND name IN ('Pizze (Demo)', 'Półprodukty (Demo)');
DELETE FROM wh_stock WHERE tenant_id=@tid AND sku LIKE 'DEMO_%';
DELETE FROM sys_items WHERE tenant_id=@tid AND sku LIKE 'DEMO_%';

-- ============================================================================
-- 1. KATEGORIE (Pizze + Półprodukty)
-- ============================================================================
INSERT INTO sh_categories (tenant_id, name, default_vat_dine_in, default_vat_takeaway, default_vat_delivery, display_order) VALUES
    (@tid, 'Pizze (Demo)',         8.00, 5.00, 5.00, 1),
    (@tid, 'Półprodukty (Demo)',   8.00, 5.00, 5.00, 99);

SET @cat_pizz    := (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='Pizze (Demo)');
SET @cat_polprod := (SELECT id FROM sh_categories WHERE tenant_id=@tid AND name='Półprodukty (Demo)');

-- ============================================================================
-- 2. SUROWCE (sys_items) + STAN MAGAZYNOWY (wh_stock)
-- ============================================================================
INSERT INTO sys_items (tenant_id, sku, name, base_unit) VALUES
    (@tid, 'DEMO_MAKA',       'Mąka pszenna typ 00 (Demo)',       'kg'),
    (@tid, 'DEMO_MOZZARELLA', 'Mozzarella Fior di Latte (Demo)',  'kg'),
    (@tid, 'DEMO_POMIDOR',    'Pomidor San Marzano (Demo)',       'kg'),
    (@tid, 'DEMO_CZOSNEK',    'Czosnek świeży (Demo)',            'kg'),
    (@tid, 'DEMO_OLIWA',      'Oliwa extra virgin (Demo)',        'l'),
    (@tid, 'DEMO_SALAMI',     'Salami picante (Demo)',            'kg');

INSERT INTO wh_stock (tenant_id, warehouse_id, sku, quantity, unit_net_cost, current_avco_price) VALUES
    (@tid, 'MAIN', 'DEMO_MAKA',       50.0000,  3.5000,  3.5000),
    (@tid, 'MAIN', 'DEMO_MOZZARELLA', 15.0000, 35.0000, 35.0000),
    (@tid, 'MAIN', 'DEMO_POMIDOR',    25.0000,  6.0000,  6.0000),
    (@tid, 'MAIN', 'DEMO_CZOSNEK',     2.0000, 18.0000, 18.0000),
    (@tid, 'MAIN', 'DEMO_OLIWA',       8.0000, 45.0000, 45.0000),
    (@tid, 'MAIN', 'DEMO_SALAMI',      4.0000, 78.0000, 78.0000);

-- ============================================================================
-- 3. PÓŁPRODUKT "Sos Pomidorowy" + jego receptura (3 składniki)
-- ============================================================================
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, type, is_active, is_deleted, vat_rate_dine_in, vat_rate_takeaway, publication_status, display_order) VALUES
    (@tid, @cat_polprod, 'Sos Pomidorowy (Demo)', 'SOS_POMIDOROWY_DEMO', 'standard', 1, 0, 8.00, 5.00, 'Live', 1);

-- Receptura sosu: 1 batch = 1kg pomidorów + 50g czosnku + 100ml oliwy
INSERT INTO sh_recipes (tenant_id, menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_packaging, is_subrecipe, subrecipe_yield, display_order) VALUES
    (@tid, 'SOS_POMIDOROWY_DEMO', 'DEMO_POMIDOR', 1.0000, 5.0000, 0, 0, 1.0000, 1),
    (@tid, 'SOS_POMIDOROWY_DEMO', 'DEMO_CZOSNEK', 0.0500, 0.0000, 0, 0, 1.0000, 2),
    (@tid, 'SOS_POMIDOROWY_DEMO', 'DEMO_OLIWA',   0.1000, 0.0000, 0, 0, 1.0000, 3);

-- ============================================================================
-- 4. PIZZA MARGHERITA TEST (standalone, bez wariantów) + cennik 3-kanałowy
-- ============================================================================
INSERT INTO sh_menu_items (tenant_id, category_id, name, ascii_key, type, is_active, is_deleted, vat_rate_dine_in, vat_rate_takeaway, publication_status, display_order) VALUES
    (@tid, @cat_pizz, 'Pizza Margherita Test', 'PIZZA_MARGHERITA_DEMO', 'standard', 1, 0, 8.00, 5.00, 'Live', 1);

INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES
    (@tid, 'ITEM', 'PIZZA_MARGHERITA_DEMO', 'POS',      25.00),
    (@tid, 'ITEM', 'PIZZA_MARGHERITA_DEMO', 'Takeaway', 25.00),
    (@tid, 'ITEM', 'PIZZA_MARGHERITA_DEMO', 'Delivery', 27.50);

-- Receptura pizzy: 0.25kg mąki + 0.08kg mozzarelli + 1 porcja sosu (SUBRECIPE z yield=10)
INSERT INTO sh_recipes (tenant_id, menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_packaging, is_subrecipe, subrecipe_yield, display_order) VALUES
    (@tid, 'PIZZA_MARGHERITA_DEMO', 'DEMO_MAKA',           0.2500, 5.0000, 0, 0,  1.0000, 1),
    (@tid, 'PIZZA_MARGHERITA_DEMO', 'DEMO_MOZZARELLA',     0.0800, 2.0000, 0, 0,  1.0000, 2),
    (@tid, 'PIZZA_MARGHERITA_DEMO', 'SOS_POMIDOROWY_DEMO', 1.0000, 0.0000, 0, 1, 10.0000, 3);

-- ============================================================================
-- 5. GRUPA MODYFIKATORÓW "Dodatki Mięsne" z opcją "Salami" + powiązanie z pizzą
-- ============================================================================
INSERT INTO sh_modifier_groups (tenant_id, name, ascii_key, min_selection, max_selection, free_limit, allow_multi_qty, publication_status) VALUES
    (@tid, 'Dodatki Mięsne (Demo)', 'GRP_MIESNE_DEMO', 0, 5, 0, 0, 'Live');

SET @grp_miesne := (SELECT id FROM sh_modifier_groups WHERE tenant_id=@tid AND ascii_key='GRP_MIESNE_DEMO');

INSERT INTO sh_modifiers (group_id, name, ascii_key, action_type, linked_warehouse_sku, linked_quantity, linked_waste_percent, price, is_default, is_deleted, is_active, has_visual_impact) VALUES
    (@grp_miesne, 'Salami picante', 'SALAMI_DEMO', 'ADD', 'DEMO_SALAMI', 0.0500, 0.0000, 4.00, 0, 0, 1, 1);

-- Powiązanie grupy z pizzą
SET @pizza_id := (SELECT id FROM sh_menu_items WHERE tenant_id=@tid AND ascii_key='PIZZA_MARGHERITA_DEMO');
INSERT INTO sh_item_modifiers (item_id, group_id) VALUES (@pizza_id, @grp_miesne);

-- ============================================================================
-- WERYFIKACJA — pokazuje co zostało wstawione
-- ============================================================================
SELECT '=== KATEGORIE ===' AS info;
SELECT id, name, display_order FROM sh_categories WHERE tenant_id=@tid AND name LIKE '%Demo%' ORDER BY display_order;

SELECT '=== MENU ITEMS ===' AS info;
SELECT id, category_id, ascii_key, name, publication_status FROM sh_menu_items WHERE tenant_id=@tid AND ascii_key LIKE '%DEMO%';

SELECT '=== CENY 3-KANAŁOWE ===' AS info;
SELECT target_sku, channel, price FROM sh_price_tiers WHERE tenant_id=@tid AND target_sku LIKE '%DEMO%' ORDER BY target_sku, channel;

SELECT '=== RECEPTURY (z is_subrecipe + yield) ===' AS info;
SELECT menu_item_sku, warehouse_sku, quantity_base, waste_percent, is_subrecipe, subrecipe_yield, display_order FROM sh_recipes WHERE tenant_id=@tid AND menu_item_sku LIKE '%DEMO%' ORDER BY menu_item_sku, display_order;

SELECT '=== SUROWCE + STAN MAGAZYNOWY ===' AS info;
SELECT s.sku, s.name, w.quantity, w.current_avco_price FROM sys_items s LEFT JOIN wh_stock w ON w.sku=s.sku AND w.tenant_id=s.tenant_id WHERE s.tenant_id=@tid AND s.sku LIKE 'DEMO_%';

SELECT '=== MODYFIKATORY ===' AS info;
SELECT g.name AS grupa, m.name AS opcja, m.action_type, m.linked_warehouse_sku, m.linked_quantity, m.price FROM sh_modifier_groups g JOIN sh_modifiers m ON m.group_id=g.id WHERE g.tenant_id=@tid AND g.ascii_key='GRP_MIESNE_DEMO';

SELECT '=== PIZZA <-> GRUPY MODYFIKATORÓW ===' AS info;
SELECT mi.name AS pizza, mg.name AS grupa FROM sh_item_modifiers im JOIN sh_menu_items mi ON mi.id=im.item_id JOIN sh_modifier_groups mg ON mg.id=im.group_id WHERE mi.tenant_id=@tid AND mi.ascii_key='PIZZA_MARGHERITA_DEMO';
