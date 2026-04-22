-- =============================================================================
-- SliceHub Pro — Migration 022: Scene Kit & The Table Foundation
--
-- Fundament dla:
--   - Scene Studio (manager) — biblioteka szablonów scenografii
--   - The Table (klient)     — interaktywny stół z dioramami
--   - Category Style Engine  — Hollywood-level zmiana stylu kategorii
--   - Promotion Layer        — wbudowana mechanika upsell
--   - Scene Triggers         — automatyzacja per data/godzina/pogoda
--   - AI Job Queue           — queue dla style transform / enhance / variants
--
-- ZASADY:
--   - Idempotentne (CREATE TABLE IF NOT EXISTS + INSERT IGNORE / ON DUPLICATE KEY)
--   - Additive only — żadnych DROP, żadnych zmian destrukcyjnych
--   - Zero FK constraints w nowych tabelach (elastyczność > rygor referential integrity)
--   - Bez DELIMITER/PROCEDURE — żeby plik dał się wykonać przez PDO::exec()
--   - ALTERy istniejących tabel (sh_menu_items, sh_categories, sh_atelier_scenes,
--     sh_board_companions, sh_tenant_settings) wykonuje setup_database.php
--     z guard checking po INFORMATION_SCHEMA
--
-- Faza 1 dostarcza SCHEMA + minimalnie bogaty SEED. Logika (AI Pipeline,
-- Style Engine runner, Triggers cron, edytory UI) w Fazach 2-4.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- =============================================================================
-- SEKCJA A: NOWE TABELE
-- =============================================================================

-- ── A1. sh_scene_templates ──────────────────────────────────────────────────
-- Biblioteka szablonów scenografii. Każdy szablon to "rodzaj sceny":
--   pizza_top_down, static_hero, pasta_bowl, beverage_bottle, ...
-- Manager wybiera szablon dla dania/kategorii — Scene Studio pokazuje
-- odpowiednie panele edycji.

