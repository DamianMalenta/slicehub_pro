# Faza 1 — Scene Studio + The Table · Fundament

> **Status: ✅ UKOŃCZONA** (5 sesji, ~2 godziny roboty)
> **Data:** 2026-04-17
> **Kolejny krok:** Faza 2 (content Scene Kit + edytor CategoryScene + edytor modyfikatora)
>
> **Aktualizacja 2026-04-18 · sesja 2.7 — Scene Kit Editor (M023.7)**
> - Nowa akcja `save_scene_kit` w `api/backoffice/api_menu_studio.php` z auto-klonem system template → tenant-owned przy pierwszym zapisie. Walidacja ID assetów z `sh_assets` (tenant + globalne).
> - `StudioApi.sceneKitSave(templateKey, kit)` + `StudioApi.assetsListCompact(limit)` w `modules/online_studio/js/studio_api.js`.
> - `ScenographyPanel` — przycisk „Edytuj kit" w headerze otwiera modal z 4 kubełkami (tła / rekwizyty / światła / odznaki), pickerem z biblioteki tenanta i natychmiastowym odświeżeniem kit-u po zapisie.
> - CSS `.sc-kit-*` w `director.css` (overlay z blur, chipy, grid kafelków 140×140).
>
> **Aktualizacja 2026-04-18 · sesja 3.0 — Interaction Contract v1**
> - Trzy nowe publiczne akcje w `api/online/engine.php` (backward-compat, `get_menu`/`get_dish` zostają):
>   `get_scene_menu` (batch mini-kontraktów per kategoria), `get_scene_dish` (pełny
>   `SceneResolver::resolveDishVisualContract` + cena + mod groups + companions +
>   halfHalfSurcharge/surfaceUrl/modifierVisuals), `get_scene_category` (scena
>   kategorii grouped/hybrid).
> - `OnlineAPI.getSceneMenu / getSceneDish / getSceneCategory` w `modules/online/js/online_api.js`.
> - Nowy dokument `_docs/07_INTERACTION_CONTRACT.md` z pełną specyfikacją JSON (request/response + cascade styli + wzorce wywołań dla The Table).
> - Kontrakt wersjonowany (`_meta.contractVersion: 1`). Kolejne breaking zmiany → bump wersji.
>
> **Aktualizacja 2026-04-18 · sesja 3.1 — The Table (klient) v1**
> - Nowy renderer `modules/online/js/online_table.js`: pionowy stos kategorii (scroll-snap), w każdej poziomy pas items (scroll-snap-x, swipe mobile), per-kategoria theming z `activeStyle.colorPalette` (CSS vars `--t-primary / --t-accent / --t-bg / --t-text / --t-font`).
> - `online_app.js` przełączone na `getSceneMenu` + `getSceneDish` (z adapterem `adaptSceneDishToLegacy` → istniejący `fillDishSheet` działa bez zmian; theming panelu przez `applyDishSheetTheme`).
> - Feature-flag `?legacy=1` przywraca stary akordeon + `getMenu/getDish` (backward-compat).
> - CSS `.table-section / .table-card / .table-surface` w `online/css/style.css` (~180 linii, mobile-first z breakpointem 560px).
>
> **Aktualizacja 2026-04-18 · sesja 4.1 — Cart promotions (frontend integration)**
> - Backend `CartEngine::applyAutoPromotions` + `evaluateRule` (logika `discount_percent` / `discount_amount` / `combo_half_price` / `free_item_if_threshold` / `bundle` + `time_window_json` + best-wins) **już zaimplementowany** — potwierdzone `api/cart/CartEngine.php:18-335`. `cart_calculate` zwraca `applied_auto_promotions`, `auto_promotion_discount`, `discount` (suma auto+manual).
> - Frontend: `renderCartSummary()` w `online_ui.js` (~50 linii) + kontener `#cart-summary` w `index.html` + CSS `.cart-sum / .cart-sum__badge (amber/emerald/rose/sky/violet/neutral)` — cart drawer pokazuje badges promocji z kolorem, kwotą, notatką oraz breakdown: subtotal → rabaty → dostawa → Razem.
> - `online_app.js` — `recalcCart()` aktualizuje summary przy każdej zmianie koszyka.
> - Dokument `_docs/07_INTERACTION_CONTRACT.md` zaktualizowany o pełną tabelę `rule_kind` + przykładowy JSON response `cart_calculate`.
>
> **Aktualizacja 2026-04-18 · sesja 5.1 — Guest Checkout v1**
> - Backend: nowa akcja `guest_checkout` w `api/online/engine.php` (~220 linii) — weryfikuje `lock_token` z `init_checkout`, rekalkuluje koszyk authoritative, hashuje kanoniczny payload (race protection), tworzy `sh_orders` + `sh_order_lines` + `sh_order_audit` w atomic transaction, konsumuje lock_token (`consumed_at`, `consumed_order_id`), generuje `tracking_token` (16 hex) oraz atomowy `order_number` przez `sh_order_sequences`, bumpuje `sh_promo_codes.current_uses`. Zwraca `{orderId, orderNumber, trackingToken, trackingUrl, grandTotal}`.
> - Frontend: nowy moduł `modules/online/js/online_checkout.js` (~350 linii) — overlay finalizacji z formularzem (imię/telefon/email, adres dostawy albo preferowana godzina odbioru, metoda płatności: gotówka/karta przy dostawie, online disabled), inline walidacja, localStorage persistence kontaktu + historii zamówień + `last_order`, success screen z CTA „Śledź zamówienie".
> - `OnlineAPI.initCheckout / guestCheckout / trackOrder / deliveryZones` w `online_api.js`.
> - HTML: zamiana paragrafu w cart drawer na przycisk CTA „Zamów za X zł" + kotwica „Śledź ostatnie zamówienie" (pokazuje się jeśli `last_order` w localStorage).
> - CSS: ~260 linii w `online/css/style.css` dla overlayu, formularza, payment grid (hover/selected states), success screen z badge animacją, responsive mobile.
>
> **Aktualizacja 2026-04-18 · sesja 5.2 — Track Order UX**
> - Nowa strona `modules/online/track.html` (standalone) + moduł `modules/online/js/online_track.js` (~300 linii) — `?token=X&phone=Y` parsing, fallback do localStorage `last_order`, prompt form gdy brak danych.
> - Smart polling: 15s normal, 5s gdy `status='in_delivery'`. Auto-stop przy terminalnych stanach (`completed`/`cancelled`).
> - UI: header card (order number, status pill z kolorami per-status + animacją pulse dla `in_delivery`), interaktywny 6-step timeline z dynamic reached/current states + animacje, driver card z mapą Leaflet (OpenStreetMap tiles), pulsujący marker kuriera z heading rotation, summary DL (adres, płatność, ETA).
> - CSS osobny plik `modules/online/css/track.css` (~320 linii) — full design system (dark topbar + jasne karty + colorowe pille per status).
>
> **Aktualizacja 2026-04-18 · sesja 6.1 — KDS Status Flow Repair + UX**
> - **KRYTYCZNY BUG FIX**: KDS szukał `status IN ('pending','preparing')`, ale nowe zamówienia z `guest_checkout` i POS zapisują `status='new'`. Zamówienia WEB nigdy nie trafiały do kuchni. Poprawione: `status IN ('new','accepted','preparing')` + sortowanie wg priorytetu (new → accepted → preparing → oldest first).
> - `bump_order`: rozszerzone transitions (`new → accepted → preparing → ready`) + audit trail (INSERT do `sh_order_audit`) + atomic transaction + `updated_at = NOW()`.
> - Nowa akcja `recall_order`: rollback `ready → preparing` (kucharz pomylił się z „Gotowe").
> - Frontend: `BUMP_CONFIG` map per-status (accept/start/done labels & colors), badges na tickecie: source (WWW/POS/KIO/AGG), payment method (gotówka/karta/online), customer line z klikalnym telefonem (`tel:` link). Recall button w stopce.
> - CSS: new/accepted status borders, animacja pulsu dla `new`, recall button style, badges (gradient colors).
>
> **Aktualizacja 2026-04-18 · sesja 7.1 — Event System Foundation (m026)**
> - **Problem:** User podniósł krytyczną obawę architektoniczną — moduły (POS ↔ KDS ↔ Delivery ↔ Courses ↔ Online ↔ Gateway) komunikowały się przez wspólne `sh_orders` zamiast przez luźno powiązane eventy. Brak warstwy integracyjnej dla zewnętrznych POS (Papu, Dotykacka, GastroSoft).
> - **Rozwiązanie:** Transactional Outbox Pattern — event publishowany w TEJ SAMEJ transakcji co zapis do `sh_orders`, worker ciągnie asynchronicznie.
> - **Migracja 026** — `database/migrations/026_event_system.sql`:
>   • `sh_event_outbox` — transactional outbox z idempotency key (UNIQUE tenant+key), status enum (pending/dispatching/delivered/failed/dead), exponential backoff scheduling (next_attempt_at), snapshot payload JSON.
>   • `sh_webhook_endpoints` — subskrybenci webhooków per tenant z HMAC secret, events_subscribed whitelist, max_retries, auto-disable przy consecutive_failures.
>   • `sh_webhook_deliveries` — historia prób dostawy (HTTP code, duration, error).
>   • `sh_tenant_integrations` — registry adapterów 3rd-party POS (provider, credentials, direction push/pull, events_bridged).
> - **Core service** — `core/OrderEventPublisher.php` (~250 linii):
>   • `publish()` low-level + `publishOrderLifecycle()` z auto-snapshotem order header + lines.
>   • Whitelist 13 kanonicznych eventów (1:1 z `_docs/08_ORDER_STATUS_DICTIONARY.md`).
>   • Silent degradation — brak tabeli outbox = no-op, nigdy nie łamie głównej transakcji.
>   • Idempotency: drugi `publish()` tego samego klucza = INSERT IGNORE no-op.
> - **Integracja w 7 endpointach** (każdy publikuje event W TEJ SAMEJ transakcji, przed `commit()`):
>   • `api/online/engine.php#guest_checkout` → `order.created` (source=online, actor=guest)
>   • `api/gateway/intake.php` → `order.created` (source=gateway, actor=external_api)
>   • `api/pos/engine.php#finalize_order` → `order.created` / `order.edited` (source=pos, actor=staff)
>   • `api/kds/engine.php#bump_order` → `order.accepted` / `order.preparing` / `order.ready` (source=kds)
>   • `api/kds/engine.php#recall_order` → `order.recalled` (source=kds)
>   • `api/delivery/dispatch.php` → `order.dispatched` (per stop, source=delivery, z course_id)
>   • `api/courses/engine.php#update_order_status` → `order.completed` / `order.delivered` / `order.cancelled` (source=courses)
> - **Setup** — `scripts/setup_database.php` sekcja „Migration 026 — Event System" z idempotent exec + verify 4 tabel.
> - **Dokumentacja** — nowy plik `_docs/09_EVENT_SYSTEM.md` (~350 linii): problem, architektura ASCII diagram, słownik eventów z payload contract, idempotency rules, webhook HMAC signature, integration registry, roadmapa Sesji 7.2-7.6, debugowanie SQL snippets.
> - **Kolejne sesje (roadmap event-driven):** 7.2 Gateway v2 (normalize cross-source payloads) → 7.3 Webhook Worker (cron + HMAC signing + exponential backoff + dead letter) → 7.4 Integration Adapters (PapuAdapter, DotykackaAdapter, GastroSoftAdapter) → 7.5 UI Settings panel → 7.6 Internal consumers (SMS, SSE dashboard, analytics).
>
> **Aktualizacja 2026-04-18 · sesja 7.2 — Gateway v2 · Unified Order Intake (m027)**
> - **Problem:** `api/gateway/intake.php` v1 miał single env key + żaden rate limiter + żadna idempotency. Aggregator który retry'uje (network glitch) zduplikowałby order. Brak per-source rozróżnienia (kiosk vs Uber vs własna apka mobilna).
> - **Rozwiązanie:** Multi-key auth + sliding-window rate limiter + per-source idempotency przez `external_id` + JSON schema validation + source-aware order_number prefixes.
> - **Migracja 027** — `database/migrations/027_gateway_v2.sql`:
>   • `sh_gateway_api_keys` — multi-key auth per tenant × source, `key_prefix` (widoczny w logach) + `key_secret_hash` (SHA-256, nigdy plaintext), `scopes` JSON, `rate_limit_per_min/per_day`, `revoked_at`, `expires_at`, `last_used_at/ip`.
>   • `sh_rate_limits` — sliding window per klucz (bucket kind: minute | day | hour), UNIQUE(api_key_id, window_kind, window_bucket) → race-safe `INSERT … ON DUPLICATE KEY UPDATE count=count+1`.
>   • `sh_external_order_refs` — mapa `(tenant, source, external_id) → order_id` z `request_hash` (SHA-256 oryginalnego body dla replay detection).
>   • `sh_orders` +`gateway_source` +`gateway_external_id` — audit-friendly (bez joina do refs przy każdym readzie). Alter additive — legacy POS/Online działa z NULLami.
> - **Core service** — `core/GatewayAuth.php` (~320 linii):
>   • `authenticateKey()` — parse `sh_{env}_{prefix}.{secret}`, lookup po `key_prefix`, timing-safe `hash_equals(SHA-256(secret), stored_hash)`, sprawdzenie `is_active / revoked_at / expires_at`. **Legacy fallback** do env `GATEWAY_API_KEY` gdy tabela nie istnieje → zero breaking changes dla istniejących setupów.
>   • `checkAndIncrementRateLimit()` — dwupoziomowy (minute + day), **fail-open** gdy DB awaria (bezpieczniejsze niż blokowanie ruchu przy problemach z cache table).
>   • `lookupExternalRef()` / `storeExternalRef()` — idempotency z SHA-256 request_hash.
>   • `generateKey()` — factory dla UI Settings (Sesja 7.5): 8-char prefix + 48-hex (192-bit) secret. Raw secret zwracany **1×** — potem niedostępny.
> - **Refaktor `api/gateway/intake.php` v2** — 13-stepowy pipeline:
>   1. PARSE JSON → 2. AUTH (X-API-Key) → 3. RATE LIMIT → 4. RESOLVE SOURCE (payload OR key, whitelist) → 5. SCHEMA VALIDATE per source → 6. IDEMPOTENCY (external_id) → 7. TENANT ACTIVE → 8. CartEngine recalculate → 9. MIN ORDER (skip for kiosk) → 10. BUSINESS HOURS (skip for pos_3rd/internal) → 11. REQUESTED TIME BOUNDS → 12. GEOFENCING (opt.) → 13. PERSIST (sh_orders + lines + audit + m026 event + external ref — wszystko w jednej transakcji).
>   • **Source whitelist:** `web | mobile_app | kiosk | pos_3rd | public_api | internal | aggregator | aggregator_uber | aggregator_glovo | aggregator_pyszne | aggregator_wolt`.
>   • **Source-binding:** klucz `source='aggregator_uber'` nie puszcza zamówień jako `aggregator_glovo` (credential-stuffing protection). Generyczny `aggregator` może publikować jako dowolny `aggregator_*`.
>   • **Per-source order_number prefixes:** WWW / MOB / KIO / EXT / API / UBR / GLV / PYS / WLT / AGG.
>   • **Per-source schemas:** aggregator/pos_3rd wymagają `external_id`; kiosk nie wymaga `customer_phone`; Delivery wymaga `customer_address`.
>   • **20+ structured error codes** (INVALID_JSON, INVALID_SOURCE, MISSING_FIELD, EMPTY_CART, ITEM_UNAVAILABLE, BELOW_MINIMUM, STORE_CLOSED, INVALID_TIME, OUT_OF_ZONE, AUTH_*, SCOPE_DENIED, SOURCE_MISMATCH, TENANT_INACTIVE, RATE_LIMITED, …).
> - **Setup** — `scripts/setup_database.php` sekcja „Migration 027 — Gateway v2" z idempotent exec + verify 3 tabel + kolumny `sh_orders.gateway_source`.
> - **Dokumentacja** — nowy plik `_docs/10_GATEWAY_API.md` (~400 linii): cel, autoryzacja (new + legacy), rate limiting, idempotency, payload contract, per-source schemas, prefiksy, pipeline, kody błędów, integracja z m026 events, bezpieczeństwo (SHA-256 hashe, timing-safe, IP tracking, source-binding, request_hash), **przykład Uber Eats end-to-end**, roadmap, debugowanie SQL.
> - **Kolejne sesje:** 7.3 Webhook Worker (cron) → 7.4 Integration Adapters → 7.5 UI Settings → Integrations → 7.6 Public read endpoints (`/api/gateway/menu.php`, `/order_status.php`) → 7.7 IP allowlist per key + HMAC-signed incoming requests.
>
> **Aktualizacja 2026-04-18 · sesja 7.3 — Webhook Dispatcher · HMAC + backoff + DLQ**
> - **Problem:** Eventy z m026 lądowały w `sh_event_outbox` ale **nikt ich nie konsumował**. Brakowało async workera który pushuje do 3rd-party subscriberów (Papu, Dotykacka, Slack, custom analytics) z retrami i bezpieczeństwem.
> - **Rozwiązanie:** Cron-based pull worker z atomic claim + HMAC-SHA256 signed POST + exponential backoff + dead letter queue.
> - **Core service** — `core/WebhookDispatcher.php` (~430 linii):
>   • `runBatch()` — public entry point: claim → match subscribers → deliver → log → mark.
>   • `claimPendingEvents()` — SELECT `status='pending' AND next_attempt_at <= NOW()` ORDER BY id ASC LIMIT 50, potem per-row atomic `UPDATE … WHERE status='pending'` + `rowCount()==1` guard (race-safe dla multi-worker).
>   • `findSubscribers()` — lookup w `sh_webhook_endpoints` z filtrem `events_subscribed` (JSON array lub wildcard `["*"]`).
>   • `deliverToSubscriber()` — HMAC-SHA256 signature (`t={ts},v1={hex}` gdzie `hmac = HMAC(secret, "{ts}.{body}")`), cURL POST z headerami `X-Slicehub-Event / -Delivery / -Signature / -Attempt`, audit do `sh_webhook_deliveries` (http_code, response_body truncated 2KB, error_message, duration_ms).
>   • **Klasyfikacja błędów:** 2xx delivered | 408/429/5xx/0 transient retry | inne 4xx permanent (straight to dead).
>   • **Exponential backoff:** 30s → 2m → 10m → 30m → 2h → 6h → 24h (schedule w stałej `BACKOFF_SCHEDULE`). `MAX_ATTEMPTS_DEFAULT=6` → `status='dead'`.
>   • **Auto-pause:** endpoint z `consecutive_failures >= max_retries` → `is_active=0`, reset na 2xx → `consecutive_failures=0 + last_success_at=NOW()`.
>   • **Isolation:** 1 failed endpoint nie blokuje eventu dla innych (event retry całościowo, ale subscriberzy idempotencyują po `event_id`).
>   • **Injectable HTTP transport** (konstruktor przyjmuje callable) → unit testy + `--dry-run` bez prawdziwego cURL.
>   • **Feature-detect:** brak tabel outbox → no-op (nie crashuje).
> - **CLI worker** — `scripts/worker_webhooks.php` (~170 linii):
>   • Flagi: `--loop`, `--sleep=N`, `--batch=N`, `--dry-run`, `--max-batches=N`, `-v`, `--help`.
>   • **Tryby:** single batch (cron-friendly, exit po batchu) | continuous loop (systemd, graceful shutdown na SIGTERM/SIGINT via `pcntl_async_signals`).
>   • **PID-lock** (`logs/worker_webhooks.pid` + `flock(LOCK_EX|LOCK_NB)`) → drugi instance na tym samym node exit 2.
>   • **Exit codes:** 0 OK / 1 DB/config / 2 locked / 3 runtime exception.
>   • **Adaptive sleep:** pusty batch → sleep pełny; batch-full → 100ms (chomikuj backlog).
> - **Dokumentacja** — nowy plik `_docs/11_WEBHOOK_DISPATCHER.md` (~500 linii): diagramy architektury, signature format, weryfikacja w Node.js + PHP (subscriber sample code), backoff table, DLQ operations, monitoring SQL (health check / top failing / throughput), cron + systemd setup, decyzje projektowe.
> - **Kolejne sesje:** 7.4 `core/Integrations/*Adapter.php` (PapuAdapter rozszerzenie + DotykackaAdapter + GastroSoftAdapter pullują event z outbox i pushują do swoich API → pod spodem ten sam dispatcher ale z per-provider logic) → 7.5 UI Settings panel dla webhooków + DLQ management (replay, delete, test endpoint) → 7.6 per-delivery retry state (nie per-event) → 7.7 secret rotation (v1/v2 key versioning).
>
> **Aktualizacja 2026-04-18 · sesja 7.4 — Integration Adapters (m028): Papu + Dotykacka + GastroSoft**
> - **Problem:** WebhookDispatcher (7.3) rozsyła eventy jako generyczny HTTP POST — ale restauracje mają **konkretne systemy POS** z własnym formatem payloadu, własną autentykacją i własną semantyką response'u. Papu oczekuje `{external_order_id, items: [{sku, unit_price, modifiers}]}`, Dotykacka `{_items: [{_productId, unitPrice}]}`, GastroSoft polskich kodów płatności `GOTOWKA/KARTA`. Potrzebujemy warstwy per-provider mapowania.
> - **Rozwiązanie:** Niezależny async consumer outboxa (oddzielny worker, oddzielny state) z pluggable adapterami.
> - **Architektura:**
>   ```
>   sh_event_outbox (m026)
>     ├─► worker_webhooks.php     → sh_webhook_endpoints     → sh_webhook_deliveries    (generic)
>     └─► worker_integrations.php → AdapterRegistry          → sh_integration_deliveries (concrete)
>                                       ├── PapuAdapter
>                                       ├── DotykackaAdapter
>                                       └── GastroSoftAdapter
>   ```
>   Workery **nie koordynują się** — webhook worker zarządza `sh_event_outbox.status`, integration worker trzyma własny stan. Skalują niezależnie.
> - **Migracja 028** — `database/migrations/028_integration_deliveries.sql`:
>   • `sh_integration_deliveries` — per (event × integration) state: status ENUM(pending/delivering/delivered/failed/dead), attempts, next_attempt_at, last_error, http_code, duration_ms, `external_ref` (ID po stronie 3rd-party — używane przy status-update callach), request_payload JSON (snapshot 3rd-party shape, nie nasz envelope), response_body TEXT (2KB), UNIQUE(event_id, integration_id).
>   • `sh_integration_attempts` — full historia prób: delivery_id (FK), attempt_number, http_code, duration_ms, request_snippet (500B), response_body (2KB), error_message, attempted_at. Debug timeline dla flaky adapterów.
>   • Rozszerzenie `sh_tenant_integrations` (additive, idempotent): consecutive_failures / last_failure_at / max_retries (6) / timeout_seconds (8).
> - **BaseAdapter (kontrakt)** — `core/Integrations/BaseAdapter.php` (~220 linii):
>   • `providerKey()`, `displayName()` — statyczne identyfikatory.
>   • `buildRequest(envelope)` → `[method, url, headers, body]` — mapowanie eventu na HTTP request.
>   • `parseResponse(code, body, transportErr?)` → `[ok, transient, externalRef, error]` — interpretacja response'u (provider-specific semantyka).
>   • `supportsEvent(eventType)` — filtr z `events_bridged` JSON.
>   • Helpers: `credentials()` (parsed JSON cache), `apiBaseUrl()`, `grToPln(int)`, `requireCredential(key)` (rzuca AdapterException = permanent DLQ), `extractOrderSnapshot(envelope)`, `extractOrderLines(envelope)`, public gettery dla dispatchera (integrationId, tenantId, timeoutSeconds, maxRetries).
> - **AdapterRegistry** — `core/Integrations/AdapterRegistry.php`:
>   • `PROVIDER_MAP`: `papu → PapuAdapter`, `dotykacka → DotykackaAdapter`, `gastrosoft → GastroSoftAdapter`.
>   • `resolveForTenant(pdo, tenantId)` — ładuje aktywne wiersze z `sh_tenant_integrations` (direction IN push/bidirectional, is_active=1), instancjonuje adaptery, cache per tenant. Feature-detect fallback gdy brak kolumn health (m028 nie uruchomiona).
>   • `availableProviders()` — dla UI Settings (7.5) dropdown.
>   • `registerProvider(key, class)` — runtime extensibility (plugin integracje bez modyfikacji MAP).
> - **PapuAdapter** (~140 linii):
>   • Event routing: `order.created → POST /orders`, `order.edited → PATCH /orders/{id}`, `order.cancelled → POST /orders/{id}/cancel`, `order.ready/delivered/completed → PATCH /orders/{id}/status`.
>   • Credentials: `api_key` (wymagane), `api_secret` (opcjonalne → HMAC `X-Papu-Signature: t={ts},v1={hex}`), `tenant_ext` (opcjonalne → `X-Papu-Tenant`).
>   • **Custom parseResponse:** `HTTP 200 + {ok:false}` = permanent error (Papu semantyka validation fail na sukces HTTP).
>   • Pełny mapping lines z modifiers/removed/vat/comment. Kwoty w PLN stringach "12.34" (nie grosze).
> - **DotykackaAdapter** (~180 linii):
>   • OAuth2 Bearer flow — MVP używa `refresh_token` bezpośrednio jako Bearer (Dotykacka API docs: refresh tokens long-lived). Pełen OAuth2 z persistent `access_token` cache w Sesji 7.5–7.6.
>   • Event routing: `order.created → POST /clouds/{cloudId}/documents` (document type=invoice), `order.ready/completed → PATCH /documents/{extRef}` z `_completed=true`, `order.cancelled → DELETE /documents/{extRef}`.
>   • Credentials: `client_id`, `refresh_token`, `cloud_id` (wymagane), `branch_id`, `access_token`, `access_token_expires_at` (opcjonalne cache).
>   • **Custom parseResponse:** HTTP 401 → transient (token expired, następny retry odświeży), invaliduje cache instance'a.
>   • Payload shape: `_items` (Dotykacka konwencja z leading underscore), payment method mapping (CASH/CARD/ONLINE/OTHER), tags `['slicehub', source]`.
> - **GastroSoftAdapter** (~170 linii):
>   • Event routing: `order.created → POST /restaurants/{code}/orders`, `order.cancelled → DELETE /restaurants/{code}/orders/{orderNumber}`.
>   • Credentials: `api_key`, `restaurant_code` (wymagane), `terminal_id` (opcjonalne → `X-Terminal-Id` dla mapowania na fizyczny POS).
>   • **Custom parseResponse:** HTTP 409 Conflict = idempotency success (duplikat, zwraca `existing_order_id` jako externalRef).
>   • Payload shape: polskie nazwy pól dla payment (GOTOWKA/KARTA/ONLINE/INNA), mapowanie kanałów (POS→ONSITE, Takeaway, Delivery), flatten modifiers do string `"+ ser mozzarella, - cebula"`.
> - **IntegrationDispatcher** — `core/Integrations/IntegrationDispatcher.php` (~430 linii):
>   • `runBatch()` — public entry point, pobiera kandydatów z outboxa + dla każdego iteruje adapterami tenanta.
>   • `collectCandidates(limit)` — SELECT eventów z ostatnich 24h dla tenantów z aktywnymi integracjami (bez atomic claim; zabezpieczenie przez `UNIQUE(event_id, integration_id)` + silent retry przy violation).
>   • `deliverOnce(adapter, envelope, deliveryRow, event)` — upsert delivery row + buildRequest (AdapterException → `dead`, inny throw → `failed+retry`) + injectable HTTP transport + parseResponse + update state.
>   • **Backoff schedule:** 30s / 2m / 10m / 30m / 2h / 6h / 24h (identyczny jak webhook dispatcher). Max 6 attempts → `dead`.
>   • **Auto-pause:** `consecutive_failures >= max_retries` → `is_active=0` (integracja przestaje być resolvowana).
>   • **External ref handling:** sukces `order.created` zapisuje `externalRef` do `sh_integration_deliveries.external_ref`. Status-update eventy (DotykackaAdapter `order.ready`) ekstraktują z envelope context lub `order.gateway_external_id`; worker→adapter lookup pełnego extRef z poprzedniej dostawy jest w roadmap 7.5.
>   • Feature-detect: brak m028/m026 → no-op (nie crashuje).
> - **CLI worker** — `scripts/worker_integrations.php` (~170 linii):
>   • Flagi: `--loop`, `--sleep=N` (default 10), `--batch=N` (default 50), `--dry-run`, `--max-batches=N`, `-v`, `--help`.
>   • Tryby: single batch (cron) | continuous loop (systemd, SIGTERM graceful).
>   • PID-lock (`logs/worker_integrations.pid` + `flock LOCK_EX|LOCK_NB`) — drugi instance exit 2.
>   • Exit codes: 0 OK / 1 DB error / 2 locked / 3 exception.
>   • Adaptive sleep: batch-full → 100ms, pusty → pełen sleep.
>   • Dry-run: fake HTTP transport zwraca `{id:"dry-run-ext-id", dry_run:true}` + verbose STDERR z method/url/body preview.
> - **Setup hook** — `scripts/setup_database.php` sekcja „Migration 028 — Integration Deliveries" z idempotent exec + verify 2 tabel + weryfikacja health columns na `sh_tenant_integrations`.
> - **Dokumentacja** — nowy plik `_docs/12_INTEGRATION_ADAPTERS.md` (~340 linii): architektura z diagramem, schemat DB, flow dostawy (9 kroków), backoff table, auto-pause, szablon „jak dodać nowy adapter", credentials shape per provider, CLI + cron + systemd, monitoring SQL (queue / DLQ / per-event timeline / health), decyzje projektowe (niezależny state per-consumer, candidate query bez atomic claim, external_ref store, legacy PapuClient coexistence), roadmap (7.5–7.9).
> - **Co legacy zostaje bez zmian:** `core/Integrations/PapuClient.php` (fire-and-forget z POS finalize, auto-creating `sh_integration_logs`) nadal działa. Nowe adaptery **uzupełniają**, nie zastępują. Deprecation w 7.6+ gdy event-driven flow złapie production.
> - **Kolejne sesje:** 7.5 UI Settings — Integrations panel (aktywacja provider + wklej credentials + „Test Ping" + DLQ replay + monitoring dashboards, credentials encrypted at rest via libsodium) → 7.6 Dotykacka pełny OAuth2 z persistent access_token cache + background refresh task → 7.7 reverse direction (webhook endpointy PRZYJMUJĄCE pushy od Papu/Dotykacka, gdy status zmienia się u nich) → 7.8 DLQ replay UI → 7.9 aggregator adapters (Uber Eats / Glovo / Pyszne z direction='bidirectional').
>
> **Aktualizacja 2026-04-18 · sesja 7.5 — Settings Panel: Integrations/Webhooks/API Keys/DLQ + CredentialVault**
> - **Problem:** konfiguracja event-system po sesjach 7.1–7.4 wymagała INSERT/UPDATE manualnie w SQL (tenant integrations, webhook endpointy, gateway API keys). Brak "Test Ping" = manager nie wie czy provider odpowiada zanim faktyczne zamówienie padnie. Brak DLQ replay = dead events wymagały ręcznego UPDATE w DB. Wszystkie credentials (API keys, OAuth tokens, HMAC secrets) trzymane plaintext w JSON.
> - **Rozwiązanie:** unified admin panel (`modules/settings/`) + unified action dispatcher (`api/settings/engine.php`) + encryption-at-rest layer (`core/CredentialVault.php`).
> - **CredentialVault — `core/CredentialVault.php` (~250 linii):**
>   • Transparent AEAD encryption (libsodium **XChaCha20-Poly1305**) dla wrażliwych pól: `sh_tenant_integrations.credentials`, `sh_webhook_endpoints.secret`.
>   • Format: `vault:v1:<base64(nonce[24] || ciphertext || tag[16]))>` — prefix pozwala na legacy plaintext coexistence (BEZ prefixu → decrypt zwraca as-is).
>   • Key lookup: `$GLOBALS['SLICEHUB_VAULT_KEY']` (testy) → env `SLICEHUB_VAULT_KEY` (prod) → `config/vault_key.txt` (0600). Generowanie: `bin2hex(random_bytes(32))`.
>   • **Graceful degradation:** brak libsodium lub klucza → `encrypt()` zwraca plaintext z warning do `error_log` (panel Settings pokazuje „⚠️ PLAINTEXT" badge w topbar). Aplikacja nie crashuje, admin od razu widzi status.
>   • `BaseAdapter::credentials()` + `WebhookDispatcher::performDelivery()` — transparent decrypt przy odczycie (`isEncrypted($raw)` check + fallback na null z warning gdy bad ciphertext).
>   • `bootstrapKey()` — factory dla setup scripts (generuje + zapisuje do `config/vault_key.txt` 0600).
> - **Backend — `api/settings/engine.php` (~650 linii):**
>   • Unified action dispatcher (wzorzec POS/KDS/Courses engine): `integrations_list|save|toggle|delete|test_ping`, `webhooks_list|save|toggle|delete|test_ping`, `api_keys_list|generate|revoke`, `dlq_list|dlq_replay`, `health_summary`.
>   • Każda akcja tenant-scoped (auth_guard.php → `$tenant_id`), prepared statements, redacted credentials w listach (`"••••(api_key,cloud_id,…)"` + flaga `credentials_encrypted`).
>   • **Test Ping (integrations):** buduje synthetic `order.created` envelope (`_test_ping=true`) → `adapter.buildRequest()` → cURL z real timeoutem → `adapter.parseResponse()` → full report (stage, http_code, transport_error, externalRef, transport_ms, request_preview, response_preview). **Bez persystencji** w outbox/deliveries.
>   • **Test Ping (webhooks):** synthetic envelope → HMAC-SHA256 signature (identyczna jak WebhookDispatcher) → cURL → raport.
>   • **DLQ Replay:** `UPDATE sh_event_outbox / sh_integration_deliveries SET status='pending', attempts=0, next_attempt_at=NOW(), last_error=CONCAT('REPLAY …', old_error) WHERE status='dead'`. `sh_integration_attempts` nigdy nie czyszczony (pełna historia). Worker weźmie w następnym batchu.
>   • **Secret-once flow:** create/rotate zwraca raw secret (webhook) albo `full_key` (Gateway API) RAZ w responsie, UI pokazuje modalny reveal z clipboard-copy. Re-list response nigdy nie zawiera raw sekretów.
>   • **Health summary:** vault status (libsodium + key), outbox stats grouped by status (7d), webhook endpoints (total / active / auto-paused), integrations per provider z `consecutive_failures`, gateway API keys (total / active).
> - **UI — `modules/settings/` (~900 linii):**
>   • `index.html` (~40 linii) + `css/style.css` (~380 linii, dark theme spójne z KDS/POS, CSS vars) + `js/settings_app.js` (~600 linii single-file vanilla).
>   • 5 zakładek: **Integrations** (add/edit/toggle/delete + Test Ping), **Webhooks** (same + rotate_secret), **API Keys** (generate + revoke), **Dead Letters** (separate lists per channel, per-item Replay button), **Health** (vault/outbox/webhooks/api_keys/integrations grid).
>   • Vault status badge w topbar (🔒 zielony = ready / ⚠️ pomarańczowy = PLAINTEXT).
>   • Modal editors z pełnymi formularzami (provider picker, events comma-separated input, credentials JSON textarea, direction select, timeout/retries numeric), toast notifications, confirm dialogs, clipboard integration.
>   • Zero build step, zero npm, FontAwesome via CDN, mobile responsive.
> - **Bezpieczeństwo:** tenant_id wyłącznie z sesji, prepared statements, redacted secrets w responsach, SHA-256 hashe dla API key secrets, HTTPS SSL verify w Test Ping, `Cache-Control: no-store`, `X-Slicehub-Test: 1` header w test pingach (subscriber rozpoznaje dry-run).
> - **Verification:** `php -l` wszystkie nowe pliki pass. Smoke test CredentialVault roundtrip (plaintext passthrough legacy, bad ciphertext → null, proper encrypt/decrypt when sodium available). Smoke test engine.php → 401 bez auth (auth_guard działa).
> - **Dokumentacja:** `_docs/13_SETTINGS_PANEL.md` (~420 linii): architektura z diagramem, CredentialVault format + bootstrap, wszystkie actions z request/response shape, Test Ping stages, DLQ replay semantics, UI secret-once flow, security checklist, deployment guide (3 opcje dla vault key), monitoring SQL, design decisions (unified engine vs split files, secret-once flow, test ping without persistence, vault key rotation strategy), roadmap 7.6+ (CSRF, rate limit ping, audit log, delivery inspector, key rotation job, scope picker UX, multi-tenant admin view).
> - **Co zostało do zrobienia:** CSRF tokens dla mutacji (obecnie tylko cookie session), rate limit na `test_ping` (max 5/min per endpoint), audit log `sh_settings_audit` (7.6).
> - **Kolejne sesje:** 7.6 Dotykacka pełny OAuth2 z persistent access_token cache + background refresh task → 7.7 reverse direction webhooks (Papu/Dotykacka → SliceHub status updates) → 7.8 delivery inspector UI (pełna timeline HTTP requestów z paginacją) → 7.9 aggregator adapters (Uber Eats / Glovo / Pyszne).
>
> **Aktualizacja 2026-04-18 · sesja 7.6 — Infrastructure Completion (✅ Faza 7 READY FOR FUTURE CONNECTIONS)**
> - **Problem:** po 7.5 zostały luki w warstwie infrastruktury (security/compliance + symetria integracji): brak CSRF tokenów, brak rate-limitu na Test Ping (DoS vector), brak audit-log'u mutacji w Settings Panel, brak generic inbound callback framework (providerzy nie mogą nam pushować statusów), brak skryptu do bootstrap'u klucza vault + migracji legacy plaintext credentials.
> - **Rozwiązanie:** zamknięcie Fazy 7 w jednej sesji — pięć niezależnych komponentów + migracja 029 + dokumentacja.
> - **Migracja 029 — `database/migrations/029_infrastructure_completion.sql`:**
>   • `sh_settings_audit` — audit trail dla mutacji Settings Panel (user_id, actor_ip, action, entity_type/id, before_json/after_json z redact'em secretów).
>   • `sh_inbound_callbacks` — surowy log przychodzących callbacków od 3rd-party (provider, external_event_id, external_ref, event_type, mapped_order_id, raw_headers, raw_body, signature_verified, status, received_at/processed_at). `UNIQUE(provider, external_event_id)` dla idempotency — retry od providera wraca 200 OK bez re-processingu.
> - **Inbound Callback Framework — `api/integrations/inbound.php` + `BaseAdapter::parseInboundCallback()`:**
>   • Generic receiver: `POST /api/integrations/inbound.php?provider=<key>&integration_id=<n>`. Publiczny (auth przez HMAC signature w headerze).
>   • Flow: INSERT log do `sh_inbound_callbacks` PRZED walidacją (bad sigs też lądują dla debug) → lookup `sh_tenant_integrations` + decrypt credentials przez CredentialVault → dispatch do adaptera → verify signature + map status → idempotency check `(provider, external_event_id)` → match `external_ref` z `sh_orders.gateway_external_id` → whitelist transition UPDATE `sh_orders.status` (identyczne reguły jak `OrderStateMachine`) → `OrderEventPublisher::publishOrderLifecycle()` dla wewnętrznych listenerów (KDS, Driver panel, notifications) → UPDATE `sh_inbound_callbacks.status='processed'`.
>   • `BaseAdapter::supportsInbound(): bool` (default false) + `BaseAdapter::parseInboundCallback($rawBody, $headers, $credentials)` z kontraktem `{ok, signature_verified, external_event_id, external_ref, event_type, new_status, payload, error?}`.
>   • **PapuAdapter** pełna reference implementation: HMAC-SHA256 (`X-Papu-Signature: t=<ts>,v1=<hmac>`, replay-window 5 min, timing-safe `hash_equals`), mapowanie statusów Papu → naszych event types. Dotykacka/GastroSoft — `supportsInbound()=false` (rzucają 501 aż ktoś doimplementuje).
>   • Response codes: 200 OK (processed / duplicate), 401 (bad sig), 404 (integration not found), 422 (bad payload), 501 (provider nie ma inbound), 500 (exception). Zachowuje raw body + headers w `sh_inbound_callbacks` nawet przy 401/500.
> - **CSRF tokens w `api/settings/engine.php`:**
>   • `settings_csrfCheck($action)` — whitelist akcji READ-ONLY (`*_list`, `health_summary`, `csrf_token`) pominięty. Reszta wymaga headera `X-CSRF-Token` porównywanego przez `hash_equals` z `$_SESSION['settings_csrf_token']`.
>   • Nowa akcja `csrf_token` (GET/POST): generuje `bin2hex(random_bytes(24))` i zapisuje w session, zwraca `{token, header}` do klienta. UI (`modules/settings/js/settings_app.js` — nie modyfikujemy go w 7.6, integracja client-side w 7.7) ma zawsze pobrać token przed pierwszą mutacją.
> - **Rate-limit Test Ping:**
>   • `settings_rateLimitTestPing($pdo, $tenantId)` — zlicza w `sh_settings_audit` ile było `*_test_ping` w ostatnich 60 sekundach. Powyżej 5 → 429 Too Many Requests. Zero dodatkowych tabel, reuse audit log.
>   • Fail-open gdy `sh_settings_audit` niedostępne (m029 niezmigrowane) — lepiej działać bez limitu niż crashować stary deploy.
> - **Audit auto-logging w `engine.php`:**
>   • `settings_audit($pdo, $tenantId, $userId, $action, $entityType, $entityId, $before, $after)` — dodane do wszystkich mutacji: `integrations_save/toggle/delete`, `webhooks_save/toggle/delete`, `api_keys_generate/revoke`, `dlq_replay`.
>   • Automatyczny redact wrażliwych pól: `credentials`, `secret`, `key_secret_hash`, `api_key`, `api_secret`, `refresh_token`, `access_token`, `full_key` → `••••(redacted)` w zapisie JSON.
>   • Before/after dla UPDATE (SELECT before + execute UPDATE + SELECT after → insert), for INSERT tylko after, dla DELETE tylko before.
> - **Vault Scripts — `scripts/bootstrap_vault.php` + `scripts/rotate_credentials_to_vault.php`:**
>   • **bootstrap_vault.php:** generuje 32-byte XChaCha20 klucz, zapisuje do `config/vault_key.txt` (0600). Flagi: `--force` (overwrite z warning), `--print-only` (stdout). Abortuje gdy klucz już jest (prevent data loss). Sprawdza libsodium extension, wyświetla next steps (backup key off-server, verify isReady, rotate credentials).
>   • **rotate_credentials_to_vault.php:** skanuje `sh_tenant_integrations.credentials` + `sh_webhook_endpoints.secret`. Dla każdego plaintext rekordu (nie zaczyna się od `vault:v1:`) robi `encrypt()` + self-test `decrypt()` roundtrip. Jeśli roundtrip ok → UPDATE (tylko w `--live` mode). Flagi: `--dry-run` (default), `--live`, `--only=integrations|webhooks`. Idempotent — ponowne wykonanie pomija już zaszyfrowane.
> - **setup_database.php hook:** nowa sekcja "Migration 029 — Infrastructure Completion" idempotent exec + verify `sh_settings_audit` + `sh_inbound_callbacks` istnieją po migracji.
> - **Dokumentacja:**
>   • `_docs/14_INBOUND_CALLBACKS.md` (~250 linii): architektura flow z ASCII diagramem, tabela `sh_inbound_callbacks` schema, adapter kontrakt, reference Papu implementation, status mapping table, transition whitelist, URL + headers dla providerów, testing (curl smoke test), expected HTTP responses, debugging SQL, checklista dla nowego providera, roadmap (IP whitelist, OAuth2, webhook auto-registration).
>   • `_docs/13_SETTINGS_PANEL.md` zaktualizowany — sekcja "Roadmap" rozdzielona na DONE (7.6) vs otwarte, nowe sekcje "CSRF flow" + "Audit log" z przykładowym SQL.
>   • `_docs/00_PAMIEC_SYSTEMU.md` zaktualizowany — wpisy CredentialVault/Settings Panel rozszerzone o 7.6, nowe wpisy "Inbound Callbacks" i "Vault Scripts".
> - **Verification:** `php -l` wszystkie nowe/zmodyfikowane pliki pass. Smoke test inbound.php — 400 bez provider/integration_id, 405 na GET, 404 na non-existent integration.
> - **Status Fazy 7:** ✅ **GOTOWA NA FUTURE CONNECTIONS** — infrastruktura event-driven symetryczna (outbound + inbound), zabezpieczona (CSRF + rate limit + vault + audit), operable przez UI (Settings Panel) + CLI (bootstrap/rotate scripts). Konkretne integracje (pełny OAuth2 Dotykacki, Uber/Glovo adapters) można dołożyć gdy będzie to faktycznie potrzebne — infra ich nie spowalnia.
> - **Kolejne sesje:** 7.7 (CSRF client integration w settings_app.js + delivery inspector UI), 8.0 (start Fazy 8 — Kitchen Display System deep integration), albo opcjonalne 7.8 (gdy klient faktycznie chce się integrować: Dotykacka OAuth2 Refresh, Uber Eats adapter, Glovo adapter).
>
> **Aktualizacja 2026-04-18 · sesja 2.10 — Cleanup (m025): legacy Magic Dictionary & Ingredient Assets**
> - **Decyzja:** system jest jeszcze w fazie tworzenia — usuwamy dług techniczny od razu, bez okresu przejściowego. Dwa równoległe źródła prawdy (nowy sh_asset_links + legacy sh_modifier_visual_map) były wyłącznie źródłem zamieszania.
> - **Migracja 025** — `database/migrations/025_drop_legacy_magic_dict.sql`:
>   • DROP TABLE `sh_modifier_visual_map` (m018)
>   • DROP TABLE `sh_ingredient_assets` (m014b)
>   • DROP VIEW `v_modifier_icon`
>   • DELETE z `sh_asset_links` rekordów z `role = 'modifier_icon'` (backfill z m021 sekcja 4)
> - **Kod wycięty:**
>   • `api/online/engine.php` — flaga `$hasMagicMap`, blok `magicDict` w `get_dish` i `get_scene_dish`, pole `magicDict` w response.
>   • `api/online_studio/engine.php` — akcje `magic_list`, `magic_save`, `magic_clear`, `magic_auto_match` (zwracają teraz deprecation error).
>   • `modules/online_studio/js/tabs/magic.js` — usunięty plik w całości.
>   • `modules/online_studio/js/studio_app.js` + `studio_api.js` — import `mountMagic`, `magicList/Save/Clear/AutoMatch`, `Studio.magic`, `refreshMagic`, tab nav, keyboard shortcut, deep-link.
>   • `modules/online_studio/index.html` — zakładka „Modifier Mapper" + `<section id="tab-magic">`.
>   • `modules/online_studio/css/studio.css` — cały blok `.magic*` Magic Dictionary (nie mylić z `.dt-magic-*` Director AI enhancements, które zostają).
>   • `modules/online/js/online_ui.js#resolveModifierVisual` — fallback na `magicDict` usunięty; nowy kontrakt `modifierVisuals` (m021+m024) jest jedynym źródłem prawdy.
>   • `modules/online/js/online_table.js#adaptSceneDishToLegacy` — pole `magicDict` usunięte.
>   • `core/AssetResolver.php` — metody `batchModifierIcons()` + `injectModifierIcons()` usunięte (modyfikatory nie mają dedykowanych ikon).
>   • `api/assets/engine.php` — z whitelisty `AE_LINK_ROLES` usunięto `modifier_icon`, dodano `modifier_hero`.
>   • `modules/online_studio/js/tabs/assets.js` — pickerz ról dla `modifier` entity_type: zamiast `modifier_icon` + `thumbnail` → `layer_top_down` + `modifier_hero` + `thumbnail`.
>   • `api/backoffice/api_menu_studio.php` — akcje `get_ingredient_assets`, `save_ingredient_asset`, `delete_ingredient_asset`, `get_board_context` (wszystkie 100% osierocone, nikt nie wołał).
>   • `scripts/setup_database.php` — usunięte sekcje m014b (Ingredient Assets) i m018 (Magic Dictionary), widok `v_modifier_icon` z verify-listy, tabela `sh_ingredient_assets` z verify-listy. Dodana nowa sekcja m025 z idempotentnym exec + verify (DROPped, role count = 0).
>   • Pliki migracji `014_ingredient_assets.sql` + `018_modifier_visual_map.sql` przeniesione do `database/migrations/_archive_*.sql` (historia zachowana, nowe instalacje ich nie wywołują).
> - **Rezultat:** jeden (i tylko jeden) mechanizm modyfikator→wizualia: Menu Studio → Modifier Editor → „Surface — wizualne sloty" → `sh_asset_links` (role `layer_top_down` + `modifier_hero`) + `sh_modifiers.has_visual_impact` → `SceneResolver::resolveModifierVisuals` → `modifierVisuals` w API online → `online_ui.js#resolveModifierVisual`.
>
> **Aktualizacja 2026-04-18 · sesja 6.2 — Status Dictionary Unification**
> - Usunięta **destrukcyjna** linia w `api/courses/engine.php` (auto-migracja `UPDATE status='pending' WHERE status IN ('new','accepted')`) — to zjadało nowe zamówienia z web/POS przy każdym starcie courses engine bez kolumny `delivery_status`.
> - `track_order` rozszerzone: czyta `delivery_status` (jeśli kolumna istnieje) i mapuje na `logicalStatus` dla klienta (delivered → completed, in_delivery → in_delivery; dla takeaway/pickup timeline pomija stage `in_delivery`).
> - Response zawiera `rawStatus` + `deliveryStatus` dla debugowania/audyty; `status` to zawsze logical view dla UI.
> - Frontend `track.css`: timeline grid zmieniony na `grid-auto-flow: column` (działa dla 5 stages w pickup flow jak i 6 dla delivery).
> - Nowy dokument `_docs/08_ORDER_STATUS_DICTIONARY.md` — kanoniczny słownik statusów (pipeline ASCII, tabela transitions, reguły logical mapping, rekord deprecacji, roadmap migracji POS do słownika).
>
> **Aktualizacja 2026-04-18 · sesja 2.9 — Faza 2 Verification & Integration (✅ FAZA 2 UKOŃCZONA)**
> - **Weryfikacja:** audit potwierdził, że Modifier Editor z dual-slots (`modules/studio/js/studio_modifiers.js` sekcja „Surface — wizualne sloty" checkbox + 2 selecty) oraz Category Scene Editor (`modules/studio/js/studio_category_table.js` drag&drop) były już **w pełni zaimplementowane** od strony backendu (`api/backoffice/api_menu_studio.php` akcje `save_modifier_group` z `layerTopDownAssetId`/`modifierHeroAssetId` + `save_category_scene_layout`) i frontendu. Brakowało jedynie integracji z runtime storefrontem.
> - **Backend integracja:** nowa metoda `SceneResolver::resolveModifierVisuals(PDO, int $tenantId, array $modSkus)` — czyta `sh_asset_links` (role `layer_top_down` + `modifier_hero`) + flagę `sh_modifiers.has_visual_impact`, zwraca mapę `{sku: {hasVisualImpact, layerAsset, heroAsset}}` z publicznymi URL-ami przez AssetResolver. Cicho degraduje gdy AssetLibrary m021 niegotowa.
> - Obie akcje `get_dish` i `get_scene_dish` w `api/online/engine.php` dostają teraz pole `modifierVisuals` w response (legacy `magicDict` zostaje jako fallback dla starszych tenantów nie zmigrowanych do Asset Studio).
> - **Frontend integracja:** w `modules/online/js/online_ui.js` nowa funkcja `resolveModifierVisual(sku, data)` — priorytet: `modifierVisuals` (nowe) > `magicDict` (legacy). `repaintSurface` korzysta z niej przy budowaniu warstw scatter i hero bubbles. `cycleModifier` dispatchuje `CustomEvent('sh:mod-toggled')` + oznacza ostatnio zmieniony SKU w `ctx._lastModChange` żeby renderer zagrał animację „materializacji" tylko na nowo-dodanej bąbelce.
> - **Animacja materializacji** (Faza 2 wizja „The Surface"): klasa `.sc-hero__bubble--new` z keyframes `scBubbleMaterialize` (0.9s: opacity 0 → 1, scale 0.2 → 1.18 → 1, blur 6px → 0, rotate -8deg → 0) + halo `scBubbleHalo` (drop-shadow 24px bursts z amber-gold). Klasa jest konsumowalna (JS zdejmuje na `animationend`).
> - Adapter `adaptSceneDishToLegacy` w `online_table.js` przekazuje `modifierVisuals` do payload legacy UI.
> - **Status Fazy 2:** ✅ **KOMPLETNA** — Scene Kit editor (sesja 2.7), Category Scene editor (już było), Modifier Visual Slots editor (już było) + runtime integracja (sesja 2.9). System authoringowy dla Menu Studio ma „ręce i nogi" — manager może skonfigurować pełną scenografię i modyfikatory z wizualnymi slotami, a storefront The Table prezentuje je natychmiast z pop-in animacją.

---

## 1. Co zostało zbudowane

### 1.1. Warstwa danych (migracja 022)

**Nowa infrastruktura schematu — [database/migrations/022_scene_kit.sql](../database/migrations/022_scene_kit.sql)**

**8 nowych tabel:**
| Tabela | Cel | Stan |
|--------|-----|------|
| `sh_scene_templates` | Biblioteka szablonów scenografii (pizza_top_down, static_hero, ...) | ✅ Schema + 8 seed rows |
| `sh_promotions` | Definicje promocji (discount / combo / free_item / bundle) | ✅ Schema (logika w Fazie 4) |
| `sh_scene_promotion_slots` | Pozycjonowanie promocji na scenach | ✅ Schema |
| `sh_style_presets` | Biblioteka 12 stylów wizualnych (anime, noir, pop art, ...) | ✅ Schema + 12 seed rows |
| `sh_category_styles` | Aktywny styl per kategoria + audyt kosztów AI | ✅ Schema |
| `sh_scene_triggers` | Reguły automatycznego aktywowania scen (data/godzina/pogoda) | ✅ Schema |
| `sh_scene_variants` | Warianty scen (A/B, AI variants) | ✅ Schema |
| `sh_ai_jobs` | Kolejka zadań AI (style transform / enhance / variant gen) | ✅ Schema (runner w Fazie 4) |

**Rozszerzenia istniejących tabel:**
- `sh_menu_items` + `composition_profile` (VARCHAR 64, default `'static_hero'`)
- `sh_categories` + `default_composition_profile`, `layout_mode` (ENUM grouped/individual/hybrid/legacy_list), `category_scene_id`
- `sh_atelier_scenes` + `scene_kind`, `template_id`, `parent_category_id`, `active_style_id`, `active_camera_preset`, `active_lut`, `atmospheric_effects_enabled_json`
- `sh_board_companions` + `cta_label`, `is_always_visible`, `slot_class`
- `sh_tenant_settings` + klucze AI budget (`ai_monthly_budget_zl` = 50.00 zł default)

**Zasada migracji:** **ADDITIVE ONLY** — zero DROP, zero destrukcyjnych zmian. Idempotentne (safe re-run). Feature detection w endpoints tolerujący starą bazę.

### 1.2. Resolver (core service)

**Nowy plik: [core/SceneResolver.php](../core/SceneResolver.php)** (~610 linii)

Publiczne metody:
- `SceneResolver::resolveDishVisualContract($pdo, $tenantId, $sku, $channel?)` → pełny kontrakt dania (scene_spec, layers, hero, companions, promotions, meta)
- `SceneResolver::resolveCategoryScene($pdo, $tenantId, $categoryId)` → scena kategorii + lista items
- `SceneResolver::resolveCategoryStyle($pdo, $tenantId, $categoryId)` → aktywny styl kategorii
- `SceneResolver::batchResolveForCategory($pdo, $tenantId, $categoryId)` → batch mini-kontraktów
- `SceneResolver::getSceneTemplate($pdo, $asciiKey)` → template lookup (cache per-request)
- `SceneResolver::getStylePreset($pdo, $keyOrId)` → style preset lookup
- `SceneResolver::checkActiveTrigger($pdo, $tenantId, $sceneId)` → runtime check Scene Triggers

**Hierarchia fallback (wbudowana):**
```
hero_url:      sh_asset_links(hero) → sh_menu_items.image_url → null
layers:        sh_atelier_scenes.spec_json.pizza.layers → sh_visual_layers → []
active_style:  scene.active_style_id → sh_category_styles → template.default_style_id → null
```

**Gwarancje:**
- Nigdy nie rzuca wyjątku (wszystkie błędy złapane cicho).
- Cache per-request (templates, styles, column-exists).
- Tolerancja brakujących tabel m022 (fallback do danych z m021 / legacy).

### 1.3. UI Menu Studio

**Zmiany wizualne:**
- **Formularz dania**: nowa sekcja „Wizualna Kompozycja Dania" z miniaturą hero 112×112 + badge „Scena: TAK/BRAK" + select `composition_profile` + opis profilu. Przycisk „Otwórz w Scene Studio" z deep-linkiem `?tab=director&item=SKU`.
- **Pole Legacy URL**: schowane pod zwijany `<details>` „Opcje zaawansowane — integracje zewnętrzne". Nadal zapisuje do `sh_menu_items.image_url` — zero breaking change dla integracji.
- **Modal edycji kategorii**: rozszerzony 420→520px. Nowe pola: `default_composition_profile` (dropdown z sceneTemplates) + `layout_mode` (4 radio z opisami).

**Backend:**
- Nowa akcja `list_scene_templates` z filtrem `kind='item'|'category'`.
- `add_category` / `update_category` przyjmują `layoutMode` + `defaultCompositionProfile`.
- `add_item` / `update_item_full` przyjmują `compositionProfile`.
- `get_item_details` zwraca `compositionProfile`, `hasScene`, `sceneId`.
- `get_menu_tree` zwraca `layoutMode`, `defaultCompositionProfile`, `categorySceneId` per kategoria.

### 1.4. Scene Studio (rebrand + fix deep-linka)

- Sidebar label: „Visual Director" → **„Scene Studio"** (consistent w Menu Studio, Online Studio, deep-linkach)
- Sub-brand: „Director's Atelier" → **„Scene Studio · Hollywood Atelier"**
- Nagłówek stage: „Scene Studio" (+ opis Hollywood-grade)
- **Fix deep-linka**: klik w Menu Studio „Otwórz w Scene Studio" → auto-wybór dania w Directorze (wcześniej wymagał ręcznego kliknięcia)
- Toast przy deep-linku: „🔗 Deep-link z Menu Studio · PIZZA_MARGHERITA" (3.5s)

### 1.5. Migration runner

`scripts/setup_database.php` podpięte migracja 020 (pre-check dla `sh_atelier_scenes`) + 022. Verify sekcja rozszerzona o wszystkie nowe tabele + kolumny.

---

## 2. Walidacja — zero regresji

### 2.1. Smoke test SceneResolver (tenant #1, 5 dań)

```
PIZZA_MARGHERITA    scene=YES layers=5  profile=static_hero
PIZZA_PEPPERONI     scene=YES layers=1  profile=static_hero
PIZZA_CAPRICCIOSA   scene=no  layers=1  profile=static_hero   ← fallback działa
PIZZA_HAWAJSKA      scene=no  layers=1  profile=static_hero   ← fallback działa
PIZZA_4FORMAGGI     scene=YES layers=1  profile=static_hero
```

### 2.2. Zero regresji w legacy API

`POST /api/online/engine.php` action=`get_menu`:
```json
success: true
categories: 9
totalItems: 37
first_item: PIZZA_MARGHERITA, visualLayers: 5
```

Storefront klienta zwraca **identyczne dane** co przed Fazą 1 — żadna inna część systemu nie zauważyła zmian.

### 2.3. Lint / syntax check

- `php -l api/backoffice/api_menu_studio.php` → OK
- `php -l scripts/setup_database.php` → OK
- `php -l core/SceneResolver.php` → OK
- `ReadLints` wszystkich zmodyfikowanych plików JS/PHP/HTML → 0 błędów

### 2.4. Idempotencja migracji

Drugi i trzeci run `setup_database.php` → wszystkie kolumny/tabele pokazują `SKIP (already exists)`, seed `ON DUPLICATE KEY UPDATE`. **Zero błędów przy re-run.**

---

## 3. Co nie zostało dotknięte (świadomie)

Zgodnie z zasadą "Nie ruszamy innych modułów" z rozmowy operacyjnej:

- ❌ `api/online/engine.php` — storefront klienta (nadal działa swoim inline `resolveVisualLayersForSku`)
- ❌ `api/pos/engine.php` — POS
- ❌ `api/kds/*`, `api/delivery/*`, `modules/driver_app/*`, `modules/online/*`, `modules/pos/*`, `modules/kds/*`
- ❌ `api/assets/engine.php`, `api/online_studio/engine.php`
- ❌ Legacy uploaders: `library_upload.php`, `asset_upload.php`, `api_visual_studio.php`, akcja `menu_set_product_image`
- ❌ `sh_menu_items.image_url` — kolumna żyje, nadal zapisywana przez pole Legacy URL w Menu Studio
- ❌ Stare migracje 001–021

**Wszystkie kolejne fazy** (2, 3, 4, 5, 6) będą przepinać te moduły jeden po drugim — świadomie, z Twoją akceptacją.

---

## 4. Ready for Faza 2

### 4.1. Co Faza 2 ma dostępne (bez nowej migracji)

- ✅ Schema dla Scene Kit — możesz wgrać 10 teł + 30 rekwizytów + 8 świateł + 5 badge'ów do `sh_assets` i podpiąć do `sh_scene_templates.scene_kit_assets_json`
- ✅ Schema dla stylów — 12 rekordów z paletami + fontami + motion — wypełnij `ai_prompt_template` + `lora_ref` aby aktywować Style Engine
- ✅ Schema dla promocji — stwórz pierwszą promocję w UI, przypisz slot do sceny
- ✅ Placeholders dla 4 templates — `pasta_bowl_placeholder`, `beverage_bottle_placeholder`, `burger_three_quarter_placeholder`, `sushi_top_down_placeholder` czekają na metadane
- ✅ SceneResolver gotowy do konsumpcji przez klienta — The Table może już teraz wołać API i dostawać pełny kontrakt

### 4.2. Roadmapa Faz (skrót)

| Faza | Cel | Zależne od |
|------|-----|------------|
| **1** ✅ | Fundament danych + resolver + sprzątanie Menu Studio + fix deep-linka | — |
| 2 | Content Scene Kit (produkcja assetów) + edytor CategoryScene w UI + edytor modyfikatora z dwoma slotami | Faza 1 ✅ |
| 3 | The Table (klient) — persistent table, scroll-snap, swipe, interaction contract | Faza 2 (Scene Kit content) |
| 4 | AI Photo Pipeline (invisible) + Category Style Engine (Replicate API) + Cart promotions logic + Cinema Cameras + Mood LUTs + Atmospheric Effects | Faza 2-3 |
| 5 | Guided Camera Mode (mobile PWA) + Scene Triggers runner (cron) + Public API (X-Slicehub-Key) + Photographer Marketplace | Faza 3-4 |
| 6 | Custom brand styles (własna LoRA per tenant) + A/B testing stylów z auto-winner + usuwanie legacy endpointów | Faza 5 |

### 4.3. Dokumenty do zaktualizowania przed Fazą 2

- [x] `_docs/00_PAMIEC_SYSTEMU.md` — migracje 017-022 + SceneResolver w sekcji core
- [x] `_docs/FAZA_1_STATUS.md` — ten plik
- [ ] `_docs/04_BAZA_DANYCH.md` — nowe tabele m022 (do aktualizacji przed zakończeniem Fazy 2)

---

## 5. Harmonogram sesji Fazy 1

| Sesja | Co | Czas |
|-------|-----|------|
| 1 | Migracja 022 + setup_database.php + seed 8 templates + 12 styles | ~25 min |
| 2 | core/SceneResolver.php + smoke test | ~22 min |
| 3 | Menu Studio refactor (studio_item.js, studio_ui.js, api_menu_studio.php, studio_core.js) | ~42 min |
| 4 | Deep-link fix + rebrand Director → Scene Studio | ~15 min |
| 5 | Validation + docs handoff | ~20 min |
| **Razem** | | **~2h 4min** |

---

## 6. Kontakt architektoniczny

W razie wątpliwości co do decyzji technicznych Fazy 1 — czytaj:
- [.cursor/plans/scene_studio_+_the_table_79198723.plan.md](../.cursor/plans/scene_studio_+_the_table_79198723.plan.md) — pełny plan (Interaction Contract, Hollywood Cinema Creator, Manager Empowerment Stack, Scene Triggers, Variants, Persistent Table metaphor, Recommended Presence).
- [database/migrations/022_scene_kit.sql](../database/migrations/022_scene_kit.sql) — komentarze DDL wyjaśniające każde pole.
- [core/SceneResolver.php](../core/SceneResolver.php) — PHPDoc i hierarchia fallback.
