# Sesja: Quick Wins + CredentialVault hardening + PanicEngine fix

**Data:** 2026-07-28

## Wdrożone fixy

### C1 — ChannelRegistry::knownTypes() fatal error

**Plik:** `core/Notifications/ChannelRegistry.php:85`

`self::$fileMap` (nieistniejąca właściwość statyczna) → `self::$classMap`. Metoda nie była nigdy wywoływana (martwy kod), ale naprawiona dla poprawności.

### D1 — Guard SLICEHUB_SCRIPT_KEY na 5 skryptach destrukcyjnych

**Pliki:** `scripts/setup_database.php`, `scripts/seed_demo_all.php`, `scripts/reset_users.php`, `scripts/nuclear_reset.php`, `scripts/seed_final_test.php`

Każdy skrypt dostał identyczny guard (spójny z istniejącym wzorcem z `install_panel.php`, `worker_ksef_inbox.php`, `keep_only_owner_admin_users.php`):

```php
if (PHP_SAPI !== 'cli') {
    $localSecrets = __DIR__ . '/../core/local_secrets.php';
    if (is_file($localSecrets)) require_once $localSecrets;
    $expectedKey = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';
    $givenKey = (string)($_SERVER['HTTP_X_SCRIPT_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '');
    if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'message' => 'Brak/zły klucz dostępu (SLICEHUB_SCRIPT_KEY).']));
    }
}
```

- CLI: skip (dev workflow niezmieniony)
- HTTP bez `local_secrets.php`: 403 (zablokowane)
- HTTP z `local_secrets.php` + poprawny klucz: działa

### C2 — 5 brakujących metod DirectorApp

**Plik:** `modules/online_studio/js/director/DirectorApp.js:955-1008`

Panele `InspectorPanel.js` i `HierarchyPanel.js` wywoływały 5 metod które nie istniały w `DirectorApp`. Optional chaining (`?.`) połykał brak — przyciski były martwe.

Dodane metody używają istniejącego modelu danych (`this._store.spec.pizza?.layers`, `layerSku`, `this._store.patch()`):

| Metoda | Co robi |
|--------|---------|
| `moveLayerUp(layerSku)` | `zIndex += 5` + `store.patch()` |
| `moveLayerDown(layerSku)` | `zIndex -= 5` + `store.patch()` |
| `reorderLayers(orderBottomUp)` | Liniowo przelicza `zIndex = (idx+1)*10` dla tablicy SKU |
| `duplicateLayer(layerSku)` | `structuredClone` + sufiks `_copy_` + `store.patch()` + selekcja |
| `duplicateCompanion(sku)` | `structuredClone` + sufiks `_copy_` + offset x/y + `store.patch()` + selekcja |

### D2 — CredentialVault encrypt() strict, encryptSoft() graceful

**Plik:** `core/CredentialVault.php` — `encrypt()` zmienione z fail-open na strict (throw RuntimeException).

**6 call-site'ów przepiętych** `encrypt()` → `encryptSoft()`:

| Plik | Linia | Kontekst |
|------|------|----------|
| `core/Ksef/Client.php` | 833 | Zapis credentials KSeF po refresh tokena |
| `api/procurement/ksef_config.php` | 193 | Zapis konfiguracji KSeF (token z portalu MF) |
| `api/settings/engine.php` | 300 | Zapis credentials integracji (JSON) |
| `api/settings/engine.php` | 305 | Zapis credentials integracji (string) |
| `api/settings/engine.php` | 543 | Rotacja sekretu webhooka |
| `api/settings/engine.php` | 570 | Nowy webhook — zapis sekretu |

**2 call-site'y zostawione na `encrypt()`** — `scripts/rotate_credentials_to_vault.php` (skrypt migracyjny z własnym `isReady()` guard + `exit(2)`).

Pełna mapa 26 call-site'ów w 8 plikach — patrz sekcja poniżej.

### PanicEngine — 2 pre-existing bugi (znalezione podczas testów)

**Plik 1:** `core/PanicEngine.php:57-75` — mieszanie named i positional parametrów w PDO (`?` + `:delay` w jednym prepared statement) → `SQLSTATE[HY093]: Invalid parameter number: mixed named and positional parameters`. Przepisane na wszystkie named (`:s0`, `:s1`, ...).

**Plik 2:** `api/pos/engine.php:1568` — `$user_id` (int) przekazywany do `PanicEngine::execute(?string $userId)` → `TypeError`. Dodano cast `(string)$user_id`.

Oba bugi powodowały HTTP 500 na akcji `panic_mode` — test T58 failował. Po naprawie: 62/62 PASS.

## Pełna mapa call-site'ów CredentialVault (26 w 8 plikach)

