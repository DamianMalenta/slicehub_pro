# Audyt fundamentów BI: sprzedaż, AVCO, HR, KSeF → PZ

**Typ:** READ-ONLY (analiza kodu i schematu, bez zmian w aplikacji).  
**Data:** 2026-05-14  
**Zakres:** `api/pos/engine.php`, silos zamówień (`sh_*`), `core/PzEngine.php`, `core/WzEngine.php`, HR (`HrClockEngine`, `api/backoffice/hr/engine.php`, worker payroll), `api/procurement/inbox.php` (akcja `accept`).

---

## 1. Sprzedaż i przychody (Frontline / POS)

### 1.1 Główny endpoint

| Artefakt | Rola |
|----------|------|
| `api/pos/engine.php` | Router akcji JSON; kluczowa akcja `process_order` (zapis zamówienia), `get_orders`, `accept_order`, rozliczenia płatności (`settle_and_close`, `fast_complete`), integracja z `CartEngine`, `OrderStateMachine`, `WarehouseConsumeHook`. |

### 1.2 Netto vs brutto — jak są zapisywane kwoty

**Konwencja pieniądza:** kwoty zamówień w `sh_orders` i `sh_order_lines` są w **groszach (INT)** — brutto z VAT w cenie.

| Tabela | Kolumny | Znaczenie |
|--------|---------|-----------|
| `sh_orders` | `subtotal`, `grand_total`, `discount_amount`, `delivery_fee`, `tip_amount` | W `process_order` ścieżka POS ustawia obecnie **`subtotal` = `grand_total`** na policzony `totalGrosze` (suma po pozycjach); **nie wypełnia** w tym INSERT/UPDATE osobno `discount_amount` ani `delivery_fee` (pozostają domyślne z bazy, zwykle 0), o ile inna ścieżka ich nie ustawi. |
| `sh_order_lines` | `unit_price`, `line_total`, `vat_rate`, `vat_amount` | `unit_price` — cena jednostkowa **brutto** w groszach; `line_total` = `unit_price * quantity` (brutto). `vat_amount` liczone w PHP jako część VAT w cenie: `round(lineTotal * vatRate / (100 + vatRate))` (**INT grosze**). **Netto linii (logicznie):** `line_total - vat_amount`. |

**Źródło cen w POS:** `CartEngine::calculate()` (serwer) nadpisuje sumę i ceny jednostkowe przy sukcesie walidacji (`process_order`), mapując `order_type` → kanał cenowy (`dine_in`→`POS`, `takeaway`→`Takeaway`, `delivery`→`Delivery`).

### 1.3 Kanały sprzedaży (Dine-in, Delivery, agregatory)

| Pole / mechanizm | Opis |
|------------------|------|
| `sh_orders.order_type` | Wartości z payloadu: np. `dine_in`, `takeaway`, `delivery` — **model operacyjny** (stolik vs na wynos vs dostawa). |
| `sh_orders.channel` | Mapowanie 1:1 z `order_type` w POS: `POS` / `Takeaway` / `Delivery` — **kanał cenowy** zgodny z `sh_price_tiers.channel`. |
| `sh_orders.source` | String źródła UI / integracji (np. `POS`, `local`, `waiter`). W POS przy tworzeniu zamówienia zapisywany jest payload `source`. |
| `api/gateway/intake.php` | Zamówienia zewnętrzne: wymaga `channel` ∈ {`POS`,`Takeaway`,`Delivery`} oraz `order_type` ∈ {`dine_in`,`takeaway`,`delivery`}. **`source`** payloadu to whitelist (`web`, `aggregator_uber`, …); do `sh_orders.source` trafia **prefiks numeru** (`WWW`, `UBR`, `PYS`, …), a przy migracji gateway zapisuje też `gateway_source` / `gateway_external_id` dla pełnej identyfikacji agregatora. |

**Agregatory:** rozróżniane przez **`gateway_source`** (np. `aggregator_uber`) oraz prefiks `order_number`; nie ma osobnej kolumny „commission” w zamówieniu w analizowanym kodzie POS/gateway.

### 1.4 Zniżki i prowizje

