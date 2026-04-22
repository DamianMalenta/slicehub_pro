/**
 * Online storefront — DOM (menu, Surface Card, koszyk).
 */
import { mountPizzaScene, tryNeonFallback, applyAtmosphericEffects } from './online_renderer.js';
import { pickRenderMode } from '../../../core/js/scene_renderer.js';
import { ModifierOrchestrator } from './surface/ModifierOrchestrator.js';

/** Mini-podgląd tej samej sceny co Surface Card (tryb full) — kafel popularne / menu. */
function mapLayersForTile(visualLayers) {
    return (visualLayers || []).map((L) => ({
        ...L,
        assetUrl: L.assetUrl,
        zIndex: L.zIndex,
        calScale: L.calScale,
        calRotate: L.calRotate,
        layerSku: L.layerSku,
    }));
}

export function mountTilePizzaScene(mountEl, visualLayers, atmosphericEffects, activeCamera) {
    if (!mountEl || !visualLayers || !visualLayers.length) {
        return false;
    }
    mountEl.innerHTML = '';
    const root = document.createElement('div');
    root.className = 'pizza-scene-root pizza-scene-root--tile';
    mountEl.appendChild(root);
    mountPizzaScene(root, {
        layersA: mapLayersForTile(visualLayers),
        layersB: [],
        mode: 'full',
        effects: atmosphericEffects || null,
        camera: activeCamera || null,
        infoBlock: false,
        companions: [],
        ambient: { steam: { count: 0, intensity: 0 }, crumbs: { count: 0, seed: 'tile' }, oilSheen: { enabled: false } },
        sceneMode: 'preview',
    });
    return true;
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

export function resolveAssetUrl(url) {
    if (!url) return '';
    const u = String(url).trim();
    if (!u) return '';
    if (/^(https?:|data:|blob:)/i.test(u)) return u;
    // Already includes the /slicehub mount prefix (returned by API) → keep as-is.
    if (u.startsWith('/slicehub/') || u === '/slicehub') return u;
    // Absolute path (no mount prefix) → add the mount once.
    if (u.startsWith('/')) return '/slicehub' + u;
    // Relative path → add full prefix.
    return '/slicehub/' + u;
}

export function renderLoading(root, show, text) {
    const el = document.getElementById('online-loading');
    if (!el) return;
    el.classList.toggle('hidden', !show);
    if (text) el.querySelector('.online-loading__text').textContent = text;
}

export function renderErrorBanner(container, message) {
    if (!container) return;
    if (!message) {
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }
    container.classList.remove('hidden');
    container.innerHTML = `<div class="online-banner online-banner--error" role="alert">${escapeHtml(message)}</div>`;
}

/** Mapuje tryb UI → silnik wizualny (domyślnie pełna pizza — „pół” tylko przy wyborze pół na pół). */
export function computePizzaMode(ctx) {
    if (ctx.halfHalf && ctx.halfBSku) return 'halfHorizontal';
    if (ctx.halfHalf) return 'half';
    if (ctx.surfaceFull) return 'full';
    return 'full';
}

export function refreshPizzaScene(mountEl, layersA, layersB, ctx, scenePayload = {}) {
    if (!mountEl) return;
    const mode = computePizzaMode(ctx);
    const {
        effects = null,
        camera = null,
        sceneSpec = null,
        stage = null,
        companions = [],
        infoBlock = null,
        ambient = null,
        dishMeta = null,
        backend = 'dom',
    } = scenePayload || {};
    const la = (layersA || []).map((L) => ({
        ...L,
        assetUrl: L.assetUrl,
        zIndex: L.zIndex,
        calScale: L.calScale,
        calRotate: L.calRotate,
        layerSku: L.layerSku,
    }));
    const lb = (layersB || []).map((L) => ({
        ...L,
        assetUrl: L.assetUrl,
        zIndex: L.zIndex,
        calScale: L.calScale,
        calRotate: L.calRotate,
        layerSku: L.layerSku,
    }));

    if (!la.length) {
        mountEl.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'pizza-neon-fallback';
        mountEl.appendChild(box);
        tryNeonFallback(box, []);
        applyAtmosphericEffects(box, effects || null);
        return;
    }

    mountPizzaScene(mountEl, {
        layersA: la,
        layersB: mode === 'halfHorizontal' ? lb : [],
        mode: mode === 'halfHorizontal' ? 'halfHorizontal' : mode === 'full' ? 'full' : 'half',
        effects,
        camera,
        sceneSpec,
        stage,
        companions,
        infoBlock,
        ambient,
        dishMeta,
        backend,
        sceneMode: ctx.editMode ? 'edit' : 'preview',
    });
}

// Heurystyczny dobór ikonki FA na akordeon kategorii, na podstawie nazwy.
function pickCategoryIcon(name) {
    const n = String(name || '').toLowerCase();
    if (/pizza|pizz|placek/.test(n)) return '\uf818'; // fa-pizza-slice
    if (/burger|kanapk/.test(n))     return '\uf805'; // fa-hamburger
    if (/makaron|pasta|spagh/.test(n)) return '\uf81e'; // fa-bowl-food
    if (/salat|sałat/.test(n))       return '\uf5d7'; // fa-seedling
    if (/napój|napoj|drink|cola|sok|water|woda/.test(n)) return '\uf4e3'; // fa-bottle-water
    if (/deser|lody|ciasto|cake|dessert/.test(n)) return '\uf5cf'; // fa-ice-cream
    if (/przystaw|starter|zupa|soup/.test(n)) return '\uf5b8'; // fa-bread-slice (fallback)
    if (/sos|dip|sauce/.test(n))     return '\uf0f5'; // fa-utensils
    if (/alkohol|piwo|wino|beer/.test(n)) return '\uf000'; // fa-glass
    return '\uf2e7'; // fa-utensils alt
}

function formatPricePl(v) {
    return Number(v).toFixed(2).replace('.', ',');
}

export function buildMenuAccordion(categories, onPickItem) {
    const frag = document.createDocumentFragment();
    if (!categories || !categories.length) {
        const p = document.createElement('p');
        p.className = 'online-empty';
        p.textContent = 'Brak pozycji w menu.';
        frag.appendChild(p);
        return frag;
    }

    categories.forEach((cat, catIdx) => {
        const section = document.createElement('section');
        section.className = 'cat-accordion';
        section.dataset.catId = String(cat.id);
        section.style.animationDelay = `${catIdx * 60}ms`;

        const head = document.createElement('button');
        head.type = 'button';
        head.className = 'cat-accordion__head';
        head.dataset.icon = pickCategoryIcon(cat.name);
        head.innerHTML = `
            <span class="cat-accordion__title">${escapeHtml(cat.name)}</span>
            <span class="cat-accordion__count">${cat.items?.length || 0} poz.</span>
            <span class="cat-accordion__chev" aria-hidden="true"></span>
        `;

        const body = document.createElement('div');
        body.className = 'cat-accordion__body hidden';

        head.addEventListener('click', () => {
            const open = body.classList.toggle('hidden');
            head.classList.toggle('cat-accordion__head--open', !open);
        });

        (cat.items || []).forEach((item) => {
            // Cena: gdy pozycja ma warianty (np. pizza 32/40/50 cm), pokazujemy
            // "od X,XX zł". Inaczej bierzemy item.price.
            let priceHtml = '—';
            const variants = Array.isArray(item.variants) ? item.variants : [];
            if (variants.length > 0) {
                const prices = variants.map((v) => Number(v.price || 0)).filter((p) => p > 0);
                if (prices.length) {
                    const min = Math.min(...prices);
                    priceHtml = `<small>od</small> ${escapeHtml(formatPricePl(min))} zł`;
                }
            } else if (item.price != null) {
                priceHtml = `${escapeHtml(formatPricePl(item.price))} zł`;
            }

            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'menu-ticket';
            row.dataset.sku = item.sku;

            const thumb = document.createElement('span');
            thumb.className = 'menu-ticket__thumb';
            const mode = pickRenderMode(item);
            if (mode === 'scene') {
                thumb.classList.add('menu-ticket__thumb--scene');
                const sm = document.createElement('div');
                sm.className = 'menu-ticket__scene-mount';
                thumb.appendChild(sm);
            } else if (mode === 'hero') {
                const im = document.createElement('img');
                im.src = resolveAssetUrl(item.imageUrl);
                im.alt = '';
                im.loading = 'lazy';
                thumb.appendChild(im);
            } else {
                thumb.classList.add('menu-ticket__thumb--ph');
            }

            const main = document.createElement('span');
            main.className = 'menu-ticket__main';
            main.innerHTML = `
                <span class="menu-ticket__name">${escapeHtml(item.name)}</span>
                <span class="menu-ticket__desc">${escapeHtml((item.description || '').slice(0, 140))}</span>
            `;

            const priceEl = document.createElement('span');
            priceEl.className = 'menu-ticket__price';
            priceEl.innerHTML = priceHtml;

            row.appendChild(thumb);
            row.appendChild(main);
            row.appendChild(priceEl);

            if (mode === 'scene') {
                mountTilePizzaScene(
                    thumb.querySelector('.menu-ticket__scene-mount'),
                    item.visualLayers,
                    item.atmosphericEffects || null,
                    item.activeCamera || null
                );
            }

            row.addEventListener('click', (e) => {
                e.stopPropagation();
                onPickItem(item.sku);
            });
            body.appendChild(row);
        });

        section.appendChild(head);
        section.appendChild(body);
        frag.appendChild(section);
    });

    return frag;
}

export function renderPopularCarousel(container, items, onPickItem) {
    if (!container) return;
    container.innerHTML = '';
    if (!items || !items.length) {
        container.classList.add('hidden');
        return;
    }
    container.classList.remove('hidden');
    const track = document.createElement('div');
    track.className = 'popular-track';
    items.forEach((it, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'popular-chip';
        btn.dataset.sku = it.sku;
        btn.style.animationDelay = `${idx * 55}ms`;

        const mode = pickRenderMode(it);
        const img = mode === 'scene'
            ? '<div class="popular-chip__scene-mount" aria-hidden="true"></div>'
            : mode === 'hero'
              ? `<img src="${escapeHtml(resolveAssetUrl(it.imageUrl))}" alt="" loading="lazy" />`
              : '<div class="popular-chip__ph"><i class="fa-solid fa-pizza-slice"></i></div>';
        const priceVal = it.price != null ? `${formatPricePl(it.price)} zł` : '';
        btn.innerHTML = `
            <div class="popular-chip__media">
                <span class="popular-chip__rank">#${idx + 1}</span>
                ${img}
            </div>
            <div class="popular-chip__body">
                <span class="popular-chip__name">${escapeHtml(it.name)}</span>
                <span class="popular-chip__price">${escapeHtml(priceVal)}</span>
            </div>
        `;
        btn.addEventListener('click', () => onPickItem(it.sku));
        track.appendChild(btn);
        if (mode === 'scene') {
            const sm = btn.querySelector('.popular-chip__scene-mount');
            if (sm) mountTilePizzaScene(sm, it.visualLayers, it.atmosphericEffects || null, it.activeCamera || null);
        }
    });
    container.appendChild(track);
}

/**
 * Surface Card v2 — zgodny z wizją 06_WIZJA:
 *   - pełny ekran, pół pizzy flush-left na drewnianej desce,
 *   - companions integracyjne po prawej (hero photos),
 *   - modyfikatory w sekcji dolnej z mechaniką x1/x2 (scatter + hero obok pizzy),
 *   - sticky CTA bar z live price.
 *
 * @param {HTMLElement} el - container (#dish-sheet-inner)
 * @param {object}      data - payload z api/online get_dish (modifierGroups, visualLayers, companions, modifierVisuals, surfaceUrl, halfHalfSurcharge, item)
 * @param {object}      ctx - { surfaceFull, halfHalf, halfBSku, layersB, editMode, modCounts:Map }
 * @param {object}      handlers - { menuItems, onAddToCart, onAddCompanion, onCtxChange, onModifierChange }
 */
export function fillDishSheet(el, data, ctx, handlers) {
    if (!el || !data || !data.item) return;
    const item = data.item;
    const half = data.halfHalfSurcharge != null ? Number(data.halfHalfSurcharge).toFixed(2).replace('.', ',') : '—';
    const surfaceUrl = data.surfaceUrl ? resolveAssetUrl(data.surfaceUrl) : '';

    ctx.modCounts = ctx.modCounts instanceof Map ? ctx.modCounts : new Map();

    const hasMods = (data.modifierGroups || []).some((g) => (g.options || []).length);
    const menuOptions = (handlers.menuItems || [])
        .filter((x) => x.sku !== item.sku)
        .map((x) => `<option value="${escapeHtml(x.sku)}" ${ctx.halfBSku === x.sku ? 'selected' : ''}>${escapeHtml(x.name)}</option>`)
        .join('');

    const basePriceLabel = item.basePrice != null ? moneyPl(item.basePrice) : '—';

    el.innerHTML = `
      <article class="sc-card" data-mode="${ctx.editMode ? 'edit' : 'preview'}" data-half="${ctx.halfHalf ? '1' : '0'}">
        <header class="sc-topbar">
            <button type="button" class="sc-topbar__close" id="sc-close-x" aria-label="Zamknij">✕</button>
            <div class="sc-topbar__crumb">
                <span class="sc-topbar__cat">${escapeHtml(data._categoryName || 'Danie')}</span>
                <h1 class="sc-topbar__title">${escapeHtml(item.name)}</h1>
            </div>
            <div class="sc-topbar__modes" role="group" aria-label="Tryb widoku">
                <button type="button" class="sc-mode-btn ${!ctx.editMode ? 'is-active' : ''}" id="sc-mode-preview" aria-pressed="${!ctx.editMode}">
                    <span>Podgląd</span>
                </button>
                <button type="button" class="sc-mode-btn ${ctx.editMode ? 'is-active' : ''}" id="sc-mode-edit" aria-pressed="${ctx.editMode}">
                    <span>Skomponuj</span>
                </button>
            </div>
        </header>

        <main class="sc-main">
            <section class="sc-hero" style="${surfaceUrl ? `--surface-bg-url: url('${surfaceUrl}');` : ''}">
                <div class="sc-hero__surface" aria-hidden="true"></div>
                <div class="sc-hero__stage" id="sc-stage">
                    <div id="pizza-scene" class="pizza-scene-root"></div>
                    <div class="sc-hero__bubbles" id="sc-hero-bubbles" aria-hidden="true"></div>
                </div>
                <div class="sc-hero__legend">
                    <span class="sc-chip"><i>◐</i> ${
                        ctx.halfHalf && ctx.halfBSku
                            ? 'Pół na pół (A+B)'
                            : ctx.halfHalf
                              ? 'Pół widoku'
                              : ctx.surfaceFull
                                ? 'Pełny talerz'
                                : 'Podgląd'
                    }</span>
                    <span class="sc-chip sc-chip--mute" id="sc-layers-count">0 warstw</span>
                </div>
            </section>

            <aside class="sc-info">
                <div class="sc-info__head">
                    <p class="sc-info__cat">${escapeHtml(data._categoryName || 'Menu')}</p>
                    <h2 class="sc-info__title">${escapeHtml(item.name)}</h2>
                    <p class="sc-info__desc">${escapeHtml((item.description || '').slice(0, 220))}</p>
                </div>

                <div class="sc-info__price">
                    <span class="sc-info__price-lbl">Od</span>
                    <span class="sc-info__price-val" id="sc-base-price">${basePriceLabel}</span>
                </div>

                <div class="sc-info__toolbar">
                    <label class="sc-tool">
                        <input type="checkbox" id="sc-half-toggle" ${ctx.halfHalf ? 'checked' : ''}/>
                        <span>⬌ Pół na pół</span>
                        <small>+${escapeHtml(half)} zł</small>
                    </label>
                    <label class="sc-tool">
                        <input type="checkbox" id="sc-surface-full" ${ctx.surfaceFull ? 'checked' : ''}/>
                        <span>Pełny widok</span>
                    </label>
                </div>

                <div class="sc-half-panel ${ctx.halfHalf ? '' : 'hidden'}" id="sc-half-panel">
                    <label class="sc-half-panel__label">Druga połowa (B)</label>
                    <select id="sc-half-b-sku" class="sc-half-panel__select">
                        <option value="">— wybierz —</option>
                        ${menuOptions}
                    </select>
                </div>

                <section class="sc-companions" aria-label="Polecamy">
                    <h3 class="sc-section-h">Polecamy przy tej pizzy</h3>
                    <div class="sc-companions__grid" id="sc-companions-grid"></div>
                </section>
            </aside>
        </main>

        <section class="sc-mods" aria-label="Modyfikatory">
            <header class="sc-mods__head">
                <h3 class="sc-section-h sc-section-h--big">${hasMods ? 'Dodatki — klikaj aby komponować' : 'Dodatki'}</h3>
                <small class="sc-mods__hint">${hasMods
                    ? '<b>1×</b> dodaje warstwę, <b>2×</b> wyświetla zdjęcie produktu obok pizzy, <b>3×</b> reset.'
                    : 'Ta pozycja nie ma konfigurowalnych dodatków.'}</small>
            </header>
            <div id="sc-mod-groups" class="sc-mod-groups"></div>
        </section>

        <footer class="sc-cta">
            <div class="sc-cta__price">
                <span class="sc-cta__price-lbl">Razem</span>
                <strong class="sc-cta__price-val" id="sc-cta-price">${basePriceLabel}</strong>
            </div>
            <button type="button" class="sc-cta__btn" id="sc-add-btn">
                <span>Dodaj do koszyka</span>
                <i class="sc-cta__arrow" aria-hidden="true">→</i>
            </button>
        </footer>
      </article>
    `;

    renderCompanions(el.querySelector('#sc-companions-grid'), data.companions || [], handlers);
    renderModifiers(el.querySelector('#sc-mod-groups'), data.modifierGroups || [], ctx, handlers);

    wireSurfaceCard(el, data, ctx, handlers);

    // Initial scene + bubble paint
    repaintSurface(el, data, ctx);
}

function moneyPl(n) {
    if (n == null) return '—';
    const v = Number(String(n).replace(',', '.'));
    if (Number.isNaN(v)) return String(n);
    return v.toFixed(2).replace('.', ',') + ' zł';
}

function renderCompanions(mount, comps, handlers) {
    if (!mount) return;
    if (!comps.length) {
        mount.innerHTML = '<p class="online-muted">Brak polecanych.</p>';
        return;
    }
    mount.innerHTML = comps.map((c) => {
        const url = c.heroUrl || c.assetUrl || '';
        const thumb = url
            ? `<img src="${escapeHtml(resolveAssetUrl(url))}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'sc-comp__ph',textContent:'🍽'}))">`
            : `<span class="sc-comp__ph">🍽</span>`;
        const price = c.price != null ? moneyPl(c.price) : '';
        return `
            <button type="button" class="sc-comp" data-sku="${escapeHtml(c.sku)}" title="Dodaj ${escapeHtml(c.name)}">
                <span class="sc-comp__thumb">${thumb}</span>
                <span class="sc-comp__body">
                    <span class="sc-comp__name">${escapeHtml(c.name)}</span>
                    <span class="sc-comp__price">${escapeHtml(price)}</span>
                </span>
                <span class="sc-comp__plus" aria-hidden="true">+</span>
            </button>
        `;
    }).join('');

    mount.querySelectorAll('.sc-comp').forEach((btn) => {
        btn.addEventListener('click', () => {
            const sku = btn.dataset.sku;
            const c = comps.find((x) => x.sku === sku);
            btn.classList.add('sc-comp--pop');
            setTimeout(() => btn.classList.remove('sc-comp--pop'), 380);
            handlers.onAddCompanion?.(c || { sku, name: sku });
        });
    });
}

function renderModifiers(mount, groups, ctx, handlers) {
    if (!mount) return;
    if (!groups.length) {
        mount.innerHTML = `<p class="sc-mods__empty">Ta pozycja nie ma modyfikatorów.</p>`;
        return;
    }

    const iconForGroup = (name) => {
        const n = (name || '').toLowerCase();
        if (/ostr|chili|pieprz/.test(n))        return '🌶';
        if (/warzyw|vege|vegan/.test(n))        return '🥬';
        if (/ser|cheese|queso/.test(n))         return '🧀';
        if (/mięso|mieso|meat|szynk|salami/.test(n)) return '🥓';
        if (/sos|sauce|dip/.test(n))            return '🥣';
        if (/ciast|base|dough/.test(n))         return '🍞';
        if (/zio|herb|oregan|bazy/.test(n))     return '🌿';
        return '✨';
    };

    mount.innerHTML = groups.map((g, gi) => {
        const all = g.options || [];
        const visible = all.slice(0, 4);
        const rest = all.slice(4);
        return `
            <section class="sc-mod-grp" data-group-idx="${gi}">
                <header class="sc-mod-grp__head">
                    <span class="sc-mod-grp__icon">${iconForGroup(g.name)}</span>
                    <h4 class="sc-mod-grp__title">${escapeHtml(g.name)}</h4>
                    <span class="sc-mod-grp__meta">
                        ${g.freeLimit > 0 ? `<small>${g.freeLimit} free</small>` : ''}
                        ${g.maxSelection > 0 ? `<small>max ${g.maxSelection}</small>` : ''}
                    </span>
                </header>
                <div class="sc-mod-grp__opts">
                    ${visible.map((o) => modButtonHtml(o, ctx)).join('')}
                </div>
                ${rest.length ? `
                    <div class="sc-mod-grp__rest hidden" data-rest>
                        ${rest.map((o) => modButtonHtml(o, ctx)).join('')}
                    </div>
                    <button type="button" class="sc-mod-more" data-more>
                        <span>▼ Więcej (${rest.length})</span>
                    </button>
                ` : ''}
            </section>
        `;
    }).join('');

    const orchestrator = new ModifierOrchestrator(ctx, handlers);
    orchestrator.bind(mount);
    mount.querySelectorAll('[data-more]').forEach((moreBtn) => {
        moreBtn.addEventListener('click', () => {
            const rest = moreBtn.parentElement.querySelector('[data-rest]');
            if (!rest) return;
            const open = rest.classList.toggle('hidden');
            moreBtn.querySelector('span').textContent = open
                ? `▼ Więcej (${rest.children.length})`
                : '▲ Zwiń';
        });
    });
}

function modButtonHtml(o, ctx) {
    const count = ctx.modCounts?.get(o.sku) || 0;
    const priceLbl = o.price != null ? `+${moneyPl(o.price).replace(' zł', '')}` : '';
    return `
        <button type="button" class="sc-mod ${count > 0 ? 'sc-mod--on' : ''} ${count === 2 ? 'sc-mod--hot' : ''}"
                data-sku="${escapeHtml(o.sku)}" data-price="${o.price ?? ''}" data-count="${count}">
            <span class="sc-mod__name">${escapeHtml(o.name)}</span>
            ${priceLbl ? `<span class="sc-mod__price">${priceLbl}</span>` : ''}
            <span class="sc-mod__count">${count > 0 ? '×' + count : ''}</span>
        </button>
    `;
}

/**
 * Normalizuje wizualny slot modyfikatora na podstawie nowego kontraktu
 * `modifierVisuals[sku]` (m024 · Asset Studio; role `layer_top_down` + `modifier_hero`).
 *
 * Legacy `magicDict` (m018 · sh_modifier_visual_map) usunięte w m025 — jest
 * tylko jedno źródło prawdy: sh_asset_links + sh_modifiers.has_visual_impact.
 *
 * Zwraca `{ scatter, hero, source }` spłaszczone do shape oczekiwanego przez
 * silnik `mountPizzaScene`, lub `null` gdy SKU nie ma slotów.
 */
function resolveModifierVisual(sku, data) {
    const mv = data?.modifierVisuals?.[sku];
    if (!mv || mv.hasVisualImpact === false) return null;
    if (!mv.layerAsset && !mv.heroAsset) return null;

    const layer = mv.layerAsset;
    const hero = mv.heroAsset;
    return {
        source: 'asset_links',
        scatter: layer ? {
            url: resolveAssetUrl(layer.url),
            zIndex: layer.zIndex || 45,
            scale: 1.0,
            rotate: 0,
        } : null,
        hero: hero ? {
            url: resolveAssetUrl(hero.url),
            zIndex: hero.zIndex || 90,
        } : (layer ? {
            // Brak heroAsset → użyj layer jako bubble w x2 (Asset Studio może mieć
            // tylko layer_top_down bez osobnego modifier_hero).
            url: resolveAssetUrl(layer.url),
            zIndex: 90,
        } : null),
    };
}

function wireSurfaceCard(el, data, ctx, handlers) {
    el.querySelector('#sc-close-x')?.addEventListener('click', () => handlers.onClose?.());

    el.querySelector('#sc-mode-preview')?.addEventListener('click', () => {
        if (ctx.editMode !== false) handlers.onCtxChange?.({ editMode: false });
    });
    el.querySelector('#sc-mode-edit')?.addEventListener('click', () => {
        if (ctx.editMode !== true) handlers.onCtxChange?.({ editMode: true });
    });

    el.querySelector('#sc-surface-full')?.addEventListener('change', (e) => {
        handlers.onCtxChange?.({ surfaceFull: e.target.checked });
    });
    el.querySelector('#sc-half-toggle')?.addEventListener('change', (e) => {
        handlers.onCtxChange?.({ halfHalf: e.target.checked });
    });
    el.querySelector('#sc-half-b-sku')?.addEventListener('change', (e) => {
        handlers.onCtxChange?.({ halfBSku: e.target.value });
    });

    const addBtn = el.querySelector('#sc-add-btn');
    if (addBtn) {
        const halfInvalid = ctx.halfHalf && !ctx.halfBSku;
        addBtn.disabled = !!halfInvalid;
        addBtn.setAttribute('aria-disabled', halfInvalid ? 'true' : 'false');
        addBtn.title = halfInvalid ? 'Wybierz drugą pizzę (połowa B)' : '';
        addBtn.addEventListener('click', () => handlers.onAddToCart?.());
    }

    bindStageParallax(el.querySelector('#sc-stage'));
}

function bindStageParallax(stageEl) {
    if (!stageEl || stageEl.dataset.parallaxBound === '1') return;
    stageEl.dataset.parallaxBound = '1';

    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) return;

    const setVars = (x, y) => {
        stageEl.style.setProperty('--sc-parallax-x', `${x.toFixed(2)}px`);
        stageEl.style.setProperty('--sc-parallax-y', `${y.toFixed(2)}px`);
        stageEl.style.setProperty('--sc-parallax-rot', `${(x / 8).toFixed(2)}deg`);
    };
    const reset = () => setVars(0, 0);

    stageEl.addEventListener('pointermove', (ev) => {
        const box = stageEl.getBoundingClientRect();
        const relX = ((ev.clientX - box.left) / Math.max(box.width, 1)) - 0.5;
        const relY = ((ev.clientY - box.top) / Math.max(box.height, 1)) - 0.5;
        setVars(relX * 18, relY * 12);
    });
    stageEl.addEventListener('pointerleave', reset);

    window.addEventListener('deviceorientation', (ev) => {
        if (typeof ev.gamma !== 'number' || typeof ev.beta !== 'number') return;
        const x = Math.max(-10, Math.min(10, ev.gamma)) * 0.8;
        const y = Math.max(-8, Math.min(8, ev.beta - 45)) * 0.35;
        setVars(x, y);
    }, { passive: true });
}

