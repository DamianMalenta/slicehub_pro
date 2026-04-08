# STRUKTURA BAZY DANYCH: SLICEHUB ENTERPRISE

Dokumentacja kluczowych tabel i relacji dla Agenta AI. 
**ZASADA KRYTYCZNA:** Baza opiera się silnie na kluczach znakowych (`ascii_key` oraz `sku`), a nie tylko na numerycznych ID. Zawsze zwracaj uwagę na to, po czym łączysz tabele!

## 1. RDZEŃ MENU I TEMPORAL TABLES (Czas)
Wszystkie encje menu wspierają "Temporal Tables" (publikacja w czasie).
- `sh_menu_items`: Główne dania.
  - Klucz unikalny: `ascii_key` (np. `ITM_MARGHERITA`).
  - Pola temporalne: `publication_status` (Draft/Live/Archived), `valid_from`, `valid_to`.
  - Inne ważne: `kds_station_id`, `printer_group`, `is_secret`.
- `sh_categories`: Kategorie. Łączy się z daniami przez `sh_menu_items.category_id`.

## 2. PRAWO MACIERZY CENOWEJ (Omnichannel)
**NIGDY** nie szukaj ceny bezpośrednio w tabeli `sh_menu_items`.
- `sh_price_tiers`: Trójwymiarowa macierz cenowa.
  - `target_type`: Wskazuje czy to danie ('ITEM') czy opcja ('MODIFIER').
  - `target_sku`: Łączy się z `sh_menu_items.ascii_key` lub `sh_modifiers.ascii_key`.
  - `channel`: 'POS', 'Takeaway', 'Delivery'.
  - `price`: Właściwa cena dla danego kanału.

## 3. BLIŹNIAK CYFROWY I MODYFIKATORY
- `sh_modifier_groups`: Grupy opcji (np. "Sosy").
- `sh_modifiers`: Konkretne opcje (np. "Czosnkowy"). 
  - **Połączenie z Magazynem (KRYTYCZNE):**
  - `action_type`: 'NONE', 'ADD', 'REMOVE'.
  - `linked_warehouse_sku`: Łączy się z magazynem (`sys_items.sku`).
  - `linked_quantity`: Ułamek zużycia (np. 0.05).
- `sh_item_modifiers`: Tabela łącząca (Pivot) między `sh_menu_items.id` a `sh_modifier_groups.id`.

## 4. MAGAZYN I FOOD COST (KSeF)
- `sys_items`: Kartoteka towarów magazynowych (surowce).
  - Klucz główny: `sku` (np. `SKU_SER_MOZZ`).
  - Zawiera: `base_unit` (np. kg, szt), `vat_rate_purchase`.
- `wh_stock`: Stany magazynowe w czasie rzeczywistym.

## 5. TRANSAKCJE I BATTLEFIELD (POS)
- `sh_orders`: Nagłówki zamówień (źródło: local, online, kiosk, delivery_aggregator).
- `sh_order_items`: Pozycje na rachunku (łączy się po `menu_item_sku`).