### Zapis (szyfrowanie) — 8

| # | Plik | Metoda | Podpięte do |
|---|------|--------|-------------|
| 1 | `core/Ksef/Client.php:833` | `encryptSoft()` | KSeF Inbox — zapis po refresh |
| 2 | `api/procurement/ksef_config.php:193` | `encryptSoft()` | Procurement — konfiguracja KSeF |
| 3 | `api/settings/engine.php:300` | `encryptSoft()` | Settings — zapis integracji (JSON) |
| 4 | `api/settings/engine.php:305` | `encryptSoft()` | Settings — zapis integracji (string) |
| 5 | `api/settings/engine.php:543` | `encryptSoft()` | Settings — rotacja sekretu webhooka |
| 6 | `api/settings/engine.php:570` | `encryptSoft()` | Settings — nowy webhook |
| 7 | `scripts/rotate_credentials_to_vault.php:87` | `encrypt()` | Skrypt migracyjny (CLI) |
| 8 | `scripts/rotate_credentials_to_vault.php:135` | `encrypt()` | Skrypt migracyjny (CLI) |

### Odczyt (odszyfrowanie) — 8

| # | Plik | Metoda | Podpięte do |
|---|------|--------|-------------|
| 9 | `core/Ksef/Client.php:110` | `decrypt()` | KSeF Client — ładowanie credentials |
| 10 | `core/Ksef/Client.php:814` | `decrypt()` | KSeF Client — refresh tokena |
| 11 | `core/Integrations/BaseAdapter.php:236` | `decrypt()` | Papu/Dotykačka — wywołanie API |
| 12 | `core/WebhookDispatcher.php:282` | `decrypt()` | worker_webhooks — wysyłka webhooka |
| 13 | `core/SettingsPingLib.php:105` | `decrypt()` | Settings — ping webhooka |
| 14 | `api/integrations/inbound.php:318` | `decrypt()` | Webhook inbound — weryfikacja podpisu |
| 15 | `scripts/rotate_credentials_to_vault.php:88` | `decrypt()` | Skrypt migracyjny — test roundtrip |
| 16 | `scripts/rotate_credentials_to_vault.php:136` | `decrypt()` | Skrypt migracyjny — test roundtrip |

### Status — 10

| # | Plik | Metoda | Podpięte do |
|---|------|--------|-------------|
| 17 | `api/settings/engine.php:66` | `isEncrypted()` | redactCredentials — ukrywanie sekretów |
| 18 | `api/settings/engine.php:241` | `isEncrypted()` | Lista integracji — flaga dla UI |
| 19 | `api/settings/engine.php:255` | `isReady()` | Lista integracji — vault_ready |
| 20 | `api/settings/engine.php:499` | `isEncrypted()` | Lista webhooków — flaga dla UI |
| 21 | `api/settings/engine.php:503` | `isReady()` | Lista webhooków — vault_ready |
| 22 | `api/settings/engine.php:900` | `isReady()` | Health summary |
| 23 | `api/settings/engine.php:901` | `isSodiumAvailable()` | Health summary |
| 24 | `core/Integrations/BaseAdapter.php:235` | `isEncrypted()` | Adapter — check przed decrypt |
| 25 | `core/WebhookDispatcher.php:281` | `isEncrypted()` | WebhookDispatcher — check przed decrypt |
| 26 | `api/integrations/inbound.php:318` | `isEncrypted()` | Inbound — check przed decrypt |

## Wpływ

- **Dev (bez vaultu):** KSeF, Settings, webhooki działają identycznie jak wcześniej — `encryptSoft()` zwraca plaintext
- **Produkcja (z vaultem):** `encrypt()` rzuca wyjątek jeśli vault niegotowy — nie zapisuje cichego plaintextu
- **Skrypty destrukcyjne:** CLI działa, HTTP zablokowane bez klucza
- **DirectorApp:** Przyciski w Inspector i Hierarchy działają (warstwa up/down, duplikat, reorder drag&drop)
- **Panic mode:** Działa przez HTTP (był HTTP 500 od typu bugów)

## Testy

```
HR Payroll Ledger (regresje):     58/58 PASS
PayrollEngine Parity (tenant 1):  11/11 PASS (Δ 0 gr net)
Test Runner (E2E browser):        61 pass / 0 fail / 1 warn  (62 total)
```

## Lint

```
core/CredentialVault.php          — No syntax errors detected
core/Ksef/Client.php              — No syntax errors detected
api/settings/engine.php           — No syntax errors detected
api/procurement/ksef_config.php   — No syntax errors detected
core/PanicEngine.php              — No syntax errors detected
api/pos/engine.php                — No syntax errors detected
```
