# PROMPT do nowego okna Cursor Cloud Agent — wgranie menu Pizzeria Forno + magazyn + faktury + zamówienia

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.
> **Model**: 1× Claude Sonnet (zadanie kodowe + bazodanowe — Sonnet daje najlepszy balans)
> **Liczba agentów**: 1 (deterministyczne, nie potrzeba best-of-N)

---

# Zadanie: stwórz produkcyjny seed bazy SliceHub Pro z menu Pizzerii Forno + magazyn + faktury + zamówienia

## Kontekst

System: **SliceHub Pro** — multi-tenantowy operating system gastronomii (PHP 8 + MariaDB). Aktualnie produkcja stoi na https://slicehub.net (uti.pl shared hosting), tenant_id=2 owner=Damian.

**Jest ZA PUSTO w bazie** — owner widzi tylko 4 demo pizze (Margherita Test Data, TEST_SYNC_E2E, etc.) i 3 demo zamówienia. Trzeba zapełnić system **prawdziwymi, bogatymi danymi** żeby:
1. Demo dla SPARK 3.0 wyglądało jak działająca pizzeria, nie sandbox
2. Można było robić sensowne testy E2E (variant scales, modyfikatory, finalizacja, magazyn, KSeF AutoScan, dispatcher)
3. Klienci pilotażowi widzieli produkcyjny ekosystem przy demo

## Źródło danych

W repo na main: `_docs/menu_pizzaforno/`:
- `menu (14).xlsx` (139 KB, 190 dań × 17 kategorii) — eksport z choiceqr.com (Pizzerii Forno)
- `additions.xlsx` (211 KB, 275 modifierów × 25 setów)
- **`SEED_DESIGN.md`** — **KRYTYCZNE**: pełen design dokument z mappingiem Pizza Forno → SliceHub schema. **Przeczytaj go najpierw, w nim wszystkie decyzje techniczne**.

**WAŻNE**: nie dostosowujemy się do choiceqr — bierzemy tylko dane menu. Format danych musi pasować do **NASZEJ** schemy SliceHub (nie odwrotnie).

## Co masz wyprodukować

### Deliverable 1: skrypt Python `scripts/seed_pizzaforno_build.py`

Czyta oba xlsx, transformuje wg mappingu z `SEED_DESIGN.md`, wypluwa SQL fixture.

```bash
pip install openpyxl
python3 scripts/seed_pizzaforno_build.py
# → wypluwa scripts/seed_pizzaforno.sql (~50-100 KB)
```

Zawartość skryptu:

1. **Funkcja `make_sku(name)`** — escape polskich diacritics + uppercase + underscore (zgodnie z DESIGN sekcja 3)
2. **Parser `parse_menu_xlsx()`** — czyta `menu (14).xlsx`, zwraca listę słowników z polami: `name, category, price, description, weight, prep_time`
3. **Parser `parse_additions_xlsx()`** — czyta `additions.xlsx`, zwraca listę modifierów z polami: `name, set_name, price, type, required`
4. **Funkcja `extract_variant_families(items)`** — grupuje items po nazwie bez sufiksu rozmiaru, zwraca [{parent_name, parent_sku, scale, children: [{size, sku, price}]}]:
   - PIZZA 30 CM/37 CM → scale 'pizza_round' (multiplier 1.0/1.5)
   - PANINI z " - MAŁE"/" - DUŻE" → scale 'panini_size'
5. **Funkcja `extract_ingredients(menu_items)`** — wyciąga unikalne składniki z opisów + nazw modifierów. Dla każdego: SKU, name, unit (heuristic: ser/sos/mięso=kg, oliwa/sos płyn=l), category. Mała baza referencyjna ~60-80 SKU (patrz DESIGN sekcja 8).
6. **Funkcja `infer_recipe(description, ingredients_dict)`** — parser opisu typu "buffalo mozzarella / pieczarki / Nduja" → lista linii receptury z ilościami (heuristic z DESIGN sekcja 7).
7. **Funkcja `consolidate_modifier_groups(additions)`** — łączy "Dodatkowe warzywa 30 cm" + "37 cm" w jedną grupę z F-S2 Topping Size Pricing (różne ceny per variant_option).
8. **Funkcja `build_sql()`** — wypluwa kompletny SQL z:
   - Header z meta info (data generacji, źródło, liczba items)
   - `SET @tid := 2;` (configurable)
   - Cleanup section (DELETE WHERE markery)
   - Wszystkie INSERTy w transakcji `START TRANSACTION; ... COMMIT;`
   - Sprawdzenie końcowe `SELECT COUNT(*) ...`

