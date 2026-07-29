# Sesja: HR Payroll Drawer — live accrual, month nav, rozbieżności

**Data:** 2026-07-28

## Cel

Naprawa rozbieżności w wyświetlaniu danych płacowych w module HR:
1. Poprzednie miesiące nie pokazywały godzin/brutto w drawerze pracownika
2. Nowy pracownik po clock-out nie miał naliczonej wypłaty (brak accrual w ledger)
3. Karta pracownika pokazywała inne godziny niż drawer (snapshot vs live)
4. Otwarta sesja nie pokazywała live elapsed hours

## Pliki dotknięte

- `api/backoffice/hr/engine.php` — dodano `current_rate_minor` subquery do `employees_list`; dodano `advance_installment_repay` action
- `modules/backoffice/hr/js/hr_app.js` — refaktor `loadDrawerStats` (zawsze fetch z API, live accrual z open sessions); `renderCards` dodaje live hours z `clock_status`; `loadDrawerSessions` pokazuje live elapsed dla otwartych sesji; `refreshClockStatus` zapisuje `_clockElapsed` map; month navigation w drawer; naprawa pola `hours` vs `hours_qty`; polling 15s
- `modules/backoffice/hr/css/hr.css` — style month pickera, repay button
- `modules/backoffice/hr/index.html` — month picker HTML w drawer
- `core/TeamPayrollEngine.php` — dodano `employee_id` w payroll report dla matching z drawer

## Decyzje architektoniczne

1. **`loadDrawerStats` zawsze fetchuje z `employee_ledger`** — nie używa cached `emp.current_month_hours`. Zapewnia spójność danych przy każdym otwarciu/nawigacji miesiąca.

2. **Live accrual z otwartej sesji** — `clock_status` zwraca `elapsed_seconds` dla open sessions. Frontend dodaje `liveHrs = elapsed_seconds / 3600` do ledger hours i liczy `liveEarn = liveHrs × current_rate_minor`. Stawka pobierana z `employees_list` (nowe pole `current_rate_minor`).

3. **Timezone: `start_time` z DB jest local (CEST)** — nie append `'Z'` (UTC). Używamy `.replace(' ', 'T')` dla parse jako local time.

4. **`worker_payroll_accrual` musi być uruchamiany ręcznie lokalnie** — clock-out publikuje event do outbox, worker przetwarza go i tworzy wpis w `sh_payroll_ledger`. Na produkcji cron co 1-5 min.

5. **Nie dodano per-employee close period** — `PayrollLedger::lockPeriod` lockuje globalnie. Modyfikacja na per-employee byłaby ryzykowna (Prawo VI Snajper). Zamykanie okresu zostaje w zakładce Wypłaty.

6. **Nie dodano nowego subtabu "Rozliczenie"** — wszystkie logiki już istnieją (`PayrollEngine::calculate`, `bonus_add`, `adjustment_add`, `advance_request`). Zamiast dublować endpointy, podsumowanie rozliczenia można wyświetlić w istniejącym subtabie Ledger używając `payroll_report` z filtrem `employee_id`. Zostawiono do decyzji użytkownika.

## Otwarte pytania

1. **Podsumowanie rozliczenia w drawer** — czy dodać panel podsumowania (gross/deductions/net/advances) nad listą ledgera w istniejącym subtabie? Minimalna zmiana JS, zero backend.
2. **Per-employee close period** — czy potrzebne? Obecnie close jest globalny (cały miesiąc, wszyscy pracownicy). Może wystarczy globalne close + wizualne oznaczenie statusu w drawer.
3. **`worker_payroll_accrual` automatyzacja lokalnie** — czy uruchamiać worker automatycznie przy starcie Apache, czy zostawić ręczne?
