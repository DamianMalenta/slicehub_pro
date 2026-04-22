# BAZA DANYCH — Schemat & Dokumentacja

**Baza:** `slicehub_pro_v2` | **Silnik:** MariaDB 10.4+ / MySQL 8.0+ | **Kodowanie:** utf8mb4_unicode_ci

---

## 1. KONWENCJE

| Reguła | Standard |
|--------|----------|
| Prefiks `sh_` | Tabele biznesowe SliceHub |
| Prefiks `sys_` | Tabele systemowe (surowce) |
| Prefiks `wh_` | Tabele magazynowe |
| `tenant_id` | Obowiązkowy FK → `sh_tenant(id)` w każdej tabeli danych |
| Kwoty pieniężne | `INT` w groszach (1 PLN = 100) — zamówienia, rozliczenia |
| Ceny katalogowe | `DECIMAL(10,2)` w PLN — sh_price_tiers, wh_stock |
| UUID | `CHAR(36)` — zamówienia, linie, płatności, audyt |
| Auto ID | `BIGINT UNSIGNED AUTO_INCREMENT` — encje (users, items, categories) |
| Soft delete | `is_deleted TINYINT(1)` zamiast fizycznego DELETE |
| Statusy | `VARCHAR(32)` — walidacja po stronie aplikacji, nie ENUM |

---

## 2. TABELE

### A. Core & Auth

| Tabela | PK | Opis |
|--------|----|------|
| `sh_tenant` | `id` AI | Lokale / restauracje (multi-tenant) |
| `sh_tenant_settings` | `(tenant_id, setting_key)` | KV-store ustawień + pola SLA/prep |
| `sh_users` | `id` AI | Wszyscy użytkownicy systemu |

**sh_tenant_settings** — podwójna rola:
- `setting_key = ''` → kolumny SLA (`min_prep_time_minutes`, `sla_green_min`...)
- `setting_key = 'half_half_surcharge'` → czyste KV (`setting_value`)

**sh_users.role** → `owner` · `manager` · `waiter` · `cook` · `driver` · `team`
**sh_users.pin_code** → logowanie kiosk-mode (POS / Driver App). Owner nie ma PINa.

---

### B. Menu & Studio

| Tabela | PK | Opis |
|--------|----|------|
| `sh_categories` | `id` AI | Kategorie menu |
| `sh_menu_items` | `id` AI | Pozycje menu; `ascii_key` = unikalny SKU |
| `sh_modifier_groups` | `id` AI | Grupy modyfikatorów |
| `sh_modifiers` | `id` AI | Konkretne modyfikatory |
| `sh_item_modifiers` | `(item_id, group_id)` | M:N link pozycja ↔ grupa modyfikatorów |
| `sh_price_tiers` | `id` AI | Ceny omnichannel; UNIQUE `(target_type, target_sku, channel, tenant_id)` |
| `sh_recipes` | `id` AI | Receptury — zużycie surowca per pozycja menu |
| `sh_promo_codes` | `id` AI | Kody rabatowe |

**sh_price_tiers:**
- `target_type` → `ITEM` / `MODIFIER`
- `channel` → `POS` / `Takeaway` / `Delivery`
- `target_sku` → `ascii_key` pozycji lub modyfikatora

**Widok `sh_item_prices`** — filtruje `sh_price_tiers` WHERE `target_type = 'ITEM'`

**sh_modifiers.linked_warehouse_sku** — opcjonalny link do surowca magazynowego (np. "Podwójny ser" → `SER_MOZZ` × 0.1 kg)

---

### C. Orders & Fleet

| Tabela | PK | Opis |
|--------|----|------|
| `sh_orders` | `id` CHAR(36) | Zamówienia — kwoty w groszach |
| `sh_order_lines` | `id` CHAR(36) | Linie zamówienia — modyfikatory w JSON |
| `sh_order_audit` | `id` AI | Historia zmian statusów |
| `sh_order_payments` | `id` CHAR(36) | Płatności (split payment ready) |
| `sh_order_item_modifiers` | `id` AI | Modyfikatory per linia zamówienia |
| `sh_kds_tickets` | `id` CHAR(36) | Tickety KDS (Kitchen Display) |
| `sh_order_sequences` | `(tenant_id, date)` | Numerator zamówień dziennych |
| `sh_course_sequences` | `(tenant_id, date)` | Numerator kursów dziennych |
| `sh_dispatch_log` | `id` CHAR(36) | Log kursów dostawczych (K1, K2...) |
| `sh_delivery_zones` | `id` AI | Strefy dostawy (POLYGON) |
| `sh_sla_breaches` | `id` CHAR(36) | Naruszenia SLA |
| `sh_panic_log` | `id` CHAR(36) | Log Panic Button |

