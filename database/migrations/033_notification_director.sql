-- =============================================================================
-- Migration 033: Notification Director — pełna infrastruktura powiadomień
--
-- Zakres:
--   1. Rozszerzenie sh_orders: email klienta, zgody RODO, timestampy lifecycle
--   2. sh_customer_contacts  — mini-CRM (dedup po phone)
--   3. sh_notification_channels — konfiguracja kanałów per tenant
--   4. sh_notification_routes   — routing event_type → kanały z fallbackiem
--   5. sh_notification_templates — szablony per event+channel
--   6. sh_notification_deliveries — log każdej próby wysyłki
--   7. sh_marketing_campaigns — kampanie marketingowe
--   8. sh_customer_inbox      — przychodzące SMS (bidirectional phone relay)
--   9. sh_sse_broadcast       — kolejka dla SSE InAppChannel (bez Redisa)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Rozszerzenie sh_orders
-- ---------------------------------------------------------------------------

ALTER TABLE sh_orders
    ADD COLUMN IF NOT EXISTS customer_email      VARCHAR(255) NULL        AFTER customer_phone,
    ADD COLUMN IF NOT EXISTS sms_consent         TINYINT(1)  NOT NULL DEFAULT 0 AFTER customer_email,
    ADD COLUMN IF NOT EXISTS marketing_consent   TINYINT(1)  NOT NULL DEFAULT 0 AFTER sms_consent,
    ADD COLUMN IF NOT EXISTS accepted_at         DATETIME    NULL        AFTER promised_time,
    ADD COLUMN IF NOT EXISTS ready_at            DATETIME    NULL        AFTER accepted_at,
    ADD COLUMN IF NOT EXISTS out_for_delivery_at DATETIME    NULL        AFTER ready_at,
    ADD COLUMN IF NOT EXISTS delivered_at        DATETIME    NULL        AFTER out_for_delivery_at,
    ADD COLUMN IF NOT EXISTS cancelled_at        DATETIME    NULL        AFTER delivered_at,
    ADD COLUMN IF NOT EXISTS customer_requested_cancel TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_at;

