-- =============================================================================
-- Migration 027 — Gateway v2 (Multi-source Intake Infrastructure)
-- -----------------------------------------------------------------------------
-- Cel: umożliwienie `api/gateway/intake.php` przyjmowania zamówień z wielu
-- zewnętrznych źródeł (WWW, aggregatorzy typu Uber Eats/Glovo/Pyszne.pl,
-- kioski, własne aplikacje tenantów, 3rd-party POS push) — z niezależnymi
-- limitami, kluczami i idempotency key space per source.
--
-- Architektura:
--   ┌─────────────────┐
--   │ 3rd-party caller│
--   │ (Uber / Glovo / │
--   │  Pyszne / kiosk │
--   │  / own app)     │
--   └────────┬────────┘
--            │ POST /api/gateway/intake.php
--            │ Header: X-API-Key: <sh_gateway_api_keys.key_prefix.key_secret>
--            │ Body:   { source, tenant_id?, external_id, lines, customer, ... }
--            ▼
--   ┌─────────────────────────────────────┐
--   │ Gateway v2                          │
--   │  1. Lookup key → tenant + scopes    │ ← sh_gateway_api_keys
--   │  2. Rate limit check/increment      │ ← sh_rate_limits
--   │  3. Idempotency check (external_id) │ ← sh_external_order_refs
--   │  4. JSON Schema validate per source │
--   │  5. CartEngine recalculate          │
--   │  6. INSERT order + audit            │ ← sh_orders / sh_order_lines
--   │  7. Publish order.created event     │ ← sh_event_outbox (m026)
--   │  8. Store external ref              │ ← sh_external_order_refs
--   └─────────────────────────────────────┘
--
-- Powiązane:
--   • core/GatewayAuth.php        — key lookup + rate limit + ext ref store
--   • api/gateway/intake.php       — refaktor v2 (Sesja 7.2)
--   • _docs/10_GATEWAY_API.md      — specyfikacja publicznego API
-- =============================================================================

SET NAMES utf8mb4;