### Deliverable 2: SQL fixture `scripts/seed_pizzaforno.sql`

Wynik działania pkt 1. Plik gotowy do wklejenia w phpMyAdmin user'a. Idempotentny (cleanup na początku — DELETE WHERE markery).

### Deliverable 3: dynamiczne dane (3 tabele)

Te NIE są w xlsx — generujesz losowo zgodnie z DESIGN sekcja 10/11/12:

#### 3.1 Faktury PZ (`wh_documents` + `wh_document_lines`)
3-5 PZ z ostatnich 30 dni (różne daty). Każda 5-15 linii surowców. Dostawcy:
- "HURTOWNIA SPOŻYWCZA WARMIA Sp. z o.o." (NIP 5252311234)
- "FRUKTUS — Warzywa i Owoce" (NIP 7780012345)
- "DI MARCO — Włoskie Specjały" (NIP 1230012345)
- "MŁYNY POLSKIE S.A." (NIP 8870012345)
- "BROWAR REGIONALNY 'TYSKIE'" (NIP 6450012345)

Każda linia: SKU z `sys_items` + qty + unit_price + line_total. **AKTUALIZUJ wh_stock** przy każdej PZ (qty_on_hand += linia.qty).

#### 3.2 KSeF Inbox (`sh_ksef_invoices` + `sh_ksef_invoice_lines`)
3 faktury (jedna powinna być nowa do akceptacji — najlepszy demo material):
- FA/FORNO/2026/001 — `status='new'`, dostawca "HURTOWNIA WARMIA", 4-5 linii (nie zmapowane do SKU jeszcze, AutoScan to zrobi)
- FA/FORNO/2026/002 — `status='accepted'`, dostawca "FRUKTUS", linkuje do `linked_wh_document_id` z PZ historycznej
- FA/FORNO/2026/003 — `status='processing'`, dostawca "DI MARCO", AutoScan w trakcie (resolved_sku dla niektórych linii, NULL dla innych z `match_type='FUZZY'`)

#### 3.3 Zamówienia (`sh_orders` + `sh_order_lines`)
8 zamówień FORNO-001 do FORNO-008 (lista w DESIGN sekcja 12). Każde z realistycznymi pozycjami z menu Pizza Forno (np. FORNO-001 = MARGHERITA 30cm + DI PARMA 37cm + Coca-Cola).

Każde zamówienie:
- Order line z prawidłowym `item_sku` (musi pasować do `sh_menu_items.ascii_key` po seed menu)
- `unit_price`, `quantity`, `line_total`, `vat_amount` (kalkulowane)
- `modifiers_json` jeśli pizza ma dodatki (1-2 modifiery na pizzę)
- `customer_name`, `customer_phone`, `delivery_address` + `delivery_lat`/`delivery_lng` dla delivery
- `created_at` rozłożone od dziś -2h do -7 dni (rozproszenie czasowe)

### Deliverable 4: skrypt walidacji `scripts/seed_pizzaforno_verify.sh`

Bash skrypt który po wgraniu SQL łączy się z bazą i sprawdza wg DESIGN sekcja "Walidacja":
1. `SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=2 AND created_at > NOW() - INTERVAL 1 HOUR` ≥ 190
2. Każda variant family ma 2 children (`SELECT parent_item_id, COUNT(*) GROUP BY parent_item_id` — wszystkie =2)
3. Brak orphanowych referencji (parent_item_id wskazuje na istniejący item, variant_option_id na istniejącą opcję)
4. KSeF lines `external_name` LIKE faktyczne dostawcze nazwy
5. wh_stock zsumowany ≥ liczba linii PZ

