# ChoiceQR — instrukcja uruchomienia (krok po kroku)

> **Stan (2026-08-04):** ✅ **INTEGRACJA URUCHOMIONA I POŁĄCZONA Z PRAWDZIWYM CHOICEQR**
>
> OAuth flow ukończony — JWT token i var_symbol (128463) zapisane w bazie (zaszyfrowane vaultem).
> Wszystkie 6 endpointów działa przez Tailscale Funnel. ChoiceQR API odpowiada (GET /orders/list = 200).
> Menu i areas dostępne dla ChoiceQR. Czekamy na pierwsze zamówienie testowe od klienta.
>
> **Bug fix (2026-08-04):** Wszystkie 6 endpointów ChoiceQR miało bug — czytały credentials
> z bazy bez deszyfrowania przez CredentialVault (credentials są zaszyfrowane vault:v1: po OAuth flow).
> Naprawione: dodano `CredentialVault::decrypt()` w webhook.php, events.php, pay.php, menu.php,
> areas.php, table_orders.php.

---

## Co jest zrobione

| Element | Stan |
|---------|------|
| 6 endpointów ChoiceQR (`api/integrations/choiceqr/*.php`) | ✅ Istniały + bug fix (CredentialVault decrypt) |
| `oauth_callback.php` (OAuth code→token) | ✅ Napisany, **działa — OAuth ukończony** |
| `scripts/choiceqr_setup.sql` (wiersz w bazie) | ✅ Uruchomiony |
| `scripts/test_choiceqr_endpoints.php` (smoke test) | ✅ 10/10 PASS |
| `scripts/choiceqr_test_live_api.php` (test na żywo) | ✅ ChoiceQR API 200, menu/areas/table_orders 200 |
| Wiersz `sh_tenant_integrations` (id=2, provider=choiceqr) | ✅ Z prawdziwymi credentials |
| `webhook_token` | ✅ `b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| `config/vault_key.txt` (szyfrowanie credentials) | ✅ Istnieje |
| Publiczny URL (Tailscale Funnel) | ✅ `https://desktop-i72peau.tail9e5f0e.ts.net` |
| Endpointy dostępne z internetu | ✅ 10/10 PASS przez Funnel |
| Aplikacja POS terminal w panelu ChoiceQR | ✅ Stworzona (clientId: `6a70dbd08cdc4ca63a0e7972`) |
| 5 URL-i POS w formularzu aplikacji | ✅ Skonfigurowane |
| **"ChoiceQR token" (token firmy pizzaforno)** | ✅ Dostarczony przez ChoiceQR |
| **OAuth flow (code → JWT)** | ✅ Ukończony (2026-08-04 15:24) |
| JWT token z ChoiceQR | ✅ W bazie (zaszyfrowany, 211 znaków) |
| `var_symbol` Twojej firmy | ✅ `128463` (pizzaforno.choiceqr.com) |
| **Pierwsze zamówienie testowe** | ⏳ Czeka na złożenie zamówienia przez klienta |

---

## Architektura ChoiceQR — dwa osobne systemy (ważne!)

ChoiceQR ma **dwa osobne systemy** które trzeba rozróżnić:

1. **`open-api.choiceqr.com/auth/client`** — portal dla **developerów/partnerów** (dostawców integracji).
   Tu tworzy się konto developera, aplikacje POS terminal, dostaje `clientId`/`clientSecret`.
   **To jest konto dostawcy integracji** (SliceHub).

2. **`pizzaforno.choiceqr.com/admin`** — panel **restauracji** (klienta ChoiceQR).
   Tu zarządza się menu, zamówieniami, strefami, stolikami.
   **To jest konto klienta ChoiceQR** (pizzaforno).

**"ChoiceQR token"** o który pyta consent page to token **konta restauracji** (pizzaforno),
nie konta developera. To dwa różne światy — nawet jeśli jesteś właścicielem obu.

Cytat z dokumentacji ChoiceQR (`https://open-api.choiceqr.com/docs/content/authorization.md`):
> **"❗ You have to have ChoiceQR token. Please request from api@choiceqr.com or your point of contact"**

ChoiceQR celowo nie wystawia tego tokenu w panelu admina restauracji — trzeba go dostać
od zespołu ChoiceQR. To jest ich model bezpieczeństwa (ownership proof).

---

## BLOCKER — "ChoiceQR token" na consent page — ROZWIĄZANY (2026-08-04)