-- ── 1. sh_gateway_api_keys ──────────────────────────────────────────────────
-- API keys z wieloma właścicielami — każdy tenant może mieć N kluczy dla
-- różnych integracji (Uber, Glovo, własna app mobilna, public API).
--
-- Format klucza: "{prefix}_{secret}" np. "sh_live_a1b2c3...".
--   • `key_prefix`           — widoczny identyfikator (log, UI), np. "sh_live_a1b2c3"
--   • `key_secret_hash`      — SHA-256(full_key) — NIGDY plaintext w DB
--   • Komponujemy: full_key = "{key_prefix}.{raw_secret}" (raw_secret pokazany 1×)
CREATE TABLE IF NOT EXISTS sh_gateway_api_keys (
    id                BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    tenant_id         INT UNSIGNED         NOT NULL,
    key_prefix        VARCHAR(32)          NOT NULL
        COMMENT 'Publiczna część klucza np. sh_live_a1b2c3d4. Indeksowalna, logowalna.',
    key_secret_hash   CHAR(64)             NOT NULL
        COMMENT 'SHA-256(raw_secret) — nigdy plaintext. Weryfikacja: hash_equals(hash(secret), stored).',
    name              VARCHAR(128)         NOT NULL
        COMMENT 'Human-readable label, np. "Uber Eats integration" / "Mobile App iOS"',
    source            VARCHAR(32)          NOT NULL
        COMMENT 'web | aggregator | kiosk | pos_3rd | mobile_app | public_api | internal',
    scopes            JSON                 NOT NULL
        COMMENT 'Uprawnienia: ["order:create","order:read","menu:read",...]. ["*"] = wszystkie.',
    rate_limit_per_min INT UNSIGNED        NOT NULL DEFAULT 60
        COMMENT 'Max requestów na minutę dla tego klucza (sliding window 60s)',
    rate_limit_per_day INT UNSIGNED        NOT NULL DEFAULT 10000
        COMMENT 'Max requestów na dobę (UTC midnight reset)',
    is_active         TINYINT(1)           NOT NULL DEFAULT 1,
    last_used_at      DATETIME             NULL,
    last_used_ip      VARCHAR(45)          NULL
        COMMENT 'IPv4 lub IPv6 caller\'a',
    expires_at        DATETIME             NULL
        COMMENT 'NULL = nigdy nie wygasa; opcjonalna rotacja sekretów',
    created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        BIGINT UNSIGNED      NULL
        COMMENT 'sh_users.id — kto w UI Settings wygenerował klucz',
    revoked_at        DATETIME             NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_gw_key_prefix (key_prefix),
    KEY idx_gw_tenant_source (tenant_id, source, is_active),
    KEY idx_gw_active (is_active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Gateway API keys per tenant × source (m027)';

-- ── 2. sh_rate_limits ───────────────────────────────────────────────────────
-- Sliding-window rate limiter (in-DB — dev/prod z moderatywnym ruchem).
-- Dla dużego ruchu docelowo Redis — zachowujemy pattern aby łatwo zmigrować.
--
-- Key = (api_key_id, window_bucket_iso). Bucket granularity:
--   • minute: YYYY-mm-dd HH:MM       → rate_limit_per_min check
--   • day:    YYYY-mm-dd             → rate_limit_per_day check
--
-- Auto-cleanup: stare bucketu (> 7 dni) kasuje cron (Sesja 7.3).
CREATE TABLE IF NOT EXISTS sh_rate_limits (
    id               BIGINT UNSIGNED       NOT NULL AUTO_INCREMENT,
    api_key_id       BIGINT UNSIGNED       NOT NULL,
    window_kind      ENUM('minute','day','hour') NOT NULL,
    window_bucket    VARCHAR(19)           NOT NULL
        COMMENT 'ISO timestamp bucketu: "2026-04-18 19:45" (minute) / "2026-04-18" (day) / "2026-04-18 19" (hour)',
    request_count    INT UNSIGNED          NOT NULL DEFAULT 0,
    first_hit_at     DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit_at      DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_rl_bucket (api_key_id, window_kind, window_bucket),
    KEY idx_rl_cleanup (last_hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sliding-window rate limiter per API key (m027)';

-- ── 3. sh_external_order_refs ──────────────────────────────────────────────
-- Idempotency dla external ID-ków z 3rd-party systemów.
-- Przykład: Uber Eats wysyła ten sam order 2× (network retry) — drugi POST
-- zwraca identyczny `order_id` + `was_duplicate=true`.
--
-- UNIQUE per (tenant, source, external_id) — różne sourcy mogą mieć
-- te same external_id bez kolizji (Uber ma swój numer, Glovo swój).
CREATE TABLE IF NOT EXISTS sh_external_order_refs (
    id             BIGINT UNSIGNED       NOT NULL AUTO_INCREMENT,
    tenant_id      INT UNSIGNED          NOT NULL,
    source         VARCHAR(32)           NOT NULL
        COMMENT 'web | aggregator_uber | aggregator_glovo | aggregator_pyszne | kiosk | pos_3rd | mobile_app | public_api',
    external_id    VARCHAR(128)          NOT NULL
        COMMENT 'ID z systemu 3rd-party (np. Uber UUID, Glovo order_ref). Nigdy pusty gdy source != web.',
    order_id       CHAR(36)              NOT NULL
        COMMENT 'UUID zamówienia w sh_orders',
    api_key_id     BIGINT UNSIGNED       NULL
        COMMENT 'Który klucz został użyty — do audytu',
    request_hash   CHAR(64)              NULL
        COMMENT 'SHA-256 oryginalnego payloadu — detect replay z różnymi danymi',
    created_at     DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_ext_ref (tenant_id, source, external_id),
    KEY idx_ext_order (order_id),
    KEY idx_ext_key (api_key_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='External order ID → internal order_id map (idempotency, m027)';

-- ── 4. Rozszerzenie sh_orders (idempotency tracking) ───────────────────────
-- Dodaj kolumny `gateway_source` i `gateway_external_id` — audit-friendly
-- zapis już na orderze (bez joina do sh_external_order_refs przy każdym read).
--
-- ADDITIVE: dodaje tylko jeśli nie istnieją. Stara logika POS/Online nadal
-- działa z NULLami w tych polach.
SET @col_source_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sh_orders'
      AND COLUMN_NAME = 'gateway_source'
);
SET @sql := IF(
    @col_source_exists = 0,
    'ALTER TABLE sh_orders
       ADD COLUMN gateway_source VARCHAR(32) NULL
         COMMENT ''Źródło gdy order przyszedł przez gateway (m027)''
       AFTER source,
       ADD COLUMN gateway_external_id VARCHAR(128) NULL
         COMMENT ''external_id z 3rd-party systemu (m027)''
       AFTER gateway_source,
       ADD INDEX idx_orders_gw_ext (tenant_id, gateway_source, gateway_external_id)',
    'SELECT ''sh_orders gateway_* columns already present'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Migration marker ────────────────────────────────────────────────────────
SELECT 'm027 gateway_v2 applied: sh_gateway_api_keys, sh_rate_limits, sh_external_order_refs, sh_orders+gateway_*' AS migration_027;
