# ChoiceQR POS Integration — dokumentacja wdrożenia

## Status: P0+P1+P2+P2.1+P2.2+P2.3 WDRUŻONE (2026-07-29) — webhook + menu + areas + push adapter + events + QR pay + pay.php payload fix + compliance fix (NC1/NC12 pending staging)

> ⚠ **P2.3 — COMPLIANCE FIX (WDRUŻONE 2026-07-29):** Audyt kodowy + zestawienie z oficjalną dokumentacją
> ChoiceQR (pos/index.md, order/schema.md, webhooks.md, order/methods.md, api.md) wykryło
> 3 krytyczne niezgodności z wymogami ChoiceQR + 2 do weryfikacji live payloadiem.
> P2.3 wdrożyło 3 fixy (K5, NC6, K3). NC1/NC12 — pending live payload ze staging ChoiceQR
> (użytkownik ma dostęp do staging). Szczegóły: sekcja „P2.3 — Compliance fix" na końcu dokumentu.

> ⚠ **P2.1 — TECHNICZNY DŁUG (zamknięty):** Po audycie (2026-07-29) odkryto że `events.php` dubluje
> infrastrukturę `api/integrations/inbound.php` ale nie używa jej mechanizmów (sh_inbound_callbacks,
> OrderStateMachine, parseInboundCallback z adaptera). `inbound.php` z kolei ma przestarzałą hardcoded
> listę przejść statusów (rozjechana z OrderStateMachine) i nie obsługuje delivery_status/payment_status.
> Naprawione w P2.1.

### Status faz

| Faza | Status | Pliki |
|------|--------|-------|
| P0 — MVP (webhook + menu + areas) | **WDRUŻONE** | 3 nowe |
| P1 — Push statusów (adapter) | **WDRUŻONE** | 4 zmienione |
| P2 — Webhook events + QR pay | **WDRUŻONE** | 3 nowe + 1 zmieniony |
| P2.1 — Naprawa inbound infrastructure | **WDRUŻONE** | events.php refactor + inbound.php fix |
| P2.2 — Naprawa payloadu pay.php (tablePayOrderSchema) | **WDRUŻONE** | pay.php (payload + idempotencja + tip + event context) |
| P2.3 — Compliance fix (wymogi ChoiceQR) | **WDRUŻONE** (NC1/NC12 pending staging) | webhook.php + table_orders.php |

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

### P1 (push statusów — WDRUŻONE 2026-07-29)

4. **`core/Integrations/ChoiceQRAdapter.php`** — adapter push statusów
   - extends BaseAdapter
   - `providerKey()` = 'choiceqr'
   - `displayName()` = 'ChoiceQR POS'
   - `buildRequest()` — mapuje event → ChoiceQR API call:
     - `order.cancelled` → `PUT /orders/:_id/cancel { reason }`
     - `order.ready` / `order.delivered` / `order.completed` → `PUT /orders/:_id/close`
     - `order.dispatched` → `PUT /orders/:_id/delivery { deliveryStatus: "waitingForPickUp" }`
     - `order.in_delivery` → `PUT /orders/:_id/delivery { deliveryStatus: "progress" }`
   - `supportsEvent()` — override: filtruje tylko PUSH_EVENTS (pomija inbound order.created/accepted)
   - `parseResponse()` — dziedziczy domyślną z BaseAdapter (standard HTTP status codes)
   - `resolveExternalId()` — pobiera `gateway_external_id` z order snapshot lub `_context`
   - Credentials: `{ "token": "JWT", "webhook_token": "SECRET", "var_symbol": "10102" }`
   - `supportsInbound()` = false (P2: events.php)

5. **`core/Integrations/AdapterRegistry.php`** — dodano `'choiceqr' => ChoiceQRAdapter::class`

6. **`scripts/worker_integrations.php`** — dodano `require_once` dla `ChoiceQRAdapter.php` i `ElzabAdapter.php`

7. **`core/OrderEventPublisher.php`** — `snapshotOrder()` dociąga `gateway_source` + `gateway_external_id` (feature-detect, migracja 027)

**Lint: 4/4 PASS** (ChoiceQRAdapter.php, AdapterRegistry.php, worker_integrations.php, OrderEventPublisher.php)

**Testy P1 (2026-07-29):**

| Test | Wynik |
|------|-------|
| Adapter functional (42 assertions) | **42/42 PASS** |
| Worker dry-run (ChoiceQRAdapter loaded) | **PASS** (1 event processed, 1 dead — expected: no matching push event for test order) |
| E2E Test Runner (62 tests) | **61 pass / 0 fail / 1 warn** (brak regresji) |
| Lint PHP (4 pliki) | **4/4 PASS** |

**Test script:** `php scripts/test_choiceqr_adapter.php`

**Cron:** `php scripts/worker_integrations.php` co 2 min (do ustawienia na produkcji).

**Test dry-run:** `php scripts/worker_integrations.php --dry-run -v`

### P2 (webhook events + QR płatności — WDRUŻONE 2026-07-29)

8. **`api/integrations/choiceqr/events.php`** — handler webhook events (POST)
   - Endpoint: `POST /api/integrations/choiceqr/events.php?t=SECRET_TOKEN`
   - Auth: token w `?t=` (jak webhook.php, `hash_equals` vs `credentials.webhook_token`)
   - Parsuje eventy ChoiceQR: `{ id, type, langCode, data, varSymbol, timestramp }`
   - Event routing:
     - `order.cancelled` → UPDATE `sh_orders.status='cancelled'` + `cancellation_reason`
     - `order.closed` → UPDATE `sh_orders.status='completed'`
     - `order.delivery.update` → UPDATE `sh_orders.delivery_status` (mapowanie ChoiceQR → SliceHub)
     - `order.qrPayment.completed` → UPDATE `sh_orders.payment_status='online_paid'`
     - `order.qrPayment.error` → log + alert (no status change)
     - `section/category/dish created/changed/removed` → log (info only, nie modyfikujemy menu z ChoiceQR)
     - `marketplace.acceptance.enabled/disabled` → log
     - `import.full.done` → log
   - Idempotency: event `id` w `sh_external_order_refs` (source='choiceqr_event')
   - Order matching: `gateway_external_id` = `data._id` (lub `data.order._id` / `data.orderId`)
   - Publishuje wewnętrzne eventy przez `OrderEventPublisher` (transactional outbox)
   - Response: 200 OK empty body (jak webhook.php)
   - Feature-detect: `gateway_source`, `delivery_status`, `cancellation_reason` kolumny

