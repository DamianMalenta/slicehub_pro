# RAPORT ARCHITEKTURY TECHNICZNEJ — SliceHub Enterprise OS

> Dokument techniczny pod wniosek o dofinansowanie.  
> Stan kodu: audyt przeprowadzony na żywym repozytorium (**rewizja 2026-05-20**, baza: `main` po scaleniu BI + KSeF v2 + procurement OPEX).

---

## 1. WYIZOLOWANE SILOSY (Moduły DDD) — Fizyczna Mapa Kodu

System SliceHub Enterprise składa się z **17 modułów operacyjnych frontendowych** (+ `ui_shell`, `backoffice`), **20+ domen backendowych API**, **55 migracji SQL** w łańcuchu (`scripts/_migrations_chain.php`), **3 silosów bazodanowych** oraz **warstwy silników domenowych** (`core/`). Każdy moduł jest samodzielną jednostką z własnym interfejsem HTML, logiką JS i dedykowanym API — zgodnie z wzorcem Domain-Driven Design opartym na fizycznej separacji katalogów.

### 1.1 Moduły Frontendowe (`/modules/`)

| # | Moduł | Ścieżka | Przeznaczenie biznesowe |
|---|-------|---------|------------------------|
| 0 | **Hub** | `/modules/hub/` | Panel startowy — kafelki modułów, nawigacja RBAC, link do BI P&L. |
| 1 | **POS** (Point of Sale) | `/modules/pos/` | Terminal sprzedażowy — kafelki menu, koszyk, checkout, rozliczenia, modal wariantów rozmiaru. PWA z trybem offline (`sw.js`, `PosLocalStore`, `PosApiOutbox`, `PosSyncEngine`). |
| 2 | **Menu Studio** | `/modules/studio/` | Backoffice zarządzania menu — CRUD kategorii, dań, receptur, modyfikatorów, macierzy cenowej omnichannel, variant scales (F-S1), kalkulacji marży, edycji masowej. |
| 3 | **Online Studio (Director)** | `/modules/online_studio/` | Wizualny kompozytor scen dla witryny publicznej — reżyser scen (`DirectorApp.js`), panele warstw, Harmony Score, system presetów stylowych. |
| 4 | **Online Storefront** | `/modules/online/` | Publiczna karta menu klienta — checkout gościnny, śledzenie zamówień (SSE real-time), QR otwieranie stolika, PWA offline shell. |
| 5 | **Warehouse** | `/modules/warehouse/` | Moduł magazynowy V2 — stany, dokumenty PZ/RW/INW/KOR/MM, wieża kontrolna (`warehouse_control_tower.html`), workflow zatwierdzania, mapowanie surowców, katalog AVCO. |
| 6 | **Dispatcher (Courses)** | `/modules/courses/` | Centrum logistyki — 3 zakładki (zamówienia/mapa/kursy), dispatch multi-order na kierowców, mapa Leaflet z GPS, rozliczenie kasowe, Emergency Recall. |
| 7 | **Driver App** | `/modules/driver_app/` | Mobilna aplikacja PWA kierowcy — shift, portfel, GPS 15s, Payment Lock, Emergency Alert (flash + wibracje), `manifest.json` standalone. |
| 8 | **Tables (Plan Sali)** | `/modules/tables/` | Plan sali restauracji — strefy, stoliki, otwieranie/zamykanie rachunków, transfery między stolikami. |
| 9 | **Waiter** | `/modules/waiter/` | Mobilny interfejs kelnera — PIN login, obsługa stolików z perspektywy kelnera. |
| 10 | **KDS** (Kitchen Display) | `/modules/kds/` | Tablica kuchenna — siatka ticketów z polling 6s, bump flow (`accepted → preparing → ready`), recall, filtr stacji. |
| 11 | **Settings** | `/modules/settings/` | Panel konfiguracyjny tenanta — integracje, webhooki, klucze API, DLQ, health ping, stawki VAT, payroll. |
| 12 | **Inbox** | `/modules/inbox/` | Skrzynka SMS — wiadomości przychodzące od klientów, odpowiedzi, statystyki. |
| 13 | **Marketing** | `/modules/marketing/` | Kreator kampanii SMS — segmentacja audytorium, wysyłka przez outbox events. |
| 14 | **Procurement (KSeF Inbox)** | `/modules/procurement/` | Skrzynka e-faktur KSeF API v2 — poll metadata, upload FA(2)/FA(3) XML, AutoScan (EXACT/ALIAS/NAME/FUZZY), akceptacja → `PzEngine`, linie INVENTORY vs EXPENSE (OPEX), kategorie kosztów. |
| 15 | **BI (P&L)** | `/modules/bi/` | Dashboard rentowności — przychód netto, COGS z WZ, koszty pracy (`sh_payroll_ledger`), OPEX z faktur KSeF, karta „zamrożony kapitał” (AVCO×stan). |
| 16 | **Kiosk** | `/modules/kiosk/` | Kiosk obecności HR — clock-in/out PIN, integracja z `HrClockEngine`. |
| 17 | **Backoffice HR** | `/modules/backoffice/` | Kadry — pracownicy, stawki, zaliczki (`AdvanceEngine`), payroll. |
| — | **Shared** | `/modules/ui_shell/` | Współdzielone style mobilne (`sh_mobile_shell.css`). |

