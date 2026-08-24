# 19. Logistyka & Bezpieczeństwo Operacyjne — 3 Mechanizmy Anty-Błędowe

> Dokument techniczny opisujący 3 "perełki" operacyjne modułu logistyki SliceHub:
> Emergency Recall, Pre-Flight Check produktów krytycznych, Payment Lock.
>
> Źródła: `api/courses/engine.php`, `core/OrderStateMachine.php`, `modules/driver_app/`,
> `modules/courses/`, `database/migrations/010_driver_action_type.sql`.

---

## 0. Wspólny fundament

Wszystkie trzy mechanizmy operują na zunifikowanym REST API
`POST /api/courses/engine.php` (action-based, `switch($action)`),
z barierą tenantową (§2 Konstytucji) i **3-filarowym modelem stanu**
zadeklarowanym w nagłówku silnika (`api/courses/engine.php:8-12`):

```
status:          new | accepted | pending | preparing | ready | completed | cancelled
payment_status:  to_pay | online_unpaid | cash | card | online_paid
delivery_status: unassigned | in_delivery | delivered
```

3-filarowa State Machine egzekwowana przez `core/OrderStateMachine.php`.
Wszystkie kwoty w **integer grosze**. Wszystkie ID tenant-scoped.

---

## 1. Emergency Recall — "Zawróć kierowcę"

### 1.1 Mechanika sygnału (backend)

Sygnał przerwania trasy **nie tworzy nowej tabeli ani kolejki** —
wykorzystuje istniejące pole `heading` w `sh_driver_locations` jako
kanał poza-pasmowy z **wartownikową wartością `-999`**
(niemożliwą fizycznie dla kursu kompasu 0–359°).

| Akcja | Linie | Opis |
|---|---|---|
| `emergency_recall` | `api/courses/engine.php:1349-1363` | Dyspozytor → `INSERT ... ON DUPLICATE KEY UPDATE heading = -999, updated_at = NOW()`. Atomowo działa nawet gdy kierowca nie wysyłał jeszcze GPS. |
| `check_recall` | `api/courses/engine.php:1368-1378` | PWA kierowcy → `SELECT heading FROM sh_driver_locations WHERE driver_id = :did`. Zwraca `recalled = ((int)heading === -999)`. |
| `clear_recall` | `api/courses/engine.php:1383-1389` | Po potwierdzeniu → `UPDATE ... SET heading = NULL WHERE heading = -999`. Idempotentne. |

```sql
-- emergency_recall
INSERT INTO sh_driver_locations (driver_id, tenant_id, lat, lng, heading, speed_kmh, accuracy_m, updated_at)
VALUES (:did, :tid, 0, 0, NULL, NULL, NULL, NOW())
ON DUPLICATE KEY UPDATE heading = -999, updated_at = NOW()
```

Wartownik `-999` jest też udokumentowany w `.cursorrules` §8 i obroniony
w przepływie GPS — `update_location` (`api/courses/engine.php:980`)
zapisuje rzeczywisty `heading`, nadpisując `-999` dopiero **po**
akwitacji kierowcy.

### 1.2 Trigger po stronie dyspozytora

- `modules/courses/js/courses_ui.js:73` — przycisk **Zawróć** renderowany
  WYŁĄCZNIE dla kierowców `status === 'busy'` (czyli już w trasie).
- `modules/courses/js/courses_app.js:298-320` — `openRecallModal()` →
  `confirmRecall()` → `CoursesAPI.emergencyRecall(driverId)`.
- `modules/courses/js/courses_api.js:46` — wrapper
  `_post('emergency_recall', { driver_user_id })`.
- `modules/courses/index.html:147-158` — modal potwierdzenia z `App.confirmRecall()`.

### 1.3 Reakcja w aplikacji kierowcy (PWA)

- `modules/driver_app/js/driver_app.js:9` — `RECALL_CHECK_INTERVAL = 12000`
  (polling co 12 s).
- `modules/driver_app/js/driver_app.js:90` —
  `setInterval(checkRecall, RECALL_CHECK_INTERVAL)` startuje przy `startApp()`.