9. **`api/integrations/choiceqr/table_orders.php`** — GET table orders (QR płatności)
   - Endpoint: `GET /api/integrations/choiceqr/table_orders.php?t=SECRET_TOKEN&varSymbol=ID&area=TABLE_ID`
   - Zwraca otwarte zamówienia `dine_in` (nie completed/cancelled) w formacie ChoiceQR
   - Filtr po `table_number` gdy `area=table_N`
   - Feature-detect `table_number` kolumny

10. **`api/integrations/choiceqr/pay.php`** — POST pay table order (QR płatności)
    - Endpoint: `POST /api/integrations/choiceqr/pay.php?t=SECRET_TOKEN`
    - Payload (`tablePayOrderSchema`, /content/pos/index.md): `{ order: { _id, items, total, tips, customer, paymentCustomerDetails }, varSymbol }`
    - QR płatność przy stoliku jest **ZAWSZE online** → `payment_status='online_paid'`, `payment_method='online'`, `tip_amount=order.tips`
    - Idempotency: klucz w kolejności `rro.transactionId` > `paymentOrderNum` > `pay:{order._id}:{order.total}` (bo jedno zamówienie może mieć wiele płatności częściowych) w `sh_external_order_refs` (source='choiceqr_pay')
    - Order matching: `id` → fallback `gateway_external_id` (oba z `tenant_id` w WHERE, `FOR UPDATE` w transakcji)
    - Walidacja kwoty: `order.total` vs `grand_total` — log warning przy niezgodności, nie odrzuca
    - Publishuje `order.payment_completed` event z `paymentCustomerDetails` w kontekście
    - Response: 200 OK empty body (nawet przy błędach — wymóg ChoiceQR, inaczej anulowanie po 3 próbie)

11. **`core/Integrations/ChoiceQRAdapter.php`** — dodano inbound support (P2)
    - `supportsInbound()` = true (było false)
    - `parseInboundCallback()` — parsuje eventy ChoiceQR, mapuje na wewnętrzne event types:
      - `order.cancelled` → `new_status='cancelled'`
      - `order.closed` → `new_status='completed'`
      - `order.delivery.update` → `new_delivery_status` (mapowanie przez `DELIVERY_STATUS_MAP`)
      - `order.qrPayment.completed` → `new_payment_status='online_paid'`
      - `order.qrPayment.error` → log only
    - `INBOUND_EVENTS` const — lista obsługiwanych event types
    - `DELIVERY_STATUS_MAP` const — ChoiceQR delivery status → SliceHub delivery_status
    - `extractOrderIdFromEventData()` — flexible order _id extraction (data._id / data.order._id / data.orderId)

**Lint: 4/4 PASS** (events.php, ChoiceQRAdapter.php, table_orders.php, pay.php)

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
| Webhook events URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/events.php?t=SECRET_TOKEN` |
| Get table orders URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/table_orders.php?t=SECRET_TOKEN&varSymbol=VAR_SYMBOL` |
| Pay table order URL | `https://TWOJA_DOMENA.pl/api/integrations/choiceqr/pay.php?t=SECRET_TOKEN` |

**Uwaga:** `SECRET_TOKEN` i `VAR_SYMBOL` muszą być zgodne z `sh_tenant_integrations.credentials`.

## Szacunek pracy (zaktualizowany)

| Faza | Status | Pliki | Czas |
|------|--------|-------|------|
| P0 — MVP (webhook + menu + areas) | **WDRUŻONE** | 3 nowe | ~8h |
| P1 — Push statusów (adapter) | **WDRUŻONE** | 4 zmienione + 1 test | ~3h |
| P2 — Webhook events + QR pay | **WDRUŻONE** | 3 nowe + 1 zmieniony | ~3h |
| Testy P0 | **PASS** | curl | ~1h |
| Testy P1 | **PASS** | 42 assertions + E2E 61/0/1 | ~1h |
| **Razem (P0+P1+P2)** | | | **~16h** |

---

## P2.1 — Naprawa inbound infrastructure (PLANOWANE)

### Kontekst

System nie był jeszcze na produkcji — Papu, Dotykacka, GastroSoft nigdy nie działały na żywo.
Szykujemy wdrożenie na żywy organizm. Audyt (2026-07-29) odkrył następujące problemy:

### Znalezione problemy

#### Problem 1: events.php dubluje inbound.php

`api/integrations/inbound.php` to gotowy generyczny receiver dla callbacków od dostawców zewnętrznych.
Ma: zapis do `sh_inbound_callbacks`, idempotencję, walidację przejść statusów, publikację eventów,
pełny audyt. `events.php` robi to samo inline ale:
- **NIE zapisuje do `sh_inbound_callbacks`** — nie widać w Settings → Inbound
- **NIE używa `ChoiceQRAdapter::parseInboundCallback()`** — metoda napisana ale nie wywoływana
- **NIE waliduje przejść statusów** — może ustawić `cancelled` na `completed` zamówieniu
- **NIE ma pełnego audytu** jak inbound.php

#### Problem 2: inbound.php ma przestarzałą logikę

- Hardcoded transition whitelist (linie 470-478) rozjechana z `OrderStateMachine::STRICT_TRANSITIONS`:
  - Brak `pending` w przejściach z `new` i `accepted`
  - `ready → dispatched` i `ready → in_delivery` istnieją w inbound.php ale NIE w OrderStateMachine
  - Brak uwzględnienia `order_type` (dine_in ma osobną mapę)
  - Brak feature flagów (skip_kitchen, auto_complete itp.)
- Nie czyta `new_delivery_status` ani `new_payment_status` z adaptera (tylko `new_status`)
- Nie wywołuje `OrderStateMachine::transitionOrder()` — robi ręczny UPDATE
- Brak audit trail do `sh_order_audit` / `sh_order_logs`

#### Problem 3: ChoiceQRAdapter nie jest zarejestrowany w inbound.php

Linie 346-350 wymieniają tylko PapuAdapter, DotykackaAdapter, GastroSoftAdapter.
ChoiceQRAdapter (który ma `supportsInbound() = true` i `parseInboundCallback()`) nie jest na liście.

