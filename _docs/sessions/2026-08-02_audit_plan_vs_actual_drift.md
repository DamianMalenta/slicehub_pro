# Sesja: Audyt plan vs actual — drift po Konstytucji v5

**Data:** 2026-08-02
**Powiązane:** `2026-07-30_rekomendacje_po_fazach_a_do_e.md`, `2026-05-11_constitution_v5.md`, `01_KONSTYTUCJA.md §8`
**Cel:** Zrekonstruować odchylenia od pierwotnego planu v5 (2026-05-11) i zweryfikować czy agenci podpinali gotowe silniki czy tworzyli nowe.

---

## 1. Diagnoza procesu

Po Konstytucji v5 (2026-05-11, Prawo VIII — Domknięcie Kontraktu) agenci domykali fazy "po swojemu": jeden backend, drugi frontend, trzeci dokumentację. Brak spójnej weryfikacji end-to-end. "DOMKNIĘTE" w commit msg ≠ end-to-end wired.

Trzy patologie:
1. **"Gotowe do podpięcia" = niepodpięte** — komponenty napisane, testy jednostkowe przechodzą, commit mówi "DOMKNIĘTE", ale brakuje konsumenta (worker, frontend, transakcja).
2. **Duble z niedokończonej migracji** — nowa klasa obok starej, obie żyją.
3. **"DOMKNIĘTE" z otwartym wyjątkiem** — faza domknięta z "R8 open" / "@planned", w kodzie zostaje szczelina.

---

## 2. Smoking gun: worker_payroll_accrual.php

**Krytyczne znalezisko historyczne:**

- `72eec73` (2026-05-03) — **DODANY** `scripts/worker_payroll_accrual.php` (321 linii) jako Faza 3B. Konsument outboxu `employee.clocked_out` → `PayrollLedger::record` z `work_earnings`. Współpracował z `PayrollAllocator::splitByPeriod` (midnight-crossing).
- `1f50b4e` (2026-07-29) — **USUNIĘTY** w "Backend cleanup" razem z 9 "legacy endpoints" (`api/delivery/dispatch.php`, `api/orders/accept.php`, `api/payments/settle.php`, itd.).

**Błąd kategoryzacji:** agent potraktował `worker_payroll_accrual.php` jako "legacy endpoint" jak `api/delivery/dispatch.php`. Ale:
- 8 z 9 usuniętych endpointów miało logikę przeniesioną do `core/` (ekstrakcja OK — `SettlementEngine`, `PanicEngine`, `OrderStateMachine`, `courses/engine.php#dispatch`, `online/engine.php#guest_checkout`).
- **`worker_payroll_accrual.php` był aktywnym konsumentem outboxu** — nie miał następcy w `core/`. Usunięcie = zagubienie funkcjonalności, nie ekstrakcja.

**Skutek obecny:**
- `HrClockEngine::clockOut` publikuje `employee.clocked_out` do `sh_event_outbox` (linia 144-152).
- Brak konsumenta → eventy nie są przetwarzane.
- `sh_payroll_ledger` nie dostaje `work_earnings` automatycznie.
- `PayrollAllocator` (split month-boundary) — TEST-ONLY, napisany pod tego workera.
- HR drawer UI pokazuje dane niepełne (brak live accrual z nowych sesji).

---

## 3. Tabela: Plan v5 vs Stan faktyczny per komponent

