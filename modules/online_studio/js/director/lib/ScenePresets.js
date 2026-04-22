/**
 * ScenePresets — 7 ready-made scene layout templates.
 * Each returns a partial DishSceneSpec that gets merged into current spec.
 */

export const PRESETS = [
    {
        name: 'bottom-left-bleed',
        label: 'Bottom-Left Bleed',
        desc: 'Pizza w lewym dolnym rogu, ucięta krawędzią. Editorial classic.',
        icon: 'fa-arrow-down-left',
        apply: () => ({
            pizza: { x: -8, y: 62, scale: 1.35, rotation: -2 },
            infoBlock: { x: 52, y: 8, w: 44, h: 38 },
            stage: { letterbox: 3 },
        }),
    },
    {
        name: 'top-right-drama',
        label: 'Top-Right Drama',
        desc: 'Pizza wylewa się z górnego prawego rogu. Odważny, dramatyczny.',
        icon: 'fa-arrow-up-right',
        apply: () => ({
            pizza: { x: 58, y: -12, scale: 1.4, rotation: 8 },
            infoBlock: { x: 5, y: 55, w: 40, h: 35 },
            stage: { letterbox: 0 },
        }),
    },
    {
        name: 'centered-classic',
        label: 'Centered Classic',
        desc: 'Pizza w centrum, symetryczny układ. Tradycyjny, bezpieczny.',
        icon: 'fa-crosshairs',
        apply: () => ({
            pizza: { x: 50, y: 50, scale: 1.0, rotation: 0 },
            infoBlock: { x: 50, y: 2, w: 50, h: 20 },
            stage: { letterbox: 0 },
        }),
    },
    {
        name: 'magazine-editorial',
        label: 'Magazine Editorial',
        desc: 'Prawa połowa = pizza, lewa = tekst. Jak layout z Bon Appétit.',
        icon: 'fa-newspaper',
        apply: () => ({
            pizza: { x: 72, y: 50, scale: 1.25, rotation: -4 },
            infoBlock: { x: 3, y: 12, w: 38, h: 50 },
            stage: { letterbox: 4 },
        }),
    },
    {
        name: 'festival-table',
        label: 'Festival Table',
        desc: 'Pizza mniejsza, dużo companionów wokół. Stół imprezowy.',
        icon: 'fa-champagne-glasses',
        apply: () => ({
            pizza: { x: 45, y: 50, scale: 0.85, rotation: 3 },
            infoBlock: { x: 3, y: 3, w: 35, h: 22 },
            stage: { letterbox: 0 },
        }),
    },
    {
        name: 'minimal-zoom',
        label: 'Minimal Zoom',
        desc: 'Ekstremalne zbliżenie na pizzę. Widać teksturę. Mało UI.',
        icon: 'fa-magnifying-glass-plus',
        apply: () => ({
            pizza: { x: 35, y: 45, scale: 2.0, rotation: -6 },
            infoBlock: { x: 65, y: 68, w: 32, h: 28 },
            stage: { letterbox: 6 },
        }),
    },
    {
        name: 'split-composition',
        label: 'Split Composition',
        desc: 'Diagonalny podział: pizza lewo-dół, companions prawo-góra.',
        icon: 'fa-scissors',
        apply: () => ({
            pizza: { x: 18, y: 58, scale: 1.15, rotation: -3 },
            infoBlock: { x: 50, y: 5, w: 46, h: 30 },
            stage: { letterbox: 2 },
        }),
    },
];

export function getPreset(name) {
    return PRESETS.find(p => p.name === name);
}
