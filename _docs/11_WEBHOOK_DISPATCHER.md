# 11. Webhook Dispatcher — async delivery z HMAC + exponential backoff

> **Status:** ✅ Faza 7 · Sesja 7.3 (2026-04-18)
> **Core:** `core/WebhookDispatcher.php`
> **CLI:** `scripts/worker_webhooks.php`
> **Tabele (m026):** `sh_event_outbox`, `sh_webhook_endpoints`, `sh_webhook_deliveries`

---

## 1. Miejsce w architekturze

```
┌──────────────────────┐
│ Producers (m026)     │
│  - POS engine        │
│  - Online checkout   │
│  - Gateway intake    │
│  - KDS bump/recall   │
│  - Delivery dispatch │
│  - Courses engine    │
└──────────┬───────────┘
           │ publishOrderLifecycle()  (w tej samej TX co sh_orders)
           ▼
    ┌──────────────┐
    │ sh_event_outbox   status=pending
    └──────┬───────┘
           │ (cron poll)
           ▼
┌───────────────────────────────────┐
│ scripts/worker_webhooks.php       │
│   └─► core/WebhookDispatcher.php  │
│         1. claim batch (atomic)   │
│         2. find subscribers       │
│         3. sign HMAC + POST       │
│         4. log delivery           │
│         5. mark delivered/failed  │
└───────────┬───────────────────────┘
            │
            ▼
┌──────────────────────────────┐
│ sh_webhook_endpoints         │
│   (Papu, Slack, analytics,   │
│    own integration)          │
└──────────────────────────────┘
```

---

## 2. Co robi worker

### 2.1. Claim pending events (atomic race-safe)

```sql
-- krok 1: wybierz kandydatów
SELECT id, tenant_id, event_type, payload, attempts, created_at
FROM sh_event_outbox
WHERE status = 'pending'
  AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
ORDER BY id ASC
LIMIT 50;

-- krok 2: per-row atomic claim
UPDATE sh_event_outbox
SET status = 'dispatching', dispatched_at = NOW()
WHERE id = :id AND status = 'pending';
-- Jeśli rowCount() == 0 → inny worker już przejął, pomijamy.
```

Ten pattern daje **linearyzację FIFO** per kolejność ID-ków i race-safety przy wielu workerach (multi-node lub race z PID lockiem pominiętym).

### 2.2. Match subscribers

```sql
SELECT id, name, url, secret, events_subscribed, max_retries, timeout_seconds, consecutive_failures
FROM sh_webhook_endpoints
WHERE tenant_id = :tid AND is_active = 1;
```

Następnie w PHP filtr po `events_subscribed` (JSON array) z obsługą wildcard `["*"]` = wszystkie eventy tenanta.

### 2.3. Sign + POST

Każdy subscriber dostaje **ten sam envelope** (żeby re-deliver po błędzie dał bitem identyczny payload), ale nowy podpis (timestamp się zmienia — chroni przed replay).

**Envelope JSON:**

```jsonc
{
  "event_id":       "123",
  "event_type":     "order.created",
  "aggregate_id":   "9f3c-4b2a-…",
  "aggregate_type": "order",
  "tenant_id":      1,
  "source":         "pos",
  "actor_type":     "staff",
  "actor_id":       "42",
  "occurred_at":    "2026-04-18T14:23:11Z",
  "attempt":        2,
  "delivery_id":    1847,
  "payload": {
    "_context": { ... per-source context ... },
    "_meta":    { ... },
    "order": {
      "id":           "9f3c-4b2a-…",
      "order_number": "POS/20260418/0042",
      "status":       "new",
      "channel":      "Delivery",
      "order_type":   "delivery",
      "grand_total_grosze": 4700,
      "lines": [ { ... snapshot linii ... } ]
    }
  }
}
```

**HTTP headers:**

