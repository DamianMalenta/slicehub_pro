# Sesja 2026-08-20 — Audyt tenant_id w core/ i api/ (bariera multi-tenancy)

## Cel
Pełny audyt zapytań SQL na tabelach `sh_*`/`wh_*` w `core/` i `api/` pod kątem brakującej bariery `tenant_id` (Prawo I Konstytucji). Skan automatyczny (PDO prepare/query/exec, z wykluczeniem sond `LIMIT 0` i dynamicznych `$where` z tenantem) → 91 trafień → ręczna weryfikacja każdego: schemat tabeli + ścieżka wywołania. Wzorzec poprawki: PR #44 (`ElzabFiscalEngine::fetchCashierName`).

## Pliki dotknięte
- `api/backoffice/api_menu_studio.php` — `add_item`/`update_item_full`: walidacja własności `itemId` (`SELECT id FROM sh_menu_items WHERE id=? AND tenant_id=? AND is_deleted=0`) przed `DELETE/INSERT sh_item_modifiers`. Jedyne realne uchybienie krytyczne (cross-tenant modyfikacja przypisań modyfikatorów).
- `api/pos/sync.php` — idempotency check `sh_pos_op_log` po `(op_id, tenant_id)` zamiast samego `op_id` (klienckiego UUID).
- `api/courses/engine.php` — `UPDATE sh_dispatch_log` i `UPDATE sh_driver_shifts` z `AND tenant_id=:tid`.
- `core/HrClockEngine.php` — `fetchSessionStartIso(\PDO, string $sessionUuid, int $tenantId)`: `AND tenant_id = :tid` (jedyny caller `clockIn()` ma `$tenantId` w scope).
- `api/online_studio/engine.php` — `UPDATE sh_atelier_scenes` (2 miejsca) z `AND tenant_id`.
- `_docs/audits/tenant_id_audit_2026-08-20.md` — pełny raport: poprawki + świadome wyjątki + false positives.
- `_docs/sessions/2026-08-20_tenant_id_audit.md` — ta notatka.

## Decyzje architektoniczne
- Tabele-dzieci bez kolumny `tenant_id` (`sh_order_lines`, `sh_order_audit`, `sh_ksef_invoice_lines`, `wh_document_lines`, `sh_modifiers`, `sh_item_modifiers`, `sh_atelier_scene_history`, `sh_scene_promotion_slots`) — izolacja przez tenant-zwalidowanego rodzica; NIE dodajemy kolumn (zmiana schematu poza zakresem audytu). Kluczowe: każdy punkt wejścia musi walidować rodzica — stąd fix w `api_menu_studio.php`.
- Globalne GC/TTL (`sh_sse_broadcast`, `sh_checkout_locks`) i workery outbox (`sh_event_outbox`, `sh_integration_deliveries`) pozostają celowo cross-tenant — udokumentowane w raporcie audytu.
- `core/SceneResolver.php` (`sh_style_presets`/`sh_scene_templates` z `tenant_id=0` = systemowe) — bez zmian; przy Fazie 6 (custom presety per tenant) wymagany filtr `tenant_id IN (0, :tid)`.
- Ścieżka raportu: `_docs/audits/` (konwencja repo; polecenie mówiło `docs/audits/`, ale katalog `docs/` nie istnieje).

## Test (E2E)
- Lint: `find . -name "*.php" | xargs -P4 -I{} php -l {}` — No syntax errors detected (wszystkie pliki).
- Golden path: import `001_init_slicehub_pro_v2.sql` → `apply_migrations_chain.php` → `seed_demo_all.php`; headless test runner `node scripts/run_test_runner_headless.cjs` — oczekiwane 62 pass / 0 fail (wynik w PR).
- Weryfikacja logiczna każdej poprawki: ID rodzica w każdym zmienionym UPDATE pochodzi z wcześniejszego tenant-scoped SELECT, więc zmiany nie zmieniają zachowania dla poprawnych żądań; blokują wyłącznie żądania cross-tenant.

## Otwarte pytania
- Czy przy Fazie 6 (custom style presety) dodać barierę `tenant_id IN (0,:tid)` w `SceneResolver` (obecnie tylko rekordy systemowe `tenant_id=0`)?
- Ewentualna migracja dodająca `tenant_id` do tabel-dzieci zamówień (denormalizacja dla defense-in-depth) — poza zakresem tego PR.
