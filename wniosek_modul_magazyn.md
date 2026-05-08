# MODUŁ MAGAZYNOWY — Audyt Obecnego Stanu i Wizja Docelowa

> Analiza techniczna modułu Warehouse pod wniosek o dofinansowanie.  
> Audyt kodu: maj 2026. Dokument łączy stan obecny (co istnieje) z planem pełnej automatyzacji.

---

## 1. ARCHITEKTURA OBECNA

### 1.1 Struktura kodu

```
modules/warehouse/                    api/warehouse/
├── index.html (Hub)                  ├── stock_list.php
├── warehouse_control_tower.html      ├── warehouse_list.php
├── manager_pz.html                   ├── receipt.php          → PzEngine
├── manager_rw.html                   ├── internal_rw.php
├── manager_in.html                   ├── batch_rw.php
├── manager_kor.html                  ├── correction.php       → KorEngine
├── manager_mm.html                   ├── transfer.php         → MmEngine
├── documents.html                    ├── inventory.php        → InwEngine
├── approval.html                     ├── approve.php          → InwEngine
├── settings_magazyn.html             ├── add_item.php
├── settings_mapping.html             ├── avco_dict.php
├── js/warehouse_core.js              ├── documents_list.php
├── js/warehouse_api.js               └── mapping.php
├── js/warehouse_pz.js
├── js/warehouse_rw.js                core/
├── js/warehouse_in.js                ├── PzEngine.php     (Przyjęcie + AVCO)
├── js/warehouse_transfer.js          ├── WzEngine.php     (Wydanie + Alert 86)
├── js/warehouse_correction.js        ├── InwEngine.php    (Inwentaryzacja)
├── js/warehouse_documents.js         ├── KorEngine.php    (Korekta)
├── js/warehouse_approval.js          ├── MmEngine.php     (Przesunięcie MM)
├── js/warehouse_settings_catalog.js  ├── FoodCostEngine.php
└── js/warehouse_settings_mapping.js  └── SequenceEngine.php
```

### 1.2 Baza danych — tabele magazynowe

| Tabela | Silo | Przeznaczenie |
|--------|------|---------------|
| `sys_items` | `sys_` | Słownik surowców (SKU, nazwa, jednostka, aliasy wyszukiwania, `is_active`, `is_deleted`) |
| `wh_stock` | `wh_` | Stany magazynowe per (tenant, warehouse, SKU): `quantity`, `unit_net_cost`, `current_avco_price` |
| `wh_documents` | `wh_` | Nagłówki dokumentów: typ (PZ/RW/MM/INW/WZ/KOR/PW), status, supplier, warehouse, `order_id`, `references_wz` |
| `wh_document_lines` | `wh_` | Linie dokumentów: SKU, ilość, `unit_net_cost`, `line_net_value`, VAT, `old_avco`, `new_avco`, `system_qty`, `counted_qty`, `variance` |
| `wh_stock_logs` | `wh_` | Dziennik zmian: `change_qty` (+/-), `after_qty`, `document_type`, `document_id` |
| `sh_product_mapping` | `sh_` | Mapowanie nazwa z faktury → wewnętrzny SKU (per tenant) |
| `sh_recipes` | `sh_` | Receptury: `menu_item_sku` → `warehouse_sku` z `quantity_base`, `waste_percent` |
| `sh_doc_sequences` | `sh_` | Atomowe sekwencje numerów dokumentów |

---

## 2. PIĘĆ SILNIKÓW MAGAZYNOWYCH — Szczegółowy Audyt

### 2.1 PzEngine — Przyjęcie Zewnętrzne (PZ)

**Lokalizacja:** `core/PzEngine.php` · `api/warehouse/receipt.php`

**Co robi:**
- Przyjmuje dostawę z fakturą → tworzy dokument PZ z liniami
- Przelicza AVCO (średnią ważoną) dla każdego przyjętego surowca
- Auto-mapuje nazwy z faktury na wewnętrzne SKU przez `sh_product_mapping`

**Algorytm AVCO:**
```
jeśli oldQty ≤ 0 lub (oldQty + rcvQty) = 0:
    newAvco = unitNetCost                        (safe guard)
w przeciwnym razie:
    newAvco = (oldQty × oldAvco + rcvQty × unitNetCost) / (oldQty + rcvQty)
    zaokrąglone do 6 miejsc dziesiętnych
```

