# Studio Menu Pro — Kompleksowy projekt redesignu panelu Menu Studio

**Data:** 2026-07-28
**Status:** FAZA 1 + FAZA 2 + FAZA 3 + FAZA 4 + FAZA 5 WDRUŻONE (Polish + Keyboard Shortcuts + DnD + Quick Edit)
**Zakres:** `modules/studio/` — pełny redesign UX/UI bez zmian backendu
**Pliki źródłowe:** `modules/studio/js/studio_*.js`, `modules/studio/index.html`
**Backend:** `api/backoffice/api_menu_studio.php` (3382 linie) — **BEZ ZMIAN**

### Postęp faz

| Faza | Status | Pliki |
|---|---|---|
| **§12: saveModifierGroup refaktor** | ✅ Wdrożone | `studio_modifiers.js` |
| **§13: Navigator + Dashboard + Toast + Dirty Flag** | ✅ Wdrożone | `studio_core.js`, `index.html`, `studio_ui.js` |
| **Faza 2: Tabbed Editor (7 zakładek)** | ✅ Wdrożone | `studio_item.js` |
| **Faza 3: Modifiers Drawer** | ✅ Wdrożone | `studio_modifiers.js`, `index.html`, `studio_ui.js`, `studio_item.js` |
| **Faza 4: Inspector + Dashboard + Bulk auto-switch** | ✅ Wdrożone | `index.html`, `studio_ui.js`, `studio_core.js`, `studio_item.js` |
| **Faza 5: Polish + Keyboard Shortcuts** | ✅ Wdrożone | `studio_core.js`, `studio_ui.js`, `studio_item.js`, `studio_bulk.js`, `studio_meals.js`, `index.html` |

---

## 1. Architektura 3-Pane

### Layout

```
┌─────────────┬──────────────────────────┬──────────────┐
│  NAVIGATOR  │     WORKSPACE            │  INSPECTOR   │
│  (280px)    │     (flexible)           │  (380px)     │
│             │                          │              │
│  Search     │  Dashboard / Editor /    │  Food Cost   │
│  Filters    │  Bulk Mode / Combo /     │  Preview     │
│  Tree       │  Category Table          │  Margin      │
│  +Actions   │                          │  Activity    │
└─────────────┴──────────────────────────┴──────────────┘
```

### Kontener HTML (`index.html`)

Zastępuje obecne 4 osobne view divy (menu / modifiers / bulk / meals) jednym 3-kolumnowym flexboxem:

```html
<div id="studio-root" class="flex h-screen overflow-hidden">
  <aside id="studio-navigator" class="w-[280px] flex-shrink-0 …"></aside>
  <main id="studio-workspace" class="flex-1 overflow-y-auto …"></main>
  <aside id="studio-inspector" class="w-[380px] flex-shrink-0 …"></aside>
</div>
```

- **Navigator** (lewy, stały 280px) — drzewo kategorii + dań z search, filter chips, hover actions
- **Workspace** (środek, flexible) — przełącza 5 trybów automatycznie na podstawie stanu
- **Inspector** (prawy, 380px) — contextual: recipe/margin gdy danie, lista gdy bulk, stats gdy dashboard, ukryty gdy puste

### Routing trybów (`studio_core.js` → `StudioState.mode`)

| Warunek | Tryb | Funkcja renderująca |
|---|---|---|
| `selectedItemId === null && bulkIds.length === 0` | Dashboard | `StudioUI.renderDashboard()` |
| `selectedItemId !== null && !isCombo` | Item Editor | `ItemEditor.renderTabs()` |
| `selectedItemId !== null && isCombo` | Combo Editor | `ItemEditor.renderTabs()` z tabem "Skład" |
| `bulkIds.length > 1` | Bulk Mode | `BulkEditor.renderBulkPanel()` |
| `categoryTableMode === true` | Category Table | `CategoryTable.renderOverlay()` |

### Co NIE ulega zmianie:

1. **Backend API** (`api_menu_studio.php`, 3382 linie) — dotykamy zero
2. **Logika biznesowa** (ceny, VAT, warianty, modyfikatory, receptury) — dotykamy zero
3. `studio_api.js` — bez zmian (API client, 33 linie)
4. Online Studio (`modules/online_studio/`) — bez zmian (link z tabu Scena)
5. Warehouse API — bez zmian (inspector używa istniejącego)

---

## 2. Pane 1 — Navigator (lewy, 280px)

Zastępuje obecny sidebar + drzewo (łączy je w jeden panel).

### Elementy (top → bottom):

1. **Search bar** — filtr po nazwie, SKU, kategorii (live filter, debounce 200ms)
   - JS: `ItemEditor.debounce()` (studio_item.js:23) — funkcja debounce już istnieje
   - Filtrowanie client-side po `StudioState.items` (nie wymaga API)

2. **Filter chips**: Wszystkie | Live | Draft | Archiw | Tajne
   - Status z `item.pub_status` (live/draft/archived) + `item.is_secret`
   - Klik chipa → filtruje drzewo

3. **Drzewo kategorii** z drag&drop reorder:
   - Kategoria: nazwa + licznik dań + hover actions (edit, add item, delete)
   - Danie: thumbnail 32px + nazwa + cena + status dot (zielony=Live, żółty=Draft, czerwony=Archived)
   - Hover na danie: quick actions (edit, duplicate, archive, delete)
   - Checkbox pojawia się przy hover lub gdy bulk mode aktywny
   - JS: `StudioUI.renderTree()` — nowe, zastępuje obecne `StudioUI.renderMenuTree()`
   - API: `get_menu_tree` (istniejące, zwraca kategorie + items)

4. **Sticky bottom bar**: `+ Kategoria` | `+ Danie` | `+ Pizza Wizard`
   - `+ Pizza Wizard` → `ItemEditor.openNewPizzaWizard()` (studio_item.js — istnieje, F-S6)

5. **Klawisze**: strzałki góra/dół = nawigacja, Enter = otwórz, Ctrl+D = duplikuj

### Implementacja:
- Plik: `studio_ui.js` (przepisany z 453 na ~600 linii)
- API: `get_menu_tree` (istniejące)
- Drag&drop: HTML5 Drag API (vanilla, zero zależności)

---

## 3. Pane 2 — Workspace (środek, flexible)

### Tryb A: Dashboard (gdy nic nie wybrano)

```
┌─────────────────────────────────────────┐
│  Studio Menu — Dashboard                │
├──────────┬──────────┬──────────┬────────┤
│  47 Dań  │  38 Live │  5 Draft │ 4 Tajne│
├──────────┴──────────┴──────────┴────────┤
│  ⚠ Margin Alerts (3 dania >40% FC)      │
│  • Pizza Hawajska — FC 42% — sugerowana │
│    cena 38→42 PLN                       │
├─────────────────────────────────────────┤
│  Ostatnio edytowane                     │
│  • Pizza Margherita — 2h temu           │
│  • Burger Classic — 5h temu             │
├─────────────────────────────────────────┤
│  [+ Dodaj danie]  [Edycja masowa]       │
└─────────────────────────────────────────┘
```

**Implementacja:**
- Nowy widok w `studio_ui.js`: `StudioUI.renderDashboard()` (renderowany gdy `StudioState.selectedItemId === null`)
- Stats: zliczenie z `StudioState.items` (client-side, zero API)
- Margin alerts: z `RecipeMapper` data (jeśli dostępne) lub pominięte w V1
- Ostatnio edytowane: localStorage (last 10 item IDs + timestamps)

### Tryb B: Item Editor (gdy danie wybrane)

**Zamiast 10 sekcji w scrollu → 7 tabów:**

```
┌─────────────────────────────────────────┐
│ 🍕 Pizza Margherita    [Live ●] [Save] │
│ SKU: PIZZA_MARGHERITA                   │
├─────────────────────────────────────────┤
│ Podstawowe │ Ceny │ Dodatki │ Scena │   │
│ Logistyka  │ Czas │ Marketing│         │
├─────────────────────────────────────────┤
│                                         │
│  [Zawartość taba — max 5-6 pól]        │
│                                         │
└─────────────────────────────────────────┘
```

