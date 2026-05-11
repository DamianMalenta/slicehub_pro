# AI Session Audit — F-S1 Variant Scales Model (Mała / Średnia / Duża)

**Data:** 2026-05-11
**Branch:** `projektx/phase-fs1-variant-model-c3d7`
**Konstytucja:** v5 (zakotwiczenia: Prawo II — Bliźniak Cyfrowy, Prawo VI — Snajper, Prawo X — Audyt Sesji)
**Trigger:** Wniosek użytkownika „ruszaj F-S1" w odpowiedzi na audyt Studio Menu (poprzednia sesja).

---

## 1. Cel

Wprowadzić **iiko-style model rozmiarów** dla menu items. Przed F-S1:
- Pizza Margherita Mała / Średnia / Duża = TRZY osobne rekordy `sh_menu_items` z osobnymi `ascii_key`, osobnymi recepturami, osobnymi wpisami w `sh_price_tiers`.
- Spinał je tylko tekstowy `parent_sku` bez FK i bez walidacji.

Po F-S1:
- **JEDEN parent** (`is_variant_parent=1`, niesprzedawalny w POS) z `variant_scale_id`.
- **JEDNA receptura** na parent — WzEngine mnoży przez `multiplier(option)` (Mała=0.7, Średnia=1.0, Duża=1.3).
- **Wiele children** (`parent_item_id` FK, `variant_option_id` FK) — każdy z własnym SKU i własną ceną.
- POS pokazuje JEDEN kafelek z parenta → modal wyboru rozmiaru → child SKU trafia do koszyka.

---

## 2. Zakres zmian

| Plik | Zmiana |
|---|---|
| `database/migrations/048_variant_scales.sql` | **NEW**: tabele `sh_variant_scales`, `sh_variant_scale_options` + ALTER `sh_menu_items` (4 nowe kolumny + 4 FK + 3 indeksy) |
| `scripts/_migrations_chain.php` | Chain entry `048_variant_scales.sql` |
| `core/WzEngine.php` | Schema-aware SELECT — pobiera `parent_recipe_sku` + `variant_multiplier` z LEFT JOIN. `recipeSkuSet` używa parent SKU dla wariantów. `aggregateRecipes` mnoży przez `variant_multiplier` (1.0 dla zwykłych). |
| `api/backoffice/api_menu_studio.php` | 5 nowych akcji: `list_variant_scales`, `save_variant_scale`, `delete_variant_scale`, `create_variant_family`. `add_item`/`update_item_full` przyjmuje `variantScaleId`+`isVariantParent`. `get_item_details` zwraca variant info. |
| `modules/studio/js/studio_item.js` | Nowa sekcja UI `Rozmiary / Warianty` z dropdown + modal `openVariantScaleManager` (CRUD skal). Anchor nav: `sec-variants`. `generateVariantFamily()` → najpierw save parent, potem `create_variant_family`. |
| `api/pos/engine.php#get_init_data` | Schema-aware SELECT — ukrywa parentów (`is_variant_parent=1`). Dodaje `variantGroups` (parent → lista wariantów) do payload. |
| `modules/pos/js/pos_app.js` | `_renderMenu` deduplikuje children variant family (pokazuje 1 ambassador per parent). `_openVariantPicker` modal — Lightspeed-style wybór rozmiaru przed dish card. |
| `scripts/migrate_parent_sku_to_variants.php` | **NEW**: one-time migracja legacy `parent_sku` (tekstowy) → wariant family. DRY-RUN domyślnie, `--apply` żeby zapisać. |
| `_docs/02_ARCHITEKTURA.md` | Bump kompilacji + nowy wpis. |

---

## 3. Schema

```sql
sh_variant_scales:
  id, tenant_id, name, key_ascii, description, is_active, is_deleted, ...
  UNIQUE (tenant_id, key_ascii)

sh_variant_scale_options:
  id, scale_id, tenant_id, name, key_ascii, display_order,
  diameter_cm, multiplier DECIMAL(6,3), is_default, is_deleted, ...
  UNIQUE (scale_id, key_ascii)

sh_menu_items (ALTER):
  + variant_scale_id BIGINT UNSIGNED NULL  -- tylko na parentach
  + is_variant_parent TINYINT(1) DEFAULT 0  -- 1 = niesprzedawalny w POS
  + parent_item_id    BIGINT UNSIGNED NULL  -- FK do parent sh_menu_items.id
  + variant_option_id BIGINT UNSIGNED NULL  -- FK do sh_variant_scale_options.id
```

Wszystkie FK z `ON UPDATE CASCADE ON DELETE SET NULL` — żeby usunięcie skali nie kasowało itemów.

---

## 4. Decyzje konstytucyjne

### Prawo II — Bliźniak Cyfrowy
- Receptura wpisana **RAZ na parenta** odzwierciedla rzeczywisty fakt biznesowy: „pizza Margherita w wersji bazowej (M) wymaga 250g mąki". Mała = ×0.7, Duża = ×1.3.
- Skala jest **reużywalna między pizzami** (jeśli wszystkie używają tej samej rozmiarówki).

