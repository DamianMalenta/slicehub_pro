# Audyt: Stan fiskalizacji w SliceHub Enterprise OS

> **READ-ONLY audyt** — dokumentacja ustaleń z kodu, bez propozycji zmian.
>
> **Data:** 2026-07-29 (aktualizacja: 2026-07-29 — sekcja 3b)
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

## 3. Fiskalizacja = tylko PLAN, zamrożony

### Dokument referencyjny

- `_docs/16_RESILIENT_POS.md` — spec/historyczny plan architektury „Local-first, Cloud-synced".

### Kluczowe sekcje

| Sekcja | Linia | Treść |
|--------|-------|-------|
| §3.5 Receipt ledger deterministic | ~110–112 | Numery paragonów generowane lokalnie formułą `{tenant}-{pos_id}-{yyyymmdd}-{seq}`. Drukarka fiskalna odbierze paragon gdy wróci (lokalny agent ESC/POS lub Web Bluetooth). |
| Ograniczenia | ~462 | „Brak fiskalizacji offline — drukarka ESC/POS bridge w **P6+**, Web Bluetooth." |
| Ryzyka | ~701 | „Fiskalizacja drukarki offline — Wysokie — ESC/POS local bridge (agent) lub Web Bluetooth — **scope P6+**." |

### Code freeze

- **🧊 CODE FREEZE od 2026-04-23** (linia ~3).
- Fazy P1–P4 ukończone. Fazy **P5–P8 świadomie odłożone** (linia ~5, roadmapa ~125–128).
- Fiskalizacja jest w scope **P6+** — nie rozpoczęta.

### Brak bezpośredniego sterownika drukarki fiskalnej

- Grep na `escpos`, `renderReceipt`, `fiscal_printer`, `fiscal_no` (w kontekście logiki fiskalizacji) nie zwraca implementacji sterownika.
- Brak klasy/narzędzia komunikującego się bezpośrednio z drukarką fiskalną (ESC/POS, Web Bluetooth, USB).
- Brak generowania numeru paragonu fiskalnego (formuła z §3.5 jest tylko opisem planowanym, nie zaimplementowana).
- **ALE** istnieje pośrednia ścieżka fiskalizacji przez integracje POS — patrz sekcja 3b poniżej.

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
| Numer paragonu fiskalnego | **BRAK** | Plan w `_docs/16_RESILIENT_POS.md` §3.5, nie zaimplementowane |
| Sterownik ESC/POS / Web Bluetooth (bezpośredni) | **BRAK** | Plan w P6+, nie rozpoczęte |
| Adapter drukarki fiskalnej (Posnet/Novitus/Elzab) | **BRAK** | Można dodać przez `BaseAdapter` + `AdapterRegistry` — bez nowej infrastruktury |
| Renderowanie paragonu fiskalnego | **BRAK** | Brak kodu |
| Fiskalizacja offline-safe (bezpośrednia) | **BRAK** | Plan w P6+, zamrożone od 2026-04-23 |

---

## Wniosek

**Warstwa VAT jest wdrożona i działa** — stawki VAT są liczone per linia w groszach, zapisywane w `sh_order_lines`, obsługiwane przez `CartEngine` dla obu kanałów sprzedaży (POS i Online). Flaga `print_receipt` i wydruk przeglądarkowy („PARAGON NIEFISKALNY") pozwalają na oznaczanie i drukowanie paragonów informacyjnych.

**Infrastruktura integracji POS jest wdrożona i działa** — `OrderEventPublisher` + `IntegrationDispatcher` + 3 adaptery (Papu, Dotykačka, GastroSoft) pushują zamówienia z pełnymi danymi VAT do zewnętrznych systemów POS, które mogą fiskalizować samodzielnie. Jest to pośrednia ścieżka fiskalizacji dla restauracji używających tych systemów.

**Bezpośrednia fiskalizacja — sterownik ESC/POS, Web Bluetooth, adapter producenta drukarki (Posnet/Novitus/Elzab), generowanie numeru paragonu fiskalnego — jest niezaimplementowana.** Jest zamrożona w fazie P6+ dokumentu `_docs/16_RESILIENT_POS.md` od code freeze 2026-04-23. `SequenceEngine` nie ma typu `RPC`/`FIS`. Pole `legal_fiscal_no` w profilu prawnym tenanta jest wyłącznie danymi informacyjnymi bez powiązania z logiką fiskalizacji. KSeF obsługuje faktury zakupowe (przychodzące), a nie fiskalizację sprzedaży.

**Dodanie nowego adaptera drukarki fiskalnej** (np. `PosnetAdapter`) możliwe bez nowej infrastruktury — nowa klasa `extends BaseAdapter` + wpis w `AdapterRegistry::PROVIDER_MAP`. Istniejący framework zapewnia retry, DLQ, audit trail i per-tenant konfigurację przez `sh_tenant_integrations`.
