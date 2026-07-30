# Rekomendacje po Fazach A→E (SLA / PromisedTime / Food Cost / Order Edit)

**Data:** 2026-07-30
**Zakres:** Zamknięcie cyklu wpięcia orphan'ów z audytu 2026-04-19 (Prawo VIII — Domknięcie Kontraktu)
**Powiązane sesje:**
- `2026-07-29_promised_time_sla_audit_and_plan.md` (plan A→E)
- `2026-07-29_phase_a_sla_thresholds.md` (Faza A)
- `2026-07-29_phase_b_promised_time_engine.md` (Faza B)
- `2026-07-29_phase_c_sla_breach_panel.md` (Faza C)
- `2026-07-30_phase_d_food_cost_report.md` (Faza D)
- `2026-07-30_phase_e_order_edit_kds_delta.md` (Faza E)

---

## 1. Status zamknięcia (wszystkie 5 faz DOMKNIĘTE)

| Faza | Komponent | Commit | Status |
|---|---|---|---|
| A | SLA Thresholds (ujednolicenie w frontendach) | `4b5e3cb` | ✅ DOMKNIĘTE 2026-07-29 |
| B | PromisedTimeEngine (4 ścieżki ASAP) | `fcb6bb1` | ✅ DOMKNIĘTE 2026-07-29 |
| C | SLA breach panel (Dispatcher + cron) | `b1feee2` | ✅ DOMKNIĘTE 2026-07-29 |
| D | Food Cost Report (backoffice) | `34e5b26` | ✅ DOMKNIĘTE 2026-07-29 |
| E | Order Edit + DeltaEngine (KDS highlight) | `de13674` | ✅ DOMKNIĘTE 2026-07-30 |

**Repo:** 10 commitów ahead of `origin/main` (NIE pushowane — zgodnie z instrukcją sesji).

---

## 2. Pozostałe `@planned` (po Fazach A→E)

| Komponent | Status | Uzasadnienie zachowania |
|---|---|---|
| `api/orders/estimate.php` | 🟡 PLANNED | Wrapper HTTP na `PromisedTimeEngine::calculate()`. Silnik jest wpięty **bezpośrednio** w 4 produkcyjne ścieżki (Faza B). Wrapper pozostaje dla **future scheduled-picker UI** (storefront "Zaplanuj na później" — pre-check przed złożeniem). Brak konsumenta UI = celowy. Nie usuwać. |

**Wniosek:** Lista `@planned` w `_docs/01_KONSTYTUCJA.md §8` jest aktualna. Jeden świadomy orphan (`estimate.php`) z udokumentowanym uzasadnieniem.

---

## 3. Rekomendacje dla kolejnych sesji (uporządkowane wg priorytetu)

### 🔴 WYSOKI PRIORYTET

#### R1. Demo data SKU inconsistency (seed_demo_all.php) — ✅ DOMKNIĘTE 2026-07-30

**Problem (diagnoza poprawiona):** Pierwotna rekomendacja twierdziła, że `seed_demo_all.php` zapisuje `item_sku=MARGHERITA` w `sh_order_lines`. Audyt `git blame` (2026-07-30) wykazał, że seed od pierwszego commita (`554594f`, 2026-04-23) używa poprawnych SKU z prefiksem (`PIZZA_MARGHERITA`, `BURGER_CLASSIC`, etc.) — zgodnych z `sh_menu_items.ascii_key`. `MARGHERITA` (bez prefiksu) w bazie pochodził z ręcznego utworzenia menu item podczas testów, nie z seeda.

**Realny problem:** `tests/test_runner.html` (suite POS) używał nieistniejących SKU (`MARGHERITA`, `OPT_JALAPENO`, `SER_MOZZARELLA`) zamiast `PIZZA_MARGHERITA`, `EXTRA_JALAP`, `SER_MOZZ` — test przechodził tylko dlatego, że w bazie był ręcznie utworzony item `ascii_key=MARGHERITA`.

**Fix (2026-07-30):**
- `tests/test_runner.html` — poprawiono SKU + ceny (24.00/56.00 zamiast 25.99/51.98) na zgodne z seedem.
- `scripts/check_sku_consistency.php` (nowy) — backfill `MARGHERITA` → `PIZZA_MARGHERITA` dla istniejących order lines + weryfikacja orphanów.

**Weryfikacja:** Po `seed_demo_all.php` — 0 orphan SKU (LEFT JOIN `sh_menu_items` zwraca 0 braków). `check_sku_consistency.php` = 0 rows to backfill.

