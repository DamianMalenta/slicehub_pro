# Sesja: Drift Rectification N1–N5

**Data:** 2026-08-04
**Powiązane:** `2026-08-02_audit_plan_vs_actual_drift.md`, `01_KONSTYTUCJA.md §8 (Domknięcie Kontraktu)`
**Cel:** Naprawić 8 driftów zidentyfikowanych w audycie z 2026-08-02.

---

## Wykonane naprawy

### N1 — Regresja krytyczna

| # | Drift | Fix | Commit |
|---|-------|-----|--------|
| N1.1 | `worker_payroll_accrual.php` usunięty w "cleanup" | Przywrócony z `72eec73`, dodany PID-lock + `--loop` + CLI guard | Committed (session prior) |
| N1.2 | `inbound.php` publishOrderLifecycle po commit() | Przeniesiony PRZED `$pdo->commit()` (wewnątrz tx) | Committed (session prior) |

### N2 — Brakujące outbox publications

| # | Plik | Opis |
|---|------|------|
| N2.1 | `api/orders/edit.php` | Dodany `publishOrderLifecycle` wewnątrz tx |
| N2.2 | `core/Elzab/ElzabFiscalEngine.php` | Dodany `publishOrderLifecycle` po zapisie fiscal_receipt_number |
| N2.3 | `core/PanicEngine.php` | Dodany `publishOrderLifecycle` wewnątrz tx PanicEngine |

### N3 — Delivery/dispatch outbox

| # | Plik | Transition |
|---|------|-----------|
| N3.1a | `api/courses/engine.php` (create_course) | queued |
| N3.1b | `api/courses/engine.php` (assign_driver) | queued → in_delivery |
| N3.1c | `api/courses/engine.php` (append_to_course) | unassigned → in_delivery |
| N3.1d | `api/courses/engine.php` (cancel_stop) | in_delivery → unassigned |
| N3.2 | `api/pos/engine.php` (assign_route) | order.in_delivery |

### N4 — Orphany i duble

| # | Drift | Fix |
|---|-------|-----|
| N4.1 | `KdsTicketEngine` orphan (frontend nie woła `bump_ticket`) | Usunięty `core/KdsTicketEngine.php` + `bump_ticket` z `api/kds/engine.php` |
| N4.2 | `PapuClient` sync push obok `PapuAdapter` (async outbox) | Usunięty `core/Integrations/PapuClient.php` + blok sync w `pos/engine.php` |
| N4.3 | POS "ZAAKCEPTUJ" wysyła `now` → blokuje PromisedTimeEngine ASAP | `pos_app.js`: mins===0 → `custom_time: null` → ASAP engine triggers |

### N5 — Hardening

| # | Drift | Fix |
|---|-------|-----|
| N5.1 | `* 100` w CartEngine zamiast `Money::fromPln` | **DEFERRED** — matematycznie identyczny wynik, P4 refactor |
| N5.2 | `mt_rand` UUID w `pos/engine.php` | Zamienione 3 bloki na `Uuid::v4()` (CSPRNG) |

---

## Podsumowanie zmian

- **Pliki usunięte:** `core/Integrations/PapuClient.php`, `core/KdsTicketEngine.php`
- **Pliki dodane:** `scripts/worker_payroll_accrual.php` (przywrócony)
- **Pliki zmodyfikowane:** `api/pos/engine.php`, `api/courses/engine.php`, `api/orders/edit.php`, `api/integrations/inbound.php`, `api/kds/engine.php`, `core/Elzab/ElzabFiscalEngine.php`, `core/PanicEngine.php`, `core/OrderEventPublisher.php`, `modules/pos/js/pos_app.js`

## Otwarte (future)

- N5.1: Unifikacja `(int)round(float*100)` → `Money::fromPln()` w CartEngine (14 instancji, P4)
- `PromisedTimeEngine::calculate` mode `scheduled` — dead code, do usunięcia w osobnej sesji
