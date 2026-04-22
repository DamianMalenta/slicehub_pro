# 12. Integration Adapters (m028, Sesja 7.4)

## Po co to jest

SliceHub publikuje kanoniczne eventy zamówień (`order.created`, `order.accepted`, …) do `sh_event_outbox` (m026). **Webhook Dispatcher** (Sesja 7.3) rozsyła je jako generyczny HTTP POST do skonfigurowanych URL-i. Ale realnie restauracje mają **konkretne systemy POS** (Papu, Dotykacka, GastroSoft, Bitrix, CHIP, …) — każdy z własnym formatem payloadu, własną autentykacją, własną semantyką response'u.

**Integration Adapters** to warstwa konkretnych providerów:

- **Nie** generyczny HTTP — mapowanie per-provider (payload shape, HTTP method, auth header).
- **Nie** osobny outbox — te same eventy co webhooki, ale z niezależnym stanem (własne retry/DLQ).
- **Nie** wymusza koordynacji z webhookami — dwa workery biegną równolegle.

## Architektura

```
sh_event_outbox (m026)
   │
   ├─► worker_webhooks.php  ──► sh_webhook_endpoints  ──► generyczny HTTP  ──► sh_webhook_deliveries
   │
   └─► worker_integrations.php ──► AdapterRegistry ──► PapuAdapter      ──► https://api.papu.io
                                                   ├── DotykackaAdapter ──► https://api.dotykacka.cz
                                                   └── GastroSoftAdapter ──► https://api.gastrosoft.pl
                                                                   │
                                                                   └──► sh_integration_deliveries + sh_integration_attempts
```

## Pliki

| Plik                                                 | Rola |
| ---                                                  | --- |
| `database/migrations/028_integration_deliveries.sql` | Schema (`sh_integration_deliveries`, `sh_integration_attempts` + health columns na `sh_tenant_integrations`) |
| `core/Integrations/BaseAdapter.php`                  | Abstrakcyjny kontrakt + helpers (credentials, grosze→PLN, payload extract) |
| `core/Integrations/AdapterRegistry.php`              | Mapowanie `provider` → klasa adaptera + cache per tenant |
| `core/Integrations/PapuAdapter.php`                  | Papu.io POS (Bearer + opcjonalny HMAC) |
| `core/Integrations/DotykackaAdapter.php`             | Dotykačka POS Cloud (OAuth2 Bearer) |
| `core/Integrations/GastroSoftAdapter.php`            | GastroSoft (X-Api-Key) |
| `core/Integrations/IntegrationDispatcher.php`        | Konsument outboxa + zarządzanie retries/DLQ |
| `scripts/worker_integrations.php`                    | CLI runner (cron / systemd / dry-run) |

## Schemat DB (m028)

### `sh_integration_deliveries`

Per (event × integration) — jeden rekord przechowujący **bieżący stan**:

```sql
id, tenant_id, event_id (FK sh_event_outbox), integration_id (FK sh_tenant_integrations),
provider, aggregate_id, event_type,
status ENUM('pending','delivering','delivered','failed','dead'),
attempts, next_attempt_at, last_error,
http_code, duration_ms, external_ref,
request_payload JSON, response_body TEXT,
created_at, last_attempted_at, completed_at,
UNIQUE (event_id, integration_id)
```

### `sh_integration_attempts`

Full historia prób (1 rekord = 1 HTTP request):

```sql
id, delivery_id (FK), attempt_number, http_code, duration_ms,
request_snippet (500B), response_body (2KB), error_message, attempted_at
```

### `sh_tenant_integrations` — dodane (additive, idempotentne)

```sql
consecutive_failures INT UNSIGNED DEFAULT 0,
last_failure_at      DATETIME     NULL,
max_retries          TINYINT      DEFAULT 6,
timeout_seconds      TINYINT      DEFAULT 8
```

## Flow pojedynczej dostawy

