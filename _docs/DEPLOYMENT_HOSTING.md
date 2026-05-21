# DEPLOYMENT HOSTING — shared hosting (PHP/MySQL)

> **Cel:** od zera do działającego produktu na hostingu współdzielonym (uti.pl, OVH, home.pl, kei.pl) bez SSH, bez Composer-a, bez build-stepa. Wszystko przez panel hostingu i przeglądarkę.
>
> **Zasada:** każdy krok jest **idempotentny** — można go powtórzyć po błędzie, nic się nie psuje. Migracje są chronione `IF NOT EXISTS` i `ON DUPLICATE KEY UPDATE`.
>
> **Środowiska:**
> - **Lokalnie (XAMPP)** — zostawiasz `db_config.php` jak jest, wszystko działa od ręki.
> - **Hosting** — ustawiasz 4 zmienne środowiskowe i wgrywasz `.htaccess`. Reszta przez przeglądarkę.

---

## ✅ KROK 1 — `.htaccess` w `public_html/` (root domeny)

> **Po co:** historycznie kod używał absolutnych ścieżek `/slicehub/api/...`. Od migracji Tier 3 (2026-05-21) frontend używa **SSOT** `core/js/sh_api_base.js` — `SliceHub.getApiBase()` zwraca `/api` na hostingu root bez dodatkowej konfiguracji. Reguła aliasu `/slicehub/` poniżej nadal pomaga dla starych zakładek, manifestów PWA i uploadów zwracanych z API z prefiksem `/slicehub/`.

**Co zrobić:**

1. Panel uti.pl → menedżer plików → wejdź do `public_html/` (root domeny `<TWOJA_DOMENA>`).
2. Stwórz nowy plik o nazwie **dokładnie** `.htaccess` (z kropką na początku, bez rozszerzenia).
3. Wklej zawartość:

```apacheconf
RewriteEngine On

# (1) Pass Authorization header to PHP (Apache strips it by default)
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

# (2) ALIAS /slicehub/X → /X (ratunek dla absolutnych ścieżek w kodzie)
RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1) [NC]
RewriteRule ^slicehub/(.*)$ /$1 [L]
```

4. Zapisz.

**Test:** wejdź na `https://<TWOJA_DOMENA>/index.html` — powinno przekierować na `https://<TWOJA_DOMENA>/modules/hub/index.html`. Jeśli jeszcze nie ma styli/skryptów — to OK, idziemy dalej. Jeśli 404 na samej stronie głównej — sprawdź czy plik nazywa się dokładnie `.htaccess` (kropka), czy jest w `public_html/` (nie w podkatalogu), czy hosting ma włączony `mod_rewrite`.

**Pełna instrukcja referencyjna:** `_docs/hostingowy_htaccess_root.txt`.

### Prefiks API (SSOT)

| Środowisko | URL modułu | `SliceHub.getApiBase()` | `SliceHub.getAppBase()` |
|---|---|---|---|
| XAMPP lokalnie | `/slicehub/modules/hub/...` | `/slicehub/api` | `/slicehub` |
| Hosting root (uti.pl) | `/modules/hub/...` | `/api` | `` (pusty) |

Manifesty PWA (`online/manifest.webmanifest`, `waiter/manifest.json`, `pos/manifest.webmanifest`) używają **ścieżek relatywnych** (`./index.html`) — działają na obu mountach bez zmian.

Moduły `.htaccess` (POS, Online) ustawiają `Service-Worker-Allowed` dynamicznie wg `Request_URI` (XAMPP vs root).

Opcjonalnie w panelu hostingu (Plesk / env): `SLICEHUB_API_BASE=/api` — ustawia `window.__SH_API_BASE__` przez `tenant_config.php`.

Moduły ładują `tenant_config.php` jako `<script src="../../tenant_config.php">` (relatywnie od `modules/*/`).

---

## ✅ KROK 2 — Baza danych (utwórz pustą + zaimportuj `001`)

> **Status:** Według ostatniej rozmowy z 2026-05-05 — wykonane (baza istnieje + migracja 001 wgrana). Sekcja zostaje jako referencja przy świeżym deployu.

