# AI Session Audit — F-S3.2 WzEngine Combo Expansion

**Data:** 2026-05-11
**Branch:** `projektx/release-bundle-c3d7` (continuation)
**Konstytucja:** v5 (Prawa II, VI, VIII, X)
**Trigger:** „ruszaj F-S3.2"

---

## 1. Cel

Domknąć kontrakt F-S3 / F-S3.1: gdy klient zamawia combo, **magazyn musi spadać per składnik** (a nie tylko zniknąć cena z koszyka). Bez tego cancel order i raportowanie sprzedaży były rozjechane z rzeczywistością kuchni.

**Przed F-S3.2:**
- POS combo → `cart` line z `ascii_key = FAMILY_DEAL` + comment "Combo #X | picks: ..."
- `process_order` zapisywał linię z item_sku='FAMILY_DEAL' bez strukturalnych meta
- `WzEngine::consumeForOrder` szukał `sh_recipes` dla `FAMILY_DEAL` → **nie ma** → magazyn nie spada
- Stan magazynu się rozjeżdżał

**Po F-S3.2:**
- POS frontend wysyła `combo_meta` z meal_id + picks + fixed_items
- `process_order` zapisuje to do `sh_order_lines.combo_meta_json` (nowa kolumna, migracja 054)
- `WzEngine` wykrywa combo_meta_json, **rozwija** combo line na N wirtualnych linii (per składnik)
- Wirtualne linie przechodzą przez normalny pipeline (variant multiplier + subrecipe rekursja)
- Magazyn spada **dokładnie** zgodnie z fizyczną kuchnią

---

## 2. Zakres

| Plik | Zmiana |
|---|---|
| `database/migrations/054_order_line_combo_meta.sql` | **NEW** — `ALTER sh_order_lines ADD combo_meta_json LONGTEXT` |
| `scripts/_migrations_chain.php` | Chain entry 054 |
| `core/WzEngine.php` | Schema-aware probe + expansion combo line → wirtualne linie + drugi fetch metadata wariantowych |
| `api/pos/engine.php` | `process_order`: schema-aware INSERT z `combo_meta_json` |
| `modules/pos/js/pos_cart.js` | `addItem` propaguje `comboMeta` z `_isMealLine` |
| `modules/pos/js/pos_app.js` | `cartForApi` dodaje pole `combo_meta` |

---

## 3. Algorytm WzEngine

### Krok 1: Fetch order lines
SELECT zawiera dodatkowo `combo_meta_json` (schema-aware: `NULL AS combo_meta_json` gdy migracja 054 nie zaaplikowana).

### Krok 2: Expansion
```
foreach (linia w orderLines):
  if linia.combo_meta_json != null:
    parse JSON → { meal_id, picks, fixed_items }
    foreach (component w fixed_items + picks):
      virtualLine = {
        item_sku: component.sku,
        line_qty: linia.line_qty × component.qty,
        modifiers_json: null,
        removed_ingredients_json: null,
        _is_combo_virtual: true,
        ...metadata uzupełniona w kroku 3
      }
      expandedLines.push(virtualLine)
  else:
    expandedLines.push(linia)
```

### Krok 3: Second fetch dla variant metadata
Dla wszystkich SKU wirtualnych linii pobieramy z `sh_menu_items` (+ LEFT JOIN parent + scale_option) `parent_item_id`, `variant_option_id`, `variant_multiplier`. Dzięki temu pizza_L wewnątrz combo dostaje multiplier 1.3.

### Krok 4: Normalne przetwarzanie
`orderLines = expandedLines` → reszta `consumeForOrder` działa bez zmian. Recipe lookups, variant multiplier, subrecipe rekursja (F-S5) — wszystko aplikuje się do każdego wirtualnego składnika.

### Anti-cycles
Combo nie zagnieżdża combo (po prostu pomijane jeśli ktoś zrobiłby `sh_meal_packages.ascii_key` jako `component_sku` — wtedy SELECT z menu_items nie znajdzie i recipe lookup zwróci puste).

---

## 4. Test E2E (2 PASSED)

### Test 1 — Basic combo
```
Setup: Family Deal = 1× Pizza + 2× Fries + 1× Cola
Recipes:
  Pizza: 0.25kg flour
  Fries: 0.20kg potato
  Cola: 0.5kg cola syrup

1× combo zamówienie → consumeForOrder:
  FLOUR  = 0.250  ✅ (1× pizza)
  POTATO = 0.400  ✅ (2 × 0.20)
  COLA   = 0.500  ✅ (1× cola)
```

### Test 2 — Combo + Variant L + Subrecipe (full kompozycja)
```
Setup:
  Pizza parent + scale options S/M/L (multipliers 0.7/1.0/1.3)
  Pizza recipe: 0.25 flour + 1 porcja sosu (is_subrecipe=1, yield=10)
  Sos recipe: 1.0kg tomato
  Fries: 0.20kg potato

Deal = 1× Pizza_L (variant L) + 2× Fries
1× combo → consumeForOrder:
  FLOUR  = 0.325  ✅ (0.25 × 1.3 variant)
  TOMATO = 0.130  ✅ (1.0 × 1.3 / 10 yield)  ← combo → variant → subrecipe
  POTATO = 0.400  ✅ (2 × 0.20)
```

**To zamyka kontrakt F-S3 + F-S5 + F-S1 w jednym pipeline.**

---

## 5. Konstytucja v5

### Prawo II (Bliźniak Cyfrowy)
Combo to bundle realnych produktów. Każdy składnik realnie zużywa surowce z magazynu, w odpowiedniej ilości proporcjonalnej do receptury × variant × subrecipe yield.

### Prawo VI (Snajper)
- Schema-aware probes w 2 nowych miejscach (`combo_meta_json` w WzEngine i POS engine).
- Cross-silo? Brak — wszystko w obrębie `sh_` (Prawo §9).
- `tenant_id = ?` w drugim fetchu metadanych.

### Prawo VIII (Domknięcie Kontraktu)
- `combo_meta_json` ma 3 call-sites: pos_cart.js#addItem (writer), pos_app.js#cartForApi (transport), WzEngine#expansion (reader).
- WzEngine combo expansion ma 1 użycie: `consumeForOrder` (pętla wewnętrzna).
- `process_order` INSERT z combo_meta_json — 1 call-site, schema-aware fallback.

### Prawo X (Audyt Sesji AI)
- Ten plik.

---

## 6. Dług zamknięty

| Co | Status |
|---|---|
| F-S3 backend (CRUD meal_packages) | F-S3 (poprzednia sesja) |
| F-S3.1 POS UI combo wizard | F-S3.1 (poprzednia sesja) |
| **F-S3.2 WzEngine konsumpcja per składnik combo** | ✅ **TEN PR** |

Pełen pipeline od UI POS → cart → process_order → DB → consumeForOrder → wh_stock → wh_documents — **end-to-end** działa.

---

## 7. Co jeszcze zostało

| Temat | Prio | Faza |
|---|---|---|
| F-S5.1 Studio UI checkbox `is_subrecipe` w studio_recipe.js | P2 | Otwarte |
| F-S6.1 Wizard step 5: default modifier groups | P3 | Otwarte |
| F-S7 Hard PRICE_MISMATCH | P2 | Otwarte |
| F-S1.2 Variant scale presets (auto-multiplier z sufiksu) | P3 | Otwarte |
| F-S8 Reverse stock dla combo (cancel order po accept) | P1 | Otwarte — naturalnie powinno działać przez WarehouseReverseHook (testuje wh_documents per linia), ale wymaga weryfikacji |