### 1.2 Domeny Backendowe (`/api/`)

| # | Domena API | Ścieżka | Endpointy | Przeznaczenie |
|---|-----------|---------|-----------|---------------|
| 1 | **Auth** | `api/auth/` | `login.php` | Dwutrybowa autentykacja: `system` (username+password) + `kiosk` (PIN+tenant). Zwraca JWT. |
| 2 | **POS Engine** | `api/pos/` | `engine.php`, `sync.php` | 13-akcyjny router POS: `get_init_data`, `get_orders`, `process_order`, `accept_order`, `settle_and_close`, `fast_complete`, `cancel_order`, `panic_mode`, `assign_route`. Sync endpoint dla trybu offline. |
| 3 | **Cart** | `api/cart/` | `CartEngine.php`, `calculate.php` | Server-authoritative kalkulacja koszyka — ceny, grosze, half/half, modyfikatory. Brak mutacji — pure computation. |
| 4 | **Orders** | `api/orders/` | `checkout.php`, `accept.php`, `edit.php`, `estimate.php`, `panic.php`, `sla_monitor.php`, `DeltaEngine.php` | Cykl życia zamówienia — finalizacja, akceptacja, edycja (DeltaEngine), estymacja czasu, SLA monitoring. |
| 5 | **Courses/Logistics** | `api/courses/` | `engine.php` | Unified logistics engine — `dispatch`, `update_order_status`, `start_shift`, `update_location`, `collect_payment`, `deliver_order`, `emergency_recall`, `reconcile`, `set_driver_status`. |
| 6 | **Delivery** | `api/delivery/` | `dispatch.php`, `reconcile.php` | Standalone endpointy dispatch + rozliczenie zmiany. |
| 7 | **Tables** | `api/tables/` | `engine.php` | Router stolików — plany sali, rachunki, transfery, fire course, settle, complete dine-in. |
| 8 | **KDS** | `api/kds/` | `engine.php`, `update_ticket.php` | Kitchen display — `get_board` z opcjonalnym filtrem stacji, `bump_order`, `recall_order`. |
| 9 | **Warehouse** | `api/warehouse/` | 13 endpointów | PZ (`PzEngine`), RW, INW (`InwEngine`), KOR (`KorEngine`), MM (`MmEngine`), stany, AVCO, dokumenty, zatwierdzanie, mapowanie. |
| 10 | **Menu Studio** | `api/backoffice/` | `api_menu_studio.php`, `api_visual_studio.php` | CRUD menu, ceny, modyfikatory, receptury + legacy visual upload. |
| 11 | **HR** | `api/backoffice/hr/` | `engine.php` | Clock-in/out, status zmianowy. Kanoniczny endpoint silosu HR. |
| 12 | **Online** | `api/online/` | `engine.php`, `sse.php` | Publiczny storefront API + SSE (Server-Sent Events) do real-time tracking. |
| 13 | **Online Studio** | `api/online_studio/` | `engine.php`, `library_upload.php` | Director/composer API — sceny, warstwy, style presets, upload assetów. |
| 14 | **Gateway** | `api/gateway/` | `intake.php` | Unified external intake — multi-key auth (`GatewayAuth`), rate limit, idempotency, `CartEngine`. |
| 15 | **Settings** | `api/settings/` | `engine.php` | Konfiguracja tenanta — integracje, webhooki, klucze, DLQ, vault, CSRF. |
| 16 | **Assets** | `api/assets/` | `engine.php` | SSOT (Single Source of Truth) biblioteki assetów — `sh_assets` + `sh_asset_links`. |
| 17 | **BI** | `api/bi/` | `dashboard_data.php` | Agregacja P&L w groszach (`BiEngine::generateDashboard`), RBAC owner/admin/manager. |
| 18 | **Procurement** | `api/procurement/` | `inbox.php`, `ksef_config.php` | KSeF inbox (upload, accept, rescan, bulk edit linii), konfiguracja JWT v2, `poll_now`. |