**Co zrobić (jeśli świeży hosting):**

1. Panel uti.pl → MariaDB / MySQL → utwórz nową bazę. Zapisz **dokładnie**:
   - nazwę bazy (np. `srv12345_slicehub`)
   - login użytkownika (np. `srv12345_admin`)
   - hasło
   - host (zazwyczaj `localhost` na uti.pl, czasami `mysql.serwer.uti.pl`)
2. phpMyAdmin (z panelu) → wybierz bazę → zakładka „Import" → wybierz plik `database/migrations/001_init_slicehub_pro_v2.sql` z lokalnego repo → Wykonaj.
3. Powinno się skończyć bez błędów (jeśli któraś tabela już istnieje od wcześniejszego eksperymentu — skasuj bazę i utwórz pustą).

---

## ✅ KROK 3 — Zmienne środowiskowe DB w panelu hostingu

> **Po co:** żeby `db_config.php` na produkcji NIE miał plaintext'em hasła do bazy. Wzorzec już jest dla `JWT_SECRET`. Po commicie [data: 2026-05-05] obejmuje też 4 zmienne DB.

**Co zrobić:**

Panel uti.pl → ustawienia hostingu → zmienne środowiskowe (PHP environment variables / Custom Environment Variables — różne nazwy menu w różnych wersjach panelu). Dodaj:

| Zmienna | Wartość |
|---|---|
| `SLICEHUB_DB_HOST` | host bazy (np. `localhost` lub `mysql.serwer.uti.pl`) |
| `SLICEHUB_DB_NAME` | nazwa bazy (z Kroku 2) |
| `SLICEHUB_DB_USER` | login DB (z Kroku 2) |
| `SLICEHUB_DB_PASS` | hasło DB (z Kroku 2) |
| `JWT_SECRET` | losowy 64-znakowy ciąg, **inny niż lokalny** |
| `SLICEHUB_API_BASE` | opcjonalnie `/api` — wymusza prefiks API (domyślnie heurystyka pathname) |

**Wygenerowanie `JWT_SECRET`** — w PowerShell na lokalu:

```powershell
[Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
```

albo w przeglądarce DevTools Console:

```js
crypto.getRandomValues(new Uint8Array(32)).reduce((s,b)=>s+b.toString(16).padStart(2,'0'),'')
```

Skopiuj wygenerowany ciąg do `JWT_SECRET`.

**Alternatywa (jeśli panel uti.pl NIE wspiera env-ów):** edytuj plik `core/db_config.php` na hostingu (przez menedżer plików) — w liniach z `$host`/`$db`/`$user`/`$pass` zamień fallback default na hostingowe wartości. Mniej elegancko, ale działa. **Nie commituj tego pliku z prawdziwym hasłem do repo.**

**Test:** otwórz `https://<TWOJA_DOMENA>/api/online/engine.php` (POST endpoint odpalony GET-em). Spodziewany wynik:

```json
{"success":false,"data":null,"message":"Tenant ID is required."}
```

Lub coś podobnego z polami `success` i `message`. **Jeśli widzisz `Database connection error`** — env-y się nie zaczytują albo credentialsy są błędne. Wróć do Kroku 3.

---

## ✅ KROK 4 — Łańcuch migracji (przeglądarka)

> **Po co:** baza ma na razie tylko schemat z `001`. Cały kod (Studio, Online, POS, KDS, Driver, Workers, Settings) wymaga tabel/kolumn dodanych w migracjach 004–044. Bez tego co druga akcja zwraca 500.

**Co zrobić:**

Otwórz w przeglądarce:

```
https://<TWOJA_DOMENA>/scripts/apply_migrations_chain.php
```

Skrypt wypluje stronę HTML z listą:

```
AUDIT: pliki SQL w migrations/ zgadzają się z $chain (001 i _archive_* wyłączone).
SliceHub — apply_migrations_chain
Baza: połączenie z db_config.php (USE z plików SQL jest usuwany).
Pominięto 015_normalize_three_drivers.sql (...)
OK: 004_expand_search_aliases.sql
OK: 006_studio_mission_control.sql
OK: 007_pos_engine_columns.sql
...
OK: 044_hr_advances.sql
Zakończono.
```

