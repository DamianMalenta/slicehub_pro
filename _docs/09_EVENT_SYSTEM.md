# 09. Event System — Transactional Outbox + Webhooks + Integration Registry

> **Status:** ✅ Faza 7 · Sesja 7.1 (2026-04-18)
> **Migracja:** `026_event_system.sql`
> **Core:** `core/OrderEventPublisher.php`
> **Worker:** `scripts/worker_webhooks.php` (Sesja 7.3 — pending)

---

## 1. Problem, który rozwiązujemy

Przed m026 moduły SliceHub (POS, KDS, Delivery, Courses, Online, Gateway) komunikowały się **przez wspólną bazę** (`sh_orders`, `sh_order_audit`). To tworzyło trzy poważne problemy:

1. **Sprzężenie modułów** — każdy moduł musiał znać schemat `sh_orders`, bezpośrednio czytać/zapisywać. Zmiana jednego moduły mogła zepsuć inny.
2. **Brak integracji z zewnętrznymi systemami** — aby zintegrować z Papu, Dotykacką, GastroSoftem musielibyśmy w każdym miejscu dodać `$papu->push()` (fire-and-forget, bez retry, bez audytu).
3. **Brak async processing** — długie operacje (AI pipeline, notyfikacje SMS, webhooki do 3rd-party) musiałyby być wykonywane synchronicznie → slow response, timeout ryzyko.

**Pattern: Transactional Outbox** (Chris Richardson, *Microservices Patterns*) — rozwiązuje wszystkie trzy:
- Event publikowany **w tej samej transakcji** co zapis do `sh_orders` → zero eventual inconsistency.
- Workerzy asynchroniczni ciągną eventy z outbox i dostarczają je subskrybentom.
- Fault tolerance: awaria worker'a / 3rd-party API nigdy nie blokuje głównego flow.

---

## 2. Architektura

