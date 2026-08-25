-- 069_order_is_corrected.sql
--
-- RFC-001 Faza 3: flaga is_corrected na sh_orders.
-- Ustawiana na 1 gdy owner/admin koryguje pozycje zamkniętego/sfiskalizowanego
-- zamówienia przez edit_scope='force' (Poziom 3). Pozwala BiEngine odróżnić
-- przychód z zamówień korygowanych od czystych oraz oznacza w UI historii
-- zamówienia które przeszły twardą korektę (KOR magazynowy + snapshot).
--
-- Idempotentna — bezpieczna do wielokrotnego uruchomienia (IF NOT EXISTS).

ALTER TABLE sh_orders
  ADD COLUMN IF NOT EXISTS is_corrected TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Flaga: zamknięte zamówienie było korygowane przez force_edit (RFC-001 Poziom 3)'
  AFTER receipt_printed;