**Komplikacje praktyczne:**

| Problem | Rozwiązanie |
|---|---|
| Strona ładuje się długo (>30s) i nagle „504 Gateway Timeout" | uti.pl ma limit `max_execution_time`. Skrypt jest **idempotentny** — odśwież stronę, leci dalej od tej migracji która jeszcze nie weszła. Nigdy nie zrobi czegoś dwa razy. |
| `FAIL: 030_scene_harmony_cache.sql — Foreign key constraint is incorrectly formed` | Znany błąd legacy. Jeśli reszta przeszła OK — pomijamy, nie blokuje produkcji. Można naprawić ręcznie w przyszłości (pewnie chodzi o kolejność tabel). |
| `FAIL: 010_driver_action_type.sql — Duplicate column name` | Też znany. Idempotency-bug w starszej migracji. Migracja faktycznie się wykonała wcześniej, błąd kosmetyczny. |
| Wszystko `FAIL` z `Table doesn't exist` | Krok 2 nie został wykonany (nie ma `001`). Wróć. |

**Test:** otwórz `https://<TWOJA_DOMENA>/api/pos/engine.php` — powinien zwrócić JSON o brakującej autoryzacji, **nie** 500 z `Table 'sh_event_outbox' doesn't exist`.

---

## ✅ KROK 5 — `setup_database.php` (siatka bezpieczeństwa)

> **Po co:** historycznie część ALTER-ów nigdy nie weszła do plików SQL — tylko do `setup_database.php`. Skrypt jest idempotentny (dla każdego ALTER-a sprawdza `Duplicate column` → `SKIP (already exists)`). Po tym kroku schemat jest 100% zsynchronizowany z kodem.

```
https://<TWOJA_DOMENA>/scripts/setup_database.php
```

Spodziewany output: lista bloków `Migration 006 / 007 / 008 / ...` z głównie `SKIP (already exists)` (bo Krok 4 już je zaaplikował) i może kilkoma `OK` dla tych, które są wyłącznie w setupie.

---

## ✅ KROK 6 — Seed danych demo (jednokrotnie, opcjonalnie)

