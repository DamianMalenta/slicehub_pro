# Settings Panel — Integrations, Webhooks, API Keys, DLQ (Sesja 7.5)

**Moduł:** `modules/settings/`
**Backend:** `api/settings/engine.php` (unified action dispatcher)
**Krypto:** `core/CredentialVault.php` (transparent AEAD encryption at rest)

> **Zakładki w UI:** 8 — rdzeń sesji 7.5 (pięć pierwszych wierszy) + **Inbound** (read-only callbacks) + **Powiadomienia** (Notification Director / m033) + **Dziennik** (read-only audyt z `sh_settings_audit`). Szczegóły zsynchronizowania z kodem: [`_docs/AUDIT_SETTINGS_PANEL.md`](AUDIT_SETTINGS_PANEL.md).

---

## 1. Cel

Panel admina spinający w jedno miejsce całą konfigurację integracji event-system wprowadzoną w sesjach 7.1 – 7.4 oraz późniejsze rozszerzenia (inbound, powiadomienia):

| Zakładka         | Zarządza                                                        | Dodaje w 7.5                                                      |
|------------------|------------------------------------------------------------------|-------------------------------------------------------------------|
| **Integrations** | `sh_tenant_integrations` (Papu / Dotykacka / GastroSoft)         | UI + Test Ping + encrypted credentials                           |
| **Webhooks**     | `sh_webhook_endpoints` (generyczne HMAC-signed HTTP POST)        | UI + Test Ping + rotate_secret + encrypted secret                |
| **API Keys**     | `sh_gateway_api_keys` (Gateway v2 intake)                        | UI + generate + revoke (klucz pokazywany raz)                    |
| **Dead Letters** | `sh_event_outbox` + `sh_integration_deliveries` (status='dead')  | Lista + **Replay** (reset do `pending` + `attempts=0`)           |
| **Health**       | Snapshot: vault, outbox, endpoints, integracje, klucze; rozszerzenie: inbound 24h, licznik plaintext | Monitoring dashboard                                              |
| **Inbound**      | `sh_inbound_callbacks` (podgląd callbacków 3rd-party → SliceHub) | UI read-only lista + filtry (patrz też [`14_INBOUND_CALLBACKS.md`](14_INBOUND_CALLBACKS.md)) |
| **Powiadomienia** | `sh_notification_channels` / `routes` / `templates`               | UI: kanały, routing zdarzeń, szablony, test-send (`notifications.js`) |
| **Dziennik**      | `sh_settings_audit` (ostatnie N wpisów, tenant-scoped)             | UI read-only tabela + szczegóły JSON (`audit_log_list`)             |

## 2. Architektura

```
┌──────────────────────────┐
│  modules/settings/       │  Vanilla JS, bez frameworków.
│  ├── index.html          │  8 zakładek: Integrations … Health + Inbound + Powiadomienia + Dziennik
│  ├── css/style.css       │
│  ├── js/settings_app.js  │  główny panel (API + render zakładek rdzenia + Inbound + Health)
│  └── js/notifications.js │  zakładka Powiadomienia (kanały / routing / szablony)
└────────────┬─────────────┘
             │ fetch (POST JSON)
             ▼
┌────────────────────────────────────────────────────────────────┐
│  api/settings/engine.php  (action-based dispatcher)            │
│    ├── integrations_* | webhooks_* | api_keys_* | dlq_*        │
│    ├── health_summary | inbound_list                           │
│    ├── csrf_token                                              │
│    ├── audit_log_list (read-only)                               │
│    └── notifications_* (channels, routes, templates, test)       │
│                                                                │
│  Każdy action:                                                 │
│    • auth_guard.php → tenant_id + user_id z sesji/JWT          │
│    • CredentialVault::encrypt/decrypt przy zapisie/odczycie    │
│    • prepared statements, redacted outputs                     │
└─────────────┬──────────────────────────────────────────────────┘
              │
              ▼
┌───────────────────────────────────┬────────────────────────────┐
│  sh_tenant_integrations (m026)    │  sh_gateway_api_keys (m027)│
│  sh_webhook_endpoints (m026)      │  sh_integration_deliv (m028)│
│  sh_event_outbox (m026)           │                            │
└───────────────────────────────────┴────────────────────────────┘
```

