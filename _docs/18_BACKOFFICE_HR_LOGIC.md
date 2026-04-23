# 18. BACKOFFICE — HR & PAYROLL LOGIC (SliceHub Enterprise)

> **Status:** DRAFT · ARCHITEKTURA (2026-04-23)
> **Autor:** Główny Architekt SliceHub Enterprise
> **Zakres:** Silos Backoffice / HR & Payroll — fundament domeny pracowniczej.
> **Faza:** Krok 1 — projekt architektury. Migracje SQL i kod produkcyjny — w następnym kroku.

Dokument opisuje:

1. Wyniki audytu logiki biznesowej z legacy (Kiosk, Ekipa, Szefa — patrz `_docs/LEGACY_BUSINESS_LOGIC_EXTRACTION.md §5` + `§8.2` + `§8.4`).
2. Docelową architekturę tabel (DDD + Multi-tenant).
3. Mapowanie procesów legacy → nowy system.
4. Plan implementacji API Clock-in / Clock-out dla POS.

---

## 0. ŹRÓDŁA AUDYTU

| Źródło legacy | Rola historyczna | Plik-zakotwiczenie |
|---|---|---|
| `api_auth_kiosk.php` + `kiosk.html` | PIN-login pracowników (4-cyfrowy) | legacy extraction §8.2 |
| `api_ekipa.php` + `app.html` | Self-service pracownika (godziny, zarobek, zaliczki) | legacy extraction §5 |
| `api_auth.php` (stare pliki) — `clock_action` | Clock-in/out, auto-rejestracja drivera | legacy extraction §5.1 |
| `admin_app.html` + `api_manager.php` | Panel „Szefa" (team payroll, wnioski finansowe) | legacy extraction §5.5, §8.4 |

**Stan obecny (2026-04-23, po Kroku 4 Fazy 3B):** na scenie stoją:

- `core/HrClockEngine.php` — **jedyny kanoniczny silnik** clock in/out (UUID v4, second-precision, terminal binding, PIN bcrypt, geo, event outbox, snapshot stawki). Stary `core/ClockEngine.php` został usunięty.
- `core/PayrollLedger.php` — **append-only writer** dla `sh_payroll_ledger` (świętość pieniądza: STRICT `int` grosze, whitelist `entry_type`, sign-per-type, cross-tenant ref guard, idempotency po `entry_uuid`, `reverse()` zamiast `update`/`delete`). **18/18 smoke test PASS.**
- `core/AdvanceEngine.php` — cykl życia zaliczki (`requested → approved → paid → settled` + `rejected`), rozbicie rat z resztą do ostatniej raty, auto-settlement po pełnej spłacie. Każdy wpis pieniężny → przez `PayrollLedger::record`. **20/20 smoke test PASS.**
- `core/PayrollEngine.php` — okresowe przeliczanie (WTD / MTD / YTD + comparison). *TODO Faza 3C:* przepisanie readerów na `sh_payroll_ledger::sumForPeriod`.
- `core/TeamPayrollEngine.php` — agregat dla „Szefa". *TODO Faza 3C:* ten sam refactor.
- `scripts/worker_driver_fanout.php` — **konsument eventów** `employee.clocked_in/out` (aggregate_type=`shift`) → `sh_drivers.status`. Pod **feature flag per-tenant** `HR_USE_EVENT_DRIVER_FANOUT` (w `sh_tenant_settings`; default OFF). Polityka: kierowca w trasie (`status='busy'`) NIGDY nie jest ruszany przez clock_out. **9/9 smoke test PASS.**
- Tabele: `sh_employees`, `sh_employee_rates`, `sh_work_sessions` (rozszerzone m042), `sh_payroll_ledger`, `sh_advances`, `sh_advance_installments`, `sh_meals`. `sh_users.hourly_rate` oznaczona `DEPRECATED_HR_M041`.
- Endpoint: `api/backoffice/hr/engine.php` z akcjami `clock_in` / `clock_out` / `clock_status`. Stary `api/staff/clock.php` (PLANNED, bez konsumenta) został usunięty razem z `ClockEngine`.

**Rdzeń tego dokumentu:** *co trzeba dopisać / przeorganizować*, żeby z prowizorycznego systemu „ekipa v1" zrobić kanoniczny silos **Backoffice / HR & Payroll** gotowy do Faz 3–4.

---

## 1. AUDYT LEGACY — ZIDENTYFIKOWANE ALGORYTMY I BŁĘDY

### 1.1. Klucz-algorytmy (co warto zachować i usztywnić)

| Algorytm | Legacy | Obecny stan | Decyzja |
|---|---|---|---|
| **Clock-in insert** | `INSERT sh_work_sessions (user_id, start_time)` | `HrClockEngine::clockIn` z `session_uuid`, `employee_id`, `terminal_id`, `source`, geo oraz hardware-level idempotencją (`uq_ws_single_open`) | **ZREALIZOWANE Faza 3A** — m041/m042 + `core/HrClockEngine.php` |
| **Clock-out + total_hours** | `TIMESTAMPDIFF(MINUTE)/60` | `TIMESTAMPDIFF(SECOND)/3600` → **DECIMAL(10,4)** | KANON (precyzja co do 0.36 sek.) |
| **Gross pay** | `hours × hourly_rate` | jw. (`PayrollEngine::calculate`) | ROZBUDOWAĆ o temporalne stawki (§3.4) |
| **Net pay** | `gross − deductions − meals` | jw. | PRZESTAWIĆ na ledger zdarzeń (§3.5) |
| **Prev-period comparison** (day-of-month + time-of-day cap) | `stare pliki/api_ekipa.php` | `PayrollEngine::buildComparison` | ZACHOWAĆ, tylko **przenieść cap do UTC** |
| **Pizza economy display** (coins → pizza/slice/bite) | `app.html` | brak w nowym kodzie | PRZENIEŚĆ do **modułu Gamifikacja** (oddzielny silos — *nie* HR) |
| **Advance (zaliczka) flow** | `sh_finance_requests(type='advance')` → ręczna migracja do `sh_deductions` | nieobsługiwane | ZASTĄPIĆ kanoniczną tabelą `sh_advances` z workflow (§3.3) |
| **Bonus** | `sh_finance_requests(type='bonus')` — tylko pozytywna składowa | brak | WPROWADZIĆ jako *positive entry* w `sh_payroll_ledger` (§3.5) |
| **Meal deduction** | `sh_meals.employee_price` — per-spożycie | `sh_meals` (zachowane) | OZNACZYĆ jako event w ledgerze (zaciąga do deductions) |

### 1.2. Błędy i długi techniczne (obowiązkowe do naprawy)

