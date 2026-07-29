# Audyt: Martwy kod w `core/`

> **Data:** 2026-07-29
>
> **Zakres:** wszystkie pliki PHP i JS w `core/` (50+ plików, 3 podkatalogi)
>
> **Metodologia:** dla każdego pliku przeszukano cały codebase (`api/`, `modules/`, `scripts/`, `tests/`) pod kątem referencji — `require_once`, `import`, wywołań klas/funkcji.

---

## 1. Martwy kod

### `slicehubSyncMissingDriverFleetRows()` — `core/DriverFleetHelper.php:37-51`

Funkcja zdefiniowana, ale **nigdzie nie wywołana** (grep = 0 wyników poza definicją).

Miała synchronizować brakujące wiersze `sh_drivers` dla wszystkich kont driver w tenancie (bulk INSERT...SELECT...NOT EXISTS), ale nigdy nie została podpięta.

Druga funkcja z tego pliku — `slicehubEnsureDriverFleetRow()` — jest aktywna (3 call-site'y w `api/courses/engine.php`).

---

## 2. Pliki redundantne (aktywne, ale nakładające się)

### `core/AuthGuard.php` (class) vs `core/auth_guard.php` (procedural)

Dwa równoległe implementacje auth guard:

- **`core/auth_guard.php`** (procedural) — 45+ call-site'ów, główny guard, session+JWT, ustawia `$tenant_id` / `$user_id`
- **`core/AuthGuard.php`** (class, stateless JWT) — 3 call-site'y: `api/inbox/engine.php`, `api/studio/generate_key.php`, `api/system/generate_seq.php`

`AuthGuard::protect()` robi to samo co `auth_guard.php`, tylko zwraca payload zamiast ustawiać zmienne globalne. Można skonsolidować.

### `core/Integrations/PapuClient.php` vs `core/Integrations/PapuAdapter.php`

- **`PapuClient`** — legacy sync push, wywoływany w `api/pos/engine.php:1136` (fire-and-forget)
- **`PapuAdapter`** — async push przez `IntegrationDispatcher` + transactional outbox

Oba aktywne, różne wzorce. `PapuClient` technicznie nadpisany przez `PapuAdapter` dla async, ale sync push w POS engine nadal używa `PapuClient`.

---

## 3. Pełny inwentarz — wszystkie pliki aktywne

### PHP (44 pliki w `core/`)

| Plik | Call-site'y | Status |
|------|-------------|--------|
| `AdvanceEngine.php` | HR engine, testy | ✅ |
| `AsciiKeyEngine.php` | `api/studio/generate_key.php` | ✅ |
| `AssetResolver.php` | studio API, online engine, POS engine | ✅ |
| `AuthEngine.php` | `api/auth/login.php`, HR engine | ✅ |
| `AuthGuard.php` | 3 endpointy | ⚠️ redundantny |
| `auth_guard.php` | 45+ endpointów | ✅ główny guard |
| `AutoScanEngine.php` | procurement, KSeF inbox | ✅ |
| `BiEngine.php` | `api/bi/dashboard_data.php`, seed | ✅ |
| `CredentialVault.php` | 8 plików (encrypt/decrypt/status) | ✅ |
| `CustomerContactRepository.php` | NotificationDispatcher, inbound, worker | ✅ |
| `DomainError.php` | HR engine, testy | ✅ |
| `DriverFleetHelper.php` | `api/courses/engine.php` (3 calls) | ⚠️ 1 martwa funkcja |
| `FoodCostEngine.php` | `api/reports/food_cost.php` | ✅ |
| `GatewayAuth.php` | `api/gateway/intake.php`, settings engine | ✅ |
| `Geocoder.php` | `api/pos/engine.php` | ✅ |
| `HrClockEngine.php` | HR engine, worker_driver_fanout | ✅ |
| `HrRoles.php` | HR engine | ✅ |
| `InvoiceLineQtyNormalizer.php` | inbox.php, KSeF, audyty | ✅ |
| `InwEngine.php` | `api/warehouse/approve.php`, `inventory.php` | ✅ |
| `JwtProvider.php` | AuthEngine, AuthGuard, auth_guard, logout | ✅ |
| `KdsAcceptRouting.php` | `api/pos/engine.php` | ✅ |
| `KdsTicketEngine.php` | `api/kds/engine.php` | ✅ |
| `KorEngine.php` | `api/warehouse/correction.php`, WzEngine, inbox | ✅ |
| `MealEngine.php` | HR engine, testy | ✅ |
| `MmEngine.php` | `api/warehouse/transfer.php` | ✅ |
| `Money.php` | HR engine, AdvanceEngine, PayrollLedger, testy | ✅ |
| `OrderEventPublisher.php` | POS engine, online engine, orders | ✅ |
| `OrderStateMachine.php` | POS, online, courses, tables, settlement | ✅ |
| `PackSizeExtractor.php` | `InvoiceLineQtyNormalizer.php` | ✅ |
| `PanicEngine.php` | `api/pos/engine.php` | ✅ |
| `PayrollAllocator.php` | testy, `_tmp_verify_implementation.php` | ✅ |
| `PayrollEngine.php` | HR engine, TeamPayrollEngine | ✅ |
| `PayrollLedger.php` | HR engine, AdvanceEngine, testy, migracje | ✅ |
| `PromisedTimeEngine.php` | `api/orders/estimate.php` | ✅ |
| `PzEngine.php` | `api/procurement/inbox.php`, `api/warehouse/receipt.php` | ✅ |
| `SceneResolver.php` | online engine, studio API, POS engine | ✅ |
| `SequenceEngine.php` | `api/system/generate_seq.php` | ✅ (brak typu RPC/FIS) |
| `SettingsPingLib.php` | settings engine, worker_integration_health | ✅ |
| `SettlementEngine.php` | POS engine, tables engine, testy | ✅ |
| `StaffFleetPresence.php` | login, logout, POS, tables, courses | ✅ |
| `TeamPayrollEngine.php` | HR engine, seed | ✅ |
| `Units.php` | InvoiceLineQtyNormalizer, BiEngine, PayrollLedger, HR | ✅ |
| `Uuid.php` | 30+ plików | ✅ |
| `WarehouseConsumeHook.php` | `api/pos/engine.php#accept_order` | ✅ |
| `WarehouseReverseHook.php` | `api/pos/engine.php#cancel_order` | ✅ |
| `WebhookDispatcher.php` | `scripts/worker_webhooks.php` | ✅ |
| `WzEngine.php` | WarehouseConsumeHook, KorEngine, correction | ✅ |
| `db_config.php` | wszystkie endpointy | ✅ |
| `local_secrets.php` | gitignored, lokalny config | ✅ (secrets) |

### Integrations/ (7 plików)

| Plik | Status |
|------|--------|
| `AdapterRegistry.php` | ✅ |
| `BaseAdapter.php` | ✅ |
| `DotykackaAdapter.php` | ✅ |
| `GastroSoftAdapter.php` | ✅ |
| `IntegrationDispatcher.php` | ✅ |
| `PapuAdapter.php` | ✅ |
| `PapuClient.php` | ⚠️ redundantny z PapuAdapter, ale aktywny |

### Ksef/ (5 plików)

| Plik | Status |
|------|--------|
| `Client.php` | ✅ |
| `InboxImport.php` | ✅ |
| `InboxInvoiceRepository.php` | ✅ |
| `InboxQtyNormalize.php` | ✅ |
| `Parser.php` | ✅ |

### Notifications/ (10 plików)

| Plik | Status |
|------|--------|
| `ChannelInterface.php` | ✅ |
| `ChannelRegistry.php` | ✅ |
| `DeliveryResult.php` | ✅ |
| `NotificationDispatcher.php` | ✅ |
| `SmartReplyEngine.php` | ✅ |
| `TemplateRenderer.php` | ✅ |
| `Channels/EmailChannel.php` | ✅ |
| `Channels/InAppChannel.php` | ✅ |
| `Channels/PersonalPhoneChannel.php` | ✅ |
| `Channels/SmsGatewayChannel.php` | ✅ |

### js/ (6 plików)

| Plik | Status |
|------|--------|
| `api_client.js` | ✅ (warehouse, studio, tables) |
| `core_validator.js` | ✅ (warehouse, studio — `SliceValidator`) |
| `neon_pizza_engine.js` | ✅ (studio, online) |
| `scene_renderer.js` | ✅ (online_ui, SharedSceneRenderer) |
| `sh_api_base.js` | ✅ (8+ modułów) |
| `surface/SharedSceneRenderer.js` | ✅ (online_renderer, Director) |

---

## 4. Kontekst fiskalizacji

Z `_docs/audits/fiscalization_status.md`:

- **VAT:** liczony per linia w groszach, zapisywany w `sh_order_lines` — GOTOWE
- **`print_receipt`:** tylko flaga DB (`receipt_printed=1`), brak sterownika drukarki
- **Fiskalizacja właściwa:** zamrożona w P6+ (code freeze 2026-04-23), brak kodu w `core/`
- **Infrastruktura integracji:** `OrderEventPublisher` + `IntegrationDispatcher` + 3 adaptery (Papu, Dotykačka, GastroSoft) — aktywne, pushują zamówienia z VAT do zewnętrznych POS
- **Braki w `core/`:** brak typu `RPC`/`FIS` w `SequenceEngine`, brak klasy sterownika drukarki, `legal_fiscal_no` tylko informacyjne

---

## 5. Podsumowanie liczbowe

| Kategoria | Liczba |
|-----------|--------|
| Martwe funkcje | 1 (`slicehubSyncMissingDriverFleetRows`) |
| Redundantne pliki (aktywne) | 2 (`AuthGuard.php`, `PapuClient.php`) |
| Aktywne pliki PHP | 44 |
| Aktywne pliki JS | 6 |
| Aktywne podkatalogi | 3 (Integrations/7, Ksef/5, Notifications/10) |
| **Razem plików** | **50+** |
