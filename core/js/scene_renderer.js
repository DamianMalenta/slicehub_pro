/**
 * SliceHub Unified Scene Renderer — SSOT dla renderowania warstw dania.
 * ═══════════════════════════════════════════════════════════════════════════
 * M2 · Unifikacja rendererów (2026-04-19).
 *
 * Jeden moduł ES6 odpowiedzialny za matematykę renderu warstwy pizzy/dania:
 *   zIndex, calScale, calRotate, offsetX, offsetY, blendMode, alpha,
 *   brightness, saturation, hueRotate, shadowStrength, feather.
 *
 * Cel: WYSIWYG — co widzi manager w Directorze dokładnie tak samo widzi
 * klient w Storefroncie. Zero dryfu w liczeniu transformów/filtrów.
 *
 * Używany przez:
 *   - modules/online/js/online_renderer.js             (Storefront kard)
 *   - modules/online_studio/js/director/renderer/...   (Director viewport)
 *   - modules/studio/js/studio_*.js                    (Menu Studio preview — faza następna)
 *
 * Ta warstwa nie zajmuje się: stagem, LUT, ambient, companions, infoBlock,
 * promotion badges, grain/vignette/lighting. To robi `SceneComposer` w
 * Directorze (pełna scena) i może w przyszłości robić też Storefront.
 * ═══════════════════════════════════════════════════════════════════════════
 */

/**
 * Normalizuje URL assetu do formy akceptowalnej przez <img src>.
 * Obsługuje absolute (http/data/blob), `/slicehub/...`, `/...`, relative.
 */
export function resolveAssetUrl(u) {
    if (!u) return '';
    const s = String(u).trim();
    if (!s) return '';
    if (/^(https?:|data:|blob:)/i.test(s)) return s;
    if (s.startsWith('/slicehub/') || s === '/slicehub') return s;
    if (s.startsWith('/')) return '/slicehub' + s;
    return '/slicehub/' + s;
}

/**
 * Zwraca kopię warstw posortowaną po zIndex rosnąco (stabilnie).
 */
export function sortLayers(layers) {
    return [...(layers || [])]
        .map((L, i) => ({ L, i }))
        .sort((a, b) => {
            const za = a.L.zIndex || 0;
            const zb = b.L.zIndex || 0;
            return za !== zb ? za - zb : a.i - b.i;
        })
        .map(x => x.L);
}

/**
 * Buduje filtr CSS łączący brightness/saturate/hueRotate/shadow dla warstwy.
 */
function buildLayerFilterString(L) {
    const parts = [];
    if (L.brightness && L.brightness !== 1) parts.push(`brightness(${L.brightness})`);
    if (L.saturation && L.saturation !== 1) parts.push(`saturate(${L.saturation})`);
    if (L.hueRotate) parts.push(`hue-rotate(${L.hueRotate}deg)`);
    const shadow = L.shadowStrength;
    if (shadow != null && shadow > 0) {
        parts.push(`drop-shadow(2px 3px 3px rgba(30,15,5,${shadow}))`);
    }
    return parts.join(' ');
}

/**
 * Buduje maskę piórka (feathering) dla warstwy jeśli zdefiniowana.
 */
function buildLayerMask(L) {
    if (!(L.feather > 0)) return null;
    const stop = Math.max(0, 100 - L.feather);
    return `radial-gradient(circle, black ${stop}%, transparent 100%)`;
}

/**
 * Buduje kompletny element DOM dla pojedynczej warstwy (wrap > inner > img).
 *
 * Zwraca null jeśli warstwa jest ukryta (visible === false).
 *
 * @param {object} L — layer dict z `sh_atelier_scenes.spec_json.pizza.layers[n]`
 * @param {object} opts
 *   - classes:  { wrap, inner, img } (domyślnie klasy Storefrontu)
 *   - transformMode: 'cssVars' (Storefront — CSS czyta zmienne) lub
 *                    'inline'  (Director — style.transform = ...)
 *   - dataSceneId: opcjonalnie ustawia wrap.dataset.sceneId
 *   - imgAlt:     alt dla <img>
 * @returns {HTMLElement|null}
 */
