# Sesja: Audyt wpięcia PromisedTimeEngine — luki po Faza B

**Data:** 2026-08-03
**Powiązane:** `2026-07-29_phase_b_promised_time_engine.md` (Faza B — wpięcie ASAP), `2026-07-29_promised_time_sla_audit_and_plan.md` (audyt pierwotny), `2026-08-02_audit_plan_vs_actual_drift.md` (drift plan vs kod)
**Konstytucja:** Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)
**Typ:** Audyt read-only — bez zmian kodu, dokumentacja odkryć.

---

## 1. Cel

Weryfikacja end-to-end wpięcia `PromisedTimeEngine` po Faza B (2026-07-29).
Faza B zadeklarowała "DOMKNIĘTE" dla 4 ścieżek ASAP. Audyt sprawdza czy:
(a) silnik faktycznie odpala w każdej ścieżce, (b) tryb `scheduled` silnika
ma konsumenta, (c) frontendy wysyłają payload kompatybilny z logiką fallback.

**Werdykt zbiorczy:** Faza B **częściowo domknięta** — ASAP wpięte w 4 backendy,
ale **3 luki subtelne** powodują że silnik nie odpala w 2 z 4 ścieżek produkcyjnych,
a tryb `scheduled` pozostaje martwym kodem.

---

## 2. Pliki zbadane (audyt read-only)

### Backend — silnik i call sites

| Plik | Linie | Rola |
|---|---|---|
| `core/PromisedTimeEngine.php` | 1–196 | Centralny kalkulator (ASAP + scheduled) |
| `api/pos/engine.php` | 741–751, 1178–1214, 1687–1701 | `process_order`, `accept_order`, `panic_mode` |
| `api/online/engine.php` | 1195–1219, 1380–1392, 1755–1763 | `guest_checkout`, `track_order` ETA |
| `api/gateway/intake.php` | 350–397 | Requested time bounds (aggregatory) |
| `api/integrations/choiceqr/webhook.php` | 220–241 | ChoiceQR promised time fallback |
| `api/orders/estimate.php` | 1–92 | **ORPHAN** — HTTP wrapper, 0 call-site'ów |
| `api/orders/sla_monitor.php` | 1–173 | **ORPHAN** — HTTP endpoint, cron worker działa |
| `core/PanicEngine.php` | 1–109 | Bulk shift promised_time |
| `core/SlaThresholds.php` | 1–39 | SSOT progów SLA |
| `scripts/worker_sla_monitor.php` | 1–80 | CLI cron — breach logging (działa) |

### Frontend — UI nadawania czasu

| Plik | Linie | Rola |
|---|---|---|
| `modules/pos/js/pos_ui.js` | 309–372 | Checkout modal — pillsy czasu |
| `modules/pos/js/pos_ui.js` | 480–526 | Pulse sidebar — przyciski akceptu |
| `modules/pos/js/pos_ui.js` | 534–545 | `fmtTime()` — klasyfikacja SLA |
| `modules/pos/js/pos_app.js` | 873–952 | `_openCheckout` — wysyłka `custom_datetime` |
| `modules/pos/js/pos_app.js` | 1037–1060 | `onAccept` / `onAcceptDate` — pulse akcept |
| `modules/pos/js/pos_app.js` | 957–961 | `_triggerPanic` — panic button |
| `modules/online/js/online_checkout.js` | 175–185, 349 | Pole `requestedTime` (tekst "19:30") |
| `modules/courses/js/courses_ui.js` | 105, 122 | SLA badge (tylko display) |
| `modules/driver_app/js/driver_app.js` | 641–642 | SLA badge (tylko display) |

---

## 3. Decyzje architektoniczne (ustalenia audytu)

### 3.1 PromisedTimeEngine — dwa tryby, asymetryczne wpięcie

**Tryb `asap`** (linie 86–88 silnika):
```
promised = now + (base_prep × load_factor) + channel_buffer
```
- `base_prep_minutes` z `sh_tenant_settings` (default 25)
- `load_factor = min(2.0, 1.0 + active_orders/20)` — aktywne: `accepted`/`preparing`
- `channel_buffer`: `dine_in=0`, `takeaway=+5`, `delivery=+15` min

