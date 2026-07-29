# ChoiceQR POS Integration — dokumentacja wdrożenia

## Status: P0 WDRUŻONE (2026-07-29) — webhook + menu + areas przetestowane na żywo

### Status faz

| Faza | Status | Pliki |
|------|--------|-------|
| P0 — MVP (webhook + menu + areas) | **WDRUŻONE** | 3 nowe |
| P1 — Push statusów (adapter) | Oczekuje | 2 pliki |
| P2 — Webhook events + QR pay | Oczekuje | 2 pliki |

### Pliki wdrożone (P0)

| Plik | Metoda | Funkcja |
|------|--------|--------|
| `api/integrations/choiceqr/webhook.php` | POST | Odbiór zamówień z ChoiceQR → sh_orders + sh_order_lines |
| `api/integrations/choiceqr/menu.php` | GET | Export menu (kategorie + dania + modyfikatory) w formacie ChoiceQR |
| `api/integrations/choiceqr/areas.php` | GET | Export stref/stolików + wirtualne takeaway/delivery |

### Testy na żywo (XAMPP, 2026-07-29)

| Test | Wynik |
|------|-------|
| Webhook POST — order intake | **200 OK** empty body |
| Webhook POST — idempotency (2nd call, same `_id`) | **200 OK**, 1 order in DB (no dup) |
| Menu GET — export | **200**, 8 kategorii, posID=ascii_key, ceny w groszach, dishOptions |
| Areas GET — export | **200**, 3 zones + 10 tables + takeaway + delivery |
| Auth — brak tokenu | **401** |
| Auth — zły token | **401** |
| DB — order z gateway_source='choiceqr' | ✅ gateway_external_id=ObjectID |
| DB — 2 line items z modifiers_json | ✅ |
| DB — sh_external_order_refs (idempotency) | ✅ 1 wpis, 0 duplikatów |
| Lint PHP | 3/3 PASS |

## Dokumentacja API ChoiceQR

- **Główna:** https://open-api.choiceqr.com/docs#/content/pos/index
- **POS integration:** https://open-api.choiceqr.com/docs/content/pos/index.md
- **Order schema:** https://open-api.choiceqr.com/docs/content/order/schema.md
- **Order methods:** https://open-api.choiceqr.com/docs/content/order/methods.md
- **Order statuses:** https://open-api.choiceqr.com/docs/content/order/statuses.md
- **Webhooks:** https://open-api.choiceqr.com/docs/content/webhooks.md
- **Authorization:** https://open-api.choiceqr.com/docs/content/authorization.md
- **Application:** https://open-api.choiceqr.com/docs/content/application.md
- **API guidelines:** https://open-api.choiceqr.com/docs/content/api.md

## Model integracji

ChoiceQR ma **odwrócony model** — to oni pushują zamówienia do nas, nie my do nich.

### Rejestracja

1. Email do api@choiceqr.com po zaproszenie
2. Rejestracja na https://open-api.choiceqr.com/auth/client
3. Tworzenie aplikacji typu **"POS terminal"** (nie można zmienić po utworzeniu)
4. OAuth flow → Bearer JWT token (ważny 5 lat)
5. Base API URL: `https://open-api.choiceqr.com`

### Konfiguracja w panelu ChoiceQR

Podajemy URL-e naszego POS (SliceHub):

| URL | Metoda | Wymagany | Co robi |
|-----|--------|----------|---------|
| Create order URL | POST | **TAK** | ChoiceQR wysyła tu zamówienie po akceptacji |
| Get menu URL | GET | **TAK** | ChoiceQR pobiera menu z SliceHub |
| Get areas URL | GET | **TAK** | ChoiceQR pobiera strefy/stoliki |
| Get table orders URL | GET | nie | Dla QR płatności przy stoliku |
| Pay table order URL | POST | nie | Dla QR płatności przy stoliku |

### Auth

- **API REST (my → ChoiceQR):** Bearer JWT token w nagłówku `Authorization: Bearer {{token}}`
- **Webhook (ChoiceQR → my):** ChoiceQR nie opisuje HMAC/signature. Zabezpieczenie: token w query path URL (np. `?t=SECRET_TOKEN`)
- **Rate limit:** 60 req/sec po stronie ChoiceQR API
- **Idempotency:** ChoiceQR wspiera `x-idempotence-key` header

## Flow zamówienia

