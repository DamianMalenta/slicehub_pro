# 14. Inbound Callbacks — 3rd-Party → SliceHub

> **Status:** Faza 7.6 + P2.1 (2026-07-29) — infrastruktura **READY**, adapter dla Papu w pełni zaimplementowany (reference), ChoiceQR w pełni zaimplementowany (P2.1), Dotykacka/GastroSoft **stub** (rzucają `not implemented` dopóki ktoś nie uzupełni `parseInboundCallback`).

## 1. Problem

Integracje POS/delivery są **symetryczne** — my pushujemy eventy do nich, oni pushują statusy do nas:

```
  SliceHub                        Papu / Dotykacka / Uber …
  ─────────                        ─────────────────────────
  order.created  ──────outbound──▶  (zamówienie pojawia się u kuriera)
                                    ↓
                                    kurier akceptuje
                 ◀─────inbound─────  POST /callback  (status=accepted)
                                    ↓
                                    kurier w drodze
                 ◀─────inbound─────  POST /callback  (status=in_delivery)
```

Bez inbound flow system byłby ślepy — KDS/Driver panels nie wiedziałyby, że kurier faktycznie odebrał paczkę.

## 2. Architektura

```
  ┌─────────────────────────────────────────────────────────────────┐
  │ 3rd-party provider (Papu)                                       │
  └─────────────────┬───────────────────────────────────────────────┘
                    │  POST /api/integrations/inbound.php
                    │    ?provider=papu&integration_id=42
                    │  X-Papu-Signature: t=...,v1=<hmac>
                    │  { event_id, order_id, status, ... }
                    ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │ api/integrations/inbound.php                                    │
  │                                                                 │
  │   1. Validate params + method POST                              │
  │   2. ALWAYS INSERT sh_inbound_callbacks (raw_body, raw_headers) │
  │   3. Lookup sh_tenant_integrations[integration_id]              │
  │      → decrypt credentials (CredentialVault)                    │
  │   4. AdapterRegistry → PapuAdapter::parseInboundCallback(...)   │
  │   5. Adapter verifies HMAC signature + parses payload           │
  │      returns {ok, signature_verified, external_ref,             │
  │               event_type, new_status, payload}                  │
  │   6. Idempotency check via UNIQUE(provider, external_event_id)  │
  │   7. Match external_ref → sh_orders.gateway_external_id         │
  │   8. Whitelisted status transition → UPDATE sh_orders           │
  │   9. OrderEventPublisher::publishOrderLifecycle(...)            │
  │      → sh_event_outbox (worker roznosi do KDS / Driver / notif) │
  │  10. UPDATE sh_inbound_callbacks.status = 'processed'           │
  └─────────────────────────────────────────────────────────────────┘
```

## 3. Tabele (m029)

### `sh_inbound_callbacks`

Surowy dziennik wszystkich przychodzących callbacków — persystowany **PRZED** jakąkolwiek walidacją (bad signature też ląduje, żeby było co debugować).

| Kolumna | Typ | Opis |
|---|---|---|
| `id` | BIGINT PK | auto |
| `tenant_id` | INT NULL | Rozpoznane po zmatchowaniu `integration_id` |
| `integration_id` | INT NULL | FK do `sh_tenant_integrations` |
| `provider` | VARCHAR(32) | `papu`, `dotykacka`, `gastrosoft`, `uber`, ... |
| `external_event_id` | VARCHAR(128) NULL | ID eventu u providera (idempotency key) |
| `external_ref` | VARCHAR(128) NULL | ID zamówienia u providera |
| `event_type` | VARCHAR(64) NULL | Zmapowany nasz typ (`order.delivered`) |
| `mapped_order_id` | BIGINT NULL | Nasze `sh_orders.id` po matchingu |
| `raw_headers` | JSON | Wybrane headery (Content-Type, X-*-Signature, User-Agent) |
| `raw_body` | MEDIUMTEXT | Pierwsze 64KB body (truncated powyżej) |
| `signature_verified` | TINYINT | 1 = adapter potwierdził HMAC |
| `status` | ENUM | `pending`\|`processed`\|`rejected`\|`ignored`\|`error` |
| `error_message` | TEXT | Diagnostyka |
| `remote_ip` | VARCHAR(45) | |
| `received_at` | DATETIME | |
| `processed_at` | DATETIME NULL | |

**Idempotency:** `UNIQUE(provider, external_event_id)` — prowider retryuje? drugi callback wraca 200 OK bez re-processingu.

## 4. Adapter kontrakt

```php
abstract class BaseAdapter
{
    // NOWE w 7.6 — opt-in dla adapterów
    public static function supportsInbound(): bool { return false; }

    public function parseInboundCallback(
        string $rawBody,
        array $headers,
        array $credentials
    ): array {
        // Default: rzuca "not implemented"
    }
}
```