## 3. CredentialVault — szyfrowanie at rest

`core/CredentialVault.php` wprowadza warstwę transparent encryption dla wrażliwych pól
(`sh_tenant_integrations.credentials`, `sh_webhook_endpoints.secret`).

### 3.1. Format

Zaszyfrowane stringi prefixujemy `vault:v1:` i base64:

```
vault:v1:<base64(nonce[24] || ciphertext || tag[16])>
```

Algorytm: **XChaCha20-Poly1305 AEAD** (libsodium `sodium_crypto_aead_xchacha20poly1305_ietf_*`).

### 3.2. Klucz

32-byte random key (64 hex znaki). Ustawiony w **JEDNYM z**:

1. `$GLOBALS['SLICEHUB_VAULT_KEY']` — tylko do testów / inicjalizacji
2. Env var `SLICEHUB_VAULT_KEY` — zalecane dla produkcji (systemd/Apache `SetEnv`)
3. Plik `config/vault_key.txt` (owned przez www-data, chmod 0600)

Generowanie:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 3.3. Backward-compat & graceful degradation

- **Wartości BEZ prefixu `vault:v1:`** → decrypt zwraca je as-is (plaintext legacy).
  Pozwala to migrować stopniowo: stare rekordy działają, nowe (zapisane przez Settings API) są szyfrowane.
- **Brak libsodium / brak klucza** → `encrypt()` zwraca plaintext z warning do `error_log`
  (`[CredentialVault] encrypt running in PLAINTEXT mode: libsodium unavailable`). Aplikacja **nie crashuje**.
- **Korupcja ciphertextu** → `decrypt()` zwraca `null`; wywołujący (BaseAdapter, WebhookDispatcher)
  logują warning i pomijają rekord.

### 3.4. Integracja z workerami

**BaseAdapter (Integrations):**
```php
// core/Integrations/BaseAdapter.php → credentials()
if (\CredentialVault::isEncrypted($rawStr)) {
    $decrypted = \CredentialVault::decrypt($rawStr);
    if ($decrypted === null) { error_log(...); return []; }
    $rawStr = $decrypted;
}
$decoded = json_decode($rawStr, true);
```

**WebhookDispatcher (Webhooks):**
```php
// core/WebhookDispatcher.php → performDelivery()
if (CredentialVault::isEncrypted($secret)) {
    $decrypted = CredentialVault::decrypt($secret);
    if ($decrypted === null) return ['ok' => false, 'transient' => false, 'error' => 'vault decrypt failed'];
    $secret = $decrypted;
}
$signature = hash_hmac('sha256', $signingBase, $secret);
```

## 4. Backend — `api/settings/engine.php`

### 4.1. Request format

- `Content-Type: application/json` lub urlencoded form.
- `action` określa operację (wybór z listy).
- Cookie-based session lub `Authorization: Bearer <jwt>`.

### 4.2. Response envelope

```json
{ "success": true|false, "data": <object|array|null>, "message": "..." }
```

### 4.3. Actions

#### Integrations

| Action                       | Payload                                                                              | Response                            |
|------------------------------|--------------------------------------------------------------------------------------|-------------------------------------|
| `integrations_list`          | —                                                                                    | `{integrations, available_providers, vault_ready}` |
| `integrations_save`          | `{id?, provider, display_name, api_base_url, credentials, direction, events_bridged, is_active, timeout_seconds, max_retries}` | `{id, created/updated}`             |
| `integrations_toggle`        | `{id, active}`                                                                       | `{id, is_active}`                   |
| `integrations_delete`        | `{id}`                                                                               | `{id, deleted}`                     |
| `integrations_test_ping`     | `{id}`                                                                               | Full ping report (see §6)           |

`credentials` w requeście → `object` (JSON). Engine szyfruje przy zapisie przez `CredentialVault::encrypt()`.
W responsach pokazuje tylko `credentials_redacted` (`"••••(api_key,cloud_id,…)"`) i flagę `credentials_encrypted`.

#### Webhooks

