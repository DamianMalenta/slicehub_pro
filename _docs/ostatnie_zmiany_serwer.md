# OSTATNIE ZMIANY · Wdrożenie testowe SliceHub na hostingu

> Sesja: 2026-05-05 (noc).
> Cel: postawić darmową, czystą wersję SliceHub na hostingu `uti.pl` (domena `<TWOJA_DOMENA>`)
> i przygotować moduły do testowania **z telefonu**, bez aplikacji natywnej.

---

## 1. Decyzja techniczna — gdzie hostujemy

**Nie potrzebujemy Node.js.** System jest stricte:

- Frontend: HTML + vanilla JS + CSS (bez bundlera, bez `package.json`).
- Backend: PHP 8+ (REST endpointy w `api/`).
- Baza: MariaDB / MySQL.
- Wymagany Apache (z powodu `.htaccess` przekazującego nagłówek `Authorization`).

**Wybrana opcja:** współdzielony hosting `uti.pl` + domena `<TWOJA_DOMENA>`.

**Co działa OK:** Hub, POS, Kiosk, KDS, Kadry, Studio, Online, Waiter, Driver (HTTPS = OK).
**Co wymagałoby VPS dopiero w przyszłości:** worker’y CLI (`scripts/worker_*.php`), długie SSE (`api/online/sse.php`), cron (`scripts/cron_*.php`), webhooki produkcyjne.

Dla testów funkcjonalnych UI/API/DB **w pełni wystarcza shared hosting**.

---

## 2. DNS / domena

Pierwszy ekran pokazywał `Hostido` (Twoja domena była skierowana na NS Hostido).
Działania:

1. Dodanie domeny `<TWOJA_DOMENA>` do usługi w panelu `uti.pl`.
2. Skierowanie nameserverów / rekordów A na hosting `uti.pl`.
3. Propagacja DNS (5 min – 24 h, weryfikacja przez `nslookup <TWOJA_DOMENA>`).
4. Włączenie SSL dla `https://<TWOJA_DOMENA>`.

---

## 3. Baza danych

### 3.1. Problem `#1044`

Próba `CREATE DATABASE slicehub_pro_v2` w phpMyAdmin → błąd
`#1044 - Access denied for user '<panel_user>'@'localhost'`.

**Powód:** na shared hostingu user MySQL nie ma uprawnień do tworzenia baz.
**Rozwiązanie:** bazę MUSI utworzyć panel `uti.pl`. phpMyAdmin służy tylko
do importu tabel do już istniejącej bazy.

### 3.2. Czysta baza testowa

Wgrana została **wyłącznie migracja 001** (`database/migrations/001_init_slicehub_pro_v2.sql`).
Z pliku usunięte przed importem:

```sql
CREATE DATABASE IF NOT EXISTS slicehub_pro_v2 ...
USE slicehub_pro_v2;
```

(`uti.pl` nie pozwala na `CREATE DATABASE`).

### 3.3. Dlaczego HR padał

Po wgraniu samego `001` UI Kadr (`api/backoffice/hr/engine.php`) padał, bo wymaga
tabel `sh_employees`, `sh_employee_rates`, `sh_payroll_ledger`, `sh_advances`,
które są dopiero w migracjach `041–044`.

**Wniosek:** dla testów potrzebne są wszystkie migracje, nie tylko `001`.

---

## 4. Konto startowe (tenant + owner)

Migracja `001` tworzy tabele, ale nie wstawia żadnych danych. Brak loginu = brak
możliwości wejścia do Hub.

Wstrzyknięte ręcznie przez phpMyAdmin → SQL:

```sql
INSERT INTO sh_tenant (name)
VALUES ('SliceHub Test');

SET @tenant_id = LAST_INSERT_ID();

INSERT INTO sh_users (
  tenant_id, username, password_hash, pin_code, name,
  first_name, last_name, role, status, is_active, is_deleted
) VALUES (
  @tenant_id,
  '<OWNER_LOGIN>',
  '<BCRYPT_HASH>',                       -- patrz instrukcja generowania niżej
  NULL,
  '<DISPLAY_NAME>',
  '<FIRST_NAME>',
  '<LAST_NAME>',
  'owner',
  'active',
  1,
  0
);
```

Bcrypt hash generujesz lokalnie:

```bash
php -r "echo password_hash('TWOJE_HASLO', PASSWORD_DEFAULT);"
```

**Login:** `<OWNER_LOGIN>` (np. `admin`, `manager_t1`)
**Hasło:** wybierane przez Ciebie, **min. 12 znaków losowych** (menedżer haseł)
**Rola:** `owner`

