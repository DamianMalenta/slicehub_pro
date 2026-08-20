# Audyt tenant_id — zapytania SQL bez bariery tenanta (core/ + api/)

**Data:** 2026-08-20
**Zakres:** wszystkie pliki PHP w `core/` i `api/` — zapytania na tabelach `sh_*` / `wh_*` bez warunku `tenant_id`.
**Metoda:** skan automatyczny wyrażeń `PDO::prepare/query/exec` (z wykluczeniem sond schematu `LIMIT 0` i dynamicznych `$where` zawierających tenant), następnie ręczna weryfikacja każdego ze 91 trafień: schemat tabeli (czy w ogóle ma kolumnę `tenant_id`) + ścieżka wywołania (czy ID rodzica pochodzi z zapytania tenant-scoped).
**Wzorzec poprawki:** PR #44 (`ElzabFiscalEngine::fetchCashierName` — dodanie `AND tenant_id = :tid`).

---

## 1. Poprawione uchybienia (ten PR)

| Plik / miejsce | Problem | Poprawka |
|---|---|---|
| `api/backoffice/api_menu_studio.php` (`add_item`/`update_item_full`) | `DELETE FROM sh_item_modifiers WHERE item_id = ?` + `INSERT` wykonywane na `itemId` z inputu **bez weryfikacji własności** — tenant-scoped `UPDATE sh_menu_items` nie sprawdzał `rowCount`, więc żądanie z ID dania innego tenanta czyściło/nadpisywało jego przypisania grup modyfikatorów (tabela-dziecko bez kolumny `tenant_id`). | Walidacja własności przed operacjami: `SELECT id FROM sh_menu_items WHERE id = ? AND tenant_id = ? AND is_deleted = 0`. |
| `api/pos/sync.php` (idempotency check) | `SELECT status, server_ref, error_text FROM sh_pos_op_log WHERE op_id = ?` — `op_id` jest UUID generowanym po stronie klienta; brak `tenant_id` umożliwiał cross-tenant odczyt statusu/`server_ref` (i kolizję idempotencji). | `... AND tenant_id = ?`. |
| `api/courses/engine.php` (~l.706) | `UPDATE sh_dispatch_log SET order_ids_json = ... WHERE id = :lid` — tabela ma `tenant_id`, update tylko po PK. | `... AND tenant_id = :tid`. |
| `api/courses/engine.php` (~l.1256) | `UPDATE sh_driver_shifts ... WHERE id=:sid AND status='active'` — tabela ma `tenant_id`, update tylko po PK. | `... AND tenant_id=:tid ...`. |
| `core/HrClockEngine.php` (`fetchSessionStartIso`) | `SELECT start_time FROM sh_work_sessions WHERE session_uuid = :sid` — tabela ma `tenant_id`; brak bariery (wzorzec identyczny jak `fetchCashierName` z PR #44). | Parametr `int $tenantId` + `AND tenant_id = :tid`. |
| `api/online_studio/engine.php` (~l.1789, ~l.2045) | `UPDATE sh_atelier_scenes ... WHERE id = :id` — tabela ma `tenant_id`, update tylko po PK (ID z wcześniejszego tenant-scoped SELECT, ale bariera bezpośrednia wymagana przez Konstytucję). | `... AND tenant_id = :tid`. |

Uwaga: pozostałe trafienia po ręcznej weryfikacji zaklasyfikowano jako świadome wyjątki (sekcja 2) lub false positives skanera (sekcja 3).

---

## 2. Świadome wyjątki (bez zmian — udokumentowane)

### 2.1 Tabele-dzieci bez kolumny `tenant_id`, dostępne wyłącznie przez tenant-zwalidowanego rodzica

Schemat tych tabel nie zawiera `tenant_id` — izolację zapewnia FK do rodzica, którego ID zawsze pochodzi z zapytania z barierą `tenant_id`:

- **`sh_order_lines`, `sh_order_audit`, `sh_order_item_modifiers`** (rodzic: `sh_orders`) — `api/pos/engine.php`, `api/kds/engine.php`, `api/courses/engine.php`, `api/tables/engine.php`, `api/orders/edit.php`, `api/gateway/intake.php`, `api/online/engine.php`, `api/integrations/choiceqr/webhook.php`, `core/KdsTicketEngine.php`, `core/SettlementEngine.php`, `core/OrderEventPublisher.php`. Każde z tych miejsc najpierw waliduje/tworzy zamówienie z `tenant_id = :tid`.
- **`sh_ksef_invoice_lines`** (rodzic: `sh_ksef_invoices`) — `api/procurement/inbox.php`, `core/Ksef/InboxImport.php`, `core/Ksef/InboxInvoiceRepository.php`, `core/Ksef/InboxQtyNormalize.php`. Każda akcja routera waliduje `invoice_id` przez `SELECT ... FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid` (operacje per-linia dodatkowo wiążą `line_id` z `ksef_invoice_id`).
- **`wh_document_lines`** (rodzic: `wh_documents`) — `api/warehouse/internal_rw.php`, `api/procurement/inbox.php` (reverse PZ), `core/InwEngine.php`, `core/KorEngine.php`, `core/WarehouseReverseHook.php`. `document_id` zawsze z tenant-scoped SELECT/INSERT na `wh_documents`.
- **`sh_modifiers`, `sh_item_modifiers`** (rodzice: `sh_modifier_groups`, `sh_menu_items`) — `api/backoffice/api_menu_studio.php` (`save_modifier_group` waliduje grupę przez `WHERE id = ? AND tenant_id = ?`; `save_item` po poprawce z sekcji 1 waliduje danie).
- **`sh_atelier_scene_history`** (rodzic: `sh_atelier_scenes`) — `api/backoffice/api_menu_studio.php`, `api/online_studio/engine.php`; `scene_id` z tenant-scoped SELECT/INSERT.
- **`sh_scene_promotion_slots`** (rodzic: `sh_atelier_scenes`) — `api/online_studio/engine.php`; `scene_id` z tenant-scoped SELECT.

### 2.2 Globalne sprzątanie TTL/GC (celowo cross-tenant)

- `api/online/sse.php`, `api/courses/sse_driver.php` — `DELETE FROM sh_sse_broadcast WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)` (kasowanie przeterminowanych rekordów wszystkich tenantów; dostarczanie eventów jest filtrowane per `tracking_token`).
- `api/online/engine.php` — `DELETE FROM sh_checkout_locks WHERE expires_at < ...` (probabilistyczny GC przeterminowanych locków; locki są konsumowane po unikalnym `lock_token`).

### 2.3 Workery / dispatchery outbox (celowo cross-tenant, ID wewnętrzne)

- `core/WebhookDispatcher.php`, `core/Notifications/NotificationDispatcher.php` — claim `sh_event_outbox` po `status='pending'` (worker obsługuje wszystkie tenanty; `tenant_id` jest niesiony w rekordzie eventu).
- `core/Integrations/IntegrationDispatcher.php` — `UPDATE sh_integration_deliveries` / `INSERT sh_integration_attempts` po `delivery_id` utworzonym w tym samym procesie (lastInsertId).
- `core/Notifications/NotificationDispatcher.php` — liczniki `sh_notification_deliveries` / `sh_notification_channels` per `channel_id` (kanał wybierany wcześniej tenant-scoped).
- `api/integrations/inbound.php` (`inbound_updateCallback`) — `UPDATE sh_inbound_callbacks WHERE id = :id`, gdzie `id` = `lastInsertId()` z bieżącego żądania.

### 2.4 Auth / infrastruktura przed lub obok rozpoznania tenanta

- `core/GatewayAuth.php` — `sh_rate_limits` kluczowane po `api_key_id` (klucz API sam w sobie jest zasobem tenanta zwalidowanym przy autoryzacji; tabela nie ma kolumny `tenant_id`).
- `core/SceneResolver.php` — `sh_style_presets`, `sh_scene_templates`, `sh_assets`: rekordy systemowe z `tenant_id = 0` (custom per-tenant to dopiero Faza 6 — wtedy wymagany filtr `tenant_id IN (0, :tid)`).

### 2.5 Sondy schematu i skrypty instalacyjne

- Wszystkie `SELECT ... LIMIT 0` (feature-detection kolumn/tabel) — bez danych, poza zakresem bariery.
- Skrypty w `scripts/` / instalator — poza zakresem audytu (`core/`+`api/`).

---

## 3. False positives skanera

Dynamiczne `$where`/`$whereEp` budowane w PHP zawierające `tenant_id = :tid` (skaner nie widzi predykatu w literale SQL): `api/backoffice/hr/engine.php`, `api/inbox/engine.php`, `api/settings/engine.php`, `api/warehouse/documents_list.php`, `api/online_studio/engine.php` (l.2166).