| Action                   | Payload                                                                              | Response                            |
|--------------------------|--------------------------------------------------------------------------------------|-------------------------------------|
| `webhooks_list`          | —                                                                                    | `{endpoints, vault_ready}`          |
| `webhooks_save`          | `{id?, name, url, events_subscribed, is_active, max_retries, timeout_seconds, rotate_secret?}` | `{id, new_secret?}`           |
| `webhooks_toggle`        | `{id, active}`                                                                       | `{id, is_active}`                   |
| `webhooks_delete`        | `{id}`                                                                               | `{id, deleted}`                     |
| `webhooks_test_ping`     | `{id}`                                                                               | Full ping report                    |

**Secret lifecycle:** przy CREATE endpoint generuje fresh `bin2hex(random_bytes(32))` (64 hex chars),
szyfruje przez vault, wkłada do DB. Zwraca `new_secret` (plaintext) w response **dokładnie jeden raz** — UI pokazuje modalne
"Copy NOW — nie zobaczymy tego ponownie".

`rotate_secret: true` przy UPDATE ma ten sam efekt + zerowanie `consecutive_failures`.

#### API Keys

| Action                   | Payload                                                                              | Response                                    |
|--------------------------|--------------------------------------------------------------------------------------|---------------------------------------------|
| `api_keys_list`          | —                                                                                    | `{api_keys}`                                |
| `api_keys_generate`      | `{name, source, scopes, rate_limit_per_min, rate_limit_per_day, expires_at?}`        | `{id, full_key, prefix, source, scopes}`    |
| `api_keys_revoke`        | `{id}`                                                                               | `{id, revoked}`                             |

`full_key` format: `sh_live_abc12345.<48-hex-chars>` (patrz `GatewayAuth::generateKey()`).
Pokazujemy raz, zapisujemy tylko SHA-256 secretu (`key_secret_hash`).

#### DLQ

| Action        | Payload                                 | Response                                     |
|---------------|-----------------------------------------|----------------------------------------------|
| `dlq_list`    | `{channel?=all|webhooks|integrations, limit?=50}` | `{webhooks[], integrations[], counts}` |
| `dlq_replay`  | `{channel: webhooks|integrations, id}`  | `{channel, id, replayed}`                    |

**Replay** = `UPDATE ... SET status='pending', attempts=0, next_attempt_at=NOW(), last_error=CONCAT('REPLAY', ...) WHERE status='dead'`.
Worker weźmie event w następnym batchu.

#### Health

| Action            | Payload | Response                                                                |
|-------------------|---------|-------------------------------------------------------------------------|
| `health_summary`  | —       | m.in. `vault_ready`, `vault_has_sodium`, statystyki `sh_event_outbox` (7 dni), podsumowanie webhooków, lista integracji, `api_keys`, **`inbound`** (24h), **`plaintext`** (licznik legacy credentials) |

#### Inbound (read-only)

| Action           | Payload | Response |
|------------------|---------|----------|
| `inbound_list`   | `limit`, opcjonalnie `provider`, `status` | `{rows, counts_24h, table_ready}` |

#### Powiadomienia (Notification Director)

| Akcje (prefiks `notifications_`) | Opis |
|----------------------------------|------|
| `channels_list` / `channels_upsert` / `channels_delete` / `channels_test` | Kanały SMS/email/in-app itd. |
| `routes_get` / `routes_set` | Mapowanie `event_type` → kanał + fallback |
| `templates_get` / `templates_set` | Szablony treści |

#### Dziennik (read-only audit)

| Action            | Payload                          | Response                                      |
|-------------------|----------------------------------|-----------------------------------------------|
| `audit_log_list`  | `{limit?: 1–200}` (domyślnie 100) | `{rows}` — wpisy `sh_settings_audit` dla tenanta |

## 5. UI — `modules/settings/`

- **Zero build step** — vanilla JS, FontAwesome via CDN, zero npm deps.
- **Dark theme** spójne z KDS/POS.
- **Mobile responsive** (grid-2 collapses, actions wrap).
- **Dwa pliki JS:** `settings_app.js` (rdzeń + Inbound + Health + Dziennik + CSRF/bootstrap) oraz `notifications.js` (zakładka Powiadomienia); wspólne `callApi` / CSRF w obu ścieżkach.
  - API client (`callApi()`)
  - DOM helpers (`el()`, `$()`, `$$()`, `escHtml()`)
  - Toast notifications
  - Modal dialogs
  - Render funkcji per zakładka (8)
  - Shared helpers (`showRevealSecret()`, `updateVaultBadge()`)

