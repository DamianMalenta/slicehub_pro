-- =============================================================================
-- SliceHub Pro — Migration 023: Scene Templates Content (Faza 2.1)
--
-- Wypełnienie metadanych dla 4 placeholder templates z Fazy 1 (pasta_bowl,
-- beverage_bottle, burger_three_quarter, sushi_top_down) + 2 category
-- templates (category_flat_table, category_hero_wall).
--
-- ZASADY:
--   - Idempotent (UPDATE ... WHERE — safe re-run)
--   - Additive only (zero DROP, zero struktur)
--   - Ascii_keys ZOSTAJĄ z suffixem '_placeholder' dla template items —
--     aby nie łamać referencji z wcześniej zapisanych dań. W Fazie 2.2 przy
--     seed'owaniu realnych assetów dodamy aliasy bez suffixu.
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- =============================================================================
-- SEKCJA A: Pasta Bowl Placeholder — makaron w misce, kamera 3/4
-- =============================================================================
UPDATE sh_scene_templates SET
  name = 'Makaron — miska 3/4 kąt',
  stage_preset_json = JSON_OBJECT(
    'aspect', '4/3',
    'background_kind', 'linen_beige',
    'lighting', JSON_OBJECT('preset', 'warm_window_left', 'x', 30, 'y', 20),
    'vignette', 18,
    'grain', 3,
    'letterbox', 0
  ),
  composition_schema_json = JSON_OBJECT(
    'layers_required', JSON_ARRAY('hero'),
    'layers_optional', JSON_ARRAY('garnish'),
    'centerpiece', 'bowl',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 60, 'scale', 1.0)
  ),
  available_cameras_json = JSON_ARRAY('hero_three_quarter', 'macro_close', 'top_down'),
  available_luts_json = JSON_ARRAY('warm_summer_evening', 'golden_hour', 'crisp_morning', 'bleach_bypass'),
  atmospheric_effects_json = JSON_ARRAY('steam_rising', 'dust_particles_golden'),
  photographer_brief_md = '## Pasta Bowl — Brief Fotograficzny

**Kamera:** kąt 3/4 (30-45°) od góry, odległość 40-50cm.
**Światło:** miękkie, naturalne z okna PO LEWEJ stronie, dodatkowy rim-light z prawej dla głębi.
**Tło:** beżowy lniany obrus lub jasne drewno, rozmyte tło (bokeh).
**Kompozycja:** miska zajmuje ~60% kadru, widelec lub drewniane pałeczki obok (opcjonalnie zwinięty makaron na widelcu — „glamour shot"). Odrobina świeżej bazylii/pietruszki na wierzchu.
**Para:** najlepiej gdy danie jest gorące — para dodaje realizmu.

**Czego unikać:**
- Zimnego makaronu (brak pary, zastygnięty sos = martwe zdjęcie).
- Płaskiego, top-down kadru (to dla pizzy, nie miski).
- Tła kolorowo-wzorzystego (odwraca uwagę od dania).',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'appetizing',
    'auto_apply', true,
    'background_remove', true,
    'tone_map_to_kit', true,
    'add_drop_shadow', true,
    'warm_boost', 0.15
  )
WHERE ascii_key = 'pasta_bowl_placeholder' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA B: Beverage Bottle Placeholder — butelka/kubek, kamera z boku
-- =============================================================================
UPDATE sh_scene_templates SET
  name = 'Napój — butelka / kubek (boczny)',
  stage_preset_json = JSON_OBJECT(
    'aspect', '3/4',
    'background_kind', 'condensation_glass',
    'lighting', JSON_OBJECT('preset', 'studio_softbox_rim', 'x', 30, 'y', 30),
    'vignette', 12,
    'grain', 2,
    'letterbox', 0
  ),
  composition_schema_json = JSON_OBJECT(
    'layers_required', JSON_ARRAY('hero'),
    'layers_optional', JSON_ARRAY('condensation', 'label_foreground'),
    'centerpiece', 'bottle',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 55, 'scale', 1.1)
  ),
  available_cameras_json = JSON_ARRAY('hero_eye_level', 'slight_low_angle', 'macro_close'),
  available_luts_json = JSON_ARRAY('cold_nordic', 'crisp_morning', 'teal_orange_blockbuster'),
  atmospheric_effects_json = JSON_ARRAY('condensation_drops', 'dust_particles_golden'),
  photographer_brief_md = '## Beverage Bottle — Brief Fotograficzny

**Kamera:** poziom oczu (eye-level) lub lekko z dołu (low angle 5-10°) dla heroicznego efektu.
**Światło:** dwa softboxy — główne z boku (45°), kontra z tyłu (rim light) aby wyciągnąć krawędzie butelki i kondensację.
**Tło:** ciemne (granatowe, czarne) lub neutralne szare z efektem motion blur — napój ma być bohaterem.
**Butelka:** schłodzona z lodówki, krople kondensacji na szkle. Etykieta idealnie czytelna.
**Kompozycja:** butelka lekko po lewej od centrum (zasada trzech), przestrzeń negatywna po prawej dla ceny.

