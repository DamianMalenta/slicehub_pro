# Audyt gotowości produkcyjnej — inwentarz stanu repo

**Data:** 2026-07-22
**Typ:** audyt (read-only) — bez zmian w kodzie aplikacji.
**Zakres:** pełny przegląd stanu plików pod kątem gotowości na deploy. Ustalenia oparte **wyłącznie na faktycznym kodzie** (nagłówki `STATUS:`, `.cursorrules`, `scripts/_migrations_chain.php`, treść silników) — nie na opisach z dokumentacji.

## Cel

Jeden uporządkowany rejestr długu (dokumentacyjnego i kodowego), realnych bugów, martwego/zdublowanego kodu, kwestii bezpieczeństwa oraz otwartych bloków produktowych — tak, by kolejna sesja / właściciel w kilka minut wiedzieli *co* jest niedomknięte, *dlaczego* i *co dalej*. Na końcu: rekomendowana kolejność domykania oraz uczciwy rejestr korekt własnych twierdzeń i białych plam.

---

## A. Dług dokumentacyjny (papiery ≠ kod)

Rozjazd między dokumentacją a rzeczywistym stanem kodu. Do zsynchronizowania (edycja tekstu, nie kodu).

| # | Plik / sekcja | Stan w docs | Stan w kodzie | Akcja |
|---|---------------|-------------|---------------|-------|
| A1 | `_docs/START_TUTAJ.md` § D (l. 57) + § 5 (l. 115) | `api/payments/settle.php` = `@planned orphan` (split tender bez UI) | Domknięty przez `core/SettlementEngine.php` (sesja 07.07); `settle.php` = cienki wrapper HTTP delegujący do silnika | Zaktualizować oba zdania — usunąć status `@planned orphan` |
| A2 | `_docs/00_PAMIEC_SYSTEMU.md` § Prawo VIII (l. 168) | `settle.php` na liście **aktualnych `@planned`** | j.w. — już nieplanowany, wpięty produkcyjnie (`pos/engine.php#settle_and_close` → `SettlementEngine`) | Wykreślić `settle.php` z listy `@planned` |
| A3 | `.cursorrules` § 10 (l. 128) | Lista `@planned` = **3 pliki**: `edit.php`, `estimate.php`, `sla_monitor.php` | Nagłówki `STATUS:` w repo = **9 plików** (patrz sekcja B) | Zsynchronizować rejestr `@planned`/orphan/utility z realnymi nagłówkami `STATUS:` |

**Dowód A1/A2 (settle domknięty):** `_docs/sessions/2026-07-07_settlement_engine_phase1.md` + `api/payments/settle.php` (wrapper) + `api/pos/engine.php` (`settle_and_close` → `SettlementEngine::settleAndClose`).

---

## B. Pliki kompletne, ale NIEPODPIĘTE do UI (9 nagłówków `STATUS:`)

Wszystkie 9 mają docblock `STATUS:` z audytu `2026-04-19`. Kod istnieje i jest kompletny; brak call-site z frontendu.

| Plik | STATUS | Silnik / opis | Rekomendacja |
|------|--------|---------------|--------------|
| `api/orders/edit.php` | PLANNED | `DeltaEngine` → `sh_orders.kitchen_delta` (JSON); czeka na `admin_hub` „Edytuj zamówienie" (Faza 3) | **Podpiąć do UI** (admin_hub) |
| `api/orders/estimate.php` | ORPHAN | Cienki wrapper `PromisedTimeEngine::calculate()`; czeka na „Zaplanuj na później" w `modules/online/` | **Podpiąć do UI** (scheduled-order picker) |
| `api/orders/sla_monitor.php` | ORPHAN | Agregat SLA → `sh_sla_breaches` (UPSERT); czeka na dashboard admin_hub + cron | **Podpiąć do UI** (aggregate health) / cron |
| `api/orders/panic.php` | LEGACY DUPLICATE | Funkcja już w `api/pos/engine.php#panic_mode` (debounce + `sh_panic_log`) | **USUNĄĆ** (patrz E) |
| `api/dashboard/team_payroll.php` | PLANNED | `TeamPayrollEngine`; czeka na dashboard szefa (Faza 3) | **Podpiąć do UI** (po HR Faza 4) |
| `api/reports/food_cost.php` | PLANNED | `FoodCostEngine` + AVCO; per-item food cost + marża | **Podpiąć do UI** (reports panel) |
| `api/staff/payroll.php` | PLANNED | `PayrollEngine`; per-user week/month/year | **Podpiąć do UI** (po HR Faza 4) |
| `api/studio/generate_key.php` | UTILITY | Wrapper `AsciiKeyEngine` (podgląd ascii_key + collision probe) | **Zostawić jako utility** |
| `api/system/generate_seq.php` | UTILITY | Wrapper `SequenceEngine` (atomowy numer dokumentu) | **Zostawić jako utility** |

