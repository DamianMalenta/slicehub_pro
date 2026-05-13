-- =============================================================================
-- 050_meal_packages.sql — F-S3 (2026-05-11)
--
-- Combo / Bundle / Meal Packages (Petpooja/Slice-style).
--
-- UWAGA: `sh_meals` w bazie to HR (posiłki pracownicze, migracja 001). Tutaj
-- używamy NOWEJ nazwy `sh_meal_packages` żeby zachować izolację domen.
--
-- Konstytucja v5 § Prawo II — bliźniak biznesowy: combo to sprzedażowy bundle,
-- nie HR posiłek pracowniczy.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sh_meal_packages (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            INT UNSIGNED NOT NULL,
    ascii_key            VARCHAR(128) NOT NULL COMMENT 'Klucz znakowy SKU, np. FAMILY_DEAL',
    name                 VARCHAR(255) NOT NULL,
    description          TEXT NULL,
    category_id          BIGINT UNSIGNED NULL COMMENT 'Opcjonalna kategoria w menu',
    type                 ENUM('fixed','choice') NOT NULL DEFAULT 'fixed'
                              COMMENT 'fixed = stały bundle; choice = klient wybiera z listy',
    final_price_grosze   INT NULL COMMENT 'NULL = suma cen składników (z discount_percent)',
    discount_percent     DECIMAL(5,2) NULL COMMENT 'Rabat liczony od sumy składników (gdy final_price NULL)',
    image_url            VARCHAR(512) NULL,
    publication_status   VARCHAR(32)  NULL DEFAULT 'Draft',
    valid_from           DATETIME NULL,
    valid_to             DATETIME NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    is_deleted           TINYINT(1) NOT NULL DEFAULT 0,
    display_order        INT NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meal_tenant_key (tenant_id, ascii_key),
    KEY idx_meal_tenant_active (tenant_id, is_deleted, is_active),
    CONSTRAINT fk_meal_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_meal_category
        FOREIGN KEY (category_id) REFERENCES sh_categories (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sh_meal_components (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    meal_id           BIGINT UNSIGNED NOT NULL,
    tenant_id         INT UNSIGNED NOT NULL,
    component_type    ENUM('fixed_item','category_choice') NOT NULL
                          COMMENT 'fixed_item = konkretny item; category_choice = wybór z kategorii',
    item_sku          VARCHAR(255) NULL COMMENT 'Ascii_key dla fixed_item (mostek do sh_menu_items)',
    category_id       BIGINT UNSIGNED NULL COMMENT 'FK kategorii dla category_choice',
    qty               INT NOT NULL DEFAULT 1 COMMENT 'Ilość sztuk w combo',
    allow_upgrade     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = klient może zamienić na droższy z dopłatą',
    surcharge_grosze  INT NOT NULL DEFAULT 0 COMMENT 'Dopłata gdy klient wybiera droższy upgrade',
    display_order     INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meal_comp_meal (meal_id, display_order),
    KEY idx_meal_comp_tenant (tenant_id),
    KEY idx_meal_comp_item_sku (item_sku),
    CONSTRAINT fk_meal_comp_meal
        FOREIGN KEY (meal_id) REFERENCES sh_meal_packages (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_meal_comp_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_meal_comp_category
        FOREIGN KEY (category_id) REFERENCES sh_categories (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
