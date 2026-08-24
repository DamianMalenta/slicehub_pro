# Seed HR fix — pracownicy w kadrach + sh_order_payments

**Data:** 2026-08-24
**Sesja:** naprawa seed_pizzaforno_ops.sql — dodanie sh_employees, sh_employee_rates, sh_work_sessions, sh_payroll_ledger, sh_order_payments

---

## Cel

Po wgraniu `seed_pizzaforno_ops.sql` na uti.pl pracownicy nie pojawiali się w zakładce Kadry. Diagnoza: moduł HR czyta z `sh_employees` (nie z `sh_users`), a seed tworzył tylko konta logowania w `sh_users` bez profili HR. Dodatkowo brakowało `sh_order_payments`, `sh_work_sessions` i `sh_payroll_ledger`.

## Pliki dotknięte

- `scripts/seed_pizzaforno_build.py` — `build_ops_sql()`: dodano sekcje 2b (sh_employees), 2c (sh_employee_rates), 2d (sh_work_sessions + sh_payroll_ledger), 7b (sh_order_payments) + cleanup + validation. `build_sql()` (full mode): dodano sekcje 2.19-2.21 (tenant_settings + users + drivers + HR + order_audit + order_payments) + cleanup + validation.
- `scripts/seed_pizzaforno_ops.sql` — zregenerowany (40.6 KB → 432.8 KB)
- `scripts/seed_pizzaforno.sql` — zregenerowany (368.4 KB → 764.6 KB)
- `scripts/seed_pizzaforno_menu.sql` — zregenerowany (bez zmian treści, header updated)

## Decyzje architektoniczne

### 1. Root cause: sh_users ≠ sh_employees

Moduł kadry (`api/backoffice/hr/engine.php` → `employees_list`) czyta z `sh_employees` z `LEFT JOIN sh_users`. Samo konto w `sh_users` bez profilu HR **nie pojawia się** w kadrach. `seed_demo_all.php` (tenant 1) tworzy profile HR poprawnie — `seed_pizzaforno_ops.sql` (tenant 2) nigdy ich nie miał.

### 2. Dodane tabele HR do ops seed

| Tabela | Liczba wierszy | Opis |
|---|---|---|
| `sh_employees` | 5 | Profile HR (EMP-FORNO-001..005) z hire_date=DATE_SUB(CURDATE(), INTERVAL 6 MONTH) |
| `sh_employee_rates` | 4 | Stawki godzinowe (manager 28, waiter 22, cook 25, driver 20 PLN/h; owner bez stawki) |
| `sh_work_sessions` | ~320 | 4 mies × ~20 shiftów per pracownik (8h, Mon-Fri, dynamiczne daty DATE_SUB(NOW(),...)) |
| `sh_payroll_ledger` | ~256 | Wpisy work_earnings per sesja (tylko dla pracowników ze stawką) |
| `sh_order_payments` | ~5 | Płatności dla zamówień card/online_paid (skip to_pay) |

### 3. Dynamiczne daty w work_sessions

Daty sesji używają `DATE_SUB(NOW(), INTERVAL X DAY)` — świeże po każdym re-seedzie. UUID deterministyczny per (employee_code, month, day) — idempotentne (ON DUPLICATE KEY UPDATE).

### 4. Full mode (build_sql) też naprawiony

`build_sql()` (full seed) nie miał `sh_users`, `sh_tenant_settings`, `sh_drivers`, ani HR. Dodano sekcje 2.19-2.21 z tymi samymi danymi co ops mode.

### 5. sh_order_payments

Dodano płatności dla zamówień z `payment_status` = 'card' lub 'online_paid'. Zamówienia 'to_pay' nie mają rekordu płatności (pending). `transaction_id` = `CONCAT('SEED-', UPPER(SUBSTRING(REPLACE(order_id,'-',''), 1, 12)))`.

## Zgodność z Konstytucją v5

| Prawo | Status | Uwagi |
|---|---|---|
| IV (Zero zaufania) | ⚠️ P3 CLI | Spójne z seed_demo_all.php, daty dynamiczne |
| VI (Snajper / tenant_id) | ✅ | Wszystkie query mają @tid barierę |
| VIII (Domknięcie) | ✅ | SEED_GUIDE.md już zaktualizowany w poprzednim commicie |
| X (Audyt) | ✅ | Ten plik sesji |

## Otwarte pytania

1. **Stale daty KSeF/PZ** — nadal hardcodowane (date_ago() w Python). Nie naprawiono w tej sesji (poza zakresem — skupiono się na HR).
2. **sh_driver_shifts daty** — użytkownik wybrał "bez zmian".
3. **sh_event_outbox** — nie seedowany (runtime data, poprawne).
4. **sh_advances** — nie seedowany (opcjonalne, poza zakresem).

## Test (E2E)

- `python seed_pizzaforno_build.py --ops-only` → 432.8 KB (było 40.6 KB)
- `python seed_pizzaforno_build.py` → 764.6 KB (było 368.4 KB)
- `python seed_pizzaforno_build.py --menu-only` → 336.1 KB (bez zmian treści)
- `php -l scripts/install_panel.php` → No syntax errors
- `php -l scripts/seed_demo_all.php` → No syntax errors
- `grep sh_employees seed_pizzaforno_ops.sql` → 1150 trafień (HR tabele obecne)
- `grep sh_employees seed_pizzaforno.sql` → 1147 trafień (HR tabele obecne)

Pełne testy E2E wgrywania na uti.pl — do wykonania po deployu.