| Temat | Gdzie w systemie |
|-------|------------------|
| **Zniżki (promocje, kody)** | Silnik: `api/cart/CartEngine.php` — `discount_grosze`, `subtotal_grosze`, `grand_total_grosze`, tabele `sh_promotions`, `sh_promo_codes`. **Gateway** zapisuje `sh_orders.discount_amount` z wyniku `CartEngine::calculate()`. **POS `process_order`** w analizowanym fragmencie **nie zapisuje** `discount_amount` (różnica ścieżek: online/gateway vs lokalny POS). |
| **Prowizje agregatorów** | Brak dedykowanej kolumny w `sh_orders` / `sh_order_lines` w przeglądanym modelu. Prowizja musiałaby pochodzić z integracji zewnętrznej lub być dopisana w warstwie BI. |

### 1.5 Płatności — uwaga o nazwie tabeli

W repozytorium **nie występuje** tabela `sh_payments`. Kanoniczny zapis płatności per zamówienie to:

| Tabela | Kluczowe kolumny |
|--------|------------------|
| `sh_order_payments` | `order_id`, `tenant_id`, `method`, `amount_grosze`, `tendered_grosze`, `user_id` (kto zebrał — kierowca), `transaction_id`, czasem `created_at` (rozszerzenia enterprise). |

**Powiązanie z nagłówkiem:** `sh_orders.payment_method`, `payment_status` odzwierciedlają stan rozliczenia; sumaryczna logika zgodności z `grand_total` jest m.in. w `core/OrderStateMachine.php` (split tender vs prepaid).

### 1.6 Powiązane tabele i funkcje (Frontline)

**Tabele:** `sh_orders`, `sh_order_lines`, `sh_order_item_modifiers` (opcjonalnie), `sh_order_payments`, `sh_order_audit`, `sh_order_sequences`, `sh_price_tiers`, `sh_menu_items`, `sh_promotions`, `sh_promo_codes`, `sh_external_order_refs` (idempotencja gateway).

**Funkcje / klasy:** `CartEngine::calculate()`, `OrderStateMachine::transitionOrder()`, `OrderEventPublisher::publishOrderLifecycle()`, `pos/engine.php` → `process_order`, `accept_order`.

---

## 2. Food cost i magazyn (Supply Chain)

### 2.1 AVCO — `core/PzEngine.php`

| Element | Opis |
|---------|------|
| **Wejście** | `PzEngine::processReceipt(PDO, tenantId, warehouseId, payload, userId)` — `payload.lines[]`: `resolved_sku`, `quantity`, `unit_net_cost` (netto jednostkowe), opcjonalnie `vat_rate`, `external_name`. |
| **Wzór AVCO** | Dla istniejącego stanu: `newAvco = ((oldQty * oldAvco) + (rcvQty * unitNetCost)) / (oldQty + rcvQty)`; zaokrąglenie do 6 miejsc; gdy `oldQty <= 0` lub mianownik 0 → `newAvco = unitNetCost`. |
| **Zapis** | `wh_documents` (typ `PZ`), `wh_document_lines` (snapshot `old_avco`, `new_avco`, `unit_net_cost`, `line_net_value`), `wh_stock` (`quantity`, `current_avco_price` przez upsert), `wh_stock_logs` (dodatnie `change_qty` — przyjęcie). |
| **Mapowanie** | `AutoScanEngine::match()` / wyjątek `PzMappingException`; auto-learn aliasów po commicie. |

**Jednostki:** koszt w PZ jest **netto** (`unit_net_cost`); VAT linii dokumentu jest zapisywany w polu `vat_rate` linii dokumentu, ale wycena magazynowa opiera się o **netto**.

### 2.2 Zużycie na sprzedaży — czy odpis jest po AVCO?

| Artefakt | Odpowiedź |
|----------|-----------|
| `core/WarehouseConsumeHook.php` | Po **zaakceptowaniu** zamówienia woła `WzEngine::consumeForOrder` (poza transakcją zapisu zamówienia — post-commit). |
| `core/WzEngine.php` | Dla każdej skonsumowanej ilości SKU: `lineValue = round(deductQty * currentAvco, 2)` gdzie `currentAvco` pochodzi z **`wh_stock.current_avco_price`** w momencie odpisu; ten sam `currentAvco` trafia jako `unit_net_cost` na `wh_document_lines` dokumentu **WZ**. **Nie przelicza** AVCO przy wydaniu — **COGS momentu sprzedaży = ilość × bieżący AVCO**. |
| `wh_stock_logs` | Przy WZ: **`change_qty` ujemne** (wydanie), `document_type` = `WZ`. |

