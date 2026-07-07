# Instrukcja: naprawa środowiska, czyste konta i przykładowe zamówienia

Założenia: **XAMPP** lub **Cloud Agent**, baza **MySQL/MariaDB**, projekt w `htdocs/slicehub`, domyślny tenant demo **`tenant_id = 1`**. Ścieżki URL: `http://localhost/slicehub/` — dopasuj do swojej konfiguracji.

> **Hosting (uti.pl):** ten sam łańcuch migracji, ale bez seeda w Install Panelu — patrz [`_docs/20_DEPLOY_TEST_HOSTING.md`](../_docs/20_DEPLOY_TEST_HOSTING.md) oraz sekcja *Produkty* poniżej.

---

## Migracje a produkty — nie myl tych kroków

```
001 (tabele)  →  chain 004–059 (kolumny)  →  SEED lub Studio (dane)
                                              ↑
                                    bez tego POS = pusty
```

| Po kroku | `sh_menu_items` | POS |
|----------|-----------------|-----|
| Tylko `001` | 0 | pusty |
| `001` + chain | 0 | pusty |
| + `seed_demo_all.php` (tenant 1) | 33 | pełne menu demo |
| + `seed_pizzaforno.sql` (tenant 2) | ~229 | menu Forno (`?tenant=2`) |

Ceny są w **`sh_price_tiers`**, nie w `sh_menu_items`. Seed wstawia obie tabele.

---

## Audyt zgodności migracji (czy coś jest pominięte)

### Pliki w `database/migrations/`

| Grupa | Pliki |
|--------|--------|
| **Baza startowa (osobno)** | `001_init_slicehub_pro_v2.sql` — **nie** jest w łańcuchu (import przed chain). |
| **Archiwum** | `_archive_014_ingredient_assets.sql`, `_archive_018_modifier_visual_map.sql` — nie uruchamiaj. |
| **Opcjonalnie destrukcyjne** | `015_normalize_three_drivers.sql` — tylko `php scripts/apply_migrations_chain.php --include-015`. |
| **Łańcuch kanoniczny** | **53 pliki** `004` … `059` (bez 015) — tablica w `scripts/_migrations_chain.php`. |

**Brak plików 002, 003, 005, 018** — zamierzone luki.

### Automatyczna weryfikacja dysku ↔ łańcuch

```bash
php scripts/apply_migrations_chain.php --audit
```

- Kod **0**: każdy plik `migrations/*.sql` (poza `001`, `_archive_*`, domyślnie `015`) jest w łańcuchu.
- Kod **1**: nowy `.sql` na dysku bez wpisu w `_migrations_chain.php` — napraw przed deployem.

Przy normalnym uruchomieniu skrypt **najpierw** robi audyt; przy błędzie **nie łączy się z bazą**.

### `setup_database.php` a pełny schemat

`setup_database.php` **nie zastępuje** `apply_migrations_chain.php`. Wykonuje kopie **006–008**, część plików **012–029** oraz ALTER-y fazy 2–3 migracji **022** (tylko w PHP).

**Tylko w chain** (po `001`) są m.in.: **004**, **009–011**, **019**, **030–059**, **037–046**, **048–055**. Ścieżka kompletna:

**001 → apply_migrations_chain → (opcjonalnie) setup_database → seed**

### Poza folderem `migrations/`

| Skrypt | Rola |
|--------|------|
| `scripts/setup_enterprise_tables.php` | Legacy stołów — kanon w migracji **037** |
| `scripts/install_panel.php` | Panel hostingowy: 001 + chain + tenant/owner (**bez produktów**) |
| `scripts/seed_demo_all.php` | Dane demo tenant 1 |
| `scripts/seed_pizzaforno.sql` | Pełne menu tenant 2 (phpMyAdmin) |

---

## Ścieżka A — „od zera” (gdy schemat jest stary / podejrzany)

1. **Zatrzymaj ruch na produkcji** — kroki wyłącznie na lokalnym/dev.

2. **Utwórz pustą bazę**, np. `slicehub_pro_v2`, kodowanie **utf8mb4_unicode_ci**.

3. **Import schematu startowego** — `database/migrations/001_init_slicehub_pro_v2.sql`  
   Na hostingu usuń linie `CREATE DATABASE` i `USE` przed importem.

4. **Połączenie DB** — lokalnie `core/db_config.php` (fallback XAMPP); na hostingu env-y `SLICEHUB_DB_*`.

