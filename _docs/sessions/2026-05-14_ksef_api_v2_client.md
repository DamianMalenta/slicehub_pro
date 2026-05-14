# Sesja 2026-05-14 — KSeF: migracja klienta na API v2 (MF)

> **Status wdrożenia:** scalone do `main` (merge gałęzi `projektx/ksef-client-api-v2-3f1a`).

## Poprawka (walidacja MF 21405)

`POST /invoices/query/metadata` z `subjectType: Subject2`: pole **`buyerIdentifier` nie może być ustawione** — nabywca wynika z kontekstu JWT po uwierzytelnieniu. Wcześniejsze wysłanie NIP w body powodowało HTTP 400 (`buyer` must be null). W `Client::queryInbox` usunięto `buyerIdentifier` z payloadu.

## Historia iteracji metadata (skondensowane)

- **21405:** przy `Subject2` brak `buyerIdentifier` w body (nabywca z JWT).
- **`pageOffset`:** w API MF to **indeks strony** (0,1,2…), nie offset wiersza; `to` w UTC.
- **Zakres / paginacja:** do ~90 dni wstecz, `sortOrder: Desc`, `pageSize` = **250** (max OpenAPI), `hasMore` w pętli.
- **Rate limits MF:** przez jakiś czas był tylko przebieg `Invoicing` (mniej POST-ów); **przywrócono `Issue` ∪ `Invoicing`** — portal i MF bywają niespójne, samo Invoicing pomijało nowe faktury widoczne użytkownikowi. `from`/`to` w metadata w **UTC `…Z`** (wcześniej `from` bez strefy vs `to` w UTC). Worker / poll: margines **14 dni** wstecz od `last_polled_at` (zamiast 1 dnia).

## Inbox F3 — duplikat przy ręcznym XML (`upload_xml`)

- `api/procurement/inbox.php`: przed `INSERT` wykrywanie istniejącego wiersza po **tenant_id + numer faktury + NIP dostawcy (cyfry)**. Odpowiedź **409** `DUPLICATE_INVOICE` z `data` (m.in. `can_replace`). Drugi request z **`duplicate_resolution: replace`** usuwa stary wiersz (`DELETE` + CASCADE linii) i wstawia nowy — **zablokowane** dla `status=accepted` lub gdy jest `linked_wh_document_id` (PZ).
- `modules/procurement/js/procurement_app.js`: `confirm` — zastąp vs anuluj, ponowne `upload_xml` z `replace`.

## Cel

Zastąpienie martwego kontraktu API 1.0 (`ksef*.mf.gov.pl/api/online/…`, nagłówek `SessionToken`) oficjalnym **KSeF API v2** (`api*.ksef.mf.gov.pl/v2`), tak aby ten sam `core/Ksef/Client.php` obsługiwał sandbox i produkcję z tokenem z portalu oraz **kontekstem NIP** tenanta, bez osobnego modułu równoległego.

## Remediacja audytu (2026-05-14)

- **`is_active`:** `Client` blokuje sandbox/prod przy `is_active=0`; worker pomija tenantów; `poll_now` zwraca `KSEF_INACTIVE`.
- **HTTP 429:** `httpRequest` — do 4 prób, oczekiwanie wg `Retry-After` lub backoff.
- **Race na UNIQUE:** `InboxInvoiceRepository::isMysqlDuplicateKey` — worker i `poll_now` liczą duplikat jako skip.
- **DRY:** `core/Ksef/InboxInvoiceRepository.php` — wspólny INSERT + `matchInvoiceLines`; upload XML przez repozytorium + `inboxRescanLines` (statystyki).
- **Docs/UI:** `_docs/02_ARCHITEKTURA.md`, `modules/procurement/index.html` (hosty API v2).

## Skrót zmian (co poszło do `main`)

| Obszar | Zmiana |
|--------|--------|
| `core/Ksef/Client.php` | Hosty v2 (test/prod), pełny flow: `challenge` → RSA-OAEP SHA-256 (`openssl pkeyutl`) → `/auth/ksef-token` (kontekst NIP) → poll `/auth/{ref}` → `/auth/token/redeem` i `/auth/token/refresh`; lista: `POST /invoices/query/metadata`; XML: `GET /invoices/ksef/{ksefNumber}`; w `credentials` zapisywane `ksef_refresh_token`, `ksef_access_token`, `ksef_access_valid_until`. |
| `api/procurement/ksef_config.php` | `config_save` scala istniejący JSON — przy tym samym tokenie portalu i `environment` nie kasuje pól `ksef_*`. Komentarz: test połączenia = API v2 (JWT). |
| Test połączenia | Po uwierzytelnieniu sonda **`GET /rate-limits`** (nie `/sessions` — uniknięcie wymogu szerszych uprawnień). |
| NIP | Odczyt z `sh_tenant.nip` (to samo pole co **Backoffice → Profil firmy**); komunikaty błędów wskazują ten moduł zamiast surowej nazwy kolumny. |
| Settings / KSeF | Szczegóły: `_docs/sessions/2026-05-14_settings_ksef_integration_isolation.md` — karta KSeF, modal, blokada zapisu POS na `provider=ksef`. |