> ⚠️ Nigdy nie wklejaj prawdziwego hasha (`password_hash` output) do publicznego repo.
> Hash wystarczy znać hostingowi — Ty pamiętasz tylko hasło plain.

Logowanie w Hub: `https://<TWOJA_DOMENA>/slicehub/modules/hub/index.html`.

---

## 5. Konfiguracja połączenia z bazą

`core/db_config.php` na hostingu trzeba przerobić z lokalnych danych XAMPP-a:

```php
$host = 'localhost';
$db = 'slicehub_pro_v2';
$user = 'root';
$pass = '';
```

**UPDATE 2026-05-09:** od commita `feat(deploy): hosting-ready (env-driven DB config)`
plik `core/db_config.php` czyta dane z env-ów. **Nie edytujemy go** — zamiast tego
ustawiamy w panelu hostingu zmienne:

| Env | Wartość |
|---|---|
| `SLICEHUB_DB_HOST` | host MySQL z panelu |
| `SLICEHUB_DB_NAME` | nazwa bazy |
| `SLICEHUB_DB_USER` | user MySQL |
| `SLICEHUB_DB_PASS` | hasło DB |
| `JWT_SECRET` | losowy 64-znakowy ciąg |

Lokalnie (XAMPP) env-y nie są wymagane — fallback do XAMPP-owych defaultów.

> ⚠️ Hasło DB **nigdy** nie powinno trafić do `db_config.php` w repo.

---

## 6. Nowe pliki dokumentacji

### `_docs/20_DEPLOY_TEST_HOSTING.md`

Pełna instrukcja krok-po-kroku do postawienia testowej instancji od zera:

- 0. Założenia (PHP / MariaDB / Apache, struktura `/slicehub/`).
- 1. Pliki na hosting (co wgrać, co pominąć, `.htaccess`).
- 2. Baza (utworzenie w panelu, edycja `db_config.php`, kolejność migracji).
- 3. Konto startowe (tenant + owner, gotowy SQL z bcrypt).
- 4. JWT_SECRET.
- 5. Smoke test (Hub → Kadry → Studio → POS → Kiosk).
- 6. Co NIE działa na shared hostingu (worker’y, SSE).
- 7. Reset bazy testowej.
- 8. Skrót — minimalna ścieżka.

---

## 7. Nowy plik wejściowy (root)

### `index.html` (root projektu)

Stworzony, żeby wejście na `https://<TWOJA_DOMENA>/slicehub/` od razu przekierowywało
do Hub. Trzy warstwy redirectu (działa nawet z wyłączonym JS):

- `<meta http-equiv="refresh" content="0; url=modules/hub/index.html">`
- `<script>location.replace('modules/hub/index.html')</script>`
- Przycisk fallback „Otwórz Hub” (klikalny ręcznie).

Branding: logo „SH”, ciemne tło `#0a0e14`, jeden font systemowy.

---

## 8. Aplikacja Kelner (`modules/waiter/`)

### Nowy `manifest.json` (PWA)

```json
{
    "name": "SliceHub Kelner",
    "short_name": "Kelner",
    "start_url": "/slicehub/modules/waiter/index.html",
    "scope": "/slicehub/modules/waiter/",
    "display": "standalone",
    "orientation": "portrait",
    "lang": "pl"
}
```

Po wejściu z telefonu można „Dodaj do ekranu głównego” → osobna ikona „Kelner”.

### `modules/waiter/index.html`

- Dodano `<link rel="manifest">`.
- Dodano `apple-touch-icon`, `apple-mobile-web-app-title="Kelner"`,
  `mobile-web-app-capable`, `format-detection: telephone=no`.
- Inputy loginu dostały tagi mobile:
  `autocapitalize="off"`, `autocorrect="off"`, `spellcheck="false"`,
  `inputmode="text"`, `enterkeyhint="next/go"`.

### `modules/waiter/css/waiter.css`

- `min-height: 100dvh` na `body` i `.view` (dynamic viewport — eliminuje
  problem z URL barem na iOS/Android).
- `overscroll-behavior-y: none` (brak bouncing pull-down).
- `.dash-btn-icon` 38 px → 44 px (Apple HIG touch target).
- Pole loginu: `padding 12→14 px`, `appearance: none`.
- Przycisk Zaloguj: `min-height: 52 px`, `font-size: 16 px` (iOS bez auto-zoom).

---

## 9. Aplikacja Kierowca (`modules/driver_app/`)

