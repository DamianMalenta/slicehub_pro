# MODUŁ MAGAZYNOWY — Audyt Stanu i Wizja Docelowa

> Analiza techniczna pod wniosek o dofinansowanie. Audyt kodu: **rewizja 2026-05-20** (KSeF API v2 + inbox produkcyjny + OPEX per linia + BI P&L).

---

## 1. OBECNA ARCHITEKTURA

**10 ekranów UI** (`modules/warehouse/`), **13 endpointów API** (`api/warehouse/`), **5 silników domenowych** (`core/`), **8 tabel bazodanowych** w 3 silosach (`sys_`, `wh_`, `sh_`).

| Tabela | Przeznaczenie |
|--------|---------------|
| `sys_items` | Słownik surowców (SKU, nazwa, jednostka, aliasy) |
| `wh_stock` | Stany per (tenant, warehouse, SKU): ilość, cena netto, AVCO |
| `wh_documents` | Nagłówki dokumentów (PZ/RW/MM/INW/WZ/KOR/PW), status, dostawca |
| `wh_document_lines` | Linie: SKU, ilość, ceny, AVCO stary/nowy, system/counted/variance |
| `wh_stock_logs` | Dziennik zmian: `change_qty` (+/-), `after_qty`, typ dokumentu |
| `sh_product_mapping` | Mapowanie nazwy z faktury → wewnętrzny SKU |
| `sh_recipes` | Receptury: danie → surowce z `quantity_base`, `waste_percent` |
| `sh_doc_sequences` | Atomowe sekwencje numerów dokumentów |

---

## 2. PIĘĆ SILNIKÓW MAGAZYNOWYCH

### 2.1 PzEngine — Przyjęcie Zewnętrzne (PZ) ✅

Przyjmuje dostawę → tworzy dokument PZ → przelicza **AVCO** (średnią ważoną) → auto-mapuje nazwy z faktury na SKU przez `sh_product_mapping`.

**Algorytm AVCO:** `newAvco = (oldQty × oldAvco + rcvQty × unitCost) / (oldQty + rcvQty)` — zaokrąglony do 6 miejsc. Safe guard na zero/ujemny denominator.

**SQL:** `SELECT ... FOR UPDATE` (pesymistyczny lock) + `INSERT ... ON DUPLICATE KEY UPDATE` (atomowy upsert). Brak mapowania → `PzMappingException` → HTTP 400.

### 2.2 WzEngine — Wydanie na Zamówienie (WZ) ✅

Automatyczne ściągnięcie ze stanu po checkout — pełna dekompozycja: receptury + modyfikatory + waste% + half/half + kompozyty A+B.

**Formuła:** `deductQty = quantity_base × (1 + waste_percent/100) × multiplier × lineQty`

**Alert 86 Convention:** Brak wiersza `wh_stock` → wstawiany **ujemny stan**. System pozwala na oversell; UI oznacza czerwonym.

**Preflight `checkAvailability()`:** Blokuje checkout przy brakach z tolerancją epsilon. Zwraca listę `shortages`.

### 2.3 InwEngine — Inwentaryzacja (INW) ✅

Porównanie stanów systemowych z policzonymi. **3-poziomowa eskalacja:**

| Odchylenie | Zatwierdzanie | Automatyzm |
|-----------|---------------|------------|
| ≤ 2% | Auto | Wyrównanie stanów + auto-dokumenty RW/PW |
| 2% – 10% | Manager | Wymaga zatwierdzenia |
| > 10% | Owner | Wymaga zatwierdzenia właściciela |

Tryb ślepy (UI): ukrywa stany systemowe przed liczącym. AVCO nie zmienia się przy inwentaryzacji.

### 2.4 KorEngine — Korekta (KOR) ✅

Odwraca WZ po anulowaniu zamówienia. Bazuje na **oryginalnych liniach WZ** (nie rekalkulacji receptury). Jeden KOR per WZ (duplikat → error). AVCO przeliczany przy zwrocie tą samą formułą co PZ.

### 2.5 MmEngine — Przesunięcie MM ✅

Transfer między magazynami z blendowaniem AVCO na target. Źródło: hard fail jeśli `sourceQty < transferQty` (brak ujemnych stanów przy MM).

---

## 3. KOMPONENTY UZUPEŁNIAJĄCE

| Komponent | Co robi |
|-----------|---------|
| **FoodCostEngine** | Kalkulacja food cost per kanał (POS/Takeaway/Delivery) z AVCO + waste. Status tiers: excellent ≤25%, healthy ≤33%, at_risk ≤40%, critical >40% |
| **Margin Guardian** | Real-time kalkulator marży w Menu Studio — słownik AVCO z `avco_dict.php` |
| **Mapowanie faktur** | CRUD `sh_product_mapping`: nazwa z faktury → SKU. Case-insensitive. Użycie: auto-resolve w PzEngine |
| **Control Tower** | Dashboard: Alert 86, wartość magazynu, drafty PZ, auto-refresh 30s, quick actions (PZ/RW/nowy surowiec), Smart Action Panel |
| **Rejestr dokumentów** | Filtrowany widok (PZ/RW/INW/MM/KOR/WZ) z paginacją |
| **Workflow zatwierdzania** | Ekran `approval.html` dla INW w statusie `pending_approval` |

