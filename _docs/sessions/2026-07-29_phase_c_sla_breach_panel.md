# Sesja: Faza C — SLA breach panel w Dispatcher + cron

**Data:** 2026-07-29
**Powiązane:** `_docs/sessions/2026-07-29_promised_time_sla_audit_and_plan.md` (Faza C)
**Konstytucja:** Prawo VI (Snajper), Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)

---

## 1. Cel

Wpięcie SLA breach monitoring end-to-end: backend `get_sla_breaches` (już istniał)
otrzymuje konsumenta frontend. Dispatcher pokazuje panel z breachami (ostatnie 24h),
odświeżany co 30s. Cron `worker_sla_monitor.php` zapisuje breache do `sh_sla_breaches`
(już udokumentowane w `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md §6.7`).

Dotychczas: backend akcja `get_sla_breaches` istniała ale żaden frontend nie wywoływał.
Cron worker istniał i działał ale breache nie były widoczne w UI.

---

## 2. Pliki dotknięte (Snajper — tylko funkcje Fazy C)

### Frontend (nowy panel w Dispatcher)

| Plik | Funkcja | Zmiana |
|---|---|---|
| `modules/courses/js/courses_api.js` | `getSlaBreaches()` (nowa) | `POST action=get_sla_breaches` — zwraca listę breachy |
| `modules/courses/js/courses_app.js` | `state.slaBreaches`, `pollSlaBreaches()`, `SLA_BREACH_POLL_INTERVAL=30000` | Osobny timer co 30s (nie blokuje głównego polla 8s) |
| `modules/courses/js/courses_app.js` | `enterApp()` / `logout()` | start/stop `slaBreachTimer` |
| `modules/courses/js/courses_app.js` | `render()` | wywołuje `CoursesUI.renderSlaBreachesPanel(state.slaBreaches)` |
| `modules/courses/js/courses_ui.js` | `renderSlaBreachesPanel(breaches)` (nowa) | Pure render — lista z order_number, address, driver, breach_minutes, logged_at. Sort z backendu (breach_minutes DESC). Empty state gdy brak. |
| `modules/courses/index.html` | `<div id="sla-breaches-panel">` | Kontener w sidebar pod listą kierowców |
| `modules/courses/css/style.css` | `.sla-breaches-panel`, `.sla-breach-item`, `.sla-breach-header` | Style panelu (border-left color wg nasilenia: red ≥30, yellow ≥10, orange <10) |

### Dokumentacja (Prawo VIII — Domknięcie Kontraktu)

| Plik | Zmiana |
|---|---|
| `_docs/01_KONSTYTUCJA.md` | `api/orders/sla_monitor.php` 🟡 PARTIAL → ✅ **DOMKNIĘTE 2026-07-29 (Faza C)** |
| `_docs/02_ARCHITEKTURA.md` | j.w. — tabela Orders |

### Backend — bez zmian

`api/courses/engine.php#get_sla_breaches` (linia ~1652) już istniał i zwracał:
`order_id, breach_minutes, driver_id, course_id, logged_at, order_number,
customer_name, delivery_address, first_name, last_name` z JOIN po `sh_orders` i
`sh_users` (24h window, `tenant_id = :tid`). Bez zmian — Faza C to czysto frontend.

`scripts/worker_sla_monitor.php` — bez zmian. Iteruje po tenantach, UPSERT do
`sh_sla_breaches`. Cron instrukcja już w `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md §6.7`
(Linux crontab `* * * * * php scripts/worker_sla_monitor.php`).

---

## 3. Decyzje architektoniczne

1. **Osobny timer 30s** (nie w głównym pollu 8s) — `get_sla_breaches` robi JOIN po 3
   tabelach; breachy nie wymagają sub-10s świeżości. Główny poll (orders/drivers/courses)
   pozostaje 8s.
2. **Pure render** — `renderSlaBreachesPanel` nie mutuje stanu; `pollSlaBreaches`
   aktualizuje `state.slaBreaches` i wywołuje render. `render()` (główny) re-renderuje
   panel z cache — natychmiastowa aktualizacja przy switch tab bez czekania na 30s.
3. **Sort z backendu** — `ORDER BY breach_minutes DESC, logged_at DESC` (już w SQL).
   Frontend nie re-sortuje.
4. **Empty state** — gdy 0 breachy: zielony checkmark "Brak spóźnień (ostatnie 24h)".
5. **Color coding** — `border-left` wg nasilenia: red (≥30 min), yellow (≥10 min),
   orange (<10 min). Wizualna hierarchia priorytetów.
6. **XSS-safe** — `_esc()` helper (textContent) dla address/driver name/order_number.
7. **Snajper** — dotknięte tylko: 1 nowa funkcja w `courses_api.js`, 3 sekcje w
   `courses_app.js` (state, timers, render-call), 1 nowa funkcja w `courses_ui.js`,
   1 kontener w HTML, 1 blok CSS. Inne funkcje nietknięte.

---

## 4. Weryfikacja (Test E2E)

- **Lint:** `node -c` na 3 JS plikach → OK (bez błędów składni).
- **Smoke test E2E** (`scripts/_tmp_test_sla_breaches.cjs`):
  - Login waiter (tenant 1, PIN 1111) → OK
  - `get_sla_breaches` → 8 breachy, wszystkie pola obecne (order_id, breach_minutes,
    logged_at, order_number, delivery_address)
  - Sample: `D22`, breach_minutes=2273, address="ul. Winogrady 144/8, 61-626 Poznań"
- **Worker smoke test:** `php scripts/worker_sla_monitor.php` →
  `{"tenants":2,"orders":9,"breached":8,"upserted":8,"failed":0}` — cron worker
  zapisuje breache poprawnie.
- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core,
  Chrome `C:\Program Files\Google\Chrome\Application\chrome.exe`). Błędy 400/401/405
  w logach = oczekiwane negatywne testy walidacji. **Brak regresji.**

---

## 5. Otwarte pytania (dla kolejnej sesji)

1. **Faza B (PromisedTimeEngine)** — kolejna wg planu A→C→B→D→E. Prerekwizita dla
   pełnego efektu Fazy A+C: online ASAP zapisuje `null` → SLA monitoring ślepy dla
   kanału online (badge zawsze zielony, breach nigdy nie logowany dla online ASAP).
   Rekomendacja: TAK, najwyższy priorytet.
2. **Polling 30s vs SSE** — gdy Faza P5/P8 (WebSocket/SSE) będzie gotowa, breachy
   mogą być pushowane zamiast pollowane. Obecnie 30s poll jest wystarczające.
3. **Akcja z panelu** — obecnie panel jest read-only. Czy dodać akcje (np. "zawróć
   kierowcę" z poziomu breach item)? Obecnie recall jest w driver card — spójne.
4. **Filtr czasowy** — backend zwraca 24h. Czy dodać przełącznik 1h/8h/24h w UI?
   Niski priorytet — 24h wystarcza.
