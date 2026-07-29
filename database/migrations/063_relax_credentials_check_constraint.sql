-- 063_relax_credentials_check_constraint.sql
-- Relaksacja CHECK constraint na sh_tenant_integrations.credentials,
-- żeby CredentialVault (Faza 7.6) mógł zapisać wartości w formacie
-- "vault:v1:<base64>" (XChaCha20-Poly1305 AEAD). Dotychczasowy constraint
-- wymagał json_valid(credentials), co blokowało rotację plaintext → vault
-- (UPDATE odrzucany z SQLSTATE 23000 / kod 4025).
--
-- Nowy constraint dopuszcza:
--   • NULL / pusty string        — brak credentials
--   • "vault:v1:<base64>"        — zaszyfrowane (CredentialVault::encrypt)
--   • poprawny JSON              — legacy plaintext (migracja stopniowa)
--
-- UWAGA: MariaDB 10.4 (XAMPP) nie wspiera `DROP CHECK IF EXISTS` ani
-- `ADD CONSTRAINT IF NOT EXISTS` dla CHECK. Oryginalny constraint był
-- INLINE (column-level: `credentials ... CHECK (json_valid(credentials))`)
-- — nie da się go usunąć przez DROP CONSTRAINT, tylko przez MODIFY COLUMN
-- bez klauzuli CHECK. Dlatego ta migracja używa MODIFY COLUMN.
--
-- Idempotentna: po MODIFY COLUMN stary inline CHECK znika; table-level
-- ADD CONSTRAINT o tej samej nazwie jest bezpieczne o ile wcześniej
-- nie istniał (jeśli re-run, ADD CONSTRAINT rzuci duplicate-name error,
-- który można zignorować — klauzula jest już na miejscu).

ALTER TABLE sh_tenant_integrations
  MODIFY COLUMN credentials LONGTEXT
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
    NULL
    COMMENT 'api_key, tokens, tenant_ext_id — zaszyfrowane (vault:v1: prefix = CredentialVault, plaintext JSON = legacy)';

ALTER TABLE sh_tenant_integrations
  ADD CONSTRAINT credentials
  CHECK (
    credentials IS NULL
    OR credentials = ''
    OR credentials LIKE 'vault:v1:%'
    OR json_valid(credentials)
  );

-- ── Migration marker ─────────────────────────────────────────────────────
SELECT 'm063 relax_credentials_check_constraint applied: sh_tenant_integrations.credentials accepts vault:v1:' AS migration_063;
