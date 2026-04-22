# Instrukcja: naprawa środowiska, czyste konta i przykładowe zamówienia

Założenia: **XAMPP**, baza **MySQL/MariaDB**, projekt w `htdocs/slicehub`, domyślny tenant **`tenant_id = 1`**. Ścieżki URL zakładają `http://localhost/slicehub/` — dopasuj je do swojej konfiguracji.

---

## Audyt zgodności migracji (czy coś jest pominięte)

### Pliki w `database/migrations/`

| Grupa | Pliki |
|--------|--------|
| **Baza startowa (osobno)** | `001_init_slicehub_pro_v2.sql` — **nie** jest w `apply_migrations_chain.php` (import ręczny przed łańcuchem). |
| **Archiwum (celowo poza łańcuchem)** | `_archive_014_ingredient_assets.sql`, `_archive_018_modifier_visual_map.sql` — zastąpione przez nowsze migracje / cleanup w **025**. |
| **Opcjonalnie destrukcyjne dane** | `015_normalize_three_drivers.sql` — **domyślnie pominięte**; łańcuch: `php scripts/apply_migrations_chain.php --include-015`. |
| **Reszta numerów** | Wszystkie pozostałe `*.sql` (004, 006–014, 016–034, dwa pliki **032**) są **w tablicy `$chain`** w `scripts/apply_migrations_chain.php`. |

**Brak plików dla numerów 002, 003, 005, 018** w katalogu — to zamierzone luki w repozytorium (nie ma czego „dopinać”).

### Automatyczna weryfikacja dysku ↔ łańcuch

```text
c:\xampp\php\php.exe scripts\apply_migrations_chain.php --audit
```

- Kod **0**: każdy plik `migrations/*.sql` (poza `001` i `_archive_*`) jest w łańcuchu; każdy wpis łańcucha istnieje na dysku.  
- Kod **1**: nowy plik `.sql` dopisany do folderu, ale nie dodany do skryptu — trzeba zaktualizować `$chain`.

Przy normalnym uruchomieniu (bez `--audit`) skrypt **najpierw** wykonuje ten sam audyt; przy błędzie **nie łączy się z bazą**.

### `setup_database.php` a pełny schemat

`setup_database.php` **nie zastępuje** importu wszystkich plików z `migrations/`. Wykonuje m.in. **kopie** **006/008** (007 inline), pliki **012–014**, **016–017** (część w PHP), **020–023**, **024–029** oraz **ALTER-y fazy 2–3** migracji **022** (tylko w PHP).  

**Tylko w `apply_migrations_chain.php`** (po **001**) pojawiają się m.in.: **004**, **009**, **010**, **011**, **019**, **030–034**. Dlatego ścieżka **001 → apply_migrations_chain → setup_database** jest kompletna dla plików SQL w repozytorium.

### Poza folderem `migrations/`

**`scripts/setup_enterprise_tables.php`** — stoły / dine-in / dodatkowe FK (nie jest to ten sam zestaw co `migrations/`). Uruchom **tylko jeśli** używasz modułu stolików / dokumentacji enterprise.

---

## Ścieżka A — „od zera” (gdy schemat jest stary / podejrzany)

Najpewniejsza metoda: nowa baza + pełny łańcuch migracji + seed.

1. **Zatrzymaj ruch na produkcji** — te kroki są wyłącznie na lokalnym/dev.

2. **Utwórz pustą bazę** (phpMyAdmin lub konsola), np. `slicehub_pro_v2`, kodowanie **utf8mb4_unicode_ci**.

3. **Import schematu startowego**  
   W phpMyAdmin: Import → plik  
   `database/migrations/001_init_slicehub_pro_v2.sql`  
   (tworzy tabele `sh_*`, `sys_*`, `wh_*` itd.)

4. **Dopasuj `core/db_config.php`**  
   Ustaw `$db`, `$user`, `$pass` tak, aby wskazywały **tę samą bazę**, co w imporcie.

5. **Łańcuch migracji SQL (004–034)**  
   W przeglądarce lub CLI (XAMPP):  
   `http://localhost/slicehub/scripts/apply_migrations_chain.php`  
   lub:  
   `c:\xampp\php\php.exe scripts\apply_migrations_chain.php`  
   (z katalogu głównego projektu).  
   Opcjonalnie najpierw: `--dry-run` lub sam audyt zgodności: `--audit`.  
   **015** domyślnie pominięte; na dev z seedem jak w migracji:  
   `php scripts/apply_migrations_chain.php --include-015`

