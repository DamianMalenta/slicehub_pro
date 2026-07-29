# AGENTS.md

## Cursor Cloud specific instructions

### Overview

SliceHub Enterprise OS is a multi-tenant restaurant management system built on a pure LAMP stack:
- **Backend:** PHP 8.3 (no framework, no Composer)
- **Frontend:** Vanilla JS + Tailwind CSS (CDN) — no Node.js, no build step
- **Database:** MariaDB 10.11 (`slicehub_pro_v2`, charset `utf8mb4_unicode_ci`)
- **Web server:** Apache 2 with mod_rewrite

There are **zero external dependencies to install** (no `package.json`, no `composer.json`). The update script handles system packages only.

### Starting services

After the update script runs, start services manually each session:

```bash
# Start MariaDB
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld && mysqld_safe &
sleep 2

# Start Apache
apachectl start
```

The app is served at `http://localhost/slicehub/` via symlink `/var/www/html/slicehub -> /workspace`.

### Lint

```bash
find /workspace -name "*.php" -not -path "*/vendor/*" | xargs -P4 -I{} php -l {}
```

All output should say "No syntax errors detected". Any other output is a failure.

### Tests

Open `http://localhost/slicehub/tests/test_runner.html` in a browser and click "Uruchom Wszystkie Testy". All 62 tests should pass. The tests are JavaScript-based and call the REST API endpoints.

Headless (agent/CI, bez `package.json` w repo):

```bash
cd /tmp && npm install puppeteer-core
node /workspace/scripts/run_test_runner_headless.cjs
# Oczekiwany wynik: "pass": "62", "fail": "0"
```

Test runner **auto-discovery:** przed suite'ami skanuje tenant 1–10 i typowe PIN-y (`0000`…`6666`); nie zakłada `tenant_id=1`. Po `seed_demo_all.php` loguje się jako waiter PIN `1111` na tenant 1. Po instalacji przez `install_panel.php` (tylko tenant 2) — PIN ownera ustawiony przy `create_owner` lub ręcznie w HR.

### API authentication

Prefiks API zależy od deployu: lokalnie `/slicehub/api`, na hostingu root `/api`. Frontend używa SSOT `core/js/sh_api_base.js` (`SliceHub.apiUrl('/auth/login.php')`).

- **Kiosk login (PIN):** `POST {api_base}/auth/login.php` with `{"mode":"kiosk","tenant_id":<tid>,"pin_code":"0000"}` — **`tenant_id` musi zgadzać się z lokalem** (meta `sh-tenant-id` z `tenant_config.php` lub `?tenant=N` w URL modułu).
- **System login:** `POST {api_base}/auth/login.php` with `{"mode":"system","username":"<login>","password":"<hasło>"}` — hasło to to ustawione przy tworzeniu konta (install panel / HR), **nie** domyślne demo `password`, chyba że uruchomiono `seed_demo_all.php`.
- Returns JWT token in `data.token`. Pass as `Authorization: Bearer <token>` header.
- Env overrides w `tenant_config.php`: `SLICEHUB_API_BASE`, `SLICEHUB_TENANT_ID`.

### Tenant ID w frontendzie (`tenant_config.php`)

Kolejność: env `SLICEHUB_TENANT_ID` → sesja PHP → **tenant z aktywnymi użytkownikami** → pierwszy `sh_tenant` → `1`. W przeglądarce: `?tenant=2` w URL modułu nadpisuje meta tag. Szczegóły: `_docs/sessions/2026-05-22_tenant_discovery_auth.md`.

### Demo accounts (tenant_id=1, po `seed_demo_all.php`)

| Username | Role    | PIN  |
|----------|---------|------|
| manager  | manager | 0000 |
| waiter1  | waiter  | 1111 |
| waiter2  | waiter  | 2222 |
| cook1    | cook    | 3333 |
| driver1  | driver  | 4444 |
| driver2  | driver  | 5555 |
| team1    | team    | 6666 |

### Key gotchas

