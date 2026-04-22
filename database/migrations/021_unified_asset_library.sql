-- =============================================================================
-- SliceHub Pro — Migration 021: Unified Asset Library
--
-- Konsolidacja WSZYSTKICH rozproszonych pól obrazkowych w jeden rejestr:
--
--   sh_assets       — kanoniczny rekord pliku/zasobu (per tenant lub global)
--   sh_asset_links  — join n:m: która encja używa którego zasobu i w jakiej roli
--
-- Zastępuje rozproszenie:
--   sh_menu_items.image_url
--   sh_global_assets.filename
--   sh_ingredient_assets.asset_filename
--   sh_visual_layers.asset_filename + product_filename
--   sh_board_companions.asset_filename + product_filename
--
-- ZERO DESTRUKCJI: stare kolumny ZOSTAJĄ (Prawo Czwartego Wymiaru). Migracja
-- tylko dodaje nową warstwę + backfilluje dane + zostawia kolumny z komentarzem
-- DEPRECATED_M021. Fizyczne usunięcie w osobnej, późniejszej migracji —
-- dopiero po migracji całego API na nową warstwę.
--
-- IDEMPOTENT: INSERT IGNORE + WHERE NOT EXISTS + INFORMATION_SCHEMA guards.
-- Bezpieczne do wielokrotnego uruchomienia.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- =============================================================================
-- 1. sh_assets — kanoniczny rejestr plików
-- =============================================================================
-- Konwencja ascii_key (żeby uniknąć kolizji między źródłami):
--   <bare>            — rekord z sh_global_assets (canonical library)
--   ING__<key>        — rekord backfillowany z sh_ingredient_assets
--   HERO__<item_key>  — hero photo dania z sh_menu_items.image_url
--   VL_L__<id>        — asset_filename z sh_visual_layers (niematchujący global)
--   VL_P__<id>        — product_filename z sh_visual_layers
--   BC_L__<id>        — asset_filename z sh_board_companions
--   BC_P__<id>        — product_filename z sh_board_companions
--
-- Konwencja storage_url:
--   - jeśli zaczyna się http:// lub https:// → external URL (nie resolve przez DOCROOT)
--   - w przeciwnym razie → relative path od DOCROOT (bez leading slash)
--     np. 'uploads/global_assets/meat_salami.webp'
--         'uploads/visual/7/sauce_garlic.webp'