---

## 4. GAP ANALYSIS — Czego Brakuje

### 4.1 KSeF / e-Faktura ✅ (wdrożone 2026-05-11 — 2026-05-14)

Moduł **`/modules/procurement/`** + `api/procurement/` + `core/Ksef/` — produkcyjna ścieżka od faktury do magazynu.

| Element | Stan |
|---------|------|
| Integracja z **KSeF API v2** MF (`api*.ksef.mf.gov.pl/v2`, JWT + kontekst NIP tenanta) | ✅ `core/Ksef/Client.php` — challenge, token, refresh, `POST /invoices/query/metadata`, `GET /invoices/ksef/{ksefNumber}` |
| Worker poll inbox | ✅ `scripts/worker_ksef_inbox.php` — margines 14 dni, Issue ∪ Invoicing, retry HTTP 429 |
| Upload ręczny FA(2)/FA(3) XML | ✅ `upload_xml` — wykrywanie duplikatu (409), opcjonalne `replace` |
| AutoScan mapowania linii → SKU | ✅ `InboxInvoiceRepository::matchInvoiceLines` — poziomy EXACT / ALIAS / NAME / FUZZY |
| Akceptacja → dokument PZ | ✅ `accept` → `PzEngine::processReceipt` (tylko linie `INVENTORY`) |
| Rozdział kosztów: magazyn vs OPEX | ✅ `sh_ksef_invoice_lines.line_type`: `INVENTORY` \| `EXPENSE` — OPEX trafia do **BI** (`BiEngine`), nie do PZ |
| Kategorie kosztów / bulk edit linii | ✅ UI + API (m057) — masowa edycja SKU i tagów OPEX |
| Ustawienia KSeF w Settings | ✅ dedykowana karta integracji (NIP z Profilu firmy, test `GET /rate-limits`) |
| JPK_MAG / JPK_V7M | ❌ (roadmapa raportowa) |

### 4.2 Alerty i Automatyzacja ❌

| Element | Stan |
|---------|------|
| Alert 86 (zero/ujemne stany) | ✅ UI + backend |
| Stany minimalne / optymalne / reorder point per SKU | ❌ Brak kolumn w DB |
| Auto-generowanie list zakupowych | ❌ |
| SMS/push przy niskich stanach | ❌ |
| Predykcja zużycia | ❌ |
| Auto-zamówienie do dostawcy | ❌ |
| Harmonogram inwentaryzacji (cron) | ❌ |
| OCR faktur papierowych | ❌ |

### 4.3 Znane Luki Techniczne

| Problem | Priorytet |
|---------|-----------|
| `checkAvailability` nie rozwiązuje `half_half` przez DB — fałszywy "available" | Średni |
| FoodCostEngine vs WzEngine: drobna rozbieżność formuły waste | Niski |
| Niespójna numeracja dokumentów (insert_id vs SequenceEngine) | Niski |
| ~~KSeF pole w UI Control Tower nie trafia do API~~ | Zamknięte — inbox w module Procurement |

---

## 5. WIZJA DOCELOWA — 5 Faz Automatyzacji

### Faza 1: KSeF Integration — **✅ ZREALIZOWANA (rdzeń)**

```
KSeF API v2 MF → Client.php → InboxInvoiceRepository → AutoScan → accept → PzEngine
  1. Poll metadata / upload XML
  2. Parsuj FA(2)/FA(3) → sh_ksef_invoice_lines
  3. Mapuj pozycje → SKU (AutoScan + sh_product_mapping)
  4. Manager akceptuje INVENTORY → PZ; EXPENSE → OPEX w BiEngine (bez podwójnego liczenia magazynu)
```

**Tabele (migracje m056–m057):** `sh_ksef_invoices`, `sh_ksef_invoice_lines` (`line_type`, `cost_category_id`, …).

### Faza 2: Inteligentne Alerty

```
wh_stock_thresholds (per tenant/warehouse/SKU)
  → StockAlertEngine.php (cron co 15 min)
    → sh_event_outbox → NotificationDispatcher → SMS/email/in-app
```

**5 typów alertów:**

| Typ | Wyzwalacz | Akcja |
|-----|-----------|-------|
| `critical` | `qty ≤ min_stock` | SMS + opcjonalna blokada sprzedaży |
| `low` | `qty ≤ reorder_point` | Powiadomienie in-app |
| `reorder` | `qty ≤ reorder_point + auto_alert` | Auto-lista zakupowa |
| `surplus` | `qty > optimal × 2` | Ostrzeżenie o nadmiarze |
| `expiring` | Termin ważności (przyszłość) | Alert przeterminowanie |

### Faza 3: Auto-Listy Zakupowe

```
PurchaseListEngine.php
  → generateFromThresholds() → groupBySupplier() → PDF/CSV
  → wh_purchase_orders (draft → sent → received → auto-PZ)
```