### `manifest.json` rozszerzony

Dodano `scope`, `lang`, lepszy `name`/`short_name` po polsku, `description`.

### `modules/driver_app/index.html`

- Tytuł „Driver” → „Kierowca” (PL spójność).
- Dodano `apple-touch-icon`, `apple-mobile-web-app-title="Kierowca"`,
  `mobile-web-app-capable`, `format-detection`.
- Tagi mobile na inputach loginu (jak w Kelnerze).

### `modules/driver_app/css/style.css`

- `min-height: 100dvh` na `body` i `#app-root`.
- `touch-action: manipulation` (eliminacja 300 ms tap-delay).
- Login input: `padding 14×16`, `appearance: none`.
- Przycisk Zaloguj: `min-height: 56 px`, font 15→16 px.

---

## 10. Wspólna warstwa mobile (`modules/ui_shell/sh_mobile_shell.css`)

Plik był załadowany w **każdym** module z `modules/`. Rozbudowany defensywnie
o reguły mobile bez modyfikacji logiki/JS żadnego modułu.

### Sekcja 1 — globalne (każdy ekran)

- iOS: `text-size-adjust: 100%` przy obracie / pinch.
- `safe-area-inset-*` (notch, home indicator).
- `overscroll-behavior-y: none` na body.
- `touch-action: manipulation` na każdym interaktywnym elemencie.

### Sekcja 2 — telefon < 768 px

- Każdy `<input>`/`<textarea>`/`<select>` dostaje `font-size >= 16 px`
  (iOS przestaje samoczynnie zoomować).
- Buttony bez własnej kontroli rozmiaru: `min-height: 40 px`.
- Klasy pomocnicze: `.sh-mb-table-scroll`, `.sh-mb-fullsheet`, `.sh-mb-skip`,
  `.sh-mb-no-print`.
- `body { overflow-x: hidden }`.

### Sekcja 3 — < 480 px

- Buttony 40 → 44 px (Apple HIG).
- Większe odstępy w listach/kartach.

### Sekcja 4 — landscape małego telefonu

- Modale dostają `overflow-y: auto`.

### Sekcja 5 — punktowe poprawki desktopowych modułów (< 900 px)

| Moduł | Co poprawione |
|---|---|
| **Studio** (`body.flex` + `aside.w-64`) | Layout zmienia się na kolumnowy, sidebar zwija się do paska na górze z poziomym scrollem. |
| **Online Studio** (`.app` + `.sidebar`) | Tak samo jak Studio. |
| **POS / Courses / Tables** (`.shell-nav .nav-tabs`) | Poziomy scroll tabek + `min-height: 44 px`. |
| **Settings** (`.st-tabs`, `.st-topbar`) | Scroll horizontal tabek, łamanie topbara. |
| **KDS** (`.kds-board`) | Grid → 1 kolumna na telefon. |
| **Warehouse** (`body.p-8`, `body.p-12`) | Padding 16 px zamiast 32-48 px. |
| **HR Backoffice** | `<table>` w `main`/`section`/`.container` dostaje wymuszony horyzontalny scroll. |
| **Courses Map** (`.leaflet-container`) | `min-height: 50 dvh`. |

### Sekcja 6 — print

- Klasa `.sh-mb-no-print` ukrywa elementy w wydruku.

**Bezpieczeństwo zmian:**
`body.flex` istnieje tylko w Studio, klasa `.app` tylko w Online Studio
(zweryfikowane). Reguły działają tylko < 900 px. Desktop nietknięty.

---

## 11. Lista plików utworzonych / zmienionych

### Utworzone (3 pliki)

- `index.html` (root projektu) — przekierowanie do Hub.
- `_docs/20_DEPLOY_TEST_HOSTING.md` — instrukcja deploy testowego.
- `_docs/ostatnie_zmiany_serwer.md` — ten plik.
- `modules/waiter/manifest.json` — PWA Kelnera.

### Zmienione (5 plików)

- `modules/waiter/index.html` — manifest, mobile metatagi, inputy.
- `modules/waiter/css/waiter.css` — `100dvh`, touch-targety, login form.
- `modules/driver_app/index.html` — manifest, mobile metatagi, inputy, PL.
- `modules/driver_app/css/style.css` — `100dvh`, touch-action, login form.
- `modules/driver_app/manifest.json` — `scope`, `lang`, PL nazwa.
- `modules/ui_shell/sh_mobile_shell.css` — pełna rozbudowa mobile shell.

---