CREATE TABLE IF NOT EXISTS sh_assets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED    NOT NULL DEFAULT 0
                  COMMENT '0 = globalny asset współdzielony; >0 = tenant-scoped',
  ascii_key       VARCHAR(191)    NOT NULL
                  COMMENT 'Unikalny per tenant. Konwencje prefixów w komentarzu migracji.',

  -- Storage
  storage_url     VARCHAR(1024)   NOT NULL
                  COMMENT 'Pełny URL (zaczyna się http(s)://) lub ścieżka relatywna od DOCROOT',
  storage_bucket  VARCHAR(32)     NOT NULL DEFAULT 'legacy'
                  COMMENT 'library / hero / surface / companion / brand / variant / legacy',

  -- File metadata (nullable dla backfill; uzupełniane przy re-uploadzie)
  mime_type       VARCHAR(64)     NULL     DEFAULT 'image/webp',
  width_px        INT UNSIGNED    NULL,
  height_px       INT UNSIGNED    NULL,
  filesize_bytes  BIGINT UNSIGNED NULL,
  has_alpha       TINYINT(1)      NOT NULL DEFAULT 1,
  checksum_sha256 CHAR(64)        NULL
                  COMMENT 'SHA-256 pliku; NULL dla backfillu — wypełniane przy re-uploadzie dla dedup',

  -- Klasyfikacja (miękka, nie constraint — encja może mieć wiele ról przez sh_asset_links)
  role_hint       VARCHAR(32)     NULL
                  COMMENT 'hero / layer / surface / companion / icon / logo / thumbnail / poster / og',
  category        VARCHAR(32)     NULL
                  COMMENT 'board / base / sauce / cheese / meat / veg / herb / drink / surface / brand / misc',
  sub_type        VARCHAR(64)     NULL
                  COMMENT 'Np. tomato, mozzarella, pepperoni, marble_white, pepsi_330',
  z_order_hint    INT             NOT NULL DEFAULT 50
                  COMMENT 'Domyślny z-index dla warstw (niższy = głębiej)',

  -- Derivative chain (thumbnaily, postery, LQ/HQ warianty)
  variant_of      BIGINT UNSIGNED NULL
                  COMMENT 'FK → sh_assets.id, jeśli to pochodna innego zasobu',
  variant_kind    VARCHAR(32)     NULL
                  COMMENT 'thumbnail / poster / webp_lq / webp_hq / og_share / avif',

  -- Elastyczne rozszerzenie (bez migracji schematu przy nowych polach)
  metadata_json   JSON            NULL
                  COMMENT 'LUT preset, lighting, EXIF, target_px, custom tags itd.',

  -- Lifecycle
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL
                  COMMENT 'Soft-delete (Prawo Czwartego Wymiaru) — NIGDY hard-delete',
  created_by_user VARCHAR(64)     NULL
                  COMMENT 'User ID/login kto wgrał; NULL dla backfillowanych',

  PRIMARY KEY (id),
  UNIQUE KEY uq_assets_tenant_key (tenant_id, ascii_key),
  KEY idx_assets_tenant_active  (tenant_id, is_active, deleted_at),
  KEY idx_assets_role           (tenant_id, role_hint),
  KEY idx_assets_category       (tenant_id, category, sub_type),
  KEY idx_assets_variant        (variant_of),
  KEY idx_assets_checksum       (tenant_id, checksum_sha256),
  KEY idx_assets_bucket         (tenant_id, storage_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kanoniczny rejestr wszystkich plików/obrazów (od m021).';

-- FK self-reference dla variant_of (dodajemy po CREATE, żeby IF NOT EXISTS działał)
SET @fk_variant = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_assets'
    AND CONSTRAINT_NAME = 'fk_assets_variant_of'
);
SET @sql_fk_v = IF(@fk_variant = 0,
  'ALTER TABLE sh_assets ADD CONSTRAINT fk_assets_variant_of FOREIGN KEY (variant_of) REFERENCES sh_assets (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''fk_assets_variant_of already exists'''
);
PREPARE s FROM @sql_fk_v; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- 2. sh_asset_links — powiązania n:m: encja ⇄ asset z rolą
-- =============================================================================
-- Pozwala jednemu assetowi być użytym w wielu miejscach, oraz jednej encji
-- mieć wiele assetów (np. hero + thumbnail + og_image).
--
-- entity_type  → semantyka entity_ref:
--   'menu_item'        → sh_menu_items.ascii_key
--   'modifier'         → sh_modifiers.ascii_key
--   'visual_layer'     → '{item_ascii_key}::{layer_ascii_key}' (composite)
--   'board_companion'  → '{item_ascii_key}::{companion_ascii_key}' (composite)
--   'atelier_scene'    → '{item_ascii_key}' (sh_atelier_scenes po item_sku)
--   'scene_layer'      → '{scene_id}::{layer_uuid}' (warstwa wewnątrz spec_json)
--   'tenant_brand'     → tenant_id jako string (np. '7')
--   'surface_library'  → 'surface::{category}::{sub_type}' (bucket surfaces)
--
-- role          → hero / layer_top_down / product_shot / surface_bg /
--                 companion_icon / modifier_icon / tenant_logo / tenant_favicon /
--                 thumbnail / poster / og_image / ambient_texture

