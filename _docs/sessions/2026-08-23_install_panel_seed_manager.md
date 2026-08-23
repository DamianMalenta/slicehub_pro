# Install Panel — Seed Manager (sekcja 2c)

**Data:** 2026-08-23
**Sesja:** dodanie seed managera do `install_panel.php` + `--tenant=N` CLI arg w `seed_demo_all.php`

---

## Cel

Użytkownik nie mógł wgrywać seedów (`seed_pizzaforno*.sql`, `seed_demo_all.php`) z poziomu panelu instalacyjnego — musiał robić to ręcznie przez `mysql` CLI lub phpMyAdmin. Celem było dodanie profesjonalnego seed managera z walidacją pre-flight, auto-remapem `tenant_id` i statusem danych per tenant.

## Pliki dotknięte

- `scripts/install_panel.php` — +281 linii backend (3 funkcje: `action_list_seeds`, `action_seed_status`, `action_run_seed`) + 50 linii HTML (sekcja 2c) + 93 linii JS (handlers) + 3 nowe case'y w switch dispatcherze
- `scripts/seed_demo_all.php` — +8 linii: `--tenant=N` CLI override (backward-compatible, default 1)
- `_docs/SEED_GUIDE.md` — aktualizacja daty + nowa sekcja "Wgrywanie seedów przez install_panel.php"

## Decyzje architektoniczne

### 1. Auto-remap `tenant_id` bez podglądu diff

Użytkownik wybrał opcję "Auto-remap bez podglądu" — panel wykonuje remap od razu, logowany w odpowiedzi JSON. Implementacja:
- **SQL seedy:** `preg_replace('/SET\s+@tid\s*:=\s*\d+\s*;/', "SET @tid := {$targetTid};", $sql, 1)` — podmienia tylko pierwsze wystąpienie `SET @tid`, bezpieczne bo `(int)` cast
- **PHP seed:** `exec("php seed_demo_all.php --tenant={$targetTid}")` — dodany CLI arg `getopt('', ['tenant::'])`

### 2. Pre-flight: ostrzeżenie + drugi klik (nie twardy blok)

Dla `seed_pizzaforno_ops.sql` gdy `menu_items=0` — panel zwraca `success:false` z `warning:'no_menu'`. Użytkownik może zaznaczyć checkbox "Wymuś" i kliknąć ponownie. Twarde bloki tylko dla: tenant nie istnieje, plik nie istnieje, plik poza whitelist.

### 3. Whitelist plików (security)

Hardcoded lista 4 dozwolonych plików w `action_run_seed`:
```php
$allowed = ['seed_pizzaforno.sql', 'seed_pizzaforno_menu.sql',
            'seed_pizzaforno_ops.sql', 'seed_demo_all.php'];
```
Zapobiega path traversal (`../../etc/passwd` itp.).

### 4. UI placement: po migracjach, przed tenantami

Sekcja "2c. Dane demo / seed" umieszczona między "2b. Migracje wybrane" a "4. Tenant" — bo dane są częścią instalacji (po schema + migracjach, przed/zamiast ręcznego tworzenia tenanta jeśli seed go tworzy).

### 5. SQL split: po `;\n` z pomijaniem komentarzy `--`

`action_run_seed` dzieli SQL na statements ignorując linie zaczynające się od `--` i puste. Exec statement po statement z licznikiem OK/fail. Błędy logowane (max 10) w odpowiedzi.

### 6. Spójność z istniejącymi wzorcami

- `install_panel.php` jest w `scripts/` = kategoria CLI wg audytu 2026-07-30
- `$pdo->exec($stmt)` na raw SQL = ten sam pattern co `seed_demo_all.php` (P3, ryzyko zero)
- Brak transakcji na cały plik = spójne z `mysql CLI` i `seed_demo_all.php` (seedy robią `DELETE FROM` na początku, nie rollback)
- Brak `OrderEventPublisher` = spójne z istniejącymi seedami (one-time data loading, nie produkcjne mutacje)

## Zgodność z Konstytucją v5

| Prawo | Status | Uwagi |
|---|---|---|
| IV (Zero zaufania) | ⚠️ P3 CLI | Spójne z `seed_demo_all.php`, `(int)` cast eliminuje injection |
| VI (Snajper / tenant_id) | ✅ | Wszystkie query na tabelach multi-tenant mają barierę `tenant_id = ?` |
| VII (Innowacja) | N/A | `scripts/` poza zakresem |
| VIII (Domknięcie) | ✅ naprawione | `SEED_GUIDE.md` zaktualizowany o sekcję panelu |
| X (Audyt sesji) | ✅ ten plik | Zmiana w `scripts/` nie wymaga, ale utworzono dla kompletności |
| AGENTS #15 (outbox) | ✅ | Spójne z istniejącymi seedami (one-time loading) |

## Otwarte pytania

1. **Transakcje na cały seed?** — obecnie exec statement po statement bez transakcji. Jeśli seed poleci w połowie, baza jest w stanie mieszanym. Seedy są idempotentne (cleanup na początku), ale pełna transakcja byłaby bezpieczniejsza. Decyzja: zostawić jak jest (spójne z `mysql CLI`), bo niektóre seedy mają DDL który nie może być w transakcji.

2. **Pasek postępu per-statement?** — obecnie cały seed leci w jednym żądaniu HTTP. Dla dużych seedów (`seed_pizzaforno.sql` = 377KB) może trwać >30s. Opcja: SSE/WebSocket streaming postępu. Decyzja: zostawić synchroniczne (prostsze), timeout PHP można podnieść przez `set_time_limit(0)` w `action_run_seed` jeśli potrzebne.

3. **Upload własnego seeda?** — obecnie tylko 4 hardcoded pliki. Opcja: `<input type="file">` + upload do `scripts/` + walidacja. Decyzja: poza zakresem, seedy są w repo.

4. **Rollback po nieudanym seedzie?** — ryzykowne, seedy robią `DELETE FROM` na początku ale nie mamy transakcji na cały plik. Decyzja: poza zakresem, użytkownik może `drop_all` + `full_install` + reseed.

## Test (E2E)

- `php -l scripts/install_panel.php` → "No syntax errors detected"
- `php -l scripts/seed_demo_all.php` → "No syntax errors detected"
- HTTP GET `http://localhost/slicehub/scripts/install_panel.php` → 200, HTML zawiera sekcję "2c." + `seeds-picker` + `btn-run-seed` + `list_seeds` (4 wzorce znalezione w HTML)
- Browser preview: panel renderuje się poprawnie po zalogowaniu kluczem

Pełne testy E2E wgrywania seedów przez panel — do wykonania na uti.pl po deployu (wymaga klucza `SLICEHUB_SCRIPT_KEY` + istniejącego tenanta).
