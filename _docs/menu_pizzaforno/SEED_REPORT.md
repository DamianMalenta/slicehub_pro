# Seed Pizza Forno — Raport

**Wygenerowany przez:** `scripts/seed_pizzaforno_build.py`
**Data:** 2026-05-14
**Źródło:** `_docs/menu_pizzaforno/menu (14).xlsx` + `additions.xlsx`
**Plik wynikowy:** `scripts/seed_pizzaforno.sql` (361 KB, 6109 linii)

---

## Statystyki liczbowe

| Encja | Liczba | Uwagi |
|-------|--------|-------|
| `sh_menu_items` | **229** | 190 oryginalne + 43 virtual parents |
| Rodziny pizzy (parents) | 32 | 31 par 30cm+37cm + 1 tylko 37cm (PÓŁ NA PÓŁ) |
| Pizza 30cm children | 31 | Variant items, sprzedawalne |
| Pizza 37cm children | 32 | Variant items, sprzedawalne |
| Rodziny panini (parents) | 11 | 11 par MAŁE+DUŻE |
| Panini MAŁE/DUŻE children | 22 | Variant items |
| Single items | 101 | Bez wariantów |
| `sh_modifier_groups` | **19** | 25 oryginalnych setów → 19 po konsolidacji |
| `sh_modifiers` | **158** | |
| `sh_modifier_pricing` (F-S2) | **146** | Size-differentiated pricing per wariant |
| `sh_item_modifiers` | **637** | Linki item ↔ group |
| `sh_price_tiers` | **558** | POS + Takeaway + Delivery per każdy item |
| `sh_recipes` | **304** | Linie receptur (heuristic z opisów) |
| `sys_items` | **67** | Słownik surowców |
| `wh_stock` | **67** | Stany magazynowe |
| `wh_documents` (PZ) | **5** | Faktury dostawców z 30 dni |
| `wh_document_lines` | **33** | Linie PZ |
| `sh_ksef_invoices` | **3** | KSeF inbox (new/accepted/processing) |
| `sh_ksef_invoice_lines` | **13** | Linie faktur KSeF |
| `sh_orders` | **8** | FORNO-001 do FORNO-008 |
| `sh_order_lines` | **22** | Pozycje zamówień |

---

## Decyzje architektoniczne

### 1. Konsolidacja kategorii PIZZE

Kategorie `PIZZA 30 CM` i `PIZZA 37 CM` z choiceqr zostały połączone w jedną kategorię `PIZZE`. Rozmiar jest teraz wariantem, nie osobną kategorią. Pozwala to na:
- Jeden parent item (np. MARGHERITA) z dwoma children (MARGHERITA_30CM, MARGHERITA_37CM)
- Jedną recepturę na parenta (z multiplier 1.0 dla 30cm / 1.5 dla 37cm)

### 2. Variant Scales (F-S1)

Dwie skale wariantów:

| Scale | key_ascii | Opcje | Multiplier |
|-------|-----------|-------|-----------|
| Rozmiary pizzy | `SCALE_PIZZA` | 30CM (×1.0), 37CM (×1.5) | ✅ |
| Rozmiary panini | `SCALE_PANINI` | MALE (×1.0), DUZE (×1.5) | ✅ |

### 3. Konsolidacja modifier groups (F-S2)

25 setów choiceqr → 19 grup SliceHub. Grupy z "30 cm" i "37 cm" zostały scalone:

| Oryginalne sety | Skonsolidowana grupa | Pricing |
|-----------------|---------------------|---------|
| `Dodatkowe warzywa 30 cm` + `37 cm` | `Dodatkowe warzywa` | F-S2: 3 zł/5 zł |
| `Dodatkowe mięsa 30 cm` + `37 cm` | `Dodatkowe mięsa` | F-S2: 4 zł/6 zł |
| `Dodatkowe sery 30 cm` + `37 cm` | `Dodatkowe sery` | F-S2: różne/nie |
| `Pozostałe 30 cm` + `37 cm` | `Pozostałe pizza` | F-S2: 5 zł/7 zł |
| `Wybierz 2 połówki 30 cm` + `37 cm` | `Wybierz 2 połówki` | F-S2: różne ceny |

F-S2 pricing: modifier `pieczarki` ma cenę 3 zł dla opcji 30CM i 5 zł dla 37CM w `sh_modifier_pricing`.

### 4. Duplikaty w xlsx

4 itemy pojawiały się w 2 kategoriach jednocześnie:

| Item | Kategoria 1 | Kategoria 2 | Decyzja |
|------|-------------|-------------|---------|
| MINI CALZONE z malinami i mascarpone | DESERY | NOWOŚCI !!! | Zachowano DESERY |
| MINI CALZONE z jabłkiem i cynamonem | DESERY | NOWOŚCI !!! | Zachowano DESERY |
| PIZZA ZIMOWA 30cm | NOWOŚCI !!! | ZIMOWE MENU | Zachowano ZIMOWE MENU |
| PIZZA ZIMOWA 37cm | NOWOŚCI !!! | ZIMOWE MENU | Zachowano ZIMOWE MENU |

