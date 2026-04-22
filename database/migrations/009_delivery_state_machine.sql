-- =============================================================================
-- SliceHub Pro V2 — Migration 009: 3-Pillar Delivery State Machine
--
-- Pillar 1 — status (kitchen lifecycle):
--   pending | preparing | ready | completed | cancelled
--
-- Pillar 2 — payment_status (payment lifecycle):
--   to_pay | online_unpaid | cash | card | online_paid
--
-- Pillar 3 — delivery_status (delivery lifecycle):
--   NULL (non-delivery) | unassigned | in_delivery | delivered
--
-- SAFE TO RE-RUN (idempotent).
-- =============================================================================

-- 1) Add delivery_status column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_orders'
      AND COLUMN_NAME  = 'delivery_status'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sh_orders ADD COLUMN delivery_status VARCHAR(32) NULL DEFAULT NULL AFTER stop_number',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Add cancellation_reason column for driver-initiated cancels
SET @col_exists2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_orders'
      AND COLUMN_NAME  = 'cancellation_reason'
);
SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE sh_orders ADD COLUMN cancellation_reason TEXT NULL AFTER delivery_status',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3) Migrate delivery_status from status column
UPDATE sh_orders
SET    delivery_status = 'in_delivery',
       status          = 'ready'
WHERE  status = 'in_delivery'
  AND  (delivery_status IS NULL OR delivery_status = '');

UPDATE sh_orders
SET    delivery_status = 'delivered'
WHERE  status = 'completed'
  AND  order_type = 'delivery'
  AND  driver_id IS NOT NULL
  AND  (delivery_status IS NULL OR delivery_status = '');

UPDATE sh_orders
SET    delivery_status = 'unassigned'
WHERE  order_type = 'delivery'
  AND  status NOT IN ('completed', 'cancelled')
  AND  (delivery_status IS NULL OR delivery_status = '');

-- 4) Migrate payment_status values
UPDATE sh_orders SET payment_status = 'to_pay'
WHERE  payment_status = 'unpaid' AND (payment_method IS NULL OR payment_method NOT IN ('online'));

UPDATE sh_orders SET payment_status = 'online_unpaid'
WHERE  payment_status = 'unpaid' AND payment_method = 'online';

UPDATE sh_orders SET payment_status = 'cash'
WHERE  payment_status = 'paid' AND payment_method = 'cash';

UPDATE sh_orders SET payment_status = 'card'
WHERE  payment_status = 'paid' AND payment_method = 'card';

UPDATE sh_orders SET payment_status = 'online_paid'
WHERE  payment_status = 'paid' AND payment_method = 'online';

-- Catch-all: any remaining 'paid' without a recognized method
UPDATE sh_orders SET payment_status = 'cash'
WHERE  payment_status = 'paid';

-- 5) Migrate legacy 'new'/'accepted' status to 'pending'
UPDATE sh_orders SET status = 'pending'
WHERE  status IN ('new', 'accepted');

-- 6) Index for delivery queries (MariaDB: no comma between ALGORITHM and LOCK)
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'sh_orders'
      AND INDEX_NAME   = 'idx_orders_delivery_status'
);
SET @sql_idx = IF(@idx_exists = 0,
    'CREATE INDEX idx_orders_delivery_status ON sh_orders (tenant_id, delivery_status) ALGORITHM=INPLACE LOCK=NONE',
    'SELECT 1'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
