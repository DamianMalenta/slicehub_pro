-- =============================================================================
-- SliceHub Pro — Migration 045: Tenant Legal Profile (Profil Firmy)
-- -----------------------------------------------------------------------------
-- Spec: _docs/13_SETTINGS_PANEL.md (przyszłe rozszerzenie) +
--       wizja Architekta z 2026-05-11.
--
-- Cel:
--   Wprowadza minimalny "profil prawny" tenanta — to czego brakowało po
--   migracji 001 (sh_tenant ma tylko id+name+created_at). Dane statutowe
--   (NIP, REGON, KRS, adres rejestrowy, IBAN, e-mail do faktur, fiskalka)
--   są edytowane PRZEZ OWNERA w nowym module `modules/backoffice/profile/`.
--
-- Architektura — separacja marketingowe vs legalne:
--   - Storefront (marketingowe, klient-facing): `Online Studio → Storefront`
--     siedzi w `sh_tenant_settings` z prefiksem `storefront_*`. BEZ ZMIAN.
--   - Legal (statutowe, do faktur / księgowości): TUTAJ.
--     • `nip` + `slug` ladują w kolumnach na `sh_tenant`, bo będą używane
--       w `WHERE`/`JOIN`/`UNIQUE` (NIP w PDF-ach faktur, slug w routing
--       multi-tenant).
--     • Reszta (REGON, KRS, IBAN, bank, e-mail faktur, fiskalka, oznaczenie
--       VAT czynny) idzie do `sh_tenant_settings` z prefiksem `legal_*` —
--       są to dane dokumentowe, rzadko filtrowane.
--
-- Roadmapa (M046 — multi-lokalizacja, NIE w tej migracji):
--   Przy wprowadzeniu `sh_locations` (jeden tenant = wiele adresów):
--     - LEGAL zostaje na `sh_tenant` (jedno NIP per organizacja).
--     - STOREFRONT z `sh_tenant_settings.storefront_*` przeniesie się do
--       `sh_locations.storefront_settings_json` (FK do sh_tenant.id).
--     - Endpointy `storefront_settings_get/save` dorzucą param `location_id`.
--   Tym kontraktem już teraz nie blokujemy się — legal jest osobno od
--   storefront, a slug w sh_tenant przygotowuje routing organizacja-poziom.
--
-- IDEMPOTENT. Bezpieczne wielokrotne uruchomienie.
-- MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

SET NAMES utf8mb4;

-- ── 1. sh_tenant — kolumny LEGAL używane w WHERE/JOIN ───────────────────────

-- NIP (Numer Identyfikacji Podatkowej) — 10 cyfr po normalizacji.
-- Format w bazie: dokładnie 10 cyfr (BEZ kresek; UI normalizuje przed save).
-- Walidacja checksumy odbywa się APP-SIDE (PHP + JS), nie w schemacie.
-- VARCHAR(15) bo niektórzy partnerzy / kraje używają dłuższych formatów,
-- ale sam SliceHub przyjmuje 10-cyfrowy PL NIP.
ALTER TABLE sh_tenant
    ADD COLUMN IF NOT EXISTS nip VARCHAR(15) NULL DEFAULT NULL
        COMMENT 'NIP 10 cyfr (PL), normalized — bez separatorów. Walidacja checksumy app-side.';

-- Slug — krótki, URL-bezpieczny identyfikator organizacji.
-- Cel: routing online (np. /pizzeria-mario), subdomena (mario.slicehub.net),
-- użycie w QR kodach POS / linkach klient-facing.
-- UNIQUE — 2 tenanty nie mogą mieć tego samego slug-a.
-- 64 znaki: a-z, 0-9, '-', '_', start z litery (walidacja app-side).
ALTER TABLE sh_tenant
    ADD COLUMN IF NOT EXISTS slug VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'URL-safe org id, np. "pizzeria-mario". Walidacja app-side.';

-- UNIQUE INDEX na slug — IF NOT EXISTS nie wspierane uniwersalnie dla indeksów,
-- więc używamy stored procedure-style guarda przez INFORMATION_SCHEMA.
SET @slugIdxExists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'sh_tenant'
      AND index_name   = 'uq_tenant_slug'
);
SET @sql := IF(@slugIdxExists = 0,
    'ALTER TABLE sh_tenant ADD UNIQUE INDEX uq_tenant_slug (slug)',
    'SELECT "uq_tenant_slug already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 2. Pre-rezerwacja prefiksu `legal_*` w sh_tenant_settings ───────────────
-- Sama tabela `sh_tenant_settings` istnieje od 001. Klucze poniżej zapisuje
-- backend `api/backoffice/profile/engine.php` przez setSetting() przy save.
-- Dla READ-MEowości — KV których backend używa:
--
--   legal_company_name      — pełna nazwa rejestrowa (≠ sh_tenant.name marketing)
--   legal_legal_form        — forma prawna: jdg, sp_zoo, sa, sk, sj, fundacja, stow, inne
--   legal_regon             — REGON 9 lub 14 cyfr (bez separatorów)
--   legal_krs               — KRS 10 cyfr (bez separatorów; tylko spółki KRS-owe)
--   legal_address_street    — ulica + numer rejestrowy (≠ adres lokalu w storefront)
--   legal_address_postal    — kod pocztowy XX-XXX
--   legal_address_city      — miasto rejestrowe
--   legal_address_country   — kod kraju ISO (default 'PL')
--   legal_invoice_email     — e-mail do faktur (≠ storefront_email klient-facing)
--   legal_bank_name         — nazwa banku
--   legal_bank_iban         — IBAN PL: PL + 26 cyfr (28 znaków total, normalized BEZ spacji)
--   legal_bank_swift        — BIC/SWIFT (opcjonalnie, dla zagranicznych przelewów)
--   legal_vat_payer         — '1' / '0' — czy podatnik VAT czynny
--   legal_fiscal_no         — numer fiskalny kasy (np. PL1234567890; opcjonalny)
--
-- (Brak ALTER bo nie ma kolumn do dodania — KV używa istniejącej kolumny
--  setting_value VARCHAR(255). Dla pól > 255 znaków: w przyszłości można
--  dodać setting_value_long TEXT, na razie żaden legal field nie jest dłuższy.)

SELECT 'm045 tenant_legal_profile applied: sh_tenant.nip + sh_tenant.slug + UNIQUE(slug)' AS migration_045;
