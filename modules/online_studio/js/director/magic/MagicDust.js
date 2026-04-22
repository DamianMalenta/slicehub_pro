/**
 * MagicDust — adds ambient elements: crumbs, steam, oil sheen, grain.
 */
import { profileDish } from '../lib/DishProfiler.js';

export function magicDust(profile) {
    const configs = {
        'classic':     { crumbs: { count: 15, seed: 'classic' },  steam: { count: 3, intensity: 45 }, oilSheen: { enabled: true, x: 42, y: 38 } },
        'spicy':       { crumbs: { count: 12, seed: 'spicy' },    steam: { count: 4, intensity: 65 }, oilSheen: { enabled: true, x: 48, y: 32 } },
        'vegetarian':  { crumbs: { count: 8,  seed: 'vege' },     steam: { count: 2, intensity: 30 }, oilSheen: { enabled: false, x: 45, y: 35 } },
        'meat-heavy':  { crumbs: { count: 18, seed: 'meat' },     steam: { count: 4, intensity: 55 }, oilSheen: { enabled: true, x: 40, y: 40 } },
        'white':       { crumbs: { count: 6,  seed: 'white' },    steam: { count: 2, intensity: 35 }, oilSheen: { enabled: false, x: 45, y: 35 } },
        'fancy':       { crumbs: { count: 10, seed: 'fancy' },    steam: { count: 3, intensity: 50 }, oilSheen: { enabled: true, x: 44, y: 36 } },
    };
    return configs[profile] || configs['classic'];
}

export function applyDust(store) {
    const profile = profileDish(store.dishMeta || {});
    const cfg = magicDust(profile);
    store.patch('ambient', cfg, 'Magic Dust');
}
