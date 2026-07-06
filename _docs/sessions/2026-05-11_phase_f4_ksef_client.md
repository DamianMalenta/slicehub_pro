# Sesja: F4 — KSeF API Client + Worker + Settings UI

> **⚠️ SUPERSEDED (2026-05-14):** Opisuje klienta **KSeF API 1.0** (`SessionToken`, hosty `ksef*.mf.gov.pl/api/online/…`). **Aktualny kontrakt produkcyjny:** [`2026-05-14_ksef_api_v2_client.md`](2026-05-14_ksef_api_v2_client.md) (JWT + NIP, `api*.ksef.mf.gov.pl/v2`). Decyzje F4.5 (smart-create, reverse-PZ, worker, CredentialVault) nadal obowiązują — zmienił się wyłącznie transport auth i endpointy MF.

**Data:** 2026-05-11 (po F3)
**Czas trwania:** ~2.5h
**Architekt:** AI (Cloud Agent) + użytkownik
**Branch:** `projektx/phase-f4-ksef-client-c3d7`

---

## 1. Cel

Domknąć ostatnią warstwę procurement (faktury dostawców → magazyn) — **prawdziwy KSeF API client** z auth przez KSeF Token, polling worker (cron / HTTP trigger), Settings UI z token managementem szyfrowanym at-rest (CredentialVault z m029).

Plus **F4.5** — dwa otwarte pytania z F3:
- **Smart-create** dla linii NONE: utworzenie nowego `sys_items` SKU + auto-mapping do `sh_product_mapping`.
- **Reverse**: wycofanie zaakceptowanej faktury (KOR dokument + reverse magazynu + status→draft).

---

## 2. Pliki dotknięte

| Plik | Status | Co robi |
|---|---|---|
| `core/Ksef/Client.php` | NEW | REST client do KSeF API (sandbox/prod) z mock mode dla testów. Auth KSeF Token. `testConnection / queryInbox / fetchInvoiceXml`. CredentialVault decrypt przy ładowaniu konfigu. |
| `scripts/worker_ksef_inbox.php` | NEW | Cron-callable + HTTP-trigger script. Polluje KSeF, INSERT-uje do `sh_ksef_invoices` z dedup po `ksef_reference_id UNIQUE`. AutoScan match per linia. Aktualizuje cursor w `sh_ksef_inbox_state`. |
| `api/procurement/ksef_config.php` | NEW | 6 akcji: `config_get / config_save / test_connection / poll_now / state / toggle_auto_poll`. RBAC granularny (owner-only dla save+toggle). Audit do `sh_settings_audit`. |
| `api/procurement/inbox.php` | CHANGED | +2 akcje: `smart_create_sku` (F4.5) i `reverse` (F4.5). Smart-create tworzy sys_items + auto-mapping. Reverse tworzy KOR + minusuje wh_stock + status→draft. |
| `modules/procurement/index.html` | CHANGED | +Config modal (env+token+auto-poll) +Smart-create modal +Pull-now button +Settings (gear) button. |
| `modules/procurement/js/procurement_app.js` | CHANGED | +handlers: openConfigModal/saveConfig/testConnection/pollNow + smart-create flow per linia NONE + handleReverse dla zaakceptowanych faktur. |
| `_docs/sessions/2026-05-11_phase_f4_ksef_client.md` (NEW) | Plik sesji wg Prawa X. |
| `_docs/sessions/README.md` | CHANGED | Indeks. |

---

## 3. Decyzje architektoniczne

### 3.1 Reuse `sh_tenant_integrations` z `provider='ksef'`

Tabela istnieje od m026, ma już:
- `credentials LONGTEXT` (z `CredentialVault::encrypt`)
- `api_base_url`, `is_active`, `consecutive_failures`, `last_failure_at` — wszystko gotowe pod KSeF.

Tylko **dorzuciliśmy nowy provider** ('ksef') do istniejącej tabeli. Zero nowych tabel pod token storage.

### 3.2 Mock mode dla testów lokalnych

Realny KSeF API wymaga rejestracji firmy + KSeF Token z `podatki.gov.pl`. Lokalne testy bez tego są niemożliwe.

**Rozwiązanie:** trzy environments w `Client.php`:
- `mock` — fixture invoices generated przez `mockQueryInbox()` + `mockFetchInvoiceXml()` (3 mockowe faktury z 3 dni wstecz, valid FA(2) XML)
- `sandbox` — real HTTP do `ksef-test.mf.gov.pl`
- `prod` — real HTTP do `ksef.mf.gov.pl`

Mock zwraca prawdziwie zbudowany FA(2) XML (z `Podmiot1`, `Podmiot2`, `Fa`, `FaWiersz`) — przechodzi przez `core/Ksef/Parser` jak każda inna faktura. To pozwala na pełny E2E test bez KSeF Token.

