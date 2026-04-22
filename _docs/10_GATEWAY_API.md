# 10. Gateway API v2 — Unified Order Intake

> **Status:** ✅ Faza 7 · Sesja 7.2 (2026-04-18)
> **Migracja:** `027_gateway_v2.sql`
> **Core:** `core/GatewayAuth.php`
> **Endpoint:** `POST /api/gateway/intake.php`

---

## 1. Cel

Jedna publiczna bramka dla **wszystkich** zewnętrznych źródeł zamówień, zastępująca rozproszone integracje:

- **Web** — własne frontendy (legacy kompatybilny z m026)
- **Mobile App** — aplikacje mobilne tenanta
- **Kiosk** — samoobsługowe terminale w lokalu
- **Aggregatorzy** — Uber Eats, Glovo, Pyszne.pl, Wolt
- **3rd-party POS** — Papu / Dotykacka / GastroSoft (push do nas)
- **Public API** — własne integracje tenanta (system lojalnościowy, CRM)

---

## 2. Autoryzacja

### 2.1. Nowy tryb (m027) — wielokluczowa autoryzacja

Klucz ma format `sh_{env}_{prefix}.{secret}`:

```
sh_live_a1b2c3d4.89abcdef...48chars
│   │    │        │
│   │    │        └── secret (192 bit entropii, SHA-256 hash w DB)
│   │    └── 8-char prefix (widoczny w logach)
│   └── env: live | test | dev
└── namespace
```

**Tworzenie klucza** (UI Settings → Sesja 7.5, na razie SQL):

```php
$generated = GatewayAuth::generateKey('live');
// => ['prefix' => 'sh_live_...', 'rawSecret' => '...', 'fullKey' => '...', 'hash' => '...']

$pdo->prepare("
    INSERT INTO sh_gateway_api_keys
        (tenant_id, key_prefix, key_secret_hash, name, source, scopes,
         rate_limit_per_min, rate_limit_per_day)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
")->execute([
    1,
    $generated['prefix'],
    $generated['hash'],
    'Uber Eats integration',
    'aggregator_uber',
    json_encode(['order:create','order:read']),
    120,     // 120 req/min
    50000,   // 50k req/day
]);

// ⚠️ Pokaż $generated['fullKey'] użytkownikowi 1 RAZ — nigdy więcej nie da się odtworzyć.
```

**Wywołanie:**

```bash
curl -X POST https://slicehub.example.com/api/gateway/intake.php \
  -H "X-API-Key: sh_live_a1b2c3d4.89abcdef..." \
  -H "Content-Type: application/json" \
  -d '{ ... }'
```

### 2.2. Legacy mode (bez m027)

Jeśli tabela `sh_gateway_api_keys` nie istnieje, gateway fallbackuje do env `GATEWAY_API_KEY` (single key, tenant_id wymagany w payloadzie). Źródło domyślne: `web`. Rate limiting wyłączony.

### 2.3. Scopes

| Scope | Znaczenie |
|---|---|
| `order:create` | Tworzenie zamówień (`POST intake.php`) — minimum dla wszystkich kluczy |
| `order:read` | Odczyt statusu zamówienia (planowane: `/api/gateway/order_status.php`) |
| `menu:read` | Odczyt menu + cen (planowane: `/api/gateway/menu.php`) |
| `*` | Wszystkie uprawnienia (superkey) |

---

## 3. Rate Limiting

Sliding window **per klucz API**, dwupoziomowy:

| Bucket | Default | Przeznaczenie |
|---|---|---|
| **minute** | 60 req/min | Burst protection |
| **day** | 10 000 req/day | Fair use |

Limity konfigurowalne per klucz (`rate_limit_per_min`, `rate_limit_per_day`). `0` = bez limitu (dla superkey).

Przy przekroczeniu → HTTP **429** z headerem `Retry-After: <seconds>`:

```json
{
  "success": false,
  "error": "RATE_LIMITED",
  "message": "Rate limit exceeded. Retry after 42 seconds.",
  "data": {
    "retry_after_seconds": 42,
    "hits": { "minute": 61, "day": 3241 }
  }
}
```

**Implementacja:** `sh_rate_limits` z `UNIQUE(api_key_id, window_kind, window_bucket)` i `INSERT ... ON DUPLICATE KEY UPDATE count=count+1` — race-safe.

---

## 4. Idempotency (external_id)

Dla źródeł innych niż `web`/`kiosk` pole `external_id` jest **wymagane**. Gdy dla pary `(tenant, source, external_id)` istnieje już order, gateway zwraca **HTTP 200** z oryginalnym `order_id`:

```json
{
  "success": true,
  "data": {
    "order_id": "9f3c-4b2a-...",
    "status": "duplicate",
    "was_duplicate": true,
    "original_created_at": "2026-04-18 14:23:11",
    "message": "Order with this external_id already exists — returning original."
  }
}
```

