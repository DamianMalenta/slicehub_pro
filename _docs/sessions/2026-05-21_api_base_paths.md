# Sesja: SSOT prefiksów API i aplikacji (Tier 1–5)

**Data:** 2026-05-21  
**Czas trwania:** ~2 sesje (Tier 1–4, potem Tier 5 + BI/test_runner)  
**Architekt:** AI (Cursor Agent) z udziałem właściciela  
**Commity:** `22a4efc`, `ad57f8e`, `c05a70d`  
**Handoff:** `.cursor/plans/api_base_paths_handoff.md`

---

## 1. Cel

Jeden SSOT prefiksu API we frontendzie — moduły działają lokalnie pod `/slicehub/api` (XAMPP) i na hostingu root (np. uti.pl) pod `/api`, bez hardcoded `/slicehub/api` w kodzie modułów.

---

## 2. Pliki dotknięte

### SSOT i core

| Plik | Co zmieniono |
|---|---|
| `core/js/sh_api_base.js` (NEW) | `getApiBase()`, `getApiFallback()`, `apiUrl()`, `getAppBase()`, `appUrl()` — rejestracja na `window.SliceHub`. |
| `core/js/api_client.js` | `resolveEndpoint()` — `/api/...` i `../../api/...` → `SliceHub.apiUrl()`. |
| `tenant_config.php` | `window.__SH_API_BASE__` z env `SLICEHUB_API_BASE`. |

### Moduły operacyjne (Tier 1–3) — commit `22a4efc`

Hub, POS, Waiter, Tables, Courses, Driver, Kiosk, KDS, Inbox, Procurement, Online, Online Studio, Profile, Studio (meals/recipe), Warehouse (wszystkie HTML + `warehouse_pz.js`).

Wzorzec: `<script src=".../sh_api_base.js">` przed `*_api.js`; fetch przez `SliceHub.apiUrl('/…/engine.php')`.

### PWA / deploy (Tier 4) — commit `22a4efc`

| Plik | Co zmieniono |
|---|---|
| `modules/online/manifest.webmanifest`, `modules/waiter/manifest.json` | Ścieżki relatywne (`./index.html`). |
| `modules/pos/.htaccess`, `modules/online/.htaccess` | Dynamiczne `Service-Worker-Allowed` (`SetEnvIf Request_URI "/slicehub/"`). |
| `modules/online/sw.js`, `offline.html`, `index.html` | Heurystyka `BASE_PATH` z pathname; relatywne linki do core JS. |

### Backoffice (Tier 5) — commit `c05a70d`

| Moduł | Pliki |
|---|---|
| Settings | `index.html`, `settings_app.js`, `notifications.js` |
| Marketing | `index.html` (inline fetch) |
| HR | `index.html`, `hr_app.js` |

### BI + testy — commit `ad57f8e`

| Plik | Co zmieniono |
|---|---|
| `modules/bi/index.html` | `tenant_config` + `sh_api_base.js`. |
| `tests/test_runner.html` | T62: retry auth (waiter → manager PIN → `TEST_JWT_TOKEN`); własny `detectBasePath()` bez SSOT. |

### Dokumentacja

| Plik | Co zmieniono |
|---|---|
| `_docs/DEPLOYMENT_HOSTING.md` | SSOT, env vars, hosting root. |
| `_docs/02_ARCHITEKTURA.md` | Wpis `sh_api_base.js` + konwencja ładowania modułu. |
| `_docs/00_PAMIEC_SYSTEMU.md` | Drzewo `core/js/` + wzorzec API client. |
| `AGENTS.md` | Nota o SSOT i przykładach ścieżek API (po tej sesji audytowej). |

---

## 3. Decyzje architektoniczne

### 3.1 Jeden plik JS jako SSOT (A), nie PHP inject w każdym HTML (B)

**Wybrano A:** `core/js/sh_api_base.js` ładowany w `<head>` modułów.

