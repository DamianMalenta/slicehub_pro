# Sesja: Zachowanie promised_time klienta w POS accept_order + dynamiczny przycisk Pulse

**Data:** 2026-08-24
**Powiązane:** `2026-08-24_online_promised_time_scheduled_wiring.md` (L1 domknięcie), `2026-08-03_promised_time_wiring_audit.md` (audyt luk L1–L5)
**Konstytucja:** Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)
**Typ:** Fix — domknięcie luki L1 z audytu 2026-08-03.

---

## 1. Cel

Domknięcie luki **L1** zidentyfikowanej w audycie `2026-08-03_promised_time_wiring_audit.md`:

> POS `accept_order` wysyłał `now` (lub null → ASAP) przy akceptacji zamówień online,
> **nadpisując** `promised_time` ustalony przez klienta w checkout (scheduled lub ASAP
> estymacja). Klient, który wybrał np. 19:30, po akceptacji kasjerem "ASAP" widział w
> trackerze czas ~za 40 min zamiast 19:30 — łamanie kontraktu scheduled.

Dodatkowo: dynamiczna etykieta głównego przycisku akceptacji w Pulse sidebar, aby kasjer
widział czas klienta przed kliknięciem i świadomie decydował o nadpisaniu (kafelkami/slotami).

---

## 2. Implementacja

### 2.1 Backend — `api/pos/engine.php` (akcja `accept_order`)

**Przed (linie ~1468–1480):**
```php
if ($parsedTime === null) {
    require_once __DIR__ . '/../../core/PromisedTimeEngine.php';
    $ptChannel = strtolower((string)($orderRow['order_type'] ?? 'delivery'));
    $ptCalc = PromisedTimeEngine::calculate($pdo, $tenant_id, 'asap', $ptChannel);
    $parsedTime = (new DateTime($ptCalc['promised_time'], ...))->format('Y-m-d H:i:s');
}
```
— bezwzględnie nadpisywało istniejący `promised_time` nowym ASAP.

**Po:**
1. Dodano `promised_time` do pre-check SELECT:
   ```sql
   SELECT status, order_type, promised_time FROM sh_orders WHERE id = :oid AND tenant_id = :tid
   ```
2. Logika zachowania:
   ```php
   if ($parsedTime === null) {
       if (!empty($orderRow['promised_time'])) {
           // Klient ustalił czas w checkout — ZACHOWAJ
           $parsedTime = $orderRow['promised_time'];
       } else {
           // Brak promised_time (POS-created) — wylicz ASAP przez silnik
           $ptCalc = PromisedTimeEngine::calculate($pdo, $tenant_id, 'asap', $ptChannel);
           $parsedTime = ...;
       }
   }
   ```

**Zachowanie kasjer nadpisuje jawnie:** gdy kasjer klika kafelkę (+10m/+15m/etc) lub
wybiera slot, `$parsedTime !== null` → istniejący `promised_time` jest nadpisywany
jawnym wyborem kasjera (niezmienione).

### 2.2 Frontend — `modules/pos/js/pos_ui.js` (Pulse sidebar)

**Przed:** przycisk `pulse-accept-now` miał statyczną etykietę "ASAP", nadpisywaną
później przez `_loadSlotsFromBackend` na "ASAP (~Nmin)".

**Po:** dynamiczna etykieta zależna od `o.promised_time`:
- **Scheduled** (>60min w przyszłość): `AKCEPTUJ NA HH:MM`
- **ASAP z estymacją** (≤60min): `AKCEPTUJ ASAP (HH:MM)`
- **Brak promised_time**: `AKCEPTUJ ASAP`

Dodano atrybut `data-promised` do przycisku — `_loadSlotsFromBackend` sprawdza go i
**nie nadpisuje** dynamicznej etykiety generycznym "ASAP (~Nmin)" dla zamówień z
ustalonym czasem klienta.

Kafelki (+10m/+15m/+20m/+30m/+45m) i slot picker pozostają jako opcjonalne nadpisanie.

---

## 3. Weryfikacja

| Sprawdzenie | Wynik |
|---|---|
| `php -l api/pos/engine.php` | ✅ No syntax errors |
| `deno lint` (108 plików) | ✅ Checked 108 files, 0 błędów |
| `node scripts/run_test_runner_headless.cjs` | ✅ **62/62 PASS** (`"badge": "✓ ALL 62 PASSED"`) |

> Test runner uruchomiony z `$env:CHROME_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe"`.
> Błędy 400/401/404/405 w konsoli browsera to oczekiwane odpowiedzi negatywne z testów.

---

## 4. Status luk z audytu 2026-08-03 po tej sesji

| Luka | Status |
|---|---|
| ✅ L1 (POS accept nadpisuje promised_time klienta) | **DOMKNIĘTE** (PR #66) |
| ✅ L2 (online scheduled bez walidacji) | DOMKNIĘTE (PR #63, sesja 2026-08-24) |
| ✅ L3 (silnik scheduled = martwy kod) | DOMKNIĘTE (PR #63, sesja 2026-08-24) |
| ❌ L4 (gateway scheduled duplikat logiki) | otwarte, niski priorytet |
| ❌ L5 (POS process_order fallback `now()`) | otwarte, niski priorytet |

---

## 5. Pliki zmienione

| Plik | Zmiana |
|---|---|
| `api/pos/engine.php` | + `promised_time` w pre-check SELECT; logika zachowania istniejącego czasu |
| `modules/pos/js/pos_ui.js` | dynamiczna etykieta przycisku akceptacji + `data-promised` guard |
| `_docs/sessions/2026-08-24_pos_accept_preserve_promised_time.md` | ten dokument |

**Migracja SQL:** brak.

---

## 6. Domknięcie sesji

**PR #66:** https://github.com/DamianMalenta/slicehub_pro/pull/66

**Sesja zamknięta.** Pozostałe luki (L4/L5) udokumentowane — niski priorytet, do osobnej sesji.
