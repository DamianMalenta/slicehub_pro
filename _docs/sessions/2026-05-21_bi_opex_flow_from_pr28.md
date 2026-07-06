# Sesja AI — 2026-05-21 · BI P&L — OPEX wg kategorii + capital flow (z PR #28)

> **Wymaga:** [`2026-05-14_bi_engine.md`](2026-05-14_bi_engine.md) (część 1 — fundament `BiEngine`, COGS, T62).

## Cel
Uzupełnić dashboard BI na `main` o elementy z zamkniętego PR #28, bez utraty `stock_value_minor`, `date_from`/`date_to`, RBAC managera i logiki COGS (wszystkie WZ).

## Pliki dotknięte
- `core/BiEngine.php` — `opex_by_category`, `capital_flow`, `prime_cost_*`, `*_pct_bp`, rozbicie brutto/VAT.
- `modules/bi/index.html` — UI: Prime cost, %, waterfall, tabela OPEX.

## Decyzje architektoniczne
- OPEX per kategoria: ten sam filtr dat co suma OPEX (`COALESCE(processed_at, updated_at)`).
- Nie przywracano `MAX(id)` deduplikacji WZ z PR #28 — zostaje polityka `main` (wszystkie linie WZ).
- API bez zmian kontraktu (`date_from` / `date_to`).

## Otwarte pytania
- Czy w UI pokazać osobno `gross_revenue_minor` / `output_vat_minor` (obecnie tylko w silniku, nie na kartach).

## Test (E2E)

- `tests/test_runner.html` T62 — PASS po `seed_demo_all.php` (retry auth manager PIN z sesji `api_base_paths`).
- Ręcznie: dashboard BI — karty Prime cost, waterfall OPEX, capital flow widoczne dla owner/manager.
