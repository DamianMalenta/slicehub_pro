/**
 * AssetPicker — modal for selecting an asset (unified asset library) or a product (menu_items).
 *
 * Used by Director to:
 *   - add a layer (mode: 'layer')   → returns { type: 'asset', asset: {...} }
 *   - add a companion (mode: 'companion') → returns { type: 'product', item: {...} }
 *   - replace an existing layer asset (mode: 'replace') → same as 'layer'
 *
 * Backed by Studio.assets.items (SSOT: api/assets/engine.php) + Studio.menu.items.
 * Repipe 2026-04-19 · Faza B (SSOT biblioteki): Studio.library → Studio.assets.
 */
export class AssetPicker {
    /**
     * @param {object} Studio - the global Studio instance (for library/menu access)
     */
    constructor(Studio) {
        this._Studio = Studio;
    }

    /**
     * @param {'layer'|'companion'|'replace'} mode
     * @param {object} [options] - { excludeSkus?: string[], categoryFilter?: RegExp }
     * @returns {Promise<object|null>}
     */
    open(mode, options = {}) {
        return new Promise(resolve => {
            const titleByMode = {
                layer:     'Wybierz warstwę z biblioteki',
                replace:   'Zamień asset warstwy',
                companion: 'Dodaj companiona',
            };

            const wrap = document.createElement('div');
            wrap.className = 'dt-asset-picker';

            const search = document.createElement('div');
            search.className = 'dt-ap-search';
            search.innerHTML = `
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="Szukaj po nazwie, sub_type, kategorii..." class="dt-ap-input" autofocus>
                <span class="dt-ap-count" id="dt-ap-count">0</span>
            `;
            wrap.appendChild(search);

            const filters = document.createElement('div');
            filters.className = 'dt-ap-filters';
            wrap.appendChild(filters);

            const grid = document.createElement('div');
            grid.className = 'dt-ap-grid';
            wrap.appendChild(grid);

            let resolved = false;
            const done = (result) => {
                if (resolved) return;
                resolved = true;
                resolve(result);
            };
            const close = (result) => { done(result); modal.close(); };

            const data = (mode === 'companion')
                ? this._loadProducts(options)
                : this._loadAssets(options);

            const allCategories = this._uniqueCategories(data);

            let activeCategory = '';
            let activeSearch = '';

            const renderFilters = () => {
                filters.innerHTML = '';
                const allBtn = document.createElement('button');
                allBtn.className = `dt-ap-filter ${activeCategory === '' ? 'is-active' : ''}`;
                allBtn.textContent = `Wszystkie (${data.length})`;
                allBtn.onclick = () => { activeCategory = ''; renderFilters(); render(); };
                filters.appendChild(allBtn);
                allCategories.forEach(cat => {
                    const count = data.filter(x => x.category === cat).length;
                    if (count === 0) return;
                    const b = document.createElement('button');
                    b.className = `dt-ap-filter ${activeCategory === cat ? 'is-active' : ''}`;
                    b.textContent = `${cat} (${count})`;
                    b.onclick = () => { activeCategory = cat; renderFilters(); render(); };
                    filters.appendChild(b);
                });
            };

            const render = () => {
                const f = activeSearch.toLowerCase().trim();
                const filtered = data.filter(it => {
                    if (activeCategory && it.category !== activeCategory) return false;
                    if (!f) return true;
                    return [it.label, it.subLabel, it.sku, it.category]
                        .filter(Boolean)
                        .some(s => s.toLowerCase().includes(f));
                });
                grid.innerHTML = '';
                wrap.querySelector('#dt-ap-count').textContent = filtered.length;

                if (filtered.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'dt-ap-empty';
                    empty.innerHTML = '<i class="fa-solid fa-box-open"></i><div>Brak wyników</div><small>Spróbuj inny filtr lub upload assetu</small>';
                    grid.appendChild(empty);
                    return;
                }

                filtered.forEach(it => {
                    const card = document.createElement('button');
                    card.className = 'dt-ap-card';
                    const thumb = it.url ? `style="background-image:url('${String(it.url).replace(/'/g, "\\'")}')"` : '';
                    const initial = (it.label || '?').charAt(0).toUpperCase();
                    card.innerHTML = `
                        <span class="dt-ap-thumb" ${thumb}>${it.url ? '' : initial}</span>
                        <span class="dt-ap-name" title="${it.label}">${it.label}</span>
                        ${it.subLabel ? `<small class="dt-ap-sub">${it.subLabel}</small>` : ''}
                    `;
                    card.onclick = () => close({ ...it, _mode: mode });
                    grid.appendChild(card);
                });
            };

            renderFilters();
            render();

            const inp = wrap.querySelector('.dt-ap-input');
            inp.addEventListener('input', () => { activeSearch = inp.value; render(); });

            const footer = document.createElement('div');
            footer.className = 'dt-ap-foot';
            const cancel = document.createElement('button');
            cancel.className = 'dt-btn';
            cancel.textContent = 'Anuluj';
            cancel.onclick = () => close(null);
            footer.appendChild(cancel);

            const modal = this._Studio.modal({
                title: titleByMode[mode] || 'Picker',
                body: wrap,
                footer,
                wide: true,
                onClose: () => done(null),
            });

            setTimeout(() => inp.focus(), 50);
        });
    }

    _loadAssets(options) {
        const items = this._Studio?.assets?.items || [];
        const exclude = new Set(options.excludeSkus || []);
        return items
            .filter(it => !exclude.has(it.asciiKey))
            .map(it => ({
                kind: 'asset',
                sku: it.asciiKey,
                category: it.category || 'Inne',
                subLabel: it.displayName && it.displayName !== it.asciiKey
                    ? it.displayName
                    : (it.subType || it.bucket || ''),
                // displayName jest czytelniejszy dla operatora (po polsku, z M5).
                // Jeśli brak → fallback do ascii_key (dla starych wpisów).
                label: it.displayName || it.asciiKey,
                url: it.publicUrl || it.storageUrl || '',
                width: it.width,
                height: it.height,
                hasAlpha: it.hasAlpha,
                zOrder: it.zOrderHint,
            }));
    }

    _loadProducts(options) {
        const items = this._Studio?.menu?.items || [];
        const exclude = new Set(options.excludeSkus || []);
        let filtered = items.filter(it => !exclude.has(it.sku));
        if (options.categoryFilter) {
            filtered = filtered.filter(it => options.categoryFilter.test(it.categoryName || ''));
        }
        return filtered.map(it => ({
            kind: 'product',
            sku: it.sku,
            category: it.categoryName || 'Inne',
            subLabel: it.price ? Number(it.price).toFixed(2).replace('.', ',') + ' zł' : '',
            label: it.name,
            url: it.imageUrl || '',
        }));
    }

    _uniqueCategories(data) {
        const set = new Set();
        data.forEach(d => { if (d.category) set.add(d.category); });
        return [...set].sort();
    }
}
