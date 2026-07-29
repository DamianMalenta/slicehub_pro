# Audyt: Stan fiskalizacji w SliceHub Enterprise OS

> **READ-ONLY audyt** — dokumentacja ustaleń z kodu, bez propozycji zmian.
>
> **Data:** 2026-07-29 (aktualizacja: 2026-07-29 — sekcja 3b, sekcja 6: implementacja Elzab, konfiguracja w module Settings)
>
> **Zakres:** warstwa VAT, flaga `print_receipt`, fiskalizacja właściwa (kasa/drukarka), integracje POS, pole `legal_fiscal_no`, KSeF.
>
> **Metodologia:** bezpośrednia inspekcja kodu źródłowego (PHP + JS) oraz dokumentów projektowych w `_docs/`.

---

## 1. Warstwa VAT — GOTOWA w kodzie

VAT jest liczony i zapisywany dla każdej linii zamówienia w obu kanałach sprzedaży (POS i Online).

### POS (`api/pos/engine.php`)

- **Lokalizacja:** ok. linii 1036–1037
- Stawka VAT zależy od typu zamówienia: `dine_in` → 8.00%, inaczej 5.00% (domyślnie), z możliwością nadpisania przez `item['vat_rate']`.
- Kwota VAT liczona w groszach: `vat_amount = round(lineTotal * vatRate / (100 + vatRate))`.
- Zapis do `sh_order_lines` w kolumnach `vat_rate` i `vat_amount`.

```php
// api/pos/engine.php:1036-1037
$vatRate = (float)($item['vat_rate'] ?? ($orderType === 'dine_in' ? 8.00 : 5.00));
$vatAmount = (int)round($lineTotal * $vatRate / (100 + $vatRate));
```

### Online (`api/online/engine.php`)

- **Lokalizacja:** ok. linii 1385–1404
- INSERT do `sh_order_lines` z `vat_rate` / `vat_amount` pochodzącymi z `CartEngine::calculate()`.
- Wartości w groszach: `:vrate` (np. `8.00`), `:vamt` (np. `161` groszy).

```php
// api/online/engine.php:1385-1392
$stmtLine = $pdo->prepare(
    "INSERT INTO sh_order_lines
        (id, order_id, item_sku, snapshot_name, unit_price,
         quantity, line_total, vat_rate, vat_amount,
         modifiers_json, removed_ingredients_json, comment)
     VALUES
        (:id, :oid, :sku, :name, :unit, :qty, :total,
         :vrate, :vamt, :mods, :removed, :comment)"
);
```

### CartEngine (`api/cart/CartEngine.php`)

- Serwer-autorytatywny silnik koszyka, współdzielony przez kanały.
- Liczy `subtotal_grosze`, `discount_grosze`, `delivery_fee_grosze`, `grand_total_grosze` oraz `vat_amount_grosze` per linia — wszystko w groszach (INT).
- Obsługuje stawki `vat_rate_dine_in` / `vat_rate_takeaway` z `sh_menu_items`, warianty, pół-porcje (half&half), modyfikatory, promocje auto i kody.
- Bucketowanie VAT (`$vatBuckets`) po stawkach dla agregacji.

### Konwencja kwot

- Wszystkie kwoty w groszach (INT) — patrz `_docs/audits/bi_foundation_audit.md`.
- Ceny w menu są brutto (z VAT wliczonym w cenę).

---

## 2. `print_receipt` = tylko flaga DB, brak fiskalizacji

### Akcja `print_receipt` w POS API

- `api/pos/engine.php`, akcja `print_receipt` (ok. linii 1310): ustawia `receipt_printed=1` w `sh_orders` — zwykły UPDATE SQL, bez wywołania urządzenia zewnętrznego.

```php
// api/pos/engine.php:1310-1313
if ($action === 'print_receipt') {
    $oid    = inputStr($input, 'order_id');
    $sql = "UPDATE sh_orders SET receipt_printed=1, updated_at=NOW()";
```

### SettlementEngine

- `core/SettlementEngine.php`:
  - Opcja `print_receipt` przekazywana w flagach (ok. linia 304–306): przy płatności kartą wymusza `$printReceipt = true`.
  - Efekt: wyłącznie `receipt_printed = 1` w `sh_orders` (ok. linie 508–510 dla `completed` oraz 624–626 dla `paid`).
  - Brak jakiejkolwiek komunikacji z drukarką fiskalną, kasą rejestrującą lub API fiskalnym.