CREATE TABLE IF NOT EXISTS sh_asset_links (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED    NOT NULL,
  asset_id        BIGINT UNSIGNED NOT NULL,

  entity_type     VARCHAR(32)     NOT NULL
                  COMMENT 'menu_item / modifier / visual_layer / board_companion / atelier_scene / scene_layer / tenant_brand / surface_library',
  entity_ref      VARCHAR(255)    NOT NULL
                  COMMENT 'ascii_key albo composite ref — semantyka w komentarzu migracji',

  role            VARCHAR(32)     NOT NULL
                  COMMENT 'hero / layer_top_down / product_shot / surface_bg / companion_icon / modifier_icon / tenant_logo / thumbnail / poster / og_image / ambient_texture',

  sort_order      INT             NOT NULL DEFAULT 0
                  COMMENT 'Kolejność wyświetlania gdy wiele zasobów w tej samej roli',

  display_params_json JSON        NULL
                  COMMENT 'Per-link overrides: calScale, calRotate, offsetX/Y, zIndex, isBase, visualKind itd.',

  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NULL     ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL,
  created_by_user VARCHAR(64)     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_al_unique     (tenant_id, entity_type, entity_ref, role, asset_id),
  KEY idx_al_entity           (tenant_id, entity_type, entity_ref, role),
  KEY idx_al_asset            (asset_id),
  KEY idx_al_role             (tenant_id, role),
  CONSTRAINT fk_al_asset
    FOREIGN KEY (asset_id) REFERENCES sh_assets (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_al_tenant
    FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='n:m mapowanie encji na zasoby (sh_assets) z rolami (od m021).';

-- =============================================================================
-- 2.5 DEFENSIVE GUARDS — upewnij się że m019 (offset_x/y) zostało zastosowane
-- =============================================================================
-- Runner scripts/setup_database.php nie rejestrował m019 osobno; wiele
-- instalacji może nie mieć tych kolumn. Backfill sekcji 6 ich używa.

SET @col_ox = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'offset_x'
);
SET @sql_ox = IF(@col_ox = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN offset_x DECIMAL(4,3) NOT NULL DEFAULT 0.000 COMMENT ''Visual X offset (-0.5..+0.5 of half-pizza radius)'' AFTER cal_rotate',
  'SELECT ''offset_x already present'''
);
PREPARE s FROM @sql_ox; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_oy = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sh_visual_layers'
    AND COLUMN_NAME  = 'offset_y'
);
SET @sql_oy = IF(@col_oy = 0,
  'ALTER TABLE sh_visual_layers ADD COLUMN offset_y DECIMAL(4,3) NOT NULL DEFAULT 0.000 COMMENT ''Visual Y offset (-0.5..+0.5 of half-pizza radius)'' AFTER offset_x',
  'SELECT ''offset_y already present'''
);
PREPARE s FROM @sql_oy; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- 3. BACKFILL: sh_global_assets → sh_assets
-- =============================================================================
-- Kopiujemy każdy wiersz z biblioteki globalnej jako kanoniczny asset.
-- Zachowujemy oryginalny ascii_key (bez prefixu) — to jest "canonical library".

INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, mime_type,
   width_px, height_px, filesize_bytes, has_alpha,
   role_hint, category, sub_type, z_order_hint,
   metadata_json, is_active, created_at, updated_at)
SELECT
  ga.tenant_id,
  ga.ascii_key,
  CONCAT('uploads/global_assets/', ga.filename) AS storage_url,
  'library' AS storage_bucket,
  'image/webp' AS mime_type,
  NULLIF(ga.width, 0),
  NULLIF(ga.height, 0),
  NULLIF(ga.filesize_bytes, 0),
  ga.has_alpha,
  'layer' AS role_hint,
  ga.category,
  ga.sub_type,
  ga.z_order,
  JSON_OBJECT('target_px', ga.target_px, 'backfilled_from', 'sh_global_assets', 'orig_id', ga.id) AS metadata_json,
  ga.is_active,
  ga.created_at,
  COALESCE(ga.updated_at, ga.created_at)
FROM sh_global_assets ga;

-- =============================================================================
-- 4. BACKFILL: sh_ingredient_assets → sh_assets (ascii_key prefix 'ING__')
--              + sh_asset_links (entity=modifier, role=modifier_icon)
-- =============================================================================
-- Tylko jeśli tabela sh_ingredient_assets istnieje (defensywny check).

SET @tbl_ing = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_ingredient_assets'
);

