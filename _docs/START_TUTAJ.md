# START TUTAJ — SliceHub Enterprise OS

> **Punkt wejścia do dokumentacji.** Czytaj ten plik pierwszy, potem dokumenty wskazane dla Twojego zadania.
> Ostatnia aktualizacja indeksu sesji: **2026-07-07** (`_docs/sessions/README.md`).

---

## 1. Zasady nadrzędne (nie omijaj)

| Kolejność | Plik | Po co |
|-----------|------|--------|
| 1 | [`01_KONSTYTUCJA.md`](01_KONSTYTUCJA.md) | 10 praw systemu (multi-tenant, silosy SKU, Zero-Reload, Prawo VIII–X) |
| 2 | [`00_PAMIEC_SYSTEMU.md`](00_PAMIEC_SYSTEMU.md) | North Star — wizja, freeze offline POS, mapa modułów |
| 3 | [`02_ARCHITEKTURA.md`](02_ARCHITEKTURA.md) | Mapa katalogów, API, moduły |
| 4 | [`04_BAZA_DANYCH.md`](04_BAZA_DANYCH.md) | Schemat DB — **zakaz wymyślania tabel/kolumn** |

Operacyjny skrót dla AI w czasie rzeczywistym: [`.cursorrules`](../.cursorrules) w root repo.

---

## 2. Wybierz tor zadania

### A. Magazyn + Studio Menu + spójność kontraktów (priorytet handoff)

**Handoff:** [`PRZEKAZANIE_NOWE_OKNO_MAGAZYN_STUDIO.md`](PRZEKAZANIE_NOWE_OKNO_MAGAZYN_STUDIO.md)

Czytaj też: `04_BAZA_DANYCH.md`, `03_MAPA_KOPALNI.md`, sesje Studio w [`sessions/2026-05-11_STUDIO_RELEASE_INDEX.md`](sessions/2026-05-11_STUDIO_RELEASE_INDEX.md).

Cel: jeden język między `sys_items` / `wh_stock` / `sh_menu_items` / `sh_recipes` / Studio UI / POS.

### B. Moduł Online (storefront klienta)

**Kanon:** [`15_KIERUNEK_ONLINE.md`](15_KIERUNEK_ONLINE.md) + [`ustalenia.md`](ustalenia.md)

Status ~65% Fazy 2: G1–G6 done; **M5 Living Table** i **M6 Checkout** otwarte.

> Dokumenty w [`ARCHIWUM/`](ARCHIWUM/) (stara wizja Online) — tylko historia, nie źródło prawdy.

### C. KSeF / Procurement (zakupy, inbox, PZ)

**Kanon sesji:** [`sessions/2026-05-22_ksef_qty_normalization.md`](sessions/2026-05-22_ksef_qty_normalization.md)  
**Propozycja (zsynchronizowana):** [`proposals/ksef_invoice_qty_normalization.md`](proposals/ksef_invoice_qty_normalization.md)  
**Skrót operacyjny:** [`sessions/HANDOFF_2026-05-22_ksef_inbox_continue.md`](sessions/HANDOFF_2026-05-22_ksef_inbox_continue.md) — **DOMKNIĘTE**

Migracje: **058** (qty cache), **059** (mapping per NIP). Testy CLI:

```bash
php scripts/test_invoice_qty_normalizer.php   # 10/10
php scripts/test_ksef_parser_quality.php      # 8/8
```

### D. POS / logistyka / kierowca

