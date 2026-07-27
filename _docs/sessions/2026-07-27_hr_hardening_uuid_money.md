# 2026-07-27 · HR hardening + prymitywy SSOT (Money / Uuid / DomainError)

## Cel

Domknięcie audytu HR/Payroll: wdrożenie poprawek KRYTYCZNYCH/WYSOKICH (1–4) oraz
MEDIUM/LOW z audytu, a następnie konsolidacja rozsypanych po repo kopii
generatora UUID do jednego prymitywu.

## Pliki dotknięte

### Nowe prymitywy (`core/`)

| Plik | Rola |
|---|---|
| `core/Money.php` | SSOT arytmetyki pieniądza: PLN → grosze (HALF_UP), formatowanie, sanity cap. Zero float w ścieżce zapisu. |
| `core/Uuid.php` | SSOT generowania (`v4()`, CSPRNG) i walidacji UUID + deterministyczne warianty. |
| `core/DomainError.php` | Rozbicie wyjątku domenowego na `code` (ASCII) + `message` (UTF-8). |
| `core/HrRoles.php` | Kanoniczna lista ról managerskich (koniec z powielaną listą w routerze). |

### Silniki HR

- `core/PayrollLedger.php` — sanity cap `Money` w `record()`, `Uuid` zamiast prywatnych kopii.
- `core/AdvanceEngine.php` — `Money` + `Uuid`; `deterministicTail()` zostaje jako logika domenowa.
- `core/MealEngine.php` — `Money` + `Uuid`; **opcjonalny `idempotency_key`** dla `meal_record`.
- `core/HrClockEngine.php` — `Uuid::v4()`.

### API

- `api/backoffice/hr/engine.php` — `hrFailFromDomain()`, jeden `hrMoneyMinor()` na całą
  ścieżkę parsowania kwot, opcjonalne `period_year`/`period_month` dla `bonus_add`
  i `adjustment_add`, `idempotency_key` w `meal_record`, `hrRequireManager()` z `HrRoles`.
- `api/staff/payroll.php`, `api/dashboard/team_payroll.php` — usunięte wildcard-CORS.

### UI

- `modules/backoffice/hr/js/hr_app.js` — stan `_activeTab` + jeden dyspozytor
  `refreshActiveTab()` po każdej mutacji; `newIdempotencyKey()`; zaksięgowany okres
  pokazywany w toastach i modalu ledgera.

### Migracje / skrypty

- `database/migrations/048_variant_scales.sql` — guard rozbity per obiekt (kolumna / indeks / FK).
- `scripts/nuclear_reset.php` — ostrzeżenie o braku profili HR i pustym payrollu.
- `scripts/test_hr_payroll_ledger.php` — testy regresji (golden vectors UUID, konwersje `Money`, ledger).

### Sweep UUID (usunięte kopie algorytmu)

`core/PayrollLedger.php`, `core/AdvanceEngine.php`, `core/MealEngine.php`,
`core/HrClockEngine.php`, `core/SettlementEngine.php`, `core/KdsAcceptRouting.php`,
`api/orders/panic.php`, `api/orders/sla_monitor.php`, `api/orders/checkout.php`,
`api/orders/edit.php`, `api/courses/engine.php`, `api/delivery/dispatch.php`,
`api/gateway/intake.php`, `api/online/engine.php`, `api/tables/engine.php`,
`scripts/seed_demo_all.php`, `scripts/seed_final_test.php`,
`scripts/seed_ultimate_delivery.php`, `scripts/test_settlement_engine.php`.

Weryfikacja: `grep "0x0f) | 0x40"` w `core/ api/ scripts/ tests/` → **1 trafienie**
(`core/Uuid.php` — SSOT).

## Decyzje architektoniczne

### D1. `core/Uuid.php` jest infrastrukturą, nie silnikiem domenowym

Prawo VI zakazuje **JOIN-ów cross-silo po numerycznym `id`** — nie zakazuje współdzielenia
prymitywów technicznych. `Uuid` należy do tej samej klasy co `db_config.php` i
`auth_guard.php`: wszystkie silosy zależą od niego, żaden silos nie zależy od
**logiki domenowej** innego silosu. Graf zależności pozostaje acykliczny i gwiaździsty
(silos → `core/` infrastruktura), zero krawędzi silos → silos.