1. **No npm/Node.js/build tools.** The `.cursorrules` explicitly forbids them. Tailwind is loaded from CDN.
2. **Database config** is hardcoded in `core/db_config.php` (root@localhost, empty password, database `slicehub_pro_v2`).
3. **MariaDB root auth** must use `mysql_native_password` with empty password (not unix_socket) for Apache's PHP process to connect.
4. **Migration failures for 015/030/037** are pre-existing MariaDB 10.11 compatibility issues and do not block the application from running.
5. **App path:** Cloud Agent symlink serves at `/slicehub/`; XAMPP lokalnie też. Moduły używają `SliceHub.apiUrl()` / `appUrl()` — nie hardcoduj `/slicehub/api`. Szczegóły: `_docs/sessions/2026-05-21_api_base_paths.md`.
6. **Database reset path:** `nuclear_reset.php` → `seed_demo_all.php` to get clean demo data (orders/users only — not full schema).
7. **Full schema rebuild (golden path):** Import `001_init_slicehub_pro_v2.sql` → `php scripts/apply_migrations_chain.php` → `php scripts/seed_demo_all.php`. See `_docs/SEED_GUIDE.md`. Optional: `seed_pizzaforno.sql` (tenant 2).
8. **KSeF demo data** is included in `seed_demo_all.php` (`FA/DEMO/*` invoices). Run migrations through **059** before seeding.
9. **Multi-tenant bez seeda demo:** pusty tenant 1 + dane w tenant 2 → POS/testy wymagają poprawnego discovery (`tenant_config.php`, commit `e92b095`). PIN kasowy: zawsze para `(tenant_id, pin_code)`.
10. **CredentialVault (Faza 7.6):** `extension=sodium` musi być włączone w `php.ini` (XAMPP: odkomentuj `extension=sodium` linia 958; DLL `php_sodium.dll` już w `ext/`). Klucz vaultu: `php scripts/bootstrap_vault.php` → `config/vault_key.txt` (ignorowany przez `.gitignore`). Rotacja plaintext → vault: `php scripts/rotate_credentials_to_vault.php --live` — wymaga migracji **063** (relaksuje CHECK constraint na `sh_tenant_integrations.credentials`, bez niej UPDATE odrzuca `SQLSTATE 23000 / kod 4025`). Po edycji `php.ini` zrestartuj Apache (CLI pickuje od razu, Apache dopiero po restarcie). Szczegóły: `_docs/sessions/2026-07-30_credential_vault_bootstrap_and_rotation.md`.
11. **MariaDB wersja (XAMPP vs Cloud):** AGENTS.md mówił „MariaDB 10.11" — to prawda dla środowiska Cloud Agent. **XAMPP lokalnie ma 10.4.32**. Skutki: `DROP CHECK IF EXISTS` / `ADD CONSTRAINT IF NOT EXISTS` **nie działają** na 10.4 (migracja 063 używa `MODIFY COLUMN` zamiast tego). Migracje 015/030/037 mogą sypać błędami kompatybilności na XAMPP — to pre-existing, nie blokuje działania aplikacji.
12. **ChoiceQR (P2.1, 2026-07-29):** ChoiceQR ma **odwrócony model** (oni pushują do nas) i własne endpointy zamiast `inbound.php`: `api/integrations/choiceqr/events.php` (webhook eventy: status/delivery/QR payment/menu), `api/integrations/choiceqr/pay.php` (potwierdzenie płatności QR przy stoliku). Auth: token w `?t=SECRET_TOKEN`, tenant mapping przez `varSymbol`. Wszystkie endpointy modyfikujące `sh_orders` używają `SELECT ... FOR UPDATE` w transakcji. Response: **200 OK empty body** (ChoiceQR anuluje zamówienie po 3 brakach 200). `inbound.php` (generic) też przepięte na `OrderStateMachine::transitionOrder()` (zamiast hardcoded whitelist) + obsługa `delivery_status` i `payment_status`. Adapter: `core/Integrations/ChoiceQRAdapter.php`. Szczegóły: `_docs/14_INBOUND_CALLBACKS.md` sekcja 13.
13. **Fiskalizacja Elzab (smart switch, 2026-07-29b):** POS sprawdza status drukarki przy starcie (`_fiscalReady` w `pos_app.js`). Jeśli Elzab Zeta Online online → drukuje **wyłącznie paragon fiskalny** (numer → `sh_orders.fiscal_receipt_number`, migracja 062). Jeśli offline → fallback na paragon niefiskalny (`window.print`). Guard w `ElzabFiscalEngine::fiscalizeOrder()` blokuje podwójną fiskalizację (chyba że `force=true` dla reprintu). Na karcie zamówienia jeden przycisk (🧾 fiskalny / 📄 niefiskalny) zamiast dwóch. Konfiguracja drukarki w module Settings (zakładka „Drukarka Fiskalna"). Szczegóły: `_docs/audits/fiscalization_status.md`.
