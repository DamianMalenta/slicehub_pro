/**
 * MagicHarmonize — naprawia TYLKO outliery wskazane przez HarmonyScore.
 *
 * Różni się od MagicConform tym, że:
 *   • MagicConform       → rusza WSZYSTKIE warstwy (dla świeżej sceny, po auto-genie).
 *   • MagicHarmonize     → rusza TYLKO warstwy z listy outlierów (precyzyjny retouch).
 *
 * Strategia (Prawo VII — nie slider):
 *   1. Dla każdego outliera znajdź medianę wartości wśród warstw tego samego typu
 *      (jeśli jest ≥ 2 warstwy tego typu) — inaczej użyj presetu typu.
 *   2. Soft-blend odchyloną metrykę W STRONĘ mediany z siłą zależną od severity.
 *   3. Nie ruszamy scale/rotate/offset — to intencja managera.
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
    base:    { brightness: 1.00, saturation: 1.00, feather: 0,  shadowStrength: 0.00, alpha: 1.00 },
    sauce:   { brightness: 1.00, saturation: 1.05, feather: 35, shadowStrength: 0.00, alpha: 0.92 },
    cheese:  { brightness: 1.02, saturation: 1.08, feather: 20, shadowStrength: 0.10, alpha: 0.88 },
    meat:    { brightness: 0.98, saturation: 1.05, feather: 5,  shadowStrength: 0.55, alpha: 1.00 },
    veggie:  { brightness: 1.00, saturation: 1.10, feather: 8,  shadowStrength: 0.40, alpha: 1.00 },
    herb:    { brightness: 1.05, saturation: 1.10, feather: 3,  shadowStrength: 0.30, alpha: 0.95 },
    extra:   { brightness: 1.00, saturation: 1.00, feather: 0,  shadowStrength: 0.45, alpha: 1.00 },
    default: { brightness: 1.00, saturation: 1.00, feather: 5,  shadowStrength: 0.35, alpha: 1.00 },
};

function detectType(sku) {
    const low = (sku || '').toLowerCase();
    for (const e of TYPE_KEYWORDS) for (const w of e.words) if (low.includes(w)) return e.type;
    return 'default';
}

function median(vals) {
    if (!vals.length) return null;
    const s = [...vals].sort((a, b) => a - b);
    const m = Math.floor(s.length / 2);
    return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
}

function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

function targetFor(layers, type, metric) {
    const peers = layers.filter(L => detectType(L.layerSku) === type);
    if (peers.length >= 2) {
        const vs = peers.map(L => L[metric]).filter(v => typeof v === 'number' && isFinite(v));
        const m = median(vs);
        if (m !== null) return m;
    }
    const preset = TYPE_PRESETS[type] || TYPE_PRESETS.default;
    return preset[metric];
}

/**
 * @param {Array} layers — cała scena
 * @param {Array<string>} outlierSkus — lista layerSku do naprawy
 * @param {number} strength 0..1 (domyślnie 0.85 — agresywnie dociągamy do mediany)
 * @returns {Array}
 */
export function magicHarmonize(layers, outlierSkus, strength = 0.85) {
    if (!Array.isArray(layers) || !layers.length) return [];
    if (!Array.isArray(outlierSkus) || !outlierSkus.length) return layers;
    const s = clamp(strength, 0, 1);
    const fixSet = new Set(outlierSkus);

    return layers.map(L => {
        if (!fixSet.has(L.layerSku)) return L;
        const type = detectType(L.layerSku);

        const bTgt = targetFor(layers, type, 'brightness');
        const sTgt = targetFor(layers, type, 'saturation');
        const fTgt = targetFor(layers, type, 'feather');
        const shTgt = targetFor(layers, type, 'shadowStrength');
        const aTgt = targetFor(layers, type, 'alpha');

        return {
            ...L,
            brightness:     +clamp((L.brightness ?? 1) + (bTgt - (L.brightness ?? 1)) * s, 0.5, 1.6).toFixed(3),
            saturation:     +clamp((L.saturation ?? 1) + (sTgt - (L.saturation ?? 1)) * s, 0.5, 1.6).toFixed(3),
            feather:        Math.round((L.feather ?? 0) + (fTgt - (L.feather ?? 0)) * s),
            shadowStrength: +clamp((L.shadowStrength ?? 0) + (shTgt - (L.shadowStrength ?? 0)) * s, 0, 1).toFixed(2),
            alpha:          +clamp((L.alpha ?? 1) + (aTgt - (L.alpha ?? 1)) * s, 0.05, 1).toFixed(3),
        };
    });
}