/**
 * Recompute visible layers (base + modifier scatter/cluster/garnish)
 * + hero bubbles (x2 → hero obok pizzy) + live price.
 * Called after every modifier click / ctx change by online_app.
 */
export function repaintSurface(el, data, ctx) {
    const scene = el.querySelector('#pizza-scene');
    const bubblesEl = el.querySelector('#sc-hero-bubbles');
    const layersCountEl = el.querySelector('#sc-layers-count');
    const ctaPriceEl = el.querySelector('#sc-cta-price');
    if (!scene) return;

    const baseLayers = (data.visualLayers || []).map((L) => ({ ...L, _src: 'base' }));
    const scatterAdds = [];
    const heroes = [];

    // m024 · Faza 2.9 — jednorazowy znacznik ostatniej zmiany modyfikatora
    // (ustawiany przez cycleModifier). Pozwala zaznaczyć jedną konkretną bąbelkę
    // klasą `sc-hero__bubble--new` do animacji pop-in.
    const lastChange = ctx._lastModChange;
    ctx._lastModChange = null; // konsumuj — kolejny repaint nie powieli animacji

    // Build scatter/cluster layers from counted modifiers
    (ctx.modCounts || new Map()).forEach((count, sku) => {
        const mv = resolveModifierVisual(sku, data);
        if (!mv) return;

        if (count >= 1 && mv.scatter?.url) {
            scatterAdds.push({
                layerSku: sku + '@' + (count === 2 ? 'x2' : 'x1'),
                assetUrl: mv.scatter.url,
                zIndex: mv.scatter.zIndex,
                calScale: (mv.scatter.scale || 1) * (count === 2 ? 1.15 : 1),
                calRotate: mv.scatter.rotate || 0,
                _modSku: sku,
                _modSource: mv.source,
            });
        }
        if (count === 2 && mv.hero?.url) {
            heroes.push({
                modSku: sku,
                url: mv.hero.url,
                isNew: !!(lastChange && lastChange.sku === sku && lastChange.next === 2),
            });
        }
    });

    // Combine base + adds, stable sort by zIndex
    const layersA = [...baseLayers, ...scatterAdds];
    const layersB = ctx.layersB || [];

    refreshPizzaScene(scene, layersA, layersB, ctx, {
        effects: data.atmosphericEffects || null,
        camera: data.activeCamera || null,
        sceneSpec: data.sceneContract?.scene_spec || null,
        stage: data.surfaceUrl ? { boardUrl: data.surfaceUrl } : null,
        companions: data.companions || [],
        infoBlock: ctx.editMode ? null : { visible: false },
        ambient: data.sceneContract?.scene_spec?.ambient || null,
        dishMeta: {
            name: data.item?.name || '',
            categoryName: data._categoryName || 'Danie',
            description: data.item?.description || '',
            price: data.item?.basePrice ?? data.price ?? null,
        },
    });

    // Hero bubbles next to the pizza — ostatnio dodany dostaje klasę `--new`
    // dla animacji materializacji (pop-in + shimmer; CSS w modules/online/css/style.css).
    if (bubblesEl) {
        bubblesEl.innerHTML = heroes.map((h, i) => `
            <span class="sc-hero__bubble ${h.isNew ? 'sc-hero__bubble--new' : ''}" style="--bi:${i}">
                <img src="${escapeHtml(resolveAssetUrl(h.url))}" alt="" loading="lazy">
            </span>
        `).join('');
        // Po zakończeniu animacji zdejmij klasę --new, żeby kolejny repaint nie zagrał ponownie.
        bubblesEl.querySelectorAll('.sc-hero__bubble--new').forEach((b) => {
            b.addEventListener('animationend', () => b.classList.remove('sc-hero__bubble--new'), { once: true });
        });
    }

    if (layersCountEl) layersCountEl.textContent = `${layersA.length} warstw`;

    // Live-price (optimistic; server CartEngine is canonical on add-to-cart)
    if (ctaPriceEl) {
        const base = data.item?.basePrice != null ? Number(String(data.item.basePrice).replace(',', '.')) : 0;
        const half = ctx.halfHalf ? Number(data.halfHalfSurcharge || 0) : 0;
        let mods = 0;
        (ctx.modCounts || new Map()).forEach((cnt, sku) => {
            const m = (data._modIndex || {})[sku];
            if (!m || m.price == null) return;
            mods += Number(String(m.price).replace(',', '.')) * cnt;
        });
        const total = base + half + mods;
        const next = moneyPl(total);
        if (ctaPriceEl.textContent !== next) {
            ctaPriceEl.textContent = next;
            ctaPriceEl.classList.remove('is-bumped');
            void ctaPriceEl.offsetWidth; // restart animation
            ctaPriceEl.classList.add('is-bumped');
            setTimeout(() => ctaPriceEl.classList.remove('is-bumped'), 320);
        }
    }
}

