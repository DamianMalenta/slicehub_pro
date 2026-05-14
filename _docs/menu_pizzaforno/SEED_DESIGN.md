# Pizza Forno → SliceHub Pro — Seed Design Document

## Cel

Wgrać kompletne menu Pizzerii Forno (źródło: choiceqr.com export) jako produkcyjny seed do bazy SliceHub Pro. Dodatkowo zapełnić warstwy operacyjne (magazyn, faktury, KSeF inbox, zamówienia) tak żeby system wyglądał i zachowywał się jak działająca pizzeria — nie demo sandbox.

## Pliki źródłowe

| Plik | Zawartość | Rozmiar |
|------|-----------|---------|
| `_docs/menu_pizzaforno/menu (14).xlsx` | 190 menu items × 17 kategorii | 139 KB |
| `_docs/menu_pizzaforno/additions.xlsx` | 275 modifierów × 25 setów | 211 KB |

**Źródło**: export z choiceqr.com (system POS Pizzerii Forno). **Nie dostosowujemy się do choiceqr** — bierzemy tylko dane menu.

## Mapping danych Pizza Forno → SliceHub schema

### 1. Kategorie (17 → 16 po consolidacji)

| Pizza Forno | SliceHub `sh_categories.name` | Komentarz |
|-------------|-------------------------------|-----------|
| `PIZZA 30 CM` + `PIZZA 37 CM` | **`PIZZE`** (jedna kategoria) | Rozmiar to wariant, nie osobna kategoria |
| `PANINI` | `PANINI` | MAŁE/DUŻE jako warianty (na sufiks rozpoznać) |
| `MAKARONY` | `MAKARONY` | |
| `CALZONE` | `CALZONE` | |
| `FOCCACIA` | `FOCCACIA` | |
| `ZAPIEKANKI` | `ZAPIEKANKI` | |
| `GYROSY` | `GYROSY` | |
| `SAŁATKI` | `SAŁATKI` | |
| `SOSY` | `SOSY` | |
| `DESERY` | `DESERY` | |
| `DLA DZIECI` | `DLA DZIECI` | |
| `NAPOJE ZIMNE` | `NAPOJE` | |
| `PIWA` | `PIWA` | VAT inny (23%) |
| `POZOSTAŁE` | `POZOSTAŁE` | |
| `NOWOŚCI !!!` | `NOWOŚCI` | Tagged jako "promo" badge |
| `ZIMOWE MENU` | `ZIMOWE MENU` | Sezonowe — `valid_from/to` ustawione |

### 2. Variant Scales (`sh_variant_scales` + `sh_variant_scale_options`)

Tworzymy **2 skale**:

#### Skala "Pizza Round" (id=1)

| key_ascii | name | multiplier | display_order |
|-----------|------|-----------|---------------|
| `30CM` | 30 cm | 1.000 | 0 |
| `37CM` | 37 cm | 1.500 | 1 |

Stosowana do wszystkich items z kategorii `PIZZA 30 CM` / `PIZZA 37 CM`.

Nazwa pizzy bez sufiksu rozmiaru = **parent** (np. "MARGHERITA"). Sufiks "30cm"/"37cm" = **child**.

#### Skala "Panini Size" (id=2)

| key_ascii | name | multiplier | display_order |
|-----------|------|-----------|---------------|
| `MALE` | MAŁE | 1.000 | 0 |
| `DUZE` | DUŻE | 1.500 | 1 |

Stosowana do PANINI z sufiksem "- MAŁE" / "- DUŻE".

### 3. Menu items (`sh_menu_items`)

#### Pizza family (np. MARGHERITA)

```
parent: ascii_key='MARGHERITA', name='MARGHERITA', is_variant_parent=1, variant_scale_id=1
  child 30cm: ascii_key='MARGHERITA_30CM', parent_item_id=parent.id, variant_option_id=opt30.id, multiplier=1.0
  child 37cm: ascii_key='MARGHERITA_37CM', parent_item_id=parent.id, variant_option_id=opt37.id, multiplier=1.5
```

#### Reguła generowania `ascii_key`

```python
def make_sku(name: str) -> str:
    # 1. Polish diacritics → ASCII
    # 2. Lowercase → uppercase
    # 3. Non-alphanumeric → underscore
    # 4. Multiple underscores → single
    # 5. Trim trailing/leading underscore
    return name.replace('ą','A').replace('ę','E')... \
               .upper().replace(' ', '_').replace('-', '_') \
               .replace('!','').replace('?','').replace('.','') \
               # then collapse __+ to _
```

Przykłady:
- "MARGHERITA 30cm" → parent: `MARGHERITA`, child: `MARGHERITA_30CM`
- "PANINI STEKY - MAŁE" → parent: `PANINI_STEKY`, child: `PANINI_STEKY_MALE`
- "FOCCACIA ROSMARINO" → `FOCCACIA_ROSMARINO` (no variants)

### 4. Price tiers (`sh_price_tiers`)

