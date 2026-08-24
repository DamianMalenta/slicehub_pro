-- 067_normalize_payment_status_defaults.sql
--
-- Naprawa 2026-08-24: normalizuje residualne wartości 'unpaid'/'paid' w
-- sh_orders.payment_status do słownika kanonicznego zgodnego z
-- OrderStateMachine (line 14):
--   to_pay | online_unpaid | cash | card | online_paid
--
-- Migracja 009 już to robiła, ale uruchamia się PRZED seed_demo_all.php,
-- który wpisywał stare wartości z powrotem. Ta migracja:
--   1) Zmienia DEFAULT kolumny na 'to_pay' (zgodne z 001_init po naprawie)
--   2) Ponownie normalizuje residualne 'unpaid'/'paid' dla instalacji
--      które seedowały po migracji 009 (lub miały ręczne INSERT-y)
--
-- Idempotentna — bezpieczna do wielokrotnego uruchomienia.

-- 1) Zmień DEFAULT kolumny payment_status
ALTER TABLE sh_orders
  MODIFY COLUMN payment_status VARCHAR(32) NOT NULL DEFAULT 'to_pay';

-- 2) Normalizuj residual 'unpaid' → 'to_pay' (dla cash/card/NULL)
UPDATE sh_orders SET payment_status = 'to_pay'
WHERE payment_status = 'unpaid'
  AND (payment_method IS NULL OR payment_method NOT IN ('online'));

-- 3) Normalizuj residual 'unpaid' → 'online_unpaid' (dla online)
UPDATE sh_orders SET payment_status = 'online_unpaid'
WHERE payment_status = 'unpaid'
  AND payment_method = 'online';

-- 4) Normalizuj residual 'paid' → 'cash'/'card'/'online_paid'
UPDATE sh_orders SET payment_status = 'cash'
WHERE payment_status = 'paid' AND payment_method = 'cash';

UPDATE sh_orders SET payment_status = 'card'
WHERE payment_status = 'paid' AND payment_method = 'card';

UPDATE sh_orders SET payment_status = 'online_paid'
WHERE payment_status = 'paid' AND payment_method = 'online';

-- 5) Catch-all: residual 'paid' bez rozpoznaniej metody → 'cash'
UPDATE sh_orders SET payment_status = 'cash'
WHERE payment_status = 'paid';