#### Problem 4: events.php audit trail — puste wpisy dla delivery/payment events

Linie 405-414: dla `order.delivery.update` i `order.qrPayment.completed`, `$newStatus` jest null.
Audit INSERT loguje `old_status = $currentStatus`, `new_status = $currentStatus` — pusty audit entry
(zero zmiany, mylący przy analizie audytu). Podobne do Problemu 6 (pay.php).

#### Problem 5: pay.php UPDATE bez tenant_id — multi-tenant security hole

Linia 190: `WHERE id = :oid` — brak `tenant_id = :tid`. SELECT (linia 129) filtruje po tenant_id,
ale UPDATE nie. Na produkcji z wieloma tenantami = niedopuszczalne.

#### Problem 6: pay.php audit trail mylący

Linie 200-209: `sh_order_audit.old_status` / `new_status` dostają wartości payment_status
(`unpaid` → `online_paid`), nie order status. Mylące przy analizie audytu.

#### Problem 7: pay.php SELECT przed transakcją — race condition

Linia 127: SELECT bez `FOR UPDATE`, linia 184: `beginTransaction()`. Ten sam race condition
como Problem 1 (events.php brak FOR UPDATE), choć mniej krytyczny — dotyczy tylko `payment_status`,
nie `status` zamówienia.

#### Problem 8: events.php brak FOR UPDATE — race condition

Linia 294-301: SELECT statusu bez `FOR UPDATE`, potem UPDATE w transakcji. Inny proces (POS, KDS)
może zmienić status między SELECT a UPDATE. `OrderStateMachine::transitionOrder()` używa `FOR UPDATE`.

#### Problem 9: events.php fallback query — dead code

Linia 292: `"source = 'CQR' AND id = :eid"` — `id` to UUID z SliceHub, `:eid` to MongoDB ObjectID
z ChoiceQR. Nigdy się nie zmatchuje.

#### Problem 10: table_orders.php N+1 query

Linie 166-172: dla każdego zamówienia osobny SELECT na `sh_order_lines`. Przy 50 zamówieniach
= 51 zapytań. Można zastąpić batch SELECT lub LEFT JOIN.

#### Problem 11: pay.php brak walidacji kwoty

Linie 164-170: nie sprawdza czy `payment.amount` = `order.grand_total`. Partial payment zostanie
zapisana jako `online_paid` bez śladu kwoty.

#### Problem 12: Różny model auth

ChoiceQR: token w URL (`?t=SECRET_TOKEN`), tenant mapping przez `varSymbol`.
inbound.php: `?provider=X&integration_id=N` (HMAC w nagłówkach).
ChoiceQR w panelu pozwala podać tylko jeden URL — nie można dodać parametrów `integration_id`.
**Rozwiązanie:** events.php zostaje jako "drzwi wejściowe" (auth + tenant mapping) ale deleguje
logikę do wspólnego kodu (nie przez HTTP — przez require/shared function).

### Plan zmian (P2.1) — WDRUŻONE 2026-07-29

#### Krok 1: Naprawić inbound.php — WDRUŻONE

1. **Dodać ChoiceQRAdapter** do listy adapterów (linia 348-353) ✅
2. **Zastąpić hardcoded transition whitelist** wywołaniem `OrderStateMachine::transitionOrder()` ✅
   - Import `core/OrderStateMachine.php`
   - Wywołać `transitionOrder($pdo, $orderId, $tenantId, 0, $newStatus, $flags, $extraCols)`
   - `transitionOrder` sam waliduje przejścia, robi audit trail, optymistyczny locking
   - Usunięto hardcoded `$allowedTransitions` array
3. **Dodać obsługę `new_delivery_status`** z adapter result ✅
   - Jeśli `new_delivery_status` present → `OrderStateMachine::canTransitionDelivery()` + UPDATE
4. **Dodać obsługę `new_payment_status`** z adapter result ✅
   - Jeśli `new_payment_status` present → UPDATE `sh_orders.payment_status`
5. **Dodać `require_once` dla ChoiceQRAdapter.php i OrderStateMachine.php** ✅

#### Krok 2: Przerobić events.php na cienką warstwę — WDRUŻONE

1. **Auth tokenem** (zostaje jak jest — `?t=SECRET_TOKEN`, `hash_equals`) ✅
2. **Mapowanie varSymbol → tenant_id + integration_id** (zostaje, dodano $integrationRow + $credentials) ✅
3. **Zapis do `sh_inbound_callbacks`** (dla widoczności w Settings → Inbound) ✅
   - INSERT z `provider='choiceqr'`, `raw_body`, `raw_headers`, `remote_ip`, `status='pending'`
   - Feature-detect: fallback do `sh_external_order_refs` gdy tabela nie istnieje
4. **Delegacja do `ChoiceQRAdapter::parseInboundCallback()`** (zamiast inline switch/case) ✅
   - Tworzy instancję adaptera z integration row
   - Wywołuje `parseInboundCallback($rawBody, [], $credentials)`
   - Otrzymuje `{ok, external_event_id, external_ref, event_type, new_status, new_delivery_status, new_payment_status, payload}`
5. **Idempotencja przez `sh_inbound_callbacks`** (z fallback do `sh_external_order_refs`) ✅
   - Duplikat = 200 OK, skip
6. **Order matching + status update przez `OrderStateMachine::transitionOrder()`** ✅
   - Match `external_ref` → `sh_orders.gateway_external_id`
   - Wywołać `transitionOrder()` z `new_status` (jeśli present)
   - UPDATE `delivery_status` (jeśli `new_delivery_status` present + `canTransitionDelivery`)
   - UPDATE `payment_status` (jeśli `new_payment_status` present)
7. **Publikacja eventów przez `OrderEventPublisher`** (zostaje) ✅
8. **UPDATE `sh_inbound_callbacks` status='processed'** na końcu ✅
9. **Response: 200 OK empty body** (zostaje — ChoiceQR tego wymaga) ✅
10. **Log-only events** (menu changes, marketplace, import, qrPayment.error) — 200 OK + log do sh_inbound_callbacks z status='ignored' ✅
11. **Usunięto funkcje `cqr_map_delivery_status()` i `cqr_extract_order_id()`** — logika przeniesiona do adaptera ✅
12. **Usunięto feature-detect dla gateway_columns, delivery_status, cancellation_reason** — OSM zarządza tym wewnętrznie ✅

