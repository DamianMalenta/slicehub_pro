# HANDOFF — KSeF Inbox (normalizacja qty + puste faktury)

**Data handoff:** 2026-05-22  
**Branch:** `cursor/ksef-qty-normalization-b255`  
**PR (draft):** https://github.com/DamianMalenta/slicehub_pro/pull/32  
**Base:** `main`

---

## Cel sesji w nowym czacie

Domknąć PR #32: UI procurement (błędy importu, reassess, Audyt XML), deploy migracji 058, test E2E, opcjonalnie migracja 059 (mapping per NIP).

---

## Co już jest zrobione (nie przerabiać od zera)

### 1. Normalizacja ilości FA → magazyn (`base_unit`)

- `core/Units.php`, `PackSizeExtractor.php` (P_7 + **P_7A**), `InvoiceLineQtyNormalizer.php`, `Ksef/InboxQtyNormalize.php`
- Migracja **`058_ksef_line_qty_normalization.sql`** (kolumny cache linii + `pack_*` na `sh_product_mapping`)
- `api/procurement/inbox.php`: `accept`, `preview_normalize`, refresh po upload/reparse/update
- `modules/procurement/js/procurement_app.js`: podgląd „→ magazyn: …”
- CLI: `php scripts/test_invoice_qty_normalizer.php` — **10/10 OK**

### 2. Puste / dziwne faktury z KSeF

- `core/Ksef/InboxImport.php` — jedna ścieżka importu (worker, `poll_now`, upload)
- `Parser::assessProcurementQuality()` → `status=error` zamiast pustego `draft`
- `Parser::enrichParsedTotals()` — sumy z `FaWiersz` gdy P_13/P_15 puste
- `Client::validateInvoiceXmlBody()` — odrzucenie JSON/HTML bez `<Faktura>`
- API `reassess_invoice` w `inbox.php`
- Lista inbox: **domyślnie** `AND status <> 'error'` (filtr `status=error` pokazuje błędy)
- Worker: stat `invoices_quality_error`
- CLI: `php scripts/test_ksef_parser_quality.php` — **7/7 OK**

### 3. Warstwy walidacji (bez duplikacji)

| Warstwa | Gdzie |
|---------|--------|
| Transport HTTP | `Client::validateInvoiceXmlBody` |
| Struktura FA | `Parser::parse()` |
| Normalizacja sum | `enrichParsedTotals()` wewnątrz `parse()` |
| Nabywca (upload) | `parseAndVerifyBuyer()` |
| Zakupy draft/error | `assessProcurementQuality()` |
| SKU/PZ | `inbox.php` `accept` |

- `upload_xml`: **jeden** parse (`parseAndVerifyBuyer` → `preParsed` do `InboxImport`), bez drugiego rescan.

### Commity istotne

```
db90285 fix(ksef): warstwy walidacji Parser + jeden parse na import
e13a2cc fix(ksef): P_7A w gramaturze + audyt xml_blob vs baza (200G)
85c8f20 feat(procurement): normalizacja ilości KSeF do base_unit przy accept PZ
```

---

## Co DOKOŃCZYĆ (priorytet)

### P0 — UI + commit lokalny

**Niezacommitowane:** `modules/procurement/js/procurement_app.js` — handler przycisku **„Audyt XML”** (`#pi-modal-audit-xml` → `sync_lines_from_xml` dry_run/sync). Przycisk jest w `index.html`, na origin **brak** listenera.

Dodać w procurement:

1. **Pigułka filtra** `data-status="error"` + `#pi-stat-error` (licznik ze `stats.error`)
2. **Baner** w modalu: `status_message` gdy `inv.status === 'error'`
3. **Przycisk** „Ponów ocenę” → `api('reassess_invoice', { invoice_id })` + przeładowanie modalu
4. **Wyłączyć Accept** w JS przy `status === 'error'` (API już blokuje)
5. Commit + push brancha, zaktualizować PR #32

### P1 — Deploy / test

```bash
php scripts/apply_migrations_chain.php   # wymaga 058
php scripts/test_invoice_qty_normalizer.php
php scripts/test_ksef_parser_quality.php
```

Manual E2E (Apache + MariaDB):