```php
// core/SettlementEngine.php:304-306
if ($method === 'card') {
    $printReceipt = true;
}
```

```php
// core/SettlementEngine.php:508-510
if ($printReceipt) {
    $extraCols['receipt_printed'] = 1;
}
```

### Drukowanie paragonu w POS UI (nie-fiskalne)

- `modules/pos/js/pos_ui.js` (ok. linii 723–750): funkcja `printTemplate()` generuje HTML i wywołuje `window.print()` przez ukryty iframe.
- Tytuł wydruku: **„RACHUNEK / PARAGON NIEFISKALNY"** — wyraźnie oznaczony jako niefiskalny.
- Jest to zwykły wydruk przeglądarkowy (systemowy dialog drukarki), nie komunikacja z kasą fiskalną.

```js
// modules/pos/js/pos_ui.js:723
const title = isKitchen ? 'BON NA KUCHNIĘ' : 'RACHUNEK / PARAGON NIEFISKALNY';
```

---

## 3. Fiskalizacja bezpośrednia — WDRUŻONA (Elzab Zeta Online, 2026-07-29)

### Implementacja — protokół Thermal przez TCP/IP

**Zaimplementowano synchroniczną fiskalizację przez drukarkę Elzab Zeta Online przy użyciu protokołu Thermal (Posnet-compatible) over TCP port 1001.**

#### Nowe pliki (4):

| Plik | Opis |
|------|------|
| `core/Elzab/ThermalProtocol.php` | Protokół Thermal: ramkowanie ESC P/ESC \, XOR checksum, komendy `$h`/`$l`/`$y`/`#r`/`$d`, mapowanie VAT→PTU (A–E), konwersja UTF-8→MAZOVIA, kody błędów |
| `core/Elzab/ElzabPrinter.php` | Połączenie TCP (`stream_socket_client`), handshake (CAN×3 + DLE/ENQ), `printReceipt()`, `printDailyReport()`, `getStatus()`, `ping()` |
| `core/Elzab/ElzabFiscalEngine.php` | Synchroniczna fiskalizacja: pobiera order+linie+płatności z DB, mapuje na Thermal, drukuje, zapisuje `fiscal_receipt_number` do `sh_orders` |
| `core/Integrations/ElzabAdapter.php` | Adapter dla `AdapterRegistry` (widoczność w UI, `supportsEvent('order.completed')`) |

#### Migracja DB:

- `database/migrations/062_fiscal_receipt_number.sql` — `ALTER TABLE sh_orders ADD COLUMN fiscal_receipt_number VARCHAR(20)`
- Zarejestrowana w `scripts/_migrations_chain.php` (pozycja 062)

#### Zmodyfikowane pliki:

| Plik | Zmiana |
|------|--------|
| `core/Integrations/AdapterRegistry.php:26` | `'elzab' => ElzabAdapter::class` w `PROVIDER_MAP` |
| `api/pos/engine.php:1326-1365` | 3 nowe akcje: `fiscal_print`, `fiscal_daily_report`, `fiscal_status` + `fiscal_test` (test połączenia z live form) |
| `modules/pos/js/pos_api.js:83-99` | `fiscalPrint()`, `fiscalDailyReport()`, `fiscalStatus()`, `fiscalTest()` |
| `modules/pos/js/pos_app.js` | Auto-fiskalizacja po `settleAndClose` (best-effort) + `_fiscalDailyReport()` + `_fiscalReprint()` (ponowna fiskalizacja z karty zamówienia) |
| `modules/pos/index.html:81` | Przycisk „RAPORT DOBOWY" w topbarze (przycisk „DRUKARKA" i modal konfiguracji usunięte — przeniesione do Settings) |
| `api/settings/engine.php:1412-1498` | 6 nowych akcji: `fiscal_get_config`, `fiscal_save_config`, `fiscal_test`, `fiscal_test_print`, `fiscal_status`, `fiscal_daily_report` |
| `modules/settings/index.html:35,49` | Nowa zakładka „Drukarka Fiskalna" (tab + pane) |
| `modules/settings/js/settings_app.js:1480-1640` | `renderFiscalPane()` — formularz konfiguracji (IP, port, cashbox, stopka), Test połączenia, Zapisz, Drukuj paragon testowy |
| `modules/settings/js/settings_app.js:37` | `fiscal_get_config`, `fiscal_status` dodane do `CSRF_READONLY` |
| `api/settings/engine.php:99` | `fiscal_get_config`, `fiscal_status` dodane do backend CSRF whitelist |
| `api/settings/engine.php:1141` | `fiscal_*` dodane do fall-through w `default` case switch'a |

