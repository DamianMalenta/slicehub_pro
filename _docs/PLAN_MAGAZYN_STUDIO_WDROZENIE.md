# Plan wdrożenia — Magazyn + Studio Menu

> Jeden kanoniczny plan (2026-07-07). Handoff: `PRZEKAZANIE_NOWE_OKNO_MAGAZYN_STUDIO.md`.

## Diagnoza (skrót)

| Obszar | Stan | Ryzyko |
|--------|------|--------|
| Studio ↔ `api_menu_studio.php` | Akcje zgodne nazwami; drift w payloadach | clone, menuTree, waste |
| Magazyn ↔ `stock_list.php` | Wrapper kompletny; filtry `sys_items` niespójne z recepturami | martwe SKU w pickerach |
| Cross-silo | Tylko `sku` / `ascii_key` + `tenant_id` | OK z Konstytucją |
| Studio API client | Mieszane `apiStudio()` vs hardcoded path | deploy pod inną ścieżką |

## Model pojęć (docelowy)

| Pojęcie | Tabela / klucz | Moduł właściciel |
|---------|----------------|------------------|
| Surowiec | `sys_items.sku` | Magazyn (słownik) + stan `wh_stock` |
| Pozycja menu | `sh_menu_items.ascii_key` | Studio Menu |
| Receptura | `sh_recipes` (`menu_item_sku` → `warehouse_sku`) | Studio Receptury |
| Modyfikator | `sh_modifiers` + `linked_warehouse_sku` | Studio Modyfikatory |
| Półprodukt | `sh_recipes.is_subrecipe=1`, SKU = `ascii_key` innego dania | Studio Receptury |

## Etapy

### Etap 1 — Kontrakty krytyczne ✅ (2026-07-07)

- [x] `StudioState.menuTree` — clone receptury, warianty
- [x] `stock_list.php` — filtr `is_active` / `is_deleted` jak `get_recipes_init`
- [x] `clone_recipe` — `is_subrecipe`, `subrecipe_yield`, `display_order`
- [x] Modyfikatory — round-trip `linkedWastePercent` (API + UI grupy)

### Etap 2 — API client + UX (następny)

- [ ] `studio_api.js` — wszystkie wywołania przez `apiStudio()`
- [ ] Margin Guardian — koszt półproduktów (rekurencja lub flat expand)
- [ ] `save_recipe` — warn/block na nieistniejący `warehouseSku`
- [ ] `@planned` lub wire: visual layers, half-pricing w Studio

### Etap 3 — UI docelowe

- [ ] Jednolity flow: pozycja → receptura → food cost → modyfikatory
- [ ] Magazyn: link „otwórz w Studio” per SKU (readonly)
- [ ] Uproszczenie zbędnych opcji w inspektorze modyfikatorów

### Etap 4 — DB tylko jeśli konieczne

- Brak nowych migracji w Etapie 1.
- Ewentualne indeksy / widoki po audycie wydajności `get_menu_tree`.

## Ryzyka

- Zmiana filtra `stock_list` może ukryć SKU używane w starych recepturach → OK (świadomie).
- Centralizacja API w Studio = duży diff — robić plik po pliku.

## Test E2E (Etap 1)

1. Studio → Receptury → „Klonuj z…” — lista pozycji niepusta
2. Studio → Modyfikatory → zapisz waste % → przeładuj grupę — wartość wraca
3. `GET /api/warehouse/stock_list.php` — brak `is_deleted=1` pozycji