### Return shape

```php
[
    'ok' => bool,                      // Czy payload zrozumiany + sig ok
    'signature_verified' => bool,      // Czy HMAC/OAuth sig pasuje
    'external_event_id' => ?string,    // Dla idempotency
    'external_ref' => ?string,         // ID zamówienia u providera
    'event_type' => ?string,           // Nasz: order.accepted/preparing/delivered/...
    'new_status' => ?string,           // Dla UPDATE sh_orders.status
    'new_delivery_status' => ?string,  // Dla UPDATE sh_orders.delivery_status (P2.1)
    'new_payment_status' => ?string,   // Dla UPDATE sh_orders.payment_status (P2.1)
    'payload' => array,                // Dodatkowy kontekst (driver name, eta)
    'error' => ?string,                // Gdy ok=false
]
```

### Reference implementation — PapuAdapter

Implementuje pełny flow HMAC (XChaCha nie wymagany — Papu używa sha256):
1. Parsuje header `X-Papu-Signature: t=<ts>,v1=<hmac>`.
2. Sprawdza replay-window (5 min).
3. Oblicza `hash_hmac('sha256', ts + '.' + body, api_secret)` i `hash_equals` porównuje.
4. Dekoduje JSON body.
5. Mapuje Papu status → nasz event type.

## 5. Status mapping (Papu)

| Papu status | Nasz `event_type` | Nasz `new_status` |
|---|---|---|
| `accepted` | `order.accepted` | `accepted` |
| `preparing` | `order.preparing` | `preparing` |
| `ready_for_pickup` | `order.ready` | `ready` |
| `picked_up` | `order.dispatched` | `dispatched` |
| `in_delivery` | `order.in_delivery` | `in_delivery` |
| `delivered` | `order.delivered` | `delivered` |
| `completed` | `order.completed` | `completed` |
| `cancelled` | `order.cancelled` | `cancelled` |

## 6. Status transition (P2.1 — via OrderStateMachine)

Od P2.1 (2026-07-29) `api/integrations/inbound.php` i `api/integrations/choiceqr/events.php` nie używają hardcoded whitelist — zamiast tego wywołują `OrderStateMachine::transitionOrder()` który:

- Waliduje przejścia według `STRICT_TRANSITIONS` (lub `DINE_IN_TRANSITIONS` dla `order_type='dine_in'`)
- Rozszerza mapę o feature flagi (`skip_kitchen`, `auto_complete`, `skip_dispatch`, `skip_acceptance`)
- Wykonuje `SELECT ... FOR UPDATE` (optimistic locking)
- Zapisuje audit trail do `sh_order_audit` i `sh_order_logs`
- Akceptuje `extraCols` (np. `cancellation_reason`)

Dla `delivery_status` używane jest `OrderStateMachine::canTransitionDelivery()`:
```
unassigned  → in_delivery
in_delivery → delivered
```

`payment_status` nie ma state machine — bezpośredni UPDATE (z `tenant_id` w WHERE).

## 7. URL endpointu dla providerów

```
POST https://<your-host>/api/integrations/inbound.php
     ?provider=<papu|dotykacka|gastrosoft|choiceqr>
     &integration_id=<sh_tenant_integrations.id>

Headers:
  Content-Type: application/json
  X-<Provider>-Signature: <provider-specific HMAC>
```

### Rejestracja u providera

- **Papu:** Ustawić w `Settings → Webhooks → Status Changes` URL powyżej + `api_secret` z creds.
- **ChoiceQR:** Osobne endpointy (nie używa `inbound.php` bezpośrednio — patrz sekcja 13).
- **Dotykacka:** (TODO: uzupełnić gdy ktoś wdroży `parseInboundCallback`)
- **GastroSoft:** (TODO: jw.)

## 8. Testing

### Smoke test (manualny)

```bash
# 1. Zarejestruj integrację w Settings Panel
#    http://localhost/modules/settings/ → tab Integrations → Add
#    Provider: papu, credentials: {"api_key":"test","api_secret":"s3cr3t"}

# 2. Przygotuj payload + sig
BODY='{"event_id":"evt_1","order_id":"ext_42","status":"delivered"}'
TS=$(date +%s)
SIG=$(echo -n "${TS}.${BODY}" | openssl dgst -sha256 -hmac "s3cr3t" | cut -d' ' -f2)

# 3. POST
curl -X POST "http://localhost/api/integrations/inbound.php?provider=papu&integration_id=1" \
     -H "Content-Type: application/json" \
     -H "X-Papu-Signature: t=${TS},v1=${SIG}" \
     -d "$BODY"
```

