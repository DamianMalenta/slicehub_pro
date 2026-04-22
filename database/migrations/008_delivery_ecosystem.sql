-- =============================================================================
-- SliceHub Pro V2 — Migration 008: Delivery Ecosystem Enhancement
-- Adds real-time driver location tracking for map-based dispatching.
-- Depends on: 001_init_slicehub_pro_v2.sql (sh_drivers, sh_users, sh_tenant)
-- =============================================================================

USE slicehub_pro_v2;

CREATE TABLE IF NOT EXISTS sh_driver_locations (
  driver_id   BIGINT UNSIGNED NOT NULL,
  tenant_id   INT UNSIGNED NOT NULL,
  lat         DECIMAL(10,7) NOT NULL,
  lng         DECIMAL(10,7) NOT NULL,
  heading     SMALLINT NULL COMMENT 'Degrees 0-359',
  speed_kmh   DECIMAL(5,1) NULL,
  accuracy_m  DECIMAL(6,1) NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, driver_id),
  CONSTRAINT fk_drivloc_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_drivloc_user
    FOREIGN KEY (driver_id) REFERENCES sh_users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add notes column to sh_orders if not present (for driver-visible notes)
-- Safe: IF NOT EXISTS via information_schema check handled by setup_database.php