```
Klient składa zamówienie na ChoiceQR (QR/stona/aplikacja)
  → status: waiting_for_approve
  → akceptacja przez terminal/telegram bota przez restaurację
  → status: approved
  → ChoiceQR HTTP POST { order: OrderSchema, varSymbol: "ID" } na nasz Create order URL
  → SliceHub tworzy zamówienie w sh_orders + sh_order_lines
  → SliceHub zwraca 200 OK empty body
  → zamówienie pojawia się w POS/KDS/driver app
```

**Retry policy ChoiceQR:** 3 próby, 3 sec odstęp, 15 sec timeout. Brak 200 = zamówienie anulowane po 3 próbie.

## Mapowanie pól ChoiceQR → SliceHub

### Order header

| ChoiceQR | SliceHub (sh_orders) | Uwagi |
|----------|---------------------|-------|
| `order._id` | `gateway_external_id` | MongoDB ObjectID, idempotency key |
| `order.num` | komentarz/reference | numer zamówienia ChoiceQR |
| `order.type` | `order_type` | delivery→delivery, takeaway→takeaway, table→dine_in |
| `order.subTotal` | `subtotal` | już w groszach (1/100) |
| `order.total` | `grand_total` | 1/100 |
| `order.discount` | `discount_amount` | |
| `order.tips` | `tip_amount` | |
| `order.costOfPack` | packaging cost | do dodania jako line item lub fee |
| `order.delivery.cost` | `delivery_fee` | |
| `order.payBy` | `payment_method` | cash/card/online |
| `order.currency` | currency | PLN |
| `order.status` | `status` | approved → new |
| `varSymbol` | tenant mapping | identyfikator firmy w ChoiceQR |

### Customer (delivery/takeaway)

| ChoiceQR | SliceHub | Uwagi |
|----------|----------|-------|
| `order.delivery.customer.name` | `customer_name` | |
| `order.delivery.customer.phone` | `customer_phone` | format +48XXXXXXXXX |
| `order.delivery.customer.address` | `delivery_address` | złożyć: streetName + streetNumber + city |
| `order.delivery.customer.address.location.coordinates` | `lat`, `lng` | [lng, lat] → lat/lng |
| `order.delivery.when` | `promised_time` | local ISODate |
| `order.delivery.comment` | comment | |

### Order lines (items)

| ChoiceQR | SliceHub (sh_order_lines) | Uwagi |
|----------|--------------------------|-------|
| `items[].posID` | `item_sku` | **KLUCZOWE** — musi = ascii_key w SliceHub |
| `items[].name.en.name` | `snapshot_name` | |
| `items[].price` | `unit_price` | 1/100 = grosze |
| `items[].count` | `quantity` | |
| `items[].total` | `line_total` | |
| `items[].vat` | `vat_rate` | |
| `items[].menuOptions` | `modifiers_json` | mapowanie modifierów |
| `items[].comment` | `comment` | |

### Modifiers (menuOptions)

| ChoiceQR | SliceHub | Uwagi |
|----------|----------|-------|
| `menuOptions[].posID` | modifier group ID | |
| `menuOptions[].item` | modifier item ID | |
| `menuOptions[].optionName.en.name` | modifier group name | |
| `menuOptions[].itemName.en.name` | modifier item name | |
| `menuOptions[].count` | qty | |
| `menuOptions[].price` | unit price | 1/100 |
| `menuOptions[].total` | line total | |

## Order statuses

### ChoiceQR order flow

```
Payment: cash/offline-card:
  new → waiting_for_approve → approved → pre_closed → closed
  new → waiting_for_approve → cancelled (fail)

Payment: online:
  new → prepare_payment → paying → processing_payment → waiting_for_approve → approved → pre_closed → closed
  new → ... → waiting_for_approve → reverting → reverted → cancelled (fail)
```

### Delivery statuses (ChoiceQR)
idle → created → processing → waitingForPickUp → arrivedForPickUp → progress → done / cancelled / error

### Mapowanie statusów SliceHub → ChoiceQR (push)

| SliceHub status | ChoiceQR API call | ChoiceQR status |
|----------------|-------------------|-----------------|
| new/accepted | (nic — ChoiceQR już wie) | approved |
| preparing | (nic) | approved |
| ready | PUT /orders/:_id/close | pre_closed/closed |
| delivered | PUT /orders/:_id/close | closed |
| cancelled | PUT /orders/:_id/cancel { reason } | cancelled |
| in_delivery | PUT /orders/:_id/delivery { deliveryStatus } | progress |