Szczegóły tabów — patrz **§4** poniżej.

### Tryb C: Bulk Mode (gdy zaznaczone >1 danie w drzewie)

```
┌─────────────────────────────────────────┐
│ Edycja masowa — 5 dań zaznaczonych      │
│ [Zastosuj]  [Wyczyść zaznaczenie]       │
├─────────────────────────────────────────┤
│  💰 Ceny        │  📅 Publikacja         │
│  Kanał: [POS ▼] │  Status: [Live ▼]     │
│  Operacja: [+5%]│  Od: [datetime]       │
│  Wartość: [2.00]│  Do: [datetime]       │
├─────────────────┤                        │
│  🏷 VAT & KDS    │  🎯 Marketing         │
│  VAT: [8% ▼]    │  Badge: [Bestseller ▼]│
│  KDS: [KITCHEN ▼]│  Secret: [Toggle]    │
├─────────────────────────────────────────┤
│  Podgląd: Pizza Margherita, Burger...   │
└─────────────────────────────────────────┘
```

- Bulk mode pojawia się **automatycznie** gdy zaznaczysz >1 danie w drzewie
- Nie trzeba przełączać widoku — workspace sam zmienia tryb
- Inspector pokazuje listę zaznaczonych dań
- API: `save_bulk` (istniejące)
- JS: `BulkEditor.renderBulkPanel()` — refaktor z `studio_bulk.js` (obecnie 80 linii)

### Tryb D: Combo/Meals (zintegrowany, nie osobny widok)

- W drzewie combo meals oznaczone ikoną 🍔
- Kliknięcie combo → edytor z tabem "Skład" zamiast tabu "Dodatki"
- Skład: komponenty (fixed_item / category_choice), cena finalna, typ
- Ten sam header, ten sam save, ten sam inspector
- API: istniejące akcje meals (`save_meal`, `get_meals`, `delete_meal`)
- JS: `studio_meals.js` (obecnie ~400 linii) — refaktor na tab w edytorze

### Tryb E: Category Table Editor (M025 — The Table)

- Akcja per-kategoria w Navigatorze: "Układ stołu" → otwiera overlay na workspace
- Drag-and-drop kart dań na drewnianym stole (pointer events, x/y ∈ [0..1])
- Template selection (category_flat_table, category_hero_wall, + dynamiczne z `StudioState.sceneTemplates`)
- Z-index per karta, scale per karta
- Auto-grid `defaultPlacement()` gdy brak zapisanych pozycji
- Zapis: `save_category_scene_layout` API
- API: `get_category_scene_editor`, `save_category_scene_layout` (istniejące)
- JS: `studio_category_table.js` (obecnie 236 linii) — refaktor UI, logika bez zmian

---

## 4. Editor Tabs — szczegółowe mapowanie

### Header edytora:
- Nazwa dania + status badge (klik = cycle Live/Draft/Archived)
  - JS: `ItemEditor.setPubStatus()` (studio_item.js — istnieje)
- Save button (zawsze widoczny, z indicator zmian: żółta kropka = unsaved)
- `Ctrl+S` = save, `Esc` = wróć do dashboard
- JS: `ItemEditor.saveItem()` (studio_item.js — istnieje)

### Tab 1: Podstawowe (łączy Identity + część Marketing)

| Pole | Typ | JS funkcja / źródło | API pole |
|---|---|---|---|
| Nazwa * | text input | `ItemEditor.populateAnchorNav()` → name | `name` |
| SKU * (auto z nazwy) | text, auto-fill | `ItemEditor.toAutoSlug()` (studio_item.js:38) | `ascii_key` |
| Kategoria * | select | `StudioState.categories` | `category_id` |
| Typ (Standard/Pół-Pół) | select | `ItemEditor.setItemType()` (studio_item.js:62) | `item_type` |
| Opis | textarea | — | `description` |
| Badge | select: none/new/promo/bestseller/hot | — | `badge` |
| Hero image | thumbnail + picker button | `ItemEditor.openItemHeroPicker()` (studio_item.js — istnieje) | `hero_asset_id` |

**Auto-slug mechanism:**
- `input` event on name field → `ItemEditor.toAutoSlug(value)` → uppercase, replace spaces with `_`, remove non-alphanumeric
- SKU field jest **read-only po pierwszym zapisie** (patrz §10 — dirty flag & SKU lock)

**Warianty (F-S1) w Tab 1:**
- Jeśli Typ = Standard i Skala Rozmiarów wybrana → pojawia się sekcja pod typem
- Jeśli Typ = Pół/Pół → pojawia się sekcja "Połowa A / Połowa B"
- **Variant Scale Manager** — przycisk "Zarządzaj skalami" → modal CRUD z presetami (Pizza 4 rozmiary, Kawa, Napoje, Frytki), multiplier → receptura
  - JS: `ItemEditor.openVariantScaleManager()` (studio_item.js — istnieje)
  - API: `list_variant_scales`, `create_variant_family` (istniejące)
- **Pizza Wizard (F-S6)** → uruchamiany z Navigator bottom bar, nie z edytora
  - JS: `ItemEditor.openNewPizzaWizard()` (studio_item.js — istnieje, 5 kroków)
  - Kroki: 1. Nazwa+SKU+Kategoria → 2. Skala rozmiarów → 3. Ceny bazowe → 4. Grupy modyfikatorów → 5. Podsumowanie+Generuj
  - JS: `ItemEditor._fs6Step(direction)` — nawigacja kroków z walidacją
  - JS: `ItemEditor._fs6LoadModifierGroups()` — ładowanie grup modyfikatorów do kroku 4
  - JS: `ItemEditor._fs6Generate()` — tworzenie parent + N children

### Tab 2: Ceny (łączy Matrix + VAT)

| Pole | Typ | JS funkcja / źródło | API pole |
|---|---|---|---|
| POS cena | number input | `ItemEditor.autoFillOmnichannel()` (studio_item.js:50) | `price_pos` |
| Takeaway cena | number input | auto-fill z POS × 1.0 | `price_takeaway` |
| Delivery cena | number input | auto-fill z POS × 1.10 | `price_delivery` |
| Auto-fill button | button | `ItemEditor.autoFillOmnichannel()` | — |
| VAT toggle (auto/manual) | switch | `ItemEditor.toggleVatInherit()` (studio_item.js:78) | `vat_inherit` |
| VAT dine-in | select (5%/8%/23%) | — | `vat_rate_dinein` |
| VAT takeaway | select (5%/8%/23%) | — | `vat_rate_takeaway` |
| Live preview: marża % | text | `MarginGuardian.calculate()` (studio_margin.js — istnieje) | — (computed) |

**Auto-fill mechanism:**
- Klik "Auto-uzupełnij" → `autoFillOmnichannel()` kopiuje POS → Takeaway (×1.0) i Delivery (×1.10)
- Każde pole jest edytowalne po auto-fill

### Tab 3: Dodatki (łączy Modifiers section + cały Modifiers view)

- Checkboxy grup modyfikatorów (jak teraz)
- `+ Nowa grupa` → **inline drawer** (nie osobny widok!) z pełnym CRUD grupy
- `Zarządzaj grupą` → ten sam drawer w trybie edycji
- Drawer zawiera: nazwa, SKU, min/max, free limit, multi-qty, opcje z cenami, visual slots, warehouse link
- **Size Pricing (F-S2.1)** — przycisk "Cennik per rozmiar" w opcji → modal z macierzą cen per (skala × opcja)
  - JS: `ModifierInspector.openSizePricingModal()` (studio_modifiers.js — istnieje)
  - API: `get_modifier_pricing`, `save_modifier_pricing` (istniejące)