6. **Domknięcie schematu (ALTER-y M022 z PHP)**  
   Otwórz:  
   `http://localhost/slicehub/scripts/setup_database.php`  
   (idempotentne; nakłada się m.in. na 006–008 — to celowe **kopie** w kodzie).

7. **Dane demo: użytkownicy, menu, magazyn, 12 przykładowych zamówień**  
   `http://localhost/slicehub/scripts/seed_demo_all.php`  
   Poczekaj na zielony wynik sekcji **„Orders (12 total)”**.

8. **Logowanie kiosk (PIN)**  
   Po seedzie m.in.: kelner `1111`, kierowca `4444` / `5555` (użytkownicy `waiter1`, `driver1`, `driver2` w `seed_demo_all.php`).  
   W `modules/pos/index.html` meta **tenant** musi być **`1`**, jeśli POS filtruje po `tenant_id`.

---

## Ścieżka B — schemat OK, chcesz tylko wyczyścić konta i zamówienia

Gdy migracje już są, a chcesz **puste zamówienia + świeże konta** bez przebudowywania całej bazy:

1. **Sprawdź nazwę bazy**  
   Skrypt `scripts/nuclear_reset.php` ma **na sztywno** host `localhost` i bazę `slicehub_pro_v2` (linie ~41–44).  
   Jeśli używasz innej nazwy bazy — tymczasowo zmień tam DSN **albo** zmień nazwę bazy w MySQL na `slicehub_pro_v2`.

2. **Uruchom nuclear reset**  
   `http://localhost/slicehub/scripts/nuclear_reset.php`  
   Skrypt usuwa m.in. linie zamówień, audyt, `sh_orders`, dyspozycje, zmiany kierowców, GPS, `sh_drivers`, **wszystkich `sh_users` dla tenant 1**, resetuje sekwencje, potem tworzy **6 kont** z PIN-ami `0000`–`5555` (manager, kelnerzy, kucharz, 2 kierowców).

3. **Przykładowe zamówienia ponownie**  
   `nuclear_reset` **nie** wstawia zamówień — tylko czyści i daje 6 użytkowników.  
   Żeby znów mieć **12 zamówień demo** (jak w seedzie), **jednorazowo** uruchom:  
   `http://localhost/slicehub/scripts/seed_demo_all.php`  
   Ten skrypt robi `ON DUPLICATE KEY UPDATE` na użytkownikach/menu — **uzupełni** konta do zestawu 8 osób z seeda i wstawi paczkę zamówień.  
   **Uwaga:** sekcja zamówień generuje **nowe UUID** przy każdym pełnym przebiegu seeda — **nie uruchamiaj `seed_demo_all.php` wielokrotnie** bez wcześniejszego wyczyszczenia zamówień (np. ponownie `nuclear_reset`), bo narastają duplikaty zamówień.

---

## Ścieżka C — tylko wymiana kont (bez kasowania zamówień)

`http://localhost/slicehub/scripts/reset_users.php`  
— używa `db_config.php`, usuwa użytkowników tenant 1 i wstawia **5** kont z innym rosterem. **Zamówień nie usuwa** (mogą zostać „sieroty” względem `user_id` — stąd dla pełnego resetu lepszy **nuclear_reset**).

---

## Co sprawdzić, gdy coś nadal „nie działa”

| Problem | Gdzie szukać |
|--------|----------------|
| Błąd połączenia z bazą | `core/db_config.php` vs rzeczywista nazwa bazy użytkownika/hasło |
| Kiosk 401 / Invalid credentials | `pin_code` + `status = 'active'` w `sh_users`; po seedzie/nuclear sprawdź wiersze dla `tenant_id = 1` |
| Brak tabel / kolumn | Czy wykonano **001** → **apply_migrations_chain** → **setup_database** |
| Nuclear reset nie łączy się | Nazwa bazy w `nuclear_reset.php` musi być zgodna z MySQL |

---

## Krótkie podsumowanie kolejności (najczęstszy dev)

| Cel | Kolejność |
|-----|-----------|
| Wszystko od zera | **001** → **apply_migrations_chain** → **setup_database** → **seed_demo_all** |
| Czyste konto + czyste zamówienia + potem demo | **nuclear_reset** → **seed_demo_all** (raz) |
| Tylko schema bez danych | **001** → **apply_migrations_chain** → **setup_database** (bez seeda) |

Szczegóły łańcucha migracji: `database/README.md`.