#### Krok 3: Naprawić pay.php — WDRUŻONE

1. **Dodać `tenant_id` do WHERE w UPDATE** ✅: `WHERE id = :oid AND tenant_id = :tid`
2. **Przenieść SELECT do transakcji z `FOR UPDATE`** ✅ — zamknięty race condition (Problem 7)
3. **Audit trail:** usunięto ręczny INSERT do `sh_order_audit` (payment_status to nie order status) ✅
4. **Dodać zapis do `sh_inbound_callbacks`** (jak events.php po kroku 2) — dla audytu ✅
5. **Walidacja `payment.amount` vs `order.grand_total`** — log warning przy niezgodności, nie odrzuca ✅

#### Krok 4: table_orders.php — optymalizacja N+1 — WDRUŻONE

Zastąpiono N+1 (per-order SELECT na `sh_order_lines`) batch SELECT z `WHERE order_id IN (...)` ✅

#### Krok 5: Aktualizacja dokumentacji — WDRUŻONE

- `_docs/14_INBOUND_CALLBACKS.md` — dodać ChoiceQR do listy providerów
- `_docs/integrations/choiceqr_integration.md` — oznaczyć P2.1 jako WDRUŻONE (ten dokument)

### Lint PHP — 4/4 PASS

```
No syntax errors detected in api/integrations/inbound.php
No syntax errors detected in api/integrations/choiceqr/events.php
No syntax errors detected in api/integrations/choiceqr/pay.php
No syntax errors detected in api/integrations/choiceqr/table_orders.php
```

### Testy — 61 pass / 0 fail / 1 warn (pre-existing)

### Dodatkowa naprawa po weryfikacji (2026-07-29)

Podczas weryfikacji wykryto ten sam race condition co w pay.php (Problem 7/8) również w:
- **inbound.php** — SELECT order bez `FOR UPDATE` i bez transakcji dla delivery/payment updates. Naprawiono: otoczono `beginTransaction()` + `SELECT ... FOR UPDATE` + `commit/rollback`.
- **events.php** — SELECT order poza transakcją (linia 301), transakcja zaczynała się dopiero na linii 335. Naprawiono: przeniesiono SELECT do wnętrza transakcji z `FOR UPDATE`.

### Czego NIE zmieniamy

- `webhook.php` (P0) — odbiór zamówień, osobny cel, nie dotykamy
- `menu.php`, `areas.php` (P0) — export GET, nie dotykamy
- `ChoiceQRAdapter::parseInboundCallback()` — już napisana poprawnie, zostaje
- `ChoiceQRAdapter` push (P1) — zostaje jak jest
- `GatewayAuth` — zostaje (używany przez webhook.php P0)

### Ryzyka

1. **inbound.php obsługuje też Papu/Dotykacka** — zmiana transition logic na OrderStateMachine
   może zmienić zachowanie dla tych adapterów. Ale skoro nigdy nie były na produkcji, ryzyko = niskie.
2. **events.php musi zachować 200 OK empty body** — ChoiceQR wymaga tego, inaczej anuluje zamówienie.
   Wszystkie ścieżki (sukces, duplikat, log-only) muszą zwracać 200 + empty body.
3. **sh_inbound_callbacks może nie istnieć** — migracja 029 może nie być zaaplikowana.
   events.php musi mieć feature-detect (jak inbound.php linia 232).
4. **OrderStateMachine::transitionOrder() wymaga userId** — events.php nie ma sesji użytkownika.
   Użyjemy `userId=0` (system/external) z `actor_type='external_api'` w extraCols.
5. **pay.php po dodaniu `tenant_id` do WHERE** — jeśli istnieją stare dane testowe gdzie `tenant_id`
   nie jest ustawiony na zamówieniu, UPDATE nie trafi. Ryzyko zerowe na czystej produkcji.
6. **pay.php SELECT w transakcji** — przeniesienie SELECT do transakcji może minimalnie wydłużyć
   czas trwania transakcji. Akceptowalne dla pojedynczego UPDATE payment_status.
7. **events.php audit trail po refaktorze** — po delegacji do OSM, `transitionOrder()` sam tworzy
   audit entry do `sh_order_audit` i `sh_order_logs`. Dla delivery_status i payment_status changes
   (które nie przechodzą przez OSM) trzeba osobno logować lub pominąć audit (Problem 4).

---

## P2.2 — Naprawa payloadu pay.php (zgodność z tablePayOrderSchema) — WDRUŻONE 2026-07-29

### Kontekst

Audyt (2026-07-29, `_docs/reports/2026-07-29_choiceqr_pay_fix_plan.md`) wykrył, że `pay.php`
był niezgodny z oficjalną dokumentacją ChoiceQR (`/content/pos/index.md`, sekcja
"Pay (split/close) dishes in POS"). Oczekiwał starego payloadu `{ _id, payment, varSymbol }`,
tymczasem ChoiceQR wysyła `tablePayOrderSchema`:

```json
{
  "order": {
    "_id": "orderID1",
    "items": [...],
    "total": 180000,
    "tips": 30000,
    "paymentCustomerDetails": {
      "card": { "type": "MC", "last4": "5000*00" },
      "paymentOrderNum": "TEST_REST-O-1",
      "rro": { "transactionId": "2350740175", "terminalId": "...", "authcode": "..." }
    }
  },
  "varSymbol": "10102"
}
```

Skutek: QR płatność przy stoliku mogła nie być poprawnie rozpoznawana, napiwek był gubiony,
a w najgorszym przypadku ChoiceQR anulował transakcję po 3 nieudanych próbach (bo `pay.php`
nie rozumiał payloadu i rzucał błędami).

### Zmiany w `api/integrations/choiceqr/pay.php`

1. **Payload** — czyta `{ order, varSymbol }` zamiast `{ _id, payment, varSymbol }`. Z `order`
   wyciąga `_id`, `total`, `tips`, `paymentCustomerDetails` (zgodnie z `tablePayOrderSchema`).
2. **Idempotencja** — klucz w kolejności:
   - `paymentCustomerDetails.rro.transactionId` → `txn:...` (najpewniejszy)
   - `paymentCustomerDetails.paymentOrderNum` → `pon:...`
   - fallback `pay:{order._id}:{order.total}` (jedno zamówienie przy stoliku może mieć wiele
     płatności częściowych, więc sama kombinacja zamówienie + kwota daje sensowną unikalność)
