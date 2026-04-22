/**
 * HarmonyScore v2 — transparentna numeryczna brama jakości sceny.
 *
 * Prawo VII: to NIE jest slider ani ozdoba UI. Manager widzi liczbę i WIE co nacisnąć,
 * bo każdy punkt da się wyjaśnić konkretnym brakiem lub konkretną wariancją.
 *
 * REWRITE 2026-04-19 (Faza C). Poprzedni algorytm zwracał 100% dla scen z 2
 * warstwami przy defaultowych parametrach (bo penalty=0). To było kłamstwo —
 * scena z samym ciastem nie może być "kinowa". Nowy model jest transparentny:
 *
 *   score = completeness (0–50) + polish (0–30) + consistency (0–20)  ∈ [0, 100]
 *
 *   • completeness — czy scena ma wszystkie kanoniczne elementy pizzy:
 *       base (15) + sauce (10) + cheese (10) + ≥1 topping (10) + stage bonus (5)
 *     Bez tego warstwy mogą być idealnie wykalibrowane i i tak scena jest „pusta".
 *
 *   • polish — jak bardzo warstwy są dopracowane (feather/shadow/scale/rotate niezerowe,
 *       LUT ustawiony, companions obecni). Scena z defaultami = 0. Scena nad którą ktoś
 *       realnie siedział = wysokie.
 *
 *   • consistency — wariancja shadow/feather/alpha w grupach tego samego typu (meat vs meat,
 *       veggie vs veggie). Niespójne warstwy obcinają pkt.
 *
 * Wynik:
 *   {
 *     score: 0-100,
 *     breakdown: { completeness:{score,max,missing}, polish:{...}, consistency:{...} },
 *     outliers: [{ type, key, delta, action }],   // actionable: „+10 · dodaj topping"
 *     variance: {...},                             // raw numbers for analytics
 *     layerCount
 *   }
 *
 * Wymaga `spec.pizza.layers[]`. Pola warstwy (wszystkie opcjonalne, defaults w kodzie):
 *   layerSku, calScale, calRotate, brightness, saturation, feather, shadowStrength, alpha.
 * Pola sceny (opcjonalne):
 *   spec.stage.surfaceBg, spec.stage.lutName, spec.companions[].
 */

/* ─────────── Typy warstw (detekcja z ascii_key) ─────────── */

const TYPE_KEYWORDS = [
    { type: 'base',    words: ['base', 'dough', 'ciast', 'pizza_base'] },
    { type: 'sauce',   words: ['sauce', 'sos', 'ketchup', 'bbq', 'pesto', 'tomato_sauce'] },
    { type: 'cheese',  words: ['cheese', 'ser', 'mozzarella', 'parmezan', 'gouda', 'cheddar', 'gorgonzola'] },
    { type: 'meat',    words: ['meat', 'salami', 'pepperoni', 'szynka', 'ham', 'bacon', 'boczek', 'chicken', 'kurczak', 'prosciutto', 'chorizo', 'kabanos'] },
    { type: 'veggie',  words: ['veg', 'pomidor', 'tomato', 'papryk', 'pepper', 'oliwk', 'olive', 'pieczark', 'mushroom', 'cebul', 'onion', 'kukurydz', 'corn', 'ananas', 'pineapple'] },
    { type: 'herb',    words: ['herb', 'rukol', 'bazylia', 'basil', 'oregano', 'szpinak', 'spinach', 'roszponka'] },
    { type: 'seafood', words: ['tuna', 'tuńcz', 'shrimp', 'krewet', 'calamari', 'kalamar', 'salmon', 'łosoś', 'sardel', 'anchovi'] },
    { type: 'extra',   words: ['extra', 'item', 'board', 'plate'] },
];

/** Dodatkowe presety (używane tylko do kalibracji polish — nie do karania jak w v1). */
const TYPE_PRESETS = {
    base:    { feather:  0, shadowStrength: 0.00 },
    sauce:   { feather: 35, shadowStrength: 0.00 },
    cheese:  { feather: 20, shadowStrength: 0.10 },
    meat:    { feather:  5, shadowStrength: 0.55 },
    veggie:  { feather:  8, shadowStrength: 0.40 },
    herb:    { feather:  3, shadowStrength: 0.30 },
    seafood: { feather:  5, shadowStrength: 0.45 },
    extra:   { feather:  0, shadowStrength: 0.45 },
    default: { feather:  5, shadowStrength: 0.35 },
};

