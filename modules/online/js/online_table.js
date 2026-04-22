/**
 * The Table v1 — Scene-aware menu renderer (Faza 3.1)
 *
 * Konsumuje Interaction Contract v1 (`OnlineAPI.getSceneMenu`) i renderuje:
 *   - pionowy stos kategorii (każda kategoria = jedna sekcja z własnym tłem / theming)
 *   - poziomy "pas" items w każdej sekcji (scroll-snap-x, swipe na mobile)
 *   - per-category theming z `activeStyle.colorPalette` (jeśli jest)
 *
 * Payload (category):
 *   { id, name, isMenu, layoutMode, defaultCompositionProfile, hasCategoryScene,
 *     items: [{ sku, name, description, heroUrl, compositionProfile,
 *               hasScene, activeStyle, price, priceFallback }] }
 */

import { resolveAssetUrl } from './online_ui.js';

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function formatPricePl(v) {
    if (v == null || v === '') return '—';
    const n = typeof v === 'string' ? parseFloat(v.replace(',', '.')) : Number(v);
    if (Number.isNaN(n)) return String(v);
    return n.toFixed(2).replace('.', ',') + ' zł';
}

/**
 * Aplikuje paletę koloru jako CSS vars do elementu sekcji.
 * Fallback (brak activeStyle) = neutralne ciemne tło.
 */
function applySectionTheme(sectionEl, activeStyle) {
    const palette = activeStyle?.colorPalette || activeStyle?.color_palette || {};
    const fontFamily = activeStyle?.fontFamily || activeStyle?.font_family || '';
    sectionEl.style.setProperty('--t-primary', palette.primary || '#d97706');
    sectionEl.style.setProperty('--t-accent',  palette.accent  || '#fbbf24');
    sectionEl.style.setProperty('--t-bg',      palette.bg      || '#0a0a0a');
    sectionEl.style.setProperty('--t-text',    palette.text    || '#fafafa');
    if (fontFamily) {
        sectionEl.style.setProperty('--t-font', `"${fontFamily}", Fraunces, serif`);
    }
}

/**
 * Karta dania w pasie (horizontal scroll-snap).
 */
function buildItemCard(item, onPick) {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'table-card';
    card.dataset.sku = item.sku;
    if (item.hasScene) card.classList.add('table-card--scene');

    const heroUrl = item.heroUrl || '';
    const priceTxt = formatPricePl(item.price);
    const priceBadge = item.priceFallback ? ' table-card__price--fallback' : '';
    const styleChip = item.activeStyle?.name
        ? `<span class="table-card__chip" title="Styl: ${escapeHtml(item.activeStyle.name)}"><i class="fa-solid fa-palette"></i> ${escapeHtml(item.activeStyle.name)}</span>`
        : '';

    card.innerHTML = `
        <div class="table-card__stage">
            ${heroUrl
                ? `<img class="table-card__hero" src="${escapeHtml(resolveAssetUrl(heroUrl))}" alt="${escapeHtml(item.name || '')}" loading="lazy" />`
                : `<div class="table-card__placeholder"><i class="fa-solid fa-utensils"></i></div>`}
            ${styleChip}
        </div>
        <div class="table-card__body">
            <h3 class="table-card__name">${escapeHtml(item.name || '')}</h3>
            <p class="table-card__desc">${escapeHtml((item.description || '').slice(0, 120))}</p>
            <div class="table-card__foot">
                <span class="table-card__price${priceBadge}">${escapeHtml(priceTxt)}</span>
                <span class="table-card__cta" aria-hidden="true"><i class="fa-solid fa-plus"></i></span>
            </div>
        </div>
    `;
    card.addEventListener('click', () => {
        if (typeof onPick === 'function') onPick(item.sku);
    });
    return card;
}

/**
 * Pojedyncza sekcja kategorii (pion scroll-snap-y).
 */
function buildCategorySection(category, onPickItem) {
    const section = document.createElement('section');
    section.className = 'table-section';
    section.dataset.categoryId = String(category.id);
    section.dataset.layoutMode = category.layoutMode || 'individual';

    // Primary theming — z pierwszego item z activeStyle (kolejne items mogą mieć różne, ale
    // dla całej kategorii jeden layout; pierwszy najczęściej reprezentatywny).
    const firstStyledItem = (category.items || []).find((x) => x.activeStyle);
    applySectionTheme(section, firstStyledItem?.activeStyle || null);

    const kicker = category.isMenu ? 'Menu' : 'Dodatki';
    const countTxt = `${(category.items || []).length} pozycji`;

    section.innerHTML = `
        <header class="table-section__head">
            <div class="table-section__titles">
                <span class="table-section__kicker">${escapeHtml(kicker)}</span>
                <h2 class="table-section__name">${escapeHtml(category.name || '')}</h2>
            </div>
            <div class="table-section__meta">
                <span class="table-section__count">${escapeHtml(countTxt)}</span>
                ${category.hasCategoryScene ? '<span class="table-section__badge" title="Kategoria ma dedykowaną scenę"><i class="fa-solid fa-film"></i> Scena</span>' : ''}
            </div>
        </header>
        <div class="table-section__strip" role="list"></div>
        <div class="table-section__hint"><i class="fa-solid fa-arrow-right"></i> przewiń</div>
    `;

    const strip = section.querySelector('.table-section__strip');
    (category.items || []).forEach((it) => {
        const card = buildItemCard(it, onPickItem);
        card.setAttribute('role', 'listitem');
        strip.appendChild(card);
    });

    return section;
}