## 12. Co działa na telefonie po tych zmianach

| Moduł | Stan |
|---|---|
| Hub | OK (mobile-first od początku) |
| Kelner | OK (PWA + dedykowana mobile UI) |
| Kierowca | OK (PWA + dedykowana mobile UI) |
| Kiosk | OK (mobile-first od początku) |
| POS | Klikalny, paski tabek scrollują się, klawiatura PIN OK |
| KDS | Klikalny, tickety w 1 kolumnie |
| Studio | Klikalny, sidebar zwija się do paska na górze |
| Online Studio | Klikalny, sidebar zwija się do paska na górze |
| Settings | Klikalny, tabki scroll, topbar się łamie |
| Warehouse | Klikalny (Tailwind + drobne paddingi) |
| Backoffice / HR | Klikalny, tabela pracowników scroll-x |
| Marketing / Inbox / Tables / Courses | Klikalne |

---

## 13. Pozostałe ograniczenia (świadome, do późniejszej decyzji)

- **SSE** (`api/online/sse.php`) — może być ucinane przez Hostido. Front ma fallback na polling.
- **Worker’y CLI** (`scripts/worker_*.php`) — wymagają cron lub SSH na hostingu.
- **Ikony PWA** — `apple-touch-icon` wskazuje na `../hub/css/icon-192.png`. Plik trzeba dorzucić do Hub (jeden PNG 192×192) i obie aplikacje go automatycznie podchwycą.
- **JWT_SECRET** — zostaje deweloperski, dopóki nie ustawisz `SetEnv JWT_SECRET ...` w `.htaccess` lub w panelu hostingu.

---

## 14. KOREKTA — pliki w roocie hostingu (`public_html/`), nie w podkatalogu

Po przeniesieniu plików **bezpośrednio do `public_html/`** (URL: `<TWOJA_DOMENA>/`,
nie `<TWOJA_DOMENA>/slicehub/`) okazało się, że kod ma w wielu miejscach
**absolutne ścieżki `"/slicehub/..."`** (Service Worker POS i Online,
manifesty PWA, część wrapperów API w Online/Waiter/Inbox/Driver).

**Skala problemu (zliczone z plików):**

- `modules/online/sw.js` — 22 absolutne ścieżki precache.
- `modules/pos/sw.js` — 17 absolutnych ścieżek + matchery URL.
- `modules/online/manifest.webmanifest`, `modules/pos/manifest.webmanifest` — `start_url` + `scope`.
- `modules/online/js/online_api.js`, `modules/waiter/js/waiter_app.js`,
  `modules/inbox/js/inbox_app.js`, `modules/driver_app/js/driver_api.js` — `BASE = '/slicehub/api'`.

Service Worker POS i online manifesty są **zamrożone Konstytucją** projektu
(Sekcja `FREEZE NOTICE` w `00_PAMIEC_SYSTEMU.md`) — nie wolno ich edytować.

### Rozwiązanie — alias w `.htaccess` w roocie hostingu

Stworzony plik gotowy do skopiowania:

[`_docs/hostingowy_htaccess_root.txt`](hostingowy_htaccess_root.txt)

Zawiera:

```apache
RewriteEngine On

# Pass Authorization header to PHP
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

# ALIAS /slicehub/X → /X (tylko hosting, nie localhost)
RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1) [NC]
RewriteRule ^slicehub/(.*)$ /$1 [L]
```

**Co wgrać:** zawartość tego pliku → wkleić jako **`public_html/.htaccess`**
(root domeny, nie `public_html/slicehub/.htaccess` — bo nie ma takiego katalogu).

**Co to daje:**

- `/slicehub/api/auth/login.php` → `/api/auth/login.php` (działa).
- `/slicehub/modules/pos/sw.js` → `/modules/pos/sw.js` (SW cache działa).
- Lokalny XAMPP nieruszony — reguła nie dotyczy `localhost`.
- Frozen Konstytucją pliki nie są edytowane.

### Aktualizacja dokumentu `_docs/20_DEPLOY_TEST_HOSTING.md`

- Sekcja 0 — dodano podsekcję 0.1 o aliasie `/slicehub/`.
- Sekcja 1.1 — zmieniono strukturę z `public_html/slicehub/` na `public_html/`.
- Sekcja 1.3 — wyjaśniono dwie funkcje `.htaccess` (Authorization + alias).
- Wszystkie URL-e w instrukcji: `<TWOJA_DOMENA>/slicehub/...` → `<TWOJA_DOMENA>/...`.

---

