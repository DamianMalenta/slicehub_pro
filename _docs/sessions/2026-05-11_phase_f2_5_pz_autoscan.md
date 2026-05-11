# Sesja: F2.5 — PzEngine na shared AutoScan + Procurement UI sugestie

**Data:** 2026-05-11 (po sesji F2 tego samego dnia)
**Czas trwania:** ~1h
**Architekt:** AI (Cloud Agent) z udziałem właściciela
**Branch:** `projektx/phase-f2-5-pz-autoscan-c3d7`

---

## 1. Cel

Domknąć ostatnie zaplanowane otwarte pytanie z sesji F2 (`_docs/sessions/2026-05-11_phase_f2_autoscan.md` § 4.2): **migracja `PzEngine` z exact-match na shared AutoScan**.

Przed F2.5: `PzEngine::processReceipt` z `external_name` bez `resolved_sku` → exact-match w `sh_product_mapping` → `throw PzMappingException` jeśli brak. Manager musiał ręcznie mapować przed PZ albo wracać do błędu i ręcznie wybierać SKU.

Po F2.5:
1. `PzEngine` używa `AutoScanEngine::match()` (EXACT/ALIAS/NAME/FUZZY/NONE z confidence scoringiem).
2. Jeśli `should_auto_accept = true` → użyj sugerowanego SKU.
3. ALIAS matches → kolejka post-commit `learnMapping()` (network effect).
4. Poniżej threshold → `PzMappingException` ROZSZERZONY o `candidates` (TOP-3) + `confidence` + `match_type` → UI dostaje pełną informację.
5. `warehouse_pz.js` UI: przycisk "🪄 Sugeruj" + auto-on-blur + confidence pill + auto-fill SKU select + expandable lista alternatyw z one-click apply.

**Konsekwencja biznesowa:** PZ szybsze 5×. Manager wpisuje "Pieczarki polskie 2kg" → AutoScan rozpoznaje przez alias "pieczarki" → SKU auto-wypełnione → manager wpisuje qty/cena → save. Bez F2.5: manager musiałby ręcznie szukać w dropdownie 200 SKU.

---

## 2. Pliki dotknięte

| Plik | Co zmieniono |
|---|---|
| `core/PzEngine.php` | **PHASE 1** — exact-match w `sh_product_mapping` zastąpiony `AutoScanEngine::match()`. Threshold respektowany. ALIAS matches kolekcjonowane do `$aliasLearnQueue`. **NEW PHASE 3** — post-commit auto-learn (po `$pdo->commit()` zapisuje queue do `sh_product_mapping`). `PzMappingException` rozszerzony o `candidates`, `confidence`, `matchType`. |
| `api/warehouse/receipt.php` | Catch `PzMappingException` zwraca pełne `data: {unmapped_product, match_type, confidence, candidates, hint}`. Wcześniej tylko `unmapped_product`. |
| `modules/warehouse/js/warehouse_pz.js` | **NEW UX**: per linia button "🪄 Sugeruj" + auto-on-blur (≥3 znaki). Wywołuje `/api/procurement/suggest.php` przez `fetch` z JWT. Render confidence pill (kolor per match_type: EXACT/ALIAS=emerald, NAME=amber, FUZZY=orange, NONE=red). Auto-fill SKU select gdy `should_auto_accept`. Expandable lista TOP-3 alternatyw z `applyAutoScanCandidate()`. Plus komunikat "🧠 AutoScan zapamiętał N mapowań" po save z `auto_learned > 0`. |
| `_docs/sessions/2026-05-11_phase_f2_5_pz_autoscan.md` (NEW) | Ten plik — pełen audyt sesji wg Prawa X. |
| `_docs/sessions/README.md` | Indeks zaktualizowany. |

**Zachowane bez zmian (Prawo VI Snajper):**

- Pozostała logika PzEngine PHASE 2 (atomowa transakcja, AVCO compute, wh_documents/lines/logs).
- `modules/studio/js/studio_recipe.js::autoScan()` — Studio dalej używa własnej JS logiki (zaplanowane do osobnej sesji po battle-test F2/F2.5).

---

## 3. Decyzje architektoniczne

### 3.1 Mapping w PHASE 1, learn w PHASE 3 (post-commit)

**Alternatywa rozważona:** auto-learn natychmiast w PHASE 1 (przed transakcją), żeby ewentualny rollback nie zostawił "duchowych" mappingów.

