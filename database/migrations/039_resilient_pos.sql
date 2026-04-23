-- =============================================================================
-- SliceHub Pro — Migration 039: Resilient POS (Phase 3 · Sync foundation)
-- -----------------------------------------------------------------------------
-- Cel: infrastruktura serwerowa dla „Local-first, Cloud-synced" POS-a.
--      Rejestruje terminale POS per tenant, śledzi cursory synchronizacji
--      i loguje wszystkie ops przechodzące przez endpoint api/pos/sync.php.
--
-- Zakres:
--   1. sh_pos_terminals      — rejestr terminali POS (device_uuid, label, ua)
--   2. sh_pos_sync_cursors   — ostatnio widziany event per terminal (pull_since)
--   3. sh_pos_op_log         — audit trail wszystkich ops (dead-letter, replay)
--
-- Filozofia (patrz _docs/16_RESILIENT_POS.md):
--   - POS operuje na lokalnym IndexedDB, serwer jest repliką.
--   - Każdy op ma `op_id` (UUID v7) generowany na kliencie.
--   - `op_id` jest PRIMARY KEY → podwójne przesłanie tego samego opa → idempotentne.
--   - `status ENUM` pozwala serwerowi oznaczyć konflikty do rozstrzygnięcia.
--
-- IDEMPOTENT. Safe to re-run. Kompatybilne z MariaDB 10.4+.
-- =============================================================================

-- =============================================================================
-- 1. sh_pos_terminals — Rejestr terminali POS per tenant
-- =============================================================================
-- Każdy POS (tablet/komputer) rejestruje się przy pierwszym kontakcie z backendem
-- przez akcję `register_terminal`. `device_uuid` = UUID v7 generowany i przecho-
-- wywany w localStorage klienta (patrz PosLocalStore.getClientUuid()). Dzięki
-- temu nawet po reinstallu PWA ID jest stabilne (localStorage przeżywa uninstall).
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_pos_terminals (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NOT NULL,
  device_uuid    CHAR(36)     NOT NULL COMMENT 'UUID v7 wygenerowany przez klienta',
  label          VARCHAR(64)  NULL     COMMENT 'Przyjazna nazwa (np. "Kasa frontowa")',
  last_seen_at   DATETIME(3)  NULL,
  last_user_id   INT UNSIGNED NULL     COMMENT 'Ostatni zalogowany użytkownik',
  last_user_agent VARCHAR(255) NULL,
  last_ip        VARCHAR(45)  NULL,
  app_version    VARCHAR(32)  NULL     COMMENT 'Wersja klienta (CACHE_VERSION z sw.js)',
  ops_received   INT UNSIGNED NOT NULL DEFAULT 0,
  ops_applied    INT UNSIGNED NOT NULL DEFAULT 0,
  ops_rejected   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_device (tenant_id, device_uuid),
  KEY idx_terminal_last_seen (tenant_id, last_seen_at),
  CONSTRAINT fk_pos_terminal_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rejestr terminali POS per tenant (Resilient POS · Phase 3)';

-- =============================================================================
-- 2. sh_pos_sync_cursors — Ostatni widziany event per terminal (pull_since)
-- =============================================================================
-- Cursor to timestamp ostatniego eventu z serwera, który klient już skonsumował.
-- Przy kolejnym `pull_since` serwer zwraca eventy > cursor_ts. Przechowujemy
-- też direction='push' i 'pull' jeśli kiedyś zdecydujemy rozdzielić pushe
-- (outbox → serwer) od pull-i (delta → klient). Na razie 1 wiersz per terminal.
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_pos_sync_cursors (
  terminal_id      INT UNSIGNED NOT NULL,
  tenant_id        INT UNSIGNED NOT NULL,
  pull_cursor_ts   DATETIME(3)  NULL     COMMENT 'Ostatni event z serwera przyjęty przez klienta',
  push_cursor_ts   DATETIME(3)  NULL     COMMENT 'Ostatni op wysłany przez klienta do serwera',
  last_sync_at     DATETIME(3)  NULL,
  last_error       VARCHAR(255) NULL,
  PRIMARY KEY (terminal_id),
  KEY idx_sync_tenant (tenant_id),
  CONSTRAINT fk_pos_sync_terminal
    FOREIGN KEY (terminal_id) REFERENCES sh_pos_terminals (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pos_sync_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cursor synchronizacji per terminal (Resilient POS · Phase 3)';

-- =============================================================================
-- 3. sh_pos_op_log — Audit trail wszystkich ops przechodzących przez sync
-- =============================================================================
-- Każdy push_batch loguje się tu wiersz per op. `op_id` PRIMARY KEY gwarantuje
-- idempotencję — drugie wysłanie tego samego op_id jest no-op (INSERT IGNORE).
-- `status` mówi:
--   applied    — serwer zastosował operację, zmieniona została sh_orders etc.
--   rejected   — serwer odrzucił (np. zamówienie już anulowane), klient dostaje
--                informację, op ląduje w outboxie jako 'dead' (bez retry).
--   conflict   — serwer wykrył konflikt (np. stolik zajęty przez inny POS);
--                klient pokazuje rollback animation i pyta usera.
--   dead       — op dotarł na serwer ale nie udało się go zastosować po
--                wielokrotnych próbach (rzadkie — server-side bug/data issue).
-- =============================================================================
CREATE TABLE IF NOT EXISTS sh_pos_op_log (
  op_id        CHAR(36)     NOT NULL COMMENT 'UUID v7 — client-generated',
  terminal_id  INT UNSIGNED NOT NULL,
  tenant_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL     COMMENT 'Użytkownik POS który wykonał op',
  action       VARCHAR(64)  NOT NULL COMMENT 'Nazwa akcji API (np. process_order)',
  payload_json JSON         NOT NULL,
  status       ENUM('applied','rejected','conflict','dead') NOT NULL,
  server_ref   VARCHAR(64)  NULL     COMMENT 'Referencja do zasobu utworzonego (np. order UUID)',
  applied_at   DATETIME(3)  NULL,
  latency_ms   INT UNSIGNED NULL     COMMENT 'Od client createdAt do server applied',
  error_text   TEXT         NULL,
  client_created_at DATETIME(3) NULL COMMENT 'Kiedy klient zakolejkował op',
  created_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (op_id),
  KEY idx_oplog_terminal_status (terminal_id, status),
  KEY idx_oplog_tenant_created (tenant_id, created_at),
  KEY idx_oplog_action (action),
  KEY idx_oplog_dead (status, created_at) COMMENT 'Szybkie wyszukiwanie dead-letter',
  CONSTRAINT fk_oplog_terminal
    FOREIGN KEY (terminal_id) REFERENCES sh_pos_terminals (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_oplog_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail ops z Resilient POS (Phase 3)';

-- =============================================================================
-- Koniec migracji 039.
-- =============================================================================