SET @sql_ing_assets = IF(@tbl_ing > 0,
  'INSERT IGNORE INTO sh_assets
     (tenant_id, ascii_key, storage_url, storage_bucket, mime_type,
      has_alpha, role_hint, category, is_active, created_at, updated_at)
   SELECT
     ia.tenant_id,
     CONCAT(''ING__'', ia.ascii_key) AS ascii_key,
     CONCAT(''uploads/visual/'', ia.tenant_id, ''/'', ia.asset_filename) AS storage_url,
     ''library'' AS storage_bucket,
     ''image/webp'' AS mime_type,
     1 AS has_alpha,
     ''layer'' AS role_hint,
     CASE ia.z_category
       WHEN ''base''      THEN ''base''
       WHEN ''sauce''     THEN ''sauce''
       WHEN ''cheese''    THEN ''cheese''
       WHEN ''meat''      THEN ''meat''
       WHEN ''veggie''    THEN ''veg''
       WHEN ''herb''      THEN ''herb''
       WHEN ''finishing'' THEN ''herb''
       ELSE ''misc''
     END AS category,
     ia.is_active,
     ia.created_at,
     COALESCE(ia.updated_at, ia.created_at)
   FROM sh_ingredient_assets ia',
  'SELECT ''sh_ingredient_assets not present, skip backfill'''
);
PREPARE s FROM @sql_ing_assets; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql_ing_links = IF(@tbl_ing > 0,
  'INSERT IGNORE INTO sh_asset_links
     (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
   SELECT
     ia.tenant_id,
     a.id AS asset_id,
     ''modifier'' AS entity_type,
     ia.ascii_key AS entity_ref,
     ''modifier_icon'' AS role,
     0 AS sort_order,
     ia.is_active,
     ia.created_at
   FROM sh_ingredient_assets ia
   INNER JOIN sh_assets a
     ON a.tenant_id = ia.tenant_id
    AND a.ascii_key = CONCAT(''ING__'', ia.ascii_key)',
  'SELECT ''sh_ingredient_assets not present, skip links'''
);
PREPARE s FROM @sql_ing_links; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- 5. BACKFILL: sh_menu_items.image_url → sh_assets + sh_asset_links (hero)
-- =============================================================================
-- Obsługujemy oba przypadki: pełny URL i legacy path.

INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, mime_type,
   role_hint, category, is_active, created_at, updated_at)
SELECT
  mi.tenant_id,
  CONCAT('HERO__', mi.ascii_key) AS ascii_key,
  mi.image_url AS storage_url,
  'hero' AS storage_bucket,
  CASE
    WHEN mi.image_url LIKE '%.png'  THEN 'image/png'
    WHEN mi.image_url LIKE '%.jpg'  THEN 'image/jpeg'
    WHEN mi.image_url LIKE '%.jpeg' THEN 'image/jpeg'
    WHEN mi.image_url LIKE '%.avif' THEN 'image/avif'
    ELSE 'image/webp'
  END AS mime_type,
  'hero' AS role_hint,
  'hero' AS category,
  mi.is_active,
  COALESCE(mi.created_at, CURRENT_TIMESTAMP),
  mi.updated_at
FROM sh_menu_items mi
WHERE mi.image_url IS NOT NULL
  AND TRIM(mi.image_url) <> '';

INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
SELECT
  mi.tenant_id,
  a.id AS asset_id,
  'menu_item' AS entity_type,
  mi.ascii_key AS entity_ref,
  'hero' AS role,
  0 AS sort_order,
  mi.is_active,
  COALESCE(mi.created_at, CURRENT_TIMESTAMP)
FROM sh_menu_items mi
INNER JOIN sh_assets a
  ON a.tenant_id = mi.tenant_id
 AND a.ascii_key = CONCAT('HERO__', mi.ascii_key)
WHERE mi.image_url IS NOT NULL
  AND TRIM(mi.image_url) <> '';

-- =============================================================================
-- 6. BACKFILL: sh_visual_layers → sh_asset_links
-- =============================================================================
-- Strategia: jeśli asset_filename pasuje do filename w sh_global_assets
-- (tenant_id IN (0, vl.tenant_id)) → link do istniejącego sh_assets.
-- Jeśli nie — tworzymy nowy sh_assets z ascii_key = 'VL_L__{vl.id}'.

