-- Migration 065 — Composite index for Staff Fleet Presence queries (idempotent)
-- POS/Dispatch filtrują flotę przez WHERE tenant_id = ? AND last_seen >= NOW() - INTERVAL ...
-- (core/StaffFleetPresence.php). Bez indeksu skan po idx_users_tenant.
-- SAFE TO RE-RUN.

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_users'
      AND INDEX_NAME   = 'idx_users_tenant_last_seen'
);
SET @sql_idx = IF(@idx_exists = 0,
    'CREATE INDEX idx_users_tenant_last_seen ON sh_users (tenant_id, last_seen) ALGORITHM=INPLACE LOCK=NONE',
    'SELECT 1'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
