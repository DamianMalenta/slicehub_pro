# Prompt: Wdrożenie promised_time & SLA — Fazy A→E

## Kontekst startowy

Jesteś programistą pracującym nad SliceHub Enterprise OS — multi-tenant system gastronomiczny (PHP 8.3 bez frameworka, Vanilla JS, MariaDB 10.11, Tailwind CDN). Zero zewnętrznych zależności (brak Composer, brak npm w runtime).

### Zanim tkniesz kod — przeczytaj:
1. `_docs/01_KONSTYTUCJA.md` — 10 praw architektonicznych (szczególnie Prawo VI: Snajper, Prawo VIII: Domknięcie Kontraktu, Prawo IV: Zero Zaufania)
2. `_docs/sessions/2026-07-29_promised_time_sla_audit_and_plan.md` — pełny audyt i plan
3. `AGENTS.md` — konwencje kodowe, autentykacja, demo accounts

### Stan obecny (zweryfikowane 2026-07-29):

**Kod kompletny ale NIE wpięty (6 orphan-ów):**
- `core/PromisedTimeEngine.php` — pełny silnik estymacji promised_time (load factor, channel buffers, business hours). Zero call-site'ów.
- `api/orders/estimate.php` — wrapper HTTP na PromisedTimeEngine. Zero call-site'ów.
- `api/orders/sla_monitor.php` — SLA breach monitoring API (4-tier klasyfikacja, zapis do sh_sla_breaches). Zero call-site'ów frontend.
- `scripts/worker_sla_monitor.php` — CLI cron worker (mirror sla_monitor.php). Cron nie ustawiony.
- `api/courses/engine.php#get_sla_breaches` — backend akcja czytająca sh_sla_breaches. Zero call-site'ów frontend.
- `api/orders/edit.php` + `api/orders/DeltaEngine.php` — edycja zamówienia z kitchen delta. Zero call-site'ów.
- `api/reports/food_cost.php` + `core/FoodCostEngine.php` — food cost report. Zero call-site'ów.

**5 różnych progów SLA w frontendach (wszystkie hardcoded):**
- POS: delivery <15/≤59, non-delivery <6/≤14
- KDS: <0/≤5
- Dispatcher: <0/≤5
- Driver App: <0/≤5
- SLA Monitor API: czyta z sh_tenant_settings (sla_green_min, sla_yellow_min)

**5 różnych logik nadawania promised_time (żadna nie używa PromisedTimeEngine):**
- POS: ręczny input kasjera lub now()
- Online storefront: surowy requested_time lub null
- Gateway intake: now + minPrepMinutes (uproszczone)
- ChoiceQR webhook: hardcoded +30 min
- Estimate API: PromisedTimeEngine (orphan)

---

## Fazy wdrożenia (kolejność A → C → B → D → E)

### FAZA A — Ujednolicenie progów SLA (HIGH, ~2h)

**Cel:** Wszystkie frontendy czytają progi SLA z `sh_tenant_settings` zamiast hardcoded wartości.

**Pliki do zmiany:**

**Backend (dodać progi do response):**
1. `api/courses/engine.php` — w `get_dashboard` i `get_driver_runs`: dodać SELECT `sla_green_min`, `sla_yellow_min` z `sh_tenant_settings` WHERE `tenant_id = :tid`, zwrócić w response jako `sla_thresholds: {green_min: N, yellow_min: N}`
2. `api/kds/engine.php` — w akcji zwracającej listę zamówień: dodać j.w.
3. `api/pos/engine.php` — w akcji zwracającej listę zamówień Kanban: dodać j.w.

**Frontend (czytać progi z response zamiast hardcoded):**
4. `modules/courses/js/courses_ui.js` — `slaClass(promisedTime)`: zamiast `if (diff <= 5)` użyć `state.slaThresholds.yellow_min`. `slaText()` bez zmian.
5. `modules/driver_app/js/driver_app.js` — `slaClass()`: j.w. — czytać z response `get_driver_runs`.
6. `modules/kds/js/kds_app.js` — `_timerInfo(promisedTime)`: zamiast `if (diff <= 5)` użyć progów z response.
7. `modules/pos/js/pos_ui.js` — `fmtTime()`: zachować per-type różnicę (delivery vs non-delivery) ale bazowy próg z `sh_tenant_settings`. Delivery = green_min × 3, non-delivery = green_min × 1 (albo dedykowane ustawienia).