### 5.1. Revealed secrets flow

Po wygenerowaniu webhook secret / full API key UI pokazuje dedykowany modal `showRevealSecret()`:

- 🔑 Duża ramka z akcentową obwódką
- `<code>` z sekretem (monospace, pełna szerokość)
- Przycisk **Copy** (`navigator.clipboard.writeText`)
- Instrukcja dot. miejsca użycia (header `X-API-Key`, signature `X-Slicehub-Signature`, itp.)
- Modal zamyka się tylko jawnym **Done** — uniemożliwia przypadkowe zamknięcie tap-outside.

### 5.2. Vault status badge

Topbar pokazuje status klucza (po pierwszym `*_list` responie):

- `🔒 vault ready` (zielony) — libsodium + klucz
- `⚠️ PLAINTEXT` (pomarańczowy) — brak klucza lub brak libsodium. Manager widzi to natychmiast.

## 6. Test Ping — szczegóły

Zarówno dla integration adaptera jak i webhook endpointa, **Test Ping** buduje syntetyczny envelope
`order.created` (flag `_test_ping=true` w payloadzie) i wysyła go realnie na target, **pomijając**
`sh_event_outbox` i `sh_integration_deliveries` (bez persystencji).

**Report:**

```json
{
  "ok": true|false,
  "stage": "resolve|buildRequest|delivered|rejected|transport|http_error|decrypt|encode",
  "http_code": 200,
  "transport_error": null,
  "transient": false,
  "external_ref": "PAPU_ORDER_42",
  "request_preview": { "method": "POST", "url": "...", "headers_count": 5, "body_bytes": 1240, "body_preview": "..." },
  "response_preview": "...(500B)...",
  "duration_ms": 312,
  "transport_ms": 298
}
```

**Stages:**

- `resolve` — brak adaptera dla providera (PROVIDER_MAP miss)
- `buildRequest` — adapter rzucił wyjątek przy budowie requestu (zwykle missing credential)
- `delivered` — HTTP 2xx + `adapter.parseResponse().ok === true`
- `rejected` — HTTP 4xx lub biznesowa odmowa (np. Papu `ok: false`)
- `transport` — cURL error (timeout, DNS, SSL)
- `decrypt` (tylko webhook) — CredentialVault nie mógł rozszyfrować secretu

**Klient-strona:** modal z JSON report w `<pre>`, border-left zielony/czerwony.

## 7. DLQ Replay — szczegóły

Rekordy w DLQ to:

- `sh_event_outbox` ze `status='dead'` → webhooki nie dostarczyły (po `max_retries` attempts z WebhookDispatcher).
- `sh_integration_deliveries` ze `status='dead'` → integracje nie dostarczyły (AdapterException albo exhausted retries).

**Replay** resetuje rekord do stanu początkowego, ale **zachowuje historię**:

```sql
UPDATE sh_event_outbox
SET status='pending',
    attempts=0,
    next_attempt_at=NOW(),
    last_error=CONCAT('REPLAY ', NOW(), ' | ', IFNULL(last_error,''))
WHERE id=:id AND tenant_id=:tid AND status='dead';
```

`last_error` ma prefix `REPLAY <timestamp>` dla audytu — widać w historii że admin ręcznie uruchomił.

**Integration replay nie resetuje `sh_integration_attempts`** — zachowujemy pełną historię prób.

## 8. Bezpieczeństwo — checklista

- ✅ `tenant_id` z sesji/JWT w każdym query (nigdy z requestu)
- ✅ Prepared statements wszędzie
- ✅ Credentials szyfrowane przy zapisie (vault:v1)
- ✅ Secrets pokazywane jeden raz
- ✅ API key store: tylko SHA-256 secretu
- ✅ HTTPS SSL verify = on (cURL w Test Ping)
- ✅ Cache-Control: no-store na wszystkich responsach
- ✅ `X-Slicehub-Test: 1` header w test pingach (subscriber widzi że to dry-run)
- ✅ **CSRF** — token sesyjny + nagłówek `X-CSRF-Token` na mutacjach (szczegół §13). Read-only akcje wyłączone z wymogu.
- ✅ **Rate limit Test Ping** — max **5** żądań na minutę **per tenant** (liczone przez wpisy `*_test_ping` w `sh_settings_audit`), odpowiedź HTTP 429 przy przekroczeniu.