**Rozwiązanie:** Użytkownik dostał "ChoiceQR token" od zespołu ChoiceQR, wpisał go w consent page,
ChoiceQR przekierował na `oauth_callback.php?code=XXXX&client_id=...&client_secret=...`,
skrypt automatycznie wymienił code na JWT token i zapisal `{token, var_symbol: 128463, webhook_token}`
do bazy (zaszyfrowane vaultem).

### Objaw (historyczny)

Po otwarciu linku connect:
```
https://pizzaforno.choiceqr.com/admin/open-api/connect?clientId=6a70dbd08cdc4ca63a0e7972
  &redirectUrl=https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/oauth_callback.php
  &scope=all&response_type=code&digit=2178
```

ChoiceQR pokazuje:
```
Application "SliceHub POS"
ask permission to manage your ChoiceQR account

Please provide ChoiceQR token
Please check validity ChoiceQR token
```

### Przyczyna

ChoiceQR wymaga "ChoiceQR token" (token firmy pizzaforno) żeby udowodnić że jesteś
właścicielem firmy. To NIE jest:
- ❌ JWT token z OAuth flow (ten dostaniemy PO consent)
- ❌ Sesja logowania do panelu admina (logowanie nie pomaga)
- ❌ clientId/clientSecret aplikacji SliceHub (to dane developera, nie firmy)
- ❌ Dane SliceHub (to ChoiceQR musi potwierdzić kim jest właściciel pizzaforno)

To JEST:
- ✅ Token API firmy pizzaforno w systemie ChoiceQR (token konta restauracji)
- ✅ Wydawany przez zespół ChoiceQR (api@choiceqr.com / point of contact)
- ✅ Według dokumentacji oficjalna ścieżka dostępu

### Rozwiązanie

Napisać do osoby z ChoiceQR która dała dostęp do `open-api.choiceqr.com/auth/client`
(lub na api@choiceqr.com jeśli nie ma bezpośredniego kontaktu):

```
Cześć,

Stworzyłem aplikację POS terminal SliceHub (clientId: 6a70dbd08cdc4ca63a0e7972)
i próbuję połączyć ją z pizzaforno.choiceqr.com przez OAuth flow.
Na ekranie consent ChoiceQR pyta o "ChoiceQR token" — nie mam go.
Możesz mi przesłać ChoiceQR token dla pizzaforno.choiceqr.com?

Pozdrawiam,
Damian
```

### Po dostaniu tokenu

1. Otworzyć link connect (z `client_id` i `client_secret` w `redirectUrl`):
   ```
   https://pizzaforno.choiceqr.com/admin/open-api/connect?clientId=6a70dbd08cdc4ca63a0e7972
     &redirectUrl=https%3A%2F%2Fdesktop-i72peau.tail9e5f0e.ts.net%2Fslicehub%2Fapi%2Fintegrations%2Fchoiceqr%2Foauth_callback.php%3Fclient_id%3D6a70dbd08cdc4ca63a0e7972%26client_secret%3Dgipdrnkxxdslywkoqqlfgsjiwqbfvuyb
     &scope=all&response_type=code&digit=2178
   ```
2. Wkleić "ChoiceQR token" w pole na consent page.
3. Kliknąć "Allow" / "Accept".
4. ChoiceQR przekieruje na `oauth_callback.php?code=XXXX&client_id=...&client_secret=...`.
5. `oauth_callback.php` automatycznie:
   - Wymieni `code` na JWT token przez `POST https://open-api.choiceqr.com/auth/connect/token`
   - Zapisze `{token, var_symbol, webhook_token}` do bazy (zaszyfrowane vaultem)
   - Wyświetli zielony landing page z potwierdzeniem i `var_symbol`

---

## Marketplace vs własna aplikacja

W panelu `pizzaforno.choiceqr.com/admin` → Ustawienia → Open API provider widać listę
pre-integrowanych providerów (Dotykačka, ID POS, Poster, Smart POS). "Aktywuj" jest
wyszarzone bo żadna integracja nie jest jeszcze połączona.

SliceHub jest **nową aplikacją** (właśnie stworzoną, nie na marketplace). Aby się pojawiła
na liście i aktywowała, trzeba ukończyć OAuth flow (co wymaga "ChoiceQR token" — patrz BLOCKER wyżej).

---

## Dane aplikacji ChoiceQR (do zapamiętania)

| Pole | Wartość |
|------|---------|
| **Application name** | `SliceHub POS` |
| **Application type** | `POS terminal` (nie można zmienić po utworzeniu) |
| **Client ID** | `6a70dbd08cdc4ca63a0e7972` |
| **Client secret** | `gipdrnkxxdslywkoqqlfgsjiwqbfvuyb` |
| **Authorization callback URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/oauth_callback.php` |
| **Webhook URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/events.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| **Firma w ChoiceQR** | `pizzaforno.choiceqr.com` |
| **Connect link (z digit)** | `https://pizzaforno.choiceqr.com/admin/open-api/connect?clientId=6a70dbd08cdc4ca63a0e7972&redirectUrl=...&scope=all&response_type=code&digit=2178` |

