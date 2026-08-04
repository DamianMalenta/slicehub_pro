-- =============================================================================
-- SliceHub Enterprise — ChoiceQR Integration Setup
-- =============================================================================
-- Ten skrypt dodaje wiersz `choiceqr` do sh_tenant_integrations z placeholderami.
--
-- UWAGA: credentials są szyfrowane przez CredentialVault (XChaCha20-Poly1305).
-- Nie wstawiaj tu prawdziwego tokenu JWT — zrób to przez OAuth flow:
--   1. Najpierw uruchom ten skrypt (doda wiersz z placeholderem).
--   2. Potem przejdź OAuth flow w panelu ChoiceQR → oauth_callback.php
--      automatycznie zaktualizuje credentials (token + var_symbol).
--
-- Alternatywnie, jeśli masz już JWT token z ChoiceQR (z OAuth flow ręcznego),
-- możesz zaszyfrować go przez: php scripts/choiceqr_encrypt_credentials.php
-- i wkleić wynik do credentials poniżej.
--
-- Użycie:
--   mysql -u root slicehub_pro_v2 < scripts/choiceqr_setup.sql
-- =============================================================================

-- 1. Wstaw wiersz choiceqr (idempotent — jeśli istnieje, aktualizuje)
INSERT INTO sh_tenant_integrations
    (tenant_id, provider, display_name, api_base_url, credentials,
     direction, events_bridged, is_active, created_at, updated_at)
VALUES
    (1, 'choiceqr', 'ChoiceQR POS', 'https://open-api.choiceqr.com',
     '{"token":"PLACEHOLDER_RUN_OAUTH","webhook_token":"b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3","var_symbol":"PLACEHOLDER"}',
     'bidirectional',
     '["order.cancelled","order.ready","order.delivered","order.completed","order.dispatched","order.in_delivery"]',
     1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    display_name  = VALUES(display_name),
    api_base_url  = VALUES(api_base_url),
    direction     = VALUES(direction),
    events_bridged = VALUES(events_bridged),
    is_active     = 1,
    updated_at    = NOW();

-- 2. Wyświetl wynik
SELECT id, tenant_id, provider, display_name, api_base_url, direction,
       is_active, created_at, updated_at
FROM sh_tenant_integrations
WHERE provider = 'choiceqr';

-- =============================================================================
-- UWAGA: Po tym skrypcie MUSISZ przejść OAuth flow żeby napełnić credentials:
--
--   1. W panelu ChoiceQR (https://open-api.choiceqr.com/auth/client):
--      - Stwórz aplikację typu "POS terminal"
--      - Authorization callback URL = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/oauth_callback.php
--      - Połącz aplikację z Twoją firmą w ChoiceQR
--
--   2. Kliknij "Connect" w panelu ChoiceQR → ChoiceQR przekieruje na oauth_callback.php
--      z ?code=XXXX → skrypt wymieni code na JWT token i zaktualizuje credentials.
--
--   3. W panelu ChoiceQR ustaw 5 URL-i POS (wszystkie z ?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3):
--      - Create order URL  = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/webhook.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
--      - Get menu URL      = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/menu.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
--      - Get areas URL     = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/areas.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
--      - Get table orders  = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/table_orders.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
--      - Pay table order   = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/pay.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
--
--   4. Webhook URL (events) = https://TWOJ_TUNEL/slicehub/api/integrations/choiceqr/events.php?t=b81a797237ff335c6a7e3cb71b632a64f2a7258993c4614edb24ad032127a6e3
-- =============================================================================