```
POST /your/webhook/endpoint HTTP/1.1
Host: subscriber.example.com
Content-Type: application/json; charset=utf-8
User-Agent: SliceHub-Webhooks/1.0
X-Slicehub-Event: order.created
X-Slicehub-Delivery: 1847
X-Slicehub-Signature: t=1713451391,v1=89abcdef0123…
X-Slicehub-Attempt: 2
```

---

## 3. HMAC signing — weryfikacja po stronie subscribera

**Sygnatura:** `HMAC-SHA256(secret, "{timestamp}.{raw_body}")` w formacie:

```
X-Slicehub-Signature: t=1713451391,v1=89abcdef0123...
```

`t=` to UNIX timestamp momentu podpisania (po stronie SliceHub). `v1=` to hex-encoded HMAC-SHA256.

### 3.1. Node.js (Express) — przykład

```javascript
const crypto = require('crypto');

function verifySlicehubSignature(rawBody, header, secret) {
  const parts = Object.fromEntries(
    header.split(',').map(p => p.split('=').map(s => s.trim()))
  );

  const timestamp = parseInt(parts.t, 10);
  const signature = parts.v1;

  // 1. Replay protection — odrzuć jeśli starsze niż 5 min
  if (Math.abs(Date.now() / 1000 - timestamp) > 300) {
    return false;
  }

  // 2. Oblicz expected HMAC
  const base = `${timestamp}.${rawBody}`;
  const expected = crypto.createHmac('sha256', secret).update(base).digest('hex');

  // 3. Timing-safe compare
  return crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
}

app.post('/webhook', express.raw({type: 'application/json'}), (req, res) => {
  const header = req.get('X-Slicehub-Signature') || '';
  const ok = verifySlicehubSignature(req.body.toString('utf8'), header, process.env.SH_SECRET);
  if (!ok) return res.status(401).send('invalid signature');
  // ... handle event
  res.status(200).send('ok');
});
```

### 3.2. PHP — przykład

```php
function verifySlicehubSignature(string $rawBody, string $header, string $secret): bool {
    $parts = [];
    foreach (explode(',', $header) as $pair) {
        [$k, $v] = array_map('trim', explode('=', $pair, 2) + [null, null]);
        if ($k && $v) $parts[$k] = $v;
    }

    $ts  = (int)($parts['t']  ?? 0);
    $sig = (string)($parts['v1'] ?? '');

    if (abs(time() - $ts) > 300) return false;

    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
    return hash_equals($expected, $sig);
}
```

---

## 4. Exponential backoff

Schemat w `WebhookDispatcher::BACKOFF_SCHEDULE`:

| Attempt → | Backoff |
|---|---|
| 1st retry | 30 s |
| 2nd retry | 2 min |
| 3rd retry | 10 min |
| 4th retry | 30 min |
| 5th retry | 2 h |
| 6th retry | 6 h |
| 7th retry | 24 h |

Po `MAX_ATTEMPTS_DEFAULT = 6` próbach event przechodzi w status `dead`.

**Klasyfikacja błędów (transient vs permanent):**

| HTTP | Klasyfikacja | Akcja |
|---|---|---|
| 2xx | success | status=delivered |
| 408, 429, 5xx, 0 (timeout/DNS) | **transient** | retry z backoffem |
| 3xx, 4xx inne niż 408/429 | **permanent** | straight to `dead` |

---

## 5. Dead Letter Queue

Event oznaczony jako `status='dead'` **nie jest retried** przez workera. Decyzja po stronie managera:
- Ręczne wznowienie: `UPDATE sh_event_outbox SET status='pending', attempts=0, next_attempt_at=NULL WHERE id=?`
- Analiza: `SELECT * FROM sh_event_outbox WHERE status='dead' ORDER BY completed_at DESC`

Sesja 7.5 doda UI dla DLQ (manager widzi dead eventy, może replay lub delete).

---

## 6. Auto-pause subskrybenta

Gdy `sh_webhook_endpoints.consecutive_failures >= max_retries` (default 5), endpoint jest **auto-paused**:

```sql
UPDATE sh_webhook_endpoints
SET is_active = CASE
      WHEN consecutive_failures + 1 >= max_retries THEN 0
      ELSE is_active
    END,
    consecutive_failures = consecutive_failures + 1,
    last_failure_at = NOW()
WHERE id = :id;
```

Powoduje to, że worker pomija tego subscribera w kolejnych batchach. Manager musi ręcznie reaktywować (`is_active=1`) po naprawieniu odbiornika.

Reset: gdy endpoint odpowie 2xx → `consecutive_failures = 0` i `last_success_at = NOW()`.

---

## 7. Isolation: 1 failed endpoint nie blokuje innych

Dla eventu z N subskrybentami:
- Jeden zwrot 500 → endpoint A bumpnięty do retry, pozostali dostali 2xx.
- Event jako **całość** jest retry'owany tylko dopóki choć jeden subscriber nie dał 2xx.
- Po retrze envelope idzie do **wszystkich** (at-least-once), ale subscriberzy idempotency obsługują po `event_id`.

> **TODO (post-7.3):** per-delivery retry state (obecnie retryujemy cały event, nie per endpoint). Wystarczające dla MVP.

---

## 8. CLI — `scripts/worker_webhooks.php`

### 8.1. Tryby uruchomienia

```bash
# Jeden batch (cron-friendly) — po przetworzeniu batcha worker kończy.
php scripts/worker_webhooks.php

# Continuous loop (systemd / docker) — działa aż do SIGTERM.
php scripts/worker_webhooks.php --loop --sleep=5

# Dry-run — bez prawdziwych HTTP requestów (debug).
php scripts/worker_webhooks.php --dry-run -v

# Custom batch size.
php scripts/worker_webhooks.php --batch=100 -v
```

### 8.2. Cron setup (Linux)

```cron
# Co minutę — jeden batch po 50 eventów.
* * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_webhooks.php >> logs/webhooks.log 2>&1
```

### 8.3. Systemd unit (continuous mode)

```ini
[Unit]
Description=SliceHub Webhook Dispatcher
After=mysqld.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/slicehub
ExecStart=/usr/bin/php scripts/worker_webhooks.php --loop --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### 8.4. PID-lock

Worker trzyma `logs/worker_webhooks.pid` z `flock(LOCK_EX|LOCK_NB)`. Drugi instance na tym samym node exituje z kodem `2`. W multi-node (Kubernetes / Docker Swarm) DB-level atomic claim gwarantuje że dwa workery nie przetworzą tego samego eventu.

### 8.5. Exit codes

| Kod | Znaczenie |
|---|---|
| 0 | OK |
| 1 | DB/config error (brak `$pdo`, brak outbox table) |
| 2 | Inny worker trzyma PID lock |
| 3 | Uncaught exception w batch processing |

---

## 9. Testy / dry run

```bash
# 1. Utwórz testowego subscribera:
mysql -e "
INSERT INTO sh_webhook_endpoints
  (tenant_id, name, url, secret, events_subscribed, max_retries)
VALUES
  (1, 'test endpoint', 'https://httpbin.org/post', 'test_secret_123',
   '[\"order.created\"]', 3);
"

# 2. Wywołaj guest_checkout (albo POS finalize) — event wyląduje w sh_event_outbox.

# 3. Uruchom worker w verbose:
php scripts/worker_webhooks.php -v

# 4. Sprawdź logi:
SELECT d.id, d.event_id, d.endpoint_id, d.http_code, d.duration_ms, d.error_message, d.attempted_at
FROM sh_webhook_deliveries d
ORDER BY d.id DESC LIMIT 20;

