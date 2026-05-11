# AI Session Audit — Final Follow-ups: F-S8 + F-S1.2 + F-S6.1 + F-S7 + F-S9

**Data:** 2026-05-11
**Branch:** `projektx/release-bundle-c3d7` (continuation)
**Konstytucja:** v5 (Prawa II, IV, VIII, X)
**Trigger:** „ruszaj z wszystkim po kolei w najlepszej według ciebie kolejności w jednej sesji"

---

## 1. Cel

Zamknąć **cały otwarty dług** po jednej sesji:
1. **F-S8** najpierw — krytyczna weryfikacja że combo cancel + reverse stock działa (audit-only).
2. **F-S1.2** — szybki preset auto-multiplier.
3. **F-S6.1** — wizard step 5 (modifier groups).
4. **F-S7** — hard PRICE_MISMATCH (tenant flag).
5. **F-S9** — recipe drag-and-drop reorder.

---

## 2. F-S8 — Combo reverse stock (audit-only)

**Hipoteza:** F-S3.2 combo expansion + F-S5 subrecipe rekursja + WarehouseReverseHook powinny współgrać bez dodatkowego kodu, bo WzEngine zapisuje `wh_document_lines` per **surowiec** (nie per combo), a Reverse hook bierze sumy z tych linii.

**Test:** Combo Family Deal (1× Pizza + 2× Fries + 1× Cola) → accept → consume → cancel → reverse.

**Wynik:**
```
BEFORE:    FLOUR=10  POTATO=10  COLA_RAW=10
CONSUME → FLOUR=0.25 POTATO=0.40 COLA_RAW=0.50 zapisane w WZ jako 3 lines
AFTER:     FLOUR=9.75 POTATO=9.6  COLA_RAW=9.5
REVERSE → KOR z 3 lines, wh_stock += original
FINAL:     FLOUR=10  POTATO=10  COLA_RAW=10  ✅
WZ status='reversed' ✅
```

**Wniosek:** brak zmian w kodzie. F-S3.2 + F-S5 + Reverse hook to clean orthogonal layers — pełna spójność.

---

## 3. F-S1.2 — Variant scale presets

### Auto-multiplier z sufiksu w `migrate_parent_sku_to_variants.php`
Mapa presetów: `XS=0.55, S=0.70, M=1.00, L=1.30, XL=1.60, XXL=2.00, MINI=0.55, BIG=1.50, REG/STD/NORMAL/MEDIUM=1.00, LARGE=1.30, SMALL=0.70` + średnice `26..45 cm`.

Skrypt teraz przy legacy migracji **nie ustawia hard-coded 1.0** — dopasowuje multiplier z presetu na podstawie sufiksu klucza.

### Presety w Studio variant scale manager
Przycisk **Preset** obok **Nowa Skala** otwiera modal z 5 gotowymi:
1. 🍕 Pizza 4 rozmiary (26/32/36/40 cm) — multipliers 0.7/1.0/1.3/1.6
2. 🍕 Pizza 3 rozmiary (S/M/L) — 0.7/1.0/1.3
3. ☕ Coffee S/M/L — 0.75/1.0/1.4
4. 🥤 Napój 0.33/0.5/1.0L — 0.33/0.5/1.0
5. 🍟 Frytki Standard/Duże — 1.0/1.5

Klik dodaje skalę do listy + auto-rename gdy konflikt klucza.

### E2E
```
Legacy migracja 4 children S/M/L/XL:
  FS12TEST_S  → multiplier 0.7 from preset ✅
  FS12TEST_M  → multiplier 1.0
  FS12TEST_L  → multiplier 1.3 from preset ✅
  FS12TEST_XL → multiplier 1.6 from preset ✅
```

---

## 4. F-S6.1 — Wizard "Nowa Pizza" step 5: modifier groups

Wizard z F-S6 rozbudowany z 4 do **5 kroków**:
1. Nazwa
2. Rozmiary (skala)
3. Ceny per kanał per opcja
4. **🆕 Modyfikatory** — lista checkbox grup modyfikatorów tenanta
5. Generuj

### Implementacja
- `_fs6LoadModifierGroups()` — POST `get_modifiers_full` → renderowanie checkbox listy.
- `_fs6Step(direction)` — zbiera `modifierGroupIds` przy przejściu 4→5.
- `_fs6Generate()` — po `create_variant_family`, dla każdego stworzonego child wywołuje `update_item_full` z `modifierGroupIds`.
- `_fs6RenderSummary()` — pokazuje liczbę wybranych grup.

### UX
Manager może pominąć krok 4 (puste checkboxy). Wówczas warianty są tworzone bez modyfikatorów (dodanie później ręcznie).

---

## 5. F-S7 — Hard PRICE_MISMATCH

### Wcześniej (F5-B, soft)
`process_order` nadpisywało cenę klienta serwerową, tylko logowało diff>1gr do `error_log`.

### Teraz
Nowa flaga `sh_tenant_settings.price_mismatch_mode`:
- `soft` (default): jak wcześniej — log + override + kontynuuj.
- `hard`: HTTP 409 + JSON `{error_code: 'PRICE_MISMATCH', client_total_grosze, server_total_grosze, diff_grosze, hint}`.