export function buildLayerElement(L, opts = {}) {
    if (!L || L.visible === false) return null;

    const classes = opts.classes || {
        wrap:  'pizza-layer',
        inner: 'layer-visual',
        img:   'layer-img',
    };
    const mode = opts.transformMode || 'cssVars';

    const wrap = document.createElement('div');
    wrap.className = classes.wrap;
    wrap.style.zIndex = String(L.zIndex ?? 0);
    if (L.layerSku) wrap.dataset.layerSku = String(L.layerSku);
    if (opts.dataSceneId) wrap.dataset.sceneId = String(opts.dataSceneId);

    if (L.blendMode) wrap.style.mixBlendMode = L.blendMode;
    if (L.alpha != null && L.alpha !== 1) wrap.style.opacity = String(L.alpha);

    const filterStr = buildLayerFilterString(L);
    if (filterStr) wrap.style.filter = filterStr;

    const mask = buildLayerMask(L);
    if (mask) {
        wrap.style.maskImage = mask;
        wrap.style.webkitMaskImage = mask;
    }

    const inner = document.createElement('div');
    inner.className = classes.inner;

    const sc  = L.calScale  != null ? Number(L.calScale)  : 1;
    const rot = L.calRotate != null ? Number(L.calRotate) : 0;
    const ox  = L.offsetX   != null ? Number(L.offsetX)   : 0;
    const oy  = L.offsetY   != null ? Number(L.offsetY)   : 0;

    if (mode === 'cssVars') {
        inner.style.setProperty('--cal-scale',  String(sc));
        inner.style.setProperty('--cal-rotate', `${rot}deg`);
        if (ox !== 0) inner.style.setProperty('--cal-offset-x', `${ox * 100}%`);
        if (oy !== 0) inner.style.setProperty('--cal-offset-y', `${oy * 100}%`);
    } else {
        inner.style.transform =
            `translate(${ox * 100}%, ${oy * 100}%) scale(${sc}) rotate(${rot}deg)`;
    }

    const img = document.createElement('img');
    img.className = classes.img;
    img.alt = opts.imgAlt || '';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.draggable = false;
    const src = resolveAssetUrl(L.assetUrl);
    if (src) img.src = src;
    else img.style.opacity = '0';

    inner.appendChild(img);
    wrap.appendChild(inner);
    return wrap;
}

/**
 * Mountuje listę warstw do kontenera DOM (czyści go wcześniej jeśli clearMount).
 *
 * @param {HTMLElement} mount — kontener (dzisiaj: `.pizza-disk__stack` lub `.sr-disk`)
 * @param {Array} layers
 * @param {object} opts
 *   - classes, transformMode, imgAlt — przekazywane do buildLayerElement
 *   - clearMount:   (bool, default true) — wyczyść innerHTML przed mountem
 *   - selectedId:   (string) — np. 'layer:PEPPERONI' → dodaje klasę `sr--selected`
 *   - onLayerBuilt: (el, L, index) => void — callback po stworzeniu każdej warstwy
 */
export function renderLayersInto(mount, layers, opts = {}) {
    if (!mount) return 0;
    const clear = opts.clearMount !== false;
    if (clear) mount.innerHTML = '';

    const sorted = sortLayers(layers);
    let mounted = 0;

    sorted.forEach((L, idx) => {
        const sceneId = L.layerSku ? `layer:${L.layerSku}` : undefined;
        const el = buildLayerElement(L, {
            classes:       opts.classes,
            transformMode: opts.transformMode,
            dataSceneId:   sceneId,
            imgAlt:        opts.imgAlt,
        });
        if (!el) return;

        if (opts.selectedId && sceneId === opts.selectedId) {
            el.classList.add('sr--selected');
        }

        if (typeof opts.onLayerBuilt === 'function') {
            try { opts.onLayerBuilt(el, L, idx); }
            catch (e) { console.warn('[SceneRenderer] onLayerBuilt threw:', e); }
        }

        mount.appendChild(el);
        mounted++;
    });

    return mounted;
}

/**
 * Wybiera tryb renderowania miniatury dania: 'scene' | 'hero' | 'placeholder'.
 *
 * Reguły (deterministyczne, dzielone między wszystkie listy dań):
 *   - 'scene'       — gdy lista warstw ma ≥2 warstwy LUB ≥1 warstwę, która nie
 *                     jest pojedynczym auto-generowanym BASE (czyli ma
 *                     prawdziwą kompozycję z modyfikatorów/sceny).
 *   - 'hero'        — gdy nie ma „sensownej sceny", ale jest `imageUrl`.
 *   - 'placeholder' — nie ma ani sceny, ani hero.
 *
 * Ta reguła zapobiega sytuacji gdy auto-generator sceny (który wrzuca tylko
 * hero dania jako pierwszą warstwę BASE) powoduje, że miniatura wygląda
 * inaczej niż w karuzeli popularnych (gdzie back-end nie zwraca warstw).
 *
 * @param {object} item — obiekt dania z listy (visualLayers, imageUrl, ...)
 * @returns {'scene'|'hero'|'placeholder'}
 */
export function pickRenderMode(item) {
    if (!item || typeof item !== 'object') return 'placeholder';
    const layers = Array.isArray(item.visualLayers) ? item.visualLayers : [];

    if (layers.length >= 2) return 'scene';

    if (layers.length === 1) {
        const L = layers[0];
        const isAutoHeroBase =
            L && (L.source === 'auto_hero' || L.isBase === true);
        if (!isAutoHeroBase) return 'scene';
    }

    if (item.imageUrl) return 'hero';
    return 'placeholder';
}