### 1.3 Silniki Domenowe (`/core/`)

| Warstwa | Silniki | Przeznaczenie |
|---------|---------|---------------|
| **Foundation** | `AuthEngine`, `AuthGuard`, `JwtProvider`, `GatewayAuth`, `CredentialVault` | Autentykacja, JWT HS256, multi-key gateway, szyfrowanie AEAD XChaCha20-Poly1305 |
| **Order Lifecycle** | `OrderStateMachine`, `OrderEventPublisher`, `KdsAcceptRouting`, `PromisedTimeEngine` | Maszyna stanów zamówienia (`new→accepted→preparing→ready→in_delivery→completed`), transactional outbox, routing KDS, estymacja czasu |
| **Warehouse** | `PzEngine`, `WzEngine`, `InwEngine`, `KorEngine`, `MmEngine` | Każdy typ dokumentu magazynowego = osobny silnik z logiką AVCO, walidacji, stock logs |
| **HR/Payroll** | `HrClockEngine`, `PayrollEngine`, `TeamPayrollEngine`, `PayrollLedger`, `AdvanceEngine` | Clock-in/out z bcrypt PIN, append-only ledger groszy, cykl życia zaliczek |
| **Integrations** | `AdapterRegistry`, `BaseAdapter`, `IntegrationDispatcher`, `PapuAdapter`, `DotykackaAdapter`, `GastroSoftAdapter` | Wzorzec Strategy — registry + adaptery 3rd-party |
| **Notifications** | `NotificationDispatcher`, `ChannelRegistry`, `TemplateRenderer`, wielokanałowe `Channels/` | Multi-channel (SMS, Email, In-App) z fallback chain |
| **Visual** | `AssetResolver`, `SceneResolver`, `FoodCostEngine` | Resolving URL assetów, kontrakty scen wizualnych, kalkulacja food cost |
| **BI** | `BiEngine` | P&L: net sales (`created_at`), COGS z wielu WZ per zamówienie, payroll ledger, OPEX (`line_type=EXPENSE`), snapshot `wh_stock` |
| **KSeF** | `core/Ksef/Client.php`, `InboxInvoiceRepository`, `KsefXmlParser` | Oficjalne API MF v2 (JWT + kontekst NIP), metadata poll, pobranie XML, AutoScan → draft PZ |

### 1.4 Silosy Bazodanowe (Prefix-Based DDD)

| Prefiks | Domena | Przykładowe tabele | Liczba tabel (w 001) |
|---------|--------|---------------------|----------------------|
| **`sh_`** | Biznes SliceHub | `sh_tenant`, `sh_users`, `sh_menu_items`, `sh_orders`, `sh_order_lines`, `sh_ksef_invoices`, `sh_ksef_invoice_lines`, `sh_payroll_ledger`, `sh_variant_scales`, … | ~40+ (łańcuch migracji **004–057**, 55 plików SQL) |
| **`sys_`** | Słownik surowców (Master Data) | `sys_items` (SKU, nazwa, jednostka, kategoria, aliasy wyszukiwania) | 1 |
| **`wh_`** | Magazyn fizyczny | `wh_stock`, `wh_documents`, `wh_document_lines`, `wh_stock_logs` | 4 |

**Żelazna reguła cross-silo:** JOIN między silosami WYŁĄCZNIE przez klucze znakowe (`sku` VARCHAR, `ascii_key` VARCHAR), NIGDY przez numeryczne ID. Każdy most SKU wymaga bariery `tenant_id` po obu stronach:

```sql
-- Wzorzec kanoniczny: sh_ → sys_ przez SKU + tenant_id
FROM sh_recipes r
JOIN sys_items s ON s.sku = r.warehouse_sku AND s.tenant_id = r.tenant_id

-- Wzorzec kanoniczny: sh_ → wh_ przez SKU + tenant_id
FROM sh_modifiers m
LEFT JOIN wh_stock ws ON ws.sku = m.linked_warehouse_sku AND ws.tenant_id = :tid
```

