-- =============================================================================
-- Migration 029 — Settings Audit + Inbound Callback Tracking (Faza 7 · sesja 7.6)
-- -----------------------------------------------------------------------------
-- Cel: zamknięcie warstwy integracji event-driven:
--   1. `sh_settings_audit`        — trail zmian konfiguracji (GDPR/compliance)
--   2. `sh_inbound_callbacks`     — log przychodzących callbacków od 3rd-party
--
-- Dotyczy workflow:
--   • Admin edytuje credentials/webhook/api_key w Settings Panel → AUDIT zapisuje
--     przed/po + user_id + IP + action.
--   • 3rd-party (Papu, Dotykacka, ...) puszcza nam POST z status-updatem →
--     `api/integrations/inbound.php` zapisuje RAW request, weryfikuje signature,
--     mapuje na wewnętrzny status, publisher bumpuje `sh_event_outbox`.
-- =============================================================================

SET NAMES utf8mb4;

-- ── 1. sh_settings_audit ────────────────────────────────────────────────────
-- Each mutation in api/settings/engine.php writes one row here. Raw diffs
-- (before/after JSON) for GDPR, compliance audits, and "who broke the
-- integration" debugging.
CREATE TABLE IF NOT EXISTS sh_settings_audit (
    id              BIGINT UNSIGNED         NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED            NOT NULL,
    user_id         BIGINT UNSIGNED         NULL
        COMMENT 'sh_users.id — NULL tylko dla system/cron mutations',
    actor_ip        VARCHAR(45)             NULL
        COMMENT 'IPv4 / IPv6 remote addr',
    action          VARCHAR(48)             NOT NULL
        COMMENT 'integrations_save, webhooks_delete, api_keys_generate, api_keys_revoke, ...',
    entity_type     VARCHAR(32)             NOT NULL
        COMMENT 'integration | webhook | api_key | dlq | other',
    entity_id       BIGINT UNSIGNED         NULL
        COMMENT 'id rekordu (po insercie / przed delete)',
    before_json     JSON                    NULL
        COMMENT 'Snapshot rekordu PRZED mutacją (NULL dla create). Credentials/secrets zawsze redacted (••••).',
    after_json      JSON                    NULL
        COMMENT 'Snapshot rekordu PO mutacji (NULL dla delete). Credentials/secrets zawsze redacted.',
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_tenant_time (tenant_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for Settings Panel mutations (m029)';

-- ── 2. sh_inbound_callbacks ─────────────────────────────────────────────────
-- Surowy dziennik przychodzących callbacków od 3rd-party POS/delivery systems.
-- Architektura inbound flow:
--   POST /api/integrations/inbound.php?provider=papu&integration_id=N
--     ↓ 1. INSERT sh_inbound_callbacks (raw_request, signature, received_at) status='pending'
--     ↓ 2. AdapterRegistry::resolve(provider) → adapter.parseInboundCallback($rawBody, $headers, $credentials)
--     ↓ 3. Verify signature, parse → {aggregate_id, event_type, status, external_ref, payload}
--     ↓ 4. UPDATE sh_orders SET status=new_status WHERE gateway_external_id=external_ref
--     ↓ 5. OrderEventPublisher::publish(new status → sh_event_outbox — dla KDS/Driver/Notif)
--     ↓ 6. UPDATE sh_inbound_callbacks SET status='processed', processed_at=NOW()
CREATE TABLE IF NOT EXISTS sh_inbound_callbacks (
    id                  BIGINT UNSIGNED       NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED          NULL
        COMMENT 'Resolved po weryfikacji integration_id; może być NULL gdy request trafił z bad credentials',
    integration_id      INT UNSIGNED          NULL
        COMMENT 'FK do sh_tenant_integrations — NULL gdy nie udało się rozpoznać',
    provider            VARCHAR(32)           NOT NULL
        COMMENT 'papu | dotykacka | gastrosoft | uber | glovo | pyszne | wolt | custom',
    external_event_id   VARCHAR(128)          NULL
        COMMENT 'ID eventu po stronie 3rd-party (dla idempotency)',
    external_ref        VARCHAR(128)          NULL
        COMMENT 'ID zamówienia 3rd-party (np. Papu order_id)',
    event_type          VARCHAR(64)           NULL
        COMMENT 'Rozpoznany typ: order.status_update | order.cancelled | driver.assigned | ...',
    mapped_order_id     BIGINT UNSIGNED       NULL
        COMMENT 'sh_orders.id po matchingu external_ref → gateway_external_id',
    raw_headers         JSON                  NULL
        COMMENT 'Wybrane headery (Content-Type, X-Papu-Signature, User-Agent, X-Forwarded-For)',
    raw_body            MEDIUMTEXT            NULL
        COMMENT 'Pierwsze 64KB body — debug; powyżej TRUNCATED',
    signature_verified  TINYINT(1)            NOT NULL DEFAULT 0
        COMMENT '1 = adapter potwierdził HMAC/OAuth signature',
    status              ENUM('pending','processed','rejected','ignored','error')
                                              NOT NULL DEFAULT 'pending',
    error_message       TEXT                  NULL,
    remote_ip           VARCHAR(45)           NULL,
    received_at         DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at        DATETIME              NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_inbound_idempotency (provider, external_event_id)
        COMMENT 'Zapobiega double-processing gdy provider robi retry',
    KEY idx_inbound_tenant (tenant_id, received_at),
    KEY idx_inbound_provider (provider, received_at),
    KEY idx_inbound_ref (external_ref, provider),
    KEY idx_inbound_status (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Inbound 3rd-party webhook callbacks (m029)';

-- ── Migration marker ────────────────────────────────────────────────────────
SELECT 'm029 infrastructure_completion applied: sh_settings_audit, sh_inbound_callbacks' AS migration_029;
