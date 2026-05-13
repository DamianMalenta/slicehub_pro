-- ============================================================================
-- SliceHub — Demo Dynamic Data Fixture
-- ============================================================================
-- Zapełnia puste moduły POS / Courses / KSeF Inbox demo danymi, żeby system
-- nie wyglądał na "świeżą instalację bez aktywności" przy nagrywaniu wideo
-- i screenshotach do wniosku SPARK 3.0.
--
-- Wgranie: phpMyAdmin uti.pl → wybierz bazę slicehub_testA → zakładka SQL
--          → wklej całość → Wykonaj
--
-- Idempotentne: można uruchamiać wielokrotnie (cleanup na początku).
--
-- ⚠️ TENANT_ID — sprawdź swój przed wklejeniem!
--    SELECT id, name FROM sh_tenant;
-- ============================================================================

SET @tid := 2;  -- ⬅️ ZMIEŃ jeśli Twój tenant_id jest inny

-- ── Sprawdź czy tenant istnieje ─────────────────────────────────────────────
SELECT IF(
  (SELECT COUNT(*) FROM sh_tenant WHERE id = @tid) > 0,
  CONCAT('✅ Tenant ', @tid, ' istnieje, kontynuuję seed'),
  CONCAT('❌ Tenant ', @tid, ' NIE istnieje! Sprawdź sh_tenant i zmień @tid')
) AS status;

-- ============================================================================
-- 1. CLEANUP starych demo danych (idempotentność)
-- ============================================================================
DELETE FROM sh_order_lines WHERE order_id IN (
    SELECT id FROM (
        SELECT id FROM sh_orders WHERE tenant_id = @tid AND order_number LIKE 'DEMO-%'
    ) AS sub
);
DELETE FROM sh_orders WHERE tenant_id = @tid AND order_number LIKE 'DEMO-%';
DELETE FROM sh_drivers WHERE tenant_id = @tid AND user_id IN (
    SELECT id FROM sh_users WHERE tenant_id = @tid AND username LIKE 'kierowca_demo%'
);
DELETE FROM sh_users WHERE tenant_id = @tid AND username LIKE 'kierowca_demo%';
DELETE FROM sh_ksef_invoice_lines WHERE ksef_invoice_id IN (
    SELECT id FROM (
        SELECT id FROM sh_ksef_invoices WHERE tenant_id = @tid AND invoice_number LIKE 'FA/DEMO/%'
    ) AS sub
);
DELETE FROM sh_ksef_invoices WHERE tenant_id = @tid AND invoice_number LIKE 'FA/DEMO/%';

-- ============================================================================
-- 2. KIEROWCY (2x) — dla Courses / Dispatcher
-- ============================================================================

-- Kierowca 1: Jan Wiśniewski (status = available, online)
INSERT INTO sh_users
  (tenant_id, username, password_hash, name, first_name, last_name, role, status, is_active, is_deleted)
VALUES
  (@tid, 'kierowca_demo_1', '$2y$10$dummyhash', 'Jan Wiśniewski',
   'Jan', 'Wiśniewski', 'driver', 'active', 1, 0);

SET @driver1_uid := LAST_INSERT_ID();

INSERT INTO sh_drivers (user_id, tenant_id, status)
VALUES (@driver1_uid, @tid, 'available');

-- Kierowca 2: Tomasz Kowalczyk (status = busy — w trasie)
INSERT INTO sh_users
  (tenant_id, username, password_hash, name, first_name, last_name, role, status, is_active, is_deleted)
VALUES
  (@tid, 'kierowca_demo_2', '$2y$10$dummyhash', 'Tomasz Kowalczyk',
   'Tomasz', 'Kowalczyk', 'driver', 'active', 1, 0);

SET @driver2_uid := LAST_INSERT_ID();

INSERT INTO sh_drivers (user_id, tenant_id, status)
VALUES (@driver2_uid, @tid, 'busy');

-- ============================================================================
-- 3. ZAMÓWIENIA POS (3x) — różne kanały i statusy
-- ============================================================================

-- Zamówienie 1: DOWÓZ, opłacone online, accepted → pokazuje się w Courses do dispatchu
SET @order1_id := UUID();
INSERT INTO sh_orders
  (id, tenant_id, order_number, channel, order_type, source,
   subtotal, grand_total, status, payment_status, payment_method,
   customer_name, customer_phone, delivery_address,
   delivery_lat, delivery_lng,
   created_at)
VALUES
  (@order1_id, @tid, 'DEMO-001', 'Delivery', 'delivery', 'online',
   4500, 5300, 'accepted', 'paid', 'card',
   'Jan Kowalski', '+48 600 100 200', 'ul. Marszałkowska 12, 00-001 Warszawa',
   52.2297, 21.0122,
   NOW() - INTERVAL 15 MINUTE);

INSERT INTO sh_order_lines
  (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total, vat_rate, vat_amount)
VALUES
  (UUID(), @order1_id, 'PIZZA_MARGHERITA_DEMO_L', 'Pizza Margherita — Duża', 4500, 1, 4500, 5.00, 214);

-- Zamówienie 2: WYNOS, gotówka, in_kitchen → status czekający na klienta
SET @order2_id := UUID();
INSERT INTO sh_orders
  (id, tenant_id, order_number, channel, order_type, source,
   subtotal, grand_total, status, payment_status, payment_method,
   customer_name, customer_phone,
   created_at)
VALUES
  (@order2_id, @tid, 'DEMO-002', 'Takeaway', 'takeaway', 'pos',
   3000, 3000, 'preparing', 'unpaid', NULL,
   'Anna Nowak', '+48 600 300 400',
   NOW() - INTERVAL 8 MINUTE);