/**
 * Główny builder — dla całego `categories[]` z `getSceneMenu`.
 * Zwraca DocumentFragment gotowy do wstawienia w kontener.
 */
export function buildTableSections(categories, handlers = {}) {
    const frag = document.createDocumentFragment();
    const wrapper = document.createElement('div');
    wrapper.className = 'table-surface table-surface--hybrid';
    wrapper.dataset.paradigm = 'hybrid';

    const list = Array.isArray(categories) ? categories : [];
    if (list.length === 0) {
        wrapper.innerHTML = '<p class="online-muted table-empty">Brak dostępnych kategorii.</p>';
        frag.appendChild(wrapper);
        return frag;
    }

    list.forEach((cat) => {
        const section = buildCategorySection(cat, handlers.onPickItem);
        wrapper.appendChild(section);
    });

    frag.appendChild(wrapper);
    return frag;
}

/**
 * Adapter: `get_scene_dish` response → payload oczekiwany przez `fillDishSheet`.
 * Stara UI (pizza engine + companions + mods) dostaje znajome pola; nowy UI czyta `sceneContract`.
 */
export function adaptSceneDishToLegacy(sceneData) {
    if (!sceneData || !sceneData.sceneContract) return null;
    const sc = sceneData.sceneContract;

    // Layers — SceneResolver zwraca snake_case? Sprawdź shapy w runtime; fallback na oryginalny.
    const layers = Array.isArray(sc.layers)
        ? sc.layers.map((L) => ({
              layerSku: L.layerSku ?? L.layer_sku ?? '',
              assetUrl: L.assetUrl ?? L.asset_url ?? '',
              assetFilename: L.assetFilename ?? L.asset_filename ?? null,
              productFilename: L.productFilename ?? L.product_filename ?? null,
              heroUrl: L.heroUrl ?? L.hero_url ?? null,
              zIndex: L.zIndex ?? L.z_index ?? 0,
              isBase: !!(L.isBase ?? L.is_base),
              calScale: L.calScale ?? L.cal_scale ?? 1.0,
              calRotate: L.calRotate ?? L.cal_rotate ?? 0,
              offsetX: L.offsetX ?? L.offset_x ?? 0,
              offsetY: L.offsetY ?? L.offset_y ?? 0,
          }))
        : [];

    return {
        item: {
            sku: sc.sku,
            name: sc.name,
            description: sc.description || '',
            categoryId: sc.category_id || 0,
            basePrice: sceneData.price,
        },
        imageUrl: sc.hero_url || null,
        visualLayers: layers,
        price: sceneData.price,
        priceFallback: sceneData.priceFallback,
        modifierGroups: sceneData.modifierGroups || [],
        companions: sceneData.companions || [],
        halfHalfSurcharge: sceneData.halfHalfSurcharge ?? null,
        surfaceUrl: sceneData.surfaceUrl ?? null,
        // m024 · Modifier Visual Slots — jedyne źródło prawdy (sh_asset_links).
        // Legacy magicDict usunięte w m025.
        modifierVisuals: sceneData.modifierVisuals || {},
        // Nowe pola — nowszy UI (theming, promotion badges, camera/LUT meta):
        sceneContract: sc,
        activeStyle: sc.scene_meta?.active_style || null,
        activeCamera: sc.scene_meta?.active_camera || null,
        activeLut: sc.scene_meta?.active_lut || null,
        atmosphericEffects: sc.scene_meta?.atmospheric_effects || [],
        promotions: sc.promotions || [],
    };
}

/**
 * Aplikuje theming (paleta, font) z activeStyle do kontenera Dish Sheet.
 * Można wywołać po `fillDishSheet(...)` żeby zastąpić default theme.
 */
export function applyDishSheetTheme(panelEl, activeStyle) {
    if (!panelEl) return;
    if (!activeStyle) {
        ['--t-primary', '--t-accent', '--t-bg', '--t-text', '--t-font'].forEach((v) =>
            panelEl.style.removeProperty(v)
        );
        return;
    }
    applySectionTheme(panelEl, activeStyle);
}
