# Database — migracje i skrypty

**Krok po kroku: czysta baza, konta, przykładowe zamówienia** → [`INSTRUKCJA_CZYSTY_START.md`](INSTRUKCJA_CZYSTY_START.md) (sekcja *Audyt zgodności* + `php scripts/apply_migrations_chain.php --audit`).

## Kanoniczny łańcuch plików SQL

1. **`migrations/001_init_slicehub_pro_v2.sql`** — pełny schemat startowy (import ręczny lub narzędzie DB).
2. **`scripts/apply_migrations_chain.php`** — kolejno wykonuje pliki `004` … `034` z `migrations/` (bez `001`, bez `_archive_*`). Z linii poleceń:
   - `php scripts/apply_migrations_chain.php`
   - `php scripts/apply_migrations_chain.php --dry-run`
   - `php scripts/apply_migrations_chain.php --include-015` — dołącza `015_normalize_three_drivers.sql` (DELETE/UPDATE na danych demo tenant 1; domyślnie **pomijane**).
3. **`scripts/setup_database.php`** — nadal uruchom po łańcuchu: domyka **ALTER-y fazy 2–3 migracji 022** zapisane tylko w PHP (plik `022_scene_kit.sql` celowo nie zawiera tych `ALTER`ów). Nakłada się na **006–008** jako **kopie** (idempotentne).

## Kopie (celowo zachowane)

- **`setup_database.php`** — wbudowane `ALTER` dla **006**, **007** oraz `CREATE` dla **008** (powielają pliki SQL z `migrations/`).
- **`seed_demo_all.php`** — ponownie **006–008** na wejściu seeda (ten sam pomysł, inna ścieżka uruchomienia).
- **`nuclear_reset.php`** — m.in. własny **`CREATE` `sh_driver_locations`** (nakładka z **008**).

Nie usuwaj tych duplikatów bez decyzji architektonicznej — służą częściowemu odświeżeniu schematu bez importu całego łańcucha.

## Pełna dokumentacja schematu

`_docs/04_BAZA_DANYCH.md` (jeśli istnieje w repozytorium).