## Menu export schema (nasz Get menu URL)

ChoiceQR wywołuje `GET` na nasz Get menu URL. Oczekuje array kategorii:

```json
[
  {
    "posID": "category_ascii_key",
    "name": "Category Name",
    "active": true,
    "items": [
      {
        "posID": "item_ascii_key",
        "name": "Dish Name",
        "price": 2500,
        "description": "Opis dania",
        "active": true,
        "vat": 23,
        "allergens": [1, 2],
        "media": { "url": "https://..." },
        "menuLabels": [{ "type": "new" }],
        "dishOptions": [
          {
            "posID": "modifier_group_id",
            "name": "Dodatki",
            "type": "multiple",
            "active": true,
            "required": false,
            "list": [
              {
                "posID": "modifier_item_id",
                "name": "Ser",
                "price": 300,
                "active": true
              }
            ]
          }
        ]
      }
    ]
  }
]
```

**Ceny w 1/100** (1 PLN = 100). `posID` = nasze `ascii_key` z `sh_menu_items`.

## Areas export schema (nasz Get areas URL)

```json
[
  { "posID": "area_1", "name": "Bar" },
  { "posID": "table_1", "name": "Stolik #1" },
  { "posID": "takeaway", "name": "Odbiór własny" },
  { "posID": "delivery", "name": "Dostawa" }
]
```

## Webhook events (ChoiceQR → nasz Webhook URL)

Format:
```json
{
  "id": "unique_id",
  "type": "event_type",
  "langCode": "en",
  "data": "event data schema",
  "varSymbol": "ID company",
  "timestramp": "Unix timestamp"
}
```

Event types:
- **Order:** order.created, order.accepted, order.cancelled, order.closed, order.delivery.update
- **QR Payment:** order.qrPayment.completed, order.qrPayment.error
- **Menu:** section/category/dish created/changed/removed/positionChanged
- **Marketplace:** marketplace.acceptance.enabled/disabled
- **Import:** import.full.done

## Pliki wdrożone (P0 — opis implementacji)

### 1. `api/integrations/choiceqr/webhook.php` — odbiór zamówień (POST)

**Endpoint:** `POST /api/integrations/choiceqr/webhook.php?t=SECRET_TOKEN`

**Flow:**
1. Auth: `hash_equals()` na tokenie z `?t=` vs `webhook_token` z `sh_tenant_integrations.credentials`
2. Parse payload: `{ order: OrderSchema, varSymbol: "ID" }`
3. Tenant mapping: `varSymbol` → `sh_tenant_integrations` (provider='choiceqr', credentials.var_symbol match)
4. Idempotency: `order._id` → `GatewayAuth::lookupExternalRef()` — duplikat = 200 OK, skip
5. Mapowanie pól ChoiceQR → SliceHub (patrz tabele niżej)
6. Atomic INSERT: `sh_orders` + `sh_order_lines` + `sh_order_audit` (transakcja)
7. Event publish: `OrderEventPublisher::publishOrderLifecycle('order.created')` w tej samej transakcji
8. External ref: `GatewayAuth::storeExternalRef()` — idempotency dla retry ChoiceQR
9. Response: **200 OK empty body** (wymóg ChoiceQR — brak 200 = anulowane po 3 próbie)

**Kluczowe:**
- Brak CartEngine — ufamy totalom ChoiceQR (oni są source of truth dla cen klienta)
- Order number: `CQR/YYYYMMDD/NNNN` (osobna sekwencja w `sh_order_sequences`)
- Błędy też zwracają empty body + `error_log()` (ChoiceQR ignoruje body odpowiedzi)
- Feature-detect kolumn `gateway_source`/`gateway_external_id` (migracja 027)

### 2. `api/integrations/choiceqr/menu.php` — export menu (GET)

**Endpoint:** `GET /api/integrations/choiceqr/menu.php?t=SECRET_TOKEN&varSymbol=ID`