### Expected responses

| Scenariusz | HTTP | Body |
|---|---|---|
| Poprawny callback + order znaleziony | 200 | `{success:true, order_id, status_changed:true, new_status}` |
| Poprawny callback, duplikat event_id | 200 | `{success:true, duplicate:true, original_callback_id}` |
| Bad signature | 401 | `{success:false, error:"signature verification failed"}` |
| Bad JSON | 422 | `{success:false, error:"body is not valid JSON"}` |
| integration_id nieistniejące | 404 | `{success:false, error:"integration not found"}` |
| Provider bez inbound support | 501 | `{success:false, error:"provider 'X' does not accept inbound callbacks"}` |
| DB/adapter exception | 500 | `{success:false, error:"internal error"}` |

## 9. Debugging

```sql
-- Ostatnie 20 callbacków, ze statusem:
SELECT id, provider, status, signature_verified, event_type,
       external_ref, mapped_order_id, error_message, received_at
FROM sh_inbound_callbacks
WHERE tenant_id = ?
ORDER BY id DESC LIMIT 20;

-- Ile callbacków nie przeszło sig-verify w ostatniej godzinie (potencjalny atak):
SELECT provider, remote_ip, COUNT(*) c
FROM sh_inbound_callbacks
WHERE received_at > NOW() - INTERVAL 1 HOUR
AND signature_verified = 0
GROUP BY provider, remote_ip
ORDER BY c DESC;

-- Zobacz raw body dla failed callbacku:
SELECT raw_headers, raw_body, error_message
FROM sh_inbound_callbacks
WHERE id = ?;
```

## 10. Checklist dla nowego providera

Zanim napiszesz `parseInboundCallback` dla kolejnego providera:

- [ ] Przeczytaj provider docs — jak podpisują requesty (HMAC/OAuth/IP whitelist)?
- [ ] Jaki header niesie signature? (`X-<Provider>-Signature`)
- [ ] Jaka replay-window? (Papu: 5 min)
- [ ] Jaki payload? (struktura JSON)
- [ ] Jakie statusy? (zmapuj na nasze)
- [ ] Override `supportsInbound()` → `true`
- [ ] Override `parseInboundCallback()`
- [ ] Smoke test + dodaj happy path + bad-sig path do manualnych testów

## 11. Nieimplementowane / TODO

- **IP whitelist**: Obecnie nie filtrujemy po IP. Niektórzy providerzy wymuszają tylko swoje rangi IP (Glovo, Wolt). Można dodać kolumnę `allowed_ip_cidrs` w `sh_tenant_integrations`.
- **OAuth2 webhooks** (provider z OAuth-sig zamiast HMAC): infrastruktura gotowa, brak konkretnych implementacji.
- **Webhook subscription management**: Obecnie rejestracja URL u providera jest manualna. Można zautomatyzować: `api/integrations/register_inbound.php?id=N` → adapter wysyła POST do providera z naszym callback URL.
- **Delivery tracker integration**: Wolt/Glovo puszczają `driver.location_update` co 10 sekund — można by logować do `sh_driver_tracking` (Faza 7.7+).
- **Testy E2E ChoiceQR**: Brak testów API symulujących eventy webhookowe ChoiceQR. Do dodania: testy `order.cancelled`, `order.delivery.update`, `order.qrPayment.completed`, `pay.php`.
- **Dotykacka/GastroSoft `parseInboundCallback`**: Stub rzuca `not implemented`. Nie blokuje ChoiceQR.
- **Cron `worker_integrations.php`**: Push statusów SliceHub → ChoiceQR (P1). Wymaga weryfikacji z ChoiceQR API.
- **P2.3 — ChoiceQR compliance fix (PLANOWANE 2026-07-29)**: 3 krytyczne niezgodności z wymogami ChoiceQR (webhook 500 → anulowanie zamówienia, table_orders schema, posID stolika) + 2 weryfikacje live payloadiem (NC1, NC12). Pełny plan: `_docs/integrations/choiceqr_integration.md` sekcja „P2.3". Prompt: `_docs/integrations/choiceqr_prompt_compliance_fix.md`. Backlog (K1/K2/K4/NC3/W4/NC7/NC14-20/W1/W2/S1/S2/S6/S7) celowo poza scope.

## 12. Powiązane pliki