export function updateCartFab(count, totalLabel) {
    const fab = document.getElementById('cart-fab');
    const badge = document.getElementById('cart-fab-count');
    const total = document.getElementById('cart-fab-total');
    const topBadge = document.getElementById('topbar-cart-count');
    const label = count > 0 ? `${count} ${count === 1 ? 'pozycja' : count < 5 ? 'pozycje' : 'pozycji'}` : 'Koszyk pusty';
    if (badge) badge.textContent = label;
    if (total) total.textContent = totalLabel || '0,00 zł';
    if (topBadge) topBadge.textContent = String(count || 0);
    if (fab) fab.dataset.empty = count > 0 ? '0' : '1';
}

export function renderCartLines(container, lines, onRemove) {
    if (!container) return;
    container.innerHTML = '';
    if (!lines.length) {
        container.innerHTML = '<p class="online-muted">Koszyk jest pusty.</p>';
        return;
    }
    lines.forEach((line, idx) => {
        const row = document.createElement('div');
        row.className = 'cart-line';
        const label = line.label || line.name || line.itemSku;
        row.innerHTML = `
            <span class="cart-line__name">${escapeHtml(label)} × ${line.qty}</span>
            <button type="button" class="cart-line__rm" data-i="${idx}" aria-label="Usuń">✕</button>
        `;
        row.querySelector('.cart-line__rm').addEventListener('click', () => onRemove(idx));
        container.appendChild(row);
    });
}

