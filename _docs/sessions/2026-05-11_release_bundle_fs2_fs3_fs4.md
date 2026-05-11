# AI Session Audit — Release Bundle: F-S2 + F-S3 + F-S4

**Data:** 2026-05-11
**Branch:** `projektx/release-bundle-c3d7` (zbiera F5+F6+F-S1+F-S2+F-S3+F-S4)
**Konstytucja:** v5 (Prawa II, III, VI, VIII, X)
**Trigger:** „doslownie nic jeszcze nie wgrywalem od samego poczatku czekam az bedzie wszystko gotowe i wtedy wgram na serwer do testow. zrob cala reszte wtedy zrobie testy."

---

## 1. Co zawiera ten release

Sześć faz w jednej paczce + naprawa drift-ów:

| Faza | Zakres | Migracje |
|---|---|---|
| **F5** POS Integrity Pass | Server-authoritative cart (CartEngine revalid), reverse stock na cancel, modifiers `sku` ujednolicony, temporal filter w POS init | — (kod) |
| **F6** Geocoder Nominatim | Adres → `delivery_lat/lng` przez Nominatim + cache; dispatcher Leaflet pokazuje real pin | `047_order_geocoding.sql` |
| **F-S1** Variant Scales | Pizza Mała/Średnia/Duża jako wariant family (parent + N children + multiplier); iiko-style | `048_variant_scales.sql` |
| **F-S2** Topping Size Pricing | Modifier ma różne ceny per rozmiar pizzy (Toast-style); 4 strategie half-half | `049_modifier_size_pricing.sql` |
| **F-S3** Meal Packages | Combo / Bundle (Petpooja-style); `sh_meal_packages` + `sh_meal_components` | `050_meal_packages.sql` |
| **F-S4** Studio drift cleanup | `published`→`Live` normalizacja, `valid_from` DATE→DATETIME, bulk VAT, recipe clone, parent_sku walidacja | `051`, `052` |

---

## 2. F-S2 — Topping Size Pricing + Half-Half Strategy

### Schema (049)
```
sh_modifier_pricing:
  modifier_id, variant_option_id, price_grosze
  UNIQUE (modifier_id, variant_option_id)
sh_tenant_settings (seed):
  half_pricing_strategy ∈ {percentage|average|higher|full}, default percentage
  half_pricing_percent ∈ [0..100], default 50
```

### Backend
- `CartEngine::calculate` — schema-aware probe `variant_option_id`. `resolveModPrice($modSku, ?int $variantOptId)` → najpierw `sh_modifier_pricing`, fallback do `sh_modifiers.price` (legacy).
- Half-half: w pętli linii, jeśli `is_half`, aplikuje strategy do **każdego** modyfikatora:
  - `percentage` → `price × (half_percent / 100)` (default 50%)
  - `average` → pełna cena (modifier dotyczy obu połówek)
  - `higher` → pełna cena
  - `full` → pełna cena
- Dla half-line bierze `variant_option_id` z `halfA` (pierwsza połowa) — intuicyjne dla większości flow.

### Studio API
- `get_modifier_pricing` — lista cen per option dla modyfikatora.
- `save_modifier_pricing` — upsert + soft-delete row nieobecny w payload.
- `get_half_pricing_settings` / `save_half_pricing_settings` (walidacja: strategy enum, percent 0..100).

### Test E2E
```
Pizza S + salami = 15 + 3.00 = 18.00 zł     (Size Pricing per option S=300 gr)
Pizza L + salami = 30 + 5.00 = 35.00 zł     (Size Pricing per option L=500 gr)
Half S+L + salami (strategy=percentage 50%) = max(15,30) + 2 surcharge + (3.00 × 0.5) = 33.50 zł
```

---

## 3. F-S3 — Meal Packages (Combo)

### Schema (050)
```
sh_meal_packages:
  id, tenant_id, ascii_key UNIQUE, name, description, category_id FK,
  type ENUM('fixed','choice'), final_price_grosze NULL, discount_percent NULL,
  publication_status, valid_from/to DATETIME, is_active, display_order
sh_meal_components:
  meal_id, component_type ENUM('fixed_item','category_choice'),
  item_sku NULL (klucz znakowy → sh_menu_items), category_id FK,
  qty, allow_upgrade, surcharge_grosze, display_order
```

**Decyzja**: nazwa `sh_meal_packages` rozdziela sprzedażowe combo od HR `sh_meals` (posiłki pracownicze, migracja 001) — Konstytucja v5 § Prawo II (izolacja domen).

### Studio API
- `list_meals` — z count(components)
- `get_meal_details` — meal + components z item names + category names (LEFT JOIN cross-silo via `item_sku`)
- `save_meal` — upsert + REPLACE components (transactional)
- `delete_meal` — soft delete

### POS
- `get_init_data` zwraca `mealPackages: [...]` z components inline + temporal filter (Live + valid_from/to NOW())
- POS UI: kafelek combo (frontend rendering w przyszłej iteracji — backend gotowy)

---

## 4. F-S4 — Studio drift cleanup