1. **Worker batch query** — wybiera eventy z `sh_event_outbox` z ostatnich 24h dla tenantów z aktywnymi integracjami.
2. **`AdapterRegistry::resolveForTenant()`** — ładuje aktywne rekordy z `sh_tenant_integrations` i mapuje `provider` → konkretna klasa.
3. **Per adapter:** `supportsEvent($eventType)` (sprawdza `events_bridged` JSON).
4. **Delivery row lookup** — `sh_integration_deliveries WHERE (event_id, integration_id)`. Jeśli `delivered`/`dead` → skip. Jeśli `pending` z przyszłym `next_attempt_at` → skip.
5. **`$adapter->buildRequest($envelope)`** — zwraca `[method, url, headers, body]`. Może rzucić `AdapterException` → natychmiast `dead` (permanent).
6. **HTTP call przez injectowany transport** (cURL domyślnie, testowalny).
7. **`$adapter->parseResponse($httpCode, $body, $transportError)`** — zwraca `['ok', 'transient', 'externalRef', 'error']`.
8. **Update stanu:**
   - `ok=true` → `delivered` + `external_ref` + reset `consecutive_failures`.
   - `ok=false, transient=true, attempts<max` → `pending` + next_attempt_at z backoffu.
   - `ok=false, permanent LUB attempts>=max` → `dead` + bump `consecutive_failures` (auto-pause jeśli >= max_retries).
9. **Log do `sh_integration_attempts`** — zawsze, również przy DLQ.

## Exponential backoff

Analog do webhooków (m026) ale z lżejszą eskalacją dla POS'ów (POS-y bywają wolne, nie spamujemy 30s interwałem długo):

| Attempt | Opóźnienie |
| ---     | --- |
| 1       | 30 s |
| 2       | 2 min |
| 3       | 10 min |
| 4       | 30 min |
| 5       | 2 h |
| 6       | 6 h |
| 7+      | 24 h (DLQ check) |

## Auto-pause integracji

Gdy `consecutive_failures >= max_retries` dispatcher ustawia `sh_tenant_integrations.is_active = 0`. Integracja przestaje być ładowana przez `AdapterRegistry`. Restart wymaga manual UI re-activation (Sesja 7.5).

## Adapter — jak dodać nowy

1. Napisz klasę w `core/Integrations/XYZAdapter.php` dziedziczącą `BaseAdapter`.
2. Zaimplementuj `providerKey()`, `displayName()`, `buildRequest()`. Opcjonalnie nadpisz `parseResponse()` jeśli provider ma custom semantykę.
3. Dodaj wpis do `AdapterRegistry::PROVIDER_MAP`.
4. Dodaj require w `scripts/worker_integrations.php`.
5. Testy: dry-run + rzeczywisty payload z sandbox.

### Minimalny szablon

```php
<?php
namespace SliceHub\Integrations;

final class MyPosAdapter extends BaseAdapter
{
    public static function providerKey(): string { return 'mypos'; }
    public static function displayName(): string { return 'My POS Cloud'; }

    public function buildRequest(array $envelope): array
    {
        $apiKey = $this->requireCredential('api_key');
        $order  = $this->extractOrderSnapshot($envelope);
        $lines  = $this->extractOrderLines($envelope);

        $payload = [ /* provider-specific shape */ ];

        return [
            'method'  => 'POST',
            'url'     => $this->apiBaseUrl() . '/orders',
            'headers' => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }
}
```

## Credentials shape per provider

### Papu

```json
{
  "api_key":     "pk_live_xxx",
  "api_secret":  "whk_xxx",      // optional — włącza HMAC X-Papu-Signature
  "tenant_ext":  "restaurant_42" // optional — multi-tenant routing na Papu
}
```

### Dotykacka

```json
{
  "client_id":     "xxx",
  "refresh_token": "eyJ...",
  "cloud_id":      "12345",
  "branch_id":     "67890",      // optional
  "access_token":  "eyJ...",     // auto-cache (MVP: unused, 7.5: persistent)
  "access_token_expires_at": "2026-04-18T14:25:00Z"
}
```

### GastroSoft

```json
{
  "api_key":         "gs_live_xxx",
  "restaurant_code": "ABC123",
  "terminal_id":     "POS-01"    // optional
}
```

## CLI

```bash
# Single batch (cron)
php scripts/worker_integrations.php

# Loop (systemd)
php scripts/worker_integrations.php --loop --sleep=10 --batch=50

# Dry-run (no HTTP, fake 200 response)
php scripts/worker_integrations.php --dry-run -v

# Stop after N batches (load testing)
php scripts/worker_integrations.php --loop --max-batches=5 -v

# Help
php scripts/worker_integrations.php --help
```