> **Po co:** żebyś miał z czym pracować na produkcji. Tworzy 1 tenant „SliceHub Pizzeria Poznań", 8 użytkowników z PIN-ami, 33 pozycje menu, ceny w 3 kanałach, magazyn z PZ-tkami, 12 testowych zamówień.
>
> **Pomiń ten krok jeśli** chcesz produkcję od zera (tworzysz tenant'a + ownera ręcznie przez SQL / panel admin).

```
https://<TWOJA_DOMENA>/scripts/seed_demo_all.php
```

**Konta po seedzie** (logowanie POS — wartości DEMO, NIE używać na produkcji):

| Login | PIN demo | Rola |
|---|---|---|
| admin | (przez login email) | owner |
| manager | 0000 | manager |
| waiter1 | 1111 | waiter |
| waiter2 | 2222 | waiter |
| cook1 | 3333 | cook |
| driver1 | 4444 | driver |
| driver2 | 5555 | driver |
| team1 | 6666 | employee |

> ⚠️ **OBOWIĄZKOWO przed produkcją:**
> - Hasło dla loginu email = `password` (bcrypt hash w seedzie). **Zmień natychmiast** przez panel admin.
> - PIN-y demo (`0000`, `1111`, …) są ZNANE w repo. **Wymuś rotację** w `modules/backoffice/hr/` (akcja „Ustaw PIN kiosku") dla każdego pracownika przed pierwszą zmianą.
> - Te wartości są w repo wyłącznie po to, żeby developer mógł uruchomić smoke test. Na żywej instalacji są podatnością.

---

## ✅ KROK 7 — Smoke test (3 minuty)

| Akcja | Spodziewany wynik |
|---|---|
| `https://<TWOJA_DOMENA>/` | Redirect na hub, widać kafelki modułów |
| `https://<TWOJA_DOMENA>/modules/hub/index.html` | Hub action, brak konsolowych errorów |
| Wejście w „POS" → ekran logowania | Loaduje, widać input PIN |
| Login waiter1 / 1111 (DEMO — po seedzie) | Wpada do POS-a, widać kategorie + dania |
| `https://<TWOJA_DOMENA>/modules/online/index.html?tenant=1` | Storefront — Scena Drzwi, Hours, kanały dostawy |
| Wybierz „Wejdź" → Scena Daniowa | Loaduje listę dań, widać hero zdjęcia |
| `https://<TWOJA_DOMENA>/modules/studio/index.html` | Po logowaniu owner-a → Menu Studio z kategoriami i pozycjami |

Jeśli któryś krok zwraca 500 — **F12 → Network → kliknij failed request → preview** — komunikat błędu wskaże tabelę/kolumnę. Wróć do Kroku 4 i zobacz czy ta migracja przeszła OK.

---

## ✅ KROK 8 — Workery cronowe (event bus, integracje, payroll)

> **Po co:** event-driven architektura (m026+) wymaga procesów które konsumują `sh_event_outbox`. Bez nich:
> - status zamówienia nie propaguje się do integracji (Papu, Dotykačka, Glovo)
> - webhooki nie są dostarczane
> - notyfikacje SMS/email nie wychodzą
> - rozliczenia kadrowe nie naliczają się
>
> **Wpływ na to co zauważyłeś:** „statusy między modułami przesył był dobry" — POS ↔ KDS ↔ Driver dalej działają przez polling DB (NIE wymagają workerów). Workerów potrzebujesz dla **3rd-party integracji + powiadomień klienta**.

**Co zrobić w panelu uti.pl → Cron Jobs:**

| Worker | Częstotliwość | Komenda CLI |
|---|---|---|
| `worker_webhooks.php` | co 1 min | `php /home/USER/public_html/scripts/worker_webhooks.php --batch=20` |
| `worker_integrations.php` | co 1 min | `php /home/USER/public_html/scripts/worker_integrations.php --batch=20` |
| `worker_notifications.php` | co 1 min | `php /home/USER/public_html/scripts/worker_notifications.php --batch=10` |
| `worker_integration_health_ping.php` | co 5 min | `php /home/USER/public_html/scripts/worker_integration_health_ping.php` |
| `worker_driver_fanout.php` | co 30 sek | `php /home/USER/public_html/scripts/worker_driver_fanout.php --batch=30` |
| `worker_payroll_accrual.php` | raz dziennie 03:00 | `php /home/USER/public_html/scripts/worker_payroll_accrual.php` |

**Uwaga:** `/home/USER/public_html/` — zamień `USER` na Twój login uti.pl. Albo użyj relatywnej ścieżki, jeśli panel uti.pl pozwala (`scripts/worker_webhooks.php` z working directory na public_html).

**Jeśli uti.pl nie wspiera CLI cron-a** (niektóre tańsze pakiety) — workerów się nie odpali. Wtedy:
1. Albo **rezygnujesz z webhooków/integracji** na produkcji (statusy między POS/KDS/Driver dalej działają — wystarczy do MVP).
2. Albo **przechodzisz na hosting z CLI** (uti.pl Plus/Premium, OVH, kei.pl, vps).

---

## ⚠️ KROK 9 — `_docs/17_OFFLINE_POS_BACKLOG.md` przypomnienie

Moduł Offline-First POS jest **zamrożony 2026-04-23** w połowie prac. Tabele `sh_pos_terminals`, `sh_pos_server_events`, `sh_pos_op_log`, `sh_pos_sync_cursors` zostaną stworzone przez migracje 039 + 040 w Kroku 4 — to OK, są nieaktywne dopóki POS nie zarejestruje terminala.

**Funkcjonalnie nic Ci to nie psuje** — to tylko struktura w bazie pod przyszły moduł. Service Worker POS-a (`modules/pos/sw.js`) zarejestruje się przy pierwszym wejściu na `/modules/pos/` — to też nie boli.

---

## 🔥 SHORTCUT — minimalna ścieżka „byle działało"

Jeśli musisz ASAP w 5 minut:

1. **`.htaccess`** — Krok 1
2. **DB credentialsy** — albo env-y (Krok 3) albo bezpośrednio w `db_config.php` na hostingu
3. **Migracje** — `https://<TWOJA_DOMENA>/scripts/apply_migrations_chain.php`
4. **Setup** — `https://<TWOJA_DOMENA>/scripts/setup_database.php`
5. **Seed (tylko dla testów)** — `https://<TWOJA_DOMENA>/scripts/seed_demo_all.php`

To wystarczy do działającego MVP bez integracji 3rd-party. Cronów w Kroku 8 dorzucasz później jak będzie potrzeba (Papu, Glovo, SMS).

---

## 🧯 TROUBLESHOOTING — najczęstsze błędy hostingowe

### „Internal Server Error 500" na każdej podstronie

Najczęściej `mod_rewrite` wyłączony albo `.htaccess` ma syntax error. Sprawdź:

1. Wgraj minimalny `.htaccess` z samym `RewriteEngine On` — działa?
2. Jeśli tak — dodawaj reguły linia po linii.
3. Jeśli nie — w panelu uti.pl włącz `mod_rewrite` (zazwyczaj jest domyślnie, ale w starszych pakietach trzeba ręcznie).

### „Database connection error"

1. Otwórz `https://<TWOJA_DOMENA>/scripts/apply_migrations_chain.php` — pokaże `FATAL: brak połączenia PDO (db_config.php)`.
2. Sprawdź env-y (Krok 3) lub fallback w `db_config.php`.
3. Zwykle host bazy na uti.pl to nie `localhost` tylko `mysql.serwer.uti.pl` lub podobne — dokładną nazwę masz w panelu po utworzeniu bazy.

### „Brak tenantId" przy każdym requeście online

Module online wymaga `tenant_id`. Wbij URL z parametrem: `https://<TWOJA_DOMENA>/modules/online/index.html?tenant=1`. Albo edytuj `modules/online/index.html` linia `<meta name="sh-tenant-id" content="X">` z X = ID Twojego tenanta z bazy.

### Studio nie pokazuje produktów po dodaniu

Jeśli to **na hostingu** — pewnie schemat niepełny (Krok 4 nie zakończony). Otwórz F12 → Console → szukaj `PDOException`.

Jeśli to **lokalnie** — to inny bug. Patrz: poprzednia tura tej rozmowy (filtry `is_active`, tenant mismatch). Wymaga osobnej diagnozy z konkretnym scenariuszem.

### Service Worker zostawia stary kod po deployu

Po wgraniu nowych plików JS/CSS → otwórz F12 → Application → Service Workers → Unregister + Application → Storage → Clear site data. Albo zmień `CACHE_VERSION` w `modules/pos/sw.js` / `modules/online/sw.js` (uwaga: tylko gdy NIE jest pod Code Freeze — `online/sw.js` i `pos/sw.js` są częściowo zamrożone, patrz `_docs/17_OFFLINE_POS_BACKLOG.md`). W praktyce: ze świeżym deployem wystarczy `Clear site data` w przeglądarce każdego użytkownika raz.

---

## 📋 FINAL CHECKLIST

- [ ] Krok 1 — `.htaccess` w `public_html/`
- [ ] Krok 2 — baza utworzona + `001` zaimportowane
- [ ] Krok 3 — env-y `SLICEHUB_DB_*` + `JWT_SECRET` ustawione
- [ ] Krok 4 — `apply_migrations_chain.php` przeszło bez kraytycznych FAIL-i
- [ ] Krok 5 — `setup_database.php` przeszło (głównie SKIP)
- [ ] Krok 6 — seed (jeśli chcesz dane testowe)
- [ ] Krok 7 — smoke test 7 URL-i przeszedł
- [ ] Krok 8 — crony skonfigurowane (jeśli pakiet pozwala)

Po wszystkich krokach — `https://<TWOJA_DOMENA>/` powinien dać Hub, hub powinien dać moduły, moduły powinny działać.

> Jeśli któryś krok wywaliło — wracasz tu z informacją „Krok N — błąd X" i robimy snajperski fix.
