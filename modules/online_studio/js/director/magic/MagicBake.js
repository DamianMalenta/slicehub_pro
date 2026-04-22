/**
 * MagicBake — auto-calibrate per-layer blend, alpha, feather, shadow, pop-up
 * based on ingredient type detection.
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

function detectLayerType(layerSku) {
    const low = (layerSku || '').toLowerCase();
    for (const entry of TYPE_KEYWORDS) {
        for (const w of entry.words) {
            if (low.includes(w)) return entry.type;
        }
    }
    return 'default';
}

const PRESETS = {
    base:    { blendMode: 'normal',    alpha: 1.0,  feather: 0,  brightness: 1.0,  saturation: 1.0,  hueRotate: 0, shadowStrength: 0.0,  isPopUp: false },
    sauce:   { blendMode: 'multiply',  alpha: 0.92, feather: 35, brightness: 1.0,  saturation: 1.05, hueRotate: 0, shadowStrength: 0.0,  isPopUp: false },
    cheese:  { blendMode: 'overlay',   alpha: 0.88, feather: 20, brightness: 1.02, saturation: 1.08, hueRotate: 0, shadowStrength: 0.1,  isPopUp: false },
    meat:    { blendMode: 'normal',    alpha: 1.0,  feather: 5,  brightness: 0.98, saturation: 1.05, hueRotate: 0, shadowStrength: 0.55, isPopUp: true },
    veggie:  { blendMode: 'normal',    alpha: 1.0,  feather: 8,  brightness: 1.0,  saturation: 1.1,  hueRotate: 0, shadowStrength: 0.40, isPopUp: false },
    herb:    { blendMode: 'normal',    alpha: 0.95, feather: 3,  brightness: 1.05, saturation: 1.1,  hueRotate: 0, shadowStrength: 0.3,  isPopUp: true },
    extra:   { blendMode: 'normal',    alpha: 1.0,  feather: 0,  brightness: 1.0,  saturation: 1.0,  hueRotate: 0, shadowStrength: 0.45, isPopUp: false },
    default: { blendMode: 'normal',    alpha: 1.0,  feather: 5,  brightness: 1.0,  saturation: 1.0,  hueRotate: 0, shadowStrength: 0.35, isPopUp: false },
};

export function magicBake(layers, meta = {}) {
    return (layers || []).map(L => {
        const type = detectLayerType(L.layerSku);
        const preset = PRESETS[type] || PRESETS.default;
        return { ...L, ...preset };
    });
}

export function applyBake(store) {
    const layers = magicBake(store.spec.pizza?.layers || [], store.dishMeta || {});
    store.patch('pizza.layers', layers, 'Magic Bake');
}