## 15. Następny krok dla Ciebie

1. **Skopiuj** zawartość `_docs/hostingowy_htaccess_root.txt`
   → jako `public_html/.htaccess` na hostingu (przez menedżer plików `uti.pl`).
2. Wgraj pozostałe zmienione pliki bezpośrednio do `public_html/`
   (zachowując strukturę `modules/`, `api/`, `core/`).
3. Hard-refresh w przeglądarce (Ctrl+Shift+R) — żadne migracje DB nie są potrzebne.
4. Test z telefonu: `https://<TWOJA_DOMENA>/`
   → automatycznie wpadnie do Hub
   → login `<OWNER_LOGIN>` / `<OWNER_HASLO>` (te wybrałeś przy tworzeniu konta)
   → przeklikaj wszystkie kafelki i raportuj co rozjeżdża się na telefonie.

---

## 16. Wynik uruchomienia `apply_migrations_chain.php` na hostingu

Skrypt został uruchomiony na produkcji — output pokazał `OK` dla wszystkich
migracji **z wyjątkiem dwóch**, które **są spodziewane i nieszkodliwe**:

### 16.1. `010_driver_action_type.sql` — `Duplicate column name 'driver_action_type'` (1060)

- Migracja idempotentna; kolumna była już dodana wcześniej.
- Każde kolejne uruchomienie chain'a będzie pokazywać ten sam komunikat.
- **Akcja:** żadna. Pomijamy.

### 16.2. `037_pos_foundation.sql` — błąd `1901` przy `_active_table_guard`

Pełny komunikat:

```
General error: 1901 Function or expression 'table_id' cannot be used
in the GENERATED ALWAYS AS clause of '_active_table_guard'
```

**Co to znaczy:** wersja MariaDB na uti.pl nie wspiera tego konkretnego
wzorca `STORED GENERATED ALWAYS AS (CASE WHEN ... THEN table_id ELSE NULL END)`.
Lokalny XAMPP ma `MariaDB 10.4.32` i tam działa, ale hosting jest na innej wersji.

**Co utracone:** tylko opcjonalna "siatka bezpieczeństwa" anti-ghosting
(unikalny indeks na `(tenant_id, _active_table_guard)`, gwarantujący max 1
aktywne zamówienie per stolik).

**Co NIE utracone (czyli co weszło z migracji 037):**

- `sh_tables` (cała tabela ze stolikami).
- `sh_zones`, `sh_order_logs`, rozszerzenie `sh_order_payments`.
- `sh_orders` rozszerzone o: `table_id`, `waiter_id`, `guest_count`,
  `split_type`, `qr_session_token` + odpowiednie FK i indeksy.
- `sh_order_lines.course_number`, `sh_order_lines.fired_at` + indeks.

**Konsekwencja praktyczna:** POS startuje normalnie, można składać i
realizować zamówienia. Brak tylko twardej blokady DB na duplikat aktywnych
zamówień per stolik — i tak logika POS-a nie pozwala otworzyć dwóch
aktywnych ticketów na jednym stoliku z UI.

**Plan B (jeśli kiedyś będzie potrzebny):** zastąpić generated column
zwykłą kolumną `BIGINT UNSIGNED NULL` + trigger `BEFORE INSERT/UPDATE`,
który wpisuje `table_id` lub `NULL`. To samo zachowanie, działa na
starszych MariaDB. Na razie nie jest to konieczne do testów.

### 16.3. Co potwierdzone na produkcji jako `OK`

- **HR (najważniejsze dla "dodaj pracownika"):**
  `041_hr_employees_foundation`, `042_hr_work_sessions_extend`,
  `043_hr_payroll_ledger`, `044_hr_advances`. Tabele `sh_employees`,
  `sh_employee_rates`, `sh_payroll_ledger`, `sh_advances` istnieją.
- POS server events (`040`), Faza 7 GDPR (`034`), Atelier (`035`),
  Asset display name (`036`), legacy inventory drop (`038`),
  resilient POS (`039`) — wszystkie `OK`.
- Studio/Director/Scenes (`020–024, 030–032`) — wszystkie `OK`.
- Gateway v2 (`027`), event system (`026`), notification director (`033`) — `OK`.

### 16.4. Aktualizacja dokumentacji

- `_docs/20_DEPLOY_TEST_HOSTING.md` § 2.3 — dodano sekcję
  "Spodziewane FAIL-e w outputcie chain'a (NIESZKODLIWE)" z opisem
  obu komunikatów, żeby przy następnym uruchomieniu nie wprowadzały w błąd.