- `modules/driver_app/js/driver_app.js:168-181` — `checkRecall()`:
  - aktywuje overlay `#emergency-overlay`
    (`position:fixed; inset:0; z-index:9500; rgba(239,68,68,0.95); animation:flash-red 0.5s infinite alternate` —
    `modules/driver_app/css/style.css:71-77`);
  - **wibracja haptyczna**: `navigator.vibrate([500,200,500,200,500])` (Vibration API);
  - blokuje cały UI fizycznie (overlay przykrywa wszystko, brak X-a).
- `modules/driver_app/index.html:19-25` — overlay z jednym przyciskiem
  `POTWIERDZAM — WRACAM`.
- `acknowledgeRecall()` → `DriverAPI.clearRecall()` zeruje `heading`
  na backendzie i zdejmuje overlay.

**Audytowalność**: `updated_at = NOW()` w `INSERT ... ON DUPLICATE KEY`
pozostawia ślad czasowy momentu wezwania.

---

## 2. Pre-Flight Check produktów krytycznych
(zimna Cola, rukola, alkohol)

### 2.1 Definicja w schemacie (kanon danych)

`database/migrations/010_driver_action_type.sql` dodaje ENUM
**DWUTOROWO** (per produkt + per linia zamówienia):

```sql
ALTER TABLE sh_menu_items
  ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id')
  NOT NULL DEFAULT 'none';

ALTER TABLE sh_order_lines
  ADD COLUMN driver_action_type ENUM('none','pack_cold','pack_separate','check_id')
  NOT NULL DEFAULT 'none' AFTER comment;
```

Konfiguracja na poziomie pozycji menu (snapshot przy zamawianiu zostaje
skopiowany do linii — wzorzec point-of-sale immutability). Auto-migracja
przy starcie silnika: `api/courses/engine.php:128-134` (try/catch
na `SELECT ... LIMIT 0` jako test istnienia kolumny).

**Trzy klasy produktów krytycznych:**

| Wartość ENUM | Tag UI | Zastosowanie |
|---|---|---|
| `pack_cold` | ❄️ ZIMNE | Cola, sosy z lodówki |
| `pack_separate` | 🌿 OSOBNO | Rukola, sałaty, zioła |
| `check_id` | 🔞 WIEK | Alkohol, weryfikacja wieku |

### 2.2 Propagacja do PWA

`api/courses/engine.php:1012` — w `get_driver_runs` linie zamówienia są
pobierane jawnie z polem
`COALESCE(driver_action_type,'none') AS driver_action_type`.
PWA dostaje flagę bez dodatkowych zapytań.

### 2.3 Wymuszenie kontroli (driver_app.js) — 3 poziomy

#### A) Pre-Flight Modal (gate całego kursu)

`modules/driver_app/js/driver_app.js:188-253`:

- `_hasSpecialItems(orders)` skanuje wszystkie linie kursu pod kątem
  `driver_action_type !== 'none'`.
- `checkPreFlight()` (l. 211) wywoływane zawsze po `renderRuns()` (l. 407).
- `showPreFlight()` renderuje sekcje per-grupa (Cold / Separate / Check ID)
  z konkretnymi pozycjami i numerami zamówień (`#5/12`).
- Modal jest **pełnoekranowy** (`#preflight-overlay`) — kierowca nie
  zobaczy listy kursów dopóki nie potwierdzi.

#### B) Hold-to-confirm (1500 ms)

`_wireHoldButton()` (l. 255-297) — przycisk wymaga **fizycznego
przytrzymania 1.5 s** (`pointerdown` → `setTimeout 1500ms`).
Każde `pointerup`/`pointerleave`/`pointercancel` w trakcie zeruje progres.
Anti-fat-finger: niemożliwe przypadkowe odklikanie.

Po sukcesie: `state.dismissedCourses.add(courseId)` zapisywane do
`localStorage` (`LS_DISMISSED`, l. 272) — kontrola jest **per-kurs**,
więc nowe zamówienia z innego kursu retriggerują modal.

#### C) Age-Gate per zamówienie (twarda blokada `Dostarczono`)

Dla `check_id` istnieje **drugi** gate, na poziomie pojedynczego zamówienia,
w karcie kursu:

- `_orderHasCheckId(o)` (l. 206)
- `modules/driver_app/js/driver_app.js:349-354`:

```js
const ageGate = hasCheckId && !ageOk
  ? `<input type="checkbox" id="age-${o.id}" onchange="DriverApp.verifyAge(...)">...`
  : '';
const deliverDisabled = (hasCheckId && !ageOk) ? 'disabled' : '';
```

- `verifyAge()` (l. 410-418) wymaga jawnego checkboxu, persystuje
  w `localStorage` (`LS_AGE_VERIFIED`).
- Przycisk `Dostarczono` jest natywnie `disabled` w HTML — kierowca
  **nie może oznaczyć dostawy alkoholu** bez kliknięcia
  "Zweryfikowano wiek klienta".

#### Wizualne podświetlenie (cognitive priming)

`modules/driver_app/js/driver_app.js:337-341` — każda linia z
`driver_action_type !== 'none'` dostaje klasę
`action-glow glow-cold|glow-separate|glow-check_id` + tag
(`❄️ ZIMNE` / `🌿 OSOBNO` / `🔞 WIEK`). Kierowca widzi pozycję
krytyczną także *podczas* kursu, nie tylko w pre-flight.

---

## 3. Payment Lock — bezpieczeństwo rozliczenia gotówkowego

### 3.1 Reguła twarda (3-filarowa state machine)

`api/courses/engine.php:38-42` i mirror w `core/OrderStateMachine.php:453-456`:

```php
function isPaid(string $ps): bool {
    return in_array($ps, ['cash', 'card', 'online_paid'], true);
}
```

Wszystkie pozostałe wartości (`to_pay`, `online_unpaid`) = **NIE-paid**.

### 3.2 Blokada `deliver_order`

`api/courses/engine.php:1287-1292`:

```php
$requirePayment = empty($tenantFlags['skip_payment_lock']);
if ($requirePayment && !OrderStateMachine::isPaid($order['payment_status'])) {
    http_response_code(409);
    coursesResponse(false, null, 'PAYMENT_LOCK: Musisz najpierw pobrać płatność (gotówka/karta) przed oznaczeniem dostawy.');
}
```

- HTTP **409 Conflict** (semantycznie: "stan biznesowy uniemożliwia operację").
- Komunikat zawiera prefiks `PAYMENT_LOCK:` — frontend rozpoznaje go
  strukturalnie (`driver_app.js:440 → res.message.includes('PAYMENT_LOCK')`)
  i wyświetla user-friendly toast.
- Feature flag `skip_payment_lock` (FF-007, udokumentowany
  `core/OrderStateMachine.php:29`, ładowany przez
  `OrderStateMachine::loadTenantFlags()`) pozwala obejść lock dla
  scenariuszy aggregatorowych (Pyszne / Glovo / Wolt — gdzie pieniądze
  rozliczają się poza systemem) — to świadoma decyzja per-tenant, nie luka.

### 3.3 Atomowy zapis płatności (`collect_payment`)

`api/courses/engine.php:1174-1236` — każdy pobór gotówki/karty kierowcy
idzie przez transakcję:

1. **Walidacja**: `collection_type ∈ {cash, card}`, `isPaid(...)`
   musi zwrócić `false` (nie można ponownie pobrać już opłaconego).
2. `BEGIN TRANSACTION`:
   - `UPDATE sh_orders SET payment_status = :ps, payment_method = :pm` —
     zmienia stan na "paid".
   - **`INSERT INTO sh_order_payments` z `user_id = driverIdForPayment`**
     (l. 1210-1222) — *rejestr fizycznego inkasenta*, kluczowy dla settlement.
   - `INSERT INTO sh_order_audit` — ślad audytowy
     (`new_status = "payment_cash"|"payment_card"`).
3. `COMMIT` / `ROLLBACK` przy wyjątku.

> **To jest sedno bezpieczeństwa**: `sh_order_payments.user_id == kierowca`,
> NIE kasjer. Daje *mathematical proof*, kto fizycznie miał banknoty w ręce.

### 3.4 Reconcile — matematyka zwrotu zmiany

`api/courses/engine.php:1086-1147` (`action: reconcile`):

