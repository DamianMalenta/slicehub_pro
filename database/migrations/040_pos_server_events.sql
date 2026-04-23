-- =============================================================================
-- SliceHub Pro — Migration 040: Resilient POS (Phase 3.5 · Server → Client stream)
-- -----------------------------------------------------------------------------
-- Cel: append-only log eventów serwerowych, które mają dojść do każdego POS-a
--      w obrębie tenantu. Klient pobiera eventy > pull_cursor_ts przez
--      sync.php action=pull_since i aktualizuje swój lokalny stan.
--
-- Źródła eventów (dopinane stopniowo):
--   - storefront order placed       → 'order.created'       (payload: order snapshot)
--   - KDS status change              → 'order.status'        (payload: id+status)
--   - Admin cennik change            → 'menu.updated'        (payload: delta lub pełny refresh flag)
--   - Inny POS zmienił rezerwację    → 'table.reserved'      (payload: table id + status)
--   - Ręczny broadcast z sync.php    → 'system.test'         (payload: dowolny — do smoke testów)
--
-- Retention: 7 dni (starsze eventy czyszczone przez gc w sync.php / cron). POS,
--            który był offline >7 dni, powinien zrobić pełny reload menu+orders
--            zamiast odtwarzać zdarzenia.
--
-- IDEMPOTENT. Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sh_pos_server_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED    NOT NULL,
  event_type   VARCHAR(48)     NOT NULL COMMENT 'order.created, order.status, menu.updated, table.reserved, system.test, ...',
  entity_type  VARCHAR(32)     NULL     COMMENT 'order, menu, table, user — ułatwia filtrowanie po stronie klienta',
  entity_id    VARCHAR(64)     NULL     COMMENT 'ID zasobu którego dotyczy event',
  payload_json JSON            NOT NULL,
  origin_kind  VARCHAR(24)     NULL     COMMENT 'storefront, kds, admin, pos, system',
  origin_ref   VARCHAR(64)     NULL     COMMENT 'terminal_id (gdy from pos), user_id (gdy admin), itp.',
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_pse_tenant_created (tenant_id, created_at),
  KEY idx_pse_tenant_type    (tenant_id, event_type, created_at),
  KEY idx_pse_entity         (tenant_id, entity_type, entity_id),
  KEY idx_pse_created_gc     (created_at) COMMENT 'GC pomocniczy index',
  CONSTRAINT fk_pse_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only stream eventów server → POS (Resilient POS · Phase 3.5)';

-- =============================================================================
-- sh_pos_sync_cursors — rozszerzenie: dodaj liczniki pull dla diagnostyki.
-- Używamy ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
-- =============================================================================
ALTER TABLE sh_pos_sync_cursors
  ADD COLUMN IF NOT EXISTS pull_events_total   INT UNSIGNED NOT NULL DEFAULT 0 AFTER pull_cursor_ts,
  ADD COLUMN IF NOT EXISTS pull_last_count     INT UNSIGNED NOT NULL DEFAULT 0 AFTER pull_events_total,
  ADD COLUMN IF NOT EXISTS pull_last_fetched_at DATETIME(3) NULL            AFTER pull_last_count;

-- =============================================================================
-- Koniec migracji 040.
-- =============================================================================