> Uwaga: `edit.php` docblock ma jawne `Do NOT delete` (referencja historyczna w `_docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md §541`).

---

## C. Realne bugi kodu potwierdzone czytaniem

### C1 — `ChannelRegistry::knownTypes()` → fatal error

**Plik:** `core/Notifications/ChannelRegistry.php` (l. 83–91).
`knownTypes()` odwołuje się do `self::$fileMap`:

```php
public static function knownTypes(): array {
    return array_keys(self::$fileMap ?? [ ... ]);   // ← $fileMap NIE ISTNIEJE
}
```

Zdefiniowana statyczna właściwość to `self::$classMap` (l. 13). `$fileMap` istnieje tylko jako **lokalna** zmienna w `get()` (l. 44). Dostęp do niezadeklarowanej statycznej właściwości w PHP rzuca `Error` — i `??` tego **nie tłumi** (inaczej niż dla dostępu do niezdefiniowanego indeksu tablicy). Efekt: **fatal error przy każdym wywołaniu `knownTypes()`**.

**Naprawa jednolinijkowa:** `self::$fileMap` → `self::$classMap`.

### C2 — `DirectorApp` — martwe przyciski przesuwania/duplikacji warstw

**Panele** wołają przez `this._director?.` metody, których **nie ma** w `modules/online_studio/js/director/DirectorApp.js`:

| Wywoływana metoda | Wywołujący (call-site) | Obecna w `DirectorApp`? |
|-------------------|------------------------|--------------------------|
| `moveLayerUp` | `HierarchyPanel.js:190`, `InspectorPanel.js:226` | ❌ NIE |
| `moveLayerDown` | `HierarchyPanel.js:201`, `InspectorPanel.js:227` | ❌ NIE |
| `reorderLayers` | `HierarchyPanel.js:289` | ❌ NIE |
| `duplicateLayer` | `HierarchyPanel.js:213`, `InspectorPanel.js:225` | ❌ NIE |
| `duplicateCompanion` | `HierarchyPanel.js:214`, `InspectorPanel.js:284` | ❌ NIE |

`DirectorApp` ma publiczne: `addLayer`, `addCompanion`, `replaceLayerAsset`, `deleteLayer`, `deleteCompanion` oraz **prywatne** `_zOrder(dir)` i `_duplicate()` (sterowane skrótami klawiszowymi). Panele używają jednak innych, nieistniejących nazw publicznych → **martwe przyciski** move-up / move-down / drag-reorder / duplikacja warstwy i companiona. Bo optional chaining (`?.`) połyka wywołanie bez błędu — brak reakcji zamiast wyjątku.

> **Historia twierdzenia (uczciwość rejestru):** potwierdzone → błędnie wycofane → **ponownie potwierdzone jako realne** (patrz sekcja korekt).

**Naprawa:** dodać 5 metod w `DirectorApp` (mogą delegować do istniejących `_zOrder`/`_duplicate` + logika reorder), **albo** przepiąć panele na istniejące nazwy.

---

## D. Bezpieczeństwo / hardening

### D1 — Skrypty uruchamialne z przeglądarki BEZ klucza dostępu

Wzorzec `install_panel.php` (guard `SLICEHUB_SCRIPT_KEY`, 8 odwołań, nagłówek `HTTP_X_SCRIPT_KEY`/`key`) **NIE** jest zastosowany w poniższych. Każdy ma w docblocku jawne „Run via browser: `http://.../scripts/...php`" i zero odwołań do `SCRIPT_KEY`:

| Skrypt | Nagłówek | Guard klucza? | Destrukcyjność |
|--------|----------|---------------|----------------|
| `scripts/setup_database.php` | „Run via browser" | ❌ brak | Migracje schematu (DDL) |
| `scripts/seed_demo_all.php` | „Run via browser / CLI" | ❌ brak | Seed danych (ON DUP KEY) |
| `scripts/reset_users.php` | „Open in browser" | ❌ brak | **Wipe + reseed userów** tenant 1 |
| `scripts/nuclear_reset.php` | URL w browserze | ❌ brak | **Kasuje zamówienia/logi/userów** tenant 1 |
| `scripts/seed_final_test.php` | „Usage: php ..." | ❌ brak (brak też CLI-only guardu) | Auto-`ALTER TABLE` + seed |

**Akcja:** schować za `SLICEHUB_SCRIPT_KEY` (jak `install_panel.php`) **lub** usunąć po deployu.

### D2 — `CredentialVault::encrypt()` — fail-open

**Plik:** `core/CredentialVault.php` (l. 44–66). Gdy brak libsodium **lub** klucza `SLICEHUB_VAULT_KEY`:

```php
if (!self::isReady()) {
    self::logMissingVault('encrypt');   // tylko warning do error_log
    return $plaintext;                  // ← zwraca PLAINTEXT
}
```

Sekrety integracji / webhooków (`sh_tenant_integrations.credentials`, `sh_webhook_endpoints.secret`) mogą trafić do bazy **jawnie**, jeśli produkcja nie ma skonfigurowanego vaultu. **Akcja:** rozważyć twardy fail w `encrypt()` na produkcji; wymóg: ustawienie `SLICEHUB_VAULT_KEY` (32 bajty hex) + libsodium na hostingu.

### D3 — Sekrety demo jawne w repo

`scripts/seed_demo_all.php`: znany bcrypt hash hasła `"password"` dla WSZYSTKICH kont testowych (l. 53), PIN-y `0000`–`6666` (roster demo z AGENTS.md). **Akcja:** rotacja przez panel + `scripts/rotate_credentials_to_vault.php --live` (domyślnie `--dry-run`; self-test encrypt→decrypt→compare przed każdym UPDATE, idempotentny na `vault:v1:`).

---

## E. Martwy / zdublowany kod (istotny)

| Element | Stan | Rekomendacja |
|---------|------|--------------|
| `api/orders/panic.php` | Czysty duplikat `api/pos/engine.php#panic_mode` (l. 1502 — debounce + `sh_panic_log` INSERT). Brak zewnętrznych callerów. | **USUNĄĆ** (quick win) |
| `Studio.library` getter | `modules/online_studio/js/studio_app.js:27–28` — `@deprecated` alias `get library() { return Studio.assets; }` (kept 1 sprint dla stragglerów) | **USUNĄĆ po sprincie** |
| `core/PayrollEngine.php` | Legacy reader: `SELECT hourly_rate FROM sh_users` (l. 41), `FROM sh_deductions` (l. 256); `resolvePeriodBounds` (l. 131) | Nie ruszać w oderwaniu od HR Faza 4 (rewrite in-place) |
| `core/TeamPayrollEngine.php` | Legacy reader: `hourly_rate` z `sh_users` (l. 157); `resolvePeriodBounds` (l. 225) — **zdublowana** względem `PayrollEngine` (nie porównana linia-po-linii) | j.w.; przy rewrite HR skonsolidować `resolvePeriodBounds` |
| `sh_users.hourly_rate` | `DEPRECATED_HR_M041` (comment kolumny w `041_hr_employees_foundation.sql:244`); Expand-Contract | **DROP COLUMN po Fazie 4** (po 2× zamknięciu okresu na przepisanym silniku) |

**Fałszywe tropy (NIE dług — nie ruszać):**
- `modules/online_studio/js/director/renderer/SceneRenderer.js` — **celowy thin wrapper** re-eksportujący `SharedSceneRenderer` z `core/js/surface/` (utrzymuje starą ścieżkę importu; nie duplikat).
- Legacy z migracji `025_drop_legacy_magic_dict.sql` i `038_drop_legacy_inventory_docs.sql` — **już fizycznie DROP-nięte**.

---

## F. Duże bloki produktowe (otwarte)