/** Które typy liczymy jako „topping" dla sprawdzania kompletności. */
const TOPPING_TYPES = new Set(['meat', 'veggie', 'herb', 'seafood']);

/* ─────────── Pomocnicze ─────────── */

function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

function detectLayerType(layerSku) {
    const low = (layerSku || '').toLowerCase();
    for (const entry of TYPE_KEYWORDS) {
        for (const w of entry.words) {
            if (low.includes(w)) return entry.type;
        }
    }
    return 'default';
}

function variance(values) {
    if (!values.length) return 0;
    const mean = values.reduce((a, b) => a + b, 0) / values.length;
    const sq = values.reduce((a, b) => a + (b - mean) ** 2, 0) / values.length;
    return Math.sqrt(sq);
}

function groupBy(arr, fn) {
    const m = new Map();
    arr.forEach(x => {
        const k = fn(x);
        if (!m.has(k)) m.set(k, []);
        m.get(k).push(x);
    });
    return m;
}

/** Czy warstwa jest „dotknięta przez człowieka" (odbiega od defaultów > tolerance). */
function isPolished(L, preset) {
    const dScale   = Math.abs((L.calScale      ?? 1) - 1);
    const dRotate  = Math.abs( L.calRotate     ?? 0);
    const dShadow  = Math.abs((L.shadowStrength ?? 0) - 0);
    const dFeather = Math.abs((L.feather       ?? 0) - 0);
    const dBright  = Math.abs((L.brightness    ?? 1) - 1);
    const dSat     = Math.abs((L.saturation    ?? 1) - 1);
    const dAlpha   = Math.abs((L.alpha         ?? 1) - 1);
    return (
        dScale   > 0.02 ||
        dRotate  > 1 ||
        dShadow  > 0.05 ||
        dFeather > 2 ||
        dBright  > 0.03 ||
        dSat     > 0.03 ||
        dAlpha   > 0.03
    );
}

/* ─────────── Komponent: Kompletność (0–50) ─────────── */

function computeCompleteness(types, stage) {
    const present = {
        base:    types.has('base'),
        sauce:   types.has('sauce'),
        cheese:  types.has('cheese'),
        topping: ['meat', 'veggie', 'herb', 'seafood'].some(t => types.has(t)),
    };
    const WEIGHTS = { base: 15, sauce: 10, cheese: 10, topping: 10, stageBonus: 5 };

    let score = 0;
    const missing = [];
    if (present.base)    score += WEIGHTS.base;    else missing.push('base');
    if (present.sauce)   score += WEIGHTS.sauce;   else missing.push('sauce');
    if (present.cheese)  score += WEIGHTS.cheese;  else missing.push('cheese');
    if (present.topping) score += WEIGHTS.topping; else missing.push('topping');

    // Stage bonus — jeśli jest tło powierzchni lub jakiś „companion" na stage.
    const hasStage = !!(stage?.surfaceBg || stage?.lutName || (stage?.companionsCount || 0) > 0);
    if (hasStage) score += WEIGHTS.stageBonus;

    return {
        score,
        max: 50,
        present,
        missing,
        stageBonus: hasStage,
    };
}

/* ─────────── Komponent: Polish (0–30) ─────────── */

function computePolish(layers, stage) {
    if (!layers.length) return { score: 0, max: 30, polishedLayers: 0, totalLayers: 0, hasLut: false, hasCompanions: false };
    const total = layers.length;
    const polished = layers.filter(L => {
        const preset = TYPE_PRESETS[detectLayerType(L.layerSku)] || TYPE_PRESETS.default;
        return isPolished(L, preset);
    }).length;

    // Bazowo 0–20 pkt za stosunek warstw dopracowanych do total.
    const ratio = polished / total;
    let score = Math.round(ratio * 20);

    // Bonus 0–10 za scene-level polish:
    //   +5 gdy LUT nazwany (manager wybrał styl kolorystyczny)
    //   +5 gdy są companions (scena ma „życie")
    const hasLut = !!(stage?.lutName && stage.lutName !== 'neutral' && stage.lutName !== 'default');
    const hasCompanions = (stage?.companionsCount || 0) > 0;
    if (hasLut) score += 5;
    if (hasCompanions) score += 5;

    score = clamp(score, 0, 30);
    return { score, max: 30, polishedLayers: polished, totalLayers: total, hasLut, hasCompanions };
}

/* ─────────── Komponent: Spójność (0–20) ─────────── */

function median(values) {
    if (!values.length) return 0;
    const s = [...values].sort((a, b) => a - b);
    const mid = Math.floor(s.length / 2);
    return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
}