**Receptury:** `WzEngine` agreguje potrzeby z `sh_recipes` (+ modyfikatory z magazynem) zgodnie z dokumentacją w klasie (m.in. warianty rodzica, `combo_meta_json`).

### 2.3 Analityka teoretyczna (nie mutuje magazynu)

| Klasa | Rola |
|-------|------|
| `core/FoodCostEngine.php` | Koszt teoretyczny pozycji menu: `ingredient_cost = qty * (1 + waste%/100) * AVCO` z JOIN `sh_recipes` → `wh_stock` po **SKU + tenant_id** (tylko odczyt). |

### 2.4 Tabele magazynowe (wh\_)

`wh_documents`, `wh_document_lines`, `wh_stock`, `wh_stock_logs` — oraz most `sh_recipes` / `sh_modifiers` (silos `sh_`) do `wh_stock` wyłącznie przez **`sku`** i **`tenant_id`**.

---

## 3. Koszty pracy (HR)

### 3.1 Sesje clock-in / clock-out

| Tabela | Znaczenie |
|--------|-----------|
| `sh_work_sessions` | Jedna otwarta sesja na pracownika (`HrClockEngine`); pola m.in. `tenant_id`, `user_id`, `employee_id`, `session_uuid`, `start_time`, `end_time`, `total_hours`, `terminal_id`, `clock_in_source` / `clock_out_source`, `geo_lat_in/out`, `geo_lon_in/out`. |

**API wejścia:** `api/backoffice/hr/engine.php` — akcje `clock_in`, `clock_out`, `clock_status` (wywołują `HrClockEngine`).

**Silnik:** `core/HrClockEngine.php` — `clockIn()`, `clockOut()`, `resolveActiveRate()`, publikacja zdarzeń do `sh_event_outbox` (`aggregate_type = 'shift'`, typy `employee.clocked_in` / `employee.clocked_out`).

### 3.2 Stawki godzinowe

| Tabela | Znaczenie |
|--------|-----------|
| `sh_employee_rates` | Stawki **temporalne**: `rate_type` (np. `hourly`), `amount_minor` (grosze/h), `currency`, `effective_from`, `effective_to` (NULL = aktualna). |
| `sh_users.hourly_rate` | W migracji HR oznaczone jako **DEPRECATED** na rzecz `sh_employee_rates` — legacy. |

**Snapshot:** przy `clock_in` response zawiera `hourly_rate` z `resolveActiveRate()`. Przy `clock_out` event payload zawiera pole `rate_at_clock_in` ustawione na **tablicę** `{ amount_minor, currency }` (oraz `total_hours`). Worker payroll (poniżej) szuka opcjonalnie płaskiego `rate_at_clock_in` int w payloadzie; w typowym przypadku stosuje **lookup** `sh_employee_rates` po `start_time` sesji.

### 3.3 Naliczanie kosztu pracy (Labor cost) w okresie

| Skrypt / klasa | Rola |
|----------------|------|
| `scripts/worker_payroll_accrual.php` | Konsumuje `sh_event_outbox` (`employee.clocked_out`), liczy `earnings_minor` z `total_hours` × stawka (integer, HALF_UP), zapisuje **`sh_payroll_ledger`** przez `PayrollLedger::record()`. |
| `core/PayrollLedger.php` | Append-only; typ naliczenia z godzin: **`work_earnings`** (stała `TYPE_WORK_EARNINGS`). Kolumny m.in. `amount_minor`, `hours_qty`, `rate_applied_minor`, `ref_work_session_id`, `period_year` / `period_month` (przypisanie wg `start_time` sesji). |

**Agregacja BI:** `SUM(amount_minor)` po `tenant_id`, okresie (`period_year`, `period_month` lub zakres dat z JOIN do `sh_work_sessions.start_time`/`end_time`) i `entry_type = 'work_earnings'`.