- **HR Faza 4** — `PayrollEngine` rewrite **in-place** (SSOT, zakaz plików równoległych): readery z `sh_work_sessions` + `sh_deductions` + `sh_meals` + `sh_users.hourly_rate` → `sh_payroll_ledger::sumForPeriod`. Czeka na UI HR (`modules/backoffice/hr/`) + migrację `sh_deductions → sh_payroll_ledger`. Spec: `_docs/00_PAMIEC_SYSTEMU.md` l. 873, `_docs/18_BACKOFFICE_HR_LOGIC.md §13`.
- **Online M5 / M6 / M7** — Counter/Living Table, Checkout UX, QA/performance (otwarte).
- **`core/Integrations/PapuClient.php`** — szkielet z **6 znacznikami `TODO`** (endpoint/schema/auth = placeholder ChoiceQR-style; PLN vs grosze; Bearer vs X-Api-Key). Współistnieje z event-drivenowym `core/Integrations/PapuAdapter.php` (async, outbox → `sh_integration_deliveries` + retry/DLQ). Header `PapuAdapter` twierdzi, że `PapuClient` jest **nadal** w POS finalize jako sync push (back-compat) — **nie ustalono, który tor jest aktywny per tenant**. (Uznane w sesji za mało istotne — czeka na dokumentację API Papu.)

---

## G. Migracje

| Obserwacja | Dowód |
|------------|-------|
| Żywy `database/migrations/014_global_assets.sql` istnieje **osobno** od `_archive_014_ingredient_assets.sql` | `ls database/migrations/` |
| `018` istnieje **tylko w archiwum** (`_archive_018_modifier_visual_map.sql`) — stąd dziura w numeracji | j.w. (brak żywego `018_*`) |
| `015_normalize_three_drivers.sql` **świadomie pominięty** w chain — tylko przez `--include-015` (DELETE/UPDATE userów/kierowców tenant 1) | `scripts/apply_migrations_chain.php` l. 30–37, 68, 118–121; `scripts/_migrations_chain.php` (015 poza tablicą `$chain`) |
| `037_pos_foundation.sql` — generated column `_active_table_guard` (`BIGINT UNSIGNED GENERATED ALWAYS AS (...) STORED`) + `UNIQUE INDEX uq_one_active_order_per_table` → **błąd 1901 na MariaDB hostingu** (nieblokujący — traci się tylko opcjonalny anti-ghosting guard) | `037_pos_foundation.sql` l. 17, 222–236; AGENTS.md gotcha #4 (015/030/037 = znane pre-existing MariaDB 10.11 issues, nie blokują aplikacji) |

---

## H. Offline POS — FREEZE do 2026-08-23

Świadomie zamrożone (`_docs/00_PAMIEC_SYSTEMU.md` § FREEZE NOTICE, l. 12–31). **Nie ruszać bez jawnej decyzji właściciela.**

- **UNTIL:** `2026-08-23` (auto-review) LUB trigger biznesowy (≥3 lokale offline + 60 dni bez P0/P1, albo decyzja właściciela w chacie).
- **REASON:** moduł zamrożony w połowie (ukończono P1–P4); brak Anti-Corruption Layer `scripts/worker_pos_fanout.php`.
- **Zamrożone fazy:** P4.5 (`worker_pos_fanout`), P5 (multi-device), P6 (conflict UI + fantom cards), P7 (offline PIN auth), P8 (SSE + demo).
- **Pliki pod ochroną:** `api/pos/sync.php`, `database/migrations/039_resilient_pos.sql`, `040_pos_server_events.sql`, `modules/pos/{sw.js, manifest.webmanifest, offline.html, .htaccess}`, `modules/pos/js/{pos_sw_register.js, PosLocalStore.js, PosSyncEngine.js, PosApiOutbox.js}`, ikony/screeny POS, `modules/online/{sw.js, manifest.webmanifest, .htaccess}`.
- **Jawnie WYŁĄCZONE z freeze:** `core/StaffFleetPresence.php`, `core/DriverFleetHelper.php` (fleet presence dla apek mobilnych — można edytować normalnie).

---

## I. Infra / hosting (odłożone)

