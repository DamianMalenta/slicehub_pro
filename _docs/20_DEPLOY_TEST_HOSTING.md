# 20. WDROŻENIE TESTOWE NA HOSTINGU (uti.pl / slicehub.net)

> Krok po kroku jak postawić **pustą, czystą** wersję SliceHub na hostingu PHP/MySQL,
> żeby testować system od zera, dodawać dane ręcznie i wyłapywać błędy.
> **Bez seedów, bez zamówień, bez demo.** Tylko pusta struktura + jedno konto admina.

---

## 0. Założenia

- Hosting: `uti.pl` (Hostido) z PHP 8+ i MariaDB / MySQL.
- Domena: `slicehub.net` (już skierowana na hosting).
- Docelowy URL aplikacji: `https://slicehub.net/`
- Konto testowe: login + hasło wybierasz sam — w tej instrukcji oznaczane jako
  `<TWÓJ_LOGIN>` / `<TWOJE_HASŁO>`. **Nie używaj tych przykładowych wartości
  na produkcji** — bcrypt-uj własne hasło zgodnie z §3.

### 0.1. WAŻNE — alias `/slicehub/` na hostingu

Kod ma w wielu miejscach absolutne ścieżki `"/slicehub/..."` (Service Worker,
manifesty PWA, część wrapperów API). Lokalnie XAMPP to spełnia, bo projekt
fizycznie leży w `htdocs/slicehub/`. Na hostingu pliki są bezpośrednio
w `public_html/`, więc `"/slicehub/api/..."` zwracało 404.

**Rozwiązanie:** wgrać dodatkowy `.htaccess` do **`public_html/`** (root domeny),
który robi alias `/slicehub/X → /X`. Gotowa zawartość:

[`_docs/hostingowy_htaccess_root.txt`](hostingowy_htaccess_root.txt)

Plik nie modyfikuje `public_html/slicehub/.htaccess` (bo go nie ma — pliki
są w roocie). Reguła nie dotyczy `localhost`, więc XAMPP działa bez zmian.

---

## 1. Pliki na hosting

### 1.1. Co wgrać (FTP / Menedżer plików)

Wgraj cały katalog projektu **bezpośrednio do** `public_html/` (root domeny).

```
public_html/
├── .htaccess              ← NOWY plik z _docs/hostingowy_htaccess_root.txt
├── index.html             ← redirect do modules/hub/index.html
├── api/
├── core/
├── database/
├── modules/
├── scripts/
├── _docs/
└── ...
```

Wejście na `https://slicehub.net/` automatycznie wpadnie do Hub.

### 1.2. Co MOŻNA pominąć przy wrzucaniu (nie potrzebne do testu)

- `_KOPALNIA_WIEDZY_LEGACY/` (jeśli jest lokalnie — i tak ma być w `.gitignore`).
- `node_modules/`, `.git/`, `tests/`.
- Lokalne katalogi z dużą biblioteką zdjęć (`uploads/scene_creator/`, prywatne assety) — startujemy bez nich, dorzucisz testowe zdjęcia ręcznie z UI.

### 1.3. Sprawdź, czy `.htaccess` na hostingu działa

`uti.pl` ma Apache. Plik `public_html/.htaccess` musi:

1. Przekazywać nagłówek `Authorization` do PHP (Bearer JWT).
2. Robić alias `/slicehub/X → /X` (dla absolutnych ścieżek w kodzie).

Gotowy plik: [`_docs/hostingowy_htaccess_root.txt`](hostingowy_htaccess_root.txt).
Jeśli alias nie zadziała, niektóre moduły zwrócą 404 z każdego loginu.

---

## 2. Baza danych

### 2.1. Utwórz bazę w panelu uti.pl

W panelu hostingu:

1. **Bazy danych → Utwórz nową bazę** (nazwa wg konwencji hostingu, np. `<konto>_slicehub_test`).
2. Utwórz użytkownika MySQL i nadaj mu pełne prawa do tej bazy.
3. Zapisz: `host`, `nazwa bazy`, `user`, `hasło`.

> Uwaga: `CREATE DATABASE` nie zadziała z phpMyAdmin (`#1044 Access denied`). Bazę MUSI utworzyć panel hostingu.

### 2.2. Konfiguracja połączenia — env-y (zalecane)

`core/db_config.php` od 2026-05 czyta dane z **zmiennych środowiskowych**.
**Nie edytuj pliku** — w panelu hostingu (PHP / Plesk → zmienne env) ustaw:

| Env | Wartość |
|---|---|
| `SLICEHUB_DB_HOST` | host MySQL z panelu (zwykle `localhost`) |
| `SLICEHUB_DB_NAME` | nazwa bazy z panelu |
| `SLICEHUB_DB_USER` | user MySQL z panelu |
| `SLICEHUB_DB_PASS` | hasło DB |
| `JWT_SECRET` | losowy 64-znakowy ciąg (patrz §4) |

Lokalnie (XAMPP) env-y nie są wymagane — fallback to `localhost / slicehub_pro_v2 / root / ''`.

> ⚠️ **Nie commituj** hasła do bazy w `db_config.php` — repo ma trafić do GitHuba,
> a hasła MUSZĄ być po stronie hostingu w env-ach.

### 2.3. Import migracji — kolejność

Wszystkie migracje są w `database/migrations/`. Wykonujemy w **dwóch krokach**.

#### KROK A — schemat bazowy (`001`)

Otwórz phpMyAdmin → wejdź w nowo utworzoną bazę po lewej stronie.

Zaimportuj plik `database/migrations/001_init_slicehub_pro_v2.sql`, ale **najpierw usuń z niego dwie linie**:

```sql
CREATE DATABASE IF NOT EXISTS slicehub_pro_v2 ...
USE slicehub_pro_v2;
```

Hosting nie pozwoli `CREATE DATABASE`. Pozostała część (`DROP TABLE`, `CREATE TABLE`) musi przejść.

#### KROK B — wszystkie pozostałe migracje (004–059)

Najprostszy sposób: uruchom przez przeglądarkę:

```
https://<TWOJA_DOMENA>/scripts/apply_migrations_chain.php
```

Skrypt:
- Audytuje, czy lista migracji w kodzie zgadza się z plikami SQL na dysku (`scripts/_migrations_chain.php` — **53 pliki**).
- Wykonuje po kolei pliki **004 … 059** (pełna lista: `php scripts/apply_migrations_chain.php --dry-run` lub `database/README.md`).
- **Pomija celowo `015`** (destrukcyjne `DELETE/UPDATE` na demo tenancie).

> **Migracje nie wstawiają produktów.** Po krokach A+B tabela `sh_menu_items` jest pusta — POS/Online bez seeda lub ręcznego menu w Studio to **oczekiwany** stan. Patrz §2.4.

> Po zakończeniu skrypt zaproponuje uruchomić jeszcze `scripts/setup_database.php`. Na hostingu po pełnym chain zwykle **nie jest wymagany** do logowania; dokłada idempotentne ALTER-y z PHP (m.in. domknięcie M022).

#### Spodziewane "FAIL"-e w outputcie chain'a (NIESZKODLIWE)

Na hostingu uti.pl skrypt zazwyczaj pokazuje dwa `FAIL` — oba są spodziewane i nie blokują testów:

1. `FAIL: 010_driver_action_type.sql — SQLSTATE[42S21]: Duplicate column name 'driver_action_type' (1060)`
   - Kolumna została już dodana wcześniejszą migracją. Idempotentne — przy ponownym uruchomieniu zawsze pojawi się ten sam komunikat. **Pomijamy.**

2. `FAIL: 037_pos_foundation.sql — SQLSTATE[HY000]: General error: 1901 Function or expression 'table_id' cannot be used in the GENERATED ALWAYS AS clause of '_active_table_guard'`
   - Wersja MariaDB na hostingu nie wspiera tego konkretnego wzorca `GENERATED ALWAYS AS` z odwołaniem do innej kolumny w `STORED`. Dotyczy **tylko** opcjonalnej "siatki bezpieczeństwa" anti-ghosting w POS (max 1 aktywne zamówienie per stolik). Wszystkie pozostałe obiekty z tej migracji (kolumny `table_id`, `waiter_id`, `guest_count`, `split_type`, `qr_session_token`, FK, indeksy) **zostały utworzone**. POS będzie działał normalnie. Jeżeli kiedyś zechcesz dorzucić anti-ghosting, zrobimy to triggerem zamiast generated column.

Wszystko inne powinno mieć `OK`. Migracje `041–044` (HR) oraz `048–051` (warianty, zestawy, `publication_status`) muszą być na liście `OK` — bez HR Kadry zwracają 500; bez 048+ POS nie obsługuje rodzin wariantów.

#### KROK C — dlaczego HR i nowsze migracje też są potrzebne

Bez migracji `041–044` **nie da się dodać pracownika** (`Kadry → Dodaj`). Endpoint `api/backoffice/hr/engine.php` korzysta z tabel `sh_employees`, `sh_employee_rates`, `sh_payroll_ledger`, `sh_advances`.