**Uwaga dokumentacyjna:** migracja `043_hr_payroll_ledger.sql` w komentarzu SQL wymienia m.in. `hours_accrual`; **kod produkcyjny** używa wartości **`work_earnings`** (`PayrollLedger::TYPE_WORK_EARNINGS`).

### 3.4 Inne silniki (kontekst)

`core/PayrollEngine.php`, `core/TeamPayrollEngine.php` — dodatkowe ścieżki raportowe / zespoowe oparte m.in. o `sh_work_sessions` i legacy `hourly_rate`; dla kanonicznego HR **źródłem prawdy po naliczeniu** jest ledger.

---

## 4. KSeF i zakupy (Procurement) → PzEngine

### 4.1 Endpoint

`api/procurement/inbox.php` — akcja **`accept`** (role: `owner`, `manager`).

### 4.2 Przepływ `accept` → `PzEngine`

1. Wczytanie `sh_ksef_invoices` + linii `sh_ksef_invoice_lines` (`qty`, `unit_net`, `vat_rate`, `resolved_sku` — **wszystkie linie muszą mieć SKU**).
2. Zbudowanie tablicy `$pzLines[]`:
   - `quantity` ← `(float) qty`
   - `unit_net_cost` ← `(float) unit_net`
   - `vat_rate` ← `(float) vat_rate`
   - `external_name`, `resolved_sku`
3. Wywołanie **`PzEngine::processReceipt($pdo, $tenant_id, $warehouseId, { supplier_name, supplier_invoice, lines }, (string)$user_id)`**.
4. Aktualizacja nagłówka faktury: `status = 'accepted'`, `linked_wh_document_id = doc_id` PZ, `processed_at`, `processed_by_user_id`.
5. Audyt: `inboxAudit(..., 'ksef_accept', ...)`.

**Wniosek:** linie KSeF są **1:1 mapowane** na payload PZ; **nie ma** w tym kroku osobnej ścieżki „tylko OPEX” — wszystko wchodzi do magazynu jako PZ na rozwiązane **SKU** (`sys_items` / `wh_stock`).

### 4.3 Tabele KSeF (nagłówek i linie)

`sh_ksef_invoices` (m.in. `total_net_minor`, `total_vat_minor`, `total_gross_minor`, `xml_blob`, `status`, `linked_wh_document_id`), `sh_ksef_invoice_lines` (m.in. `external_name`, `qty`, `unit_net`, `line_net_minor`, `vat_rate`, pola matchowania AutoScan).

---

## 5. Analiza zysków (P&L) — propozycja połączenia matematycznego

Poniżej model **szacunkowy / operacyjny** dla **jednego tenanta** i ustalonego okresu \(T\) (np. dzień / miesiąc). Wszystkie kwoty najlepiej prowadzić w **minor units** (grosze) z jednolitym zaokrągleniem na końcu kroku.

### 5.1 Gross Revenue (sprzedaż brutto)

\[
\text{GrossRevenue}(T) = \sum_{\text{orders } o \in T} \text{grand\_total}(o)
\]

Opcjonalnie rozbicie: \(\sum \text{line\_total}\) z `sh_order_lines` spójne z nagłówkiem po kontroli jakości danych.

**Segmentacja:** filtry po `sh_orders.channel`, `order_type`, `gateway_source` / `source` (agregatory vs własny kanał).

### 5.2 VAT należny (uproszczenie z danych POS)

Z linii zamówienia (brutto w groszach):

\[
\text{VAT\_line} = \text{vat\_amount} \quad (\text{już zapisane w } sh\_order\_lines)
\]

\[
\text{OutputVAT}(T) = \sum_{\text{lines } \ell \in T} \text{vat\_amount}(\ell)
\]

**Net revenue (przychód netto ze sprzedaży):**

\[
\text{NetSales}(T) = \text{GrossRevenue}(T) - \text{OutputVAT}(T)
\]

(Uproszczenie: zakładamy, że `line_total` zawiera VAT stawką `vat_rate`; w razie rozjazdów z fiskalnym raportem należy dostroić regułę do prawa podatkowego i ewentualnie osobnej ewidencji fiskalnej.)

