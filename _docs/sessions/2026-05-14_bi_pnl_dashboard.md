# Sesja AI — 2026-05-14 · Moduł BI P&L (dashboard_data + UI)

## Cel

Wdrożenie read-only dashboardu P&L (przychód, VAT, COGS z WZ, koszt pracy z księgi payroll, OPEX z linii KSeF EXPENSE) zgodnie z `_docs/audits/bi_foundation_audit.md`, z izolacją silosów (brak JOIN między `sh_*` a `wh_*`), rolami owner/admin, oraz testem integracyjnym w `tests/test_runner.html`.

## Pliki dotknięte

- `api/bi/dashboard_data.php` — nowy endpoint GET, `auth_guard`, cztery osobne zapytania + agregacja PHP.
- `modules/bi/index.html`, `modules/bi/js/bi_app.js` — UI (Tailwind CDN, glass, JWT `sh_token`, presety dat, wodospad %).
- `modules/hub/index.html` — link do BI (`data-roles="owner,admin"`).
- `tests/test_runner.html` — test `T62`, `CFG.biDashboard`.
- `AGENTS.md` — liczba testów 62.
- `_docs/sessions/2026-05-14_bi_pnl_dashboard.md` — niniejszy audyt sesji.

## Decyzje architektoniczne

- **Silosy:** Zapytania Frontline (`sh_orders` / `sh_order_lines`), Supply Chain (`wh_documents` / `wh_document_lines`), HR (`sh_payroll_ledger`), Procurement (`sh_ksef_*` + `sh_expense_categories`) są rozłączone; wyniki łączone wyłącznie w PHP.
- **Półotwarty przedział czasu:** `created_at` (oraz daty faktur) filtrowane jako `>= start 00:00:00` oraz `< (end_date + 1 dzień) 00:00:00`.
- **COGS:** `SUM(ROUND(line_net_value * 100))` — wartość DECIMAL dokumentu magazynowego na grosze INT zgodnie ze specyfikacją zadania.
- **Prime cost %:** \((\mathrm{COGS} + \mathrm{labor}) / \mathrm{gross\_revenue} \times 100\) (float, 2 miejsca); przy zerowym brutto — 0.
- **Prowizje:** suma `sh_orders.commission_amount` w tym samym filtrze co przychód (migracja 057).

## Otwarte pytania

- Czy raport COGS powinien w przyszłości filtrować WZ po dacie zamówienia zamiast `wh_documents.created_at` (deduplikacja wielu WZ na jedno zamówienie — por. audyt §2.5)?
- Czy labor powinien być przypisywany po `period_year`/`period_month` zamiast `created_at` wpisu ledgeru dla zgodności z zamknięciem okresu payroll?

## Test (E2E)

- `php -l api/bi/dashboard_data.php`
- `GET /slicehub/api/bi/dashboard_data.php?start_date=…&end_date=…` z nagłówkiem `Authorization: Bearer <token admin/owner>` — oczekiwane `200`, `success: true`, struktura `data.amounts_minor`, `prime_cost_pct`, `opex_by_category`.
- Test runner: `T62` (login system `admin` / `password`, potem GET na endpoint).