**Tryb `scheduled`** (linie 90–109 silnika):
- Wymaga `requested_time` (ISO 8601)
- Check 1: `requested ≥ now + min_lead_time_minutes` (default 30)
- Check 2: walidacja `opening_hours_json` z `sh_tenant_settings`
- Zwraca surowy `requested` jako `promised_time`

**Wpięcie:**
- `asap` → 4 call sites (online, gateway, choiceqr, pos accept_order)
- `scheduled` → **0 call sites produkcyjnych**. Jedyny konsument to orphan
  `api/orders/estimate.php` (HTTP wrapper bez frontendu).

### 3.2 Mapa call sites — co odpala, co nie

| # | Ścieżka | Backend logika | Frontend payload | Silnik odpala? |
|---|---|---|---|---|
| 1 | POS `process_order` | `custom_datetime` surowo, fallback `now()` | pillsy `+15/+30/+45/+60` (dine_in/takeaway), `+45/+60/+90/+120` (delivery) | ❌ **NIE** — backend nie woła silnika, fallback to `now()` nie ASAP |
| 2 | POS `accept_order` | ręczny `custom_time` > silnik ASAP > `now()` | pulse: `+15m/+30m/+45m` lub `✓ ZAAKCEPTUJ` (= `now`) | ⚠️ **WARUNKOWO** — tylko gdy `custom_time` pusty; "ZAAKCEPTUJ" wysyła `now` → silnik się nie odpala |
| 3 | Online `guest_checkout` ASAP | silnik ASAP, fallback `null` | brak `requested_time` | ✅ **TAK** |
| 4 | Online `guest_checkout` scheduled | **surowy `requested_time`, brak walidacji** | pole tekstowe "19:30" | ❌ **NIE** — backend zapisuje bez wywołania `calculate('scheduled')` |
| 5 | Gateway `intake` ASAP | silnik ASAP, fallback `now + minPrep` | aggregator payload | ✅ **TAK** |
| 6 | Gateway `intake` scheduled | **własna walidacja inline** (lead time + closing time) | aggregator payload | ❌ **NIE** — duplikat logiki silnika zamiast wywołania |
| 7 | ChoiceQR webhook | silnik ASAP, fallback `now + 30` | `order.delivery.when` lub brak | ✅ **TAK** (gdy brak `when`) |
| 8 | `estimate.php` | pełny silnik (oba tryby) | — | ❌ **ORPHAN** — 0 call-site'ów |

### 3.3 PanicEngine — wpięte poprawnie

<ref_file file="C:\xampp\htdocs\slicehub\core\PanicEngine.php" />

- Bulk `UPDATE sh_orders SET promised_time = DATE_ADD(COALESCE(promised_time, created_at), INTERVAL :delay MINUTE)`
- Default `+20 min`, zakres 5–60, debounce 2 min (`sh_panic_log`)
- Frontend: POS button `_triggerPanic` → `PosAPI.panicMode()` → `pos/engine.php#panic_mode`
- **Uwaga:** frontend nie wysyła `delay_minutes` — backend hardcode'uje 20. Może być
  parametryzowane w UI (np. modal "Wybierz przesunięcie: +10/+20/+30").

### 3.4 SLA Thresholds — wpięte poprawnie (Faza A domknięta)

<ref_file file="C:\xampp\htdocs\slicehub\core\SlaThresholds.php" />

SSOT `slicehubSlaThresholds()` zwraca `{green_min, yellow_min}` z `sh_tenant_settings`
(default 10/5). Konsumenci:
- `api/pos/engine.php#get_orders` → `pos_ui.js:fmtTime()` (single-boundary: red<0, yellow≤yellow_min, green>yellow_min)
- `api/courses/engine.php` → `courses_ui.js:slaClass()`
- `api/kds/engine.php` → `kds_app.js:_timerInfo()`
- `api/orders/sla_monitor.php` + `scripts/worker_sla_monitor.php` (CLI cron)

---

## 4. Odkryte luki (3 krytyczne + 2 kosmetyczne)

### L1 — POS `accept_order` "ZAAKCEPTUJ" wysyła `now` zamiast pustego `custom_time`

**Lokalizacja:** `modules/pos/js/pos_ui.js:508`, `modules/pos/js/pos_app.js:1039-1049`