3. **Update zamówienia** — QR płatność przy stoliku jest ZAWSZE online (wg docs), więc:
   - `payment_status = 'online_paid'`
   - `payment_method = 'online'`
   - `tip_amount = order.tips`
4. **Walidacja kwoty** — `order.total` vs `grand_total`: log warning przy niezgodności, nie odrzuca
   (klient już zapłacił — lepiej oznaczyć opłacone niż anulować).
5. **Event** — `order.payment_completed` z `paymentCustomerDetails`, `transaction_id`,
   `payment_order_num`, `paid_amount`, `tips` w kontekście.

### Zachowane wzorce bezpieczeństwa (bez zmian)

- `hash_equals()` na tokenie `?t=` vs `webhook_token` z `sh_tenant_integrations.credentials`
- `tenant_id` w każdym WHERE (SELECT, UPDATE, fallback po `gateway_external_id`)
- `SELECT ... FOR UPDATE` w transakcji (race condition)
- 200 OK empty body zawsze (nawet przy błędach — wymóg ChoiceQR)
- Callback logging do `sh_inbound_callbacks` (feature-detect)
- Inne endpointy ChoiceQR (webhook/menu/areas/events/table_orders) nietknięte

### Weryfikacja (2026-07-29)

| Sprawdzenie | Wynik |
|-------------|-------|
| `php -l pay.php` | **PASS** (No syntax errors detected) |
| Test runner headless (`run_test_runner_headless.cjs`) | **61 pass / 0 fail / 1 warn** (warn pre-existing, brak regresji) |
| Zgodność z `tablePayOrderSchema` | ✅ wszystkie pola ze schematu obsługiwane |
| Null-safety (`paymentCustomerDetails` opcjonalne) | ✅ `null['key'] ?? ...` bezpieczne w PHP 8.3 |
| Brak pozostałości starego payloadu | ✅ grep: zero odwołań do `$payment`/`$paymentId`/`$payMethod` |

### Powiązane dokumenty

- Plan naprawy: `_docs/reports/2026-07-29_choiceqr_pay_fix_plan.md`
- Oficjalna specyfikacja: https://open-api.choiceqr.com/docs/content/pos/index.md (sekcja "Pay (split/close) dishes in POS")

---

## P2.3 — Compliance fix (wymogi ChoiceQR) — WDRUŻONE 2026-07-29

### Kontekst

Audyt kodowy (2026-07-29) + zestawienie z oficjalną dokumentacją ChoiceQR wykryło **3 krytyczne
niezgodności** z twardymi wymogami ChoiceQR + **2 do weryfikacji live payloadiem**. Bez P2.3:

- **Core order intake może tracić zamówienia klientów** (K5 — webhook zwraca 500 przy DB down →
  ChoiceQR anuluje zamówienie po 3 retry)
- **QR płatności przy stoliku nie działają** (NC6 — schema niezgodna, K3 — posID stolika się nie zgadza)

P2.1 i P2.2 naprawiły infrastrukturę wewnętrzną (race conditions, payload pay.php). P2.3 naprawia
**zgodność z ChoiceQR API** — to co ChoiceQR twardo wymaga żeby integracja działała.

Prompt dla nowej sesji: `_docs/integrations/choiceqr_prompt_compliance_fix.md`.

### Wdrożenie (2026-07-29)

3 fixy wdrożone, NC1/NC12 pending staging. Lint 3/3 PASS, testy 61 pass / 0 fail / 1 warn (brak regresji).

#### Fix 1: K5 — webhook.php 200 OK przy błędach post-auth — WDRUŻONE

**Plik:** `api/integrations/choiceqr/webhook.php`

**Zmiana:** Dodano `cqr_fail_silent(int $code, string $msg)` — zwraca **200 OK** + `error_log`
(z wzorcem `events.php` `cqr_ev_fail` / `pay.php` `cqr_pay_fail`). Oryginalny `cqr_fail` zachowany
dla błędów pre-auth (401/403).

**Mapowanie wywołań:**
- `cqr_fail(401, 'Missing token')` — pre-auth, **401** (zachowane)
- `cqr_fail(403, 'No tenant integration...')` — pre-auth, **403** (zachowane)
- `cqr_fail(401, 'Invalid webhook token')` — pre-auth, **401** (zachowane)
- `cqr_fail_silent(500, 'Database connection unavailable')` — post-auth, **200** (zmienione)
- `cqr_fail_silent(400, 'Invalid JSON payload')` — post-auth, **200** (zmienione)
- `cqr_fail_silent(400, 'Missing order or varSymbol')` — post-auth, **200** (zmienione)
- `cqr_fail_silent(400, 'Missing order._id')` — post-auth, **200** (zmienione)
- `cqr_fail_silent(500, 'Internal server error: ...')` — FATAL catch, **200** (zmienione)

**Weryfikacja curl (XAMPP, 2026-07-29):**
- Brak tokenu → 401 ✓
- Zły token + poprawny payload → 401 ✓ (token verify po payload parse)
- Dobry token + zły JSON → 200 ✓
- Dobry token + brak order → 200 ✓
- Dobry token + nieistniejący varSymbol → 403 ✓

**Uwaga:** Token verify (linia 153) następuje PO parse payload (linia 102-117). Zły token +
niepoprawny payload → 200 (failuje na payload przed token verify). To bezpieczne — ChoiceQR
retryuje, a payload jest genuinely invalid. Krytyczny fix (DB down → 200) działa poprawnie.

#### Fix 2: NC6 — table_orders.php schema tableOrderSchema — WDRUŻONE

**Plik:** `api/integrations/choiceqr/table_orders.php`

**Zmiana:** Output zgodny z `tableOrderSchema` (pos/index.md, zweryfikowane z docs):

**Item schema (każdy item z sh_order_lines):**
```json
{
  "_id": "line.id",          // required — internal order item ID
  "posID": "line.item_sku",  // required — POS ID
  "name": "line.snapshot_name", // required — plain string (NIE zagnieżdżony name.en.name)
  "count": "line.quantity",  // required — pcs
  "price": "line.unit_price",// required — end price for 1 pcs (grosze)
  "type": "dish",            // required — "dish" lub "option"
  "parent": null             // required — dla dish: null; dla option: parent line._id
}
```

