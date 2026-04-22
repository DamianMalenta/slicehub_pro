-- =============================================================================
-- Migration 025 — Drop Legacy Magic Dictionary & Ingredient Assets
-- =============================================================================
-- CEL (Faza 2 · Cleanup po sesji 2.9):
--   Po wprowadzeniu `sh_asset_links` (m021) + `sh_modifiers.has_visual_impact`
--   (m024) oraz nowego edytora w Menu Studio ("Surface — wizualne sloty"),
--   stary mechanizm „Magic Dictionary" (m018) i shadow-tabela składników
--   (m014 → sh_ingredient_assets) stały się redundantne.
--
--   Stanowisko projektowe: system jest w fazie kreacji, nie produkcji — dlatego
--   usuwamy techniczny dług od razu, zamiast utrzymywać dwa równoległe
--   rejestry modifier-wizualizacji.
--
-- DROPUJEMY (idempotentnie):
--   1. sh_modifier_visual_map      (całość — m018)
--   2. sh_ingredient_assets        (całość — m014, zastąpione backfillem m021)
--   3. v_modifier_icon             (widok — nie ma już sensu bez asset_links role)
--   4. sh_asset_links(role='modifier_icon')  (backfillowane rekordy z m021 sekcja 4)
--
-- NIE DOTYKAMY:
--   • sh_assets (zostaje — część unified library)
--   • sh_asset_links (zostaje — poza DELETE rekordów modifier_icon)
--   • sh_modifiers.has_visual_impact (m024 — nadal używane)
--   • v_menu_item_hero, v_visual_layer_asset (nadal przydatne)
--
-- Wszystkie operacje idempotentne — bezpieczne w ponownym uruchomieniu.
-- =============================================================================

-- ── 1. DROP sh_modifier_visual_map ──────────────────────────────────────────
DROP TABLE IF EXISTS sh_modifier_visual_map;

-- ── 2. DROP VIEW v_modifier_icon (zależy od sh_asset_links.role) ────────────
DROP VIEW IF EXISTS v_modifier_icon;

-- ── 3. Skasuj rekordy z rolą 'modifier_icon' z sh_asset_links ───────────────
-- (backfillowane w m021 sekcja 4 z sh_ingredient_assets)
DELETE FROM sh_asset_links WHERE role = 'modifier_icon';

-- ── 4. DROP sh_ingredient_assets (shadow-table) ─────────────────────────────
DROP TABLE IF EXISTS sh_ingredient_assets;

-- ── 5. Znacznik migracji ────────────────────────────────────────────────────
SELECT '[M025] Legacy Magic Dictionary + Ingredient Assets dropped.' AS note;
