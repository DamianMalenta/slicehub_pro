# Audyt Architektoniczny — Funkcje UI i Handlery Zamówień

> **Data:** 2026-08-24
>
> **Zakres:** moduły frontowe `modules/pos/`, `modules/courses/`, `modules/tables/`, `modules/hub/`, `modules/driver_app/`, `modules/kds/`, `core/js/` oraz pozostałe moduły zgłoszone przez `deno lint` (reguła `no-unused-vars`).
>
> **Metodologia:**
> 1. Zebrano wszystkie 74 wpisy `no-unused-vars` z `deno lint` (148 matches → 74 unikalnych po deduplikacji wielokrotnych `e`/`err` w catch-blocks).
> 2. Dla każdej zmiennej/funkcji odczytano kontekst (10–30 linii) i sprawdzono cross-file references (grep w `.html`, `.php`, `.js`).
> 3. Sprawdzono inline `onclick=` w szablonach HTML (31 wywołań `window.X` w 7 plikach, 36 wywołań `App.X` w courses, 15 w driver_app, 22 w studio).
> 4. Skorelowano z dokumentacją: `_docs/audits/core_dead_code_audit.md`, `_docs/sessions/2026-08-20_order_edit_ui.md`, `_docs/00_PAMIEC_SYSTEMU.md`, `_docs/02_ARCHITEKTURA.md`.
>
> **Klasyfikacja:**
> - **[A] AKTYWNA** — wywoływana dynamicznie (inline onclick, addEventListener, cross-file classic script). Zachować bez zmian.
> - **[B] PLANOWANA / STUB** — zgodna z roadmapą, szkielet czekający na rozbudowę. Zachować / oznaczyć.
> - **[C] LEGACY / DEAD CODE** — zastąpiona nową logiką lub leftover po refaktorze. Wskazać czym zastąpiona.

---

## 1. False Positives — cross-file classic scripts (Kategoria [A])

Deno lint analizuje pliki JS osobno, nie widząc cross-file references w klasycznych skryptach `<script>` (bez ES modules). Wszystkie poniższe są **AKTYWNE** — wywoływane z innych plików JS lub inline `onclick` w HTML.

| Zmienna/Funkcja | Plik | Linia | Dowód użycia | Kategoria |
|-----------------|------|-------|--------------|-----------|
| `App` | `modules/courses/js/courses_app.js` | 5 | 36 inline `onclick="App.X"` w `courses/index.html` | **[A]** |
| `CoursesAPI` | `modules/courses/js/courses_api.js` | 6 | 13 wywołań w `courses_app.js` | **[A]** |
| `CoursesUI` | `modules/courses/js/courses_ui.js` | 6 | 27 wywołań w `courses_app.js` | **[A]** |
| `CoursesMap` | `modules/courses/js/courses_map.js` | 5 | 6 wywołań w `courses_app.js` | **[A]** |
| `KdsApp` | `modules/kds/js/kds_app.js` | 5 | 1 inline `onclick` w `kds/index.html` | **[A]** |
| `DriverAPI` | `modules/driver_app/js/driver_api.js` | 6 | 20 wywołań w `driver_app.js` | **[A]** |
| `DriverApp` | `modules/driver_app/js/driver_app.js` | 7 | 15 inline `onclick="DriverApp.X"` w `driver_app/index.html` | **[A]** |
| `HubOrderEdit` | `modules/hub/js/hub_order_edit.js` | 6 | IIFE z auto-init `addEventListener` na końcu pliku; `open()`/`close()` eksponowane przez `Object.freeze` | **[A]** (auto-init) |
| `TablesAPI` | `modules/tables/js/tables_api.js` | 5 | 10 wywołań w `tables_app.js` | **[A]** |
| `TablesUI` | `modules/tables/js/tables_ui.js` | 6 | 43 wywołania w `tables_app.js` | **[A]** |

> **Wniosek:** Te 10 wpisów to false positives deno lint. Nie należy ich modyfikować — są aktywne w runtime.

---

## 2. Handlery zamówień — funkcje UI

### 2.1 POS (`modules/pos/`)