**Modyfikatory (z `modifiers_json`):** rozwijane do osobnych itemów:
```json
{
  "_id": "line.id-opt-N",
  "posID": "mod.posID",
  "name": "mod.itemName.en.name",
  "count": "mod.count",
  "price": "mod.price",
  "type": "option",
  "parent": "line.id"
}
```

**Order-level (tylko pola z tableOrderSchema):**
```json
{
  "_id": "order.id",
  "items": [...],
  "table": { "_id": "table_{sh_tables.id}", "name": "Stolik N" },
  "waiter": null,
  "allowUseDiscount": false
}
```

**Usunięto z order-level** (nie ma w tableOrderSchema): `num`, `type`, `subTotal`, `total`, `tips`,
`status`, `payBy`, `currency`.

**Weryfikacja curl (XAMPP, 2026-07-29):** Output zweryfikowany — schema zgodna, items z `_id`/`type`/`parent`.

#### Fix 3: K3 — areas.php vs table_orders.php posID stolika — WDRUŻONE (Opcja A)

**Plik:** `api/integrations/choiceqr/table_orders.php` (areas.php bez zmian)

**Decyzja użytkownika:** Opcja A (JOIN w table_orders.php, areas.php bez zmian).

**Implementacja (po weryfikacji schema DB):**
- `sh_orders` ma kolumnę **`table_id`** (FK → `sh_tables.id`), NIE `table_number` (jak zakładał spec)
- `areas.php` eksportuje `posID = 'table_{sh_tables.id}'` (PK)
- `table_orders.php` z `area=table_N` → match `sh_orders.table_id = N` bezpośrednio (LEFT JOIN sh_tables
  dla label/name)

**4 ścieżki query (feature-detect):**
1. **Preferowana:** `sh_orders.table_id` istnieje → `WHERE o.table_id = :table_pk_id` + LEFT JOIN sh_tables
2. **Legacy (table_number + sh_tables):** → JOIN sh_tables po table_number, `WHERE t.id = :table_pk_id`
3. **Legacy (table_number bez sh_tables):** → `WHERE table_number = :tn` (zachowanie pre-P2.3)
4. **Brak filtru:** zone_N / takeaway / delivery / brak area → wszystkie dine_in

**Weryfikacja curl (XAMPP, 2026-07-29):**
- `areas.php` → `table_5` (posID = `table_{id=5}`)
- `table_orders.php?area=table_5` (z order `table_id=5`) → 1 order, `table._id = "table_5"` ✓
- posID spójny między areas.php a table_orders.php ✓

### Źródła dokumentacji ChoiceQR (zweryfikowane 2026-07-29)

- https://open-api.choiceqr.com/docs/content/pos/index.md — POS integration (Create order, Get menu, Get areas, Get table orders, Pay table order, tableOrderSchema, tablePayOrderSchema)
- https://open-api.choiceqr.com/docs/content/order/schema.md — Order schema (~30 pól)
- https://open-api.choiceqr.com/docs/content/order/methods.md — PUT /cancel, /close, /delivery (allowed statuses)
- https://open-api.choiceqr.com/docs/content/order/statuses.md — Order + delivery statuses
- https://open-api.choiceqr.com/docs/content/webhooks.md — Webhook event schema
- https://open-api.choiceqr.com/docs/content/api.md — x-idempotence-key, rate limit 60 req/sec
- https://open-api.choiceqr.com/docs/content/authorization.md — OAuth flow, varSymbol z token exchange

### Fixy w scope P2.3

#### Fix 1: K5 — webhook.php musi zwracać 200 OK przy błędach post-auth (KRYTYCZNE)

**Problem:** `webhook.php` `cqr_fail()` (linia 47-54) ustawia realny HTTP kod (401/403/500).
ChoiceQR docs (pos/index.md): „Platform will try 3 times with 3 seconds interval (15 sec timeout),
then order will mark as cancelled". Przy chwilowym padzie DB (500) → ChoiceQR retryuje 3x →
**anuluje zamówienie klienta**. Klient płaci, restauracja nie widzi zamówienia.

**Fix:** `cqr_fail` zwraca 200 OK + `error_log` dla błędów **post-auth** (DB down, parse error,
insert failure). Realne kody HTTP (401/403) zachować tylko dla błędów **pre-auth** (brak tokenu,
zły token, brak tenant mapping) — bo te ChoiceQR i tak retryuje, ale przynajmniej w logach Apache
widać 401.

**Wzorzec:** skopiuj zachowanie `events.php` `cqr_ev_fail` (linia 47-54) i `pay.php` `cqr_pay_fail`
(linia 40-47) — one już zwracają 200 OK + log.

**Pliki:** `api/integrations/choiceqr/webhook.php`

**Wymagane przez ChoiceQR:** TAK (twardo — dosłownie w docs)

#### Fix 2: NC6 — table_orders.php niezgodny z tableOrderSchema (KRYTYCZNE)

**Problem:** `table_orders.php` linia 184-192 zwraca itemy jako:
```php
['posID', 'name', 'price', 'count', 'total', 'vat']
```