### 5.3 COGS (Food cost na podstawie AVCO)

**Metoda zgodna z silnikiem magazynu (moment wydania):**

Dla każdego dokumentu **WZ** powiązanego z zamówieniem zrealizowanym w \(T\) (lub dla zamówień ze statusem „zużyto” w \(T\)):

\[
\text{COGS}(T) = \sum_{\text{WZ lines } w} \text{line\_net\_value}(w)
\]

ponieważ `WzEngine` ustawia `line_net_value = deductQty * current_avco` oraz `unit_net_cost = current_avco`.

Alternatywa analityczna (bez pełnej historii WZ): odtworzenie z `wh_stock_logs` z `document_type='WZ'` i znakiem ujemnym `change_qty`, z JOIN do `wh_document_lines` po `document_id`.

### 5.4 Labor (koszty pracy)

\[
\text{LaborCost}(T) = \sum_{\substack{\ell \in \text{sh\_payroll\_ledger} \\ \ell.entry\_type = \texttt{work\_earnings} \\ \ell.created\_at \in T}} \ell.\text{amount\_minor}
\]

(lub agregacja po `period_year`/`period_month` oraz regułach zamknięcia okresu).

### 5.5 OPEX z KSeF / zakupów

Faktury zaakceptowane do PZ zwiększają magazyn i AVCO; **nie cały zakup jest OPEX**. Propozycja warstwy BI:

- **OPEX bieżący (okres \(T\)):** suma **netto** nagłówków `sh_ksef_invoices` w \(T\) dla faktur sklasyfikowanych jako **niezwiązane z magazynem żywnościowym** (np. po `sys_items.category`, GTU, PKWiU, lub ręcznym tagu — **do zaprojektowania**), minus ewentualne zwroty (KOR).
- **COGS pośredni:** zakupy surowców, które trafiły do PZ i zostały skonsumowane jako WZ — już w sekcji COGS; **nie podwajać** w OPEX.

Praktyczny wariant startowy: **OPEX\_approx** = suma `total_net_minor` zaakceptowanych faktur minus suma netto linii zmapowanych na SKU sklasyfikowane jako „food inventory”.

### 5.6 Zysk netto operacyjny (uproszczony realtime)

W modelu jednej waluty (PLN) i bez pełnego CIT/ZUS w czasie rzeczywistym:

\[
\text{OperatingProfit}_{\text{approx}}(T) = \text{NetSales}(T) - \text{COGS}(T) - \text{LaborCost}(T) - \text{OPEX}(T) - \text{Commissions}(T)
\]

gdzie **Commissions** — jeśli brak w DB, import z paneli agregatorów lub szacunek `%` od `GrossRevenue` per `gateway_source`.

**Uwagi kontrolne:**

- **Płatności vs przychód:** `sh_order_payments` vs `payment_status` — rozliczenie gotówki u kierowcy może nastąpić po okresie; BI „cash vs accrual” wymaga jawnej definicji.
- **POS vs gateway:** spójność `discount_amount` / `delivery_fee` między ścieżkami — w raportach należy ujednolicić źródło prawdy (preferencyjnie `CartEngine` + nagłówek zamówienia).
- **KSeF → tylko PZ:** paliwo/prąd wchodzą jako PZ na SKU; **klasyfikacja kont** musi być warstwą referencyjną BI, nie jest wbudowana w `accept`.

---

## 6. Indeks plików źródłowych (referencja szybka)

| Obszar | Pliki |
|--------|--------|
| POS / zamówienia | `api/pos/engine.php`, `api/cart/CartEngine.php`, `api/gateway/intake.php`, `core/OrderStateMachine.php` |
| Magazyn / AVCO / zużycie | `core/PzEngine.php`, `core/WzEngine.php`, `core/WarehouseConsumeHook.php`, `core/FoodCostEngine.php` |
| HR / payroll | `core/HrClockEngine.php`, `api/backoffice/hr/engine.php`, `scripts/worker_payroll_accrual.php`, `core/PayrollLedger.php` |
| KSeF / PZ | `api/procurement/inbox.php`, `core/AutoScanEngine.php` |

---

*Koniec raportu.*