CREATE TABLE IF NOT EXISTS sh_scene_templates (
  id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id                   INT UNSIGNED    NOT NULL DEFAULT 0
                              COMMENT '0 = system template (wbudowany), >0 = custom tenanta',
  ascii_key                   VARCHAR(64)     NOT NULL
                              COMMENT 'Stabilny identyfikator: pizza_top_down, static_hero, ...',
  name                        VARCHAR(128)    NOT NULL,
  kind                        ENUM('item','category') NOT NULL DEFAULT 'item'
                              COMMENT 'item = jedno danie; category = stół całej kategorii',

  -- Stage (scenografia, kamera, światło, vignette)
  stage_preset_json           JSON            NULL
                              COMMENT 'aspect, background_kind, lighting, vignette, grain, letterbox',

  -- Composition schema (jakie warstwy przyjmuje)
  composition_schema_json     JSON            NULL
                              COMMENT 'layers_required, layers_optional, centerpiece, centerpiece_position',

  -- Manager Empowerment Stack
  scene_kit_assets_json       JSON            NULL
                              COMMENT 'Lista asset_id z sh_assets dla tła/rekwizytów/świateł — Faza 2',
  pipeline_preset_json        JSON            NULL
                              COMMENT 'Domyślne ustawienia AI Photo Pipeline: preset, auto_apply, kroki',
  placeholder_asset_id        BIGINT UNSIGNED NULL
                              COMMENT 'FK logiczne do sh_assets — co pokazać gdy brak zdjęcia dania',
  photographer_brief_md       TEXT            NULL
                              COMMENT 'Markdown brief dla fotografa per template',

  -- Cinema Cameras / Mood LUTs / Atmospheric Effects (Hollywood Cinema Creator)
  available_cameras_json      JSON            NULL
                              COMMENT 'Tablica camera presets dostępnych w tym template',
  available_luts_json         JSON            NULL
                              COMMENT 'Tablica LUT presets dostępnych w tym template',
  atmospheric_effects_json    JSON            NULL
                              COMMENT 'Tablica atmospheric effects dostępnych w tym template',
  default_style_id            INT UNSIGNED    NULL
                              COMMENT 'FK logiczne do sh_style_presets — domyślny styl wizualny',

  -- Lifecycle
  is_system                   TINYINT(1)      NOT NULL DEFAULT 0,
  is_active                   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_st_tenant_key (tenant_id, ascii_key),
  KEY idx_st_kind_active (kind, is_active),
  KEY idx_st_tenant (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: biblioteka szablonów scenografii (Scene Studio)';


-- ── A2. sh_promotions ───────────────────────────────────────────────────────
-- Definicje promocji. Logika liczenia rabatu w CartEngine — Faza 4.

CREATE TABLE IF NOT EXISTS sh_promotions (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED    NOT NULL,
  ascii_key           VARCHAR(64)     NOT NULL,
  name                VARCHAR(128)    NOT NULL,

  rule_kind           ENUM('discount_percent','discount_amount','combo_half_price',
                           'free_item_if_threshold','bundle')
                      NOT NULL DEFAULT 'discount_percent',
  rule_json           JSON            NOT NULL
                      COMMENT 'Parametry reguły: trigger_sku, target_sku, discount, threshold itd.',

  -- Wizual
  badge_text          VARCHAR(32)     NULL COMMENT 'np. "-50%", "GRATIS", "KOMBO"',
  badge_style         VARCHAR(32)     NULL DEFAULT 'amber'
                      COMMENT 'neon / gold / red_burst / amber / vintage',

  -- Time gating
  time_window_json    JSON            NULL
                      COMMENT '{ days:[1..7], start:"HH:MM", end:"HH:MM" }',
  valid_from          DATETIME        NULL,
  valid_to            DATETIME        NULL,

  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_tenant_key (tenant_id, ascii_key),
  KEY idx_promo_tenant_active (tenant_id, is_active),
  KEY idx_promo_validity (valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: definicje promocji (logika w CartEngine — Faza 4)';


-- ── A3. sh_scene_promotion_slots ────────────────────────────────────────────
-- Pozycjonowanie promocji na konkretnej scenie.

CREATE TABLE IF NOT EXISTS sh_scene_promotion_slots (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  scene_id            INT             NOT NULL
                      COMMENT 'FK logiczne do sh_atelier_scenes.id (signed INT bo tak jest tam)',
  promotion_id        INT UNSIGNED    NOT NULL
                      COMMENT 'FK logiczne do sh_promotions.id',

  slot_x              DECIMAL(6,2)    NOT NULL DEFAULT 50.00 COMMENT 'Pozycja % na scenie (X)',
  slot_y              DECIMAL(6,2)    NOT NULL DEFAULT 50.00 COMMENT 'Pozycja % na scenie (Y)',
  slot_z_index        INT             NOT NULL DEFAULT 100,
  display_order       INT             NOT NULL DEFAULT 0,

  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_sps_scene (scene_id, is_active),
  KEY idx_sps_promo (promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: link-table promotion ↔ scena z pozycją';


-- ── A4. sh_style_presets ────────────────────────────────────────────────────
-- Biblioteka stylów wizualnych dla Category Style Engine (Hollywood).
-- Zawiera 12 wbudowanych + miejsce na custom tenanta.

CREATE TABLE IF NOT EXISTS sh_style_presets (
  id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id                   INT UNSIGNED    NOT NULL DEFAULT 0
                              COMMENT '0 = system, >0 = custom brand style tenanta (Faza 6)',
  ascii_key                   VARCHAR(64)     NOT NULL,
  name                        VARCHAR(128)    NOT NULL,
  cinema_reference            VARCHAR(255)    NULL
                              COMMENT 'np. "Studio Ghibli / Makoto Shinkai"',
  thumbnail_asset_id          BIGINT UNSIGNED NULL
                              COMMENT 'FK logiczne do sh_assets — miniatura w Style Gallery',

  -- AI generation parameters (Faza 4 wypełni)
  ai_prompt_template          TEXT            NULL
                              COMMENT 'Template dla img2img — Faza 4',
  ai_model_ref                VARCHAR(128)    NULL
                              COMMENT 'np. replicate/flux-schnell',
  lora_ref                    VARCHAR(255)    NULL
                              COMMENT 'Reference do LoRA stylu (Faza 4)',

  -- UI / motion / audio per styl
  color_palette_json          JSON            NULL
                              COMMENT '{primary, secondary, accent, bg, text}',
  font_family                 VARCHAR(64)     NULL
                              COMMENT 'Google Font name lub system stack',
  motion_preset               VARCHAR(32)     NULL DEFAULT 'spring'
                              COMMENT 'spring / glass / vhs_glitch / slow_fade / instant',
  ambient_audio_ascii_key     VARCHAR(64)     NULL
                              COMMENT 'FK logiczne do sh_assets (audio bucket)',
  default_lut                 VARCHAR(64)     NULL
                              COMMENT 'np. warm_summer_evening / golden_hour / film_noir_bw',

  is_system                   TINYINT(1)      NOT NULL DEFAULT 0,
  is_active                   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_tenant_key (tenant_id, ascii_key),
  KEY idx_sp_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: biblioteka stylów wizualnych (Style Engine)';


-- ── A5. sh_category_styles ──────────────────────────────────────────────────
-- Aktywny styl per kategoria + audyt kosztów AI.

CREATE TABLE IF NOT EXISTS sh_category_styles (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED    NOT NULL,
  category_id         BIGINT UNSIGNED NOT NULL
                      COMMENT 'FK logiczne do sh_categories.id',
  style_preset_id     INT UNSIGNED    NOT NULL
                      COMMENT 'FK logiczne do sh_style_presets.id',

  applied_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_by_user_id  INT UNSIGNED    NULL,
  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  ai_cost_zl          DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                      COMMENT 'Audyt kosztów AI dla tej aplikacji stylu',

  PRIMARY KEY (id),
  KEY idx_cs_category_active (tenant_id, category_id, is_active),
  KEY idx_cs_style (style_preset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: aktywny styl per kategoria + historia';


-- ── A6. sh_scene_triggers ───────────────────────────────────────────────────
-- Automatyczne aktywowanie scen po dacie/godzinie/pogodzie/dniu.

CREATE TABLE IF NOT EXISTS sh_scene_triggers (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED    NOT NULL,
  scene_id            INT             NOT NULL
                      COMMENT 'FK logiczne do sh_atelier_scenes.id',

  trigger_rule_json   JSON            NOT NULL
                      COMMENT '{ date_range, time_range, weather, day_of_week }',

  priority            INT             NOT NULL DEFAULT 100
                      COMMENT 'Wyższy priorytet wygrywa gdy wiele triggers aktywnych',

  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  valid_from          DATETIME        NULL,
  valid_to            DATETIME        NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_st_scene_active (scene_id, is_active),
  KEY idx_st_tenant_active (tenant_id, is_active),
  KEY idx_st_validity (valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: automatyczne triggery scen (Faza 4 cron runner)';


-- ── A7. sh_scene_variants ───────────────────────────────────────────────────
-- Eksperymentalne warianty scen (AI On-Demand Variants, A/B testing).

CREATE TABLE IF NOT EXISTS sh_scene_variants (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  parent_scene_id     INT             NOT NULL
                      COMMENT 'FK logiczne do sh_atelier_scenes.id',

  variant_spec_json   JSON            NOT NULL
                      COMMENT 'Pełny DishSceneSpec wariantu',

  generated_by        ENUM('manual','ai_oneshot','ai_ab_test') NOT NULL DEFAULT 'manual',
  variant_label       VARCHAR(128)    NULL COMMENT 'np. "wieczorne", "drugi kąt", "winter mood"',

  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_sv_parent_active (parent_scene_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: warianty scen (Faza 4)';


-- ── A8. sh_ai_jobs ──────────────────────────────────────────────────────────
-- Queue zadań AI: style transform, background removal, enhancement, variant gen.

CREATE TABLE IF NOT EXISTS sh_ai_jobs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           INT UNSIGNED    NOT NULL,

  job_kind            ENUM('style_transform','background_remove','enhance',
                           'generate_variant','generate_placeholder')
                      NOT NULL,
  input_json          JSON            NOT NULL
                      COMMENT 'asset_id, target_style, prompt, settings',
  output_json         JSON            NULL
                      COMMENT 'result asset_id, generated metadata',

  status              ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  progress_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- Provider audit
  provider            VARCHAR(32)     NULL COMMENT 'replicate / cloudinary / openai / self_hosted',
  provider_job_id     VARCHAR(128)    NULL,
  cost_zl             DECIMAL(10,4)   NULL,

  -- Lifecycle
  started_at          DATETIME        NULL,
  finished_at         DATETIME        NULL,
  error_msg           TEXT            NULL,
  retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_aj_status_queued (status, created_at),
  KEY idx_aj_tenant_status (tenant_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M022: queue zadań AI (Faza 4 runner)';


-- =============================================================================
-- SEKCJA D: SEED — sh_scene_templates
-- 2 z PEŁNYMI metadanymi (gotowe do użycia w Fazie 2-3),
-- 6 placeholderów (wypełnione w Fazie 2 wraz z content Scene Kit).
-- =============================================================================

-- D1. pizza_top_down — pełne metadane
INSERT INTO sh_scene_templates (
  tenant_id, ascii_key, name, kind,
  stage_preset_json, composition_schema_json,
  available_cameras_json, available_luts_json, atmospheric_effects_json,
  photographer_brief_md, pipeline_preset_json,
  is_system, is_active
) VALUES (
  0, 'pizza_top_down', 'Pizza — kamera z góry', 'item',
  JSON_OBJECT(
    'aspect', '1/1',
    'background_kind', 'rustic_wood',
    'lighting', JSON_OBJECT('preset', 'warm_top', 'x', 50, 'y', 15),
    'vignette', 25,
    'grain', 6,
    'letterbox', 0
  ),
  JSON_OBJECT(
    'layers_required', JSON_ARRAY('base'),
    'layers_optional', JSON_ARRAY('sauce','cheese','meat','veg','herb','garnish'),
    'centerpiece', 'pizza',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 50, 'scale', 1.0)
  ),
  JSON_ARRAY('top_down', 'macro_close', 'wide_establishing'),
  JSON_ARRAY('warm_summer_evening', 'golden_hour', 'crisp_morning', 'teal_orange_blockbuster'),
  JSON_ARRAY('steam_rising', 'dust_particles_golden'),
  '## Pizza Top-Down — Brief Fotograficzny\n\n**Kamera:** prostopadle z góry, odległość 35-45cm od deski.\n**Światło:** naturalne z okna PO LEWEJ stronie, najlepiej 10:00-12:00.\n**Tło:** BIAŁY talerz lub czarna deska drewniana (NIE kuchenny blat).\n**Kompozycja:** pizza idealnie wycentrowana, lekki cień z prawej.\n\n**Czego unikać:**\n- Neonu kuchennego (szaro-zielony podkład).\n- Flesza telefonu (twardy cień).\n- Zdjęć pod kątem (my prostujemy, ale jakość spada).',
  JSON_OBJECT(
    'preset', 'appetizing',
    'auto_apply', true,
    'background_remove', true,
    'tone_map_to_kit', true,
    'add_drop_shadow', true
  ),
  1, 1
) ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  stage_preset_json = VALUES(stage_preset_json),
  composition_schema_json = VALUES(composition_schema_json),
  available_cameras_json = VALUES(available_cameras_json),
  available_luts_json = VALUES(available_luts_json),
  atmospheric_effects_json = VALUES(atmospheric_effects_json),
  photographer_brief_md = VALUES(photographer_brief_md),
  pipeline_preset_json = VALUES(pipeline_preset_json),
  updated_at = CURRENT_TIMESTAMP;

-- D2. static_hero — pełne metadane (uniwersalny fallback dla burger/makaron/itd.)
INSERT INTO sh_scene_templates (
  tenant_id, ascii_key, name, kind,
  stage_preset_json, composition_schema_json,
  available_cameras_json, available_luts_json, atmospheric_effects_json,
  photographer_brief_md, pipeline_preset_json,
  is_system, is_active
) VALUES (
  0, 'static_hero', 'Gotowe zdjęcie dania', 'item',
  JSON_OBJECT(
    'aspect', '4/3',
    'background_kind', 'neutral_wood',
    'lighting', JSON_OBJECT('preset', 'soft_box', 'x', 50, 'y', 30),
    'vignette', 20,
    'grain', 4,
    'letterbox', 0
  ),
  JSON_OBJECT(
    'layers_required', JSON_ARRAY('hero'),
    'layers_optional', JSON_ARRAY(),
    'centerpiece', 'hero',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 55, 'scale', 1.0)
  ),
  JSON_ARRAY('hero_three_quarter', 'top_down', 'macro_close'),
  JSON_ARRAY('warm_summer_evening', 'crisp_morning', 'golden_hour', 'cold_nordic'),
  JSON_ARRAY('steam_rising'),
  '## Static Hero — Brief Fotograficzny\n\n**Kamera:** kąt 3/4 (najlepszy dla burgerów, kanapek) lub z góry (dla makaronu, sałatki).\n**Światło:** miękkie, soft-box lub okno z dyfuzorem.\n**Tło:** neutralne (białe, beżowe, jasne drewno) — żeby nie konkurowało z daniem.\n**Kompozycja:** danie zajmuje ~70% kadru, mały oddech wokół.\n\n**Czego unikać:**\n- Głębokich, zimnych cieni (twardy flesz).\n- Mocnych refleksów na talerzu.\n- Tła kolorowo-wzorzystego.',
  JSON_OBJECT(
    'preset', 'appetizing',
    'auto_apply', true,
    'background_remove', true,
    'tone_map_to_kit', true,
    'add_drop_shadow', true
  ),
  1, 1
) ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  stage_preset_json = VALUES(stage_preset_json),
  composition_schema_json = VALUES(composition_schema_json),
  available_cameras_json = VALUES(available_cameras_json),
  available_luts_json = VALUES(available_luts_json),
  atmospheric_effects_json = VALUES(atmospheric_effects_json),
  photographer_brief_md = VALUES(photographer_brief_md),
  pipeline_preset_json = VALUES(pipeline_preset_json),
  updated_at = CURRENT_TIMESTAMP;

-- D3-D6. Placeholdery — Faza 2 wypełni metadane
INSERT INTO sh_scene_templates (tenant_id, ascii_key, name, kind, is_system, is_active) VALUES
  (0, 'pasta_bowl_placeholder',         'Makaron — miska (placeholder)',     'item', 1, 1),
  (0, 'beverage_bottle_placeholder',    'Napój — butelka (placeholder)',     'item', 1, 1),
  (0, 'burger_three_quarter_placeholder','Burger — kąt 3/4 (placeholder)',   'item', 1, 1),
  (0, 'sushi_top_down_placeholder',     'Sushi — z góry (placeholder)',      'item', 1, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  updated_at = CURRENT_TIMESTAMP;

-- D7-D8. Category templates (dla CategoryScene)
INSERT INTO sh_scene_templates (
  tenant_id, ascii_key, name, kind,
  stage_preset_json, composition_schema_json,
  is_system, is_active
) VALUES
  (0, 'category_flat_table', 'Kategoria — wspólny stół', 'category',
    JSON_OBJECT(
      'aspect', '16/10',
      'background_kind', 'rustic_wood',
      'lighting', JSON_OBJECT('preset', 'warm_diffused')
    ),
    JSON_OBJECT(
      'layout', 'grid_2x3',
      'max_items', 6,
      'item_thumb_size', 'medium'
    ),
    1, 1),

  (0, 'category_hero_wall', 'Kategoria — banner kategorii', 'category',
    JSON_OBJECT(
      'aspect', '16/9',
      'background_kind', 'dark_premium',
      'lighting', JSON_OBJECT('preset', 'dramatic_rim')
    ),
    JSON_OBJECT(
      'layout', 'hero_text',
      'tagline_visible', true
    ),
    1, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  stage_preset_json = VALUES(stage_preset_json),
  composition_schema_json = VALUES(composition_schema_json),
  updated_at = CURRENT_TIMESTAMP;


-- =============================================================================
-- SEKCJA E: SEED — sh_style_presets (12 stylów)
-- Pełne metadane wizualne (paleta, font, motion, LUT) — gotowe do użycia w UI.
-- AI prompts/LoRA puste — Faza 4 podłącza Replicate API i wypełnia.
-- =============================================================================

INSERT INTO sh_style_presets (
  tenant_id, ascii_key, name, cinema_reference,
  color_palette_json, font_family, motion_preset, default_lut,
  is_system, is_active
) VALUES
  (0, 'realistic', 'Realistyczny', 'baseline food photography',
    JSON_OBJECT('primary','#d97706','secondary','#92400e','accent','#fbbf24','bg','#0a0a0a','text','#fafafa'),
    'Inter', 'spring', 'warm_summer_evening', 1, 1),

  (0, 'pastel_watercolor', 'Pastelowy', 'Wes Anderson / akwarelowy',
    JSON_OBJECT('primary','#fda4af','secondary','#fbcfe8','accent','#fde68a','bg','#fef3c7','text','#3f3f46'),
    'Quicksand', 'glass', 'crisp_morning', 1, 1),

  (0, 'hand_drawn_ink', 'Rysowany', 'ink illustration book',
    JSON_OBJECT('primary','#1f2937','secondary','#374151','accent','#fbbf24','bg','#fafaf9','text','#0c0a09'),
    'Caveat', 'spring', 'crisp_morning', 1, 1),

  (0, 'anime_ghibli', 'Anime', 'Studio Ghibli / Makoto Shinkai',
    JSON_OBJECT('primary','#0ea5e9','secondary','#22d3ee','accent','#fde047','bg','#dbeafe','text','#1e3a8a'),
    'Mochiy Pop One', 'spring', 'crisp_morning', 1, 1),

  (0, 'pixar_3d', 'Pixar 3D', 'Ratatouille cinematic',
    JSON_OBJECT('primary','#ea580c','secondary','#facc15','accent','#22c55e','bg','#fef3c7','text','#1c1917'),
    'Fredoka', 'spring', 'golden_hour', 1, 1),

  (0, 'retro_80s_synthwave', 'Retro 80s', 'Stranger Things VHS',
    JSON_OBJECT('primary','#ec4899','secondary','#a855f7','accent','#22d3ee','bg','#0c0a1f','text','#fce7f3'),
    'Orbitron', 'vhs_glitch', 'teal_orange_blockbuster', 1, 1),

  (0, 'film_noir_bw', 'Film Noir', 'czarno-biały high-contrast',
    JSON_OBJECT('primary','#fafafa','secondary','#a3a3a3','accent','#ef4444','bg','#0a0a0a','text','#fafafa'),
    'Playfair Display', 'slow_fade', 'film_noir_bw', 1, 1),

  (0, 'cyberpunk_blade_runner', 'Cyberpunk', 'Blade Runner 2049',
    JSON_OBJECT('primary','#06b6d4','secondary','#f97316','accent','#fde047','bg','#020617','text','#cffafe'),
    'Rajdhani', 'glass', 'teal_orange_blockbuster', 1, 1),

  (0, 'cottagecore_rustic', 'Cottagecore', 'warm rustic illustration',
    JSON_OBJECT('primary','#84cc16','secondary','#a16207','accent','#fbbf24','bg','#fef3c7','text','#3f2e1c'),
    'Merriweather', 'slow_fade', 'warm_summer_evening', 1, 1),

  (0, 'minimalist_editorial', 'Minimalistyczny', 'white space clean',
    JSON_OBJECT('primary','#0a0a0a','secondary','#52525b','accent','#0ea5e9','bg','#fafafa','text','#0a0a0a'),
    'Inter', 'glass', 'crisp_morning', 1, 1),

  (0, 'pop_art_lichtenstein', 'Pop Art', 'comic book halftone',
    JSON_OBJECT('primary','#dc2626','secondary','#facc15','accent','#0ea5e9','bg','#fef3c7','text','#0a0a0a'),
    'Bangers', 'spring', 'teal_orange_blockbuster', 1, 1),

  (0, 'vintage_50s_diner', 'Vintage 50s', 'americana pastel',
    JSON_OBJECT('primary','#06b6d4','secondary','#fbbf24','accent','#ef4444','bg','#fef3c7','text','#3f3f46'),
    'Lobster', 'spring', 'warm_summer_evening', 1, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  cinema_reference = VALUES(cinema_reference),
  color_palette_json = VALUES(color_palette_json),
  font_family = VALUES(font_family),
  motion_preset = VALUES(motion_preset),
  default_lut = VALUES(default_lut),
  updated_at = CURRENT_TIMESTAMP;


-- =============================================================================
-- KONIEC MIGRACJI 022
--
-- Co dalej (wykonywane przez setup_database.php):
--   1. Idempotentne ALTERy istniejących tabel (sh_menu_items, sh_categories,
--      sh_atelier_scenes, sh_board_companions) — przez INFORMATION_SCHEMA guard
--   2. INSERT IGNORE do sh_tenant_settings dla AI budget keys
--   3. Verify counts: 8 scene_templates, 12 style_presets
-- =============================================================================
