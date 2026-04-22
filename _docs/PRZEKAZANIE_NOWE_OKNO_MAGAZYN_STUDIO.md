# PRZEKAZANIE DO NOWEGO OKNA — Magazyn + Studio Menu + spójność kontraktów

> Ten plik powstał po uporządkowaniu `_docs/` i ma służyć jako **pełny handoff do nowego okna czatu**.
> Cel: dać nowemu agentowi maksimum kontekstu bez chaosu i bez odsyłania do starych, konkurujących notatek.

---

## 1. Co jest teraz najważniejsze

**Aktualny priorytet nie jest już „Online jako wizja”.**

Priorytetem jest:

1. **uporządkowanie Magazynu**,
2. **uporządkowanie Studio Menu**,
3. **spięcie kontraktów między modułami**, tak żeby:
   - Studio nie szukało innych bytów niż backend faktycznie przechowuje,
   - Magazyn, Menu, Receptury, Modyfikatory i Assety mówiły tym samym językiem,
   - nie było rozjazdu typu „frontend myśli o produkcie A, backend zapisuje B, a magazyn dedukuje C”.

To nie ma być kosmetyka.
To ma być **porządek architektury + porządek danych + porządek UI** z planem wdrożenia prawie pod finalny wygląd.

---

## 2. Najważniejsze ustalenia z tego okna czatu

### A. Dokumentacja była chaosem

Użytkownik wprost zgłosił, że w `docs` był „meksyk” i nic nie było jasne.

Dlatego zostało zrobione:

- nowy plik startowy: `START_TUTAJ.md`,
- nowy katalog: `_docs/ARCHIWUM/`,
- przeniesienie dokumentów historycznych / mylących do archiwum:
  - `_docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md`
  - `_docs/ARCHIWUM/FAZA_1_STATUS.md`
  - `_docs/ARCHIWUM/GDZIE_CZYTAC_FAZY_ONLINE.md`

Aktywna zasada po sprzątaniu:

1. zaczynasz od `START_TUTAJ.md`,
2. potem `00_PAMIEC_SYSTEMU.md`,
3. potem dokument modułowy zależny od zadania.

### B. Użytkownik chce teraz skupić się na Magazynie i Studio Menu

To jest najważniejsza zmiana kierunku.

Nie chodzi już o dopieszczanie samego storefrontu.
Chodzi o to, żeby **fundament danych i przepływów był spójny**:

- magazyn,
- receptury,
- pozycje menu,
- modyfikatory,
- mapowanie SKU,
- półprodukty / produkty / surowce,
- to co Studio pokazuje,
- to co backend przyjmuje,
- to co magazyn realnie konsumuje.

### C. Użytkownik chce plan od kogoś „bardzo mądrego”

Oczekiwanie nie brzmi „napraw pojedynczy bug”.
Oczekiwanie brzmi:

- przeanalizuj całość,
- znajdź najlepsze wyjście,
- wymyśl docelowy porządek,
- zrób konkretny plan wdrożenia,
- uwzględnij też prawie końcowy wygląd modułów,
- potem wdrażaj konsekwentnie.

Czyli: **najpierw analiza i plan systemowy, potem implementacja etapami.**

---

## 3. Co jest źródłem prawdy po sprzątaniu docs

### Start

- `_docs/START_TUTAJ.md`

### Dokumenty kanoniczne

- `_docs/00_PAMIEC_SYSTEMU.md`
- `_docs/01_KONSTYTUCJA.md`
- `_docs/02_ARCHITEKTURA.md`
- `_docs/03_MAPA_KOPALNI.md`
- `_docs/04_BAZA_DANYCH.md`

### Co czytać dla tego zadania

#### Magazyn

- `_docs/00_PAMIEC_SYSTEMU.md`
- `_docs/02_ARCHITEKTURA.md`
- `_docs/04_BAZA_DANYCH.md`
- `_docs/03_MAPA_KOPALNI.md` tylko referencyjnie

#### Studio Menu

- `_docs/00_PAMIEC_SYSTEMU.md`
- `_docs/02_ARCHITEKTURA.md`
- `_docs/04_BAZA_DANYCH.md`

### Czego nie traktować jako źródła prawdy

Wszystko w `_docs/ARCHIWUM/` jest historyczne.

---

## 4. Fakty architektoniczne ważne dla analizy

### A. Magazyn i menu są krytycznie powiązane

Z `00_PAMIEC_SYSTEMU.md`:

- menu i modyfikatory to tylko front,
- prawdziwy biznes to **magazyn i food cost**,
- każdy modyfikator wpływający na zużycie musi mieć:
  - `linked_warehouse_sku`
  - `linked_quantity`
- usunięcia typu „BEZ …” mają wpływ na logikę magazynową,
- half & half konsumuje surowce z mnożnikiem `0.5`.

### B. Są trzy silosy danych

Trzeba bezwzględnie respektować podział:

- `sh_` — biznes / menu / zamówienia / modyfikatory / receptury
- `sys_` — słownik surowców
- `wh_` — magazyn rzeczywisty