**Uwaga:** Starsze wersje tego dokumentu miały tu TODO przy CSRF / rate limit — implementacja jest opisana w §12 (DONE) i §13; nie traktuj §8 jako „brakującej funkcji”.

## 9. Uruchomienie

### 9.1. Pierwsze ustawienie

```bash
# 1. Wygeneruj klucz vault
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;" > /tmp/slicehub_vault.key

# 2. Zapisz klucz (jedna z trzech opcji):
#    a) systemd Environment
echo "Environment=\"SLICEHUB_VAULT_KEY=$(cat /tmp/slicehub_vault.key)\"" >> /etc/systemd/system/slicehub.service.d/vault.conf

#    b) Apache SetEnv
echo "SetEnv SLICEHUB_VAULT_KEY $(cat /tmp/slicehub_vault.key)" >> /etc/apache2/conf-enabled/slicehub-vault.conf

#    c) Plik
mkdir -p /var/www/slicehub/config
cat /tmp/slicehub_vault.key > /var/www/slicehub/config/vault_key.txt
chown www-data:www-data /var/www/slicehub/config/vault_key.txt
chmod 600 /var/www/slicehub/config/vault_key.txt

# 3. Usuń tymczasowy plik
shred -u /tmp/slicehub_vault.key
```

### 9.2. Wejście do panelu

```
https://<your-slicehub>/modules/settings/
```

Wymaga zalogowania managera (tenant_id + user_id w sesji).

### 9.3. Migracja istniejących credentials

Opcjonalny jednorazowy skrypt (future 7.6): wczytaj wszystkie rekordy z `sh_tenant_integrations` i `sh_webhook_endpoints`,
encrypt przez vault, zapisz z powrotem. Obecnie: przy pierwszym **Save** w UI rekord jest automatycznie szyfrowany.

## 10. Monitoring

### SQL debug — czy integration ma problem?

```sql
SELECT ti.provider, ti.display_name, ti.is_active,
       ti.consecutive_failures, ti.max_retries,
       ti.last_sync_at, ti.last_failure_at
FROM sh_tenant_integrations ti
WHERE ti.tenant_id = :tid
ORDER BY ti.consecutive_failures DESC;
```

### SQL debug — czy klucz API nadal używany?

```sql
SELECT name, source, key_prefix, last_used_at, last_used_ip,
       is_active, revoked_at
FROM sh_gateway_api_keys
WHERE tenant_id = :tid
ORDER BY last_used_at DESC NULLS LAST;
```

### SQL debug — webhook endpoint success rate

```sql
SELECT we.name, we.url, we.consecutive_failures,
       COUNT(wd.id) AS attempts_24h,
       SUM(CASE WHEN wd.http_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS ok_24h
FROM sh_webhook_endpoints we
LEFT JOIN sh_webhook_deliveries wd ON wd.endpoint_id = we.id
    AND wd.attempted_at > NOW() - INTERVAL 24 HOUR
WHERE we.tenant_id = :tid
GROUP BY we.id
ORDER BY we.consecutive_failures DESC;
```

## 11. Design decisions

### 11.1. Jeden engine.php vs osobne pliki

Wybrano **unified action dispatcher** (jeden engine.php, action-based switch) zamiast osobnych plików
per resource (`integrations.php`, `webhooks.php`, itd.). Argumenty:

- Wspólne includy (auth, vault, adapter classes) w jednym miejscu.
- Mniejszy narzut cold-start dla wielokrotnych calls z UI (keep-alive pool).
- Wzorzec znany z `api/pos/engine.php`, `api/kds/engine.php` — spójność kodu.

### 11.2. Secret-once flow

Backend **nigdy** nie zwraca raw sekretów w `*_list` responsach. Nawet admin nie może odczytać sekretu
po create — musi go rotate. To zapobiega exfiltration gdyby ktoś zdobył dostęp do sesji admina.

### 11.3. Test Ping bez persystencji

