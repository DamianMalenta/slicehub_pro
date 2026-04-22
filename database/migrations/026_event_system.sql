-- =============================================================================
-- Migration 026 — Event System (Transactional Outbox + Webhooks + Integrations)
-- -----------------------------------------------------------------------------
-- Cel: odsprzężenie modułów (POS ↔ KDS ↔ Delivery ↔ Courses ↔ Online ↔ 3rd-party)
-- od sztywnych zapisów do `sh_orders`. Moduły publikują eventy (transactional
-- outbox pattern), a inne moduły / webhook worker / integration adapters
-- konsumują je asynchronicznie.
--
-- Architektura:
--   [POS/Online/Gateway]
--          │
--          ▼ (atomic w tej samej transakcji co INSERT do sh_orders)
--   sh_event_outbox (status='pending')
--          │
--          ├──► [WebhookDispatcher cron] ──► sh_webhook_endpoints → sh_webhook_deliveries
--          │                                     │
--          │                                     ▼
--          │                             3rd-party POS (Papu, Dotykacka, ...)
--          │
--          └──► [Internal consumers: driver_app notifications, KDS refresh, analytics]
--
-- Gwarancje:
--   • AT LEAST ONCE delivery — retry z exponential backoff + idempotency key
--   • FIFO per order_id — worker bierze pending po created_at
--   • Izolacja awarii — failure 3rd-party nigdy nie blokuje transakcji POS
--
-- Powiązane:
--   • core/OrderEventPublisher.php — sink dla publisherów
--   • core/Integrations/*        — adaptery 3rd-party (PapuClient.php już jest)
--   • scripts/worker_webhooks.php — cron-based dispatcher (Sesja 7.3)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. sh_event_outbox ──────────────────────────────────────────────────────
-- Transactional outbox — zapis w tej samej transakcji co sh_orders.
-- Worker cron ciągnie rekordy z status='pending' FIFO.
CREATE TABLE IF NOT EXISTS sh_event_outbox (
    id               BIGINT UNSIGNED       NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED          NOT NULL,
    event_type       VARCHAR(64)           NOT NULL
        COMMENT 'Kanoniczne: order.created, order.accepted, order.preparing, order.ready, order.dispatched, order.in_delivery, order.delivered, order.completed, order.cancelled, order.edited, order.recalled',
    aggregate_type   VARCHAR(32)           NOT NULL DEFAULT 'order'
        COMMENT 'order | payment | shift | driver — enum dla bramek routingu',
    aggregate_id     VARCHAR(64)           NOT NULL
        COMMENT 'UUID zamówienia (dla order.*) lub ID innego aggregatu',
    idempotency_key  VARCHAR(128)          NULL
        COMMENT 'Klucz anti-duplicate — np. {aggregate_id}:{event_type}:{status_transition}. Unikalny per tenant.',
    payload          JSON                  NOT NULL
        COMMENT 'Snapshot danych eventu (order header + lines + context)',
    source           VARCHAR(32)           NOT NULL DEFAULT 'internal'
        COMMENT 'online | pos | kiosk | gateway | kds | delivery | courses | admin',
    actor_type       VARCHAR(24)           NULL
        COMMENT 'guest | staff | system | external_api',
    actor_id         VARCHAR(64)           NULL
        COMMENT 'user_id / api_key_hash / null dla system',
    status           ENUM('pending','dispatching','delivered','failed','dead')
                                           NOT NULL DEFAULT 'pending',
    attempts         TINYINT UNSIGNED      NOT NULL DEFAULT 0,
    next_attempt_at  DATETIME              NULL
        COMMENT 'Kiedy worker ma spróbować ponownie (exponential backoff)',
    last_error       TEXT                  NULL,
    created_at       DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_at    DATETIME              NULL,
    completed_at     DATETIME              NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_event_idempotency (tenant_id, idempotency_key),
    KEY idx_event_pending (status, next_attempt_at, id)
        COMMENT 'Worker query: WHERE status=pending AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id',
    KEY idx_event_aggregate (tenant_id, aggregate_type, aggregate_id, created_at),
    KEY idx_event_type (tenant_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Transactional outbox — lifecycle events (m026)';

-- ── 2. sh_webhook_endpoints ────────────────────────────────────────────────
-- Konfiguracja webhooków per tenant. Jeden tenant może mieć N endpointów
-- (np. Papu + własny system analityczny + Slack).
CREATE TABLE IF NOT EXISTS sh_webhook_endpoints (
    id                   INT UNSIGNED           NOT NULL AUTO_INCREMENT,
    tenant_id            INT UNSIGNED           NOT NULL,
    name                 VARCHAR(128)           NOT NULL
        COMMENT 'Human-readable label, np. "Papu sync" / "Analytics firehose"',
    url                  VARCHAR(512)           NOT NULL,
    secret               VARCHAR(128)           NOT NULL
        COMMENT 'HMAC-SHA256 signing secret — header X-Slicehub-Signature',
    events_subscribed    JSON                   NOT NULL
        COMMENT 'Lista event_type np. ["order.created","order.ready"]. ["*"] = wszystkie.',
    is_active            TINYINT(1)             NOT NULL DEFAULT 1,
    max_retries          TINYINT UNSIGNED       NOT NULL DEFAULT 5,
    timeout_seconds      TINYINT UNSIGNED       NOT NULL DEFAULT 5,
    last_success_at      DATETIME               NULL,
    last_failure_at      DATETIME               NULL,
    consecutive_failures INT UNSIGNED           NOT NULL DEFAULT 0
        COMMENT 'Gdy >= max_retries → endpoint auto-paused (is_active=0 z decyzją managera)',
    created_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_endpoint_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Webhook subscribers (m026)';

-- ── 3. sh_webhook_deliveries ───────────────────────────────────────────────
-- Historia prób dostawy. Jeden event może mieć N rekordów (po 1 na endpoint × próba).
CREATE TABLE IF NOT EXISTS sh_webhook_deliveries (
    id              BIGINT UNSIGNED         NOT NULL AUTO_INCREMENT,
    event_id        BIGINT UNSIGNED         NOT NULL,
    endpoint_id     INT UNSIGNED            NOT NULL,
    attempt_number  TINYINT UNSIGNED        NOT NULL DEFAULT 1,
    http_code       SMALLINT UNSIGNED       NULL,
    response_body   TEXT                    NULL
        COMMENT 'Limited to first 2000 chars — debug tylko',
    error_message   TEXT                    NULL,
    duration_ms     INT UNSIGNED            NULL,
    attempted_at    DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_delivery_event (event_id),
    KEY idx_delivery_endpoint (endpoint_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Webhook delivery log (m026)';

-- ── 4. sh_tenant_integrations ──────────────────────────────────────────────
-- Konfiguracja adapterów 3rd-party (Papu, Dotykacka, GastroSoft, ...) per tenant.
-- Integration registry — jeden tenant może spiąć N zewnętrznych systemów.
CREATE TABLE IF NOT EXISTS sh_tenant_integrations (
    id             INT UNSIGNED         NOT NULL AUTO_INCREMENT,
    tenant_id      INT UNSIGNED         NOT NULL,
    provider       VARCHAR(32)          NOT NULL
        COMMENT 'papu | dotykacka | gastrosoft | custom | webhook — determinuje który adapter ładowany',
    display_name   VARCHAR(128)         NOT NULL,
    api_base_url   VARCHAR(512)         NULL,
    credentials    JSON                 NULL
        COMMENT 'api_key, tokens, tenant_ext_id — zaszyfrowane (TODO: AES-256 at-rest w fazie 7.4)',
    direction      ENUM('push','pull','bidirectional') NOT NULL DEFAULT 'push'
        COMMENT 'push = SliceHub → 3rd-party | pull = scrape orders | bidirectional',
    events_bridged JSON                 NULL
        COMMENT 'Whitelist event_types przekazywanych do tego providera',
    is_active      TINYINT(1)           NOT NULL DEFAULT 1,
    last_sync_at   DATETIME             NULL,
    created_at     DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_integration_tenant_provider (tenant_id, provider),
    KEY idx_integration_active (is_active, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='3rd-party POS / ERP adapters registry (m026)';

SET FOREIGN_KEY_CHECKS = 1;

-- ── Migration marker ────────────────────────────────────────────────────────
SELECT 'm026 event_system applied: sh_event_outbox, sh_webhook_endpoints, sh_webhook_deliveries, sh_tenant_integrations' AS migration_026;