**Cross-silo tylko przez klucze znakowe** (`sku`, `ascii_key`) i zawsze z barierą `tenant_id`.

### C. Studio Menu ma dług techniczny

Z `02_ARCHITEKTURA.md`:

- `modules/studio/` nie ma dedykowanego `studio_api.js`,
- pliki frontu Studio wołają `api/backoffice/api_menu_studio.php` bezpośrednio,
- to jest jawnie opisane jako dług techniczny.

### D. Magazyn już ma osobną warstwę API

`modules/warehouse/js/warehouse_api.js` już robi wrapper na `api/warehouse/*.php`.

To oznacza, że:

- Magazyn jest rozbity na wiele endpointów,
- Studio Menu jest bardziej monolityczne i mniej spójne po stronie frontu.

### E. `api/backoffice/api_menu_studio.php` jest bardzo szerokim routerem

Ten plik obsługuje jednocześnie:

- CRUD menu,
- ceny,
- modyfikatory,
- receptury,
- część logiki powiązanej z assetami / visual linkami.

To zwiększa ryzyko, że jeden moduł „szuka czegoś innego” niż drugi.

---

## 5. Główne problemy do rozwiązania

Nowy agent ma przeanalizować i nazwać te problemy bardzo konkretnie.

### Problem 1. Rozjazd pojęć i bytów

Trzeba ustalić jednoznacznie:

- czym jest **produkt**,
- czym jest **półprodukt**,
- czym jest **surowiec**,
- czym jest **pozycja menu**,
- czym jest **modyfikator**,
- czym jest **receptura**,
- czym jest **asset/hero/warstwa wizualna**,
- gdzie kończy się odpowiedzialność Studio Menu,
- gdzie zaczyna się odpowiedzialność Magazynu,
- gdzie zaczyna się odpowiedzialność Online Studio / Asset Studio.

### Problem 2. Rozjazd kontraktów frontend ↔ backend

Użytkownik wprost wskazał problem typu:

> „żeby nie było że moduł studio wysyła albo ma albo szuka całkiem czegoś innego”

To trzeba sprawdzić moduł po module:

- Studio Menu frontend,
- Magazyn frontend,
- `api/backoffice/api_menu_studio.php`,
- `api/warehouse/*.php`,
- powiązania z `sh_recipes`,
- powiązania z `sh_modifiers.linked_warehouse_sku`,
- powiązania z `sys_items` / `wh_stock`,
- ewentualnie z `sh_asset_links`, ale tylko tam, gdzie to naprawdę potrzebne.

### Problem 3. UI ma być końcowe lub prawie końcowe

To nie ma być plan „zrobimy backend, UI kiedyś”.

Trzeba zaplanować:

- docelowy podział ekranów,
- które opcje są potrzebne,
- które opcje są zbędne i tylko robią chaos,
- jak użytkownik ma przechodzić przez moduły,
- jaki ma być porządek w danych i w widokach.

### Problem 4. Integracja między modułami

Trzeba przeanalizować spójność przepływu:

`Studio Menu -> Receptury -> Magazyn -> POS / Online / Orders -> dedukcja stanów`

I znaleźć wszystkie miejsca, gdzie:

- SKU są niespójne,
- jeden moduł oczekuje ID, a drugi tekstowego klucza,
- frontend operuje inną strukturą niż backend,
- coś da się zapisać w UI, ale nie daje się potem poprawnie wykorzystać operacyjnie.

---

## 6. Co ma zrobić nowy agent

Nowy agent **nie powinien od razu kodować**.

Najpierw powinien zrobić:

### Etap 1. Analiza

1. Przeczytać:
   - `_docs/START_TUTAJ.md`
   - `_docs/00_PAMIEC_SYSTEMU.md`
   - `_docs/02_ARCHITEKTURA.md`
   - `_docs/04_BAZA_DANYCH.md`
2. Zmapować:
   - `modules/studio/`
   - `modules/warehouse/`
   - `api/backoffice/api_menu_studio.php`
   - `api/warehouse/*.php`
   - kluczowe tabele: `sh_menu_items`, `sh_modifiers`, `sh_price_tiers`, `sh_recipes`, `sys_items`, `wh_stock`, `wh_documents`, `wh_document_lines`
3. Wskazać:
   - co jest spójne,
   - co się dubluje,
   - co jest martwe,
   - co jest nieczytelne,
   - co jest ryzykiem operacyjnym.

### Etap 2. Projekt docelowy

Agent ma zaproponować:

1. **docelowy model pojęć**,
2. **docelowy podział odpowiedzialności modułów**,
3. **docelowe kontrakty API**,
4. **docelowy układ UI** dla:
   - Magazynu,
   - Studio Menu,
   - miejsc styku między nimi,
5. plan migracji bez rozwalenia istniejącej logiki.

### Etap 3. Plan wdrożenia

Plan ma być konkretny:

- etapami,
- z kolejnością,
- z plikami,
- z ryzykiem,
- z zależnościami,
- z tym co można zrobić bez zmian DB,
- z tym co wymaga zmian DB,
- z tym co można od razu uprościć w UI.

