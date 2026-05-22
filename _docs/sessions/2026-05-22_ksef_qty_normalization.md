# Sesja: KSeF — normalizacja ilości FA → base_unit magazynu

**Data:** 2026-05-22  
**Branch:** `cursor/ksef-inbox-p3-d8f0` (P3 po merge PR #32)

---

## Cel

Naprawić błąd przyjmowania faktur (np. `BAZYLIA CIĘTA 20G`, 1 SZT. → magazyn w kg): poprawna ilość i AVCO w `sys_items.base_unit`, podgląd przed akceptacją. **P3:** domknięcie follow-up z HANDOFF (mapping per NIP, reparse, learn, KOR z liniami, UI pack, poll stats).

---

## Pliki dotknięte (P1 + P3)

| Plik | Zmiana |
|------|--------|
| `core/Units.php`, `PackSizeExtractor.php`, `InvoiceLineQtyNormalizer.php` | Normalizacja qty |
| `core/Ksef/InboxQtyNormalize.php`, `InboxImport.php`, `Parser.php`, `Client.php` | Import, warstwy, KOR z liniami → warn/draft |
| `core/AutoScanEngine.php` | `learnMapping` + `match` z `supplier_nip` |
| `core/Ksef/InboxInvoiceRepository.php` | AutoScan z NIP dostawcy |
| `database/migrations/058_*.sql`, `059_product_mapping_unique_supplier.sql` | Cache linii + UNIQUE mapping |
| `api/procurement/inbox.php` | accept (+ P_7A w SELECT), reparse (ochrona MANUAL), `set_line_pack`, learn przy `update_line` |
| `api/procurement/ksef_config.php` | `poll_now` → `quality_error` w stats |
| `modules/procurement/js/procurement_app.js` | Pack UI, poll alert |
| `scripts/test_*.php` | CLI regresja |

---

## Decyzje architektoniczne

1. **Autorytatywne przeliczenie w `accept`** — cache w DB to podgląd; PZ z `InvoiceLineQtyNormalizer`.
2. **Warstwy walidacji (Parser):** transport (`Client`) → struktura (`parse`) → sumy (`enrichParsedTotals`) → gate zakupów (`assessProcurementQuality`) → SKU/PZ (`accept`).
3. **Puste FA / KOR bez linii** → `status=error`; **KOR z FaWiersz + kwoty** → `draft` + `level=warn` (Reverse/KOR PZ w UI).
4. **Migracja 059:** `UNIQUE (tenant_id, supplier_nip, external_name)` — learn pack i alias per dostawca.
5. **reparse:** nie dotyka linii z `resolved_by_user_id` (ręczne SKU).
6. **update_line / bulk INVENTORY:** `AutoScanEngine::learnMapping` z NIP dostawcy.

---

## Otwarte pytania

- Retroaktywna korekta już zaakceptowanych PZ — poza zakresem.
- **reparse** nadal nadpisuje AutoScan na liniach bez ręcznego `resolved_by_user_id` (zamierzone).

---

## Test (E2E)

```bash
php scripts/apply_migrations_chain.php   # 058 + 059
php scripts/test_invoice_qty_normalizer.php
php scripts/test_ksef_parser_quality.php   # + KOR z liniami → warn
```

**Test (E2E) manualny:** BAZYLIA + P_7A 20G → accept → +0,02 kg; `set_line_pack` w modalu; poll_now pokazuje `quality_error`.
