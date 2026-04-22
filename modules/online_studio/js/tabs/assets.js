/**
 * Online Studio — ASSET STUDIO (m032 · Asset Library Organizer)
 *
 * Jeden panel do porządkowania 200+ assetów. Cechy:
 *   • Widok grupowany po kategorii (Ser/Mięso/Warzywo/...) + toggle flat.
 *   • Sidebar: filtry Kategorii + Bucketów + Cook state + Health.
 *   • Health Panel ("Do posprzątania"): sieroty, duble, bez kategorii, bez
 *     cook_state, duże pliki — jeden klik ustawia filtr.
 *   • Selection mode (checkboxy, Shift-click zakres, Ctrl+A, Del, Esc) +
 *     sticky bulk-action bar: Przenieś kategorię/bucket/cook_state, Tagi,
 *     Duplikuj, Usuń.
 *   • display_name jako tytuł (fallback ascii_key) + auto-zgadywanie z nazwy
 *     pliku w kreatorze. Rename smart (+ opcjonalny regen ascii_key).
 *   • Widok dubli + merge (wybierz keepera, linki przechodzą, reszta soft-del).
 *   • Inline quick-edit: klik chip kategorii/cook → popover z dropdownem.
 *   • Batch upload: wiele plików naraz, kolejka z progress i wspólnymi metadanymi.
 *
 * Backend: api/assets/engine.php (m021 + m031 + m032).
 */

// -----------------------------------------------------------------------------
// CONSTANTS
// -----------------------------------------------------------------------------

const BUCKETS = [
    { id: 'library',   label: 'Warstwa kompozytora',   hint: 'Składnik, element do DishSceneSpec (np. salami, ser)',    roleHint: 'layer',     icon: 'fa-layer-group',  accent: '#f59e0b' },
    { id: 'hero',      label: 'Zdjęcie hero dania',    hint: 'Główny kadr karty menu (np. margherita_hero)',           roleHint: 'hero',      icon: 'fa-image',        accent: '#10b981' },
    { id: 'library',   label: 'Ikonka modyfikatora',   hint: 'Sos czosnkowy, dodatek — wyświetli się w checkout',      roleHint: 'icon',      icon: 'fa-droplet',      accent: '#06b6d4' },
    { id: 'companion', label: 'Companion',             hint: 'Napój, sałatka, deska — element do stołu klienta',       roleHint: 'companion', icon: 'fa-bottle-water', accent: '#a855f7' },
    { id: 'surface',   label: 'Tekstura stołu',        hint: 'Blat, deska, marmur — tło dla "The Surface"',            roleHint: 'surface',   icon: 'fa-border-all',   accent: '#ef4444' },
    { id: 'brand',     label: 'Logo / brand',          hint: 'Logo tenanta, favicon, OG-share',                         roleHint: 'logo',      icon: 'fa-crown',        accent: '#eab308' },
];

// Kolejność sekcji w widoku grupowanym (od dna pizzy do wierzchu + dodatki na końcu)
const CATEGORIES_ORDER = [
    'board', 'base', 'sauce', 'cheese', 'meat', 'veg', 'herb',
    'extra', 'drink', 'surface', 'hero', 'brand', 'misc',
];

const CATEGORY_META = {
    board:   { label: 'Deska',        icon: 'fa-utensils',      accent: '#78716c' },
    base:    { label: 'Ciasto',       icon: 'fa-bread-slice',   accent: '#d97706' },
    sauce:   { label: 'Sos',          icon: 'fa-wine-bottle',   accent: '#dc2626' },
    cheese:  { label: 'Ser',          icon: 'fa-cheese',        accent: '#eab308' },
    meat:    { label: 'Mięso',        icon: 'fa-bacon',         accent: '#b91c1c' },
    veg:     { label: 'Warzywo',      icon: 'fa-carrot',        accent: '#16a34a' },
    herb:    { label: 'Zioło',        icon: 'fa-leaf',          accent: '#059669' },
    drink:   { label: 'Napój',        icon: 'fa-mug-saucer',    accent: '#2563eb' },
    extra:   { label: 'Dodatek',      icon: 'fa-star',          accent: '#a855f7' },
    surface: { label: 'Powierzchnia', icon: 'fa-border-all',    accent: '#ef4444' },
    hero:    { label: 'Hero',         icon: 'fa-image',         accent: '#10b981' },
    brand:   { label: 'Brand',        icon: 'fa-crown',         accent: '#eab308' },
    misc:    { label: 'Inne',         icon: 'fa-square',        accent: '#71717a' },
};

const CATEGORIES = CATEGORIES_ORDER.map(id => ({ id, label: CATEGORY_META[id].label, icon: CATEGORY_META[id].icon }));

const FILTER_BUCKETS = [
    { id: '',          label: 'Wszystkie',    icon: 'fa-layer-group' },
    { id: 'library',   label: 'Warstwy',      icon: 'fa-palette'     },
    { id: 'hero',      label: 'Hero',         icon: 'fa-image'       },
    { id: 'companion', label: 'Companions',   icon: 'fa-bottle-water'},
    { id: 'surface',   label: 'Surface',      icon: 'fa-border-all'  },
    { id: 'brand',     label: 'Brand',        icon: 'fa-crown'       },
    { id: 'legacy',    label: 'Legacy',       icon: 'fa-clock-rotate-left' },
];

const HEALTH_FILTERS = [
    { id: '',                 label: 'Wszystko',             icon: 'fa-database',   countKey: 'total' },
    { id: 'orphans',          label: 'Sieroty',              icon: 'fa-ghost',      countKey: 'orphans',            tone: 'warn' },
    { id: 'duplicates',       label: 'Duble',                icon: 'fa-clone',      countKey: 'duplicates',         tone: 'err'  },
    { id: 'missing_name',     label: 'Bez nazwy',            icon: 'fa-signature',  countKey: 'missingDisplayName', tone: 'warn' },
    { id: 'missing_cook',     label: 'Bez st. pieczenia',    icon: 'fa-fire',       countKey: 'missingCookState',   tone: 'warn' },
    { id: 'missing_category', label: 'Bez kategorii',        icon: 'fa-folder-open', countKey: 'missingCategory',   tone: 'warn' },
    { id: 'large',            label: 'Duże pliki (≥2MB)',    icon: 'fa-weight-hanging', countKey: 'largeFiles',     tone: 'info' },
];

const COOK_FILTERS = [
    { id: '',        label: 'Wszystkie',         icon: 'fa-circle-dot' },
    { id: 'either',  label: 'Neutralny',         icon: 'fa-circle'     },
    { id: 'raw',     label: 'Surowy (hero)',     icon: 'fa-seedling'   },
    { id: 'cooked',  label: 'Upieczony (pizza)', icon: 'fa-fire'       },
    { id: 'charred', label: 'Przypalony',        icon: 'fa-fire-flame-simple' },
];

const COOK_STATES = [
    { id: 'either',  label: 'Neutralny (domyślnie)' },
    { id: 'raw',     label: 'Surowy — hero/karta menu' },
    { id: 'cooked',  label: 'Upieczony — warstwy pizzy top-down' },
    { id: 'charred', label: 'Mocno przypalony (promocja)' },
];

const COOK_LABEL = {
    either:  'neutralny',
    raw:     'surowy',
    cooked:  'upieczony',
    charred: 'przypalony',
};

const ROLE_HINTS = ['layer', 'hero', 'surface', 'companion', 'icon', 'logo', 'thumbnail', 'poster', 'og'];

// role mapping per entity_type — co można zrobić z tym assetem
const ENTITY_ROLE_MAP = {
    menu_item:       [{ id: 'hero', label: 'Hero (karta dania)' }, { id: 'thumbnail', label: 'Miniatura' }, { id: 'og_image', label: 'OG share' }],
    modifier:        [{ id: 'layer_top_down', label: 'Warstwa top-down (Surface)' }, { id: 'modifier_hero', label: 'Hero modyfikatora (x2)' }, { id: 'thumbnail', label: 'Miniatura' }],
    board_companion: [{ id: 'companion_icon', label: 'Ikona na stole' }, { id: 'product_shot', label: 'Product shot' }],
    visual_layer:    [{ id: 'layer_top_down', label: 'Warstwa top-down' }, { id: 'product_shot', label: 'Product shot' }],
    surface_library: [{ id: 'surface_bg',  label: 'Tło powierzchni' }, { id: 'ambient_texture', label: 'Tekstura' }],
    tenant_brand:    [{ id: 'tenant_logo', label: 'Logo tenanta' }, { id: 'tenant_favicon', label: 'Favicon' }, { id: 'og_image', label: 'OG share' }],
};

// -----------------------------------------------------------------------------
// PUBLIC MOUNT
// -----------------------------------------------------------------------------

