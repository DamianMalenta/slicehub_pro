-- 068_order_metadata_notes.sql
--
-- RFC-001 Faza 2: kolumna na notatki/z komentarz managera na nagłówku zamówienia.
-- Dotychczas sh_orders nie miała kolumny notes — uwagi klienta były sklejane
-- w sh_order_lines.comment. Ta migracja dodaje kanoniczną kolumnę na poziomie
-- nagłówka, używaną przez edycję metadanych zamkniętych zamówień (Poziom 2).
--
-- Idempotentna — bezpieczna do wielokrotnego uruchomienia (IF NOT EXISTS +
-- information_schema fallback dla MariaDB 10.4 / XAMPP).

ALTER TABLE sh_orders
  ADD COLUMN IF NOT EXISTS notes TEXT NULL
  COMMENT 'Notatki managera / uwagi niefiskalne na nagłówku zamówienia (RFC-001 Poziom 2)'
  AFTER delivery_address;
