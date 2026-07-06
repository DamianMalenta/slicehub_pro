# Sesja 2026-05-22 — Tenant discovery · logowanie PIN · test runner

## Cel

Naprawić logowanie PIN/hasłem i testy integracyjne na instalacji **multi-tenant bez seeda demo (tenant 1 pusty, dane w tenant 2)**.

## Pliki dotknięte

| Plik | Opis |
|------|------|
| `tenant_config.php` | Discovery tenanta z aktywnymi `sh_users`; override `?tenant=N` w URL strony; usunięto błędne `sh_tenant.is_deleted` |
| `tests/test_runner.html` | `discoverAuthTenant()` — skan tenant 1–10 + typowe PIN-y; poprawiony fallback JWT (secret + `tenant_id` z bazy) |
| `scripts/install_panel.php` | Akcja `create_owner` przyjmuje opcjonalny `pin_code` (4 cyfry) przy tworzeniu konta |

## Decyzje architektoniczne

1. **Kolejność rozwiązywania `tenant_id` w frontendzie** (bez zmian priorytetów, tylko naprawa kroku 3):
   - `SLICEHUB_TENANT_ID` (env)
   - sesja PHP po `loginSystem` / `loginKiosk`
   - **pierwszy tenant z co najmniej jednym aktywnym użytkownikiem** (`sh_users.status='active'`, `is_deleted=0`)
   - fallback: pierwszy wiersz `sh_tenant`
   - fallback końcowy: `1`
   - **override w JS:** `?tenant=N` w URL modułu (np. POS) nadpisuje meta `sh-tenant-id`

2. **PIN kasowy wymaga poprawnego `tenant_id` w body** — `loginKiosk()` dopasowuje `(pin_code, tenant_id)`. Meta domyślne `content="1"` w HTML jest tylko placeholderem; `tenant_config.php` musi nadpisać przed startem JS.

3. **Test runner nie zakłada tenant_id=1** — auto-discovery przed suite'ami; fallback JWT podpisany aktualnym `JWT_SECRET` z `db_config.php` (`dev_localhost_secret_change_in_production` lokalnie).

4. **`create_owner` + PIN** — owner/manager/admin tworzeni przez install panel mogą od razu dostać PIN kasowy (wcześniej `pin_code=NULL` → kiosk fail do ręcznego ustawienia w HR).

## Objawy przed fixem (diagnoza)

| Objaw | Przyczyna |
|-------|-----------|
| PIN `0000` „Invalid credentials" mimo poprawnego rekordu w DB | POS wysyłał `tenant_id=1`, użytkownik w `tenant_id=2` |
| Hub: login + hasło fail | Hasło ustawione w install panelu ≠ demo `password` (oczekiwane) |
| T62 BI → HTTP 403 | Test runner: JWT `tenant_id=1` + `user_id=1`, w bazie user `id=1` należy do tenant 2 |
| T28–T30 WARN „0 surowców" | Żądania API z niewłaściwym tenantem / brakiem tokenu |

## Weryfikacja po fixie

```powershell
# tenant_config → tid=2 (gdy tenant 1 pusty)
Invoke-WebRequest http://localhost/slicehub/tenant_config.php

# kiosk + BI
POST /slicehub/api/auth/login.php  {"mode":"kiosk","tenant_id":2,"pin_code":"0000"}
GET  /slicehub/api/bi/dashboard_data.php?date_from=2020-01-01&date_to=2030-12-31
     Authorization: Bearer <token>
```

Przeglądarka: `http://localhost/slicehub/tests/test_runner.html` → **Uruchom Wszystkie Testy**.

## Otwarte pytania

1. Czy usuwać pusty „Demo Tenant" (id=1) przy instalacji produkcyjnej przez install panel, żeby uniknąć mylenia z prawdziwym lokalem?
2. Czy dodać `SLICEHUB_TENANT_ID` do checklisty XAMPP obok `SLICEHUB_API_BASE`?
3. Fallback JWT w test_runner jest hardcoded pod `user_id=1` — przy wielu tenantach z różnymi ID ownerów discovery PIN jest właściwą ścieżką; JWT tylko awaryjnie.