**Flow:**
1. Auth + tenant mapping (jak webhook)
2. Czyta `sh_categories` (is_menu=1, is_deleted=0) → kategorie
3. Czyta `sh_menu_items` (is_active=1, is_deleted=0) → dania
4. Ceny z `sh_price_tiers` (target_type='ITEM', channel POS preferred, fallback Takeaway/Delivery)
5. Modyfikatory: `sh_item_modifiers` → `sh_modifier_groups` → `sh_modifiers` → `sh_price_tiers` (target_type='MODIFIER')
6. Output: array kategorii w formacie ChoiceQR (patrz schema niżej)

**Kluczowe:**
- `posID` = `ascii_key` z `sh_menu_items` (KLUCZOWE — musi się zgadzać z order.items[].posID)
- Ceny w groszach (1 PLN = 100, np. 25.00 PLN = 2500)
- `dishOptions` = grupy modyfikatorów z `type: single|multiple` (na podstawie max_selection)
- `allergens` z `allergens_json`, `media` z `image_url`, `menuLabels` z `badge_type`
- Pomija dania z `publication_status='draft'`

### 3. `api/integrations/choiceqr/areas.php` — export stref (GET)

**Endpoint:** `GET /api/integrations/choiceqr/areas.php?t=SECRET_TOKEN&varSymbol=ID`

**Flow:**
1. Auth + tenant mapping (jak webhook)
2. Czyta `sh_zones` (is_active=1) → strefy (sala/ogródek/bar/VIP)
3. Czyta `sh_tables` (is_active=1) → stoliki
4. Dodaje wirtualne strefy: `takeaway` (Odbiór własny), `delivery` (Dostawa)
5. Output: array `{ posID, name }` w formacie ChoiceQR

**Kluczowe:**
- Feature-detect `sh_zones`/`sh_tables` (migracja 037) — graceful fallback gdy tabele nie istnieją
- `posID` dla zones: `zone_{id}`, dla tables: `table_{id}`

### P1 (push statusów — oczekuje)

4. **`core/Integrations/ChoiceQRAdapter.php`** — adapter push statusów
   - extends BaseAdapter
   - `providerKey()` = 'choiceqr'
   - `displayName()` = 'ChoiceQR'
   - `buildRequest()` — mapuje event → ChoiceQR API call
   - `supportsInbound()` = true
   - `parseInboundCallback()` — parsuje webhook events
   - Credentials: `{ "token": "JWT", "webhook_token": "SECRET" }`

5. **`core/Integrations/AdapterRegistry.php`** — dodać `'choiceqr' => ChoiceQRAdapter::class`

### P2 (webhook events + QR płatności)

6. **`api/integrations/choiceqr/events.php`** — handler webhook events
   - POST handler: parsuje eventy (menu changes, order status updates)
   - Aktualizuje lokalne dane gdy menu zmienione w ChoiceQR

7. **Table orders + Pay** — dla QR płatności przy stoliku (opcjonalne)

## Konfiguracja bazy danych

### sh_tenant_integrations (wymagany wpis per tenant)

```sql
INSERT INTO sh_tenant_integrations
  (tenant_id, provider, display_name, api_base_url, credentials, direction, events_bridged, is_active)
VALUES
  (1, 'choiceqr', 'ChoiceQR', 'https://open-api.choiceqr.com',
   '{"token":"JWT_TOKEN","webhook_token":"SECRET_TOKEN","var_symbol":"10102"}',
   'bidirectional',
   '["order.created","order.accepted","order.cancelled","order.closed","order.delivery.update"]',
   1);
```

**Pola w credentials JSON:**
- `token` — JWT Bearer token z ChoiceQR (do API REST, my → ChoiceQR, P1)
- `webhook_token` — nasz secret token do auth webhooków (ChoiceQR → my, P0)
- `var_symbol` — identyfikator firmy w ChoiceQR (tenant mapping)

### sh_external_order_refs (istnieje)
- source = 'choiceqr'
- external_id = order._id (MongoDB ObjectID)

### Dane testowe (XAMPP)

W bazie `slicehub_pro_v2` wstawiony testowy wpis:
- tenant_id = 1
- webhook_token = `test_secret_token`
- var_symbol = `10102`

**Do produkcji:** zmień `webhook_token` i `token` na realne wartości.

## Infrastruktura SliceHub (już gotowa)

- `api/gateway/intake.php` — wzorzec dla order intake (auth, idempotency, cart recalc)
- `core/GatewayAuth.php` — key management, `storeExternalRef()`, `lookupExternalRef()`
- `core/Integrations/BaseAdapter.php` — adapter framework z `parseInboundCallback()`
- `core/Integrations/AdapterRegistry.php` — provider map
- `core/OrderEventPublisher.php` — transactional outbox
- `api/cart/CartEngine.php` — server-authoritative cart recalc (opcjonalnie użyte)