| Komponent | Plan (v5 / commit) | Stan faktyczny (kod) | Werdykt |
|---|---|---|---|
| **Faza A** SlaThresholds | SSOT + 4 frontendy | 5 call site'ów API + 4 frontendy | ✅ MATCH |
| **Faza B** PromisedTimeEngine | 4 ścieżki ASAP | 4 ścieżki + `estimate.php` @planned | ✅ MATCH |
| **Faza C** SLA breach panel | Dispatcher + cron | sla_monitor + courses poll 30s + worker | ✅ MATCH |
| **Faza D** FoodCost | backoffice report | food_cost.php + modules/backoffice/food_cost/ | ✅ MATCH |
| **Faza E** OrderEdit + DeltaEngine | edit.php + KDS highlight | edit.php + DeltaEngine + KDS OK, **brak outboxu** (R8 open) | ⚠️ PARTIAL |
| **R1** SKU consistency | fix test_runner | naprawione | ✅ MATCH |
| **R2** edited_since_print reset | reset przy `ready` | reset w `bump_order` (frontend path) | ✅ MATCH |
| **Settlement F1** single tender | SSOT + POS | SettlementEngine + POS UI | ✅ MATCH |
| **Settlement F2** split-tender UI | POS modal | pos_ui.js:375-477 pełny modal | ✅ MATCH |
| **Settlement F3** tables split | tables UI | **Backend jest, frontend tables NIE MA** (redirect do POS) | ❌ BACKEND-ONLY |
| **Settlement F4** driver collect | driver_app UI | driver_app.js:636-637 Gotówka/Karta | ✅ MATCH |
| **HR Faza 4** PayrollEngine rewrite | ledger SSOT | PayrollEngine/TeamPayrollEngine/PayrollLedger WIRED + drawer UI | ✅ MATCH |
| **HR worker_payroll_accrual** | cron worker Faza 3B | **PLIK USUNIĘTY w 1f50b4e**. PayrollAllocator TEST-ONLY. Outbox nie ma konsumenta | ❌ REGRESJA |
| **ChoiceQR P2.1** | OSM + endpointy | events.php/pay.php/webhook.php używają OSM | ✅ MATCH |
| **ChoiceQR P2.3** compliance | K5/NC6/K3/NC1/NC12 | wszystkie 5 fixów | ✅ MATCH |
| **ChoiceQR bez CartEngine** | świadomy wyjątek Prawa IV | webhook.php pomija CartEngine | ✅ MATCH (z wyjątkiem) |
| **ChoiceQR outbox** | publish per outbox | events.php ✅ (w tx), pay.php ✅, webhook.php ✅, **inbound.php POZA tx** | ⚠️ PARTIAL |

---

## 4. Duble silników (zbieżne implementacje)

### D1 — AuthGuard (klasa) vs auth_guard.php (procedural)
- **Pochodzenie:** oba z `554594f` (2026-04-23, checkpoint początkowy).
- **Żywa ścieżka:** `auth_guard.php` — 41 endpointów API.
- **Martwa:** `AuthGuard` — tylko 2 endpointy admina (`api/studio/generate_key.php`, `api/system/generate_seq.php`).
- **Diagnoza:** Niedokończona migracja na klasowy JWT middleware. Nie "mieszanie" agenta — to dług z checkpointu.

### D2 — PapuClient (sync) vs PapuAdapter (async outbox)
- **Pochodzenie:** oba z `554594f` (2026-04-23, checkpoint).
- **Żywa ścieżka:** `PapuAdapter` — zarejestrowany w `AdapterRegistry`, odpalany przez `worker_integrations.php` (async outbox).
- **Martwa/zastąpiona:** `PapuClient` — wołany synchronicznie z `api/pos/engine.php:1139,1154` (post-commit, gubi zdarzenia).
- **Diagnoza:** Dwa równoległe kanały push do Papu.io. `PapuClient` łamie wzorzec Transactional Outbox (AGENTS.md §14). POS powinien publikować do outboxu (już robi przez `OrderEventPublisher`), `PapuAdapter` przejmuje push.

### D3 — KdsTicketEngine::bump (action `bump_ticket`) vs bump_order (inline)
- **Pochodzenie:** `KdsTicketEngine` dodany w `eb4e1bf` (ekstrakcja z `api/kds/update_ticket.php` usuniętego w `1f50b4e`).
- **Żywa ścieżka:** `bump_order` w `api/kds/engine.php:191` — frontend `kds_app.js:299` od początku (od `554594f`).
- **Martwa:** action `bump_ticket` (`api/kds/engine.php:334`) + `KdsTicketEngine::bump` — **frontend nigdy nie wołał `bump_ticket`** (git log -S potwierdza).
- **Diagnoza:** Ekstrakcja bez konsumenta. Agent wyciągnął logikę per-ticket do `core/KdsTicketEngine.php` + dodał action `bump_ticket`, ale frontend używał `bump_order` (per-order). Silnik został jako "DOMKNIĘTY" ale bez podpięcia UI.

---

## 5. Niedopięcia end-to-end (gotowe ale nie podpięte)