```sql
SELECT COALESCE(SUM(p.amount_grosze), 0) AS cash_grosze
FROM sh_order_payments p
JOIN sh_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
WHERE p.user_id = :did AND p.tenant_id = :tid AND p.method = 'cash'
  AND o.order_type = 'delivery' AND o.status = 'completed'
  AND p.created_at >= :ss   -- granica zmiany (sh_driver_shifts.created_at)
```

Komentarz w kodzie (l. 1111) jest manifestem architektonicznym:

> **Authoritative cash collected from `sh_order_payments`
> (not `sh_orders.payment_status`)**

Dlaczego? Bo zamówienie online_paid (`payment_status = 'online_paid'`)
NIE produkuje rekordu w `sh_order_payments` dla kierowcy → nie wlicza
się do jego rozliczenia. Online_paid nigdy nie zafałszuje gotówki kierowcy.

**Wzór:**

```
expected = sh_driver_shifts.initial_cash
         + Σ(sh_order_payments.amount_grosze WHERE user_id=driver, method=cash)
variance = counted_cash - expected
flag     = abs(variance) > 500 grosze ? 'REVIEW_REQUIRED' : 'OK'
```

Tolerancja **5 zł** (500 gr, l. 1126). Powyżej — automatyczna flaga
do audytu. Atomowo: zamknięcie zmiany (`status='closed'`) + zerowanie
statusu kierowcy (`UPDATE sh_drivers SET status='offline'`) idą
w jednej transakcji (l. 1128-1138).

### 3.5 Wallet — live dashboard (`get_driver_wallet`)

`api/courses/engine.php:1392-1489`. Komentarz (l. 1394-1397):

> **SETTLEMENT-SAFE**: Financial breakdown sourced from `sh_order_payments`
> with `user_id = :driver_id`, NOT from `sh_orders.payment_status`.
> This guarantees that only money the driver PHYSICALLY collected
> appears in "cash to return" / "card on terminal".

To samo źródło prawdy w czasie rzeczywistym — kierowca w PWA
(`tab-wallet`) widzi dokładnie tę samą liczbę co dyspozytor przy reconcile.
Brak rozjazdu UI ↔ księgowość.

### 3.6 Frontend: zamek wizualny

`modules/driver_app/js/driver_app.js:369-377` — gdy `payment_status` ∈
`{to_pay, online_unpaid}`, kontroler renderuje:

- czerwony badge `DO ZAPŁATY — XX zł`,
- przyciski `Gotówka` / `Karta` (`collectPayment`),
- przycisk `Dostarcz` w stanie **`disabled` z ikoną kłódki**:

```html
<button class="d-action locked" disabled>
  <i class="fa-solid fa-lock"></i> Dostarcz
</button>
```

Kierowca fizycznie nie ma jak kliknąć "Dostarczono" — przycisk nawet
nie reaguje na tap. Backend i frontend trzymają tę samą inwariantę
**dwoma niezależnymi mechanizmami** (defense in depth).

---

## 4. Eliminacja błędów ludzkich — mapa

| Klasa błędu | Mechanizm | Plik:linia |
|---|---|---|
| Kierowca jedzie po odwołaniu | `heading=-999` sentinel + 12 s polling + full-screen overlay + wibracje | `api/courses/engine.php:1349-1389`, `driver_app.js:9,168-181` |
| Zapomniana zimna Cola / rukola | `driver_action_type` ENUM w schemacie + pre-flight modal (1.5 s hold) per kurs | `migrations/010_driver_action_type.sql`, `driver_app.js:188-297` |
| Sprzedaż alkoholu nieletnim | `check_id` flag + obowiązkowy checkbox `verifyAge` + `disabled` na `Dostarczono` | `driver_app.js:206,349-354,410-418` |
| "Dostarczono" bez pobrania kasy | HTTP 409 `PAYMENT_LOCK` (backend) + `disabled+lock-icon` na przycisku (frontend) | `engine.php:1289-1291`, `driver_app.js:369-377,440-445` |
| Kreatywne księgowanie kasy | `sh_order_payments.user_id = driver` jako *single source of truth*, NIE `sh_orders.payment_status` | `engine.php:1111-1122,1394-1397` |
| Fałszowanie zmiany | Reconcile: tolerancja ±5 zł, `flag=REVIEW_REQUIRED` powyżej, atomowe zamknięcie zmiany | `engine.php:1124-1138` |
| Pomyłka audytowa | `sh_order_audit` insert per `collect_payment` ze starym i nowym statusem | `engine.php:1224-1227` |
| Race / wyciek między pizzeriami | Każde zapytanie ma `tenant_id = :tid` (zgodnie z `.cursorrules` §2) | wszędzie |