Migracje `045–059` dodają m.in. profil firmy (NIP), KSeF inbox, geokodowanie, **warianty rozmiarów pizz (048)**, zestawy POS (050), normalizację statusu publikacji menu (051). Krok B wgrywa je automatycznie z `_migrations_chain.php`.

#### KROK D — skąd wziąć produkty (menu)

Czysty deploy z tej instrukcji **świadomie nie seeduje menu**. Po A+B+C masz schemat + ownera, ale **zero pozycji w `sh_menu_items`**.

| Cel | Co zrobić |
|-----|-----------|
| Jedna pizza testowa | Hub → **Studio** → kategoria + danie, status **Live**, cena w macierzy cenowej |
| Demo sandbox (tenant 1) | `https://<TWOJA_DOMENA>/scripts/seed_demo_all.php` — 33 produkty, PIN-y demo |
| Pełne menu Pizzerii Forno (tenant 2) | phpMyAdmin → import `scripts/seed_pizzaforno.sql` (idempotentny) |
| Install Panel | Tworzy tenanta + ownera — **bez** automatycznego menu |

Ceny są w `sh_price_tiers` (`target_type='ITEM'`, `target_sku`), nie w `sh_menu_items`. Seedy wstawiają obie tabele.

Weryfikacja w phpMyAdmin:

```sql
SELECT tenant_id, COUNT(*) AS produkty FROM sh_menu_items WHERE is_deleted = 0 GROUP BY tenant_id;
SELECT tenant_id, COUNT(*) AS ceny FROM sh_price_tiers WHERE target_type = 'ITEM' GROUP BY tenant_id;
```

Szczegóły: [`database/README.md`](../database/README.md), [`SEED_GUIDE.md`](SEED_GUIDE.md).

---

## 3. Jedno konto startowe (tenant + owner)

Czysta baza nie ma żadnej restauracji ani użytkownika. Trzeba wstrzyknąć ręcznie **jeden tenant** i **jednego ownera**.

W phpMyAdmin (zaznaczona Twoja baza po lewej) → zakładka **SQL** → wklej i wykonaj:

```sql
INSERT INTO sh_tenant (name)
VALUES ('SliceHub Test');

SET @tenant_id = LAST_INSERT_ID();

INSERT INTO sh_users (
  tenant_id,
  username,
  password_hash,
  pin_code,
  name,
  first_name,
  last_name,
  role,
  status,
  is_active,
  is_deleted
) VALUES (
  @tenant_id,
  '<TWÓJ_LOGIN>',                 -- np. `admin_t1` — username musi być unikalny
  '<BCRYPT_HASH_TWOJEGO_HASLA>',  -- patrz instrukcja generowania niżej
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

> ⚠️ **Bezpieczeństwo:** użyj WŁASNEGO hasła, **min. 12 znaków**, najlepiej losowych
> (np. z menedżera haseł). Nigdy nie wklejaj tutaj pliku z prawdziwym hashem
> i nie commituj go do publicznego repo.

### 3.1. Generowanie bcrypt hasła

W PowerShell (lokalnie, masz PHP w XAMPP):

```bash
php -r "echo password_hash('TUTAJ_TWOJE_HASLO', PASSWORD_DEFAULT), PHP_EOL;"
```

Skopiuj wyplutý ciąg do `password_hash` w SQL powyżej.

Logowanie:

- URL: `https://<TWOJA_DOMENA>/modules/hub/index.html`
- Login: `<TWÓJ_LOGIN>`
- Hasło: `<TWOJE_HASŁO>`

### 3.2. Późniejsza zmiana hasła ownera

Jeżeli chcesz zmienić hasło, wygeneruj świeży hash:

```bash
php -r "echo password_hash('NOWE_HASLO', PASSWORD_DEFAULT), PHP_EOL;"
```

I podmień w bazie:

```sql
UPDATE sh_users
   SET password_hash = '<NOWY_BCRYPT_HASH>'
 WHERE username = '<TWÓJ_LOGIN>';
```

### 3.2. Dodatkowe konta techniczne (opcjonalnie)

Jeśli chcesz, żeby skrypt automatycznie dorzucił brakujące konta `owner_t1` / `admin_t1`, możesz puścić:

```
php scripts/keep_only_owner_admin_users.php 1
```

(z CLI hostingu, jeśli `uti.pl` daje SSH; alternatywnie dorzucasz wpisy ręcznie tak jak wyżej).

---

## 4. JWT_SECRET (zalecane)

Plik `core/db_config.php`:

```php
$jwtSecret = getenv('JWT_SECRET');
if (!is_string($jwtSecret) || $jwtSecret === '') {
    $jwtSecret = 'dev_localhost_secret_change_in_production';
}
```

Na produkcyjnym/test-hostingu ustaw zmienną środowiskową `JWT_SECRET` (panel hostingu → PHP → zmienne env), albo dorzuć w `.htaccess`:

```
SetEnv JWT_SECRET losowy_dlugi_string_min_48_znakow
```

Inaczej system działa, ale używa znanego sekretu deweloperskiego.

---

## 5. Test smoke — kolejność klikania

Po zalogowaniu jako `<TWÓJ_LOGIN>`:

1. **Hub** — `https://<TWOJA_DOMENA>/modules/hub/index.html` — powinien załadować kafelki.
2. **Kadry** — `https://<TWOJA_DOMENA>/modules/backoffice/hr/index.html`
   - Dodaj pracownika (powinno działać po migracjach 041–044).
   - Ustaw PIN 4-cyfrowy → trafia do `sh_employees.auth_pin_hash`.
   - Ustaw stawkę godzinową → trafia do `sh_employee_rates`.
3. **Studio** — `https://<TWOJA_DOMENA>/modules/studio/index.html`
   - Dodaj kategorię i jedną pizzę (najprostsza, bez modyfikatorów).
4. **POS** — `https://<TWOJA_DOMENA>/modules/pos/index.html`
   - Powinien zobaczyć kafelek dodanej pizzy.
5. **Kiosk** — `https://<TWOJA_DOMENA>/modules/kiosk/index.html`
   - Logowanie 4-cyfrowym PIN-em pracownika.

---

## 6. Co NIE działa na shared hostingu (świadome ograniczenia testowe)

System ma kilka komponentów, które są zaprojektowane pod **VPS / cron / worker’y**. Na shared hostingu większość Hub/POS/Kiosk/KDS/Kadry działa, ale:

| Komponent | Status na shared | Uwagi |
|---|---|---|
| `api/online/sse.php` (Tracker v2 push) | częściowo / niestabilnie | Hostido może ucinać długie połączenia. Front i tak ma fallback na polling. |
| `scripts/worker_webhooks.php` | wymaga cron / SSH | Bez tego eventy z `sh_event_outbox` nie polecą do zewnętrznych webhooków. |
| `scripts/worker_notifications.php` | wymaga cron / SSH | Powiadomienia (SMS/email) nie wyjdą bez workera. |
| `scripts/worker_integrations.php` | wymaga cron / SSH | Integracje POS (Papu, Dotykačka, GastroSoft). |
| `scripts/worker_driver_fanout.php` | wymaga cron / SSH | Auto-przełączanie statusu kierowcy po clock-in/out. |
| Service Worker `modules/pos/sw.js` + `modules/online/sw.js` | OK | Wymaga HTTPS — domena `slicehub.net` z SSL = OK. |
| GPS w `modules/driver_app/js/driver_app.js` | OK | Wymaga HTTPS — OK. |

> Wnioski: do testów funkcjonalnych UI / API / DB wystarczy `uti.pl`.
> Do realnych testów z webhookami / SMS-ami / integracjami POS w przyszłości — VPS z cronem.

---

## 7. Reset bazy testowej

Jeśli chcesz zacząć od zera bez dotykania konfiguracji:

1. W phpMyAdmin: zaznacz wszystkie tabele Twojej bazy → **Drop**.
2. Powtórz **KROK A** (`001`) i **KROK B** (`apply_migrations_chain.php`).
3. Powtórz **sekcję 3** (insert tenant + owner).

Czas: 2-3 minuty.

---

## 8. Skrót — minimalna ścieżka

Dla pamięci:

```text
1. Utwórz bazę w panelu hostingu (nazwa wg konwencji hostingu).
2. Wgraj projekt do public_html/.
3. Ustaw env-y: SLICEHUB_DB_HOST/NAME/USER/PASS + JWT_SECRET (panel PHP).
4. phpMyAdmin → import 001 (bez CREATE DATABASE / USE).
5. Otwórz: https://<TWOJA_DOMENA>/scripts/apply_migrations_chain.php
6. phpMyAdmin → SQL → wstaw INSERT tenant + INSERT user (sekcja 3,
   z WŁASNYM bcrypt hasłem).
7. Zaloguj się: https://<TWOJA_DOMENA>/modules/hub/index.html
   login: <TWÓJ_LOGIN>    hasło: <TWOJE_HASŁO>
8. Klikaj i zgłaszaj błędy.
```

