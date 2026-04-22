-- =============================================================================
-- Migration 034: GDPR & Security hardening (Faza 7)
--
-- Zakres:
--   1. sh_notification_channels: webhook_secret (HMAC) + ciągłe rate-limit state
--   2. sh_notification_deliveries: sanityzacja — provider_response (raw) usunięta,
--      nowa kolumna provider_status_code (tylko HTTP status) bez body PII
--   3. sh_rate_limit_buckets — generyczny bufor tokenów dla rate-limiting per kanał
--   4. sh_security_audit_log — niemodyfikowalny log zdarzeń bezpieczeństwa
--   5. sh_gdpr_consent_log — historia zmian zgód (append-only, bez DELETE)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Rozszerzenie sh_notification_channels: webhook_secret + audit
-- ---------------------------------------------------------------------------

ALTER TABLE sh_notification_channels
    ADD COLUMN IF NOT EXISTS webhook_secret     VARCHAR(128) NULL
        COMMENT 'HMAC secret dla incoming webhooks od tego kanału',
    ADD COLUMN IF NOT EXISTS hmac_algo          VARCHAR(16)  NOT NULL DEFAULT 'sha256'
        COMMENT 'sha256 | sha512',
    ADD COLUMN IF NOT EXISTS tls_verify         TINYINT(1)   NOT NULL DEFAULT 1
        COMMENT '0 = wyłącz weryfikację TLS (tylko dev)',
    ADD COLUMN IF NOT EXISTS pii_in_log         TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '1 = przechowuj surowy recipient w logach (opt-in, wymaga DPA)';

-- ---------------------------------------------------------------------------
-- 2. sh_notification_deliveries: dod. kolumny bez PII
-- ---------------------------------------------------------------------------

ALTER TABLE sh_notification_deliveries
    ADD COLUMN IF NOT EXISTS http_status_code   SMALLINT UNSIGNED NULL
        COMMENT 'HTTP status code od providera (bez body)',
    ADD COLUMN IF NOT EXISTS pii_redacted       TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = recipient zamazany wg GDPR default';

-- ---------------------------------------------------------------------------
-- 3. sh_rate_limit_buckets — token-bucket per (tenant, channel_id, window)
--    Worker NotificationDispatcher aktualizuje ten bufor zamiast liczyć
--    z sh_notification_deliveries (wolne). Okno: 'hour' | 'day'
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_rate_limit_buckets (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    channel_id      INT UNSIGNED    NOT NULL,
    window_type     ENUM('hour','day') NOT NULL DEFAULT 'hour',
    window_start    DATETIME        NOT NULL COMMENT 'Początek aktualnego okna',
    tokens_used     INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bucket (tenant_id, channel_id, window_type, window_start),
    KEY idx_rl_channel_window (channel_id, window_type, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. sh_security_audit_log — niemodyfikowalny log zdarzeń bezpieczeństwa
--    INSERT only — brak UPDATE/DELETE przez aplikację.
--    event_type: webhook.hmac_fail | webhook.replay | rate_limit.exceeded |
--                gdpr.optout | gdpr.consent_change | auth.fail | ...
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_security_audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NULL     COMMENT 'NULL dla zdarzeń globalnych',
    event_type      VARCHAR(64)     NOT NULL,
    severity        ENUM('info','warn','critical') NOT NULL DEFAULT 'info',
    actor_type      VARCHAR(32)     NULL     COMMENT 'user | system | external_api | webhook',
    actor_id        VARCHAR(128)    NULL     COMMENT 'user_id lub IP (hashed)',
    resource_type   VARCHAR(32)     NULL     COMMENT 'order | channel | contact | ...',
    resource_id     VARCHAR(64)     NULL,
    details_json    LONGTEXT        NULL     CHECK (JSON_VALID(details_json) OR details_json IS NULL),
    remote_ip_hash  CHAR(64)        NULL     COMMENT 'SHA-256(IP) dla audytu bez przechowywania raw IP',
    occurred_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sal_tenant_event (tenant_id, event_type, occurred_at),
    KEY idx_sal_severity (severity, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. sh_gdpr_consent_log — historia zgód (append-only)
--    Każda zmiana sms_consent / marketing_consent logowana tu.
--    Wymagane przez RODO art. 7 ust. 1 (udowodnienie zgody).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sh_gdpr_consent_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    contact_id      BIGINT UNSIGNED NULL     COMMENT 'FK → sh_customer_contacts.id',
    phone_hash      CHAR(64)        NOT NULL COMMENT 'SHA-256(phone) — bez raw PII',
    consent_type    ENUM('sms','marketing') NOT NULL,
    granted         TINYINT(1)      NOT NULL COMMENT '1=zgoda, 0=cofnięcie',
    source          VARCHAR(32)     NOT NULL DEFAULT 'checkout'
        COMMENT 'checkout | sms_stop | api | admin | import',
    ip_hash         CHAR(64)        NULL,
    user_agent_hash CHAR(64)        NULL,
    order_id        CHAR(36)        NULL,
    occurred_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gdpr_phone (tenant_id, phone_hash, consent_type, occurred_at),
    KEY idx_gdpr_contact (tenant_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