| # | Komponent | Co jest | Czego brak | Skutek |
|---|---|---|---|---|
| N1 | `worker_payroll_accrual.php` | PayrollAllocator, outbox `employee.clocked_out`, drawer UI | **Plik usunięty w 1f50b4e** | HR Faza 3B niedziałająca — clock-out eventy nie mają konsumenta |
| N2 | SettlementEngine F3 (tables split) | `api/tables/engine.php:split_payment` + `applyPartialPayments` | **Brak UI w `modules/tables/`** (redirect do POS) | Split-payment przy stolikach niedostępny z UI tables |
| N3 | `api/orders/edit.php` outbox | edit.php + DeltaEngine + KDS delta | **Brak `OrderEventPublisher::publish`** (R8 open) | Edycje zamówień niewidoczne dla webhooków/integracji |
| N4 | `inbound.php` outbox poza tx | publikacja jest (linia 537) | **Publikacja po `commit` (521)** — łamie transactional outbox | Błąd między commit a publish → gubi zdarzenie |
| N5 | `pos/engine.php:1778` dispatch K-number | UPDATE `delivery_status='in_delivery'` | **Brak publikacji** | Driver dispatch niewidoczny dla subskrybentów |
| N6 | `courses/engine.php` 4× delivery UPDATE | linie 506 (queued), 590 (assign), 685 (reassign), 883 (recall) | **Brak publikacji** (dispatch 382 i delivered 786 publikują) | 4 transitie delivery niewidoczne dla subskrybentów |

---

## 6. Bypass'y SSOT (żywe ale naruszają zasady)

| # | Bypass | Skala | Akcja |
|---|---|---|---|
| B1 | `Money` — raw `* 100` zamiast `Money::fromPln` | 22 instancje (13 w CartEngine, 9 reszta) | Wymiana w CartEngine priorytet |
| B2 | `Uuid` — `mt_rand` zamiast `Uuid::v4` | 3 instancje w `pos/engine.php` | Prosta wymiana |
| B3 | `OrderStateMachine::transitionDelivery` — 0 wywołań, delivery UPDATE surowym SQL | 6+ instancji w courses/pos | Albo użyć `transitionDelivery`, albo usunąć metodę |

---

## 7. Rekomendacja: jak to powinno wyglądać

### Krok 1 — Wstrzymać "DOMKNIĘTE" jako pojęcie
Wprowadź w `AGENTS.md` / `01_KONSTYTUCJA.md §8` definicję:
> **DOMKNIĘTE** = endpoint API + frontend konsument + (jeśli dotyczy outboxu) publikacja w tej samej transakcji + test E2E w `tests/test_runner.html`. Brak któregokolwiek = `@planned` lub `@backend-only`, NIE "DOMKNIĘTE".

### Krok 2 — Fazy naprawcze (nie mieszać)

**Faza N (naprawy niedopięć) — najwyższy priorytet:**
1. **N1**: Przywrócić `scripts/worker_payroll_accrual.php` z git (`git show 72eec73:scripts/worker_payroll_accrual.php`) lub napisać na nowo — konsument `employee.clocked_out` z outboxu, woła `PayrollAllocator::splitByPeriod` + `PayrollLedger::record` z `work_earnings`. Domknie HR Faza 3B realnie.
2. **N3+N5+N6**: Dodać `OrderEventPublisher::publishOrderLifecycle` w `edit.php`, `pos/engine.php:1778`, `courses/engine.php` (506/590/685/883) — w tej samej transakcji co UPDATE.
3. **N4**: Przenieść publikację `inbound.php:537` **przed** `commit` (521) — do transakcji.
4. **N2**: Zbudować UI split-payment w `modules/tables/` (lub zdecydować: tables zawsze redirect do POS — wtedy usunąć endpoint `split_payment` jako martwy).

**Faza D (likwidacja dubli):**
1. **D1**: Przepiąć `api/studio/generate_key.php` + `api/system/generate_seq.php` na `auth_guard.php`, usunąć `core/AuthGuard.php`.
2. **D2**: Usunąć `PapuClient` z `api/pos/engine.php:1139,1154`, POS ma publikować do outboxu (już robi), `worker_integrations.php` + `PapuAdapter` przejmuje push.
3. **D3**: Usunąć action `bump_ticket` z `api/kds/engine.php` + `core/KdsTicketEngine.php` (lub zrefaktorować `bump_order` żeby delegował do engine — drugie czystsze ale więcej roboty).

