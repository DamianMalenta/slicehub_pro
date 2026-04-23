# 00. PAMIĘĆ SYSTEMU — SliceHub Enterprise (Master Reference)

> **Ten plik jest Północną Gwiazdą.** Czytasz go ZANIM zaczniesz kodować cokolwiek.
> Jeśli coś jest tu napisane — to jest prawda. Jeśli nie ma — idź do `_docs/`, `database/migrations/` lub `core/`.
> **NIGDY nie zgaduj.** Nigdy nie wymyślaj tabel, kolumn, akcji API. Nigdy nie zmieniaj struktury bazy bez wyraźnej zgody użytkownika.

**Kompilacja:** 2026-04-23
**Źródła:** `START_TUTAJ.md`, `01_KONSTYTUCJA.md`, `02_ARCHITEKTURA.md`, `04_BAZA_DANYCH.md`, `05_INSTRUKCJA_FOTO_UPLOAD.md`, `ustalenia.md`, `LEGACY_BUSINESS_LOGIC_EXTRACTION.md`, `OPTIMIZED_CORE_LOGIC_V2.md`, `.cursorrules`, migracje 001–040.

---

## 🧊 FREEZE NOTICE — OFFLINE-FIRST POS (2026-04-23)

> **UWAGA:** Moduł **Offline-First POS jest zamrożony w połowie prac** (ukończono P1, P2, P3, **P3.5 i P4**). AI ma **kategoryczny zakaz** refaktoryzacji, usuwania plików SW oraz edycji tabel offline (`sh_pos_terminals`, `sh_pos_sync_cursors`, `sh_pos_op_log`, `sh_pos_server_events`). **Instrukcja wznowienia prac** znajduje się w dokumencie [`17_OFFLINE_POS_BACKLOG.md`](./17_OFFLINE_POS_BACKLOG.md).
>
> **Pliki pod ochroną** (pełna lista w §2 backlogu): `api/pos/sync.php`, `database/migrations/039_resilient_pos.sql`, `database/migrations/040_pos_server_events.sql`, `modules/pos/sw.js`, `modules/pos/manifest.webmanifest`, `modules/pos/offline.html`, `modules/pos/.htaccess`, `modules/pos/js/pos_sw_register.js`, `modules/pos/js/PosLocalStore.js`, `modules/pos/js/PosSyncEngine.js`, `modules/pos/js/PosApiOutbox.js`, wszystkie ikony/screenshoty POS + `modules/online/sw.js`, `modules/online/manifest.webmanifest`, `modules/online/.htaccess`.
>
> **Zamrożone fazy** (NIE implementować bez jawnego rozmrożenia): P4.5 (worker_pos_fanout), P5 (multi-device), P6 (conflict UI + fantom cards), P7 (offline PIN auth), P8 (SSE + demo).
>
> **Zakaz dotyczy też** dodawania nowych akcji do `api/pos/sync.php` oraz bezpośredniego `INSERT INTO sh_pos_server_events` z `api/online/*`, `api/kds/*`, `api/backoffice/*`. Gdy wznowimy — producenci pójdą przez `sh_event_outbox` + nowy `scripts/worker_pos_fanout.php` (Anti-Corruption Layer), co chroni Prawo VI § 4 Konstytucji (Klocki Lego) i izolację domen.
>
> **Kto rozmraża:** wyłącznie właściciel produktu, jawną decyzją w chacie. AI nie rozmraża samodzielnie.

---

## ★ WIZJA CELU — CZYTAJ ZANIM COKOLWIEK NAPISZESZ

> **Ta sekcja powstała 2026-04-19** po przebudowie koncepcji Online + Studio. Jeśli kolejna sesja zaczyna pracę nad `modules/online/`, `modules/online_studio/`, `api/online/`, `api/online_studio/`, `api/assets/` albo `core/SceneResolver.php` / `core/js/scene_renderer.js` — **musi znać to zanim otworzy edytor.**

### Co budujemy (jednym zdaniem)