function computeConsistency(layers) {
    if (layers.length < 2) {
        return {
            score: 20, max: 20,
            variance: { shadow: 0, feather: 0, alpha: 0 },
            penaltyBreakdown: [],
            layerOutliers: [],
        };
    }

    const byType = groupBy(layers, L => detectLayerType(L.layerSku));
    let penalty = 0;
    let maxShadow = 0, maxFeather = 0, maxAlpha = 0;
    const breakdown = [];
    const layerOutliers = [];

    byType.forEach((ls, type) => {
        if (ls.length < 2) return;
        const vShadow  = variance(ls.map(L => L.shadowStrength ?? 0));
        const vFeather = variance(ls.map(L => L.feather ?? 0));
        const vAlpha   = variance(ls.map(L => L.alpha ?? 1));
        maxShadow  = Math.max(maxShadow,  vShadow);
        maxFeather = Math.max(maxFeather, vFeather);
        maxAlpha   = Math.max(maxAlpha,   vAlpha);

        // Tolerancja = ile wariancji akceptujemy bez kary.
        // Ponad nią — każdy +1σ obcina 2–3 pkt.
        const TOL_SHADOW  = 0.10;
        const TOL_FEATHER = 8;
        const TOL_ALPHA   = 0.08;

        const pShadow  = Math.max(0, (vShadow  - TOL_SHADOW)  / TOL_SHADOW)  * 3;
        const pFeather = Math.max(0, (vFeather - TOL_FEATHER) / TOL_FEATHER) * 2;
        const pAlpha   = Math.max(0, (vAlpha   - TOL_ALPHA)   / TOL_ALPHA)   * 2;

        const sub = pShadow + pFeather + pAlpha;
        penalty += sub;
        if (sub > 0.5) {
            breakdown.push({
                type, count: ls.length,
                shadowVar:  +vShadow.toFixed(3),
                featherVar: +vFeather.toFixed(2),
                alphaVar:   +vAlpha.toFixed(3),
                penalty:    +sub.toFixed(1),
            });

            // Znajdź konkretne warstwy odstające względem mediany grupy
            // (dla Magic Harmonize — wie które warstwy naprawić).
            const medShadow  = median(ls.map(L => L.shadowStrength ?? 0));
            const medFeather = median(ls.map(L => L.feather ?? 0));
            const medAlpha   = median(ls.map(L => L.alpha ?? 1));
            ls.forEach(L => {
                const dShadow  = Math.abs((L.shadowStrength ?? 0) - medShadow);
                const dFeather = Math.abs((L.feather        ?? 0) - medFeather);
                const dAlpha   = Math.abs((L.alpha          ?? 1) - medAlpha);
                const outness = (dShadow / (TOL_SHADOW || 1)) + (dFeather / (TOL_FEATHER || 1)) + (dAlpha / (TOL_ALPHA || 1));
                if (outness > 1.5) {
                    layerOutliers.push({
                        layerSku: L.layerSku,
                        type,
                        severity: +outness.toFixed(2),
                        deltas: {
                            shadow:  +dShadow.toFixed(3),
                            feather: +dFeather.toFixed(2),
                            alpha:   +dAlpha.toFixed(3),
                        },
                    });
                }
            });
        }
    });

    layerOutliers.sort((a, b) => b.severity - a.severity);

    const score = Math.round(clamp(20 - penalty, 0, 20));
    return {
        score, max: 20,
        variance: {
            shadow:  +maxShadow.toFixed(3),
            feather: +maxFeather.toFixed(2),
            alpha:   +maxAlpha.toFixed(3),
        },
        penaltyBreakdown: breakdown,
        layerOutliers: layerOutliers.slice(0, 12),
    };
}

/* ─────────── Generator actionable outliers ─────────── */

const MISSING_COPY = {
    base:    { label: 'Dodaj bazę ciasta',        delta: '+15', type: 'missing', icon: 'fa-pizza-slice' },
    sauce:   { label: 'Dodaj sos',                 delta: '+10', type: 'missing', icon: 'fa-droplet' },
    cheese:  { label: 'Dodaj ser',                 delta: '+10', type: 'missing', icon: 'fa-cheese' },
    topping: { label: 'Dodaj przynajmniej 1 dodatek (np. pepperoni, pomidor, rukola)', delta: '+10', type: 'missing', icon: 'fa-leaf' },
};