Pizza Forno ma **jedną cenę** w xlsx (kolumna Price). Dla SliceHub mapujemy na 3 kanały:

| Channel | Price |
|---------|-------|
| `POS` (sala) | xlsx_price |
| `Takeaway` | xlsx_price |
| `Delivery` | xlsx_price + 0 (bez markup — Pizza Forno ich nie ma) |

Przykład MARGHERITA 30cm: POS=27, Takeaway=27, Delivery=27.

### 5. Modifier groups (`sh_modifier_groups`)

25 setów → 25 grup z konwencją:

| Pizza Forno Set | SliceHub `name` | min/max | required |
|-----------------|-----------------|---------|----------|
| `Sosy do wyboru` (single, Y) | "Sos bazowy" | 1/1 | TAK |
| `Dodatkowe warzywa 30 cm` (multiple, N) | "Dodatkowe warzywa" | 0/N | NIE |
| `Dodatkowe warzywa 37 cm` (multiple, N) | (consolidate z 30 cm) | | |
| `Wybierz 2 połówki 30 cm` (multiple, Y) | "Half-and-half" | 2/2 | TAK |
| `Sosy do pizzy` (multiple, N) | "Sosy dodatkowe" | 0/3 | NIE |
| ... | ... | | |

**Konsolidacja "30 cm"/"37 cm"**: zamiast 2 grup ("Dodatkowe warzywa 30 cm" + "Dodatkowe warzywa 37 cm"), tworzymy **1 grupę** "Dodatkowe warzywa" z modifierami które mają **F-S2 Topping Size Pricing** — 1 modifier `pieczarki` z cenami per `variant_option_id`:
- `variant_option_id=30CM` → 3 zł
- `variant_option_id=37CM` → 5 zł

Zapis w `sh_modifier_pricing`:
```sql
INSERT INTO sh_modifier_pricing (modifier_id, variant_option_id, price_grosze)
VALUES (mod_pieczarki.id, opt_30cm.id, 300);
VALUES (mod_pieczarki.id, opt_37cm.id, 500);
```

### 6. Modifiers (`sh_modifiers` + `sh_modifier_pricing`)

Dla każdej grupy + każdej unikalnej nazwy modifier'a:
- Bazowa cena (`base_price_grosze`) — jeśli nie ma podziału per rozmiar (np. "pomidorowy" sos jest 0 zł zawsze)
- Cena per variant_option (`sh_modifier_pricing`) — gdy w xlsx widać różne ceny per "30 cm" vs "37 cm"

### 7. Recipes (`sh_recipes`) — z parsowania Description

Pole "Description" w xlsx zawiera składniki rozdzielone `/`:
```
"buffalo mozzarella / smażone pieczarki / włoska kiełbasa / Nduja / ricotta"
```

Parsujemy + dla każdego składnika tworzymy linię receptury z **przybliżoną ilością** (heuristic):

| Słowo kluczowe | Ilość bazowa | Jednostka |
|----------------|--------------|-----------|
| `mozzarella`, `cheese`, `ser` | 80 | g |
| `sos`, `pomidor` | 60 | g |
| `pieczarki`, `papryka`, `cebula` | 40 | g |
| `salami`, `szynka`, `kurczak`, `boczek` | 60 | g |
| (inne) | 30 | g |

Multiplier z variant_option auto-skaluje (37cm = 1.5× ilość).

Składnik linkowany do `sys_items.sku` przez `warehouse_sku` (musi pasować — patrz pkt 8).

### 8. Sys items (`sys_items`) — słownik surowców

Wyciąć z **wszystkich** Description + nazw modifierów unikalne ingredients (~60-80 SKU):

| sku | name | unit | category |
|-----|------|------|----------|
| `MAKA_TYP_00` | Mąka pszenna typ 00 | kg | suche |
| `SOS_POMIDOROWY` | Sos pomidorowy passata | l | sosy |
| `MOZZARELLA` | Mozzarella fior di latte | kg | sery |
| `MOZZARELLA_BUFFALO` | Mozzarella di buffalo | kg | sery |
| `RICOTTA` | Ricotta | kg | sery |
| `PARMEZAN` | Parmezan | kg | sery |
| `SALAMI_PICANTE` | Salami picante | kg | mięsa |
| `KURCZAK_PIERS` | Kurczak (pierś) | kg | mięsa |
| `STEK_WOLOWY` | Stek wołowy | kg | mięsa |
| `PIECZARKI` | Pieczarki | kg | warzywa |
| `RUKOLA` | Rukola | kg | warzywa |
| `POMIDORKI_KOKTAJLOWE` | Pomidorki koktajlowe | kg | warzywa |
| `OREGANO` | Oregano | kg | przyprawy |
| `OLIWA` | Oliwa z oliwek | l | tłuszcze |
| ... | ... | | |

### 9. Stany magazynowe (`wh_stock`)

Dla każdego `sys_items` ustawiamy startowy stan + AVCO koszt:

| sku | qty_on_hand | unit | avg_cost_grosze |
|-----|-------------|------|-----------------|
| `MAKA_TYP_00` | 50.000 | kg | 350 (3.50 zł/kg) |
| `MOZZARELLA` | 20.000 | kg | 4500 |
| `SALAMI_PICANTE` | 8.000 | kg | 4200 |
| `RUKOLA` | 5.000 | kg | 1800 |
| ... | ... | | |

### 10. Faktury PZ (`wh_documents` + `wh_document_lines`)

3-5 testowych przyjęć (PZ) z ostatnich 30 dni. Każda PZ ma 5-15 linii surowców z `sys_items`.

Przykład:
- PZ-2026/05/001 (10 dni temu, "HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o."): mąka, sosy, sery
- PZ-2026/05/002 (5 dni temu, "FRUKTUS — Warzywa"): rukola, pomidorki, papryka
- PZ-2026/05/003 (wczoraj, "DI MARCO — Włoskie Specjały"): mozzarella buffalo, parmezan, oliwa

### 11. KSeF Inbox (`sh_ksef_invoices` + `sh_ksef_invoice_lines`)

3 faktury z różnymi statusami:
- 1× `status='new'` — czeka na akceptację (dla user'a do nagrania demo)
- 1× `status='accepted'` — przerobione na PZ historycznie
- 1× `status='processing'` — w trakcie AutoScan match'owania

### 12. Zamówienia testowe (`sh_orders` + `sh_order_lines`)

5-8 zamówień w różnych stanach:

| order_number | channel | status | customer | grand_total |
|--------------|---------|--------|----------|-------------|
| FORNO-001 | Delivery | accepted | Jan Kowalski (paid card) | ~85 zł (Pizza + dodatki) |
| FORNO-002 | Delivery | preparing | Anna Nowak (paid online) | ~62 zł |
| FORNO-003 | Takeaway | new | Marcin Wójcik (cash) | ~38 zł |
| FORNO-004 | POS (dine-in) | preparing | Stolik 4 (Para) | ~120 zł |
| FORNO-005 | Delivery | delivered | Kasia Zalewska (historic) | ~95 zł |
| FORNO-006 | Delivery | in_route | Piotr Nowicki | ~75 zł (przypisany do Jana Wiśniewskiego z poprz. seed) |
| FORNO-007 | Online | new | Tomek Bąk | ~45 zł |
| FORNO-008 | POS | completed | Stolik 8 (Rodzina) | ~180 zł |

## Strategia wykonania

**1 skrypt Python `scripts/seed_pizzaforno_build.py`** który:
1. Czyta oba xlsx
2. Parsuje + transformuje wg powyższego mappingu
3. Generuje SQL fixture `scripts/seed_pizzaforno.sql` (idempotentny)

**1 plik SQL `scripts/seed_pizzaforno.sql`** który:
1. Cleanup na początku (DELETE WHERE created_by_seed='pizza_forno' OR ascii_key LIKE 'FORNO_%')
2. Wszystkie INSERT'y w transakcji
3. Sprawdzenie końcowe (`SELECT COUNT(*) ...`)

User uruchamia **tylko SQL** w phpMyAdmin (Python tylko do regenerowania jeśli pliki xlsx się zmienią).

## Idempotency

Każdy seed-owy rekord ma marker:
- `sh_categories`: kolumna `name` z prefiksem unikalnym (lub kolumna `created_by` jeśli istnieje, inaczej rozpoznajemy po znanej liście nazw)
- `sh_menu_items`: `ascii_key` LIKE wzorzec rozpoznawalny
- `sh_modifier_groups`: identyfikator po nazwie
- `sh_orders`: `order_number` LIKE 'FORNO-%'
- `sh_ksef_invoices`: `invoice_number` LIKE 'FA/FORNO/%'
- `wh_documents`: `doc_number` LIKE 'PZ-2026/05/FORNO%'

## Walidacja

Po seedzie sprawdzić:
1. Liczba menu items (≥190)
2. Liczba modifierów (≥275 lub mniej po consolidacji)
3. Każda variant family ma 2 children
4. Każdy `parent_item_id` istnieje w `sh_menu_items`
5. Każdy `variant_option_id` istnieje w `sh_variant_scale_options`
6. Każdy ingredient w `sh_recipes.warehouse_sku` istnieje w `sys_items.sku`
7. Każda PZ ma `wh_stock` zaktualizowany (qty_on_hand zwiększony o sumę z linii)
8. Wszystkie KSeF faktury parseable

## Co NIE robi seed (out of scope)

- Płacenia / fiskalizacji (testowe, nie podłączamy realnej drukarki)
- Zdjęć dań (zostawiamy `image_url=NULL`, można zaimportować z choiceqr osobno)
- Real KSeF API — to fixture do inboxu, nie integracja
- User accounts (kasjerów, kelnerów) — osobny seed
- Driver app dane — używamy istniejących z `demo_seed_dynamic_data.sql`