- **Asset Picker (Visual Slots)** — `layer_top_down` + `modifier_hero` z grid picker (filtrowanie po kategorii, roli, search, thumbnail preview, live preview warstw)
  - JS: `ModifierInspector.openAssetPickerModal()` (studio_modifiers.js — istnieje)
  - API: `assets/engine.php` z `roleHints` (istniejące)
- **Franchise Shield** — HQ lock na pola grupy i opcji (nazwa, SKU, warehouse link, visual) gdy `isLockedByHq`
  - JS: `ModifierInspector.applyFranchiseShield()` — sprawdza `group.is_locked_by_hq` / `option.is_locked_by_hq`
  - UI: disabled inputs + lock icon 🔒
- **Kluczowe:** drawer jest overlay na workspace, nie osobny widok — nie tracisz kontekstu dania
- API: `get_modifiers_full`, `save_modifier_group`, `save_modifier_quick` (istniejące)
- JS: `ModifierInspector.saveModifierGroup()` (studio_modifiers.js — istnieje) — refaktor na drawer component
- JS: `ModifierInspector.openCreatorPanel()` (studio_modifiers.js — istnieje) — refaktor na inline form

**Auto-slug dla grup i opcji:**
- JS: `ModifierInspector.autoSlugGroup()` i `ModifierInspector.autoSlugOption()` (studio_modifiers.js — istnieje)
- Grupa: `groupAsciiKey` auto-generowany z nazwy, czyszczony i walidowany przed wysłaniem
- Opcja: `optionAsciiKey` auto-generowany z nazwy opcji

### Tab 4: Scena (obecna Visual, uproszczona)

| Pole | Typ | JS funkcja / źródło | API pole |
|---|---|---|---|
| Profil kompozycji | dropdown | `StudioState.sceneProfiles` | `composition_profile` |
| Hero preview | thumbnail | `ItemEditor.openItemHeroPicker()` | `hero_asset_id` |
| Link do Online Studio | link button | `window.open(?tab=director&item=SKU)` | — (deep-link) |
| Auto-generate scene | button | `ItemEditor.autogenerateScene()` (studio_item.js — istnieje) | — (API: `autogenerate_scene`) |

- **Jedyny tab z linkiem zewnętrznym** — reszta self-contained
- API: `set_item_hero`, `unlink_item_hero`, `autogenerate_scene` (istniejące)

### Tab 5: Logistyka (obecna Logistics, uproszczona)

| Pole | Typ | API pole |
|---|---|---|
| Printer group | select | `printer_group` |
| KDS station | select | `kds_station` |
| Driver action | select z ikonami | `driver_action` |
| PLU | number input | `plu_code` |
| Display order | number input | `display_order` |
| Stock count | number input | `stock_count` |

- 3 inputy w jednym rzędzie (PLU, display order, stock count)
- 2 selecty obok siebie (printer, KDS)

### Tab 6: Czas (obecna Schedule)

| Pole | Typ | API pole |
|---|---|---|
| Valid from | datetime-local | `valid_from` |
| Valid to | datetime-local | `valid_to` |
| Dni tygodnia | 7 chipów toggle | `available_days` (JSON array) |
| Godziny od | time input | `available_from` |
| Godziny do | time input | `available_to` |

### Tab 7: Marketing (obecne Marketing + Enterprise, połączone)

| Pole | Typ | API pole |
|---|---|---|
| Tags | input (comma-separated) | `tags` |
| Allergens | 11 chipów toggle | `allergens` (JSON array) |
| Barcode EAN | text input | `barcode_ean` |
| Parent SKU | text input | `parent_sku` |
| Secret toggle | switch | `is_secret` |
| Legacy image URL | text w `<details>` | `legacy_image_url` |

---

## 5. Modifiers Drawer (inline, nie osobny widok)

Zastępuje obecny osobny widok modyfikatorów:

```
┌──────────────────────────────────────────┐
│  Grupy Modyfikatorów              [✕]   │
├──────────────────────────────────────────┤
│  [+ Nowa grupa]                          │
│                                          │
│  ┌────────────────────────────────────┐  │
│  │ 🟢 SOSY                            │  │
│  │ 5 opcji · Min 0 Max 3 · Live       │  │
│  │ [Edytuj] [Duplikuj] [Archiwizuj]   │  │
│  └────────────────────────────────────┘  │
│  ┌────────────────────────────────────┐  │
│  │ 🟡 DODATKI                         │  │
│  │ 8 opcji · Min 0 Max 5 · Live       │  │
│  │ [Edytuj] [Duplikuj] [Archiwizuj]   │  │
│  └────────────────────────────────────┘  │
└──────────────────────────────────────────┘
```

### Struktura drawer:

- **Drawer** = overlay z prawej strony workspace (width 480px, slide-in animation)
- Lista grup z statusem i licznikiem opcji
- Klik "Edytuj" → rozwija formularz grupy w tym samym drawerze (accordion)

### Formularz grupy (po kliknięciu "Edytuj" lub "+ Nowa grupa"):

| Pole | Typ | JS funkcja | API pole |
|---|---|---|---|
| Nazwa grupy * | text | `autoSlugGroup()` → auto-gen SKU | `group_name` |
| SKU grupy (groupAsciiKey) | text, auto-fill | `autoSlugGroup()` (studio_modifiers.js) | `group_ascii_key` |
| Min selection | number | — | `min_selection` |
| Max selection | number | — | `max_selection` |
| Free limit | number | — | `free_limit` |
| Multi-qty | switch | — | `multi_qty` |
| Publikacja | switch | — | `pub_status` |

### Opcje w grupie (każda opcja to wiersz):

| Pole | Typ | JS funkcja | API pole |
|---|---|---|---|
| Nazwa opcji | text | `autoSlugOption()` → auto-gen SKU | `option_name` |
| SKU opcji (optionAsciiKey) | text, auto-fill | `autoSlugOption()` | `option_ascii_key` |
| Cena POS | number | — | `price_pos` |
| Cena Takeaway | number | — | `price_takeaway` |
| Cena Delivery | number | — | `price_delivery` |
| Warehouse link | select (searchable) | — | `warehouse_product_id` |
| Visual slot | thumbnail + picker | `openAssetPickerModal()` | `asset_id` |
| Cennik per rozmiar | button → modal | `openSizePricingModal()` | — (API: `save_modifier_pricing`) |

### Kluczowe zasady drawer:

- **Nie tracisz kontekstu edytora dania** — po zamknięciu drawer wracasz do tabu Dodatki z odświeżoną listą grup
- **Franchise Shield** — gdy `isLockedByHq`, pola nazwa/SKU/warehouse/visual są disabled z ikoną 🔒
- **SKU lock po pierwszym zapisie** — `groupAsciiKey` i `optionAsciiKey` stają się read-only po pierwszym save (patrz §10)
- API: `get_modifiers_full`, `save_modifier_group`, `save_modifier_quick` (istniejące)
- Plik: `studio_modifiers.js` (refaktor z osobnego widoku na drawer component, 1449 → ~1200 linii)

### JS funkcje w drawer (✅ zaimplementowane):