**Powód:** Zero-Reload (Konstytucja v5) — vanilla JS bez build step. PHP `tenant_config.php` tylko jako opcjonalny override (`__SH_API_BASE__`), nie duplikacja logiki w 20+ HTML.

### 3.2 Kolejność rozwiązywania prefiksu

1. `<meta name="sh-api-base">` — ręczny override per strona  
2. `window.__SH_API_BASE__` — deploy / env  
3. Heurystyka pathname: prefix przed `/modules/` + `/api`  
4. Fallback: `/slicehub/api` gdy URL zawiera `slicehub`, inaczej `/api`

**Powód:** Hosting root nie ma segmentu `/slicehub/`; lokalny XAMPP ma. Heurystyka działa bez konfiguracji; env/meta dla edge case'ów.

### 3.3 `getAppBase()` / `appUrl()` obok API

Uploady, moduły, service worker potrzebują prefiksu aplikacji (`/slicehub` vs ``), nie samego `/api`. Wyprowadzane z `getApiBase()` przez obcięcie `/api`.

### 3.4 `api_client.js` resolve zamiast masowej podmiany stringów w Studio

Studio nadal ma literały `../../api/...` w kodzie; `resolveEndpoint()` normalizuje przy wywołaniu.

**Powód:** Prawo VI (Snajper) — uniknięcie 200-line diff w `studio_*.js` bez zmiany zachowania. Kosmetyka Studio = opcjonalny Tier 6.

### 3.5 test_runner poza SSOT

Własny `detectBasePath()` — test harness musi działać nawet gdy otwarty spod `/tests/` bez pełnego stacku modułu.

### 3.6 T62 BI: retry łańcuch auth zamiast zmiany RBAC

Waiter token nie ma dostępu do `api/bi/dashboard_data.php`. Test retry: bieżący token → manager PIN 0000 → hardcoded owner JWT.

**Powód:** Test weryfikuje endpoint BI, nie uprawnienia kelnera. Zmiana RBAC kelnera = scope creep.

### 3.7 Zgodność z Konstytucją v5

| Prawo | Ocena |
|---|---|
| Zero-Reload | ✓ vanilla JS, CDN Tailwind |
| Klocki Lego | ✓ per-moduł `*_api.js` + core helper |
| Prawo VII (Settings) | ✓ Settings/HR/Marketing poza POS shell — tylko SSOT ścieżek |
| Prawo X (audyt) | ✓ ten plik |

**Test (E2E):** `tests/test_runner.html` — **62/62 PASS** po `php scripts/seed_demo_all.php`. Ręcznie: DevTools `SliceHub.getApiBase()` na Hub/POS; smoke Settings/Marketing/HR.

---

## 4. Otwarte pytania

1. **Tier 6 (opcjonalny):** Podmiana literałów `../../api/` w `modules/studio/js/*` na `SliceHub.apiUrl()` — kosmetyka, bez wpływu na runtime dzięki `api_client.js`.
2. **test_runner na SSOT:** Czy `detectBasePath()` ma delegować do `sh_api_base.js`, czy zostaje izolowany harness?
3. **login.html / AuthEngine:** Ścieżki logowania i redirecty — poza scope Tier 1–5; sprawdzić przy pierwszym deploy na uti.pl.
4. **Service workers:** Własna heurystyka `BASE_PATH` — zsynchronizować z `getAppBase()` tylko jeśli pojawią się bugi cross-origin cache.
5. **Fallbacki `/slicehub/...` w JS** gdy `SliceHub` nie załadowany — audit grep i stopniowe usuwanie lub dokumentacja jako „dev-only”.

---

## Weryfikacja (skrót)

| Test | Wynik |
|---|---|
| `grep /slicehub/api modules/**` | 0 |
| `getApiBase('/slicehub/modules/hub/...')` | `/slicehub/api` |
| `getApiBase('/modules/hub/...')` | `/api` |
| test_runner | 62/62 PASS |