**Symptomy:** Faza B wpięła silnik ASAP w `accept_order` jako fallback gdy
`custom_time` jest pusty. Ale przycisk "✓ ZAAKCEPTUJ" w pulse sidebar wysyła
`now + 0 min` jako `custom_time` — niepusty string → backend uznaje to za
ręczny input → silnik ASAP nigdy się nie odpala.

**Skutek:** "Inteligentny default" z Fazy B jest martwy dla ścieżki
"ZAAKCEPTUJ natychmiast". Działa tylko gdyby frontend wysłał pusty
`custom_time` — a tak nigdy nie robi.

**Fix (propozycja):** Frontend "ZAAKCEPTUJ" powinien wysyłać pusty `custom_time`
(lub w ogóle go pomijać), żeby backendowy fallback ASAP się odpalił.
Alternatywnie: backend powinien traktować `custom_time === now` jako "pusty"
(również odpala silnik).

### L2 — Online `guest_checkout` scheduled zapisuje surowo bez walidacji

**Lokalizacja:** `api/online/engine.php:1380-1392`, `modules/online/js/online_checkout.js:181-185`

**Symptomy:** Frontend ma pole tekstowe `requestedTime` (placeholder "np. 19:30",
maxlength 5). Backend sprawdza tylko `if ($requestedTime !== '') return $requestedTime`
— zapisuje surowy string bez wywołania `PromisedTimeEngine::calculate('scheduled')`.

**Skutek:** Klient może wpisać "19:30" nawet jeśli:
- lokal zamknięty (brak business-hours gate)
- za 2 minuty (brak lead-time gate)
- godzina z przeszłości (brak walidacji `≥ now`)

**Fix (propozycja):** Backend powinien wywołać
`PromisedTimeEngine::calculate($pdo, $tenantId, 'scheduled', $orderType, $requestedTime)`
— silnik ma już oba gaty (lead-time + business-hours). Frontend powinien
pokazywać błąd z silnika zamiast generic "Błąd".

### L3 — `PromisedTimeEngine` tryb `scheduled` = martwy kod

**Lokalizacja:** `core/PromisedTimeEngine.php:90-109`, `api/orders/estimate.php`

**Symptomy:** Tryb `scheduled` silnika (lead-time gate + business-hours gate)
jest zaimplementowany ale **0 produkcyjnych call-site'ów**. Jedyny konsument to
orphan `api/orders/estimate.php` (HTTP GET wrapper, nagłówek "STATUS: ORPHAN").

**Skutek:** 20 linii kodu (linie 90–109) + helpery `parseIsoDate` +
`validateBusinessHours` nie są nigdy wykonywane w produkcji. Mimo że Faza B
zadeklarowała "DOMKNIĘTE" dla silnika, tryb scheduled pozostaje @planned.

**Fix (propozycja):** Domknąć przez L2 (online checkout scheduled) — wtedy
silnik scheduled dostaje konsumenta. `estimate.php` pozostaje orphanem dla
future scheduled-picker UI w storefront (zgodnie z Faza B §6).

### L4 (kosmetyczna) — Gateway `intake` scheduled duplikuje logikę silnika

**Lokalizacja:** `api/gateway/intake.php:376-397`

**Symptomy:** Scheduled orders w gateway mają własną walidację inline
(`min_lead_time` + `closing_time`) zamiast wywołać
`PromisedTimeEngine::calculate('scheduled')`.

**Skutek:** Duplikat logiki — silnik ma lead-time + business-hours, gateway
ma lead-time + closing-time. Rozbieżność: silnik czyta `opening_hours_json`,
gateway czyta `closingTime` z innego source. Ryzyko driftu.

**Fix (propozycja):** Przepiąć gateway scheduled na silnik (zachować fallback
inline przy wyjątku). Niski priorytet — aggregatory rzadko wysyłają scheduled.

### L5 (kosmetyczna) — POS `process_order` fallback to `now()` nie silnik

**Lokalizacja:** `api/pos/engine.php:741-751`

**Symptomy:** Gdy kasjer nie wyśle `custom_datetime`, backend ustawia `now()`
zamiast wywołać `PromisedTimeEngine::calculate('asap')`.

**Skutek:** Zamówienie POS z pustym czasem dostaje `promised_time = now`
(zamiast `now + base_prep × load + channel_buffer`). SLA badge od razu pokazuje
żółty/czerwony. Ale — frontend zawsze wysyła wartość z pillsów (default aktywny),
więc ścieżka `now()` jest rzadko osiągana.

