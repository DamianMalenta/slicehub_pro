/**
 * MagicCompanions — auto-select companion products matching dish profile.
 */
export function magicCompanions(spec, meta, companionsLibrary = []) {
    if (!companionsLibrary.length) return spec.companions || [];

    const profile = (meta._profile || 'classic').toLowerCase();
    const name = (meta.name || '').toLowerCase();

    const scored = companionsLibrary.map(c => {
        let score = 0;
        const cn = (c.name || '').toLowerCase();
        const sku = (c.sku || '').toLowerCase();

        if (profile === 'spicy' && /cola|pepsi|sprite|woda|water|napój|drink/i.test(cn)) score += 3;
        if (profile === 'fancy' && /wino|wine|prosecco|champagne/i.test(cn)) score += 4;
        if (profile === 'vegetarian' && /limonada|juice|sok|smoothie|herbata/i.test(cn)) score += 3;
        if (profile === 'meat-heavy' && /piwo|beer|cola|pepsi/i.test(cn)) score += 3;
        if (profile === 'classic' && /cola|fanta|sprite|woda/i.test(cn)) score += 2;
        if (profile === 'white' && /wino|wine|acqua|woda/i.test(cn)) score += 3;

        if (/sos|dip|sauce|ketchup|ranch|garlic/i.test(cn)) score += 2;
        if (/frytki|fries|bread|chleb|focaccia|bruschetta/i.test(cn)) score += 1;
        if (/deser|dessert|tiramisu|panna|gelato|lody/i.test(cn)) score += 1;

        score += Math.random() * 0.5;
        return { ...c, _score: score };
    });

    scored.sort((a, b) => b._score - a._score);

    return scored.slice(0, 4).map((c, i) => ({
        sku: c.sku,
        name: c.name,
        assetUrl: c.heroUrl || c.assetUrl || '',
        x: 50, y: 50,
        scale: 0.85,
        rotation: 0,
        label: '+ Dodaj do pizzy',
        labelVisible: true,
        visible: true,
        locked: false,
    }));
}
