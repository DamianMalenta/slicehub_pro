# Sesja 2026-05-14 — KSeF: migracja klienta na API v2 (MF)

> **Status wdrożenia:** scalone do `main` (merge gałęzi `projektx/ksef-client-api-v2-3f1a`).

## Cel

Zastąpienie martwego kontraktu API 1.0 (`ksef*.mf.gov.pl/api/online/…`, nagłówek `SessionToken`) oficjalnym **KSeF API v2** (`api*.ksef.mf.gov.pl/v2`), tak aby ten sam `core/Ksef/Client.php` obsługiwał sandbox i produkcję z tokenem z portalu oraz **kontekstem NIP** tenanta, bez osobnego modułu równoległego.

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

- `core/Ksef/Client.php` — pełny przepływ uwierzytelniania v2 (challenge, RSA-OAEP SHA-256 przez `openssl pkeyutl`, `/auth/ksef-token`, poll, redeem, refresh), zapytania `POST /invoices/query/metadata`, pobranie XML `GET /invoices/ksef/{ksefNumber}`, zapis tokenów JWT w credentials.
- `api/procurement/ksef_config.php` — `config_save` scala z istniejącym JSON (nie kasuje `ksef_*` przy ponownym zapisie tego samego tokenu i środowiska).

## Decyzje architektoniczne

- **Jeden klient, te same metody publiczne** (`testConnection`, `queryInbox`, `fetchInvoiceXml`) — worker i UI bez zmian kontraktu; `ref_id` w liście to **numer KSeF** (`ksefNumber`), zgodny z deduplikacją w `sh_ksef_invoices`.
- **RSA-OAEP SHA-256** realizowane przez binarkę **OpenSSL 3** (`proc_open` + `pkeyutl`), bo `openssl_public_encrypt()` w PHP 8.3 nie pozwala wymusić OAEP MGF1-SHA256 wymaganego przez MF.
- **NIP** brany z `sh_tenant.nip` (bariera `tenant_id`); źródło danych = Backoffice Profil firmy; brak NIP = czytelny błąd konfiguracji zamiast „502” po stronie aplikacji.
- **Tryb mock** bez zmian (fixtures lokalne).

## Test (E2E) przy wdrożeniu

- `find … -name "*.php" … | xargs php -l` — brak błędów składni.
- Ręcznie: stary prod `/api/online/…` zwraca HTML zamknięcia API 1.0; v2 `POST …/v2/auth/challenge` zwraca 200.

## Otwarte pytania

- Paginacja `pageOffset` / `hasMore` w `queryInbox` — obecnie pierwsza strona (50 pozycji); przy bardzo dużym napływie faktur warto dodać cursor w `sh_ksef_inbox_state`.
- Hostingi bez `proc_open` lub bez OpenSSL CLI — wymagałoby innego kanału kryptograficznego (np. zewnętrzny helper); na typowym VPS z OpenSSL 3 ścieżka jest stabilna.
- Czy `Client` / worker mają respektować `is_active` na wierszu `ksef` (dziś `Client` tego nie czyta) — nadal otwarte (por. sesja Settings).