5. **Łańcuch migracji SQL (004–059)**  
   ```bash
   php scripts/apply_migrations_chain.php
   ```  
   lub w przeglądarce: `http://localhost/slicehub/scripts/apply_migrations_chain.php`  
   Opcjonalnie: `--dry-run`, `--audit`. **015** domyślnie pominięte.

6. **Domknięcie schematu (opcjonalnie)**  
   `http://localhost/slicehub/scripts/setup_database.php`  
   Idempotentne; na świeżej bazie po pełnym chain zwykle zbędne do logowania.

7. **Dane demo — produkty, użytkownicy, magazyn, 12 zamówień**  
   `http://localhost/slicehub/scripts/seed_demo_all.php`  
   Poczekaj na sekcję **„Orders (12 total)”**. Wymaga chain do co najmniej **046, 050, 057** (preflight w seedzie).

8. **Logowanie kiosk (PIN)**  
   Po seedzie: kelner `1111`, kierowca `4444` / `5555`. Tenant **1** w meta / `tenant_config.php`.

---

## Ścieżka A2 — produkcja Pizza Forno (tenant 2)

Po **001 + chain** (bez `seed_demo_all`):

1. W phpMyAdmin zaimportuj `scripts/seed_pizzaforno.sql` (idempotentny, `SET @tid := 2`).
2. Weryfikacja: `bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 2`
3. Moduły: `?tenant=2` w URL lub env `SLICEHUB_TENANT_ID=2`.

Szczegóły: [`_docs/SEED_GUIDE.md`](../_docs/SEED_GUIDE.md), [`_docs/menu_pizzaforno/SEED_REPORT.md`](../_docs/menu_pizzaforno/SEED_REPORT.md).

---

## Ścieżka B — schemat OK, chcesz tylko wyczyścić konta i zamówienia

1. **`nuclear_reset.php`** — DSN na sztywno `slicehub_pro_v2` / `localhost` (dostosuj jeśli inna baza).

2. Uruchom: `http://localhost/slicehub/scripts/nuclear_reset.php`  
   Kasuje zamówienia i userów tenant 1; **nie kasuje menu** (`sh_menu_items` zostaje).

3. Ponowne zamówienia demo: **jednorazowo** `seed_demo_all.php` (sekcja Orders).  
   Wielokrotny seed bez resetu zamówień → duplikaty UUID zamówień.

---

## Ścieżka C — tylko wymiana kont (bez kasowania zamówień)

`http://localhost/slicehub/scripts/reset_users.php` — 5 kont, tenant 1. Zamówień nie usuwa.

---

## Co sprawdzić, gdy coś nadal „nie działa”

| Problem | Gdzie szukać |
|--------|----------------|
| Błąd połączenia z bazą | `core/db_config.php` / env `SLICEHUB_DB_*` |
| Kiosk 401 | `pin_code` + `tenant_id` w `sh_users` |
| **Brak produktów w POS** | Czy był **seed** lub ręczne dodanie w Studio? Po samym chain: **0 produktów = OK** |
| Produkty w DB, pusty POS | Zły `tenant_id`; `publication_status` ≠ `Live`; sezonowe `valid_to` w przeszłości |
| Brak cen | Brak wierszy w `sh_price_tiers` dla `target_sku` |
| Brak tabel / kolumn | **001** → **apply_migrations_chain** (do **059**) |
| Nuclear reset nie łączy się | Nazwa bazy w `nuclear_reset.php` |

### Szybka diagnostyka SQL (phpMyAdmin)

```sql
SELECT tenant_id, COUNT(*) AS produkty
FROM sh_menu_items WHERE is_deleted = 0 GROUP BY tenant_id;

SELECT tenant_id, COUNT(*) AS ceny
FROM sh_price_tiers WHERE target_type = 'ITEM' GROUP BY tenant_id;
```

---

## Krótkie podsumowanie kolejności

| Cel | Kolejność |
|-----|-----------|
| Wszystko od zera (demo) | **001** → **apply_migrations_chain** → **seed_demo_all** |
| Forno (tenant 2) | **001** → **chain** → import **seed_pizzaforno.sql** |
| Tylko schema (hosting czysty) | **001** → **chain** → Install Panel (tenant+owner) → Studio lub seed |
| Czyste zamówienia + demo | **nuclear_reset** → **seed_demo_all** (raz) |

Szczegóły łańcucha: [`database/README.md`](README.md).