---

## 5. Wniosek architektoniczny

System **nie polega na dyscyplinie kierowcy** — egzekwuje regułę na
poziomie SQL / PHP / UI **równocześnie**. Każdy z trzech mechanizmów
ma **co najmniej dwa niezależne punkty enforcement** (backend kod +
frontend DOM lub backend + DB constraint), więc obejście wymagałoby
kompromitacji więcej niż jednej warstwy.

Wzorzec architektoniczny: **defense in depth + single source of truth**.

- Truth o pieniądzach kierowcy: `sh_order_payments` (z `user_id`).
- Truth o krytyczności produktu: `sh_menu_items.driver_action_type`
  → snapshot do `sh_order_lines.driver_action_type`.
- Truth o odwołaniu kursu: `sh_driver_locations.heading == -999`.

Każda z tych prawd ma jedno miejsce zapisu i wiele miejsc czytania —
brak rozjazdów, brak duplikacji stanu, brak okazji do błędu ludzkiego.

---

## 6. Driver App — stan modułu i roadmapa (audyt 2026-07-29)

### 6.1 Architektura

| Warstwa | Plik | Opis |
|---|---|---|
| Backend API | `api/courses/engine.php` | 20 akcji REST (action-based, JWT auth, tenant-scoped) |
| SSE endpoint | `api/courses/sse_driver.php` | Real-time push dla kierowcy (JWT via `?token=`) |
| Frontend PWA | `modules/driver_app/` | Vanilla JS, Tailwind CDN, offline-first |
| Service Worker | `modules/driver_app/sw.js` | Offline app shell, stale-while-revalidate |
| Dokumentacja | `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md` | 3 mechanizmy anty-błędowe (§1-5) |

### 6.2 Backend — 20 akcji `engine.php` (wszystkie ✅)

| Akcja | Linia | Opis |
|---|---|---|
| `get_dashboard` | 164 | Auto-heal busy drivers, lista kierowców + kursów |
| `dispatch` | 289 | Przypisz zamówienia do kierowcy |
| `create_course` | 444 | Utwórz kurs K-number bez kierowcy (queued) |
| `assign_driver_to_course` | 537 | Przypisz kierowcę do istniejącego kursu |
| `append_to_course` | 622 | Dodaj zamówienia do aktywnego kursu |
| `update_order_status` | 732 | Zmień status zamówienia (state machine) |
| `cancel_stop` | 860 | Usuń pojedyncze zamówienie z kursu |
| `cancel_order` | 899 | Anuluj zamówienie (kierowca lub dyspozytor) |
| `start_shift` | 976 | Rozpocznij zmianę — initial cash → `sh_driver_shifts` |
| `update_location` | 1007 | GPS — `sh_driver_locations` |
| `get_driver_runs` | 1033 | Aktywne zamówienia kierowcy + wallet summary |
| `set_initial_cash` | 1105 | Ustaw pogotowie kasowe |
| `reconcile` | 1138 | Rozliczenie zmiany — SSOT `sh_order_payments` + tips + ±5 zł |
| `set_driver_status` | 1250 | `available` / `busy` / `offline` |
| `collect_payment` | 1295 | Pobierz gotówkę/kartę — atomic INSERT + audit |
| `deliver_order` | 1371 | Oznacz dostarczone — Payment Lock (HTTP 409 if unpaid) |
| `emergency_recall` | 1457 | Zawróć kierowcę — `heading = -999` sentinel |
| `check_recall` | 1476 | Sprawdź sygnał zawrócenia |
| `clear_recall` | 1491 | Potwierdź zawrócenie |
| `get_driver_wallet` | 1499 | Live dashboard — settlement-safe z `sh_order_payments` |

