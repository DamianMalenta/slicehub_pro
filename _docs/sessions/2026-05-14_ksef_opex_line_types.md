# Sesja 2026-05-14 — KSeF Inbox: linie INVENTORY vs EXPENSE + słownik OPEX

## Cel

- Rozdzielić linie faktury KSeF: **INVENTORY** (jak dotychczas → PzEngine / PZ) oraz **EXPENSE** (tag + kategoria OPEX, bez magazynu i bez wpływu na AVCO).
- Dodać tabelę `sh_expense_categories`, API CRUD `api/procurement/expense_categories.php`, kolumnę `commission_amount` na `sh_orders` (fundament BI).
- UI: przełącznik typu linii, wybór kategorii OPEX, modal zarządzania kategoriami obok ustawień KSeF.

## Pliki dotknięte

- `database/migrations/057_ksef_line_opex_expense_categories.sql`
- `scripts/_migrations_chain.php`
- `api/procurement/expense_categories.php` (nowy)
- `api/procurement/inbox.php` (`show`, `update_line`, `accept`, `reparse` przez `inboxRescanLines`, `set_cost_category`, `smart_create_sku`)
- `modules/procurement/js/procurement_app.js`, `index.html`, `css/procurement.css`

## Decyzje architektoniczne

- **Bez zmian** w `core/PzEngine.php`, AVCO, WzEngine, HR — zgodnie z zakazem.
- Po migracji **057** `accept` rozgałęzia się na ścieżkę per-linia (`line_type`); nagłówkowe `cost_category` + `set_cost_category` pozostają dla instancji **bez** kolumn `line_type` (legacy); gdy kolumny są, `set_cost_category` zwraca `USE_LINE_OPEX`.
- Faktura 100% EXPENSE: `linked_wh_document_id = NULL`, audyt `ksef_accept_opex_only`; mieszana: PZ tylko z linii INVENTORY, nagłówek `cost_category = magazyn` (bo powstał PZ).

## Test (E2E)

- `php -l api/procurement/inbox.php` oraz `php -l api/procurement/expense_categories.php` — brak błędów składni.
- Migracja 057: uruchomić na MariaDB (`mysql … < database/migrations/057_ksef_line_opex_expense_categories.sql` lub łańcuch migracji), potem ręcznie: Procurement → OPEX → lista kategorii; faktura → przełącz linia na OPEX, wybór kategorii, Zapisz; Akceptuj (mieszana / sam OPEX).

## Otwarte pytania

- Agregacja P&L z `sh_ksef_invoice_lines` (EXPENSE) + `line_net_minor` / nagłówek — osobny moduł BI.
