/**
 * DishProfiler — heuristic dish_profile detection from name, description,
 * recipe ingredients, and modifier names.
 * Returns: 'classic' | 'spicy' | 'vegetarian' | 'meat-heavy' | 'white' | 'fancy'
 */

const PROFILES = {
    spicy: {
        keywords: ['diavola','pikant','ostry','ostra','chili','jalapeño','jalapeno','peperoni',
                   'habanero','sriracha','tabasco','buffalo','hot','spicy','pepperoncino','nduja'],
        weight: 3,
    },
    vegetarian: {
        keywords: ['wegetari','vege','vegan','margherita','caprese','ortolana','primavera',
                   'rucola','szpinak','spinach','grzyb','mushroom','verdur','jarsk'],
        weight: 2,
    },
    'meat-heavy': {
        keywords: ['mięsna','miesna','carnivore','meat','salami','pepperoni','szynka','ham',
                   'boczek','bacon','kielbasa','sausage','pulled','bbq','burger','kabanos',
                   'chorizo','prosciutto','parma','kurczak','chicken'],
        weight: 2,
    },
    white: {
        keywords: ['bianca','bianco','white','4 formaggi','quattro formaggi','trufl','tartufo',
                   'gorgonzola','ricotta','mascarpone','alfredo','carbonara'],
        weight: 2.5,
    },
    fancy: {
        keywords: ['premium','deluxe','luxury','szef','chef','specjal','signature','gold',
                   'trufl','losos','salmon','krewet','shrimp','krab','crab','langust',
                   'foie gras','wagyu','burrata'],
        weight: 2.5,
    },
};

export function profileDish(dishMeta) {
    const text = [
        dishMeta.name || '',
        dishMeta.description || '',
        ...(dishMeta.ingredients || []),
        ...(dishMeta.modifierNames || []),
    ].join(' ').toLowerCase();

    const scores = {};
    for (const [profile, cfg] of Object.entries(PROFILES)) {
        let hits = 0;
        for (const kw of cfg.keywords) {
            if (text.includes(kw)) hits++;
        }
        scores[profile] = hits * cfg.weight;
    }

    let best = 'classic';
    let bestScore = 0;
    for (const [p, s] of Object.entries(scores)) {
        if (s > bestScore) { best = p; bestScore = s; }
    }
    return best;
}

export function profileLabel(profile) {
    const map = {
        'classic': 'Klasyczna',
        'spicy': 'Pikantna',
        'vegetarian': 'Wegetariańska',
        'meat-heavy': 'Mięsna',
        'white': 'Biała / Serowa',
        'fancy': 'Premium',
    };
    return map[profile] || profile;
}

export function profileIcon(profile) {
    const map = {
        'classic': 'fa-pizza-slice',
        'spicy': 'fa-pepper-hot',
        'vegetarian': 'fa-leaf',
        'meat-heavy': 'fa-drumstick-bite',
        'white': 'fa-cheese',
        'fancy': 'fa-crown',
    };
    return map[profile] || 'fa-pizza-slice';
}