export function mountAssets(root, Studio, Api) {
    const state = {
        items: [],
        total: 0,
        // Filtry
        bucket: '',
        category: '',
        cookState: '',
        healthFilter: '',
        search: '',
        // Widok
        view: 'grouped',        // 'grouped' | 'flat'
        collapsed: new Set(),   // kategorie zwinięte w widoku grouped
        // Wybór
        selectedId: null,       // aktywny (details)
        selectedIds: new Set(), // wybór masowy
        lastSelectedId: null,   // dla Shift-click
        // Cache
        health: null,
        entitiesCache: null,
    };

    // Wymuszenie style/escapy XSS
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    root.innerHTML = `
        <div class="as-shell">
            <!-- DROP ZONE -->
            <div id="as-dropzone" class="as-dropzone" tabindex="0">
                <input type="file" id="as-file" accept=".webp,.png,.jpg,.jpeg,image/webp,image/png,image/jpeg" multiple hidden>
                <div class="as-dropzone__inner">
                    <div class="as-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div class="as-dropzone__title">Przeciągnij pliki lub kliknij, aby wgrać</div>
                    <div class="as-dropzone__sub">
                        WebP / PNG / JPG · min 200×200 · max 4096×4096 · max 5 MB ·
                        <span class="as-dropzone__hint">działa wsadowo — wiele plików naraz</span>
                    </div>
                </div>
            </div>

            <!-- HEALTH PANEL — "Do posprzątania" -->
            <div id="as-health" class="as-health">
                <div class="as-health__title">
                    <i class="fa-solid fa-broom"></i>
                    <span>Do posprzątania</span>
                    <button type="button" id="as-health-refresh" class="as-health__refresh" title="Odśwież statystyki">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
                <div id="as-health-chips" class="as-health__chips"></div>
            </div>

            <!-- TOOLBAR -->
            <div class="as-toolbar">
                <div class="as-filters-row">
                    <div class="as-filters-label">Kategoria</div>
                    <div class="as-filters" id="as-filters-cat"></div>
                </div>
                <div class="as-filters-row">
                    <div class="as-filters-label">Bucket</div>
                    <div class="as-filters" id="as-filters-bucket"></div>
                    <div class="as-filters-label as-filters-label--inline">Stan pieczenia</div>
                    <div class="as-filters" id="as-filters-cook"></div>
                </div>
                <div class="as-toolbar__right">
                    <input id="as-search" class="input" placeholder="Szukaj: nazwa / ascii_key / sub_type">
                    <div class="as-view-toggle" role="group" aria-label="Widok">
                        <button type="button" class="as-view-btn is-active" data-view="grouped" title="Grupuj po kategoriach">
                            <i class="fa-solid fa-layer-group"></i>
                        </button>
                        <button type="button" class="as-view-btn" data-view="flat" title="Widok płaski">
                            <i class="fa-solid fa-grip"></i>
                        </button>
                    </div>
                    <button id="as-refresh" class="btn btn--ghost" title="Odśwież (R)">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                    <span class="pill-chip" id="as-total">—</span>
                </div>
            </div>

            <!-- BULK ACTION BAR (widoczny gdy selectedIds.size > 0) -->
            <div id="as-bulk-bar" class="as-bulk-bar hidden" role="toolbar" aria-label="Akcje masowe">
                <div class="as-bulk-bar__info">
                    <i class="fa-solid fa-check-double"></i>
                    <span id="as-bulk-count">0 zaznaczonych</span>
                    <button type="button" class="as-bulk-bar__clear" id="as-bulk-clear" title="Odznacz (Esc)">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="as-bulk-bar__actions">
                    <button class="btn btn--ghost" id="as-bulk-category" title="Przenieś do kategorii">
                        <i class="fa-solid fa-folder-tree"></i> Kategoria
                    </button>
                    <button class="btn btn--ghost" id="as-bulk-cook" title="Ustaw stan pieczenia">
                        <i class="fa-solid fa-fire"></i> Cook state
                    </button>
                    <button class="btn btn--ghost" id="as-bulk-bucket" title="Przenieś do bucketu">
                        <i class="fa-solid fa-box-archive"></i> Bucket
                    </button>
                    <button class="btn btn--ghost" id="as-bulk-tags" title="Dopisz tagi">
                        <i class="fa-solid fa-tags"></i> Tagi
                    </button>
                    <button class="btn btn--ghost" id="as-bulk-duplicate" title="Duplikuj zaznaczone">
                        <i class="fa-solid fa-clone"></i> Duplikuj
                    </button>
                    <button class="btn btn--danger" id="as-bulk-delete" title="Usuń (Del)">
                        <i class="fa-solid fa-trash"></i> Usuń
                    </button>
                </div>
            </div>

            <!-- MAIN SPLIT: grid + details -->
            <div class="as-split">
                <div id="as-grid" class="as-grid" tabindex="0"></div>
                <aside id="as-details" class="as-details as-details--empty">
                    <div class="as-details__placeholder">
                        <i class="fa-solid fa-hand-pointer"></i>
                        <p>Kliknij kafelek, aby zobaczyć szczegóły, edycję i <strong>gdzie jest używany</strong>.</p>
                        <p class="muted">Zaznacz kilka (checkbox / Shift-click / Ctrl+A) i skorzystaj z akcji masowych u góry.</p>
                    </div>
                </aside>
            </div>

            <div id="as-empty" class="as-empty hidden">
                <i class="fa-solid fa-ghost"></i>
                <p>Brak assetów dla wybranego filtru.</p>
            </div>
        </div>
    `;

    const $dropzone     = root.querySelector('#as-dropzone');
    const $file         = root.querySelector('#as-file');
    const $filtersCat   = root.querySelector('#as-filters-cat');
    const $filtersBuc   = root.querySelector('#as-filters-bucket');
    const $filtersCook  = root.querySelector('#as-filters-cook');
    const $healthChips  = root.querySelector('#as-health-chips');
    const $grid         = root.querySelector('#as-grid');
    const $details      = root.querySelector('#as-details');
    const $empty        = root.querySelector('#as-empty');
    const $total        = root.querySelector('#as-total');
    const $search       = root.querySelector('#as-search');
    const $bulkBar      = root.querySelector('#as-bulk-bar');
    const $bulkCount    = root.querySelector('#as-bulk-count');

    // -------------------------------------------------------------------------
    // FILTERS
    // -------------------------------------------------------------------------
    function renderFilters() {
        // Kategoria
        const countsByCat = {};
        state.items.forEach(it => {
            const k = it.category || 'misc';
            countsByCat[k] = (countsByCat[k] || 0) + 1;
        });
        const catItems = [
            { id: '', label: 'Wszystkie', icon: 'fa-sparkles', accent: null },
            ...CATEGORIES_ORDER.map(id => ({ id, label: CATEGORY_META[id].label, icon: CATEGORY_META[id].icon, accent: CATEGORY_META[id].accent })),
        ];
        $filtersCat.innerHTML = catItems.map(f => `
            <button class="as-filter ${state.category === f.id ? 'active' : ''}" data-cat="${esc(f.id)}" ${f.accent ? `style="--cat-accent:${f.accent}"` : ''}>
                <i class="fa-solid ${f.icon}"></i>
                <span>${esc(f.label)}</span>
                ${f.id !== '' && countsByCat[f.id] ? `<span class="as-filter__count">${countsByCat[f.id]}</span>` : ''}
            </button>
        `).join('');
        $filtersCat.querySelectorAll('[data-cat]').forEach(btn => {
            btn.onclick = () => {
                state.category = btn.dataset.cat;
                reload(false);
            };
        });

        // Bucket
        $filtersBuc.innerHTML = FILTER_BUCKETS.map(f => `
            <button class="as-filter as-filter--sm ${state.bucket === f.id ? 'active' : ''}" data-bucket="${esc(f.id)}">
                <i class="fa-solid ${f.icon}"></i> ${esc(f.label)}
            </button>
        `).join('');
        $filtersBuc.querySelectorAll('[data-bucket]').forEach(btn => {
            btn.onclick = () => {
                state.bucket = btn.dataset.bucket;
                reload(false);
            };
        });

        // Cook
        $filtersCook.innerHTML = COOK_FILTERS.map(f => `
            <button class="as-filter as-filter--sm ${state.cookState === f.id ? 'active' : ''}" data-cook="${esc(f.id)}">
                <i class="fa-solid ${f.icon}"></i> ${esc(f.label)}
            </button>
        `).join('');
        $filtersCook.querySelectorAll('[data-cook]').forEach(btn => {
            btn.onclick = () => {
                state.cookState = btn.dataset.cook;
                reload(false);
            };
        });
    }

    // -------------------------------------------------------------------------
    // HEALTH PANEL
    // -------------------------------------------------------------------------
    function renderHealth() {
        const h = state.health;
        $healthChips.innerHTML = HEALTH_FILTERS.map(f => {
            const cnt = h ? (h[f.countKey] || 0) : '—';
            const isActive = state.healthFilter === f.id;
            const zero = cnt === 0;
            return `
                <button type="button"
                    class="as-health-chip ${isActive ? 'is-active' : ''} ${zero ? 'is-zero' : ''} ${f.tone ? 'is-' + f.tone : ''}"
                    data-health="${esc(f.id)}"
                    ${zero ? 'disabled' : ''}>
                    <i class="fa-solid ${f.icon}"></i>
                    <span class="as-health-chip__label">${esc(f.label)}</span>
                    <span class="as-health-chip__count">${cnt}</span>
                </button>
            `;
        }).join('');

        $healthChips.querySelectorAll('[data-health]').forEach(btn => {
            btn.onclick = () => {
                state.healthFilter = btn.dataset.health;
                reload(false);
            };
        });

        // Akcja "Scal duble" jeśli są grupy
        if (h && h.duplicateGroups && h.duplicateGroups.length > 0) {
            const merge = document.createElement('button');
            merge.type = 'button';
            merge.className = 'as-health-chip is-err as-health-chip--action';
            merge.innerHTML = `<i class="fa-solid fa-code-merge"></i><span class="as-health-chip__label">Scal duble</span><span class="as-health-chip__count">${h.duplicateGroups.length}</span>`;
            merge.onclick = () => openMergeDuplicates();
            $healthChips.appendChild(merge);
        }
    }

    async function loadHealth() {
        const r = await Api.assetsScanHealth();
        if (r.success) {
            state.health = r.data;
            renderHealth();
        }
    }

    // -------------------------------------------------------------------------
    // VISIBLE ITEMS (po search client-side)
    // -------------------------------------------------------------------------
    function visibleItems() {
        const q = state.search.toLowerCase().trim();
        if (!q) return state.items;
        return state.items.filter(it => (
            (it.displayName || '').toLowerCase().includes(q)
            || (it.asciiKey   || '').toLowerCase().includes(q)
            || (it.subType    || '').toLowerCase().includes(q)
            || (it.storageUrl || '').toLowerCase().includes(q)
            || (it.tags || []).some(t => t.toLowerCase().includes(q))
        ));
    }

    // -------------------------------------------------------------------------
    // GRID RENDER (grouped / flat)
    // -------------------------------------------------------------------------
    function renderBody() {
        const items = visibleItems();
        $total.innerHTML = `<i class="fa-solid fa-database"></i> ${items.length} / ${state.total}`;

        if (items.length === 0) {
            $grid.innerHTML = '';
            $empty.classList.remove('hidden');
            return;
        }
        $empty.classList.add('hidden');

        if (state.view === 'flat') {
            $grid.className = 'as-grid as-grid--flat';
            $grid.innerHTML = items.map(renderCard).join('');
        } else {
            // Grupuj
            const groups = new Map();
            for (const c of CATEGORIES_ORDER) groups.set(c, []);
            for (const it of items) {
                const k = CATEGORIES_ORDER.includes(it.category) ? it.category : 'misc';
                if (!groups.has(k)) groups.set(k, []);
                groups.get(k).push(it);
            }
            $grid.className = 'as-grid as-grid--grouped';
            $grid.innerHTML = Array.from(groups.entries())
                .filter(([, arr]) => arr.length > 0)
                .map(([cat, arr]) => renderSection(cat, arr))
                .join('');
        }

        attachCardHandlers();
    }

    function renderSection(cat, arr) {
        const meta = CATEGORY_META[cat] || CATEGORY_META.misc;
        const isCollapsed = state.collapsed.has(cat);
        // Wszystkie zaznaczone w tej sekcji?
        const sectionIds = arr.map(a => a.id);
        const allSelected = sectionIds.every(id => state.selectedIds.has(id));
        return `
            <section class="as-section ${isCollapsed ? 'is-collapsed' : ''}" data-cat="${esc(cat)}" style="--cat-accent:${meta.accent}">
                <header class="as-section__head">
                    <button type="button" class="as-section__toggle" data-toggle="${esc(cat)}" aria-expanded="${!isCollapsed}">
                        <i class="fa-solid fa-chevron-${isCollapsed ? 'right' : 'down'}"></i>
                    </button>
                    <i class="fa-solid ${meta.icon} as-section__icon"></i>
                    <h3 class="as-section__title">${esc(meta.label)}</h3>
                    <span class="as-section__count">${arr.length}</span>
                    <label class="as-section__select" title="Zaznacz wszystkie z kategorii">
                        <input type="checkbox" data-select-section="${esc(cat)}" ${allSelected ? 'checked' : ''}>
                        <span>wszystkie</span>
                    </label>
                </header>
                ${isCollapsed ? '' : `<div class="as-section__cards">${arr.map(renderCard).join('')}</div>`}
            </section>
        `;
    }

    function renderCard(a) {
        const selected = state.selectedIds.has(a.id);
        const isActive = state.selectedId === a.id;
        const title    = a.displayName || a.asciiKey;
        const sub      = a.displayName ? a.asciiKey : a.subType || '';
        const isDup    = (a.duplicateCount || 0) > 1;
        const catMeta  = CATEGORY_META[a.category] || CATEGORY_META.misc;

        return `
            <div class="as-card ${isActive ? 'is-active' : ''} ${selected ? 'is-selected' : ''} ${isDup ? 'is-duplicate' : ''}"
                 data-id="${a.id}"
                 tabindex="0"
                 style="--card-accent:${catMeta.accent}">
                <label class="as-card__check" title="Zaznacz (Spacja / Shift-click)">
                    <input type="checkbox" data-check="${a.id}" ${selected ? 'checked' : ''}>
                    <span class="as-card__check-box"><i class="fa-solid fa-check"></i></span>
                </label>
                <div class="as-card__badges">
                    ${a.linkCount > 0
                        ? `<span class="as-card__badge" title="${a.linkCount} aktywnych powiązań"><i class="fa-solid fa-link"></i> ${a.linkCount}</span>`
                        : `<span class="as-card__badge as-card__badge--empty" title="Nieużywany"><i class="fa-solid fa-ghost"></i></span>`}
                    ${isDup ? `<span class="as-card__badge as-card__badge--dup" title="${a.duplicateCount} dubli po SHA-256"><i class="fa-solid fa-clone"></i> ${a.duplicateCount}</span>` : ''}
                </div>
                <div class="as-card__image">
                    <img src="${esc(a.publicUrl)}" alt="${esc(title)}" loading="lazy" onerror="this.style.opacity='0.15'">
                </div>
                <div class="as-card__body">
                    <div class="as-card__title" title="${esc(title)}">${esc(title)}</div>
                    ${sub ? `<div class="as-card__sub" title="${esc(sub)}">${esc(sub)}</div>` : ''}
                    <div class="as-card__meta">
                        ${a.category ? `<button class="chip chip--cat" data-edit-cat="${a.id}" title="Zmień kategorię"><i class="fa-solid ${catMeta.icon}"></i> ${esc(catMeta.label)}</button>` : ''}
                        ${a.cookState && a.cookState !== 'either'
                            ? `<button class="chip chip--cook chip--cook-${esc(a.cookState)}" data-edit-cook="${a.id}" title="Stan pieczenia: ${esc(a.cookState)}">${esc(COOK_LABEL[a.cookState] || a.cookState)}</button>`
                            : `<button class="chip chip--cook chip--cook-either" data-edit-cook="${a.id}" title="Brak stanu pieczenia — kliknij aby ustawić">neutralny</button>`}
                        ${a.width && a.height ? `<span class="chip chip--dim">${a.width}×${a.height}</span>` : ''}
                    </div>
                    ${(a.tags && a.tags.length) ? `<div class="as-card__tags">${a.tags.slice(0,4).map(t => `<span class="chip chip--tag">#${esc(t)}</span>`).join('')}</div>` : ''}
                </div>
            </div>
        `;
    }

    function attachCardHandlers() {
        // Card click
        $grid.querySelectorAll('.as-card').forEach(card => {
            card.onclick = (e) => {
                // Checkbox i chip-edit mają własne handlery — NIE otwieraj details
                if (e.target.closest('[data-check]') || e.target.closest('[data-edit-cat]') || e.target.closest('[data-edit-cook]')) return;
                const id = parseInt(card.dataset.id, 10);
                if (e.shiftKey && state.lastSelectedId != null) {
                    // Zakres selection
                    selectRange(state.lastSelectedId, id);
                } else if (e.ctrlKey || e.metaKey) {
                    toggleSelect(id);
                } else {
                    // Klik zwykły — details
                    state.selectedId = id;
                    state.lastSelectedId = id;
                    renderBody();
                    renderDetails();
                }
            };
        });
        // Checkbox
        $grid.querySelectorAll('[data-check]').forEach(chk => {
            chk.onclick = (e) => {
                e.stopPropagation();
                const id = parseInt(chk.dataset.check, 10);
                toggleSelect(id, chk.checked);
                state.lastSelectedId = id;
            };
        });
        // Chip edit cat / cook
        $grid.querySelectorAll('[data-edit-cat]').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const id = parseInt(btn.dataset.editCat, 10);
                openQuickPopover(btn, id, 'category');
            };
        });
        $grid.querySelectorAll('[data-edit-cook]').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const id = parseInt(btn.dataset.editCook, 10);
                openQuickPopover(btn, id, 'cook_state');
            };
        });
        // Section toggle
        $grid.querySelectorAll('[data-toggle]').forEach(btn => {
            btn.onclick = () => {
                const cat = btn.dataset.toggle;
                if (state.collapsed.has(cat)) state.collapsed.delete(cat);
                else state.collapsed.add(cat);
                renderBody();
            };
        });
        // Select all in section
        $grid.querySelectorAll('[data-select-section]').forEach(chk => {
            chk.onclick = (e) => {
                e.stopPropagation();
                const cat = chk.dataset.selectSection;
                const items = visibleItems().filter(it => (it.category || 'misc') === cat);
                items.forEach(it => {
                    if (chk.checked) state.selectedIds.add(it.id);
                    else state.selectedIds.delete(it.id);
                });
                renderBody();
                renderBulkBar();
            };
        });
    }

    function selectRange(fromId, toId) {
        const ids = visibleItems().map(it => it.id);
        const iFrom = ids.indexOf(fromId);
        const iTo = ids.indexOf(toId);
        if (iFrom < 0 || iTo < 0) { toggleSelect(toId, true); return; }
        const [a, b] = iFrom < iTo ? [iFrom, iTo] : [iTo, iFrom];
        for (let i = a; i <= b; i++) state.selectedIds.add(ids[i]);
        renderBody();
        renderBulkBar();
    }

    function toggleSelect(id, force) {
        if (force === true)  state.selectedIds.add(id);
        else if (force === false) state.selectedIds.delete(id);
        else if (state.selectedIds.has(id)) state.selectedIds.delete(id);
        else state.selectedIds.add(id);
        renderBody();
        renderBulkBar();
    }

    // -------------------------------------------------------------------------
    // QUICK POPOVER (inline edit category / cook_state)
    // -------------------------------------------------------------------------
    let activePopover = null;
    function openQuickPopover(anchor, id, field) {
        closePopover();
        const pop = document.createElement('div');
        pop.className = 'as-popover';
        let html = '';
        if (field === 'category') {
            html = `<div class="as-popover__title">Zmień kategorię</div>` +
                CATEGORIES_ORDER.map(c => {
                    const m = CATEGORY_META[c];
                    return `<button type="button" class="as-popover__opt" data-val="${esc(c)}" style="--cat-accent:${m.accent}">
                        <i class="fa-solid ${m.icon}"></i><span>${esc(m.label)}</span>
                    </button>`;
                }).join('');
        } else if (field === 'cook_state') {
            html = `<div class="as-popover__title">Stan pieczenia</div>` +
                COOK_STATES.map(c => `<button type="button" class="as-popover__opt" data-val="${esc(c.id)}"><span>${esc(c.label)}</span></button>`).join('');
        }
        pop.innerHTML = html;
        document.body.appendChild(pop);

        const rect = anchor.getBoundingClientRect();
        pop.style.left = Math.max(8, rect.left) + 'px';
        pop.style.top  = (rect.bottom + 6) + 'px';

        // Dopasuj do viewportu
        requestAnimationFrame(() => {
            const pr = pop.getBoundingClientRect();
            if (pr.right > window.innerWidth - 8) pop.style.left = (window.innerWidth - pr.width - 8) + 'px';
            if (pr.bottom > window.innerHeight - 8) pop.style.top = (rect.top - pr.height - 6) + 'px';
        });

        pop.querySelectorAll('[data-val]').forEach(btn => {
            btn.onclick = async () => {
                const val = btn.dataset.val;
                closePopover();
                const payload = { id };
                payload[field] = val;
                const r = await Api.assetsUpdate(payload);
                if (r.success) {
                    Studio.toast('Zapisano.', 'ok');
                    await reload(false);
                } else {
                    Studio.toast(r.message || 'Błąd.', 'err');
                }
            };
        });

        activePopover = pop;
        setTimeout(() => {
            document.addEventListener('click', onDocClick, { once: true });
        }, 0);
    }
    function onDocClick(e) {
        if (activePopover && !activePopover.contains(e.target)) closePopover();
    }
    function closePopover() {
        if (activePopover) {
            activePopover.remove();
            activePopover = null;
        }
    }

    // -------------------------------------------------------------------------
    // BULK ACTION BAR
    // -------------------------------------------------------------------------
    function renderBulkBar() {
        const n = state.selectedIds.size;
        if (n === 0) { $bulkBar.classList.add('hidden'); return; }
        $bulkBar.classList.remove('hidden');
        $bulkCount.textContent = `${n} zaznaczonych`;
    }

    function clearSelection() {
        state.selectedIds.clear();
        state.lastSelectedId = null;
        renderBody();
        renderBulkBar();
    }

    function bulkIds() {
        return Array.from(state.selectedIds);
    }

    async function bulkUpdate(patch, label) {
        const ids = bulkIds();
        if (!ids.length) return;
        const r = await Api.assetsBulkUpdate(ids, patch);
        if (r.success) {
            Studio.toast(`${label}: ${r.data.updated} assetów.`, 'ok');
            clearSelection();
            await reload(false);
        } else {
            Studio.toast(r.message || 'Błąd.', 'err');
        }
    }

    function openBulkChoice(title, options, onPick) {
        const body = document.createElement('div');
        body.innerHTML = `
            <p class="muted" style="margin:0 0 14px">${esc(title)} (${state.selectedIds.size} assetów)</p>
            <div class="as-bulk-choice">
                ${options.map(o => `<button class="as-bulk-choice__btn" data-val="${esc(o.id)}" ${o.accent ? `style="--cat-accent:${o.accent}"` : ''}>
                    ${o.icon ? `<i class="fa-solid ${o.icon}"></i>` : ''}
                    <span>${esc(o.label)}</span>
                </button>`).join('')}
            </div>
        `;
        const m = Studio.modal({ title, body });
        body.querySelectorAll('[data-val]').forEach(btn => {
            btn.onclick = async () => {
                await onPick(btn.dataset.val);
                m.close();
            };
        });
    }

    root.querySelector('#as-bulk-clear').onclick = clearSelection;
    root.querySelector('#as-bulk-category').onclick = () => {
        const opts = CATEGORIES_ORDER.map(c => ({ id: c, label: CATEGORY_META[c].label, icon: CATEGORY_META[c].icon, accent: CATEGORY_META[c].accent }));
        openBulkChoice('Przenieś do kategorii', opts, (val) => bulkUpdate({ category: val }, 'Przeniesiono'));
    };
    root.querySelector('#as-bulk-cook').onclick = () => {
        openBulkChoice('Ustaw stan pieczenia', COOK_STATES.map(c => ({ id: c.id, label: c.label })), (val) => bulkUpdate({ cook_state: val }, 'Ustawiono cook_state'));
    };
    root.querySelector('#as-bulk-bucket').onclick = () => {
        const opts = ['library','hero','surface','companion','brand','variant','legacy'].map(b => ({ id: b, label: b }));
        openBulkChoice('Przenieś do bucketu', opts, (val) => bulkUpdate({ bucket: val }, 'Przeniesiono bucket'));
    };
    root.querySelector('#as-bulk-tags').onclick = async () => {
        const raw = prompt('Dopisz tagi (po przecinku):');
        if (raw == null) return;
        const tags = raw.split(',').map(s => s.trim()).filter(Boolean);
        if (!tags.length) return;
        await bulkUpdate({ tags_append: tags }, 'Dodano tagi');
    };
    root.querySelector('#as-bulk-duplicate').onclick = async () => {
        const ids = bulkIds();
        if (!ids.length) return;
        if (!confirm(`Sklonować ${ids.length} assetów? Każdy będzie miał nowy ascii_key i zero linków.`)) return;
        let ok = 0;
        for (const id of ids) {
            const r = await Api.assetsDuplicate({ id });
            if (r.success) ok++;
        }
        Studio.toast(`Sklonowano ${ok}/${ids.length}.`, ok === ids.length ? 'ok' : 'warn');
        clearSelection();
        await reload(false);
    };
    root.querySelector('#as-bulk-delete').onclick = async () => {
        const ids = bulkIds();
        if (!ids.length) return;
        if (!confirm(`Oznaczyć ${ids.length} assetów jako usunięte?\n\n(Soft-delete — pliki zostają na dysku, można przywrócić z bazy)`)) return;
        const r = await Api.assetsBulkSoftDelete(ids);
        if (r.success) {
            Studio.toast(`Usunięto ${r.data.deleted}.`, 'ok');
            clearSelection();
            await reload();
        } else {
            Studio.toast(r.message || 'Błąd.', 'err');
        }
    };

    // -------------------------------------------------------------------------
    // DETAILS PANEL
    // -------------------------------------------------------------------------
    async function renderDetails() {
        const a = state.items.find(x => x.id === state.selectedId);
        if (!a) {
            $details.classList.add('as-details--empty');
            $details.innerHTML = `
                <div class="as-details__placeholder">
                    <i class="fa-solid fa-hand-pointer"></i>
                    <p>Kliknij kafelek, aby zobaczyć szczegóły.</p>
                </div>
            `;
            return;
        }
        $details.classList.remove('as-details--empty');
        const catMeta = CATEGORY_META[a.category] || CATEGORY_META.misc;
        const title = a.displayName || a.asciiKey;
        $details.innerHTML = `
            <div class="as-det">
                <div class="as-det__preview">
                    <img src="${esc(a.publicUrl)}" alt="${esc(title)}">
                </div>
                <div class="as-det__title">
                    <strong>${esc(title)}</strong>
                    <code>${esc(a.asciiKey)}</code>
                </div>
                <div class="as-det__key">
                    <span class="chip chip--cat" style="--cat-accent:${catMeta.accent}"><i class="fa-solid ${catMeta.icon}"></i> ${esc(catMeta.label)}</span>
                    <span class="chip">${esc(a.bucket)}</span>
                    ${a.roleHint ? `<span class="chip">${esc(a.roleHint)}</span>` : ''}
                    ${a.cookState && a.cookState !== 'either' ? `<span class="chip chip--cook chip--cook-${esc(a.cookState)}">${esc(COOK_LABEL[a.cookState] || a.cookState)}</span>` : ''}
                </div>
                ${(a.tags && a.tags.length) ? `<div class="as-det__tags">${a.tags.map(t => `<span class="chip chip--tag">#${esc(t)}</span>`).join('')}</div>` : ''}
                <div class="as-det__meta">
                    ${a.width && a.height ? `<span><i class="fa-solid fa-expand"></i> ${a.width}×${a.height}</span>` : ''}
                    ${a.filesizeBytes ? `<span><i class="fa-solid fa-weight-hanging"></i> ${Math.round(a.filesizeBytes/1024)} KB</span>` : ''}
                    ${a.mimeType ? `<span><i class="fa-solid fa-file-code"></i> ${esc(a.mimeType)}</span>` : ''}
                    ${a.subType ? `<span><i class="fa-solid fa-tag"></i> ${esc(a.subType)}</span>` : ''}
                    ${a.tenantId === 0 ? `<span class="pill-chip pill-chip--global"><i class="fa-solid fa-globe"></i> global</span>` : ''}
                    ${(a.duplicateCount||0) > 1 ? `<span class="pill-chip pill-chip--warn"><i class="fa-solid fa-clone"></i> ${a.duplicateCount} dubli</span>` : ''}
                </div>

                <div class="as-det__section">
                    <div class="as-det__section-title">
                        <i class="fa-solid fa-link"></i> Gdzie używane
                        <span class="pill-chip" id="as-det-usage-count">?</span>
                    </div>
                    <div id="as-det-usage" class="as-det__usage">
                        <div class="muted"><i class="fa-solid fa-spinner fa-spin"></i> Ładuję...</div>
                    </div>
                </div>

                <div class="as-det__actions">
                    <button class="btn btn--accent" id="as-det-link"><i class="fa-solid fa-plus"></i> Powiąż z encją</button>
                    <button class="btn btn--ghost" id="as-det-edit"><i class="fa-solid fa-pen"></i> Edytuj</button>
                    <button class="btn btn--ghost" id="as-det-dup"><i class="fa-solid fa-clone"></i> Duplikuj</button>
                    ${a.tenantId !== 0
                        ? `<button class="btn btn--danger" id="as-det-del"><i class="fa-solid fa-trash"></i> Soft-delete</button>`
                        : ''}
                </div>
            </div>
        `;

        loadUsage(a.id);

        $details.querySelector('#as-det-link')?.addEventListener('click', () => openLinkWizard(a));
        $details.querySelector('#as-det-edit')?.addEventListener('click', () => openEditor(a));
        $details.querySelector('#as-det-dup')?.addEventListener('click',  () => duplicateAsset(a));
        $details.querySelector('#as-det-del')?.addEventListener('click',  () => softDelete(a));
    }

    async function loadUsage(assetId) {
        const r = await Api.assetsListUsage(assetId);
        const $usage = $details.querySelector('#as-det-usage');
        const $count = $details.querySelector('#as-det-usage-count');
        if (!$usage) return;
        if (!r.success) {
            $usage.innerHTML = `<div class="muted">Błąd: ${esc(r.message || 'nieznany')}</div>`;
            return;
        }
        const links = r.data.links || [];
        if ($count) $count.textContent = String(links.length);
        if (links.length === 0) {
            $usage.innerHTML = `<div class="muted"><i class="fa-solid fa-ghost"></i> Nie jest powiązany z żadną encją.</div>`;
            return;
        }
        $usage.innerHTML = links.map(l => `
            <div class="as-usage-row" data-link-id="${l.id}">
                <div class="as-usage-row__main">
                    <div class="as-usage-row__label">${esc(l.entityLabel || (l.entityType + ':' + l.entityRef))}</div>
                    <div class="as-usage-row__role"><span class="chip">${esc(l.role)}</span></div>
                </div>
                <button class="btn btn--sm btn--danger" data-unlink="${l.id}" title="Rozłącz">
                    <i class="fa-solid fa-link-slash"></i>
                </button>
            </div>
        `).join('');
        $usage.querySelectorAll('[data-unlink]').forEach(btn => {
            btn.onclick = async () => {
                if (!confirm('Rozłączyć ten asset od encji?')) return;
                const r2 = await Api.assetsUnlink({ id: parseInt(btn.dataset.unlink, 10) });
                if (r2.success) {
                    Studio.toast('Rozłączono.', 'ok');
                    await loadUsage(assetId);
                    await reload(false);
                } else {
                    Studio.toast(r2.message || 'Błąd.', 'err');
                }
            };
        });
    }

    async function duplicateAsset(a) {
        const r = await Api.assetsDuplicate({ id: a.id });
        if (r.success) {
            Studio.toast('Sklonowano.', 'ok');
            state.selectedId = r.data.id;
            await reload(false);
        } else {
            Studio.toast(r.message || 'Błąd.', 'err');
        }
    }

    async function softDelete(a) {
        if (!confirm(`Oznaczyć "${a.displayName || a.asciiKey}" jako usunięty?\n\n(Soft-delete — plik zostaje na dysku, można przywrócić z bazy)`)) return;
        const r = await Api.assetsSoftDelete({ id: a.id, cascade_links: true });
        if (r.success) {
            Studio.toast('Usunięto (soft).', 'ok');
            state.selectedId = null;
            await reload();
        } else {
            Studio.toast(r.message || 'Błąd.', 'err', 5000);
        }
    }

    // -------------------------------------------------------------------------
    // UPLOAD — kolejka wsadowa (batch)
    // -------------------------------------------------------------------------
    function handleFiles(files) {
        if (!files || files.length === 0) return;
        const arr = Array.from(files);
        if (arr.length === 1) {
            openUploadWizard(arr[0], null);
            return;
        }
        // Multi: najpierw wizard na pierwszym z opcją "zastosuj do wszystkich"
        openUploadWizard(arr[0], arr);
    }

    function openUploadWizard(file, batchFiles) {
        const previewUrl = URL.createObjectURL(file);
        const guessHint = guessFromFilename(file.name);
        const displayGuess = prettyNameFromFilename(file.name);
        const isBatch = batchFiles && batchFiles.length > 1;

        const body = document.createElement('div');
        body.innerHTML = `
            <div class="wiz">
                <div class="wiz__preview">
                    <img src="${esc(previewUrl)}" alt="preview">
                    <div class="wiz__filename">${esc(file.name)}<br><small>${Math.round(file.size/1024)} KB</small></div>
                </div>
                <div class="wiz__body">
                    ${isBatch ? `
                        <div class="wiz__batch-note">
                            <i class="fa-solid fa-layer-group"></i>
                            Wgrywasz <strong>${batchFiles.length}</strong> plików wsadowo — te ustawienia zastosują się do wszystkich.
                            ${batchFiles.length} × &lt; ${Math.round((batchFiles.reduce((a,f)=>a+f.size,0))/1024/1024*10)/10} MB łącznie.
                        </div>
                    ` : ''}
                    <div class="wiz__step">
                        <div class="wiz__step-title">1. Co to jest?</div>
                        <div class="wiz__bucket-grid" id="wiz-bucket-grid">
                            ${BUCKETS.map((b, idx) => {
                                const selected = guessHint && guessHint.bucketIdx === idx ? 'selected' : (!guessHint && idx === 0 ? 'selected' : '');
                                return `
                                    <button type="button" class="wiz__bucket-card ${selected}" data-bucket-idx="${idx}" style="--accent:${b.accent}">
                                        <i class="fa-solid ${b.icon}"></i>
                                        <div class="wiz__bucket-label">${esc(b.label)}</div>
                                        <div class="wiz__bucket-hint">${esc(b.hint)}</div>
                                    </button>
                                `;
                            }).join('')}
                        </div>
                    </div>

                    <div class="wiz__step">
                        <div class="wiz__step-title">2. Nazwa i kategoria</div>
                        <div class="form-row form-row--single">
                            <div>
                                <label class="label">Ludzka nazwa (display_name) <small class="muted">— co manager zobaczy na karcie</small></label>
                                <input id="wiz-display" class="input" placeholder="np. Pieczarki plasterki" value="${esc(displayGuess)}">
                            </div>
                        </div>
                        <div class="form-row">
                            <div>
                                <label class="label">Kategoria</label>
                                <select id="wiz-category" class="select">
                                    ${CATEGORIES.map(c => `<option value="${c.id}" ${guessHint?.category === c.id ? 'selected' : ''}>${esc(c.label)}</option>`).join('')}
                                </select>
                            </div>
                            <div>
                                <label class="label">sub_type (identyfikator)</label>
                                <input id="wiz-subtype" class="input" placeholder="np. salami_spicy, pepsi_330, marble_white" value="${esc(guessHint?.subType || '')}">
                            </div>
                        </div>
                        <div class="form-row">
                            <div>
                                <label class="label" title="Stan pieczenia — 'cooked' trafia na pizzę top-down, 'raw' do hero/karty menu">Stan pieczenia <i class="fa-regular fa-circle-question muted"></i></label>
                                <select id="wiz-cook" class="select">
                                    ${COOK_STATES.map(c => `<option value="${c.id}">${esc(c.label)}</option>`).join('')}
                                </select>
                            </div>
                            <div>
                                <label class="label">Tagi (po przecinku, opcjonalne)</label>
                                <input id="wiz-tags" class="input" placeholder="np. włoska, mięsiste, sezonowe">
                            </div>
                        </div>
                        <div class="form-row">
                            <div>
                                <label class="label">ascii_key (opcjonalny)</label>
                                <input id="wiz-ascii" class="input" placeholder="auto-generowany z display_name">
                            </div>
                            <div>
                                <label class="label">z-order (stack hint)</label>
                                <input id="wiz-zorder" type="number" class="input" placeholder="auto wg kategorii">
                            </div>
                        </div>
                    </div>

                    ${!isBatch ? `
                    <div class="wiz__step">
                        <div class="wiz__step-title">
                            3. Powiąż od razu (opcjonalne)
                            <small>— możesz przypisać później w panelu szczegółów</small>
                        </div>
                        <div id="wiz-link-area">
                            <label class="checkbox-row">
                                <input type="checkbox" id="wiz-link-enable">
                                <span>Tak, powiąż z encją teraz</span>
                            </label>
                            <div id="wiz-link-fields" class="wiz__link-fields hidden"></div>
                        </div>
                    </div>
                    ` : `
                    <div class="wiz__progress hidden" id="wiz-progress">
                        <div class="wiz__progress-bar"><div class="wiz__progress-fill" id="wiz-progress-fill"></div></div>
                        <div class="wiz__progress-label" id="wiz-progress-label">—</div>
                    </div>
                    `}
                </div>
            </div>
        `;

        const footer = document.createElement('div');
        footer.innerHTML = `
            <div style="flex:1"></div>
            <button type="button" class="btn" id="wiz-cancel">Anuluj</button>
            <button type="button" class="btn btn--accent" id="wiz-go">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                ${isBatch ? `Wgraj wszystkie (${batchFiles.length})` : 'Wgraj'}
            </button>
        `;

        const m = Studio.modal({ title: isBatch ? `Wgraj ${batchFiles.length} plików (batch)` : 'Wgraj nowy asset', body, footer, wide: true });

        let selectedBucketIdx = guessHint ? guessHint.bucketIdx : 0;
        const $bucketCards = body.querySelectorAll('[data-bucket-idx]');
        $bucketCards.forEach(btn => {
            btn.onclick = () => {
                selectedBucketIdx = parseInt(btn.dataset.bucketIdx, 10);
                $bucketCards.forEach(b => b.classList.toggle('selected', b === btn));
                const b = BUCKETS[selectedBucketIdx];
                if (b.roleHint === 'surface') body.querySelector('#wiz-category').value = 'surface';
                if (b.roleHint === 'logo')    body.querySelector('#wiz-category').value = 'brand';
                if (b.roleHint === 'hero')    body.querySelector('#wiz-category').value = 'hero';
            };
        });

        const $linkChk    = body.querySelector('#wiz-link-enable');
        const $linkFields = body.querySelector('#wiz-link-fields');
        if ($linkChk) {
            $linkChk.onchange = async () => {
                if ($linkChk.checked) {
                    $linkFields.classList.remove('hidden');
                    $linkFields.innerHTML = `<div class="muted"><i class="fa-solid fa-spinner fa-spin"></i> Ładuję listę encji...</div>`;
                    const entities = await loadEntities();
                    renderLinkFields($linkFields, entities, () => BUCKETS[selectedBucketIdx].roleHint);
                } else {
                    $linkFields.classList.add('hidden');
                    $linkFields.innerHTML = '';
                }
            };
        }

        footer.querySelector('#wiz-cancel').onclick = () => {
            URL.revokeObjectURL(previewUrl);
            m.close();
        };
        footer.querySelector('#wiz-go').onclick = async () => {
            const bucketDef = BUCKETS[selectedBucketIdx];
            const category  = body.querySelector('#wiz-category').value;
            const subType   = (body.querySelector('#wiz-subtype').value || '').trim();
            const cookState = body.querySelector('#wiz-cook').value || 'either';
            const ascii     = (body.querySelector('#wiz-ascii').value || '').trim();
            const zOrder    = body.querySelector('#wiz-zorder').value;
            const tagsInput = (body.querySelector('#wiz-tags').value || '').trim();
            const dispInput = (body.querySelector('#wiz-display').value || '').trim();

            if (bucketDef.id === 'library' && !subType) {
                Studio.toast('Dla warstw / ikonek wymagane sub_type (a-z0-9_).', 'warn');
                return;
            }

            const makeFd = (f, overrideDisplay) => {
                const fd = new FormData();
                fd.append('file', f);
                fd.append('bucket', bucketDef.id);
                fd.append('role_hint', bucketDef.roleHint);
                fd.append('category', category);
                if (subType) fd.append('sub_type', subType);
                fd.append('cook_state', cookState);
                if (ascii && !isBatch) fd.append('ascii_key', ascii);
                if (zOrder !== '') fd.append('z_order_hint', zOrder);
                const dn = overrideDisplay != null ? overrideDisplay : dispInput;
                if (dn) fd.append('display_name', dn);
                if (tagsInput) fd.append('tags', tagsInput);
                return fd;
            };

            footer.querySelector('#wiz-go').disabled = true;

            if (isBatch) {
                // Batch mode — kolejka z progressem
                const $prog = body.querySelector('#wiz-progress');
                const $fill = body.querySelector('#wiz-progress-fill');
                const $lbl  = body.querySelector('#wiz-progress-label');
                $prog.classList.remove('hidden');
                let done = 0, dedup = 0, fail = 0, lastId = null;
                for (const f of batchFiles) {
                    $lbl.textContent = `${done + 1} / ${batchFiles.length} — ${f.name}`;
                    const perFileDisplay = prettyNameFromFilename(f.name);
                    const up = await Api.assetsUpload(makeFd(f, perFileDisplay));
                    if (up.success) {
                        if (up.data.deduplicated) dedup++;
                        if (up.data.id) lastId = up.data.id;
                    } else {
                        fail++;
                    }
                    done++;
                    $fill.style.width = Math.round(done / batchFiles.length * 100) + '%';
                }
                Studio.toast(`Batch: ${done - fail}/${done} OK, duble ${dedup}, błędy ${fail}.`, fail ? 'warn' : 'ok', 5000);
                URL.revokeObjectURL(previewUrl);
                m.close();
                if (lastId) state.selectedId = lastId;
                await reload();
                loadHealth();
                return;
            }

            // Single mode
            const up = await Api.assetsUpload(makeFd(file));
            if (!up.success) {
                Studio.toast(up.message || 'Błąd uploadu.', 'err', 5000);
                footer.querySelector('#wiz-go').disabled = false;
                return;
            }
            const newAssetId = up.data.id;
            if (up.data.deduplicated) {
                Studio.toast(`Plik już istnieje (${up.data.asciiKey}) — zwrócono istniejący.`, 'warn', 4500);
            } else {
                Studio.toast(`Wgrano: ${up.data.asciiKey}`, 'ok');
            }

            if ($linkChk?.checked) {
                const linkPayload = collectLinkPayload($linkFields, newAssetId);
                if (linkPayload) {
                    const lr = await Api.assetsLink(linkPayload);
                    if (lr.success) Studio.toast('Powiązano z encją.', 'ok');
                    else Studio.toast('Upload OK, ale link się nie udał: ' + (lr.message || '???'), 'warn', 5000);
                }
            }

            URL.revokeObjectURL(previewUrl);
            m.close();
            state.selectedId = newAssetId;
            await reload();
            loadHealth();
        };
    }

    // -------------------------------------------------------------------------
    // LINK WIZARD (standalone, z details)
    // -------------------------------------------------------------------------
    function openLinkWizard(asset) {
        const body = document.createElement('div');
        body.innerHTML = `
            <p class="muted" style="font-size:12px;line-height:1.55;margin:0 0 14px">
                Asset <code>${esc(asset.displayName || asset.asciiKey)}</code> zostanie powiązany z wybraną encją w określonej roli.
            </p>
            <div id="link-fields" class="wiz__link-fields"></div>
        `;
        const footer = document.createElement('div');
        footer.innerHTML = `
            <div style="flex:1"></div>
            <button type="button" class="btn" id="link-cancel">Anuluj</button>
            <button type="button" class="btn btn--accent" id="link-go"><i class="fa-solid fa-link"></i> Powiąż</button>
        `;
        const m = Studio.modal({ title: 'Powiąż asset z encją', body, footer });

        const $fields = body.querySelector('#link-fields');
        $fields.innerHTML = `<div class="muted"><i class="fa-solid fa-spinner fa-spin"></i> Ładuję listę encji...</div>`;

        loadEntities().then(entities => {
            renderLinkFields($fields, entities, () => asset.roleHint || 'layer');
        });

        footer.querySelector('#link-cancel').onclick = m.close;
        footer.querySelector('#link-go').onclick = async () => {
            const payload = collectLinkPayload($fields, asset.id);
            if (!payload) { Studio.toast('Uzupełnij encję i rolę.', 'warn'); return; }
            footer.querySelector('#link-go').disabled = true;
            const r = await Api.assetsLink(payload);
            if (r.success) {
                Studio.toast('Powiązano.', 'ok');
                m.close();
                await reload(false);
                renderDetails();
            } else {
                Studio.toast(r.message || 'Błąd.', 'err', 5000);
                footer.querySelector('#link-go').disabled = false;
            }
        };
    }

    function renderLinkFields(container, entities, roleHintFn) {
        container.innerHTML = `
            <div class="form-row">
                <div>
                    <label class="label">Typ encji</label>
                    <select id="lnk-etype" class="select">
                        <option value="menu_item">🍕 Danie (menu)</option>
                        <option value="modifier">🧂 Modyfikator</option>
                        <option value="board_companion">🥤 Companion (napój / deska)</option>
                        <option value="surface_library">🧱 Surface (tekstura stołu)</option>
                        <option value="tenant_brand">🏷 Brand (logo tenanta)</option>
                    </select>
                </div>
                <div>
                    <label class="label">Rola</label>
                    <select id="lnk-role" class="select"></select>
                </div>
            </div>
            <div class="form-row form-row--single">
                <div>
                    <label class="label">Wybierz encję</label>
                    <div id="lnk-entity-area"></div>
                </div>
            </div>
        `;

        const $etype = container.querySelector('#lnk-etype');
        const $role  = container.querySelector('#lnk-role');
        const $area  = container.querySelector('#lnk-entity-area');

        function refreshRoles() {
            const roles = ENTITY_ROLE_MAP[$etype.value] || [{ id: 'thumbnail', label: 'Miniatura' }];
            const preferRole = roleHintFn();
            const match = roles.find(r => r.id === preferRole) || roles.find(r => r.id.includes(preferRole)) || roles[0];
            $role.innerHTML = roles.map(r => `<option value="${r.id}" ${r === match ? 'selected' : ''}>${esc(r.label)}</option>`).join('');
        }
        function refreshEntityArea() {
            const t = $etype.value;
            if (t === 'menu_item') {
                $area.innerHTML = `<select id="lnk-entity" class="select"><option value="">— wybierz danie —</option></select>`;
                const sel = $area.querySelector('#lnk-entity');
                (entities.menuItems || []).forEach(it => {
                    const o = document.createElement('option');
                    o.value = it.sku;
                    o.textContent = `${it.name} (${it.sku})${it.isActive ? '' : ' · nieaktywne'}`;
                    sel.appendChild(o);
                });
            } else if (t === 'modifier') {
                $area.innerHTML = `<select id="lnk-entity" class="select"><option value="">— wybierz modyfikator —</option></select>`;
                const sel = $area.querySelector('#lnk-entity');
                (entities.modifiers || []).forEach(it => {
                    const o = document.createElement('option');
                    o.value = it.sku;
                    o.textContent = `${it.groupName} / ${it.name} (${it.sku})`;
                    sel.appendChild(o);
                });
            } else if (t === 'board_companion') {
                $area.innerHTML = `<select id="lnk-entity" class="select"><option value="">— wybierz companion —</option></select>`;
                const sel = $area.querySelector('#lnk-entity');
                (entities.boardCompanions || []).forEach(it => {
                    const o = document.createElement('option');
                    o.value = it.ref;
                    o.textContent = it.label;
                    sel.appendChild(o);
                });
                if ((entities.boardCompanions || []).length === 0) sel.innerHTML = `<option value="">— brak companions —</option>`;
            } else if (t === 'surface_library') {
                $area.innerHTML = `<input id="lnk-entity" class="input" placeholder="surface::wood::oak_dark"><small class="muted">Format: <code>surface::category::sub_type</code></small>`;
            } else if (t === 'tenant_brand') {
                $area.innerHTML = `<input id="lnk-entity" class="input" placeholder="numer tenant_id"><small class="muted">Zwykle Twój tenant_id.</small>`;
            }
        }

        $etype.onchange = () => { refreshRoles(); refreshEntityArea(); };
        refreshRoles();
        refreshEntityArea();
    }

    function collectLinkPayload(container, assetId) {
        const etype = container.querySelector('#lnk-etype')?.value;
        const role  = container.querySelector('#lnk-role')?.value;
        const ref   = container.querySelector('#lnk-entity')?.value?.trim();
        if (!etype || !role || !ref) return null;
        return { asset_id: assetId, entity_type: etype, entity_ref: ref, role, sort_order: 0 };
    }

    async function loadEntities() {
        if (state.entitiesCache) return state.entitiesCache;
        const r = await Api.assetsListEntities();
        if (!r.success) { Studio.toast(r.message || 'Błąd ładowania encji.', 'err'); return { menuItems: [], modifiers: [], boardCompanions: [] }; }
        state.entitiesCache = r.data;
        return r.data;
    }

    // -------------------------------------------------------------------------
    // EDITOR
    // -------------------------------------------------------------------------
    function openEditor(a) {
        const body = document.createElement('div');
        body.innerHTML = `
            <div class="form-row form-row--single">
                <div>
                    <label class="label">Ludzka nazwa (display_name)</label>
                    <input id="ed-dn" class="input" value="${esc(a.displayName || '')}" placeholder="np. Pieczarki plasterki">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label class="label">ascii_key (unikalny per tenant)</label>
                    <input id="ed-ak" class="input" value="${esc(a.asciiKey)}">
                </div>
                <div>
                    <label class="label">&nbsp;</label>
                    <label class="checkbox-row">
                        <input type="checkbox" id="ed-regen">
                        <span>Regeneruj ascii_key z display_name</span>
                    </label>
                </div>
            </div>
            <div class="form-row form-row--single">
                <div>
                    <label class="label">Tagi (po przecinku)</label>
                    <input id="ed-tags" class="input" value="${esc((a.tags || []).join(', '))}" placeholder="np. włoska, mięsiste">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label class="label">Bucket</label>
                    <select id="ed-buc" class="select">
                        ${['library','hero','surface','companion','brand','variant','legacy'].map(b => `<option value="${b}" ${b === a.bucket ? 'selected' : ''}>${b}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label class="label">Role hint</label>
                    <select id="ed-rh" class="select">
                        ${ROLE_HINTS.map(r => `<option value="${r}" ${r === a.roleHint ? 'selected' : ''}>${r}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label class="label">Kategoria</label>
                    <select id="ed-cat" class="select">
                        ${CATEGORIES.map(c => `<option value="${c.id}" ${c.id === a.category ? 'selected' : ''}>${esc(c.label)}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label class="label">sub_type</label>
                    <input id="ed-sub" class="input" value="${esc(a.subType || '')}">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label class="label" title="Stan pieczenia: cooked → scena pizzy top-down; raw → hero/karta menu">Stan pieczenia <i class="fa-regular fa-circle-question muted"></i></label>
                    <select id="ed-cook" class="select">
                        ${COOK_STATES.map(c => `<option value="${c.id}" ${c.id === (a.cookState || 'either') ? 'selected' : ''}>${esc(c.label)}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label class="label">z_order_hint</label>
                    <input id="ed-z" type="number" class="input" value="${a.zOrderHint || 50}">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label class="label">Status</label>
                    <select id="ed-act" class="select">
                        <option value="1" ${a.isActive ? 'selected' : ''}>Aktywny</option>
                        <option value="0" ${!a.isActive ? 'selected' : ''}>Nieaktywny</option>
                    </select>
                </div>
                <div></div>
            </div>
        `;
        const footer = document.createElement('div');
        footer.innerHTML = `
            <div style="flex:1"></div>
            <button class="btn" id="ed-cancel">Anuluj</button>
            <button class="btn btn--accent" id="ed-save"><i class="fa-solid fa-check"></i> Zapisz</button>
        `;
        const m = Studio.modal({ title: 'Edytuj asset · ' + (a.displayName || a.asciiKey), body, footer });

        footer.querySelector('#ed-cancel').onclick = m.close;
        footer.querySelector('#ed-save').onclick = async () => {
            const tagsArr = (body.querySelector('#ed-tags').value || '').split(',').map(s => s.trim()).filter(Boolean);
            const r = await Api.assetsUpdate({
                id: a.id,
                ascii_key: body.querySelector('#ed-ak').value.trim(),
                display_name: body.querySelector('#ed-dn').value.trim(),
                tags: tagsArr,
                regenerate_ascii_key: body.querySelector('#ed-regen').checked,
                storage_bucket: body.querySelector('#ed-buc').value,
                role_hint: body.querySelector('#ed-rh').value,
                category: body.querySelector('#ed-cat').value,
                sub_type: body.querySelector('#ed-sub').value.trim(),
                cook_state: body.querySelector('#ed-cook').value || 'either',
                z_order_hint: parseInt(body.querySelector('#ed-z').value, 10) || 50,
                is_active: body.querySelector('#ed-act').value === '1',
            });
            if (r.success) {
                Studio.toast('Zapisano.', 'ok');
                m.close();
                await reload();
            } else {
                Studio.toast(r.message || 'Błąd.', 'err', 5000);
            }
        };
    }

    // -------------------------------------------------------------------------
    // MERGE DUPLICATES
    // -------------------------------------------------------------------------
    async function openMergeDuplicates() {
        await loadHealth();
        const groups = state.health?.duplicateGroups || [];
        if (!groups.length) { Studio.toast('Brak dubli do scalenia.', 'ok'); return; }

        // Zbuduj mapkę id → item (żeby wiedzieć display_name etc.)
        const byId = new Map(state.items.map(it => [it.id, it]));

        const body = document.createElement('div');
        body.innerHTML = `
            <p class="muted">Znaleziono <strong>${groups.length}</strong> grup dubli (identyczny plik po SHA-256). Dla każdej grupy wybierz "keepera" — jego zachowamy, resztę oznaczymy jako usunięte, a wszystkie linki przeniesiemy na keepera.</p>
            <div class="as-merge-list" id="merge-groups"></div>
        `;
        const footer = document.createElement('div');
        footer.innerHTML = `
            <div style="flex:1"></div>
            <button class="btn" id="mg-cancel">Zamknij</button>
            <button class="btn btn--accent" id="mg-go"><i class="fa-solid fa-code-merge"></i> Scal wszystkie zaznaczone grupy</button>
        `;
        const m = Studio.modal({ title: `Scal duble (${groups.length} grup)`, body, footer, wide: true });

        const $list = body.querySelector('#merge-groups');
        $list.innerHTML = groups.map((g, gi) => {
            const ids = g.ids;
            // Default keeper = asset z największą liczbą linków, fallback najstarszy
            let keeperId = ids[0];
            let maxLinks = -1;
            for (const id of ids) {
                const it = byId.get(id);
                if (it && (it.linkCount || 0) > maxLinks) { maxLinks = it.linkCount || 0; keeperId = id; }
            }
            return `
                <div class="as-merge-group" data-group="${gi}" data-checksum="${esc(g.checksum)}">
                    <header class="as-merge-group__head">
                        <label class="checkbox-row">
                            <input type="checkbox" data-merge-enable="${gi}" checked>
                            <span>Grupa ${gi + 1} · ${g.count} plików</span>
                        </label>
                        <span class="muted" style="font-size:11px">SHA: ${esc(g.checksum.slice(0, 12))}…</span>
                    </header>
                    <div class="as-merge-group__items">
                        ${ids.map(id => {
                            const it = byId.get(id);
                            if (!it) return `<div class="as-merge-item as-merge-item--missing">#${id} (nieznany)</div>`;
                            const title = it.displayName || it.asciiKey;
                            return `
                                <label class="as-merge-item ${keeperId === id ? 'is-keeper' : ''}">
                                    <input type="radio" name="keeper-${gi}" value="${id}" ${keeperId === id ? 'checked' : ''}>
                                    <img src="${esc(it.publicUrl)}" alt="" loading="lazy">
                                    <div class="as-merge-item__body">
                                        <strong>${esc(title)}</strong>
                                        <small class="muted">${esc(it.asciiKey)} · ${it.linkCount || 0} linków</small>
                                    </div>
                                </label>
                            `;
                        }).join('')}
                    </div>
                </div>
            `;
        }).join('');

        footer.querySelector('#mg-cancel').onclick = m.close;
        footer.querySelector('#mg-go').onclick = async () => {
            const enabled = $list.querySelectorAll('[data-merge-enable]:checked');
            if (!enabled.length) { Studio.toast('Zaznacz co najmniej jedną grupę.', 'warn'); return; }
            if (!confirm(`Scal ${enabled.length} grup dubli? Operacji nie da się cofnąć jednym kliknięciem.`)) return;
            footer.querySelector('#mg-go').disabled = true;
            let ok = 0, merged = 0;
            for (const chk of enabled) {
                const gi = parseInt(chk.dataset.mergeEnable, 10);
                const g = groups[gi];
                const keeperId = parseInt($list.querySelector(`input[name="keeper-${gi}"]:checked`)?.value || g.ids[0], 10);
                const mergeIds = g.ids.filter(x => x !== keeperId);
                if (!mergeIds.length) continue;
                const r = await Api.assetsMergeDuplicates(keeperId, mergeIds);
                if (r.success) { ok++; merged += mergeIds.length; }
            }
            Studio.toast(`Scalono ${ok} grup (${merged} dubli).`, 'ok');
            m.close();
            clearSelection();
            await reload();
            loadHealth();
        };
    }

    // -------------------------------------------------------------------------
    // DATA
    // -------------------------------------------------------------------------
    async function reload(resetSelection = true) {
        const params = {
            per_page: 500,
            include_globals: true,
        };
        if (state.bucket) params.bucket = state.bucket;
        if (state.category) params.category = state.category;
        if (state.cookState) params.cook_state = state.cookState;
        if (state.search) params.search = state.search;

        // Health filter maps to specific backend flags
        switch (state.healthFilter) {
            case 'orphans':          params.orphans_only = true; break;
            case 'duplicates':       params.duplicates_only = true; break;
            case 'missing_category': params.missing_category = true; break;
            case 'missing_cook':     params.missing_cook_state = true; break;
            case 'large':            params.large_files = true; break;
            case 'missing_name':     params.missing_display_name = true; break; // backend filter n/a — handled client-side below
        }

        const r = await Api.assetsList(params);
        if (!r.success) { Studio.toast(r.message || 'Błąd.', 'err'); return; }
        let items = r.data.items || [];
        // Dodatkowe filtry client-side (których nie ma w backendzie)
        if (state.healthFilter === 'missing_name') {
            items = items.filter(it => !it.displayName);
        }
        state.items = items;
        state.total = r.data.total || 0;
        if (resetSelection && state.selectedId && !state.items.find(x => x.id === state.selectedId)) {
            state.selectedId = null;
        }
        // Prune selectedIds do visible
        const visibleIds = new Set(state.items.map(x => x.id));
        Array.from(state.selectedIds).forEach(id => { if (!visibleIds.has(id)) state.selectedIds.delete(id); });

        const $sidebarCount = document.getElementById('nav-library-count');
        if ($sidebarCount) $sidebarCount.textContent = state.total;
        renderFilters();
        renderBody();
        renderBulkBar();
        if (state.selectedId) renderDetails();
    }

    // -------------------------------------------------------------------------
    // DRAG & DROP
    // -------------------------------------------------------------------------
    ['dragenter','dragover'].forEach(evt => $dropzone.addEventListener(evt, e => {
        e.preventDefault();
        $dropzone.classList.add('as-dropzone--hot');
    }));
    ['dragleave','drop'].forEach(evt => $dropzone.addEventListener(evt, e => {
        e.preventDefault();
        $dropzone.classList.remove('as-dropzone--hot');
    }));
    $dropzone.addEventListener('drop', e => handleFiles(e.dataTransfer?.files));
    $dropzone.addEventListener('click', () => $file.click());
    $dropzone.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $file.click(); }
    });
    $file.addEventListener('change', () => {
        handleFiles($file.files);
        $file.value = '';
    });

    // -------------------------------------------------------------------------
    // TOP-LEVEL BINDS
    // -------------------------------------------------------------------------
    root.querySelector('#as-refresh').onclick = () => { reload(); loadHealth(); };
    root.querySelector('#as-health-refresh').onclick = loadHealth;
    $search.oninput = () => { state.search = $search.value; renderBody(); };

    // View toggle
    root.querySelectorAll('.as-view-btn').forEach(btn => {
        btn.onclick = () => {
            root.querySelectorAll('.as-view-btn').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            state.view = btn.dataset.view;
            renderBody();
        };
    });

    // Keyboard shortcuts
    function onKey(e) {
        // Nie łap gdy user pisze w input
        const tag = (e.target?.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
        if (e.key === 'Escape') { clearSelection(); closePopover(); }
        else if (e.key === 'Delete' && state.selectedIds.size > 0) {
            e.preventDefault();
            root.querySelector('#as-bulk-delete').click();
        }
        else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
            // Ctrl+A — zaznacz wszystkie widoczne
            if (document.activeElement && root.contains(document.activeElement)) {
                e.preventDefault();
                visibleItems().forEach(it => state.selectedIds.add(it.id));
                renderBody();
                renderBulkBar();
            }
        }
    }
    document.addEventListener('keydown', onKey);

    // Initial load
    renderFilters();
    reload();
    loadHealth();

    return {
        onEnter: () => { reload(false); loadHealth(); },
        onLeave: () => { document.removeEventListener('keydown', onKey); closePopover(); },
    };
}

// -----------------------------------------------------------------------------
// HELPERS
// -----------------------------------------------------------------------------

/**
 * Heurystyka — zgadnij bucket/kategorię/sub_type z nazwy pliku.
 */
function guessFromFilename(name) {
    const base = (name || '').toLowerCase().replace(/\.[a-z]+$/, '');
    const patterns = [
        { re: /(surface|blat|deska|marmur|wood|oak|marble|granite)/, bucketIdx: 4, category: 'surface' },
        { re: /(hero|photo|foto|shot)/, bucketIdx: 1, category: 'hero' },
        { re: /(pepsi|cola|coca|fanta|sprite|mirinda|woda|napoj|beer|piwo|beverage|drink)/, bucketIdx: 3, category: 'drink' },
        { re: /(logo|brand|favicon|og_)/, bucketIdx: 5, category: 'brand' },
        { re: /(sauce|sos|czosnk|garlic|salsa|ketchup|mayo|dressing)/, bucketIdx: 2, category: 'sauce' },
        { re: /(pepperoni|salami|ham|bacon|chicken|beef|pork|meat|szynka|boczek|kurczak|prosciutto|chorizo|kabanos)/, bucketIdx: 0, category: 'meat' },
        { re: /(cheese|ser|mozz|parmesan|cheddar|gouda|feta|gorgonzola)/, bucketIdx: 0, category: 'cheese' },
        { re: /(tomato|pomidor|pieczark|mushroom|olive|oliwk|onion|cebul|pepper|papryk|kukurydz|corn|ananas|pineapple|veg)/, bucketIdx: 0, category: 'veg' },
        { re: /(basil|bazyl|oregano|herb|zielen|parsley|rukol|szpinak)/, bucketIdx: 0, category: 'herb' },
        { re: /(dough|ciasto|base|crust)/, bucketIdx: 0, category: 'base' },
    ];
    for (const p of patterns) {
        if (p.re.test(base)) {
            return {
                bucketIdx: p.bucketIdx,
                category:  p.category,
                subType:   base.replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').slice(0, 48),
            };
        }
    }
    return null;
}

/**
 * Z nazwy pliku wyciągnij "ludzką" nazwę — "pieczarki_plasterki.webp" → "Pieczarki plasterki"
 */
function prettyNameFromFilename(name) {
    let s = (name || '').replace(/\.[a-z]+$/i, '');
    s = s.replace(/[_\-]+/g, ' ').trim();
    s = s.replace(/\s+/g, ' ');
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}