ChoiceQR `tableOrderSchema` (pos/index.md sekcja „Getting table orders from POS") wymaga w itemach:
- `_id` (string, **required**) — internal order item ID w POS
- `posID` (string, **required**) — POS ID
- `name` (string, **required**) — display name
- `count` (number, **required**) — pcs
- `price` (number, **required**) — end price for 1 pcs (with discount + options)
- `type` (string, **required**) — `"dish"` lub `"option"`
- `parent` (string, nullable) — dla `type="option"`: dish parent `_id`
- `alcohol` (boolean, optional)
- `fractionItem` (boolean, optional)

**Brakuje `_id`, `type`, `parent`** (wszystkie required). Bez `type` ChoiceQR nie wie co to danie a
co modyfikator. Bez `parent` nie wie do którego dania należy modyfikator. **QR płatności przy
stoliku z modyfikatorami nie zrenderują się poprawnie.**

Dodatkowo SliceHub zwraca extra pola order-level (`num`, `type`, `subTotal`, `total`, `tips`,
`status`, `payBy`, `currency`) których **nie ma w `tableOrderSchema`** — ChoiceQR prawdopodobnie je
zignoruje, ale schema walidacja może odrzucić. Sprawdź w docs czy extra pola są dozwolone; jeśli
nie — usuń.

**Fix:**
- Każdy item z `sh_order_lines` → `{_id: line.id, posID: line.item_sku, name: line.snapshot_name,
  count: line.quantity, price: line.unit_price, type: "dish", parent: null}`
- Modyfikatory z `modifiers_json` → osobne itemy z `type: "option"`, `parent: line.id`,
  `posID: modifier.posID`, `price: modifier.price`, `count: modifier.count`
- Order-level: zostaw tylko `_id`, `items`, `table` (z `table._id` = posID stolika, `table.name`),
  `waiter` (null jeśli nie masz), `allowUseDiscount` (false)
- Usuń `num`, `subTotal`, `total`, `tips`, `status`, `payBy`, `currency` z outputu order-level
  (nie ma ich w schema)

**Pliki:** `api/integrations/choiceqr/table_orders.php`

**Wymagane przez ChoiceQR:** TAK (twardo — schema validation)

#### Fix 3: K3 — areas.php vs table_orders.php niezgodność posID stolika (KRYTYCZNE)

**Problem:**
- `areas.php` linia 136: eksportuje `'posID' => 'table_' . $table['id']` gdzie `id` to
  **`sh_tables.id`** (PK)
- `table_orders.php` linia 103-104: `preg_match('/^table_(\d+)$/', $areaId, $m)` →
  `$tableId = (int)$m[1]`
- `table_orders.php` linia 131-132: `AND table_number = :tn` z `:tn = (string)$tableId`

Więc `areas.php` wysyła `table_{sh_tables.id}`, a `table_orders.php` interpretuje to jako
`table_number={sh_tables.id}`. Jeśli `sh_tables.id=5` ale `table_number='12'`, ChoiceQR wyśle
`area=table_5`, a `table_orders.php` szuka `table_number='5'` (nie istnieje) → pusta lista →
**QR płatności nie znajdą stolika**.

**Fix (dwie opcje — decyzja użytkownika wymagana):**

**Opcja A:** W `table_orders.php` matchuj po `sh_tables.id` z JOIN do `sh_orders.table_number`:
```sql
SELECT o.* FROM sh_orders o
JOIN sh_tables t ON t.table_number = o.table_number AND t.tenant_id = o.tenant_id
WHERE t.id = :table_pk_id AND o.tenant_id = :tid AND o.order_type = 'dine_in' ...
```
Plus zachowaj `areas.php` bez zmian. Zaleta: nie zmienia posID dla istniejących konfiguracji
ChoiceQR. Wada: wymaga JOIN, bardziej złożone.

**Opcja B:** W `areas.php` eksportuj `'posID' => 'table_' . $table['table_number']` zamiast
`table_{id}`. Plus zachowaj `table_orders.php` bez zmian. Zaleta: prostsze. Wada: zmienia posID
dla istniejących konfiguracji ChoiceQR (trzeba re-export areas w panelu ChoiceQR).

**Pliki:** `api/integrations/choiceqr/table_orders.php` (Opcja A) lub
`api/integrations/choiceqr/areas.php` (Opcja B)

**Wymagane przez ChoiceQR:** TAK (twardo — posID musi być spójne między areas a table_orders)

### Weryfikacje w scope P2.3 (do potwierdzenia live payloadiem)

#### Weryfikacja 1: NC1 — `order.delivery.update` ścieżka `deliveryStatus`

**Hipoteza z audytu:** `ChoiceQRAdapter::parseInboundCallback()` linia 307 czyta
`$data['deliveryStatus']`. Docs webhooks.md mówią że dla `order.delivery.update` schema `data` =
pełny Order schema, gdzie pole to `delivery.status` (zagnieżdżone). Jeśli to prawda,
`$data['deliveryStatus']` zawsze puste → status dostawy się nie aktualizuje.

**ALE:** nie mamy potwierdzenia live payloadiem. ChoiceQR może wysyłać uproszczony
`{_id, deliveryStatus}` zamiast pełnego Order. **Nie naprawiaj ślepo.**

**Działanie:**
- Sprawdź czy w `sh_inbound_callbacks` są jakieś realne eventy `order.delivery.update` od ChoiceQR
  (jeśli tenant ma aktywne integracje)
- Jeśli tak — sprawdź `raw_body` → zobacz realną strukturę `data`
- Jeśli nie ma — **zapytaj użytkownika** czy ma dostęp do stagingu ChoiceQR lub czy może
  wygenerować testowy event
- Jeśli nie da się zweryfikować → dodaj **obustronną fallback logikę**: czytaj
  `$data['deliveryStatus'] ?? $data['delivery']['status'] ?? null`. To bezpieczne — działa dla
  obu wariantów.

**Status (2026-07-29):** PENDING STAGING. `sh_inbound_callbacks` jest pusta (brak live eventów).
Użytkownik ma dostęp do staging ChoiceQR → weryfikacja po dostarczeniu live payloadu. Nie dodano
ślepego fallbacku (zgodnie z decyzją użytkownika).

**Pliki:** `core/Integrations/ChoiceQRAdapter.php` (do naprawy po weryfikacji)

**Wymagane przez ChoiceQR:** PRAWDOPODOBNIE (zależy od realnego payloadu)

#### Weryfikacja 2: NC12 — `order.qrPayment.completed` pole `payment`

**Hipoteza:** Adapter linia 321 czyta `$data['payment']`. Order schema ma `paymentCustomerDetails`
i `qrPayment`, nie `payment`. Jeśli `data` = pełny Order, `$data['payment']` puste →
`payment_data = []`.

**Działanie:** analogicznie jak NC1 — sprawdź `sh_inbound_callbacks` dla
`order.qrPayment.completed`, jeśli nie ma → zapytaj użytkownika. Fallback: czytaj
`$data['payment'] ?? $data['paymentCustomerDetails'] ?? $data['qrPayment'] ?? []`.

**Status (2026-07-29):** PENDING STAGING. Analogicznie jak NC1 — brak live eventów, weryfikacja
po dostarczeniu payloadu ze staging.

**Pliki:** `core/Integrations/ChoiceQRAdapter.php` (do naprawy po weryfikacji)

**Wymagane przez ChoiceQR:** PRAWDOPODOBNIE (zależy od realnego payloadu)

### Poza scope P2.3 (BACKLOG — nie blokuje wdrożenia)

Poniższe problemy są celowo poza scope P2.3. To dług wewnętrzny SliceHub, nie wymagania ChoiceQR.

| Problem | Kategoria | Uzasadnienie |
|---------|-----------|--------------|
| K1 — pętla eventów (source='gateway' pushowane z powrotem) | Szum operacyjny | ChoiceQR odrzuci duplikat 400 → DLQ. Nie blokuje. |
| K2 — `paid` vs `online_paid` (słownik payment_status) | Nasz słownik wewnętrzny | ChoiceQR nie definiuje słownika payment_status. |
| K4 — pay.php callback log (brak tenant_id/integration_id) | Observability | Nie compliance. Płatność działa, tylko nie widać w Settings. |
| NC3 — `customerComments` vs `delivery.comment` | Jakość danych | ChoiceQR wysyła oba, my czytamy zły. Restauracja dostanie zamówienie. |
| W4 — ignorowane pola Order schema (18 pól: marketplace, loyalty, cutlery, timezone, ...) | Feature gaps | ChoiceQR nie waliduje czy czytamy. Restauracja obsłuży bez tego. |
| NC7 — OAuth flow automatyczny | Onboarding | Manual token entry działa. Automatyzacja to koszt bez ROI. |
| NC11 — `order.accepted` event nie obsłużony | Log-only | Zamówienie działa, tracemy tylko info o akceptacji na terminalu. |
| NC13 — `table.customer.email` | Feature gap | Email klienta przy stoliku. Backlog. |
| NC14 — `external` (marketplace BOLT/GLOVO/WOLT/UBER) | Raportowanie | Brak raportów po marketplace. Backlog gdy pojawi się klient. |
| NC15 — `additionalFees` (opłaty strefowe) | Feature gap | Total z ChoiceQR zawiera opłaty, my nie rozkładamy. |
| NC16 — `discountData` (loyalty/promocode/area discount) | Raportowanie | Nie wiemy z czego zniżka. Backlog. |
| NC17 — `cutlery` (sztućce) | Feature gap | Restauracja nie wie ile osób. Backlog. |
| NC18 — `preparingTime` / `deliveringTime` | Estymacja | promised_time hardcoded +30min. Backlog. |
| NC19 — `timezone` hardcoded Europe/Warsaw | Bug dla klientów spoza PL | Zła strefa dla Czech/Słowacja. Backlog. |
| NC20 — `loyalty` (bonus/gifts) | Feature gap | Brak w POS. Backlog. |
| W1 — token w URL → logi Apache | Hardening | Mitigacja: dedykowany vhost z CustomLog. Backlog. |
| W2 — brak `x-idempotence-key` w push adaptera | Hardening | ChoiceQR sugeruje, nie wymaga. Backlog. |
| S1 — brak cache menu | Wydajność | 5 SELECTów per request. Backlog. |
| S2 — `posID` kategorii to `cat_{id}` nie `ascii_key` | Stabilność ID | Re-import zmieni ID. Backlog. |
| S6 — DLQ dla events przy DB down | Resilience | Spooler plikowy + alert. Backlog. |
| S7 — testy inbound (parseInboundCallback + E2E) | Test coverage | Brak testów dla 5 typów eventów. Backlog. |

### Workflow P2.3 — WYKONANE

1. ✅ Przeczytaj dokumentację (sekcja „Źródła dokumentacji ChoiceQR")
2. ✅ Przeczytaj aktualny kod: `webhook.php`, `table_orders.php`, `areas.php`, `ChoiceQRAdapter.php`
3. ✅ Zweryfikuj NC1 i NC12 — `sh_inbound_callbacks` pusta (brak live eventów) → zapytano użytkownika
4. ✅ Zapytaj użytkownika o wybór **Opcji A vs B** dla Fix 3 (K3) → wybrano **Opcja A**
5. ✅ Wdroż Fix 1 (K5) — `cqr_fail_silent` dla post-auth, `cqr_fail` dla pre-auth
6. ✅ Wdroż Fix 2 (NC6) — schema tableOrderSchema (_id, type, parent, modyfikatory jako option items)
7. ✅ Wdroż Fix 3 (K3) — Opcja A: match `sh_orders.table_id = N` (FK → sh_tables.id)
   - **Uwaga:** schema DB ma `table_id` (FK), nie `table_number` jak zakładał spec. Implementacja
     dostosowana: 4 ścieżki query (table_id / table_number+JOIN / table_number / brak filtru).
8. ⏸ NC1/NC12 — pending staging (użytkownik ma dostęp, dostarczy live payload)
9. ✅ Lint: `php -l` — 3/3 PASS (webhook.php, table_orders.php, areas.php)
10. ✅ Testy: `node scripts/run_test_runner_headless.cjs` → 61 pass / 0 fail / 1 warn (brak regresji)
11. ✅ Testy curl: webhook (401 pre-auth / 200 post-auth), table_orders (schema + JOIN), areas (posID)
12. ✅ Zaktualizowano ten dokument
13. Commit: `fix(choiceqr): compliance fix — webhook 200 OK, table_orders schema, table posID alignment`

### Zasady P2.3

- **Zawsze pytaj użytkownika gdy nie jesteś pewien** — szczególnie dla NC1/NC12 (live payload) i
  K3 (Opcja A vs B). Nie zgaduj.
- **Nie ruszaj działającego kodu** — P0/P1/P2/P2.1/P2.2 są wdrożone i testowane. Zmieniasz tylko
  to co w scope.
- **Nie dodawaj feature'ów z backlogu** — sekcja „Poza scope" jest celowo wylistowana.
- **Zachowaj wzorce bezpieczeństwa** — `hash_equals()` na tokenie, `tenant_id` w WHERE,
  `SELECT ... FOR UPDATE` w transakcjach, 200 OK empty body dla ChoiceQR.
- **Testuj po każdej zmianie** — lint + test runner + curl. Nie batchuj testów na koniec.
- **Aktualizuj dokumentację** — ten plik musi odzwierciedlać stan po zmianach.

### Powiązane dokumenty

- Prompt dla nowej sesji: `_docs/integrations/choiceqr_prompt_compliance_fix.md`
- Oficjalna dokumentacja ChoiceQR: sekcja „Źródła dokumentacji ChoiceQR" wyżej
- Poprzednie fazy: P2.1 (sekcja wyżej), P2.2 (sekcja wyżej)
