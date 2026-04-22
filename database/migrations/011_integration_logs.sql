-- ============================================================================
-- Migration 011: Integration logs for external webhook pushers (Papu.io, etc.)
-- ============================================================================

CREATE TABLE IF NOT EXISTS sh_integration_logs (
    id              BIGINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED       NOT NULL,
    order_id        CHAR(36)           NULL,
    provider        VARCHAR(32)        NOT NULL DEFAULT 'papu',
    http_code       SMALLINT UNSIGNED  NULL,
    request_payload JSON               NULL,
    response_body   TEXT               NULL,
    error_message   TEXT               NULL,
    created_at      DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intlog_tenant_order (tenant_id, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