**SQL kluczowe:**
- `SELECT ... FOR UPDATE` na `wh_stock` (pesymistyczny lock)
- `INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), current_avco_price = VALUES(current_avco_price)` (atomowy upsert)
- Mapowanie: `SELECT internal_sku FROM sh_product_mapping WHERE LOWER(external_name) = LOWER(:ext)`

**Walidacje:** Ilość > 0, cena ≥ 0, SKU zmapowany (lub `PzMappingException` → HTTP 400 z `unmapped_product`)

**Stan:** ✅ W pełni zaimplementowany i produkcyjny

---

### 2.2 WzEngine — Wydanie na Zamówienie (WZ)

**Lokalizacja:** `core/WzEngine.php` · wywoływany z `api/orders/checkout.php` i `api/online/engine.php`

**Co robi:**
- Dekompozycja zamówienia na surowce: receptury + modyfikatory + waste% + half/half + kompozyty A+B
- Automatyczne ściągnięcie ze stanu po finalizacji zamówienia
- Preflight `checkAvailability()` blokujący checkout przy brakach

**Algorytm dekompozycji:**
```
DLA KAŻDEJ linii zamówienia:
  jeśli item_type = 'half_half':
    załaduj dzieci z sh_menu_items (parent_sku), multiplier = 0.5
  jeśli SKU zawiera '+':
    split na części, multiplier = 0.5 per część
  w przeciwnym razie:
    multiplier = 1.0

  DLA KAŻDEGO składnika receptury (sh_recipes):
    jeśli SKU w liście usunięte_składniki → pomiń
    deductQty = quantity_base × (1 + waste_percent/100) × multiplier × lineQty

  DLA KAŻDEGO dodanego modyfikatora:
    deductQty = linked_quantity × (1 + linked_waste_percent/100) × lineQty
```

**Alert 86 Convention:** Jeśli brak wiersza `wh_stock` — wstawiany jest **ujemny stan** (`quantity = -deductQty`). System pozwala na oversell; UI wyróżnia czerwonym kolorem.

**Preflight (`checkAvailability`):**
- Tolerancja epsilon: `availableQty + 0.0001 >= requiredQty`
- Zwraca `shortages: [{sku, required, available, deficit}]`
- **Znany gap:** Nie rozwiązuje `half_half` przez DB (`parent_sku`), tylko kompozyty `A+B` — może dać fałszywy "available" dla half/half

**Stan:** ✅ Zaimplementowany (z jednym znanym gap w preflight)

---

### 2.3 InwEngine — Inwentaryzacja (INW)

**Lokalizacja:** `core/InwEngine.php` · `api/warehouse/inventory.php` · `api/warehouse/approve.php`

**Co robi:**
- Porównuje stany systemowe ze stanami policzonymi
- 3-poziomowa eskalacja zatwierdzania na podstawie % odchylenia
- Automatyczne dokumenty kompensacyjne RW (straty) i PW (nadwyżki)

**System zatwierdzania:**

| Odchylenie | Poziom zatwierdzania | Automatyzm |
|-----------|---------------------|------------|
| ≤ 2% (konfigurowane `auto_approve_pct`) | `none` | Automatyczne zatwierdzenie + wyrównanie stanów + dokumenty RW/PW |
| 2% – 10% (`critical_pct`) | `manager` | Wymaga zatwierdzenia managera w `approval.html` |
| > 10% | `owner` | Wymaga zatwierdzenia właściciela |

**Tryb ślepy (UI):** `toggleBlindMode` w `warehouse_in.js` — ukrywa stany systemowe przed liczącym, zapobiega „dopasowywaniu" do systemu.

**AVCO:** Inwentaryzacja **nie zmienia AVCO** — tylko ilości. Nowe wiersze wstawiane z `current_avco_price = 0`.

**Stan:** ✅ W pełni zaimplementowany z workflow zatwierdzania

---

### 2.4 KorEngine — Korekta (KOR)

**Lokalizacja:** `core/KorEngine.php` · `api/warehouse/correction.php`

**Co robi:**
- Odwraca WZ po anulowaniu zamówienia
- Zwraca surowce na stan na podstawie **oryginalnych linii WZ** (nie rekalkulacji receptury)
- Przelicza AVCO przy zwrocie (ta sama formuła co PZ)