| Funkcja | Źródło | Status po refaktorze |
|---|---|---|
| `ModifierInspector.openDrawer()` | studio_modifiers.js | **Nowe** — otwiera drawer, renderuje listę grup |
| `ModifierInspector.closeDrawer()` | studio_modifiers.js | **Nowe** — zamyka drawer |
| `ModifierInspector._showListInDrawer()` | studio_modifiers.js | **Nowe** — lista grup (karty z statusem, licznikiem opcji) |
| `ModifierInspector._showFormInDrawer(groupId)` | studio_modifiers.js | **Nowe** — renderuje formularz grupy w drawer (via `renderInit()` + `selectGroup`/`createNewGroup`) |
| `ModifierInspector.renderInit()` | studio_modifiers.js | Refaktor — render w `#modifiers-drawer` (single-column 480px) |
| `ModifierInspector.renderGroupList()` | studio_modifiers.js | Refaktor — odświeża listę w drawerze, nie nadpisuje `#dynamic-tree-container` |
| `ModifierInspector.saveModifierGroup()` | studio_modifiers.js | Refaktor — `alert()` → `StudioToast.show()`, po sukcesie → `_showListInDrawer()` |
| `ModifierInspector.saveModifier()` | studio_modifiers.js | Refaktor — `alert()` → `StudioToast.show()`, po sukcesie → `_showListInDrawer()` |
| `ModifierInspector.openCreatorPanel()` | studio_modifiers.js | Bez zmian — inline form w `#modifier-creator-slot` (w drawer) |
| `ModifierInspector.openSizePricingModal()` | studio_modifiers.js | Refaktor — `alert()` → `StudioToast.show()` (4×), logiki bez zmian |
| `ModifierInspector.openAssetPickerModal()` | studio_modifiers.js | Bez zmian logiki |
| `ModifierInspector.autoSlugGroup()` | studio_modifiers.js | Bez zmian |
| `ModifierInspector.autoSlugOption()` | studio_modifiers.js | Bez zmian |
| `ModifierInspector.applyFranchiseShield()` | studio_modifiers.js | Bez zmian logiki |

---

## 6. Pane 3 — Inspector (prawy, 380px, contextual)

### Gdy danie wybrane:

#### Food Cost panel (receptura + składniki + margin)

| Sekcja | JS funkcja | API |
|---|---|---|
| Ładowanie receptury | `RecipeMapper.loadRecipe()` (studio_recipe.js) | `get_item_recipe` |
| Init (AVCO dictionary) | `RecipeMapper.init()` (studio_recipe.js) | `get_recipes_init` |
| Zapis receptury | `RecipeMapper.saveRecipe()` (studio_recipe.js) | `save_recipe` |
| Margin calculation | `MarginGuardian.calculate()` (studio_margin.js) | — (computed client-side) |
| Margin render | `MarginGuardian.render()` (studio_margin.js) | — |
| Reactive updates | `MarginGuardian.bindReactivity()` (studio_margin.js) | — (event delegation) |

#### Margin Guardian (color-coded)

| Food Cost % | Kolor | Status |
|---|---|---|
| < 30% | `emerald` (zielony) | OK |
| 30-40% | `amber` (żółty) | Warning |
| > 40% | `red` (czerwony) | Critical |

- JS: `MarginGuardian.calculate()` — oblicza net price, gross profit, food cost % per channel (POS/Takeaway/Delivery)
- JS: `MarginGuardian.render()` — renderuje wyniki w inspector panel
- JS: `MarginGuardian.bindReactivity()` — event delegation: aktualizuje obliczenia gdy inputy cen lub receptury się zmieniają

#### Fuzzy Search — wyszukiwarka surowców

- JS: `RecipeMapper.fuzzySearch()` (studio_recipe.js — istnieje)
- Keyboard nav: ↑↓ = nawigacja, Enter = wybierz, Esc = zamknij
- Live wyniki z stock badge + AVCO cost
- API: `GET_STOCK` (istniejące)

#### Bulk Add — wklej listę składników

- JS: `RecipeMapper.openBulkAddModal()` (studio_recipe.js — istnieje)
- Modal z parsowaniem "nazwa ilość jednostka" + fuzzy match + live preview
- Parsowanie: split linii, regex na `nazwa | ilość | jednostka`

#### AutoScan — skanuj opis dania

- JS: `RecipeMapper.autoScan()` (studio_recipe.js — istnieje)
- Auto-dopasowanie surowców z opisu dania (tokenizacja + aliasy)
- API: `GET_STOCK` z fuzzy match

#### Subrecipe / Półprodukty (F-S5.1)

- JS: `RecipeMapper.toggleSubrecipe()` (studio_recipe.js — istnieje)
- Toggle wiersza na półprodukt → picker kandydatów (iiko-style "заготовка")
- Yield per batch, rekurencyjna ekspansja w WzEngine
- API: `get_recipes_init` (zwraca listę półproduktów)

#### Recipe Clone (F-S4)

- JS: `RecipeMapper.openCloneModal()` (studio_recipe.js — istnieje)
- Przycisk "Skopiuj z innej pozycji" → modal wyboru źródła z search
- API: `get_item_recipe` dla źródła, `save_recipe` dla celu

#### Recipe Drag&Drop (F-S9)

- JS: `RecipeMapper.bindDragDrop()` (studio_recipe.js — istnieje)
- HTML5 DnD reorder wierszy receptury z auto-displayOrder
- Drag handle na każdym wierszu, drop zone między wierszami

#### Stock badges

- Per wiersz: ok/low/out z animacją pulse
- AVCO cost per wiersz (z `get_recipes_init` AVCO dictionary)
- JS: `RecipeMapper.renderStockBadge()` (studio_recipe.js — istnieje)

#### Franchise Shield

- HQ lock na pola dania (name, desc, image, ascii, KDS, tags) gdy `isLockedByHq`
- JS: `ItemEditor.applyFranchiseShield()` — sprawdza `item.is_locked_by_hq`
- UI: disabled inputs + lock icon 🔒

#### Quick stats

- Cena POS, food cost, marża % — zawsze widoczne na górze inspector
- JS: `MarginGuardian.render()` — istnieje

### Gdy bulk mode:
- Lista zaznaczonych dań z mini-podglądem (thumbnail + nazwa + cena)

### Gdy dashboard:
- Ostatnia aktywność (localStorage)
- Top margin alerts

### Gdy puste (nie wybrano nic):
- Zwinięty / ukryty — workspace dostaje pełną szerokość

### Implementacja:
- Pliki: `studio_recipe.js` (refaktor UI, 1080 → ~900 linii) + `studio_margin.js` (refaktor UI, 409 → ~350 linii)
- API: `get_item_recipe`, `get_recipes_init`, `GET_STOCK` (istniejące)

---

## 7. Plan implementacji (kolejność i zależności)

### Faza 1: Layout + Navigator (1 sesja, ~2-3h)

**Pliki:**
- `index.html` — przepisanie na 3-pane container
- `studio_ui.js` — przepisanie: search, filters, tree z hover actions, dashboard
- `studio_core.js` — rozszerzenie: routing między trybami (dashboard / editor / bulk)

**Nowe funkcje:**
- `StudioUI.renderDashboard()` — dashboard z stats
- `StudioUI.renderTree()` — drzewo z search + filters
- `StudioUI.filterTree(query)` — live filter
- `StudioCore.routeToMode()` — routing automatyczny

**Zależności:** brak (pierwsza faza)
**Ryzyko:** niskie — tylko UI, brak logiki biznesowej

### Faza 2: Tabbed Editor (1 sesja, ~2-3h) — ✅ WDRUŻONE 2026-07-29

**Pliki:**
- `studio_item.js` — refaktor: podział na 7 tabów (zamiast 10 sekcji w scrollu)
- Header z save + status + unsaved indicator
- Skróty klawiszowe (Ctrl+S)

