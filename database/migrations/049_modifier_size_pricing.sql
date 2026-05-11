-- =============================================================================
-- 049_modifier_size_pricing.sql — F-S2 (2026-05-11)
--
-- Toast-style Size Pricing dla modyfikatorów + tenant-level half-half strategy.
--
-- Cel: ten sam topping (np. SALAMI) ma różną cenę w zależności od rozmiaru
-- pizzy (mała 3 zł / duża 6 zł). Konstytucja v5 § Prawo II — fizyczny bliźniak.
--
-- Bez tej tabeli modyfikator ma jedną cenę z sh_modifiers.price (fallback).
-- =============================================================================

-- 1. Cennik modyfikator × wariant — jedna cena per (modifier, variant_option).
CREATE TABLE IF NOT EXISTS sh_modifier_pricing (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         INT UNSIGNED NOT NULL,
    modifier_id       BIGINT UNSIGNED NOT NULL,
    variant_option_id BIGINT UNSIGNED NOT NULL,
    price_grosze      INT NOT NULL DEFAULT 0 COMMENT 'Cena modyfikatora w groszach dla tego rozmiaru',
    is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mod_pricing (modifier_id, variant_option_id),
    KEY idx_mp_tenant (tenant_id, modifier_id),
    CONSTRAINT fk_mp_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mp_modifier
        FOREIGN KEY (modifier_id) REFERENCES sh_modifiers (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mp_variant_option
        FOREIGN KEY (variant_option_id) REFERENCES sh_variant_scale_options (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tenant settings dla half-half pricing strategy (key-value).
-- Wzorzec: sh_tenant_settings(tenant_id, setting_key, setting_value).
-- Seed strategii default ('percentage' 50%).
INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT id, 'half_pricing_strategy', 'percentage' FROM sh_tenant
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT id, 'half_pricing_percent', '50' FROM sh_tenant
ON DUPLICATE KEY UPDATE setting_key = setting_key;