**Fix (propozycja):** Zmienić fallback na `PromisedTimeEngine::calculate('asap')`
dla spójności z `accept_order`. Niski priorytet — pillsy maskują problem.

---

## 5. Tabela podsumowująca — status domknięcia

| Element | Status Faza B | Status audyt 2026-08-03 | Luka |
|---|---|---|---|
| PromisedTimeEngine ASAP | ✅ DOMKNIĘTE | ⚠️ **CZĘŚCIOWO** — 3/4 ścieżki odpalają | L1 (POS accept "ZAAKCEPTUJ") |
| PromisedTimeEngine scheduled | (nie w zakresie Faza B) | ❌ **MARTWY KOD** | L3 (0 konsumentów) |
| Online checkout scheduled | (nie w zakresie) | ❌ **BRAK WALIDACJI** | L2 (zapis surowy) |
| Gateway scheduled | (nie w zakresie) | ⚠️ **DUPLIKAT** | L4 (własna walidacja) |
| POS process_order fallback | (nie w zakresie) | ⚠️ **FALLBACK `now()`** | L5 (nie silnik) |
| PanicEngine | ✅ DOMKNIĘTE | ✅ **OK** | — |
| SLA Thresholds (Faza A) | ✅ DOMKNIĘTE | ✅ **OK** | — |
| `estimate.php` | PLANNED (Faza B §6) | ❌ **ORPHAN** | L3 powiązane |
| `sla_monitor.php` | PARTIAL (Faza C) | ⚠️ **ORPHAN HTTP**, cron działa | (Faza C open) |

---

## 6. Otwarte pytania (dla kolejnej sesji)

1. **L1 — czy "ZAAKCEPTUJ" ma wysyłać pusty `custom_time`?** Decyzja UX:
   czy "ZAAKCEPTUJ" oznacza "akceptuj z inteligentnym defaultem" (pusty →
   silnik ASAP) czy "akceptuj na teraz" (`now` → ręczny override)? Obecnie
   to drugie, ale Faza B zakładała pierwsze.
2. **L2 — czy online checkout scheduled ma walidować przez silnik?** Jeśli
   tak, to frontend musi pokazywać błędy z `PromisedTimeEngine` (lead-time,
   business-hours) zamiast generic "Błąd". Wymaga zmiany UI.
3. **L3 — czy domknąć `estimate.php` przez scheduled-picker w storefront?**
   To był plan Faza B §6. Wymaga nowego UI w `modules/online/` (datetime
   picker z walidacją przez `estimate.php`).
4. **L4 — czy przepiąć gateway scheduled na silnik?** Niski priorytet,
   aggregatory rzadko wysyłają scheduled. Ale spójność architektoniczna.
5. **L5 — czy zmienić fallback `process_order` na silnik?** Pillsy maskują
   problem, ale fallback `now()` jest niespójny z `accept_order`.
6. **PanicEngine parametryzacja** — czy dodać modal "Wybierz przesunięcie"
   w POS zamiast hardcoded `+20`? `PanicEngine::execute` przyjmuje
   `delayMinutes` (5–60), frontend nie wykorzystuje.
7. **Czy Faza B powinna zostać zaktualizowana w `01_KONSTYTUCJA.md`?**
   Obecnie mówi "DOMKNIĘTE 2026-07-29 (Faza B)" — ale audyt pokazuje
   że domknięcie jest częściowe. Może "DOMKNIĘTE (ASAP only, scheduled
   open)"?

---

## 7. Rekomendacja kolejności napraw

| Priorytet | Luka | Trudność | Efekt |
|---|---|---|---|
| HIGH | L1 (POS accept "ZAAKCEPTUJ") | ~30 min frontend | Inteligentny default ASAP zaczyna działać |
| HIGH | L2 (Online scheduled walidacja) | ~1h backend + frontend | Bezpieczeństwo — blok zamówień poza godzinami |
| MEDIUM | L3 (domknąć scheduled silnik) | zależy od L2 | Usunięcie martwego kodu / domknięcie Konstytucji VIII |
| LOW | L5 (process_order fallback) | ~15 min backend | Spójność fallbacków |
| LOW | L4 (gateway scheduled) | ~30 min backend | Spójność architektoniczna |
