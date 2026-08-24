-- Migration 066 — Korelacja shiftu kasowego z sesją HR (idempotent)
-- sh_driver_shifts.work_session_uuid = sh_work_sessions.session_uuid (VARCHAR key,
-- bez FK cross-silo po numerycznym ID — zgodnie z _docs/18 §9.3 pkt 3).
-- SAFE TO RE-RUN.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_driver_shifts'
      AND COLUMN_NAME  = 'work_session_uuid'
);
SET @sql_col = IF(@col_exists = 0,
    'ALTER TABLE sh_driver_shifts ADD COLUMN work_session_uuid CHAR(36) NULL DEFAULT NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_driver_shifts'
      AND INDEX_NAME   = 'idx_shifts_tenant_ws_uuid'
);
SET @sql_idx = IF(@idx_exists = 0,
    'CREATE INDEX idx_shifts_tenant_ws_uuid ON sh_driver_shifts (tenant_id, work_session_uuid)',
    'SELECT 1'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