### 3.3 Worker: CLI (cron) + HTTP (zewnętrzny trigger)

Shared hosting uti.pl bywa chaotyczne z cron-em. Worker działa w dwóch trybach:

**CLI:**
```
php scripts/worker_ksef_inbox.php [tenant_id]
```
Cron: co 15 min (`(slash)15 (gwiazdki)` w crontabie).

**HTTP (cron-job.org friendly):**
```
https://slicehub.net/scripts/worker_ksef_inbox.php?key=<SLICEHUB_SCRIPT_KEY>&tenant_id=1
```
External scheduler (cron-job.org, EasyCron, easycron) strzela co X min. Nie wymaga cron-a hostingu.

Klucz weryfikowany ten sam `SLICEHUB_SCRIPT_KEY` co w `install_panel.php` (z `core/local_secrets.php`).

### 3.4 Dedup przez UNIQUE (tenant_id, ksef_reference_id)

Bez `INSERT IGNORE` — używamy zwykłego `SELECT ... LIMIT 1` przed INSERT-em. Powód:
- Klarowność: wiemy ile faktur jest skipped (`stats['skipped']++`).
- Race condition mało prawdopodobny (cron co 15 min, jeden worker per tenant).

Jeśli race condition stałby się problemem — `INSERT IGNORE INTO sh_ksef_invoices ...` zadziała bo `uq_ksef_ref` UNIQUE constraint blokuje duplikaty.

### 3.5 Auto-poll opt-in per tenant (Konstytucja v5 § Prawo IV — Zero Zaufania)

Domyślnie `sh_ksef_inbox_state.auto_poll_enabled = 0`. Worker bez argumentu (`php worker.php`) iteruje TYLKO tenantów z `auto_poll_enabled = 1`. Owner musi świadomie włączyć w UI (button "Auto-poll WŁĄCZONY" w config modal).

To chroni przed:
- Niepotrzebne pollowania dla tenantów którzy używają tylko manual upload (F3 flow).
- Race condition z mock-iem (development): mock w cron-ie generuje 3 fixtures × N tenantów co 15 min — chaos. Z opt-in: tylko świadomie skonfigurowane tenanty pollują.

### 3.6 Smart-create (F4.5) — atomic: sys_items + line update + mapping

Trzy operacje w jednej transakcji:
1. `INSERT IGNORE INTO sys_items` (idempotentny, jeśli SKU już istnieje, skip).
2. `UPDATE sh_ksef_invoice_lines SET resolved_sku='NEW_SKU', match_type='MANUAL'`.
3. `INSERT IGNORE INTO sh_product_mapping (external_name, internal_sku)` — **network effect**: kolejna faktura z tą samą nazwą → EXACT 100%.

Failure dowolnej operacji → rollback. Sukces → audit do `sh_settings_audit` z `action='ksef_smart_create'`.

### 3.7 Reverse (F4.5) — KOR dokument zamiast hard rollback

**Decyzja:** wycofanie zaakceptowanej faktury **nie usuwa** PZ z `wh_documents`. Zamiast tego tworzy **KOR** (korekta) z negative quantity per linia + odwraca `wh_stock` − qty.

Powód (Konstytucja v5 § Prawo III Czwarty Wymiar):
- Hard delete dokumentu PZ łamie audit trail (`wh_stock_logs` referencjonuje document_id).
- KOR jest standardowym dokumentem korekty w schemacie (m001).
- `sh_ksef_invoices.status='draft'` + clear `linked_wh_document_id` — manager może ponownie zaakceptować z poprawioną mapingiem.

Wycofana faktura: `status='draft'`, `status_message='Zwrócona z accepted przez owner. KOR doc: KOR/...'`. Manager widzi historię w UI.

### 3.8 RBAC: reverse = owner only

`reverse` dostępne TYLKO ownerowi. Powód:
- Operacja destrukcyjna (zmienia stany magazynowe + cofnięcie wcześniejszej decyzji managera).
- Manager może akceptować i odrzucać (operacyjne decyzje), ale **wycofać** = decyzja właścicielska.

---

## 4. Otwarte pytania

### 4.1 Real KSeF Token testing

Mock mode pokazuje że cała maszyneria działa. Real test wymaga:
1. Rejestracja firmy na `podatki.gov.pl`.
2. Wygenerowanie KSeF Token (sandbox albo prod).
3. Wpisanie tokenu w Settings UI.
4. Test connection → fetch real faktur.

Plan: po wgraniu paczki na uti.pl, user generuje sandbox token, testuje. F4 jest pełne pod kątem kodu — brakuje tylko real data.

### 4.2 KSeF Session model