Konsekwencja: sweep obejmuje **wszystkie** silosy, w tym `SettlementEngine`
i `KdsAcceptRouting`. Wcześniejsze odłożenie ich „bo inny silos" było błędną
interpretacją Prawa VI i zostało cofnięte.

### D2. `api/tables/engine.php` — poprawka bezpieczeństwa przy okazji

Ten plik generował `order_id` przez **`mt_rand()`** (nie-CSPRNG, przewidywalny przy znajomości
seeda). Po migracji na `Uuid::v4()` identyfikatory zamówień pochodzą z `random_bytes()`.

### D3. Aliasy w skryptach zamiast przepisywania call-site'ów

W `scripts/seed_demo_all.php` (closure `$uuid4` przekazywana przez `use` do kilkunastu
seedów) i w skryptach testowych helper **deleguje** do `Uuid::v4()` zamiast być usuwany.
Duplikat *algorytmu* znika — o to chodziło; churn na kilkunastu call-site'ach seeda nie
wnosi wartości (Prawo VI, Zasada Snajpera).

### D4. Idempotencja `meal_record` po stronie klienta

`meal_record` był jedyną mutacją pieniężną bez idempotencji (double-click = podwójne
potrącenie). Klucz generuje UI (`newIdempotencyKey()`), backend mapuje go na
deterministyczny `entry_uuid` — retry zwraca istniejący wpis, zero duplikatu.

## Otwarte pytania

1. ~~**`fn_allocate_hours` (HR-6)**~~ — **ZAMKNIĘTE (2026-07-27, sesja 2).** Zaimplementowane
   jako `core/PayrollAllocator.php` (PHP, nie SQL UDF): `splitByPeriod()` tnie sesję na
   granicach miesięcy, `allocate()` dzieli kwotę proporcjonalnie (metoda największych reszt,
   zero groszowego wycieku). `worker_payroll_accrual.php` przepisany na segmenty.
   Patrz `_docs/18_BACKOFFICE_HR_LOGIC.md §15`.
2. ~~**`DROP COLUMN sh_users.hourly_rate`**~~ — **ZAMKNIĘTE (2026-07-27, sesja 2).**
   Migracja `061_drop_users_hourly_rate.sql` wykonana. `seed_demo_all.php` i
   `tests/payroll_engine_rewrite_parity.php` przestawione na `sh_employee_rates`.
   Migracja `041` ma dynamic-SQL-guards na re-run po 061.
3. **`api/payments/settle.php`** — nadal ORPHAN bez UI (Prawo VIII). Decyzja:
   wpiąć w POS albo świadomie usunąć.
4. **Self-service ekipy (`modules/ekipa/`, Faza 5)** — `advance_request` z poziomu
   pracownika (nie managera) wymaga osobnego trybu auth (PIN zamiast sesji managera).

## Sesja 2 (2026-07-27, ciąg dalszy) — HR-6, migracja 061, idempotentność migracji

### Nowe pliki

| Plik | Rola |
|---|---|
| `core/PayrollAllocator.php` | Rozbija sesję na segmenty miesięczne (`splitByPeriod`) + proporcjonalny podział kwoty (`allocate`) metodą największych reszt. Zero groszowego wycieku (fuzz 500× w testach). |
| `core/MealEngine.php` | Rejestruje posiłki pracownicze z atomowym potrąceniem w ledgerze. Idempotentny przez `idempotency_key`. |
| `database/migrations/061_drop_users_hourly_rate.sql` | DROP `sh_users.hourly_rate` (faza Contract wzorca Expand-Contract). |

### Zmiany w silnikach

- `core/PayrollLedger.php` — sanity cap przez `Money::isWithinCap()` zamiast `PHP_INT_MIN/MAX`.
- `core/AdvanceEngine.php` — `MAX_AMOUNT_MINOR` aliasuje `Money::MAX_MINOR`; `synthUuid()` → `Uuid::deterministic()`.
- `core/PayrollEngine.php` — fix #2: wpisy księgowane wstecz (period ≠ created_at month) widoczne w raporcie okresu (klauzula OR w SQL).

### Zmiany w workerze

- `scripts/worker_payroll_accrual.php` — sesje przecinające miesiące dzielone przez `PayrollAllocator::splitByPeriod()`; segmenty z zamkniętych okresów przesuwane do najbliższego otwartego (`resolveOpenPeriod`); `Uuid::deterministic` dla segmentów split.