### 5 URL-i POS (skonfigurowane w formularzu aplikacji)

| Pole | URL |
|------|-----|
| **Create order URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/webhook.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| **Get menu URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/menu.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| **Get areas URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/areas.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| **Get table orders URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/table_orders.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |
| **Pay table order URL** | `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/pay.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` |

### Webhook token (do auth webhooków)

```
b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
```

### Tailscale Funnel (publiczny URL)

```
tailscale funnel --bg 80
→ https://desktop-i72peau.tail9e5f0e.ts.net
```

Wyłączenie: `tailscale funnel --https=443 off`

---

## Krok 1 — Publiczny URL (tunel) — ZROBIONE

**Wykonano:** Tailscale Funnel (Opcja D — alternatywa dla ngrok/cloudflared).

```
tailscale funnel --bg 80
→ https://desktop-i72peau.tail9e5f0e.ts.net
```

Wymaga włączonego Funnel w Tailscale admin:
`https://login.tailscale.com/f/funnel?node=nXoQWFPV4v11CNTRL`

### Alternatywy (gdyby Funnel nie działał)

#### Opcja A — ngrok

1. Pobierz ngrok: https://ngrok.com/download
2. Zarejestruj się (darmowy plan).
3. Uruchom: `ngrok http 80`
4. Otrzymasz URL typu `https://abc123.ngrok.io`.

#### Opcja B — cloudflared

1. Pobierz cloudflared: https://github.com/cloudflare/cloudflared/releases
2. Uruchom: `cloudflared tunnel --url http://localhost:80`
3. Otrzymasz URL typu `https://xyz.trycloudflare.com`.

#### Opcja C — hosting z publiczną domeną

Jeśli masz hosting z publiczną domeną (np. `https://slicehub.pl`), użyj go zamiast tunelu.

**Zapisz swój publiczny URL** — będzie potrzebny w kolejnych krokach. W dalszej instrukcji oznaczam go jako `TWOJ_URL`.

---

## Krok 2 — Aplikacja POS terminal w panelu ChoiceQR — ZROBIONE

**Wykonano:** Stworzono aplikację w `https://open-api.choiceqr.com/auth/client`.

- **Application name:** `SliceHub POS`
- **Authorization callback URL:** `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/oauth_callback.php`
- **Webhook URL:** `https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/events.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3`
- **Application type:** `POS terminal`
- **Client ID:** `6a70dbd08cdc4ca63a0e7972`
- **Client secret:** `gipdrnkxxdslywkoqqlfgsjiwqbfvuyb`
- **5 URL-i POS:** skonfigurowane w formularzu (patrz sekcja "Dane aplikacji" wyżej)

---

## Krok 3 — Połączenie aplikacji z Twoją firmą w ChoiceQR — ZROBIONE

**Wykonano:**
- W panelu ChoiceQR (open-api.choiceqr.com/auth/client) w sekcji "Connected company list"
  wpisano `https://pizzaforno.choiceqr.com` i kliknięto "Connect".
- ChoiceQR wygenerował link connect z `digit=2178`.
- Otwarto link → consent page pytał o "ChoiceQR token" → wpisano token od ChoiceQR → "Allow".
- ChoiceQR przekierował na `oauth_callback.php?code=XXXX&client_id=...&client_secret=...`.
- `oauth_callback.php` automatycznie wymienił code na JWT token + zapisał var_symbol 128463.

---

## Krok 4 — OAuth flow (automatyczny) — ZROBIONE (2026-08-04 15:24)

**Wykonano:** OAuth flow ukończony pomyślnie.
- JWT token (211 znaków, `eyJhbGciOiJIUzI1NiIs...`) zapisany w bazie (zaszyfrowany vaultem).
- var_symbol: `128463` (pizzaforno.choiceqr.com).
- domain: `pizzaforno.choiceqr.com`.
- `last_sync_at`: 2026-08-04 15:24:25.

### Weryfikacja (2026-08-04):