```
┌─────────────────────────────────────────────────────────────────────┐
│  PRODUCENCI EVENTÓW (synchroniczne, w transakcji main)             │
│                                                                     │
│   api/online/engine.php#guest_checkout   → order.created            │
│   api/gateway/intake.php                 → order.created            │
│   api/pos/engine.php#finalize_order      → order.created/edited    │
│   api/kds/engine.php#bump_order          → order.accepted/preparing/ready │
│   api/kds/engine.php#recall_order        → order.recalled           │
│   api/delivery/dispatch.php              → order.dispatched         │
│   api/courses/engine.php#update_status   → order.completed/delivered/cancelled │
│                         │                                           │
│                         ▼                                           │
│   OrderEventPublisher::publishOrderLifecycle()                     │
│                         │                                           │
│                         ▼ (transactional outbox)                   │
│   sh_event_outbox [status=pending]                                  │
└─────────────────────────────────────────────────────────────────────┘
                          │
                          │  (asynchroniczne, cron)
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  KONSUMENCI EVENTÓW                                                │
│                                                                     │
│   scripts/worker_webhooks.php                                       │
│       → sh_webhook_endpoints (HMAC-signed HTTP POST)               │
│       → sh_webhook_deliveries (audit + retry history)              │
│                                                                     │
│   scripts/worker_integrations.php  (Sesja 7.4)                     │
│       → sh_tenant_integrations                                      │
│       → core/Integrations/PapuClient.php                           │
│       → core/Integrations/DotykackaAdapter.php  (TBD)              │
│       → core/Integrations/GastroSoftAdapter.php (TBD)              │
│                                                                     │
│   Wewnętrzni konsumenci (opcjonalnie, do analizy):                 │
│       → Analytics warehouse                                         │
│       → SMS/email notifications                                     │
│       → Dashboard live feed (SSE)                                   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. Kanoniczny słownik eventów

W 1:1 z [`_docs/08_ORDER_STATUS_DICTIONARY.md`](./08_ORDER_STATUS_DICTIONARY.md).

| event_type | Emitowane gdy | Źródło | Payload kluczowe |
|---|---|---|---|
| `order.created` | Nowe zamówienie zapisane (`status='new'`) | `online`, `gateway`, `pos`, `kiosk` | order header + lines |
| `order.accepted` | Kuchnia przyjęła (`new → accepted`) | `kds` | from_status, to_status, actor_id (kucharz) |
| `order.preparing` | Kuchnia zaczęła (`accepted → preparing`) | `kds` | from_status, to_status |
| `order.ready` | Kuchnia skończyła (`preparing → ready`) | `kds` | from_status, to_status |
| `order.dispatched` | Driver przypisany (course creation) | `delivery` | driver_id, course_id, stop_number |
| `order.in_delivery` | Driver ruszył (`delivery_status='in_delivery'`) | `delivery`, `driver_app` | driver_id, course_id, lat/lng |
| `order.delivered` | Driver dostarczył (`delivery_status='delivered'`) | `courses`, `driver_app` | course_id, driver_id |
| `order.completed` | Zamówienie zamknięte (`status='completed'`) | `courses`, `pos`, `payments` | from_status, to_status |
| `order.cancelled` | Anulowane (`status='cancelled'`) | `courses`, `pos`, `admin` | reason, actor_id |
| `order.edited` | Edytowane po przyjęciu (kuchnia już wie) | `pos` | kitchen_changes |
| `order.recalled` | KDS rollback (`ready → preparing`) | `kds` | actor_id (kucharz) |
| `payment.settled` | Płatność zaksięgowana | `payments` | method, amount, reference |
| `payment.refunded` | Zwrot wykonany | `payments` | amount, reason |

### 3.1. Kontrakt payloadu

Każdy event dostaje pełny snapshot aggregatu (order header + lines + context). Worker **NIE dociąga danych z DB** w momencie dispatchu — payload jest as-of-publish-time.

```json
{
  "id": "uuid-order-id",
  "tenant_id": 1,
  "order_number": "WWW/20260418/0042",
  "channel": "Delivery",
  "order_type": "delivery",
  "source": "WWW",
  "status": "new",
  "payment_status": "unpaid",
  "payment_method": "cash_on_delivery",
  "subtotal": 4200,
  "discount_amount": 0,
  "delivery_fee": 500,
  "grand_total": 4700,
  "customer_name": "Jan Kowalski",
  "customer_phone": "+48500123456",
  "delivery_address": "Warszawa, Marszałkowska 1",
  "lat": 52.2297,
  "lng": 21.0122,
  "promised_time": "2026-04-18 19:30:00",
  "delivery_status": null,
  "driver_id": null,
  "tracking_token": "a1b2c3d4e5f6g7h8",
  "created_at": "2026-04-18 18:55:14",
  "lines": [
    {
      "id": "uuid-line-id",
      "item_sku": "PIZZA_MARGHERITA",
      "snapshot_name": "Pizza Margherita",
      "unit_price": 2500,
      "quantity": 1,
      "line_total": 2500,
      "vat_rate": 5.00,
      "vat_amount": 119,
      "modifiers": [{"ascii_key":"EXTRA_CHEESE","name":"Ekstra ser"}],
      "removed_ingredients": [],
      "comment": null
    }
  ],
  "_context": {
    "payment_method": "cash_on_delivery",
    "lock_token": "..."
  },
  "_meta": {
    "event_type": "order.created",
    "published_at": "2026-04-18T18:55:14+02:00",
    "contract_version": 1
  }
}
```

---

## 4. Idempotency

Każdy event ma `idempotency_key` = `{aggregate_id}:{event_type}:[status_transition?]`.

**UNIQUE (tenant_id, idempotency_key)** — drugi `publish()` z tym samym kluczem jest no-opem (INSERT IGNORE).

Przykłady:
- `guest_checkout` publikuje `{orderId}:order.created` — retry checkoutu nie duplikuje eventu.
- `bump_order new→accepted` publikuje `{orderId}:order.accepted:new` — podwójne kliknięcie „Przyjmij" nie generuje dwóch notyfikacji.
- `order.recalled` używa timestampu w kluczu (`:order.recalled:20260418185514`) — każde wywołanie jest unikalne (bo to sytuacja awaryjna).

---

## 5. Webhooks (sh_webhook_endpoints)

Manager tenanta konfiguruje webhooki w panelu Settings (UI: Sesja 7.3):

```sql
INSERT INTO sh_webhook_endpoints (tenant_id, name, url, secret, events_subscribed) VALUES
  (1, 'Papu sync',          'https://api.papu.io/v1/slicehub-hook',    '<secret-32b>', '["order.created","order.ready","order.completed"]'),
  (1, 'Analytics firehose', 'https://analytics.example.com/slicehub',   '<secret-32b>', '["*"]'),
  (1, 'Slack ops',          'https://hooks.slack.com/services/...',     '<secret-32b>', '["order.cancelled"]');
```

### 5.1. HMAC signature

Worker przy każdym POST dodaje header:

```
X-Slicehub-Signature: sha256=<hmac(payload, secret)>
X-Slicehub-Event-Id: 1234
X-Slicehub-Event-Type: order.created
X-Slicehub-Delivery-Id: <uuid>
X-Slicehub-Timestamp: 2026-04-18T18:55:14+02:00
```

Subskrybent weryfikuje `X-Slicehub-Signature` przed parsowaniem payloadu.

### 5.2. Retry

- Max prób: `sh_webhook_endpoints.max_retries` (default 5)
- Backoff: exponential `min(2^attempt, 3600)s` (1s, 2s, 4s, 8s, 16s, ..., max 1h)
- Po przekroczeniu → `sh_event_outbox.status='dead'` + error_log
- Endpoint `consecutive_failures >= max_retries * 3` → auto-disable (wymaga manual re-enable)

---

## 6. Integration Registry (sh_tenant_integrations)

Warstwa **ponad webhookami** — dla POS/ERP systems które nie mają standardowego webhook API (wymagają specjalnego formatu, auth flow itp.).

Przykład: Papu.io nie akceptuje zwykłego JSONa z SliceHuba — wymaga własnej struktury (`transformPayload`). Zamiast robić webhook → adapter używa adaptera bezpośrednio.

```sql
INSERT INTO sh_tenant_integrations
    (tenant_id, provider, display_name, api_base_url, credentials, direction, events_bridged)
