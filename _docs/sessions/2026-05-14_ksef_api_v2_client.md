# Sesja 2026-05-14 — KSeF: migracja klienta na API v2 (MF)

## Cel

Zastąpienie martwego kontraktu API 1.0 (`ksef*.mf.gov.pl/api/online/…`, nagłówek `SessionToken`) oficjalnym **KSeF API v2** (`api*.ksef.mf.gov.pl/v2`), tak aby ten sam `core/Ksef/Client.php` obsługiwał sandbox i produkcję z tokenem z portalu oraz **kontekstem NIP** tenanta, bez osobnego modułu równoległego.

## Pliki dotknięte

- `core/Ksef/Client.php` — pełny przepływ uwierzytelniania v2 (challenge, RSA-OAEP SHA-256 przez `openssl pkeyutl`, `/auth/ksef-token`, poll, redeem, refresh), zapytania `POST /invoices/query/metadata`, pobranie XML `GET /invoices/ksef/{ksefNumber}`, zapis tokenów JWT w credentials.
- `api/procurement/ksef_config.php` — `config_save` scala z istniejącym JSON (nie kasuje `ksef_*` przy ponownym zapisie tego samego tokenu i środowiska).

## Decyzje architektoniczne

- **Jeden klient, te same metody publiczne** (`testConnection`, `queryInbox`, `fetchInvoiceXml`) — worker i UI bez zmian kontraktu; `ref_id` w liście to **numer KSeF** (`ksefNumber`), zgodny z deduplikacją w `sh_ksef_invoices`.
- **RSA-OAEP SHA-256** realizowane przez binarkę **OpenSSL 3** (`proc_open` + `pkeyutl`), bo `openssl_public_encrypt()` w PHP 8.3 nie pozwala wymusić OAEP MGF1-SHA256 wymaganego przez MF.
- **NIP** brany z `sh_tenant.nip` (bariera `tenant_id`); brak NIP = czytelny błąd konfiguracji zamiast „502” po stronie aplikacji.
- **Tryb mock** bez zmian (fixtures lokalne).

## Otwarte pytania

- Paginacja `pageOffset` / `hasMore` w `queryInbox` — obecnie pierwsza strona (50 pozycji); przy bardzo dużym napływie faktur warto dodać cursor w `sh_ksef_inbox_state`.
- Hostingi bez `proc_open` lub bez OpenSSL CLI — wymagałoby innego kanału kryptograficznego (np. zewnętrzny helper); na typowym VPS z OpenSSL 3 ścieżka jest stabilna.
