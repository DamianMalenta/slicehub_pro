# ARCHITEKTURA SYSTEMU — MAPA KATALOGÓW

> Oficjalna mapa drogowa projektu **SliceHub Enterprise** dla Agentów AI.
> Nie zgaduj — sprawdzaj strukturę tutaj.
>
> **Ostatnia synchronizacja:** 2026-07-27 (HR hardening sesja 2 — HR-6 domknięte, migracja 061, MealEngine, idempotentność migracji):
> - F5 POS Integrity Pass (cart revalid + reverse stock + modifiers SKU)
> - F6 Geocoder Nominatim (delivery_lat/lng + dispatcher map)
> - F-S1 Variant Scales (pizza Mała/Średnia/Duża + multiplier)
> - F-S2 Topping Size Pricing (Toast-style + 4 half-half strategies)
> - F-S2.1 Studio UI: macierz cen modyfikatorów per rozmiar
> - F-S3 Meal Packages (Combo/Bundle) — backend
> - F-S3.1 POS UI: kafelki combo + wizard wyboru składników
> - F-S4 Studio drift cleanup (Live/published, valid_from DATETIME, bulk VAT, recipe clone, parent_sku validation)
> - F-S5 Multi-stage recipes (półprodukty, iiko-style „заготовки", WzEngine rekurencyjna ekspansja z max depth 3)
> - F-S6 Wizard „Nowa Pizza" (4-step modal: nazwa → skala → ceny → generuj)
> - F-S3.2 WzEngine combo expansion (combo line → wirtualne linie → konsumpcja per składnik; pełna kompozycja z variant + subrecipe)
> - F-S5.1 Studio UI dla półproduktów (toggle recycle + picker + yield input; backend walidacja anti-cycle + LEFT JOIN sys_items/sh_menu_items)
> - F-S8 (verify) — Combo reverse stock działa BEZ dodatkowego kodu (WarehouseReverseHook czyta wh_document_lines per surowiec, F-S3.2 expansion produkuje per-surowiec WZ)
> - F-S1.2 — Variant scale presets (5 gotowych skal w Studio UI) + auto-multiplier w skrypcie migracji legacy parent_sku (z mapy XS=0.55, S=0.70, M=1.00, L=1.30, XL=1.60, XXL=2.00, MINI/BIG/REG/STD/NORMAL, średnice 26..45 cm)
> - F-S6.1 — Wizard "Nowa Pizza" rozbudowany do 5 kroków (krok 4: modifier groups checkbox lista, krok 5: generuj)
> - F-S7 — Hard PRICE_MISMATCH (tenant flag `price_mismatch_mode`: soft|hard; hard → HTTP 409 z error_code)
> - F-S9 — Recipe drag-and-drop reorder (migracja 055 + native HTML5 drag w studio_recipe.js + schema-aware ORDER BY display_order)
> **North Star:** [`_docs/00_PAMIEC_SYSTEMU.md`](00_PAMIEC_SYSTEMU.md) — master reference.
> **Hub & Kiosk dedykowany doc:** [`_docs/19_HUB_AND_KIOSK.md`](19_HUB_AND_KIOSK.md).

---

## 1. FRONTEND — Moduły UI (`/modules/`)

### A. STUDIO — Silnik Zarządzania Menu
`/modules/studio/`

| Plik | Rola |
|------|------|
| `index.html` | Szkielet i interfejs Studio |
| `js/studio_api.js` | **Etap 2 · 2026-07-07** — kanoniczny klient API (`apiStudio` / `StudioApi.post`) |
| `js/studio_core.js` | Stan globalny, `loadMenuTree`, `StudioState` |
| `js/studio_ui.js` | Drzewo menu, kategorie, zaznaczanie masowe |
| `js/studio_item.js` | Edytor dania + Macierz Cenowa (Omnichannel) |
| `js/studio_modifiers.js` | Bliźniak Cyfrowy, zużycie surowców, akcje ADD/REMOVE |
| `js/studio_recipe.js` | Edytor receptur (surowce → dania) |
| `js/studio_bulk.js` | Edycja Masowa (ceny, publikacja temporalna) |
| `js/studio_margin.js` | Kalkulator marży |

> ⚠ **DŁUG TECHNICZNY:** Studio NIE posiada dedykowanego `studio_api.js`. Każdy plik wywołuje `window.ApiClient.post('api/backoffice/api_menu_studio.php', …)` bezpośrednio. Planowany refactor do spójnego wrappera.

### B. POS — Strefa Operacyjna (Dark Battlefield)
`/modules/pos/`

| Plik | Rola |
|------|------|
| `index.html` | Szkielet POS (kafelki + koszyk + checkout) |
| `js/pos_app.js` | Kontroler główny: auth, menu, koszyk, checkout |
| `js/pos_api.js` | API wrapper → `api/pos/engine.php`, `api/auth/login.php`, `api/courses/engine.php`, `api/tables/engine.php` |
| `js/pos_cart.js` | Logika koszyka (UI — prawda zawsze z serwera) |
| `js/pos_ui.js` | Rendering UI |
| `css/style.css` | Dark Battlefield theme |

### C. TABLES — Moduł Kelnerski (Stoliki)
`/modules/tables/`

| Plik | Rola |
|------|------|
| `index.html` | Plan sali + listy zamówień otwartych |
| `js/tables_app.js` | Kontroler stolików, otwieranie/zamykanie rachunków, transfery |
| `js/tables_api.js` | API wrapper → `api/tables/engine.php`, `api/auth/login.php` (strict JWT) |
| `css/style.css` | Dark Glass theme |

### D. WAITER — Mobile Waiter App
`/modules/waiter/`

| Plik | Rola |
|------|------|
| `index.html` | PIN login + mobilny interfejs kelnera |
| `js/waiter_app.js` | Monolit: PIN login + wywołania `api/tables/engine.php` + UI |

> ⚠ **DŁUG TECHNICZNY:** Waiter używa bezpośredniego `fetch`, nie ma `waiter_api.js`. Do refaktoru.

### E. DISPATCHER — Centrum Logistyki (Kursy)
`/modules/courses/`

| Plik | Rola |
|------|------|
| `index.html` | 3 zakładki: Zamówienia / Mapa / Aktywne Kursy |
| `js/courses_app.js` | Auth PIN, polling 8s, dispatch workflow, modals (cash/reconcile) |
| `js/courses_api.js` | API wrapper → `api/courses/engine.php`, `api/auth/login.php` |
| `js/courses_map.js` | Leaflet.js: markery zamówień + kierowców (Carto Dark) |
| `js/courses_ui.js` | Karty zamówień, kierowców, kursów, SLA badges, wallet, toast |
| `css/style.css` | Dark Glass theme |

Workflow: `ready` + `available` → Select driver + orders → Dispatch → Kurs `Kn` z przystankami `L1..Ln`

Features: multi-order dispatch, Leaflet map, active courses z per-course rozliczeniem (cash/card/prepaid), pogotowie kasowe, reconciliation modal, Emergency Recall, SLA badges.

### F. DRIVER APP — Aplikacja Mobilna PWA
`/modules/driver_app/`

| Plik | Rola |
|------|------|
| `index.html` | PIN login + bottom tab bar (Kursy / Portfel) |
| `js/driver_app.js` | Auth, polling 10s, GPS 15s, payment lock, emergency recall |
| `js/driver_api.js` | API wrapper → `api/courses/engine.php`, `api/auth/login.php` |
| `css/style.css` | High-contrast dark, touch 56px+, safe-area-inset, PWA |
| `manifest.json` | PWA manifest (standalone, portrait) |

Critical: Payment Lock, Driver Wallet, Emergency Alert (red flash + vibration), GPS do `sh_driver_locations`.

### G. KDS — Kitchen Display System
`/modules/kds/`

| Plik | Rola |
|------|------|
| `index.html` | Tablica kuchenna (tickets grid) |
| `js/kds_app.js` | Polling 6s, bump (accept → preparing → ready), recall, Bearer auth |
| `css/style.css` | Kitchen display theme |

> ℹ Działa z każdą sesją logowaną (JWT lub session cookie). Po 401 pokazuje lock-screen z instrukcją logowania.

### H. ONLINE — Publiczna Witryna (The Surface)
`/modules/online/`

| Plik | Rola |
|------|------|
| `index.html` | Publiczna karta menu |
| `track.html` | Tracking zamówienia klienta (odzysk po `tracking_token` + telefon) |
| `js/online_app.js` | Główny bootstrap |
| `js/online_api.js` | API wrapper → `api/online/engine.php` (bez JWT — publiczne; tenant z `meta[name="sh-tenant-id"]` albo `?tenant=`) |
| `js/online_renderer.js` | Rendering sceny (tła, warstwy, companions) |
| `js/online_checkout.js` | Checkout gość (`init_checkout` + `guest_checkout`) |
| `js/online_table.js` | Otwarcie stolika przez QR |
| `js/online_track.js` | Logika śledzenia (`track_order`, polling 10s) |
| `js/online_ui.js` | UI atoms |
| `css/style.css`, `css/track.css` | The Surface theme |

### I. ONLINE STUDIO — Reżyser Sceny (Director)
`/modules/online_studio/`

> ⚠ Moduł aktualnie pod aktywnym rozwojem — start od `START_TUTAJ.md`, potem `00_PAMIEC_SYSTEMU.md` i `15_KIERUNEK_ONLINE.md`. Struktura: `js/director/DirectorApp.js` + `js/tabs/*` + `js/studio_api.js` → `api/online_studio/engine.php`, `api/assets/engine.php`, `api/backoffice/api_menu_studio.php`, `api/online_studio/library_upload.php`.

### J. WAREHOUSE — Moduł Magazynowy
`/modules/warehouse/`

| Plik | Rola |
|------|------|
| `index.html` | Dashboard (stany, dokumenty, alerty) |
| `manager_pz.html` | Przyjęcie towaru (PZ) |
| `manager_rw.html` | Rozchód wewnętrzny (RW) |
| `manager_in.html` | Inwentaryzacja (INW) |
| `manager_kor.html` | Korekta (KOR) |
| `manager_mm.html` | Przesunięcie międzymagazynowe (MM) |
| `js/warehouse_core.js` | Logika współdzielona |
| `js/warehouse_api.js` | API wrapper → `api/warehouse/*.php` (wiele endpointów) |

### K. SETTINGS — Panel Konfiguracyjny
`/modules/settings/`

Panel ustawień tenanta, konfiguracja integracji, webhooków, stawek VAT, zmianowych stawek payroll. Opisany w `_docs/13_SETTINGS_PANEL.md`.

### L. HUB — Centralny Launcher (NEW · 2026-05-04)
`/modules/hub/`

| Plik | Rola |
|------|------|
| `index.html` | Login restauracji (login + hasło: owner / manager / admin) + grid kafelków po zalogowaniu |
| `js/hub_app.js` | Auth (`/api/auth/login.php` mode=`system`), routing do podmodułów |
| `css/hub.css` | Dark theme, glass cards |

**Filozofia:** jeden „home screen" lokalu. Po zalogowaniu manager / owner widzi siatkę: **Praca operacyjna** (POS, Kiosk zmiany, Plan sali, KDS, Dispatch) + **Administracja** (Kadry, Ustawienia, Studio) + **Aplikacje mobilne pracowników** (Kelner, Kierowca — osobne PWA).

> Pełny opis flow auth + relacji z pozostałymi modułami: `_docs/19_HUB_AND_KIOSK.md`.

### M. KIOSK — Terminal Obecności / Zmiana (NEW · 2026-05-04)
`/modules/kiosk/`

| Plik | Rola |
|------|------|
| `index.html` | 3-stage flow: login terminala → PIN pracownika (4 cyfry) → ekran „Rozpocznij prace / licznik / Zakończ prace" |
| `js/kiosk_attendance.js` | Cross-silo wyłącznie przez REST (`/api/auth/login.php`, `/api/backoffice/hr/engine.php`). Zero `import` z silosu POS. |
| `css/kiosk_attendance.css` | High-contrast, touch-first, safe-area-inset |

**Cross-silo (Konstytucja §9):** Kiosk komunikuje się z silosem HR **tylko** przez REST. Dwustopniowy auth: terminal (konto techniczne lokalu, JWT) → pracownik (PIN bcrypt z `sh_employees.auth_pin_hash`).

### N. BACKOFFICE / HR — UI Kadry (NEW · 2026-05-04)
`/modules/backoffice/hr/`

| Plik | Rola |
|------|------|
| `index.html` | Tabela pracowników, modale (dodaj/edytuj profil, ustaw PIN, ustaw stawkę godzinową, zaliczki, wpisy ledgera: premia/korekta/posiłek), zakładki (Pracownicy / Zaliczki / Payroll), close period |
| `js/hr_app.js` | CRUD przez `/api/backoffice/hr/engine.php` (akcje `employees_list`, `employee_upsert`, `employee_pin_set`, `employee_rate_set`, `advances_list`, `advance_request`/`approve`/`reject`/`mark_paid`/`void`, `bonus_add`, `adjustment_add`, `meal_record`, `payroll_report`, `payroll_period_status`, `payroll_close_period`). Tab switching, `newIdempotencyKey()`, filtry statusu zaliczek |
| `css/hr.css` | Backoffice glass theme |

Realizuje plan z `_docs/18_BACKOFFICE_HR_LOGIC.md` (Faza 4 — UI Kadry + hardening 2026-07-27). Wymaga roli owner/manager/admin (auth_guard + `HrRoles::isManager`).

### O. MARKETING — Wizard SMS / Promocji (NEW · 2026-05-04)
`/modules/marketing/`

| Plik | Rola |
|------|------|
| `index.html` | Wizard kampanii SMS (3 kroki) — konsumuje Notification Director (m033) |
| `deck/` | **Wachlarz A5** (2026-07-20) — panel edycji kart + druk; API `api/marketing/deck_engine.php`; tabele `sh_print_decks` / `sh_print_deck_cards` (migracja 060) |

> Status: MVP — szczegóły kontraktu z Notification Director w `_docs/13_SETTINGS_PANEL.md`.  
> Wachlarz: brief `_docs/ulotki/forno-wachlarz/BRIEF.md`.

### P. UI SHELL — Wspólny Mobile Shell (NEW · 2026-05-04)
`/modules/ui_shell/sh_mobile_shell.css`

Shared CSS dla wszystkich modułów: safe-area-inset, viewport-fit, mobilne nawigacje. Importowany przez `hub`, `kiosk`, `backoffice/hr`, `marketing`, `pos`, `kds`, `settings`, `tables`, `waiter`. Zastępuje wcześniejszy `modules/shared/` (zlikwidowany 2026-05-04).

---

## 2. BACKEND — API & Core

### Endpointy `/api/`

#### Auth & sesje
| Ścieżka | Opis |
|---------|------|
| `auth/login.php` | Auth (mode: `system` / `kiosk`), zwraca JWT. Startuje też sesję PHP (cookie SameSite=Strict) i dotyka `slicehubTouchStaffPresence`. **Kiosk PIN = dokładnie 4 cyfry** (regex `^\d{4}$`); fallback do `sh_employees.auth_pin_hash` (bcrypt) gdy `sh_users.pin_code` puste. Owner też może PIN-em (model jak Toast/Square — od 2026-05-04). |
| `auth/logout.php` (NEW · 2026-05-04) | Niszczy sesję PHP + dekoduje JWT i woła `slicehubClearStaffPresence` (kierowca/kelner natychmiast znika z floty POS). |

#### Core engines — routing przez `engine.php`
| Ścieżka | Opis |
|---------|------|
| `pos/engine.php` | Router POS (menu, koszyk, checkout, accept, settle, panic) |
| `tables/engine.php` | Router Stolików + Waiter (plany sali, rachunki, transfery) |
| `courses/engine.php` | Router logistyki (dispatch, GPS, reconcile, payment lock, recall) |
| `kds/engine.php` | Router KDS (`get_board` z opcjonalnym `station` — filtr linii wg `sh_kds_tickets` / menu; `bump_order`; `bump_ticket` — per-ticket state machine via `core/KdsTicketEngine.php`; `recall_order`) |
| `online/engine.php` | Router publicznej witryny (storefront, `delivery_zones`, `init_checkout`, `guest_checkout`, `track_order`) |
| `online_studio/engine.php` | Router Studio Online (director, composer, style presets, scene) |
| `online_studio/library_upload.php` | Multipart upload biblioteki assetów |
| `backoffice/api_menu_studio.php` | Router Studio menu (CRUD menu, ceny, modyfikatory, receptury) |
| `backoffice/api_visual_studio.php` | ⚠ ORPHAN — nieużywany legacy uploader |
| `assets/engine.php` | **Single Source of Truth** dla assetów (m021): upload, CRUD, health scan |
| `settings/engine.php` | Router ustawień tenanta |

#### Cart & Orders
| Ścieżka | Opis |
|---------|------|
| `cart/CartEngine.php` | Klasa silnika koszyka (ceny, grosze, half/half) |
| `cart/calculate.php` | Endpoint kalkulacji koszyka |
| ~~`orders/checkout.php`~~ | **USUNIĘTY 2026-07-28.** Duplikat `online/engine.php#guest_checkout` + `pos/engine.php#process_order`. `WzEngine::checkAvailability()` wchłonięte do obu. |
| ~~`orders/accept.php`~~ | **USUNIĘTY 2026-07-28.** Duplikat `pos/engine.php#accept_order`. `canTransition()` pre-check wchłonięty. |
| `orders/edit.php` | 🟡 PLANNED — edycja zamówienia + DeltaEngine (dla admin_hub). Zero call-site'ów. |
| `orders/estimate.php` | 🟡 PLANNED — estymacja promised_time (dla scheduled orders). Wrapper na `PromisedTimeEngine::calculate()`. Zero call-site'ów (silnik wpięty bezpośrednio w 4 ścieżki — wrapper dla future scheduled-picker UI). |
| ~~`orders/panic.php`~~ | **USUNIĘTY 2026-07-28.** Duplikat `pos/engine.php#panic_mode`. Logika wchłonięta do `core/PanicEngine.php` (debounce + configurable delay). |
| `orders/sla_monitor.php` | ✅ **DOMKNIĘTE 2026-07-29 (Faza C)** — SLA breach monitor (4-tier klasyfikacja, zapis do `sh_sla_breaches`). Wpięte end-to-end: `worker_sla_monitor.php` (cron CLI) + `courses/engine.php#get_sla_breaches` (backend) + `courses_api.js#getSlaBreaches` + `courses_app.js` (poll 30s) + `courses_ui.js#renderSlaBreachesPanel` (Dispatcher sidebar). |
| `orders/DeltaEngine.php` | Klasa wykrywająca różnice w liniach zamówienia (konsument: `edit.php` — orphan) |
| `core/PromisedTimeEngine.php` | ✅ **DOMKNIĘTE 2026-07-29 (Faza B)** — pełny silnik estymacji promised_time (load factor, channel buffers, business hours). Wpięty w 4 ścieżki ASAP: online/gateway/choiceqr/pos-accept. Ręczny input (POS) i scheduled (online/gateway) bez zmian. |

#### Warehouse
| Ścieżka | Opis |
|---------|------|
| `warehouse/stock_list.php` | Lista stanów + filtry |
| `warehouse/warehouse_list.php` | Słownik magazynów |
| `warehouse/receipt.php` | PZ — przyjęcie (wywołuje `core/PzEngine.php`) |
| `warehouse/internal_rw.php` | RW wewnętrzny |
| `warehouse/batch_rw.php` | RW masowe |
| `warehouse/inventory.php` | INW (wywołuje `core/InwEngine.php`) |
| `warehouse/correction.php` | KOR (wywołuje `core/KorEngine.php`) |
| `warehouse/transfer.php` | MM (wywołuje `core/MmEngine.php`) |
| `warehouse/add_item.php` | Dodanie pozycji do słownika |
| `warehouse/approve.php` | Zatwierdzanie dokumentów |
| `warehouse/avco_dict.php` | Słownik AVCO |
| `warehouse/documents_list.php` | Lista dokumentów magazynowych |
| `warehouse/mapping.php` | Mapowanie surowców |

#### Payments, Staff, Reports, Dashboard
| Ścieżka | Status |
|---------|--------|
| ~~`payments/settle.php`~~ | **USUNIĘTY 2026-07-28.** Martwy wrapper HTTP, zero call-site'ów. Logika w `core/SettlementEngine.php`. Produkcja POS: `pos/engine.php#settle_and_close`. |
| `backoffice/hr/engine.php` | ✅ **LIVE** — action router HR. **Zmiana (clock):** `clock_in` / `clock_out` / `clock_status` (Faza 3A, m041–m044). **Backoffice (NEW · 2026-05-04, wymaga `hrRequireManager`):** `employees_list`, `employee_get`, `employee_upsert` (z opcjonalnym `create_login` — tworzy konto `sh_users`), `employee_pin_set` (bcrypt do `sh_employees.auth_pin_hash` + sync `sh_users.pin_code` żeby ten sam PIN działał w POS i w Kiosk), `employee_rate_set` (zamyka poprzednią linię w `sh_employee_rates`, otwiera nową), `hr_users_unlinked` (lista `sh_users` bez profilu HR — do podpięcia istniejącego konta przy upsercie). **Faza 4 (2026-07-27):** `payroll_report`, `payroll_period_status`, `payroll_close_period` (auto-spłata rat zaliczek + lockPeriod), `advances_list`, `advance_request`/`approve`/`reject`/`mark_paid`/`void`, `bonus_add`, `adjustment_add`, `meal_record`. Konsument: `modules/backoffice/hr/index.html`. Kanoniczny endpoint silosu HR. |
| ~~`staff/payroll.php`~~ | **USUNIĘTY 2026-07-28.** Martwy wrapper GET bez konsumenta. Logika w `hr/engine.php#payroll_report` → `PayrollEngine::calculate()`. Katalog `api/staff/` usunięty. |
| ~~`dashboard/team_payroll.php`~~ | **USUNIĘTY 2026-07-28.** Martwy wrapper GET bez konsumenta. Logika w `hr/engine.php#payroll_report` → `TeamPayrollEngine::getAggregate()`. Katalog `api/dashboard/` usunięty. |
| `reports/food_cost.php` | 🟡 PLANNED — food cost + margin (FoodCostEngine). Zero call-site'ów (dodane do Prawo VIII 2026-07-29). |

#### Gateway / Integrations (m026–m029)
| Ścieżka | Opis |
|---------|------|
| `gateway/intake.php` | Zewnętrzny punkt wejścia (multi-key auth, rate limit, idempotency) |
| `integrations/inbound.php` | Callback handler dla 3rd-party POS / dostawców (webhook inbound) |

#### Procurement (m045+ · F2+F3 · 2026-05-11)
| Ścieżka | Opis |
|---------|------|
| `procurement/suggest.php` | AutoScan endpoint — action-based: `suggest`, `suggest_bulk`, `learn`, `learn_bulk`, `threshold_get`, `threshold_set`. RBAC: suggest = owner/admin/manager, learn = owner/manager, threshold_set = owner. Audit do `sh_settings_audit`. Fundament pod F3 (Procurement Inbox UI) + F4 (KSeF API client). |
| `procurement/inbox.php` (F3 · 2026-05-11, F4.5, 057 · 2026-05-14) | KSeF Inbox — action-based: `list`, `show`, `upload_xml`, `reparse`, `update_line`, `set_cost_category`, `accept`, `reject`, `smart_create_sku`, `reverse`. **F4.5:** `smart_create_sku`, `reverse` (owner-only, KOR + magazyn lub cofnięcie bez PZ). **m057:** przy istniejących kolumnach `line_type` / `expense_category_id` na `sh_ksef_invoice_lines` — `accept` / `update_line` / `reparse` (AutoScan tylko dla `INVENTORY`) oraz `set_cost_category` → kod `USE_LINE_OPEX`. RBAC + audyt `sh_settings_audit`. |
| `procurement/expense_categories.php` (2026-05-14 · m057) | Słownik `sh_expense_categories` — akcje: `list`, `create`, `update`, `delete` (POST JSON, `tenant_id` z JWT). Usunięcie rekordu z `is_system = 1` → HTTP 403. |
| `procurement/ksef_config.php` (NEW · F4 · 2026-05-11) | KSeF konfiguracja — action-based: `config_get`, `config_save` (owner-only, token przez CredentialVault), `test_connection` (real HTTP do sandbox/prod albo mock), `poll_now` (manual trigger), `state`, `toggle_auto_poll` (włącz/wyłącz worker). |

#### Utility
| Ścieżka | Status |
|---------|--------|
| `system/generate_seq.php` | 🟡 UTILITY — HTTP wrapper na `SequenceEngine` |
| `studio/generate_key.php` | 🟡 UTILITY — HTTP wrapper na `AsciiKeyEngine` |
| `visual_composer/asset_upload.php` | Smarter uploader dla layer/hero (używany przez online_studio) |

Wszystkie orphan/planned endpointy mają w nagłówku komentarz `// STATUS: …` wyjaśniający docelowego konsumenta.

---

### Core `/core/`

#### Foundation
| Plik | Rola |
|------|------|
| `db_config.php` | Połączenie PDO → `$pdo` |
| `AuthEngine.php` | `loginSystem`, `loginKiosk`, `getTargetModule`. **Od 2026-05-04:** PIN dokładnie 4 cyfry, owner dozwolony w kiosk mode (model jak Toast/Square), fallback `loginKioskResolveByEmployeePin` przez `sh_employees.auth_pin_hash` (bcrypt). |
| `AuthGuard.php` | Stateless JWT guard (V2) |
| `auth_guard.php` | Session + JWT guard (akceptuje oba — używany w endpointach chronionych; NIE w `api/online/engine.php`) |
| `JwtProvider.php` | Generowanie / walidacja JWT (HS256) |
| `CredentialVault.php` | Transparent AEAD encryption dla wrażliwych danych (m029) |
| `GatewayAuth.php` | Multi-key auth + rate limit + idempotency (m027) |
| `StaffFleetPresence.php` (NEW · 2026-05-04) | Heartbeat `last_seen` dla zalogowanych w aplikacjach mobilnych. `slicehubTouchStaffPresence` (login + każdy poll mobilny), `slicehubClearStaffPresence` (logout). TTL = `slicehubFleetPresenceTtlSeconds()` = **120 s**. Gwarancja: kierowca / kelner pojawia się na liście POS tylko gdy **realnie pracuje w aplikacji**. Wyjątek: kierowca w trasie (`sh_drivers.status='busy'`) — zawsze widoczny niezależnie od TTL. |
| `DriverFleetHelper.php` (NEW · 2026-05-04) | Idempotentne dopinanie wiersza `sh_drivers` dla kont z rolą `driver` powstałych w Kadrach. `slicehubEnsureDriverFleetRow` (per-call) + `slicehubSyncMissingDriverFleetRows` (bulk sync per tenant). |

#### Prymitywy współdzielone (NEW · 2026-07-27)

> Infrastruktura, nie logika domenowa — dostępne dla **wszystkich** silosów na tych samych prawach co `db_config.php` / `auth_guard.php`. Zależność zawsze w kierunku silos → `core/`, nigdy silos → silos (Prawo VI).

| Plik | Rola |
|------|------|
| `Uuid.php` | **SSOT generowania UUID.** `v4()` (CSPRNG `random_bytes`), `isValid()`, warianty deterministyczne. Zlikwidował **19 kopii** algorytmu rozsypanych po `core/`, `api/` i `scripts/`. Weryfikacja braku regresji: `grep "0x0f) | 0x40"` → dokładnie 1 trafienie (ten plik). |
| `Money.php` | **SSOT arytmetyki pieniądza.** PLN → grosze z HALF_UP, formatowanie, sanity cap. Zero `float` w ścieżce zapisu finansowego. |
| `DomainError.php` | Rozbicie wyjątku domenowego na `code` (ASCII, kontrakt dla klienta) + `message` (UTF-8, dla człowieka). |
| `HrRoles.php` | Kanoniczna lista ról managerskich (`hrRequireManager`) — zastępuje powielaną inline listę w routerze HR. |

#### Business engines
| Plik | Rola |
|------|------|
| `OrderStateMachine.php` | Transitions: `new → accepted → preparing → ready → in_delivery → completed / cancelled` |
| `SettlementEngine.php` | **F1–F4 · 2026-07-07** — canonical payment settlement. Split-tender close (F2), `applyPartialPayments()` stoły (F3), `collectDriverPayment()` kierowca (F4). |
| `OrderEventPublisher.php` | Transactional outbox dla event bus (m026) |
| `WebhookDispatcher.php` | Asynchroniczna dostawa webhooków (m026–m027) |
| `PromisedTimeEngine.php` | Obliczanie promised_time (kuchnia + dojazd + bufor) |
| `SequenceEngine.php` | Atomowa generacja numerów dokumentów (§28) |
| `AsciiKeyEngine.php` | Transliteracja + normalizacja + collision probe (§29) |

#### Warehouse engines
| Plik | Rola |
|------|------|
| `PzEngine.php` | Przyjęcie + AVCO |
| `WzEngine.php` | Zużycie surowców po acceptance (waste + modyfikatory). **`consumeForOrder` wpięty od F1 · 2026-05-11** przez `core/WarehouseConsumeHook` w `api/pos/engine.php#accept_order` + `api/orders/accept.php`. `checkAvailability` wpięty w online checkout. Formuła Prawa II Konstytucji v5: `needed = recipe_qty × (1 + waste%/100) × multiplier`. |
| `WarehouseConsumeHook.php` (NEW · F1 2026-05-11) | Helper post-commit hook konsumpcji magazynu po `transitionOrder('accepted')`. Wywołuje `WzEngine::consumeForOrder` w niezależnej transakcji. Failure NIE blokuje akceptu zamówienia (kuchnia gotuje, manager może naprawić korektą KOR). |
| `WarehouseReverseHook.php` (NEW · F5-C 2026-05-11) | Symetryczny hook do `WarehouseConsumeHook`. Wywoływany z `api/pos/engine.php#cancel_order` gdy zamówienie zostaje anulowane PO `accepted`. Tworzy `wh_documents` typ `KOR`, zwraca surowce na `wh_stock`, oznacza pierwotne `WZ` jako `status='reversed'`. Bez tego POS-cancel powodował drift magazynu (Konstytucja v5 § Prawo II). |
| `Geocoder.php` (NEW · F6 2026-05-11) | Lekki klient Nominatim + cache w `sh_geocode_cache`. Wpięty w `api/pos/engine.php#process_order` dla `order_type=delivery`. Adres → `delivery_lat`/`delivery_lng` w `sh_orders`. Dispatcher (`api/courses/engine.php#get_dashboard`) używa `COALESCE(delivery_lat, lat)` żeby mapa pokazywała prawdziwy pin zamiast losowego fallbacku. Rate-limit 1 req/sec (cache eliminuje powtórki). |
| `AutoScanEngine.php` (NEW · F2 2026-05-11) | **Shared AutoScan Engine** dla nazw zewnętrznych z faktur dostawców (KSeF inbox, PZ import). 4-stopniowe confidence scoring: `EXACT (100)` → `ALIAS (85)` → `NAME (60-80)` → `FUZZY (≤59)` → `NONE`. Self-learning przez `sh_product_mapping` (`learnMapping()`). API: `match()`, `matchBulk()`, `learnMapping()`. Threshold auto-accept w `sh_tenant_settings.autoscan_auto_accept_threshold` (default 70). Wpięty w `api/procurement/suggest.php`. Fundament pod F3 (Procurement Inbox UI) i F4 (KSeF API client). |
| `Ksef/Parser.php` (NEW · F3 2026-05-11) | Parser polskiego standardu FA(2) XML (KSeF). Namespace-aware SimpleXML, ekstraktuje Podmiot1/Podmiot2/Fa/FaWiersz/totals. Konsumowane przez `api/procurement/inbox.php#upload_xml`, `core/Ksef/InboxInvoiceRepository.php`, worker KSeF. |
| `Ksef/InboxInvoiceRepository.php` (2026-05-14) | Wspólny `INSERT` nagłówka + linii `sh_ksef_invoices` / `sh_ksef_invoice_lines` oraz pierwszy przebieg AutoScan dla importu z API (`worker_ksef_inbox`, `poll_now`) i uploadu XML. |
| `Ksef/Client.php` (F4 2026-05-11, v2 2026-05-14) | Klient **KSeF API v2** MF: `https://api-test.ksef.mf.gov.pl/v2` (sandbox), `https://api.ksef.mf.gov.pl/v2` (prod). Uwierzytelnianie: token z podatki.gov.pl → challenge → RSA-OAEP SHA-256 (`openssl pkeyutl`) → JWT access/refresh w `credentials`. Metody: `testConnection()` (sonda `GET /rate-limits`), `queryInbox()` (`POST /invoices/query/metadata`), `fetchInvoiceXml()` (`GET /invoices/ksef/{ksefNumber}`). Szanuje `sh_tenant_integrations.is_active`; retry przy HTTP 429 z `Retry-After`. Konsumowane przez `scripts/worker_ksef_inbox.php` i `api/procurement/ksef_config.php`. |
| `InwEngine.php` | Inwentaryzacja |
| `KorEngine.php` | Korekta |
| `MmEngine.php` | Międzymagazynowe |

#### Backoffice HR & Payroll (Faza 3A + 3B + 3C — DONE 2026-04-23)
| Plik | Rola |
|------|------|
| `HrClockEngine.php` | ✅ **Jedyny kanon** clock-in/out. `employee_id`-first, PIN bcrypt, terminal/source/geo, event outbox, snapshot stawki. Konsolidacja 2026-04-23 (stary `ClockEngine.php` **usunięty**; `api/staff/clock.php` **usunięty**). |
| `PayrollLedger.php` | ✅ **Faza 3B + 3C DONE** — append-only writer do `sh_payroll_ledger`. `record()` / `reverse()` + readery (`sumForPeriod`, `listForPeriod`) + `lockPeriod()` / `isPeriodLocked()` (jednokierunkowe zamknięcie księgi, `ERR_PERIOD_LOCKED` w `record`/`reverse`). STRICT int grosze, sign-per-type, cross-tenant ref guard, idempotency po `entry_uuid`. **31/31 smoke PASS**. |
| `AdvanceEngine.php` | ✅ **Faza 3B + 3C DONE** — cykl życia zaliczki (`requested → approved → paid → settled` + `rejected` + `void`). `markPaid()` rozbija raty + emituje `advance_payment` do ledgera; `recordRepayment()` auto-settluje; `voidAdvance()` (3C) wycofuje błędnie wypłaconą zaliczkę (reverse payment + void pending rat; blokada `ERR_PARTIAL_REPAYMENT`). **34/34 smoke PASS**. |
| `PayrollEngine.php` | ✅ **Faza 4 DONE (2026-07-25)** — rewrite IN-PLACE wykonany. `calculate()` / `buildComparison()` czytają z `sh_payroll_ledger` (SSOT) + stawki temporalne z `sh_employee_rates`. Zero czytników `sh_deductions` / `sh_users.hourly_rate`. **Fix #2 (2026-07-27):** wpisy księgowane wstecz (period ≠ created_at month) widoczne w raporcie okresu (klauzula OR w SQL). Parity 0 gr: `tests/payroll_engine_rewrite_parity.php`. Szczegóły: `_docs/18_BACKOFFICE_HR_LOGIC.md §13.8`. |
| `TeamPayrollEngine.php` | ✅ **Faza 4 DONE (2026-07-25)** — konsument przepisanego `PayrollEngine`; lista z `sh_employees`, burn rate ze stawek temporalnych. |
| `PayrollAllocator.php` (NEW · 2026-07-27) | ✅ **HR-6 ZAMKNIĘTE** — czysta logika podziału sesji na segmenty miesięczne (`splitByPeriod`) + proporcjonalny podział kwoty (`allocate`) metodą największych reszt. Zero groszowego wycieku (fuzz 500×). Konsument: `worker_payroll_accrual.php`. Szczegóły: `_docs/18_BACKOFFICE_HR_LOGIC.md §14.4`. |
| `MealEngine.php` (NEW · 2026-07-27) | ✅ Posiłki pracownicze z atomowym potrąceniem w ledgerze. Idempotentny przez `idempotency_key`. Używa `Money` + `Uuid`. |

#### Visual & assets
| Plik | Rola |
|------|------|
| `AssetResolver.php` | Unified URL resolver (m021, m025 cleanup) |
| `SceneResolver.php` | Full dish scene contracts (m022) |
| `FoodCostEngine.php` | Food cost + margin per-channel |

#### Integrations `/core/Integrations/` (m028)
| Plik | Rola |
|------|------|
| `AdapterRegistry.php` | Rejestr adapterów 3rd-party |
| `BaseAdapter.php` | Klasa bazowa |
| `IntegrationDispatcher.php` | Wysyłka do adapterów |
| `DotykackaAdapter.php` | Dotykačka POS |
| `GastroSoftAdapter.php` | GastroSoft |
| `PapuAdapter.php` + `PapuClient.php` | Papu (delivery aggregator) |

#### Frontend helpers `/core/js/`
| Plik | Rola |
|------|------|
| `sh_api_base.js` | **SSOT prefiksu URL** — `SliceHub.getApiBase()`, `getAppBase()`, `apiUrl()`, `appUrl()`. Kolejność: meta `sh-api-base` (opcj., nieużywany w modułach) → `window.__SH_API_BASE__` (env) → heurystyka pathname przed `/modules/` → heurystyka segmentu URL → fallback. Handoff: `.cursor/plans/api_base_paths_handoff.md`. |

**`tenant_config.php` (root repo, serwowany jako JS):** ustawia `meta[name=sh-tenant-id]` i `window.__SH_TENANT_ID__` przed modułowym JS. Kolejność: `SLICEHUB_TENANT_ID` → sesja PHP → tenant z aktywnymi `sh_users` → pierwszy `sh_tenant` → `1`; w przeglądarce `?tenant=N` nadpisuje wynik. Env `SLICEHUB_API_BASE` → `window.__SH_API_BASE__`. Audyt: `_docs/sessions/2026-05-22_tenant_discovery_auth.md`.
| `api_client.js` | Bazowy `ApiClient` (fetch wrapper z Bearer tokenem); endpointy `../../api/...` i `/api/...` resolve'uje przez `SliceHub.apiUrl` |
| `core_validator.js` | Walidatory współdzielone |
| `neon_pizza_engine.js` | Animacje / efekty wizualne |
| `scene_renderer.js` | Rendering scen na frontendzie |

**Konwencja modułu:** w `index.html` → `tenant_config.php` (relatywnie) → `sh_api_base.js` → modułowy JS. W JS: `SliceHub.apiUrl('/{domena}/engine.php')` zamiast hardcoded `/slicehub/api` lub absolutnych ścieżek.

---

## 3. BAZA DANYCH & SKRYPTY

### Migracje `/database/migrations/`

| Nr | Plik | Co dodaje |
|----|------|-----------|
| 001 | `001_init_slicehub_pro_v2.sql` | Grand schema (wszystkie tabele, widoki, FK, indeksy) |
| 004 | `004_expand_search_aliases.sql` | sys_items: search_aliases, is_active, is_deleted + PL deklinacje |
| 006 | `006_studio_mission_control.sql` | sh_categories: VAT / sh_menu_items: PLU, dostępność |
| 007 | `007_pos_engine_columns.sql` | sh_orders: druk paragonu, cart_json, NIP |
| 008 | `008_delivery_ecosystem.sql` | `sh_driver_locations` (GPS) |
| 009 | `009_delivery_state_machine.sql` | Stany delivery |
| 010 | `010_driver_action_type.sql` | Akcje kierowcy (pack_cold, check_id…) — **idempotent (2026-07-27)**: guard per-kolumna |
| 011 | `011_integration_logs.sql` | Logi integracji 3rd-party |
| 012 | `012_visual_layers.sql` | Warstwy wizualne (pierwsza iteracja) |
| 013 | `013_board_companions.sql` | Companions |
| 014 | `014_global_assets.sql` | Pierwsza iteracja globalnej biblioteki assetów |
| 015 | `015_normalize_three_drivers.sql` | Normalizacja ról kierowców |
| 016 | `016_visual_compositor_upgrade.sql` | Hero photo + kalibracja; surface bg |
| 017 | `017_online_module_extensions.sql` | Rozszerzenia dla modułu online |
| 019 | `019_layer_positioning.sql` | Pozycjonowanie warstw |
| 020 | `020_director_scenes.sql` | Sceny Directora |
| 021 | `021_unified_asset_library.sql` | **Single Source of Truth** — `sh_assets` + `sh_asset_links` |
| 022 | `022_scene_kit.sql` | Scene Kit (contracts) |
| 023 | `023_scene_templates_content.sql` | Seedy szablonów scen |
| 024 | `024_modifier_visual_impact.sql` | Wpływ modyfikatorów na wizualny render |
| 025 | `025_drop_legacy_magic_dict.sql` | Czyszczenie legacy magic_* słowników |
| 026 | `026_event_system.sql` | Event bus + transactional outbox |
| 027 | `027_gateway_v2.sql` | Gateway V2 (multi-key, rate limit, idempotency) |
| 028 | `028_integration_deliveries.sql` | Integracje delivery/POS |
| 029 | `029_infrastructure_completion.sql` | Domknięcie infrastruktury (vault, webhook retry…) |
| 030 | `030_scene_harmony_cache.sql` | Cache harmonii sceny |
| 031 | `031_baked_variants.sql` | "Upieczone" warianty wizualne (`sh_assets.cook_state`) |
| 032 | `032_asset_library_organizer.sql` | `sh_assets.display_name` (VARCHAR 191) + `tags_json` + 3 indexy |
| 033 | `033_notification_director.sql` | Notification Director — kanały / routing / szablony / CRM (`sh_customer_contacts`) |
| 034 | `034_faza7_gdpr_security.sql` | GDPR hardening — HMAC, rate-limit buckets, consent log, security audit |
| 035 | `035_atelier_performance.sql` | Atelier Performance — `sh_asset_registry` + generated columns (wcześniej 021a, przenumerowane po rozwiązaniu kolizji) |
| 036 | `036_asset_display_name.sql` | Backfill polskich etykiet w `sh_assets.display_name` (wcześniej 032_asset_display_name, przenumerowane po rozwiązaniu kolizji) |
| 037 | `037_pos_foundation.sql` | **Dine-In Foundation** — `sh_zones`, `sh_tables`, `sh_order_logs`; rozszerzenia `sh_orders` (table_id, waiter_id, guest_count, split_type, qr_session_token) + anti-ghosting; rozszerzenia `sh_order_payments` i `sh_order_lines`. Wcześniej w `scripts/setup_enterprise_tables.php` (legacy helper). |
| 038 | `038_drop_legacy_inventory_docs.sql` | **Cleanup** — DROP `wh_inventory_docs` + `wh_inventory_doc_items` (martwe legacy od m001, 0 wierszy, 0 użyć w kodzie). Kanon: `wh_documents` (type=INW). |
| 041 | `041_hr_employees_foundation.sql` | **HR Foundation** — `sh_employees` + `sh_employee_rates` + backfill z `sh_users`. **(2026-07-27)** Dynamic-SQL-guards na `hourly_rate` — re-run po migracji 061 = no-op. |
| 048 | `048_variant_scales.sql` | **Variant Scales** — `sh_variant_scales` + `sh_menu_items.variant_scale_id` + ceny per-skala. **(2026-07-27)** Guard rozbity per obiekt (kolumna / indeks / FK osobno). |
| 061 | `061_drop_users_hourly_rate.sql` (NEW · 2026-07-27) | **Expand-Contract domknięte** — DROP `sh_users.hourly_rate`. Stawki czytane wyłącznie z `sh_employee_rates` (SSOT). |

> Luki 002/003/005/018 — dawne seedy/eksperymenty przeniesione do `seed_demo_all.php` lub `_archive_*.sql`.
> Szczegóły schematu → [`_docs/04_BAZA_DANYCH.md`](04_BAZA_DANYCH.md)

### Skrypty `/scripts/`

| Plik | Co robi | Uruchomienie |
|------|---------|--------------|
| `setup_database.php` | Migracje 006/007/008 (bez danych, idempotentny) | Przeglądarka |
| `seed_demo_all.php` | Unified Demo Seed — kompletne dane testowe dla CAŁEGO systemu. **(2026-07-27)** Stawki z mapy PHP `$HR_RATES_MINOR` → `sh_employee_rates` (nie z `sh_users.hourly_rate` — kolumna zdropowana w migracji 061). | Przeglądarka / CLI |
| `seed_ultimate_delivery.php` | Delivery Ecosystem Seed — kierowcy, zamówienia delivery (paid/unpaid), GPS | Przeglądarka |

### Procedura czystej instalacji

```
1. phpMyAdmin → CREATE DATABASE slicehub_pro_v2 (utf8mb4_unicode_ci)
2. phpMyAdmin → Import → database/migrations/001_init_slicehub_pro_v2.sql
3. `php scripts/apply_migrations_chain.php` (kolejno migracje 004 → 061, idempotentne)
4. Przeglądarka → http://localhost/slicehub/scripts/seed_demo_all.php
```

---

## 4. LEGACY — Strefa kwarantanny

`/_KOPALNIA_WIEDZY_LEGACY/` — **ARCHIWUM OFFLINE (od 2026-04-22)** — przeniesione poza repo na dysk backupowy właściciela, wpis w `.gitignore`. Stary kod (108 plików PHP/HTML), w tym dawny moduł `pos_kelner_STARY/`. Inwentarz historyczny: `_docs/03_MAPA_KOPALNI.md`.

`/_archive/` — **ARCHIWUM OFFLINE (od 2026-04-22)** — przeniesione poza repo (`.gitignore`). Stare monolity warehouse (manager_in/pz/rw, settings_magazyn, settings_mapping, warehouse_*.js z 9-10.04) + 3 stare API (`api_inventory.php`, `api_mapping.php` → zastąpione przez `api/warehouse/*`; `api_manager.php` → HR/finanse kadry, nie zmigrowane, patrz backlog w `00_PAMIEC_SYSTEMU.md` §15.9). Kluczowy dokument `ustalenia_2026-04-16.md` przeniesiony do `_docs/ARCHIWUM/`.

**ZASADY DLA AI:**
1. Oba foldery = **STRICTLY READ-ONLY**
2. Zakaz edycji, zakaz linkowania do nowego UI
3. Służą wyłącznie jako encyklopedia biznesowa — czytaj, zrozum logikę, napisz NOWY kod w folderze produkcyjnym

---

## 5. DOKUMENTACJA

| Plik | Zawartość |
|------|-----------|
| `_docs/START_TUTAJ.md` | **Punkt wejścia** — od tego pliku zaczynasz czytanie docs |
| `_docs/00_PAMIEC_SYSTEMU.md` | **NORTH STAR** — master reference, 7 nienaruszalnych praw |
| `_docs/01_KONSTYTUCJA.md` | Konstytucja projektu (cele, filozofia) |
| `_docs/02_ARCHITEKTURA.md` | Ten plik — mapa systemu |
| `_docs/03_MAPA_KOPALNI.md` | Mapa _KOPALNIA_WIEDZY_LEGACY |
| `_docs/04_BAZA_DANYCH.md` | Schemat bazy — tabele, relacje, konwencje |
| `_docs/05_INSTRUKCJA_FOTO_UPLOAD.md` | Limity uploadu, walidacja, brief fotograficzny |
| `_docs/07_INTERACTION_CONTRACT.md` | Kontrakt interakcji frontend ↔ backend |
| `_docs/08_ORDER_STATUS_DICTIONARY.md` | Kanoniczny słownik statusów zamówienia |
| `_docs/09_EVENT_SYSTEM.md` | Event bus (m026) |
| `_docs/10_GATEWAY_API.md` | Gateway V2 (m027) |
| `_docs/11_WEBHOOK_DISPATCHER.md` | Webhook dispatcher |
| `_docs/12_INTEGRATION_ADAPTERS.md` | Adaptery 3rd-party (m028) |
| `_docs/13_SETTINGS_PANEL.md` | Panel Settings |
| `_docs/14_INBOUND_CALLBACKS.md` | Callbacki przychodzące |
| `_docs/15_KIERUNEK_ONLINE.md` | Kierunek rozwoju modułu Online |
| `_docs/18_BACKOFFICE_HR_LOGIC.md` | Architektura silosu HR & Payroll |
| `_docs/19_HUB_AND_KIOSK.md` | **Hub-Centric Mobile Shell + Staff Fleet Presence + Kiosk** (2026-05-04) |
| `_docs/ustalenia.md` | Ustalenia projektowe (roboczy) |
| `_docs/ARCHIWUM/README.md` | Zasady archiwum dokumentacji |
| `database/README.md` | Quick start — instalacja / reset / aktualizacja bazy |