**Faza B (SSOT hardening):**
1. **B1**: Wymienić 13× `* 100` w `api/cart/CartEngine.php` na `Money::fromPln` — gorąca ścieżka, błąd groszowy realny.
2. **B2**: 3× `mt_rand` UUID w `pos/engine.php` → `Uuid::v4`.
3. **B3**: Zdecydować — albo `transitionDelivery()` wdrożyć w courses/pos (6+ call site'ów), albo usunąć metodę jako martwą i udokumentować że delivery_status nie przechodzi przez maszynę stanu (świadomy wyjątek).

### Krok 3 — Weryfikacja end-to-end (jednorazowo)
Po Fazie N: `seed_demo_all.php` → clock-in/clock-out pracownika → sprawdź czy `work_earnings` pojawił się w `sh_payroll_ledger` (N1). Edytuj zamówienie → sprawdź `sh_event_outbox` (N3). Dispatch K-number → sprawdź outbox (N5). To testy których brakuje w `test_runner.html` — obecnie 62 testy nie weryfikują outboxu ani workera payroll.

### Krok 4 — Aktualizacja dokumentacji (na końcu)
Po naprawach zaktualizuj `00_PAMIEC_SYSTEMU.md` i `01_KONSTYTUCJA.md §8` (lista `@planned`) — usuń wpisy które stały się realnie DOMKNIĘTE, zostaw tylko świadome orphans (`estimate.php` — scheduled-picker). Eliminuje główną przyczynę "namieszania w dokumentacji": agenci czytali nieaktualny `@planned` i pracowali pod złą premisą.

---

## 8. Porównanie kodu OLD vs NEW (weryfikacja "połatane vs innowacyjne")

Hipoteza użytkownika: stare pliki były innowacyjne, nowe są "połatane żeby działało".
Weryfikacja przez porównanie faktycznego kodu (nie dokumentacji):

### 8.1 Pliki WRAPPER (usunięcie OK — silnik w core/ żyje)

| Stary plik | Był | Werdykt |
|---|---|---|
| `api/payments/settle.php` | Thin HTTP adapter nad `core/SettlementEngine.php` (header: "Phase 1 wrapper") | Usunięcie OK — SettlementEngine żyje, POS woła bezpośrednio |
| `api/staff/payroll.php` | Wrapper (header: "STATUS: WRAPPER — thin alias for PayrollEngine::calculate") | Usunięcie OK — PayrollEngine żyje w hr/engine.php |
| `api/dashboard/team_payroll.php` | Wrapper nad TeamPayrollEngine | Usunięcie OK — TeamPayrollEngine żyje |

### 8.2 Pliki FULL IMPLEMENTATION (ekstrakcja do core/ — weryfikacja)

| Stary plik | Nowy odpowiednik | Werdykt subagentów (cross-check) |
|---|---|---|
| `api/delivery/dispatch.php` (21KB) | `courses/engine.php#dispatch` | **NEW is better** — zachował wszystko + dodał `force_new`, `active_course_id`, `delivery_status='in_delivery'` guard |
| `api/delivery/reconcile.php` (21KB) | `courses/engine.php#reconcile` | **ROUGHLY EQUAL** — zachował night-shift-safe + settlement-safe, dodał active-order guard. Utracono tylko komentarze dokumentujące innowacje |
| `api/orders/accept.php` (15KB) | `pos/engine.php#accept_order` | **NEW is better** — zachował OSM + KDS + outbox + WarehouseConsumeHook, dodał PromisedTimeEngine |
| `api/orders/panic.php` (14KB) | `pos/engine.php#panic_mode` + `core/PanicEngine.php` | **NEW is better** — ekstrakcja do PanicEngine, strict types, UUID, 429 debounce, DateTimeImmutable |
| `api/orders/checkout.php` (23KB) | `online/engine.php#guest_checkout` | **NEW is better** — zachował CartEngine + WzEngine + lock + idempotency, dodał PromisedTimeEngine + geocoding + loyalty |
| `api/kds/update_ticket.php` (16KB) | `kds/engine.php#bump_ticket` + `core/KdsTicketEngine.php` | **ROUGHLY EQUAL** kodowo, **ALE orphan** — frontend nigdy nie wołał `bump_ticket`, używa `bump_order`. Per-ticket state machine nieosiągalny z UI |

### 8.3 Plik bez następcy — REGRESJA

| Stary plik | Nowy odpowiednik | Werdykt |
|---|---|---|
| `scripts/worker_payroll_accrual.php` (34KB, 413 linii) | **BRAK** | **REGRESSION — CRITICAL** |

**Co zawierał stary worker (innowacje utracone):**
- Konsument outboxu `employee.clocked_out` → `sh_payroll_ledger` (jedyne źródło automatycznego accrual)
- **HR-6 alokacja międzyokresowa**: `PayrollAllocator::splitByPeriod` — sesja przecinająca koniec miesiąca cięta na segmenty per okres rozliczeniowy
- **Largest remainder method**: zarobek dzielony proporcjonalnie do SEKUND, zero groszowego wycieku
- **Rate resolver 3-stopniowy**: (1) `rate_at_clock_in` z payloadu, (2) temporal lookup w `sh_employee_rates`, (3) no-op dla niegodzinowych
- **Int-only arithmetic**: DECIMAL(10,4) → milli-hours × rate → HALF_UP rounding, zero floats
- **Idempotencja deterministyczna**: segment #0 = `session_uuid`, segment #N = `Uuid::deterministic('ws-split-{uuid}-{N}')`
- **Period locking deferment**: `resolveOpenPeriod()` — wpis z zamkniętego okresu przesuwany do najbliższego otwartego (24-miesięczny horyzont), z adnotacją w description
- **Retry z exponential backoff**: MAX_ATTEMPTS=5, `next_attempt_at = NOW() + (attempts × 60s)`, dead-letter po 5 próbach
- **Atomic row-level claim**: `UPDATE status='dispatching' WHERE status='pending'` — zapobiega duplikatom
- **Statystyki**: processed, accrued, split_sessions, deferred_entries, skipped_no_rate, failed

**Kompatybilność z obecnym kodem (zweryfikowane):**
- `PayrollLedger::record($pdo, $tenantId, [...])` — sygnatura i payload (`entry_uuid, employee_id, period_year, period_month, entry_type, amount_minor, currency, hours_qty, rate_applied_minor, ref_work_session_id, description`) **zgodne 1:1** z obecnym API
- `PayrollLedger::TYPE_WORK_EARNINGS` — istnieje (linia 35)
- `PayrollLedger::isPeriodLocked()` — istnieje (linia 228)
- `PayrollAllocator::splitByPeriod` + `allocate` — istnieją (TEST-ONLY, czekają na workera)
- **Wniosek: worker można przywrócić z git (`git show 72eec73:scripts/worker_payroll_accrual.php`) i zadziała z obecnym PayrollLedger bez modyfikacji.**

### 8.4 Podsumowanie werdyktów

| Kategoria | Liczba | Werdykt |
|---|---|---|
| Wrappery usunięte (OK) | 3 | Silniki w core/ żyją dalej |
| Ekstrakcje do core/ (upgrade) | 5 | NEW is better — zachowały innowacje + dodały nowe |
| Ekstrakcja orphan (KdsTicketEngine) | 1 | Kod OK, ale frontend nie woła — per-ticket KDS nieosiągalny |
| **Regresja (brak następcy)** | **1** | **worker_payroll_accrual — krytyczna utrata funkcjonalności** |

**Hipoteza "stare było innowacyjne, nowe jest połatane": NIEPOTWIERDZONA dla 9/10 plików.** Ekstrakcje do `core/` były realnymi upgrade'ami (strict types, lepsza separacja, nowe funkcje). **POTWIERDZONA dla 1 pliku** — `worker_payroll_accrual.php` był wyrafinowany (HR-6 alokacja, largest remainder, period locking deferment, idempotencja deterministyczna) i został usunięty bez następcy.

---

## 9. Otwarte pytania

1. **`worker_payroll_accrual.php` — przywrócić z git czy napisać od nowa?** Wersja z `72eec73` (413 linii) jest **kompatybilna z obecnym PayrollLedger API** (zweryfikowane — sygnatura `record()`, `TYPE_WORK_EARNINGS`, `isPeriodLocked`, `PayrollAllocator` wszystkie zgodne). Przywrócenie z git = najszybsza naprawa. Jedyne co warto dodać: PID-file locking (wzorzec z `worker_webhooks.php`).
2. **F3 tables — budować UI czy usunąć endpoint?** Tables obecnie redirect do POS dla wszystkich akcji zamówień. Split-payment UI w tables = duplikacja POS UI. Alternatywa: usunąć `split_payment` endpoint jako martwy.
3. **`transitionDelivery()` — wdrożyć czy usunąć?** 6+ surowych UPDATE'ów `delivery_status` w courses/pos. Wdrożenie = spójność z maszyną stanu, ale to refaktor 6 call site'ów. Usunięcie = świadomy wyjątek udokumentowany.
4. **`KdsTicketEngine` — usunąć czy podpiąć?** Per-ticket state machine istnieje ale frontend używa `bump_order` (per-order). Albo usunąć `bump_ticket` action + `KdsTicketEngine` (prostsze), albo zrefaktorować frontend żeby używał per-ticket (więcej roboty, ale pozwala na multi-station KDS — pizza station done, drink station pending).