Reguła: jeśli item jest w `NOWOŚCI !!!` i innej kategorii → preferujemy kategorię właściwą. Badge `new` jest nadawany przez kategorię NOWOŚCI, nie przez obecność w niej.

### 5. Heurystyki receptur

Parser opisów (format: "składnik1 / składnik2 / ...") z przybliżonymi ilościami:

| Słowo kluczowe w opisie | SKU składnika | Ilość bazowa |
|------------------------|---------------|-------------|
| `buffalo mozzarella`, `mozzarella di buffalo` | `MOZZ_BUFFALO` | 90 g |
| `mozzarella`, `ser` | `MOZZ_FIOR` | 100 g |
| `ricotta` | `RICOTTA` | 60 g |
| `parmezan` | `PARMEZAN` | 40 g |
| `salami picante` | `SALAMI_PICANTE` | 60 g |
| `nduja` | `NDUJA` | 40 g |
| `szynka parmeńska` | `SZYNKA_PARM` | 60 g |
| `kurczak` | `KURCZAK` | 80 g |
| `stek wołowy` | `STEK_WOLOWY` | 80 g |
| `sos`, `pomidor` | `SOS_POMIDOROWY` | 80 g |
| `pieczarki` (smażone) | `PIECZARKI_SMAZONE` | 50 g |
| `pieczarki` | `PIECZARKI` | 50 g |
| + 30 innych składników | ... | 10–60 g |

Każda pizza otrzymuje też bazową pozycję `MAKA_TYP_00` (280 g) jeśli nie jest wspomniana wprost.

Receptury są na **parent item** (nie na child variants). WzEngine mnoży przez `multiplier(option)` przy kalkulacji kosztu wariantu.

### 6. Identyfikatory SKU

Funkcja `make_sku()` — konwersja nazwy → ASCII uppercase SKU:
```
MARGHERITA 30cm → MARGHERITA_30CM
PANINI STEKY - MAŁE → PANINI_STEKY_MALE  
FOCCACIA ROSMARINO → FOCCACIA_ROSMARINO
```
Polskie znaki: ą→A, ć→C, ę→E, ł→L, ń→N, ó→O, ś→S, ź/ż→Z (uppercase).

---

## Trudności w mappingu składników

Kilka składników nie miało oczywistego dopasowania:

1. **"krem balsamiczny"** → `KREM_BALSAMICZNY` (unit=`l`, kategoria=sosy) — traktowany jako płyn
2. **"sezamowe ciasto"** → brak mapowania do składnika; pizze na sezamowym cieście są osobnymi itemami w NOWOŚCI (wariant ciasta obsługuje modifier `RODZAJ_CIASTA`)
3. **"chipsy ziemniaczane"** → `CHIPSY_ZIEMN` — na STEAKY pizza, traktowane jako gotowy produkt (kg)
4. **"Nduja"** → `NDUJA` — włoska kiełbasa ostra, mapowana na osobne SKU od `KIELB_WLOSKA`
5. **"frytki"** → `FRYTKI` — kilogram mrożonych frytek
6. **Napoje** → mapowane na SKU sztukowe (COCA_COLA, SPRITE, etc. w szt.)

---

## Dostawcy PZ

| Doc | Dostawca | NIP | Data | SKU główne |
|-----|----------|-----|------|-----------|
| PZ-2026/05/FORNO-001 | HURTOWNIA WARMIA Sp. z o.o. | 5252311234 | -14 dni | MAKA_TYP_00, SOS_POMIDOROWY, MOZZ_FIOR |
| PZ-2026/05/FORNO-002 | FRUKTUS — Warzywa i Owoce | 7780012345 | -7 dni | RUKOLA, POMIDORKI_KOKAT, PAPRYKA |
| PZ-2026/05/FORNO-003 | DI MARCO — Włoskie Specjały | 1230012345 | -3 dni | MOZZ_BUFFALO, PARMEZAN, SZYNKA_PARM |
| PZ-2026/05/FORNO-004 | MŁYNY POLSKIE S.A. | 8870012345 | -10 dni | MAKA_TYP_00, MAKA_SEZAMOWA, BUŁKA_PANINI |
| PZ-2026/05/FORNO-005 | BROWAR REGIONALNY TYSKIE | 6450012345 | -1 dzień | PIWO_BUTELKA, COCA_COLA, SPRITE |

---

## Jak uruchomić

```bash
# Regeneracja SQL z xlsx (opcjonalne — SQL jest już w repo):
pip install openpyxl
python3 scripts/seed_pizzaforno_build.py

# Wgranie (phpMyAdmin lub mysql CLI):
mysql -u sh -psh slicehub_pro_v2 < scripts/seed_pizzaforno.sql

# Walidacja po wgraniu:
bash scripts/seed_pizzaforno_verify.sh [database] [tenant_id]
# np. bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 2
```

---

## Wynik walidacji E2E (sandbox)

```
✅ Seed Pizza Forno verified — 229 items, 158 modifiers, 8 orders, 3 invoices
   PASS: 31 | WARN: 0 | FAIL: 0
```

Seed jest idempotentny — przetestowano 2× uruchomienie na tej samej bazie z identycznym wynikiem.