1. Otwórz link connect z `client_id` i `client_secret` w `redirectUrl` (zakodowane jako `%3F` `%3D` `%26`):
   ```
   https://pizzaforno.choiceqr.com/admin/open-api/connect?clientId=6a70dbd08cdc4ca63a0e7972
     &redirectUrl=https%3A%2F%2Fdesktop-i72peau.tail9e5f0e.ts.net%2Fslicehub%2Fapi%2Fintegrations%2Fchoiceqr%2Foauth_callback.php%3Fclient_id%3D6a70dbd08cdc4ca63a0e7972%26client_secret%3Dgipdrnkxxdslywkoqqlfgsjiwqbfvuyb
     &scope=all&response_type=code&digit=2178
   ```
2. Wklej "ChoiceQR token" w pole na consent page.
3. Kliknij "Allow" / "Accept".
4. ChoiceQR przekieruje na:
   ```
   https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/oauth_callback.php?code=XXXX&client_id=6a70dbd08cdc4ca63a0e7972&client_secret=gipdrnkxxdslywkoqqlfgsjiwqbfvuyb
   ```
5. `oauth_callback.php` automatycznie:
   - Wymieni `code` na JWT token przez `POST https://open-api.choiceqr.com/auth/connect/token`
   - Zapisze `{token, var_symbol, webhook_token}` do `sh_tenant_integrations` (zaszyfrowane vaultem)
   - Wyświetli zielony landing page z potwierdzeniem i `var_symbol` Twojej firmy

### Alternatywa — Ręczna (jeśli automatyczna nie zadziała)

1. Otwórz link connect (bez dodatkowych parametrów).
2. Wklej "ChoiceQR token" + kliknij "Allow".
3. ChoiceQR przekieruje na `oauth_callback.php?code=XXXX` (bez client_id/client_secret).
4. Skopiuj `code` z URL.
5. Otwórz w przeglądarce:
   ```
   https://desktop-i72peau.tail9e5f0e.ts.net/slicehub/api/integrations/choiceqr/oauth_callback.php?code=XXXX&client_id=6a70dbd08cdc4ca63a0e7972&client_secret=gipdrnkxxdslywkoqqlfgsjiwqbfvuyb
   ```
6. `oauth_callback.php` zrobi resztę.

### Alternatywa — Env (gdy masz serwer z env vars)

Ustaw w Apache envvars (lub `.env`):
```
SLICEHUB_CHOICEQR_CLIENT_ID=6a70dbd08cdc4ca63a0e7972
SLICEHUB_CHOICEQR_CLIENT_SECRET=gipdrnkxxdslywkoqqlfgsjiwqbfvuyb
```
Zrestartuj Apache. Potem OAuth flow zadziała bez dodawania parametrów do URL.

---

## Krok 5 — Skonfiguruj 5 URL-i POS w panelu ChoiceQR — ZROBIONE

**Wykonano:** 5 URL-i POS wpisanych w formularzu aplikacji SliceHub POS w panelu ChoiceQR
(`https://open-api.choiceqr.com/auth/client` → edycja aplikacji).

URL-e są w sekcji "Dane aplikacji ChoiceQR" wyżej. Wszystkie z `?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` na końcu.

**Webhook URL** (dla eventów — order.cancelled, order.delivery.update, order.qrPayment.completed)
ustawiony w Kroku 2 w polu "Webhook URL" formularza aplikacji.

---

## Krok 6 — Weryfikacja

### Sprawdź w bazie że OAuth się udał:
```bash
mysql -u root slicehub_pro_v2 -e "SELECT id, tenant_id, provider, display_name, api_base_url, direction, is_active, last_sync_at FROM sh_tenant_integrations WHERE provider='choiceqr';"
```
`last_sync_at` powinno mieć dzisiejszą datę, `is_active=1`.

### Uruchom smoke test:
```bash
php scripts/test_choiceqr_endpoints.php http://localhost/slicehub/api/integrations/choiceqr b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3 TWOJ_VAR_SYMBOL
```
Oczekiwany wynik: `PASS: 10 / FAIL: 0 / TOTAL: 10`.

### Przetestuj na żywo z panelu ChoiceQR:
1. W panelu ChoiceQR kliknij **"Sync menu"** → powinno pobrać menu z SliceHub.
2. W panelu ChoiceQR kliknij **"Areas"** → powinno pobrać strefy/stoliki.
3. Złóż testowe zamówienie na ChoiceQR (jako klient) → restauracja akceptuje → ChoiceQR POSTuje na webhook.php → zamówienie pojawia się w POS/KDS SliceHub.

