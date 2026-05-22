# Sesja: KSeF — normalizacja ilości FA → base_unit magazynu

**Data:** 2026-05-22  
**Branch:** `cursor/ksef-qty-normalization-b255`

---

## Cel

Naprawić błąd przyjmowania faktur (np. `BAZYLIA CIĘTA 20G`, 1 SZT. → magazyn w kg): poprawna ilość i AVCO w `sys_items.base_unit`, podgląd przed akceptacją.

---

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `core/Units.php` | NEW |
| `core/PackSizeExtractor.php` | NEW |
| `core/InvoiceLineQtyNormalizer.php` | NEW |
| `core/Ksef/InboxQtyNormalize.php` | NEW |
| `database/migrations/058_ksef_line_qty_normalization.sql` | NEW |
| `scripts/_migrations_chain.php` | +058 |
| `scripts/test_invoice_qty_normalizer.php` | NEW |
| `api/procurement/inbox.php` | accept, preview_normalize, refresh cache |
| `scripts/worker_ksef_inbox.php` | refresh po imporcie |
| `modules/procurement/js/procurement_app.js` | Podgląd normalizacji |
| `modules/procurement/css/procurement.css` | Style podglądu |
| `_docs/proposals/ksef_invoice_qty_normalization.md` | Stan obecny + status wdrożenia |

---

## Decyzje architektoniczne

1. **Autorytatywne przeliczenie w `accept`** — cache w DB (`qty_normalized`) to podgląd; PZ zawsze z `InvoiceLineQtyNormalizer`.
2. **Warstwy:** mapping `pack_*` → konwersja jednostki FA → ekstrakcja z nazwy (warn).
3. **Learn:** po accept z `name_weight` / `name_multipack` zapis `pack_qty_base` w `sh_product_mapping`.
4. **AutoScan bez zmian** — tylko SKU; qty poza scoringiem.

---

## Otwarte pytania

- UI ręcznej korekty `pack_qty_base` bez ponownego uploadu (formularz w modalu) — opcjonalny follow-up.
- Retroaktywna korekta już zaakceptowanych PZ — poza zakresem (tylko nowe accept).

---

## Test (E2E)

- CLI: `php scripts/test_invoice_qty_normalizer.php` — 10/10 OK.
- Manual: migracja 058 + inbox accept linia E1 (wymaga środowiska z Apache/MariaDB).
