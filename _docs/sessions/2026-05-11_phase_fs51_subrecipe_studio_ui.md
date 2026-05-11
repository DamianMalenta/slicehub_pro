# AI Session Audit — F-S5.1 Studio UI dla półproduktów

**Data:** 2026-05-11
**Branch:** `projektx/release-bundle-c3d7` (continuation)
**Konstytucja:** v5 (Prawa II, VI, VIII, X)
**Trigger:** „ruszaj F-S5.1"

---

## 1. Cel

F-S5 dodało schema (`is_subrecipe`, `subrecipe_yield`) i WzEngine rekurencyjną ekspansję, ale **brakowało UI** żeby manager mógł oznaczyć linię receptury jako półprodukt. Dotąd half-fixami musiałby modyfikować bazę przez SQL.

F-S5.1 domyka kontrakt — kompletny UX:
1. W edytorze receptury każda linia ma przycisk `recycle` (toggle półprodukt ↔ surowiec).
2. Klik na recycle → modal picker półproduktu (lista pozycji menu z własną recepturą).
3. Po wyborze: linia dostaje orange highlight + badge `SUB ×N` (yield) + input do edycji yield.
4. Save → API zapisuje pola; reload pokazuje stan z powrotem.

---

## 2. Zakres

| Plik | Zmiana |
|---|---|
| `api/backoffice/api_menu_studio.php` | `get_item_recipe` LEFT JOIN (sys_items + sh_menu_items) + zwraca `isSubrecipe`/`subrecipeYield`; `save_recipe` schema-aware INSERT + walidacja anti-cycle; **NEW** `list_subrecipe_candidates` |
| `modules/studio/js/studio_recipe.js` | Linia receptury: recycle button + warunkowy yield input + orange highlight; `toggleSubrecipe(idx)`, `updateSubrecipeYield(idx, val)`, `_openSubrecipePicker(candidates, onPicked)` |

**Brak migracji** — schema z F-S5 (migracja 053) wystarcza.

---

## 3. UX flow

### Edycja receptury (np. Pizza)
1. Manager otwiera Studio → wybiera "Pizza" z drzewa → ładuje recepturę.
2. Widzi linie: `0.25 kg flour`, `0.05 kg cheese`, `... raw badge`.
3. Klika **recycle** przy `cheese`. Zmienia decyzję: w tej pizzy cheese to nie surowiec ale półprodukt (np. "mieszanka serów").
4. Modal pokazuje listę kandydatów: każda pozycja menu z recepturą (sos, mieszanka serów, ciasto).
5. Wybiera "Mieszanka Serów" → linia zamienia się na orange highlight z badge `SUB ×10`.
6. Edytuje yield (np. 8 — z 1 garnka miksu sera dostaje 8 porcji do pizzy).
7. Zapis. WzEngine przy konsumpcji magazynu **automatycznie expanduje**: 1 pizza → 1/8 batchu miksu → surowce miksu (cheddar, mozzarella, parmesan) × proporcja.

### Anti-cycle
- Walidacja serwerowa: linia receptury **nie może** wskazywać sam siebie (`menu_item_sku == warehouse_sku` przy `is_subrecipe=1`).
- Walidacja serwerowa: półprodukt musi istnieć w `sh_menu_items` (chronimy przed sierotami).
- WzEngine ma własny **max depth 3** (chroni przed A→B→A→B... w przyszłości).

---

## 4. Test E2E (3 PASSED)

```
Test 1 — list_subrecipe_candidates:
  Zwraca FS51_SAUCE (1 recipe line), wykluczając variant_parents.
  ✅ Filter na is_variant_parent=0 działa.

Test 2 — save_recipe + get_item_recipe LEFT JOIN:
  Pizza: 0.25 flour (raw) + 1.0 SAUCE (is_subrecipe=1, yield=20)
  Readback:
    FLOUR: name z sys_items, base_unit=kg, is_subrecipe=0
    SAUCE: name z sh_menu_items, base_unit fallback='porcja', is_subrecipe=1, yield=20
  ✅ COALESCE name + base_unit działa dla obu typów linii.

Test 3 — anti-cycle:
  Detection: gdy menu_item_sku == warehouse_sku przy is_subrecipe=1,
  backend wpisuje do 'skipped' z powodem "(cycle)".
  ✅
```

---

## 5. Konstytucja v5

### Prawo II (Bliźniak Cyfrowy)
- Półprodukt w UI = real "заготовка" w kuchni. WzEngine konsumuje surowce zgodnie z faktycznym przepływem.

### Prawo VI (Snajper)
- Schema-aware probes w `get_item_recipe` i `save_recipe` (graceful gdy migracja 053 brak).
- LEFT JOIN cross-silo (sys_items + sh_menu_items po `tenant_id` i kluczu znakowym `sku`/`ascii_key`).
- Walidacja FK w PHP zamiast w schemacie — bo `warehouse_sku` może wskazywać na DWA różne typy (Prawo §9 dopuszcza klucze znakowe między silosami).

### Prawo VIII (Domknięcie Kontraktu)
- `list_subrecipe_candidates` call-site: `studio_recipe.js#toggleSubrecipe` (klik recycle).
- `save_recipe` z `isSubrecipe` call-site: `studio_recipe.js#saveItemRecipe` (każdy zapis).
- F-S5 backend dostał teraz pełen UI = domknięcie kontraktu między schema, silnikiem, i UX.

### Prawo X (Audyt Sesji AI)
- Ten plik.

---

## 6. Dług zamknięty + otwarty

### Zamknięte
- ✅ F-S5 schema + WzEngine (poprzednia sesja)
- ✅ **F-S5.1 Studio UI** — TEN PR

### Pozostały otwarty dług
| Temat | Prio | Faza |
|---|---|---|
| F-S6.1 Wizard step 5: default modifier groups | P3 | Otwarte |
| F-S7 Hard PRICE_MISMATCH | P2 | Otwarte |
| F-S1.2 Variant scale presets (auto-multiplier z sufiksu) | P3 | Otwarte |
| F-S8 Verify combo reverse stock przy cancel po accept | P1 | Otwarte |
| F-S9 Multi-step recipe drag-and-drop reorder | P3 | Otwarte (UX) |
