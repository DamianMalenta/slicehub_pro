# Sesja: Faza A — Ujednolicenie progów SLA w frontendach

**Data:** 2026-07-29
**Powiązane:** `_docs/sessions/2026-07-29_promised_time_sla_audit_and_plan.md` (Faza A)
**Konstytucja:** Prawo VI (Snajper), Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)

---

## 1. Cel

Wszystkie frontendy SLA (POS, KDS, Dispatcher/courses, Driver App) czytają progi SLA
z `sh_tenant_settings` (`sla_green_min`, `sla_yellow_min`) zamiast hardcoded wartości.
Default 10/5 zgodny z `api/orders/sla_monitor.php` i schematem (`001_init_slicehub_pro_v2.sql:72-73`).

Dotychczas 5 różnych progów hardcoded:
- POS: delivery <15/≤59, non-delivery <6/≤14
- KDS: <0/≤5
- Dispatcher (courses): <0/≤5
- Driver App: <0/≤5
- SLA Monitor API: czytał z bazy (jedyny spójny)

---

## 2. Pliki dotknięte

### Backend (dodać `sla_thresholds` do response — Snajper: tylko sekcje response)

| Plik | Akcja | Zmiana |
|---|---|---|
| `api/courses/engine.php` | `get_dashboard` (linia ~264) | SELECT sla_green_min/sla_yellow_min z sh_tenant_settings WHERE tenant_id=:tid AND setting_key='', dodane `sla_thresholds` do response |
| `api/courses/engine.php` | `get_driver_runs` (linia ~1129) | j.w. — driver_app czyta z tego response |
| `api/kds/engine.php` | `get_board` (linia ~159) | j.w. — kds_app czyta z response |
| `api/pos/engine.php` | `get_orders` (linia ~562) | j.w. — pos_app czyta z response |

Każdy backend zwraca: `sla_thresholds: { green_min: N, yellow_min: N }` z default 10/5 gdy NULL.

### Frontend (czytać progi z response zamiast hardcoded)

| Plik | Funkcja | Zmiana |
|---|---|---|
| `modules/courses/js/courses_ui.js` | `slaClass()` (linia ~16) | `diff <= 5` → `diff <= _slaThresholds.yellow_min`; dodany module-level `_slaThresholds` + `setSlaThresholds()` |
| `modules/courses/js/courses_app.js` | `poll()` (linia ~122) | wywołuje `CoursesUI.setSlaThresholds(res.data.sla_thresholds)` |
| `modules/driver_app/js/driver_app.js` | `slaClass()` (linia ~59) | `diff <= 5` → `diff <= state.slaThresholds.yellow_min`; dodany `state.slaThresholds` |
| `modules/driver_app/js/driver_app.js` | `poll()` (linia ~208) | ustawia `state.slaThresholds` z `res.data.sla_thresholds` |
| `modules/kds/js/kds_app.js` | `_timerInfo()` (linia ~84) | `diff <= 5` → `diff <= _slaThresholds.yellow_min`; dodany module-level `_slaThresholds` |
| `modules/kds/js/kds_app.js` | `refresh()` (linia ~133) | ustawia `_slaThresholds` z `data.sla_thresholds` |
| `modules/pos/js/pos_ui.js` | `fmtTime()` (linia ~534) | **single-boundary**: red<0, yellow≤yellow_min, green>yellow_min (zamiast per-type 15/59 i 6/14); dodany module-level `_slaThresholds` + `setSlaThresholds()` |
| `modules/pos/js/pos_app.js` | `_fetchOrders()` (linia ~296) | wywołuje `PosUI.setSlaThresholds(res.data.sla_thresholds)` |

### Decyzja: POS single-boundary

Użytkownik wybrał opcję "POS zjednoczony (single-boundary)" — POS traci per-type różnicę
(delivery 15/59 → 5), zyskując spójność z courses/kds/driver. Progi z `sh_tenant_settings`.
Przy default `yellow_min=5` POS pokazuje żółty przy ≤5 min dla wszystkich typów (wcześniej:
delivery ≤59, non-delivery ≤14).

### Refactor DRY — SSOT: `core/SlaThresholds.php`

Po wdrożeniu Fazy A zauważono powielenie: 5 call-site'ów miało ten sam inline SELECT
(`sla_green_min`, `sla_yellow_min` z `sh_tenant_settings`). Zdecydowano o pełnym DRY
(decyzja użytkownika: "Wszystkie 5 (pełne DRY)").

**Nowy prymityw infrastrukturalny:** `core/SlaThresholds.php` — funkcja
`slicehubSlaThresholds(PDO, int): array` zwracająca `['green_min'=>N, 'yellow_min'=>N]`
(default 10/5). Wzorzec proceduralny zgodny z `core/StaffFleetPresence.php`,
`core/DriverFleetHelper.php` (funkcje z prefiksem `slicehub`, `declare(strict_types=1)`).

**Izolacja/DDD:** `Uuid.php` linia 8-11 wyraźnie stanowi: prymitywy infrastrukturalne
(jak `Uuid`, `Money`) mogą być `require_once` z dowolnego silosu bez naruszania izolacji
— zakaz dotyczy tylko cudzych `*Engine`-ów domenowych. `SlaThresholds.php` jest
bezstanowym prymitywem czytającym z bazy (jak `DriverFleetHelper`, `StaffFleetPresence`
już używane cross-silo) — ten sam status.