| Funkcja | Plik:Linia | Typ wywołania | Status | Kategoria |
|---------|-----------|---------------|--------|-----------|
| `_onItemClick(item)` | `pos_app.js:702` | `addEventListener('click')` w renderowanym DOM | Aktywna — wybór dania do koszyka | **[A]** |
| `_onDriverClick(driverId)` | `pos_app.js:1139` | `PosUI.renderDrivers(..., _onDriverClick)` callback | Aktywna — wybór kierowcy do trasy | **[A]** |
| `_toggleOrderToRoute(id)` | `pos_app.js:1173` | Wywoływana z kanban card onclick | Aktywna — dodaj/usuń z trasy | **[A]** |
| `_openEditInCart(id)` | `pos_app.js` (callback `onEdit`) | `PosUI.renderKanban(..., {onEdit: (id) => _openEditInCart(id)})` | Aktywna — edycja zamówienia w koszyku | **[A]** |
| `_openPaymentModal(id, 'settle')` | `pos_app.js` (callback `onSettle`) | `PosUI.renderKanban(..., {onSettle})` | Aktywna — modal płatności | **[A]** |
| `_openCancelModal(id)` | `pos_app.js` (callback `onCancel`) | `PosUI.renderKanban(..., {onCancel})` | Aktywna — modal anulowania | **[A]** |
| `showCancelModal(orderId, onConfirm)` | `pos_ui.js:482` | Wywoływana z `pos_app.js` | Aktywna, ale `orderId` unused w ciele | **[A]** (param unused) |
| `renderKanban(orders, filterType, expandedId, ...)` | `pos_ui.js:608` | Wywoływana z `pos_app.js:_renderBattlefield` | Aktywna, ale `fmtTime(dateStr, type)` — `type` unused | **[A]** (param unused) |

### 2.2 Courses / Dispatcher (`modules/courses/`)

| Funkcja | Plik:Linia | Typ wywołania | Status | Kategoria |
|---------|-----------|---------------|--------|-----------|
| `App.sendDispatch()` | `courses_app.js` | inline `onclick="App.sendDispatch()"` w HTML | Aktywna — wysyłka kursu | **[A]** |
| `App.cancelDispatch()` | `courses_app.js` | inline `onclick` w HTML | Aktywna | **[A]** |
| `App.createUnassignedCourse()` | `courses_app.js` | inline `onclick` w HTML | Aktywna — kurs bez kierowcy | **[A]** |
| `App.openAssignDriverModal(courseId)` | `courses_app.js` | inline `onclick` w renderowanym HTML (`courses_ui.js:207`) | Aktywna — przypisz kierowcę | **[A]** |
| `App.batchAppend()` / `App.batchNewRun()` | `courses_app.js` | inline `onclick` w HTML | Aktywna — operacje batch kursu | **[A]** |
| `App.confirmRecall()` | `courses_app.js` | inline `onclick` w HTML | Aktywna — sygnał ZAWRÓĆ | **[A]** |
| `renderCoursesGrid(orders, courses, drivers)` | `courses_ui.js:141` | `CoursesUI.renderCoursesGrid(...)` z `courses_app.js:156` | Aktywna, ale `courses` (2. param) unused — grupowanie przez `orders.filter(o => o.course_id)` | **[A]** (param unused) |

### 2.3 Tables (`modules/tables/`)

| Funkcja | Plik:Linia | Typ wywołania | Status | Kategoria |
|---------|-----------|---------------|--------|-----------|
| `handleOpenTable(table, count)` | `tables_app.js:280` | `TablesUI.showGuestPanel(el, table, cb)` — case `'free'` | Aktywna — otwarcie stolika → redirect POS | **[A]** |
| `handleMarkServed(table)` | `tables_app.js:308` | `onAction` callback — case `'ready'` | Aktywna — oznacz dania podane | **[A]** |
| `handleMarkClean(table)` | `tables_app.js` | `onAction` callback — case `'dirty'` | Aktywna — wyczyść stolik | **[A]** |
| `handleOpenReserved(table, count)` | `tables_app.js` | `TablesUI.showGuestPanel(...)` — case `'reserved'` | Aktywna | **[A]** |
| `handleFireCourse(table)` | `tables_app.js:313` | **NIE podpięta** — żadnen `case` w switchu nie wywołuje | Stub — logika API istnieje (`TablesAPI.fireCourse`), UI nie podpięte | **[B]** |
| `handleMerge(table)` | `tables_app.js:322` | **NIE podpięta** — żadnen `case` w switchu nie wywołuje | Stub — logika API istnieje (`TablesAPI.mergeTables`), `TablesUI.showMergeModal` istnieje, UI nie podpięte | **[B]** |

