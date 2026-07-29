# Studio Module — Dead Code & Orphaned API Actions Audit

**Data:** 2026-07-28  
**Pliki audytowane:** `modules/studio/js/studio_*.js`, `api/backoffice/api_menu_studio.php`

---

## 1. Orphaned API Actions in `api_menu_studio.php`

### W pełni osierocone (implementacja istnieje, żaden frontend nie wywołuje)

| # | Akcja | Linia | Opis | Powód osierocenia |
|---|-------|-------|------|-------------------|
| 1 | `get_modifier_visual` | 569 | Pobranie slotów wizualnych pojedynczego modyfikatora | Nadpisane przez `get_modifiers_full` (zwraca `visualSlots` per opcja) |
| 2 | `save_modifier_visual` | 631 | Zapis slotów wizualnych pojedynczego modyfikatora | Nadpisane przez `save_modifier_group` (obsługa assetów inline via `syncModifierVisualAssetLinks`) |
| 3 | `get_visual_layers` | 2345 | Pobranie warstw wizualnych + SKU dla dania | Legacy Exploded View; online_studio używa innych endpointów |
| 4 | `save_visual_layer` | 2521 | Upsert pojedynczej warstwy wizualnej | Jw. — brak frontendu |
| 5 | `delete_visual_layer` | 2559 | Usunięcie warstwy wizualnej | Jw. — brak frontendu |
| 6 | `save_board_companion` | 2594 | Upsert companion link | Brak wywołania w całym codebase |
| 7 | `delete_board_companion` | 2640 | Usunięcie companion link | Brak wywołania w całym codebase |
| 8 | `get_global_assets` | 2687 | Pobranie fotorealistcznych assetów z `sh_global_assets` | Brak frontendu; online_studio używa `sh_assets` via `list_assets_compact` |
| 9 | `get_half_pricing_settings` | 2954 | Odczyt strategii half-pricing z tenant settings | Brak frontendu |
| 10 | `save_half_pricing_settings` | 2995 | Zapis strategii half-pricing | Brak frontendu |
| 11 | `get_price_mismatch_mode` | 2969 | Odczyt flagi price_mismatch_mode | Brak frontendu |
| 12 | `set_price_mismatch_mode` | 2980 | Ustawienie flagi price_mismatch_mode | Brak frontendu |

### Defensive stubs (już usunięte, rzucają błąd)

| # | Akcja | Linia | Notatka |
|---|-------|-------|---------|
| 13 | `get_board_context` | 2672 | Rzuca błąd — usunięte w m025 |
| 14 | `get_ingredient_assets` | 2676 | Rzuca błąd — usunięte w m025 |
| 15 | `save_ingredient_asset` | 2677 | Rzuca błąd — usunięte w m025 |
| 16 | `delete_ingredient_asset` | 2678 | Rzuca błąd — usunięte w m025 |

### Aktywne akcje API (używane przez co najmniej jeden frontend)

| Akcja | Wywołana z |
|-------|------------|
| `add_category` | `studio_ui.js:399` |
| `update_category` | `studio_ui.js:420` |
| `list_scene_templates` | `studio_core.js:48`, `online_studio/js/studio_api.js:219` |
| `list_assets_compact` | `studio_modifiers.js:19`, `online_studio/js/studio_api.js:222` |
| `set_item_hero` | `studio_item.js:1077` |
| `unlink_item_hero` | `studio_item.js:1103` |
| `autogenerate_scene` | `studio_item.js:1155` |
| `get_category_scene_editor` | `studio_category_table.js:30` |
| `save_category_scene_layout` | `studio_category_table.js:212` |
| `get_menu_tree` | `studio_core.js:4` |
| `get_item_details` | `studio_ui.js:206`, `studio_item.js:1648,1679,1698` |
| `add_item` | `studio_item.js:869,1593` |
| `update_item_full` | `studio_item.js:869,1650,1681,1700` |
| `save_bulk` | `studio_bulk.js:26` |
| `save_modifier_quick` | `studio_modifiers.js:1073` |
| `save_modifier_group` | `studio_modifiers.js:1235` |
| `get_modifiers_full` | `studio_modifiers.js:381` |
| `get_recipes_init` | `studio_recipe.js:15`, `studio_modifiers.js:361` |
| `get_item_recipe` | `studio_recipe.js:66` |
| `list_subrecipe_candidates` | `studio_recipe.js:661` |
| `save_recipe` | `studio_recipe.js:954` |
| `clone_recipe` | `studio_recipe.js:1055` |
| `list_meals` | `studio_meals.js:16` |
| `get_meal_details` | `studio_meals.js:111` |
| `save_meal` | `studio_meals.js:162` |
| `delete_meal` | `studio_meals.js:178` |
| `list_variant_scales` | `studio_item.js:1728`, `studio_modifiers.js:1324` |
| `save_variant_scale` | `studio_item.js:1896` |
| `delete_variant_scale` | `studio_item.js:1916` |
| `get_modifier_pricing` | `studio_modifiers.js:1325` |
| `save_modifier_pricing` | `studio_modifiers.js:1412` |
| `create_variant_family` | `studio_item.js:1290,1622` |
| `get_scene_kit` | `online_studio/js/studio_api.js:220` |
| `save_scene_kit` | `online_studio/js/studio_api.js:221` |

