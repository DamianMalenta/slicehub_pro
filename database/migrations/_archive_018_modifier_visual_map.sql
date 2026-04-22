-- =============================================================================
-- Migration 018 — Magic Dictionary (modifier ↔ visual asset)
-- =============================================================================
-- Łączy modyfikator (sh_modifiers.ascii_key) z warstwą z biblioteki
-- (sh_global_assets.ascii_key). Używane przez storefront do dynamicznego
-- dodawania warstwy na pizzę, gdy klient klika modyfikator w Surface Card.
--
-- Dane są per-tenant — jedno mapowanie per (tenant_id, modifier_sku).
-- Dozwolone jest mapowanie jednego modyfikatora na wiele assetów tylko gdy
-- rozróżnimy typ wizualizacji:
--   • scatter  → rozrzucone kawałki na pizzy (standard, np. salami, oliwki)
--   • cluster  → skoncentrowane w punkcie (np. rukola "kopczyk")
--   • hero     → duże zdjęcie produktu obok pizzy (x2 → hero w koszyku companions)
--   • garnish  → dekoracja na wierzchu (bazylia, oregano)
--
-- Wersjonowanie: kolumna updated_at pozwala na optimistic-lock jeśli kiedyś
-- edytujemy mapping kolejnie z wielu sesji.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sh_modifier_visual_map (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED NOT NULL,
    modifier_sku     VARCHAR(255)  NOT NULL COMMENT 'sh_modifiers.ascii_key',
    asset_ascii_key  VARCHAR(255)  NOT NULL COMMENT 'sh_global_assets.ascii_key',
    visual_kind      ENUM('scatter','cluster','hero','garnish') NOT NULL DEFAULT 'scatter',
    default_scale    DECIMAL(4,2)  NOT NULL DEFAULT 1.00,
    default_rotate   SMALLINT      NOT NULL DEFAULT 0,
    default_z_index  SMALLINT UNSIGNED NOT NULL DEFAULT 40,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    notes            VARCHAR(255)  NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_magic_per_tenant (tenant_id, modifier_sku, visual_kind),
    KEY idx_magic_tenant_active  (tenant_id, is_active),
    KEY idx_magic_asset          (tenant_id, asset_ascii_key),
    CONSTRAINT fk_magic_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Magic Dictionary — maps modifier ascii_key to visual layer asset (per tenant).';