### 6.3 Frontend — funkcje PWA (wszystkie ✅)

| Funkcja | Plik | Opis |
|---|---|---|
| Login (login + hasło, mode: system) | `driver_app.js`, `driver_api.js` | JWT token w localStorage |
| Polling (10s) + GPS (15s) + Recall check (12s) | `driver_app.js` | Fallback dla SSE |
| SSE real-time (8 event types + auto-reconnect) | `driver_app.js`, `sse_driver.php` | JWT auth via `?token=` |
| Start Shift modal (initial cash input) | `driver_app.js`, `index.html` | `sh_driver_shifts` + localStorage |
| Status Toggle (available ↔ busy, topbar circle) | `driver_app.js`, `index.html` | `setDriverStatus()` |
| SLA Badges (green/yellow/red + min remaining) | `driver_app.js` | Client-side, `promised_time` z API |
| Order cards (address, phone, maps, lines, pay badge) | `driver_app.js` | Pełne renderowanie kursów |
| Pre-Flight Check (cold/separate/check_id + 1.5s hold) | `driver_app.js`, `index.html` | Per-course dismiss |
| Age Gate (checkbox + disabled Deliver button) | `driver_app.js` | Weryfikacja wieku dla alkoholu |
| Payment Lock (disabled Deliver + lock icon if unpaid) | `driver_app.js` | HTTP 409 backend + UI frontend |
| Collect Payment (cash/card buttons) | `driver_app.js` | Atomic INSERT `sh_order_payments` |
| Deliver Order | `driver_app.js` | Payment Lock enforcement |
| Cancel Order modal (reason textarea) | `driver_app.js`, `index.html` | Z powodem |
| Tip display on order cards | `driver_app.js` | `tip_amount > 0` |
| Wallet tab (cash in hand, breakdown, delivery history) | `driver_app.js` | Settlement-safe |
| Reconcile modal (expected vs counted, variance, auto-logout) | `driver_app.js`, `index.html` | ±5 zł tolerance |
| Emergency Recall overlay (full-screen red + vibrate) | `driver_app.js`, `index.html` | `heading=-999` sentinel |
| Service Worker (offline app shell) | `sw.js`, `driver_app.js` | Network-first + offline fallback |
| PWA manifest (SVG icons, mobile-web-app-capable) | `manifest.json`, `icon.svg` | Instalowalna PWA |
| Offline fallback page | `offline.html` | Brak sieci → fallback |

### 6.4 Bug fix (2026-07-28)

- **Tips w reconcile** — `engine.php#reconcile` nie wliczał `tip_amount` do expected cash → naprawione (dodane `cash_tips_grosze` query + `cash_from_tips` w response). Wzorzec skopiowany z legacy `api/delivery/reconcile.php`.

### 6.5 Niedokończone — roadmapa

| # | Funkcja | Status | Szczegóły |
|---|---|---|---|
| 1 | **SLA breach logging** (`api/orders/sla_monitor.php`) | ✅ WDRUŻONE (2026-07-29) | `scripts/worker_sla_monitor.php` — CLI cron script (iteruje po tenantach, UPSERT do `sh_sla_breaches`). `engine.php#get_sla_breaches` — nowa akcja zwracająca breachy (24h) z JOIN do zamówień i kierowców. Cron: `php scripts/worker_sla_monitor.php` co 1-2 min. |
| 2 | **`worker_driver_fanout.php`** — auto-sync statusu kierowcy z HR | ✅ WŁĄCZONY (2026-07-29) | Feature flag `HR_USE_EVENT_DRIVER_FANOUT='1'` ustawiona dla tenant 1. Worker gotowy (9/9 smoke test PASS). Cron: `php scripts/worker_driver_fanout.php` co 30s. |
| 3 | **HR clock_in/clock_out integracja** | ✅ WDRUŻONE (2026-07-29) | `driver_api.js` — `hrClockIn()` / `hrClockOut()` (wywołuje `/api/backoffice/hr/engine.php` z `auth:self`, `source:mobile`). `driver_app.js` — `confirmStartShift()` wywołuje `hrClockIn()` po sukcesie, `confirmReconcile()` wywołuje `hrClockOut()` po sukcesie. Best effort — nie blokuje shift/reconcle przy błędzie. |
| 4 | **Status `returning`** | ⚠️ ODŁOŻONE | Kierowca wracający do bazy po dostawie — dodatkowy status w state machine. Na prośbę użytkownika — nie implementowane. |
| 5 | **Push notifications dla kierowcy** | ❌ Brak | `NotificationDispatcher` działa dla klientów (SMS/email). Driver App ma SSE + polling, ale nie ma pushów. Może wykorzystać `sh_notification_routes`. |