| Drift | Fix |
|---|---|
| Studio zapisywało `Live`, POS filtrował `published` | Migracja 051: `UPDATE WHERE publication_status='published' SET 'Live'`. POS engine akceptuje `IN ('Live','published')` (backward compat). |
| `valid_from`/`valid_to` to DATE — happy hours 14:00-17:00 nie działały | Migracja 052: `ALTER COLUMN ... DATETIME`. POS i Studio backend już używały NOW() / datetime-local. |
| Edycja masowa: select VAT pokazywał wartości 1/2/3/4 (ID), backend ich nie wysyłał i nie czytał | `studio_bulk.js` wysyła `vatRateDineIn` + `vatRateTakeaway` (z `bulk-vat` select). `index.html`: option value to teraz prawdziwe stawki 0/5/8/23. `api_menu_studio.php#save_bulk` ma handler. |
| `parent_sku` nie był walidowany (mógł wskazywać na nieistniejące SKU) | Walidacja FK w `add_item`/`update_item_full` — rzuca błąd dla brakującego klucza. |
| Brak recipe templates — duplikowanie receptur między rozmiarami | Nowa akcja `clone_recipe` (source_ascii_key → target_ascii_key) + modal w `studio_recipe.js` z search + 1-click clone. |

### Test E2E (5 PASS)
```
✅ clone_recipe FS4_SRC → FS4_DST: 2 składniki (FLOUR + CHEESE) skopiowane z waste_percent
✅ Bulk VAT 23% aplikowane do dine_in + takeaway
✅ parent_sku NONEXISTENT_KEY → endpoint rzuca Exception
✅ Migracja 051: 0 itemów z 'published' (znormalizowane do 'Live')
✅ Migracja 052: sh_menu_items.valid_from = DATETIME
```

---

## 5. Konstytucja v5 — zakotwiczenia

### Prawo II (Bliźniak Cyfrowy)
- F-S2: physical: ten sam topping ma rzeczywiście różną cenę w restauracji per rozmiar pizzy.
- F-S3: physical: combo jest realnym produktem w menu, nie symulacją sumowania.
- F-S4: physical: happy hours od 14:00 mają być rozróżnialne, więc DATETIME nie DATE.

### Prawo III (Temporal Tables)
- F-S4 migracja 051 normalizuje słownik `Draft|Live|Archived`.
- F-S4 migracja 052 daje pełną granularność czasu.

### Prawo VI (Snajper)
- Wszystkie nowe tabele zachowują `tenant_id` w każdym JOIN-ie i FK.
- Cross-silo? Tylko w obrębie silosu `sh_` (Prawo §9 dopuszcza). `sh_meal_components.item_sku` to klucz znakowy do `sh_menu_items.ascii_key` (Prawo II §9).
- Schema-aware probes w 6 miejscach (graceful degrade dla starszych baz).

### Prawo VIII (Domknięcie Kontraktu)
- `clone_recipe` call-site: `studio_recipe.js#confirmCloneRecipe` (przycisk "Skopiuj z innej pozycji").
- `save_modifier_pricing` call-site: docelowo Studio UI modyfikatorów (F-S2.1 follow-up).
- `save_meal` call-site: docelowo Studio zakładka "Combo" (F-S3.1 follow-up).
- POS UI dla `mealPackages` i `modifier size pricing` to follow-up — backend gotowy, ale 0 sale-stoppers (legacy ścieżki działają).

### Prawo X (Audyt Sesji AI)
- Ten plik + `2026-05-11_phase_f5_pos_integrity_and_f6_geocoder.md` + `2026-05-11_phase_fs1_variant_scales.md`.

---

## 6. Plan migracji produkcji (uti.pl)

1. **Backup bazy danych** (mysqldump przed apply migracji).
2. Wgraj paczkę `slicehub_release_bundle_full.tar.gz` na `public_html/`.
3. Uruchom migracje:
   ```bash
   php scripts/apply_migrations_chain.php --apply
   ```
   Aplikowane: 047, 048, 049, 050, 051, 052.
4. **Dry-run** legacy variant migracji (sprawdza co zrobi):
   ```bash
   php scripts/migrate_parent_sku_to_variants.php
   ```
5. Jeśli OK, apply:
   ```bash
   php scripts/migrate_parent_sku_to_variants.php --apply --tenant=<your_id>
   ```
6. Otwórz Studio → sekcja "Rozmiary / Warianty" — dostrój multipliers (legacy startuje z 1.0).
7. Testy biznesowe:
   - Zamów Pizza Mała → sprawdź magazyn (powinno spaść × multiplier z opcji).
   - Pizza L + salami → sprawdź cenę (size pricing).
   - Half S+L + salami → sprawdź strategy percentage 50%.
   - Combo Deal (zdefiniuj w Studio "Combo") → sprawdź czy widoczny w POS.
   - Anuluj zamówienie po acceptance → KOR + restock + zwolnij stolik.

---

## 7. Otwarte tematy / dług techniczny

| # | Temat | Status |
|---|---|---|
| 1 | POS UI dla `mealPackages` (kafelek combo + wizard wyboru składników) | F-S3.1 follow-up |
| 2 | Studio UI dla `modifier size pricing` (matrix cen per option) | F-S2.1 follow-up |
| 3 | Multi-stage recipes (półprodukty: sos pomidorowy = osobna podreceptura) | Brak migracji — dług na F-S5 |
| 4 | Wizard "Nowa Pizza" (4-krokowy: nazwa → scale → cena bazowa → toppingi default) | UX usprawnienie, nie blocker |
| 5 | Variant scale presets („Pizza 4 rozmiary", „Coffee S/M/L") | F-S1.1 follow-up |
| 6 | F5-B hard PRICE_MISMATCH (po 2 tyg. obserwacji soft override) | F-S5 follow-up |
| 7 | Aggregator integration (Wolt/Bolt/Foodora) | Online ecosystem, osobny temat |