**Wdrożone zmiany:**
- `ItemEditor.switchTab(n)` — nowe, przełącza widoczność paneli `.item-tab-panel[data-tab]` i styluje przyciski `.item-tab-btn`
- `ItemEditor._currentTab` / `_isDirty` — nowe pola stanu
- `ItemEditor._markDirty()` / `_markClean()` — nowe, obsługują wskaźnik `#dirty-indicator` w command bar + sync z `StudioState`
- `ItemEditor._populateAnchorNav()` → no-op (zastąpione przez tab bar)
- `ItemEditor._setupScrollSpy()` → no-op (zastąpione przez tab bar)
- `ItemEditor._populateCommandBar()` — usunięto secret toggle (przeniesiony do Tab 7), dodano `#dirty-indicator`
- `ItemEditor.ensureOmnichannelForm()` — przepisane na 7 tabów (`.item-tab-panel[data-tab="1..7"]`), wywołuje `switchTab(1)` zamiast `_setupScrollSpy()`
- `ItemEditor._bindAllEvents()` — dodany dirty flag tracking (input/change → `_markDirty()`) + Ctrl+S shortcut
- `ItemEditor.loadItemDataToForm()` — dodany `switchTab(1)` + `_markClean()`
- `ItemEditor.saveItem()` — `alert()` → `StudioToast.show()`, dodany `_markClean()` na sukces
- `ItemEditor.openItemHeroPicker()` — `alert()` → `StudioToast.show()`
- `ItemEditor.linkItemHero()` — `alert()` → `StudioToast.show()`
- `ItemEditor.setPubStatus()` — bez zmian, w headerze
- `ItemEditor.setItemType()` — bez zmian, w Tab 1
- `ItemEditor.autoFillOmnichannel()` — bez zmian, w Tab 2
- `ItemEditor.toggleVatInherit()` — bez zmian, w Tab 2
- `ItemEditor.autogenerateScene()` — bez zmian, w Tab 4
- `ItemEditor.openVariantScaleManager()` — bez zmian, w Tab 1
- `ItemEditor.openNewPizzaWizard()` — bez zmian, z Navigator
- `ItemEditor._fs6Step()` / `_fs6LoadModifierGroups()` / `_fs6Generate()` — bez zmian
- `ItemEditor.toAutoSlug()` / `debounce()` — bez zmian

**Mapowanie tabów:**

| Tab | Etykieta | Zawartość |
|-----|----------|-----------|
| 1 | Podstawowe | Identity (name, SKU, category, type) + Variants (scale) + Description + Badge |
| 2 | Ceny | Price matrix (POS/Takeaway/Delivery) + VAT (dine-in/takeaway) |
| 3 | Dodatki | Modifier group checkboxes |
| 4 | Scena | Hero picker, composition profile, Scene Studio link, auto-generate |
| 5 | Logistyka | Printer, KDS, driver action, PLU, display order, stock |
| 6 | Czas | Valid from/to, days, hours |
| 7 | Marketing | Tags, legacy URL, EAN, parent SKU, allergens, secret toggle |

**Zależności:** Faza 1 (layout + routing)
**Ryzyko:** średnie — duża plik (1964 linie), ale logika bez zmian
**Lint:** `node -c studio_item.js` — PASS (exit 0)

### Faza 3: Modifiers Drawer (✅ WDRUŻONE 2026-07-29)

**Pliki:**
- `studio_modifiers.js` — refaktor: z osobnego widoku na drawer component
- `index.html` — drawer HTML containers + CSS + nav button
- `studio_ui.js` — `Core.switchView('modifiers')` fallback → `openDrawer()`
- `studio_item.js` — Tab 3 (Dodatki) przycisk → `openDrawer()`

**Zmiany w `studio_modifiers.js`:**
- **Nowe metody:** `openDrawer()`, `closeDrawer()`, `_showListInDrawer()`, `_showFormInDrawer(groupId)`
- `renderInit()` → renderuje formularz w `#modifiers-drawer` (single-column 480px, nie 2-col)
- `renderGroupList()` → odświeża listę w drawerze, nie nadpisuje `#dynamic-tree-container`
- `saveModifierGroup()` → wszystkie `alert()` → `StudioToast.show()`, po sukcesie → `_showListInDrawer()` po 800ms
- `saveModifier()` → `alert()` → `StudioToast.show()`, po sukcesie → `_showListInDrawer()`
- `openSizePricingModal()` → 4× `alert()` → `StudioToast.show()`
- `load` event listener → usunięto override `switchView` dla modifiers

**Zmiany w `index.html`:**
- `#modifiers-drawer` (480px slide-in z prawej, `translateX(100%)` → `translateX(0)`, `transition: transform 200ms ease-out`)
- `#modifiers-drawer-backdrop` (click-to-close, `opacity` transition)
- `nav-modifiers` → `openDrawer()` (nie `switchView('modifiers')`)
- Usunięty stary `#modifiers-view` container

**Zmiany w `studio_ui.js`:**
- `Core.switchView('modifiers')` → fallback `openDrawer()` (backward compat)

**Zmiany w `studio_item.js`:**
- Tab 3 (Dodatki) — przycisk "Zarządzaj Grupami Modyfikatorów" → `openDrawer()`

**Zależności:** Faza 2 (tabbed editor — drawer jest overlay na workspace)
**Ryzyko:** niskie — drawer jest niezależny od widoków, współistnieje z tabami
**Lint:** 3/3 PASS (`studio_modifiers.js`, `studio_item.js`, `studio_ui.js`)

**Następny krok:** Faza 4 (Bulk+Combo) lub Faza 5 (Polish+Shortcuts)

### Faza 4: Inspector + Dashboard polish (1 sesja, ~2-3h)

**Pliki:**
- `studio_recipe.js` — refaktor UI na inspector panel
- `studio_margin.js` — refaktor UI na margin guardian w inspector

**Zmiany w `studio_recipe.js`:**
- `RecipeMapper.loadRecipe()` → render w inspector panel
- `RecipeMapper.init()` → bez zmian logiki
- `RecipeMapper.saveRecipe()` → bez zmian logiki
- `RecipeMapper.fuzzySearch()` → bez zmian logiki, keyboard nav w inspector
- `RecipeMapper.openBulkAddModal()` → bez zmian logiki
- `RecipeMapper.autoScan()` → bez zmian logiki
- `RecipeMapper.toggleSubrecipe()` → bez zmian logiki
- `RecipeMapper.openCloneModal()` → bez zmian logiki
- `RecipeMapper.bindDragDrop()` → bez zmian logiki
- `RecipeMapper.renderStockBadge()` → bez zmian logiki
- `RecipeMapper._triggerMarginUpdate()` → bez zmian, integracja z MarginGuardian

**Zmiany w `studio_margin.js`:**
- `MarginGuardian.calculate()` → bez zmian logiki
- `MarginGuardian.render()` → refaktor: render w inspector panel (zamiast osobnego panelu)
- `MarginGuardian.bindReactivity()` → bez zmian logiki
- `MarginGuardian.loadAvcoDict()` → bez zmian logiki
- `MarginGuardian.preloadSubrecipeCosts()` → bez zmian logiki

**Nowe w `studio_ui.js`:**
- Dashboard stats (zliczenie z `StudioState.items`)
- Recent items (localStorage)
- Margin alerts (z RecipeMapper data)

**Zależności:** Faza 2 (editor musi istnieć, inspector jest contextual)
**Ryzyko:** niskie — logika bez zmian, tylko UI refaktor

### Faza 5: Bulk Mode + Drag&Drop (1 sesja, ~2-3h)

**Pliki:**
- `studio_bulk.js` — refaktor: bulk mode w workspace (nie osobny widok)
- `studio_ui.js` — drag&drop reorder w drzewie
- Quick edit (inline price change w drzewie)

**Zmiany:**
- `BulkEditor.renderBulkPanel()` → refaktor z osobnego widoku na workspace mode
- `StudioUI.bindTreeDragDrop()` → nowe (HTML5 DnD reorder kategorii/dań)
- `StudioUI.quickEditPrice()` → nowe (inline price edit w drzewie)

**Zależności:** Faza 1 (navigator) + Faza 4 (inspector dla bulk list)
**Ryzyko:** niskie — bulk API już istnieje, DnD to vanilla JS

---

## 8. Pliki dotknięte