---

## 7. Ważne ograniczenia dla nowego agenta

### Nie robić znowu chaosu dokumentacyjnego

Nie produkować 10 nowych konkurujących dokumentów.
Jeśli powstanie plan, powinien być:

- jeden główny plan wdrożenia,
- ewentualnie jeden pomocniczy dokument mapujący problem.

### Nie mieszać wszystkiego z Online

Online / Assety / Director mają znaczenie tylko tam, gdzie wpływają na kontrakty.

Główne zadanie teraz to:

- Magazyn,
- Studio Menu,
- podział produktów / półproduktów / surowców,
- spójność danych i przepływów.

### Nie zgadywać schematu

Każda decyzja ma być oparta na:

- `_docs/04_BAZA_DANYCH.md`,
- realnym kodzie,
- realnych tabelach i polach,
- realnych endpointach.

### Nie robić planu „tylko backendowego”

Plan ma uwzględnić także:

- UX,
- wygląd docelowy albo prawie docelowy,
- porządek ekranów,
- uproszczenie opcji,
- kolejność pracy użytkownika.

---

## 8. Konkretne miejsca, od których agent ma zacząć

### Dokumentacja

- `_docs/START_TUTAJ.md`
- `_docs/00_PAMIEC_SYSTEMU.md`
- `_docs/02_ARCHITEKTURA.md`
- `_docs/04_BAZA_DANYCH.md`

### Frontend Studio Menu

- `modules/studio/js/studio_core.js`
- `modules/studio/js/studio_item.js`
- `modules/studio/js/studio_modifiers.js`
- `modules/studio/js/studio_recipe.js`
- `modules/studio/js/studio_bulk.js`

### Frontend Magazyn

- `modules/warehouse/js/warehouse_api.js`
- `modules/warehouse/js/warehouse_core.js`
- `modules/warehouse/index.html`
- `modules/warehouse/manager_pz.html`
- `modules/warehouse/manager_rw.html`
- `modules/warehouse/manager_in.html`

### Backend

- `api/backoffice/api_menu_studio.php`
- `api/warehouse/stock_list.php`
- `api/warehouse/receipt.php`
- `api/warehouse/internal_rw.php`
- `api/warehouse/inventory.php`
- `api/warehouse/correction.php`
- `api/warehouse/transfer.php`
- `api/warehouse/mapping.php`
- `api/warehouse/documents_list.php`

### Core

- `core/PzEngine.php`
- `core/WzEngine.php`
- `core/MmEngine.php`
- `core/InwEngine.php`
- `core/KorEngine.php`

---

## 9. Gotowy prompt do nowego okna

Poniżej gotowy prompt do wklejenia:

```markdown
Pracujemy nad SliceHub. Priorytetem NIE jest teraz storefront, tylko porządek w Magazynie i Studio Menu oraz pełna spójność kontraktów między modułami.

Najpierw przeczytaj:
- `_docs/START_TUTAJ.md`
- `_docs/00_PAMIEC_SYSTEMU.md`
- `_docs/02_ARCHITEKTURA.md`
- `_docs/04_BAZA_DANYCH.md`
- `_docs/PRZEKAZANIE_NOWE_OKNO_MAGAZYN_STUDIO.md`

Zakres analizy:
- `modules/studio/`
- `modules/warehouse/`
- `api/backoffice/api_menu_studio.php`
- `api/warehouse/*.php`
- `core/PzEngine.php`, `WzEngine.php`, `MmEngine.php`, `InwEngine.php`, `KorEngine.php`

Cel:
1. znaleźć najlepszy docelowy porządek dla Magazynu i Studio Menu,
2. spiąć produkty / półprodukty / surowce / receptury / modyfikatory / mapowania,
3. naprawić sytuacje, gdzie frontend i backend „szukają czegoś innego”,
4. zaproponować prawie końcowy układ UI/UX dla tych modułów,
5. przygotować konkretny plan wdrożenia etapami.

Nie zaczynaj od kodowania.
Najpierw zrób analizę i przedstaw jeden spójny plan wdrożenia:
- architektura,
- kontrakty,
- model danych,
- podział odpowiedzialności,
- etapy prac,
- ryzyka,
- zależności,
- które stare opcje usunąć jako zbędne.

Nie twórz chaosu dokumentacyjnego.
Chcę jeden bardzo mocny plan, który potem będzie można wdrażać konsekwentnie.
```

---

## 10. Oczekiwany rezultat pracy nowego agenta

Po analizie ma powstać:

1. jedna diagnoza aktualnego stanu,
2. jeden docelowy model modułów,
3. jeden etapowy plan wdrożenia,
4. jasna decyzja co zostaje, co upraszczamy, co usuwamy, co spinamy.

Nie „jeszcze jedna wizja”.
Nie „jeszcze jedna lista luźnych pomysłów”.
Tylko **realny plan wykonania porządku**.

---

**Kompilacja:** 2026-04-19