**Czego unikać:**
- Zdjęć z góry (tracimy proporcje butelki, wyglądają jak lekarstwo).
- Butelki w pełnym słońcu (odblaski na etykiecie = nieczytelne).
- Wody w szklance bez lodu (amator-look).',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'studio',
    'auto_apply', true,
    'background_remove', true,
    'tone_map_to_kit', true,
    'add_drop_shadow', true,
    'enhance_label', true
  )
WHERE ascii_key = 'beverage_bottle_placeholder' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA C: Burger Three-Quarter Placeholder — burger z kąta 3/4
-- =============================================================================
UPDATE sh_scene_templates SET
  name = 'Burger — kąt 3/4 (signature shot)',
  stage_preset_json = JSON_OBJECT(
    'aspect', '1/1',
    'background_kind', 'rustic_wood_dark',
    'lighting', JSON_OBJECT('preset', 'warm_rim_both', 'x', 50, 'y', 25),
    'vignette', 30,
    'grain', 7,
    'letterbox', 0
  ),
  composition_schema_json = JSON_OBJECT(
    'layers_required', JSON_ARRAY('hero'),
    'layers_optional', JSON_ARRAY('side_dish', 'sauce_drip'),
    'centerpiece', 'burger',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 55, 'scale', 1.05)
  ),
  available_cameras_json = JSON_ARRAY('hero_three_quarter', 'hero_eye_level', 'macro_close'),
  available_luts_json = JSON_ARRAY('warm_summer_evening', 'golden_hour', 'hot_mexican', 'bleach_bypass'),
  atmospheric_effects_json = JSON_ARRAY('steam_rising', 'sauce_drip', 'dust_particles_golden'),
  photographer_brief_md = '## Burger 3/4 — Brief Fotograficzny

**Kamera:** kąt 3/4 (około 30° od poziomu), odległość 25-35cm. Żywy burger = widoczne 3 warstwy.
**Światło:** ciepły rim light z tyłu (żółto-pomarańczowy) + główne światło z boku (neutralne białe). Rim najważniejszy — oddziela burger od tła.
**Tło:** ciemne drewno rustykalne, łupek, metalowa taca — męski klimat steakhouse.
**Akcesoria:** frytki obok (w papierowym rożku lub małym wiaderku), kapsla od cola. OPCJONALNIE: kropla sosu spływająca z bułki.
**Bułka:** lekko posypana sezamem, świeża, NIE zgnieciona — górna bułka w połowie zsunięta dla pokazu warstw.

**Czego unikać:**
- Zimnego burgera (ser musi się lekko topić).
- Zdjęć top-down (tracimy warstwy — to nie pizza!).
- Białego tła (burger zlewa się, brak głębi).',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'dramatic',
    'auto_apply', true,
    'background_remove', false,
    'tone_map_to_kit', true,
    'add_drop_shadow', true,
    'warm_boost', 0.25,
    'contrast_boost', 0.15
  )
WHERE ascii_key = 'burger_three_quarter_placeholder' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA D: Sushi Top-Down Placeholder — sushi, kamera z góry
-- =============================================================================
UPDATE sh_scene_templates SET
  name = 'Sushi — kamera z góry (board)',
  stage_preset_json = JSON_OBJECT(
    'aspect', '16/10',
    'background_kind', 'slate_dark',
    'lighting', JSON_OBJECT('preset', 'studio_soft_top', 'x', 50, 'y', 0),
    'vignette', 20,
    'grain', 4,
    'letterbox', 0
  ),
  composition_schema_json = JSON_OBJECT(
    'layers_required', JSON_ARRAY('hero'),
    'layers_optional', JSON_ARRAY('garnish', 'chopsticks', 'soy_sauce_bowl'),
    'centerpiece', 'sushi_board',
    'centerpiece_position', JSON_OBJECT('x', 50, 'y', 50, 'scale', 1.0)
  ),
  available_cameras_json = JSON_ARRAY('top_down', 'macro_close', 'dutch_angle'),
  available_luts_json = JSON_ARRAY('cold_nordic', 'film_noir_bw', 'crisp_morning', 'teal_orange_blockbuster'),
  atmospheric_effects_json = JSON_ARRAY('dust_particles_golden'),
  photographer_brief_md = '## Sushi Top-Down — Brief Fotograficzny

**Kamera:** prostopadle z góry (90°), odległość 40-50cm. Cała deska w kadrze.
**Światło:** soft-box nad stołem (central top), lekki rim z boku. Sushi ma subtelny połysk — nie twardy, nie matowy.
**Tło:** czarny łupek, ciemne drewno bambusowe, czarny marmur — kontrastuje z ryżem.
**Akcesoria:** pałeczki drewniane skośnie w rogu, małe naczynko z sosem sojowym, listek wasabi. OPCJONALNIE: imbir marinowany w rogu.
**Układ:** sushi NIE w rzędzie — naturalnie rozrzucone, lekko pod różnymi kątami dla dynamiki.