---

## 2. Orphaned JS Functions

### `studio_modifiers.js`

- **`_buildAssetSelectOptions(selectedId)`** — linia 29
  - Zdefiniowana, ale nigdzie nie wywoływana
  - Prawdopodobnie część starego pickera opartego na `<select>`, zastąpionego przez modal (`openAssetPickerModal`)

### `studio_margin.js`

- **`bindReactivity(updateFn, opts)`** — linia 354
  - Eksportowana w publicznym API (`window.MarginGuardian.bindReactivity`), ale nigdzie nie wywoływana
  - Miała automatycznie podpinać listenery DOM + debounced recalculation; `studio_recipe.js` wywołuje `calculate`/`render` ręcznie przez `_triggerMarginUpdate()`

- **`_debounce(fn, ms)`** — linia 383
  - Wywoływana tylko przez `bindReactivity`, więc tranzytywnie osierocona

---

## 3. Podsumowanie liczbowe

| Kategoria | Liczba |
|-----------|--------|
| Orphaned API actions (pełna implementacja, brak wywołania) | 12 |
| Defensive stubs (rzucają błąd, już usunięte w m025) | 4 |
| Orphaned JS functions | 3 (`_buildAssetSelectOptions`, `bindReactivity`, `_debounce`) |
| Aktywne API actions | 33 |
| Aktywne JS functions | wszystkie pozostałe (zweryfikowane) |

---

## 4. Proponowany kierunek

### Faza 1 — Bezpieczne usunięcie (low risk, high cleanup)

1. **Usunąć 12 orphaned API case'ów** z `api_menu_studio.php` (pozycje 1–12 z tabeli wyżej)
2. **Usunąć 4 defensive stubs** (pozycje 13–16 — od m025 rzucają tylko błędy)
3. **Usunąć `_buildAssetSelectOptions`** z `studio_modifiers.js`
4. **Usunąć `bindReactivity` + `_debounce`** z `studio_margin.js` (oraz z exportu publicznego)

### Faza 2 — Weryfikacja tabel bazodanowych (medium risk)

Sprawdzić czy tabele powiązane z usuniętymi akcjami są jeszcze używane przez inne części systemu:
- `sh_visual_layers` — używana przez `api/online/engine.php` (get_dish, get_scene_dish) → **NIE usuwać tabeli**, tylko endpointy CRUD z Studio
- `sh_board_companions` — używana przez `get_visual_layers` (czytanie) → sprawdzić czy online/engine czyta
- `sh_global_assets` — sprawdzić czy `api_visual_studio.php` lub online_studio używa
- `sh_modifier_pricing` — **AKTYWNA** (używana przez `get_modifier_pricing`/`save_modifier_pricing`), nie ruszać

### Faza 3 — Konsolidacja (optional, long-term)

- `get_modifier_visual` / `save_modifier_visual` — już nadpisane przez `get_modifiers_full` / `save_modifier_group`, usunięcie potwierdza SSOT
- Half-pricing i price-mismatch settings — jeśli planowane na przyszłość, przenieść do osobnego endpointu ustawień tenant (np. `api/settings/engine.php`) zamiast trzymać w Menu Studio