**Default values (jeśli brak w sh_tenant_settings):** green_min=10, yellow_min=5 (zgodne z obecnym sla_monitor.php).

**Test E2E:** Zmienić `sla_yellow_min` na 10 w `sh_tenant_settings` → wszystkie frontendy pokazują żółty przy ≤10 min.

**Lint:** `php -l` na 3 backend plikach, `node -c` na 4 frontend plikach.

---

### FAZA C — SLA breach w Dispatcher + cron (MEDIUM, ~1.5h)

**Cel:** Dispatcher pokazuje panel z breachami, cron zapisuje breache do bazy.

**Pliki do zmiany:**

**Frontend (nowy panel w Dispatcher):**
1. `modules/courses/js/courses_api.js` — dodać `getSlaBreaches()`: fetch `POST api/courses/engine.php` z `action=get_sla_breaches`, zwraca listę breachy (order_number, customer_name, delivery_address, breach_minutes, driver_name, logged_at)
2. `modules/courses/js/courses_app.js` — polling co 30s: wywołanie `getSlaBreaches()`, cache w `state.slaBreaches`
3. `modules/courses/js/courses_ui.js` — nowa funkcja `renderSlaBreachesPanel(breaches)`: renderuje listę w kontenerze, sortowana wg breach_minutes desc, z linkiem do zamówienia
4. `modules/courses/index.html` — dodać `<div id="sla-breaches-panel">` w sidebar lub pod listą zamówień

**Cron:**
5. Instrukcja: dodać do crontab `* * * * * php /workspace/scripts/worker_sla_monitor.php` (co 1 min) lub `*/2 * * * *` (co 2 min)

**Konstytucja Prawo VIII:**
6. Oznaczyć `api/orders/sla_monitor.php` jako **DOMKNIĘTE** w `_docs/01_KONSTYTUCJA.md` (breach logging wpięte: cron + frontend)

**Test E2E:** Utworzyć zamówienie delivery z `promised_time` w przeszłości → uruchomić `php scripts/worker_sla_monitor.php` → odświeżyć Dispatcher → breach widoczny w panelu.

**Lint:** `node -c` na 3 JS plikach.

---

### FAZA B — Wpięcie PromisedTimeEngine (MEDIUM, ~4h)

**Cel:** 4 produkcyjne ścieżki używają PromisedTimeEngine zamiast uproszczonych logik. POS pozostaje ręczny gdy kasjer poda czas.

**Pliki do zmiany:**

1. `api/online/engine.php` — w `guest_checkout` (linia ~1380): gdy `requested_time` pusty lub ASAP → wywołać `PromisedTimeEngine::calculate($pdo, $tenantId, 'asap', $channel)` zamiast zapisu `null`. Gdy scheduled → zostawić jak jest (surowy requested_time).
2. `api/gateway/intake.php` (linia ~364): zastąpić `$promisedTime = $now->modify("+{$minPrepMinutes} minutes")` wywołaniem `PromisedTimeEngine::calculate($pdo, $tenantId, 'asap', $channel)`. Scheduled path zostaje ale z walidacją z PromisedTimeEngine (lead time + business hours).
3. `api/integrations/choiceqr/webhook.php` (linia ~207-209): zastąpić hardcoded `+30 minutes` wywołaniem `PromisedTimeEngine::calculate($pdo, $tenantId, 'asap', 'delivery')`.
4. `api/pos/engine.php` — w `accept_order` (linia ~1198): gdy kasjer nie poda `custom_time`, użyć `PromisedTimeEngine::calculate()` jako default zamiast `now()`.
5. `api/orders/estimate.php` — oznaczyć jako **DOMKNIĘTE** w Konstytucji (jeśli storefront zacznie go wywoływać w scheduled-order picker — opcjonalne).

