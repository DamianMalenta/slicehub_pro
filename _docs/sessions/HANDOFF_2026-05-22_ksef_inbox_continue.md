# HANDOFF — KSeF Inbox (normalizacja qty + puste faktury)

> **Status: DOMKNIĘTE (2026-05-22).** Kanon audytu sesji → [`2026-05-22_ksef_qty_normalization.md`](2026-05-22_ksef_qty_normalization.md).  
> Ten plik zostaje jako **skrót operacyjny** (API ściąga, lista plików). Nie używaj go jako jedynego źródła prawdy.

**Data handoff:** 2026-05-22  
**Gałęzie robocze:** `cursor/ksef-qty-normalization-b255` (PR #32, P0–P2), `cursor/ksef-inbox-p3-d8f0` (P3)  
**Base:** `main`

---

## Szybki start (po merge)

```bash
php scripts/apply_migrations_chain.php   # 058 + 059
php scripts/test_invoice_qty_normalizer.php
php scripts/test_ksef_parser_quality.php
```

Manual E2E: linia `BAZYLIA CIĘTA` + P_7A `200G`, 1 SZT, SKU w **kg** → accept → PZ **+0,02 kg**.  
Test runner: `tests/test_runner.html` — 62/62 po `seed_demo_all.php`.

---

## Co zostało wdrożone (skrót)

Szczegóły decyzji i plików → [`2026-05-22_ksef_qty_normalization.md`](2026-05-22_ksef_qty_normalization.md).

| Obszar | Status |
|--------|--------|
| Normalizacja qty FA → `base_unit` (m058) | ✅ |
| Mapping per NIP (m059) | ✅ |
| Puste FA / KOR bez linii → `error`; KOR z liniami → `draft`+warn | ✅ |
| `reparse` chroni linie z `resolved_by_user_id` | ✅ |
| UI pack (`set_line_pack`), poll `quality_error` | ✅ |

### Warstwy walidacji

| Warstwa | Gdzie |
|---------|--------|
| Transport HTTP | `Client::validateInvoiceXmlBody` |
| Struktura FA | `Parser::parse()` |
| Normalizacja sum | `enrichParsedTotals()` wewnątrz `parse()` |
| Nabywca (upload) | `parseAndVerifyBuyer()` |
| Zakupy draft/error | `assessProcurementQuality()` |
| SKU/PZ | `inbox.php` `accept` |

---

## Pliki kluczowe

```
core/Ksef/Parser.php
core/Ksef/InboxImport.php
core/Ksef/InboxInvoiceRepository.php
core/Ksef/Client.php
core/Ksef/InboxQtyNormalize.php
core/InvoiceLineQtyNormalizer.php
api/procurement/inbox.php
api/procurement/ksef_config.php
scripts/worker_ksef_inbox.php
modules/procurement/js/procurement_app.js
database/migrations/058_ksef_line_qty_normalization.sql
database/migrations/059_product_mapping_unique_supplier.sql
_docs/proposals/ksef_invoice_qty_normalization.md
```

---

## API — szybka ściąga

**Auth:** `POST {api_base}/auth/login.php` system → Bearer token (`SliceHub.apiUrl()`).

**Inbox:** `POST {api_base}/procurement/inbox.php`

| action | Uwagi |
|--------|--------|
| `list` | Bez `status` → ukrywa `error`; `status=error` → tylko błędy |
| `upload_xml` | `parseAndVerifyBuyer` + `InboxImport`; może zwrócić `IMPORT_QUALITY_ERROR` |
| `reassess_invoice` | `{ invoice_id }` — przebudowa z `xml_blob` |
| `sync_lines_from_xml` | `{ invoice_id, dry_run: true\|false }` — audyt P_7/P_7A |
| `accept` | Blokuje `status=error`; `InvoiceLineQtyNormalizer` przy PZ |
| `set_line_pack` | Korekta pack w modalu (learn → mapping) |

**Poll KSeF:** `POST .../procurement/ksef_config.php` `action=poll_now` lub `php scripts/worker_ksef_inbox.php [tenant_id]`

---

## Znane ograniczenia

- Retroaktywna korekta już zaakceptowanych PZ — poza zakresem.
- `accept` nie zmienia progów AutoScan.
- Stare wpisy `draft` z `total_gross_minor=0` — `reassess_invoice` lub ręczne usunięcie.

---

## Dane historyczne (P2)

Stare wpisy `draft` z zerowymi sumami — import ich nie naprawi:

- Per faktura: `POST inbox.php` `action=reassess_invoice` (wymaga `xml_blob`)
- Lub odrzucić/usunąć ręcznie