### 1.5 Procesy w Tle (`/scripts/`)

| Worker | Przeznaczenie |
|--------|---------------|
| `worker_webhooks.php` | Asynchroniczna dostawa webhooków z retry |
| `worker_integrations.php` | Retransmisja do adapterów 3rd-party |
| `worker_notifications.php` | Outbox powiadomień (SMS, email, in-app) |
| `worker_driver_fanout.php` | Synchronizacja HR → status kierowcy |
| `worker_payroll_accrual.php` | Akumulacja payroll |
| `worker_integration_health_ping.php` | Health check integracji |
| `cron_reorder_nudge.php` | Cron — nudge do reorder |
| `worker_ksef_inbox.php` | Poll KSeF metadata → inbox, deduplikacja, `matchInvoiceLines` |

Wszystkie workery wspierają PID-lock i flagi `--loop` / `--dry-run`.

---

## 2. MECHANIZM IZOLACJI DANYCH DLA FRANCZYZ (Multi-Tenancy)

SliceHub implementuje **Row-Level Security (RLS) w warstwie aplikacyjnej** — model izolacji danych oparty na kolumnie `tenant_id` obecnej w każdej tabeli biznesowej.

### 2.1 Architektura przepływu tenant_id

```
HTTP Request
    │
    ├─── [Staff API] Authorization: Bearer <JWT>
    │         │
    │         ▼
    │    core/auth_guard.php
    │    ├── JwtProvider::decode() → payload{tenant_id, user_id, role, exp}
    │    ├── Walidacja: tenant_id > 0 AND user_id > 0
    │    ├── $_SESSION['tenant_id'] = tid  (mirror do sesji)
    │    └── EKSPORT: $tenant_id, $user_id (globalne zmienne PHP)
    │         │
    │         ▼
    │    API Handler (np. api/pos/engine.php)
    │    ├── $stmt = $pdo->prepare("SELECT ... WHERE tenant_id = ?")
    │    └── $stmt->execute([$tenant_id])
    │
    ├─── [Public Storefront] POST body: {"tenantId": 1}
    │         │
    │         ▼
    │    api/online/engine.php (BEZ auth_guard — publiczny)
    │    └── $tenantId = inputInt($input, 'tenantId') → walidacja > 0
    │
    ├─── [Gateway] Header: X-API-Key: <api_key>
    │         │
    │         ▼
    │    GatewayAuth::authenticateKey() → tenant_id z sh_api_keys
    │
    └─── [Inbound Webhook] Query: ?integration_id=<id>
              │
              ▼
         sh_tenant_integrations → tenant_id
```

### 2.2 Bariera tenant_id w zapytaniach SQL

**Każde zapytanie** (SELECT, INSERT, UPDATE, DELETE) musi zawierać barierę `tenant_id`. Przykłady z kodu produkcyjnego:

**SELECT z barierą (POS Engine):**
```php
// api/pos/engine.php:352-361
$stmt = $pdo->prepare("
    SELECT o.*, COALESCE(NULLIF(TRIM(u.name),''), u.username) AS creator_name
    FROM sh_orders o
    LEFT JOIN sh_users u ON o.user_id = u.id
    WHERE o.tenant_id = ? AND o.status NOT IN ('completed','cancelled')
");
$stmt->execute([$tenant_id]);
```

**INSERT z tenant_id (Checkout):**
```php
// api/orders/checkout.php:132-172
$stmtOrder = $pdo->prepare("
    INSERT INTO sh_orders (id, tenant_id, order_number, channel, order_type, ...)
    VALUES (:id, :tid, :num, :channel, :order_type, ...)
");
$stmtOrder->execute([':id' => $orderId, ':tid' => $tenant_id, ...]);
```

**Cross-silo JOIN z podwójną barierą tenant_id (Receptury → Słownik surowców):**
```php
// api/pos/engine.php:315-319
$stmtRecipe = $pdo->prepare("
    SELECT r.warehouse_sku AS sku, si.name, si.base_unit AS unit
    FROM sh_recipes r
    LEFT JOIN sys_items si ON si.sku = r.warehouse_sku AND si.tenant_id = r.tenant_id
    WHERE r.menu_item_sku = ? AND r.tenant_id = ?
");
```

### 2.3 Mechanizm dziedziczenia globalnych danych (tenant_id = 0)

System wspiera **hierarchiczną konfigurację** — dane z `tenant_id = 0` są traktowane jako domyślne ustawienia HQ, które franczyza może nadpisać:

```php
// api/pos/engine.php:116-121
$stmtPrices = $pdo->prepare("
    SELECT target_sku, channel, price, tenant_id
    FROM sh_price_tiers
    WHERE (tenant_id = ? OR tenant_id = 0) AND target_type = 'ITEM'
    ORDER BY target_sku, channel, tenant_id DESC
");
```

`ORDER BY tenant_id DESC` gwarantuje, że wiersz specyficzny dla franczyzy (`tenant_id > 0`) ma priorytet nad domyślnym globalnym (`tenant_id = 0`).

### 2.4 Autentykacja — JWT z embedded tenant_id

Token JWT zawiera `tenant_id` już na etapie logowania, co uniemożliwia klientowi zmianę kontekstu tenanta po uwierzytelnieniu:

```php
// core/AuthEngine.php:87-94 (system login)
$payload = [
    'user_id'   => $id,
    'tenant_id' => $tid,    // z wiersza sh_users
    'role'      => $role,
    'exp'       => $exp,
];
$token = JwtProvider::encode($payload, $jwtSecret);
```

Kiosk PIN login dodatkowo waliduje PIN w kontekście konkretnego tenanta:
```php
// core/AuthEngine.php:130-138
$stmt = $pdo->prepare('
    SELECT id, tenant_id, ... FROM sh_users
    WHERE pin_code = :pin AND tenant_id = :tid AND status = \'active\'
');
```

### 2.5 Sesja PHP — hardening

```php
// core/auth_guard.php:11-22
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isSecure,  // true gdy HTTPS
    'httponly' => true,
    'samesite' => 'Strict',
]);
```

Flagi `httponly`, `samesite=Strict` i warunkowy `secure` chronią przed XSS i CSRF na warstwie sesji.

---

## 3. STACK TECHNOLOGICZNY — GOTOWOŚĆ DO KONTENERYZACJI

### 3.1 Manifest stosu ("Zero-Dependency LAMP")

| Warstwa | Technologia | Wersja | Uzasadnienie |
|---------|-------------|--------|--------------|
| **Runtime** | PHP | 8.3+ (strict_types) | Natywne typy, PDO, JSON, brak frameworka |
| **Serwer HTTP** | Apache | 2.4+ | mod_rewrite, mod_php, .htaccess |
| **Baza danych** | MariaDB | 10.11+ (MySQL 8.0+) | utf8mb4_unicode_ci, transakcje InnoDB |
| **Frontend** | Vanilla JS (ES6+) + HTML5 | — | Zero bundlera, zero transpilera |
| **CSS** | Tailwind CSS | CDN (Play) | Brak lokalnego build step |
| **Ikony** | Font Awesome | 6.x CDN | Brak npm |
| **Mapy** | Leaflet.js | 1.9.4 CDN | Carto Dark tiles (publiczne) |
| **Fonty** | Google Fonts | CDN | Inter, DM Sans, Fraunces, Montserrat |
| **Real-time** | SSE (Server-Sent Events) | Natywne PHP | `api/online/sse.php` + `EventSource` JS |

### 3.2 Zerowa liczba zewnętrznych zależności

```
✗ package.json       — nie istnieje
✗ composer.json      — nie istnieje
✗ node_modules/      — nie istnieje
✗ vendor/            — nie istnieje
✗ requirements.txt   — nie istnieje
✗ Makefile            — nie istnieje
✗ Dockerfile          — nie istnieje (jeszcze)
```

**To jest celowa decyzja architektoniczna**, egzekwowana przez Konstytucję projektu (`.cursorrules` §3):

> *"ABSOLUTNY ZAKAZ: Node.js, npm, Webpack, React, Vue, Angular, jQuery."*

### 3.3 Wymagane rozszerzenia PHP (kompletna lista z audytu kodu)