**Zabezpieczenia:**
- Jeden KOR na jeden WZ (duplikat → RuntimeException)
- Numer dokumentu: `KOR/{numer_WZ}/{id}` — powiązanie z oryginalnym WZ
- `returnCost = unit_net_cost ?: old_avco` z linii WZ (historyczny koszt)

**Stan:** ✅ W pełni zaimplementowany

---

### 2.5 MmEngine — Przesunięcie Międzymagazynowe (MM)

**Lokalizacja:** `core/MmEngine.php` · `api/warehouse/transfer.php`

**Co robi:**
- Transferuje surowce między magazynami z blendowaniem AVCO
- Waliduje dostępność w magazynie źródłowym (hard fail, brak ujemnych stanów)

**Reguły:**
- Source: `sourceQty < transferQty` → RuntimeException (409)
- Target: AVCO blendowany tą samą formułą co PZ
- Jedno logowanie per linia: source (negative `change_qty`) + target (positive)

**Stan:** ✅ W pełni zaimplementowany

---

## 3. DODATKOWE KOMPONENTY

### 3.1 FoodCostEngine — Kalkulacja Food Cost

**Lokalizacja:** `core/FoodCostEngine.php` · `api/reports/food_cost.php`

**Formuła per składnik receptury:**
```
ingredient_cost = quantity_base × AVCO
waste_cost      = quantity_base × (waste_percent / 100) × AVCO
total_food_cost = Σ(ingredient_cost + waste_cost)
```

**Analiza per kanał:** Pobiera ceny z `sh_price_tiers` (POS/Takeaway/Delivery), oblicza food cost % i marżę per kanał.

**Status tiers:**
| Food Cost % | Status |
|-------------|--------|
| ≤ 25% | `excellent` |
| ≤ 33% | `healthy` |
| ≤ 40% | `at_risk` |
| > 40% | `critical` |

### 3.2 Margin Guardian (frontend)

**Lokalizacja:** `modules/studio/js/studio_margin.js`

Real-time kalkulator marży w Menu Studio — pobiera słownik AVCO z `api/warehouse/avco_dict.php`, oblicza food cost per kanał z uwzględnieniem waste i konwersji jednostek.

### 3.3 Mapowanie Faktur → SKU

**Lokalizacja:** `api/warehouse/mapping.php` · `modules/warehouse/settings_mapping.html`

- CRUD na `sh_product_mapping`: `external_name` (nazwa z faktury) → `internal_sku` (SKU w sys_items)
- Case-insensitive matching: `LOWER(external_name) = LOWER(:ext)`
- Użycie: `PzEngine` auto-resolve przy przyjęciu — jeśli brak mapowania → `PzMappingException`

### 3.4 Control Tower — Dashboard Operacyjny

**Lokalizacja:** `modules/warehouse/warehouse_control_tower.html` · `warehouse_core.js`

- Alert 86: Liczba SKU z qty ≤ 0
- Wartość magazynu: Σ(qty × AVCO) dla qty > 0
- Oczekujące drafty PZ
- Auto-refresh co 30s
- Quick actions: PZ modal, RW modal, nowy surowiec
- Smart Action Panel: klik na wiersz → PZ/RW/IN per surowiec

### 3.5 Rejestr Dokumentów

**Lokalizacja:** `modules/warehouse/documents.html` · `warehouse_documents.js`

Filtrowany widok (All/PZ/RW/INW/MM/KOR/WZ) z paginacją — numer dokumentu, typ, status, magazyn, linie, wartość netto.

---

## 4. CO BRAKUJE — GAP ANALYSIS

### 4.1 KSeF / e-Faktura (❌ Niezaimplementowane)

| Element | Stan |
|---------|------|
| Integracja z API KSeF (Krajowy System e-Faktur) | ❌ Brak — UI ma etykiety "KSeF Ready", ale brak klienta API |
| Pole `ksef_code` w `sys_items` | ❌ Brak kolumny w bazie (UI zbiera pole, ale `add_item.php` go nie zapisuje) |
| Automatyczne pobieranie faktur z KSeF | ❌ Brak |
| Parsowanie XML UPO / FA(2) | ❌ Brak |
| Generowanie JPK_MAG / JPK_V7M | ❌ Brak |
| Auto-tworzenie PZ z pobranej e-faktury | ❌ Brak |
| Mapowanie pozycji e-faktury na SKU | ❌ Brak (istniejący `sh_product_mapping` wspiera tylko ręczne mapowanie) |