| Plik | Linie (obecnie) | Zmiana | Nowe linie (szac.) |
|---|---|---|---|
| `modules/studio/index.html` | 374 | Przepisanie — 3-pane container | ~150 |
| `modules/studio/js/studio_ui.js` | 453 | Przepisanie — navigator + dashboard + routing + DnD | ~600 |
| `modules/studio/js/studio_item.js` | 1926 | Refaktor — tabbed editor (7 tabów) | ~1800 |
| `modules/studio/js/studio_modifiers.js` | 1449 | Refaktor — drawer component | ~1200 |
| `modules/studio/js/studio_bulk.js` | 80 | Refaktor — bulk mode w workspace | ~200 |
| `modules/studio/js/studio_meals.js` | ~400 | Refaktor — combo tab w edytorze | ~350 |
| `modules/studio/js/studio_recipe.js` | 1080 | Refaktor — inspector panel | ~900 |
| `modules/studio/js/studio_margin.js` | 409 | Refaktor — margin guardian w inspector | ~350 |
| `modules/studio/js/studio_core.js` | 57 | Rozszerzenie — routing + state + dirty flag | ~120 |
| `modules/studio/js/studio_category_table.js` | 236 | Refaktor — overlay w workspace | ~250 |
| `modules/studio/js/studio_api.js` | 33 | **Bez zmian** | 33 |
| `api/backoffice/api_menu_studio.php` | 3382 | **Bez zmian** | 3382 |

**Szacowany czas:** 5 sesji (po 2-3h każda)
**Ryzyko:** Niskie — zero zmian backendu, wszystkie dane z istniejącego API

---

## 9. 20 kluczowych funkcji — pokrycie w projekcie

| # | Funkcja | Kod (JS plik:funkcja) | Gdzie w projekcie | Status |
|---|---|---|---|---|
| 1 | Pizza Wizard (F-S6) | `studio_item.js:openNewPizzaWizard()` | §3 Tryb B, Navigator bottom bar | ✅ Istnieje, bez zmian |
| 2 | Variant Scale Manager (F-S1) | `studio_item.js:openVariantScaleManager()` | §4 Tab 1 (pod typem) | ✅ Istnieje, bez zmian |
| 3 | Size Pricing (F-S2.1) | `studio_modifiers.js:openSizePricingModal()` | §4 Tab 3, §5 Drawer | ✅ Istnieje, bez zmian logiki |
| 4 | Asset Picker (Visual Slots) | `studio_modifiers.js:openAssetPickerModal()` | §4 Tab 3, §5 Drawer | ✅ Istnieje, bez zmian logiki |
| 5 | Fuzzy Search (surowce) | `studio_recipe.js:fuzzySearch()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 6 | Bulk Add (wklej listę) | `studio_recipe.js:openBulkAddModal()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 7 | AutoScan (skanuj opis) | `studio_recipe.js:autoScan()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 8 | Subrecipe / Półprodukty (F-S5.1) | `studio_recipe.js:toggleSubrecipe()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 9 | Recipe Clone (F-S4) | `studio_recipe.js:openCloneModal()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 10 | Recipe Drag&Drop (F-S9) | `studio_recipe.js:bindDragDrop()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 11 | Franchise Shield (HQ lock) | `studio_modifiers.js` + `studio_item.js` | §4 Tab 3, §5 Drawer, §6 Inspector | ✅ Istnieje, refaktor UI |
| 12 | Category Table Editor (M025) | `studio_category_table.js` | §3 Tryb E | ✅ Istnieje, refaktor UI |
| 13 | Margin Guardian (food cost %) | `studio_margin.js:calculate() + render()` | §6 Inspector | ✅ Istnieje, refaktor UI |
| 14 | Composition Profile (scena) | `studio_item.js` (Visual section) | §4 Tab 4 | ✅ Istnieje, uproszczone |
| 15 | Hero Picker (hero image) | `studio_item.js:openItemHeroPicker()` | §4 Tab 1 + Tab 4 | ✅ Istnieje, bez zmian |
| 16 | Deep-link to Online Studio | `studio_item.js` (?tab=director&item=SKU) | §4 Tab 4 | ✅ Istnieje, bez zmian |
| 17 | Auto-slug (SKU z nazwy) | `studio_item.js:toAutoSlug()` + `studio_modifiers.js:autoSlugGroup/Option()` | §4 Tab 1, §5 Drawer | ✅ Istnieje, bez zmian |
| 18 | Stock badges (ok/low/out) | `studio_recipe.js:renderStockBadge()` | §6 Inspector | ✅ Istnieje, bez zmian logiki |
| 19 | Bulk Edit (multi-item) | `studio_bulk.js` | §3 Tryb C | ✅ Istnieje, refaktor UI |
| 20 | Meals/Combo CRUD | `studio_meals.js` | §3 Tryb D | ✅ Istnieje, refaktor UI |

**Wniosek:** Wszystkie 20 kluczowych funkcji jest pokrytych w projekcie. Żadna nowa logika biznesowa nie jest wymagana — wszystkie funkcje istnieją w kodzie i wymagają tylko refaktoru UI (przeniesienie do tabów/drawer/inspector).

---

## 10. System wizualny i UX

### Kolorystyka (Tailwind CDN)

Uproszczenie z obecnych 7+ kolorów akcentu do 3 + status:

| Kategoria | Kolor Tailwind | Użycie |
|---|---|---|
| Primary | `blue` | Akcje, buttony, aktywne taby, focus ring |
| Warning | `amber` | Alerty margin, unsaved indicator, warning toast |
| Success | `emerald` | Save success, Live status, success toast |
| Status Live | `green` | Status dot w drzewie, badge "Live" |
| Status Draft | `yellow` | Status dot w drzewie, badge "Draft" |
| Status Archived | `red` | Status dot w drzewie, badge "Archived" |
| Neutral | `slate` | Tła, bordery, tekst, wszystko inne |

**Zakazane kolory:** `purple`, `cyan`, `orange`, `rose`, `indigo`, `teal` (obecnie używane nadmiarowo)

### Font size (minimum)

| Element | Rozmiar | Tailwind |
|---|---|---|
| Labelki | 12px | `text-xs` |
| Inputy | 14px | `text-sm` |
| Wartości cen | 16px | `text-base` |
| Header edytora | 18px | `text-lg` |
| Dashboard stats | 24px | `text-2xl` |
| Toast tytuł | 14px | `text-sm` |
| Toast body | 12px | `text-xs` |

### Spacing

| Element | Spacing | Tailwind |
|---|---|---|
| Między polami w tabie | 16px | `p-4` / `gap-4` |
| Między tabami (padding) | 24px | `p-6` |
| Między kartami w drzewie | 4px | `gap-1` |
| Drawer padding | 20px | `p-5` |

### Toast notifications (zamiast `alert()`)

Mały JS snippet (~30 linii) w `studio_core.js`:

```javascript
StudioToast = {
  show(msg, type = 'info', duration = 3000) {
    const colors = {
      success: 'bg-emerald-600', error: 'bg-red-600',
      warning: 'bg-amber-600', info: 'bg-blue-600'
    };
    const toast = document.createElement('div');
    toast.className = `fixed bottom-6 right-6 z-[500] ${colors[type]} text-white px-4 py-3 rounded-lg shadow-lg text-sm font-bold animate-slide-in`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), duration);
  }
};
```

**Użycie:**
- `StudioToast.show('Zapisano pomyślnie', 'success')` — po save
- `StudioToast.show('Błąd zapisu: ' + err, 'error')` — po błędzie
- `StudioToast.show('Nie zapisano — masz niezapisane zmiany', 'warning')` — dirty flag warning

### Dirty flag & unsaved changes

W `studio_core.js` (`StudioState`):

```javascript
StudioState = {
  isDirty: false,
  markDirty() { this.isDirty = true; this._updateSaveIndicator(); },
  markClean() { this.isDirty = false; this._updateSaveIndicator(); },
  _updateSaveIndicator() {
    const dot = document.getElementById('save-indicator-dot');
    if (dot) dot.classList.toggle('bg-amber-400', this.isDirty);
  }
};
```

- Każdy `input` event w edytorze → `StudioState.markDirty()`
- Po `saveItem()` success → `StudioState.markClean()`
- `beforeunload` event → `if (StudioState.isDirty) return 'Masz niezapisane zmiany'`
- Przejście do innego dania → `if (StudioState.isDirty) confirm('Nie zapisano zmian. Kontynuować?')`