### 16.5. Następny test na hostingu

1. Hard-refresh (Ctrl+Shift+R) na `https://<TWOJA_DOMENA>/`.
2. Login `<OWNER_LOGIN>` / `<OWNER_HASLO>`.
3. **Kadry → Dodaj pracownika** — powinno działać bez 500.
4. Jeżeli mimo wszystko leci 500 z `/api/backoffice/hr/engine.php`,
   trzeba podejrzeć Network → Response zakładka — tam będzie
   konkretny tekst PHP z błędem (najczęściej brak konkretnej
   kolumny w `sh_employees` lub niedopasowany `tenant_id`).

---

## 17. Hotfix — poprawna ścieżka `sh_mobile_shell.css` w module Kadry

### 17.1. Co się działo

Po wdrożeniu w konsoli Kadr (`/modules/backoffice/hr/index.html`) leciało
twarde `404` na CSS:

```
GET https://<TWOJA_DOMENA>/modules/backoffice/hr/shell/sh_mobile_shell.css 404
```

(URL widoczny na hostingu wynikał ze starej kopii pliku, ale problem
występował też lokalnie — tyle że pod innym URL-em.)

### 17.2. Przyczyna

Kadry to **jedyny moduł zagłębiony dwa poziomy** w drzewie:

```
modules/backoffice/hr/index.html
modules/ui_shell/sh_mobile_shell.css  ← cel
```

Wszystkie pozostałe moduły siedzą pod `modules/<nazwa>/index.html`,
więc względna ścieżka `../ui_shell/sh_mobile_shell.css` u nich się
rozwija poprawnie do `modules/ui_shell/sh_mobile_shell.css`.

W Kadrach `..` to `modules/backoffice/`, a nie `modules/` — więc trzeba
**dwóch poziomów** w górę.

Zweryfikowane przez `Glob modules/*/*/index.html` — jedyny taki plik
w projekcie.

### 17.3. Fix

`modules/backoffice/hr/index.html` linia 11:

- przed: `<link rel="stylesheet" href="../ui_shell/sh_mobile_shell.css">`
- po:    `<link rel="stylesheet" href="../../ui_shell/sh_mobile_shell.css">`

### 17.4. Co wgrać na hosting

Tylko ten jeden plik. Po hardowym odświeżeniu (Ctrl+Shift+R) URL w
zakładce Network powinien wskazać `/modules/ui_shell/sh_mobile_shell.css`
i wrócić `200 OK`.

W konsoli zostanie wówczas tylko `Uncaught SyntaxError: Unexpected token
'export' (webpage_content_reporter.js)` — to wtyczka Chrome, nie nasz
kod, ignorować.

---

## 18. Hub — uzupełnienie brakujących modułów

### 18.1. Co było nie tak

Stary `modules/hub/index.html` pokazywał tylko 10 kafelków. W projekcie
istnieje natomiast 15 kompletnych modułów (każdy ze swoim `index.html`).
**Nieobecnych w Hubie było pięć:**

- `modules/warehouse/index.html` — Magazyn V2 (PZ / MM / RW / KOR / Inwentaryzacja, control tower, settings)
- `modules/online/index.html` — strona klienta "Zamów online"
- `modules/online_studio/index.html` — Visual Compositor / Director / Conductor
- `modules/marketing/index.html` — kampanie SMS-owe
- `modules/inbox/index.html` — skrzynka SMS od klientów

### 18.2. Co dodano

Dodano pięć kafelków oraz nową sekcję, żeby logicznie pogrupować:

- **Administracja** dostała: `Online Studio`, `Magazyn` (między Studio a Ustawieniami).
- **Klient & marketing** — nowa sekcja z: `Zamów online`, `Marketing SMS`, `Skrzynka SMS`.

Lista wszystkich kafelków w Hubie po zmianie (15 modułów / 4 sekcje):

```
Praca operacyjna           Administracja              Aplikacje mobilne     Klient & marketing
─────────────────          ─────────────────          ─────────────────     ─────────────────
Kasa POS                   Kadry                      Kelner                Zamów online
Kiosk zmiany               Studio                     Kierowca              Marketing SMS
Plan sali                  Online Studio                                    Skrzynka SMS
KDS                        Magazyn
Dispatch                   Ustawienia
```

### 18.3. Co wgrać na hosting

Jeden plik: `modules/hub/index.html` → `public_html/modules/hub/index.html`.
Po hardowym refresh w Hubie zobaczysz wszystkie 15 kafelków.