### 6.6 Kolejność prac (rekomendowana)

1. ~~**HR integracja (fanout)**~~ — ✅ DONE. Flag ON dla tenant 1, `hrClockIn`/`hrClockOut` w Driver App, worker gotowy do cron.
2. ~~**SLA breach logging**~~ — ✅ DONE. `worker_sla_monitor.php` (CLI cron) + `get_sla_breaches` akcja w `engine.php`.
3. **Status `returning`** — gdy właściciel zdecyduje. Wymaga zmiany w state machine (`OrderStateMachine.php` + `engine.php` + frontend).
4. **Push notifications** — opcjonalne, niski priorytet. SSE + polling działają wystarczająco.

### 6.7 Cron setup (Windows/XAMPP)

Na produkcji (Linux) użyj crontab. Na Windows/XAMPP użyj Task Scheduler:

```bash
# Linux crontab — co 30s (worker_driver_fanout) + co 1 min (worker_sla_monitor)
* * * * * php /path/to/scripts/worker_driver_fanout.php
* * * * * sleep 30 && php /path/to/scripts/worker_driver_fanout.php
* * * * * php /path/to/scripts/worker_sla_monitor.php
```

```powershell
# Windows Task Scheduler — odpowiedniki
# worker_driver_fanout: co 30s (wymaga 2 zadań z offset 30s)
# worker_sla_monitor: co 1 min
```

### 6.8 Spięcie HR ↔ Logistyka — Fazy 1–4 (2026-08-24)

Plan 4-fazowy domykający powiązanie shiftu kasowego z sesją HR (PR #57/#58/#59):

| Faza | Zakres | Pliki | Status |
|---|---|---|---|
| 1 | Retry `hrClockIn`/`hrClockOut` (3 próby, backoff) + widoczny badge stanu HR w Driver App; zapamiętanie `session_uuid` w localStorage | `driver_api.js`, `driver_app.js`, `index.html` | ✅ |
| 2 | Korelacja `sh_driver_shifts.work_session_uuid CHAR(36)` (migracja 066, index `idx_shifts_tenant_ws_uuid`, bez FK cross-silo) + akcja `link_hr_session` + raport rozjazdów `scripts/report_hr_shift_drift.php` | `engine.php`, migracja 066 | ✅ |
| 3 | **Miękki gate HR** (`core/HrSessionGate.php`, read-only cross-silo per `_docs/18` §9.3): FF per-tenant `HR_REQUIRE_OPEN_SESSION_FOR_DISPATCH` (default OFF). Kierowca bez otwartej `sh_work_sessions` (grace 5 min od startu shiftu) dostaje `hr_session_ok=false` w `get_dashboard` i listach POS (badge „HR" w dyspozytorni, lista NIE filtrowana); `action=dispatch` przechodzi, ale zwraca `hr_warning:true` + alert SSE `hr.dispatch_without_session` (`broadcast:<tid>`). Brak twardej blokady. | `HrSessionGate.php`, `api/courses/engine.php`, `api/pos/engine.php`, `courses_ui.js` | ✅ |
| 4 | Sprzątanie po clock-out w `worker_driver_fanout`: natychmiastowy `slicehubClearStaffPresence()` + wykrycie wiszącego aktywnego `sh_driver_shifts` → alert SSE `hr.shift_hanging`. **Bez auto-close** — reconcile wymaga rozliczenia gotówki przez człowieka. `busy` nadal nietykany (§11.4 doc 18). | `worker_driver_fanout.php` | ✅ |

Włączenie gate'u: `INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value) VALUES (N,'HR_REQUIRE_OPEN_SESSION_FOR_DISPATCH','1')`. Rollback = flip flagi.