**Czego unikać:**
- Zdjęć pod kątem (top-down to tradycja w food photography sushi).
- Białego tła (ryż się zlewa, tracimy kontrast).
- Rozwinietych rolek (wygląda jak pomyłka; chyba że to celowy art shot).',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'studio',
    'auto_apply', true,
    'background_remove', true,
    'tone_map_to_kit', true,
    'add_drop_shadow', true,
    'sharpness_boost', 0.2
  )
WHERE ascii_key = 'sushi_top_down_placeholder' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA E: Category Flat Table — uzupełnienie metadanych
-- =============================================================================
UPDATE sh_scene_templates SET
  composition_schema_json = JSON_OBJECT(
    'layout', 'grid_2x3',
    'max_items', 6,
    'item_thumb_size', 'medium',
    'spacing_px', 32,
    'allow_item_reorder', true,
    'show_prices', true,
    'show_cta', true,
    'cta_style', 'glass_dark'
  ),
  available_cameras_json = JSON_ARRAY('wide_establishing', 'top_down'),
  available_luts_json = JSON_ARRAY('warm_summer_evening', 'golden_hour', 'crisp_morning'),
  atmospheric_effects_json = JSON_ARRAY('dust_particles_golden', 'candle_glow'),
  photographer_brief_md = '## Category Flat Table — Brief dla Managera

**Kiedy używać:** kategoria ma 2-6 pozycji, wszystkie można zmieścić na jednym wspólnym stole (np. sosy, napoje, desery).

**Manager:** w edytorze wybierz stół (Scene Kit background), rozłóż items drag&drop, każdy z własną ceną i CTA „Dodaj". Klient widzi jedną dioramę z wszystkimi pozycjami naraz.

**Nie używać gdy:** więcej niż 6 pozycji (user traci overview) lub pozycje wymagają indywidualnej scenografii (użyj `individual`).',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'appetizing',
    'auto_apply', true,
    'unified_lighting', true,
    'unified_background_removal', true
  )
WHERE ascii_key = 'category_flat_table' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA F: Category Hero Wall — uzupełnienie metadanych
-- =============================================================================
UPDATE sh_scene_templates SET
  composition_schema_json = JSON_OBJECT(
    'layout', 'hero_text',
    'tagline_visible', true,
    'subtitle_visible', true,
    'cta_visible', true,
    'cta_text_default', 'Zobacz wszystkie',
    'text_position', 'left_center',
    'text_max_width_percent', 50
  ),
  available_cameras_json = JSON_ARRAY('wide_establishing', 'hero_three_quarter'),
  available_luts_json = JSON_ARRAY('film_noir_bw', 'teal_orange_blockbuster', 'golden_hour', 'bleach_bypass'),
  atmospheric_effects_json = JSON_ARRAY('dust_particles_golden', 'sun_rays', 'candle_glow'),
  photographer_brief_md = '## Category Hero Wall — Brief dla Managera

**Kiedy używać:** otwarcie kategorii premium (np. „Pizze", „Desery autorskie") — pierwsza diorama w sekwencji indywidualnych scen (layout_mode=hybrid).

**Struktura:** duże dramatyczne zdjęcie flagowego produktu + tagline (np. „NAJLEPSZE PIZZE W MIEŚCIE") + subtitle + CTA „Zobacz wszystkie →".

**Manager:** wybierz hero_asset (jedno zdjęcie flagowca kategorii), ustaw tagline + subtitle. Kolejne dioramy to dania indywidualne.

**Nie używać gdy:** kategoria ma < 3 pozycje (overkill) lub layout_mode = legacy_list / grouped.',
  pipeline_preset_json = JSON_OBJECT(
    'preset', 'dramatic',
    'auto_apply', true,
    'warm_boost', 0.2,
    'contrast_boost', 0.3
  )
WHERE ascii_key = 'category_hero_wall' AND tenant_id = 0;


-- =============================================================================
-- SEKCJA G: Zarezerwowane slot'y scene_kit_assets_json (puste tablice — Faza 2.2 wypełni)
-- Tylko dla templates, które nie mają jeszcze tego pola ustawionego.
-- =============================================================================
UPDATE sh_scene_templates
SET scene_kit_assets_json = JSON_OBJECT(
  'backgrounds', JSON_ARRAY(),
  'props',       JSON_ARRAY(),
  'lights',      JSON_ARRAY(),
  'badges',      JSON_ARRAY()
)
WHERE tenant_id = 0
  AND scene_kit_assets_json IS NULL;


-- =============================================================================
-- KONIEC MIGRACJI 023
--
-- Po tej migracji:
--   - Wszystkie 8 system scene_templates mają pełne metadane
--   - 4 placeholder templates dostały briefy fotograficzne + pipeline presets
--   - 2 category templates dostały kompletne composition_schema + briefy
--   - Każdy template ma zarezerwowane scene_kit_assets_json (puste tablice)
--     gotowe do wypełnienia asset_id w Fazie 2.2
-- =============================================================================