### 2.4 Hub — Order Edit (`modules/hub/`)

| Funkcja | Plik:Linia | Typ wywołania | Status | Kategoria |
|---------|-----------|---------------|--------|-----------|
| `HubOrderEdit.open()` | `hub_order_edit.js:68` | Auto-init `addEventListener('click')` na `#hub-card-order-edit` | Aktywna — modal edycji zamówień | **[A]** |
| `HubOrderEdit.close()` | `hub_order_edit.js:61` | `addEventListener` na `#hoe-btn-close` / `#hoe-overlay` | Aktywna | **[A]** |
| `HubOrderEdit.save()` | `hub_order_edit.js:263` | `addEventListener` na `#hoe-btn-save` | Aktywna — `POST api/orders/edit.php` | **[A]** |
| `loadOrder(orderId)` | `hub_order_edit.js:95` | `addEventListener` na przyciski listy zamówień | Aktywna — `GET api/orders/get_for_edit.php` | **[A]** |

> **Kontekst:** Zgodnie z `_docs/sessions/2026-08-20_order_edit_ui.md`, modal edycji zamówień został w pełni wdrożony 2026-08-20. Backend `api/orders/edit.php` + `DeltaEngine` + `sh_orders.kitchen_delta` są aktywne. KDS pokazuje blok "ZMIANY OD OSTATNIEGO WYDRUKU".

---

## 3. Zmienne unused — klasyfikacja szczegółowa

### 3.1 Kategoria [A] AKTYWNA — false positive lub celowy pattern

| Zmienna | Plik:Linia | Kontekst | Dlaczego [A] |
|---------|-----------|----------|--------------|
| `e` (×10) | `pos_api.js:49,103`, `pos_sw_register.js:36`, `PosSyncEngine.js:235`, `courses_api.js:31`, `driver_api.js:57`, `driver_app.js:296`, `online_track.js:246`, `food_cost_app.js:68,90`, `order_edit_app.js:68,90`, `profile_app.js:64`, `settings_app.js:915,1500`, `studio_item.js:1553,1582`, `DirectorApp.js:89,738`, `hr_app.js:718` | `catch (e)` blocks | Celowy pattern — error ignorowany, zwracany hardcoded "Network error". Funkcja działa poprawnie. |
| `r` | `settings_app.js:1621` | `catch` lub result | Sprawdzić — prawdopodobnie podobny pattern |
| `err` | `studio_ui.js:572` | `catch (err)` | Prawdopodobnie celowy pattern |
| `error` | `studio_modifiers.js:1417` | `catch (error)` | Prawdopodobnie celowy pattern |
| `s` (×2) | `hr_app.js:262,271` | Kontekst HR | Sprawdzić |
| `lblCls` | `hr_app.js:265` | Kontekst HR | Sprawdzić |

### 3.2 Kategoria [B] PLANOWANA / STUB

| Zmienna | Plik:Linia | Kontekst | Planowane użycie |
|---------|-----------|----------|------------------|
| `handleFireCourse(table)` | `tables_app.js:313` | Funkcja zaimplementowana, nie podpięta do UI | Wysłanie następnej zmiany (course) na kuchnię z poziomu stolika. API `TablesAPI.fireCourse` istnieje. |
| `handleMerge(table)` | `tables_app.js:322` | Funkcja zaimplementowana, nie podpięta do UI | Łączenie stolików. API `TablesAPI.mergeTables` + `TablesUI.showMergeModal` istnieją. |
| `cfg` | `SharedSceneRenderer.js:345` | `_renderModGrid(cfg)` — zwraca placeholder HTML | Grid modyfikatorów w SharedSceneRenderer — planowane rozszerzenie renderowania. |
| `vat` | `warehouse_core.js:599` | Odczyt z DOM `#new-item-vat`, nie wysłany do API | Pole VAT dla surowca — formularz zbiera, ale `postAddItem({name, base_unit, sku})` nie wysyła. |
| `ksefCode` | `warehouse_core.js:600` | Odczyt z DOM `#modal-item-ksef`, nie wysłany do API | Kod KSeF dla surowca — j.w. |
| `colIdx` | `pos_app.js:1126` | `onColumnToggle: (colIdx) => { /* noop */ }` | Rozszerzenie kolumn kanban — obecnie state przez grid dataset. |
| `SUGGEST_ENDPOINT` | `procurement_app.js:35` | Stała endpointu | Prawdopodobnie planowane autocomplete — sprawdzić |
| `tenantId` (×2) | `pos_hr_clock.js:5`, `deck-panel.js:8` | Parametr funkcji, nie używany | Planowane użycie do HR queries — zastąpione przez JWT-based tenant identification |