### 4.2 Automatyczne Alerty i Stany Krytyczne (❌ Częściowo)

| Element | Stan |
|---------|------|
| Alert 86 (zero/ujemne stany) | ✅ UI + backend (ujemne stany dozwolone) |
| Stany minimalne per SKU (`min_stock`) | ❌ Brak kolumny w `wh_stock` ani `sys_items` |
| Stany optymalne per SKU (`optimal_stock`) | ❌ Brak |
| Stany krytyczne z alertem | ❌ Brak (brak thresholdów w bazie) |
| Auto-generowanie list zakupowych | ❌ Brak |
| Powiadomienia push/SMS o niskich stanach | ❌ Brak |
| Predykcja zużycia (ML/heurystyka) | ❌ Brak |

### 4.3 Automatyzacja Procesów (❌ Brak)

| Element | Stan |
|---------|------|
| Auto-PZ z e-faktury KSeF | ❌ Brak |
| Auto-RW po inwentaryzacji (straty automatyczne) | ✅ Jest — `InwEngine` generuje RW/PW po zatwierdzeniu |
| Auto-zamówienie do dostawcy przy niskim stanie | ❌ Brak |
| Harmonogram inwentaryzacji (cron) | ❌ Brak |
| Raportowanie cykliczne (food cost, manka) | ❌ Brak |
| Integracja z hurtowniami (API dostawców) | ❌ Brak |
| OCR/AI rozpoznawanie faktur papierowych | ❌ Brak |

### 4.4 Luki w Istniejącym Kodzie

| Problem | Priorytet | Opis |
|---------|-----------|------|
| Preflight half/half | Średni | `checkAvailability` nie rozwiązuje `half_half` przez DB — może dać fałszywy "available" |
| FoodCost vs WZ waste formula | Niski | `FoodCostEngine` liczy `base + waste` oddzielnie; `WzEngine` liczy `base × (1 + waste%)` — drobna rozbieżność w raportowaniu vs rzeczywistym zużyciu |
| Numeracja dokumentów | Niski | Silniki magazynowe używają `TYPE/YYYY/mm/dd/{insert_id}`; `SequenceEngine` używa `TYPE/YYYYMMDD/####` — niespójność |
| batch_rw vs internal_rw | Niski | `batch_rw` nie ustawia `status='completed'` w nagłówku (zależy od DEFAULT w DB) |
| KSeF pole w UI | Niski | Control Tower zbiera "Kod KSeF" ale `saveNewItem()` nie wysyła go do API |

---

## 5. WIZJA DOCELOWA — Pełna Automatyzacja

### 5.1 Faza 1: KSeF Integration (fundamenty e-fakturowania)

```
                    ┌──────────────────┐
                    │   KSeF API MF    │
                    │  (Ministerstwo   │
                    │   Finansów)      │
                    └────────┬─────────┘
                             │ REST/XML
                    ┌────────▼─────────┐
                    │  KSeFClient.php   │
                    │  • authorize()    │
                    │  • fetchInvoices()│
                    │  • getInvoice()   │
                    │  • getUPO()       │
                    └────────┬─────────┘
                             │
                    ┌────────▼─────────┐
                    │ KSeFParser.php    │
                    │  • parseFA2()     │
                    │  • extractLines() │
                    │  • mapToSKU()     │
                    └────────┬─────────┘
                             │
              ┌──────────────▼──────────────┐
              │   Auto-PZ Pipeline          │
              │  1. Pobierz e-faktury z KSeF│
              │  2. Parsuj XML → linie      │
              │  3. Mapuj pozycje → SKU     │
              │     (sh_product_mapping)    │
              │  4. Oznacz niezmapowane     │
              │  5. Utwórz draft PZ        │
              │  6. Manager zatwierdza      │
              │  7. PzEngine.processReceipt │
              └─────────────────────────────┘
```

