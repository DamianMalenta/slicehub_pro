# Sesja: F3 — Procurement Inbox UI + FA(2) Parser + KSeF Inbox Schema

**Data:** 2026-05-11 (po sesji F2.5 tego samego dnia)
**Czas trwania:** ~3h
**Architekt:** AI (Cloud Agent) z udziałem właściciela
**Branch:** `projektx/phase-f3-procurement-inbox-c3d7`

---

## 1. Cel

Domknąć fazę „faktury dostawców → magazyn" przez pełny flow UI dla manual upload XML (F3 MVP). F4 (KSeF API client + worker) ma używać tych samych tabel + endpointów, tylko zamiast `upload_xml` z UI będzie `worker_ksef_inbox.php` pollujące API i `INSERT INTO sh_ksef_invoices`.

User-flow F3:
1. Manager wpada w „Inbox KSeF" w Hub-ie.
2. Przeciąga FA(2) XML (`.xml`) do drop-zone.
3. Parser wyciąga supplier+invoice+lines z FA(2), zapisuje raw XML + parsed JSON do `sh_ksef_invoices`.
4. AutoScan match-uje każdą linię (`EXACT/ALIAS/NAME/FUZZY/NONE`) z confidence — `4/5 auto-accept` widoczne w toaście.
5. Manager klika fakturę → modal z liniami + match pills + SKU select per linia.
6. Manual override unresolved (5 minut na fakturę zamiast 30).
7. Klik „Akceptuj → PZ" → `PzEngine::processReceipt` utworzy `wh_documents` (typ PZ) + `auto-learn` ALIAS mappings → magazyn rośnie.

---

## 2. Pliki dotknięte

| Plik | Status | Co robi |
|---|---|---|
| `database/migrations/046_ksef_inbox.sql` | NEW | 3 tabele: `sh_ksef_invoices`, `sh_ksef_invoice_lines`, `sh_ksef_inbox_state`. Idempotent (`IF NOT EXISTS`). |
| `scripts/_migrations_chain.php` | CHANGED | Dopisane 046 na końcu chain. |
| `core/Ksef/Parser.php` | NEW | Lekki parser FA(2): namespace-aware SimpleXML, extract Podmiot1/Podmiot2/Fa/FaWiersz/totals, normalize daty, parseDecimal z PL formatem (`,` → `.`), validate strukturę. ~280 linii. |
| `api/procurement/inbox.php` | NEW | 6 akcji: `list / show / upload_xml / reparse / update_line / accept / reject`. RBAC granularny per akcja. Audit do `sh_settings_audit`. ~360 linii. |
| `modules/procurement/index.html` | NEW | Dark glass UI: topbar + hint box + drop-zone + stats pills filtr + invoices list + detail modal. |
| `modules/procurement/css/procurement.css` | NEW | Pełen styling (~340 linii) — drop-zone z drag-over, modal z table linii, match pills (EXACT=zielony / ALIAS=ciemnozielony / NAME=żółty / FUZZY=pomarańczowy / NONE=czerwony / MANUAL=niebieski). |
| `modules/procurement/js/procurement_app.js` | NEW | Vanilla JS (~330 linii): drag&drop multi-file, list+stats, modal z table linii + SKU select per linia + auto-fill, accept/reject/rescan actions. |
| `modules/hub/index.html` | CHANGED | Nowy kafelek „Inbox KSeF" (fa-inbox icon, `data-roles="owner,admin,manager"`) w sekcji Administracja, obok Profil Firmy. |
| `_docs/sessions/2026-05-11_phase_f3_procurement_inbox.md` (NEW) | Ten plik. |
| `_docs/sessions/README.md` | CHANGED | Indeks +F3. |

---

## 3. Decyzje architektoniczne

### 3.1 Manual upload first (F3), KSeF API później (F4)

**Powód:** sandbox KSeF wymaga rejestracji firmy + KSeF Token + integracja z bilingiem. To 2-3 tygodnie kalendarzowo. Lepiej zbudować **pełny UX i flow** najpierw z manual upload, zweryfikować że parser + AutoScan + PzEngine grają razem, a F4 (worker + API client) podpina się 1:1 do tych samych tabel.

Po F4 nic w UI nie musi się zmieniać — `upload_xml` zostaje (dla edge cases: dostawcy spoza KSeF, faktury z e-maila/PDF→XML), worker dopisuje do tych samych `sh_ksef_invoices`.

### 3.2 Schema: raw XML + parsed JSON + structured columns

**Trzy reprezentacje** tej samej faktury w `sh_ksef_invoices`:

| Pole | Typ | Cel |
|---|---|---|
| `xml_blob` | LONGTEXT | Compliance — KSeF wymaga 5-letnią archiwizację oryginalnych XML. |
| `parsed_json` | JSON | Quick re-render w UI bez ponownego parsowania (debug, history view). |
| Structured columns (supplier_nip, invoice_number, total_*_minor, ...) | typed | Filtrowanie, indeksy, JOIN-y, raporting. |

Trzy reprezentacje zajmują ~3-5KB per faktura — akceptowalne (10k faktur/rok = 30-50 MB). Korzyść: nigdy nie tracimy oryginału.

### 3.3 Quoty pieniężne — INT minor (grosze)

`total_net_minor / total_vat_minor / total_gross_minor / line_net_minor` — wszystko `BIGINT` w groszach (Konstytucja v5 § Konwencje, jak orders/payments). Zero floating-point drift, łatwe sumowanie.

W UI: `formatPLN(minor)` dzieli przez 100 dla display.

### 3.4 RBAC granularny per akcja (Konstytucja v5 § Prawo VI Snajper)

| Akcja | Allowed |
|---|---|
| `list` / `show` | owner / admin / manager |
| `upload_xml` | owner / admin / manager (każdy może wrzucić — wymaga tylko żeby buyer NIP się zgadzał) |
| `reparse` / `update_line` / `reject` | owner / manager (operacyjna decyzja przyjęcia dostawy) |
| `accept` | owner / manager (PZ tworzy się — manager z zmiany albo owner) |

Brak `admin` w decyzjach operacyjnych — `admin` to konto techniczne (sieć / integrator), nie operuje magazynem.

### 3.5 Walidacja buyer NIP przed save

W `upload_xml`: czytamy `sh_tenant.nip` (z m045 legal profile), porównujemy z `Podmiot2.NIP` w XML. Jeśli nie pasuje — `WRONG_BUYER_NIP` 400. Powód: chroni przed pomyłkowym wrzuceniem faktury wystawionej na inną firmę (np. inny tenant w tym samym SaaS).

Jeśli `sh_tenant.nip` jest puste (NULL), walidacja jest skip-owana (defensive — tenant nie podał NIP-u to nie wiemy z czym porównywać).

### 3.6 PzEngine reused via core/PzEngine::processReceipt

**Alternatywa rozważona:** duplikat logiki PZ inline w `inbox.php` (osobny INSERT do wh_documents).

**Wybrane:** Wywołanie PzEngine z F2.5. Powód:
- Single source of truth dla AVCO compute.
- ALIAS auto-learn z F2.5 działa automatycznie — manager pierwszy raz mapping ręcznie, drugi raz EXACT.
- Wszelkie przyszłe rozszerzenia PzEngine (waste% przy przyjęciu, multi-warehouse split) propagują się natychmiast.

PzEngine PHASE 1 mapping jest jednak omijane bo wszystkie linie mają już `resolved_sku` z inbox UI. PzEngine traktuje to jak backward-compat call z explicit SKU.

### 3.7 Match pills + threshold per tenant z F2

`procurement_app.js` czyta threshold z `show` response (zwracane przez backend z `sh_tenant_settings.autoscan_auto_accept_threshold`, default 70). Per linia: jeśli `confidence >= threshold` → zielona "auto" indykacja pod match pill.

Owner może obniżyć threshold do 50 w Settings (gdy zaufa AutoScan-owi) albo podnieść do 90 (gdy chce surową kontrolę). Bez kodu zmiany — to jest setting.

### 3.8 Drop-zone multi-file

User może drag&drop wiele XML naraz (test: 3 faktury jednocześnie). Każda parsowana sekwencyjnie, summary z liczbą sukces/błąd w toaście.

`.xml` extension + `application/xml` MIME jako filtr. Inne pliki pomijane z komunikatem.

---

## 4. Otwarte pytania

### 4.1 F4 — KSeF API client (worker + cron polling)

Następna sesja. Wymaga:
- KSeF Token z `podatki.gov.pl` (sandbox + prod).
- `core/Ksef/Client.php` (REST do `ksef.mf.gov.pl` / `ksef-test.mf.gov.pl`).
- `scripts/worker_ksef_inbox.php` — cron co 5-15 min: query → fetch XML → INSERT do `sh_ksef_invoices` → match przez AutoScan.
- Settings UI: zarządzanie tokenem (CredentialVault z m029 — szyfrowanie at-rest).

Plan: F4 nie zmieni UI — tylko backend. `worker_ksef_inbox.php` wstawia te same rekordy do tych samych tabel.

### 4.2 Pełne FA(2) XSD validation

Obecny parser jest **uproszczony** — czyta najczęstsze pola. Pełna walidacja XSD wymaga `ext-xmlreader` + downloaded schema XSD z MF + walidacja przed parsowaniem. Plan: F4.5 albo gdy realny KSeF API zacznie zwracać corner-case XML-e.