---

#### R2. `edited_since_print` reset — ✅ DOMKNIĘTE 2026-07-30

**Problem:** Flaga `sh_orders.edited_since_print` ustawiana na `1` przy edycji (Faza E) ale nigdy resetowana na `0`. KDS pokazuje banner "ZAMÓWIENIE EDYTOWANE" permanentnie.

**Fix (2026-07-30) — podejście auto-reset przy `ready` (prostsze niż przycisk):**
- `api/kds/engine.php#bump_order` (linia 225-227): przejście do `ready` → `edited_since_print=0, kitchen_delta=NULL`.
- `core/KdsTicketEngine.php#bump` (linia 103-107): last ticket done → order `ready` → ten sam reset.
- `api/pos/engine.php#print_kitchen` (linia 1328, już committed): reset `edited_since_print=0` przy ponownym druku ticketu.

**Logika:** gdy kuchnia kończy (bump do `ready`), flaga edycji i `kitchen_delta` są czyszczone — banner "ZAMÓWIENIE EDYTOWANE" znika, bo kuchnia potwierdziła zakończeniem. Nie wymaga przycisku w UI — zero dodatkowego click-path dla kucharza.

**Dodatkowy fix:** `modules/courses/js/courses_ui.js:281` — typo w eksporcie `formatGrosche` → `formatGrosze` (nazwa funkcji to `formatGrosze`).

---

### 🟡 ŚREDNI PRIORYTET

#### R3. Edycja modyfikatorów w UI (Faza E — rozszerzenie)

**Problem:** Formularz `modules/backoffice/order_edit/` edytuje tylko `quantity` + dodawanie/usuwanie linii. Edycja modyfikatorów (`added_modifier_skus`, `removed_ingredient_skus`) istniejących linii nie jest w UI. DeltaEngine wykryje zmiany jeśli frontend je wyśle, ale UI tego nie expose'uje.

**Wpływ:** Manager nie może edytować modyfikatorów (np. "bez cebuli", "dodaj ser") przez panel — musi usunąć linię i dodać nową.

**Rekomendacja:** Rozszerzyć `order_edit_app.js` o modal modyfikatorów per linia (reuse z POS cart — `get_item_details` zwraca modifier groups). Opcjonalnie — niski priorytet bo obecny zakres spełnia kontrakt Fazy E (dodaj/usuń/zmień qty).

**Pliki:** `modules/backoffice/order_edit/js/order_edit_app.js`, `css/order_edit.css`.

---

#### R4. Kalibracja progów statusu marży (Food Cost Report)

**Problem:** `FoodCostEngine::resolveStatus` ma hardcoded progi: excellent ≤25%, healthy ≤33%, at_risk ≤40%, critical >40%. W demo danych (Faza D smoke test) wszystkie kanały PIZZA_CALZONE pokazały `critical` (54-58% marży) — progi mogą być zbyt agresywne dla pizzerii.

**Wpływ:** Wizualnie wszystkie dania mogą pokazywać "critical" nawet przy zdrowej marży 50%+ (typowa dla gastronomii).

**Rekomendacja:** Przenieść progi do `sh_tenant_settings` (per-tenant, jak SLA thresholds w Fazie A). Klucze: `foodcost_excellent_pct`, `foodcost_healthy_pct`, `foodcost_at_risk_pct`. SSOT w `core/FoodCostEngine.php`, fallback na hardcoded.

**Pliki:** `core/FoodCostEngine.php`, `api/reports/food_cost.php`, `modules/backoffice/food_cost/js/food_cost_app.js` (opcjonalnie — pokazanie progów w UI).

---

#### R5. Bulk Food Cost Report (agregacja całego menu)

**Problem:** Obecnie Food Cost Report jest per-item (jedno danie na raz). Brak widoku agregacji (całe menu z rankingiem marży).

**Wpływ:** Manager musi klikać każde danie osobno żeby znaleźć niskomarżowe.

**Rekomendacja:** Dodać widok "Wszystkie dania" w `modules/backoffice/food_cost/` — tabela z kolumnami: SKU, nazwa, koszt, cena (główny kanał), food cost %, marża %, status. Sortowanie po marży rosnąco. Backend: nowa akcja `bulk` w `food_cost.php` (iteruje wszystkie itemy z recepturą) albo frontend loop (wolniejsze ale prostsze).

