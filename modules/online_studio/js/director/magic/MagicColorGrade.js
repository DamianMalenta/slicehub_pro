/**
 * MagicColorGrade — auto-apply LUT + subtle adjustments per profile.
 */
import { lutForProfile } from '../lib/LutLibrary.js';

export function magicColorGrade(profile) {
    return { lutName: lutForProfile(profile) };
}

export function applyColorGrade(store, profile) {
    const cfg = magicColorGrade(profile);
    store.patch('stage', cfg, 'Magic Color Grade');
}