function buildOutliers(breakdown) {
    const out = [];
    for (const key of breakdown.completeness.missing) {
        if (MISSING_COPY[key]) {
            out.push({ key, ...MISSING_COPY[key] });
        }
    }
    // Polish suggestions
    const polish = breakdown.polish;
    if (polish.totalLayers > 0 && polish.polishedLayers < polish.totalLayers) {
        const ratio = polish.polishedLayers / polish.totalLayers;
        if (ratio < 0.5) {
            out.push({
                key: 'polish_low',
                type: 'polish',
                label: `Dopracuj warstwy (${polish.polishedLayers}/${polish.totalLayers} ma ustawienia inne niż domyślne)`,
                delta: `+${Math.round((0.5 - ratio) * 20)}`,
                icon: 'fa-wand-magic-sparkles',
            });
        }
    }
    if (!polish.hasLut) {
        out.push({ key: 'no_lut', type: 'polish', label: 'Wybierz LUT / styl kolorystyczny sceny', delta: '+5', icon: 'fa-palette' });
    }
    if (!polish.hasCompanions) {
        out.push({ key: 'no_companions', type: 'polish', label: 'Dodaj companions (sztućce, sos w miseczce, napój)', delta: '+5', icon: 'fa-champagne-glasses' });
    }
    // Consistency suggestions
    if (breakdown.consistency.penaltyBreakdown.length > 0) {
        const worst = breakdown.consistency.penaltyBreakdown[0];
        out.push({
            key: `inconsistent_${worst.type}`,
            type: 'consistency',
            label: `Wyrównaj warstwy „${worst.type}" (wariancja cienia/feather między nimi)`,
            delta: `+${Math.round(worst.penalty)}`,
            icon: 'fa-arrows-left-right-to-line',
        });
    }
    return out.slice(0, 8);
}

/* ─────────── Public API ─────────── */

/**
 * @param {{
 *   pizza?: { layers?: Array },
 *   companions?: Array,
 *   stage?: { surfaceBg?: string, lutName?: string, companionsCount?: number }
 * }} spec
 * @returns {{
 *   score: number,
 *   breakdown: { completeness: object, polish: object, consistency: object },
 *   outliers: Array<{ key: string, type: string, label: string, delta: string, icon: string }>,
 *   variance: object,
 *   layerCount: number
 * }}
 */
export function computeHarmonyScore(spec) {
    const layers = Array.isArray(spec?.pizza?.layers) ? spec.pizza.layers : [];

    // Agreguj stage info (companions count + stage fields)
    const companionsCount = Array.isArray(spec?.companions) ? spec.companions.length : 0;
    const stage = {
        surfaceBg:       spec?.stage?.surfaceBg || null,
        lutName:         spec?.stage?.lutName || null,
        companionsCount: companionsCount,
    };

    const types = new Set(layers.map(L => detectLayerType(L.layerSku)));
    const completeness = computeCompleteness(types, stage);
    const polish       = computePolish(layers, stage);
    const consistency  = computeConsistency(layers);

    const score = clamp(completeness.score + polish.score + consistency.score, 0, 100);
    const breakdown = { completeness, polish, consistency };
    const outliers = buildOutliers(breakdown);

    return {
        score,
        breakdown,
        outliers,                                   // actionable hints dla managera (UI)
        layerOutliers: consistency.layerOutliers,   // per-layer odstające (Magic Harmonize)
        variance: consistency.variance,
        layerCount: layers.length,
    };
}

export function scoreTier(score) {
    if (score >= 90) return { tier: 'kino',  label: 'Kinowa',      color: '#facc15', iconClass: 'fa-crown' };
    if (score >= 70) return { tier: 'ok',    label: 'Dobra',       color: '#22c55e', iconClass: 'fa-check' };
    if (score >= 50) return { tier: 'warn',  label: 'Do poprawki', color: '#f59e0b', iconClass: 'fa-triangle-exclamation' };
    return            { tier: 'block', label: 'Blokada',     color: '#ef4444', iconClass: 'fa-xmark' };
}

export function formatReason(reason) {
    const map = {
        scale:          'skala',
        rotate:         'rotacja',
        brightness:     'jasność',
        saturation:     'nasycenie',
        feather:        'feather (krawędź)',
        shadowStrength: 'cień',
        alpha:          'przezroczystość',
        base:           'baza',
        sauce:          'sos',
        cheese:         'ser',
        topping:        'dodatek',
    };
    return map[reason] || reason;
}

// Re-export dla komponentów UI które same liczą typ warstwy
export { detectLayerType };