/**
 * Render podsumowania koszyka z rabatami (sh_promotions — Faza 4.1).
 *   - Lista `applied_auto_promotions` (nazwa + badge + kwota).
 *   - Manual promo_code (applied_discount) jeżeli aplikowany.
 *   - Subtotal, delivery fee, grand_total.
 *
 * @param {HTMLElement} container — wrapper pod listą pozycji
 * @param {object}      calc      — response z cart_calculate (response object)
 */
export function renderCartSummary(container, calc) {
    if (!container) return;
    if (!calc) {
        container.innerHTML = '';
        return;
    }
    const autoPromos = Array.isArray(calc.applied_auto_promotions) ? calc.applied_auto_promotions : [];
    const manualPromo = calc.applied_discount || null;
    const subtotal    = calc.subtotal || null;
    const delivery    = calc.delivery_fee || null;
    const totalDisc   = calc.discount || null;
    const grand       = calc.grand_total || null;

    const promoRows = autoPromos.map((p) => {
        const badgeTxt = p.badge_text ? `<span class="cart-sum__badge cart-sum__badge--${escapeHtml(p.badge_style || 'amber')}">${escapeHtml(p.badge_text)}</span>` : '';
        const note = p.note ? `<span class="cart-sum__note">${escapeHtml(p.note)}</span>` : '';
        const amount = p.amount ? `<strong class="cart-sum__neg">−${escapeHtml(p.amount)}</strong>` : '';
        return `<div class="cart-sum__row cart-sum__row--promo">
            <span class="cart-sum__lbl">${badgeTxt}${escapeHtml(p.name || 'Promocja')}</span>
            <span class="cart-sum__val">${note}${amount}</span>
        </div>`;
    }).join('');

    const manualRow = manualPromo
        ? `<div class="cart-sum__row cart-sum__row--promo">
               <span class="cart-sum__lbl"><i class="fa-solid fa-ticket"></i> Kod: ${escapeHtml(calc.applied_promo_code || '')}</span>
               <strong class="cart-sum__neg">−${escapeHtml(manualPromo)}</strong>
           </div>`
        : '';

    container.innerHTML = `
        <div class="cart-sum">
            ${subtotal != null ? `<div class="cart-sum__row"><span>Suma przed rabatami</span><span>${escapeHtml(subtotal)}</span></div>` : ''}
            ${promoRows}
            ${manualRow}
            ${delivery != null ? `<div class="cart-sum__row"><span>Dostawa</span><span>${escapeHtml(delivery)}</span></div>` : ''}
            ${totalDisc != null ? `<div class="cart-sum__row cart-sum__row--muted"><span>Łączny rabat</span><strong class="cart-sum__neg">−${escapeHtml(totalDisc)}</strong></div>` : ''}
            ${grand != null ? `<div class="cart-sum__row cart-sum__row--total"><span>Razem</span><strong>${escapeHtml(grand)}</strong></div>` : ''}
        </div>
    `;
}
