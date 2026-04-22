-- =============================================================================
-- Migration 030 — Scene Harmony Cache (Faza 2.4 · G4 Harmony Score)
-- -----------------------------------------------------------------------------
-- Cel: domknięcie fundamentu jakości sceny w Online Studio.
--
--   sh_scene_metrics — cache metryki spójności sceny (0–100). Manager widzi
--   numeryczny badge w Viewport, system blokuje publikację gdy score < 50.
--   Rozwiązanie oparte o wariancję cal_scale/cal_rotate/brightness/saturation/
--   feather w obrębie sceny + median check per typ składnika (MagicBake zna typy).
--
-- Konstytucja:
--   • Klucz = scene_id (1:1 z sh_atelier_scenes.id).
--   • tenant_id duplikowany dla szybkich JOIN-ów w Style Conductor / raportach
--     (Prawo "każdy SELECT ma tenant_id").
--   • outliers_json i variance_json NULLable — jeśli worker nie policzył jeszcze,
--     frontend przelicza na żądanie i upserts.
--
-- Audyt: tabela jest BEZ historii (metryka = live cache). History zmian sceny
-- żyje w sh_atelier_scene_history (m020).
--
-- Powiązane: _docs/15_KIERUNEK_ONLINE.md § 2.4.
-- =============================================================================

SET NAMES utf8mb4;

-- ── sh_scene_metrics ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sh_scene_metrics (
    scene_id            INT UNSIGNED            NOT NULL
        COMMENT 'FK → sh_atelier_scenes.id (1:1)',
    tenant_id           INT UNSIGNED            NOT NULL
        COMMENT 'duplikat z sh_atelier_scenes dla szybkich filtrów',
    harmony_score       TINYINT UNSIGNED        NOT NULL DEFAULT 0
        COMMENT '0–100, 0=brak metryki, 50=próg publikacji, 70=toast OK, 100=perfekcyjna',
    layer_count         SMALLINT UNSIGNED       NOT NULL DEFAULT 0
        COMMENT 'liczba warstw w momencie ostatniego liczenia (dla snapshot-diff)',
    outliers_json       JSON                    NULL
        COMMENT 'lista layerSku z outliem jakości: [{ layerSku, reason: "scale|rotate|brightness|feather", delta: 0.4 }]',
    variance_json       JSON                    NULL
        COMMENT 'breakdown wariancji per metric: { scale: 0.04, rotate: 3.2, brightness: 0.02, saturation: 0.01, feather: 6.1 }',
    last_computed_at    DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'dodaje się przy każdym INSERT/UPDATE (frontend upserts)',
    PRIMARY KEY (scene_id),
    INDEX idx_tenant_score (tenant_id, harmony_score)
        COMMENT 'dla Style Conductor: WHERE tenant_id=X AND harmony_score<70',
    CONSTRAINT fk_scene_metrics_scene
        FOREIGN KEY (scene_id) REFERENCES sh_atelier_scenes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Faza 2.4 · G4 Harmony Score cache — metryka jakości sceny';