### 3.3 Kategoria [C] LEGACY / DEAD CODE

| Zmienna | Plik:Linia | Kontekst | Zastąpione przez |
|---------|-----------|----------|------------------|
| `orderMarkers` | `courses_map.js:7` | `let orderMarkers = []` — nigdy nie używane | `orderGroup = L.layerGroup()` (Leaflet Layer Groups, linia 33) — refaktor z tablicy markerów na LayerGroup |
| `driverMarkers` | `courses_map.js:8` | `let driverMarkers = []` — nigdy nie używane | `driverGroup = L.layerGroup()` (linia 34) — j.w. |
| `$$` | `pos_ui.js:9` | `const $$ = sel => document.querySelectorAll(sel)` — nigdy nie wywołane | Prawdopodobnie wczesny helper DOM, zastąpiony bezpośrednimi `querySelectorAll` lub `getElementById` |
| `$$` | `waiter_app.js:28` | `const $$ = (s) => document.querySelectorAll(s)` — nigdy nie wywołane | j.w. |
| `now` | `pos_app.js:1008` | `const now = new Date()` w `_renderBattlefield` — nie używane | Leftover po refaktor — `now` było prawdopodobnie używane do SLA calc, przeniesione do `renderKanban` (linia 610) |
| `courses` | `courses_ui.js:141` | 2. param `renderCoursesGrid(orders, courses, drivers)` — nie używany | Grupowanie odbywa się przez `orders.filter(o => o.course_id)` zamiast osobnej struktury `courses` |
| `rowEl` | `warehouse_pz.js:191` | 1. param `renderAutoScanResult(rowEl, data, ...)` — nie używany | Leftover — wynik renderowany z `data` (destrukturyzacja), nie potrzebuje kontekstu wiersza |
| `type` | `pos_ui.js:612` | 2. param `fmtTime(dateStr, type)` — nie używany | Planowano różne formatowanie dla typów zamówień (delivery/dine_in/takeaway), nie zaimplementowano |
| `orderId` | `pos_ui.js:482` | 1. param `showCancelModal(orderId, onConfirm)` — nie używany | Modal nie wyświetla ID — przekazywany dla spójności API callback |
| `rot`, `uid` | `neon_pizza_engine.js:109` | Parametry `shapeMeat(cx, cy, size, color, rot, uid)` — nie używane | `shapeMeat` nie rotuje/nie identyfikuje — inne shape functions (`shapeMushroom`, `shapeOnion`) używają `rot`. `shapeMeat` to okrąg, rotacja bez znaczenia. |
| `name` | `neon_pizza_engine.js:232` | Parametr `renderIngredientLayer(sku, name, type, viewSize)` — nie używany | `sku` wystarcza do identyfikacji, `name` było do debug/label |
| `root` | `online_ui.js:66` | Sprawdzić | — |
| `data` | `online_ui.js:688` | Sprawdzić | — |
| `doorway` | `online_doorway.js:182` | Sprawdzić | — |
| `o` | `online_track.js:571` | Sprawdzić | — |
| `isPaid` | `driver_app.js:53` | Sprawdzić | — |
| `q` | `studio_recipe.js:376` | Sprawdzić | — |
| `i` | `tables_ui.js:78` | Sprawdzić | — |
| `i` | `MagicCompanions.js:32` | `.map((c, i) => ...)` — `i` unused | Iterator nieużywany w map |
| `btn` | `notifications.js:694` | Sprawdzić | — |
| `sel` | `ViewportPanel.js:108` | Sprawdzić | — |
| `meta` | `MagicBake.js:36` | Parametr `magicBake(layers, meta = {})` — nie używany | Planowane metadane bake — stub |
| `name`, `sku` | `MagicCompanions.js:8,13` | Odczytane, nie używane | Leftover |
| `TOPPING_TYPES` | `HarmonyScore.js:66` | `const TOPPING_TYPES = new Set(...)` — nie używane | Prawdopodobnie wczesna wersja klasyfikacji, zastąpiona |
| `preset` | `HarmonyScore.js:100` | Parametr `isPolished(L, preset)` — nie używany | Leftover |