# 5. Sprawdź stan outboxa:
SELECT id, event_type, status, attempts, next_attempt_at, last_error
FROM sh_event_outbox
WHERE tenant_id = 1
ORDER BY id DESC LIMIT 20;
```

### Dry run (bez HTTP)

```bash
php scripts/worker_webhooks.php --dry-run -v
# → wypisze na STDERR body requestu który WYŚLAŁBY,
#   dispatcher zwraca HTTP 200 → event=delivered.
```

---

## 10. Monitoring queries

### 10.1. Health check — ile eventów czeka?

```sql
SELECT status, COUNT(*) AS cnt
FROM sh_event_outbox
WHERE created_at > NOW() - INTERVAL 1 HOUR
GROUP BY status;
```

### 10.2. Top failing endpoints

```sql
SELECT e.id, e.name, e.url, e.consecutive_failures, e.last_failure_at,
       (SELECT COUNT(*) FROM sh_webhook_deliveries d
        WHERE d.endpoint_id = e.id AND d.http_code >= 400
          AND d.attempted_at > NOW() - INTERVAL 1 DAY) AS errors_24h
FROM sh_webhook_endpoints e
WHERE e.tenant_id = 1
ORDER BY errors_24h DESC
LIMIT 10;
```

### 10.3. Dead letter queue

```sql
SELECT id, tenant_id, event_type, aggregate_id, attempts, last_error, completed_at
FROM sh_event_outbox
WHERE status = 'dead'
ORDER BY completed_at DESC
LIMIT 50;
```

### 10.4. Replay dead event

```sql
UPDATE sh_event_outbox
SET status = 'pending', attempts = 0, next_attempt_at = NULL,
    last_error = NULL, completed_at = NULL, dispatched_at = NULL
WHERE id = :id;
```

### 10.5. Throughput (eventy/min)

```sql
SELECT DATE_FORMAT(completed_at, '%Y-%m-%d %H:%i') AS bucket,
       COUNT(*) AS delivered
FROM sh_event_outbox
WHERE status = 'delivered'
  AND completed_at > NOW() - INTERVAL 1 HOUR
GROUP BY bucket
ORDER BY bucket DESC;
```

---

## 11. Decyzje projektowe

1. **At-least-once (nie exactly-once)** — prostsze w implementacji, subscriberzy obsługują idempotency po `event_id` / `aggregate_id + event_type`.
2. **FIFO po `id ASC`** — zamówienia z tego samego `aggregate_id` idą w kolejności (`order.created` → `order.accepted` → `order.preparing` → ...), co ułatwia state machines w subscriberach.
3. **Event-level retry, nie delivery-level** — MVP. Realny edge case to "1 z 5 endpointów ciągle pada" — reszta dostanie duplikaty (bez problemu jeśli są idempotent). Sesja 7.x może dodać per-delivery retry state.
4. **Timestamped HMAC signature** — klasyczny Stripe/Shopify pattern. 5-min replay window.
5. **Transient/permanent error classification** — 4xx (poza 408/429) to dzwonek "subscriber cie nie chce" → dead letter od razu. 5xx/timeouty → retry z backoffem.
6. **PID lock per node + DB atomic claim** — podwójna ochrona. Single-node: PID lock wystarcza. Multi-node: claim atomic wystarcza. Oba: defense in depth.
7. **Response body → max 2KB log** — truncate chroni przed śmieciowym logiem gdy subscriber zwraca HTML error page.
8. **Fail-open na flock** — brak PID file path (np. read-only fs) → worker odmawia startu (exit 1), nie pcha ruchu bez bezpiecznika.

---

## 12. Roadmap

| Sesja | Co | Status |
|---|---|---|
| **7.3** | Webhook Dispatcher + HMAC + backoff + DLQ (ta sesja) | ✅ |
| 7.4 | `core/Integrations/*Adapter.php` — PapuAdapter (rozszerzenie), DotykackaAdapter, GastroSoftAdapter — konsumują eventy i pushują do 3rd-party POS-ów | pending |
| 7.5 | UI Settings → Webhooks + DLQ management (replay, delete, test endpoint) | pending |
| 7.6 | Per-delivery retry state (nie per-event) — fine-grained | pending |
| 7.7 | Webhook signing with rotating secrets + key versioning (v1, v2) | pending |
