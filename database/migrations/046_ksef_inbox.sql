-- =============================================================================
-- SliceHub Pro — Migration 046: KSeF Inbox (Procurement)
-- -----------------------------------------------------------------------------
-- Spec: _docs/sessions/2026-05-11_phase_f3_procurement_inbox.md
-- Wizja: docelowo F4 — `worker_ksef_inbox.php` polluje API KSeF i zapisuje tutaj.
-- W F3 (MVP): user dragguje FA(2) XML pliki przez UI Procurement Inbox,
--             parser zapisuje do tych tabel, AutoScan match-uje linie, manager
--             akceptuje → utworzenie PZ przez PzEngine (auto-learn ALIAS).
--
-- Architektura (Konstytucja v5 § Prawo IX — Klocki Lego + § Prawo II):
--   Faktury KSeF żyją w osobnym silosie logicznym (sh_ksef_*) zanim trafią
--   do magazynu jako PZ. Pozwala to przechowywać oryginalne XML
--   (compliance: 5 lat archiwizacji), parsować na lekkie struktury, robić
--   AutoScan matching per linia, zatwierdzać/odrzucać bez naruszania
--   integralności wh_documents.
--
-- IDEMPOTENT. MariaDB 10.4+ / MySQL 8+.
-- =============================================================================

SET NAMES utf8mb4;

-- ── 1. sh_ksef_invoices — header faktury KSeF (raw + parsed metadata) ───────
CREATE TABLE IF NOT EXISTS sh_ksef_invoices (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               INT UNSIGNED NOT NULL,
    ksef_reference_id       VARCHAR(64) NULL
        COMMENT 'External ID z KSeF API (NULL dla manual upload w F3)',

    -- Dostawca (Podmiot1 z FA(2))
    supplier_nip            VARCHAR(15) NULL,
    supplier_name           VARCHAR(255) NULL,
    supplier_address        VARCHAR(500) NULL,

    -- Nabywca (Podmiot2 — powinien == sh_tenant.nip; walidacja przy upload)
    buyer_nip               VARCHAR(15) NULL,
    buyer_name              VARCHAR(255) NULL,

    -- Numer i daty
    invoice_number          VARCHAR(128) NOT NULL,
    issue_date              DATE NULL,
    sale_date               DATE NULL,
    payment_due_date        DATE NULL,
    currency                VARCHAR(3) NOT NULL DEFAULT 'PLN',

    -- Sumy (w PLN, integer grosze jak orders/payments per § 4 BAZA_DANYCH)
    total_net_minor         BIGINT NULL COMMENT 'NET w groszach',
    total_vat_minor         BIGINT NULL COMMENT 'VAT w groszach',
    total_gross_minor       BIGINT NULL COMMENT 'BRUTTO w groszach',

    -- Raw XML (do compliance, archiwum 5 lat) + parsed JSON dla quick read
    xml_blob                LONGTEXT NULL COMMENT 'Pełny FA(2) XML (kompresja innodb_compression jak włączona)',
    parsed_json             JSON NULL COMMENT 'Snapshot parsed result (Parser.php result)',

    -- Status workflow
    status                  VARCHAR(32) NOT NULL DEFAULT 'new'
        COMMENT 'new | parsing | draft | accepted | rejected | error',
    status_message          TEXT NULL,
    linked_wh_document_id   BIGINT UNSIGNED NULL
        COMMENT 'FK → wh_documents.id (gdy status=accepted i utworzono PZ)',
    rejected_reason         VARCHAR(500) NULL,

    -- Audit
    fetched_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Kiedy faktura trafiła do inbox-a (manual upload albo KSeF API poll)',
    processed_at            DATETIME NULL
        COMMENT 'Kiedy zaakceptowana/odrzucona',
    processed_by_user_id    BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ksef_ref (tenant_id, ksef_reference_id),
    KEY idx_ksef_tenant_status (tenant_id, status),
    KEY idx_ksef_supplier_nip (tenant_id, supplier_nip),
    KEY idx_ksef_invoice_no (tenant_id, invoice_number),

    CONSTRAINT fk_ksef_invoices_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. sh_ksef_invoice_lines — parsed linie z FaWiersz, gotowe do mappingu ──
CREATE TABLE IF NOT EXISTS sh_ksef_invoice_lines (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ksef_invoice_id         BIGINT UNSIGNED NOT NULL,
    line_no                 INT UNSIGNED NOT NULL COMMENT 'NrWierszaFa z FA(2)',

    -- Dane z faktury
    external_name           VARCHAR(500) NOT NULL COMMENT 'Nazwa z FaWiersz.Nazwa',
    external_description    TEXT NULL,
    gtu_code                VARCHAR(16) NULL COMMENT 'GTU_NN klasyfikacja',
    pkwiu                   VARCHAR(32) NULL COMMENT 'PKWiU klasyfikacja',
    unit                    VARCHAR(32) NULL COMMENT 'Miara (np. kg, l, szt)',
    qty                     DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_net                DECIMAL(15,4) NOT NULL DEFAULT 0,
    line_net_minor          BIGINT NOT NULL DEFAULT 0 COMMENT 'WartoscNetto w groszach',
    vat_rate                DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'np. 23.00, 8.00, 5.00',

    -- AutoScan rezultat (cache — refresh przez `reparse` action)
    resolved_sku            VARCHAR(128) NULL,
    match_type              VARCHAR(16) NULL COMMENT 'EXACT | ALIAS | NAME | FUZZY | NONE',
    match_confidence        INT NULL COMMENT '0-100',
    match_candidates_json   JSON NULL COMMENT 'TOP-3 alternatywy (gdy confidence < threshold)',
    resolved_at             DATETIME NULL,
    resolved_by_user_id     BIGINT UNSIGNED NULL
        COMMENT 'NULL = auto-resolved przez AutoScan; user_id = manual override',

    PRIMARY KEY (id),
    KEY idx_ksef_line_invoice (ksef_invoice_id),
    KEY idx_ksef_line_sku (resolved_sku),

    CONSTRAINT fk_ksef_line_invoice
        FOREIGN KEY (ksef_invoice_id) REFERENCES sh_ksef_invoices (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. sh_ksef_inbox_state — cursor per tenant (dla przyszłego F4 KSeF API) ──
-- W F3: tylko placeholder. F4 będzie tu zapisywał `last_polled_at`,
-- `last_invoice_id_seen` żeby worker_ksef_inbox.php wiedział od którego momentu
-- pollować KSeF API.
CREATE TABLE IF NOT EXISTS sh_ksef_inbox_state (
    tenant_id               INT UNSIGNED NOT NULL,
    last_polled_at          DATETIME NULL,
    last_invoice_seen_id    VARCHAR(64) NULL,
    error_count             INT NOT NULL DEFAULT 0,
    last_error              TEXT NULL,
    auto_poll_enabled       TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0 = MVP F3 manual upload; 1 = F4 worker_ksef_inbox.php polling',
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (tenant_id),

    CONSTRAINT fk_ksef_state_tenant
        FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'm046 ksef_inbox applied: sh_ksef_invoices + sh_ksef_invoice_lines + sh_ksef_inbox_state' AS migration_046;
