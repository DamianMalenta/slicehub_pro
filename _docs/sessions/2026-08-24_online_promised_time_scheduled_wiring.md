# Sesja: Wpięcie wyboru godziny realizacji (PromisedTimeEngine / scheduled) w sklepie online

**Data:** 2026-08-24
**Powiązane:** `2026-08-03_promised_time_wiring_audit.md` (audyt luk L2 + L3), `2026-07-29_phase_b_promised_time_engine.md` (Faza B — ASAP)
**Konstytucja:** Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)
**Typ:** Implementacja — domknięcie luk L2 + L3 z audytu 2026-08-03.

---

## 1. Cel

Domknięcie dwóch luk zidentyfikowanych w audycie `2026-08-03_promised_time_wiring_audit.md`:

- **L2** — Online `guest_checkout` scheduled zapisywał surowy `requested_time` bez walidacji
  (klient mógł wpisać godzinę z przeszłości / poza godzinami otwarcia / za 2 minuty).
- **L3** — Tryb `scheduled` `PromisedTimeEngine` był martwym kodem (0 produkcyjnych
  konsumentów; jedyny wrapper `api/orders/estimate.php` jest za `auth_guard`/JWT i
  nieosiągalny dla gościa ze sklepu online).

Dodatkowo: przebudowa UI checkoutu z pola tekstowego "19:30" (tylko takeaway) na
intuicyjny selector ASAP / "Wybierz godzinę" z dropdownem slotów — dla **delivery i takeaway**.

---

## 2. Stan przed implementacją (audyt)

### Co już istniało (nie dublowano)

| Element | Lokalizacja | Status |
|---|---|---|
| `PromisedTimeEngine` (asap + scheduled) | `core/PromisedTimeEngine.php` | ✅ pełny, 2 tryby, 2 gaty |
| `sh_orders.promised_time DATETIME NULL` | migracja `001` | ✅ w schemacie |
| `sh_tenant_settings`: `opening_hours_json`, `base_prep_minutes` (25), `min_lead_time_minutes` (30) | migracja `001` | ✅ w schemacie |
| ASAP w `guest_checkout` | `api/online/engine.php` (closure w INSERT) | ✅ wpięte (Faza B) |
| `api/orders/estimate.php` (wrapper asap/scheduled/slots) | `api/orders/` | ❌ ORPHAN — za JWT, 0 call-site'ów |
| Pole tekstowe `requestedTime` "19:30" | `online_checkout.js` | ⚠️ tylko takeaway, brak walidacji |
| `get_doorway` (godziny otwarcia + status) | `api/online/engine.php` | ✅ istniało |

### Migracja SQL: **NIE wymagana** — wszystkie kolumny istniały w `001_init_slicehub_pro_v2.sql`.

---

## 3. Implementacja

### 3.1 Backend — publiczny wrapper silnika (L3 fix)

**Plik:** `api/online/engine.php` — nowa akcja `estimate_time` (wstawiona między `delivery_zones` a `guest_checkout`).

`api/orders/estimate.php` jest za `auth_guard.php` (JWT) → nieosiągalny dla gościa ze sklepu.
Dodano publiczny odpowiednik w publicznym engine'u sklepu (tenant z POST body, jak inne akcje).

**Modes:**
- `asap` → `PromisedTimeEngine::calculate('asap')` — estymacja `prep × load + channel_buffer`
- `scheduled` → `PromisedTimeEngine::calculate('scheduled')` — walidacja lead-time + business-hours
- `slots` → generator slotów co `interval` min (default 15, zakres 5–60), **filtrowany po godzinach otwarcia**:
  - start = zaokrąglony w górę ASAP estimate
  - każdy slot sprawdzany gegen `opening_hours_json[dayKey]` (open ≤ slot < close)
  - brak konfiguracji godzin = fail-open (zgodne z silnikiem)
  - limit 400 prób / `count` slotów (max 24) — bezpieczny guard przeciw pętli

### 3.2 Backend — walidacja scheduled w checkoutcie (L2 fix)

**Plik:** `api/online/engine.php` — `guest_checkout`.

**Przed:** closure w INSERT zapisywała `if ($requestedTime !== '') return $requestedTime` — surowy string, 0 walidacji.

