# 19. HUB-CENTRIC SHELL · KIOSK · STAFF FLEET PRESENCE

> **Status:** SSOT (Single Source of Truth) — 2026-05-04
> **Zakres:** filozofia auth, topologia kont, kiosk obecności, presence-based fleet
> **Powiązane:** `01_KONSTYTUCJA.md`, `02_ARCHITEKTURA.md`, `13_SETTINGS_PANEL.md`, `18_BACKOFFICE_HR_LOGIC.md`

Ten dokument **ma pierwszeństwo** nad starszymi sekcjami w `00_PAMIEC_SYSTEMU.md` § 7 / `02_ARCHITEKTURA.md` § 1, jeśli pojawi się sprzeczność. Konstytucja (`01_*`) jest nadrzędna nad wszystkim.

---

## 1. FILOZOFIA — „Jeden lokal, jeden Hub"

SliceHub Enterprise jest projektowany pod **multi-restaurant chain / franczyzę**. Każda fizyczna lokalizacja (`sh_tenant`) ma własną „instalację" złożoną z:

| Warstwa | Auth | Co to | Urządzenie |
|---|---|---|---|
| **Hub** | login + hasło konta lokalu (owner / manager / admin) | Centralny launcher — siatka kafelków: POS / Kiosk / Plan sali / KDS / Dispatch / Kadry / Settings / Studio / Online | Komputer biurowy / tablet zaplecza |
| **POS** | PIN 4 cyfry (kasa) | Sprzedaż przy ladzie. Otwierany z Hub jako kafel albo bezpośrednio. | Stacjonarna kasa (touch / mysz) |
| **Kiosk obecności** | login terminala (konto techniczne lokalu) → PIN pracownika | Fizyczny zegar na ścianie zaplecza | Tablet / mały terminal w korytarzu |
| **KDS** | sesja przeniesiona z POS (Bearer JWT) lub login | Tablica kuchenna — bumpy, recall | Ekran w kuchni |
| **Dispatch** | sesja przeniesiona z POS | Mapa kursów, dispatch, reconcile | Komputer dyspozytora |
| **Driver App (PWA)** | login + hasło → JWT | Telefon kierowcy — kursy, GPS, payment lock, recall | Telefon kierowcy (osobiste) |
| **Waiter App (PWA)** | login + hasło → JWT | Telefon kelnera — stoliki, rachunki | Telefon kelnera (osobiste) |
| **Settings** | sesja Hub | Integracje, webhooks, API keys, audit, notifications | Tablet zaplecza |
| **Backoffice HR** | sesja Hub + role gate (`hrRequireManager`) | CRUD pracowników, PIN, stawki | Tablet zaplecza |

**Konsekwencje dla auth:**
- Konta **owner / manager / admin** = login + hasło (logowanie do Hub, dostęp do administracji).
- Konta operacyjne (kasjer, kelner, kierowca) = **PIN 4 cyfry** w POS / Kiosk + opcjonalnie login + hasło dla aplikacji mobilnych.
- Pracownicy mobilni (driver / waiter) **nie muszą** logować się do Hub — mają własną PWA.
- Kiosk obecności jest **dedykowanym terminalem** — login raz na instalację (konto techniczne), potem wielu pracowników odbija PIN-em.

---

## 2. AUTH FLOW

### 2.1. Hub login (manager / owner / admin)

```
Hub (modules/hub/index.html)
  │
  ▼  username + password
api/auth/login.php  mode=system
  │  loginSystem() → JWT 24h + sesja PHP (cookie SameSite=Strict)
  │  slicehubTouchStaffPresence()
  ▼
HUB DASHBOARD (siatka kafelków)
  ├─→ ../pos/index.html         (sesja PHP wystarczy; POS może też wymagać PIN)
  ├─→ ../kiosk/index.html       (sesja PHP TERMINALA; pracownicy odbijają PIN-em)
  ├─→ ../tables/index.html
  ├─→ ../kds/index.html
  ├─→ ../courses/index.html
  ├─→ ../backoffice/hr/index.html  (gate: hrRequireManager)
  ├─→ ../settings/index.html
  └─→ ../studio/index.html
```

### 2.2. POS PIN (kasjer / waiter / cook / driver / owner też OK)

```
POS pin-screen (modules/pos/index.html)
  │  PIN 4 cyfry
  ▼
api/auth/login.php  mode=kiosk
  │  loginKiosk() — regex /^\d{4}$/
  │     1) szuka sh_users.pin_code = :pin AND tenant_id = :tid AND status='active' AND is_deleted=0
  │     2) FALLBACK: sh_employees.auth_pin_hash (bcrypt) → resolveByEmployeePin → sync sh_users.pin_code
  │  JWT 8h + slicehubTouchStaffPresence
  ▼
POS APP (z `target_module` w response)
```

**Owner OK od 2026-05-04** (model jak Toast / Square — typowy POS pozwala wszystkim rolom z PIN-em, dawne `AuthForbiddenException` dla owner usunięte).