VALUES
    (1, 'papu', 'Papu.io POS',
     'https://api.papu.io/v1',
     '{"api_key":"enc:AES256:..."}',
     'push',
     '["order.created","order.cancelled"]');
```

Worker `worker_integrations.php` (Sesja 7.4):
1. Znajduje providers dla tenanta
2. Dla każdego providera ładuje adapter z `core/Integrations/{Provider}Adapter.php`
3. Przekazuje event + decrypted credentials → adapter sam decyduje jak to wysłać

---

## 7. Dla developerów — jak publikować event?

### 7.1. Z API endpoint (zalecane)

```php
require_once __DIR__ . '/../../core/OrderEventPublisher.php';

// W TEJ SAMEJ transakcji co INSERT/UPDATE sh_orders
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO sh_orders ...");
    $pdo->exec("INSERT INTO sh_order_audit ...");

    OrderEventPublisher::publishOrderLifecycle(
        $pdo,
        $tenantId,
        'order.created',
        $orderId,
        ['payment_method' => 'cash_on_delivery'],
        [
            'source'    => 'online',
            'actorType' => 'guest',
            'actorId'   => $customerPhone,
        ]
    );

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

### 7.2. Low-level (gdy masz już payload)

```php
OrderEventPublisher::publish(
    $pdo,
    $tenantId,
    'payment.settled',
    $orderId,
    [
        'method'    => 'card',
        'amount'    => 5000,
        'reference' => 'TXN-123',
    ],
    ['source' => 'payments', 'actorType' => 'system']
);
```

---

## 8. Feature detection / graceful degradation

`OrderEventPublisher::ensureOutboxAvailable()` robi `SELECT 1 FROM sh_event_outbox LIMIT 0` raz per request. Jeśli tabela nie istnieje (stara instalacja bez migracji 026), klasa:
- loguje error_log raz
- cache'uje flagę `false`
- każde kolejne `publish()` jest no-opem

**Efekt:** wszystkie API endpoints które dodały `publish()` nadal działają bez migracji 026 — po prostu nie emitują eventów.

---

## 9. Roadmapa (Sesje 7.x)

| Sesja | Co | Status |
|---|---|---|
| **7.1** | Migracja 026, `OrderEventPublisher`, publish() w 7 endpointach | ✅ (ta sesja) |
| 7.2 | `api/gateway/intake.php` v2 — normalize payload z różnych źródeł, rate limiting, external_id idempotency | pending |
| 7.3 | `scripts/worker_webhooks.php` — cron dispatcher, HMAC signing, retry + backoff | pending |
| 7.4 | `core/Integrations/` adapter pattern — PapuAdapter (rozszerzenie), DotykackaAdapter, GastroSoftAdapter | pending |
| 7.5 | UI Settings → Integrations — manager configures webhooks + integrations w panelu | pending |
| 7.6 | Internal consumers — SMS notifications, live dashboard feed (SSE), analytics warehouse sync | pending |

---

## 10. Kontrakt wersjonowany

`payload._meta.contract_version: 1` — każdy event ma wersję kontraktu. Subskrybenci (webhook endpoints, integration adapters) powinni deklarować `supported_versions` i odrzucać eventy z nieznanym kontraktem.

Breaking changes → bump wersji + okres przejściowy dual-publish (2 wersje równolegle przez N dni).

---

## 11. Debugowanie

### 11.1. Sprawdź pending events

```sql
SELECT id, tenant_id, event_type, aggregate_id, status, attempts, created_at
FROM sh_event_outbox
WHERE status IN ('pending','dispatching','failed')
ORDER BY id DESC
LIMIT 50;
```

### 11.2. Historia eventów per zamówienie

```sql
SELECT event_type, source, status, created_at, completed_at, last_error
FROM sh_event_outbox
WHERE aggregate_id = '<uuid>' AND tenant_id = 1
ORDER BY created_at ASC;
```

### 11.3. Webhook delivery log

```sql
SELECT d.attempt_number, d.http_code, d.error_message, d.duration_ms, e.event_type
FROM sh_webhook_deliveries d
JOIN sh_event_outbox e ON e.id = d.event_id
WHERE d.endpoint_id = ?
ORDER BY d.id DESC LIMIT 100;
```

### 11.4. Force re-publish (ręcznie w MySQL)

```sql
UPDATE sh_event_outbox
SET status='pending', attempts=0, next_attempt_at=NULL, last_error=NULL
WHERE id=?;
```

---

## 12. Testowanie w dev

Po uruchomieniu `scripts/setup_database.php` migracja 026 się wykona. Wtedy:

1. Złóż zamówienie przez storefront (`modules/online/index.html`) albo POS (`modules/pos/index.html`).
2. Sprawdź `SELECT * FROM sh_event_outbox ORDER BY id DESC LIMIT 10;`.
3. Bump order w KDS — każdy bump tworzy nowy event.
4. Do momentu uruchomienia worker'a (Sesja 7.3) wszystkie eventy zostają w status='pending' — to oczekiwane zachowanie.