- `api/integrations/inbound.php` — receiver (generic, all providers)
- `api/integrations/choiceqr/events.php` — ChoiceQR webhook events (thin layer → adapter)
- `api/integrations/choiceqr/pay.php` — ChoiceQR QR payment confirmation
- `api/integrations/choiceqr/table_orders.php` — ChoiceQR table orders listing
- `core/Integrations/BaseAdapter.php` — abstract `parseInboundCallback`
- `core/Integrations/PapuAdapter.php` — reference impl (HMAC)
- `core/Integrations/ChoiceQRAdapter.php` — ChoiceQR impl (token auth, event mapping)
- `core/OrderStateMachine.php` — status transitions (used by inbound.php + events.php)
- `core/OrderEventPublisher.php` — publish internal events
- `core/GatewayAuth.php` — idempotency refs (fallback when no `sh_inbound_callbacks`)
- `database/migrations/029_infrastructure_completion.sql` — `sh_inbound_callbacks`

## 13. ChoiceQR — dedykowane endpointy (P2.1)

ChoiceQR ma **odwrócony model** (oni pushują do nas) i używa własnych endpointów zamiast `inbound.php`:

| Endpoint | Metoda | Cel |
|---|---|---|
| `api/integrations/choiceqr/webhook.php` | POST | Odbiór zamówień (P0) |
| `api/integrations/choiceqr/events.php` | POST | Webhook eventy (status, delivery, QR payment, menu) |
| `api/integrations/choiceqr/pay.php` | POST | Potwierdzenie płatności QR przy stoliku |
| `api/integrations/choiceqr/table_orders.php` | GET | Lista zamówień przy stoliku |
| `api/integrations/choiceqr/menu.php` | GET | Export menu |
| `api/integrations/choiceqr/areas.php` | GET | Export stref/stolików |

### Auth

Token w query param: `?t=SECRET_TOKEN`. Tenant mapping przez `varSymbol` → `sh_tenant_integrations`.

### events.php flow (P2.1)

1. Auth: `?t=SECRET_TOKEN` + `hash_equals`
2. Tenant mapping: `varSymbol` → `sh_tenant_integrations`
3. Log do `sh_inbound_callbacks` (feature-detect, fallback `sh_external_order_refs`)
4. Idempotency: `event.id` w `sh_inbound_callbacks`
5. Delegacja: `ChoiceQRAdapter::parseInboundCallback()`
6. Status: `OrderStateMachine::transitionOrder()` (z `SELECT ... FOR UPDATE` w transakcji)
7. Delivery: `OrderStateMachine::canTransitionDelivery()` + UPDATE
8. Payment: bezpośredni UPDATE (z `tenant_id` w WHERE)
9. Publish: `OrderEventPublisher::publishOrderLifecycle()`
10. Response: **200 OK empty body** (ChoiceQR wymaga — brak 200 = anulowanie po 3 retry)

### pay.php flow (P2.1)

1. Auth + tenant mapping (jak events.php)
2. Log do `sh_inbound_callbacks`
3. Idempotency: `payment.id` lub `pay:{orderId}`
4. `SELECT ... FOR UPDATE` w transakcji
5. UPDATE `payment_status` z `tenant_id` w WHERE
6. Walidacja kwoty (log warning przy mismatch, nie odrzuca)
7. Publish: `order.payment_completed`
8. Response: **200 OK empty body**

### Log-only events (200 OK, bez zmian w DB)

- `section.created/changed/removed`, `category.created/changed/removed`, `dish.created/changed/removed`
- `marketplace.acceptance.enabled/disabled`
- `order.qrPayment.error`

### Status mapping (ChoiceQR → SliceHub)

| ChoiceQR event | `event_type` | `new_status` | `new_delivery_status` | `new_payment_status` |
|---|---|---|---|---|
| `order.created` | — (log-only) | — | — | — |
| `order.accepted` | — (log-only) | — | — | — |
| `order.cancelled` | `order.cancelled` | `cancelled` | — | — |
| `order.closed` | `order.completed` | `completed` | — | — |
| `order.delivery.update` | `order.in_delivery` / `order.delivered` / — | — | `in_delivery` / `delivered` | — |
| `order.qrPayment.completed` | `order.payment_completed` | — | — | `online_paid` |
| `order.qrPayment.error` | — (log-only) | — | — | — |

### Race condition prevention (P2.1)

Wszystkie endpointy modyfikujące `sh_orders` używają `SELECT ... FOR UPDATE` wewnątrz `beginTransaction()`:
- `inbound.php` — otoczono order matching w transakcję z FOR UPDATE
- `events.php` — SELECT order przeniesiony do transakcji z FOR UPDATE
- `pay.php` — SELECT order w transakcji z FOR UPDATE

### Testy

- Lint PHP: 4/4 PASS
- Test runner: 61 pass / 0 fail / 1 warn (pre-existing)
- Testy E2E ChoiceQR: **TODO** — obecne testy JS nie pokrywają integracji ChoiceQR