**Po:** obliczenie `promised_time` **przed transakcją** (krok 3.6):
- `requested_time` niepusty → `PromisedTimeEngine::calculate('scheduled')` → błąd `InvalidArgumentException` **blokuje checkout** z konkretną wiadomością (lead-time / business-hours)
- `requested_time` pusty → `PromisedTimeEngine::calculate('asap')` (jak Faza B)
- inny `Throwable` → fallback `null` (nie blokuj checkoutu, jak wcześniej)

Zmienna `$resolvedPromisedTime` przekazywana do bindera INSERT (usunięto closure).

### 3.3 Frontend — API wrapper

**Plik:** `modules/online/js/online_api.js` — nowa metoda `estimateTime(payload)`.

### 3.4 Frontend — UI checkoutu

**Plik:** `modules/online/js/online_checkout.js` + `modules/online/css/style.css`.

**Przed:** pole tekstowe `requestedTime` (placeholder "np. 19:30", maxlength 5) **tylko dla takeaway**. Delivery nie miało wyboru czasu wcale.

**Po:** nowy fieldset "Czas realizacji" dla **delivery i takeaway**:
- toggle radiowy: **"Jak najszybciej" (ASAP)** | **"Wybierz godzinę" (scheduled)**
- ASAP: pokazuje estymowany czas (`~N min (ok. HH:MM)`) z `estimateTime({mode:'asap'})`
- scheduled: pokazuje `<select>` slotów ładowany lazy z `estimateTime({mode:'slots'})`
  - sloty filtrowane po godzinach otwarcia (backend)
  - pusta lista = "Brak dostępnych godzin — wybierz ASAP" (lokal zamknięty)
- walidacja frontend: scheduled bez wybranego slotu = błąd przed wysyłką
- `requested_time` wysyłane jako ISO (`Y-m-d\TH:i`) z backendu — backend waliduje ponownie

**Stare pole tekstowe usunięte** — zastąpione structurą. `saved.requestedTime` w localStorage pozostaje kompatybilne (nie wpływa na nowe UI).

---

## 4. Weryfikacja

| Sprawdzenie | Wynik |
|---|---|
| `php -l api/online/engine.php` | ✅ No syntax errors |
| `php -l core/PromisedTimeEngine.php` | ✅ No syntax errors |
| `deno lint` (108 plików) | ✅ Checked 108 files, 0 błędów |
| `node scripts/run_test_runner_headless.cjs` | ✅ **62/62 PASS** (`"badge": "✓ ALL 62 PASSED"`) |

> Test runner uruchomiony z `$env:CHROME_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe"`
> (skrypt hardcoduje Linux path `/usr/local/bin/google-chrome` — na Windows trzeba override).
> Błędy 400/401/404/405 w konsoli browsera to oczekiwane odpowiedzi negatywne z testów walidacyjnych.

---

## 5. Mapa call sites po zmianach (aktualizacja audytu 2026-08-03)