**Wymagane zmiany DB:**
```sql
ALTER TABLE sys_items
  ADD COLUMN ksef_code VARCHAR(50) NULL COMMENT 'Kod GTU/CN/PKWiU z KSeF',
  ADD COLUMN gtin VARCHAR(14) NULL COMMENT 'EAN/GTIN dla auto-mapowania';

CREATE TABLE wh_ksef_imports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  ksef_reference_number VARCHAR(100) NOT NULL,
  invoice_number VARCHAR(100),
  supplier_nip VARCHAR(20),
  supplier_name VARCHAR(255),
  invoice_date DATE,
  xml_hash VARCHAR(64),
  status ENUM('fetched','parsed','mapped','draft_pz','completed','error') DEFAULT 'fetched',
  pz_document_id BIGINT UNSIGNED NULL,
  raw_xml LONGTEXT,
  parsed_json JSON,
  unmapped_lines_json JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ksef_ref (tenant_id, ksef_reference_number),
  INDEX idx_status (tenant_id, status)
);
```

### 5.2 Faza 2: Inteligentne Alerty i Stany Krytyczne

```
              ┌─────────────────────────────┐
              │  wh_stock_thresholds        │
              │  (tenant, warehouse, sku)   │
              │  • min_stock (krytyczny)    │
              │  • optimal_stock (optymalny)│
              │  • reorder_point            │
              │  • lead_time_days           │
              │  • preferred_supplier_id    │
              └─────────────┬───────────────┘
                            │
              ┌─────────────▼───────────────┐
              │  StockAlertEngine.php        │
              │  • checkThresholds()         │
              │  • generateAlerts()          │
              │  • generatePurchaseList()    │
              │  • predictDepletion()        │
              └─────────────┬───────────────┘
                            │
              ┌─────────────▼───────────────┐
              │  cron_stock_alerts.php       │
              │  (co 15 min)                │
              │  → sh_event_outbox           │
              │  → NotificationDispatcher    │
              │  → SMS/email/in-app          │
              └─────────────────────────────┘
```

**Wymagane zmiany DB:**
```sql
CREATE TABLE wh_stock_thresholds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  warehouse_id VARCHAR(50) NOT NULL DEFAULT 'MAIN',
  sku VARCHAR(100) NOT NULL,
  min_stock DECIMAL(12,3) DEFAULT 0 COMMENT 'Stan krytyczny',
  optimal_stock DECIMAL(12,3) DEFAULT 0 COMMENT 'Stan optymalny',
  reorder_point DECIMAL(12,3) DEFAULT 0 COMMENT 'Punkt zamówienia',
  reorder_qty DECIMAL(12,3) DEFAULT 0 COMMENT 'Ilość do zamówienia',
  lead_time_days SMALLINT UNSIGNED DEFAULT 1,
  preferred_supplier VARCHAR(255) NULL,
  auto_alert TINYINT(1) DEFAULT 1,
  UNIQUE KEY uq_threshold (tenant_id, warehouse_id, sku),
  INDEX idx_alert (tenant_id, auto_alert)
);

CREATE TABLE wh_stock_alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  warehouse_id VARCHAR(50) NOT NULL,
  sku VARCHAR(100) NOT NULL,
  alert_type ENUM('critical','low','reorder','surplus','expiring') NOT NULL,
  current_qty DECIMAL(12,3),
  threshold_qty DECIMAL(12,3),
  status ENUM('active','acknowledged','resolved') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_active (tenant_id, status, alert_type)
);
```

**Typy alertów:**

| Typ | Wyzwalacz | Akcja |
|-----|-----------|-------|
| `critical` | `quantity ≤ min_stock` | SMS do managera + blokada sprzedaży (opcjonalna) |
| `low` | `quantity ≤ reorder_point` | Powiadomienie in-app |
| `reorder` | `quantity ≤ reorder_point` + `auto_alert = 1` | Auto-generowanie listy zakupowej |
| `surplus` | `quantity > optimal_stock × 2` | Ostrzeżenie o nadmiernym zapasie |
| `expiring` | (przyszłość) termin ważności | Alert o przeterminowaniu |

### 5.3 Faza 3: Auto-Listy Zakupowe i Integracja z Dostawcami

```
              ┌─────────────────────────────┐
              │  PurchaseListEngine.php      │
              │  • generateFromThresholds() │
              │  • generateFromForecast()   │
              │  • groupBySupplier()        │
              │  • exportToPDF/CSV()        │
              │  • sendToSupplier()         │
              └─────────────┬───────────────┘
                            │
              ┌─────────────▼───────────────┐
              │  wh_purchase_orders         │
              │  (draft → sent → received)  │
              │  → auto-PZ po potwierdzeniu │
              └─────────────────────────────┘
```