- POS: `api/pos/engine.php`, `modules/pos/`
- Kursy: `api/courses/engine.php`, `modules/courses/`, `modules/driver_app/`
- Payment Lock: `collect_payment` przed `deliver_order`
- **Settle:** produkcyjnie `pos/engine.php#settle_and_close` → `core/SettlementEngine.php`; ~~`api/payments/settle.php`~~ **USUNIĘTY 2026-07-28** (martwy wrapper, zero call-site'ów)

### E. HR / Payroll

[`18_BACKOFFICE_HR_LOGIC.md`](18_BACKOFFICE_HR_LOGIC.md) — Faza 3 done; Faza 4 (PayrollEngine → ledger) otwarta.

### F. BI / P&L

[`sessions/2026-05-14_bi_engine.md`](sessions/2026-05-14_bi_engine.md) + [`sessions/2026-05-21_bi_opex_flow_from_pr28.md`](sessions/2026-05-21_bi_opex_flow_from_pr28.md)

### G. Deploy / hosting / multi-tenant

- [`DEPLOYMENT_HOSTING.md`](DEPLOYMENT_HOSTING.md), [`DEPLOY_CHECKLIST_UTI.md`](DEPLOY_CHECKLIST_UTI.md)
- SSOT API: [`sessions/2026-05-21_api_base_paths.md`](sessions/2026-05-21_api_base_paths.md) → `core/js/sh_api_base.js`
- Discovery tenanta / PIN: [`sessions/2026-05-22_tenant_discovery_auth.md`](sessions/2026-05-22_tenant_discovery_auth.md)
- Seed: [`SEED_GUIDE.md`](SEED_GUIDE.md)

### H. Materiały SPARK / F6S (bez runtime)

[`sessions/2026-05-20_spark_prezentacja_ludzka.md`](sessions/2026-05-20_spark_prezentacja_ludzka.md), katalog `_docs/SPARK_materialy/`, `spark_update/`.

---

## 3. Indeks audytów sesji AI (Prawo X)

Pełna lista: [`sessions/README.md`](sessions/README.md)

Każda sesja zmieniająca `core/`, `api/`, migracje lub Konstytucję **musi** zostawić plik `sessions/YYYY-MM-DD_<topic>.md` (4 sekcje: Cel, Pliki, Decyzje, Otwarte).

---

## 4. Golden path — dev od zera

```bash
# Baza
mysql -u root slicehub_pro_v2 < database/migrations/001_init_slicehub_pro_v2.sql
php scripts/apply_migrations_chain.php    # do 059; 015/037 mogą FAIL na MariaDB 10.11 — znane
php scripts/seed_demo_all.php

# Serwisy (Cloud Agent / lokalnie)
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld && mysqld_safe &
apachectl start

# Weryfikacja
php scripts/test_invoice_qty_normalizer.php
php scripts/test_ksef_parser_quality.php
# Przeglądarka: http://localhost/slicehub/tests/test_runner.html → 62/62
# Lub headless (agent): node scripts/run_test_runner_headless.cjs  (wymaga puppeteer-core poza repo)
```

Szczegóły: [`AGENTS.md`](../AGENTS.md) w root.

---

## 5. Status `@planned` / orphan (Prawo VIII)

| Element | Stan |
|---------|------|
| ~~`api/payments/settle.php`~~ | **USUNIĘTY 2026-07-28** — logika w `core/SettlementEngine.php` |
| ~~`api/orders/panic.php`~~ | **USUNIĘTY 2026-07-28** — wchłonięty do `core/PanicEngine.php` → `pos/engine.php#panic_mode` |
| ~~`api/orders/accept.php`~~ | **USUNIĘTY 2026-07-28** — `canTransition()` pre-check wchłonięty do `pos/engine.php#accept_order` |
| ~~`api/orders/checkout.php`~~ | **USUNIĘTY 2026-07-28** — `WzEngine::checkAvailability()` wchłonięte do `online/engine.php` + `pos/engine.php` |
| ~~`api/delivery/dispatch.php`~~ | **USUNIĘTY 2026-07-28** — `out_for_delivery_at` + events wchłonięte do `courses/engine.php#dispatch` |
| ~~`api/delivery/reconcile.php`~~ | **USUNIĘTY 2026-07-28** — driver release + stats wchłonięte do `courses/engine.php#reconcile` |
| ~~`api/kds/update_ticket.php`~~ | **USUNIĘTY 2026-07-28** — per-ticket state machine w `core/KdsTicketEngine.php` → `kds/engine.php#bump_ticket` |
| ~~`api/staff/payroll.php`~~ | **USUNIĘTY 2026-07-28** — martwy wrapper, logika w `hr/engine.php#payroll_report` |
| ~~`api/dashboard/team_payroll.php`~~ | **USUNIĘTY 2026-07-28** — martwy wrapper, logika w `hr/engine.php#payroll_report` |
| `api/orders/edit.php`, `estimate.php`, `sla_monitor.php` | @planned (unikalna logika — zostawione) |
| `tables/split_payment`, `complete_dine_in` (UI) | backend bez pełnego podpięcia frontend |
| `core/PromisedTimeEngine.php` (tryb `scheduled`) | ⚠️ **AUDYT 2026-08-03**: tryb scheduled = martwy kod (0 call-site'ów); ASAP częściowo wpięte (POS "ZAAKCEPTUJ" wysyła `now` zamiast pustego → silnik nie odpala). Szczegóły: [`sessions/2026-08-03_promised_time_wiring_audit.md`](sessions/2026-08-03_promised_time_wiring_audit.md) |
| Offline POS P4.5–P8 | FREEZE do 2026-08-23 — [`17_OFFLINE_POS_BACKLOG.md`](17_OFFLINE_POS_BACKLOG.md) |

Pełna lista: `01_KONSTYTUCJA.md` § Prawo VIII, `00_PAMIEC_SYSTEMU.md`.

---

## 6. Archiwum

Historyczne / mylące dokumenty: [`ARCHIWUM/README.md`](ARCHIWUM/README.md). **Nie** buduj na nich nowych feature'ów.

---

## 7. Konflikt priorytetów (świadomy wybór)

| Dokument | Mówi |
|----------|------|
| `15_KIERUNEK_ONLINE.md` | Priorytet: M5 Living Table, M6 Checkout |
| `PRZEKAZANIE_NOWE_OKNO_MAGAZYN_STUDIO.md` | Priorytet: Magazyn + Studio, **nie** sam Online |

**Przy starcie sesji:** ustal z właścicielem który tor jest aktualny — handoff punktowy ma pierwszeństwo przy konkretnym zadaniu.