INSERT INTO sh_order_lines
  (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total, vat_rate, vat_amount)
VALUES
  (UUID(), @order2_id, 'TEST_SYNC_E2E', 'TEST_SYNC_E2E', 3000, 1, 3000, 5.00, 142);

-- Zamówienie 3: SALA (dine-in), nowe, w trakcie zamówienia
SET @order3_id := UUID();
INSERT INTO sh_orders
  (id, tenant_id, order_number, channel, order_type, source,
   subtotal, grand_total, status, payment_status, payment_method,
   customer_name, guest_count,
   created_at)
VALUES
  (@order3_id, @tid, 'DEMO-003', 'POS', 'dine_in', 'pos',
   7500, 7500, 'new', 'unpaid', NULL,
   'Stolik 5 (Para)', 2,
   NOW() - INTERVAL 3 MINUTE);

INSERT INTO sh_order_lines
  (id, order_id, item_sku, snapshot_name, unit_price, quantity, line_total, vat_rate, vat_amount)
VALUES
  (UUID(), @order3_id, 'PIZZA_MARGHERITA_DEMO_M', 'Pizza Margherita — Średnia', 3500, 1, 3500, 8.00, 259),
  (UUID(), @order3_id, 'PIZZA_CAPRICCIOSA',         'Pizza Capricciosa', 4000, 1, 4000, 8.00, 296);

-- ============================================================================
-- 4. KSEF INBOX (2 faktury dostawców)
-- ============================================================================

-- Faktura 1: nowa, oczekuje na akceptację (highlight do nagrania)
INSERT INTO sh_ksef_invoices
  (tenant_id, ksef_reference_id, supplier_nip, supplier_name, supplier_address,
   buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
   currency, total_net_minor, total_vat_minor, total_gross_minor,
   status, fetched_at)
VALUES
  (@tid, 'KSEF-DEMO-REF-001',
   '5252311234', 'HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o.',
   'ul. Hurtowa 15, 10-100 Olsztyn',
   '5551234567', 'Moja Pizzeria',
   'FA/DEMO/2026/001',
   CURRENT_DATE - INTERVAL 2 DAY,
   CURRENT_DATE - INTERVAL 2 DAY,
   CURRENT_DATE + INTERVAL 12 DAY,
   'PLN',
   25000,   -- 250.00 zł netto
   5750,    -- 57.50 zł VAT (23%)
   30750,   -- 307.50 zł brutto
   'new',
   NOW() - INTERVAL 30 MINUTE);

SET @inv1_id := LAST_INSERT_ID();

INSERT INTO sh_ksef_invoice_lines
  (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate)
VALUES
  (@inv1_id, 1, 'Mąka pszenna typ 00 (worek 25 kg)', 4.00, 'szt', 80.00, 32000, 5.00),
  (@inv1_id, 2, 'Sos pomidorowy passata (5 L)',      3.00, 'szt', 45.00, 13500, 5.00),
  (@inv1_id, 3, 'Mozzarella fior di latte (2.5 kg)',  4.00, 'szt', 35.00, 14000, 5.00);

-- Faktura 2: zaakceptowana, juz przerobiona na PZ (przykład historii)
INSERT INTO sh_ksef_invoices
  (tenant_id, ksef_reference_id, supplier_nip, supplier_name, supplier_address,
   buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
   currency, total_net_minor, total_vat_minor, total_gross_minor,
   status, fetched_at, processed_at)
VALUES
  (@tid, 'KSEF-DEMO-REF-002',
   '7780012345', 'FRUKTUS WARZYWA I OWOCE',
   'ul. Targowa 88, 04-100 Warszawa',
   '5551234567', 'Moja Pizzeria',
   'FA/DEMO/2026/002',
   CURRENT_DATE - INTERVAL 5 DAY,
   CURRENT_DATE - INTERVAL 5 DAY,
   CURRENT_DATE + INTERVAL 9 DAY,
   'PLN',
   12000,
   600,
   12600,
   'accepted',
   NOW() - INTERVAL 5 DAY,
   NOW() - INTERVAL 4 DAY);

SET @inv2_id := LAST_INSERT_ID();

INSERT INTO sh_ksef_invoice_lines
  (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate)
VALUES
  (@inv2_id, 1, 'Bazylia świeża (pęczek)', 20.00, 'szt', 3.00,  6000, 5.00),
  (@inv2_id, 2, 'Pomidory koktajlowe (kg)',  6.00, 'kg',  10.00, 6000, 5.00);

-- ============================================================================
-- ✅ KONIEC — podsumowanie
-- ============================================================================
SELECT
  (SELECT COUNT(*) FROM sh_orders WHERE tenant_id = @tid AND order_number LIKE 'DEMO-%') AS demo_orders,
  (SELECT COUNT(*) FROM sh_drivers WHERE tenant_id = @tid) AS drivers_total,
  (SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id = @tid AND invoice_number LIKE 'FA/DEMO/%') AS demo_ksef_invoices;

-- Co teraz zobaczysz na slicehub.net:
--   • POS → 3 kafelki zamówień (DOWÓZ Jan Kowalski, WYNOS Anna Nowak, SALA Stolik 5)
--   • Courses → 2 kierowców (Jan available, Tomasz busy), 1 zamówienie w kolejce dispatchu
--   • KSeF Inbox → 1 nowa faktura (Hurtownia Warmia, 307.50 zł) + 1 historyczna