### Prawo VI — Snajper
- Cross-silo? `parent_item_id` jest FK po ID **w obrębie tego samego silosu `sh_`** (`sh_menu_items` → `sh_menu_items`). Prawo VI dopuszcza ID joiny **wewnątrz silosu** — patrz §9 Konstytucji.
- WzEngine zachowuje wzorzec `tenant_id = ?` na wszystkich JOIN-ach (parent_mi, opt).
- Schema-aware probe w 4 miejscach (WzEngine, get_item_details, add_item/update_item, POS get_init_data) — żeby branch działał także bez migracji 048 (graceful degrade do trybu standalone).

### Prawo VIII — Domknięcie Kontraktu
- `create_variant_family` → call-site: `studio_item.js#generateVariantFamily` (button "Wygeneruj rodzinę").
- `_openVariantPicker` → call-site: `pos_app.js#_onItemClick` (gdy `_isVariantAmbassador`).
- Skala bez itemów = niewidoczna w POS (item parent jest filtrowany). Manager widzi w Studio.

### Prawo X — Audyt Sesji AI
- Ten plik.

---

## 5. Test E2E (sandbox MariaDB)

**WzEngine multiplier:**
```
Scenario A: Mała 1×    → FS1_FLOUR deducted = 0.175 (0.250 × 0.7 × 1) ✅
Scenario B: Duża 2×    → FS1_FLOUR deducted = 0.650 (0.250 × 1.3 × 2) ✅
Scenario C: Średnia 1× → FS1_FLOUR deducted = 0.250 (0.250 × 1.0 × 1 baseline) ✅
```

**Studio API:**
```
✅ save_variant_scale: 3 options (S=0.7, M=1.0, L=1.3) saved
✅ list_variant_scales: returns scales with nested options
✅ create_variant_family: 3 children linked to parent + variant_option_id
```

**Migracja legacy:**
```
Found 1 parent_sku group(s)
--- tenant=1 parent_sku='LEGACYTEST' (3 children: ...)
    parent missing — creating stub (is_variant_parent=1)
    creating scale LEGACY_LEGACYTEST
✅ APPLIED: 1 parent, 1 scale, 3 children linked
```

**Migracja 048 syntax:** ✅ aplikowana w sandbox po fix.

---

## 6. UX flow w Studio (po F-S1)

1. Menedżer otwiera Studio → klika „Nowa pozycja" w kategorii Pizze.
2. Wypełnia nazwę: `Pizza Margherita`, klucz `PIZZA_MARGHERITA`.
3. W sekcji **Rozmiary / Warianty** wybiera ze skali „Rozmiary pizzy" (lub klika „Zarządzaj skalami" żeby dodać nową).
4. Po wyborze skali, przycisk „Wygeneruj rodzinę" staje się aktywny po pierwszym save.
5. Klik „Wygeneruj rodzinę" → backend tworzy `PIZZA_MARGHERITA_S`, `_M`, `_L` z `parent_item_id` + `variant_option_id`.
6. Menedżer wraca do każdego child żeby ustawić cenę per kanał (POS/Takeaway/Delivery).
7. Receptura: w `studio_recipe.js` wpisuje JEDNĄ recepturę na parent → WzEngine mnoży automatycznie.

## 7. UX flow w POS (po F-S1)

1. POS pobiera menu z `get_init_data` → parentowie są ukrywani (`is_variant_parent=1`).
2. Children z `parentAsciiKey != NULL` są dedupowane: 1 kafelek per parent (z nazwą parenta).
3. Klik w kafelek → modal `_openVariantPicker` pokazuje listę rozmiarów (z cenami).
4. Klik w rozmiar → `_openDishCard(chosenVariant)` — reszta jak normalnie.
5. WzEngine konsumuje receptura parenta × multiplier(option).

---

## 8. Otwarte ryzyka / dług

| Ryzyko | Mitygacja / dług |
|---|---|
| Skala bez `tenant_id` cross-ref → możliwość scale_id wskazującego scale innego tenanta? | `fk_option_scale` na `scale_id` + `tenant_id` na obu stronach (denormalized). API zawsze filtruje `tenant_id = ?`. |
| Stare itemy z `parent_sku` ale BEZ recepty na parent | Skrypt migracji tworzy stub parenta bez receptury — manager musi dopisać. Children-tylko działają legacy (każdy ma swoją recepturę). |
| Multiplier przyjmuje 1.0 dla legacy migracji | Świadome — admin dostraja w UI po fakcie. Lepsza UX = pre-fill 0.7/1.0/1.3 jeśli sufiks pasuje (S/M/L). To dług F-S1.1. |
| POS UI: brak `priceTiers` per variant w `variantGroups` | Każdy child ma własne `priceTiers` w `items[]` — variantGroups to tylko metadane. `_openVariantPicker` używa `siblings[].priceGrosze` (już skalibrowane przez channel). |
| Brak topping size pricing (Toast-style) | F-S2 (osobna sesja). |
| Brak combo/bundle | F-S3 (osobna sesja). |

---

## 9. Następne kroki

- **F-S1.1** — Variant scale presets („Pizza 4 rozmiary", „Coffee S/M/L") + auto-multiplier z sufiksu klucza dla legacy migracji.
- **F-S2** — Topping size pricing (`sh_modifier_pricing` per `variant_option_id`).
- **F-S3** — Combo / bundle / meal (`sh_meal_packages`).
- **F-S4** — Naprawa drift-ów z poprzedniego audytu (Live vs published, valid_from DATETIME, save_bulk VAT).