**Tenant ID w POS (2026-05-22):** `modules/pos/index.html` ma meta `sh-tenant-id` nadpisywane przez `tenant_config.php` **przed** startem `pos_app.js`. Kolejność discovery: env `SLICEHUB_TENANT_ID` → sesja PHP → tenant z aktywnymi użytkownikami → fallback. Override: `?tenant=2` w URL. `loginKiosk()` wymaga zgodności `(tenant_id, pin_code)` — PIN z innego lokalu zawsze zwraca 401.

### 2.3. Kiosk obecności (3-stage)

```
Stage 1 — TERMINAL LOGIN (raz na instalację)
  modules/kiosk/index.html  →  view-terminal
    ka-form-terminal: username + password (konto techniczne lokalu)
    │
    ▼
  api/auth/login.php  mode=system  (zapis JWT w localStorage 'sh_token')

Stage 2 — PIN PRACOWNIKA (każda zmiana)
  view-pin (PIN-pad, _kaPin)
    PIN 4 cyfry
    │
    ▼
  api/backoffice/hr/engine.php  action=clock_status  auth.pin=...
    │  (POST z body, Bearer JWT terminala)
    │  HrClockEngine::resolveEmployeeByPin (bcrypt verify)
    ▼
  data.employee_snapshot + data.open_sessions

Stage 3 — STAN ZMIANY
  view-work (timer + akcje)
    [Rozpocznij prace]  → action=clock_in   auth.pin=... source=kiosk
    [Zakończ prace]     → action=clock_out  auth.pin=... source=kiosk
    [Inna osoba]        → reset PIN buffer, wraca do Stage 2
```

**Wylogowanie terminala** czyści `sh_token`, woła `api/auth/logout.php` (clearStaffPresence) i wraca do Stage 1.

### 2.4. Driver / Waiter (mobile PWA)

Niezależne PWA z własnym bootstrappem. Auth: login + hasło → JWT. Brak konieczności logowania do Hub.

---

## 3. KIOSK · KONSTYTUCJA §9 (cross-silo)

`modules/kiosk/` realizuje **dedykowany terminal HR** w sposób zgodny z izolacją silosów:

✅ **Co jest dozwolone:**
- `kiosk_attendance.js` woła `/api/auth/login.php` (silos auth) i `/api/backoffice/hr/engine.php` (silos HR) — czyste REST.
- Współdzielony CSS przez `../ui_shell/sh_mobile_shell.css` — to **definicje stylu**, nie logika.

❌ **Co jest zabronione (i czego pilnujemy):**
- `import` z `../pos/js/*` — łamie izolację. Stary `kiosk_app.js` (usunięty 2026-05-04) miał `import { initPosHrClock } from '../../pos/js/pos_hr_clock.js'` — to było naruszenie.
- Bezpośrednie `require_once` lub PHP include między silosami modułowymi.

> **Dlaczego to ważne:** kiedy w Fazie 5 dojdzie self-service ekipy (`modules/ekipa/`), każdy silos musi się komunikować z HR przez te same REST-owe akcje. Brak shortcut'u przez import = brak długu technicznego.

---

## 4. STAFF FLEET PRESENCE (NEW · 2026-05-04)

### 4.1. Problem
POS pokazuje listę kierowców i kelnerów (dropdown w panelu fleet, polling co 8 s). Wcześniej pokazywała **wszystkich** z `sh_drivers` / `sh_users(role='waiter')`. Manager widział kierowców, którzy nie pracowali tego dnia, kelnerów, którzy zmienili lokal — szum.

### 4.2. Rozwiązanie

`core/StaffFleetPresence.php`:

```php
slicehubFleetPresenceTtlSeconds(): int       // = 120
slicehubTouchStaffPresence(PDO, tenantId, userId): void   // UPDATE sh_users.last_seen = NOW()
slicehubClearStaffPresence(PDO, tenantId, userId): void   // UPDATE sh_users.last_seen = NULL
```

### 4.3. Punkty heartbeat

| Endpoint / akcja | Wywołanie |
|---|---|
| `api/auth/login.php` (po success) | `slicehubTouchStaffPresence` |
| `api/courses/engine.php` action=`update_location` | touch |
| `api/courses/engine.php` action=`get_driver_runs` (polling z driver app) | touch + ensureFleetRow |
| `api/tables/engine.php` action=`get_floor_status` (polling z waiter / tables) | touch |
| `api/auth/logout.php` (po dekodzie JWT) | clear |
| `api/courses/engine.php` action=`set_driver_status` z `'offline'` (sam siebie) | clear |

### 4.4. Reguła widoczności w POS

`api/pos/engine.php` (akcja `get_pos_data` i polling driver list):

```sql
WHERE u.tenant_id = ?
  AND u.is_deleted = 0
  AND (
    d.status = 'busy'                                                  -- kierowca w trasie
    OR (u.last_seen IS NOT NULL AND u.last_seen >= NOW() - INTERVAL 120 SECOND)
  )
```

