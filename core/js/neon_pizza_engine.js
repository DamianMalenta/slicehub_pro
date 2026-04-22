// =============================================================================
// SliceHub — Neon Pizza Engine v2
// core/js/neon_pizza_engine.js
//
// Procedural SVG neon generator. ZERO uploads, ZERO configuration.
// Reads modifier names → auto-detects ingredient type → generates glowing
// neon SVG shapes scattered across a pizza canvas.
//
// Public API:
//   NeonPizza.renderPizza(el, modifiers, opts)
//   NeonPizza.renderIcon(name, size)
//   NeonPizza.detectType(name)
//   NeonPizza.getColor(type)
// =============================================================================

window.NeonPizza = (() => {

    // =========================================================================
    // NEON PALETTE — each ingredient type gets a signature glow color
    // Two-tone fills: inner (bright center) + outer (glow edge)
    // =========================================================================
    const PALETTE = {
        base:      { stroke:'#FF8C00', fill:'rgba(255,140,0,0.12)',   glow:'#FF8C00', inner:'#FFB347' },
        sauce:     { stroke:'#FF1744', fill:'rgba(255,23,68,0.15)',   glow:'#FF1744', inner:'#FF6B6B' },
        cheese:    { stroke:'#FFD700', fill:'rgba(255,215,0,0.12)',   glow:'#FFD700', inner:'#FFEC80' },
        meat:      { stroke:'#FF3D3D', fill:'rgba(255,61,61,0.12)',   glow:'#FF3D3D', inner:'#FF8A80' },
        veggie:    { stroke:'#39FF14', fill:'rgba(57,255,20,0.10)',   glow:'#39FF14', inner:'#80FF72' },
        herb:      { stroke:'#7FFF00', fill:'rgba(127,255,0,0.10)',   glow:'#7FFF00', inner:'#B8FF80' },
        olive:     { stroke:'#00FF88', fill:'rgba(0,255,136,0.12)',   glow:'#00FF88', inner:'#66FFBB' },
        mushroom:  { stroke:'#E0D0FF', fill:'rgba(224,208,255,0.10)',glow:'#D4B8FF', inner:'#F0E8FF' },
        onion:     { stroke:'#E040FB', fill:'rgba(224,64,251,0.10)', glow:'#E040FB', inner:'#F48CFB' },
        pepper:    { stroke:'#FF6B35', fill:'rgba(255,107,53,0.10)', glow:'#FF6B35', inner:'#FFAB91' },
        pineapple: { stroke:'#FFEA00', fill:'rgba(255,234,0,0.10)',  glow:'#FFEA00', inner:'#FFF59D' },
        corn:      { stroke:'#FFE066', fill:'rgba(255,224,102,0.10)',glow:'#FFE066', inner:'#FFF0B0' },
        seafood:   { stroke:'#00E5FF', fill:'rgba(0,229,255,0.10)', glow:'#00E5FF', inner:'#80F0FF' },
        egg:       { stroke:'#FFF9C4', fill:'rgba(255,249,196,0.12)',glow:'#FFF9C4', inner:'#FFFDE7' },
        default:   { stroke:'#00FFFF', fill:'rgba(0,255,255,0.08)', glow:'#00FFFF', inner:'#80FFFF' },
    };

    // =========================================================================
    // KEYWORD DETECTION — Polish + English ingredient names → visual type
    // =========================================================================
    const KEYWORDS = [
        { type:'sauce',    words:['sos','sauce','ketchup','bbq','ranch','aioli','ostry','hot sauce'] },
        { type:'cheese',   words:['ser','cheese','mozzarella','parmezan','parmesan','gouda','cheddar','gorgonzola','ricotta','feta','podwójny ser'] },
        { type:'meat',     words:['salami','pepperoni','szynka','ham','boczek','bacon','kurczak','chicken','wolowina','beef','kielbasa','sausage','prosciutto','parma','kabanos','chorizo'] },
        { type:'mushroom', words:['pieczark','grzyb','mushroom','shimeji','shitake','borowik'] },
        { type:'onion',    words:['cebul','onion','por ','leek','szalotka'] },
        { type:'olive',    words:['oliwk','olive','kapar'] },
        { type:'pepper',   words:['papryk','pepper','chili','jalapeno','jalapeño','peperoni','peperoncino'] },
        { type:'pineapple',words:['ananas','pineapple'] },
        { type:'herb',     words:['rukol','bazylia','basil','oregano','tymianek','thyme','szczypior','herb','roszponka','szpinak','spinach'] },
        { type:'corn',     words:['kukurydz','corn'] },
        { type:'veggie',   words:['pomidor','tomato','ogór','cucumber','brokuł','brocco','szparag','asparagus','awokado','avocado','warzy'] },
        { type:'seafood',  words:['krewet','shrimp','tuńczyk','tuna','łosoś','salmon','anchois','anchov','owoce morza'] },
        { type:'egg',      words:['jajk','jajo','egg'] },
    ];

    function detectType(name) {
        if (!name) return 'default';
        const low = name.toLowerCase();
        for (const entry of KEYWORDS) {
            for (const w of entry.words) {
                if (low.includes(w)) return entry.type;
            }
        }
        return 'default';
    }

    // =========================================================================
    // DETERMINISTIC PSEUDO-RANDOM from string seed
    // =========================================================================
    function hash(str) {
        let h = 0;
        for (let i = 0; i < str.length; i++) {
            h = ((h << 5) - h) + str.charCodeAt(i);
            h |= 0;
        }
        return Math.abs(h);
    }

    function seededRandom(seed, index) {
        const x = Math.sin(hash(seed + '_' + index) * 9301 + 49297) * 49297;
        return x - Math.floor(x);
    }

    // =========================================================================
    // SCATTER — polar coordinate placement with gap avoidance
    // =========================================================================
    function scatterPositions(seed, count, innerR, outerR, cx, cy) {
        const positions = [];
        for (let i = 0; i < count; i++) {
            const angle = seededRandom(seed, i * 3) * Math.PI * 2;
            const rFrac = seededRandom(seed, i * 3 + 1);
            const r = innerR + Math.sqrt(rFrac) * (outerR - innerR);
            const x = cx + Math.cos(angle) * r;
            const y = cy + Math.sin(angle) * r;
            const rot = (seededRandom(seed, i * 3 + 2) - 0.5) * 50;
            const sc = 0.75 + seededRandom(seed, i * 7) * 0.5;
            positions.push({ x, y, rot, sc });
        }
        return positions;
    }

    // =========================================================================
    // SHAPE GENERATORS
    // =========================================================================

    function shapeMeat(cx, cy, size, color, rot, uid) {
        const r = size;
        return `<g>
            <circle cx="${cx}" cy="${cy}" r="${r * 1.1}" fill="${color.glow}" opacity="0.08"/>
            <circle cx="${cx}" cy="${cy}" r="${r}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="2"/>
            <circle cx="${cx}" cy="${cy}" r="${r * 0.6}" fill="none" stroke="${color.inner}" stroke-width="0.6" opacity="0.3"/>
            <circle cx="${cx - r * 0.25}" cy="${cy - r * 0.2}" r="${r * 0.12}" fill="${color.stroke}" opacity="0.4"/>
            <circle cx="${cx + r * 0.3}" cy="${cy + r * 0.15}" r="${r * 0.08}" fill="${color.stroke}" opacity="0.3"/>
        </g>`;
    }

    function shapeMushroom(cx, cy, size, color, rot) {
        const r = size;
        const cap = `M ${cx - r},${cy + r * 0.1} A ${r},${r} 0 0,1 ${cx + r},${cy + r * 0.1} L ${cx + r * 0.5},${cy + r * 0.5} L ${cx - r * 0.5},${cy + r * 0.5} Z`;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <path d="${cap}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.8" stroke-linejoin="round"/>
            <line x1="${cx}" y1="${cy + r * 0.1}" x2="${cx}" y2="${cy + r * 0.45}" stroke="${color.inner}" stroke-width="0.8" opacity="0.35"/>
            <line x1="${cx - r * 0.3}" y1="${cy + r * 0.15}" x2="${cx - r * 0.15}" y2="${cy + r * 0.45}" stroke="${color.inner}" stroke-width="0.5" opacity="0.25"/>
        </g>`;
    }

    function shapeOnion(cx, cy, size, color, rot) {
        const r1 = size * 1.0, r2 = size * 0.6, r3 = size * 0.3;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <circle cx="${cx}" cy="${cy}" r="${r1}" fill="none" stroke="${color.stroke}" stroke-width="1.6" stroke-dasharray="${r1 * 1.4} ${r1 * 1.8}" opacity="0.85"/>
            <circle cx="${cx}" cy="${cy}" r="${r2}" fill="none" stroke="${color.stroke}" stroke-width="1.2" stroke-dasharray="${r2 * 1.2} ${r2 * 2}" opacity="0.55"/>
            <circle cx="${cx}" cy="${cy}" r="${r3}" fill="${color.fill}" stroke="${color.inner}" stroke-width="0.8" opacity="0.35"/>
        </g>`;
    }

    function shapeOlive(cx, cy, size, color) {
        return `<g>
            <circle cx="${cx}" cy="${cy}" r="${size * 1.1}" fill="${color.glow}" opacity="0.06"/>
            <circle cx="${cx}" cy="${cy}" r="${size}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.8"/>
            <circle cx="${cx}" cy="${cy}" r="${size * 0.35}" fill="${color.stroke}" opacity="0.25"/>
        </g>`;
    }

    function shapePepper(cx, cy, size, color, rot) {
        const w = size * 2.0, h = size * 0.7;
        const d = `M ${cx - w / 2},${cy} Q ${cx},${cy - h} ${cx + w / 2},${cy} Q ${cx},${cy + h * 0.4} ${cx - w / 2},${cy}`;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <path d="${d}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.6"/>
            <line x1="${cx - w * 0.2}" y1="${cy}" x2="${cx + w * 0.2}" y2="${cy}" stroke="${color.inner}" stroke-width="0.5" opacity="0.3"/>
        </g>`;
    }

    function shapeHerb(cx, cy, size, color, rot) {
        const d = `M ${cx},${cy - size} Q ${cx + size * 0.8},${cy - size * 0.2} ${cx},${cy + size * 0.6} Q ${cx - size * 0.8},${cy - size * 0.2} ${cx},${cy - size}`;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <path d="${d}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.3"/>
            <line x1="${cx}" y1="${cy - size * 0.7}" x2="${cx}" y2="${cy + size * 0.4}" stroke="${color.inner}" stroke-width="0.6" opacity="0.4"/>
        </g>`;
    }

    function shapePineapple(cx, cy, size, color, rot) {
        const s = size;
        const pts = `${cx},${cy - s} ${cx + s * 0.9},${cy + s * 0.55} ${cx - s * 0.9},${cy + s * 0.55}`;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <polygon points="${pts}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.5" stroke-linejoin="round"/>
            <line x1="${cx - s * 0.3}" y1="${cy}" x2="${cx + s * 0.3}" y2="${cy}" stroke="${color.inner}" stroke-width="0.5" opacity="0.3"/>
        </g>`;
    }

    function shapeCorn(cx, cy, size, color) {
        const r = size * 0.55;
        return `<g>
            <circle cx="${cx}" cy="${cy}" r="${r * 1.4}" fill="${color.glow}" opacity="0.06"/>
            <circle cx="${cx}" cy="${cy}" r="${r}" fill="${color.stroke}" opacity="0.7" stroke="${color.inner}" stroke-width="0.5"/>
        </g>`;
    }

    function shapeVeggie(cx, cy, size, color, rot) {
        return `<g transform="rotate(${rot},${cx},${cy})">
            <circle cx="${cx}" cy="${cy}" r="${size * 1.05}" fill="${color.glow}" opacity="0.05"/>
            <circle cx="${cx}" cy="${cy}" r="${size}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.5"/>
            <line x1="${cx - size * 0.55}" y1="${cy}" x2="${cx + size * 0.55}" y2="${cy}" stroke="${color.inner}" stroke-width="0.6" opacity="0.35"/>
            <line x1="${cx}" y1="${cy - size * 0.55}" x2="${cx}" y2="${cy + size * 0.55}" stroke="${color.inner}" stroke-width="0.6" opacity="0.35"/>
        </g>`;
    }

    function shapeSeafood(cx, cy, size, color, rot) {
        const d = `M ${cx - size},${cy} Q ${cx - size * 0.2},${cy - size * 1.3} ${cx + size},${cy} Q ${cx + size * 0.3},${cy - size * 0.4} ${cx - size},${cy}`;
        return `<g transform="rotate(${rot},${cx},${cy})">
            <path d="${d}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.6" stroke-linejoin="round"/>
        </g>`;
    }

    function shapeEgg(cx, cy, size, color) {
        return `<g>
            <ellipse cx="${cx}" cy="${cy}" rx="${size}" ry="${size * 0.78}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.6"/>
            <circle cx="${cx}" cy="${cy}" r="${size * 0.35}" fill="${color.inner}" opacity="0.25"/>
        </g>`;
    }

    function shapeDefault(cx, cy, size, color) {
        return `<g>
            <circle cx="${cx}" cy="${cy}" r="${size * 0.8}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="1.5"/>
        </g>`;
    }

    const SHAPE_FN = {
        meat: shapeMeat, mushroom: shapeMushroom, onion: shapeOnion,
        olive: shapeOlive, pepper: shapePepper, herb: shapeHerb,
        pineapple: shapePineapple, corn: shapeCorn, veggie: shapeVeggie,
        seafood: shapeSeafood, egg: shapeEgg, default: shapeDefault,
    };

    const COUNT_FOR_TYPE = {
        corn: 22, herb: 10, olive: 9, mushroom: 8, onion: 7,
        meat: 12, pepper: 8, pineapple: 8, veggie: 8,
        seafood: 7, egg: 3, default: 10,
    };

    const SIZE_FOR_TYPE = {
        corn: 0.018, egg: 0.055, mushroom: 0.04, meat: 0.038,
        onion: 0.035, olive: 0.028, pepper: 0.04, herb: 0.032,
        pineapple: 0.035, veggie: 0.035, seafood: 0.04, default: 0.035,
    };

    // =========================================================================
    // INGREDIENT LAYER RENDER
    // =========================================================================
    function renderIngredientLayer(sku, name, type, viewSize) {
        const color = PALETTE[type] || PALETTE.default;
        const cx = viewSize / 2, cy = viewSize / 2;
        const pizzaR = viewSize * 0.42;

        if (type === 'cheese') return renderCheese(sku, color, cx, cy, pizzaR, viewSize);
        if (type === 'sauce')  return renderSauce(sku, color, cx, cy, pizzaR);

        const count = COUNT_FOR_TYPE[type] || 10;
        const toppingSize = viewSize * (SIZE_FOR_TYPE[type] || 0.035);
        const positions = scatterPositions(sku, count, pizzaR * 0.1, pizzaR * 0.85, cx, cy);
        const shapeFn = SHAPE_FN[type] || SHAPE_FN.default;

        return positions.map((p, i) =>
            shapeFn(p.x, p.y, toppingSize * p.sc, color, p.rot, hash(sku + i))
        ).join('\n');
    }

    function renderCheese(sku, color, cx, cy, r, viewSize) {
        let svg = '';
        // Radial cheese melt glow
        svg += `<circle cx="${cx}" cy="${cy}" r="${r * 0.92}" fill="${color.fill}" opacity="0.5"/>`;

        // Wavy melt lines
        const count = 9;
        for (let i = 0; i < count; i++) {
            const sr = seededRandom(sku + 'cheese', i);
            const angle = (i / count) * Math.PI * 2 + sr * 0.3;
            const r1 = r * 0.15, r2 = r * 0.88;
            const x1 = cx + Math.cos(angle) * r1;
            const y1 = cy + Math.sin(angle) * r1;
            const x2 = cx + Math.cos(angle) * r2;
            const y2 = cy + Math.sin(angle) * r2;
            const cpOff = 0.25 + sr * 0.2;
            const cpx = cx + Math.cos(angle + cpOff) * r * 0.55;
            const cpy = cy + Math.sin(angle + cpOff) * r * 0.55;
            svg += `<path d="M ${x1},${y1} Q ${cpx},${cpy} ${x2},${y2}"
                fill="none" stroke="${color.stroke}" stroke-width="2" opacity="${0.25 + sr * 0.35}"
                stroke-linecap="round"/>`;
        }

        // Cheese drip blobs at crust edge
        for (let i = 0; i < 5; i++) {
            const angle = seededRandom(sku + 'drip', i) * Math.PI * 2;
            const blobR = viewSize * 0.012 + seededRandom(sku + 'drip', i + 5) * viewSize * 0.01;
            const bx = cx + Math.cos(angle) * r * 0.88;
            const by = cy + Math.sin(angle) * r * 0.88;
            svg += `<circle cx="${bx}" cy="${by}" r="${blobR}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="0.8" opacity="0.5"/>`;
        }
        return svg;
    }

    function renderSauce(sku, color, cx, cy, r) {
        let svg = '';
        svg += `<circle cx="${cx}" cy="${cy}" r="${r * 0.92}" fill="${color.fill}" opacity="0.6"/>`;
        svg += `<circle cx="${cx}" cy="${cy}" r="${r * 0.85}" fill="none" stroke="${color.stroke}" stroke-width="1" opacity="0.3" stroke-dasharray="5 7"/>`;
        // Sauce splatter blobs
        for (let i = 0; i < 6; i++) {
            const angle = seededRandom(sku + 'splat', i) * Math.PI * 2;
            const dist = r * (0.3 + seededRandom(sku + 'splat', i + 6) * 0.45);
            const bx = cx + Math.cos(angle) * dist;
            const by = cy + Math.sin(angle) * dist;
            const br = r * 0.04 + seededRandom(sku + 'splat', i + 12) * r * 0.03;
            svg += `<circle cx="${bx}" cy="${by}" r="${br}" fill="${color.stroke}" opacity="${0.15 + seededRandom(sku, i) * 0.15}"/>`;
        }
        return svg;
    }

    // =========================================================================
    // MAIN API: renderPizza
    // =========================================================================
    function renderPizza(el, mods, opts = {}) {
        const size = opts.size || 440;
        const showBase = opts.showBase !== false;
        const animate = opts.animate !== false;

        const uid = 'np_' + hash(mods.map(m => m.sku || m.name).join('_'));

        const filterDef = `
        <defs>
            <filter id="${uid}_glow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur in="SourceGraphic" stdDeviation="3" result="blur1"/>
                <feGaussianBlur in="SourceGraphic" stdDeviation="8" result="blur2"/>
                <feMerge>
                    <feMergeNode in="blur2"/>
                    <feMergeNode in="blur1"/>
                    <feMergeNode in="SourceGraphic"/>
                </feMerge>
            </filter>
            <filter id="${uid}_soft" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur in="SourceGraphic" stdDeviation="5" result="blur"/>
                <feMerge>
                    <feMergeNode in="blur"/>
                    <feMergeNode in="SourceGraphic"/>
                </feMerge>
            </filter>
            <filter id="${uid}_ambient" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur in="SourceGraphic" stdDeviation="18"/>
            </filter>
            <radialGradient id="${uid}_bg" cx="50%" cy="50%" r="55%">
                <stop offset="0%" stop-color="rgba(255,140,0,0.08)"/>
                <stop offset="70%" stop-color="rgba(255,80,0,0.02)"/>
                <stop offset="100%" stop-color="transparent"/>
            </radialGradient>
            <radialGradient id="${uid}_crust" cx="50%" cy="50%" r="50%">
                <stop offset="80%" stop-color="rgba(255,140,0,0.03)"/>
                <stop offset="95%" stop-color="rgba(255,140,0,0.08)"/>
                <stop offset="100%" stop-color="rgba(255,140,0,0.02)"/>
            </radialGradient>
        </defs>`;

        const cx = size / 2, cy = size / 2;
        const pizzaR = size * 0.42;
        let svg = '';

        // Ambient halo
        svg += `<circle cx="${cx}" cy="${cy}" r="${pizzaR * 1.4}" fill="url(#${uid}_bg)" filter="url(#${uid}_ambient)"/>`;

        // Base crust
        if (showBase) {
            const bc = PALETTE.base;
            svg += `<g filter="url(#${uid}_glow)">
                <circle cx="${cx}" cy="${cy}" r="${pizzaR}" fill="url(#${uid}_crust)" stroke="${bc.stroke}" stroke-width="2.5"/>
                <circle cx="${cx}" cy="${cy}" r="${pizzaR * 0.97}" fill="none" stroke="${bc.stroke}" stroke-width="0.7" opacity="0.25" stroke-dasharray="2 8"/>
                <circle cx="${cx}" cy="${cy}" r="${pizzaR * 0.92}" fill="none" stroke="${bc.inner}" stroke-width="0.4" opacity="0.12"/>
            </g>`;
        }

        // Sort layers by visual z-order
        const zOrder = ['sauce','cheese','meat','seafood','veggie','mushroom','onion','olive','pepper','pineapple','corn','egg','herb','default'];

        const enriched = mods.map(m => ({
            ...m,
            _type: m.type || detectType(m.name),
        }));

        enriched.sort((a, b) => {
            const ai = zOrder.indexOf(a._type);
            const bi = zOrder.indexOf(b._type);
            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        });

        enriched.forEach((mod, idx) => {
            const layer = renderIngredientLayer(mod.sku || mod.name, mod.name, mod._type, size);
            const animClass = animate ? `class="neon-layer" style="animation-delay:${idx * 80}ms"` : '';
            svg += `<g filter="url(#${uid}_soft)" opacity="0.95" ${animClass}>${layer}</g>`;
        });

        el.innerHTML = `<svg viewBox="0 0 ${size} ${size}" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg" style="display:block;">
            ${filterDef}
            ${svg}
        </svg>`;
    }

    // =========================================================================
    // ICON — single ingredient neon icon for cards
    // =========================================================================
    function renderIcon(name, size = 52) {
        const type = detectType(name);
        const color = PALETTE[type] || PALETTE.default;
        const cx = size / 2, cy = size / 2;
        const s = size * 0.32;
        const shapeFn = SHAPE_FN[type] || SHAPE_FN.default;
        const fid = 'ig' + hash(name + size);

        let shape = '';
        if (type === 'cheese') {
            shape = `<circle cx="${cx}" cy="${cy}" r="${s}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="2"/>
                <path d="M ${cx - s * 0.5},${cy - s * 0.2} Q ${cx},${cy - s * 0.8} ${cx + s * 0.5},${cy - s * 0.2}" fill="none" stroke="${color.inner}" stroke-width="1" opacity="0.5"/>`;
        } else if (type === 'sauce') {
            shape = `<circle cx="${cx}" cy="${cy}" r="${s}" fill="${color.fill}" stroke="${color.stroke}" stroke-width="2" stroke-dasharray="3 4"/>`;
        } else {
            shape = shapeFn(cx, cy, s, color, 0, 0);
        }

        return `<svg viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg">
            <defs><filter id="${fid}" x="-60%" y="-60%" width="220%" height="220%">
                <feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="b"/>
                <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter></defs>
            <g filter="url(#${fid})">${shape}</g>
        </svg>`;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    function getColor(type) {
        return PALETTE[type] || PALETTE.default;
    }

    return { renderPizza, renderIcon, detectType, getColor, PALETTE };

})();