### Migracje — idempotentność

Migracje **010, 041, 047, 048, 053, 054, 055** przepisane na wzorzec `information_schema` guard per-obiekt. Każda kolumna / indeks / FK ma własny guard — re-run po przerwaniu jest bezpieczny.

Migracja **041** — odwołania do `sh_users.hourly_rate` (backfill sekcje 3 i 4) opakowane w dynamic-SQL-guards, żeby re-run łańcucha po migracji 061 był no-opem.

### Seed / testy

- `scripts/seed_demo_all.php` — stawki z mapy PHP (`$HR_RATES_MINOR`) do `sh_employee_rates`, nie z `sh_users.hourly_rate`; INSERT bez kolumny `hourly_rate`.
- `scripts/nuclear_reset.php` — weryfikacja profili HR i stawek po resecie.
- `tests/payroll_engine_rewrite_parity.php` — `legacyCalculate` czyta z `sh_employee_rates` zamiast `sh_users.hourly_rate`.
- `scripts/test_hr_payroll_ledger.php` — 58/58 PASS (sekcja A, bez bazy).
- `scripts/migrate_deductions_to_ledger.php` — `Uuid::deterministic()` zamiast lokalnego `legacyLedgerUuid()`.

### Autoryzacja

- `api/dashboard/team_payroll.php` — dodany gate `HrRoles::isManager()` (agregat płac = dane wrażliwe).
- `api/staff/payroll.php` — gate `HrRoles::isManager()` dla dostępu do cudzych danych płacowych.

### Frontend HR

- `modules/backoffice/hr/js/hr_app.js` — nowe modale: zaliczki (advance), wpisy ledgera (bonus/korekta/posiłek); `closePeriod`; filtry statusu zaliczek; tab switching; `newIdempotencyKey()`.
- `modules/ui_shell/sh_mobile_shell.css` — table overflow fix (direct child `>` zamiast descendant).

## Sesja 3 (2026-07-27, wieczór) — UI hardening, weryfikacja API

Użytkownik zgłosił wrażenie, że moduł HR „nie działa". Diagnoza przez HTTP i code review:

### Naprawione błędy

| Plik | Błąd | Naprawa |
|---|---|---|
| `modules/ui_shell/sh_mobile_shell.css:258` | `main table { display: block }` łamało układ kolumnowy tabel HR na < 900px — tabele w `.hr-table-wrap` traciły wyrównanie. | `main > table` (direct child) — tabele w wrapperach wykluczone. |
| `modules/backoffice/hr/js/hr_app.js:803` | `wire()` nie wywoływał `switchTab('employees')` — stan zakładek zależał tylko od klasy `active` w HTML. | Dodano jawne `switchTab('employees')` na końcu `wire()`. |

### Weryfikacja API (tenant 2, dane demo)

Wszystkie endpointy HR przetestowane przez HTTP (skrypt PHP z `curl_exec`):

| Akcja | HTTP | Wynik |
|---|---|---|
| `login` (kiosk, t=2, pin=0000) | 200 | Token JWT OK |
| `employees_list` | 200 | 4 pracowników, wszyscy z PIN |
| `payroll_report` (2026-07) | 200 | 4 pracowników, 63.23h, 1379.94 PLN brutto |
| `payroll_report` (2026-06) | 200 | Pusty okres, struktura OK |
| `advances_list` | 200 | 3 zaliczki |
| `payroll_period_status` (2026-07) | 200 | 6 wpisów, okres otwarty |
| `hr_users_unlinked` | 200 | 0 (wszyscy powiązani) |
| `clock_status` | 200 | 2 otwarte sesje |

Assets (JS/CSS) serwowane 200, zawartość zgodna z plikiem na dysku (brak stale cache).
Test regresji: **58 PASS / 0 FAIL**.

## Test (E2E)

```powershell
# Lint
Get-ChildItem -Recurse -Include *.php -Path core,api,scripts | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }

# Regresja HR (bez bazy — sekcja A)
C:\xampp\php\php.exe scripts\test_hr_payroll_ledger.php

# Regresja HR (z bazą — sekcja A + B)
C:\xampp\php\php.exe scripts\test_hr_payroll_ledger.php --db

# Parity PayrollEngine
C:\xampp\php\php.exe tests\payroll_engine_rewrite_parity.php
```
