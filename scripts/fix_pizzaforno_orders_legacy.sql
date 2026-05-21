-- =============================================================================
-- fix_pizzaforno_orders_legacy.sql — migracja starych zamówień FORNO-* do
-- kanonicznego modelu 3-filarowego (status + delivery_status + payment_status).
-- Bezpieczne do wielokrotnego uruchomienia. Tenant domyślnie 2 (Pizza Forno).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @tid := 2;

-- status: delivered / in_route → kanoniczne
UPDATE sh_orders SET
  status = 'completed',
  delivery_status = 'delivered'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND status = 'delivered';

UPDATE sh_orders SET
  status = 'ready',
  delivery_status = 'in_delivery'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND status IN ('in_route', 'in_delivery');

-- order_type: legacy → kanoniczne
UPDATE sh_orders SET order_type = 'takeaway'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%' AND order_type = 'collection';

UPDATE sh_orders SET order_type = 'dine_in'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%' AND order_type = 'table';

-- payment_status: paid/unpaid → kanoniczne
UPDATE sh_orders SET payment_status = 'to_pay'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND payment_status = 'unpaid' AND (payment_method IS NULL OR payment_method != 'online');

UPDATE sh_orders SET payment_status = 'online_unpaid'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND payment_status = 'unpaid' AND payment_method = 'online';

UPDATE sh_orders SET payment_status = 'cash'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND payment_status = 'paid' AND payment_method = 'cash';

UPDATE sh_orders SET payment_status = 'card'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND payment_status = 'paid' AND payment_method = 'card';

UPDATE sh_orders SET payment_status = 'online_paid'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND payment_status = 'paid' AND payment_method = 'online';

UPDATE sh_orders SET payment_status = 'cash'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%' AND payment_status = 'paid';

-- delivery_status dla aktywnych dostaw bez ustawionej kolumny
UPDATE sh_orders SET delivery_status = 'unassigned'
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
  AND order_type = 'delivery'
  AND status NOT IN ('completed', 'cancelled')
  AND (delivery_status IS NULL OR delivery_status = '');

-- tracking_token dla FORNO-006 (track demo)
UPDATE sh_orders SET tracking_token = LOWER(SUBSTRING(REPLACE(id,'-',''), 1, 16))
WHERE tenant_id = @tid AND order_number = 'FORNO-006'
  AND (tracking_token IS NULL OR tracking_token = '');

-- odśwież created_at aktywnych zamówień (ujemne liczniki SLA)
UPDATE sh_orders SET
  created_at = DATE_SUB(NOW(), INTERVAL 90 MINUTE),
  promised_time = DATE_ADD(DATE_SUB(NOW(), INTERVAL 90 MINUTE), INTERVAL 35 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-001';

UPDATE sh_orders SET
  created_at = DATE_SUB(NOW(), INTERVAL 45 MINUTE),
  promised_time = DATE_ADD(DATE_SUB(NOW(), INTERVAL 45 MINUTE), INTERVAL 35 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-002';

UPDATE sh_orders SET created_at = DATE_SUB(NOW(), INTERVAL 15 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-003';

UPDATE sh_orders SET created_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-004';

UPDATE sh_orders SET
  created_at = DATE_SUB(NOW(), INTERVAL 75 MINUTE),
  promised_time = DATE_ADD(DATE_SUB(NOW(), INTERVAL 75 MINUTE), INTERVAL 35 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-006';

UPDATE sh_orders SET
  created_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE),
  promised_time = DATE_ADD(DATE_SUB(NOW(), INTERVAL 6 MINUTE), INTERVAL 35 MINUTE)
WHERE tenant_id = @tid AND order_number = 'FORNO-007';

SELECT order_number, order_type, status, delivery_status, payment_status,
       DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at
FROM sh_orders
WHERE tenant_id = @tid AND order_number LIKE 'FORNO-%'
ORDER BY order_number;