Exit codes: `0` OK · `1` DB/boot · `2` PID lock · `3` runtime exception.

## Cron / systemd

### cron (every 2 min)

```cron
*/2 * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_integrations.php >> logs/integrations.log 2>&1
```

### systemd (continuous)

```ini
[Unit]
Description=SliceHub Integration Worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/slicehub
ExecStart=/usr/bin/php scripts/worker_integrations.php --loop --sleep=10 -v
Restart=always
RestartSec=5
StandardOutput=append:/var/log/slicehub/integrations.log
StandardError=append:/var/log/slicehub/integrations.log

[Install]
WantedBy=multi-user.target
```

## Monitoring (SQL)

Eventy czekające w kolejce:

```sql
SELECT provider, status, COUNT(*) AS n
FROM sh_integration_deliveries
GROUP BY provider, status
ORDER BY provider, status;
```

Dead letters (wymagają ręcznej interwencji):

```sql
SELECT id, provider, event_type, aggregate_id, attempts, last_error, created_at
FROM sh_integration_deliveries
WHERE status = 'dead'
ORDER BY created_at DESC
LIMIT 50;
```

Ostatnie próby dla konkretnego eventu:

```sql
SELECT a.attempt_number, a.http_code, a.duration_ms, a.error_message, a.attempted_at
FROM sh_integration_attempts a
JOIN sh_integration_deliveries d ON d.id = a.delivery_id
WHERE d.aggregate_id = 'ORDER-UUID-HERE'
ORDER BY a.attempted_at DESC;
```

Health integracji (consecutive failures):

```sql
SELECT id, tenant_id, provider, display_name, is_active,
       consecutive_failures, max_retries, last_sync_at, last_failure_at
FROM sh_tenant_integrations
ORDER BY consecutive_failures DESC, tenant_id;
```

## Decyzje projektowe

1. **Niezależny state per-consumer** — integracje nie modyfikują `sh_event_outbox.status`. To eliminuje race z webhook workerem i pozwala skalować workery niezależnie (można mieć 3 instancje integration worker + 1 webhook worker).
2. **Candidate query bez atomic claim** — w przeciwieństwie do WebhookDispatcher integration worker polega na `UNIQUE(event_id, integration_id)` jako zabezpieczeniu przed duplikatami; dwóch workerów próbujących jednocześnie jedną prob zakończy się violation + silent retry. Jeśli w przyszłości to będzie hot-path, dodamy `FOR UPDATE SKIP LOCKED`.
3. **External ref store** — `sh_integration_deliveries.external_ref` trzyma ID po stronie 3rd-party po udanym `order.created`. Adaptery update-status (np. DotykackaAdapter dla `order.ready`) mogą go pobrać do routingu HTTP (`PATCH /documents/{extRef}`). W MVP DotykackaAdapter wyciąga z `gateway_external_id` albo z payload context — pełen flow (worker ładuje `external_ref` z poprzedniej dostawy) jest w roadmap 7.5.
4. **Timeout_seconds per integracja** — POS-y bywają wolne (8s default vs 5s webhook). Konfigurowalne per integracja.
5. **Legacy `PapuClient.php` pozostaje** — fire-and-forget z POS finalize. Nowe adaptery **uzupełniają**, nie zastępują. Gdy event-driven flow złapie production, legacy fire-and-forget można usunąć (deprecation w 7.6+).

## Roadmap

- **7.5:** Settings UI (restauracja aktywuje integrację, wkleja credentials, testuje "Ping"). Credentials encrypted at rest (libsodium).
- **7.6:** Dotykacka pełny OAuth2 flow z persistent `access_token` cache w DB + background refresh task.
- **7.7:** Reverse direction (`direction='pull'` / `'bidirectional'`) — webhook endpointy dla Papu/Dotykacka pushujących zmiany z ich strony (np. "order ready" odpalony w POS lokalu, zwrotnie do nas).
- **7.8:** DLQ replay UI (admin widzi dead deliveries, wybiera, retry z nowymi credentials).
- **7.9:** Adaptery delivery aggregators (Uber Eats API, Glovo API, Pyszne.pl API) z direction='bidirectional'.