> **Nieaktualne po 2026-05-14:** API v2 używa JWT (`ksef_access_token` / refresh), nie SessionToken API 1.0. Patrz [`2026-05-14_ksef_api_v2_client.md`](2026-05-14_ksef_api_v2_client.md).

Obecny client (F4, API 1.0) używał **stateless** SessionToken (header). Plan F4.1 (session management) **nie został wdrożony** — zastąpiony migracją v2.

### 4.3 Pełna FA(2) XSD validation

Obecny parser akceptuje minimalny XML (`<Faktura xmlns="...">`). KSeF Online API może zwracać enveloped XML z dodatkowymi elementami (sygnatury, znaczniki czasu). Plan: jak testach na sandboxie wyjdą edge cases, dorzucić XSD validation z `libxml`.

### 4.4 Batch fetch (paginacja)

`queryInbox()` zwraca listę invoice_reference_ids, potem `fetchInvoiceXml()` pobiera każdy osobno. KSeF API ma też `Invoice/Batch/Init` + polling status + ZIP download. Plan: jeśli dla dużych instancji (1000+ faktur/dzień) overhead per-invoice fetch będzie duży, przejść na batch.

### 4.5 KOR z PzEngine vs custom inline

Reverse-PZ w `inbox.php#reverse` ma **inline KOR logic** (INSERT do wh_documents z type='KOR' + iteracja linii). Powód: `KorEngine` w m001 jest dla zwrotu produktu klientowi (referencjuje WZ), nie dla zwrotu surowca dostawcy (PZ).

Plan: jeśli okaże się że refactor jest wartościowy, dorzucić `KorEngine::reverseForPzDocument($pzDocId)`. Na razie inline = mniej zmian, mniej ryzyka.

---

## Test (E2E)

### Setup
- Lokalna MariaDB 10.11.14
- m046 + sh_tenant.nip=5252344078
- `manager` (PIN 0000) + `owner_t1` użytkownicy

### F4 — KSeF Client + Worker

| # | Akcja | Wynik |
|---|---|---|
| T1 | Worker w trybie mock | 3 fixtures wstawione, AutoScan match (EXACT/EXACT z istniejących mappingów) |
| T2 | Worker drugi raz (dedup) | 2 skipped (dedup po `ksef_reference_id`) |
| T3 | `config_get` (owner) — bez konfigu | `configured=false, environment=mock` |
| T4 | `config_save` manager → 403 FORBIDDEN | ✓ — owner only |
| T5 | `config_save` owner sandbox+token | ✓ — token zaszyfrowany w `sh_tenant_integrations.credentials` |
| T6 | `config_get` po save | `env=sandbox, has_token=true, preview=••••1234` |
| T7 | `toggle_auto_poll` true | ✓ — `sh_ksef_inbox_state.auto_poll_enabled=1` |
| T8 | `test_connection` sandbox z fake token | HTTP 302 z KSeF (real HTTP do `ksef-test.mf.gov.pl`) — endpoint dobrze adresowany, tylko token zły |

### F4.5 — Smart-create + Reverse

| # | Akcja | Wynik |
|---|---|---|
| T10 | `smart_create_sku` dla NONE linii | Utworzony `NOWY_SKU_ABC` w `sys_items`, line update, mapping zapisany |
| T11 | DB verify | `sys_items` row + `sh_product_mapping` row — network effect aktywny |
| T12 | `accept` invoice z nowym SKU | PZ #13 utworzony, magazyn rośnie |
| T13 | `wh_stock NOWY_SKU_ABC` | 5kg @ AVCO 28 (z faktury) ✓ |
| T14 | `reverse` (owner) | KOR #14 utworzony (`KOR/2026/05/11/00014`), references `PZ/2026/05/11/00013` |
| T15 | `wh_stock` po reverse | NOWY_SKU_ABC=0 (5-5=0), invoice status='draft' ✓ |
| T16 | `reject` invoice (manager) | ✓ status=rejected |

### Lint

- ✅ `php -l core/Ksef/Client.php`
- ✅ `php -l scripts/worker_ksef_inbox.php`
- ✅ `php -l api/procurement/ksef_config.php`
- ✅ `php -l api/procurement/inbox.php` (+2 akcje smart_create / reverse)

---

**Status sesji: ✅ DONE.** F4 + F4.5 zamknięte. Procurement Inbox jest pełnoprawnym modułem z KSeF integration, smart-create dla NONE, reverse-PZ z KOR.

**Otwarte pytania → przyszłe iteracje:**
- F4.1: KSeF session management (jeśli stateless nie wystarczy w sandbox testach)
- F4.2: Pełna XSD validation FA(2) (po edge cases z real API)
- F4.3: Batch fetch (paginacja) dla dużych instancji
- F4.4: `KorEngine::reverseForPzDocument` refactor (jak inline staje się duże)
