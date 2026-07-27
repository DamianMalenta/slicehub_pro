-- =============================================================================
-- 047_order_geocoding.sql — F6 (2026-05-11)
--
-- Cel: zapisać współrzędne geograficzne adresu dostawy bezpośrednio w sh_orders
-- + globalny cache geokodowania (oszczędzanie limitu rate Nominatim).
--
-- Konstytucja v5 § Prawo II (Bliźniak Cyfrowy): dispatcher widzi PRAWDZIWĄ
-- lokalizację dostawy zamiast losowego pinu z fallbacku.
-- =============================================================================

-- 1. sh_orders: lat/lng + meta geokodowania (idempotent — guarded)
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'sh_orders' AND COLUMN_NAME = 'delivery_lat';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sh_orders
     ADD COLUMN delivery_lat       DECIMAL(10,7) NULL AFTER delivery_address,
     ADD COLUMN delivery_lng       DECIMAL(10,7) NULL AFTER delivery_lat,
     ADD COLUMN geocode_provider   VARCHAR(32)   NULL AFTER delivery_lng,
     ADD COLUMN geocode_quality    VARCHAR(16)   NULL COMMENT ''exact|approximate|cached|fallback|none'' AFTER geocode_provider,
     ADD COLUMN geocoded_at        DATETIME      NULL AFTER geocode_quality,
     ADD KEY idx_orders_geocoded (tenant_id, delivery_lat, delivery_lng)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Globalny cache geokodowania (per tenant).
-- Hash adresu po normalizacji (lowercase + trim) — żeby drobne różnice w pisowni
-- trafiały na ten sam pin.
CREATE TABLE IF NOT EXISTS sh_geocode_cache (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         INT UNSIGNED NOT NULL,
  address_hash      CHAR(40) NOT NULL COMMENT 'SHA1(normalize(address))',
  address_raw       TEXT NOT NULL,
  lat               DECIMAL(10,7) NULL,
  lng               DECIMAL(10,7) NULL,
  provider          VARCHAR(32) NOT NULL DEFAULT 'nominatim',
  quality           VARCHAR(16) NULL,
  hit_count         INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_geocache_tenant_hash (tenant_id, address_hash),
  KEY idx_geocache_tenant (tenant_id),
  CONSTRAINT fk_geocache_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