- **7 workerów CLI** (kod gotowy, wymaga crona/CLI): `worker_driver_fanout.php`, `worker_integration_health_ping.php`, `worker_integrations.php`, `worker_ksef_inbox.php`, `worker_notifications.php`, `worker_payroll_accrual.php`, `worker_webhooks.php` + `scripts/cron_reorder_nudge.php`.
- **SSE fallback na polling** (np. `modules/online/js/online_track.js`).
- **Dwie niespójne procedury deploy** (`_docs/DEPLOY_CHECKLIST_UTI.md` + `_docs/ostatnie_zmiany_serwer.md`).
- `SLICEHUB_VAULT_KEY` + libsodium na produkcji (patrz D2).

---

## Korekty własnych twierdzeń w trakcie audytu

Dla uczciwości rejestru — twierdzenia, które w toku sesji zmieniły status:

| Twierdzenie | Pierwotnie | Skorygowane na |
|-------------|-----------|----------------|
| `orders/edit` \| `estimate` \| `sla_monitor` | „placeholdery" | **kompletne, niepodpięte endpointy** (silniki `DeltaEngine`/`PromisedTimeEngine` istnieją) |
| Adaptery integracji (`PapuAdapter` i in.) | „możliwe stuby" | **kompletne** (async, outbox + retry/DLQ) |
| Bug `DirectorApp` (C2) | potwierdzony | **błędnie wycofany → ponownie potwierdzony jako realny** |

---

## Białe plamy (nieczytane linia po linii)

Ok. **~90% logiki repo** nie było czytane linia-po-linii — poniższe traktować jako *niezweryfikowane*, nie jako „czyste":

- `core/Ksef/*` (`Client.php`, `Parser.php`, `InboxImport.php`, `InboxInvoiceRepository.php`, `InboxQtyNormalize.php`)
- `core/AuthEngine.php`, `core/JwtProvider.php`, `core/OrderStateMachine.php`, `core/WebhookDispatcher.php`
- Silniki magazynu: `KorEngine`, `MmEngine`, `InwEngine`, `BiEngine`, `PzEngine`
- `api/warehouse/*` — 13 endpointów (`add_item`, `approve`, `avco_dict`, `batch_rw`, `correction`, `documents_list`, `internal_rw`, `inventory`, `mapping`, `receipt`, `stock_list`, `transfer`, `warehouse_list`)
- Ciała `resolvePeriodBounds` (nie porównane linia-po-linii między `PayrollEngine` a `TeamPayrollEngine`)
- Który tor Papu jest aktywny per tenant (`PapuClient` sync vs `PapuAdapter` async)
- `scripts/bootstrap_vault.php`, `scripts/nuclear_reset.php`, `scripts/reset_users.php`, `scripts/seed_final_test.php` (przeczytane tylko nagłówki)

---

## Rekomendowana kolejność domykania

### 1. Quick wins (niski koszt, natychmiastowe)
- **A1–A3:** sync docs (`START_TUTAJ.md`, `00_PAMIEC_SYSTEMU.md`, `.cursorrules`) — usunąć `settle.php` z `@planned`, zsynchronizować rejestr 9 nagłówków `STATUS:`.
- **E:** usunięcie `api/orders/panic.php` (duplikat `engine.php#panic_mode`).
- **C1:** fix `ChannelRegistry::knownTypes()` (`self::$fileMap` → `self::$classMap`).

### 2. Średnie
- **D:** hardening skryptów (`setup_database`, `seed_demo_all`, `reset_users`, `nuclear_reset`, `seed_final_test`) za `SLICEHUB_SCRIPT_KEY` lub usunięcie po deployu; twardy fail `CredentialVault`; rotacja sekretów demo.
- **C2:** dodać metody `moveLayerUp/Down`, `reorderLayers`, `duplicateLayer/Companion` w `DirectorApp` (lub przepiąć panele).
- **B:** decyzja o `orders/*` (edit/estimate/sla_monitor) — podpiąć do UI albo świadomie zostawić.

### 3. Duże bloki
- **HR Faza 4:** `PayrollEngine` rewrite in-place (ledger zamiast legacy) + UI HR + migracja `sh_deductions → sh_payroll_ledger`; potem DROP `sh_users.hourly_rate`.
- **Online M5–M7:** Counter/Living Table, Checkout UX, QA/performance.

---

*Audyt read-only. Żaden kod aplikacji nie został zmieniony w tej sesji — wyłącznie ten dokument + wpis do `_docs/sessions/README.md`.*
