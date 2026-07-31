# Database — migracje i skrypty

**Krok po kroku: czysta baza, konta, produkty, zamówienia** → [`INSTRUKCJA_CZYSTY_START.md`](INSTRUKCJA_CZYSTY_START.md).

**Audyt łańcucha (bez bazy):**

```bash
php scripts/apply_migrations_chain.php --audit
# Oczekiwany wynik: OK: łańcuch zgodny z plikami na dysku
```

---

## Ważne: migracje ≠ produkty

| Warstwa | Plik / skrypt | Co daje |
|---------|---------------|---------|
| **1. Schemat bazowy** | `migrations/001_init_slicehub_pro_v2.sql` | Tabele `sh_*`, `sys_*`, `wh_*`. **Zero pozycji menu.** |
| **2. Ewolucja schematu** | `scripts/apply_migrations_chain.php` | Kolumny i tabele z migracji **004–059** (53 pliki). **Nadal zero produktów.** |
| **3. Dane (menu, ceny, magazyn)** | `scripts/seed_demo_all.php`, `scripts/seed_pizzaforno.sql`, Studio / Install Panel | Dopiero tu pojawiają się produkty w `sh_menu_items` + ceny w `sh_price_tiers`. |

Po samym **001 + chain** POS, Online i Kiosk pokazują **pustą listę** — to poprawne zachowanie, nie błąd migracji.

Źródło prawdy kolejności SQL: [`scripts/_migrations_chain.php`](../scripts/_migrations_chain.php) (jedna tablica używana przez `apply_migrations_chain.php` i `install_panel.php`).

---

## Kanoniczny łańcuch plików SQL

1. **`migrations/001_init_slicehub_pro_v2.sql`** — pełny schemat startowy (import ręczny lub Install Panel → krok A). Na hostingu usuń z pliku linie `CREATE DATABASE` i `USE` przed importem.

2. **`scripts/apply_migrations_chain.php`** — wykonuje pliki **004 … 059** z `migrations/` (bez `001`, bez `_archive_*`). CLI:
   - `php scripts/apply_migrations_chain.php`
   - `php scripts/apply_migrations_chain.php --dry-run`
   - `php scripts/apply_migrations_chain.php --audit` — tylko weryfikacja dysku ↔ łańcuch
   - `php scripts/apply_migrations_chain.php --include-015` — dołącza `015_normalize_three_drivers.sql` (DELETE/UPDATE na demo tenant 1; domyślnie **pomijane**)

3. **`scripts/setup_database.php`** — opcjonalnie po łańcuchu: domyka **ALTER-y fazy 2–3 migracji 022** zapisane tylko w PHP. Nakłada się na **006–008** jako **kopie** (idempotentne). Na hostingu zwykle **nie jest wymagany** do logowania — wystarczy pełny chain.

4. **Dane** — osobny krok (nie jest częścią `migrations/`):
   - Demo (tenant 1): `scripts/seed_demo_all.php` — 33 produkty, 8 użytkowników, magazyn, zamówienia
   - Produkcja Forno (tenant 2): `scripts/seed_pizzaforno.sql` — import w phpMyAdmin (~229 pozycji menu)
   - Hosting czysty: `scripts/install_panel.php` tworzy tenanta + ownera, **bez seeda** — menu dodajesz w Studio lub importujesz SQL

---

## Pliki poza domyślnym łańcuchem

| Plik | Status |
|------|--------|
| `015_normalize_three_drivers.sql` | Opcjonalny (`--include-015`), destrukcyjny na tenant 1 |
| `_archive_014_ingredient_assets.sql` | Archiwum — nie uruchamiaj |
| `_archive_018_modifier_visual_map.sql` | Archiwum — nie uruchamiaj |

**Brak numerów 002, 003, 005, 018** — zamierzone luki historyczne.

Nowa migracja: dopisz plik na końcu `_migrations_chain.php` i uruchom `--audit` — patrz [`_docs/MIGRATIONS_AGENT_CHECKLIST.md`](../_docs/MIGRATIONS_AGENT_CHECKLIST.md).

---

## Zakres łańcucha 004–059 (skrót)

| Zakres | Temat |
|--------|--------|
| 004–011 | Aliasy AutoScan, Studio, POS, delivery, integracje |
| 012–025 | Warstwy wizualne, assety, sceny, Scene Kit, cleanup legacy |
| 026–034 | Event outbox, gateway, webhooki, powiadomienia, GDPR |
| 035–040 | Atelier, POS foundation, resilient POS, server events |
| 041–044 | HR: pracownicy, sesje, płace, zaliczki |
| 045–047 | Profil firmy (NIP), KSeF inbox, geokodowanie zamówień |
| 048–055 | Warianty rozmiarów, zestawy POS, `publication_status`, subreceptury |
| 056–059 | KSeF OPEX, normalizacja qty, unique mapping dostawcy |

Pełna lista plików: `php -r 'foreach(require "scripts/_migrations_chain.php" as $f) echo $f, PHP_EOL;'`

---

## Znane FAIL na MariaDB hostingowej (uti.pl)

Przy ponownym uruchomieniu chaina lub na starszej MariaDB:

1. **`010`** — `Duplicate column name 'driver_action_type'` — nieszkodliwe przy re-run.
2. **`037`** — błąd `_active_table_guard` (generated column) — POS działa; brakuje tylko twardej blokady DB na duplikat aktywnego zamówienia per stolik.

Wszystkie pozostałe migracje powinny mieć `OK`.

---

## Kopie (celowo zachowane)

- **`setup_database.php`** — wbudowane `ALTER` dla **006**, **007** oraz `CREATE` dla **008**.
- **`seed_demo_all.php`** — preflight sprawdza tabele z chain (KSeF, meal packages, OPEX); nie zastępuje `apply_migrations_chain`.
- **`nuclear_reset.php`** — reset zamówień/userów tenant 1; **nie** kasuje menu.
- **`setup_enterprise_tables.php`** — legacy; kanon stołów jest w migracji **037**.

Nie usuwaj tych duplikatów bez decyzji architektonicznej.

---

## Powiązana dokumentacja

| Temat | Plik |
|-------|------|
| Czysty start dev / nuclear reset | [`INSTRUKCJA_CZYSTY_START.md`](INSTRUKCJA_CZYSTY_START.md) |
| Seed demo i Forno | [`_docs/SEED_GUIDE.md`](../_docs/SEED_GUIDE.md) |
| Deploy hosting (uti.pl) | [`_docs/20_DEPLOY_TEST_HOSTING.md`](../_docs/20_DEPLOY_TEST_HOSTING.md) |
| Pełny schemat tabel | [`_docs/04_BAZA_DANYCH.md`](../_docs/04_BAZA_DANYCH.md) |
| Nowa migracja (checklist) | [`_docs/MIGRATIONS_AGENT_CHECKLIST.md`](../_docs/MIGRATIONS_AGENT_CHECKLIST.md) |
| Wszystkie skrypty w `scripts/` | opis w sesji / README projektu — katalog [`../scripts/`](../scripts/) |
