# 2026-08-24 — Storefront Prep Time Settings (edycja PromisedTime w Online Studio)

**Commit:** `662643c` (gałąź `feature/pos-time-control-and-gps-eta`)
**PR:** #65 — https://github.com/DamianMalenta/slicehub_pro/pull/65
**Status:** OPEN (gałąź współdzielona z równoległą sesją „Centrum Kontroli Czasu + GPS ETA")

## Kontekst

Audyt `core/PromisedTimeEngine.php` (patrz raport w tej samej sesji) wykazał, że
parametry `base_prep_minutes` i `min_lead_time_minutes` w tabeli `sh_tenant_settings`
 były ustawiane **wyłącznie przez skrypty seed/install** — brak było możliwości
edycji z panelu przez właściciela lokalu. Jedynym parametrem promised-time
edytowalnym z UI były godziny otwarcia (`opening_hours_json`) w zakładce Storefront
modułu Online Studio.

**Luka udokumentowana wcześniej w:**
- `_docs/sessions/2026-08-03_promised_time_wiring_audit.md` — audyt wpięcia silnika
  (sekcja 3.1: „base_prep_minutes z sh_tenant_settings (default 25)", bez wzmianki o UI)
- `_docs/RAPORT_OBCIAZENIE_I_ELASTYCZNOSC_MENU.md` §3.4 — tabelka „uprawnienia managera"
  wymienia `base_prep_minutes` i `min_lead_time_minutes` jako konfigurowalne przez
  `tenant_settings`, ale bez wskazania modułu UI.
- `_docs/00_PAMIEC_SYSTEMU.md` linia 472 — sekcja PromisedTimeEngine opisuje
  `base_prep_minutes` jako parametr tenanta, ale nie wspominada edycji z panelu.

## Cel

Dodać możliwość edycji `base_prep_minutes` (5–120 min) i `min_lead_time_minutes`
(15–240 min) w panelu Online Studio → zakładka Storefront, obok istniejących
ustawień preorder i godzin otwarcia.

## Zmiany

### Backend: `api/online_studio/engine.php`

**`storefront_settings_get`** (ok. linia 1193):
- Rozszerzono zapytanie SQL z `opening_hours_json` o `base_prep_minutes` i
  `min_lead_time_minutes` (wiersz `setting_key=''`).
- Fallbacki PHP zgodne z `core/PromisedTimeEngine.php`: `basePrepMinutes ?? 25`,
  `minLeadTimeMinutes ?? 30` (`DEFAULT_BASE_PREP=25`, `DEFAULT_MIN_LEAD_TIME=30`).
- Nowa sekcja `promisedTime` w odpowiedzi JSON:
  ```json
  "promisedTime": {
    "basePrepMinutes": 25,
    "minLeadTimeMinutes": 30
  }
  ```

**`storefront_settings_save`** (ok. linia 1400):
- Nowa sekcja `promisedTime` w paylodzie wejściowym:
  - `basePrepMinutes` — int, clamp 5–120, walidacja poza zakresem → błąd.
  - `minLeadTimeMinutes` — int, clamp 15–240, walidacja poza zakresem → błąd.
- Zapis atomowy w transakcji SQL z `SELECT ... FOR UPDATE` (blokada wiersza
  `tenant_id + setting_key=''`).
- Obsługa legacy tenantów bez wiersza `setting_key=''` — `INSERT` zamiast
  `UPDATE`.
- Częściowy zapis: gdy przekazano tylko jedną kolumnę, druga pozostaje
  nietknięta (dynamiczny `SET` klauzuli).

### Frontend: `modules/online_studio/js/tabs/storefront.js`

- Nowa sekcja UI „Czasy zamówień (PromisedTime)" z ikoną `fa-stopwatch`,
  umieszczona między sekcją „Kanały sprzedaży" a „Mapa".
- Dwa pola liczbowe:
  - `#sf-base-prep-min` — „Bazowy czas przygotowania (minuty)", min=5, max=120,
    step=5, default=25.
  - `#sf-min-lead-min` — „Minimalne wyprzedzenie zamówień na godzinę (minuty)",
    min=15, max=240, step=5, default=30.
- `harvest()` — zbiera wartości z pól do `payload.promisedTime`.
- `hydrate()` — wstawia wartości z serwera z fallbackami 25/30.
- Nagłówek PHPDoc zaktualizowany o nowe kolumny i parametry.

## Weryfikacja

- `php -l api/online_studio/engine.php` → No syntax errors detected.
- `deno lint` → Checked 108 files, 0 błędów.
- `node scripts/run_test_runner_headless.cjs` → **62/62 PASS** (0 fail, 0 warn).
  - `CHROME_PATH="C:\Program Files\Google\Chrome\Application\chrome.exe"` (Windows).

## Zakres commita

Scommitowano **wyłącznie** 3 pliki (commit `662643c`):
- `api/online_studio/engine.php`
- `modules/online_studio/js/tabs/storefront.js`
- `_docs/sessions/2026-08-24_storefront_prep_time_settings.md` (ten dokument)

Pozostałe zmiany na gałęzi `feature/pos-time-control-and-gps-eta` (GPS ETA
Haversine w `api/online/engine.php`, akcja `shift_time` w `api/pos/engine.php`,
`order.delayed` w `NotificationDispatcher.php` i `OrderEventPublisher.php`,
modal Centrum Kontroli Czasu w POS) należą do równoległej sesji (commit
`9589bdb`) i są częścią tego samego PR #65.

## Domknięte luki dokumentacyjne

Ta implementacja domyka lukę opisaną w audytach:
- **`2026-08-03_promised_time_wiring_audit.md`** — parametry `base_prep_minutes`
  i `min_lead_time_minutes` były czytane przez silnik ale nieedytowalne z UI.
  Teraz właściciel lokalu może je zmienić w Online Studio → Storefront →
  „Czasy zamówień (PromisedTime)".
- **`RAPORT_OBCIAZENIE_I_ELASTYCZNOSC_MENU.md` §3.4** — tabelka „uprawnienia
  managera" wymieniała te kolumny jako konfigurowalne przez `tenant_settings`,
  ale bez modułu UI. Teraz mają dedykowaną sekcję w Storefront.

## Powiązane

- Raport audytu PromisedTimeEngine (ta sama sesja, wcześniej w konwersacji).
- `core/PromisedTimeEngine.php` — silnik czyta `base_prep_minutes` i
  `min_lead_time_minutes` z `sh_tenant_settings` (linia 54–65), fallbacki
  `DEFAULT_BASE_PREP=25` / `DEFAULT_MIN_LEAD_TIME=30`.
- Schema: `database/migrations/001_init_slicehub_pro_v2.sql` linie 65–81
  (`sh_tenant_settings` — kolumny `base_prep_minutes` INT DEFAULT 25,
  `min_lead_time_minutes` INT DEFAULT 30).