### Sprawdź logi:
```bash
# Apache error log (gdzie ChoiceQR logi błędy)
tail -f /c/xampp/apache/logs/error_log | grep -i choiceqr

# sh_inbound_callbacks (logi eventów od ChoiceQR)
mysql -u root slicehub_pro_v2 -e "SELECT id, provider, status, external_event_id, event_type, received_at, processed_at FROM sh_inbound_callbacks WHERE provider='choiceqr' ORDER BY id DESC LIMIT 10;"

# sh_integration_deliveries (logi push do ChoiceQR)
mysql -u root slicehub_pro_v2 -e "SELECT id, integration_id, event_type, status, attempts, last_error FROM sh_integration_deliveries ORDER BY id DESC LIMIT 10;"
```

---

## Krok 7 — Worker (cron) dla push statusów

Żeby SliceHub odsyłał statusy do ChoiceQR (anulacja, zamknięcie, status dostawy), uruchom worker:

### Jednorazowo (test):
```bash
php scripts/worker_integrations.php -v
```

### Cron (co 2 minuty):
```
*/2 * * * * cd /c/xampp/htdocs/slicehub && /c/xampp/php/php.exe scripts/worker_integrations.php >> logs/integrations.log 2>&1
```

### Continuous loop (systemd/docker):
```bash
php scripts/worker_integrations.php --loop --sleep=10
```

Worker bierze eventy z `sh_event_outbox`, filtruje po `gateway_source='choiceqr'`, woła `ChoiceQRAdapter`, wysyła PUT do ChoiceQR API.

---

## Troubleshooting

### "No tenant integration found for varSymbol='XXX'"
- `var_symbol` w bazie nie zgadza się z tym co ChoiceQR wysyła.
- Sprawdź: `mysql -u root slicehub_pro_v2 -e "SELECT credentials FROM sh_tenant_integrations WHERE provider='choiceqr';"`
- Po OAuth flow `var_symbol` powinien być ustawiony automatycznie.

### "Invalid webhook token"
- Token w URL `?t=...` nie zgadza się z `webhook_token` w credentials.
- Sprawdź: `b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3` (ten wygenerowany przez Devina).
- Jeśli chcesz inny, zaktualizuj w bazie i w URL-ach panelu ChoiceQR.

### "credentials decrypt failed"
- `config/vault_key.txt` nie istnieje lub jest uszkodzony.
- Wygeneruj nowy: `php -r "echo bin2hex(random_bytes(32));" > config/vault_key.txt`
- Uwaga: zmiana klucza unieważnia wszystkie zaszyfrowane credentials — trzeba przejść OAuth flow ponownie.

### ChoiceQR nie uderza w endpointy
- Sprawdź czy tunel (ngrok/cloudflared) działa.
- Sprawdź czy URL-e w panelu ChoiceQR są poprawne (z `?t=TOKEN` na końcu).
- Sprawdź Apache access log: `tail -f /c/xampp/apache/logs/access_log | grep choiceqr`

### Worker nie pushuje do ChoiceQR
- Sprawdź czy `sh_event_outbox` ma eventy: `mysql -u root slicehub_pro_v2 -e "SELECT COUNT(*) FROM sh_event_outbox WHERE event_type LIKE 'order.%';"`
- Sprawdź czy worker się nie wywala: `php scripts/worker_integrations.php -v --dry-run`
- Sprawdź `sh_integration_deliveries`: `mysql -u root slicehub_pro_v2 -e "SELECT * FROM sh_integration_deliveries ORDER BY id DESC LIMIT 5;"`

---

## Pliki

| Plik | Funkcja |
|------|---------|
| `api/integrations/choiceqr/webhook.php` | Odbiór zamówień z ChoiceQR (POST) |
| `api/integrations/choiceqr/events.php` | Odbiór eventów (status/delivery/QR payment) (POST) |
| `api/integrations/choiceqr/pay.php` | Potwierdzenie płatności QR przy stoliku (POST) |
| `api/integrations/choiceqr/menu.php` | Export menu do ChoiceQR (GET) |
| `api/integrations/choiceqr/areas.php` | Export stref/stolików (GET) |
| `api/integrations/choiceqr/table_orders.php` | Lista zamówień przy stoliku (GET) |
| `api/integrations/choiceqr/oauth_callback.php` | **NOWY** — OAuth code→token exchange (GET) |
| `core/Integrations/ChoiceQRAdapter.php` | Push statusów SliceHub → ChoiceQR API |
| `scripts/choiceqr_setup.sql` | **NOWY** — dodaje wiersz `choiceqr` do bazy |
| `scripts/test_choiceqr_endpoints.php` | **NOWY** — smoke test 10 endpointów |
| `_docs/integrations/choiceqr_integration.md` | Pełna dokumentacja integracji (1378 linii) |
| `_docs/integrations/choiceqr_setup_guide.md` | **NOWY** — ta instrukcja |