Wynik: `✅ Seed Pizza Forno verified — N items, M modifiers, K orders, L invoices` lub lista błędów.

## Format dostarczenia

### Sekcja 1: Pliki w repo

Branch: `projektx/seed-pizzaforno-c3d7`

Pliki:
- `scripts/seed_pizzaforno_build.py` (Python builder, ~600-1000 linii)
- `scripts/seed_pizzaforno.sql` (wygenerowany SQL, ~3000-8000 linii)
- `scripts/seed_pizzaforno_verify.sh` (bash walidacja)
- `_docs/menu_pizzaforno/SEED_REPORT.md` (krótki raport: ile items, ile modifierów, ile orders, jakie heurystyki użyłem przy receptach, które ingredients miały trudność w mappingu)

### Sekcja 2: E2E test na sandbox MariaDB

Przed PR-em uruchom seed na lokalnym sandboxie żeby zweryfikować że SQL działa:

```bash
# Setup sandbox bazy:
sudo mysql -e "DROP DATABASE IF EXISTS seed_test; CREATE DATABASE seed_test;
               GRANT ALL ON seed_test.* TO 'sh'@'localhost'; FLUSH PRIVILEGES;"

# Wgraj schema:
php -r '$sql = preg_replace(\"/^\\s*CREATE\\s+DATABASE[^;]+;/mi\", \"\", file_get_contents(\"database/migrations/001_init_slicehub_pro_v2.sql\")); 
        $sql = preg_replace(\"/^\\s*USE\\s+[^;]+;/mi\", \"\", $sql); 
        echo trim($sql);' | mysql -u sh -psh seed_test

# Aplikuj wszystkie migracje:
for m in $(php -r '$c = require "scripts/_migrations_chain.php"; echo implode(" ", $c);'); do
  mysql -u sh -psh seed_test < database/migrations/$m
done

# Wgraj demo_seed_test.sql + demo_seed_dynamic_data.sql (poprzednie demo):
mysql -u sh -psh seed_test < _docs/demo_seed_test.sql
mysql -u sh -psh seed_test < _docs/demo_seed_dynamic_data.sql

# Uruchom Pizza Forno seed:
mysql -u sh -psh seed_test < scripts/seed_pizzaforno.sql

# Walidacja:
bash scripts/seed_pizzaforno_verify.sh seed_test
```

W finalnej odpowiedzi pokaż output walidacji.

### Sekcja 3: Pull Request (draft)

Tytuł: `feat(seed): pelne menu Pizza Forno — 190 items + 275 modifierow + magazyn + faktury + KSeF + zamowienia`

Body:
- Co zawiera seed (krótki overview liczbowy)
- Mapping decyzji (skala 'pizza_round', skala 'panini_size', F-S2 topping pricing)
- Jak uruchomić u siebie (jeden krok: wklej SQL do phpMyAdmin)
- Linki do raportu i SEED_DESIGN

### Sekcja 4: instrukcja deployu w finalnej odpowiedzi

Krótka tabela co user musi zrobić:

| Krok | Co | Czas |
|------|-----|------|
| 1 | Pull updates (pliki SQL + script) | 30s |
| 2 | phpMyAdmin → wybierz bazę → SQL → wklej `scripts/seed_pizzaforno.sql` → Wykonaj | 30s |
| 3 | Sprawdzić w Studio Menu czy 190 items widać | 1 min |
| 4 | Sprawdzić w POS czy variant pickery działają (klik MARGHERITA → modal 30cm/37cm) | 1 min |
| 5 | Sprawdzić w KSeF Inbox czy 3 faktury są (1 nowa do akceptacji) | 30s |

## Reguły jakości

