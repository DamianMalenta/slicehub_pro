# Sesja: Faza B — Wpięcie PromisedTimeEngine w 4 ścieżki ASAP

**Data:** 2026-07-29
**Powiązane:** `_docs/sessions/2026-07-29_promised_time_sla_audit_and_plan.md` (Faza B)
**Konstytucja:** Prawo VI (Snajper), Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)

---

## 1. Cel

Wpięcie `PromisedTimeEngine::calculate()` w 4 produkcyjne ścieżki nadawania
`promised_time` dla trybu ASAP. Dotychczas każda ścieżka miała własną uproszczoną
logikę (null, now+minPrep, hardcoded +30, now()). Silnik dodaje load factor
(obciążenie kuchni), channel buffer (delivery +15, takeaway +5, dine_in +0) i
tenant base_prep_minutes.

Decyzja użytkownika: **Opcja 1** — wpiąć silnik we wszystkie 4 ścieżki włącznie z
POS `accept_order` (default gdy kasjer nie poda `custom_time`; ręczny input wygrywa).

---

## 2. Pliki dotknięte (Snajper — tylko ścieżka promised_time)

| Plik | Linia | Zmiana |
|---|---|---|
| `api/online/engine.php` | ~1380 (guest_checkout INSERT) | ASAP: `null` → `PromisedTimeEngine::calculate($pdo, $tenantId, 'asap', $orderType)`. Scheduled: surowy `requested_time` (bez zmian). Fallback `null` przy wyjątku. |
| `api/gateway/intake.php` | ~363 (REQUESTED TIME BOUNDS) | ASAP: `now + minPrepMinutes` → silnik. Channel mapowany `Delivery` → `delivery`. Fallback na `now + minPrepMinutes` przy wyjątku. Scheduled: walidacja bez zmian. |
| `api/integrations/choiceqr/webhook.php` | ~227 (promised time fallback) | ASAP: hardcoded `+30 min` → silnik. `$orderType` już lowercase. Fallback na `+30 min` przy wyjątku (nie blokuj webhooka — aggregator oczekuje 200 OK). |
| `api/pos/engine.php` | ~1171 (accept_order) | Default gdy brak `custom_time`: `now()` → silnik. SELECT rozszerzony o `order_type`. Ręczny `custom_time` wygrywa (bez zmian). Fallback `now()` przy wyjątku. |

Każdy plik dostał `require_once __DIR__ . '/.../core/PromisedTimeEngine.php'` (leniwe,
wewnątrz akcji — zgodne z wzorcem z `CartEngine`/`WzEngine`).

### Dokumentacja (Prawo VIII)

| Plik | Zmiana |
|---|---|
| `_docs/01_KONSTYTUCJA.md` | `core/PromisedTimeEngine.php` 🟡 PLANNED → ✅ **DOMKNIĘTE 2026-07-29 (Faza B)**. `estimate.php` zostaje PLANNED (wrapper dla future scheduled-picker UI; silnik wpięty bezpośrednio). |
| `_docs/02_ARCHITEKTURA.md` | j.w. |

---

## 3. Decyzje architektoniczne

1. **Opcja 1 (pełne wpięcie)** — decyzja użytkownika. POS `accept_order` default
   zmienia się z `now()` na silnik. Ręczny `custom_time` nadal wygrywa. Kasjer może
   nadpisać. Semantyka: "brak czasu = inteligentny default" zamiast "brak czasu = natychmiast".
2. **Defensywny fallback** — każda ścieżka ma `try/catch` z fallback na poprzednią
   logikę (null / now+minPrep / +30 / now()). Silnik NIE blokuje zamówienia przy
   wyjątku (np. brak wiersza w `sh_tenant_settings`, błąd DB). Loggujemy do `error_log`.
3. **Channel mapping** — gateway używa PascalCase (`Delivery`), silnik chce lowercase
   (`delivery`). Mapowanie `strtolower($channel)` w gateway. Online i ChoiceQR mają
   już lowercase. POS czyta `order_type` z DB (lowercase).
4. **Scheduled orders bez zmian** — online zostawia surowy `requested_time` (klient
   wybrał godzinę). Gateway waliduje lead time + closing time (bez zmian). Silnik
   używany tylko dla ASAP.
5. **Snajper** — dotknięte tylko linie nadawania `promised_time` + require_once +
   (POS) rozszerzenie SELECT o `order_type`. Inne funkcje w tych plikach nietknięte.
6. **`estimate.php` pozostaje PLANNED** — silnik jest wpięty bezpośrednio w 4 ścieżki
   (nie przez wrapper). `estimate.php` (HTTP GET wrapper) zostaje dla future
   scheduled-order picker UI w storefront.

---

## 4. Weryfikacja (Test E2E)

- **Lint:** `php -l` na 4 backendach → "No syntax errors detected" ×4.
- **Smoke test E2E** (`scripts/_tmp_test_phase_b.cjs`):
  - Login waiter (tenant 1, PIN 1111) → OK
  - `estimate.php?mode=asap&channel=delivery` → `promised_time` za ~43 min
    (`base_prep=25, load_factor=1.1 (1 active order), channel_buffer=15, est=43`)
  - `accept_order` bez `custom_time` — zablokowane przez state machine
    (`pending → accepted` niedozwolone). To NIE jest bug Faza B — silnik zweryfikowany
    przez `estimate.php`. State machine wymaga `new → accepted`.
- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core).
  Błędy 400/401/405 w logach = oczekiwane negatywne testy walidacji. **Brak regresji.**

---

## 5. Otwarte pytania (dla kolejnej sesji)

1. **POS `accept_order` state machine** — test E2E zablokowany bo `pending → accepted`
   niedozwolone (wymaga `new → accepted`). Czy to jest celowe? `pending` pojawia się
   gdy? Może trzeba dodać test z orderem w statusie `new`.
2. **Faza D (food_cost.php)** — kolejna wg planu A→C→B→D→E. Niski priorytet.
3. **Faza E (edit.php + DeltaEngine)** — ostatnia. Edycja zamówienia z kitchen delta w KDS.
4. **Load factor obserwacja** — silnik policzył `load=1.1` przy 1 aktywnym zamówieniu
   (LOAD_DIVISOR=20). Przy 20 zamówieniach `load=2.0` (MAX_LOAD_FACTOR). Czy te progi
   są biznesowo sensowne? Może kalibracja per-tenant w `sh_tenant_settings`.
5. **Online ASAP null fallback** — jeśli silnik rzuci wyjątek, online wraca do `null`
   (badge SLA zielony, breach nie logowany). Czy fallback powinien być `now()` zamiast
   `null` żeby SLA monitoring był ślepy ale przynajmniej nie "always green"?