| # | Ścieżka | Silnik odpala? | Status po 2026-08-24 |
|---|---|---|---|
| 1 | POS `process_order` | ❌ NIE (fallback `now()`) | L5 — niski priorytet, pillsy maskują |
| 2 | POS `accept_order` | ✅ **TAK (zachowuje istniejący)** | **L1 DOMKNIĘTE** (PR #66, sesja `2026-08-24_pos_accept_preserve_promised_time.md`) |
| 3 | Online `guest_checkout` ASAP | ✅ TAK | bez zmian |
| 4 | Online `guest_checkout` scheduled | ✅ **TAK (nowość)** | **L2 DOMKNIĘTE** |
| 5 | Gateway `intake` ASAP | ✅ TAK | bez zmian |
| 6 | Gateway `intake` scheduled | ❌ NIE (duplikat inline) | L4 — niski priorytet |
| 7 | ChoiceQR webhook | ✅ TAK | bez zmian |
| 8 | `api/orders/estimate.php` | ❌ ORPHAN (JWT) | pozostaje dla autoryzowanych klientów |
| 9 | **`api/online/engine.php#estimate_time` (nowość)** | ✅ **TAK** | **L3 DOMKNIĘTE** — publiczny wrapper |

**Luki pozostałe:** L1 (POS accept "ZAAKCEPTUJ"), L4 (gateway scheduled duplikat), L5 (POS process_order fallback). Niski priorytet — poza zakresem tej sesji.

---

## 6. Decyzje architektoniczne

1. **Dlaczego nowa akcja w `engine.php` a nie przepięcie na `estimate.php`?**
   `estimate.php` wymaga `auth_guard.php` (JWT). Sklep online jest publiczny (gość, brak sesji).
   Przepięcie wymagałoby rozgałęzienia autoryzacji w `estimate.php` lub wyodrębnienia silnika
   do osobnego publicznego endpointu. Dodanie akcji do istniejącego publicznego engine'a
   jest spójne z architekturą (tenant z POST body, jak `get_doorway`, `cart_calculate`, etc.)
   i nie dubluje logiki — wywołuje ten sam `PromisedTimeEngine`.

2. **Dlaczego sloty filtrowane po stronie backendu a nie frontendu?**
   Frontend dostałby `opening_hours` z `get_doorway` i mógłby filtrować. Ale SSOT walidacji
   to silnik (lead-time + business-hours gaty). Filtrowanie slotów w backendzie gwarantuje
   że klient nie wybierze slotu, który backend odrzuci — single source of truth. Frontend
   tylko renderuje to, co backend zwróci.

3. **Dlaczego walidacja scheduled przed transakcją a nie w niej?**
   Błąd walidacji (za wcześnie / poza godzinami) to błąd klienta, nie błąd systemu.
   Surfacing z `onlineResponse(false, null, $e->getMessage())` przed `beginTransaction()`
   daje czystą wiadomość bez rollback noise. ASAP fallback `null` przy `Throwable` pozostaje
   w bloku catch (nie blokuj checkoutu gdy silnik niedostępny — jak Faza B).

4. **Dlaczego `interval=15` a nie konfigurowalne w UI?**
   15 min to rozsądny default dla restauracji. `interval` i `count` są parametrami API
   (zakres 5–60 / 4–24) — przyszła konfiguracja w Settings może je nadpisać bez zmian UI.

---

## 7. Pliki zmienione

| Plik | Zmiana |
|---|---|
| `api/online/engine.php` | + akcja `estimate_time` (126 linii); L2 fix w `guest_checkout` (walidacja scheduled przed TX) |
| `modules/online/js/online_api.js` | + metoda `estimateTime(payload)` |
| `modules/online/js/online_checkout.js` | przebudowa UI: toggle ASAP/scheduled + selector slotów (delivery + takeaway) |
| `modules/online/css/style.css` | + style `.checkout-scheduled` i `#checkout-time-group` |
| `_docs/sessions/2026-08-24_online_promised_time_scheduled_wiring.md` | ten dokument |

**Migracja SQL:** brak — wszystkie kolumny istniały w `001_init_slicehub_pro_v2.sql`.

---

## 8. Domknięcie sesji (2026-08-24)

**PR #63:** https://github.com/DamianMalenta/slicehub_pro/pull/63 — **MERGED** do `main` (commit `7684e48`).

**Weryfikacja post-merge na `main`:**
- `node scripts/run_test_runner_headless.cjs` → **62/62 PASS** (`✓ ALL 62 PASSED`)
- Uwaga: przy wielokrotnym uruchomieniu test runnera pod rząd gateway test może trafić 429 (rate-limit per-minute z `api/gateway/intake.php`). To **flaky** — nie regression. Po odczekaniu ~60s (reset okna) testy przechodzą czysto. Moje zmiany dotyczyły `api/online/engine.php`, nie gateway.

**Status luk z audytu 2026-08-03 po tej sesji:**
- ✅ L2 (online scheduled bez walidacji) — **DOMKNIĘTE**
- ✅ L3 (silnik scheduled = martwy kod) — **DOMKNIĘTE**
- ✅ L1 (POS accept "ZAAKCEPTUJ" wysyła `now`) — **DOMKNIĘTE** w sesji `2026-08-24_pos_accept_preserve_promised_time.md` (PR #66)
- ❌ L4 (gateway scheduled duplikat logiki) — otwarte, niski priorytet
- ❌ L5 (POS process_order fallback `now()`) — otwarte, niski priorytet

**Sesja zamknięta.** Pozostałe luki (L1/L4/L5) udokumentowane w sekcji 5 — do osobnej sesji.