| Rozszerzenie | Użycie | Krytyczność |
|-------------|--------|-------------|
| `pdo_mysql` | `new PDO("mysql:...")` w `core/db_config.php` | **Wymagane** |
| `json` | `json_encode/decode` z `JSON_THROW_ON_ERROR` wszędzie | **Wymagane** (wbudowane w PHP 8+) |
| `mbstring` | `mb_strlen`, `mb_substr`, `mb_strtolower` | **Wymagane** |
| `curl` | `curl_init/exec` w `WebhookDispatcher`, `PapuClient`, `SettingsPingLib` | **Wymagane** (integracje) |
| `session` | `session_start()` w `auth_guard.php` | **Wymagane** (wbudowane) |
| `hash` | `hash_hmac`, `hash_equals` — JWT HS256, GDPR HMAC | **Wymagane** (wbudowane) |
| `gd` | `getimagesize`, `imagecreatefromwebp` — upload/walidacja assetów | **Wymagane** (upload) |
| `sodium` | `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt` w `CredentialVault` | Opcjonalne (graceful degradation) |
| `intl` | `IntlDateFormatter` w `PayrollEngine` | Opcjonalne (branch `class_exists`) |
| `filter` | `filter_var(FILTER_VALIDATE_EMAIL)` w `EmailChannel` | Opcjonalne |

### 3.4 Gotowość do konteneryzacji — analiza

**Dlaczego ten stack jest idealny do Dockeryzacji:**

1. **Zero build step:** Nie ma `npm run build`, `composer install`, `webpack`, ani żadnego kroku kompilacji. Kopiowanie plików = deployment. Dockerfile składa się z:
   - Bazowy obraz `php:8.3-apache`
   - `docker-php-ext-install pdo_mysql mbstring gd curl`
   - `a2enmod rewrite`
   - `COPY . /var/www/html/slicehub`
   - Konfiguracja vhost + `.htaccess`

2. **Stateless HTTP:** Każde żądanie API jest samodzielne (JWT w nagłówku). Brak sticky sessions, brak shared filesystem (poza `uploads/`, który można zamontować jako volume).

3. **Separacja danych:** Baza MariaDB to osobny kontener. Jedyne co łączy aplikację z bazą to DSN w `core/db_config.php` — jedna zmienna środowiskowa.

4. **Brak demonów w kontenerze:** Workery (`worker_*.php`) to osobne procesy CLI, które naturalnie mapują się na osobne kontenery/sidecar lub CronJob w Kubernetes.

5. **Frontend bez transpilacji:** Vanilla JS serwowany bezpośrednio przez Apache jako static files — brak potrzeby oddzielnego kontenera na budowanie frontendu.

6. **Minimalny Dockerfile (szacunek):**

```dockerfile
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql mbstring gd
RUN a2enmod rewrite headers
COPY . /var/www/html/slicehub/
COPY .htaccess /var/www/html/slicehub/.htaccess
RUN echo '<Directory /var/www/html>\n AllowOverride All\n</Directory>' \
    >> /etc/apache2/apache2.conf
ENV DB_HOST=db DB_NAME=slicehub_pro_v2 DB_USER=root DB_PASS=
EXPOSE 80
```

**Estymacja rozmiaru obrazu:** ~120 MB (bazowy `php:8.3-apache` ~80 MB + rozszerzenia ~10 MB + kod aplikacji ~30 MB).

### 3.5 Architektura real-time

System używa **SSE (Server-Sent Events)** zamiast WebSocket — lekka implementacja natywna PHP w `api/online/sse.php`:
- `Content-Type: text/event-stream`
- Tabela `sh_sse_broadcast` jako kolejka wiadomości
- `EventSource` po stronie klienta w `online_track.js`
- Kompatybilność z reverse proxy (nagłówek `X-Accel-Buffering: no` dla nginx)

### 3.6 Podsumowanie gotowości

| Kryterium konteneryzacji | Status |
|--------------------------|--------|
| Zero zewnętrznych zależności do zainstalowania | ✅ |
| Zero kroków kompilacji | ✅ |
| Stateless HTTP (JWT) | ✅ |
| Konfiguracja przez zmienne środowiskowe | ✅ (1 plik `db_config.php`) |
| Separacja bazy danych | ✅ (osobny kontener MariaDB) |
| Workery jako osobne procesy | ✅ (CLI PHP, PID-lock, `--loop`) |
| Upload storage na volume | ✅ (`uploads/` — jedyny mutable path) |
| Health check endpoint | ✅ (ping przez dowolny API endpoint) |
| Estimated Docker image size | ~120 MB |
| Estimated Dockerfile LOC | ~10 linii |

---

*Raport wygenerowany na podstawie audytu żywego kodu repozytorium SliceHub Enterprise OS (rewizja 2026-05-20). Wszystkie ścieżki, nazwy tabel i fragmenty kodu odnoszą się do fizycznie istniejących plików w repozytorium. Testy regresji API: 62 scenariusze w `tests/test_runner.html` (w tym T62 — BI dashboard).*
