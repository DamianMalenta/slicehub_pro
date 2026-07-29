# Sesja: Promised Time & SLA — audyt, plan naprawczo/wdrożeniowy

**Data:** 2026-07-29
**Cel:** Pełna analiza obiegu `promised_time` w systemie, identyfikacja niespójności, plan naprawczy, prompt na głęboki audyt.

---

## 1. Cel

Zrozumienie end-to-end przepływu `promised_time`: nadawanie, odliczanie (countdown), przechwytywanie (breach logging), przesunięcia (panic mode). Identyfikacja wszystkich plików dotkniętych, niespójności progów SLA, orphan-ów, i luk w Konstytucji (Prawo VIII).

## 2. Pliki dotknięte (audyt)

### Nadawanie `promised_time` (6 ścieżek wejścia)

| # | Plik | Logika | PromisedTimeEngine? |
|---|---|---|---|
| 1 | `api/pos/engine.php:738` (process_order) | Ręczny input kasjera lub `now()` | Nie |
| 2 | `api/pos/engine.php:1198` (accept_order) | Ręczny `custom_time` lub `now()` | Nie |
| 3 | `api/online/engine.php:1380` (guest_checkout) | Surowy `requested_time` lub `null` | Nie |
| 4 | `api/gateway/intake.php:364` (gateway) | ASAP = `now + minPrepMinutes`, scheduled = walidowany | Nie (uproszczona) |
| 5 | `api/integrations/choiceqr/webhook.php:194-209` | `order.delivery.when` lub `now + 30` (hardcoded) | Nie |
| 6 | `api/orders/estimate.php:69` (ORPHAN) | Pełna kalkulacja | **Tak** (ale 0 call-site'ów) |

### Odliczanie / SLA badges (5 frontendów, 5 różnych progów)

| # | Plik | Czerwony | Żółty | Zielony | Źródło progów |
|---|---|---|---|---|---|
| 1 | `modules/pos/js/pos_ui.js:525-27` | delivery <15, inne <6 | delivery ≤59, inne ≤14 | > prog | Hardcoded (per type) |
| 2 | `modules/kds/js/kds_app.js:85-87` | < 0 | ≤ 5 | > 5 | Hardcoded |
| 3 | `modules/courses/js/courses_ui.js:19-21` | < 0 | ≤ 5 | > 5 | Hardcoded |
| 4 | `modules/driver_app/js/driver_app.js:60-63` | < 0 | ≤ 5 | > 5 | Hardcoded |
| 5 | `api/orders/sla_monitor.php:93-100` | ≤ 0 | ≤ yellow | > green | `sh_tenant_settings` |

### Przechwytywanie / breach logging

| # | Plik | Status |
|---|---|---|
| 1 | `api/orders/sla_monitor.php` | 🟡 PARTIAL — API istnieje, frontend nie wywołuje |
| 2 | `scripts/worker_sla_monitor.php` | ✅ Kod gotowy, cron nie ustawiony |
| 3 | `api/courses/engine.php:1641` (get_sla_breaches) | 🟡 Backend akcja, frontend nie wywołuje |
| 4 | `core/Notifications/NotificationDispatcher.php:337-343` | ✅ ETA w SMS/email (clamped do 0) |
| 5 | `core/Notifications/SmartReplyEngine.php:199-224` | ✅ Auto-reply SMS z ETA |
| 6 | `modules/online/js/online_track.js:394-416` | ✅ Server-anchored countdown (najlepszy) |

### Przesunięcie (panic)

| # | Plik | Status |
|---|---|---|
| 1 | `core/PanicEngine.php` | ✅ Wpięte w `pos/engine.php#panic_mode` |
| 2 | `api/online/engine.php:1743-1750` | ✅ ETA w trackerze klienta |

### Integracje (outbound `promised_time`)

| # | Plik | Status |
|---|---|---|
| 1 | `core/Integrations/PapuAdapter.php:172` | ✅ Przekazuje `promised_time` w payload |
| 2 | `core/Integrations/GastroSoftAdapter.php:150` | ✅ Przekazuje `promised_time` w payload |
| 3 | `core/OrderEventPublisher.php:196` | ✅ Snapshot order zawiera `promised_time` |
| 4 | `core/Notifications/TemplateRenderer.php:24` | ✅ `{{promised_time}}` w szablonach SMS/email |

## 3. Decyzje architektoniczne

### Zaktualizowano dokumentację:
- `_docs/01_KONSTYTUCJA.md` Prawo VIII — rozbicie zbiorczej linii na 5 osobnych wpisów, dodanie `core/PromisedTimeEngine.php`, `api/reports/food_cost.php`, zmiana statusu `sla_monitor.php` na PARTIAL
- `_docs/02_ARCHITEKTURA.md` — aktualizacja tabel Orders i Reports o precyzyjne statusy

### Zidentyfikowane niespójności (do naprawy):

1. **5 różnych progów SLA w frontendach** — hardcoded, ignorują `sh_tenant_settings`
2. **`PromisedTimeEngine` kompletnie nieużywany** — 5 produkcyjnych ścieżek ma własne uproszczone logiki
3. **Online storefront** zapisuje `null` dla ASAP — bez estymacji
4. **ChoiceQR webhook** hardcoded +30 min — ignoruje `base_prep_minutes`
5. **`get_sla_breaches`** — backend akcja bez konsumenta frontend
6. **`worker_sla_monitor.php`** — cron nie ustawiony na produkcji

## 4. Otwarte pytania (do rozstrzygnięcia w kolejnych sesjach)

1. Czy ujednolicić progi SLA we wszystkich frontendach do wartości z `sh_tenant_settings`?
2. Czy wpiąć `PromisedTimeEngine` w POS / online / gateway / ChoiceQR (zastępując uproszczone logiki)?
3. Czy Dispatcher ma wywoływać `get_sla_breaches` i pokazywać panel breachy?
4. Czy online storefront ma używać `PromisedTimeEngine` dla ASAP estymacji zamiast zapisywać `null`?
5. Kiedy ustawić cron `worker_sla_monitor.php` na produkcji?

---

## 5. Plan naprawczo/wdrożeniowy

### Faza A — Ujednolicenie progów SLA ( Priority: HIGH, Effort: ~2h )

**Problem:** 5 frontendów ma hardcoded progi, ignorują `sh_tenant_settings`.

**Rozwiązanie:** Frontendy czytają progi z API (już zwracane w `get_dashboard` / `get_driver_runs` jeśli dodamy).

**Kroki:**
1. `api/courses/engine.php#get_dashboard` — dodać `sla_green_min`, `sla_yellow_min` z `sh_tenant_settings` do response
2. `api/courses/engine.php#get_driver_runs` — j.w.
3. `api/kds/engine.php` — j.w. (dodać do response listy zamówień)
4. `api/pos/engine.php` — j.w. (dodać do response listy zamówień w Kanban)
5. `modules/courses/js/courses_ui.js` — `slaClass()` czyta progi z response zamiast hardcoded 5
6. `modules/driver_app/js/driver_app.js` — j.w.
7. `modules/kds/js/kds_app.js` — `_timerInfo()` czyta progi z response
8. `modules/pos/js/pos_ui.js` — `fmtTime()` czyta progi z response, zachować per-type różnicę (delivery vs non-delivery) ale mnożnik z bazy

**Testy:** E2E — zmienić `sla_yellow_min` w `sh_tenant_settings` na 10, zweryfikować że wszystkie frontendy pokazują żółty przy ≤10 min.

### Faza B — Wpięcie PromisedTimeEngine ( Priority: MEDIUM, Effort: ~4h )

**Problem:** Kompletny silnik istnieje ale nieużywany. 5 ścieżek ma uproszczone logiki.

**Rozwiązanie:** Wpiąć `PromisedTimeEngine::calculate()` w 4 produkcyjne ścieżki (POS pozostaje ręczny — kasjer decyduje).

**Kroki:**
1. `api/online/engine.php#guest_checkout` — gdy `requested_time` pusty lub ASAP → `PromisedTimeEngine::calculate($pdo, $tenantId, 'asap', $channel)` zamiast zapisu `null`
2. `api/gateway/intake.php:363-364` — zastąpić uproszczoną logikę `now + minPrepMinutes` wywołaniem `PromisedTimeEngine::calculate()` (zachowa lead time + business hours + load factor)
3. `api/integrations/choiceqr/webhook.php:207-209` — zastąpić hardcoded `+30 min` wywołaniem `PromisedTimeEngine::calculate()` (z channel='delivery')
4. `api/pos/engine.php#accept_order` — gdy kasjer nie poda `custom_time`, użyć `PromisedTimeEngine::calculate()` jako default zamiast `now()`
5. `api/orders/estimate.php` — oznaczyć jako **DOMKNIĘTE** w Konstytucji gdy Faza B gotowa (endpoint będzie wywoływany ze storefront scheduled-order picker)

**Uwaga:** `process_order` w POS pozostaje ręczny (kasjer wpisuje czas). `PromisedTimeEngine` używany tylko gdy kasjer nie poda czasu.

**Testy:** E2E — złożyć zamówienie online ASAP, zweryfikować że `promised_time` = `now + base_prep × load + channel_buffer` (nie `null`, nie `now + minPrepMinutes`).

### Faza C — Wpięcie SLA breach w Dispatcher ( Priority: MEDIUM, Effort: ~1.5h )

**Problem:** `get_sla_breaches` istnieje w backendzie ale frontend nie wywołuje. Cron nie ustawiony.

**Kroki:**
1. `modules/courses/js/courses_api.js` — dodać `getSlaBreaches()` (fetch `action=get_sla_breaches`)
2. `modules/courses/js/courses_app.js` — polling co 30s, wywołanie `getSlaBreaches()`, render w panelu Dispatcher
3. `modules/courses/js/courses_ui.js` — render breach panel (lista z zamówieniem, kierowcą, min spóźnienia)
4. `modules/courses/index.html` — dodanie kontenera `#sla-breaches-panel`
5. Cron — dodać `worker_sla_monitor.php` do crontab (co 1-2 min)
6. `api/orders/sla_monitor.php` — oznaczyć jako **DOMKNIĘTE** w Konstytucji (breach logging wpięte: cron + frontend)

**Testy:** E2E — utworzyć zamówienie z `promised_time` w przeszłości, uruchomić `worker_sla_monitor.php`, odświeżyć Dispatcher — breach widoczny w panelu.

### Faza D — Wpięcie food_cost.php ( Priority: LOW, Effort: ~2h )

**Problem:** `api/reports/food_cost.php` orphan, `FoodCostEngine` kompletny.

**Kroki:**
1. `modules/backoffice/` lub `modules/studio/` — dodanie widoku "Food Cost Report" (per-item breakdown)
2. Wywołanie `api/reports/food_cost.php?item_sku=...`
3. Render: koszt teoretyczny, składniki, modyfikatory, marża per kanał
4. Oznaczyć jako **DOMKNIĘTE** w Konstytucji

**Testy:** Manual — otworzyć raport dla dania z recepturą, zweryfikować AVCO koszt i marżę.

### Faza E — Wpięcie edit.php + DeltaEngine ( Priority: LOW, Effort: ~4h )

**Problem:** `api/orders/edit.php` orphan, `DeltaEngine` kompletny.

**Kroki:**
1. `modules/backoffice/` lub `modules/pos/` — dodanie modala "Edytuj zamówienie"
2. Wywołanie `api/orders/edit.php` z zmodyfikowanymi liniami
3. KDS — czytanie `kitchen_delta` JSON, highlight zmienionych linii
4. Oznaczyć jako **DOMKNIĘTE** w Konstytucji

**Testy:** E2E — edytować zamówienie (dodaj/usuń linię), zweryfikować KDS pokazuje delta.

### Kolejność: A → C → B → D → E

**Uzasadnienie:** Faza A (ujednolicenie progów) jest najszybsza i daje natychmiastowy efekt wizualny. Faza C (SLA breach w Dispatcher) wykorzystuje już istniejący backend. Faza B (PromisedTimeEngine) wymaga zmian w 4 plikach API + testów. Fazy D i E są niezależne, niższy priorytet.

---

## 6. Prompt na głęboki audyt sprawdzający

```
Jesteś audytorem architektonicznym systemu SliceHub Enterprise. Twoim zadaniem jest głęboki audyt obiegu promised_time i SLA monitoring w całym kodzie.

## Kontekst

SliceHub to multi-tenant system gastronomiczny (PHP 8.3, Vanilla JS, MariaDB). Konstytucja systemu (_docs/01_KONSTYTUCJA.md) definiuje 10 praw architektonicznych, w tym Prawo VIII (Domknięcie Kontraktu — funkcje bez call-site muszą mieć @planned) i Prawo VI (Snajper — każde SQL musi mieć tenant_id).

## Zakres audytu

Przejdź przez WSZYSTKIE pliki w poniższych kategoriach i zweryfikuj:

### 1. Nadawanie promised_time
Dla KAŻDEGO pliku który INSERTuje lub UPDATE'uje sh_orders.promised_time:
- Jaka logika nadaje wartość? (ręczna, ASAP estymacja, scheduled walidacja)
- Czy używa PromisedTimeEngine? Jeśli nie — dlaczego?
- Czy waliduje lead time i business hours?
- Czy uwzględnia load factor (obciążenie kuchni)?
- Czy uwzględnia channel buffer (dine_in=0, takeaway=5, delivery=15)?
- Czy timezone jest explicit (Europe/Warsaw)?
- Czy tenant_id jest w WHERE/INSERT?

Pliki do sprawdzenia:
- api/pos/engine.php (process_order, accept_order)
- api/online/engine.php (guest_checkout)
- api/gateway/intake.php
- api/integrations/choiceqr/webhook.php
- api/orders/estimate.php
- core/PromisedTimeEngine.php
- scripts/seed_demo_all.php
- scripts/seed_final_test.php
- scripts/seed_ultimate_delivery.php
- scripts/seed_pizzaforno.sql

### 2. Odliczanie / SLA badges
Dla KAŻDEGO pliku który oblicza diff między promised_time a now:
- Jakie progi są używane? (hardcoded vs sh_tenant_settings)
- Czy rozróżnia order_type (delivery vs non-delivery)?
- Czy diff może być ujemne (spóźnione)? Jak jest wyświetlane?
- Czy używa czasu serwera czy klienta (new Date())?
- Czy jest synchronizacja z serwerem (server-anchored)?

Pliki do sprawdzenia:
- modules/pos/js/pos_ui.js (fmtTime)
- modules/kds/js/kds_app.js (_timerInfo)
- modules/courses/js/courses_ui.js (slaClass, slaText)
- modules/driver_app/js/driver_app.js (slaClass, slaText)
- modules/online/js/online_track.js (ETA countdown)
- api/orders/sla_monitor.php (4-tier klasyfikacja)
- api/online/engine.php (etaSeconds w track_order)
- core/Notifications/NotificationDispatcher.php (ETA w SMS)
- core/Notifications/SmartReplyEngine.php (getOrderEta)

### 3. Przechwytywanie / breach logging
Dla KAŻDEGO pliku który czyta lub zapisuje sh_sla_breaches:
- Czy używa UPSERT (ON DUPLICATE KEY UPDATE)?
- Czy breach_minutes jest dynamiczne (odświeżane) czy zamrożone na pierwszym wykryciu?
- Czy tenant_id jest w WHERE?
- Czy loguje driver_id i course_id?
- Kto wywołuje ten kod? (frontend, cron, API)

Pliki do sprawdzenia:
- api/orders/sla_monitor.php
- scripts/worker_sla_monitor.php
- api/courses/engine.php (get_sla_breaches)
- database/migrations/001_init_slicehub_pro_v2.sql (definicja sh_sla_breaches)

### 4. Przesunięcie (panic mode)
- Czy PanicEngine przesuwa WSZYSTKIE aktywne statusy?
- Czy debounce guard działa (2 min cooldown)?
- Czy COALESCE(promised_time, created_at) jest bezpieczne?
- Czy audit log (sh_panic_log) jest zapisywany?
- Czy tenant_id jest w WHERE?

Pliki do sprawdzenia:
- core/PanicEngine.php
- api/pos/engine.php (panic_mode action)

### 5. Integracje (outbound)
- Czy promised_time jest przekazywane w payload do adapterów?
- Czy timezone jest konwertowany dla zewnętrznych API?
- Czy ChoiceQR order.delivery.when jest poprawnie mapowane?

Pliki do sprawdzenia:
- core/Integrations/PapuAdapter.php
- core/Integrations/GastroSoftAdapter.php
- core/Integrations/ChoiceQRAdapter.php
- core/Integrations/PapuClient.php
- core/OrderEventPublisher.php (snapshotOrder)
- core/Notifications/TemplateRenderer.php

### 6. Konstytucja Prawo VIII
Dla KAŻDEGO pliku na liście @planned w _docs/01_KONSTYTUCJA.md:
- Czy plik fizycznie istnieje?
- Czy ma komentarz STATUS: PLANNED w nagłówku?
- Czy ma przynajmniej jeden call-site w api/ lub core/?
- Czy ma test (manualny lub automatyczny)?
- Czy dokumentacja (02_ARCHITEKTURA.md) jest spójna z rzeczywistością?

Pliki do sprawdzenia:
- api/orders/edit.php
- api/orders/estimate.php
- api/orders/sla_monitor.php
- core/PromisedTimeEngine.php
- api/reports/food_cost.php
- api/orders/DeltaEngine.php
- core/FoodCostEngine.php

### 7. sh_tenant_settings — progi SLA
- Czy sla_green_min i sla_yellow_min istnieją w schemacie?
- Czy są czytane przez api/orders/sla_monitor.php?
- Czy są czytane przez jakikolwiek frontend?
- Czy są ustawiane w panelu Settings?
- Jaka jest wartość default?

Pliki do sprawdzenia:
- database/migrations/001_init_slicehub_pro_v2.sql
- api/orders/sla_monitor.php
- api/settings/engine.php
- modules/settings/js/notifications.js (lub inny plik Settings)

## Format raportu

Dla każdej z 7 kategorii wygeneruj tabelę:

| Plik | Linia | Co robi | Status | Niespójność | Priorytet naprawy |

Status: ✅ OK / ⚠️ NIESPÓJNOŚĆ / 🔴 BUG / 🟡 ORPHAN

Na końcu wygeneruj:
1. Listę wszystkich znalezionych bugów (z numerem linii)
2. Listę wszystkich orphan-ów (pliki bez call-site'ów)
3. Listę wszystkich niespójności (różne progi, różne logiki)
4. Rekomendacje naprawcze (uporządkowane wg priorytetu)
5. Aktualizację listy @planned w Konstytucji (co dodać, co usunąć, co zmienić status)

## Zasady
- Nie modyfikuj żadnego kodu — to audyt read-only
- Każdy plik musi być fizycznie przeczytany (nie polegaj na dokumentacji)
- Zawsze podawaj numer linii
- Jeśli plik nie istnieje — zaznacz explicite
- Jeśli funkcja jest opisana w docs ale nie ma call-site — to jest drift (Prawo VIII)
```

## 7. Otwarte pytania dla kolejnej sesji

1. Czy Faza A (ujednolicenie progów) ma zachować per-type różnicę w POS (delivery 15/59 vs non-delivery 6/14)?
2. Czy Faza B (PromisedTimeEngine w gateway) może złamać istniejące integracje (Papu, ChoiceQR) które oczekują uproszczonej estymacji?
3. Czy `api/orders/sla_monitor.php` można usunąć po wpięciu `worker_sla_monitor.php` + `get_sla_breaches`? Czy zachować jako HTTP endpoint dla zewnętrznych konsumentów?
4. Czy online storefront ma pokazywać estimated promised_time przed złożeniem zamówienia (pre-check via `estimate.php`)?