Gdy po raz pierwszy — **HTTP 201**:

```json
{
  "success": true,
  "data": {
    "order_id": "9f3c-4b2a-...",
    "order_number": "UBR/20260418/0042",
    "status": "new",
    "was_duplicate": false,
    "grand_total": "47.00",
    "promised_time": "2026-04-18 14:55:00",
    "source": "aggregator_uber",
    "external_id": "uber-ord-xyz-123"
  }
}
```

---

## 5. Payload contract

### 5.1. Wspólne pola (wszystkie source)

```jsonc
{
  "source":          "aggregator_uber",    // wymagane gdy klucz source='aggregator'
  "tenant_id":       1,                    // wymagane dla legacy key
  "channel":         "Delivery",           // POS | Takeaway | Delivery
  "order_type":      "delivery",           // dine_in | takeaway | delivery
  "customer_phone":  "+48500123456",       // E.164 PL (nie wymagane dla kiosk)
  "customer_name":   "Jan Kowalski",       // opcjonalne
  "customer_address":"Warszawa, Marsz. 1", // wymagane gdy channel='Delivery'
  "lat":             52.2297,              // opcjonalne (geofence)
  "lng":             21.0122,
  "requested_time":  "ASAP",               // "ASAP" | ISO 8601 | "YYYY-MM-DD HH:MM:SS"
  "client_total":    47.00,                // opcjonalne — gdy niezgodne z serverem → warning
  "external_id":     "uber-ord-xyz-123",   // wymagane dla aggregator_*/pos_3rd
  "lines": [
    {
      "sku":      "PIZZA_MARGHERITA",
      "quantity": 1,
      "modifiers": [{"sku":"EXTRA_CHEESE"}],
      "removed":   [],
      "comment":   null
    }
  ]
}
```

### 5.2. Per-source wymagania

| Source | Wymagane dodatkowo |
|---|---|
| `web`, `mobile_app`, `public_api` | `customer_phone` |
| `aggregator_*`, `pos_3rd` | `customer_phone` + `external_id` |
| `kiosk` | — (bez phone — dine-in + walk-in) |

### 5.3. Prefixy numeracji (sh_orders.order_number)

| Source | Prefix | Przykład |
|---|---|---|
| `web` | `WWW` | `WWW/20260418/0042` |
| `mobile_app` | `MOB` | `MOB/20260418/0042` |
| `kiosk` | `KIO` | `KIO/20260418/0042` |
| `pos_3rd` | `EXT` | `EXT/20260418/0042` |
| `public_api` | `API` | `API/20260418/0042` |
| `aggregator_uber` | `UBR` | `UBR/20260418/0042` |
| `aggregator_glovo` | `GLV` | `GLV/20260418/0042` |
| `aggregator_pyszne` | `PYS` | `PYS/20260418/0042` |
| `aggregator_wolt` | `WLT` | `WLT/20260418/0042` |

---

## 6. Pipeline walidacji (13 kroków)

```
 1. PARSE          → JSON body
 2. AUTH           → X-API-Key → GatewayAuth::authenticateKey
 3. RATE LIMIT     → GatewayAuth::checkAndIncrementRateLimit
 4. RESOLVE SOURCE → payload OR key.source + whitelist check
 5. SCHEMA VALIDATE→ gw_requiredFields($source)
 6. IDEMPOTENCY    → GatewayAuth::lookupExternalRef  → duplicate? return 200
 7. TENANT ACTIVE  → sh_tenant_settings.is_active
 8. CART RECALC    → CartEngine::calculate (server-authoritative)
 9. MIN ORDER      → sh_tenant_settings.min_order_value (skip for kiosk)
10. BUSINESS HOURS → opening_hours_json (skip for pos_3rd / internal)
11. REQUESTED TIME → min_prep_time_minutes bounds
12. GEOFENCING     → sh_delivery_zones.ST_Contains (opt.)
13. PERSIST        → sh_orders + sh_order_lines + sh_order_audit + m026 event + sh_external_order_refs
```

Każdy krok → fail-fast z ustrukturyzowanym JSON-em:

```json
{
  "success": false,
  "error": "OUT_OF_ZONE",
  "message": "The delivery address is outside our delivery area.",
  "data": { ... opcjonalnie }
}
```

---

## 7. Lista kodów błędów

