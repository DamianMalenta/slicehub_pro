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

---

## 13. Cron / worker deployment (PROD)

> **Dopisane w Hotfix 7.6.1 (W-2).** Wcześniej workery istniały, ale nie było jednego miejsca, które mówi **jak je uruchomić na świeżym deployu**. Bez crona / systemd eventy gniją w `sh_event_outbox` (status='pending' na zawsze) → KDS nie dostanie ticketów, 3rd-party nie dostanie pushów, klient nie dostanie SMSa.

### 13.1. Przegląd workerów

| Worker | Co konsumuje | Co robi | Zalecana częstotliwość |
|---|---|---|---|
| `scripts/worker_webhooks.php` | `sh_event_outbox` | HMAC-signed HTTP POST do `sh_webhook_endpoints` (generic subskrybenci) | co 1 min (cron) lub `--loop --sleep=5` (systemd) |
| `scripts/worker_integrations.php` | `sh_event_outbox` | dispatch do adapterów 3rd-party (Papu/Dotykacka/GastroSoft) | co 1 min lub `--loop --sleep=10` |
| `scripts/worker_notifications.php` | `sh_notification_deliveries` | SMS/email/push (m033 Notification Director) | co 1 min lub `--loop --sleep=5` |

Wszystkie trzy wspierają ten sam zestaw flag:

```
--loop           continuous loop (exit tylko na SIGTERM/SIGINT)
--sleep=N        sekundy między batchami w loop mode (default: 5–10)
--batch=N        max eventów/rekordów per batch (default: 50)
-v               verbose (debug output)
--dry-run        symulacja bez HTTP / SMS / push
```

Wszystkie mają **PID-file lock** → dwóch równoległych instancji tego samego workera się nie uruchomi (patrz exit code `2`). Dzięki temu można bezpiecznie wpiąć do crona co minutę + trzymać loop w systemd — kolizja nie zdupluje dispatchu.

### 13.2. Wymagane zmienne środowiskowe

CLI nie ma sesji PHP, więc konfiguracja musi być w ENV albo `.env` ładowanym przez `db_config.php`:

```
# Encryption key dla CredentialVault (XChaCha20-Poly1305). 32 bajty, base64.
# Wygeneruj raz: php scripts/bootstrap_vault.php
SLICEHUB_VAULT_KEY=<base64-32-bytes>

# DB (standard)
SLICEHUB_DB_HOST=localhost
SLICEHUB_DB_NAME=slicehub_pro_v2
SLICEHUB_DB_USER=slicehub
SLICEHUB_DB_PASS=<secret>
```

**Bez `SLICEHUB_VAULT_KEY`** workery wystartują, ale:
- `worker_integrations.php` nie rozszyfruje credentials → adaptery polecą w 401/403 z providerem
- `worker_webhooks.php` nie odszyfruje `sh_webhook_endpoints.secret` → HMAC będzie liczony na placeholderze → subskrybent odrzuci signature.

