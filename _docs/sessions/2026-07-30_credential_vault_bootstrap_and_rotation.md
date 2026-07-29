# Sesja: CredentialVault bootstrap + rotacja plaintext → vault (XAMPP)

**Data:** 2026-07-30
**Powiązane:** `_docs/00_PAMIEC_SYSTEMU.md` (Faza 7.5/7.6), `_docs/13_SETTINGS_PANEL.md` (§3), `_docs/sessions/2026-07-28_quick_wins_credential_vault_panic_fix.md`
**Konstytucja:** Prawo X (Audyt Sesji)

---

## 1. Cel

Banner w panelu Settings:

> **1 plaintext credential w bazie** — Zaszyfruj przez: `php scripts/rotate_credentials_to_vault.php`

Komenda z bannera nie działała. Cel: zdiagnozować dlaczego, naprawić, zaszyfrować sekret, udokumentować.

---

## 2. Diagnoza — 3 nakładające się problemy

| # | Problem | Objaw |
|---|---|---|
| 1 | **`extension=sodium` wyłączone** w `C:\xampp\php\php.ini:958` (`;extension=sodium`) | `function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')` → `false`. DLL `php_sodium.dll` istniał w `ext/`, ale nie ładowany. |
| 2 | **Brak klucza vault** — nie istniał `config/vault_key.txt` ani env `SLICEHUB_VAULT_KEY` | `CredentialVault::isReady()` → `false` |
| 3 | **CHECK constraint `json_valid(credentials)`** na `sh_tenant_integrations.credentials` (column-level, inline w `026_event_system.sql:133`) | UPDATE z zaszyfrowaną wartością `vault:v1:...` odrzucany: `SQLSTATE[23000] CONSTRAINT sh_tenant_integrations.credentials failed` (kod 4025). Rotate `--live` sypał błędem, dry-run przechodził (bo nie robi UPDATE). |

Skutek: jedyna integracja (`tenant_id=1, provider=choiceqr`, credentials `{"token":"te...}`) została zapisana jako plaintext i nie dało się jej zaszyfrować.

---

## 3. Naprawa

### 3.1. Włączenie sodium w php.ini

```diff
- ;extension=sodium
+ extension=sodium
```
Plik: `C:\xampp\php\php.ini:958`. Weryfikacja: `php -m | findstr sodium` → `sodium`.

### 3.2. Bootstrap klucza vault

```bash
php scripts/bootstrap_vault.php
```
Wygenerował 32-byte klucz → `config/vault_key.txt`. Weryfikacja: `CredentialVault::isReady()` → `bool(true)`.

### 3.3. Migracja 063 — relaksacja CHECK constraint

**Plik:** `database/migrations/063_relax_credentials_check_constraint.sql`

Stary constraint (column-level, inline z `026_event_system.sql`):
```sql
credentials JSON NULL CHECK (json_valid(credentials))
```

Nowy constraint (table-level, relaksowany):
```sql
ALTER TABLE sh_tenant_integrations
  MODIFY COLUMN credentials LONGTEXT
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
    COMMENT 'api_key, tokens, tenant_ext_id — zaszyfrowane (vault:v1: prefix = CredentialVault, plaintext JSON = legacy)';

ALTER TABLE sh_tenant_integrations
  ADD CONSTRAINT credentials CHECK (
    credentials IS NULL
    OR credentials = ''
    OR credentials LIKE 'vault:v1:%'
    OR json_valid(credentials)
  );
```

**Uwaga MariaDB 10.4 (XAMPP):** `DROP CHECK IF EXISTS` / `ADD CONSTRAINT IF NOT EXISTS` **nie są wspierane**. Oryginalny constraint był inline (column-level) — nie da się go usunąć `DROP CONSTRAINT`, tylko `MODIFY COLUMN` bez klauzuli CHECK. Dlatego migracja używa `MODIFY COLUMN`. AGENTS.md mówił „MariaDB 10.11" — XAMPP ma faktycznie **10.4.32**.

Zarejestrowano w `scripts/_migrations_chain.php` (linia 77).

### 3.4. Rotacja

```bash
php scripts/rotate_credentials_to_vault.php --dry-run   # 1 rekord, 130 → 237 bajtów
php scripts/rotate_credentials_to_vault.php --live       # #1 choiceqr ✓ encrypted + stored
```

### 3.5. Restart Apache

CLI miał sodium od razu po edycji `php.ini`, ale Apache (`apache2handler`) dopiero po restarcie:
```powershell
Get-Process httpd | Stop-Process -Force
cd C:\xampp; Start-Process .\apache_start.bat -WindowStyle Hidden
```

### 3.6. .gitignore — zabezpieczenie klucza

`config/vault_key.txt` nie był w `.gitignore`. Dodano w sekcji „Środowisko / sekrety":
```
config/vault_key.txt
```
Weryfikacja: `git check-ignore -v config/vault_key.txt` → `.gitignore:22:config/vault_key.txt`.

---

## 4. Weryfikacja

| Sprawdzenie | Wynik |
|---|---|
| `CredentialVault::isReady()` (CLI) | `bool(true)` |
| `CredentialVault::isReady()` (Apache `apache2handler`) | `bool(true)` |
| `sh_tenant_integrations[1].credentials` | `vault:v1:9BR...` (237 bajtów) |
| Plaintext counter API (tenant 1) | `integrations=0, webhooks=0, total=0` → banner zniknie |
| Decrypt roundtrip | odszyfrowane = `{"token":"te...` (poprawny JSON, 130 bajtów) |
| Re-run `rotate --dry-run` | `skip: already vault:v1:` (idempotentne) |
| `php -l` na zmienionych plikach | brak błędów |

---

## 5. Pliki dotknięte

| Plik | Zmiana |
|---|---|
| `C:\xampp\php\php.ini` | odkomentowanie `extension=sodium` (linia 958) — **poza repo** |
| `config/vault_key.txt` | nowy, wygenerowany przez `bootstrap_vault.php` — **ignorowany przez .gitignore** |
| `.gitignore` | dodano `config/vault_key.txt` w sekcji sekretów |
| `database/migrations/063_relax_credentials_check_constraint.sql` | nowy — relaksacja CHECK constraint |
| `scripts/_migrations_chain.php` | dodano `063_relax_credentials_check_constraint.sql` do łańcucha |

---

## 6. Następstwa dla operatora

**Backup klucza vaultu POZA serwer** (jedyna kopia na tej maszynie):
```
66f74fa970cf87f579e727438f51eba7f3cd98ba6f3690a8f8a73125b2b6de96
```
Utrata = trwała utrata dostępu do wszystkich zaszyfrowanych credentials. Traktuj jak klucz do sejfu z dokumentami firmy.

---

## 7. Lekcje

1. **Banner podpowiada komendę, ale komenda może nie działać** — 3 nakładające się problemy (sodium off, brak klucza, CHECK constraint) wymagały sekwencyjnej diagnozy. Sam banner nie przewiduje constraintu w bazie.
2. **AGENTS.md mówił MariaDB 10.11, XAMPP ma 10.4.32** — składnia `DROP CHECK IF EXISTS` nie działa na 10.4. Migracja musi używać `MODIFY COLUMN`.
3. **`config/vault_key.txt` nie był w `.gitignore`** — potencjalny wyciek sekretu do repo. Dodano.
4. **Apache vs CLI** — po edycji `php.ini` CLI pickuje zmiany od razu, Apache dopiero po restarcie procesu.