-- 6a. Linki: asset_filename ⇄ sh_global_assets match
INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, display_params_json, is_active, created_at)
SELECT
  vl.tenant_id,
  a.id AS asset_id,
  'visual_layer' AS entity_type,
  CONCAT(vl.item_sku, '::', vl.layer_sku) AS entity_ref,
  'layer_top_down' AS role,
  COALESCE(vl.z_index, 50) AS sort_order,
  JSON_OBJECT(
    'calScale',  vl.cal_scale,
    'calRotate', vl.cal_rotate,
    'offsetX',   vl.offset_x,
    'offsetY',   vl.offset_y,
    'zIndex',    vl.z_index,
    'isBase',    vl.is_base,
    'libCategory', vl.library_category,
    'libSubType',  vl.library_sub_type
  ) AS display_params_json,
  vl.is_active,
  vl.created_at
FROM sh_visual_layers vl
INNER JOIN sh_assets a
  ON a.tenant_id IN (0, vl.tenant_id)
 AND a.storage_url = CONCAT('uploads/global_assets/', vl.asset_filename)
WHERE vl.asset_filename IS NOT NULL AND vl.asset_filename <> '';

-- 6b. Fallback: asset_filename bez matchu w global_assets → nowy sh_assets
INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, role_hint, category, sub_type, is_active, created_at, updated_at)
SELECT
  vl.tenant_id,
  CONCAT('VL_L__', vl.id) AS ascii_key,
  CONCAT('uploads/visual/', vl.tenant_id, '/', vl.asset_filename) AS storage_url,
  'legacy' AS storage_bucket,
  'layer' AS role_hint,
  COALESCE(vl.library_category, 'misc') AS category,
  vl.library_sub_type,
  vl.is_active,
  vl.created_at,
  vl.updated_at
FROM sh_visual_layers vl
WHERE vl.asset_filename IS NOT NULL AND vl.asset_filename <> ''
  AND NOT EXISTS (
    SELECT 1 FROM sh_assets a
    WHERE a.tenant_id IN (0, vl.tenant_id)
      AND a.storage_url = CONCAT('uploads/global_assets/', vl.asset_filename)
  );

-- 6c. Linki dla 6b (fallback)
INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, display_params_json, is_active, created_at)
SELECT
  vl.tenant_id,
  a.id,
  'visual_layer',
  CONCAT(vl.item_sku, '::', vl.layer_sku),
  'layer_top_down',
  COALESCE(vl.z_index, 50),
  JSON_OBJECT(
    'calScale',  vl.cal_scale,
    'calRotate', vl.cal_rotate,
    'offsetX',   vl.offset_x,
    'offsetY',   vl.offset_y,
    'zIndex',    vl.z_index,
    'isBase',    vl.is_base,
    'libCategory', vl.library_category,
    'libSubType',  vl.library_sub_type
  ),
  vl.is_active,
  vl.created_at
FROM sh_visual_layers vl
INNER JOIN sh_assets a
  ON a.tenant_id = vl.tenant_id
 AND a.ascii_key = CONCAT('VL_L__', vl.id)
WHERE vl.asset_filename IS NOT NULL AND vl.asset_filename <> '';

-- 6d. product_filename → sh_assets + link (role=product_shot)
INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, role_hint, is_active, created_at, updated_at)
SELECT
  vl.tenant_id,
  CONCAT('VL_P__', vl.id) AS ascii_key,
  CONCAT('uploads/visual/', vl.tenant_id, '/', vl.product_filename) AS storage_url,
  'hero' AS storage_bucket,
  'hero' AS role_hint,
  vl.is_active,
  vl.created_at,
  vl.updated_at
FROM sh_visual_layers vl
WHERE vl.product_filename IS NOT NULL AND vl.product_filename <> '';

INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
SELECT
  vl.tenant_id,
  a.id,
  'visual_layer',
  CONCAT(vl.item_sku, '::', vl.layer_sku),
  'product_shot',
  0,
  vl.is_active,
  vl.created_at
FROM sh_visual_layers vl
INNER JOIN sh_assets a
  ON a.tenant_id = vl.tenant_id
 AND a.ascii_key = CONCAT('VL_P__', vl.id)
WHERE vl.product_filename IS NOT NULL AND vl.product_filename <> '';