- Upload/poll FA: linia `BAZYLIA CIĘTA` + P_7A `200G`, 1 SZT, SKU w **kg** → accept → PZ **+0,02 kg**, AVCO ~1333 PLN/kg
- Browser: `http://localhost/slicehub/tests/test_runner.html` — 62 testy

### P2 — Dane historyczne

Stare wpisy `draft` z `total_gross_minor=0` w bazie — import ich nie naprawi.

- Per faktura: `POST inbox.php` `action=reassess_invoice` (wymaga `xml_blob`)
- Lub odrzucić/usunąć ręcznie

### P3 — Opcjonalnie (follow-up)

| Temat | Opis |
|-------|------|
| **Migracja 059** | `UNIQUE (tenant_id, supplier_nip, external_name)` na `sh_product_mapping` — dziś UNIQUE tylko po `external_name`; `loadPackMapping` preferuje NIP w SELECT, ale learn może się gryźć między dostawcami |
| **reparse** | Nadal nadpisuje ręczne SKU, czyści `resolved_by_user_id` — znany drift |
| **learn przy update_line** | Tylko `smart_create_sku` uczy mapping; sam wybór SKU nie zapisuje aliasu |
| **KOR z liniami** | Wszystkie `KOR`/`ROZ` → `error`; ewentualny osobny flow korekt magazynowych |
| **UI korekty pack** | Ręczne `pack_qty_base` w modalu bez re-upload |
| **poll_now stats** | Pokazać w UI liczbę „błąd jakości” jak w workerze |
| **Dokumentacja** | Uzupełnić `_docs/sessions/2026-05-22_ksef_qty_normalization.md` o puste faktury + Parser layers |

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
modules/procurement/index.html
database/migrations/058_ksef_line_qty_normalization.sql
_docs/proposals/ksef_invoice_qty_normalization.md
```

---

## API — szybka ściąga

**Auth:** `POST /slicehub/api/auth/login.php` system → Bearer token.

**Inbox:** `POST /slicehub/api/procurement/inbox.php`

| action | Uwagi |
|--------|--------|
| `list` | Bez `status` → ukrywa `error`; `status=error` → tylko błędy |
| `upload_xml` | `parseAndVerifyBuyer` + `InboxImport`; może zwrócić `IMPORT_QUALITY_ERROR` |
| `reassess_invoice` | `{ invoice_id }` — przebudowa z `xml_blob` |
| `sync_lines_from_xml` | `{ invoice_id, dry_run: true\|false }` — audyt P_7/P_7A |
| `accept` | Blokuje `status=error`; używa `inboxBuildPzLine` + normalizacja |

**Poll KSeF:** `POST .../api/procurement/ksef_config.php` `action=poll_now` lub `php scripts/worker_ksef_inbox.php [tenant_id]`

---

## Znane ograniczenia (nie traktować jako bug bez decyzji)

- Retroaktywna korekta już zaakceptowanych PZ — **poza zakresem**
- `accept` nie zmienia progów AutoScan
- Puste faktury z poll Issue+Invoicing metadata — część to KOR/noty; teraz lądują w `error`, nie w „Nowe”

---

## Prompt do wklejenia w nowym czacie

```
Kontynuuj pracę na branchu cursor/ksef-qty-normalization-b255 (PR #32).

Przeczytaj: _docs/sessions/HANDOFF_2026-05-22_ksef_inbox_continue.md

Zadania P0:
1. Zacommituj handler Audyt XML w procurement_app.js (jest lokalnie niezacommitowany)
2. UI: filtr status=error, licznik, baner status_message, przycisk reassess_invoice, wyłącz Accept dla error
3. Push + update PR

Potem P1: migracja 058 + test E2E bazylia 20G/200G.

Konstytucja v5: tenant_id, silosy SKU, zero npm na produkcji.
```

---

## Git

```bash
git checkout cursor/ksef-qty-normalization-b255
git pull origin cursor/ksef-qty-normalization-b255
git status   # sprawdź procurement_app.js
```

Cloud Agent: branch prefix `cursor/`, suffix `-7fca` tylko dla **nowych** branchy; ten branch już istnieje bez suffixu.