**Workflow docelowy:**
1. `cron_stock_alerts.php` wykrywa stany poniżej `reorder_point`
2. `PurchaseListEngine` generuje listę zakupową pogrupowaną per dostawca
3. Manager zatwierdza lub modyfikuje
4. System wysyła zamówienie do dostawcy (email/API)
5. Po otrzymaniu dostawy → auto-draft PZ (lub PZ z KSeF)

### 5.4 Faza 4: Predykcja i Optymalizacja

```
              ┌─────────────────────────────┐
              │  ConsumptionForecast.php     │
              │  • analyzeHistory()         │
              │  • predictWeekly()          │
              │  • seasonalAdjust()         │
              │  • suggestOptimalStock()    │
              └─────────────────────────────┘
```

**Algorytm predykcji (heurystyczny, bez ML):**
```
DLA KAŻDEGO SKU:
  1. Pobierz wh_stock_logs za ostatnie 90 dni (type = 'WZ')
  2. Oblicz średnie dzienne zużycie per dzień tygodnia
  3. Zastosuj współczynnik sezonowy (porównanie miesiąc vs średnia)
  4. predicted_depletion_date = current_qty / avg_daily_consumption
  5. suggested_reorder_date = predicted_depletion - lead_time_days
  6. suggested_optimal = avg_weekly × 1.5 + safety_buffer
```

### 5.5 Faza 5: JPK / Raportowanie Podatkowe

| Raport | Format | Opis |
|--------|--------|------|
| JPK_MAG | XML | Ewidencja magazynowa: PZ, WZ, RW, MM per okres |
| JPK_V7M | XML | Deklaracja VAT (z faktur PZ) |
| Raport Food Cost | PDF/CSV | Per danie, per kategoria, per kanał, trend |
| Raport Manka | PDF | Wyniki inwentaryzacji: braki, nadwyżki, wartość strat |
| Raport AVCO | CSV | Historia zmian AVCO per SKU |

---

## 6. MATRYCA OBECNY STAN vs DOCELOWY

| Funkcjonalność | Obecny stan | Docelowy stan | Priorytet |
|----------------|-------------|---------------|-----------|
| **PZ — Przyjęcie** | ✅ Ręczne + mapowanie nazw | Auto-PZ z KSeF + OCR faktur papierowych | Wysoki |
| **WZ — Wydanie** | ✅ Auto z checkout (receptury, waste, half/half) | Bez zmian (dojrzałe) | — |
| **INW — Inwentaryzacja** | ✅ 3-poziomowa eskalacja + RW/PW | + Harmonogram cron + inwentaryzacja mobilna (skan) | Średni |
| **KOR — Korekta** | ✅ Odwracanie WZ po anulowaniu | Bez zmian (dojrzałe) | — |
| **MM — Transfer** | ✅ Z walidacją + AVCO blend | + Zatwierdzanie MM per kierunek | Niski |
| **RW — Strata** | ✅ Single + batch | + Auto-RW z przeterminowań (termin ważności) | Średni |
| **AVCO** | ✅ Silnik 6-cyfrowy z safe guard | Bez zmian (dojrzałe) | — |
| **Food Cost** | ✅ Per-kanał + 4 status tiers | + Trend, porównanie okresów, alert przy wzroście | Średni |
| **Margin Guardian** | ✅ Real-time w Studio | + Alert przy spadku marży poniżej progu | Średni |
| **Alert 86** | ✅ UI (zero/ujemne stany) | + Push/SMS + opcjonalna blokada sprzedaży | Wysoki |
| **Stany minimalne** | ❌ Brak | Thresholds per SKU z alertami | Wysoki |
| **Listy zakupowe** | ❌ Brak | Auto-generowanie z grupowaniem per dostawca | Wysoki |
| **KSeF** | ❌ Brak (tylko etykiety UI) | Pełna integracja: pobieranie, parsowanie, auto-PZ | Wysoki |
| **JPK / SAF-T** | ❌ Brak | Eksport JPK_MAG, JPK_V7M | Średni |
| **Predykcja zużycia** | ❌ Brak | Heurystyka na wh_stock_logs (90-dniowa historia) | Niski |
| **OCR faktur** | ❌ Brak | Rozpoznawanie skanów → auto-mapowanie | Niski |
| **Integracja dostawcy** | ❌ Brak | Email/API zamówienie + auto-PZ po dostawie | Niski |
| **Mapowanie faktur** | ✅ Ręczne CRUD | + Auto-learn z potwierdzonych PZ + sugestie AI | Średni |
| **Control Tower** | ✅ Dashboard z Alert 86 | + Wykresy trendów + predykcja + alerty real-time | Średni |
| **Rejestr dokumentów** | ✅ Lista z filtrami | + Drill-down do linii + edycja draft + PDF export | Niski |
| **Numeracja dokumentów** | ⚠️ Niespójna (insert_id vs SequenceEngine) | Ujednolicenie na SequenceEngine | Niski |