-- =============================================================================
-- 7. BACKFILL: sh_board_companions → sh_asset_links (companion_icon / product_shot)
-- =============================================================================

-- 7a. asset_filename → sh_assets + link
INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, role_hint, is_active, created_at, updated_at)
SELECT
  bc.tenant_id,
  CONCAT('BC_L__', bc.id) AS ascii_key,
  CONCAT('uploads/visual/', bc.tenant_id, '/', bc.asset_filename) AS storage_url,
  'companion' AS storage_bucket,
  'companion' AS role_hint,
  bc.is_active,
  bc.created_at,
  bc.updated_at
FROM sh_board_companions bc
WHERE bc.asset_filename IS NOT NULL AND bc.asset_filename <> '';

INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
SELECT
  bc.tenant_id,
  a.id,
  'board_companion',
  CONCAT(bc.item_sku, '::', bc.companion_sku),
  'companion_icon',
  bc.board_slot,
  bc.is_active,
  bc.created_at
FROM sh_board_companions bc
INNER JOIN sh_assets a
  ON a.tenant_id = bc.tenant_id
 AND a.ascii_key = CONCAT('BC_L__', bc.id)
WHERE bc.asset_filename IS NOT NULL AND bc.asset_filename <> '';

-- 7b. product_filename → sh_assets + link (role=product_shot)
INSERT IGNORE INTO sh_assets
  (tenant_id, ascii_key, storage_url, storage_bucket, role_hint, is_active, created_at, updated_at)
SELECT
  bc.tenant_id,
  CONCAT('BC_P__', bc.id) AS ascii_key,
  CONCAT('uploads/visual/', bc.tenant_id, '/', bc.product_filename) AS storage_url,
  'hero' AS storage_bucket,
  'hero' AS role_hint,
  bc.is_active,
  bc.created_at,
  bc.updated_at
FROM sh_board_companions bc
WHERE bc.product_filename IS NOT NULL AND bc.product_filename <> '';

INSERT IGNORE INTO sh_asset_links
  (tenant_id, asset_id, entity_type, entity_ref, role, sort_order, is_active, created_at)
SELECT
  bc.tenant_id,
  a.id,
  'board_companion',
  CONCAT(bc.item_sku, '::', bc.companion_sku),
  'product_shot',
  bc.board_slot,
  bc.is_active,
  bc.created_at
FROM sh_board_companions bc
INNER JOIN sh_assets a
  ON a.tenant_id = bc.tenant_id
 AND a.ascii_key = CONCAT('BC_P__', bc.id)
WHERE bc.product_filename IS NOT NULL AND bc.product_filename <> '';

-- =============================================================================
-- 8. WIDOKI KOMPATYBILNOŚCI
-- =============================================================================
-- Zastępują ręczne JOIN-y w API. Po migracji API wystarczy SELECT z widoku.
-- Każdy widok zwraca JEDEN URL per entity + role (pierwszy aktywny, sort_order ASC).
-- Widoki nie filtrują tenant_id — API musi dodać WHERE tenant_id = ?.

-- 8a. Hero zdjęcie dania
CREATE OR REPLACE VIEW v_menu_item_hero AS
SELECT
  mi.tenant_id,
  mi.id            AS menu_item_id,
  mi.ascii_key     AS item_sku,
  mi.name          AS item_name,
  a.id             AS asset_id,
  a.storage_url    AS hero_url,
  a.width_px,
  a.height_px,
  a.mime_type,
  al.display_params_json AS params_json
FROM sh_menu_items mi
LEFT JOIN sh_asset_links al
  ON al.tenant_id   = mi.tenant_id
 AND al.entity_type = 'menu_item'
 AND al.entity_ref  = mi.ascii_key
 AND al.role        = 'hero'
 AND al.is_active   = 1
 AND al.deleted_at IS NULL
LEFT JOIN sh_assets a
  ON a.id         = al.asset_id
 AND a.is_active  = 1
 AND a.deleted_at IS NULL;

