# AI Session Audit — Follow-ups: F-S2.1 + F-S3.1 + F-S5 + F-S6

**Data:** 2026-05-11
**Branch:** `projektx/release-bundle-c3d7` (continuation)
**Konstytucja:** v5 (Prawa II, VIII, X)
**Trigger:** „wyslij wszystko do repo i ruszaj dalej konczymy wszystko po kolei."

---

## 1. Zakres

Cztery follow-up'y zamykające otwarte tematy z poprzednich sesji (F-S2/F-S3/F-S4):

| Faza | Co | Migracje |
|---|---|---|
| **F-S2.1** | Studio UI dla `sh_modifier_pricing` — macierz cen modyfikatora per `variant_option_id` | — (UI) |
| **F-S3.1** | POS UI dla `sh_meal_packages` — kafelek combo + wizard wyboru składników | — (UI) |
| **F-S5** | Multi-stage recipes (półprodukty, iiko-style „заготовки") | `053_subrecipes.sql` |
| **F-S6** | Wizard „Nowa Pizza" — 4-step modal w Studio (nazwa → skala → ceny → generuj) | — (UI) |

---

## 2. F-S2.1 — Studio UI Size Pricing

### Lokalizacja
`modules/studio/js/studio_modifiers.js`: w panelu `advanced-panel` opcji modyfikatora pojawia się przycisk **"Cennik per rozmiar (F-S2.1)"** — aktywny gdy modyfikator ma `id > 0` (po pierwszym zapisie grupy).

### Modal
- Pobiera **wszystkie** skale tenanta + aktualne `sh_modifier_pricing` dla modyfikatora.
- Renderuje per skala: card z gridem opcji (S/M/L), każdy z input numerycznym `step="0.01"`.
- Zapisuje wyłącznie wypełnione pola — puste = soft-delete row (backend).

### Backend (już zrobiony w F-S2)
- `get_modifier_pricing` zwraca `[{variant_option_id, price_grosze, option_name, scale_id, ...}]`.
- `save_modifier_pricing` upsert + soft-delete row NOT IN payload.

---

## 3. F-S3.1 — POS UI Combo

### Lokalizacja
`modules/pos/js/pos_app.js`:
- `_menuData.mealPackages` ← z payload `get_init_data` (już zwracane przez F-S3 backend).
- `_renderMenu()` dołącza wirtualne kafelki combo do bieżącej kategorii (prefiks emoji 🍔).
- `_onItemClick` rozpoznaje `_isMealPackage` i otwiera `_openMealWizard`.

### Wizard
- **Fixed components** wyświetlane jako lista read-only („W zestawie: 1× PIZZA_FAMILY_L, 2× FRIES_L, 1× COLA_2L").
- **Category choices** (component_type='category_choice') jako `<select>` z opcjami z items danej kategorii.
- Walidacja: wszystkie selecty muszą mieć wybór.
- 1 linia w `PosCart` z meta_json `Combo #<meal_id> | picks: ...`.

### Otwarty dług
- `WzEngine` jeszcze nie expanduje meal_id → fixed_items + picks. To **F-S3.2** follow-up (osobny PR).
- Pricing: combo ma jedną cenę (`final_price_grosze`), nie sumę składników. Wystarczające dla MVP.

---

## 4. F-S5 — Multi-stage recipes (półprodukty)

### Schema (`053_subrecipes.sql`)
```sql
ALTER TABLE sh_recipes
    ADD COLUMN is_subrecipe TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN subrecipe_yield DECIMAL(10,4) NOT NULL DEFAULT 1.0000;
```

Semantyka:
- `is_subrecipe = 1`: `warehouse_sku` wskazuje na `ascii_key` **innej pozycji menu** (nie surowiec).
- `subrecipe_yield`: ile **porcji** półproduktu produkuje 1 batch. Np. 1 garnek sosu = 20 porcji do pizzy → yield = 20.

### WzEngine
- `resolveSubrecipesRecursive(pdo, tenantId, recipesByItem, maxDepth=3)`:
  - BFS-style: zbiera wszystkie `warehouse_sku` linii z `is_subrecipe=1`, fetchuje ich receptury, powtarza max 3 razy (anti-cycle A→B→A).
- `aggregateRecipes(..., $subrecipesCache)`:
  - Gdy linia ma `is_subrecipe=true`, oblicza `batchesNeeded = effectiveQty / subrecipe_yield`.
  - Rekurencyjnie wywołuje siebie z linią półproduktu i `batchesNeeded` jako lineQty.
  - Cache dzielony między wywołaniami unika powtórnych zapytań SQL.

### Test E2E
```
Setup:
  Sos: 1kg tomato + 0.05kg garlic + 0.1kg oil (yield=20 porcji)
  Pizza: 0.25kg flour + 1 porcja sosu (is_subrecipe=1)

1× Pizza zamówienie → consumeForOrder:
  FLOUR  = 0.250 (direct)
  TOMATO = 0.050 (1.0 × 1/20 batchu sosu)
  GARLIC = 0.0025 (0.05 × 1/20)
  OIL    = 0.005 (0.1 × 1/20)
✅ PASS
```

### Konstytucja v5 § Prawo II
Bliźniak cyfrowy: w prawdziwej kuchni pizza zużywa półprodukt sos, który zużywa surowce. WzEngine teraz odzwierciedla to dokładnie.

### Otwarte dług
- Studio UI dla `is_subrecipe` checkbox + yield input w `studio_recipe.js` — to F-S5.1 follow-up. Backend działa, można setować przez SQL ad-hoc lub przez `clone_recipe`.
- Wizualizacja dependencies (graph view) — opcjonalne UX.

---

## 5. F-S6 — Wizard „Nowa Pizza" (4-step)

### Krok 1 — Nazwa
- Input nazwy + auto-slug ASCII (z `toAutoSlug`)
- Wybór kategorii (z `StudioState.categories`)
- Opis opcjonalny

### Krok 2 — Skala rozmiarów
- Dropdown z `list_variant_scales`
- Live preview wybranej skali (opcje + multiplier)
- Link „Utwórz nową skalę" → otwiera `openVariantScaleManager`

### Krok 3 — Ceny
- Per opcja skali: input POS (PLN brutto)
- Auto-fill Takeaway = POS, Delivery = POS × 1.10

### Krok 4 — Generuj
- Podsumowanie wszystkich danych
- Klik „Generuj rodzinę":
  1. `add_item` (parent z `isVariantParent=1`, `variantScaleId=<id>`, ceny 0).
  2. `create_variant_family` (generuje children per opcja).
  3. Dla każdego child: `get_item_details` → `update_item_full` z `priceTiers` z wizardu.
  4. Reload tree.

### Lokalizacja przycisku
Drzewo kategorii (`modules/studio/js/studio_ui.js`) — przycisk **🍕 Pizza** obok **+ Nowe**.

### Otwarty dług
- Step 5 hipotetyczny: dodawanie default toppingów (modifier_groups). Nie zaimplementowane bo modifier groups to osobny moduł — manager dorzuca po kreatorze.

---

## 6. Konstytucja v5 — zakotwiczenia

### Prawo II (Bliźniak Cyfrowy)
- F-S5: subrecipe expansion = real dependency tree z kuchni.
- F-S6: wizard generuje real warianty z real cenami od razu.

### Prawo VIII (Domknięcie Kontraktu)
- F-S2.1 `save_modifier_pricing` call-site: button "Cennik per rozmiar" w studio_modifiers.js. ✅
- F-S3.1 mealPackages call-sites: `_renderMenu` (display) + `_openMealWizard` (interaction). ✅
- F-S5 subrecipe call-site: `resolveSubrecipesRecursive` w consumeForOrder. ✅
- F-S6 wizard call-site: button "Pizza" w drzewie kategorii (studio_ui.js). ✅

### Prawo X (Audyt Sesji AI)
- Ten plik.

---

## 7. Pełna lista plików zmienionych w tej sesji

```
database/migrations/053_subrecipes.sql        NEW
scripts/_migrations_chain.php                  053 entry
core/WzEngine.php                              resolveSubrecipesRecursive + aggregateRecipes rekurencja
modules/studio/js/studio_modifiers.js          openSizePricingModal + _saveSizePricing
modules/studio/js/studio_item.js               openNewPizzaWizard + _fs6Step/_fs6Generate
modules/studio/js/studio_ui.js                 przycisk "Pizza" w drzewie
modules/pos/js/pos_app.js                      _menuData.mealPackages + _openMealWizard
```

---

## 8. Co jeszcze zostało (otwarty dług)

| Temat | Priorytet | Faza |
|---|---|---|
| `WzEngine` expansion combo (meal_id → fixed_items + picks) z konsumpcją magazynu per składnik | P1 | F-S3.2 |
| Studio UI checkbox `is_subrecipe` + yield input w `studio_recipe.js` | P2 | F-S5.1 |
| F-S6 step 5: domyślne modifier_groups | P3 | F-S6.1 |
| Variant scale presets (auto-multiplier z sufiksu klucza dla legacy migracji) | P3 | F-S1.2 |
| Hard PRICE_MISMATCH (po 2 tyg. obserwacji soft override) | P2 | F-S5 |
| F5-B: cancel order z pickup payment → audit jak handle | P3 | F-S5.2 |