Panel Settings pokaże `vault_ready: false` (badge „PLAINTEXT" w topbarze) + banner „X plaintext credentials — uruchom rotate_credentials_to_vault.php" (tab Health / boot).

### 13.3. Wariant A — crontab (minimalny deploy)

Najprostsze, działa na każdym hostingu z PHP CLI. Plik `/etc/cron.d/slicehub`:

```cron
# SliceHub — event workers (co 1 min)
# User = właściciel katalogu (nie root!). SHELL/PATH konieczne dla composera/PHP.
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin

# Outbound webhooks (sh_event_outbox → sh_webhook_endpoints)
*  *  *  *  *  slicehub  cd /var/www/slicehub && /usr/bin/php scripts/worker_webhooks.php     >> logs/webhooks.log 2>&1

# Integrations (sh_event_outbox → 3rd-party adapters)
*  *  *  *  *  slicehub  cd /var/www/slicehub && /usr/bin/php scripts/worker_integrations.php >> logs/integrations.log 2>&1

# Notifications (m033: sh_notification_deliveries → SMS/email/push)
*  *  *  *  *  slicehub  cd /var/www/slicehub && /usr/bin/php scripts/worker_notifications.php >> logs/notifications.log 2>&1

# Rotacja kredków ciszej (raz dziennie check, runner robi dry-run bez flagi --apply)
17 3  *  *  *  slicehub  cd /var/www/slicehub && /usr/bin/php scripts/rotate_credentials_to_vault.php --dry-run >> logs/rotate-check.log 2>&1
```

> **Uwaga:** `logs/` musi istnieć i być writable dla usera `slicehub`. Utwórz raz: `mkdir -p logs && chown slicehub:slicehub logs`.

**Rotacja logów** (`/etc/logrotate.d/slicehub`):

```
/var/www/slicehub/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
```

### 13.4. Wariant B — systemd (rekomendowany PROD)

Lepszy niż cron gdy: chcesz niższe opóźnienie (loop co 5s zamiast 1 min), health-check przez `systemctl status`, automatyczny restart po crashu, structured journald logi.

Trzy unity (jeden per worker) + wspólny drop-in z ENV.

**`/etc/systemd/system/slicehub-webhooks.service`**:

```ini
[Unit]
Description=SliceHub Webhook Worker (sh_event_outbox → HTTP POST)
After=network.target mariadb.service
Wants=mariadb.service

[Service]
Type=simple
User=slicehub
Group=slicehub
WorkingDirectory=/var/www/slicehub
EnvironmentFile=/etc/slicehub/env
ExecStart=/usr/bin/php /var/www/slicehub/scripts/worker_webhooks.php --loop --sleep=5
Restart=always
RestartSec=5
StandardOutput=append:/var/www/slicehub/logs/webhooks.log
StandardError=append:/var/www/slicehub/logs/webhooks.log

# Hardening — worker nie potrzebuje niczego poza katalogiem projektu.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/www/slicehub/logs /var/www/slicehub/tmp

[Install]
WantedBy=multi-user.target
```

**`/etc/systemd/system/slicehub-integrations.service`** (kopia powyższego, zmień `ExecStart` + paths):

```ini
ExecStart=/usr/bin/php /var/www/slicehub/scripts/worker_integrations.php --loop --sleep=10
StandardOutput=append:/var/www/slicehub/logs/integrations.log
StandardError=append:/var/www/slicehub/logs/integrations.log
```

**`/etc/systemd/system/slicehub-notifications.service`** (analogicznie):

```ini
ExecStart=/usr/bin/php /var/www/slicehub/scripts/worker_notifications.php --loop --sleep=5
StandardOutput=append:/var/www/slicehub/logs/notifications.log
StandardError=append:/var/www/slicehub/logs/notifications.log
```

**`/etc/slicehub/env`** (0600, owner=root):

```
SLICEHUB_VAULT_KEY=<base64-32-bytes>
SLICEHUB_DB_HOST=localhost
SLICEHUB_DB_NAME=slicehub_pro_v2
SLICEHUB_DB_USER=slicehub
SLICEHUB_DB_PASS=<secret>
```

**Aktywacja:**

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now slicehub-webhooks slicehub-integrations slicehub-notifications
sudo systemctl status slicehub-webhooks
journalctl -u slicehub-webhooks -f
```

### 13.5. Health monitoring

Po uruchomieniu weryfikuj raz dziennie (albo w Settings → Health):

```sql
-- Nie powinno rosnąć bez kontroli — jeśli rośnie, worker nie biegnie.
SELECT status, COUNT(*) FROM sh_event_outbox
WHERE created_at > NOW() - INTERVAL 1 HOUR
GROUP BY status;
-- Oczekiwane: delivered >> pending, dead = 0 lub stałe.
```

```bash
# Żywotność workera (systemd):
systemctl is-active slicehub-webhooks slicehub-integrations slicehub-notifications
# Powinno zwrócić 3x "active".
```

Settings Panel → tab **Health** pokazuje:
- `Outbox Events (7d)` — rozbicie per status (`delivered`/`failed`/`dead`/`pending`)
- `Inbound Callbacks (24h)` — liczniki + `bad_signature_count` (atak / zła konfig)
- `Credential Vault` — badge `Ready` / `Disabled`

### 13.6. Troubleshooting

| Objaw | Przyczyna | Fix |
|---|---|---|
| `sh_event_outbox.status='pending'` rośnie, nic się nie dispatchuje | Worker nie biegnie | `systemctl status slicehub-webhooks` lub sprawdź cron log |
| `sh_webhook_deliveries.http_code=401` u wszystkich subskrybentów | `SLICEHUB_VAULT_KEY` nie ustawiony → HMAC liczony na placeholderze | Ustaw ENV + restart workera |
| `worker_webhooks` exit code `2` w logu | Inna instancja workera ciągle biegnie (PID-lock) | Normalne przy cronie co minutę + loop w systemd — wybierz **jedno** źródło uruchamiania |
| Banner „X plaintext credentials" w panelu | W DB są integracje/webhooki zapisane zanim vault był dostępny | `php scripts/rotate_credentials_to_vault.php --apply` (po dry-run) |
| Tab Inbound pusty, `table_ready: false` | Migracja `029_infrastructure_completion.sql` niezaaplikowana | `php scripts/apply_migrations_chain.php` |