Dla kelnerów: identycznie, bez warunku `busy` (bo kelner nie ma stanu „w trasie").

### 4.5. Konsekwencja

- Kierowca, który zalogował się rano i potem zamknął aplikację → znika z floty po 120 s.
- Kierowca w trasie (`busy`) → widoczny zawsze, nawet gdy GPS / heartbeat się zatrzyma (manager musi wiedzieć, że gość jest w drodze).
- Manager logujący się do POS po 6h → nie widzi kierowców z poprzedniej zmiany.

---

## 5. POLITYKA PIN (od 2026-05-04)

| Aspekt | Decyzja |
|---|---|
| Długość | **dokładnie 4 cyfry** (regex `^\d{4}$`) — wszędzie: POS, Kiosk, HR clock |
| Storage | `sh_users.pin_code` (plain — używane przez `loginKiosk` szybkie dopasowanie) **+** `sh_employees.auth_pin_hash` (bcrypt — używane przez `HrClockEngine::resolveEmployeeByPin`). Synchronizacja: `employee_pin_set` ustawia oba. |
| Jeden PIN, dwa kanały | Ten sam PIN działa w POS (loginKiosk) i Kiosku (HR clock). Manager ustawia raz w `modules/backoffice/hr/`. |
| Owner | **Może** PIN-em do POS (od 2026-05-04). Backoffice nadal hasłem przez `loginSystem`. |
| Manager_override w HR | `auth.employee_id` w body action `clock_in` / `clock_out` — manager odbija zmianę za nieobecnego pracownika. Wymaga roli ≥ manager (gate `hrRequireManager`). |

---

## 6. KONTRAKT Z `modules/backoffice/hr/`

UI Kadry (`hr_app.js`) używa wyłącznie tych akcji `api/backoffice/hr/engine.php`:

```
employees_list           → tabela pracowników (z account info + has_kiosk_pin flag)
employee_get             → load do modala edycji (z aktualną stawką)
employee_upsert          → save modala (opcjonalnie create_login → tworzy sh_users)
employee_pin_set         → modal „Ustaw PIN kiosku" (4 cyfry, sync do sh_users.pin_code)
employee_rate_set        → modal „Ustaw stawkę" (zamyka poprzednią linię w sh_employee_rates)
hr_users_unlinked        → dropdown „podepnij istniejące konto" przy upsercie
```

Wszystkie akcje wymagają `hrRequireManager` (rola ≥ manager + tenant_id z guarda).

---

## 7. ROADMAPA SHELL (krótkoterminowa)

| Faza | Zakres | Status |
|---|---|---|
| 4.1 | UI Kadry — CRUD pracowników, PIN, stawki | ✅ DONE 2026-05-04 |
| 4.2 | Kiosk obecności (3-stage flow) | ✅ DONE 2026-05-04 |
| 4.3 | Hub launcher + presence-based fleet POS | ✅ DONE 2026-05-04 |
| 4.4 | Rewrite `PayrollEngine::calculate()` IN-PLACE — readery z `sh_payroll_ledger::sumForPeriod` | ⏳ następna sesja (zakaz plików równoległych — `_docs/18 §13`) |
| 4.5 | UI Payroll w Backoffice HR (`payroll_period`, `payroll_team`) | ⏳ po 4.4 |
| 5.1 | `modules/ekipa/` — self-service mobile PWA pracownika | ⏳ |
| 5.2 | UI zaliczek (`AdvanceEngine` ma silnik gotowy: 34/34 smoke PASS) | ⏳ |

---

## 8. CO ZNIKNĘŁO 2026-05-04 (DECYZJE PORZĄDKOWE)

| Plik / katalog | Powód usunięcia |
|---|---|
| `modules/kiosk/attendance.html` | Promowany do `modules/kiosk/index.html` (kanon) |
| `modules/kiosk/js/kiosk_app.js` (stary) | Importował z silosu POS (`pos_ui.js`, `pos_hr_clock.js`) — **łamał Konstytucję §9**. Tryb POS-PIN dla kiosku był nadprogramowy, wbrew planowi z `_docs/18 §5.1` („dedykowany terminal tylko do PIN-login"). |
| `modules/shared/` | Przeniesione do `modules/ui_shell/` — `shared` był nazwą dryfu (pojawił się ad-hoc, mieszał warstwy). `ui_shell` jest jasne semantycznie. |

---

## 9. OTWARTE DECYZJE (do potwierdzenia przez właściciela)

1. **`modules/pos/js/pos_hr_clock.js`** — modal „Zmiana" w POS (convenience). Kiosk dedykowany **już istnieje** (`modules/kiosk/`), więc to formalnie duplikat. Pozostawienie = wygoda dla operatora (nie musi iść do kiosku). Usunięcie = czystsza separacja silosów. **Decyzja: czeka na właściciela produktu.**
2. **Auto-redirect na Hub** po loginie w pojedynczych modułach (np. otwarcie `modules/pos/` w nowej karcie bez sesji powinno przekierować na Hub). Obecnie pokazuje pin-screen — wymaga decyzji UX.

---

**Wytyczne zmiany:** Każda zmiana w auth flow / presence / cross-silo policy musi być zsynchronizowana w trzech miejscach: ten dokument, `_docs/02_ARCHITEKTURA.md` § 2, i `_docs/00_PAMIEC_SYSTEMU.md` § 7. Brak synchronizacji = krytyczny bug dokumentacyjny, blokujący kolejne sesje AI.
