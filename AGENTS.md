# AGENTS.md

## Cursor Cloud specific instructions

### Overview

SliceHub Enterprise OS is a multi-tenant restaurant management system built on a pure LAMP stack:
- **Backend:** PHP 8.3 (no framework, no Composer)
- **Frontend:** Vanilla JS + Tailwind CSS (CDN) — no Node.js, no build step
- **Database:** MariaDB 10.11 (`slicehub_pro_v2`, charset `utf8mb4_unicode_ci`)
- **Web server:** Apache 2 with mod_rewrite

There are **zero runtime dependencies to install** (no `composer.json`, no Node.js in production runtime). A `package.json` exists at repo root but contains **only the dev/test dependency `puppeteer-core`** for the headless test runner — it is NOT a runtime/build dependency and the app never runs `npm install` in production. The update script handles system packages only.

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

### Deno lint (JS static analysis)

Repo zawiera `deno.jsonc` (od 2026-08-24, PR #61) z konfiguracją reguł lintera Deno. Deno lint jest używany **wyłącznie** jako narzędzie analizy statycznej JS — aplikacja nigdy nie działa pod Deno.

```bash
deno lint
# Oczekiwany wynik: "Checked 108 files" bez błędów (exit 0)
```

**Wykluczone reguły** (false positives w codebase z classic `<script>` bez ES modules):
- `no-unused-vars` — cross-file globals (App, CoursesAPI, DriverApp, TablesAPI itp.) wywoływane z inline `onclick` w HTML, ale Deno analizuje pliki osobno.
- `no-empty` — celowe puste catch blocks z hardcoded fallback.
- `require-await` — async stubs/handlers dla spójności API.
- `prefer-const` — stylistyczne; let vs const w pre-existing code.
- `no-node-globals` / `no-process-global` — `process.*` w `.cjs` test runner (Deno 1.x vs 2.x naming).
- `no-this-alias` — pre-existing pattern w jednym pliku.

**Reguły NIE wykluczone** (naprawione w kodzie, PR #60):
- `no-window` / `no-window-prefix` — zastąpione `window.*` → `globalThis.*` w 72 plikach JS (919 błędów → 0).

**CI:** `.github/workflows/deno.yml` uruchamia `deno lint` (krok `deno test -A` wykomentowany — SliceHub nie ma testów Deno; testy są browser-based przez puppeteer-core). Workflow jest `workflow_dispatch` (ręczny trigger).

**Pełna klasyfikacja `no-unused-vars`:** `_docs/audits/ui_handlers_order_audit_2026-08-24.md` — 76 wpisów: [A] aktywna (31), [B] stub (9), [C] martwy (35), [D] zublowana (1).

### Tests

Open `http://localhost/slicehub/tests/test_runner.html` in a browser and click "Uruchom Wszystkie Testy". All 62 tests should pass. The tests are JavaScript-based and call the REST API endpoints.

Headless (agent/CI). `puppeteer-core` jest już w repo `package.json`; można `npm install` w repo lub w `/tmp`:

```bash
npm install              # w repo (package.json: puppeteer-core) — lub: cd /tmp && npm install puppeteer-core
node /workspace/scripts/run_test_runner_headless.cjs
# Oczekiwany wynik: "pass": "62", "fail": "0"
```

> **⚠️ ŻELAZNA REGUŁA WERYFIKACJI (od 2026-08-21):** Domyślna weryfikacja po zmianach kodu to **WYŁĄCZNIE** headless test runner (`node scripts/run_test_runner_headless.cjs`). **ZABRONIONE** jest odpalanie wirtualnej przeglądarki interaktywnej, testów UI przez skill `testing-slicehub-ui`, Puppeteer screenshot-walków ani jakichkolwiek testów klikalnych w przeglądarce — **chyba że prompt użytkownika wprost o to poprosi**. Headless runner jest jedynym kanonically-correct kanałem weryfikacji: deterministyczny, bezstanowy, nie wymaga GUI, działa w CI i na Cloud Agent. Skill `testing-slicehub-ui` (`.agents/skills/`) pozostaje dostępny ale **nie jest domyślnym narzędziem weryfikacji** — używaj tylko na wyraźne żądanie.

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

1. **No Node.js in production runtime / no build step.** `.cursorrules` §3 forbids Node.js/npm as a *runtime* dependency manager and any on-host build step; Tailwind is loaded from CDN. A `package.json` **does exist** at repo root but holds only the dev/test dep `puppeteer-core` (headless test runner) — `node_modules/` is gitignored. `git clone` still yields a working app with no `npm install`. Dev tooling (Vite/esbuild/TS) is allowed locally per `.cursorrules` §3 since v5/2026-05-11, but output must be committed and hosting never runs a build.
2. **Database config** is **env-driven** in `core/db_config.php` via `getenv('SLICEHUB_DB_HOST|NAME|USER|PASS')` (commit `31f6f7c`, "hosting-ready"), falling back to XAMPP defaults (`localhost` / `slicehub_pro_v2` / `root` / empty) when env vars are unset. Hosting (uti.pl etc.) sets the env vars in the PHP-FPM/panel; locally on XAMPP you leave them unset. JWT secret likewise reads `getenv('JWT_SECRET')` with a dev-only fallback.
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
14. **Architektura = Modular Monolith (NOT microservices).** Single MariaDB instance (`slicehub_pro_v2`), zero physical silo separation, zero Docker, zero Redis. The "3 silos bazodanowych" in `wniosek.md` §1.4 / `.cursorrules` §9 are **prefix-based logical DDD silos** (`sh_` / `sys_` / `wh_`) inside ONE shared schema — cross-silo JOINs go through VARCHAR keys (`sku`, `ascii_key`) + `tenant_id`, never numeric IDs across prefixes. Async integration is the **SQL Transactional Outbox pattern**: `OrderEventPublisher::publish()` writes `sh_event_outbox` rows IN the same transaction as the order mutation (migration `026_event_system.sql`), then CLI workers (`scripts/worker_webhooks.php`, `worker_integrations.php`, `worker_notifications.php`, `worker_driver_fanout.php`, `worker_payroll_accrual.php`) drain the outbox via cron/`--loop` with PID-file locks + atomic row-level claim. Multi-tenancy is app-level Row-Level Security on the `tenant_id` column, NOT per-tenant databases. No message broker, no cache layer — `InAppChannel` uses a `sh_sse_broadcast` table instead of Redis pub/sub; gateway rate-limit is in-DB instead of Redis. **Drift note:** `wniosek.md` (grant report, maj 2026) line 253 still claims `✗ package.json — nie istnieje` and speculates about a future Dockerfile — both are now stale (package.json exists for puppeteer-core; Docker remains a future option only, no Dockerfile in repo).
15. **Drift Rectification (2026-08-04):** Session `_docs/sessions/2026-08-04_drift_rectification_N1_N5.md` fixed 8 drifts: restored `worker_payroll_accrual.php`, added 6 missing outbox publications (`edit.php`, `inbound.php`, `ElzabFiscalEngine`, `PanicEngine`, `courses/engine.php` ×4, `pos/engine.php` dispatch), removed orphan `KdsTicketEngine`, removed sync `PapuClient` (replaced by async `PapuAdapter` via outbox), fixed POS ZAAKCEPTUJ sending `now` instead of null (blocking PromisedTimeEngine ASAP), replaced `mt_rand` UUID with `Uuid::v4()` (CSPRNG). **Rule: every `$pdo->commit()` that mutates `sh_orders` MUST have a preceding `OrderEventPublisher::publishOrderLifecycle()` call inside the same transaction.**
