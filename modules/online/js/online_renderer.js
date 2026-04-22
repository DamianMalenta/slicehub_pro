/**
 * SliceHub Online — shared scene mount for storefront surfaces.
 */
import { SharedSceneRenderer } from '../../../core/js/surface/SharedSceneRenderer.js';

/**
 * G6 · Living Scene — mapuje ascii_key efektu (z sh_scene_templates) na
 * klasę CSS zdefiniowaną w modules/online/css/living-scene.css.
 *
 * Nieznany klucz = brak rendera (graceful).
 */
const EFFECT_CLASS = {
    steam_rising:          'scene-effect--steam',
    dust_particles_golden: 'scene-effect--dust',
    condensation_drops:    'scene-effect--condensation',
    sauce_drip:            'scene-effect--drip',
    candle_glow:           'scene-effect--candle',
    sun_rays:              'scene-effect--rays',
    breath:                'scene-effect--breath',
};

/**
 * Dodaje overlay `.scene-effects` nad istniejącymi warstwami.
 *
 * @param {HTMLElement} host — .pizza-scene lub .pizza-scene-root
 * @param {Array<string>|null} effects — np. ['steam_rising','dust_particles_golden']
 */
export function applyAtmosphericEffects(host, effects) {
    if (!host) return;
    host.querySelectorAll(':scope > .scene-effects').forEach((n) => n.remove());
    if (!Array.isArray(effects) || !effects.length) return;

    const wrap = document.createElement('div');
    wrap.className = 'scene-effects';
    let rendered = 0;
    effects.forEach((key) => {
        const cls = EFFECT_CLASS[String(key)];
        if (!cls) return;
        const el = document.createElement('div');
        el.className = `scene-effect ${cls}`;
        el.setAttribute('aria-hidden', 'true');
        wrap.appendChild(el);
        rendered++;
    });
    if (rendered > 0) host.appendChild(wrap);
}

export function mountPizzaScene(root, payload) {
    if (!root) return;
    const {
        layersA = [],
        layersB = [],
        mode = 'full',
        effects = null,
        camera = null,
        stage = null,
        companions = [],
        infoBlock = null,
        ambient = null,
        dishMeta = null,
        sceneSpec = null,
        backend = 'dom',
        sceneMode = 'preview',
    } = payload || {};

    root.innerHTML = '';
    root.className = 'pizza-scene';
    root.dataset.mode = mode;

    const sourceSpec = sceneSpec && typeof sceneSpec === 'object'
        ? structuredClone(sceneSpec)
        : {};
    const spec = {
        stage: {
            aspect: '16/10',
            lightX: 50,
            lightY: 15,
            lightIntensity: 14,
            grainIntensity: 6,
            vignetteIntensity: 35,
            lutName: 'none',
            letterbox: 0,
            ...(sourceSpec.stage || {}),
            ...(stage || {}),
        },
        pizza: {
            x: 36,
            y: 56,
            scale: 1.08,
            rotation: 0,
            visible: true,
            ...(sourceSpec.pizza || {}),
            layers: layersA,
            secondaryLayers: layersB,
            mode,
        },
        companions: Array.isArray(companions) ? companions : (sourceSpec.companions || []),
        infoBlock: infoBlock === false
            ? { visible: false }
            : {
                visible: true,
                x: 56,
                y: 10,
                w: 34,
                h: 22,
                theme: 'glass-dark',
                align: 'left',
                bgOpacity: 0.82,
                ...(sourceSpec.infoBlock || {}),
                ...(infoBlock || {}),
            },
        modifierGrid: {
            visible: false,
            position: 'below-stage',
            ...(sourceSpec.modifierGrid || {}),
        },
        ambient: {
            crumbs: { count: 0, seed: dishMeta?.name || 'surface' },
            steam: { count: 0, intensity: 50 },
            oilSheen: { enabled: false, x: 45, y: 35 },
            ...(sourceSpec.ambient || {}),
            ...(ambient || {}),
        },
        _dishMeta: dishMeta || sourceSpec._dishMeta || {},
    };

    const renderer = new SharedSceneRenderer(root, { backend });
    renderer.render(spec, {
        editable: false,
        selectedId: null,
        camera,
        backend,
        sceneMode,
    });

    const stageEl = root.querySelector('.sr-stage') || root;
    applyAtmosphericEffects(stageEl, effects);
}

/**
 * Fallback neon (wymaga window.NeonPizza + kontenera kwadratowego).
 */
export function tryNeonFallback(mountEl, modifierNames) {
    if (!mountEl || typeof window.NeonPizza === 'undefined') return false;
    const names = (modifierNames || []).filter(Boolean);
    window.NeonPizza.renderPizza(mountEl, names, { size: 280 });
    return true;
}