1. **Idempotency** — SQL musi być uruchamialny wielokrotnie bez duplikatów. Cleanup na początku (DELETE WHERE markery).
2. **Schema-aware** — przed `INSERT` sprawdzaj `SHOW COLUMNS FROM ...` w trybie probe (nie hardcoduj kolumn które mogą nie istnieć w starszej migracji). W szczególności: `variant_scale_id`, `is_variant_parent`, `parent_item_id`, `variant_option_id` w `sh_menu_items` (F-S1 — dodane w migracji 048).
3. **Tenant isolation** — wszędzie `tenant_id = @tid`. NIGDY nie hardcoduj `tenant_id=1` lub `tenant_id=2`. Domyślnie `SET @tid := 2;` ale user może zmienić przed wykonaniem.
4. **Polish characters** — zachowaj polskie znaki w `name`, `description`, `customer_name` (UTF-8). Tylko `ascii_key` przekształcasz przez `make_sku()`.
5. **Realistic data** — ceny, daty, ilości muszą wyglądać realistycznie (np. PZ z 50 kg mąki za 175 zł netto, nie 0.001 kg za 1000000 zł).
6. **Error handling** — jeśli któryś modifier ma cenę nieparseable, log warning i pomiń. Nie crashuj całego seed.
7. **Performance** — SQL ma być szybki (≤30 sekund w phpMyAdmin). Używaj batch inserts (`INSERT INTO ... VALUES (...), (...), (...)`) gdzie możliwe.
8. **Foreign key safety** — INSERT w prawidłowej kolejności: categories → modifier_groups → modifiers → menu_items (parents → children) → recipes → orders → order_lines → invoices.

## Workflow rekomendowany

1. **Najpierw przeczytaj** `_docs/menu_pizzaforno/SEED_DESIGN.md` (cały dokument) — w nim wszystkie decyzje techniczne.
2. **Następnie sprawdź** strukturę plików xlsx własnym Python script żeby zobaczyć jakie dane masz w praktyce (`openpyxl.load_workbook`).
3. **Sprawdź schema bazy** — `database/migrations/001_init_slicehub_pro_v2.sql` + ostatnie migracje (048-055) żeby wiedzieć jakie pola masz dostępne.
4. **Napisz Python builder** (Deliverable 1).
5. **Wygeneruj SQL** + uruchom na sandbox.
6. **Walidacja** (Deliverable 4 bash script).
7. **Iteruj** jeśli walidacja zwróci błędy.
8. **Commit + push + draft PR**.

## Dostępy live (jeśli potrzebujesz testować na produkcji — opcjonalne)

- URL: https://slicehub.net
- Login backoffice: `Damian` / `Dammalq123123` (mode=system)
- PIN POS: `1111`
- Tenant_id: `2`

**Nie musisz** testować na produkcji — sandbox sufficient. Owner sam wgra SQL po Twojej gotowości.

## Kategorie z xlsx do uwzględnienia (17)

PIZZA 30 CM (31), PIZZA 37 CM (32) → konsolidacja do PIZZE z variants
PANINI (22), MAKARONY (15), NOWOŚCI !!! (12), PIWA (10), ZIMOWE MENU (10), CALZONE (9), NAPOJE ZIMNE (8), SOSY (8), POZOSTAŁE (7), DLA DZIECI (6), DESERY (6), ZAPIEKANKI (4), GYROSY (4), FOCCACIA (3), SAŁATKI (3)

## Sety modifierów do uwzględnienia (25)

Sosy do wyboru, Dodatkowe warzywa 30 cm, Dodatkowe warzywa 37 cm, Dodatkowe mięsa 30 cm, Dodatkowe mięsa 37 cm, Dodatkowe sery 30 cm, Dodatkowe sery 37 cm, Pozostałe 30 cm, Pozostałe 37 cm, Sosy do pizzy, Wybierz 2 połówki 30 cm, Wybierz 2 połówki 37 cm, ... (25 razem). Patrz xlsx.

**Konsolidacja**: grupy "30 cm" + "37 cm" → 1 grupa z F-S2 Topping Size Pricing.

**Powodzenia. To dużo pracy ale wynik będzie produkcyjny seed gotowy do pilotaży.**