**sh_orders — statusy:** `new` → `pending` → `preparing` → `ready` → `in_delivery` → `completed`
**sh_orders.payment_status:** `unpaid` · `paid`
**sh_orders.payment_method:** `cash` · `card` · `online`
**sh_orders.channel:** `pos` · `online`
**sh_orders.order_type:** `dine_in` · `takeaway` · `delivery`
**sh_orders.course_id / stop_number:** wypełniane po dispatch (np. `K1`, `L1`)

---

### D. Staff & HR

| Tabela | PK | Opis |
|--------|----|------|
| `sh_drivers` | `(tenant_id, user_id)` | Rejestr kierowców; FK → sh_users |
| `sh_driver_shifts` | `id` AI | Zmiany (kasa startowa, rozliczenie) |
| `sh_driver_locations` | `(tenant_id, driver_id)` | Real-time GPS — UPSERT (migracja 008) |
| `sh_work_sessions` | `id` AI | Sesje pracy (start/end) |
| `sh_deductions` | `id` AI | Potrącenia z wynagrodzenia |
| `sh_meals` | `id` AI | Posiłki pracownicze |

**sh_drivers.status:** `offline` · `available` · `on_delivery`
**sh_driver_shifts:** `initial_cash` / `counted_cash` / `variance` — w groszach

---

### E. Warehouse

| Tabela | PK | Opis |
|--------|----|------|
| `sys_items` | `id` AI | Słownik surowców; `sku` = identyfikator; `base_unit` = kg/l/szt |
| `wh_stock` | `(tenant_id, warehouse_id, sku)` | Stany magazynowe + cena AVCO |
| `wh_documents` | `id` AI | **KANON** — Dokumenty magazynowe (PZ/RW/MM/INW/WZ/KOR). Używane przez wszystkie silniki: `WzEngine`, `PzEngine`, `InwEngine`, `KorEngine`, `MmEngine`. |
| `wh_document_lines` | `id` AI | Linie dokumentów (ilość, cena, VAT, AVCO) |
| `wh_stock_logs` | `id` AI | Audit log zmian stanów |
| ~~`wh_inventory_docs`~~ | — | **USUNIĘTE w m038** (martwe legacy). Kanon: `wh_documents` type=INW. |
| ~~`wh_inventory_doc_items`~~ | — | **USUNIĘTE w m038** (martwe legacy). Kanon: `wh_document_lines`. |
| `sh_product_mapping` | `id` AI | Mapowanie faktura → SKU (AutoScan) |
| `sh_doc_sequences` | `(tenant_id, doc_type, doc_date)` | Numerator dokumentów magazynowych |

**wh_documents.type:** `PZ` (przyjęcie) · `RW` (rozchód) · `MM` (przesunięcie) · `INW` (inwentaryzacja) · `WZ` (wydanie) · `KOR` (korekta)
**wh_documents.status:** `pending_approval` · `completed`
**wh_stock.current_avco_price:** średnia ważona cena (AVCO) w PLN

---

## 3. RELACJE

```
sh_tenant ─┐
            ├── sh_tenant_settings
            ├── sh_users
            │    ├── sh_drivers ── sh_driver_locations
            │    ├── sh_driver_shifts
            │    ├── sh_work_sessions
            │    ├── sh_deductions
            │    └── sh_meals
            ├── sh_categories
            │    └── sh_menu_items
            │         ├── sh_item_modifiers → sh_modifier_groups → sh_modifiers
            │         ├── sh_recipes → sys_items
            │         └── sh_price_tiers
            ├── sh_orders
            │    ├── sh_order_lines → sh_order_item_modifiers
            │    ├── sh_order_audit
            │    ├── sh_order_payments
            │    └── sh_kds_tickets
            ├── wh_stock
            ├── wh_documents → wh_document_lines
            ├── wh_stock_logs
            └── sh_dispatch_log
```