> Numeracja kontynuuje legacy extraction §9.7 (bugi #1-15), żeby nie dublować.

| # | Bug / ryzyko | Miejsce | Wpływ finansowy | Rozwiązanie w nowym modelu |
|---|---|---|---|---|
| **HR-1** | `total_time = MINUTE/60` | `api_auth.php` (legacy) | Strata do 59 sek. na zmianę × N zmian = grosze, ale skumulowane — niewłaściwe | Użyto `SECOND/3600` w `HrClockEngine::clockOut`. **Sztywno zapisane w migracji** jako `DECIMAL(10,4)`. |
| **HR-2** | Brak historii `hourly_rate` (single column w `sh_users`) | `sh_users.hourly_rate` | Zmiana stawki *retroaktywnie* psuje wyliczenia poprzednich miesięcy — błąd krytyczny | Tabela **`sh_employee_rates`** z `effective_from`/`effective_to` (temporal). Payroll wybiera stawkę po `start_time` sesji. |
| **HR-3** | Brak waluty | cały legacy zakłada PLN | Niemożliwy multi-country rollout | **`currency CHAR(3)`** (ISO 4217) na `sh_employees`, `sh_payroll_ledger`, `sh_advances`. Default `'PLN'`. Money w `INT grosze` (per Konstytucja §3 Konwencje). |
| **HR-4** | Kaskada driver-auto-register w clock_in | `api_auth.php` (legacy) | Naruszenie DDD: moduł HR pisze do `sh_drivers` (silos logistyki) | Emit **domain event** `employee.clocked_in` przez `sh_event_outbox` (m026); konsument `worker_driver_fanout` sam zdecyduje o `sh_drivers`. |
| **HR-5** | Brak idempotencji (double-click PIN → 2 sesje) | `api_auth.php` | Podwójne naliczenie godzin | `HrClockEngine` blokuje przez `ERR_ALREADY_CLOCKED_IN` + hardware-level unique index `uq_ws_single_open(tenant_id, employee_id, open_guard)` (generated column w m042). |
| **HR-6** | Midnight-crossing shifts przypisywane po `start_time` | `PayrollEngine::sumClosedHours` | Zmiana 22:00–06:00 → cały kredyt na poprzednią dobę | **Alokacja proporcjonalna**: funkcja SQL `fn_allocate_hours(start, end, window_start, window_end)` zwraca część sesji wpadającą w okno. |
| **HR-7** | SQL Injection przez `"...".$uid.'"..."` | `api_ekipa.php::get_profile_data` | Krytyczne bezpieczeństwo | Usunięte (nowe API używa prepared statements). Warunek akceptacji: **zero stringowych interpolacji** w `PayrollEngine` i `AdvanceEngine`. |
| **HR-8** | Zaliczka jako zwykły `sh_deductions` bez statusu/decyzji | `api_manager.php` | Brak audytu, kto zatwierdził; nie można wycofać | **`sh_advances`** z polem `status` (`requested`/`approved`/`paid`/`settled`/`rejected`/`void`) + `approved_by`, `approved_at`, `paid_at`. |
| **HR-9** | Zaliczka = jedno-kwotowa, bez harmonogramu spłat | `sh_finance_requests` | Pracownik nie widzi planu spłat | Tabela `sh_advance_installments` (kwota × data). Ledger generuje wpis `advance_repayment` na każdej wypłacie aż do `settled`. |
| **HR-10** | `sh_drivers` ↔ `sh_users.id` — niespójność ID (bug #5 legacy) | `api_driver.php` | Błędne wypłaty kierowcom | Nowy model: w HR **zawsze** operujemy `employee_id` (BIGINT UNSIGNED z `sh_employees`). `sh_drivers.employee_id` będzie FK (faza 3). |
| **HR-11** | `slice_coins` + gamification w `sh_users` | `sh_users.slice_coins` | Miksowanie HR i gamifikacji | *Nie w zakresie tego dokumentu* — flag: **NIE migrujemy do HR**. Zostanie w osobnym silosie `gam_*` (przyszła faza). |
| **HR-12** | `pin_code` / `pin` — schema drift (bug #6 legacy) | `api_auth_kiosk.php` vs `api_auth.php` | Niespójność logowania Kiosk | W nowym schemacie tylko `pin_hash CHAR(60)` (bcrypt) w `sh_employees.auth_pin_hash`. Brak plain-text PIN. |
| **HR-13** | Brak kontraktów pracowniczych (UoP/B2B/zlecenie) | legacy | Wpływa na podatki/ZUS (PL) | `sh_employment_contracts` z `contract_type` (`UOP`/`UZ`/`UD`/`B2B`/`KAZUAL`), `tax_profile`, `gross_to_net_formula_key` (ASCII klucz). |
| **HR-14** | Brak kalendarza nieobecności | legacy | Urlopy, L4 liczone ręcznie | `sh_employee_absences` (`absence_type`, `date_from`, `date_to`, `paid_rate_percent`). |
| **HR-15** | Brak walidacji max godzin (11h odpoczynek, 40h tydzień) | legacy | Ryzyko prawne (PIP) | Constraint-warning w `HrClockEngine` (nie błąd; log przez event `employee.overtime_alert`). *Faza 3B.* |

---

## 2. STRATEGIE ARCHITEKTONICZNE

### 2.1. Domain-Driven Design — granice agregatów

Silos **HR & Payroll** ma trzy agregaty:

```
Employee (aggregate root: sh_employees)
 ├── EmploymentContract (sh_employment_contracts)
 ├── EmployeeRate (sh_employee_rates)  [temporal]
 └── EmployeeAbsence (sh_employee_absences)

WorkSession (aggregate root: sh_work_sessions)
 └── (events) → sh_event_outbox

PayrollPeriod (aggregate: sh_payroll_periods)
 ├── PayrollLedgerEntry (sh_payroll_ledger)  [append-only]
 └── Advance (sh_advances)
     └── AdvanceInstallment (sh_advance_installments)
```

**Reguły inwariantów:**

- `WorkSession` nie zna `EmployeeRate` bezpośrednio — to `PayrollEngine` łączy przy odczycie.
- `PayrollLedgerEntry` jest **append-only**. Korekta = osobny wpis z przeciwnym znakiem (zero-sum pair).
- `Advance` nie modyfikuje ledgera wprost — generuje wpisy przez `AdvanceEngine::projectInstallments()`.
- `Employee` nie jest klonem `sh_users`. Separate **profile pracowniczy** z własnym lifecycle.

### 2.2. Relacja `sh_users` ↔ `sh_employees`

Problem: `sh_users` dziś zawiera `hourly_rate`, role, PIN (mixed concerns).

**Decyzja:**

- `sh_users` pozostaje jako **tożsamość uwierzytelniająca** (`username`, `password_hash`, `role`, `is_active`).
- `sh_employees` to **profil HR** — 1:1 z `sh_users` poprzez `user_id` (NULLABLE, bo pracownik może nie mieć loginu systemowego — np. sezonowy pomocnik).
- `hourly_rate` z `sh_users` w momencie migracji → skopiowany do pierwszego wpisu `sh_employee_rates` (effective_from = `sh_users.created_at`).
- Kolumna `sh_users.hourly_rate` zostaje na chwilę jako **DEPRECATED_HR** (flaga w komentarzu) i zostanie usunięta w kolejnej migracji po weryfikacji.

### 2.3. Multi-tenant (Prawo II Konstytucji)

**Każda** tabela z tego silosu ma `tenant_id INT UNSIGNED NOT NULL` + FK → `sh_tenant(id)` + indeks composite `(tenant_id, …)`.

**Dodatkowo:** każde zapytanie zaczyna od `WHERE tenant_id = :tid`. Integer-ID agregatów jest unikalny per-tenant (żadnych globalnych slug-ów, bo kolidowałyby przy imporcie drugiego najemcy).

### 2.4. Klucze techniczne: ASCII, opisy: UTF-8

Zgodnie z rozkazem:

| Klasa pola | Typ | Przykład |
|---|---|---|
| Technical key (enum, action, status, contract_type) | `VARCHAR(32) ASCII` | `'clocked_in'`, `'UOP'`, `'advance_repayment'` |
| Display name / notatki / adres | `VARCHAR/TEXT utf8mb4` | `'Jan Kowalski'`, `'Umowa o pracę — pełen etat'` |
| Money | `INT UNSIGNED` (grosze / minor units) | `3250` = 32.50 PLN |
| Currency | `CHAR(3) ASCII` (ISO 4217) | `'PLN'`, `'EUR'`, `'CZK'` |
| Hours | `DECIMAL(10,4)` | `7.8333` |
| UUID | `CHAR(36) ASCII` | `'550e8400-e29b-…'` |
| Timestamps | `DATETIME(0)` (UTC storage) | `'2026-04-23 19:05:00'` |

**Konwencja nazewnicza kluczy technicznych:**
`<domain>.<action>` w eventach (`employee.clocked_in`), **snake_case** w kolumnach statusów (`pending_approval`), **UPPER_CASE** w typach kontraktów (`UOP`, `B2B`).

---

## 3. ARCHITEKTURA TABEL

### 3.1. `sh_employees` — profil pracownika (aggregate root)

```sql
CREATE TABLE sh_employees (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id               INT UNSIGNED    NOT NULL,
  user_id                 BIGINT UNSIGNED NULL,            -- link do sh_users (auth), NULLABLE
  employee_code           VARCHAR(32)     NOT NULL,         -- ASCII, per-tenant, unikalny ('EMP-0001', 'JAN_KOW_01')
  display_name            VARCHAR(191)    NOT NULL,         -- UTF-8 'Jan Kowalski'
  first_name              VARCHAR(96)     NOT NULL,
  last_name               VARCHAR(96)     NOT NULL,
  email                   VARCHAR(191)    NULL,
  phone                   VARCHAR(32)     NULL,
  birth_date              DATE            NULL,
  hire_date               DATE            NOT NULL,
  termination_date        DATE            NULL,
  -- AUTH (tylko dla Kiosk PIN — system-login idzie przez sh_users.password_hash)
  auth_pin_hash           CHAR(60)        NULL,             -- bcrypt 4-6 digit PIN
  auth_pin_updated_at     DATETIME        NULL,
  -- HR META
  primary_role            VARCHAR(32)     NOT NULL,         -- ASCII: 'cook' | 'waiter' | 'driver' | 'manager' | 'cashier' | 'cleaner'
  status                  VARCHAR(32)     NOT NULL DEFAULT 'active',  -- ASCII: 'active' | 'suspended' | 'on_leave' | 'terminated'
  default_currency        CHAR(3)         NOT NULL DEFAULT 'PLN',
  notes                   TEXT            NULL,             -- UTF-8 wolne pole
  -- META
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_deleted              TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_code_tenant (tenant_id, employee_code),
  UNIQUE KEY uq_emp_user_tenant (tenant_id, user_id),       -- 1:1 z userem per tenant
  KEY idx_emp_tenant_status (tenant_id, status, is_deleted),
  KEY idx_emp_role (tenant_id, primary_role),
  CONSTRAINT fk_emp_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE,
  CONSTRAINT fk_emp_user   FOREIGN KEY (user_id) REFERENCES sh_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Decyzje:**
- `employee_code` — ASCII, stabilny handle (NIE ID). Można go wyeksportować do integracji księgowej.
- `auth_pin_hash` — bcrypt, nigdy plain-text (HR-12).
- `primary_role` — rola zatrudnienia; *NIE* to samo co `sh_users.role` (ta ostatnia to uprawnienia systemowe).
- `default_currency` — per-pracownik, bo expat/multi-country.

---

### 3.2. `sh_employee_rates` — temporalne stawki (rozwiązanie HR-2)

```sql
CREATE TABLE sh_employee_rates (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        INT UNSIGNED    NOT NULL,
  employee_id      BIGINT UNSIGNED NOT NULL,
  rate_type        VARCHAR(32)     NOT NULL DEFAULT 'hourly',  -- ASCII: 'hourly' | 'monthly_flat' | 'per_delivery' | 'piecework'
  amount_minor     INT UNSIGNED    NOT NULL,                    -- np. 2850 = 28.50 PLN / godz.
  currency         CHAR(3)         NOT NULL DEFAULT 'PLN',
  effective_from   DATETIME        NOT NULL,
  effective_to     DATETIME        NULL,                         -- NULL = nadal aktualna
  reason           VARCHAR(64)     NULL,                         -- ASCII: 'hiring' | 'raise' | 'demotion' | 'correction'
  note             VARCHAR(255)    NULL,                         -- UTF-8
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate_emp_window (tenant_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_rate_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE,
  CONSTRAINT fk_rate_emp    FOREIGN KEY (employee_id) REFERENCES sh_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Inwariant:** w każdym momencie maksymalnie jedna stawka danego `rate_type` ma `effective_to IS NULL` dla (tenant, employee). Nowa stawka = `UPDATE` starej (`effective_to = NOW()`) + `INSERT` nowej (`effective_from = NOW()`). Atomic w transakcji.

**Algorytm wyboru stawki dla danej sesji pracy:**
```
SELECT amount_minor, currency FROM sh_employee_rates
WHERE tenant_id = :tid AND employee_id = :eid AND rate_type = 'hourly'
  AND effective_from <= :session_start
  AND (effective_to IS NULL OR effective_to > :session_start)
ORDER BY effective_from DESC LIMIT 1
```

Dla sesji **crossingowej** przez zmianę stawki — silnik musi sumować dwa segmenty (patrz §3.4 `PayrollEngine v2`).

---

### 3.3. `sh_work_sessions` — timesheet (ewolucja tabeli istniejącej)

Tabela już istnieje (`001_init_slicehub_pro_v2.sql`). Nowa migracja **rozszerzy** ją, nie stworzy od zera.

```sql
ALTER TABLE sh_work_sessions
  ADD COLUMN employee_id        BIGINT UNSIGNED NULL AFTER user_id,
  ADD COLUMN terminal_id        BIGINT UNSIGNED NULL AFTER session_uuid,  -- POS terminal (sh_pos_terminals z m039)
  ADD COLUMN clock_in_source    VARCHAR(32)     NOT NULL DEFAULT 'kiosk' AFTER terminal_id,
       -- ASCII: 'kiosk' | 'pos' | 'mobile' | 'manager_override' | 'system_auto'
  ADD COLUMN clock_out_source   VARCHAR(32)     NULL AFTER clock_in_source,
  ADD COLUMN adjusted_by_user_id BIGINT UNSIGNED NULL,                    -- jeśli manager skorygował
  ADD COLUMN adjustment_reason  VARCHAR(255)    NULL,
  ADD COLUMN geo_lat_in         DECIMAL(10,7)   NULL,
  ADD COLUMN geo_lon_in         DECIMAL(10,7)   NULL,
  ADD COLUMN geo_lat_out        DECIMAL(10,7)   NULL,
  ADD COLUMN geo_lon_out        DECIMAL(10,7)   NULL,
  ADD KEY idx_ws_employee (tenant_id, employee_id, start_time),
  ADD KEY idx_ws_terminal (tenant_id, terminal_id, start_time),
  ADD CONSTRAINT fk_ws_employee FOREIGN KEY (employee_id) REFERENCES sh_employees(id) ON DELETE CASCADE;
```

**Uwaga:** `user_id` zostaje (backward-compat), ale **nowy kod pisze `employee_id`**. Podczas migracji backfill: dla każdego `user_id` wyszukaj `sh_employees.user_id` i wpisz `employee_id`.

**Unique index anti-double-session (rozwiązanie HR-5):**

MariaDB 10.4+ wspiera generated columns, ale NULL-safe unique po prostu się nie zepsuje, bo NULL ≠ NULL. Zamiast tego:

```sql
-- generated column = 1 gdy sesja otwarta, NULL gdy zamknięta
ALTER TABLE sh_work_sessions
  ADD COLUMN open_guard TINYINT UNSIGNED GENERATED ALWAYS AS
    (CASE WHEN end_time IS NULL THEN 1 ELSE NULL END) VIRTUAL,
  ADD UNIQUE KEY uq_ws_single_open (tenant_id, employee_id, open_guard);
```

→ Druga sesja-otwarta dla tego samego pracownika w tym samym tenant rzuca `Duplicate key`. `HrClockEngine` łapie i mapuje na `ERR_ALREADY_CLOCKED_IN`.

---

### 3.4. `sh_payroll_ledger` — księga zdarzeń wynagrodzeń (append-only)

```sql
CREATE TABLE sh_payroll_ledger (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_uuid       CHAR(36)        NOT NULL,
  tenant_id        INT UNSIGNED    NOT NULL,
  employee_id      BIGINT UNSIGNED NOT NULL,
  period_year      SMALLINT UNSIGNED NOT NULL,        -- 2026
  period_month     TINYINT UNSIGNED  NOT NULL,        -- 1..12
  entry_type       VARCHAR(32)     NOT NULL,
    -- ASCII KEYS:
    --   'hours_accrual'      → dodaje do gross (źródło: sh_work_sessions)
    --   'bonus'              → dodaje do gross (decyzja managera)
    --   'correction_plus'    → dodaje do gross (manualna korekta)
    --   'correction_minus'   → odejmuje od gross
    --   'advance_payout'     → wypłacona zaliczka (informacyjnie, nie dotyka gross)
    --   'advance_repayment'  → odjęcie na poczet zaliczki w tym okresie
    --   'meal_charge'        → potrącenie za posiłek pracowniczy
    --   'penalty'            → potrącenie karne
    --   'tax_withholding'    → zaliczka PIT (future)
    --   'zus_withholding'    → ZUS (future)
  amount_minor     INT             NOT NULL,           -- SIGNED: dodatnie = przychód pracownika, ujemne = potrącenie
  currency         CHAR(3)         NOT NULL DEFAULT 'PLN',
  hours_qty        DECIMAL(10,4)   NULL,               -- wypełnione tylko dla 'hours_accrual'
  rate_applied_minor INT UNSIGNED  NULL,               -- stawka użyta (snapshot — nawet jeśli sh_employee_rates zmieni się później)
  -- REFERENCES
  ref_work_session_id BIGINT UNSIGNED NULL,            -- dla 'hours_accrual'
  ref_advance_id      BIGINT UNSIGNED NULL,            -- dla 'advance_*'
  ref_installment_id  BIGINT UNSIGNED NULL,            -- dla 'advance_repayment'
  ref_meal_id         BIGINT UNSIGNED NULL,            -- dla 'meal_charge'
  reverses_entry_id   BIGINT UNSIGNED NULL,            -- dla korekt: wskazuje którą pozycję odwraca
  -- AUDYT
  description      VARCHAR(255)    NULL,               -- UTF-8 notatka
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- LOCK (zamknięcie okresu)
  is_locked        TINYINT(1)      NOT NULL DEFAULT 0,
  locked_at        DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_uuid (entry_uuid),
  KEY idx_ledger_emp_period (tenant_id, employee_id, period_year, period_month),
  KEY idx_ledger_type (tenant_id, entry_type, period_year, period_month),
  KEY idx_ledger_ref_ws (ref_work_session_id),
  KEY idx_ledger_ref_adv (ref_advance_id),
  CONSTRAINT fk_ledger_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_emp    FOREIGN KEY (employee_id) REFERENCES sh_employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_ws     FOREIGN KEY (ref_work_session_id) REFERENCES sh_work_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_ledger_reverses FOREIGN KEY (reverses_entry_id) REFERENCES sh_payroll_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Kluczowe inwarianty:**

1. **Append-only.** `UPDATE` tylko na `is_locked`/`locked_at`. Korekta = nowy wpis `correction_plus`/`correction_minus` albo `reverses_entry_id`.
2. `amount_minor` jest **SIGNED**. Pracownik net = `SUM(amount_minor)` per okres. Typy opisują tylko raportowanie, nie matematykę.
3. `rate_applied_minor` to **snapshot** stawki. Nawet jeśli w `sh_employee_rates` ktoś wsteczniie poprawi — wpisy ledgera pokazują wartość wypłaconą.
4. Po `is_locked=1` dla okresu `(year, month, employee)` nie można dodawać wpisów o tym period_year/period_month — korekty idą jako osobne wpisy do **następnego** otwartego okresu z `description` = "Korekta 2026-04".

**Matematyka wypłaty:**

```
gross = SUM(amount_minor) WHERE entry_type IN ('hours_accrual', 'bonus', 'correction_plus')
deductions = -1 × SUM(amount_minor) WHERE entry_type IN
    ('correction_minus','advance_repayment','meal_charge','penalty','tax_withholding','zus_withholding')
net = SUM(amount_minor)  [po prostu — bo typy sumują się po znaku]
```

---

### 3.5. `sh_advances` — zaliczki z workflow (rozwiązanie HR-8, HR-9)

```sql
CREATE TABLE sh_advances (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advance_uuid         CHAR(36)        NOT NULL,
  tenant_id            INT UNSIGNED    NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,
  amount_minor         INT UNSIGNED    NOT NULL,
  currency             CHAR(3)         NOT NULL DEFAULT 'PLN',
  status               VARCHAR(32)     NOT NULL DEFAULT 'requested',
    -- ASCII: 'requested' | 'approved' | 'paid' | 'settled' | 'rejected' | 'void'
  repayment_plan       VARCHAR(32)     NOT NULL DEFAULT 'single',
    -- ASCII: 'single' | 'monthly_installments' | 'weekly_installments'
  installments_count   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  reason               VARCHAR(255)    NULL,         -- UTF-8 opis
  -- PRZEPŁYW
  requested_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  requested_by_user_id BIGINT UNSIGNED NULL,          -- sam pracownik lub manager
  approved_at          DATETIME        NULL,
  approved_by_user_id  BIGINT UNSIGNED NULL,
  rejected_at          DATETIME        NULL,
  rejection_reason     VARCHAR(255)    NULL,          -- UTF-8
  paid_at              DATETIME        NULL,
  paid_method          VARCHAR(32)     NULL,          -- ASCII: 'cash' | 'transfer'
  paid_by_user_id      BIGINT UNSIGNED NULL,
  settled_at           DATETIME        NULL,
  void_at              DATETIME        NULL,
  -- AUDYT
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_advance_uuid (advance_uuid),
  KEY idx_adv_emp_status (tenant_id, employee_id, status),
  KEY idx_adv_status (tenant_id, status, created_at),
  CONSTRAINT fk_adv_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE,
  CONSTRAINT fk_adv_emp    FOREIGN KEY (employee_id) REFERENCES sh_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sh_advance_installments (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            INT UNSIGNED    NOT NULL,
  advance_id           BIGINT UNSIGNED NOT NULL,
  seq_no               TINYINT UNSIGNED NOT NULL,    -- 1..N
  amount_minor         INT UNSIGNED    NOT NULL,
  currency             CHAR(3)         NOT NULL DEFAULT 'PLN',
  scheduled_period_year  SMALLINT UNSIGNED NOT NULL,
  scheduled_period_month TINYINT UNSIGNED  NOT NULL,
  status               VARCHAR(32)     NOT NULL DEFAULT 'pending',
    -- ASCII: 'pending' | 'applied' | 'skipped' | 'void'
  applied_ledger_entry_id BIGINT UNSIGNED NULL,
  applied_at           DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inst_seq (tenant_id, advance_id, seq_no),
  KEY idx_inst_period (tenant_id, scheduled_period_year, scheduled_period_month, status),
  CONSTRAINT fk_inst_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE,
  CONSTRAINT fk_inst_adv    FOREIGN KEY (advance_id) REFERENCES sh_advances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Maszyna stanów `status` w `sh_advances`:**

```
requested ──approve──> approved ──pay──> paid ─(repayments accrue)──> settled
    │                      │                              │
    └───reject────> rejected  └──void──> void          └──void──> void
```

- `requested → approved`: manager w panelu „Szefa" (wymaga `role IN ('owner','manager')`).
- `approved → paid`: moment fizycznej wypłaty (kasa gotówka lub przelew). Generuje wpis ledger `advance_payout` (**informacyjny — nie dotyka gross**).
- Następnie `AdvanceEngine::projectInstallments(advance_id)` generuje N wpisów `sh_advance_installments` zaplanowanych na kolejne okresy.
- W momencie zamknięcia okresu: każda raten `applied` → wpis ledger `advance_repayment` (ujemny) → minus `installment.amount_minor`.
- Gdy `SUM(applied)` = `advance.amount_minor` → `advance.status = settled`.

---

### 3.6. Tabele uzupełniające (szkielet, szczegóły w kolejnych dokumentach)

| Tabela | Rola | Priorytet |
|---|---|---|
| `sh_employment_contracts` | Typ umowy (UOP/B2B/UZ), daty, profil podatkowy | Faza 3B |
| `sh_employee_absences` | Urlopy, L4, nieobecności | Faza 3B |
| `sh_payroll_periods` | Zamknięcie miesięcy (lock) + snapshot wypłaty | Faza 3C |
| `sh_employee_documents` | PIT-11, skany umów (GDPR-aware) | Faza 4 |

**Faza 1 (ten dokument):** tylko `sh_employees`, `sh_employee_rates`, `sh_work_sessions` (ALTER), `sh_payroll_ledger`, `sh_advances`, `sh_advance_installments`.

---

## 4. MAPOWANIE LEGACY → NOWY SYSTEM

| Proces / Obiekt legacy | Nowe miejsce | Zmiana | Dlaczego |
|---|---|---|---|
| `sh_users.hourly_rate` | `sh_employee_rates` (temporal) | **Zmiana** — z single-value na historię | Retroaktywny payroll bez błędów (HR-2) |
| `sh_users.first_name, last_name` | `sh_employees` (autorytet), `sh_users` (kopia tylko dla UI auth) | **Przeniesienie** | Separacja auth vs HR |
| `sh_users.pin` / `pin_code` | `sh_employees.auth_pin_hash` (bcrypt) | **Zamiana** (hashowanie) | Bezpieczeństwo (HR-12) |
| `sh_work_sessions` | `sh_work_sessions` + ALTER | **Rozszerzenie** | `employee_id`, `terminal_id`, `clock_in_source`, geo (HR-10) |
| `sh_deductions` (legacy) | `sh_payroll_ledger` z `entry_type='advance_repayment'/'meal_charge'/'penalty'/'correction_minus'` | **Zastąpienie** | Ujednolicona księga zdarzeń |
| `sh_meals` | Zostaje (jako rejestr posiłków) + auto-generuje wpis `meal_charge` w ledger | **Zachowanie, podłączenie do ledgera** | Backward-compat |
| `sh_finance_requests` (legacy — advance/bonus/meal) | Rozdzielone: **advance** → `sh_advances`, **bonus** → bezpośredni wpis ledger, **meal** → `sh_meals` | **Rozdzielenie** | Różne lifecycle (zaliczka ma workflow; bonus to jednorazowa decyzja) |
| Gross/Net formula (`admin_app.html`) | `PayrollEngine v2::summarizePeriod()` | **Zmiana** — agreguje z ledger, nie z trzech tabel | Single source of truth |
| Auto-register drivera przy clock-in | Emit `employee.clocked_in` → `worker_driver_fanout` konsumuje | **Rozdzielenie silosów** | DDD (HR-4) |
| Slice Coins (`sh_users.slice_coins`) | NIE MIGROWANE — zostaje w przyszłym silosie `gam_` | **Wyłączenie z HR** | Out of scope (HR-11) |
| Daily trivia / gamifikacja | Osobny silos | **Wyłączenie z HR** | Out of scope |
| Online presence (`status_color` = blue/green/red) | Pozostaje, ale oparty na `sh_work_sessions.employee_id` + `sh_users.last_seen` | **Refactor join** | Spójność ID |
| Prev-period comparison (day-of-month cap) | `PayrollEngine::buildComparison` (już istnieje) | **Zachowanie** | OK |
| `TIMESTAMPDIFF(MINUTE)/60` | `TIMESTAMPDIFF(SECOND)/3600` | **Zmiana** (w `HrClockEngine`) | Precyzja (HR-1) |

**Co NIE zmieniamy:**

- Format `session_uuid` CHAR(36).
- Okna `WTD`/`MTD`/`YTD` — trzymamy zgodnie z `PayrollEngine::resolvePeriodBounds()`.
- Driver lifecycle (`available` ↔ `busy` ↔ `offline`) — silos Logistyki sterowany zdarzeniami, nie bezpośrednim UPDATE z HR.

---

## 5. PLAN IMPLEMENTACJI API CLOCK-IN / CLOCK-OUT (POS)

### 5.1. Kontekst — gdzie to żyje

- POS (`modules/pos/`) wyświetla **widget Clock** w nagłówku: pokazuje, kto jest zmianie + przycisk „Rozliczenie zmiany" → modal z PIN-padem.
- Kiosk (`modules/kiosk/` — przyszły) to **dedykowany terminal** tylko do PIN-login → clock-in/out → ekran „gotowe, idź do pracy".
- Self-service `modules/ekipa/` (mobile PWA, przyszły) — pracownik na telefonie widzi własne godziny.

**Dla Kroku 1 budujemy:** endpoint wspólny `api/backoffice/hr/engine.php` (action-based, per Konwencja §4 Architektury), z pierwszą akcją **Clock**. POS będzie jego konsumentem.

### 5.2. Endpoint: `POST /api/backoffice/hr/engine.php`

Wzorzec: jeden `engine.php`, `action` w ciele.

**Plik:** `api/backoffice/hr/engine.php`
**Auth:**
- `action=clock_in` / `action=clock_out` — dwa warianty auth:
  - **Kiosk mode:** `auth_pin_hash` w body (`pin`) → endpoint sam weryfikuje. Nie wymaga sesji systemowej.
  - **POS mode (self-service):** sesja `$user_id` (z `auth_guard.php`) → endpoint przekłada na `employee_id` przez `sh_employees.user_id`.
- Rozróżnienie przez `auth_mode` w body (`'kiosk'` | `'session'`).

#### 5.2.1. Akcja `clock_in`

**Request (kiosk mode):**
```json
{
  "action": "clock_in",
  "auth_mode": "kiosk",
  "pin": "4821",
  "terminal_id": 3,
  "source": "kiosk",
  "geo": { "lat": 52.4064, "lon": 16.9252 }
}
```

**Request (session mode — POS):**
```json
{
  "action": "clock_in",
  "auth_mode": "session",
  "terminal_id": 3,
  "source": "pos"
}
```

**Response success (200):**
```json
{
  "success": true,
  "data": {
    "session_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "employee_id": 42,
    "employee_display_name": "Jan Kowalski",
    "start_time": "2026-04-23T17:30:00Z",
    "hourly_rate": { "amount_minor": 2850, "currency": "PLN" }
  }
}
```

**Response errors:**

| HTTP | `code` (ASCII) | Kiedy |
|---|---|---|
| 400 | `INVALID_PIN_FORMAT` | PIN nie jest 4-6 cyframi |
| 401 | `PIN_NOT_MATCHED` | Brak pasującego pracownika w tenant |
| 403 | `EMPLOYEE_SUSPENDED` | `status != 'active'` |
| 404 | `EMPLOYEE_NOT_FOUND_FOR_USER` | session mode, ale `sh_users.id` nie ma profilu HR |
| 409 | `ALREADY_CLOCKED_IN` | Otwarta sesja |
| 409 | `NO_ACTIVE_RATE` | Brak stawki w `sh_employee_rates` (inwariant gross pay) |
| 422 | `TERMINAL_NOT_REGISTERED` | `terminal_id` nie istnieje w `sh_pos_terminals` dla tego tenanta |
| 429 | `TOO_MANY_PIN_ATTEMPTS` | Rate-limit per terminal (5/min) |

**Algorytm (pseudo):**
```
1. Parse body, walidate action + auth_mode.
2. Resolve tenant_id (from session OR from terminal_id lookup in sh_pos_terminals).
3. Auth:
   - kiosk mode: find sh_employees WHERE tenant_id=:tid AND status='active'
     AND password_verify(:pin, auth_pin_hash) → employee_id
     (constant-time iteration over all active employees — lub zoptymalizować pre-filter po prefixie PIN)
   - session mode: SELECT id FROM sh_employees WHERE tenant_id=:tid AND user_id=:uid
4. Validate active rate exists (sh_employee_rates, rate_type='hourly', effective window).
5. BEGIN TRANSACTION
6. INSERT sh_work_sessions (tenant_id, employee_id, user_id, start_time=NOW(),
                             session_uuid, terminal_id, clock_in_source, geo_lat_in, geo_lon_in)
7. INSERT sh_event_outbox (event_type='employee.clocked_in', aggregate_id=employee_id,
                            payload_json=...)  ← worker_driver_fanout konsumuje
8. UPDATE sh_users.last_seen = NOW() WHERE id = :user_id  (jeśli powiązany)
9. COMMIT
10. RETURN payload.
```

#### 5.2.2. Akcja `clock_out`

Body analogiczne + `session_uuid` opcjonalne (gdy nie podane — silnik wybiera najstarszą otwartą sesję tego pracownika).

**Response success:**
```json
{
  "success": true,
  "data": {
    "session_uuid": "550e8400-...",
    "employee_id": 42,
    "start_time": "2026-04-23T09:00:00Z",
    "end_time": "2026-04-23T17:30:00Z",
    "total_hours": 8.5,
    "preview_earnings": { "amount_minor": 24225, "currency": "PLN" }
  }
}
```

**Response errors:**

| HTTP | `code` | Kiedy |
|---|---|---|
| 409 | `NO_OPEN_SESSION` | Brak otwartej sesji |
| 409 | `ACTIVE_DELIVERIES` | `primary_role='driver'` + `sh_drivers.status='busy'` (hard-block) |
| 409 | `SESSION_TOO_LONG` | `start_time < NOW() - 24h` → wymaga manager_override |

**Algorytm:**
```
1. Parse, resolve tenant + employee (jak clock_in).
2. Locate open session (employee_id, end_time IS NULL).
3. Guards (driver busy, session > 24h).
4. BEGIN TRANSACTION
5. UPDATE sh_work_sessions SET end_time=NOW(),
     total_hours = ROUND(TIMESTAMPDIFF(SECOND, start_time, NOW())/3600.0, 4),
     clock_out_source=:src, geo_lat_out=:lat, geo_lon_out=:lon
6. [LATER — nie w Kroku 1, faza 3C] Generate sh_payroll_ledger entry type='hours_accrual'
     using rate resolved via sh_employee_rates @ start_time.
     (Dla Kroku 1 ledger wypełnia batch job na końcu dnia, nie clock_out synchronicznie.)
7. INSERT sh_event_outbox (event_type='employee.clocked_out', ...)
8. COMMIT
9. Compute preview_earnings (non-persistent) via PayrollEngine::previewSession().
10. RETURN.
```

### 5.3. Pomocnicze akcje w tym samym `engine.php` (szkic, kod w Kroku 2+)

| Action | Kto używa | Cel |
|---|---|---|
| `clock_status` | POS widget | kto jest na zmianie (GET-style, ale POST z body dla spójności) |
| `my_sessions` | Ekipa (self) | lista zmian pracownika w okresie |
| `employees_list` | Szefa / Settings | lista kadr |
| `employee_upsert` | Settings | CRUD profilu |
| `rate_set` | Settings | zmiana stawki → auto-wstawia rekord w `sh_employee_rates` (zamyka poprzednią) |
| `advance_request` | Ekipa (self) | wniosek o zaliczkę |
| `advance_decision` | Szefa | approve / reject |
| `advance_pay` | Szefa + kasa | zaznaczenie wypłaty |
| `payroll_period` | Szefa + Ekipa | wynik `PayrollEngine::calculate()` (nowa wersja z ledgera) |
| `payroll_team` | Szefa | `TeamPayrollEngine` agregat |

**W Kroku 1 implementujemy TYLKO `clock_in`, `clock_out`, `clock_status`.** Reszta — kolejne iteracje.

### 5.4. Integracja z POS (frontend)

- W `modules/pos/index.html` nagłówek już rezerwuje miejsce na „kto jest na zmianie".
- `modules/pos/js/pos_app.js` dodaje obsługę:
  - Na starcie aplikacji POS → `action=clock_status` (czyje sesje otwarte dla tego `tenant_id`).
  - Modal „Rozliczenie zmiany" z PIN-padem → `action=clock_in` / `clock_out` w `auth_mode='kiosk'`.
  - Response `ALREADY_CLOCKED_IN` przełącza modal na widok „Twoja zmiana trwa X h Y min → [Zakończ zmianę]".

**Ważne:** POS nie przelicza gross na froncie. Pokazuje tylko `preview_earnings` zwrócony z backendu (zgodnie z Prawo IV — Zero Zaufania).

### 5.5. Eventy (`sh_event_outbox`) — kontrakt

| `event_type` | `payload_json` | Konsument |
|---|---|---|
| `employee.clocked_in` | `{ employee_id, session_uuid, start_time, primary_role, terminal_id }` | `worker_driver_fanout` (opt-in: ustawia `sh_drivers.status='available'` jeśli role='driver'), `worker_chat_presence` (opt-in) |
| `employee.clocked_out` | `{ employee_id, session_uuid, end_time, total_hours }` | Jak wyżej + `worker_payroll_accrual` (tworzy wpis `hours_accrual` w ledger) |
| `employee.overtime_alert` | `{ employee_id, session_uuid, hours_so_far, threshold }` | `worker_manager_notification` (push do Szefa — HR-15) |
| `advance.requested` | `{ advance_id, employee_id, amount_minor }` | `worker_manager_notification` |
| `advance.approved` | `{ advance_id, approved_by_user_id }` | `worker_payroll_ledger` (generuje `advance_payout`) |
| `advance.settled` | `{ advance_id }` | `worker_employee_notification` |

---

## 6. ODPOWIEDZI NA PYTANIA STRATEGICZNE

### 6.1. Czy zrywamy z `sh_deductions` / `sh_meals`?

**Nie od razu.** Ledger (`sh_payroll_ledger`) staje się **Single Source of Truth** dla wyliczeń. Stare tabele:
- `sh_deductions` — zostaje tylko jako **DEPRECATED_LEDGER** (komentarz) przez jedną fazę. Nowy `PayrollEngine v2` nie czyta z niej — czyta z ledgera. Migracja danych: jednorazowy skrypt `scripts/migrate_deductions_to_ledger.php`.
- `sh_meals` — **zostaje** (bo ma specyficzne semantyki: który posiłek, kiedy wydany). W momencie zapisu nowego rekordu `sh_meals` → trigger/engine dopisuje `sh_payroll_ledger` z `entry_type='meal_charge'`, `ref_meal_id=...`.

### 6.2. Co z walutami?

**Kanon:** w ramach jednego `tenant_id` **jedna waluta** (`sh_tenant.default_currency` — dodamy w osobnej migracji). Ale *każdy* rekord wpisuje `currency` explicite (HR-3), żeby:
1. Eksport do księgowości był jednoznaczny.
2. Raportowanie cross-tenant (admin hub, faza G) mogło agregować per waluta.
3. Zagraniczny pracownik na kontrakcie B2B mógł mieć stawkę w EUR, mimo że lokal rozlicza się w PLN (future case).

FX-conversion w raportach dzieje się po stronie `PayrollEngine v3` (Faza 4, out of Krok 1 scope).

### 6.3. Czy usuwamy `sh_users.hourly_rate`?

**Nie w pierwszej migracji.** Sekwencja:
1. Migracja 041: tworzy `sh_employees` + `sh_employee_rates`, backfilluje z `sh_users.hourly_rate`.
2. `PayrollEngine v2` zaczyna czytać z `sh_employee_rates`.
3. Weryfikacja przez 2 zamknięcia miesiąca.
4. Migracja 04X: `DROP COLUMN sh_users.hourly_rate` — po uzyskaniu zgody (zgodnie z Konstytucją §6 Prawo Snajpera).

### 6.4. Jak postępujemy z auto-rejestracją kierowcy (HR-4)?

- Legacy `api_auth.php` (stary ClockEngine — już usunięty) robił to *w tej samej transakcji* (`INSERT sh_drivers ON DUPLICATE KEY UPDATE`) — naruszenie DDD.
- **Nowe podejście:** `HrClockEngine::clockIn` publikuje event `employee.clocked_in` do `sh_event_outbox` (aggregate_type='shift'). HR nie modyfikuje `sh_drivers`.
- Konsument (`worker_driver_fanout` — Faza 3B, pod FF `HR_USE_EVENT_DRIVER_FANOUT`) decyduje: jeśli `primary_role='driver'` → `UPDATE sh_drivers.status='available'`.
- **Dopóki workera nie ma**, zachowujemy current behavior (synchroniczny INSERT do `sh_drivers`) — pod flagą feature-flag `HR_USE_EVENT_DRIVER_FANOUT=false`. Przełączymy po napisaniu workera.

### 6.5. Lock okresu — kiedy miesiąc jest „zamknięty"?

- Osobny proces (`PayrollPeriodEngine::close(tenant_id, year, month)`) ustawia `is_locked=1` na wszystkich wpisach ledger z tym okresem. Faza 3C.
- Do tego czasu — wszystkie wpisy otwarte; korekty przez nowy wpis + `reverses_entry_id`.

---

## 7. ODCHYLENIA OD KANONU (jeśli jakieś)

**Brak** — architektura respektuje:

- Prawo I (Macierz Cenowa) — *nie dotyczy* (HR nie ma price_tiers).
- Prawo II (Bliźniak Magazynu) — *nie dotyczy*.
- Prawo III (Temporal) — **RESPEKTOWANE** przez `sh_employee_rates` (effective_from/to).
- Prawo IV (Zero Zaufania) — **RESPEKTOWANE**: POS nie wysyła ani godzin ani gross pay, tylko `action` + ewentualnie PIN.
- Prawo V (Legacy) — **RESPEKTOWANE**: nie kopiujemy kodu 1:1, wyciągamy zasady (§4 mapowanie).
- Prawo VI (Snajper) — **RESPEKTOWANE**: każda modyfikacja `sh_users` opatrzona komentarzem DEPRECATED, żadnych hardcode-delete.
- Izolacja silosów prefiksowych (§9 Konstytucji) — **RESPEKTOWANE**: HR operuje tylko na `sh_*`, żadnych JOIN-ów cross-silo po numerycznym ID.
- Multi-tenancy (§2) — **RESPEKTOWANE**: każda nowa tabela ma `tenant_id` + bariera w indexach.
- ASCII keys / UTF-8 descriptions — **RESPEKTOWANE** (zob. §2.4).

---

## 8. ZGODY WYMAGANE PRZED GENEROWANIEM MIGRACJI

Przed napisaniem SQL potrzebuję potwierdzenia jednej kwestii:

- [ ] **Nazewnictwo `sh_employees`** — potwierdzenie, że to nowa tabela (nie aliasuje istniejącej `sh_users`).
- [ ] **Relacja `employee_id` ↔ `user_id`** — zgoda na model 1:1 z NULLABLE (pracownik może nie mieć loginu systemowego; login może nie mieć profilu HR, np. admin techniczny).
- [ ] **Deprecation `sh_users.hourly_rate`** — zgoda, że w migracji 041 tylko tworzymy `sh_employee_rates` i robimy backfill, **nie usuwamy** kolumny teraz.
- [ ] **Feature-flag `HR_USE_EVENT_DRIVER_FANOUT`** — zgoda, że wprowadzamy przełącznik, żeby nie zepsuć logistyki (domyślnie `false` do czasu aż `worker_driver_fanout` istnieje).
- [ ] **Lock okresu** — odkładamy do Fazy 3C. W Kroku 1 tylko kolumny `is_locked`/`locked_at` w ledger, bez triggerów i bez `PayrollPeriodEngine`.

---

## 9. REGUŁY INTEGRACJI CROSS-SILO (ŚWIĘTOŚĆ — dopisane 2026-04-23)

Niniejsza sekcja kodyfikuje zasadę, która do tej pory była *tylko implicit* w praktyce kodu SliceHub, a którą obie ekipy (Frontline i Backoffice) muszą od teraz respektować literalnie.

### 9.1. Kontrakt izolacji silników (kod)

> **Silos → Silos tylko przez REST albo event bus. `require_once` cudzego Engine-a jest zabronione.**

| Kto | Może `require_once` | NIE może `require_once` |
|---|---|---|
| `api/backoffice/hr/engine.php` (silos HR) | `core/HrClockEngine.php`, `core/PayrollEngine.php`, `core/AdvanceEngine.php`, `core/PayrollLedger.php` | `core/OrderStateMachine.php`, `core/Integrations/*`, `core/AssetResolver.php` |
| `api/pos/engine.php`, `api/tables/engine.php` (silos Orders/Frontline) | `core/OrderStateMachine.php`, `core/AssetResolver.php`, `core/OrderEventPublisher.php`, `core/Integrations/*` | **`core/HrClockEngine.php`** ← kluczowe; POS wywołuje HR **wyłącznie przez `POST /api/backoffice/hr/engine.php`** |
| `api/courses/engine.php` (silos Logistyka) | `core/*` Logistyki | `core/HrClockEngine.php`, `core/OrderStateMachine.php` (reads przez JOIN na `sh_orders` są OK — to shared read model) |

**Precedens w kodzie:** `_docs/17_OFFLINE_POS_BACKLOG.md` §4 stanowi identyczną zasadę dla silosu POS (*"producenci nie dotykają `sh_pos_server_events` — emitują eventy"*). Analogicznie dla HR.

### 9.2. Matryca dopuszczalnej komunikacji

| Źródło → Cel | Sposób | Przykład |
|---|---|---|
| POS → HR (write) | REST `POST /api/backoffice/hr/engine.php` z akcją `clock_in` | POS renderuje kiosk pad z PIN, wysyła `{action:"clock_in", auth:{pin:"1234"}, terminal_id:3}` |
| POS → HR (read) | REST `POST /api/backoffice/hr/engine.php` z akcją `clock_status` | KDS header pokazuje „kto jest w kuchni" |
| Driver App → HR | REST `POST /api/backoffice/hr/engine.php` z akcją `clock_in` / `clock_out` | Kierowca startuje zmianę z mobilki; source=`mobile` |
| HR → Logistyka | **Event async** `employee.clocked_in` / `employee.clocked_out` → `sh_event_outbox` → `worker_driver_fanout` | HR nie widzi `sh_drivers` wprost |
| HR → Accounting (PayrollLedger) | **Wewnątrz silosu** (ledger jest w HR) → `require_once core/PayrollLedger.php` | OK — to jest ta sama bounded context |
| Kadrowa UI (backoffice) → HR | REST `POST /api/backoffice/hr/engine.php` | Wszystko przez action router |

### 9.3. Anti-Corruption Layer dla readów cross-silo

**Pragmatyczny wyjątek:** w obrębie tego samego tenanta HR może wykonać **read-only** `SELECT status FROM sh_drivers WHERE tenant_id=... AND user_id=...` dla walidacji biznesowej (driver nie może zrobić clock-out gdy wiezie zamówienie — `ERR_ACTIVE_DELIVERIES`). To jest database-sharing, nie DDD naruszenie, pod warunkiem:

1. Tylko `SELECT` — żadnych `UPDATE`/`INSERT`/`DELETE` cross-silo.
2. Query zamknięte w prywatnej metodzie silnika (np. `HrClockEngine::driverBusy`), nie leaky do callera.
3. Zero JOIN cross-silo po numerycznym ID (zgodnie z `.cursorrules §9`).

Docelowo (Faza 4+) zamieniamy to na cache lokalny budowany z eventów `driver.status_changed` (jeśli Logistyka go emituje). Do tego czasu — pragmatyczny pass-through jest akceptowalny.

### 9.4. Weryfikacja automatyczna (check-list przy code review)

- [ ] Endpoint nowego silosu używa **tylko** silników ze swojego silosu.
- [ ] Jeśli potrzebujesz write'a do innego silosu → **publish event**, nie włączaj cudzego Engine-a.
- [ ] Jeśli potrzebujesz read'u krytycznego dla biznesu → udokumentuj w komentarzu silnika i ogranicz do prywatnej metody.
- [ ] Kanoniczny endpoint HR to **jeden** plik: `api/backoffice/hr/engine.php`. Nie dodajemy duplikatów w `api/staff/`, `api/hr/`, `api/team/` itp.

---

## 10. PLAN MIGRACYJNY (podsumowanie dla Kroku 2)

Kolejność migracji do wygenerowania:

| Nr | Plik | Zawartość |
|---|---|---|
| **041** | `041_hr_employees_foundation.sql` | `sh_employees` + `sh_employee_rates` + backfill z `sh_users` |
| **042** | `042_hr_work_sessions_extend.sql` | ALTER `sh_work_sessions` (+employee_id, +terminal_id, +source, +geo, +uq_ws_single_open) |
| **043** | `043_hr_payroll_ledger.sql` | `sh_payroll_ledger` (append-only ledger) |
| **044** | `044_hr_advances.sql` | `sh_advances` + `sh_advance_installments` |

Każda migracja **idempotentna** (`CREATE TABLE IF NOT EXISTS`, `IF NOT EXISTS` przy ADD COLUMN na MariaDB 10.4+ — lub odpowiednik przez `INFORMATION_SCHEMA.COLUMNS` check; patrz wzorzec istniejący w `scripts/apply_migrations_chain.php`).

---

## 11. KROK 4 — FAZA 3B (ZROBIONE 2026-04-23)

### 11.1. Dostarczone artefakty

| Plik | Rola | Wielkość | Status testów |
|---|---|---|---|
| `core/PayrollLedger.php` | Append-only writer + reverse + readers dla `sh_payroll_ledger` | ~440 linii | **18/18 PASS** |
| `core/AdvanceEngine.php` | Cykl życia zaliczki (state machine + harmonogram rat) | ~470 linii | **20/20 PASS** |
| `scripts/worker_driver_fanout.php` | Konsument eventów shift → `sh_drivers.status` | ~180 linii | **9/9 PASS** |

### 11.2. Kontrakt Świętości Pieniądza (PayrollLedger)

> **Brak `update()`, brak `delete()`. Korekta = nowy wpis kompensujący przez `reverse()`.**

Zasady wyegzekwowane w kodzie:

1. **STRICT int** — `amount_minor` musi być PHP `int` (grosze). Float / string → `ERR_INVALID_AMOUNT`. Zero tolerancji dla IEEE 754 precision loss.
2. **Sign-per-type** — entry_type narzuca znak:
   - `work_earnings`, `advance_payment`, `bonus` → `amount_minor >= 0`
   - `meal_deduction`, `advance_repayment` → `amount_minor <= 0`
   - `adjustment`, `reversal` → dowolny znak
3. **Whitelist entry_type** — poza 7 zdefiniowanymi typami → `ERR_INVALID_ENTRY_TYPE`. Koniec z dowolnymi stringami w bazie.
4. **tenant_id w każdym zapytaniu** — przekazywany jako pierwszy argument silnika; nigdy z `$payload`. Wszystkie `SELECT` / `INSERT` mają `AND tenant_id = :tid`.
5. **Cross-tenant ref guard** — każde `ref_work_session_id`, `ref_advance_id`, `ref_installment_id`, `ref_meal_id`, `reverses_entry_id` jest walidowane przez prywatną metodę `assertRefBelongsToTenant()` (whitelist tabel). Nie da się sfabrykować wpisu odwołującego się do zasobów innego tenanta.
6. **Idempotency po `entry_uuid`** — drugi `record()` z tym samym UUID zwraca id istniejącego wpisu (bez błędu, bez duplikatu). Pozwala na bezpieczne retries.
7. **Reverse guardy**:
   - Nie można odwrócić wpisu już odwróconego (`ERR_ORIGINAL_ALREADY_REVERSED`).
   - Nie można odwrócić odwrotności (`ERR_ORIGINAL_IS_REVERSAL`) — zamyka nieskończone łańcuchy.
   - Cross-tenant `reverse()` → `ERR_ORIGINAL_NOT_FOUND`.

### 11.3. AdvanceEngine — state machine

```
requested  ─ approve ────► approved ─ markPaid ───► paid ─ (all installments paid) ─► settled
     │                                                 │
     └─ reject ──► rejected                             └─ (void) — Faza 3C
```

- `markPaid()` w jednej transakcji: tworzy `sh_advance_installments` (rozbicie z resztą do ostatniej raty), emituje wpis `advance_payment` do ledgera, flipuje status.
- `recordRepayment()` na każdą ratę: emituje `advance_repayment` (amount < 0) i automatycznie wywołuje `checkSettlement`.
- Każda transition jest hardened: `UPDATE ... WHERE status = :prev AND tenant_id = :tid` — blokuje race conditions.
- Ledger entry UUID-y są **deterministyczne** (pochodne `advance_uuid` + seq), co daje idempotency również dla `markPaid` i `recordRepayment` w razie retry na poziomie infra.

### 11.4. worker_driver_fanout — polityka statusu kierowcy

| Event | `sh_drivers.status` before | after | Warunek |
|---|---|---|---|
| `employee.clocked_in`  | `offline`  | `available` | FF on AND role='driver' |
| `employee.clocked_in`  | `busy` / `available` | **bez zmian** | (kierowca już pracuje) |
| `employee.clocked_out` | `available` | `offline` | FF on AND role='driver' |
| `employee.clocked_out` | `busy` | **bez zmian** | **Kierowca dokończy kurs.** |

- FF czytany per-tenant z `sh_tenant_settings` (setting_key=`HR_USE_EVENT_DRIVER_FANOUT`). Default OFF.
- Retry z backoff (`attempts * 60s`) do `MAX_ATTEMPTS=5`; po tym status eventu = `dead`.
- Worker po stronie Logistyki (touchuje `sh_drivers`), nie `require_once` HR Engine-a. Zgodne z regułami §9.

### 11.5. Co pozostaje (Faza 3C / dalej)

- `PayrollEngine` v2: przepisanie `calculate()` / `buildComparison()` na readery z `PayrollLedger::sumForPeriod` / `listForPeriod`. Obecny `PayrollEngine` wylicza z `sh_work_sessions + sh_deductions + sh_meals` — do wyłączenia po migracji.
- `scripts/worker_payroll_accrual.php` — konsument `employee.clocked_out` → `PayrollLedger::record(work_earnings)`. Będzie używał `sh_employee_rates` do resolutionu stawki w momencie clock_in.
- `AdvanceEngine::voidAdvance()` — dla zaliczek wypłaconych błędnie: `PayrollLedger::reverse(payment)` + `sh_advance_installments.status='void'`.
- Twarde locki księgowe: `sh_payroll_ledger.is_locked` + `locked_at` (kolumny już są). Po zamknięciu okresu rozliczeniowego, `record`/`reverse` na wpisach locked → wprost `ERR_PERIOD_LOCKED`. Faza 3C.
- UI `modules/backoffice/hr/` (Kiosk PIN + Timesheet manager).

### 11.6. Stan zgodności z Konstytucją

- ✅ `tenant_id` w każdym prepared statement.
- ✅ ASCII klucze (`entry_type`, `status`, `repayment_plan`, `paid_method`, error codes).
- ✅ UTF-8 opisy (`description`, `reason`, `rejection_reason`).
- ✅ Monetary = `INT UNSIGNED` (grosze) + `currency CHAR(3) ASCII`. SIGNED tylko w ledgerze (bo +/-).
- ✅ Zero `echo`, zero `die` w core classes — tylko `throw` z ASCII error codes.
- ✅ Zero cross-silo `require_once` (HR → własne Engine + outbox; worker → `sh_drivers` z `db_config`).

---

## ZAMELDOWANIE

Dokument `_docs/18_BACKOFFICE_HR_LOGIC.md` utworzony.

**Audyt legacy zakończony.** Zidentyfikowano:
- 8 algorytmów do zachowania (z uszczelnieniem precyzji).
- 15 wad / długów technicznych (HR-1 do HR-15) z propozycją rozwiązania.

**Architektura fundamentu HR** zdefiniowana:
- 5 nowych tabel w silosie `sh_`: `sh_employees`, `sh_employee_rates`, `sh_payroll_ledger`, `sh_advances`, `sh_advance_installments`.
- 1 ALTER: `sh_work_sessions`.
- Pełny multi-tenant, ASCII keys / UTF-8 descriptions, SIGNED amount w ledgerze, temporalne stawki.
- DDD: trzy agregaty (Employee / WorkSession / PayrollPeriod), komunikacja przez eventy (`sh_event_outbox`).

**API Clock-in / Clock-out** zaprojektowane:
- Jeden `engine.php` (`api/backoffice/hr/engine.php`), action-based.
- Dwa tryby auth: `kiosk` (PIN) i `session` (POS self-service).
- Idempotencja przez unique index + generated column.
- Integracja z POS bez hardcode-logiki payroll na frontu.

**Gotowy do wygenerowania migracji SQL (041–044) po otrzymaniu zielonego światła na listę z §8.**