| HTTP | Error code | Kiedy |
|---|---|---|
| 400 | `INVALID_JSON` | Body nie jest prawidłowym JSONem |
| 400 | `INVALID_TENANT` | tenant_id niepodany / ≤ 0 |
| 400 | `INVALID_SOURCE` | Source spoza whitelisty |
| 400 | `MISSING_FIELD` | Brakuje wymaganego pola dla danego source |
| 400 | `EMPTY_CART` | Pusta tablica `lines` |
| 400 | `INVALID_CHANNEL` | channel ≠ POS/Takeaway/Delivery |
| 400 | `INVALID_ORDER_TYPE` | order_type ≠ dine_in/takeaway/delivery |
| 400 | `INVALID_PHONE` | Format phone ≠ +48NNNNNNNNN |
| 400 | `MISSING_ADDRESS` | Delivery bez customer_address |
| 400 | `ITEM_UNAVAILABLE` | CartEngine odrzucił (brak SKU / wyłączony / poza godzinami) |
| 400 | `BELOW_MINIMUM` | Total < min_order_value tenanta |
| 400 | `STORE_CLOSED` | Poza godzinami otwarcia |
| 400 | `INVALID_TIME` | requested_time niepoprawny / poza zakresem |
| 400 | `OUT_OF_ZONE` | lat/lng poza strefą dostawy |
| 401 | `AUTH_INVALID` | Klucz API nieprawidłowy lub nieistniejący |
| 403 | `AUTH_INACTIVE` | Klucz wyłączony (is_active=0) |
| 403 | `AUTH_REVOKED` | Klucz cofnięty (revoked_at ≠ NULL) |
| 403 | `AUTH_EXPIRED` | Klucz wygasł (expires_at < NOW) |
| 403 | `SCOPE_DENIED` | Klucz nie ma `order:create` |
| 403 | `SOURCE_MISMATCH` | Payload source ≠ key.source (protection vs key-reuse) |
| 403 | `TENANT_INACTIVE` | Tenant wyłączony |
| 429 | `RATE_LIMITED` | Przekroczony limit — patrz header Retry-After |
| 500 | `DATABASE_ERROR` | Wewnętrzny błąd DB |
| 500 | `INTERNAL_ERROR` | Inny wyjątek |

---

## 8. Integracja z Event Busem (m026)

Po udanym `INSERT sh_orders` gateway publikuje event `order.created` do `sh_event_outbox` (patrz `_docs/09_EVENT_SYSTEM.md`). Event zawiera **w payloadu**:

```json
{
  "_context": {
    "channel":              "Delivery",
    "order_type":           "delivery",
    "requested_time":       "ASAP",
    "price_mismatch":       false,
    "gateway_source":       "aggregator_uber",
    "gateway_external_id":  "uber-ord-xyz-123",
    "api_key_id":           42
  }
}
```

Dzięki temu kolejne moduły (KDS, webhook dispatcher, integration adapters) wiedzą z którego źródła pochodzi order i mogą zastosować odpowiednią logikę (np. webhooks do Ubera mają specjalny callback zwrotny).

---

## 9. Bezpieczeństwo

### 9.1. Sekrety nigdy w plaintext

Kolumna `key_secret_hash` to **SHA-256(raw_secret)**. Weryfikacja używa `hash_equals()` (timing-safe).

Raw secret pokazujemy użytkownikowi **raz** przy generowaniu. Jeśli go zgubi → trzeba wygenerować nowy.

### 9.2. IP tracking

Każde użycie klucza zapisuje IP w `sh_gateway_api_keys.last_used_ip` (z `X-Forwarded-For` / `X-Real-IP` / `CF-Connecting-IP` / `REMOTE_ADDR`). Przyszłe rozszerzenie: IP allowlist per key.

### 9.3. Source-binding

Klucz `source='aggregator_uber'` nie może wysłać zamówienia jako `aggregator_glovo` — nawet jeśli payload to mówi. Zabezpieczenie przed credential stuffing.

Wyjątek: klucz generyczny `source='aggregator'` może wysyłać jako `aggregator_*` (dla integracji multi-provider).

### 9.4. Request hash

Każdy `sh_external_order_refs` zapisuje `request_hash = SHA-256(raw_body)`. Pozwala na wykrywanie replay attacków z różnymi payloadami (jeśli ktoś wysłał order z `external_id=X` a potem ten sam external_id z innymi danymi — drugi request pokaże różny hash w logach).

---

## 10. Przykład — integracja Uber Eats

### 10.1. Setup

```sql
-- 1. Generuj klucz w PHP:
--    $gen = GatewayAuth::generateKey('live');

INSERT INTO sh_gateway_api_keys
    (tenant_id, key_prefix, key_secret_hash, name, source, scopes,
     rate_limit_per_min, rate_limit_per_day)
VALUES
    (1, 'sh_live_ubr001', 'sha256-hash-here',
     'Uber Eats webhook receiver', 'aggregator_uber',
     '["order:create"]',
     120, 50000);
```

### 10.2. Przykładowy callback Ubera → nasz endpoint