### API w Studio
- `get_price_mismatch_mode` — zwraca aktualną flagę (`soft` lub `hard`).
- `set_price_mismatch_mode {mode: 'hard'|'soft'}` — owner/admin może przełączać.

### Use case
1. Manager wgrywa system, zaczyna obserwować `error_log` przez 2 tygodnie.
2. Jeśli zero PRICE_MISMATCH'ów → przełącza na `hard` przez API call.
3. Po hard: każda rozjazd ceny dla POS → 409 → frontend musi odświeżyć menu i ponowić.

### Konstytucja v5 § Prawo IV (Zero Zaufania)
Soft → hard to **dojrzewanie** Prawa IV. Soft pozwala na obserwację skali drift'u przed twardym wymuszeniem.

---

## 6. F-S9 — Recipe drag-and-drop reorder

### Schema (`055_recipe_display_order.sql`)
```sql
ALTER TABLE sh_recipes ADD COLUMN display_order INT NOT NULL DEFAULT 0;
UPDATE sh_recipes SET display_order = id WHERE display_order = 0;
```

### Backend
- `get_item_recipe`: `ORDER BY display_order ASC, id ASC` (schema-aware). Zwraca `displayOrder` w response.
- `save_recipe`: schema-aware INSERT z `display_order` (preferuje payload, fallback do indeksu pętli).

### Frontend (`studio_recipe.js`)
- Recipe row ma `data-row-idx` + `draggable="true"` + grip handle (`fa-grip-vertical`).
- `_wireRecipeDragDrop()` — native HTML5 events: `dragstart` / `dragover` / `drop`:
  - `dragover`: orange border highlight.
  - `drop`: reorder `this.state.currentRecipe` in-memory + przeliczenie `displayOrder` na bazie aktualnej kolejności + re-render.
- Save → propagacja displayOrder do backendu.

### E2E
```
Recipe z 3 składnikami, każdy z own display_order (0, 1, 2):
  TOMATO display_order=0
  CHEESE display_order=1
  FLOUR  display_order=2
SELECT ORDER BY display_order ASC zwraca: TOMATO, CHEESE, FLOUR  ✅
(Zamiast id ASC = sorted by insertion time)
```

---

## 7. Konstytucja v5

### Prawo II (Bliźniak Cyfrowy)
- F-S8: weryfikacja że bliźniak combo↔magazyn działa w obu kierunkach (consume + reverse).

### Prawo IV (Zero Zaufania)
- F-S7: pełna implementacja Hard PRICE_MISMATCH (po dojrzewaniu soft).

### Prawo VIII (Domknięcie Kontraktu)
- F-S1.2: presety + auto-multiplier zamykają lukę "po legacy migracji wszystkie multipliers=1.0".
- F-S6.1: wizard tworzy KOMPLETNE warianty z modyfikatorami (przed F-S6.1 manager musiał dodawać je ręcznie).
- F-S9: display_order zamyka UX dług "kolejność wpisów ID-discovered nie odzwierciedla woli managera".

### Prawo X (Audyt Sesji AI)
- Ten plik.

---

## 8. Pełna lista zmian

```
database/migrations/055_recipe_display_order.sql      NEW
scripts/_migrations_chain.php                          055 entry
scripts/migrate_parent_sku_to_variants.php             auto-multiplier presets
api/pos/engine.php                                     F-S7: hard PRICE_MISMATCH
api/backoffice/api_menu_studio.php                     F-S7 mode get/set + F-S9 schema-aware
modules/studio/js/studio_item.js                       F-S6.1 wizard step 5 + F-S1.2 presets
modules/studio/js/studio_recipe.js                     F-S9 drag-and-drop
_docs/sessions/2026-05-11_final_followups_fs8_fs12_fs61_fs7_fs9.md   NEW
_docs/02_ARCHITEKTURA.md                               bump
```

---

## 9. Final state: ZERO OPEN DEBT (z planu po audycie Studio)

| Tag | Co | Status |
|---|---|---|
| F5 | POS Integrity Pass | ✅ |
| F6 | Geocoder | ✅ |
| F-S1 | Variant Scales | ✅ |
| F-S1.2 | Variant scale presets | ✅ |
| F-S2 | Topping Size Pricing | ✅ |
| F-S2.1 | Studio UI Size Pricing | ✅ |
| F-S3 | Meal Packages backend | ✅ |
| F-S3.1 | POS UI combo wizard | ✅ |
| F-S3.2 | WzEngine combo expansion | ✅ |
| F-S4 | Studio drift cleanup | ✅ |
| F-S5 | Multi-stage recipes | ✅ |
| F-S5.1 | Studio UI subrecipe | ✅ |
| F-S6 | Wizard Nowa Pizza | ✅ |
| F-S6.1 | Wizard step 5 modifier groups | ✅ |
| F-S7 | Hard PRICE_MISMATCH | ✅ |
| F-S8 | Verify combo reverse stock | ✅ (audit) |
| F-S9 | Recipe reorder | ✅ |

**Wszystkie zaplanowane fazy gotowe do testów na uti.pl.**
