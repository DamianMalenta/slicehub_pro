# Sesja: Domknięcie R1 + R2 (SKU consistency + edited_since_print reset)

**Data:** 2026-07-30
**Powiązane:** `2026-07-30_rekomendacje_po_fazach_a_do_e.md` (rekomendacje), `2026-07-30_phase_e_order_edit_kds_delta.md` (Faza E)

---

## 1. Cel

Domknięcie dwóch rekomendacji z sesji po Fazach A→E:
- **R1** (wysoki priorytet): SKU inconsistency w demo data / testach.
- **R2** (wysoki priorytet): `edited_since_print` nigdy nie resetowany → KDS banner permanentny.

---

## 2. R1 — SKU inconsistency

### Diagnoza (poprawiona)

Pierwotna rekomendacja twierdziła, że `seed_demo_all.php` zapisuje `item_sku=MARGHERITA` (bez prefiksu) w `sh_order_lines`, podczas gdy `sh_menu_items.ascii_key=PIZZA_MARGHERITA`. Audyt `git blame` wykazał inaczej:

```
git blame -L 741,741 scripts/seed_demo_all.php
554594ff (DamianMalenta 2026-04-23 00:50:20 +0200 741)
    ['preparing', 'unpaid', 'cash', [['PIZZA_MARGHERITA','Margherita',2400,1],...
```

Seed od pierwszego commita (2026-04-23) używa poprawnych SKU z prefiksem (`PIZZA_MARGHERITA`, `BURGER_CLASSIC`, `DRINK_COLA_05`, etc.) — zgodnych z `sh_menu_items.ascii_key`. Wszystkie 12 zamówień demo używa 33 poprawnych SKU.

`MARGHERITA` (bez prefiksu) w bazie pochodził z **ręcznego utworzenia menu item** podczas testów (nie z seeda). CartEngine poprawnie odrzucał edycję takich zamówień, bo `ascii_key=MARGHERITA` nie istniał w `sh_menu_items` (tylko `PIZZA_MARGHERITA`).

### Realny problem: test_runner.html

`tests/test_runner.html` (suite POS `process_order`) używał nieistniejących SKU:
- `MARGHERITA` zamiast `PIZZA_MARGHERITA`
- `OPT_JALAPENO` zamiast `EXTRA_JALAP`
- `SER_MOZZARELLA` zamiast `SER_MOZZ`
- ceny `25.99/51.98` zamiast `24.00/56.00` (seed: POS price 24.00, 2× + 4.00 modifier = 56.00)

Test przechodził tylko dlatego, że w bazie był ręcznie utworzony item `ascii_key=MARGHERITA` z ceną 25.99 — po czystym resecie + seedzie test by upadł.

### Fix

| Plik | Zmiana |
|------|--------|
| `tests/test_runner.html` (linie 1473-1485) | SKU: `MARGHERITA`→`PIZZA_MARGHERITA`, `OPT_JALAPENO`→`EXTRA_JALAP`, `SER_MOZZARELLA`→`SER_MOZZ`; ceny: `25.99/51.98`→`24.00/56.00` |
| `scripts/check_sku_consistency.php` (nowy) | Backfill `MARGHERITA`→`PIZZA_MARGHERITA` dla istniejących order lines + weryfikacja orphanów (LEFT JOIN `sh_menu_items`) |

### Weryfikacja

```
mysql> SELECT ol.item_sku, mi.ascii_key, COUNT(*) cnt
      FROM sh_order_lines ol
      JOIN sh_orders o ON o.id=ol.order_id
      LEFT JOIN sh_menu_items mi ON mi.tenant_id=o.tenant_id AND mi.ascii_key=ol.item_sku
      WHERE o.tenant_id=1 AND mi.id IS NULL
      GROUP BY ol.item_sku, mi.ascii_key;
-- 0 rows (zero orphanów)

php scripts/check_sku_consistency.php
-- count=0, updated=0 rows, remaining orphan groups: 0
```

---

## 3. R2 — `edited_since_print` reset

### Problem

Flaga `sh_orders.edited_since_print` ustawiana na `1` przy edycji zamówienia (`api/pos/engine.php#process_order` edit path, linia 850) ale nigdy resetowana na `0` poza `print_kitchen` (linia 1328). KDS pokazuje banner "ZAMÓWIENIE EDYTOWANE" permanentnie — kuchnia nie ma sposobu potwierdzić, że widziała zmiany.

### Fix — auto-reset przy `ready` (zamiast przycisku)

Decyzja: auto-reset przy przejściu do `ready` (prostsze, zero dodatkowego click-path dla kucharza) zamiast przycisku "Potwierdzam zmiany" w KDS.

| Plik | Zmiana |
|------|--------|
| `api/kds/engine.php` (linia 225-227) | `bump_order` → `ready`: `edited_since_print=0, kitchen_delta=NULL` |
| `core/KdsTicketEngine.php` (linia 103-107) | `bump_ticket` (last ticket done → order `ready`): ten sam reset |
| `api/pos/engine.php` (linia 1328, już committed) | `print_kitchen`: reset `edited_since_print=0` (istniał wcześniej) |

**Logika:** gdy kuchnia kończy (bump do `ready`), flaga edycji i `kitchen_delta` są czyszczone — banner znika, bo kuchnia potwierdziła zakończeniem. `kitchen_delta` (JSON z diffem linii) jest nullowany bo po `ready` order opuszcza tablicę KDS — delta nie ma już konsumenta.

### Dodatkowy fix

`modules/courses/js/courses_ui.js:281` — typo w eksporcie: `formatGrosche` → `formatGrosze` (nazwa funkcji to `formatGrosze`, eksportował jako `formatGrosche` — `courses_app.js` używał `CoursesUI.formatGrosze` więc eksport nie pasował do nazwy).

---

## 4. Weryfikacja

### Lint
```
php -l api/kds/engine.php          → No syntax errors detected
php -l core/KdsTicketEngine.php    → No syntax errors detected
php -l scripts/check_sku_consistency.php → No syntax errors detected
node -c modules/courses/js/courses_ui.js  → OK
```

### Testy E2E (62 suite, headless Chrome)
```
CHROME_PATH="C:\Program Files\Google\Chrome\Application\chrome.exe" node scripts/run_test_runner_headless.cjs
→ { "pass": "61", "fail": "0", "warn": "1", "total": "62", "badge": "1 WARNINGS · 61 passed" }
```
Błędy 400/401/404/405 w logach = oczekiwane testy walidacji negatywnej. Brak regresji.

### Seed (po czystym resecie)
```
php scripts/seed_demo_all.php
→ 26 OK, 0 ERRORS (33 menu items, 12 zamówień, 8 użytkowników, 47 warehouse, receptury, KSeF, HR)
```

---

## 5. Pozostałe rekomendacje (kolejne sesje)

| ID | Priorytet | Status |
|----|-----------|--------|
| R1 | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 |
| R2 | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 |
| R3 | 🟡 średni | otwarte — edycja modyfikatorów w UI (Faza E rozszerzenie) |
| R4 | 🟡 średni | otwarte — kalibracja progów marży (Food Cost → `sh_tenant_settings`) |
| R5 | 🟡 średni | otwarte — bulk Food Cost Report (agregacja całego menu) |
| R6 | 🟢 niski | otwarte — `estimate.php` scheduled-picker UI (ostatni `@planned`) |
| R7 | 🟢 niski | otwarte — UX brak receptury w Food Cost Report |
| R8 | 🟢 niski | otwarte — kitchen delta historia w `sh_order_audit` |