/* =============================================================================
 * M3 · #4 Auto-perspective match — Camera Presets
 *
 * Mapuje abstrakcyjny `active_camera_preset` (np. 'hero_three_quarter')
 * na konkretne wartości CSS transform (perspective + rotateX/Y/Z + scale).
 *
 * KONSYSTENCJA: ten sam slownik konsumują:
 *   • Director SceneRenderer (edytor)
 *   • Storefront mountPizzaScene (prezentacja klientowi)
 *
 * Dzięki temu kadr który manager ustawia w Studio (np. „makro, pod kątem
 * 30°") jest identycznie renderowany w sklepie online.
 *
 * BEZPIECZEŃSTWO: brak kamery → fallback na flat top-down (bez transform).
 * Żaden endpoint nigdy nie krzyczy; po prostu pomija presę.
 * ========================================================================== */

/**
 * Paramy per-preset kamery. Jednostki:
 *   perspective  — px CSS perspective (większe = spłaszczenie, mniejsze = dramatyzm)
 *   rotateX/Y/Z  — deg rotacji
 *   scale        — mnożnik disk; >1 = bliżej, <1 = dalej
 *   rackFocus    — bool: gdy true, stos warstw dostaje filter: blur() radialnie
 */
export const CAMERA_PRESETS = Object.freeze({
    top_down:           Object.freeze({ perspective: 1400, rx: 0,  ry: 0, rz: 0, scale: 1.00 }),
    hero_three_quarter: Object.freeze({ perspective:  900, rx: 26, ry: 0, rz: 0, scale: 1.04 }),
    macro_close:        Object.freeze({ perspective: 1400, rx: 0,  ry: 0, rz: 0, scale: 1.22 }),
    wide_establishing:  Object.freeze({ perspective: 1600, rx: 0,  ry: 0, rz: 0, scale: 0.92 }),
    dutch_angle:        Object.freeze({ perspective: 1200, rx: 0,  ry: 0, rz: 7, scale: 1.00 }),
    rack_focus:         Object.freeze({ perspective: 1100, rx: 0,  ry: 0, rz: 0, scale: 1.00, rackFocus: true }),
});

/**
 * Buduje obiekt stylów CSS dla hosta sceny (np. .pizza-viewport) oraz
 * dla warstwy disk (np. .pizza-disk).
 *
 * @param {string|null} presetKey  np. 'hero_three_quarter' lub null/undefined
 * @returns {{ viewportStyle: Record<string,string>, diskStyle: Record<string,string>, rackFocus: boolean } | null}
 */
export function buildCameraTransform(presetKey) {
    if (!presetKey || typeof presetKey !== 'string') return null;
    const p = CAMERA_PRESETS[presetKey];
    if (!p) return null;
    return {
        viewportStyle: {
            perspective: `${p.perspective}px`,
            perspectiveOrigin: '50% 50%',
        },
        diskStyle: {
            transformStyle: 'preserve-3d',
            transform: `scale(${p.scale}) rotateX(${p.rx}deg) rotateY(${p.ry}deg) rotateZ(${p.rz}deg)`,
            transformOrigin: '50% 50%',
            transition: 'transform 280ms cubic-bezier(.22,.61,.36,1)',
        },
        rackFocus: !!p.rackFocus,
        presetKey,
    };
}

/**
 * Aplikuje camera preset do istniejących elementów DOM.
 *
 * @param {HTMLElement} viewport — np. .pizza-viewport (otrzymuje perspective)
 * @param {HTMLElement} disk     — np. .pizza-disk     (otrzymuje transform)
 * @param {string|null} presetKey
 */
export function applyCameraPerspective(viewport, disk, presetKey) {
    if (!viewport || !disk) return false;
    const t = buildCameraTransform(presetKey);
    if (!t) {
        // Reset — usun ewentualne stare inline style z poprzedniego renderu
        viewport.style.perspective = '';
        disk.style.transform = '';
        disk.style.transformStyle = '';
        disk.classList.remove('pizza-disk--rack-focus');
        viewport.removeAttribute('data-camera');
        return false;
    }
    Object.assign(viewport.style, t.viewportStyle);
    Object.assign(disk.style,     t.diskStyle);
    disk.classList.toggle('pizza-disk--rack-focus', t.rackFocus);
    viewport.setAttribute('data-camera', t.presetKey);
    return true;
}

/**
 * Zestaw predefiniowanych class-packów dla najczęstszych kontekstów.
 * Użycie: `renderLayersInto(mount, layers, { ...CLASS_PACKS.storefront })`
 */
export const CLASS_PACKS = Object.freeze({
    storefront: Object.freeze({
        classes: Object.freeze({ wrap: 'pizza-layer', inner: 'layer-visual', img: 'layer-img' }),
        transformMode: 'cssVars',
    }),
    director: Object.freeze({
        classes: Object.freeze({ wrap: 'sr-layer', inner: 'sr-layer__inner', img: 'sr-layer__img' }),
        transformMode: 'inline',
    }),
    studio: Object.freeze({
        classes: Object.freeze({ wrap: 'st-layer', inner: 'st-layer__inner', img: 'st-layer__img' }),
        transformMode: 'cssVars',
    }),
});
