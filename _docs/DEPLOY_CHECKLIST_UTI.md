# Deploy Checklist — uti.pl (SliceHub Pro)

Po pełnym pre-deploy audicie z 13.05.2026 wszystko jest gotowe do wgrania na hosting. Poniżej procedura krok po kroku.

## Stan main na 13.05.2026

| Element | Status |
|---|---|
| Syntax check wszystkich PHP (110+ plików) | ✅ czysto |
| Syntax check wszystkich JS modułów (ESM-aware) | ✅ czysto |
| Migracje 001 + chain (49 plików) na czystej bazie | ✅ 101 tabel utworzonych |
| Login API (`api/auth/login.php`) | ✅ działa (JWT generowany) |
| Studio Menu API — dodaj pizzę | ✅ pizza widoczna w POS od razu (`publicationStatus=Live`) |
| POS API — `process_order` | ✅ zamówienie zapisane, UUID zwrócony |
| WzEngine / CartEngine / Geocoder / PzEngine / AutoScanEngine | ✅ wszystkie ładują się bez fatal |
| KSeF Parser + Client | ✅ namespace OK |
| Studio Menu Wizard (Bug #2 — nested backticks) | ✅ naprawione (commit `b86d806`) |
| Domyślny `publicationStatus` nowych pozycji | ✅ `Live` (commit `79b7c5a`) |
| PWA SVG ze znakami polskimi | ✅ naprawione (commit `fdf3eb2`) |
| Hardcoded `USE slicehub_pro_v2` w 17 migracjach | ✅ usunięte (commit `870692a`) |

## Procedura wgrania na uti.pl

### Krok 1 — backup obecnej bazy (jeśli masz tam coś ważnego)

W Plesk → Bazy danych → `Eksportuj dump` (na wszelki wypadek).

### Krok 2 — wgranie plików

Najszybsza ścieżka: w Plesk → Git → `Pull updates` z `main`. Wciąga wszystko z GitHub.

Alternatywa (manualna): pobierz repo jako ZIP z GitHub (Code → Download ZIP), rozpakuj lokalnie, wgraj **całą zawartość** przez File Manager Plesk do `httpdocs/` (lub gdzie masz site root).

### Krok 3 — `.htaccess` w `httpdocs/`

Skopiuj zawartość `_docs/hostingowy_htaccess_root.txt` do pliku `.htaccess` w site root. Uzupełnij sekcję `SetEnv`:

```apache
SetEnv SLICEHUB_DB_HOST localhost
SetEnv SLICEHUB_DB_USER twoj_uzytkownik
SetEnv SLICEHUB_DB_PASS twoje_haslo
SetEnv SLICEHUB_DB_NAME nazwa_bazy_w_plesk
SetEnv JWT_SECRET wygeneruj_64_znaki_hex
```

`JWT_SECRET` wygeneruj raz w terminalu lub w przeglądarce:
```
openssl rand -hex 32
```

### Krok 4 — `core/local_secrets.php`

Skopiuj `_docs/hostingowy_local_secrets_template.php` jako `core/local_secrets.php`. Wpisz:

```php
<?php
return [
    'SLICEHUB_SCRIPT_KEY' => 'inny_losowy_klucz_dla_install_panel',
];
```

`SLICEHUB_SCRIPT_KEY` to klucz **tylko do `install_panel.php`** (osobny od JWT). Wygeneruj `openssl rand -hex 32`.

### Krok 5 — pierwsze uruchomienie

1. Wejdź na `https://twoja-domena.pl/scripts/install_panel.php?key=TWOJ_SCRIPT_KEY`
2. **Drop tables** (jeśli baza ma śmieci) → potem **Init schema (001)** → potem **Run chain**
3. Powinno wyświetlić `Chain OK — 48 migracji zaaplikowanych` (1 może mieć WARN dla 037 jeśli MariaDB > 10.6 — to znana niegroźna sytuacja na MySQL działa)
4. Wejdź na `https://twoja-domena.pl/install_owner.php` (jeśli istnieje) lub utwórz pierwszego owner'a przez `install_panel.php` → akcja **Create owner**

### Krok 6 — opcjonalnie: dane demo

Jeśli chcesz mieć od razu menu/składniki demo do testów, otwórz Plesk → phpMyAdmin → Twoja baza → SQL i wklej zawartość `_docs/demo_seed_test.sql`. **PAMIĘTAJ** o ustawieniu `SET @tid := X;` na początku pliku — wpisz `tenant_id` swojego konta (sprawdzisz przez `SELECT id, name FROM sh_tenant`).

### Krok 7 — twardy refresh przeglądarki

Po pierwszym wejściu w przeglądarce wciśnij **Ctrl+Shift+R** (Win) lub **Cmd+Shift+R** (Mac), żeby przeglądarka pobrała świeże JS-y (a nie stare z cache, które blokowały Studio Menu).

## Test smoke po deployu

1. `https://twoja-domena.pl/` → logowanie (Damian / Dammalq123123 lub Twoje hasło) → powinien wpuścić
2. Menu Studio → kafelek pizzy → **Dodaj nowe** → powinno otworzyć editor (przed fix `b86d806` to nie działało)
3. Wypełnij nazwę, kategorię, cenę → **Zapisz** → pizza w drzewie kategorii
4. Otwórz POS w drugiej karcie → menu powinno zawierać tę pizzę od razu (bez czekania ani publikacji — `publicationStatus=Live` domyślnie)
5. Dodaj do koszyka → **Złóż zamówienie** → toast `Zamówienie #X zaakceptowane`

Jeśli któryś krok zawodzi — DevTools (F12) → Console + Network. Wszystkie 4xx/5xx response'y z `api/*` powinny mieć JSON-em `{success:false, message:"..."}` więc od razu widać przyczynę.