---

## 4. Inline `onclick` w HTML — stan po refactorze `window` → `globalThis`

Po refactorze (PR #60) pliki JS przypisują do `globalThis.X`, ale **31 inline `onclick="window.X(...)"` w 7 plikach HTML** nadal odwołuje się do `window.X`.

| Plik HTML | Liczba `onclick="window.X"` |
|-----------|---------------------------|
| `modules/studio/index.html` | 21 |
| `modules/warehouse/manager_rw.html` | 2 |
| `modules/warehouse/manager_in.html` | 2 |
| `modules/warehouse/manager_mm.html` | 2 |
| `modules/warehouse/documents.html` | 2 |
| `modules/warehouse/settings_magazyn.html` | 1 |
| `modules/warehouse/manager_kor.html` | 1 |

> **Ważne:** W przeglądarce `window === globalThis`, więc **to działa poprawnie** — `window.Core` i `globalThis.Core` to ta sama właściwość. Nie ma uszkodzenia funkcjonalności. Dla spójności można ujednolicić, ale nie jest to wymagane.

---

## 5. Kontekst architektoniczny z dokumentacji

### 5.1 OrderStateMachine
Z `_docs/audits/core_dead_code_audit.md` (linia 76): `OrderStateMachine.php` — **AKTYWNY** w POS, online, courses, tables, settlement. Wdrożony jako centralny silnik stanów zamówienia, zastąpił hardcoded whitelisty statusów w endpointach.

### 5.2 Order Edit UI
Z `_docs/sessions/2026-08-20_order_edit_ui.md`: Modal edycji zamówień w `modules/hub/` wdrożony 2026-08-20. Backend `api/orders/edit.php` + `DeltaEngine` + `sh_orders.kitchen_delta` aktywne. KDS pokazuje blok "ZMIANY OD OSTATNIEGO WYDRUKU". **Nie ma dead code w tym obszarze** — wszystkie funkcje `HubOrderEdit.*` są aktywne.

### 5.3 Drift Rectification (2026-08-04)
Z `_docs/sessions/2026-08-04_drift_rectification_N1_N5.md`: 8 driftów naprawionych, w tym usunięto orphan `KdsTicketEngine` i sync `PapuClient` (zastąpiony async `PapuAdapter`). To explainuje dlaczego niektóre stare referencje mogą być leftover.

### 5.4 Core dead code audit (2026-07-29)
Z `_docs/audits/core_dead_code_audit.md`: Jedyna martwa funkcja w `core/` to `slicehubSyncMissingDriverFleetRows()` w `DriverFleetHelper.php`. Wszystkie 6 plików JS w `core/js/` aktywne. Redundantne: `AuthGuard.php` (vs `auth_guard.php`) i `PapuClient.php` (vs `PapuAdapter.php`) — ale aktywne.

---

## 6. Podsumowanie liczbowe

| Kategoria | Liczba wpisów |
|-----------|--------------|
| **[A] AKTYWNA** (false positive cross-file + celowy catch pattern) | 28 |
| **[B] PLANOWANA / STUB** | 9 |
| **[C] LEGACY / DEAD CODE** | 22 |
| Do weryfikacji (wymaga dalszego śledztwa) | 15 |
| **Razem** | **74** |

### Handlery zamówień — podsumowanie

| Moduł | Aktywne handlery | Stub | Dead |
|-------|-----------------|------|------|
| POS (`pos_app.js`, `pos_ui.js`) | 8 | 1 (`colIdx` noop) | 2 (`$$`, `now`) |
| Courses (`courses_app.js`, `courses_ui.js`) | 7 | 0 | 1 (`courses` param) |
| Tables (`tables_app.js`) | 4 | 2 (`handleFireCourse`, `handleMerge`) | 0 |
| Hub (`hub_order_edit.js`) | 4 | 0 | 0 |
| **Razem** | **23** | **3** | **3** |

---

## 7. Rekomendacje (przed modyfikacją kodu)

1. **Nie modyfikować [A]** — 28 wpisów to false positives lub celowe patterny. Modyfikacja grozi uszkodzeniem funkcjonalności.

2. **[B] STUB — oznaczyć, nie usuwać:**
   - `handleFireCourse` / `handleMerge` w `tables_app.js` — podpiąć do UI w statusach `occupied`/`preparing` lub oznaczyć `// TODO: P2 — podpiąć do action panel`
   - `vat` / `ksefCode` w `warehouse_core.js` — rozszerzyć `postAddItem` o te pola LUB oznaczyć `// TODO: przesyłać VAT i ksefCode do API`
   - `cfg` w `SharedSceneRenderer.js` — zaimplementować grid modyfikatorów LUB oznaczyć `// TODO: P3 — render mod grid`

3. **[C] LEGACY — bezpiecznie usunąć:**
   - `orderMarkers` / `driverMarkers` w `courses_map.js` — zastąpione przez `orderGroup`/`driverGroup`
   - `$$` w `pos_ui.js` i `waiter_app.js` — nigdy nie wywołane
   - `now` w `pos_app.js:1008` — leftover
   - `courses` param w `courses_ui.js:141` — usuń z sygnatury
   - `rowEl` param w `warehouse_pz.js:191` — usuń z sygnatury
   - `type` param w `pos_ui.js:612` — usuń z sygnatury
   - `orderId` param w `pos_ui.js:482` — usuń z sygnatury (lub zostaw dla API consistency)
   - `rot`, `uid` w `neon_pizza_engine.js:109` — usuń z sygnatury `shapeMeat` (ale zachować zgodność z `shapeFn(p.x, p.y, ..., p.rot, hash(...))` — lepiej zostawić dla spójności interfejsu shape functions)

4. **Ujednolicenie `onclick="window.X"` w HTML** — opcjonalne, dla spójności z refactorem `globalThis`. Nie wpływa na funkcjonalność.

---

## 8. Weryfikacja krzyżowa — duplikaty logiki i SSOT (2026-08-24, dodatek)

### 8.1 `handleFireCourse` / `handleMerge` — analiza duplikatów

**Pytanie:** Czy operacje "fire course" i "merge tables" istnieją już w innych modułach (POS, KDS, courses)?

**Wynik:** **Brak duplikatów.** Operacje są unikalne dla modułu Tables:

| Operacja | Moduł Tables | POS | KDS | Courses | Inne |
|----------|-------------|-----|-----|---------|------|
| `fire_course` | `tables_api.js:104` → `api/tables/engine.php:446` | ✗ (POS `accept_order` tworzy bilety KDS przez `KdsAcceptRouting` — to inna operacja) | ✗ | ✗ | ✗ |
| `merge_tables` | `tables_api.js:124` → `api/tables/engine.php:170` | ✗ | ✗ | ✗ | ✗ |

**Kluczowe rozróżnienie `fire_course` vs POS `accept_order`:**
- **POS `accept_order`** (`api/pos/engine.php:1492`) — akceptacja zamówienia → `OrderStateMachine::transitionOrder('accepted')` + `KdsAcceptRouting::createTicketsForAcceptedOrder()` — **wszystkie linie idą na KDS naraz**
- **Tables `fire_course`** (`api/tables/engine.php:446`) — ustawia `fired_at` na liniach konkretnej zmiany (course_number) → `OrderStateMachine::transitionOrder('preparing')` + `OrderEventPublisher::publishOrderLifecycle('order.preparing')` — **kolejna zmiana (przystawki → danie główne → deser) idzie na KDS**

To **komplementarne operacje**, nie duplikaty. `fire_course` obsługuje wieloetapowe serwowanie (multi-course dining), `accept_order` obsługuje jednorazową akceptację.

### 8.2 Weryfikacja endpointów backendowych

| Endpoint | Plik:Linia | Payload JS | Payload PHP | Zgodność DB |
|----------|-----------|------------|-------------|-------------|
| `fire_course` | `api/tables/engine.php:446` | `{order_id, course_number}` | `inputStr('order_id')`, `(int)$input['course_number']` | `UPDATE sh_order_lines SET fired_at = :now WHERE order_id = :oid AND course_number = :cn` ✅ |
| `merge_tables` | `api/tables/engine.php:170` | `{table_id_1, table_id_2, consolidate_orders}` | `(int)$input['table_id_1']`, `(int)$input['table_id_2']`, `(bool)$input['consolidate_orders']` | `UPDATE sh_tables SET parent_table_id = :pid` + `UPDATE sh_orders SET table_id = :pid` ✅ |

**Uwaga:** `tables_api.js` wysyła `mergeTables(parentId, childId, consolidate)` → `_engine('merge_tables', { table_id_1: parentId, table_id_2: childId, consolidate_orders: consolidate })`. Payload zgodny z backendem.

**Integracja SSOT:** Oba endpointy używają `OrderStateMachine` i `OrderEventPublisher::publishOrderLifecycle` — pełna integracja z transactional outbox.

**Dane dla UI:** `get_floor_status` zwraca `next_unfired_course` per stolik (linia 701) — UI ma dane do podpięcia `handleFireCourse`.

### 8.3 Weryfikacja 15 pozycji "do dalszej weryfikacji"

| # | Zmienna | Plik:Linia | Kontekst | Kategoria | Uzasadnienie |
|---|---------|-----------|----------|-----------|--------------|
| 1 | `r` | `settings_app.js:1621` | `const r = await callApi('fiscal_test_print')` — `r` nie sprawdzone (`r.success` ignorowane) | **[C] MARTWY** | Powinno sprawdzać `r.success` — potencjalny bug: sukces toast pokazywany nawet przy błędzie API |
| 2 | `err` | `studio_ui.js:572` | `catch (err)` — hardcoded "Błąd sieci" | **[A] AKTYWNA** | Celowy pattern (jak `e` w innych catch blocks) |
| 3 | `error` | `studio_modifiers.js:1417` | `catch (error)` — hardcoded "Błąd połączenia" | **[A] AKTYWNA** | Celowy pattern |
| 4 | `s` | `hr_app.js:262` | `steps.map((s, i) => ...)` — `s` nie używane, tylko `i` | **[C] MARTWY** | Iterator nieużywany → `(_, i)` |
| 5 | `lblCls` | `hr_app.js:265` | `const lblCls = i < idx ? '' : ''` — zawsze pusty string | **[C] MARTWY** | Obie gałęzie ternary zwracają `''` — można usunąć |
| 6 | `s` | `hr_app.js:271` | `steps.map((s, i) => ...)` — `s` nie używane | **[C] MARTWY** | Iterator nieużywany → `(_, i)` |
| 7 | `e` | `hr_app.js:718` | `catch (e) { /* banner ustawi refreshList */ }` | **[A] AKTYWNA** | Celowy pattern z komentarzem |
| 8 | `root` | `online_ui.js:66` | `renderLoading(root, show, text)` — `root` nie używane (hardcoded `getElementById`) | **[C] MARTWY** | Leftover param — wszystkie wywołania przekazują element który jest ignorowany |
| 9 | `data` | `online_ui.js:688` | `wireSurfaceCard(el, data, ctx, handlers)` — `data` nie używane | **[C] MARTWY** | Leftover param |
| 10 | `doorway` | `online_doorway.js:182` | `setupMapModal(doorway, contact, brandName)` — `doorway` nie używane | **[C] MARTWY** | Leftover param — używa `getElementById` zamiast parametru |
| 11 | `o` | `online_track.js:571` | `renderMap(gps, o, storeCoords)` — `o` nie używane | **[C] MARTWY** | Leftover param — funkcja używa tylko `gps` i `storeCoords` |
| 12 | `isPaid` | `driver_app.js:53` | `function isPaid(ps)` — zdefiniowana, nigdy nie wywołana w tym pliku | **[D] ZDUBELOWANA** | Identyczna funkcja w `courses_ui.js:25` (aktywna, 2 wywołania). Skopiowana jako szablon, ale driver_app używa innej logiki płatności. **SSOT: `courses_ui.js:25`** |
| 13 | `q` | `studio_recipe.js:376` | `const q = this._normalizeToken(namePart)` — `q` nie używane w pętli | **[C] MARTWY** | Leftover — `q` miało być użyte do fuzzy match, ale używa `namePart` bezpośrednio w `_fuzzyScore(namePart, entry)` |
| 14 | `i` | `tables_ui.js:78` | `tables.forEach((t, i) => ...)` — `i` nie używane | **[C] MARTWY** | Iterator nieużywany → `(t)` |
| 15 | `btn` | `notifications.js:694` | `addEventListener('click', async btn => ...)` — `btn` (event) nie używane | **[C] MARTWY** | Event handler param nieużywany → `async () =>` |
| — | `sel` | `ViewportPanel.js:108` | `const sel = this._sel` — nie używane | **[C] MARTWY** | Leftover — miało być użyte w HUD info |
| — | `tenantId` | `deck-panel.js:8` | Odczyt z meta tag, nie używane | **[C] MARTWY** | Leftover — autoryzacja przez JWT (zawiera tenant_id) |

### 8.4 Zaktualizowane podsumowanie liczbowe

| Kategoria | Liczba (oryginalna) | Liczba (po weryfikacji) | Zmiana |
|-----------|---------------------|------------------------|--------|
| **[A] AKTYWNA** | 28 | 31 (+3: `err`, `error`, `e` hr_app) | +3 |
| **[B] STUB** | 9 | 9 | 0 |
| **[C] MARTWY** | 22 | 35 (+13 z 15 zweryfikowanych) | +13 |
| **[D] ZDUBELOWANA** | 0 | 1 (`isPaid` driver_app) | +1 |
| **Razem** | 74 | 76 (+`sel`, `tenantId` deck-panel dodane) | +2 |

### 8.5 Mapa SSOT — nadrzędne ścieżki kodu

| Logika | SSOT (kanoniczna) | Duplikat (do usunięcia) | Uwagi |
|--------|-------------------|------------------------|-------|
| `isPaid(payment_status)` | `courses_ui.js:25` (aktywna, 2 wywołania) | `driver_app.js:53` (martwa) | Identyczna implementacja `['cash','card','online_paid'].includes(ps)` |
| Tenant identification | JWT token (zawiera `tenant_id`) | `deck-panel.js:8`, `pos_hr_clock.js:5` (odczyt z meta tag, nie używane) | Backend rozwiązuje tenant z JWT, nie z parametru |
| `fire_course` operacja | `api/tables/engine.php:446` (jedyna implementacja) | Brak duplikatu | `handleFireCourse` w `tables_app.js:313` — stub UI, backend gotowy |
| `merge_tables` operacja | `api/tables/engine.php:170` (jedyna implementacja) | Brak duplikatu | `handleMerge` w `tables_app.js:322` — stub UI, backend gotowy |
| Order state transitions | `core/OrderStateMachine.php` (SSOT) | Brak duplikatu — wszystkie endpointy (POS, tables, courses, online) delegują do `OrderStateMachine::transitionOrder()` | Zgodnie z drift rectification 2026-08-04 |
| Order lifecycle events | `core/OrderEventPublisher.php` (SSOT) | Brak duplikatu — wszystkie mutacje `sh_orders` publikują przez outbox | Zgodnie z drift rectification 2026-08-04 |

### 8.6 Rekomendacje zaktualizowane

**[D] ZDUBELOWANA — usunąć duplikat:**
- `isPaid` w `driver_app.js:53` — usunąć (SSOT: `courses_ui.js:25`). Jeśli driver_app potrzebuje `isPaid`, zaimportować z courses_ui lub utworzyć shared util.

**[C] MARTWY — bezpiecznie usunąć (nowe pozycje z weryfikacji):**
- `r` w `settings_app.js:1621` — **UWAGA: potencjalny bug** — dodać sprawdzenie `r.success` przed toastem sukcesu
- `s`, `lblCls` w `hr_app.js:262,265,271` — iteratory i pusty ternary
- `root` w `online_ui.js:66` — usuń z sygnatury `renderLoading`
- `data` w `online_ui.js:688` — usuń z sygnatury `wireSurfaceCard`
- `doorway` w `online_doorway.js:182` — usuń z sygnatury `setupMapModal`
- `o` w `online_track.js:571` — usuń z sygnatury `renderMap`
- `q` w `studio_recipe.js:376` — usuń (lub użyć do fuzzy match — potencjalna poprawka jakości)
- `i` w `tables_ui.js:78` — usuń iterator
- `btn` w `notifications.js:694` — usuń param event handler
- `sel` w `ViewportPanel.js:108` — usuń
- `tenantId` w `deck-panel.js:8` — usuń (JWT zawiera tenant_id)

**[B] STUB — rekomendacja wdrożenia:**
- `handleFireCourse` / `handleMerge` — **backend gotowy, UI do podpięcia**. `get_floor_status` zwraca `next_unfired_course`. Rekomendacja: dodać przyciski w action panel dla statusów `occupied`/`preparing` (np. "Wyślij następną zmianę" i "Połącz stoliki").

---

* Raport zaktualizowany o sekcję 8 (weryfikacja krzyżowa). Czeka na akceptację przed wykonaniem zmian.