-- 8b. Ikona modyfikatora (dla storefront — "sos czosnkowy", "ser extra")
-- UWAGA: sh_modifiers NIE MA bezpośredniego tenant_id — sięgamy przez group → groups.tenant_id.
CREATE OR REPLACE VIEW v_modifier_icon AS
SELECT
  mg.tenant_id,
  m.id             AS modifier_id,
  m.group_id       AS modifier_group_id,
  m.ascii_key      AS modifier_sku,
  m.name           AS modifier_name,
  a.id             AS asset_id,
  a.storage_url    AS icon_url,
  a.category       AS asset_category,
  a.sub_type       AS asset_sub_type,
  a.z_order_hint,
  a.width_px,
  a.height_px
FROM sh_modifiers m
INNER JOIN sh_modifier_groups mg
  ON mg.id = m.group_id
LEFT JOIN sh_asset_links al
  ON al.tenant_id   = mg.tenant_id
 AND al.entity_type = 'modifier'
 AND al.entity_ref  = m.ascii_key
 AND al.role        = 'modifier_icon'
 AND al.is_active   = 1
 AND al.deleted_at IS NULL
LEFT JOIN sh_assets a
  ON a.id         = al.asset_id
 AND a.is_active  = 1
 AND a.deleted_at IS NULL;

-- 8c. Warstwa wizualna dania (layer_top_down + display_params)
CREATE OR REPLACE VIEW v_visual_layer_asset AS
SELECT
  vl.tenant_id,
  vl.id            AS layer_id,
  vl.item_sku,
  vl.layer_sku,
  vl.z_index,
  vl.is_base,
  a.id             AS asset_id,
  a.storage_url    AS layer_url,
  a.category       AS asset_category,
  a.sub_type       AS asset_sub_type,
  al.display_params_json
FROM sh_visual_layers vl
LEFT JOIN sh_asset_links al
  ON al.tenant_id   = vl.tenant_id
 AND al.entity_type = 'visual_layer'
 AND al.entity_ref  = CONCAT(vl.item_sku, '::', vl.layer_sku)
 AND al.role        = 'layer_top_down'
 AND al.is_active   = 1
 AND al.deleted_at IS NULL
LEFT JOIN sh_assets a
  ON a.id         = al.asset_id
 AND a.is_active  = 1
 AND a.deleted_at IS NULL;

-- =============================================================================
-- 9. MARKERY DEPRECATED na starych kolumnach
-- =============================================================================
-- Nie usuwamy — tylko dokumentujemy w COMMENT, że od m021 pole jest
-- podwójnym zapisem (cień-zapis). API musi pisać w oba miejsca przez okres
-- przejściowy, a po pełnej migracji całego stosu — osobna migracja m0XX
-- DROP COLUMN (dopiero po audycie zero-reads na starych polach).

ALTER TABLE sh_menu_items
  MODIFY COLUMN image_url VARCHAR(512) NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_assets via sh_asset_links(menu_item,hero). Do DROP po migracji API.';

ALTER TABLE sh_visual_layers
  MODIFY COLUMN asset_filename VARCHAR(255) NOT NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(visual_layer,layer_top_down).',
  MODIFY COLUMN product_filename VARCHAR(255) NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(visual_layer,product_shot).';

ALTER TABLE sh_board_companions
  MODIFY COLUMN asset_filename VARCHAR(255) NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(board_companion,companion_icon).',
  MODIFY COLUMN product_filename VARCHAR(255) NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(board_companion,product_shot).';

ALTER TABLE sh_global_assets
  MODIFY COLUMN filename VARCHAR(255) NOT NULL
  COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_assets.storage_url. Do DROP po migracji API.';

-- sh_ingredient_assets defensywnie (tabela może nie istnieć w każdej instalacji):
SET @tbl_ing2 = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_ingredient_assets'
);
SET @sql_dep_ing = IF(@tbl_ing2 > 0,
  'ALTER TABLE sh_ingredient_assets
     MODIFY COLUMN asset_filename VARCHAR(255) NOT NULL
     COMMENT ''DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(modifier,modifier_icon). Do DROP po migracji API.''',
  'SELECT ''sh_ingredient_assets not present, skip deprecation marker'''
);
PREPARE s FROM @sql_dep_ing; EXECUTE s; DEALLOCATE PREPARE s;