Syntetyczne eventy z `_test_ping=true` **nie lądują w `sh_event_outbox`** ani `sh_integration_deliveries`.
Powód: nie chcemy zanieczyszczać statystyk / historii. Subscriber jednak dostaje realny HTTP request
(z flagą w body + header `X-Slicehub-Test: 1`), więc może go ignorować.

### 11.4. Vault key rotation (future)

Obecna wersja wspiera tylko `v1` (XChaCha20-Poly1305). Prefix format pozwala na:

- `vault:v2:…` — przyszłe algorytmy
- `vault:kid=01:v1:…` — key-id dla graceful key rotation (future 7.6+)

Każdy decrypt patrzy na wersję i wybiera implementację.

## 12. Roadmap

### ✓ DONE w Sesji 7.6

- [x] **CSRF tokens** — session-stored token + header `X-CSRF-Token` (double-submit). Akcje READ-ONLY pominięte.
- [x] **Rate limit** na `test_ping` — max 5/min per tenant, liczone przez `sh_settings_audit`.
- [x] **Audit log** — `sh_settings_audit` (m029). Każda mutacja (save/toggle/delete/generate/revoke/replay) zapisuje `user_id`, `action`, `entity_type/id`, `before/after` z redact'em secretów.
- [x] **Key rotation job** — `scripts/rotate_credentials_to_vault.php` + `scripts/bootstrap_vault.php`.
- [x] **Inbound callback framework** — `api/integrations/inbound.php` + `BaseAdapter::parseInboundCallback()`. Patrz [`_docs/14_INBOUND_CALLBACKS.md`](14_INBOUND_CALLBACKS.md).

### Jeszcze otwarte (7.7+)

- [ ] **Webhook delivery inspector** — pełna timeline HTTP requestów z paginacją w UI
- [ ] **Provider test suite** — automatyczne sandbox pingowania wszystkich aktywnych integracji raz na godzinę (alert gdy `consecutive_failures > 0`)
- [ ] **Scope picker UX** — checkboxy zamiast textbox dla `scopes` w API Keys
- [ ] **Multi-tenant admin view** — centralny panel dla superadmina przeglądający integracje wszystkich tenantów
- [ ] **Webhook subscription auto-register** — automatyczny POST naszego inbound URL do providera (Papu/Uber API) zamiast ręcznej konfiguracji

## 13. CSRF flow (ready 7.6)

1. Klient ładuje `modules/settings/index.html`.
2. `settings_app.js` przy starcie robi `GET/POST api/settings/engine.php?action=csrf_token` → zapisuje token w memory.
3. Każda mutacja (`integrations_save`, `webhooks_delete`, …) wysyła header `X-CSRF-Token: <token>`.
4. Backend `settings_csrfCheck()` porównuje z `$_SESSION['settings_csrf_token']` przez `hash_equals()`.
5. Token per-session — nie rotuje sam z siebie; refresh tylko przy nowym loginie.

## 14. Audit log

`sh_settings_audit` — każda mutacja loguje się automatycznie. W UI zakładka **Dziennik** pobiera ostatnie wpisy przez `audit_log_list` (bez CSRF na odczycie). Przykład SQL:

```sql
SELECT id, user_id, action, entity_type, entity_id, created_at
FROM sh_settings_audit
WHERE tenant_id = 1
ORDER BY id DESC LIMIT 20;
```

Kolumny `before_json` / `after_json` zawierają snapshoty (z redact'em secretów → `••••(redacted)`).

---

**Powiązane dokumenty:**

- `_docs/AUDIT_SETTINGS_PANEL.md` — audyt zgodności dokumentacja ↔ kod ↔ UI
- `_docs/10_GATEWAY_API.md` — public API Gateway v2 (m027)
- `_docs/11_WEBHOOKS.md` — webhook subscribers (m026 + 7.3)
- `_docs/12_INTEGRATION_ADAPTERS.md` — Papu/Dotykacka/GastroSoft adapters (m028 + 7.4)
- `_docs/14_INBOUND_CALLBACKS.md` — **inbound flow (3rd-party → SliceHub)** · 7.6
- `_docs/00_PAMIEC_SYSTEMU.md` — system memory
- `_docs/ARCHIWUM/FAZA_1_STATUS.md` — session log historyczny