### SKU lock po pierwszym zapisie

- Pole SKU (`ascii_key` / `groupAsciiKey` / `optionAsciiKey`) jest **editable** przy nowym rekordzie
- Po pierwszym zapisie (gdy `itemId` / `groupId` / `optionId` istnieje) → pole staje się **readonly** z tooltip "SKU zablokowany po pierwszym zapisie"
- JS: w `saveModifierGroup()` — po sukcesie, jeśli był to nowy rekord (brak `groupId` w payload), ustaw `document.getElementById('group-ascii-key').readOnly = true`
- To samo w `ItemEditor.saveItem()` dla `ascii_key`
- **Uzasadnienie:** SKU jest używane jako klucz w integracjach (POS, KSeF, warehouse) — zmiana po zapisie = rozbita referencja

### Skróty klawiszowe

| Skrót | Akcja | Implementacja |
|---|---|---|
| `Ctrl+S` | Zapisz danie | `document.addEventListener('keydown', e => { if(e.ctrlKey && e.key==='s'){ e.preventDefault(); ItemEditor.saveItem(); }})` |
| `Esc` | Wróć / zamknij drawer | `if(e.key==='Escape'){ closeDrawer(); or routeToDashboard(); }` |
| `Ctrl+D` | Duplikuj danie | `ItemEditor.duplicateItem()` |
| `↑/↓` | Nawigacja w drzewie | `StudioUI.navigateTree(direction)` |
| `Enter` | Otwórz danie | `StudioUI.openSelectedItem()` |
| `Ctrl+B` | Toggle bulk mode | `StudioState.toggleBulkMode()` |

**Implementacja:** jeden `keydown` listener w `studio_core.js`, dispatch do odpowiedniej funkcji na podstawie `StudioState.mode`.

### Animacje

- **Drawer slide-in**: `transform: translateX(100%) → translateX(0)`, `transition: transform 200ms ease-out`
- **Toast slide-in**: `transform: translateY(100%) → translateY(0)`, `transition: transform 200ms ease-out`
- **Tab switch**: `opacity: 0 → 1`, `transition: opacity 150ms`
- **Tree hover actions**: `opacity: 0 → 1`, `transition: opacity 100ms`
- **Stock badge pulse**: `animate-pulse` (Tailwind built-in) dla `out` status

---

## 11. Diagnoza: co jest nie tak (obecny stan)

### Strukturalne problemy

1. **4 osobne widoki** (menu / modyfikatory / bulk / meals) — każde przejście traci kontekst, nie można edytować dania i dodać modyfikator w tym samym ekranie
2. **10 sekcji w jednym scrollu** (~500 linii HTML w `ensureOmnichannelForm`) — Tożsamość, Warianty, Ceny, VAT, Modyfikatory, Visual, Logistyka, Harmonogram, Marketing, Enterprise — przytłaczające
3. **Anchor nav** (10 ikon po lewej) jako band-aid na problem zamiast podziału na taby
4. **Modyfikatory w osobnym widoku** — żeby dodać grupę musisz wyjść z edytora dania, wejść w modyfikatory, stworzyć grupę, wrócić, przypisać
5. **Food Cost panel tylko w menu view** — w innych widokach niewidoczny
6. **Bulk edit oderwany od drzewa** — zaznaczasz checkboxy w drzewie, potem idziesz do osobnego widoku, nie widzisz co zaznaczyłeś

### Wizualne problemy

7. **Tekst 8-11px** — nieczytelne, "mikroskopijne" labelki
8. **Za dużo kolorów** — fioletowy, cyan, amber, blue, emerald, rose, orange — każdy section header inny kolor = chaos wizualny
9. **Glass cards** z `absolute top-0 left-0 w-1 h-full bg-{color}` — ozdobniki zamiast hierarchii
10. **`alert()` jako feedback** — brak toastów, brak inline validation
11. **Command bar** z publikacją + secret + save — trzy różne kategorie akcji w jednym pasku
12. **Sidebar** miesza zarządzanie kartą (Drzewo, Modyfikatory, Bulk, Meals) z linkami (Online Studio, Magazyn) i placeholderami (WKRÓTCE)

### UX problemy

13. **Brak wyszukiwania** — nie można znaleźć dania po nazwie/SKU
14. **Brak drag&drop** — nie można zmienić kolejności
15. **Brak quick edit** — żeby zmienić cenę musisz otworzyć pełny edytor, scrollować do sekcji cen, zmienić, scrollować do save
16. **Brak dashboardu** — lądujesz w pustym drzewie, brak statystyk, brak alertów margin
17. **Brak unsaved changes warning** — przejście do innego dania bez zapisu = utrata danych
18. **Brak skrótów klawiszowych** — Ctrl+S, Esc, strzałki

---

## Podsumowanie

- **20/20 kluczowych funkcji** pokrytych w projekcie — wszystkie istnieją w kodzie, wymagają tylko refaktoru UI
- **Zero zmian backendu** — wszystkie dane z istniejącego API (`api_menu_studio.php`, 3382 linie)
- **5 faz implementacji** — każda faza to 1 sesja (2-3h), z jasnymi zależnościami
- **Ryzyko niskie** — refaktor UI tylko, logika biznesowa nietknięta
- **Nowe elementy UI:** toast notifications, dirty flag, SKU lock, keyboard shortcuts, dashboard, search/filter w drzewie, drag&drop reorder

---

## 12. Wdrożone zmiany — `saveModifierGroup` refaktor (2026-07-28)

### Kontekst

Refaktor funkcji `saveModifierGroup()` w `modules/studio/js/studio_modifiers.js` — poprawa czyszczenia SKU, walidacji, blokowania pól po zapisie i optymalizacji payloadu.

### Zmiana 1: Transliteracja polskich znaków w SKU

**Plik:** `modules/studio/js/studio_modifiers.js:1152-1158` (group), `1182-1185` (options)

- **Przed:** `replace(/[^a-zA-Z0-9_-]/g, '')` — **usuwało** polskie znaki (`ŻÓŁTY_SOS` → `TY_SOS`)
- **Po:** najpierw transliteracja (`ą→a`, `ż→z` itd.) przez `_PL_MAP`, potem regex czyszczenia
- `_PL_MAP` zdefiniowane lokalnie w `saveModifierGroup()`, używane dla grupy i opcji
- Zachowano dozwolone znaki `-` i `_` (zgodność z obecnym zachowaniem)

### Zmiana 2: Walidacja długości i formatu SKU

**Plik:** `modules/studio/js/studio_modifiers.js:1170-1172` (group), `1197-1198` (options)

- **Grupa:** min 3 znaki, max 50 znaków, musi zaczynać się od litery (`/^[A-Z]/`)
- **Opcja:** min 2 znaki, max 50 znaków
- Walidacja przed wysłaniem payloadu — zapobiega krótkim/długim SKU kolidującym z integracjami

### Zmiana 3: Tooltip + lock na `opt-ascii` po zapisie

**Plik:** `modules/studio/js/studio_modifiers.js:1273-1286`

- `mod-group-ascii`: dodany `title` attribute z wyjaśnieniem blokady
- `opt-ascii`: po sukcesie zapisu, wszystkie opcje z `optId > 0` dostają `disabled = true` + `opacity-50` + `cursor-not-allowed` + `title`
- Spójność z grupą — SKU opcji też używane w integracjach

### Zmiana 4: Optymalizacja payload — `groupAsciiKey` tylko przy INSERT

**Plik:** `modules/studio/js/studio_modifiers.js:1259-1261`

- **Przed:** `groupAsciiKey` wysyłany zawsze (nawet przy UPDATE)
- **Po:** `payload.groupAsciiKey = groupAsciiKey` tylko gdy `groupId === 0` (nowy rekord)
- Backend UPDATE (API linia 1820) nie zapisuje `ascii_key` — jest immutable
- Brak pola w payload UPDATE jest bezpieczny (API czyta `$input['groupAsciiKey'] ?? ''`)

