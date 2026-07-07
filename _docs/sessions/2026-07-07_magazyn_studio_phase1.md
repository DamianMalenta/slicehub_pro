# Sesja: Magazyn + Studio — Etap 1 kontraktów

**Data:** 2026-07-07  
**Branch:** `cursor/magazyn-studio-phase1-d950`

## Cel

Pierwszy etap planu `PLAN_MAGAZYN_STUDIO_WDROZENIE.md`: naprawa krytycznych rozjazdów kontraktów Studio ↔ Magazyn bez nowych migracji SQL.

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `modules/studio/js/studio_core.js` | `menuTree` + `getItemsGrouped()` |
| `api/backoffice/api_menu_studio.php` | `parentItemId` w tree, clone_recipe F-S5/F-S9, modifier waste |
| `api/warehouse/stock_list.php` | filtr aktywnych `sys_items` |
| `modules/studio/js/studio_modifiers.js` | waste % w wierszu opcji grupy |
| `_docs/PLAN_MAGAZYN_STUDIO_WDROZENIE.md` | **NOWY** — plan wdrożenia |
| `_docs/sessions/2026-07-07_magazyn_studio_phase1.md` | ten plik |

## Decyzje

1. `menuTree` budowany client-side z flat `categories` + `items` (bez zmiany kształtu API response poza `parentItemId`).
2. Filtr magazynu zgodny z `get_recipes_init` — ukrywamy nieaktywne/usunięte SKU.
3. `clone_recipe` — probe kolumn jak `save_recipe` (graceful na legacy DB).

## Otwarte (Etap 2)

- `studio_api.js` centralizacja
- Margin Guardian + subrecipe costing
- UI flow docelowy

## Test (E2E)

Manual: Studio clone recipe modal, modifier waste reload, stock_list count vs recipes_init.