Innowacyjny **system operacyjny gastronomii** z dwiema twarzami: dla **managera** — Studio z efektem „kinowego zaplecza reżyserii” (Asset Library Organizer + Director's Suite + Harmony Score + Style Conductor), dla **klienta** — Storefront jako **teatr fotograficzny** (Scena Drzwi → Scena Daniowa „Living Table” → Koszyk → Track), nie product grid.

### Docelowy przepływ (klient) — 4 sceny

1. **Scena Drzwi** (`modules/online/index.html#doorway`) — hero-entry: ilustracja lokalu, marka, godziny, kanały (POS/Takeaway/Delivery), status otwarcia, mapa modal, CTA „Wejdź”. **Ma być żywa** (pora dnia, obciążenie, pogoda) — nie static.
2. **Scena Daniowa / „Living Table”** (swipe'owany katalog `pizza-scene`) — kafelki oparte na `SceneResolver` (layer_base + sauce + cheese + toppings). Klient przesuwa jak na Instagramie. Bez dropdownów, bez formularzy. Wejście w kafelek → **Counter** (Bottom Sheet) z live price (`CartEngine`), Half & Half, modifikatorami z podglądem warstw i companion-items.
3. **Checkout** (`api/online/engine.php` → `init_checkout` + `guest_checkout`) — publiczny flow storefrontu: lock token, phone-keyed order, bez rejestracji, bez `auth_guard.php`. `api/orders/checkout.php` pozostaje kanonicznym endpointem finalizacji dla ścieżek chronionych / backoffice i NIE jest jeszcze publicznym checkoutem klienta online.
4. **Track** (`modules/online/track.html`) — **nie** ekran „twoje zamówienie: PRZYJĘTE”. To **living timeline** z ETA (`PromisedTimeEngine`), świadomy stanu kuchni + kierowcy (GPS z `api/courses/engine.php`), z obrazem daniową sceną w tle.

### Docelowy przepływ (manager) — Studio

1. **Asset Studio** (`modules/online_studio` → tab `assets`) — biblioteka 200+ zdjęć z **display_name**, **tags**, **cook_state**, **bucket**, **category**, auto-wykrywaniem duplikatów (checksum), bulk ops, Health Panel. **Jedno źródło prawdy dla assetów.** Wszystkie pickery i surfaces czerpią stąd (SSOT — sekcja § 15).
2. **Director's Suite** (tab `director`) — scena dania w 3D-ish układzie warstw (`SceneRenderer.js` + `applyCameraPerspective`). Dostępne: kamery (top-down / 35mm / serving-rack), companion layers (glass/napkin), ambient effects, Magic tools (Conform / Harmonize / Colorize / Bake).
3. **Scene Studio** (widoki list scen) — masowe operacje: Style Conductor (stosuje LUT + kamerę + companions + efekty do całej kategorii), Bake Variants, Auto-perspective, soft-delete.
4. **Menu Studio** (`api/backoffice/api_menu_studio.php`) — CRUD pozycji, modifier groups, recipes, prices. **Nie modyfikuje assetów wizualnych** (SSOT: Asset Studio).
5. **Ustawienia sklepu** (NEW, faza 4) — dane adresowe, godziny, kanały, marka, mapa, preorder (sh_tenant_settings + sh_storefront_settings).

### Dlaczego Asset Library była rozbita (historia)

Biblioteka zdjęć historycznie miała 3 niezależne endpointy: `library_list` (stary Menu Studio), `assetsList` (nowy Asset Studio M5) i `AssetPicker` (Director). Każdy miał własny cache i własny kontrakt. To **nieporozumienie z iteracji** — nie decyzja architektoniczna. **SSOT docelowa:** `api/assets/engine.php` `action=list` + wariant `list_compact` (tylko dla surface'ów/pickerów, okrojony response). Wszystko inne = dead code.

### Niezmienniki (nie dyskutujemy)

1. **Zero-Reload SPA** — brak Node/React/Vue/npm. Vanilla JS + PHP + MariaDB.
2. **SSOT biblioteki assetów** = `api/assets/engine.php`. `libraryList` w `studio_api.js` i `library_list` w `api_menu_studio.php` **muszą zniknąć** (faza B, kwiecień 2026).
3. **SceneResolver + AssetResolver = serwer.** Frontend nigdy nie wymyśla URL do obrazka.
4. **CartEngine = serwer.** Frontend nigdy nie wylicza totala.
5. **Każda zmiana w Studio ląduje w DB jako DELTA**, nie pełny overwrite (patrz `sh_scene_deltas`, Style Conductor runs).
6. **Harmony Score nigdy nie kłamie.** Jeśli scena ma 2 warstwy → nie może mieć 100%. Transparentny model: Kompletność 0–50 + Dopracowanie 0–30 + Spójność 0–20 (faza C).
7. **Prawo VII** (§ 1) — jeśli opisuję funkcję jednym słowem („slider”, „picker”), to jeszcze nie funkcja. To paint.

### Gdzie jesteśmy teraz (plan krótkoterminowy)

| Faza | Zakres | Status |
|---|---|---|
| A · WIZJA CELU | Ten dokument | **DONE** (2026-04-19) |
| B · SSOT biblioteki | Usuń `library_list`, przepnij `surface.js` + `AssetPicker.js` na `assetsList` | **DONE** (2026-04-19) |
| C · Harmony Score FIX | Transparentny model scoring (completeness 0–50 + polish 0–30 + consistency 0–20) + UI rozbicia + cache v2 (`outliers_json` jako obiekt z `version`+`breakdown`+`outliers`+`layerOutliers`) | **DONE** (2026-04-19) |
| D · Ustawienia sklepu | `api/online_studio/engine.php` akcje `storefront_settings_get`/`storefront_settings_save` + nowy tab `modules/online_studio/js/tabs/storefront.js` (brand, kontakt, godziny, kanały+preorder, mapa OSM) | **DONE** (2026-04-19) |
| E · Track screen | `api/online/engine.php action=track_order` rozbudowany (items + storeCoords + etaSeconds + heroImage). Front: live ETA countdown (1s ticker z serwerową kotwicą), hero image tła karty, lista pozycji z miniaturami, origin pin (restauracja) + driver pin + auto-fit bounds. Polling storefrontu ujednolicony do `10s`. | **DONE** (2026-04-19) |
| F · Counter + Living Table | Scena Daniowa jako teatr (swipe + Bottom Sheet + Living 3D) | **ODDZIELNA SESJA** — wymaga samodzielnej iteracji |
| G · Admin Hub | Meta-panel dla kilku tenantów (oddzielna Faza 3) | LATER |

> **Status sesji 2026-04-19:** A→E zamknięte. Następny krok: Faza F (Counter + Living Table) po tescie Fazy A–E przez użytkownika.

---

## 0. CZYM JEST SLICEHUB

SliceHub **nie jest kolejnym POS-em**. To **gastronomiczny system klasy enterprise** — wielonajemczy, wielomodułowy, z macierzą cenową omnichannel, cyfrowym bliźniakiem magazynu, temporalną publikacją menu, serwer-autorytatywną kalkulacją i stanowym silnikiem zamówień. Początkowo dla gastronomii, architektonicznie uniwersalny.

**Stos technologiczny (Manifest „Zero-Reload SPA"):**
- **Frontend:** Vanilla JS (ES6+), czysty HTML5, Tailwind CSS (w nowszych modułach) lub czysty CSS.
- **Backend:** PHP 8+ (PDO, REST API JSON).
- **Baza:** MariaDB 10.4+ / MySQL 8.0+, utf8mb4_unicode_ci, baza `slicehub_pro_v2`.
- **ABSOLUTNY ZAKAZ:** Node.js, npm, Webpack, React, Vue, Angular, jQuery.

---

## 1. KONSTYTUCJA — 6 NIENARUSZALNYCH PRAW

### Prawo I — Macierz Cenowa (Omnichannel)
- NIE ISTNIEJE „płaska cena". Każda cena żyje w macierzy `(target_type, target_sku, channel, tenant_id)` w tabeli `sh_price_tiers`.
- Kanały: **POS**, **Takeaway**, **Delivery**. `target_type` ∈ {`ITEM`, `MODIFIER`}.
- Fallback: gdy brak ceny dla kanału → fallback do `POS` i oznacz `priceFallback: true` w odpowiedzi API.
- Bulk edit na jednym kanale NIGDY nie nadpisuje innych kanałów.

### Prawo II — Bliźniak Cyfrowy (Magazyn)
- Menu i modyfikatory to tylko fronton. Prawdziwy biznes to **magazyn i food cost**.
- Każdy modyfikator wpływający na surowce MUSI mieć `linked_warehouse_sku` + `linked_quantity` (DECIMAL 10,4).
- Half & Half: każda połowa konsumuje surowce × **0.5** (multiplier).
- Formuła zużycia: `needed = recipe_qty × (1 + waste%/100) × multiplier` (zawsze z marnotrawstwem).
- Usuwanie składnika ("BEZ …") = darmowe dla klienta, ale omija dedukcję z magazynu (matching przez `warehouse_sku`, nie nazwę).

### Prawo III — Czwarty Wymiar (Temporal Tables)
- Statusy publikacji: `Draft` / `Live` / `Archived`.
- `valid_from` / `valid_to` sterują widocznością w czasie.
- **Soft delete** (`is_deleted = 1`) zamiast hard DELETE — ZAWSZE.

### Prawo IV — Zero Zaufania (Walidacja)
- Frontend **nigdy nie wysyła cen ani totali** — tylko SKU i ilości.
- Serwer ZAWSZE przelicza koszyk przez `CartEngine::calculate()` zanim zaakceptuje zamówienie.
- Wszystkie query parametryzowane (PDO prepared statements) — zero interpolacji.

### Prawo V — Kopalnia Wiedzy (Legacy)
- `_KOPALNIA_WIEDZY_LEGACY/` = **ARCHIWUM OFFLINE** (od 2026-04-22 — poza repo, w `.gitignore`). Fizyczny backup na dysku właściciela.
- Inwentarz historyczny w `_docs/03_MAPA_KOPALNI.md` — wiedza *co tam było* i *dokąd sięgać*, gdy potrzebna konsultacja.
- NIGDY nie kopiuj legacy 1:1. Wyciągnij zasadę — napisz NOWY kod zgodny z architekturą.
- Żelazna zasada: nie linkuj do legacy z nowego UI.

### Prawo VI — Snajper (Edycja AI)
- Poprawiasz błąd `A` → zostawiasz `B` w spokoju.
- Zakaz „globalnych optymalizacji" i halucynacji (usuwania nieznanych funkcji).
- Przed każdą zmianą UI sprawdź mapowanie na backend API.
- **Każde zapytanie SQL MUSI zawierać `tenant_id = :tid`.** Brak bariery = błąd krytyczny.

### Prawo VII — Innowacja albo Nic (Online Studio + Storefront)

> **Obowiązuje od 2026-04-19.** Dotyczy każdej funkcji związanej z prezentacją wizualną dla managera i klienta.

- **SliceHub nie jest kolejnym systemem POS / online do gastronomii.** Buduje rozwiązania, których jeszcze nie ma na rynku (Domino's, NUV POS, EZ Pizza, Papa John's, WooFood, Apprication, Glovo/Uber Eats web ordering).
- **Każda funkcja Online Studio i Storefrontu MUSI być o krok przed najlepszym konkurentem.** Jeśli opisuje się ją jednym słowem — „filtr", „slider", „picker", „color wheel", „thumbnail grid" — **nie piszemy tego kodu**. To paint, nie innowacja.
- **Każdy „bulk op" zmienia rzeczywistą zawartość:** ambient + companions + typografia + ruch + LUT + kompozycja — nie pojedynczy parametr.
- **Harmony Score / metryki jakości = numeryczne + actionable.** Nie ozdoba UI. Manager widzi liczbę i wie co nacisnąć.
- **Living Scene reaguje na świat** (pora dnia, pogoda, obciążenie kuchni, triggery z `sh_scene_triggers`), nie jest pętlą CSS animation.
- **Klient widzi OKNO do restauracji, nie product grid.** Storefront to teatr fotograficzny, nie katalog SKU.
- **Magic Enhance („THE button") to norma startu**, nie funkcja dodatkowa. Edytor zaczyna się od auto-compose, nie od pustego płótna.
- **Kontrakt z użytkownikiem:** jeśli zaczniesz się osuwać w „jeszcze jeden slider" — user zatrzymuje słowem `paint` → wracasz do tego filtra.

**Plan operacyjny (G1-G7) dla tego prawa:** `_docs/15_KIERUNEK_ONLINE.md` § 2.4.

---

## 2. STRUKTURA KATALOGÓW (Mapa Drogowa)

```
slicehub/
├── _docs/                              # Kanoniczna dokumentacja
│   ├── 00_PAMIEC_SYSTEMU.md            # TEN PLIK — czytasz PIERWSZY
│   ├── 01_KONSTYTUCJA.md               # 6 praw
│   ├── 02_ARCHITEKTURA.md              # Mapa katalogów, moduły
│   ├── 04_BAZA_DANYCH.md               # Schemat DB, relacje, konwencje
│   ├── 05_INSTRUKCJA_FOTO_UPLOAD.md    # Limity, walidacja, brief fotograficzny
│   └── ustalenia.md                    # Bieżący dokument roboczy (Online + Studio)
├── _KOPALNIA_WIEDZY_LEGACY/            # [ARCHIWUM OFFLINE — poza repo, .gitignore]
├── _archive/                           # [ARCHIWUM OFFLINE — poza repo, .gitignore]
├── api/
│   ├── auth/login.php                  # Logowanie (system / kiosk)
│   ├── online/engine.php               # Storefront (get_menu, get_dish, cart_calculate)
│   ├── cart/CartEngine.php             # Core silnik koszyka (static ::calculate)
│   ├── cart/calculate.php              # HTTP wrapper nad CartEngine
│   ├── orders/checkout.php             # Finalizacja zamówienia
│   ├── orders/edit.php                 # Edycja zamówienia
│   ├── pos/engine.php                  # POS (action-based)
│   ├── tables/engine.php               # Dine-in (zones, tables, merge)
│   ├── courses/engine.php              # Logistyka (dispatch, GPS, recall)
│   ├── kds/engine.php                  # KDS tickets
│   ├── warehouse/*.php                 # Magazyn (PZ, RW, MM, INW, KOR, WZ, stock_list...)
│   ├── backoffice/
│   │   ├── api_menu_studio.php         # Studio menu CRUD
│   │   └── api_visual_studio.php       # Upload warstw wizualnych (Studio)
│   └── delivery/{dispatch,reconcile}.php
├── core/
│   ├── db_config.php                   # PDO → $pdo
│   ├── AuthEngine.php / AuthGuard.php  # JWT + session auth
│   ├── JwtProvider.php                 # HS256 JWT
│   ├── auth_guard.php                  # Session-based guard
│   ├── CartEngine → patrz api/cart/    # (plik w api/cart)
│   ├── WzEngine.php                    # Recipe → stock deduction
│   ├── PzEngine.php                    # AVCO goods receipt
│   ├── MmEngine.php / KorEngine.php / InwEngine.php
│   ├── OrderStateMachine.php           # Statusy zamówień
│   ├── SequenceEngine.php              # Atomic doc numbering
│   ├── PromisedTimeEngine.php          # ASAP estimation
│   ├── PayrollEngine.php / ClockEngine.php / TeamPayrollEngine.php
│   ├── FoodCostEngine.php
│   ├── AsciiKeyEngine.php              # ASCII key generation (Polish → a-z0-9_)
│   ├── Integrations/PapuClient.php     # Papu/Pyszne (integracja)
│   └── js/api_client.js                # Frontend fetch wrapper
├── modules/
│   ├── studio/                         # Backoffice menu + visual compositor
│   ├── pos/                            # Kasa operacyjna (POS)
│   ├── online/                         # STOREFRONT (klient końcowy) — OBECNIE PRZEBUDOWYWANY
│   ├── tables/                         # Dine-in floor management
│   ├── kds/                            # Kitchen Display System
│   ├── waiter/                         # Aplikacja kelnera
│   ├── courses/                        # Dispatcher (Kursy)
│   ├── driver_app/                     # PWA kierowcy
│   └── warehouse/                      # Zarządzanie magazynem (PZ/RW/MM/INW/KOR)
├── database/migrations/                # 001–016 idempotentne
├── scripts/
│   ├── setup_database.php              # Migracje 006–016
│   ├── setup_enterprise_tables.php     # Dine-in (zones/tables/order_logs)
│   ├── seed_demo_all.php               # Unified demo seed (tenant, menu, ceny, PZ)
│   └── seed_ultimate_delivery.php      # Driver + GPS + orders
├── uploads/
│   ├── global_assets/                  # Shared assets (.webp z .htaccess)
│   └── visual/{tenant_id}/             # Per-tenant assets (.webp z .htaccess)
└── .cursorrules                        # Kanon reguł dla AI
```

---

## 3. BAZA DANYCH — KONWENCJE I TABELE

### Konwencje

| Reguła | Standard |
|--------|----------|
| Prefiks `sh_` | Tabele biznesowe SliceHub |
| Prefiks `sys_` | Tabele systemowe (słownik surowców) |
| Prefiks `wh_` | Tabele magazynowe |
| `tenant_id` | Obowiązkowy FK → `sh_tenant(id)` w każdej tabeli danych |
| Kwoty pieniężne | `INT` w groszach (1 PLN = 100) w tabelach zamówień/rozliczeń |
| Ceny katalogowe | `DECIMAL(10,2)` w PLN (sh_price_tiers, wh_stock) |
| UUID | `CHAR(36)` — zamówienia, linie, płatności, audyt |
| Auto ID | `BIGINT UNSIGNED AUTO_INCREMENT` — encje |
| Soft delete | `is_deleted TINYINT(1)` — NIGDY hard DELETE |
| Statusy | `VARCHAR(32)` — walidacja w aplikacji, nie ENUM |

### Kluczowe tabele (stan po migracji 016)

| Domena | Tabela | Klucze | Uwagi |
|--------|--------|--------|-------|
| Core | `sh_tenant` | `id` AI | Lokale (nie „sh_tenants"!) |
| Core | `sh_tenant_settings` | `(tenant_id, setting_key)` | KV + SLA/prep kolumny |
| Core | `sh_users` | `id` AI | role: owner/manager/waiter/cook/driver/team |
| Menu | `sh_categories` | `id` AI | `is_deleted`, `display_order`, `is_menu`. **NIE MA `is_active`!** |
| Menu | `sh_menu_items` | `id` AI | `ascii_key`=SKU, `is_active`+`is_deleted`, vat_rate_dine_in/takeaway |
| Menu | `sh_modifier_groups` | `id` AI | `min/max_selection`, `free_limit`, `allow_multi_qty` |
| Menu | `sh_modifiers` | `id` AI | `ascii_key`=SKU, `action_type`=ADD/REMOVE, `linked_warehouse_sku`+`linked_quantity` |
| Menu | `sh_item_modifiers` | `(item_id, group_id)` | M:N link |
| Menu | `sh_price_tiers` | UNIQUE `(target_type, target_sku, channel, tenant_id)` | **Macierz Cenowa** |
| Menu | `sh_recipes` | `id` AI | recipe_qty, waste_percent → food cost |
| Menu | `sh_promo_codes` | `id` AI | type=percentage/fixed, valid_from/to, allowed_channels JSON |
| Wizual | `sh_global_assets` (mig. 014) | `id` AI | shared library; `ascii_key`, `category`, `sub_type`, `filename`, `z_order`, `has_alpha` |
| Wizual | `sh_visual_layers` (mig. 012+016) | `id` AI | per-item: `item_sku`, `layer_sku`, `asset_filename`, `product_filename` [hero], `z_index`, `is_base`, `cal_scale`, `cal_rotate` |
| Wizual | `sh_board_companions` (mig. 013+016) | `id` AI | cross-sell: `item_sku`, `companion_sku`, `companion_type`, `board_slot`, `asset_filename`, `product_filename` |
| Orders | `sh_orders` | `id` CHAR(36) | statusy: new→accepted→preparing→ready→in_delivery→completed / cancelled |
| Orders | `sh_order_lines` | `id` CHAR(36) | modifiers w JSON; half-half: `is_half`, `half_a_sku`, `half_b_sku` |
| Orders | `sh_order_item_modifiers` | `id` AI | |
| Orders | `sh_order_payments` | `id` CHAR(36) | split tender ready |
| Orders | `sh_order_audit` | `id` AI | audit log zmian statusów |
| Orders | `sh_kds_tickets` | `id` CHAR(36) | KDS per-station (od mig. 006: `kds_station_id` na menu items) |
| Orders | `sh_order_sequences` | `(tenant_id, date)` | atomic `ON DUPLICATE KEY UPDATE seq = seq + 1` |
| Orders | `sh_course_sequences` | `(tenant_id, date)` | kursy K1, K2… |
| Orders | `sh_dispatch_log`, `sh_delivery_zones`, `sh_sla_breaches`, `sh_panic_log` | | Logistyka |
| Staff | `sh_drivers`, `sh_driver_shifts`, `sh_driver_locations` (mig. 008) | | GPS UPSERT |
| Staff | `sh_work_sessions`, `sh_deductions`, `sh_meals` | | Payroll |
| WH | `sys_items` | `id` AI + `sku` | słownik surowców (kg/l/szt) |
| WH | `wh_stock` | `(tenant_id, warehouse_id, sku)` | + `current_avco_price` (AVCO) |
| WH | `wh_documents` / `wh_document_lines` | | **KANON** — types: PZ/WZ/MM/INW/KOR/RW (wszystkie silniki magazynu: `WzEngine`, `PzEngine`, `InwEngine`, `KorEngine`, `MmEngine`) |
| WH | `wh_stock_logs` | | audit trail zmian stanu |
| — | ~~`wh_inventory_docs` / `wh_inventory_doc_items`~~ | | **USUNIĘTE w m038** (były martwe legacy, 0 wierszy). Kanon inwentaryzacji: `wh_documents` type=INW + `wh_document_lines`. |
| WH | `sh_product_mapping` | | external_name → sku (AutoScan faktur) |
| WH | `sh_doc_sequences` | `(tenant_id, doc_type, doc_date)` | numerator dokumentów |

### Migracje (stan 040 · 🧊 039/040 freeze — patrz `17_OFFLINE_POS_BACKLOG.md`)

| Nr | Co |
|----|-----|
| 001 | Grand schema (wszystkie tabele, widoki, FK) |
| 004 | sys_items: search_aliases, is_active, is_deleted |
| 006 | sh_categories VAT defaults, sh_menu_items PLU |
| 007 | sh_orders: receipt_printed, kitchen_ticket_printed, cart_json, NIP |
| 008 | sh_driver_locations (GPS UPSERT) |
| 009 | Delivery state machine |
| 010 | Driver action types |
| 011 | Integration logs |
| 012 | sh_visual_layers |
| 013 | sh_board_companions |
| 014 | sh_global_assets (sh_ingredient_assets — DROPped w m025) |
| 015 | Normalize three drivers |
| 016 | sh_visual_layers: +product_filename +cal_scale +cal_rotate; sh_board_companions: +product_filename; sh_tenant_settings: +storefront_surface_bg |
| 017 | Online module extensions: sh_visual_layers +version/+library_category/+library_sub_type; sh_orders +tracking_token; sh_checkout_locks; online settings |
| 018 | ~~sh_modifier_visual_map (Magic Dictionary)~~ — **DROPped w m025**, zastąpione przez sh_asset_links + sh_modifiers.has_visual_impact |
| 019 | sh_visual_layers +offset_x +offset_y |
| 020 | sh_atelier_scenes (DishSceneSpec JSON per dish) + sh_atelier_scene_history |
| 021 | Unified Asset Library: sh_assets + sh_asset_links (+ backfill z legacy, views v_menu_item_hero/v_visual_layer_asset, stare kolumny DEPRECATED_M021). *Rola `modifier_icon` i widok `v_modifier_icon` usunięte w m025.* |
| 022 | **Scene Kit & The Table Foundation** (Faza 1 Scene Studio): sh_scene_templates (biblioteka szablonów), sh_promotions + sh_scene_promotion_slots, sh_style_presets (12 stylów Hollywood), sh_category_styles, sh_scene_triggers, sh_scene_variants, sh_ai_jobs. Ext: sh_menu_items +composition_profile; sh_categories +layout_mode +default_composition_profile +category_scene_id; sh_atelier_scenes +scene_kind +template_id +parent_category_id +active_style_id +active_camera_preset +active_lut +atmospheric_effects_enabled_json; sh_board_companions +cta_label +is_always_visible +slot_class. Szczegóły historyczne: `ARCHIWUM/FAZA_1_STATUS.md` |
| 024 | sh_modifiers.has_visual_impact (flaga „ten modyfikator zmienia wygląd dania"; sloty w sh_asset_links role `layer_top_down` + `modifier_hero`) |
| 025 | **Cleanup** — DROP sh_modifier_visual_map, DROP sh_ingredient_assets, DROP VIEW v_modifier_icon, DELETE sh_asset_links(role='modifier_icon'). Jedno źródło prawdy: Modifier Visual Slots (m021+m024) |
| 026 | **Event System (Faza 7 · sesja 7.1)** — sh_event_outbox (transactional outbox), sh_webhook_endpoints (subskrybenci), sh_webhook_deliveries (retry log), sh_tenant_integrations (3rd-party POS registry). Decouples modules: producents (POS/Online/KDS/Delivery/Courses/Gateway) publikują eventy przez `OrderEventPublisher`, konsumenci (worker + adapters) konsumują asynchronicznie. Szczegóły: `_docs/09_EVENT_SYSTEM.md` |
| 027 | **Gateway v2 (Faza 7 · sesja 7.2)** — sh_gateway_api_keys (multi-key auth, per-source binding, scopes, SHA-256 hashe sekretów), sh_rate_limits (sliding window per klucz: minute+day), sh_external_order_refs (idempotency `(tenant,source,external_id)→order_id` z request_hash), rozszerzenie sh_orders o gateway_source + gateway_external_id. `api/gateway/intake.php` staje się jedną bramką dla web / mobile_app / kiosk / aggregator_uber / aggregator_glovo / aggregator_pyszne / aggregator_wolt / pos_3rd / public_api. Szczegóły: `_docs/10_GATEWAY_API.md` |
| 028 | **Integration Deliveries (Faza 7 · sesja 7.4)** — sh_integration_deliveries (per event×integration state: pending/delivering/delivered/failed/dead, attempts, next_attempt_at, external_ref z 3rd-party), sh_integration_attempts (full audit timeline per HTTP request), rozszerzenie sh_tenant_integrations o consecutive_failures / last_failure_at / max_retries / timeout_seconds (auto-pause przy wyczerpaniu retry). Konsumowane przez `scripts/worker_integrations.php` + `core/Integrations/{Papu,Dotykacka,GastroSoft}Adapter`. Szczegóły: `_docs/12_INTEGRATION_ADAPTERS.md` |
| 029 | **Infrastructure Completion (Faza 7 · sesja 7.6)** — `sh_settings_audit` (trail zmian konfiguracji – GDPR/compliance), `sh_inbound_callbacks` (log przychodzących callbacków 3rd-party). Domyka warstwę integracji event-driven. |
| 030 | **Scene Harmony Cache (Faza 2.4 · G4)** — `sh_scene_metrics` cache metryki spójności sceny (0–100). Manager widzi numeryczny badge w Viewport; system blokuje publikację gdy score < 50. Oparte o wariancję cal_scale/cal_rotate/brightness/saturation. |
| 031 | **Baked Variants (Faza 2 · M3 #7)** — `sh_assets.cook_state`: `raw` / `cooked` / `charred` / `either`. Pozwala mieć wiele wersji tego samego składnika (np. surowe/upieczone pieczarki). |
| 032 | **Asset Library Organizer (Faza 2 · M5)** — `sh_assets.display_name` (VARCHAR 191, ludzka etykieta), `sh_assets.tags_json` (longtext, array tagów), 3 indexy: `idx_assets_display_name(tenant_id, display_name(64))`, `idx_assets_cat_active(tenant_id, category, is_active)`, `idx_assets_checksum_tenant(tenant_id, checksum_sha256)`. |
| 033 | **Notification Director** — `sh_customer_contacts` (mini-CRM), `sh_customer_inbox`, `sh_marketing_campaigns`, `sh_sse_broadcast`, `sh_notification_channels` / `_routes` / `_templates` / `_deliveries`; rozszerzenie `sh_orders` o email/zgody RODO/timestampy lifecycle. Rdzeń: `core/Notifications/NotificationDispatcher` (claim bez SKIP LOCKED — MariaDB 10.4 compat). |
| 034 | **GDPR & Security hardening (Faza 7)** — `sh_notification_channels.webhook_secret` (HMAC), sanityzacja `sh_notification_deliveries` (usunięte raw body, tylko HTTP status), `sh_rate_limit_buckets` (generyczny rate-limit per kanał), `sh_gdpr_consent_log`, `sh_security_audit_log`. |
| 035 | **Atelier Performance** (wcześniej numerowane 021a) — generated columns + indexy w `sh_atelier_scenes`, `sh_asset_registry` (precomputed metadata dla `SharedSceneRenderer`). Przesunięte na koniec chainu po rozwiązaniu kolizji z m021_unified_asset_library. |
| 036 | **Asset Display Name backfill** (wcześniej numerowane 032_asset_display_name) — UPDATE na `sh_assets.display_name`: mapowanie ascii_key → polska nazwa dla istniejących rekordów. Kolumna jest tworzona przez m032_asset_library_organizer; ta migracja to wyłącznie backfill. |
| 037 | **POS / Dine-In Foundation** — `sh_zones`, `sh_tables` (floor-plan coords, QR, merging), `sh_order_logs` (audit trail używany przez `OrderStateMachine`). Rozszerzenia: `sh_order_payments` +created_at/+payment_method/+user_id; `sh_orders` +table_id/+waiter_id/+guest_count/+split_type/+qr_session_token +2 FK; `sh_order_lines` +course_number/+fired_at (multi-course pacing). Anti-ghosting: generated column `_active_table_guard` + UNIQUE INDEX (max 1 aktywne zamówienie per stolik). Wcześniej ta logika była wyłącznie w `scripts/setup_enterprise_tables.php` (uruchamianym ręcznie); od m037 jest w kanonicznym chain migracji. Skrypt PHP zostaje jako awaryjny helper. |
| 038 | **Drop Legacy Inventory Docs** — DROP `wh_inventory_docs` + `wh_inventory_doc_items`. Obie tabele były martwe od m001 (nigdy nie referencowane w kodzie PHP, zero wierszy w bazie). Kanon inwentaryzacji: `wh_documents` (type=INW) + `wh_document_lines`. |
| 039 | 🧊 **Resilient POS Foundation (P1–P3)** — `sh_pos_terminals` (rejestracja urządzeń per tenant), `sh_pos_sync_cursors` (stan synchronizacji per terminal), `sh_pos_op_log` (idempotent log operacji z UUID v7 PK). Obsługuje push klient→serwer. **FREEZE 2026-04-23** — edycja tylko przez rozmrożenie + nowa migracja. Spec: `_docs/16_RESILIENT_POS.md`. |
| 040 | 🧊 **Resilient POS · Phase 3.5 — Server→Client delta stream** — `sh_pos_server_events` (append-only log eventów serwer→POS, retention 7 dni) + rozszerzenie `sh_pos_sync_cursors` o `pull_events_total` / `pull_last_count` / `pull_last_fetched_at`. **FREEZE 2026-04-23.** Po rozmrożeniu — producenci publikują przez `sh_event_outbox` (m026) + nowy `scripts/worker_pos_fanout.php` (P4.5). Zakaz bezpośredniego `INSERT` z innych modułów. |

---

## 4. ARCHITEKTURA API — WZORZEC `engine.php`

**Jeden moduł biznesowy = jeden `engine.php`** z action-based routingiem (`switch($action)` lub `if/elseif`). Wyjątki: endpointy multipart/form-data (upload) mogą być osobnymi plikami ze względu na specyfikę.

### Szablon endpointu (wzorzec POS/Tables/Courses/Online):

```php
<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function response(bool $ok, $data = null, ?string $msg = null): void {
    echo json_encode(['success'=>$ok,'data'=>$data,'message'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    // Jeśli chroniony: require_once __DIR__ . '/../../core/auth_guard.php';
    //   → $pdo, $tenant_id, $user_id są dostępne
    // Jeśli publiczny (storefront): tenantId z POST body

    $input  = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    // Schema detection dla opcjonalnych tabel
    $hasX = false;
    try { $pdo->query("SELECT 1 FROM sh_x LIMIT 0"); $hasX = true; } catch (\PDOException $e) {}

    if ($action === 'foo') { /* ... */ response(true, $data, 'OK'); }
    if ($action === 'bar') { /* ... */ response(true, $data, 'OK'); }

    response(false, null, "Nieznana akcja: {$action}");
} catch (\Throwable $e) {
    error_log('[ModuleName] ' . $e->getMessage());
    response(false, null, 'Blad serwera: ' . $e->getMessage());
}
```

### Kontrakt JSON (wszędzie)

```json
{ "success": true|false, "data": { ... }|null, "message": "Treść" }
```

**Zero `echo`/`var_dump`/`print_r` przed wysłaniem JSON-a.** Zero HTML leakage.

### Frontend API client wzorzec (jak `pos_api.js`)

Prosty wrapper fetch POST → JSON, obsługa błędów i auth header. Powinien być per-moduł (np. `online_api.js`, `pos_api.js`) korzystając z `core/js/api_client.js` jako bazy.

---

## 5. SILNIKI CORE — CO JUŻ MAMY I JAK UŻYWAĆ

### `api/cart/CartEngine.php` — silnik koszyka
- **Sygnatura:** `public static function calculate(PDO $pdo, int $tenantId, array $input): array`
- `$input = ['channel'=>'POS|Takeaway|Delivery', 'order_type'=>'dine_in|takeaway|delivery', 'lines'=>[...], 'promo_code'=>'...']`
- Zwraca: `['channel','order_type','subtotal_grosze','discount_grosze','delivery_fee_grosze','grand_total_grosze','loyalty_points','applied_promo_code','lines_raw','response']`
- Half & Half: `['is_half'=>true, 'half_a_sku'=>'...', 'half_b_sku'=>'...']` — formuła `max(priceA, priceB) + half_half_surcharge` (z `sh_tenant_settings`).
- Usunięcia: `'removed_ingredient_skus'=>['...']` — darmowe, wpływ tylko na magazyn.
- **Wszystkie pieniądze w groszach (INT) w środku; `toFixed(2)` tylko dla `response.*`.**

### `core/WzEngine.php` — zużycie surowców
- Wywołuje się po akceptacji zamówienia.
- Formuła: `needed = recipe_qty × (1 + waste_percent/100) × multiplier`.
- Half & Half: multiplier 0.5 per połowa.
- Modyfikatory: używa `linked_quantity` + `linked_waste_percent` z `sh_modifiers` (nie hardcode 1.0/0.05 jak legacy).
- Matching usunięć przez `warehouse_sku` (immutable), nie nazwę.
- Generuje WZ doc i loguje do `wh_stock_logs`.

### `core/PzEngine.php` — przyjęcie + AVCO
- Formuła AVCO: `(old_qty × old_avco + new_qty × unit_cost) / (old_qty + new_qty)`, jeśli `old_qty ≤ 0` → `new_avco = unit_cost`.
- Mapping faktury → SKU przez `sh_product_mapping` (case-insensitive).

### `core/OrderStateMachine.php` — machina stanów
- Statusy: `new → accepted → preparing → ready → in_delivery → completed` / `cancelled`.
- Transitions whitelisted. Audit do `sh_order_audit`.

### `core/OrderEventPublisher.php` — transactional outbox (m026, Faza 7)
- **Cel:** publikowanie eventów lifecycle (`order.created`, `order.accepted`, `order.preparing`, `order.ready`, `order.dispatched`, `order.delivered`, `order.completed`, `order.cancelled`, `order.recalled`, `order.edited`) do `sh_event_outbox` w tej samej transakcji co zapis do `sh_orders`.
- **Gwarancje:** idempotency (UNIQUE tenant_id+key), silent degradation gdy tabela nie istnieje, snapshot payload (worker nie joinuje przy dispatchu).
- Metody: `publish()`, `publishOrderLifecycle()` (auto-snapshot order header + lines).
- Używane w: `api/online/engine.php#guest_checkout`, `api/gateway/intake.php`, `api/pos/engine.php`, `api/kds/engine.php` (bump+recall), `api/delivery/dispatch.php`, `api/courses/engine.php`.
- Konsumenci (async): `scripts/worker_webhooks.php` (m026 · Sesja 7.3), `scripts/worker_integrations.php` (m028 · Sesja 7.4), 3rd-party adapters w `core/Integrations/`.
- Szczegóły: `_docs/09_EVENT_SYSTEM.md`.

### `core/WebhookDispatcher.php` + `scripts/worker_webhooks.php` — async delivery (Faza 7 · sesja 7.3)
- **Cel:** konsument `sh_event_outbox` (m026). Pullem (cron/loop) bierze pending eventy, znajduje matching `sh_webhook_endpoints`, wysyła podpisany HMAC-SHA256 payload, loguje do `sh_webhook_deliveries`.
- **Gwarancje:** at-least-once delivery, FIFO po `id ASC`, atomic claim (UPDATE … WHERE status='pending' + rowCount check → race-safe w multi-worker setupie), PID-lock per node (single-instance guard).
- **Exponential backoff:** 30s → 2min → 10min → 30min → 2h → 6h → 24h; po wyczerpaniu `MAX_ATTEMPTS_DEFAULT=6` → `status='dead'` (Dead Letter Queue).
- **Klasyfikacja błędów:** 2xx → delivered | 408/429/5xx/0 (timeout) → transient retry | inne 4xx → permanent (straight to dead).
- **Auto-pause subskrybenta:** `consecutive_failures >= max_retries` → `is_active=0` (manager musi ręcznie reaktywować).
- **Signature format:** `X-Slicehub-Signature: t=<ts>,v1=<hex_hmac>` gdzie `hmac = HMAC-SHA256(secret, "{ts}.{body}")`. Stripe-style timestamped signature z 5-min replay window po stronie subskrybenta.
- **CLI flagi:** `--loop`, `--sleep=N`, `--batch=N`, `--dry-run`, `--max-batches=N`, `-v`. Obsługa SIGTERM/SIGINT (graceful shutdown). Exit codes: 0 OK / 1 DB error / 2 locked / 3 exception.
- **Cron:** `* * * * * php scripts/worker_webhooks.php >> logs/webhooks.log 2>&1`.
- **Injectable transport:** konstruktor WebhookDispatchera przyjmuje callable → testy + `--dry-run` bez prawdziwego cURL.
- Szczegóły: `_docs/11_WEBHOOK_DISPATCHER.md`.

### `core/Integrations/` — 3rd-party POS adapters (m028, Faza 7 · sesja 7.4)
- **Cel:** warstwa CONCRETE PROVIDER między `sh_event_outbox` a konkretnymi API (Papu.io, Dotykačka, GastroSoft, …). Uzupełnia `WebhookDispatcher` (generyczne HTTP) o mapowanie per-provider: payload shape, auth headers, response semantics.
- **Kontrakt (`BaseAdapter`):** `providerKey()`, `displayName()`, `buildRequest($envelope)` → `[method, url, headers, body]`, `parseResponse($code, $body, $transportErr?)` → `['ok', 'transient', 'externalRef', 'error']`, `supportsEvent($eventType)` (filtr z `events_bridged` JSON).
- **Registry (`AdapterRegistry`):** `PROVIDER_MAP` mapuje `sh_tenant_integrations.provider` → klasa PHP (`papu` → PapuAdapter, `dotykacka` → DotykackaAdapter, `gastrosoft` → GastroSoftAdapter). Cache per tenant_id. Feature-detect fallback gdy health columns brakują.
- **Adaptery:** PapuAdapter (Bearer + opcjonalny HMAC `X-Papu-Signature`), DotykackaAdapter (OAuth2 Bearer z refresh flow, MVP używa raw refresh_token), GastroSoftAdapter (X-Api-Key, 409 Conflict = idempotency success).
- **Dispatcher (`IntegrationDispatcher`):** konsument outboxa, własny state w `sh_integration_deliveries` (**nie modyfikuje** `sh_event_outbox.status` — webhook worker robi to niezależnie). Retry schedule: 30s/2min/10min/30min/2h/6h/24h, max 6 attempts → `dead`. Auto-pause integracji (`consecutive_failures >= max_retries`). Injectable HTTP transport (cURL + dry-run).
- **Worker (`scripts/worker_integrations.php`):** CLI cron/loop, PID-lock (`logs/worker_integrations.pid`), SIGTERM graceful, flagi: `--loop --sleep=N --batch=N --dry-run --max-batches=N -v --help`. Exit codes: 0 OK · 1 DB · 2 locked · 3 exception.
- **Audit log:** `sh_integration_attempts` (per HTTP request — attempt_number, http_code, duration_ms, request_snippet 500B, response_body 2KB, error_message). Debug timeline dla flaky adapterów.
- Szczegóły: `_docs/12_INTEGRATION_ADAPTERS.md`.

### `core/CredentialVault.php` — transparent AEAD encryption at rest (Faza 7 · sesja 7.5)
- **Cel:** szyfrowanie wrażliwych pól (`sh_tenant_integrations.credentials`, `sh_webhook_endpoints.secret`) libsodium **XChaCha20-Poly1305** z kluczem w env `SLICEHUB_VAULT_KEY` lub `config/vault_key.txt`.
- **Format:** `vault:v1:<base64(nonce[24] || ciphertext || tag[16]))>`. Wartości BEZ prefixu wracają as-is (legacy plaintext compat — pozwala migrować stopniowo).
- **Integracja:** `BaseAdapter::credentials()` i `WebhookDispatcher::performDelivery()` robią transparent decrypt; `api/settings/engine.php` szyfruje przy zapisie.
- **Graceful degradation:** brak libsodium lub brak klucza → `encrypt()` zwraca plaintext z warning do `error_log` (nie crashuje, panel Settings pokazuje status "PLAINTEXT" w topbar).
- **Bootstrap:** `php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"` → env/plik. Future key rotation przez `vault:kid=NN:vN:…` prefix.
- Szczegóły: `_docs/13_SETTINGS_PANEL.md`.

### Settings Panel — `api/settings/engine.php` + `modules/settings/` (Faza 7 · sesja 7.5)
- **Cel:** UI admina dla Integrations / Webhooks / API Keys / DLQ / Health w jednym miejscu (wcześniej: tylko SQL).
- **Backend (`api/settings/engine.php`):** unified action dispatcher — akcje `integrations_list|save|toggle|delete|test_ping`, `webhooks_list|save|toggle|delete|test_ping`, `api_keys_list|generate|revoke`, `dlq_list|dlq_replay`, `health_summary`. Każda akcja tenant-scoped (session/JWT), prepared statements, redacted credentials w responsach listowych.
- **Test Ping:** sync path — buduje syntetyczny `order.created` envelope z flagą `_test_ping=true`, wywołuje `adapter.buildRequest()` → cURL → `adapter.parseResponse()`, zwraca pełny raport (stage, http_code, transport_error, externalRef, transport_ms). **Bez persystencji** w outbox/deliveries.
- **DLQ Replay:** SQL `UPDATE … SET status='pending', attempts=0, next_attempt_at=NOW(), last_error=CONCAT('REPLAY …')` na `sh_event_outbox` (webhooks) lub `sh_integration_deliveries` (integrations). Worker weźmie event w następnym batchu. `sh_integration_attempts` (audit log) nigdy nie czyszczony.
- **Secret-once flow:** nowo wygenerowany webhook secret / Gateway API key zwracane raz w response `save/generate`, pokazywane w modalu UI z clipboard copy. Backend **nigdy** nie zwraca raw sekretów w `*_list`.
- **UI (`modules/settings/`):** vanilla JS single-file app (~600 LoC), zero build step, dark theme, mobile responsive, 5 zakładek. Vault status badge w topbar (zielony/pomarańczowy).
- **7.6 (2026-04-18):** zamknięcie infrastructure layer — `CSRF token` (session-stored, double-submit przez header `X-CSRF-Token`), `rate limit test_ping` (max 5/min per tenant via `sh_settings_audit`), automatyczny `audit log` każdej mutacji w `sh_settings_audit` (m029) z redact'em secretów.
- Szczegóły: `_docs/13_SETTINGS_PANEL.md`, `_docs/14_INBOUND_CALLBACKS.md`.

### Inbound Callbacks — `api/integrations/inbound.php` + `BaseAdapter::parseInboundCallback()` (Faza 7 · sesja 7.6)
- **Cel:** generic receiver dla callbacków od 3rd-party POS/delivery (Papu, Dotykacka, Uber, Glovo, …). Zamknięcie symetrii integracji: outbound (my → oni) + inbound (oni → my).
- **Endpoint:** `POST /api/integrations/inbound.php?provider=<key>&integration_id=<n>` — publiczny, auth przez HMAC signature w headerze (adapter-specific). Każdy callback loguje się do `sh_inbound_callbacks` (m029) **PRZED** walidacją — bad sigs też ląduje dla debugu.
- **Flow:** INSERT log → lookup integrations + decrypt credentials → `AdapterRegistry` → `adapter.parseInboundCallback($raw, $headers, $creds)` → verify signature + map status → idempotency check `UNIQUE(provider, external_event_id)` → match `external_ref` → `gateway_external_id` → whitelist transition UPDATE `sh_orders.status` → `OrderEventPublisher::publishOrderLifecycle()` → UPDATE `sh_inbound_callbacks.status='processed'`.
- **Adapter kontrakt:** `BaseAdapter::supportsInbound(): bool` (default false), `BaseAdapter::parseInboundCallback($rawBody, $headers, $credentials)` → `{ok, signature_verified, external_event_id, external_ref, event_type, new_status, payload, error?}`.
- **Reference impl:** `PapuAdapter::parseInboundCallback()` — pełny flow HMAC-SHA256 (`X-Papu-Signature: t=<ts>,v1=<hmac>`, replay-window 5 min, timing-safe `hash_equals`). Dotykacka/GastroSoft — stub (rzuca `not implemented` aż ktoś doda).
- **Whitelisted transitions:** identyczne jak `OrderStateMachine` (`new→accepted`, `ready→dispatched`, `in_delivery→delivered`, …). Cofnięcie statusu zablokowane.
- **Idempotency:** `UNIQUE(provider, external_event_id)` — prowider retryuje → drugi POST wraca 200 OK bez re-processingu (`duplicate:true`).
- Szczegóły: `_docs/14_INBOUND_CALLBACKS.md`.

### Vault Scripts — `scripts/bootstrap_vault.php` + `scripts/rotate_credentials_to_vault.php` (Faza 7 · sesja 7.6)
- **bootstrap_vault.php:** generuje 32-byte XChaCha20 klucz, zapisuje do `config/vault_key.txt` (0600). Flagi: `--force` (overwrite), `--print-only` (stdout). Abortuje gdy klucz już jest (prevent data loss).
- **rotate_credentials_to_vault.php:** migruje plaintext credentials w `sh_tenant_integrations.credentials` + `sh_webhook_endpoints.secret` do formatu `vault:v1:...`. Flagi: `--dry-run` (default), `--live`, `--only=integrations|webhooks`. Przed UPDATE robi self-test encrypt→decrypt roundtrip (skip gdy fail). Idempotent — już zaszyfrowane rekordy są skip'owane.

### `core/GatewayAuth.php` — multi-key auth + rate limiter + idempotency (m027, Faza 7)
- **Cel:** warstwa autoryzacji dla `api/gateway/intake.php` v2. Obsługuje zewnętrznych callerów (aggregatory, kioski, 3rd-party POS, public API, własna apka mobilna).
- Metody:
  - `authenticateKey(pdo, providedKey, tenantFromPayload?)` — lookup w `sh_gateway_api_keys` po `key_prefix` + `hash_equals(SHA-256(secret))`. Legacy fallback do env `GATEWAY_API_KEY` gdy tabela nie istnieje.
  - `checkAndIncrementRateLimit(pdo, apiKeyId, perMin, perDay)` — sliding window w `sh_rate_limits` z `INSERT ... ON DUPLICATE KEY UPDATE`. Fail-open gdy DB awaria.
  - `lookupExternalRef/storeExternalRef` — idempotency map `(tenant, source, external_id) → order_id` w `sh_external_order_refs` (z SHA-256 request_hash dla wykrywania replay z różnymi payloadami).
  - `generateKey(env)` — factory dla UI Settings (Sesja 7.5): generuje prefix + 192-bit secret, zwraca raw secret **1×** (hash w DB).
- **Gwarancje:** sekrety nigdy plaintext, timing-safe comparison, source-binding (klucz `aggregator_uber` nie puszcza zamówień jako `aggregator_glovo`).
- Szczegóły: `_docs/10_GATEWAY_API.md`.

### `core/SequenceEngine.php` — numeracja
- Atomic `INSERT ... ON DUPLICATE KEY UPDATE seq = seq + 1`.
- Formaty: `ORD/YYYYMMDD/NNNN`, `WWW/YYYYMMDD/NNNN`, `PZ/YYYYMMDD/NNNN`, `K{n}` dla kursów.

### `core/AsciiKeyEngine.php` — generator kluczy
- Polskie znaki → ASCII (`ą→a, ć→c, ę→e, ł→l, ń→n, ó→o, ś→s, ź→z, ż→z`).
- Non-alphanumeric → `_`, collapse, trim.
- Unique check w obrębie tenanta; collision → `_2`, `_3`.

### `core/AssetResolver.php` — resolver URL-i obrazków (m021, m025)
- **Cel:** jedno źródło prawdy dla `imageUrl` przy READ-path.
- Hierarchia: `sh_asset_links(hero)` → `sh_menu_items.image_url` (legacy) → null.
- Metody: `publicUrl()`, `batchHeroUrls()`, `injectHeros()`, `resolveHero()`, `isReady()`.
- **Zawsze cicho** (no exceptions). Cache per-request. Używane w `api/online/engine.php`, `api/backoffice/api_menu_studio.php`, `api/pos/engine.php`.
- *Metody `batchModifierIcons`/`injectModifierIcons` + rola `modifier_icon` usunięte w m025 — modyfikatory nie mają dedykowanych ikon; wizualizacja przez `SceneResolver::resolveModifierVisuals` (role `layer_top_down` + `modifier_hero`).*

### `core/SceneResolver.php` — resolver pełnego kontraktu sceny (m022, Faza 1)
- **Cel:** pełny wizualny kontrakt dania (hero + scene_spec + layers + companions + promotions + meta) dla Menu Studio, The Table, integracji zewnętrznych.
- Publiczne metody:
  - `resolveDishVisualContract($pdo, $tenantId, $sku, $channel?)` → pełny kontrakt
  - `resolveCategoryScene($pdo, $tenantId, $categoryId)` → scena kategorii + lista items
  - `resolveCategoryStyle($pdo, $tenantId, $categoryId)` → aktywny styl kategorii
  - `batchResolveForCategory($pdo, $tenantId, $categoryId)` → batch mini-kontraktów
  - `getSceneTemplate($pdo, $asciiKey)` → template lookup (cache per-request)
  - `getStylePreset($pdo, $keyOrId)` → style preset lookup
  - `checkActiveTrigger($pdo, $tenantId, $sceneId)` → runtime Scene Triggers (date/time/day_of_week)
- Hierarchia fallback: `sh_atelier_scenes.spec_json.pizza.layers` → `sh_visual_layers` → `[]`; active_style: scene → category → template default → null.
- Zawsze cicho (no exceptions). Cache per-request (templates, styles, column-exists).
- **Immutable** po przypisaniu (referenced przez `sh_price_tiers`, `sh_recipes`).

---

## 6. OMNICHANNEL + HALF-HALF + BLIŹNIAK — REFERENCYJNY FLOW

1. **Klient** (storefront/POS/kiosk) → wybiera danie, modyfikatory, ewentualnie Half & Half.
2. **Frontend** → wysyła POST do `engine.php` swojego modułu z `{action:'cart_calculate', tenantId, channel, lines:[...]}` — **tylko SKU + qty, zero cen**.
3. **Backend `engine.php`** → deleguje do `CartEngine::calculate($pdo, $tenantId, $input)`.
4. **CartEngine**:
   - Resolve ceny z `sh_price_tiers` dla danego `channel` (fallback do `POS`).
   - Half & Half: `max(priceA, priceB) + half_half_surcharge`.
   - Modyfikatory: per-SKU z `sh_price_tiers` (NIE hardcode +4 PLN).
   - Usunięcia: zero cena, flag do magazynu.
   - VAT: `vat_rate_dine_in` jeśli `order_type='dine_in'`, inaczej `vat_rate_takeaway`. Kwota VAT = `gross × rate / (100 + rate)`.
   - Promo (jeśli kod): validate window, uses, min_order, allowed_channels.
5. **Zwrot** → serwer oddaje serwer-autorytatywny `grand_total`, `vat_summary`, structured lines.
6. **Checkout publiczny (storefront)** → `api/online/engine.php#init_checkout` tworzy `lock_token`, potem `api/online/engine.php#guest_checkout` rekalkuluje koszyk, woła `WzEngine::checkAvailability()`, zapisuje `sh_orders` + `sh_order_lines`, generuje `tracking_token` i konsumuje lock.
7. **Checkout chroniony / kanoniczny** → `api/orders/checkout.php` pozostaje endpointem auth-guarded dla ścieżek wewnętrznych; ma rozszerzenia pod `source='ONLINE'`, ale nie jest jeszcze publicznym wejściem storefrontu.

---

## 7. AUTH — KTO CZYTA, KTO PISZE

| Endpoint | Auth | Skąd tenant |
|----------|------|-------------|
| `api/online/engine.php` | **PUBLIC** (storefront — klient anonimowy; `delivery_zones`, `init_checkout`, `guest_checkout`, `track_order`) | `tenantId` w POST body |
| `api/cart/calculate.php` | PUBLIC | POST body |
| `api/orders/checkout.php` | `auth_guard.php` (sesja/JWT) | `$tenant_id` z guardu |
| `api/pos/engine.php` | `auth_guard.php` (sesja/JWT) | `$tenant_id` z guardu |
| `api/tables/engine.php` | `auth_guard.php` | `$tenant_id` z guardu |
| `api/courses/engine.php` | `auth_guard.php` (dispatcher) + PIN (driver app) | guard |
| `api/backoffice/api_menu_studio.php` | `auth_guard.php` (owner/admin/manager) | guard |
| `api/backoffice/api_visual_studio.php` | `auth_guard.php` + multipart | guard |
| `api/warehouse/*` | `auth_guard.php` (manager+) | guard |

**RBAC matrix** (w 27. sekcji `OPTIMIZED_CORE_LOGIC_V2.md`):
- `owner` → wszystko
- `manager` → floor, delivery, staff, inventory, studio
- `cook` → KDS, inventory read
- `waiter` → POS (dine-in), tables
- `driver` → driver app, fleet
- `employee` → team app, clock in/out, chat

**Hard rule:** brak silnego defaultu `tenant_id ?? 1` — musi być jawnie dostarczony albo `401 Unauthorized`.

---

## 8. UI / UX — MOTYWY PER MODUŁ

| Moduł | Motyw | Filozofia |
|-------|-------|-----------|
| **POS** | Dark Battlefield (zwarte, 7-12px fonty, operator-focus) | Szybkość, gęstość info, kolorowane stany |
| **Tables / Courses** | Dark Glass (Tailwind `bg-white/5`, `backdrop-blur-md`) | Dyspozycja, orientacja przestrzenna |
| **Studio** | Dark Glass + Tailwind | Zarządzanie menu, macierz cenowa, kompozytor |
| **Warehouse** | Dark Glass + Tailwind | Dokumenty, tabele, AVCO |
| **Online (storefront)** | **The Surface** — ciemna fotograficzna powierzchnia + glass-morphism elementy | Klient końcowy, immersyjne, progressive disclosure |
| **Driver App (PWA)** | High-contrast dark, 56px touch, safe-area-inset | Mobile-first, jedna ręka |

**Alert 86:** wszystkie stany ≤ 0 → `text-red-500 font-bold` (magazyn, SLA, wariancje).

**Accessibility:** `prefers-reduced-motion` dla animacji; kontrast min. 4.5:1 nad Surface.

---

## 9. MODUŁ ONLINE (STOREFRONT) — WIZJA „THE SURFACE"

### Koncept
Cała strona to **ciemna fotograficzna powierzchnia** (uploadowany przez managera w Studio kamień/drewno/marmur). Produkty „materializują się" na niej jak u food stylisty — każdy składnik ma DWA zdjęcia: **scatter layer** (rozsypany na pizzy) i **hero product** (pojedynczy obiekt na powierzchni).

### Architektura dwupoziomowa

**Poziom 1 — Strona menu (przeglądanie):**
- Akordeon kategorii (wąskie paski ~48px, glass-morphism, jedna otwarta naraz).
- Lazy loading miniaturek.
- Każda pozycja: miniaturka + nazwa + krótki opis + cena (lub warianty inline, np. „30cm 29,99 / 37cm 39,99").
- Klik → otwiera Poziom 2.

**Poziom 2 — Karta dania (Surface Card):**
- Mobile: bottom sheet (snap: pół → pełny); Desktop: modal / side panel.
- **Pizza** po lewej: półpizza (prawy półkrąg, płaska krawędź przy lewej ścianie) jako domyślny widok.
- **Companions** po prawej: sos, napój — jako zdjęcia hero + „DODAJ".
- **Modyfikatory** pod spodem: kategorie zwinięte (WARZYWA → 3 widoczne + „Pokaż więcej").
- **Tryb Podgląd / Edycja**: w Edycji warstwy „ożywają" przy kliknięciu.
- **Half & Half**: widoczna połowa dzieli się HORYZONTALNIE (góra = Pizza A, dół = Pizza B).

### Koszyk — CAŁKOWICIE ODDZIELONY
- Floating Action Button (mobile, prawy dolny róg) z badge liczby pozycji.
- Desktop: ikona w nagłówku + dropdown/panel.
- Komunikacja: `CustomEvent('pizza-add-to-cart')` — karta dispatchuje, koszyk nasłuchuje.
- Przed dodaniem → serwer-autorytatywna walidacja przez `cart_calculate`.

### Backend (GOTOWY ✅) — `api/online/engine.php`
Akcje:
- `get_storefront_settings` → `{tenant, channel, surfaceBg, halfHalfSurcharge}`
- `get_menu` → `{channel, categories:[{id,name,items:[{sku,name,description,imageUrl,price,priceFallback}]}]}`
- `get_dish` → `{item, modifierGroups, companions, visualLayers, globalAssets, halfHalfSurcharge}`
- `cart_calculate` → serwer-autorytatywna wycena (deleguje do `CartEngine::calculate`)

### Frontend — do implementacji zgodnie z tym dokumentem
```
modules/online/
├── index.html              # Czysty shell (jak POS/Tables)
├── manifest.json           # Jeśli PWA
├── css/style.css           # Surface theme
└── js/
    ├── online_api.js       # Wrapper na engine.php (wzorzec pos_api.js)
    ├── online_app.js       # State machine, init, glue
    └── online_ui.js        # Akordeon, Surface Card, cart drawer
```

**ZAKAZ:** narzędzi edycji wizualnej w module online (drag&drop, uploadery, kalibratory). Storefront tylko **czyta** kalibrację i tło. Edycja → Studio.

### 9.1. Modifier Visual Slots (m024+m025 · Faza 2.9 + Cleanup · 2026-04-18)

Każdy modyfikator może mieć DWA wizualne sloty — to fizyczne uzupełnienie wizji „The Surface":

| Rola (sh_asset_links.role) | Co to | Jak wyświetlane w The Table |
|----------------------------|-------|------------------------------|
| `layer_top_down` | Rozsypana wersja składnika (np. posiekany bekon na pizzy) | Dołożona warstwa nad base layers po 1× klikniu modyfikatora (scatter) |
| `modifier_hero` | Solo-shot produktu (np. cały plasterek bekonu na desce) | Hero bubble w `.sc-hero__bubbles` po 2× klikniu (pop-in z halo) |

**Autorowanie:** `modules/studio/js/studio_modifiers.js` — sekcja „Surface — wizualne sloty". **Po M1 · 2026-04-19:** zamiast surowych `<select>` — **asset picker modal** (grid thumbnailów z search + filtr po `roleHint` + toggle „Tylko pasujące role"), **mini live preview** per opcja (okrągły box z base layer + scatter + hero bubble w rogu), **badge statusu** w 4 stanach (`text only` / `surface ready` / `incomplete` / `flag · brak assetów`). Zapis przez `api_menu_studio.php#save_modifier_group` (linkuje w `sh_asset_links` + ustawia `sh_modifiers.has_visual_impact`) — backend bez zmian.

**Runtime (storefront):**
1. `api/online/engine.php#get_dish` i `get_scene_dish` zwracają pole `modifierVisuals: { "OPT_BACON": { hasVisualImpact, layerAsset, heroAsset } }` — wypełniane przez `SceneResolver::resolveModifierVisuals`.
2. `online_ui.js#resolveModifierVisual(sku, data)` — jedno źródło prawdy: `modifierVisuals` (m021 + m024). Legacy `magicDict` (m018 `sh_modifier_visual_map`) **usunięte w m025** wraz z całą tabelą, akcjami `magic_*` w `api/online_studio/engine.php` i zakładką „Modifier Mapper" w Online Studio.
3. `repaintSurface` dodaje `scatter` do warstw pizzy gdy `count ≥ 1`, rysuje `hero` bubble gdy `count === 2`.
4. `cycleModifier` emituje `CustomEvent('sh:mod-toggled')` + zapisuje `ctx._lastModChange` — renderer wie, która bąbelka właśnie się „urodziła" i gra animację `.sc-hero__bubble--new` (keyframes `scBubbleMaterialize` + halo `scBubbleHalo`, łącznie 0.9s).

**Konwencja:** jedyne źródło prawdy to `sh_asset_links` + `sh_modifiers.has_visual_impact`. Po cleanupie m025 (2026-04-18) nie ma już legacy fallbacku — modyfikator bez slotów nie ma wizualnej reprezentacji na scenie (klient go widzi w liście opcji, ale nie w renderze).

### 9.2. Category Scene Editor (Faza 2 · 2026-04-18)

`modules/studio/js/studio_category_table.js` — drag&drop edytor pozycjonowania dań w scenie kategorii (`sh_scene_category_items.placement_x/y/scale/z_index`). Otwierany przyciskiem „Układ stołu kategorii" z listy kategorii w Menu Studio. Konsumowany przez `SceneResolver::resolveCategoryScene` i wyświetlany w The Table gdy `sh_categories.layout_mode IN ('grouped','hybrid')`.

---

## 10. MODUŁ STUDIO — VISUAL COMPOSITOR (zakładka w studio, NIE osobny moduł)

Zgodnie z ostateczną decyzją: **wszystkie narzędzia wizualne** (upload layer+hero, kalibracja scale/rotate live, zapis do `sh_visual_layers`, podgląd klienta) żyją w istniejącym **`modules/studio/`**.

Braki do uzupełnienia (z `ustalenia.md` §10):
1. Persystencja warstw do `sh_visual_layers` (obecnie tylko pamięć).
2. Suwaki scale/rotate z live preview.
3. Upload dual-photo (layer + hero).
4. 4. zakładka „Podgląd Online" — symulacja widoku klienta na Surface.
5. Inline wytyczne fotograficzne przy uploaderze.

---

## 11. FOTO UPLOAD — LIMITY (z `05_INSTRUKCJA_FOTO_UPLOAD.md`)

| Typ | Max rozmiar | Min wymiary | Max wymiary | Format |
|-----|-------------|-------------|-------------|--------|
| `layer` (scatter) | **3 MB** | 1000×1000 | 3000×3000 | `.webp` / `.png` |
| `hero` (produkt) | **1.5 MB** | 400×400 | 1200×1200 | `.webp` / `.png` |
| `thumbnail` | **800 KB** | 300×300 | 800×800 | `.webp` / `.png` |
| `surface` (tło) | **5 MB** | 1920×1080 | 3840×2400 | `.webp` / `.jpg` |
| `companion` | **1.5 MB** | 400×400 | 1200×1200 | `.webp` / `.png` |

**Walidacja dwupoziomowa:** JS pre-upload (File.size, naturalWidth/Height) + PHP server (`getimagesize()`, MIME, rozszerzenie, alpha channel).

**Konwencja nazw:** `{category}_{sub_type}_{sha256_6hex}.{ext}` — np. `meat_salami_cc276c.webp`.

**`.htaccess` w uploads/** — blokuje PHP execution, zezwala tylko na `.webp`/`.png` (surface + `.jpg`).

---

## 12. DANE TESTOWE (seed_demo_all.php)

- 1 tenant: „SliceHub Pizzeria Poznań"
- 8 userów (admin, manager/0000, waiter1/1111, waiter2/2222, cook1/3333, driver1/4444, driver2/5555, team1/6666)
- 8 kategorii, 33 pozycje menu
- 112 cen (99 ITEM + 13 MODIFIER × 3 kanały; Delivery +8%)
- 4 grupy modyfikatorów, 13 modyfikatorów
- 43 surowce + 43 stany WH + 44 linii receptur
- 4 dokumenty WH (3 PZ + 1 RW)
- 12 zamówień (3 dine-in + 2 takeaway + 5 delivery ready + 2 delivery completed)

**Istniejące assety w `uploads/global_assets/`** (16 plików `.webp` gotowych do kompozycji pizzy — base, sauce, cheese, meats, vegs, herbs, board).

---

## 13. CZERWONE LINIE (ABSOLUTNE ZAKAZY)

1. ❌ **ZAKAZ** modyfikacji struktury bazy (CREATE/ALTER/DROP) bez jawnej zgody usera.
2. ❌ **ZAKAZ** zmiany działających plików poza zakresem taska (Zasada Snajpera).
3. ❌ **ZAKAZ** usuwania/przenoszenia plików spoza zakresu bez zgody.
4. ❌ **ZAKAZ** kopiowania legacy kodu 1:1 — tylko ekstrakcja logiki.
5. ❌ **ZAKAZ** HTML leakage przed JSON response (echo/print/var_dump w plikach API).
6. ❌ **ZAKAZ** frameworków JS (React/Vue/jQuery) i Node.js w runtime.
7. ❌ **ZAKAZ** zapytań SQL bez `tenant_id = :tid`.
8. ❌ **ZAKAZ** płaskich cen (kolumna `price` w `sh_menu_items`) — zawsze `sh_price_tiers`.
9. ❌ **ZAKAZ** hard DELETE — zawsze `is_deleted = 1`.
10. ❌ **ZAKAZ** client-authoritative total_price — zawsze `CartEngine::calculate`.
11. ❌ **ZAKAZ** narzędzi edycji w module online/storefront — tylko Studio pisze.
12. ❌ **ZAKAZ** silent tenant default (`$tenant_id ?? 1`) — musi być jawny.
13. ❌ **ZAKAZ** interpolacji zmiennych do SQL — tylko prepared statements.
14. ❌ **ZAKAZ** JOIN-a / UPDATE-a po numerycznym `id` między silosami prefiksowymi (`sh_` ↔ `sys_` ↔ `wh_`). Cross-silo most WYŁĄCZNIE przez `sku` / `ascii_key` + `tenant_id` po obu stronach. Wewnątrz jednego silosu numeryczne FK są OK. Patrz `.cursorrules §9`.
15. 🧊 **ZAKAZ** refaktoryzacji / edycji plików Offline-First POS zamrożonych 2026-04-23. Pełna lista w `_docs/17_OFFLINE_POS_BACKLOG.md` §2. Rozmraża wyłącznie właściciel produktu.
16. 🧊 **ZAKAZ** bezpośredniego `INSERT INTO sh_pos_server_events` z modułów spoza POS (storefront, KDS, admin, gateway). Producenci publikują event do `sh_event_outbox` (m026); translator `worker_pos_fanout.php` (P4.5 — zamrożone) mapuje na stream POS. Monolit ≠ architektura.

---

## 14. ZIELONE LINIE (ABSOLUTNE WYMAGANIA)

1. ✅ **Przed pisaniem SQL** — sprawdź schemat w `_docs/04_BAZA_DANYCH.md` lub migracji.
2. ✅ **Każdy engine.php** — action-based, JSON response, schema detection dla opcjonalnych tabel.
3. ✅ **Każda pisemna zmiana cen/koszyka** — przez `CartEngine::calculate` (lub delegat).
4. ✅ **Każde zużycie surowca** — przez `WzEngine` z waste + multiplier.
5. ✅ **Każde zamówienie** — server-side numeracja przez `SequenceEngine`.
6. ✅ **Każda kwota w orderach** — INT grosze w bazie, DECIMAL tylko w `response.*`.
7. ✅ **Każdy upload** — walidacja dwupoziomowa (JS + PHP) + `.htaccess` w docelowym katalogu.
8. ✅ **Każda akcja** — `{success, data, message}` JSON envelope.
9. ✅ **Każda nowa zmiana** — zapisz w `_docs/ustalenia.md` (jeśli architektoniczna) lub w tym pliku (jeśli fundamentalna).

---

## 15. STATUS MODUŁU ONLINE (2026-04-18)

> **DECYZJA KIERUNKU:** Droga B — Realistyczny Counter + Drzwi. Zatwierdzona 2026-04-18.
> **Pełny dokument decyzyjny:** `_docs/15_KIERUNEK_ONLINE.md` (autorytatywny dla Fazy 2).
> Starsza szeroka wizja Online została odłożona do `ARCHIWUM/06_WIZJA_MODULU_ONLINE.md`; dla aktywnego kierunku wygrywa `15_KIERUNEK_ONLINE.md`.

**SPRZĄTANIE — ZROBIONE ✅**
- Usunięte: `modules/online/ordering.html`, `css/ordering.css`, `js/ordering_engine.js` (stary v4 — broken „Surface" attempt).
- Usunięte: `api/online/product.php` (zastąpiony przez `engine.php`).
- Usunięte: `_archive/online_v2/*`, `_archive/online_v3/*` (martwe archiwa).

**BACKEND — GOTOWE ✅**
- `api/online/engine.php` — unified public engine storefrontu. Oprócz `get_storefront_settings`, `get_menu`, `get_dish`, `cart_calculate` ma też działające `delivery_zones`, `init_checkout`, `guest_checkout`, `track_order`. To jest faktyczne publiczne API klienta online.
- `core/SceneResolver.php` — resolver scen (dish + category + kit assets + modifier visuals). Ready.

**DOPRECYZOWANIE STANU FAKTYCZNEGO (po przeglądzie kodu 2026-04-19)**
- `modules/online/js/online_api.js` rozwiązuje tenant publiczny z `meta[name="sh-tenant-id"]` albo `?tenant=` / `?tenantId=` — nie ma jeszcze wspólnego resolvera w `core/`.
- `track_order` działa po parze `tracking_token + customer_phone`; obecnie brak fallbacku po `order_number`.
- `delivery_zones` jest autorytatywne tylko gdy storefront poda `lat/lng`; sam `address` uruchamia miękki fallback (`in_zone = null`, manualna weryfikacja).
- `api/orders/checkout.php` ma już część rozszerzeń online (`lock_token`, `tracking_token`, stock preflight), ale nadal jest za `auth_guard.php`, więc nie zastępuje publicznego `guest_checkout`.

**PREREKWIZYTY FAZY 2**
1. ✅ **Menu Studio Polish** (DONE · 2026-04-19) — connect-dots modyfikator↔warstwa z asset pickerem + live preview + badge, **hero picker dania** (akcje `set_item_hero` / `unlink_item_hero` → `sh_asset_links` entity_type='menu_item' role='hero'), auto-generator domyślnej kompozycji (akcja `autogenerate_scene` → upsert do `sh_atelier_scenes.spec_json.pizza.layers[]`; gdy brak materiału zwraca `data.reason='no_source_data'` + `steps[]` żeby manager wiedział co uzupełnić), miniatura dania w drzewie Studio (reuse `get_menu_tree` + `AssetResolver::injectHeros`), pełen overhaul UX recept (fuzzy search z autocomplete, stock badge per wiersz z `wh_stock`, bulk add modal, live Food Cost total, przycisk "Zapisz Recepturę"). **Zero zmian schematu DB.** Patrz `_docs/15_KIERUNEK_ONLINE.md` § 2.1.
2. ✅ **Unifikacja rendererów** (DONE · 2026-04-19) — SSOT matematyki warstwy dania w `core/js/scene_renderer.js` (ES6 module eksportujący `resolveAssetUrl`, `sortLayers`, `buildLayerElement`, `renderLayersInto`, `CLASS_PACKS` dla `storefront` / `director` / `studio`). Storefront (`modules/online/js/online_renderer.js`) i Director (`modules/online_studio/js/director/renderer/SceneRenderer.js`) wołają ten sam helper z innymi klasami CSS (`pizza-layer` vs `sr-layer`) i trybami transformu (`cssVars` vs `inline`). CSS `.layer-visual` rozszerzony o `--cal-offset-x/y` (backward-compat z `0%` default) — Storefront teraz honoruje `offsetX/Y` ustawione w Directorze. Pełen zakres atrybutów warstwy (blendMode, alpha, brightness/saturation/hueRotate, shadow, feather) liczony identycznie w obu kontekstach. **Zero zmian schematu DB, zero backendu.** Pełna scena (stage/LUT/companions/infoBlock/ambient/promo) zostaje w Directorze jako następna warstwa ponad SSOT (docelowo M2.2 przeniesie to też do `core/js/` gdy Storefront zechce pełen WYSIWYG).
3. ✅ **Studio Evolution G1–G6 DONE** (2026-04-19, jedna sesja · kolejność B→A→C) — **autorytatywny dokument:** `_docs/15_KIERUNEK_ONLINE.md` § 2.4. Wszystko osadzone na istniejącym fundamencie m020–m024 + Hollywood Director's Suite (nie przebudowywane).
   - **Foundation (JUŻ JEST, nie dotykamy):** `sh_atelier_scenes` (m020), `sh_assets` + `sh_asset_links` (m021), `sh_scene_templates` + `sh_style_presets` (12 stylów kinowych seed'owanych) + `sh_category_styles` + `sh_scene_triggers` + `sh_scene_variants` + `sh_ai_jobs` + `sh_promotions` (m022). Kod: `modules/online_studio/js/director/*` (DirectorApp + 7 workspace'ów + MagicEnhance + MagicBake/Relight/ColorGrade/Dust/Companions + LutLibrary 8 LUT-ów + 7 ScenePresets + DishProfiler + ScenographyPanel + HistoryStack). Core: `SceneResolver.php`.
   - ✅ **G1 — Category Style Engine runner** — backend `category_style_apply(categoryId, stylePresetId)` w `api/online_studio/engine.php` (helpers `applyStyleToSpec` + `applyStyleBulk`, aktualizuje `sh_atelier_scenes.spec_json` + `sh_category_styles` + `sh_atelier_scene_history`). Frontend: zintegrowany w zakładkę **Style Conductor** (lepsza UX dyrygenta niż pojedynczy przycisk w ScenographyPanel).
   - ✅ **G2 — Bulk ops na całe menu + Style Conductor** — backend `menu_style_apply(stylePresetId, categoryIds?)`. Frontend: **nowa zakładka `conductor`** (`modules/online_studio/js/tabs/conductor.js` + `css/conductor.css` + wpięta do `studio_app.js` i `index.html` z klawiszem `3`). Dashboard: galeria 12 stylów kinowych + tabela kategorii (items, sceny, Harmony avg, aktywny styl, `[Zastosuj]`) + „Zastosuj do całego teatru". API client: `stylePresetsList`, `categoryStylesList`, `categoryStyleApply`, `menuStyleApply` w `studio_api.js`.
   - ⏸️ **G3 — AI Jobs Runner** (ODŁOŻONE na Fazę AI) — konsument `sh_ai_jobs` z integracją Replicate/Flux.
   - ✅ **G4 — Scene Harmony Score** — `modules/online_studio/js/director/harmony/HarmonyScore.js` (client-side metryka 0–100: wariancja `cal_scale`/`cal_rotate`/`brightness`/`saturation`/`feather` + per-layer delta vs. `TYPE_PRESETS` z MagicBake). Tier'y: `kino` (≥90) / `ok` (≥70) / `warn` (≥50) / `block` (<50). Cache w `sh_scene_metrics` (migracja **m030**: `scene_id` PK, `tenant_id`, `harmony_score`, `layer_count`, `outliers_json`, `variance_json`). UI: badge w status-barze Directora + modal outlierów z akcjami „Magic Conform" / „Magic Harmonize". Backend: akcje `scene_harmony_save` + `scene_harmony_get` (scope: `dish`/`all`).
   - ✅ **G5 — Magic Conform + Magic Harmonize** — `modules/online_studio/js/director/magic/MagicConform.js` (soft-blend wszystkich warstw do `TYPE_PRESETS` + `LUT_OFFSETS` sceny, zachowując intencjonalne transformacje managera) + `MagicHarmonize.js` (retouch **tylko outlierów** z G4 — blend do mediany sąsiadów tego samego typu). Wpięte w `ToolbarPanel` (`MAGIC` array) + `DirectorApp._runMagic` cases `conform` / `harmonize`.
   - ✅ **G6 — Living Scene na Storefroncie** — `modules/online/css/living-scene.css` (keyframes: `steam_rising`, `dust_particles_golden`, `condensation_drops`, `sauce_drip`, `candle_glow`, `sun_rays`, `breath` — wszystko respektuje `prefers-reduced-motion`). Helper `applyAtmosphericEffects(host, effects)` w `online_renderer.js` + `mountPizzaScene(payload.effects)`. `online_ui.js` propaguje `atmosphericEffects` do Surface Card (`repaintSurface`) i tile'ów (`mountTilePizzaScene`). Backend: `api/online/engine.php` zwraca `atmosphericEffects` w `get_menu` (batch load z `sh_atelier_scenes.atmospheric_effects_enabled_json`), `get_scene_menu`, `get_scene_category`. W `get_scene_dish` już wcześniej — via `sceneContract.scene_meta.atmospheric_effects` mapowane w `online_table.js`.
   - ⏸️ **G7 — Magic Workshop** (ODŁOŻONE razem z G3) — per-foto non-destrukcyjny editor z zapisem jako wariant w `sh_assets.variant_of`.
   - ✅ **Migracja m030** — wdrożona w minimalnym zakresie (`sh_scene_metrics`). `sh_assets.variant_of` + `corrections_json` czekają na Fazę AI razem z G3/G7.
4. ✅ **7-stopniowy pipeline realizmu — DOMKNIĘTY (2026-04-19)** — directional shadows, LUT inheritance, wet/grease pass, auto-perspective, scatter presets, feather refinement, baked variants. **Część punktów weszła w G5/G6** (LUT inheritance = category style runner; directional shadows + wet pass = Living Scene). Dokończenie w jednej sesji:
   - ✅ **M3 #4 · Auto-perspective match** — `CAMERA_PRESETS` + `applyCameraPerspective(viewport, disk, preset)` w `core/js/scene_renderer.js` (6 presetów: `top_down`, `hero_three_quarter`, `macro_close`, `wide_establishing`, `dutch_angle`, `rack_focus`). Storefront (`online_renderer.js`, `online_ui.js`), Director (`SceneRenderer.js`, `ViewportPanel.js`) i backend (`api/online/engine.php` → `get_menu`/`get_scene_menu`/`get_scene_category` + `api/online_studio/engine.php` → `director_load_scene`/`director_save_scene`) wszystkie honorują `active_camera_preset`. UI: dropdown kamery w `ToolbarPanel`, zapisuje się razem ze sceną (`DirectorApp._save`).
   - ✅ **M3 #7 · Baked variants** — migracja **m031** (`sh_assets.cook_state` ENUM: `either`|`raw`|`cooked`|`charred` + index `idx_assets_cook_state`). `SceneResolver::resolveModifierVisuals` — bias w `ORDER BY` (`cooked` dla `layer_top_down`, `raw`/`either` dla `modifier_hero`, UNION ALL z osobnym porządkiem). Asset Studio (`modules/online_studio/js/tabs/assets.js` + `css/studio.css`) — dropdown „Stan pieczenia" w wizardzie uploadu i edytorze + badge `chip--cook-raw/cooked/charred` na gridzie. Backend `api/assets/engine.php` — stała `AE_COOK_STATES`, obsługa w `list`/`upload`/`update`.
5. ✅ **M5 · Asset Library Organizer + Cleanup CSS/JS — DONE (2026-04-19)** — porządek w 200+ assetach. **Posprzątane dead code:** w `studio.css` usunięty kompletny blok `.lib-*` + `.asset-peek*` (~105 linii, reliktu sprzed m021 — zero użyć w JS/HTML); usunięte martwe reguły `.as-card.active` / `.as-card__bucket` / `.as-card__links` / `.as-card__links--empty` / `.as-card__key` zastąpione przez nowy system `.is-active` + `.as-card__badges` + `.as-card__title`; stare `.as-card__meta .chip--cook-*` zcalone z nową sekcją m032 pod jednolitym selektorem `.as-card .chip--cook-*`. W JS usunięte nieużywane pole `aliasId: 'library__icon'` z BUCKETS. Migracja **m032** (`sh_assets.display_name VARCHAR(191)`, `tags_json JSON`, indexy: `idx_assets_display_name`, `idx_assets_cat_active`, `idx_assets_checksum_tenant`). Backend `api/assets/engine.php` — nowe akcje: `bulk_update` (category/sub_type/cook_state/bucket/role_hint + `tags_append`/`tags_replace`), `bulk_soft_delete`, `duplicate` (klonuje asset z nowym ascii_key, wariant `duplicate`), `merge_duplicates` (wybór keepera, przeniesienie linków z UNIQUE-safe fallback, soft-delete reszty z `variant_kind='merged_into'`), `scan_health` (statystyki: total/orphans/duplicates/missingCategory/missingCookState/missingDisplayName/largeFiles + grupy dubli po SHA-256), `rename_smart` (display_name + opcjonalny regen ascii_key z kategoria+subtype+slug PL-znaki). Helpery: `ae_safe_display_name`, `ae_sanitize_tags_json` (max 12 tagów × 32 znaki, unikalne), `ae_build_pretty_ascii_key` (format `{cat}_{subtype|slug}_{N}`), `ae_normalize_id_list`. `list` rozszerzone o health-filtry (`orphans_only`, `duplicates_only`, `missing_category`, `missing_cook_state`, `large_files`) i zwraca `displayName`/`tags`/`duplicateCount`. `upload` przyjmuje `display_name` + `tags` i auto-zgaduje display z filename.
   - UI `modules/online_studio/js/tabs/assets.js` — **kompletnie przepisany**: widok grupowany per kategoria (13 sekcji w kolejności pipeline realizmu: board→base→sauce→cheese→meat→veg→herb→extra→drink→surface→hero→brand→misc) z akcentem kolorystycznym per kategoria, toggle `grouped`/`flat`, sidebar z 3 grupami filtrów (Kategoria z countami, Bucket, Stan pieczenia), Health Panel "Do posprzątania" z chipami ilościowymi (sieroty/duble/bez nazwy/bez cook/bez kategorii/duże pliki) + przycisk "Scal duble", selection mode (checkbox na hover, Shift-click zakres, Ctrl+A, Esc, Del, "zaznacz wszystkie z kategorii"), sticky bulk action bar (Kategoria/Cook state/Bucket/Tagi/Duplikuj/Usuń), inline quick-edit (klik chip kategorii lub cook → popover z dropdownem, pozycjonowany przy anchorze), karta z `displayName` jako tytuł + `asciiKey` jako sub, badge `duplicateCount` i `linkCount`/`ghost`, wizard upload rozbudowany (ludzka nazwa z auto-guess, tagi, batch multi-plik z progress-barem), edytor (display_name + tags + regenerate_ascii_key checkbox), modal **Scal duble** z miniaturami (radio keeper, preselekcja na asset z najwięcej linkami, multiselect grup, batch merge). API client `modules/online_studio/js/studio_api.js` — nowe metody `assetsBulkUpdate`, `assetsBulkSoftDelete`, `assetsDuplicate`, `assetsMergeDuplicates`, `assetsScanHealth`, `assetsRenameSmart`. Styl: `modules/online_studio/css/studio.css` — bloki `.as-health`, `.as-section`, `.as-bulk-bar`, `.as-popover`, `.as-merge-*`, `.wiz__batch-note` + `.wiz__progress`, karty z accentem kategorii (`--card-accent`), checkbox-on-hover, bulk bar z animacją pojawiania, respektuje `prefers-reduced-motion` (brak animacji w strukturze).

6. ✅ **M4 · Scena Drzwi — DONE (2026-04-19)** — pierwsze wrażenie sklepu. Ilustracja SVG drzwi restauracji z wariantami `data-time-of-day` (morning/day/evening/night) parametryzowana CSS var `--doorway-accent` (kolor marki). Status open/closing_soon/closed z animowaną kropką, info chipy (godziny, adres, telefon), switch kanałów (Dowóz/Odbiór/Na miejscu), CTA „Wejdź do menu" + pre-order CTA dla zamkniętej restauracji, tryb statyczny (a11y toggle), deep-link `?skip=doors`. Modal mapy — Leaflet ładowany on-demand (bez kosztu startowego), dodatkowy view z tygodniowym harmonogramem godzin.
   - Pliki: `modules/online/js/online_doorway.js`, `modules/online/css/doorway.css`, integracja w `modules/online/js/online_app.js` (Drzwi → `enterAfterDoorway` → menu) oraz `modules/online/index.html` (nowa sekcja `#doorway` + modal `#doorway-map-modal`).
   - Backend: `api/online/engine.php` action=`get_doorway` — zwraca brand, contact (address/city/phone/email/lat/lng), hours (today + week + JSON), status (code/label/next_open_at), channels, timeOfDay, preOrderEnabled. Klucze konfiguracyjne w `sh_tenant_settings` (KV): `storefront_address`, `storefront_city`, `storefront_phone`, `storefront_email`, `storefront_lat`, `storefront_lng`, `storefront_tagline`, `storefront_brand_color`, `storefront_channels_json`, `storefront_preorder_enabled`. Godziny otwarcia czytane z kolumny `opening_hours_json` (wspólna z PromisedTimeEngine).
   - API client: `OnlineAPI.getDoorway(channel)` w `modules/online/js/online_api.js`.

**MVP FAZY 2 — DO BUDOWY**
- ✅ Scena 1: **Drzwi** (M4 DONE — hero entry, mapa w modalu, tryb statyczny fallback, zamknięta restauracja z pre-order).
- Scena 2: **Counter + Living Table** (horyzontalny swipe między daniami, Bottom Sheet „Komponuj / Do stołu", companions persist przy swipe, live price).
- Checkout: guest + phone-keyed, drawer koszyka, tracker P1.
- Stack: czysty HTML + CSS + vanilla JS (ES6+ modules), bez frameworków. Mobile-first.

**ODŁOŻONE NA FAZĘ 3+**
- Restaurant Viewfinder (C) — 4-kierunkowy swipe: Menu/Kuchnia/Sala/Koszyk wokół Counter.
- Scena Sali, gamifikacja klienta, Time-of-Day automatyka, pełny Preorder flow.
- AI illustration mode, Magic Wand styles per kategoria.

**ODŁOŻONE NA FAZĘ 5+**
- Pełen 5-scenowy Film (droga A) z kompletnym Director's Suite i Film Template Library.

**POWIĄZANE (osobna sprawa, Faza 3)**
- `modules/admin_hub/` — unified super-admin dashboard łączący POS/KDS/Courses/Warehouse/Settings/Tables/Studio/Online Studio. Nie blokuje Fazy 2.

---

## 15.9. BACKLOG — Funkcjonalności znane, nie zaimplementowane

Rejestr rzeczy które istniały w legacy albo były projektowane, ale **nie są w obecnym `slicehub_pro_v2`**. Przed implementacją wymagają decyzji architektonicznej (gdzie, jaki kontrakt, jak integruje z resztą).

### Moduł HR / Finanse kadry (brak w `slicehub_pro_v2`)
- **Funkcjonalności:** wnioski finansowe (premie / zaliczki / kary), rejestr kadry (`get_team` bez ownera), powiązanie z `sh_users`.
- **Tabela:** `sh_finance_requests` (istniała w `baza_slicehub`, nie została zmigrowana).
- **Wzorzec historyczny:** `api_manager.php` (akcje: `get_team`, `submit_finance`) — zachowany w archiwum offline (`_archive/api_manager.php` — poza repo od 2026-04-22).
- **Status:** oczekuje na decyzję architektoniczną. Przy reimplementacji: dedykowany moduł `api/hr/engine.php` + tabela `sh_finance_requests` zgodna z kanonem (tenant_id, idempotent migrations, audit log). Powiązanie z `core/Notifications/` (zatwierdzenie wniosku → notyfikacja).

---

## 16. WIZJA DALEKOSIĘŻNA (ŚWIADOMOŚĆ KURSU)

Po stabilizacji online + Studio compositor:
- **Marketing** (sezonowe surface, promocje, featured items)
- **Loyalty** (badge, nagrody, streaki — phone-number keyed jak w `OPTIMIZED_CORE_LOGIC_V2.md` §5)
- **Statystyki** (heatmapa kliknięć, A/B testy, konwersja per składnik, food cost analytics)
- **E-Menu (QR)** — dine-in podgląd menu
- **Ustawienia globalne** (SLA thresholds, business hours, channel pricing strategy)
- **Integracje** (Papu, Pyszne, Uber Eats, KSeF e-invoicing)
- **Rozszerzenie poza gastro** — architektura multi-tenant + action-based API pozwala na adaptację do innych branż retail/service.

---

> **Koniec pliku.** Jeśli cokolwiek jest tu nieaktualne — zgłoś to użytkownikowi, zaktualizuj, nie działaj ślepo.
