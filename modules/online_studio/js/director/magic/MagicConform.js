/**
 * MagicConform — bierze WSZYSTKIE warstwy sceny i dopasowuje je do presetu
 * swojego typu (base/sauce/cheese/meat/veggie/herb/extra).
 *
 * Prawo VII: to nie jest "bake all layers" (mamy już MagicBake). Różnica:
 *   • MagicBake narzuca preset bezkompromisowo → kompletnie zastępuje params.
 *   • MagicConform ZACHOWUJE intencję managera (scale, rotate, offset) i tylko
 *     ściąga "wizualne" metryki (brightness, saturation, feather, shadow, alpha)
 *     w stronę presetu z "siłą" proportional do odchylenia.
 *
 *   To jest Magic Enhance dla całej sceny — nie pojedynczej warstwy.
 */

const TYPE_KEYWORDS = [
    { type: 'base',    words: ['base', 'dough', 'ciast', 'pizza_base'] },
    { type: 'sauce',   words: ['sauce', 'sos', 'ketchup', 'bbq', 'pesto', 'tomato_sauce'] },
    { type: 'cheese',  words: ['cheese', 'ser', 'mozzarella', 'parmezan', 'gouda', 'cheddar', 'gorgonzola'] },
    { type: 'meat',    words: ['meat', 'salami', 'pepperoni', 'szynka', 'ham', 'bacon', 'boczek', 'chicken', 'kurczak', 'prosciutto', 'chorizo', 'kabanos'] },
    { type: 'veggie',  words: ['veg', 'pomidor', 'tomato', 'papryk', 'pepper', 'oliwk', 'olive', 'pieczark', 'mushroom', 'cebul', 'onion', 'kukurydz', 'corn', 'ananas', 'pineapple'] },
    { type: 'herb',    words: ['herb', 'rukol', 'bazylia', 'basil', 'oregano', 'szpinak', 'spinach', 'roszponka'] },
    { type: 'extra',   words: ['extra', 'item', 'board', 'plate'] },
];

const TYPE_PRESETS = {
    base:    { blendMode: 'normal',   alpha: 1.00, feather: 0,  brightness: 1.00, saturation: 1.00, shadowStrength: 0.00, isPopUp: false },
    sauce:   { blendMode: 'multiply', alpha: 0.92, feather: 35, brightness: 1.00, saturation: 1.05, shadowStrength: 0.00, isPopUp: false },
    cheese:  { blendMode: 'overlay',  alpha: 0.88, feather: 20, brightness: 1.02, saturation: 1.08, shadowStrength: 0.10, isPopUp: false },
    meat:    { blendMode: 'normal',   alpha: 1.00, feather: 5,  brightness: 0.98, saturation: 1.05, shadowStrength: 0.55, isPopUp: true  },
    veggie:  { blendMode: 'normal',   alpha: 1.00, feather: 8,  brightness: 1.00, saturation: 1.10, shadowStrength: 0.40, isPopUp: false },
    herb:    { blendMode: 'normal',   alpha: 0.95, feather: 3,  brightness: 1.05, saturation: 1.10, shadowStrength: 0.30, isPopUp: true  },
    extra:   { blendMode: 'normal',   alpha: 1.00, feather: 0,  brightness: 1.00, saturation: 1.00, shadowStrength: 0.45, isPopUp: false },
    default: { blendMode: 'normal',   alpha: 1.00, feather: 5,  brightness: 1.00, saturation: 1.00, shadowStrength: 0.35, isPopUp: false },
};

/**
 * Stage może dyktować globalne modyfikatory (np. tryb "late-night" = darker):
 *   stage.lutName → subtelna korekta brightness/saturation całej sceny.
 */
const LUT_OFFSETS = {
    cinema_warm:   { brightness: +0.02, saturation: +0.05 },
    cinema_cold:   { brightness: -0.02, saturation: +0.02 },
    vintage_film:  { brightness: -0.05, saturation: -0.05 },
    neon_night:    { brightness: -0.10, saturation: +0.15 },
    golden_hour:   { brightness: +0.08, saturation: +0.10 },
    none:          { brightness: 0,     saturation: 0 },
};

function detectType(sku) {
    const low = (sku || '').toLowerCase();
    for (const e of TYPE_KEYWORDS) for (const w of e.words) if (low.includes(w)) return e.type;
    return 'default';
}

function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

function blend(current, target, strength) {
    return current + (target - current) * strength;
}

/**
 * Apply Conform = soft-blend warstwy w stronę presetu typu + LUT offset ze stage.
 *
 * @param {Array} layers
 * @param {Object} stage
 * @param {number} strength  0..1 — domyślnie 0.75 (mocne dopasowanie, ale zostawia trochę indywidualności)
 * @returns {Array} nowe warstwy (immutable)
 */
export function magicConform(layers, stage = {}, strength = 0.75) {
    if (!Array.isArray(layers) || !layers.length) return [];
    const lut = LUT_OFFSETS[stage?.lutName] || LUT_OFFSETS.none;
    const s = clamp(strength, 0, 1);

    return layers.map(L => {
        const type = detectType(L.layerSku);
        const p = TYPE_PRESETS[type] || TYPE_PRESETS.default;
        const brightTgt = clamp(p.brightness + lut.brightness, 0.5, 1.6);
        const satTgt    = clamp(p.saturation + lut.saturation, 0.5, 1.6);

        return {
            ...L,
            blendMode: L.blendMode && L.blendMode !== 'normal' ? L.blendMode : p.blendMode,
            alpha:          +clamp(blend(L.alpha ?? 1, p.alpha, s), 0.05, 1).toFixed(3),
            feather:        Math.round(blend(L.feather ?? 0, p.feather, s)),
            brightness:     +clamp(blend(L.brightness ?? 1, brightTgt, s), 0.5, 1.6).toFixed(3),
            saturation:     +clamp(blend(L.saturation ?? 1, satTgt, s), 0.5, 1.6).toFixed(3),
            shadowStrength: +clamp(blend(L.shadowStrength ?? 0, p.shadowStrength, s), 0, 1).toFixed(2),
            isPopUp:        typeof L.isPopUp === 'boolean' ? L.isPopUp : p.isPopUp,
        };
    });
}