#### Flow działania:

```
Kelner klika „Zapłać" → settle_and_close
  → POS auto-wywołuje fiscal_print(order_id)
  → ElzabFiscalEngine: pobiera order z DB
  → ElzabPrinter: connect TCP → $h → $l×N → $y → szuflada
  → Drukarka drukuje paragon fiskalny
  → Numer paragonu → sh_orders.fiscal_receipt_number
  → POS toast: „Paragon fiskalny nr 000123 ✓"
```

#### Konfiguracja drukarki:

**Od 2026-07-29 konfiguracja znajduje się w module Settings** (`modules/settings/index.html` → zakładka „Drukarka Fiskalna").

UI pozwala na:
- Wpisanie adresu IP i portu TCP drukarki
- Ustawienie identyfikatora kasy (cashbox)
- Definicję 3 linii stopki paragonu
- Test połączenia (TCP ping z live form)
- Zapis konfiguracji do `sh_tenant_settings`
- Drukowanie paragonu testowego (1× „Test SliceHub" 1.00 zł, płatność gotówką)

W `sh_tenant_settings` (zapisywane przez `ElzabFiscalEngine::saveConfig()`):
- `ELZAB_PRINTER_HOST = '192.168.8.144'`
- `ELZAB_PRINTER_PORT = '1001'`
- `ELZAB_CASHBOX = 'POS1'`
- `ELZAB_FOOTER_LINE_1` / `ELZAB_FOOTER_LINE_2` / `ELZAB_FOOTER_LINE_3`

Albo w `sh_tenant_integrations` (legacy, czytane przez `resolvePrinterConfig()`):
- `provider = 'elzab'`
- `api_base_url = 'tcp://192.168.1.50:1001'`
- `credentials = {"cashbox": "POS1"}`
- `is_active = 1`

**POS** zachowuje przycisk „RAPORT DOBOWY" w topbarze oraz przycisk ponownej fiskalizacji na kartach zamówień. Konfiguracja drukarki (przycisk „DRUKARKA" i modal) została usunięta z POS — zarządzanie odbywa się wyłącznie w module Settings.

#### Kluczowe decyzje architektoniczne:

1. **Synchroniczna, nie asynchroniczna** — POS musi natychmiast wiedzieć czy paragon się wydrukował i otrzymać numer. IntegrationDispatcher (cURL + outbox) nie pasuje.
2. **TCP, nie HTTP** — drukarka używa surowego TCP (protokół Thermal), nie ma endpointu HTTP.
3. **Best-effort w POS** — jeśli drukarka nie odpowiada, POS nie blokuje rozliczenia; fiskalizację można powtórzyć ręcznie.
4. **ElzabAdapter w AdapterRegistry** — dla widoczności w UI integracji, ale faktyczna komunikacja odbywa się przez ElzabFiscalEngine, nie przez IntegrationDispatcher.

#### Dokument referencyjny (historyczny)

- `_docs/16_RESILIENT_POS.md` — spec/historyczny plan architektury „Local-first, Cloud-synced". Plan ESC/POS bridge w P6+ został zrealizowany w innej formie (TCP Thermal zamiast ESC/POS).

---

## 3b. Integracje POS — gotowa infrastruktura pośredniej fiskalizacji

W repozytorium istnieje **kompletny framework integracji z zewnętrznymi systemami POS**, który może służyć jako ścieżka fiskalizacji — zewnętrzny POS (Papu, Dotykačka, GastroSoft) fiskalizuje zamówienia samodzielnie po ich odebraniu od SliceHub.

### Transactional Outbox — `core/OrderEventPublisher.php`

- Publikuje eventy lifecycle zamówienia (`order.created`, `order.completed`, `payment.settled` itd.) w tej samej transakcji co zapis do `sh_orders`.
- Tabela `sh_event_outbox` — append-only log z idempotency keys (`tenant_id` + `idempotency_key` UNIQUE).
- Silent degradation — brak tabeli → `error_log` + skip, nigdy nie łamie transakcji głównej.
- Pełny snapshot orderu + lines w payloadzie eventu — worker nie dociąga danych z DB.

### Integration Dispatcher — `core/Integrations/IntegrationDispatcher.php` (561 linii)

- Konsumuje eventy z `sh_event_outbox`, dostarcza do adapterów z retry + exponential backoff (30s → 86400s, 7 poziomów).
- Dead-letter queue: eventy po `max_retries` (default 6) oznaczane jako `dead`.
- Pełny audit trail: `sh_integration_deliveries` (status per event+adapter) + `sh_integration_attempts` (każda próba z HTTP code, body, duration).
- Cron worker: `scripts/worker_integrations.php`.
- HTTP transport przez cURL (`curlTransport`), z możliwością injectowania mocka dla testów.

### Adapter Registry — `core/Integrations/AdapterRegistry.php`

- Mapuje `provider` key z `sh_tenant_integrations` na klasę adaptera.
- Per-tenant, multi-provider — jeden tenant może mieć kilka aktywnych integracji jednocześnie.
- Cache instancji per `tenant_id` (worker nie reinstantiatuje w pętli).
- `availableProviders()` — lista dla UI dropdown.
- `registerProvider()` — runtime rejestracja dla testów/custom providerów.

### Gotowe adaptery (3)

| Adapter | Provider key | System | Mechanizm | Fiskalizacja |
|---------|-------------|--------|-----------|-------------|
| `PapuAdapter` | `papu` | Papu.io POS | REST API, HMAC signing, inbound callbacks | Zewnętrzna — Papu fiskalizuje |
| `DotykackaAdapter` | `dotykacka` | Dotykačka POS Cloud | REST API, OAuth2 refresh token, document/sale | Zewnętrzna — Dotykačka fiskalizuje |
| `GastroSoftAdapter` | `gastrosoft` | GastroSoft POS | REST API, API-key, restaurant_code | Zewnętrzna — GastroSoft fiskalizuje |

Każdy adapter:
- Dziedziczy z `BaseAdapter` (`core/Integrations/BaseAdapter.php`).
- Mapuje event → HTTP request (POST/PATCH/DELETE) z pełnym payloadem zamówienia.
- Przekazuje linie z `vat_rate`, `unit_price_grosze`, `line_total_grosze`, `modifiers_json`.
- Zwraca `externalRef` (ID po stronie 3rd-party) dla cross-system idempotency.
- `PapuAdapter` obsługuje również **inbound callbacks** (status updates od Papu) z weryfikacją HMAC.

### SequenceEngine — `core/SequenceEngine.php`

- Atomowe numerowanie dokumentów przez `sh_doc_sequences` + `LAST_INSERT_ID()` upsert.
- Dostępne typy: `ORD`, `WWW`, `KIO`, `PZ`, `WZ`, `MM`, `KOR`, `INW`, `RW`, `PW`.
- **Brak typu `RPC` / `FIS`** — nie generuje numeru paragonu fiskalnego.
- Format: `{DOC_TYPE}/{YYYYMMDD}/{NNNN}`.

### POS Terminal Registration — `api/pos/sync.php`

- Endpoint `register_terminal` z `pos_id` — rejestracja terminala POS.
- Może służyć jako identyfikator kasy dla numeru paragonu (formuła `{tenant}-{pos_id}-{yyyymmdd}-{seq}`).

### Co to oznacza dla fiskalizacji

- **Restauracje używające Papu / Dotykački / GastroSoft jako kasy fiskalnej** — SliceHub już potrafi pushować tam zamówienia z pełnymi danymi VAT. Te systemy fiskalizują samodzielnie.
- **Brakuje tylko:** adaptera dla bezpośredniej komunikacji z drukarką fiskalną (ESC/POS) dla restauracji bez zewnętrznego POS, typu sekwencji `RPC`/`FIS` w `SequenceEngine`, oraz podpięcia `legal_fiscal_no` do logiki.
- **Nowy adapter** (np. `PosnetAdapter`, `NovitusAdapter`) może zostać dodany bez nowej infrastruktury — wystarczy nowa klasa + wpis w `AdapterRegistry::PROVIDER_MAP`.

---

## 4. Pole `legal_fiscal_no` — profil prawny tenanta

### Migracja

- `database/migrations/045_tenant_legal_profile.sql` (linia ~93):
  - `legal_fiscal_no` — „numer fiskalny kasy (np. PL1234567890; opcjonalny)".
  - Pole KV (key-value) w istniejącej tabeli ustawień tenanta — bez ALTER TABLE.

### Obsługa API

- `api/backoffice/profile/engine.php`:
  - `legal_fiscal_no` w liście pól KV profilu prawnego (linia ~241).
  - Odczyt w `profileLoadAllLegalKv()` → zwracane jako `fiscal_no` w sekcji `legal` (linia ~342).
  - Zapis w akcji `legal_profile_save`: walidacja max 64 znaki, zapis do KV (linie ~517–520).
  - Dostęp tylko dla roli `owner`.

### Brak powiązania z logiką fiskalizacji

- Pole jest wyłącznie danymi profilu prawnego (informacyjne).
- Nie jest czytane przez POS, Online, CartEngine ani SettlementEngine.
- Nie generuje numeru paragonu fiskalnego, nie waliduje formatu numeru kasy, nie jest powiązane z żadnym przepływem fiskalizacji.

---

## 5. KSeF — faktury zakupowe, nie fiskalizacja sprzedaży

### Komponenty

- `core/Ksef/Client.php` — klient API KSeF (komunikacja z serwerem Ministerstwa Finansów).
- `core/Ksef/Parser.php` — parser faktur KSeF (XML → struktura PHP).
- `core/Ksef/InboxImport.php` — import przychodzących faktur zakupowych z inbox KSeF.
- `core/Ksef/InboxInvoiceRepository.php` — repozytorium zapisu/odczytu faktur.
- `core/Ksef/InboxQtyNormalize.php` — normalizacja ilości na fakturach.
- `api/procurement/inbox.php` — endpoint API dla inbox KSeF.

### Zakres

- KSeF obsługuje **przychodzące faktury zakupowe** (FA(2)/FA(3)) — pobieranie, parsowanie, mapowanie na dostawy/zobowiązania.
- **Nie** jest to fiskalizacja sprzedaży — KSeF nie generuje paragonów, nie komunikuje się z kasą fiskalną, nie wysyła faktur sprzedażowych.
- Grep na `fiscal`, `paragon`, `fiskal` w `core/Ksef/` i `api/procurement/inbox.php` — brak wyników.

---

## Tabela podsumowująca

| Komponent | Status | Uwagi |
|-----------|--------|-------|
| Warstwa VAT (POS) | **GOTOWE** | `api/pos/engine.php:1036-1037` — stawka + kwota VAT w groszach, zapis do `sh_order_lines` |
| Warstwa VAT (Online) | **GOTOWE** | `api/online/engine.php:1385-1404` — VAT z `CartEngine::calculate()` |
| CartEngine (VAT/subtotal/total) | **GOTOWE** | `api/cart/CartEngine.php` — pełna kalkulacja w groszach, bucketowanie VAT |
| Flaga `print_receipt` (DB) | **GOTOWE** | `api/pos/engine.php:1310`, `core/SettlementEngine.php:304-306,508-510,624-626` — tylko `receipt_printed=1` |
| Druk paragonu niefiskalnego (przeglądarka) | **GOTOWE** | `modules/pos/js/pos_ui.js:723-750` — `window.print()`, tytuł „PARAGON NIEFISKALNY" |
| Pole `legal_fiscal_no` (profil prawny) | **GOTOWE** (informacyjne) | `045_tenant_legal_profile.sql`, `api/backoffice/profile/engine.php` — KV, brak powiązania z logiką |
| KSeF (faktury zakupowe) | **GOTOWE** (inny scope) | `core/Ksef/` + `api/procurement/inbox.php` — przychodzące FA, nie fiskalizacja sprzedaży |
| Transactional Outbox (event publisher) | **GOTOWE** | `core/OrderEventPublisher.php` — eventy lifecycle w tej samej transakcji co zapis orderu |
| Integration Dispatcher (retry/DLQ) | **GOTOWE** | `core/Integrations/IntegrationDispatcher.php` — konsumpcja outbox, retry, backoff, DLQ, audit |
| Adapter Registry (multi-provider) | **GOTOWE** | `core/Integrations/AdapterRegistry.php` — per-tenant, multi-provider, cache |
| Adapter: Papu.io POS | **GOTOWE** | `core/Integrations/PapuAdapter.php` — push zamówień, HMAC, inbound callbacks |
| Adapter: Dotykačka POS Cloud | **GOTOWE** | `core/Integrations/DotykackaAdapter.php` — OAuth2, document/sale |
| Adapter: GastroSoft POS | **GOTOWE** | `core/Integrations/GastroSoftAdapter.php` — API-key, restaurant_code |
| SequenceEngine (numerowanie dok.) | **GOTOWE** (brak typu paragonu) | `core/SequenceEngine.php` — atomowe sekwencje, brak `RPC`/`FIS` |
| POS Terminal Registration | **GOTOWE** | `api/pos/sync.php` — `register_terminal` z `pos_id` |
| Fiskalizacja przez zewnętrzny POS | **POŚREDNIA** | Papu/Dotykačka/GastroSoft fiskalizują po odebraniu zamówienia z SliceHub |
| Numer paragonu fiskalnego | **WDRUŻONE** | `sh_orders.fiscal_receipt_number` (migracja 062), zapisywany przez `ElzabFiscalEngine::fiscalizeOrder()` |
| Sterownik Thermal TCP (bezpośredni) | **WDRUŻONE** | `core/Elzab/ElzabPrinter.php` — TCP `stream_socket_client`, protokół Thermal, port 1001 |
| Adapter drukarki fiskalnej (Elzab) | **WDRUŻONE** | `core/Integrations/ElzabAdapter.php` + `core/Elzab/ElzabFiscalEngine.php` — zarejestrowany w `AdapterRegistry` |
| Renderowanie paragonu fiskalnego | **WDRUŻONE** | `ThermalProtocol::buildStartTransaction` + `buildLine` + `buildEndTransaction` — komendy Thermal |
| Fiskalizacja offline-safe (bezpośrednia) | **CZĘŚCIOWO** | Best-effort w POS — drukarka offline nie blokuje rozliczenia; brak kolejki retry dla fiskalizacji |
| Konfiguracja drukarki w Settings | **WDRUŻONE** | `modules/settings/` → zakładka „Drukarka Fiskalna" — formularz IP/port/cashbox/stopka + Test + Druk testowy |
| Test wydruku paragonu | **WDRUŻONE** | `api/settings/engine.php#fiscal_test_print` — drukuje paragon testowy 1.00 zł przez `ElzabPrinter::printReceipt()` |
| Ponowna fiskalizacja (reprint) | **WDRUŻONE** | `modules/pos/js/pos_app.js#_fiscalReprint` — przycisk na karcie zamówienia, wywołuje `fiscal_print` ponownie |

---

## Wniosek

**Warstwa VAT jest wdrożona i działa** — stawki VAT są liczone per linia w groszach, zapisywane w `sh_order_lines`, obsługiwane przez `CartEngine` dla obu kanałów sprzedaży (POS i Online). Flaga `print_receipt` i wydruk przeglądarkowy („PARAGON NIEFISKALNY") pozwalają na oznaczanie i drukowanie paragonów informacyjnych.

**Infrastruktura integracji POS jest wdrożona i działa** — `OrderEventPublisher` + `IntegrationDispatcher` + 3 adaptery (Papu, Dotykačka, GastroSoft) pushują zamówienia z pełnymi danymi VAT do zewnętrznych systemów POS, które mogą fiskalizować samodzielnie. Jest to pośrednia ścieżka fiskalizacji dla restauracji używających tych systemów.

**Bezpośrednia fiskalizacja została wdrożona** — adapter Elzab Zeta Online (protokół Thermal over TCP) jest zaimplementowany w `core/Elzab/` i zintegrowany z POS engine. Synchroniczna fiskalizacja po `settle_and_close` zapisuje numer paragonu fiskalnego w `sh_orders.fiscal_receipt_number` (migracja 062). Raport dobowy dostępny z poziomu POS topbar. Konfiguracja drukarki (IP, port, cashbox, stopka paragonu) oraz test połączenia i druk paragonu testowego zostały przeniesione do modułu Settings (zakładka „Drukarka Fiskalna"). `SequenceEngine` nie ma jeszcze typu `RPC`/`FIS` (numer pochodzi bezpośrednio z drukarki). Pole `legal_fiscal_no` w profilu prawnym tenanta pozostaje danymi informacyjnymi. KSeF obsługuje faktury zakupowe (przychodzące), a nie fiskalizację sprzedaży.

**Dodanie kolejnego adaptera drukarki fiskalnej** (np. `PosnetAdapter`, `NovitusAdapter`) możliwe bez nowej infrastruktury — nowa klasa `extends BaseAdapter` + wpis w `AdapterRegistry::PROVIDER_MAP`. Istniejący framework zapewnia retry, DLQ, audit trail i per-tenant konfigurację przez `sh_tenant_integrations`.
