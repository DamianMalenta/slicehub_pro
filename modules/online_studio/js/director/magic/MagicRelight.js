/**
 * MagicRelight — auto-set light direction, shadows, vignette based on profile.
 */
import { profileDish } from '../lib/DishProfiler.js';

export function magicRelight(profile) {
    const configs = {
        'classic':     { lightX: 40, lightY: 20, vignetteIntensity: 30, grainIntensity: 5 },
        'spicy':       { lightX: 55, lightY: 10, vignetteIntensity: 45, grainIntensity: 10 },
        'vegetarian':  { lightX: 50, lightY: 25, vignetteIntensity: 22, grainIntensity: 3 },
        'meat-heavy':  { lightX: 35, lightY: 15, vignetteIntensity: 40, grainIntensity: 8 },
        'white':       { lightX: 50, lightY: 30, vignetteIntensity: 18, grainIntensity: 2 },
        'fancy':       { lightX: 45, lightY: 12, vignetteIntensity: 50, grainIntensity: 12 },
    };
    return configs[profile] || configs['classic'];
}

export function applyRelight(store) {
    const profile = profileDish(store.dishMeta || {});
    const cfg = magicRelight(profile);
    store.patch('stage', cfg, 'Magic Relight');
}