Dla F3 MVP: parser akceptuje wszystko co jest `<Faktura xmlns="...">` z `Podmiot1/Podmiot2/Fa/FaWiersz`. Niepełne pola = warning, nie error.

### 4.3 Smart-create dla NONE

Obecnie linie NONE wymagają manual SKU selection (z dropdownu sys_items). Plan: rozszerzenie UI o przycisk „Utwórz nowy SKU" obok select-a → modal z auto-generowanym SKU + GTU + PKWiU z faktury.

### 4.4 Bulk accept dla multiple invoices

UI accept-uje fakturę po fakturze. Dla wielu faktur od tego samego dostawcy z tymi samymi pozycjami — można dodać "Accept all" przycisk. Plan F5 albo gdy real-world wymusi.

### 4.5 Reverse: cancel PZ → reverse faktury

Jeśli manager pomyłkowo zaakceptował fakturę i powstał PZ, ale potem chce cofnąć — obecnie wymaga manual KOR przez Magazyn moduł. Plan F5 albo F6: button „Wycofaj" obok zaakceptowanej faktury → KorEngine + invoice status='draft' z powrotem.

### 4.6 Tailwind classes vs własny CSS

`procurement_app.js` nie używa Tailwind — operuje na klasach `pi-*` z `procurement.css`. To **lepsze** podejście niż w `warehouse_pz.js` (gdzie Tailwind CDN), bo zero runtime overhead na inline klasy. Plan przyszłej sesji: refactor `warehouse_pz.js` na `wh-*` własne klasy CSS (też zgodne z Prawem § Stos).

---

## Test (E2E)

### Setup
- Lokalna MariaDB 10.11.14
- Schema z m045 + m046 (świeżo apply-ed)
- `sh_tenant.id=1` NIP=`5252344078`
- `sh_product_mapping` z 9 wpisami (z seed + F2.5 auto-learn)
- `sh_users.manager` (PIN 0000, password "password")

### Testowy FA(2) XML

5 linii:
1. Mąka pszenna Caputo "00" (EXACT z mapping)
2. Mozzarella Fior di Latte 1kg (EXACT)
3. Passata pomidorowa S.Marzano 2.5L (EXACT)
4. Pieczarki polskie 2kg (EXACT z F2.5 auto-learn)
5. Smartfon iPhone (NONE — żadne dopasowanie)

Totals: net=3991.00 PLN, VAT=831.17 PLN, gross=4822.17 PLN

### Scenariusze

| # | Akcja | Oczekiwane | Wynik |
|---|---|---|---|
| T1 | `list` przed upload | empty | count=0 ✓ |
| T2 | `upload_xml` — wgranie FA(2) | parsed: 5 linii, 4 EXACT, 1 NONE, totals matematycznie zgodne | ✓ invoice_id=1, EXACT=4, NONE=1, auto_accept=4/5 |
| T3 | `show` | invoice + 5 linii z match info | ✓ |
| T4 | `accept` bez fix-u smartfona | UNRESOLVED_LINES error | ✓ rejected z hint |
| T5 | `update_line` smartfon → OPAK_PIZZA (manual) | success | ✓ |
| T6 | wh_stock przed | snapshot bazowy | MKA=59.49, SER_MOZZ=19.2, SOS_POM=23.9, PIECZARKI=9, OPAK_PIZZA=248 |
| T7 | `accept` | PZ utworzony, status=accepted | ✓ PZ #12 PZ/2026/05/11/00012 z 5 liniami |
| T8 | wh_stock po | wszystkie SKU +qty z faktury | MKA=84.49 (+25), SER_MOZZ=29.2 (+10), SOS_POM=28.9 (+5), PIECZARKI=12 (+3), OPAK_PIZZA=249 (+1) — **wszystkie matematycznie zgodne** ✓ |
| T9 | DB sh_ksef_invoices | status=accepted, linked_wh_document_id=12, processed_at=now | ✓ |

### Lint

- ✅ `php -l core/Ksef/Parser.php`
- ✅ `php -l api/procurement/inbox.php`
- ✅ `php -l scripts/_migrations_chain.php`
- SQL migracja 046 — uruchomiona idempotentnie

---

**Status sesji: ✅ DONE.** F3 zamknięte — manual upload FA(2) → AutoScan match → 1-click accept → PZ + magazyn rosną. Konstytucja v5 § Prawo II Bliźniak Cyfrowy + Prawo VI Snajper + Prawo VIII Domknięcie Kontraktu spełnione.

**Następna sesja:** F4 — KSeF API client (worker + cron + Settings UI). Wymaga KSeF Token z `podatki.gov.pl`.