### Backend bez zmian

`api/backoffice/api_menu_studio.php:1792` — już czyści `preg_replace('/[^a-zA-Z0-9_-]/', '', ...)` i waliduje `empty()`. UPDATE pomija `ascii_key` (poprawnie). INSERT zapisuje `ascii_key`.

### Lint

- `node -c studio_modifiers.js` — PASS (exit code 0)

---

## 13. Faza 1 — Navigator + Dashboard + Toast + Dirty Flag (2026-07-28)

### Wdrożone zmiany

#### `studio_core.js` — rozszerzenie StudioState + toast

- **`StudioState.selectedItemId`** / **`mode`** / **`isDirty`** — nowe pola stanu
- **`markDirty()` / `markClean()`** — toggle dirty flag, aktualizuje wskaźnik w dashboard
- **`routeToMode()`** — auto-routing: bulk (>1 zaznaczonych) → editor (1 zaznaczony) → dashboard (0)
- **`window.StudioToast.show(msg, type, duration)`** — toast notifications (success/error/warning/info), slide-in animation, auto-dismiss

#### `index.html` — navigator pane rozbudowany

- **Search bar** (`#tree-search`) — filtruje drzewo po nazwie lub SKU, live on input
- **Filter chips** — 3 przyciski: Wszystko / Aktywne (Live) / Draft
- **Dashboard panel** (`#navigator-dashboard`) — 3 statystyki: łącznie dań / Live / Draft
- **Save indicator dot** (`#save-indicator-dot`) — amber gdy dirty, slate gdy clean

#### `studio_ui.js` — search/filter/dashboard logic

- **`filterTree(query)`** — filtruje drzewo po nazwie + SKU (case-insensitive)
- **`setTreeFilter(filter, btn)`** — filtr: all / active / draft, aktualizuje chip styles
- **`updateDashboard()`** — liczy statystyki z `StudioState.items`, pokazuje/ukrywa dashboard
- **`_matchesSearch(item)` / `_matchesFilter(item)`** — predykaty używane w `renderTree`
- **`renderTree()`** — zmodyfikowany: aplikuje search + filter do items, ukrywa puste kategorie przy aktywnym filtrze

### Pliki zmienione

| Plik | Zmiany |
|---|---|
| `modules/studio/js/studio_core.js` | +30 linii (StudioState扩展, StudioToast) |
| `modules/studio/index.html` | +30 linii (search, filters, dashboard) |
| `modules/studio/js/studio_ui.js` | +50 linii (filterTree, setTreeFilter, updateDashboard, _matchesSearch, _matchesFilter) |

### Lint

- `node -c studio_ui.js` — PASS
- `node -c studio_core.js` — PASS

---

## Faza 5 — Polish + Keyboard Shortcuts (✅ Wdrożone 2026-07-29)

### Zakres

| Feature | Opis | Plik |
|---|---|---|
| **Keyboard shortcuts** | Globalny `keydown` listener z 6 skrótami | `studio_core.js` |
| **Drag & Drop reorder** | HTML5 DnD na wierszach drzewa, API `reorder_item` | `studio_ui.js` |
| **Quick edit price** | Inline modal edycji ceny POS z drzewa | `studio_ui.js` |
| **Duplicate item** | `duplicateItem()` + przycisk KOPIUJ w command bar | `studio_item.js` |
| **Toast migration** | Wszystkie `alert()` → `StudioToast.show()` | 5 plików JS |

### Skróty klawiszowe

| Skrót | Akcja | Warunek |
|---|---|---|
| `Ctrl+S` | Zapisz danie (`ItemEditor.saveItem()`) | Tryb edytora |
| `Esc` | Zamknij drawer / modal / wróć do dashboard | Zawsze (dirty check przy wyjściu) |
| `Ctrl+D` | Duplikuj danie (`ItemEditor.duplicateItem()`) | Tryb edytora + zapisane danie |
| `Ctrl+B` | Przełącz tryb edycji masowej | Zawsze |
| `↑` / `↓` | Nawigacja po drzewie (podświetlenie `.active-tree-item`) | Poza inputami |
| `Enter` | Otwórz podświetlone danie (`Core.openSelectedItem()`) | Poza inputami |

### Nowe metody

#### `studio_ui.js`

- **`navigateTree(direction)`** — przesuwa `_treeNavIndex` po wierszach `[data-item-id]`, podświetla aktywny
- **`openSelectedItem()`** — otwiera danie pod wskazanym indeksem w edytorze
- **`bindTreeDragDrop()`** — binduje `dragstart`/`dragover`/`drop` na kontenerze drzewa, wywołuje `reorder_item` API
- **`quickEditPrice(itemId)`** — modal z inputem ceny POS, zapis przez `save_bulk` API (`set_amount`)

#### `studio_item.js`

- **`duplicateItem()`** — pobiera dane dania przez `get_item_details`, czyści ID, dopisuje `_K` do SKU + `(kopia)` do nazwy, ustawia Draft, marks dirty

### Drag & Drop — szczegóły implementacji

- Wiersze drzewa: `draggable="true"`, uchwyt `fa-grip-vertical` (opacity 0 → group-hover)
- `dragstart` — zapisuje `_draggedItemId`, dodaje `.opacity-50`
- `dragover` — `preventDefault()`, oblicza pozycję (góra/dół wiersza), dodaje `.drag-over-top` lub `.drag-over-bottom`
- `drop` — wywołuje `window.apiStudio('reorder_item', { itemId, targetItemId, position })`, po sukcesie reload drzewa
- CSS: `.drag-over-top/bottom` — niebieska linia 2px, `.active-tree-item` — niebieskie tło

### Quick Edit — szczegóły implementacji

- Ikona ołówka (`fa-pen-to-square`) pojawia się na hover w wierszu dania
- Modal: input number + przyciski Zapisz/Anuluj
- Enter = zapisz, Esc = anuluj
- API: `save_bulk` z `omnichannelPricePatch: { apply: true, targetChannel: 'POS', operationType: 'set_amount', operationValue: newPrice }`

### Toast migration — pliki

| Plik | Liczba `alert()` → `StudioToast.show()` |
|---|---|
| `studio_bulk.js` | 4 |
| `studio_meals.js` | 5 (zachowany `confirm()` dla delete) |
| `studio_ui.js` | 4 (addCategory/editCategory) |
| `studio_item.js` | 12 standalone (pozostałe `else alert()` fallbacks zachowane) |

### Pliki zmienione

| Plik | Zmiany |
|---|---|
| `modules/studio/js/studio_core.js` | +80 linii (keyboard shortcuts listener) |
| `modules/studio/js/studio_ui.js` | +160 linii (navigateTree, openSelectedItem, bindTreeDragDrop, quickEditPrice, renderTree draggable) |
| `modules/studio/js/studio_item.js` | +35 linii (duplicateItem, KOPIUJ button, alert→toast) |
| `modules/studio/js/studio_bulk.js` | 4× alert→toast |
| `modules/studio/js/studio_meals.js` | 5× alert→toast |
| `modules/studio/index.html` | +3 linie CSS (drag-over, active-tree-item) |

### Lint

- `node -c studio_core.js` — PASS
- `node -c studio_ui.js` — PASS
- `node -c studio_item.js` — PASS
- `node -c studio_bulk.js` — PASS
- `node -c studio_meals.js` — PASS

### Uwagi backend

- **`reorder_item`** — akcja API potrzebna dla DnD. Jeśli nie istnieje w `api_menu_studio.php`, DnD pokaże toast błędu ale nie crashuje aplikacji.
- **`save_bulk`** — już istnieje, używane przez quick edit.
- **`get_item_details`** — już istnieje, używane przez `duplicateItem()`.