-- ---------------------------------------------------------------------------
-- 2. sh_customer_contacts — mini-CRM, dedup po (tenant_id, phone)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_customer_contacts (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED    NOT NULL,
    phone               VARCHAR(32)     NOT NULL,
    name                VARCHAR(255)    NULL,
    email               VARCHAR(255)    NULL,
    sms_consent         TINYINT(1)      NOT NULL DEFAULT 0,
    marketing_consent   TINYINT(1)      NOT NULL DEFAULT 0,
    sms_optout_at       DATETIME        NULL     COMMENT 'Kiedy klient wysłał STOP',
    first_seen_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_order_at       DATETIME        NULL,
    order_count         INT UNSIGNED    NOT NULL DEFAULT 0,
    notes               TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_phone (tenant_id, phone),
    KEY idx_contact_tenant (tenant_id),
    KEY idx_contact_last_order (tenant_id, last_order_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. sh_notification_channels — konfiguracja kanałów per tenant
--    channel_type: in_app | email | personal_phone | sms_gateway
--    provider:     sse | smtp | smsgateway_android | generic_http | smsapi_pl | twilio
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_notification_channels (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id               INT UNSIGNED    NOT NULL,
    name                    VARCHAR(128)    NOT NULL COMMENT 'Przyjazna nazwa np. "Mój telefon Android"',
    channel_type            VARCHAR(32)     NOT NULL,
    provider                VARCHAR(32)     NOT NULL,
    credentials_json        LONGTEXT        NULL     COMMENT 'Zaszyfrowane dane: host, port, user, pass, token, etc.' CHECK (JSON_VALID(credentials_json) OR credentials_json IS NULL),
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    priority                TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Niższy = wyższy priorytet w fallback chain',
    rate_limit_per_hour     SMALLINT UNSIGNED NULL   COMMENT 'NULL = brak limitu',
    rate_limit_per_day      SMALLINT UNSIGNED NULL,
    consecutive_failures    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    paused_until            DATETIME        NULL     COMMENT 'Auto-pauza po X błędach (jak WebhookDispatcher)',
    last_health_check_at    DATETIME        NULL,
    last_health_status      VARCHAR(16)     NULL     COMMENT 'ok | error | timeout',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_ch_tenant (tenant_id, is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. sh_notification_routes — event_type → kanały z fallbackiem
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_notification_routes (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED    NOT NULL,
    event_type          VARCHAR(64)     NOT NULL COMMENT 'order.created | order.accepted | order.ready | order.dispatched | order.delivered | order.cancelled | marketing.campaign',
    channel_id          INT UNSIGNED    NOT NULL COMMENT 'FK → sh_notification_channels.id',
    fallback_order      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=pierwszy (primary), 1,2,3... = fallbacki',
    requires_sms_consent       TINYINT(1) NOT NULL DEFAULT 0,
    requires_marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route (tenant_id, event_type, channel_id),
    KEY idx_route_tenant_event (tenant_id, event_type, fallback_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. sh_notification_templates — szablony per tenant+event+channel_type
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_notification_templates (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    event_type      VARCHAR(64)     NOT NULL,
    channel_type    VARCHAR(32)     NOT NULL,
    lang            CHAR(2)         NOT NULL DEFAULT 'pl',
    subject         VARCHAR(255)    NULL     COMMENT 'Dla email; dla SMS ignorowane',
    body            TEXT            NOT NULL COMMENT 'Mustache-lite: {{customer_name}}, {{order_number}}, {{eta_minutes}}, {{tracking_url}}, {{store_name}}, {{store_phone}}, {{total_pln}}',
    variables_json  LONGTEXT        NULL     COMMENT 'Metadata dla edytora: lista dostępnych zmiennych' CHECK (JSON_VALID(variables_json) OR variables_json IS NULL),
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_template (tenant_id, event_type, channel_type, lang),
    KEY idx_tpl_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domyślne szablony PL dla tenanta 1 (placeholders — do edycji w Settings)
INSERT IGNORE INTO sh_notification_templates
    (tenant_id, event_type, channel_type, lang, subject, body) VALUES
(1, 'order.created',    'email',          'pl', 'Otrzymaliśmy Twoje zamówienie #{{order_number}}',
    'Cześć {{customer_name}},\n\nOtrzymaliśmy Twoje zamówienie #{{order_number}} na kwotę {{total_pln}}.\nŚledzimy postęp tutaj: {{tracking_url}}\n\nDzięki, {{store_name}}'),
(1, 'order.created',    'personal_phone', 'pl', NULL,
    'Dzięki {{customer_name}}! Twoje zamówienie #{{order_number}} otrzymane. Śledzenie: {{tracking_url}}'),
(1, 'order.created',    'sms_gateway',    'pl', NULL,
    'Dzięki {{customer_name}}! Zamówienie #{{order_number}} otrzymane. Link: {{tracking_url}}'),
(1, 'order.accepted',   'email',          'pl', 'Twoje zamówienie #{{order_number}} zaakceptowane!',
    'Cześć {{customer_name}},\n\nRestauracja zaakceptowała zamówienie #{{order_number}}.\nSzacowany czas: {{eta_minutes}} min.\nŚledź: {{tracking_url}}\n\n{{store_name}}'),
(1, 'order.accepted',   'personal_phone', 'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} zaakceptowane! Gotowe za ok. {{eta_minutes}} min. Śledzenie: {{tracking_url}}'),
(1, 'order.accepted',   'sms_gateway',    'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} zaakceptowane! Gotowe za {{eta_minutes}} min. {{tracking_url}}'),
(1, 'order.ready',      'personal_phone', 'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} gotowe! Kierowca wyjeżdża za chwilę.'),
(1, 'order.ready',      'sms_gateway',    'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} gotowe do odbioru/wysyłki!'),
(1, 'order.delivered',  'personal_phone', 'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} dostarczono! Smacznego 🍕. Odp. STOP = rezygnacja z SMS.'),
(1, 'order.delivered',  'sms_gateway',    'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} dostarczone. Smacznego! Odp. STOP = rezygnacja z SMS.'),
(1, 'order.cancelled',  'email',          'pl', 'Zamówienie #{{order_number}} anulowane',
    'Cześć {{customer_name}},\n\nNiestety zamówienie #{{order_number}} zostało anulowane.\nMasz pytania? Zadzwoń: {{store_phone}}\n\n{{store_name}}'),
(1, 'order.cancelled',  'personal_phone', 'pl', NULL,
    '{{store_name}}: zamówienie #{{order_number}} anulowane. Przepraszamy! Pytania: {{store_phone}}'),
(1, 'reorder.nudge',   'personal_phone', 'pl', NULL,
    '{{store_name}}: Hej {{customer_name}}! Mamy dla Ciebie ulubioną pizzę 🍕 Zamów teraz: {{store_url}} Odp. STOP = rezygnacja.'),
(1, 'reorder.nudge',   'sms_gateway',    'pl', NULL,
    '{{store_name}}: Hej {{customer_name}}! Twoja ulubiona pizza czeka 🍕 Zamów: {{store_url}} STOP - rezygnacja.');

-- ---------------------------------------------------------------------------
-- 6. sh_notification_deliveries — log prób wysyłki
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_notification_deliveries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED    NOT NULL,
    event_id            BIGINT UNSIGNED NULL     COMMENT 'FK → sh_event_outbox.id',
    channel_id          INT UNSIGNED    NOT NULL COMMENT 'FK → sh_notification_channels.id',
    event_type          VARCHAR(64)     NOT NULL,
    recipient           VARCHAR(255)    NOT NULL COMMENT 'email lub numer telefonu (hashed dla PII w logach)',
    recipient_hash      CHAR(64)        NULL     COMMENT 'SHA-256 recipient dla audytu bez ujawniania PII',
    status              ENUM('queued','sent','failed','dead') NOT NULL DEFAULT 'queued',
    attempt_number      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    provider_message_id VARCHAR(128)    NULL     COMMENT 'ID wiadomości od providera (dla statusu doręczenia)',
    provider_status     VARCHAR(32)     NULL     COMMENT 'SENT | DELIVERED | FAILED itd. z callbacku',
    error_message       TEXT            NULL,
    cost_grosze         INT             NULL     COMMENT 'Koszt SMS w groszach (dla rozliczenia bramki)',
    next_attempt_at     DATETIME        NULL,
    attempted_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at        DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_nd_tenant_event (tenant_id, event_id),
    KEY idx_nd_channel_status (channel_id, status, next_attempt_at),
    KEY idx_nd_tenant_type_date (tenant_id, event_type, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. sh_marketing_campaigns
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_marketing_campaigns (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED    NOT NULL,
    name                VARCHAR(255)    NOT NULL,
    channel_id          INT UNSIGNED    NOT NULL,
    template_id         INT UNSIGNED    NOT NULL,
    audience_filter_json LONGTEXT       NULL     COMMENT '{"min_orders":2,"days_since_last":30,"requires_marketing_consent":true}' CHECK (JSON_VALID(audience_filter_json) OR audience_filter_json IS NULL),
    status              ENUM('draft','scheduled','running','completed','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at        DATETIME        NULL,
    started_at          DATETIME        NULL,
    completed_at        DATETIME        NULL,
    total_audience      INT UNSIGNED    NULL,
    sent_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    rate_limit_per_hour SMALLINT UNSIGNED NULL   COMMENT 'Nadpisuje limit kanału dla tej kampanii',
    created_by          BIGINT UNSIGNED NULL     COMMENT 'sh_users.id',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_camp_tenant_status (tenant_id, status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. sh_customer_inbox — przychodzące SMS (bidirectional phone relay)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_customer_inbox (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    contact_id      BIGINT UNSIGNED NULL     COMMENT 'FK → sh_customer_contacts.id (NULL jeśli nieznany)',
    from_phone      VARCHAR(32)     NOT NULL,
    body            TEXT            NOT NULL,
    intent          VARCHAR(32)     NULL     COMMENT 'Wynik SmartReplyEngine: eta_query | cancel_request | info_query | stop | other',
    order_id        CHAR(36)        NULL     COMMENT 'Powiązane zamówienie (jeśli znaleziono)',
    auto_replied    TINYINT(1)      NOT NULL DEFAULT 0,
    auto_reply_body TEXT            NULL,
    read_by_user_id BIGINT UNSIGNED NULL,
    read_at         DATETIME        NULL,
    received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inbox_tenant_read (tenant_id, read_at, received_at),
    KEY idx_inbox_contact (tenant_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. sh_sse_broadcast — kolejka SSE dla InAppChannel (bez Redisa)
--    Worker usuwa stare rekordy (TTL 5 minut). SSE endpoint polluje tę tabelę.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_sse_broadcast (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    tracking_token  CHAR(16)        NOT NULL,
    event_type      VARCHAR(64)     NOT NULL,
    payload_json    LONGTEXT        NOT NULL CHECK (JSON_VALID(payload_json)),
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sse_token_time (tracking_token, created_at),
    KEY idx_sse_tenant_time (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