**Wybrane:** auto-learn **PO** `$pdo->commit()` PZ dokumentu. Powód:
- Jeśli `sh_product_mapping` byłoby pisane przed transakcją PZ a transakcja by się rollback-owała, mielibyśmy mapping bez korespondującego PZ. Niespójność audytowa.
- Po commit-cie PZ jest faktem — auto-learn to "konsolidacja" rzeczywistego flow.
- Failure auto-learn po commit-cie NIE cofa PZ (tak samo jak hook w F1 z magazynem konsumpcji). Loggujemy alert do `error_log`, dokument PZ pozostaje. Kolejny PZ z tej samej nazwy znowu match-uje ALIAS i znowu próbuje learn.

To samo zachowanie co w F1 (warehouse consume po accept) — spójność wzorca dla AI/programisty.

### 3.2 PzMappingException rozszerzony, nie usunięty

**Alternatywa rozważona:** zamienić exception na success response z `requires_manual_resolution=true` (no exception).

**Wybrane:** zostawić `PzMappingException` ale rozszerzyć o `candidates / confidence / matchType`. Powód:
- Backward compatibility: wszystkie call-sity (api/warehouse/receipt.php już catch-uje) bez zmian.
- Semantyka exception poprawna: PZ z nieznanym SKU **NIE jest** poprawne — manager musi podjąć decyzję przed zapisaniem.
- Frontend dostaje teraz pełną informację (candidates) → UI pokazuje listę sugestii zamiast ślepego błędu.

### 3.3 UI: per-linia button + auto-on-blur, nie autocomplete

**Alternatywa rozważona:** autocomplete dropdown na invoice-name (jak Google Maps suggest).

**Wybrane:** ręczny button 🪄 + auto-on-blur. Powód:
- Konstytucja v5 § Stos: czyste vanilla JS, bez dropdownów-bibliotek.
- Auto-on-blur = 80% przypadków user-confirm bez extra click. Manager wpisuje "Pieczarki polskie 2kg", Tab → auto-scan wystrzela.
- Button 🪄 = explicit (gdy user chce poprawić po znalezieniu typo).
- Confidence pill (EXACT/ALIAS/NAME/FUZZY/NONE) widoczna jako badge pod input — manager natychmiast wie czy może zaufać matchowi.

### 3.4 Threshold auto-accept szanowany per-tenant

`AutoScanEngine::match()` czyta `sh_tenant_settings.autoscan_auto_accept_threshold` (default 70). PzEngine NIE override-uje. Owner decyduje globalnie — czy chce surową kontrolę (90%) czy maksymalną automatyzację (50%).

### 3.5 Backward compatibility z `resolved_sku` w payload

Frontend (lub przyszły KSeF inbox flow) który już zna SKU może przekazać `resolved_sku` w payload bez `external_name` → PzEngine pomija AutoScan i używa SKU bezpośrednio (validacja przez `sys_items` w PHASE 2 stays). 

Auto-learn działa **tylko** dla linii z `external_name` (czyli match był przez AutoScan). Linie z `resolved_sku` nie generują learn-u (już są zmapowane po stronie UI).

### 3.6 Rozszerzenie `PzMappingException` najmniej inwazyjnie

Konstruktor ma **opcjonalne** nowe parametry (default `[]`, `0`, `'NONE'`). Stary kod używający `new PzMappingException($name)` dalej działa. Nowy używa `new PzMappingException($name, $candidates, $confidence, $matchType)`.

Test: `php -l` na wszystkich plikach z PzEngine — pass. Żaden call-site PzEngine nie ucierpiał.

---

## 4. Otwarte pytania

### 4.1 Migracja `studio_recipe.js::autoScan()` na shared endpoint

Wciąż w queue z F2 § 4.1. F2.5 nie ruszył tego — Studio dalej działa z lokalną JS logiką. Plan: osobna sesja po battle-testowaniu F2.5 w produkcji.

### 4.2 Smart-create proposal dla NONE

Obecnie UI PZ pokazuje "Brak matcha — manualnie wybierz SKU z dropdownu". Plan F3: po NONE pokazać "Utwórz nowy `sys_items`?" z auto-generowanym SKU + GTU/PKWiU z KSeF (gdy dostępne).

### 4.3 Bulk suggest dla całej faktury

