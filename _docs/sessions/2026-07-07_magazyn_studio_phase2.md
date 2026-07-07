# Sesja: Magazyn + Studio — Etap 2

**Data:** 2026-07-07  
**Branch:** `cursor/magazyn-studio-phase2-d950`

## Cel

Etap 2 planu: centralny klient API Studio, koszt półproduktów w Margin Guardian, walidacja SKU w `save_recipe`.

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `modules/studio/js/studio_api.js` | **NOWY** — `StudioApi` / `apiStudio()` |
| `modules/studio/index.html` | load `studio_api.js` przed `studio_core.js` |
| `modules/studio/js/studio_core.js` | usunięto duplikat `apiStudio` |
| `modules/studio/js/studio_*.js` | migracja z hardcoded `../../api/...` |
| `modules/studio/js/studio_margin.js` | `ensureRecipeCosts()` — subrecipe AVCO |
| `api/backoffice/api_menu_studio.php` | `save_recipe` skip orphan SKU |

## Decyzje

1. `apiStudio(action, payload)` — jedyny entry point do `api_menu_studio.php`.
2. Subrecipe cost: rekurencyjny `get_item_recipe` + cache `batchCostCache`, max depth 8.
3. Orphan surowiec: skip + `skipped[]` w odpowiedzi (jak półprodukt).

## Otwarte (Etap 3)

- UI flow docelowy, link Magazyn→Studio
- visual layers / half-pricing `@planned`

## Test (E2E)

- `grep api_menu_studio` w `modules/studio/js` — tylko `studio_api.js`
- `php -l api_menu_studio.php`
- Manual: Margin Guardian z linią półproduktu pokazuje koszt > 0