---

## 7. ARCHITEKTURA DOCELOWA — PEŁNA AUTOMATYZACJA

```
┌─────────────────────────────────────────────────────────────────────┐
│                        WARSTWA WEJŚCIA                              │
├──────────────┬──────────────┬──────────────┬────────────────────────┤
│  KSeF API    │  OCR/AI      │  Ręczne PZ   │  API Dostawcy         │
│  (e-Faktura) │  (skan/foto) │  (manager)   │  (hurtownia)          │
└──────┬───────┴──────┬───────┴──────┬───────┴────────┬──────────────┘
       │              │              │                │
       ▼              ▼              ▼                ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    AUTO-MAPPING ENGINE                                │
│  sh_product_mapping + auto-learn + fuzzy match + GTIN/KSeF code     │
└──────────────────────────────┬───────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    SILNIKI MAGAZYNOWE (istniejące)                    │
│  PzEngine │ WzEngine │ InwEngine │ KorEngine │ MmEngine             │
│  + AVCO   │ + Alert86│ + Eskalacja│+ WZ reversal│ + AVCO blend      │
└──────────────────────────────┬───────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    WARSTWA INTELIGENCJI (nowa)                        │
├──────────────────┬───────────────────┬───────────────────────────────┤
│ StockAlertEngine │ PurchaseListEngine │ ConsumptionForecast          │
│ • thresholds     │ • groupBySupplier  │ • 90-day history             │
│ • 5 typów alertów│ • PDF/CSV export   │ • weekly prediction          │
│ • cron 15 min    │ • auto-send email  │ • seasonal adjustment        │
└──────────────────┴───────────────────┴───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    WARSTWA RAPORTOWANIA (nowa)                        │
│  JPK_MAG │ JPK_V7M │ Food Cost Trend │ Manka Report │ AVCO History │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 8. PODSUMOWANIE

### Co jest (zaimplementowane i dojrzałe):
- 5 silników magazynowych (PZ, WZ, INW, KOR, MM) z pełną logiką AVCO
- Automatyczne ściąganie ze stanu po checkout (WzEngine z dekompozycją receptur)
- 3-poziomowa eskalacja inwentaryzacji z auto-RW/PW
- Mapowanie nazw faktur na wewnętrzne SKU
- Food cost per kanał + Margin Guardian w Studio
- Control Tower z Alert 86 i auto-refresh
- 10 ekranów UI + rejestr dokumentów + workflow zatwierdzania

### Co brakuje (plan docelowy):
- **KSeF** — pełna integracja z Krajowym Systemem e-Faktur (auto-pobieranie, parsowanie XML, auto-PZ)
- **Stany krytyczne** — thresholds per SKU z alertami SMS/push
- **Listy zakupowe** — auto-generowanie z grupowaniem per dostawca
- **Predykcja** — heurystyczna prognoza zużycia na 90-dniowej historii
- **JPK** — eksport raportów podatkowych (JPK_MAG, JPK_V7M)
- **OCR** — rozpoznawanie faktur papierowych

### Estymacja implementacji docelowej:

| Faza | Zakres | Złożoność |
|------|--------|-----------|
| F1: KSeF | Klient API + parser XML + auto-PZ pipeline | Wysoka (zależność od API MF) |
| F2: Alerty | Tabele thresholds + StockAlertEngine + cron + UI | Średnia |
| F3: Listy zakupowe | PurchaseListEngine + workflow zamówień + PDF | Średnia |
| F4: Predykcja | Analiza wh_stock_logs + heurystyka + UI trendów | Średnia |
| F5: JPK | Generatory XML + walidacja schematów XSD | Średnia (zależność od przepisów) |

---

*Audyt przeprowadzony na żywym kodzie repozytorium SliceHub Enterprise OS. Wszystkie opisane mechanizmy zweryfikowane w plikach źródłowych.*