## Kluczowe decyzje

1. **CartEngine:** Skip dla choiceqr — ufamy totalom ChoiceQR (oni są source of truth dla cen klienta). CartEngine przeliczałby inaczej i odrzucałby zamówienia przy niezgodności SKU.

2. **SKU mapping:** `posID` w ChoiceQR = `ascii_key` w SliceHub. Menu export (Get menu URL) załatwia to — my wysyłamy nasze `ascii_key` jako `posID`.

3. **Auth webhooka:** Token w URL path (ChoiceQR nie wspiera HMAC dla POS webhook).

4. **Tenant mapping:** `varSymbol` z ChoiceQR → `tenant_id` z `sh_tenant_integrations.provider='choiceqr'`.

5. **Fiskalizacja:** Zamówienia z ChoiceQR trafiają do SliceHub jako `status=new`, `payment_status` zależne od `order.payBy`. Fiskalizacja przez istniejący Elzab adapter lub POS settle_and_close.

## Jak przetestować (przewodnik krok po kroku)

### Wymagania
- XAMPP uruchomiony (Apache na porcie 80, MySQL na porcie 3306)
- Baza `slicehub_pro_v2` z danymi demo (tenant_id=1)
- Testowy wpis w `sh_tenant_integrations` (patrz sekcja wyżej)

### Krok 1: Sprawdź konfigurację integracji

Otwórz w przeglądarce lub wykonaj w terminalu:
```bash
php -r "require 'c:/xampp/htdocs/slicehub/core/db_config.php'; $s=$pdo->query(\"SELECT tenant_id,provider,is_active,credentials FROM sh_tenant_integrations WHERE provider='choiceqr'\"); print_r($s->fetchAll(PDO::FETCH_ASSOC));"
```

Powinieneś zobaczyć wpis z `tenant_id=1`, `is_active=1`, `credentials` zawierającym `webhook_token` i `var_symbol`.

### Krok 2: Test Areas (GET) — najprostszy test

W przeglądarce:
```
http://localhost/slicehub/api/integrations/choiceqr/areas.php?t=test_secret_token&varSymbol=10102
```

Lub w terminalu (PowerShell — użyj `curl.exe`):
```bash
curl.exe -s "http://localhost/slicehub/api/integrations/choiceqr/areas.php?t=test_secret_token&varSymbol=10102"
```

**Oczekiwany wynik:** JSON array ze strefami, stolikami + `takeaway` + `delivery`.

### Krok 3: Test Menu (GET)

W przeglądarce:
```
http://localhost/slicehub/api/integrations/choiceqr/menu.php?t=test_secret_token&varSymbol=10102
```

**Oczekiwany wynik:** JSON array kategorii z daniami. Każde danie ma `posID` = `ascii_key`, `price` w groszach (np. 2200 = 22.00 PLN).

### Krok 4: Test Webhook (POST) — symulacja zamówienia

Utwórz plik `test_order.json` z payloadem zamówienia:
```json
{
  "varSymbol": "10102",
  "order": {
    "_id": "507f1f77bcf86cd799439011",
    "num": "CQR-00123",
    "type": "delivery",
    "subTotal": 4000,
    "total": 5000,
    "discount": 0,
    "tips": 200,
    "costOfPack": 0,
    "payBy": "cash",
    "currency": "PLN",
    "delivery": {
      "cost": 1000,
      "when": "2026-07-29T18:00:00+02:00",
      "comment": "Prosze dzwonic przed dostawa",
      "customer": {
        "name": "Jan Kowalski",
        "phone": "+48123456789",
        "address": {
          "streetName": "ul. Kwiatowa",
          "streetNumber": "15",
          "city": "Warszawa",
          "location": { "coordinates": [21.0122, 52.2297] }
        }
      }
    },
    "items": [
      {
        "posID": "BURGER_CLASSIC",
        "name": { "en": { "name": "Classic Burger" } },
        "price": 2200,
        "count": 1,
        "total": 2200,
        "vat": 8,
        "menuOptions": [
          {
            "posID": "SAUCE_GARLIC",
            "item": "SAUCE_GARLIC",
            "optionName": { "en": { "name": "Sosy" } },
            "itemName": { "en": { "name": "Czosnkowy" } },
            "count": 1,
            "price": 200,
            "total": 200
          }
        ]
      },
      {
        "posID": "SIDE_FRIES",
        "name": { "en": { "name": "Frytki" } },
        "price": 900,
        "count": 2,
        "total": 1800,
        "vat": 8
      }
    ]
  }
}
```

