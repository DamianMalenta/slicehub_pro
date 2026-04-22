/**
 * LUT Library — 7 cinematic color grade presets as CSS filter stacks.
 * Each LUT = { name, label, description, filters, grain, vignette }.
 */
export const LUTS = [
    {
        name: 'none',
        label: 'Brak (surowy)',
        desc: 'Bez korekcji koloru.',
        filters: '',
        grain: 0, vignette: 0,
    },
    {
        name: 'neapolitan',
        label: 'Neapolitan Classic',
        desc: 'Ciepły, naturalny, subtelny grain — jak trattoria w Neapolu.',
        filters: 'brightness(1.02) contrast(1.05) saturate(1.08) sepia(0.06)',
        grain: 4, vignette: 20,
    },
    {
        name: 'editorial',
        label: 'Editorial Magazine',
        desc: 'Desaturacja, wysoki kontrast, sepia wash — jak okładka magazynu.',
        filters: 'brightness(0.98) contrast(1.18) saturate(0.72) sepia(0.15)',
        grain: 8, vignette: 35,
    },
    {
        name: 'hollywood',
        label: 'Hollywood Blockbuster',
        desc: 'Teal w cieniach + pomarańcz w highlightach, ciężki grain.',
        filters: 'brightness(0.96) contrast(1.22) saturate(1.15) hue-rotate(-8deg)',
        grain: 12, vignette: 45,
    },
    {
        name: 'ghibli',
        label: 'Studio Ghibli',
        desc: 'Miękki pastel, jasny, ciepły — jak anime Hayao Miyazakiego.',
        filters: 'brightness(1.08) contrast(0.92) saturate(1.20) sepia(0.04)',
        grain: 2, vignette: 10,
    },
    {
        name: 'darkvinyl',
        label: 'Dark Vinyl',
        desc: 'Zmiażdżone cienie, ciężka vignette, moody.',
        filters: 'brightness(0.88) contrast(1.30) saturate(0.90) sepia(0.08)',
        grain: 10, vignette: 55,
    },
    {
        name: 'summer',
        label: 'Summer Picnic',
        desc: 'Jasny, wysoka saturacja, ciepły tint — letni piknik.',
        filters: 'brightness(1.10) contrast(1.04) saturate(1.30) sepia(0.02)',
        grain: 3, vignette: 12,
    },
    {
        name: 'nordic',
        label: 'Nordic Clean',
        desc: 'Chłodny tint, desaturacja, minimalny grain — skandynawski minimalizm.',
        filters: 'brightness(1.04) contrast(1.08) saturate(0.65) hue-rotate(8deg)',
        grain: 2, vignette: 18,
    },
];

export function getLut(name) {
    return LUTS.find(l => l.name === name) || LUTS[0];
}

export function lutForProfile(profile) {
    const map = {
        'classic': 'neapolitan',
        'spicy': 'hollywood',
        'vegetarian': 'ghibli',
        'meat-heavy': 'darkvinyl',
        'white': 'nordic',
        'fancy': 'editorial',
    };
    return map[profile] || 'neapolitan';
}
