/**
 * MagicEnhance — THE button. Full auto-compose in 10 steps.
 * Cinematic scene from raw layers in one click.
 */
import { profileDish } from '../lib/DishProfiler.js';
import { lutForProfile } from '../lib/LutLibrary.js';
import { magicRelight } from './MagicRelight.js';
import { magicColorGrade } from './MagicColorGrade.js';
import { magicBake } from './MagicBake.js';
import { magicDust } from './MagicDust.js';
import { magicCompanions } from './MagicCompanions.js';

export function magicEnhance(store, companionsLibrary = []) {
    const spec = structuredClone(store.spec);
    const meta = store.dishMeta || {};

    // 1. Profile the dish
    const profile = profileDish(meta);

    // 2. Auto-select LUT
    const lutName = lutForProfile(profile);
    spec.stage.lutName = lutName;

    // 3. Auto-position pizza: editorial bottom-left bleed
    const layouts = {
        'classic':     { x: -8,  y: 62,  scale: 1.35, rotation: -2 },
        'spicy':       { x: 65,  y: -8,  scale: 1.30, rotation: 5 },
        'vegetarian':  { x: -5,  y: 55,  scale: 1.25, rotation: -4 },
        'meat-heavy':  { x: 70,  y: 60,  scale: 1.40, rotation: 3 },
        'white':       { x: 50,  y: 50,  scale: 1.10, rotation: 0 },
        'fancy':       { x: 30,  y: 58,  scale: 1.45, rotation: -6 },
    };
    Object.assign(spec.pizza, layouts[profile] || layouts['classic']);

    // 4. Auto-select companions
    spec.companions = magicCompanions(spec, meta, companionsLibrary);

    // 5. Auto-place companions
    const slots = [
        { x: 75, y: 18 },
        { x: 82, y: 55 },
        { x: 18, y: 15 },
        { x: 68, y: 78 },
    ];
    spec.companions.forEach((c, i) => {
        const slot = slots[i % slots.length];
        c.x = slot.x + (i * 3) % 8;
        c.y = slot.y + (i * 5) % 10;
        c.scale = 0.75 + (i % 3) * 0.1;
        c.rotation = [-3, 2, -5, 4][i % 4];
        c.labelVisible = true;
        c.label = '+ Dodaj do pizzy';
        c.visible = true;
    });

    // 6. Auto-calibrate layers (bake)
    spec.pizza.layers = magicBake(spec.pizza.layers, meta);

    // 7. Auto-lighting
    const lightCfg = magicRelight(profile);
    Object.assign(spec.stage, lightCfg);

    // 8. Auto-dust
    const dustCfg = magicDust(profile);
    spec.ambient = dustCfg;

    // 9. Color grade adjustments (already done via LUT, add subtle global grade)
    const gradeCfg = magicColorGrade(profile);
    Object.assign(spec.stage, gradeCfg);

    // 10. Cinematic border
    spec.stage.letterbox = profile === 'fancy' ? 5 : 3;
    spec.stage.aspect = '16/10';

    // Info block position adapts to pizza position
    if (spec.pizza.x < 30) {
        Object.assign(spec.infoBlock, { x: 52, y: 8, w: 44, h: 38, theme: 'glass-dark', align: 'left' });
    } else if (spec.pizza.x > 60) {
        Object.assign(spec.infoBlock, { x: 3, y: 8, w: 40, h: 38, theme: 'glass-dark', align: 'left' });
    } else {
        Object.assign(spec.infoBlock, { x: 55, y: 5, w: 42, h: 30, theme: 'glass-dark', align: 'left' });
    }

    store.replace(spec, `Magic Enhance (${profile})`);
}
