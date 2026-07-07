# Sesja: SettlementEngine — Faza 2 + F3

**Data:** 2026-07-07  
**Branch:** `cursor/settlement-engine-phase1-d950` (kontynuacja PR #36)

## Cel

- **F2:** split-tender w POS (gotówka + karta) + silnik wielowierszowy
- **F3:** `tables/split_payment` → `SettlementEngine::applyPartialPayments`

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `core/SettlementEngine.php` | Multi-payment close, `applyPartialPayments`, `deriveHeaderFromPayments` |
| `api/pos/engine.php` | `settle_and_close` akceptuje `payments[]` |
| `api/payments/settle.php` | Wiele wpisów w `payments[]` |
| `api/tables/engine.php` | `split_payment` → silnik |
| `modules/pos/js/pos_ui.js` | UI „Podziel płatność” |
| `modules/pos/js/pos_api.js` | `settleAndClose` z tablicą lub stringiem |
| `modules/pos/js/pos_app.js` | Obsługa split w callbacku |
| `modules/pos/css/style.css` | Style split modal |
| `scripts/test_settlement_engine.php` | T4–T6 (split, partial, empty) |

## Decyzje architektoniczne

1. Split close: suma musi = `grand_total - już_opłacone`; nagłówek `payment_method = mixed` gdy >1 metoda.
2. Dominujący `payment_status` z najwyższej kwoty (jak stoły / `completeDineIn`).
3. Partial payments (stoły): bez auto-complete; `split_type = custom`; overpayment guard w silniku.
4. **F4** (driver `collect_payment`) — nadal otwarte.

## Test (E2E)

```bash
php scripts/test_settlement_engine.php  # 6/6 PASS
```

## Otwarte pytania

- **F4:** unify driver `collect_payment` (`user_id` = kierowca)