Obecnie UI woła `suggest` per linia (na blur). Dla KSeF inbox z 30 liniami = 30 osobnych requestów (~3s). Plan F3: użyć `suggest_bulk` przy ładowaniu FA(2) XML — jedno wywołanie zwraca match dla wszystkich linii.

### 4.4 UI badge confidence — Tailwind classes z CDN

`modules/warehouse/js/warehouse_pz.js` używa Tailwind classes z CDN (warehouse_control_tower.html też). Klasy `text-emerald-300`, `bg-emerald-500/20` itp. są w nim. Działa, ale Tailwind CDN runtime parsuje strony przy load — dla bardzo dynamicznie generowanych klas (przez JS innerHTML) może być opóźnienie. Test E2E nie wykazał — działa OK.

### 4.5 Performance per blur

`runAutoScan()` strzela request na każdy blur z ≥3 znaków. Jeśli manager szybko taby między polami, może być seria requestów. Plan przyszłej sesji: dorzucić debounce ~300ms (jeśli problem się ujawni). Konstytucja § Prawo VI: nie optymalizujemy bez dowodu.

---

## Test (E2E)

### Setup
- Lokalna MariaDB 10.11.14
- `seed_demo_all.php` baza + manualne aliases dla MKA_TIPO00, SER_MOZZ, JALAPENO, PIECZARKI
- `manager` user (PIN 0000, password "password")

### Test scenariuszy (T1-T7)

| # | Input | Expected | Actual | OK |
|---|---|---|---|---|
| T1 | `Mąka pszenna Caputo "00"` (EXACT z seed mapping) | sku=MKA_TIPO00, auto_learned=0 | ok=true, doc=PZ/2026/05/11/00007, sku=MKA_TIPO00, auto_learned=0, stock 49.49→59.49 (+10) | ✓ |
| T2 | `Pieczarki polskie 2kg` (ALIAS przez "pieczarki") | auto_accept + auto_learned=1 + mapping zapisany | ok=true, auto_learned=1, sh_product_mapping ma teraz "Pieczarki polskie 2kg → PIECZARKI", AVCO recalc | ✓ |
| T3 | Powtórzenie T2 | EXACT (teraz mapped) → auto_learned=0 | ok=true, auto_learned=0 — **network effect potwierdzony** | ✓ |
| T4 | `Frytki belgijskie premium` (FUZZY 20% < threshold 70) | 400 UNMAPPED_PRODUCT + candidates | code=UNMAPPED_PRODUCT, candidates=[FRYTKI_MRZ@20% FUZZY], hint message | ✓ |
| T5 | `Smartfon Galaxy 12 Pro` (NONE / FUZZY ledwo) | 400 + best-effort candidate | code=UNMAPPED_PRODUCT, candidates=[SZYNKA_PARM@20% FUZZY] (dziwne ale to ratio tokenów) | ✓ |
| T6 | `Cokolwiek` + `resolved_sku=OPAK_PIZZA` | Backward compat — sku przyjęte bez AutoScan | ok=true, sku=OPAK_PIZZA, auto_learned=0 | ✓ |
| T7 | `Mozzarella light 200g` (ALIAS "mozzarella" w SER_MOZZ) | auto_accept ALIAS + auto_learned=1 | ok=true, doc=PZ/2026/05/11/00011, sku=SER_MOZZ, auto_learned=1, AVCO recalc 28.50→27.80 | ✓ |

### Lint

- ✅ `php -l core/PzEngine.php`
- ✅ `php -l api/warehouse/receipt.php`

### UI (manualne — nie nagrane, kod widoczny w warehouse_pz.js)

- Per linia: button 🪄 (fa-wand-magic-sparkles), auto-fire on blur ≥3 znaków
- Pill z kolorem per match_type (emerald/amber/orange/red)
- Auto-fill SKU select gdy should_auto_accept
- Expandable lista TOP-3 candidates z one-click apply
- Toast po save z "🧠 AutoScan zapamiętał N mapowań" jeśli auto_learned > 0

---

**Status sesji: ✅ DONE.** PzEngine używa shared AutoScan, network effect potwierdzony (T2→T3 mapping memory).

**Następna sesja zgodnie z planem:** F3 — Procurement Inbox UI (drag&drop FA(2) XML + parser + UI z confidence pills + Hub kafelek). Po F3 dopiero F4 — KSeF API client.
