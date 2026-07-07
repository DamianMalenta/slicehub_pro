# Sesja: SettlementEngine — Faza 1

**Data:** 2026-07-07  
**Branch:** `cursor/settlement-engine-phase1-d950`

## Cel

Domknięcie driftu warstwy rozliczeń: jeden silnik `core/SettlementEngine.php` jako SSOT dla zapisu `sh_order_payments` przy zamknięciu zamówienia (Faza 1: 1 metoda, 1 wiersz). POS `settle_and_close` i orphan `api/payments/settle.php` delegują do silnika. Druk paragonu w POS **po** sukcesie API.

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `core/SettlementEngine.php` | **NOWY** — logika settle, backfill prepaid, idempotencja |
| `api/pos/engine.php` | `settle_and_close` → `SettlementEngine::settleAndClose` |
| `api/payments/settle.php` | Cienki wrapper HTTP (fix: idempotencja `isPaid`, nie `paid`) |
| `modules/pos/js/pos_app.js` | Druk paragonu po sukcesie `settleAndClose` |
| `scripts/test_settlement_engine.php` | **NOWY** — smoke CLI (4 scenariusze) |
| `_docs/02_ARCHITEKTURA.md` | Status settle + SettlementEngine |
| `.cursorrules` | Usunięcie `settle.php` z listy `@planned` |

## Decyzje architektoniczne

1. **Opcja B (zatwierdzona):** mózg w `core/`, nie promote orphan `settle.php`.
2. **F1 kontrakt:** dokładnie 1 element w `payments[]`; `user_id` w wierszu = kasjer (JWT).
3. **Idempotencja:** `OrderStateMachine::isFullyPaid()` + status `completed`; nie `payment_status === 'paid'` (wartość nie istnieje w słowniku).
4. **Backfill:** nagłówek `cash`/`card`/`online_paid` bez wierszy ledger → 1 INSERT przed `fastComplete`.
5. **Eventy:** `order.completed` tylko przy realnym zamknięciu (skip gdy `idempotent`).
6. **Bez zmian F1:** modal POS UI, driver app, checkout, tables UI, migracje SQL.

## Otwarte pytania

- **F2:** split-tender UI + wiele wierszy `payments[]`.
- **F3:** stoły (`tables/split_payment`) przez ten sam silnik.
- **F4:** unify driver `collect_payment` z SettlementEngine (osobna semantyka `user_id` = kierowca).

## Test (E2E)

```bash
php -l core/SettlementEngine.php
php scripts/test_settlement_engine.php
# MariaDB + Apache: test_runner 62/62, KSeF CLI
```