**Uwaga:** `process_order` w POS pozostaje ręczny (kasjer wpisuje czas). PromisedTimeEngine używany tylko gdy kasjer nie poda czasu.

**Test E2E:**
- Złożyć zamówienie online ASAP → `promised_time` = `now + base_prep × load + channel_buffer` (nie `null`)
- Złożyć zamówienie przez gateway ASAP → `promised_time` z load factor (nie `now + minPrepMinutes`)
- Złożyć zamówienie ChoiceQR ASAP → `promised_time` z PromisedTimeEngine (nie hardcoded +30)

**Lint:** `php -l` na 4 backend plikach.

---

### FAZA D — Wpięcie food_cost.php (LOW, ~2h)

**Cel:** Food Cost Report dostępny w panelu backoffice.

**Pliki do zmiany:**

1. `modules/backoffice/` — dodać widok "Food Cost Report" (nowy plik HTML lub tab w istniejącym module)
2. JS — wywołanie `GET api/reports/food_cost.php?item_sku=...&warehouse_id=...`
3. Render: koszt teoretyczny (AVCO), składniki, modyfikatory, marża per kanał
4. `_docs/01_KONSTYTUCJA.md` — oznaczyć `api/reports/food_cost.php` jako **DOMKNIĘTE**

**Test manualny:** Otworzyć raport dla dania z recepturą → zweryfikować AVCO koszt i marżę.

---

### FAZA E — Wpięcie edit.php + DeltaEngine (LOW, ~4h)

**Cel:** Edycja zamówienia z kitchen delta w KDS.

**Pliki do zmiany:**

1. `modules/backoffice/` lub `modules/pos/` — dodać modal "Edytuj zamówienie"
2. JS — wywołanie `POST api/orders/edit.php` z zmodyfikowanymi liniami
3. `modules/kds/js/kds_app.js` — czytanie `kitchen_delta` JSON z order, highlight zmienionych linii
4. `_docs/01_KONSTYTUCJA.md` — oznaczyć `api/orders/edit.php` jako **DOMKNIĘTE**

**Test E2E:** Edytować zamówienie (dodaj/usuń linię) → KDS pokazuje delta (zielony = dodane, czerwony = usunięte).

---

## Zasady wdrożenia

1. **Kolejność:** A → C → B → D → E (A i C najszybsze, największy efekt)
2. **Prawo VI (Snajper):** zmieniasz tylko funkcje dotknięte fazą, nie dotykasz innych w tym samym pliku
3. **Prawo VIII (Domknięcie Kontraktu):** po wpięciu oznacz jako DOMKNIĘTE w Konstytucji + test E2E
4. **Prawo X (Audyt Sesji):** commit z `Test (E2E):` w message + plik w `_docs/sessions/`
5. **Każde SQL:** `tenant_id = :tid` w WHERE
6. **Lint po każdej fazie:** `php -l` na backend, `node -c` na frontend
7. **Testy:** uruchom `http://localhost/slicehub/tests/test_runner.html` (62 testy should pass) lub headless `node /workspace/scripts/run_test_runner_headless.cjs`

## Serwisy lokalne (Windows/XAMPP)

```bash
# Start MariaDB
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld && mysqld_safe &
sleep 2
# Start Apache
apachectl start
```

URL: `http://localhost/slicehub/`
Demo login: waiter PIN 1111 (tenant 1)

## Pliki referencyjne

- Pełny audyt: `_docs/sessions/2026-07-29_promised_time_sla_audit_and_plan.md`
- Konstytucja: `_docs/01_KONSTYTUCJA.md` (Prawo VIII — lista @planned)
- Architektura: `_docs/02_ARCHITEKTURA.md` (tabela Orders, Reports)
- PromisedTimeEngine: `core/PromisedTimeEngine.php` (196 linii, kompletny)
- SLA Monitor: `api/orders/sla_monitor.php` (181 linii, kompletny)
- Cron worker: `scripts/worker_sla_monitor.php` (kompletny)
- get_sla_breaches: `api/courses/engine.php:1641` (kompletna akcja)
