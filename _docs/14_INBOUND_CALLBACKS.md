# 14. Inbound Callbacks — 3rd-Party → SliceHub

> **Status:** Faza 7.6 · Sesja 7.6 (2026-04-18) — infrastruktura **READY**, adapter dla Papu w pełni zaimplementowany (reference), Dotykacka/GastroSoft **stub** (rzucają `not implemented` dopóki ktoś nie uzupełni `parseInboundCallback`).

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

## 6. Status transition whitelist

`api/integrations/inbound.php` nie pozwoli cofnąć statusu ani skoczyć za daleko. Identyczne reguły jak w `core/OrderStateMachine.php`:

```
new         → accepted, preparing, cancelled
accepted    → preparing, ready, cancelled
preparing   → ready, cancelled
ready       → dispatched, in_delivery, delivered, completed, cancelled
dispatched  → in_delivery, delivered, cancelled
in_delivery → delivered, cancelled
delivered   → completed
```

Niedozwolone przejście → status **zostaje** (zignorowane), ale callback trafia do `sh_inbound_callbacks` z `error_message` wyjaśniającym.

## 7. URL endpointu dla providerów

```
POST https://<your-host>/api/integrations/inbound.php
     ?provider=<papu|dotykacka|gastrosoft>
     &integration_id=<sh_tenant_integrations.id>

Headers:
  Content-Type: application/json
  X-<Provider>-Signature: <provider-specific HMAC>
```

### Rejestracja u providera

- **Papu:** Ustawić w `Settings → Webhooks → Status Changes` URL powyżej + `api_secret` z creds.
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

## 12. Powiązane pliki

- `api/integrations/inbound.php` — receiver
- `core/Integrations/BaseAdapter.php` — abstract `parseInboundCallback`
- `core/Integrations/PapuAdapter.php` — reference impl
- `core/OrderEventPublisher.php` — publish internal events
- `database/migrations/029_infrastructure_completion.sql` — `sh_inbound_callbacks`