**Pliki:** `api/reports/food_cost.php` (opcjonalnie akcja `bulk`), `modules/backoffice/food_cost/js/food_cost_app.js`.

---

### 🟢 NISKI PRIORYTET

#### R6. `estimate.php` — scheduled-picker UI (ostatni @planned)

**Problem:** `api/orders/estimate.php` to ostatni `@planned`. Wrapper HTTP na `PromisedTimeEngine::calculate()`. Brak konsumenta UI.

**Wpływ:** Storefront nie pokazuje estimated `promised_time` przed złożeniem zamówienia (pre-check). Silnik działa w 4 ścieżkach ASAP (Faza B), ale scheduled orders (online "Zaplanuj na później") nie mają pre-check.

**Rekomendacja:** Gdy pojawi się storefront scheduled-picker, wpiąć `estimate.php` jako pre-check (POST `{order_type, channel, items, scheduled_for}` → estimated `promised_time`). Do wtedy zachować jako `@planned` (świadomy orphan).

**Pliki:** `api/orders/estimate.php` (już istnieje), future storefront UI.

---

#### R7. Brak receptury — UX w Food Cost Report

**Problem:** `FoodCostEngine` rzuca `RuntimeException` gdy danie nie ma receptury w `sh_recipes` (HTTP 404). UI pokazuje czerwony banner błędu.

**Wpływ:** Manager klika danie bez receptury → czerwony błąd zamiast czytelnego komunikatu.

**Rekomendacja:** W `food_cost_app.js` przechwycić HTTP 404 i pokazać przyjazny komunikat "Brak receptury — dodaj w Menu Studio" z linkiem. Backend bez zmian (404 jest poprawny).

**Pliki:** `modules/backoffice/food_cost/js/food_cost_app.js` (tylko handler błędu).

---

#### R8. Kitchen delta — czyszczenie po czasie

**Problem:** `kitchen_delta` JSON pozostaje w `sh_orders` permanentnie. Po wielu edycjach może rosnąć (kolejne delta overwrite'ują poprzednie — DeltaEngine zapisuje tylko ostatnią).

**Wpływ:** Brak — delta jest ostatni stan. Ale dla audytu (kto kiedy edytował) brak historii.

**Rekomendacja:** Opcjonalnie — logować historię edycji w `sh_order_audit` (już istnieje tabela). Niski priorytet — obecny `kitchen_delta` spełnia kontrakt KDS.

**Pliki:** `api/orders/edit.php` (insert do `sh_order_audit`), `core/DeltaEngine.php`.

---

## 4. Weryfikacja końcowa (stan po Fazach A→E)

- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core). Brak regresji.
- **Lint:** `php -l` na wszystkich dotkniętych backendach — "No syntax errors detected". `node -c` na frontendach — OK.
- **Smoke testy E2E:** Faza D (PIZZA_CALZONE: AVCO 12.71 zł, 7 składników, 4 modyfikatory, 3 kanały), Faza E (CQR/20260729/0002: dodano BURGER_BBQ, delta.added=1, kitchen_delta=533 bytes w DB, KDS get_board zwraca delta).
- **Prawo VIII (Domknięcie Kontraktu):** Wszystkie 5 orphans z audytu 2026-04-19 wpięte end-to-end z konsumentami frontend + testami E2E. `food_cost.php`, `edit.php`, `sla_monitor.php`, `PromisedTimeEngine`, SLA thresholds — wszystkie ✅ DOMKNIĘTE.
- **Prawo VI (Snajper):** Każda faza dotknęła tylko zakresu koniecznego — nowe moduły + docblocki + docs. Niezatwierdzone zmiany ChoiceQR/fiscal nietknięte (zero nakładania).
- **Prawo X (Audyt Sesji):** Notatki sesji w `_docs/sessions/` dla każdej fazy (Cel, Pliki dotknięte, Decyzje architektoniczne, Otwarte pytania).

---

## 5. Następne kroki (rekomendowana kolejność)

1. **R1** (seed_demo_all SKU fix) — odblokuje pełną testowalność Fazy E na demo danych
2. **R2** (edited_since_print reset) — UX KDS, szybkie
3. **R4** (progi marży per-tenant) — UX Food Cost Report, spójne z Fazą A (SLA thresholds)
4. **R3** (edycja modyfikatorów) — rozszerzenie Fazy E
5. **R5** (bulk food cost) — agregacja menu
6. **R6** (estimate.php UI) — gdy pojawi się storefront scheduled-picker
7. **R7, R8** — UX polish, niski priorytet
