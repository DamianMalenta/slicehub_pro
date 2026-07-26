-- =============================================================================
-- 060_print_decks.sql — Wachlarz / ulotki A5 (marketing print decks)
-- 2026-07-20
--
-- Kuratorowane karty drukowane (A5, montaż na śrubie). Nie zastępują menu POS.
-- Most do menu wyłącznie przez ascii_key w payload_json (silos sh_).
-- IDEMPOTENT.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sh_print_decks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    INT UNSIGNED NOT NULL,
    ascii_key    VARCHAR(128) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                 COMMENT 'Stabilny klucz per-tenant, np. FORNO_WACHLARZ_V1',
    name         VARCHAR(191) NOT NULL,
    status       VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'draft'
                 COMMENT 'draft | ready | archived',
    brand        VARCHAR(128) NULL,
    notes        TEXT NULL,
    is_deleted   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_print_deck_tenant_key (tenant_id, ascii_key),
    KEY idx_print_deck_tenant_status (tenant_id, is_deleted, status),
    CONSTRAINT fk_print_deck_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sh_print_deck_cards (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    INT UNSIGNED NOT NULL,
    deck_id      BIGINT UNSIGNED NOT NULL,
    card_key     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                 COMMENT 'Stabilny klucz w decku, np. kebab-killer',
    card_type    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                 COMMENT 'cover | hero_duo | hero_sizes | hero_list | cta',
    sort_order   INT NOT NULL DEFAULT 0,
    payload_json JSON NOT NULL COMMENT 'title, price, tab_label, variants, ascii_key…',
    is_deleted   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_print_card_deck_key (deck_id, card_key),
    KEY idx_print_card_deck_sort (deck_id, is_deleted, sort_order),
    KEY idx_print_card_tenant (tenant_id),
    CONSTRAINT fk_print_card_deck
        FOREIGN KEY (deck_id) REFERENCES sh_print_decks (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_print_card_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