## Commity (historia gałęzi)

1. `feat(ksef): switch Client to official API v2 (JWT + NIP context)` — rdzeń v2 + sesja + `ksef_config` merge.
2. `fix(ksef): probe live connection with GET /rate-limits` — sonda testu.
3. `docs(ksef): point NIP errors to Backoffice Profil firmy` — komunikaty UX.
4. (wcześniej na tej samej gałęzi) Settings: KSeF widoczny + szablon bez Papu — patrz drugi plik sesji.

## Pliki dotknięte (szczegółowo)

- `core/Ksef/Client.php` — pełny przepływ uwierzytelniania v2 (challenge, RSA-OAEP SHA-256 przez `openssl pkeyutl`, `/auth/ksef-token`, poll, redeem, refresh), zapytania `POST /invoices/query/metadata`, pobranie XML `GET /invoices/ksef/{ksefNumber}`, zapis tokenów JWT w credentials, **is_active**, **retry 429**.
- `core/Ksef/InboxInvoiceRepository.php` — wspólny INSERT faktury KSeF + pierwszy przebieg AutoScan na liniach.
- `api/procurement/ksef_config.php` — `config_save` scala z istniejącym JSON (nie kasuje `ksef_*` przy ponownym zapisie tego samego tokenu i środowiska); `poll_now` — `KSEF_INACTIVE`, duplikat SQL → skip.
- `api/procurement/inbox.php` — upload przez repozytorium.
- `scripts/worker_ksef_inbox.php` — `is_active`, race duplicate, repozytorium.

## Decyzje architektoniczne

- **Jeden klient, te same metody publiczne** (`testConnection`, `queryInbox`, `fetchInvoiceXml`) — worker i UI bez zmian kontraktu; `ref_id` w liście to **numer KSeF** (`ksefNumber`), zgodny z deduplikacją w `sh_ksef_invoices`.
- **RSA-OAEP SHA-256** realizowane przez binarkę **OpenSSL 3** (`proc_open` + `pkeyutl`), bo `openssl_public_encrypt()` w PHP 8.3 nie pozwala wymusić OAEP MGF1-SHA256 wymaganego przez MF.
- **NIP** brany z `sh_tenant.nip` (bariera `tenant_id`); źródło danych = Backoffice Profil firmy; brak NIP = czytelny błąd konfiguracji zamiast „502” po stronie aplikacji.
- **Tryb mock** bez zmian (fixtures lokalne).

## Test (E2E) przy wdrożeniu

- `find … -name "*.php" … | xargs php -l` — brak błędów składni.
- Ręcznie: stary prod `/api/online/…` zwraca HTML zamknięcia API 1.0; v2 `POST …/v2/auth/challenge` zwraca 200.

## Optymalizacja 2026-05-14 — mniej POST-ów `/invoices/query/metadata`

- **Problem:** Dwa pełne przebiegi (`Issue` + `Invoicing`) × wiele stron = dużo żądań na jeden poll; łatwo o grupę limitów MF (np. komunikat o przekroczeniu zapytań na godzinę).
- **Zmiana:** Jeden przebieg z **`dateType: Invoicing`** + **`pageSize` = 250** (maks. wg OpenAPI). Uzasadnienie: dla nabywcy (Subject2) krytyczna jest data przyjęcia do KSeF; worker i tak podaje wąskie `sinceDate` (od `last_polled_at`), więc zwykle wystarczy 1–kilka stron.
- **Koszt:** Teoretyczny brak pozycji widocznych tylko po filtrowaniu po dacie wystawienia w tym samym oknie — rzadki przypadek wobec inboxu zakupowego.

## Otwarte pytania

- Przy ekstremalnym wolumenie (>25k faktur w 90 dni) nadal można rozważyć dodatkowy stan/cursor w `sh_ksef_inbox_state` lub batch export po stronie MF.
- Hostingi bez `proc_open` lub bez OpenSSL CLI — wymagałoby innego kanału kryptograficznego (np. zewnętrzny helper); na typowym VPS z OpenSSL 3 ścieżka jest stabilna.
- **Wysyłka faktur (outbound)** — osobny flow OpenAPI (`/sessions/online/...`); nieobjęty tym klientem; plan w osobnej sesji / backlogu.