**Refaktorowane call-site'y (5 → 1 funkcja):**
| Plik | Linia | Akcja |
|---|---|---|
| `api/courses/engine.php` | get_dashboard | inline SELECT → `slicehubSlaThresholds($pdo, $tenant_id)` |
| `api/courses/engine.php` | get_driver_runs | j.w. |
| `api/kds/engine.php` | get_board | j.w. |
| `api/pos/engine.php` | get_orders | j.w. |
| `api/orders/sla_monitor.php` | linia ~54 | j.w. (istniejący orphan — refactor bez zmiany logiki, tylko DRY) |

Każdy plik dostał `require_once __DIR__ . '/../../core/SlaThresholds.php';`.

**Weryfikacja refactoru:**
- Lint: `php -l` na 5 plikach (4 backendy + core/SlaThresholds.php) → "No syntax errors detected" ×5
- API: 3 endpointy (courses/kds/pos) nadal zwracają `sla_thresholds: {green_min:10, yellow_min:5}`
- `sla_monitor.php`: success=True, 8 zamówień (refactor nie zmienił zachowania)
- Suite 62 testów: 61 passed, 0 failed, 1 warning (bez regresji)

---

## 3. Decyzje architektoniczne

1. **Default 10/5** — zgodny z `api/orders/sla_monitor.php:63-64` i schematem. Bez migracji.
2. **Snajper** — backend: dotknięte tylko sekcje response (SELECT + dodane pole). Frontend: dotknięte tylko funkcje SLA + punkt ustawienia progów. Inne funkcje w tych samych plikach nietknięte.
3. **`tenant_id = :tid`** w każdym nowym SELECT (Prawo VI).
4. **Defensywny parse** w JS: `Number.isFinite(+t.yellow_min) ? +t.yellow_min : 5` — brak pola lub NaN nie psuje UI.
5. **POS single-boundary** — decyzja użytkownika; spójność 4 frontendów > per-type tolerancja.

### Weryfikacja (Test E2E)

- **Lint:** `php -l` na 3 backendach → "No syntax errors detected" ×3. `node -c` na 6 frontendach → "ALL JS OK".
- **API:** Login waiter PIN 1111 (tenant 1) → `get_dashboard`/`get_board`/`get_orders` wszystkie zwracają `sla_thresholds: {green_min:10, yellow_min:5}`.
- **Konfigurowalność:** `UPDATE sh_tenant_settings SET sla_yellow_min=10` → wszystkie 3 endpointy zwracają `yellow_min:10` → przywrócono 5.
- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core, Chrome `C:\Program Files\Google\Chrome\Application\chrome.exe`). Błędy 400/401/405 w logach = oczekiwane negatywne testy walidacji.

### Analiza PromisedTimeEngine (odpowiedź na pytanie użytkownika)

Zbadano 5 ścieżek nadawania `promised_time`. Realne braki:
- **Online ASAP → null** (`api/online/engine.php:1380`): SLA monitoring ślepy dla online (badge zawsze zielony bo `!promisedTime`). **Prerekwizita dla pełnego efektu Fazy A.**
- **ChoiceQR hardcoded +30** (`api/integrations/choiceqr/webhook.php:207-209`): ignoruje `min_prep_time_minutes` tenanta.
- **Gateway uproszczone** (`api/gateway/intake.php:364`): brak load factor + channel buffer — nice-to-have, działa.

Działające (nie wymaga zmiany): POS ręczny (celowe), scheduled orders (online surowy, gateway walidowany).

**Decyzja użytkownika:** Faza B (wpięcie PromisedTimeEngine) zostaje na osobną sesję.
Faza A zostaje jak jest — online ASAP nadal `null` (zielony badge niezależnie od progu).

---

## 4. Otwarte pytania (dla kolejnej sesji)

1. **Faza B — czy wpiąć PromisedTimeEngine w online/engine.php ASAP path?** To prerekwizita dla pełnego efektu Fazy A (SLA monitoring dla online). Rekomendacja: TAK, najwyższy priorytet. Bez tego Faza A jest niepełna dla kanału online.
2. **Faza B — czy wpiąć w ChoiceQR** (hardcoded +30 → tenant-aware)? Rekomendacja: TAK.
3. **Faza B — czy wpiąć w gateway** (load factor + channel buffer)? Ryzyko: zmieni estymacje dla integracji Papu/ChoiceQR. Rekomendacja: opt-in flagą per-tenant.
4. **Faza B — POS `accept_order` default** gdy kasjer nie poda `custom_time`: `now()` → `PromisedTimeEngine::calculate()`? Plan to przewiduje, ale to zmieni zachowanie POS (celowo ręczny → automat). Do rozstrzygnięcia.
5. **POS single-boundary** — czy przy default `yellow_min=5` zachowanie POS (żółty ≤5 dla delivery) jest akceptowalne biznesowo? Obecnie delivery miało żółty ≤59. Jeśli nie — rozważyć dedykowane ustawienia per-type w `sh_tenant_settings` (np. `sla_delivery_yellow_min`).
6. **Faza C** (SLA breach panel w Dispatcher + cron) — kolejna wg planu A→C→B→D→E.
