const _e = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

globalThis.Core = {
    _treeSearchQuery: '',
    _treeFilter: 'all',

    switchView: function(viewId) {
        console.log("[UI] Przełączanie na widok:", viewId);
        globalThis.StudioState.currentView = viewId;
        const container = document.getElementById('view-container');
        if (!container) return;
        Array.from(container.children).forEach(child => {
            if(child.id.includes('-view')) child.classList.add('hidden');
        });
        const targetView = document.getElementById(viewId + '-view');
        const bulkView = document.getElementById('bulk-inspector-view');
        if (viewId === 'bulk' && bulkView) bulkView.classList.remove('hidden');
        else if (targetView) targetView.classList.remove('hidden');
        document.querySelectorAll('.nav-tab').forEach(btn => btn.classList.remove('active'));
        const navBtn = document.getElementById('nav-' + viewId);
        if (navBtn) navBtn.classList.add('active');

        if (viewId === 'modifiers') {
            // Faza 3: Modifiers are now in a drawer, not a view.
            // If somehow called, open drawer instead.
            if (globalThis.ModifierInspector) globalThis.ModifierInspector.openDrawer();
        }
        if (viewId === 'meals' && globalThis.MealEditor) {
            globalThis.MealEditor.init();
            if (!document.getElementById('meal-id')?.value || document.getElementById('meal-id').value === '0') {
                globalThis.MealEditor.newMeal();
            }
        }

        // Faza 4: update inspector context when view changes
        if (viewId !== 'modifiers') {
            this.updateInspector();
        }
    },
    filterTree: function(query) {
        this._treeSearchQuery = (query || '').toLowerCase().trim();
        this.renderTree();
    },

    setTreeFilter: function(filter, btn) {
        this._treeFilter = filter;
        document.querySelectorAll('.filter-chip').forEach(c => {
            c.className = 'filter-chip flex-1 text-[9px] font-bold uppercase py-1 rounded transition bg-black/40 text-slate-500 border border-white/5 hover:text-white';
        });
        if (btn) btn.className = 'filter-chip active flex-1 text-[9px] font-bold uppercase py-1 rounded transition bg-blue-600/30 text-blue-300 border border-blue-500/30';
        this.renderTree();
    },

    updateDashboard: function() {
        const items = globalThis.StudioState?.items || [];
        const dash = document.getElementById('navigator-dashboard');
        if (!dash) return;
        const total = items.length;
        // Faza 1 (2026-08-24): liczniki wyłącznie po publicationStatus (kanoniczny status).
        const active = items.filter(i => i.publicationStatus === 'Live').length;
        const draft = items.filter(i => i.publicationStatus === 'Draft').length;
        dash.classList.toggle('hidden', total === 0);
        const el = id => document.getElementById(id);
        if (el('dash-total-items')) el('dash-total-items').textContent = total;
        if (el('dash-active-items')) el('dash-active-items').textContent = active;
        if (el('dash-draft-items')) el('dash-draft-items').textContent = draft;
    },

    renderDashboard: function() {
        const container = document.getElementById('inspector-dashboard-content');
        if (!container) return;
        const items = globalThis.StudioState?.items || [];
        const total = items.length;
        // Faza 1 (2026-08-24): liczniki wyłącznie po publicationStatus (kanoniczny status).
        const live = items.filter(i => i.publicationStatus === 'Live').length;
        const draft = items.filter(i => i.publicationStatus === 'Draft').length;
        const archived = items.filter(i => i.publicationStatus === 'Archived').length;

        const recent = this._getRecentItems();
        const alerts = this._getMarginAlerts();

        container.innerHTML = `
            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-2">
                    <div class="bg-black/40 border border-white/5 rounded-xl p-3 text-center">
                        <div class="text-[8px] font-black uppercase text-slate-500 tracking-widest">Wszystko</div>
                        <div class="text-2xl font-black text-white mt-1">${total}</div>
                    </div>
                    <div class="bg-emerald-900/20 border border-emerald-500/20 rounded-xl p-3 text-center">
                        <div class="text-[8px] font-black uppercase text-emerald-400 tracking-widest">Live</div>
                        <div class="text-2xl font-black text-emerald-300 mt-1">${live}</div>
                    </div>
                    <div class="bg-amber-900/20 border border-amber-500/20 rounded-xl p-3 text-center">
                        <div class="text-[8px] font-black uppercase text-amber-400 tracking-widest">Draft</div>
                        <div class="text-2xl font-black text-amber-300 mt-1">${draft}</div>
                    </div>
                </div>

                ${archived > 0 ? `<div class="text-[8px] text-slate-600 font-bold uppercase tracking-wider text-center">${archived} zarchiwizowanych</div>` : ''}

                ${alerts.length > 0 ? `
                <div class="bg-red-900/20 border border-red-500/20 rounded-xl p-3">
                    <div class="text-[9px] font-black uppercase text-red-400 tracking-widest mb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-triangle-exclamation"></i> Alerty Marży (${alerts.length})
                    </div>
                    <div class="space-y-1.5 max-h-40 overflow-y-auto hide-scrollbar">
                        ${alerts.map(a => `
                            <div class="flex items-center justify-between gap-2 text-[10px] cursor-pointer hover:bg-red-500/10 rounded p-1.5 transition" onclick="globalThis.Core.openItemEditor(${a.id}, '${_e(a.asciiKey)}')">
                                <span class="text-red-300 font-bold truncate flex-1">${_e(a.name)}</span>
                                <span class="text-red-400 font-mono shrink-0">${a.fcPct.toFixed(0)}%</span>
                            </div>
                        `).join('')}
                    </div>
                </div>` : ''}

                <div>
                    <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-clock-rotate-left"></i> Ostatnie edycje
                    </div>
                    ${recent.length > 0 ? `
                        <div class="space-y-1.5">
                            ${recent.map(r => `
                                <div class="flex items-center gap-2 bg-black/30 border border-white/5 rounded-lg p-2 cursor-pointer hover:bg-blue-500/10 transition" onclick="globalThis.Core.openItemEditor(${r.id}, '${_e(r.asciiKey)}')">
                                    <div class="w-8 h-8 rounded-lg bg-black/60 border border-white/10 overflow-hidden flex items-center justify-center shrink-0">
                                        ${r.imageUrl ? `<img src="${_e(r.imageUrl)}" class="w-full h-full object-cover" loading="lazy" onerror="this.remove();">` : `<i class="fa-solid fa-utensils text-slate-700 text-[10px]"></i>`}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[11px] font-bold text-slate-300 truncate">${_e(r.name)}</div>
                                        <div class="text-[8px] text-slate-600 font-mono">${_e(r.asciiKey)} · ${r.timeAgo}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : `<div class="text-[10px] text-slate-600 italic text-center py-4">Brak ostatnich edycji</div>`}
                </div>

                <div class="border-t border-white/5 pt-3 space-y-2">
                    <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest mb-1">Szybkie akcje</div>
                    <button onclick="globalThis.Core.addCategory()" class="w-full bg-white/5 hover:bg-white/10 border border-white/10 text-slate-300 hover:text-white text-[10px] font-black uppercase tracking-wider py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-folder-plus"></i> Nowa Kategoria
                    </button>
                    <button onclick="globalThis.ItemEditor.openNewPizzaWizard()" class="w-full bg-orange-500/10 hover:bg-orange-500/20 border border-orange-500/30 text-orange-300 text-[10px] font-black uppercase tracking-wider py-2 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-pizza-slice"></i> Kreator Pizzy
                    </button>
                </div>
            </div>
        `;
    },

    _getRecentItems: function() {
        try {
            const raw = localStorage.getItem('studio_recent_items');
            if (!raw) return [];
            const data = JSON.parse(raw);
            if (!Array.isArray(data)) return [];
            return data.slice(0, 8);
        } catch { return []; }
    },

    _addRecentItem: function(item) {
        let recent = this._getRecentItems();
        recent = recent.filter(r => r.id !== item.id);
        recent.unshift({
            id: item.id,
            name: item.name || '',
            asciiKey: item.asciiKey || '',
            imageUrl: item.imageUrl || '',
            timeAgo: 'teraz',
            ts: Date.now(),
        });
        recent = recent.slice(0, 10);
        try { localStorage.setItem('studio_recent_items', JSON.stringify(recent)); } catch {}
        this._updateRecentTimeAgo();
    },

    _updateRecentTimeAgo: function() {
        const recent = this._getRecentItems();
        const now = Date.now();
        recent.forEach(r => {
            if (r.ts) {
                const diff = Math.floor((now - r.ts) / 1000);
                if (diff < 60) r.timeAgo = 'teraz';
                else if (diff < 3600) r.timeAgo = Math.floor(diff / 60) + ' min temu';
                else if (diff < 86400) r.timeAgo = Math.floor(diff / 3600) + ' godz temu';
                else r.timeAgo = Math.floor(diff / 86400) + ' dni temu';
            }
        });
        try { localStorage.setItem('studio_recent_items', JSON.stringify(recent)); } catch {}
    },

    _getMarginAlerts: function() {
        const items = globalThis.StudioState?.items || [];
        const alerts = [];
        if (!globalThis.MarginGuardian?.initialized) return alerts;
        items.forEach(it => {
            const posTier = it.priceTiers?.find(t => t.channel === 'POS');
            const price = posTier?.price || it.price || 0;
            if (price <= 0) return;
            const recipe = globalThis.RecipeMapper?.state?.currentRecipe;
            if (!recipe || recipe.length === 0) return;
            const results = globalThis.MarginGuardian.calculate(
                [{ channel: 'POS', price, vatRate: it.vatRateDineIn || 8 }],
                recipe
            );
            const fc = results.channels?.[0]?.foodCostPercent;
            if (fc !== null && fc !== undefined && fc > 30) {
                alerts.push({ id: it.id, name: it.name, asciiKey: it.asciiKey, fcPct: fc });
            }
        });
        return alerts.slice(0, 10);
    },

    updateInspector: function() {
        const inspector = document.getElementById('studio-inspector');
        if (!inspector) return;
        const mode = globalThis.StudioState.routeToMode();
        const recipePanel = document.getElementById('inspector-recipe');
        const bulkPanel = document.getElementById('inspector-bulk');
        const dashPanel = document.getElementById('inspector-dashboard');
        const title = document.getElementById('inspector-title');
        const subtitle = document.getElementById('inspector-subtitle');

        if (mode === 'editor') {
            inspector.classList.remove('hidden');
            if (recipePanel) recipePanel.classList.remove('hidden');
            if (bulkPanel) bulkPanel.classList.add('hidden');
            if (dashPanel) dashPanel.classList.add('hidden');
            if (title) title.textContent = 'Food Cost & Receptura';
            if (subtitle) subtitle.textContent = 'Bliźniak Cyfrowy dania';
        } else if (mode === 'bulk') {
            inspector.classList.remove('hidden');
            if (recipePanel) recipePanel.classList.add('hidden');
            if (bulkPanel) bulkPanel.classList.remove('hidden');
            if (dashPanel) dashPanel.classList.add('hidden');
            if (title) title.textContent = 'Edycja Masowa';
            if (subtitle) subtitle.textContent = globalThis.StudioState.bulkSelectedItems.length + ' dań zaznaczonych';
            this._renderBulkInspectorList();
        } else {
            inspector.classList.remove('hidden');
            if (recipePanel) recipePanel.classList.add('hidden');
            if (bulkPanel) bulkPanel.classList.add('hidden');
            if (dashPanel) dashPanel.classList.remove('hidden');
            if (title) title.textContent = 'Dashboard';
            if (subtitle) subtitle.textContent = 'Statystyki i alerty';
            this.renderDashboard();
        }
    },

    _renderBulkInspectorList: function() {
        const container = document.getElementById('inspector-bulk-list');
        if (!container) return;
        const selected = globalThis.StudioState.bulkSelectedItems || [];
        const items = globalThis.StudioState?.items || [];
        const selectedItems = items.filter(it => selected.includes(it.id));
        if (selectedItems.length === 0) {
            container.innerHTML = '<div class="text-[10px] text-slate-600 italic text-center py-4">Zaznacz dania w drzewie menu</div>';
            return;
        }
        container.innerHTML = selectedItems.map(it => `
            <div class="flex items-center gap-2 bg-black/30 border border-white/5 rounded-lg p-2">
                <div class="w-8 h-8 rounded-lg bg-black/60 border border-white/10 overflow-hidden flex items-center justify-center shrink-0">
                    ${it.imageUrl ? `<img src="${_e(it.imageUrl)}" class="w-full h-full object-cover" loading="lazy" onerror="this.remove();">` : `<i class="fa-solid fa-utensils text-slate-700 text-[10px]"></i>`}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-bold text-slate-300 truncate">${_e(it.name)}</div>
                    <div class="text-[8px] text-slate-600 font-mono">${_e(it.asciiKey)}</div>
                </div>
                <button onclick="globalThis.Core.toggleBulkSelection(${it.id})" class="text-red-400 hover:text-red-300 text-[10px] w-6 h-6 rounded transition shrink-0">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        `).join('');
    },

    _matchesSearch: function(item) {
        if (!this._treeSearchQuery) return true;
        const q = this._treeSearchQuery;
        return (item.name && item.name.toLowerCase().includes(q)) ||
               (item.asciiKey && item.asciiKey.toLowerCase().includes(q));
    },

    _matchesFilter: function(item) {
        if (this._treeFilter === 'all') return true;
        // Faza 1 (2026-08-24): filtry drzewa wyłącznie po publicationStatus.
        if (this._treeFilter === 'active') return item.publicationStatus === 'Live';
        if (this._treeFilter === 'draft') return item.publicationStatus === 'Draft';
        return true;
    },

    renderTree: function() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const categories = globalThis.StudioState?.categories || [];
        const items = globalThis.StudioState?.items || [];
        
        globalThis.StudioState.bulkSelectedItems = globalThis.StudioState.bulkSelectedItems || [];
        this.updateDashboard();

        if (categories.length === 0) {
            container.innerHTML = '<div class="text-center mt-10 text-slate-500 font-bold text-[10px] uppercase">Brak kategorii w bazie.</div>';
            return;
        }
        let html = '<div class="space-y-2"><style>.variant-parent-group .variant-children{display:none}.variant-parent-group.expanded .variant-children{display:flex;flex-direction:column}</style>';
        categories.forEach(cat => {
            const catItems = items.filter(item => item.categoryId == cat.id && this._matchesSearch(item) && this._matchesFilter(item));
            if (catItems.length === 0 && (this._treeSearchQuery || this._treeFilter !== 'all')) return;
            html += `
            <div class="bg-black/30 border border-white/5 rounded-lg overflow-hidden">
                <div class="px-3 py-2.5 flex items-center justify-between cursor-pointer hover:bg-white/5 transition" onclick="globalThis.Core.toggleCategory(${cat.id})">
                    <div class="flex items-center gap-2 min-w-0">
                        <i id="icon-cat-${cat.id}" class="fa-solid fa-chevron-down text-[8px] text-slate-500 transition-transform shrink-0"></i>
                        <span class="text-[10px] font-black uppercase text-slate-200 tracking-wider truncate">${_e(cat.name)}</span>
                        <span class="text-[8px] font-bold text-slate-600 bg-black/40 px-1.5 py-0.5 rounded shrink-0">${catItems.length}</span>
                    </div>
                    <div class="flex items-center gap-0.5 shrink-0">
                        ${cat.layoutMode && cat.layoutMode !== 'legacy_list' ? `
                        <button onclick="event.stopPropagation(); globalThis.CategoryTableEditor && globalThis.CategoryTableEditor.open(${cat.id})" class="w-6 h-6 rounded text-slate-600 hover:text-violet-400 hover:bg-white/5 text-[9px] transition" title="Układ stołu">
                            <i class="fa-solid fa-table-cells"></i>
                        </button>` : ''}
                        <button onclick="event.stopPropagation(); globalThis.Core.editCategory(${cat.id})" class="w-6 h-6 rounded text-slate-600 hover:text-blue-400 hover:bg-white/5 text-[9px] transition" title="Edytuj kategorię">
                            <i class="fa-solid fa-gear"></i>
                        </button>
                        <button onclick="event.stopPropagation(); globalThis.Core.addNewItem(${cat.id})" class="w-6 h-6 rounded text-green-400 hover:text-green-300 hover:bg-green-500/10 text-[9px] transition" title="Nowe danie">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                        <button onclick="event.stopPropagation(); globalThis.ItemEditor.openNewPizzaWizard()" class="w-6 h-6 rounded text-orange-400 hover:text-orange-300 hover:bg-orange-500/10 text-[9px] transition" title="Kreator pizzy">
                            <i class="fa-solid fa-pizza-slice"></i>
                        </button>
                    </div>
                </div>
                <div id="cat-items-${cat.id}" class="flex flex-col border-t border-white/5 transition-all">
            `;
            if (catItems.length === 0) {
                html += `<div class="px-3 py-2 text-[10px] text-slate-600 italic">Kategoria jest pusta — dodaj danie (+)</div>`;
            } else {
                // Podziel na parenty (z wariantami), dzieci (pod parentem) i zwykłe itemy.
                const childParentMap = {};
                catItems.forEach(it => {
                    if (it.parentItemId) childParentMap[it.parentItemId] = true;
                });
                const parents = catItems.filter(it => it.isVariantParent);
                const childrenByParent = {};
                catItems.forEach(it => {
                    if (it.parentItemId) {
                        if (!childrenByParent[it.parentItemId]) childrenByParent[it.parentItemId] = [];
                        childrenByParent[it.parentItemId].push(it);
                    }
                });
                const standalone = catItems.filter(it => !it.isVariantParent && !it.parentItemId);

                const renderItemRow = (item, indent) => {
                    const posTier = item.priceTiers ? item.priceTiers.find(t => t.channel === 'POS') : null;
                    const displayPrice = posTier ? posTier.price.toFixed(2) : (item.price ? parseFloat(item.price).toFixed(2) : "0.00");
                    // Faza 1 (2026-08-24): ikona statusu wyłącznie po publicationStatus.
                    // Live = zielona, Draft = żółta/ostrzeżenie, Archived = szara/pudełko.
                    const pubStatus = item.publicationStatus || 'Draft';
                    const statusIcon = pubStatus === 'Live'
                        ? '<i class="fa-solid fa-circle-check text-green-500 text-[10px]" title="Live"></i>'
                        : pubStatus === 'Archived'
                            ? '<i class="fa-solid fa-box-archive text-slate-400 text-[10px]" title="Archived"></i>'
                            : '<i class="fa-solid fa-circle-exclamation text-amber-500 text-[10px]" title="Draft"></i>';
                    const isChecked = globalThis.StudioState.bulkSelectedItems.includes(item.id) ? 'checked' : '';
                    const thumbHtml = item.imageUrl
                        ? `<img src="${_e(item.imageUrl)}" alt="" class="w-full h-full object-cover" loading="lazy" onerror="this.remove(); this.parentElement.innerHTML='<i class=\\'fa-solid fa-image text-slate-700 text-[11px]\\'></i>';">`
                        : `<i class="fa-solid fa-image text-slate-700 text-[11px]" title="Brak zdjęcia — dodaj w Asset Studio"></i>`;
                    const thumbRing = item.imageUrl
                        ? 'border-white/10'
                        : 'border-amber-500/20 bg-amber-900/10';
                    const indentStyle = indent ? `padding-left:${24 + indent * 20}px` : '';

                    return `
                    <div class="px-3 py-2 flex items-center justify-between border-b border-white/5 last:border-0 hover:bg-blue-500/10 cursor-pointer transition group" data-item-id="${item.id}" data-item-sku="${_e(item.asciiKey)}" draggable="true" style="${indentStyle}">
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <input type="checkbox" ${isChecked} class="w-3.5 h-3.5 rounded bg-black/50 border-white/20 cursor-pointer accent-cyan-500 shrink-0" onclick="event.stopPropagation(); globalThis.Core.toggleBulkSelection(${item.id})">
                            <i class="fa-solid fa-grip-vertical text-slate-700 text-[9px] opacity-0 group-hover:opacity-100 transition-opacity shrink-0 cursor-grab"></i>
                            <div class="item-thumb w-8 h-8 rounded-lg bg-black/60 border ${thumbRing} overflow-hidden flex items-center justify-center shrink-0">${thumbHtml}</div>
                            <span class="text-[11px] font-bold ${indent ? 'text-slate-400' : 'text-slate-300'} group-hover:text-blue-400 transition-colors truncate">${_e(item.name)}</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-[8px] text-slate-600 font-mono hidden group-hover:block transition-all">${_e(item.asciiKey)}</span>
                            <button onclick="event.stopPropagation(); globalThis.Core.quickEditPrice(${item.id})" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Szybka edycja ceny">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <span class="text-[10px] text-yellow-500 font-bold font-mono bg-black/40 px-1.5 py-0.5 rounded border border-white/5">${displayPrice} zł</span>
                            ${statusIcon}
                        </div>
                    </div>
                    `;
                };

                const renderParentWithChildren = (parent) => {
                    const children = childrenByParent[parent.id] || [];
                    const childCount = children.length;
                    let row = renderItemRow(parent, 0);
                    if (childCount > 0) {
                        row = `
                        <div class="variant-parent-group">
                            <div class="px-3 py-2 flex items-center justify-between border-b border-white/5 last:border-0 hover:bg-blue-500/10 cursor-pointer transition group" data-item-id="${parent.id}" data-item-sku="${_e(parent.asciiKey)}">
                                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                    <input type="checkbox" class="w-3.5 h-3.5 rounded bg-black/50 border-white/20 cursor-pointer accent-cyan-500 shrink-0" onclick="event.stopPropagation(); globalThis.Core.toggleBulkSelection(${parent.id})">
                                    <i class="fa-solid fa-chevron-right text-[8px] text-orange-400 transition-transform shrink-0 variant-toggle" onclick="event.stopPropagation(); this.closest('.variant-parent-group').classList.toggle('expanded'); this.style.transform=this.closest('.variant-parent-group').classList.contains('expanded')?'rotate-90deg':'';"></i>
                                    <div class="item-thumb w-8 h-8 rounded-lg bg-black/60 border border-orange-500/20 bg-orange-900/10 overflow-hidden flex items-center justify-center shrink-0">
                                        ${parent.imageUrl ? `<img src="${_e(parent.imageUrl)}" alt="" class="w-full h-full object-cover" loading="lazy" onerror="this.remove();">` : `<i class="fa-solid fa-clone text-orange-500 text-[10px]"></i>`}
                                    </div>
                                    <span class="text-[11px] font-black text-orange-300 group-hover:text-orange-100 transition-colors truncate">${_e(parent.name)}</span>
                                    <span class="text-[7px] text-orange-500/60 font-bold bg-orange-900/20 px-1.5 py-0.5 rounded shrink-0">${childCount} rozmi.</span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="text-[8px] text-slate-600 font-mono hidden group-hover:block transition-all">${_e(parent.asciiKey)}</span>
                                    <span class="text-[9px] text-orange-400 font-bold font-mono bg-black/40 px-1.5 py-0.5 rounded border border-orange-500/10">WARIANTY</span>
                                </div>
                            </div>
                            <div class="variant-children">
                                ${children.map(c => renderItemRow(c, 1)).join('')}
                            </div>
                        </div>`;
                    }
                    return row;
                };

                // Render: standalone items + parent groups (z dziećmi ukrytymi pod toggle)
                standalone.forEach(item => { html += renderItemRow(item, 0); });
                parents.forEach(parent => { html += renderParentWithChildren(parent); });
            }
            html += `</div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
        container.querySelectorAll('[data-item-id][data-item-sku]').forEach(el => {
            el.addEventListener('click', () => globalThis.Core.openItemEditor(parseInt(el.dataset.itemId), el.dataset.itemSku));
        });
        this.bindTreeDragDrop();
    },
    _treeNavIndex: -1,

    navigateTree: function(direction) {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const rows = Array.from(container.querySelectorAll('[data-item-id]'));
        if (rows.length === 0) return;
        if (this._treeNavIndex < 0) this._treeNavIndex = direction > 0 ? 0 : rows.length - 1;
        else this._treeNavIndex += direction;
        if (this._treeNavIndex < 0) this._treeNavIndex = 0;
        if (this._treeNavIndex >= rows.length) this._treeNavIndex = rows.length - 1;
        rows.forEach(r => r.classList.remove('active-tree-item'));
        const target = rows[this._treeNavIndex];
        target.classList.add('active-tree-item');
        target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    },

    openSelectedItem: function() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const rows = Array.from(container.querySelectorAll('[data-item-id]'));
        if (rows.length === 0 || this._treeNavIndex < 0) return;
        const target = rows[this._treeNavIndex];
        if (target) {
            const itemId = parseInt(target.dataset.itemId, 10);
            const sku = target.dataset.itemSku;
            if (itemId) globalThis.Core.openItemEditor(itemId, sku);
        }
    },

    _draggedItemId: null,

    bindTreeDragDrop: function() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        container.addEventListener('dragstart', (e) => {
            const row = e.target.closest('[data-item-id]');
            if (!row) return;
            this._draggedItemId = parseInt(row.dataset.itemId, 10);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(this._draggedItemId));
            row.classList.add('opacity-50');
        });
        container.addEventListener('dragend', (e) => {
            const row = e.target.closest('[data-item-id]');
            if (row) row.classList.remove('opacity-50');
            this._draggedItemId = null;
            container.querySelectorAll('.drag-over-top, .drag-over-bottom').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
        });
        container.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const row = e.target.closest('[data-item-id]');
            if (!row) return;
            container.querySelectorAll('.drag-over-top, .drag-over-bottom').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            const rect = row.getBoundingClientRect();
            const isAbove = e.clientY < rect.top + rect.height / 2;
            row.classList.add(isAbove ? 'drag-over-top' : 'drag-over-bottom');
        });
        container.addEventListener('drop', async (e) => {
            e.preventDefault();
            const row = e.target.closest('[data-item-id]');
            if (!row || !this._draggedItemId) return;
            const targetId = parseInt(row.dataset.itemId, 10);
            if (targetId === this._draggedItemId) return;
            const rect = row.getBoundingClientRect();
            const isAbove = e.clientY < rect.top + rect.height / 2;
            try {
                const res = await globalThis.apiStudio('reorder_item', {
                    itemId: this._draggedItemId,
                    targetItemId: targetId,
                    position: isAbove ? 'before' : 'after',
                });
                if (res.success) {
                    if (globalThis.StudioToast) globalThis.StudioToast.show('Kolejność zaktualizowana.', 'success');
                    await globalThis.loadMenuTree();
                    globalThis.Core.renderTree();
                } else {
                    if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd zmiany kolejności: ' + (res.message || ''), 'error');
                }
            } catch (err) {
                console.error('[UI] DnD reorder error:', err);
            }
        });
    },

    quickEditPrice: async function(itemId) {
        const items = globalThis.StudioState?.items || [];
        const item = items.find(i => i.id === itemId);
        if (!item) return;
        const posTier = item.priceTiers?.find(t => t.channel === 'POS');
        const currentPrice = posTier?.price || item.price || 0;
        let existing = document.getElementById('quick-edit-overlay');
        if (existing) existing.remove();
        const overlay = document.createElement('div');
        overlay.id = 'quick-edit-overlay';
        overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm';
        overlay.innerHTML = `
        <div class="bg-[#0c0f1a] border border-white/10 rounded-2xl p-6 w-[360px] shadow-2xl">
            <h3 class="text-white text-sm font-black uppercase tracking-widest mb-1">Szybka edycja ceny</h3>
            <p class="text-slate-500 text-[10px] mb-4">${_e(item.name)} · POS</p>
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <span class="text-slate-400 text-xs font-bold">PLN</span>
                    <input type="number" step="0.01" id="quick-edit-price-input" value="${currentPrice.toFixed(2)}" class="flex-1 bg-black/50 border border-white/10 text-white rounded-lg p-3 text-lg font-bold text-center focus:border-blue-500 focus:outline-none transition">
                </div>
                <div class="flex gap-2 mt-2">
                    <button id="quick-edit-save" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-black text-xs uppercase py-2.5 rounded-lg transition">Zapisz</button>
                    <button id="quick-edit-cancel" class="px-5 bg-white/5 border border-white/10 text-slate-400 hover:text-white font-black text-xs uppercase rounded-lg transition">Anuluj</button>
                </div>
            </div>
        </div>`;
        document.body.appendChild(overlay);
        const priceInput = document.getElementById('quick-edit-price-input');
        priceInput.focus();
        priceInput.select();
        const close = () => overlay.remove();
        document.getElementById('quick-edit-cancel').onclick = close;
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        priceInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') document.getElementById('quick-edit-save').click();
            if (e.key === 'Escape') close();
        });
        document.getElementById('quick-edit-save').onclick = async () => {
            const newPrice = parseFloat(priceInput.value) || 0;
            try {
                const res = await globalThis.apiStudio('save_bulk', {
                    itemIds: [itemId],
                    omnichannelPricePatch: {
                        apply: true,
                        targetChannel: 'POS',
                        operationType: 'set_amount',
                        operationValue: newPrice,
                    },
                });
                if (res.success) {
                    if (globalThis.StudioToast) globalThis.StudioToast.show('Cena zaktualizowana: ' + newPrice.toFixed(2) + ' PLN', 'success');
                    await globalThis.loadMenuTree();
                    globalThis.Core.renderTree();
                } else {
                    if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd: ' + (res.message || ''), 'error');
                }
            } catch (err) {
                if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd sieci.', 'error');
            }
            close();
        };
    },

    toggleBulkSelection: function(itemId) {
        globalThis.StudioState.bulkSelectedItems = globalThis.StudioState.bulkSelectedItems || [];
        
        const index = globalThis.StudioState.bulkSelectedItems.indexOf(itemId);
        if (index > -1) {
            globalThis.StudioState.bulkSelectedItems.splice(index, 1);
        } else {
            globalThis.StudioState.bulkSelectedItems.push(itemId);
        }
        
        // Faza 4: auto-switch workspace based on selection count
        const count = globalThis.StudioState.bulkSelectedItems.length;
        if (count > 1) {
            globalThis.StudioState.selectedItemId = null;
            globalThis.Core.switchView('bulk');
        } else {
            if (globalThis.StudioState.currentView === 'bulk') {
                globalThis.Core.switchView('menu');
            }
        }

        const bulkView = document.getElementById('bulk-inspector-view');
        if (bulkView) {
            const label = document.getElementById('bulk-count-label');
            if (label) label.textContent = `Zaznaczono: ${count} dań`;
        }
        
        globalThis.Core.renderTree(); // Odśwież widok, aby checkbox zareagował
        globalThis.Core.updateInspector(); // Faza 4: odśwież inspektor
    },
    toggleCategory: function(catId) {
        const container = document.getElementById(`cat-items-${catId}`);
        const icon = document.getElementById(`icon-cat-${catId}`);
        if (container) { container.classList.toggle('hidden'); if (icon) icon.classList.toggle('-rotate-90'); }
    },
    openItemEditor: async function(itemId, asciiKey) {
        console.log("[UI] Wybrano danie SKU:", asciiKey);
        globalThis.Core.switchView('menu');

        globalThis.StudioState.selectedItemId = itemId;
        globalThis.StudioState.bulkSelectedItems = [];

        // 1. Zasilenie listy kategorii w select (żeby menedżer miał z czego wybierać)
        const catSelect = document.getElementById('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            const categories = globalThis.StudioState?.categories || [];
            categories.forEach(cat => {
                catSelect.add(new Option(cat.name, cat.id));
            });
        }

        // 2. Uderzenie do bazy po PEŁNE dane księgowe (Cena, VAT, Drukarka)
        try {
            const result = await globalThis.apiStudio('get_item_details', { itemId: itemId });

            if (result.success === true && globalThis.ItemEditor) {
                // Wstrzykujemy twarde dane z bazy do formularza po lewej stronie
                globalThis.ItemEditor.loadItemDataToForm(result.data);
                // Track recent item
                const itemData = result.data;
                const treeItem = (globalThis.StudioState?.items || []).find(i => i.id === itemId);
                this._addRecentItem({
                    id: itemId,
                    name: itemData.name || treeItem?.name || '',
                    asciiKey: asciiKey,
                    imageUrl: itemData.imageUrl || treeItem?.imageUrl || '',
                });
            } else {
                console.error("[UI] Błąd pobierania detali dania:", result.message);
            }
        } catch (e) { 
            console.error("[UI] Błąd komunikacji z API przy pobieraniu dania:", e); 
        }

        // 3. Zasilenie Receptur i aktualizacja nagłówka dla inspektora
        const skuDisplay = document.getElementById('current-sku-display');
        if(skuDisplay) skuDisplay.innerText = "SKU: " + asciiKey;

        if(typeof globalThis.RecipeMapper !== 'undefined') {
            globalThis.RecipeMapper.loadItemRecipe(asciiKey);
        }

        // 4. Przełącz inspektor na tryb edytora
        this.updateInspector();
    },
    addNewItem: function(categoryId) {
        globalThis.Core.switchView('menu');

        globalThis.StudioState.selectedItemId = null;
        globalThis.StudioState.bulkSelectedItems = [];

        const catSelect = document.getElementById('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            const categories = globalThis.StudioState?.categories || [];
            categories.forEach(cat => {
                catSelect.add(new Option(cat.name, cat.id));
            });
        }

        const cat = (globalThis.StudioState?.categories || []).find(c => c.id == categoryId);
        const vatDineIn = cat?.defaultVatDineIn ?? 8;
        const vatTakeaway = cat?.defaultVatTakeaway ?? 5;

        if (globalThis.ItemEditor && typeof globalThis.ItemEditor.loadItemDataToForm === 'function') {
            globalThis.ItemEditor.loadItemDataToForm({
                id: 0,
                categoryId: categoryId,
                name: '',
                asciiKey: '',
                isActive: true,
                vatRateDineIn: vatDineIn,
                vatRateTakeaway: vatTakeaway,
                priceMatrix: { POS: 0, Takeaway: 0, Delivery: 0 },
                kdsStationId: 'NONE',
                // F-S4-fix (2026-05-13): nowa pizza default Live (przed: Draft → niewidoczna w POS).
                // Manager może zmienić na Draft ręcznie command barem przed Save jeśli chce.
                publicationStatus: 'Live'
            });
        }
        this.updateInspector();
    },

    _renderCategoryModal: function(catData) {
        const isEdit = catData && catData.id > 0;
        const title = isEdit ? 'Edytuj Kategorię' : 'Nowa Kategoria';
        const name = catData?.name || '';
        const vatDI = catData?.defaultVatDineIn ?? 8;
        const vatTA = catData?.defaultVatTakeaway ?? 5;
        // M022: layout_mode + default_composition_profile
        const layoutMode = catData?.layoutMode || 'legacy_list';
        const defaultProfile = catData?.defaultCompositionProfile || 'static_hero';

        let existing = document.getElementById('category-modal-overlay');
        if (existing) existing.remove();

        // M022: Scene templates dla dropdown — weź z cache'u
        const itemTemplates = (globalThis.StudioState?.sceneTemplates || []).filter(t => t.kind === 'item');
        const profileOptions = itemTemplates.length > 0
            ? itemTemplates.map(t =>
                `<option value="${t.asciiKey}" ${defaultProfile === t.asciiKey ? 'selected' : ''}>${t.name}</option>`
              ).join('')
            : `<option value="static_hero" ${defaultProfile === 'static_hero' ? 'selected' : ''}>Gotowe zdjęcie dania (uniwersalny)</option>
               <option value="pizza_top_down" ${defaultProfile === 'pizza_top_down' ? 'selected' : ''}>Pizza — kamera z góry (warstwy)</option>`;

        const layoutOption = (id, label, desc) => `
            <label class="flex items-start gap-3 cursor-pointer group hover:bg-white/5 p-3 rounded-lg border border-transparent hover:border-white/10 transition">
                <input type="radio" name="cat-modal-layout" value="${id}" class="mt-0.5 w-4 h-4 cursor-pointer" ${layoutMode === id ? 'checked' : ''}>
                <div class="flex-1">
                    <div class="text-white text-[11px] font-bold group-hover:text-blue-300 transition">${label}</div>
                    <div class="text-slate-600 text-[9px] mt-0.5 leading-relaxed">${desc}</div>
                </div>
            </label>`;

        const overlay = document.createElement('div');
        overlay.id = 'category-modal-overlay';
        overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm overflow-y-auto py-8';
        overlay.innerHTML = `
        <div class="bg-[#0c0f1a] border border-white/10 rounded-xl p-6 w-[480px] shadow-2xl my-auto">
            <h3 class="text-white text-[12px] font-black uppercase tracking-widest mb-5">${title}</h3>
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="studio-label">Nazwa Kategorii</label>
                    <input type="text" id="cat-modal-name" value="${name}" class="studio-input px-3 py-2.5 text-[13px]" placeholder="np. Pizze, Napoje, Desery...">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="studio-label">Domyślne Stawki VAT</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[8px] text-slate-500 font-bold uppercase">Na Sali (Dine-in)</span>
                            <select id="cat-modal-vat-dinein" class="studio-input px-3 py-2 text-[12px] cursor-pointer">
                                <option value="23" ${vatDI==23?'selected':''}>23%</option>
                                <option value="8" ${vatDI==8?'selected':''}>8%</option>
                                <option value="5" ${vatDI==5?'selected':''}>5%</option>
                                <option value="0" ${vatDI==0?'selected':''}>0%</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[8px] text-slate-500 font-bold uppercase">Wynos / Dostawa</span>
                            <select id="cat-modal-vat-takeaway" class="studio-input px-3 py-2 text-[12px] cursor-pointer">
                                <option value="23" ${vatTA==23?'selected':''}>23%</option>
                                <option value="8" ${vatTA==8?'selected':''}>8%</option>
                                <option value="5" ${vatTA==5?'selected':''}>5%</option>
                                <option value="0" ${vatTA==0?'selected':''}>0%</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-[8px] text-slate-600 mt-1">Nowe dania dodawane do tej kategorii odziedziczą te stawki.</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="studio-label">Domyślny Profil Kompozycji (Scene Studio)</label>
                    <select id="cat-modal-composition-profile" class="studio-input px-3 py-2 text-[12px] cursor-pointer">
                        ${profileOptions}
                    </select>
                    <p class="text-[8px] text-slate-600 mt-1">Pizza = warstwy z góry, pozostałe = 1 gotowe zdjęcie.</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="studio-label">Widok klienta — The Table</label>
                    <div class="flex flex-col gap-2 bg-black/30 rounded-xl p-3 border border-white/5">
                        ${layoutOption('legacy_list',
                            'Klasyczna lista',
                            'Standard — lista dań z miniaturkami.')}
                        ${layoutOption('grouped',
                            'Jeden wspólny stół',
                            'Cała kategoria na jednej dioramie. Dobre dla kategorii ≤6 pozycji.')}
                        ${layoutOption('individual',
                            'Sekwencja scen per danie',
                            'Każde danie ma własną dioramę (swipe L/R).')}
                        ${layoutOption('hybrid',
                            'Banner + sekwencja',
                            'Pierwsza diorama = banner, kolejne = osobne dania.')}
                    </div>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button id="cat-modal-save" class="flex-1 bg-blue-600/80 hover:bg-blue-500 text-white font-black text-[11px] uppercase tracking-widest py-2.5 rounded-lg transition">
                    <i class="fa-solid fa-check mr-2"></i>${isEdit ? 'Zapisz' : 'Dodaj'}
                </button>
                <button id="cat-modal-cancel" class="px-5 bg-white/5 border border-white/10 text-slate-400 hover:text-white font-black text-[11px] uppercase rounded-lg transition">
                    Anuluj
                </button>
            </div>
        </div>`;

        document.body.appendChild(overlay);
        document.getElementById('cat-modal-name').focus();
        document.getElementById('cat-modal-cancel').onclick = () => overlay.remove();
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

        return new Promise((resolve) => {
            document.getElementById('cat-modal-save').onclick = () => {
                const layoutRadio = document.querySelector('input[name="cat-modal-layout"]:checked');
                const result = {
                    name: document.getElementById('cat-modal-name').value.trim(),
                    defaultVatDineIn: parseFloat(document.getElementById('cat-modal-vat-dinein').value),
                    defaultVatTakeaway: parseFloat(document.getElementById('cat-modal-vat-takeaway').value),
                    // M022: nowe pola
                    layoutMode: layoutRadio?.value || 'legacy_list',
                    defaultCompositionProfile: document.getElementById('cat-modal-composition-profile').value || 'static_hero'
                };
                overlay.remove();
                resolve(result);
            };
            document.getElementById('cat-modal-name').addEventListener('keydown', (e) => {
                if (e.key === 'Enter') document.getElementById('cat-modal-save').click();
                if (e.key === 'Escape') { overlay.remove(); resolve(null); }
            });
        });
    },

    addCategory: async function() {
        const result = await globalThis.Core._renderCategoryModal(null);
        if (!result || !result.name) return;

        try {
            const response = await globalThis.apiStudio('add_category', result);
            if (response && response.success === true) {
                await globalThis.loadMenuTree();
                globalThis.Core.renderTree();
            } else {
                if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd: ' + (response?.message || 'Nie udało się dodać kategorii.'), 'error');
            }
        } catch (e) {
            if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd sieci przy dodawaniu kategorii.', 'error');
            console.error("[UI] addCategory error:", e);
        }
    },

    editCategory: async function(catId) {
        const cat = (globalThis.StudioState?.categories || []).find(c => c.id == catId);
        if (!cat) return;

        const result = await globalThis.Core._renderCategoryModal(cat);
        if (!result || !result.name) return;

        try {
            const response = await globalThis.apiStudio('update_category', { categoryId: catId, ...result });
            if (response && response.success === true) {
                await globalThis.loadMenuTree();
                globalThis.Core.renderTree();
            } else {
                if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd: ' + (response?.message || 'Nie udało się zaktualizować kategorii.'), 'error');
            }
        } catch (e) {
            if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd sieci przy edycji kategorii.', 'error');
            console.error("[UI] editCategory error:", e);
        }
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    if (globalThis.MarginGuardian) await globalThis.MarginGuardian.init();

    const treeContainer = document.getElementById('dynamic-tree-container');
    if (typeof globalThis.loadMenuTree !== 'function') {
        if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd Krytyczny: Brak pliku Mózgu (studio_core.js)</div>';
        return;
    }

    // M022: Scene templates loading (parallel, non-blocking — cache'owane)
    if (typeof globalThis.loadSceneTemplates === 'function') {
        globalThis.loadSceneTemplates().catch(() => {
            // cicho — migracja 022 może jeszcze nie przejść, formularz dania użyje fallback hard-coded listy
        });
    }

    const data = await globalThis.loadMenuTree();
    if (data) {
        globalThis.Core.renderTree();
        globalThis.Core.updateInspector(); // Faza 4: pokaż dashboard w inspektorze na starcie
    }
    else if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd pobierania danych z API. Sprawdź konsolę.</div>';
});