Wyślij POST (w PowerShell użyj `curl.exe`):
```bash
curl.exe -v -X POST "http://localhost/slicehub/api/integrations/choiceqr/webhook.php?t=test_secret_token" -H "Content-Type: application/json" --data-binary "@test_order.json"
```

**Oczekiwany wynik:** HTTP 200, puste body (Content-Length: 0).

### Krok 5: Weryfikacja w bazie

Sprawdź czy zamówienie zostało zapisane:
```sql
SELECT id, order_number, channel, order_type, gateway_source, gateway_external_id,
       subtotal, grand_total, tip_amount, customer_name, customer_phone, delivery_address,
       status, payment_method, promised_time
FROM sh_orders WHERE gateway_source = 'choiceqr' ORDER BY created_at DESC LIMIT 5;
```

Sprawdź pozycje zamówienia:
```sql
SELECT ol.item_sku, ol.snapshot_name, ol.unit_price, ol.quantity, ol.line_total, ol.vat_rate, ol.modifiers_json
FROM sh_order_lines ol
JOIN sh_orders o ON o.id = ol.order_id
WHERE o.gateway_source = 'choiceqr'
ORDER BY o.created_at DESC LIMIT 10;
```

Sprawdź idempotency (external refs):
```sql
SELECT * FROM sh_external_order_refs WHERE source = 'choiceqr';
```

### Krok 6: Test idempotency — wyślij to samo zamówienie drugi raz

Wykonaj ten sam curl POST z tym samym `order._id`. Powinieneś dostać **200 OK** ale w bazie nadal **1 zamówienie** (nie 2).

### Krok 7: Test auth failure

Brak tokenu:
```bash
curl.exe -s -o NUL -w "%{http_code}" "http://localhost/slicehub/api/integrations/choiceqr/areas.php?varSymbol=10102"
```
**Oczekiwane:** `401`

Zły token:
```bash
curl.exe -s -o NUL -w "%{http_code}" "http://localhost/slicehub/api/integrations/choiceqr/areas.php?t=wrong_token&varSymbol=10102"
```
**Oczekiwane:** `401`

### Krok 8: Sprawdź w POS / KDS

Otwórz POS w przeglądarce:
```
http://localhost/slicehub/modules/pos/index.html
```
Zamówienie z ChoiceQR powinno pojawić się w liście zamówień z numerem `CQR/...` i source `CQR`.

### Krok 9: Sprawdź logi błędów

Jeśli coś nie działa, sprawdź logi Apache:
``
c:/xampp/apache/logs/error.log
```

Wszystkie błędy webhook są logowane z prefixem `[ChoiceQR Webhook]`, menu z `[ChoiceQR Menu]`, areas z `[ChoiceQR Areas]`.

## Konfiguracja produkcji (URL-e do podania w panelu ChoiceQR)

Po wdrożeniu na produkcję, w panelu ChoiceQR podaj następujące URL-e:

| URL | Wartość |
|-----|---------|
| Create order URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/webhook.php?t=SECRET_TOKEN` |
| Get menu URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/menu.php?t=SECRET_TOKEN&varSymbol=VAR_SYMBOL` |
| Get areas URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/areas.php?t=SECRET_TOKEN&varSymbol=VAR_SYMBOL` |

**Uwaga:** `SECRET_TOKEN` i `VAR_SYMBOL` muszą być zgodne z `sh_tenant_integrations.credentials`.

## Szacunek pracy (zaktualizowany)

| Faza | Status | Pliki | Czas |
|------|--------|-------|------|
| P0 — MVP (webhook + menu + areas) | **WDRUŻONE** | 3 nowe | ~8h |
| P1 — Push statusów (adapter) | Oczekuje | 2 pliki | ~3h |
| P2 — Webhook events + QR pay | Oczekuje | 2 pliki | ~3h |
| Testy | **PASS** | curl | ~1h |
| **Razem (P0)** | | | **~9h** |