### Faza 4: Predykcja Zużycia

**Algorytm heurystyczny (bez ML):**
```
Per SKU:
  1. wh_stock_logs za 90 dni (type='WZ') → średnie dzienne zużycie per dzień tygodnia
  2. Współczynnik sezonowy (miesiąc vs średnia)
  3. predicted_depletion = current_qty / avg_daily
  4. suggested_reorder = predicted_depletion - lead_time_days
```

### Faza 5: Raportowanie Podatkowe

| Raport | Format | Opis |
|--------|--------|------|
| JPK_MAG | XML | Ewidencja magazynowa per okres |
| JPK_V7M | XML | Deklaracja VAT z faktur PZ |
| Food Cost Trend | PDF/CSV | Per danie, kategoria, kanał |
| Raport Manka | PDF | Inwentaryzacja: braki, nadwyżki, wartość strat |

---

## 6. MATRYCA: STAN OBECNY vs DOCELOWY

| Funkcjonalność | Teraz | Docelowo | Priorytet |
|----------------|-------|----------|-----------|
| PZ — Przyjęcie | ✅ Ręczne + mapowanie | Auto-PZ z KSeF + OCR | Wysoki |
| WZ — Wydanie | ✅ Auto z checkout | Bez zmian (dojrzałe) | — |
| INW — Inwentaryzacja | ✅ 3-poziomowa eskalacja | + Cron harmonogram + skan mobilny | Średni |
| KOR — Korekta | ✅ Reversal WZ | Bez zmian (dojrzałe) | — |
| MM — Transfer | ✅ Walidacja + AVCO blend | Bez zmian | — |
| AVCO | ✅ 6-cyfrowy z safe guard | Bez zmian (dojrzałe) | — |
| Food Cost | ✅ Per kanał + 4 tiers | + Trend, alert przy wzroście | Średni |
| Alert 86 | ✅ UI (zero/ujemne) | + SMS/push + blokada sprzedaży | Wysoki |
| Stany minimalne | ❌ | Thresholds per SKU z alertami | Wysoki |
| Listy zakupowe | ❌ | Auto-gen per dostawca | Wysoki |
| KSeF | ✅ API v2 + inbox + AutoScan + accept→PZ + OPEX/BI | JPK eksport, OCR papier | Średni |
| BI P&L (COGS/OPEX/kapitał) | ✅ `BiEngine` + moduł `/modules/bi/` | Więcej wymiarów (lokal, kategoria menu) | Średni |
| JPK | ❌ | Eksport JPK_MAG, JPK_V7M | Średni |
| Predykcja | ❌ | Heurystyka 90-dniowa | Niski |
| OCR faktur | ❌ | AI rozpoznawanie skanów | Niski |
| Mapowanie | ✅ Ręczne CRUD | + Auto-learn + fuzzy match | Średni |

---

## 7. ARCHITEKTURA DOCELOWA

```
┌─────────────────────────────────────────────────────────────┐
│                     WARSTWA WEJŚCIA                          │
│  KSeF API  │  OCR/AI  │  Ręczne PZ  │  API Dostawcy        │
└──────┬──────┴─────┬────┴──────┬──────┴────────┬─────────────┘
       ▼            ▼           ▼               ▼
┌─────────────────────────────────────────────────────────────┐
│  AUTO-MAPPING: sh_product_mapping + auto-learn + fuzzy/GTIN │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  SILNIKI MAGAZYNOWE (istniejące, dojrzałe)                   │
│  PzEngine │ WzEngine │ InwEngine │ KorEngine │ MmEngine     │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  WARSTWA INTELIGENCJI (nowa)                                 │
│  StockAlertEngine │ PurchaseListEngine │ ConsumptionForecast │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  RAPORTOWANIE: JPK_MAG │ JPK_V7M │ Food Cost │ Manka │ AVCO│
└─────────────────────────────────────────────────────────────┘
```

---

## 8. PODSUMOWANIE

**Zaimplementowane:** 5 silników z AVCO, auto-WZ z checkout (receptury + waste + half/half), 3-poziomowa eskalacja INW z auto-RW/PW, mapowanie faktur, Food Cost per kanał, Margin Guardian, Control Tower, 10 ekranów UI.

**Do zbudowania:** KSeF (auto-PZ z e-faktur), thresholds z alertami SMS, auto-listy zakupowe, predykcja zużycia, JPK, OCR.

| Faza | Zakres | Złożoność |
|------|--------|-----------|
| F1: KSeF | Klient API + parser XML + auto-PZ | Wysoka |
| F2: Alerty | Thresholds + StockAlertEngine + cron | Średnia |
| F3: Listy zakupowe | PurchaseListEngine + workflow + PDF | Średnia |
| F4: Predykcja | Analiza wh_stock_logs + heurystyka | Średnia |
| F5: JPK | Generatory XML + XSD walidacja | Średnia |

---

*Audyt na żywym kodzie SliceHub Enterprise OS. Wszystkie mechanizmy zweryfikowane w plikach źródłowych.*