```json
POST /api/gateway/intake.php
Headers:
  X-API-Key: sh_live_ubr001.<secret>
  Content-Type: application/json

Body:
{
  "source": "aggregator_uber",
  "tenant_id": 1,
  "external_id": "ubr-order-01HMC...",
  "channel": "Delivery",
  "order_type": "delivery",
  "customer_name": "Jan K.",
  "customer_phone": "+48500123456",
  "customer_address": "Warszawa, Marszałkowska 1/2",
  "lat": 52.2297,
  "lng": 21.0122,
  "requested_time": "ASAP",
  "client_total": 47.00,
  "lines": [
    {"sku":"PIZZA_MARGHERITA","quantity":1,"modifiers":[{"sku":"EXTRA_CHEESE"}]},
    {"sku":"COLA_033","quantity":2}
  ]
}
```

### 10.3. Response (first time)

```json
HTTP 201 Created

{
  "success": true,
  "data": {
    "order_id": "9f3c4b2a-...",
    "order_number": "UBR/20260418/0042",
    "status": "new",
    "was_duplicate": false,
    "grand_total": "47.00",
    "promised_time": "2026-04-18 14:55:00",
    "source": "aggregator_uber",
    "external_id": "ubr-order-01HMC..."
  }
}
```

### 10.4. Response (Uber retry → duplicate)

```json
HTTP 200 OK

{
  "success": true,
  "data": {
    "order_id": "9f3c4b2a-...",
    "status": "duplicate",
    "was_duplicate": true,
    "original_created_at": "2026-04-18 14:23:11",
    "message": "Order with this external_id already exists — returning original."
  }
}
```

---

## 11. Roadmap

| Sesja | Co | Status |
|---|---|---|
| **7.2** | Gateway v2 foundation (ta sesja) | ✅ |
| 7.3 | Webhook dispatcher worker (cron) — konsument sh_event_outbox → sh_webhook_endpoints | pending |
| 7.4 | Integration adapters (PapuAdapter, DotykackaAdapter, GastroSoftAdapter) | pending |
| 7.5 | UI Settings → Integrations (manager generuje klucze, mapuje source→provider, widzi logi) | pending |
| 7.6 | Public API `/api/gateway/menu.php` + `/api/gateway/order_status.php` — read endpoints dla integracji | pending |
| 7.7 | IP allowlist per API key + HMAC-signed incoming requests (dla ekstra paranoi) | pending |

---

## 12. Debugowanie

### 12.1. Sprawdź klucz

```sql
SELECT k.id, k.key_prefix, k.name, k.source, k.tenant_id,
       k.is_active, k.last_used_at, k.last_used_ip,
       (SELECT COUNT(*) FROM sh_external_order_refs WHERE api_key_id = k.id) AS orders_created
FROM sh_gateway_api_keys k
WHERE k.key_prefix = 'sh_live_ubr001';
```

### 12.2. Aktualne hity rate limitu

```sql
SELECT window_kind, window_bucket, request_count, last_hit_at
FROM sh_rate_limits
WHERE api_key_id = ?
  AND last_hit_at > NOW() - INTERVAL 1 HOUR
ORDER BY last_hit_at DESC;
```

### 12.3. Historia external_id → order_id

```sql
SELECT r.created_at, r.source, r.external_id, r.order_id, o.order_number, o.status, k.name
FROM sh_external_order_refs r
JOIN sh_orders o ON o.id = r.order_id
LEFT JOIN sh_gateway_api_keys k ON k.id = r.api_key_id
WHERE r.tenant_id = 1
ORDER BY r.created_at DESC
LIMIT 50;
```

### 12.4. Ręczne czyszczenie rate limit buckets (cron Sesji 7.3)

```sql
DELETE FROM sh_rate_limits WHERE last_hit_at < NOW() - INTERVAL 7 DAY;
```

---

## 13. Decyzje projektowe

1. **In-DB rate limiter zamiast Redis** — dev/prod z moderatywnym ruchem daje prostszy stack. Migracja do Redis trywialna (interfejs `GatewayAuth::checkAndIncrementRateLimit` jest stabilny).
2. **SHA-256 plain hash zamiast bcrypt** — argon2/bcrypt są dla ludzkich haseł (timing attacks na plaintext). API keys mają wysoką entropię (192 bit) → plain SHA-256 wystarcza i jest szybszy.
3. **Per-source idempotency key space** — `(tenant, source, external_id)` pozwala Uberowi i Glovo mieć te same external_id bez kolizji.
4. **Legacy fallback** — dopóki m027 nie uruchomiona, stary `GATEWAY_API_KEY` env działa bez zmian → zero migration pain.
5. **Fail-open rate limiter** — gdy DB down dla sh_rate_limits, przepuszczamy ruch (bezpieczniejsze niż blokowanie wszystkich przy awarii tabeli cache).