---

## 4. MIGRACJE

| Nr | Plik | Co dodaje |
|----|------|-----------|
| 001 | `001_init_slicehub_pro_v2.sql` | Grand schema — wszystkie tabele, widoki, FK, indeksy |
| 004 | `004_expand_search_aliases.sql` | sys_items: `search_aliases`, `is_active`, `is_deleted` + polskie deklinacje |
| 006 | `006_studio_mission_control.sql` | sh_categories: VAT defaults / sh_menu_items: PLU, dostępność |
| 007 | `007_pos_engine_columns.sql` | sh_orders: druk paragonu, cart_json, NIP |
| 008 | `008_delivery_ecosystem.sql` | Nowa tabela `sh_driver_locations` (GPS) |
| 009 | `009_delivery_state_machine.sql` | Delivery state machine |
| 010 | `010_driver_action_type.sql` | Driver action types |
| 011 | `011_integration_logs.sql` | Integration logs |
| 012 | `012_visual_layers.sql` | `sh_visual_layers` — mapowanie modifier → warstwa wizualna |
| 013 | `013_board_companions.sql` | `sh_board_companions` — cross-sell companions |
| 014 | `014_global_assets.sql` | `sh_global_assets` — shared visual assets library |
| 015 | `015_normalize_three_drivers.sql` | Normalizacja kierowców (3 konta testowe) |
| 016 | `016_visual_compositor_upgrade.sql` | `sh_visual_layers`: +`product_filename`, +`cal_scale`, +`cal_rotate`; `sh_board_companions`: +`product_filename`; `sh_tenant_settings`: +`storefront_surface_bg` |

> Luki 002/003/005 — dawne seedy przeniesione do `seed_demo_all.php`.
> Wszystkie migracje 004–016 są **idempotentne**.

---

## 5. DANE TESTOWE — `seed_demo_all.php`

### Zakres

| Obszar | Ilość | Szczegóły |
|--------|-------|-----------|
| Tenant | 1 | "SliceHub Pizzeria Poznań" + 5 ustawień |
| Użytkownicy | 8 | Unikalne PINy 0000–6666; hasło systemowe: `password` |
| Kategorie | 8 | Pizza, Burgery, Makarony, Sałatki, Napoje, Dodatki, Desery, Zestawy |
| Pozycje menu | 33 | 10 pizz · 5 burgerów · 3 makarony · 2 sałatki · 5 napojów · 4 dodatki · 2 desery · 2 zestawy |
| Ceny | 112 | 99 ITEM + 13 MODIFIER × 3 kanały (POS / Takeaway / Delivery +8%) |
| Modyfikatory | 4 grupy / 13 | Rozmiar pizzy · Dodatki · Sosy · Rozmiar burgera |
| Surowce | 43 | Mąka, sery, mięsa, warzywa, napoje, opakowania |
| Stany magazynowe | 43 | Realistyczne ilości z cenami AVCO |
| Receptury | 44 linie | Margherita, Pepperoni, Capricciosa, Hawajska, Q.Formaggi, Burger Classic, Bolognese, Cezar, Frytki |
| Dokumenty WH | 4 | 3× PZ (dostawy) + 1× RW (strata) |
| Kierowcy | 2 | driver1 (PIN 4444) + driver2 (PIN 5555), zmiany + GPS Poznań |
| Zamówienia | 12 | 3 dine-in · 2 takeaway · 5 delivery ready · 2 delivery completed |
| Sesje pracy | 6 | manager + waiter1/2 + cook1 + driver1/2 |

### Konta testowe

| Login | Rola | PIN | Moduł docelowy |
|-------|------|-----|----------------|
| `admin` | owner | — | System login |
| `manager` | manager | 0000 | POS / Dispatch |
| `waiter1` | waiter | 1111 | POS |
| `waiter2` | waiter | 2222 | POS |
| `cook1` | cook | 3333 | KDS |
| `driver1` | driver | 4444 | Driver App |
| `driver2` | driver | 5555 | Driver App |
| `team1` | team | 6666 | Team App |
