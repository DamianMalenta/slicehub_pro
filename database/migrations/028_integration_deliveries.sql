-- =============================================================================
-- Migration 028 — Integration Deliveries (async 3rd-party adapter log)
-- -----------------------------------------------------------------------------
-- Cel: per-(event, integration) state tracking dla adapterów 3rd-party POS
-- (Papu, Dotykacka, GastroSoft, ...). Analog do sh_webhook_deliveries, ale
-- dla CONCRETE PROVIDER ADAPTERS (nie generycznych webhooków).
--
-- Architektura:
--   sh_event_outbox (m026)  — wspólne źródło prawdy dla wszystkich konsumentów
--     ├─► worker_webhooks.php    → sh_webhook_deliveries    (m026)
--     └─► worker_integrations.php → sh_integration_deliveries (m028)  ← TA MIGRACJA
--
-- Ważne: oba workery są niezależne. Integration worker **nie modyfikuje**
-- `sh_event_outbox.status` — webhook worker zarządza tym polem. Integration
-- worker trzyma własny retry cycle w `sh_integration_deliveries.status`.
--
-- Zgodność wsteczna: legacy sh_integration_logs (autocreated przez PapuClient
-- fire-and-forget) zachowujemy — nowe adaptery piszą do sh_integration_deliveries,
-- stare miejsca (POS finalize fire-and-forget) nadal mogą pisać do logs.
-- =============================================================================

SET NAMES utf8mb4;

-- ── sh_integration_deliveries ─────────────────────────────────────────────
-- Per (event_id, integration_id) state: jedna próba dostawy = jeden rekord
-- w oddzielnej tabeli attempts? NIE — dla prostoty trzymamy jeden rekord
-- per integration per event z `attempts` counterem. Historia prób w
-- `sh_integration_attempts` (below).
CREATE TABLE IF NOT EXISTS sh_integration_deliveries (
    id                BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    tenant_id         INT UNSIGNED         NOT NULL,
    event_id          BIGINT UNSIGNED      NOT NULL
        COMMENT 'FK → sh_event_outbox.id',
    integration_id    INT UNSIGNED         NOT NULL
        COMMENT 'FK → sh_tenant_integrations.id',
    provider          VARCHAR(32)          NOT NULL
        COMMENT 'Denormalized (papu|dotykacka|gastrosoft|custom) dla szybkich queries/filtrów',
    aggregate_id      VARCHAR(64)          NOT NULL
        COMMENT 'Denormalized order UUID dla debugowania (join-free read)',
    event_type        VARCHAR(64)          NOT NULL
        COMMENT 'Denormalized (order.created, order.accepted, ...)',
    status            ENUM('pending','delivering','delivered','failed','dead')
                                           NOT NULL DEFAULT 'pending',
    attempts          TINYINT UNSIGNED     NOT NULL DEFAULT 0,
    next_attempt_at   DATETIME             NULL
        COMMENT 'Kiedy spróbować ponownie (exponential backoff)',
    last_error        TEXT                 NULL,
    http_code         SMALLINT UNSIGNED    NULL
        COMMENT 'Ostatni HTTP code z 3rd-party (debug)',
    duration_ms       INT UNSIGNED         NULL,
    external_ref      VARCHAR(128)         NULL
        COMMENT 'ID zamówienia po stronie 3rd-party (zwrócone przy success) np. Papu.io order_id',
    request_payload   JSON                 NULL
        COMMENT 'Snapshot ostatniego request payloadu (per-adapter shape, NIE nasz envelope) — debug',
    response_body     TEXT                 NULL
        COMMENT 'Truncated do 2KB dla debugowania',
    created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempted_at DATETIME             NULL,
    completed_at      DATETIME             NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_intdev_event_integration (event_id, integration_id)
        COMMENT 'Jeden rekord per event × integration (idempotency)',
    KEY idx_intdev_pending (status, next_attempt_at, id)
        COMMENT 'Worker query: WHERE status=pending AND (next_attempt_at IS NULL OR <= NOW())',
    KEY idx_intdev_tenant_provider (tenant_id, provider, created_at),
    KEY idx_intdev_aggregate (aggregate_id, event_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-(event,integration) delivery state — async 3rd-party adapters (m028)';

-- ── sh_integration_attempts ──────────────────────────────────────────────
-- Full historia prób dostawy — jeden rekord per HTTP request (sukces lub porażka).
-- `sh_integration_deliveries` trzyma "current state", `sh_integration_attempts`
-- trzyma "full timeline" (dla debugowania flaky adapterów).
CREATE TABLE IF NOT EXISTS sh_integration_attempts (
    id              BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    delivery_id     BIGINT UNSIGNED      NOT NULL
        COMMENT 'FK → sh_integration_deliveries.id',
    attempt_number  TINYINT UNSIGNED     NOT NULL DEFAULT 1,
    http_code       SMALLINT UNSIGNED    NULL,
    duration_ms     INT UNSIGNED         NULL,
    request_snippet VARCHAR(500)         NULL
        COMMENT 'First 500 chars of request body (full snapshot w parent row)',
    response_body   TEXT                 NULL
        COMMENT 'Truncated 2KB',
    error_message   TEXT                 NULL,
    attempted_at    DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intattempt_delivery (delivery_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-attempt audit log (m028)';

-- ── Rozszerzenia sh_tenant_integrations ──────────────────────────────────
-- Dodaj kolumny pomocnicze dla workera (healthcheck + auto-pause analog do webhooks).
SET @col_consec_failures := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sh_tenant_integrations'
      AND COLUMN_NAME = 'consecutive_failures'
);
SET @sql := IF(
    @col_consec_failures = 0,
    'ALTER TABLE sh_tenant_integrations
       ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0
         COMMENT ''Auto-pause przy consecutive_failures >= max_retries (m028)''
         AFTER last_sync_at,
       ADD COLUMN last_failure_at DATETIME NULL AFTER consecutive_failures,
       ADD COLUMN max_retries TINYINT UNSIGNED NOT NULL DEFAULT 6
         AFTER last_failure_at,
       ADD COLUMN timeout_seconds TINYINT UNSIGNED NOT NULL DEFAULT 8
         COMMENT ''HTTP timeout dla adaptera (wyższy niż webhooki — 3rd-party POS bywa wolne)''
         AFTER max_retries',
    'SELECT ''sh_tenant_integrations health columns already present'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Migration marker ─────────────────────────────────────────────────────
SELECT 'm028 integration_deliveries applied: sh_integration_deliveries, sh_integration_attempts, sh_tenant_integrations+health' AS migration_028;
