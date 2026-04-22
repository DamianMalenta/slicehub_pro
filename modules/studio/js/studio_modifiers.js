// 🪄 SLICEHUB STUDIO - MODUŁ MODYFIKATORÓW (V3.2 ENTERPRISE + SKU GRUPY)

window.StudioState = window.StudioState || {};
window.StudioState.modifierGroups = window.StudioState.modifierGroups || [];
window.StudioState.products = window.StudioState.products || [];
window.StudioState.warehouseItems = window.StudioState.warehouseItems || [];
window.StudioState.activeModGroupId = null;
window.StudioState.compactAssets = window.StudioState.compactAssets || null;

window.ModifierInspector = {
    _esc(s) {
        const t = document.createElement('div');
        t.textContent = s == null ? '' : String(s);
        return t.innerHTML;
    },

    async loadCompactAssets() {
        if (window.StudioState.compactAssets !== null) return window.StudioState.compactAssets;
        const r = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', { action: 'list_assets_compact' });
        if (r.success && r.data && Array.isArray(r.data.assets)) {
            window.StudioState.compactAssets = r.data.assets;
        } else {
            window.StudioState.compactAssets = [];
            console.warn('[ModifierInspector] list_assets_compact:', r.message || 'empty');
        }
        return window.StudioState.compactAssets;
    },

    _buildAssetSelectOptions(selectedId) {
        const assets = window.StudioState.compactAssets || [];
        let html = '<option value="">— brak —</option>';
        const sid = selectedId ? parseInt(selectedId, 10) : 0;
        for (const a of assets) {
            const id = parseInt(a.id, 10);
            const sel = sid > 0 && id === sid ? 'selected' : '';
            const lab = `${a.asciiKey || ''}${a.category ? ' · ' + a.category : ''}`;
            html += `<option value="${id}" ${sel}>${this._esc(lab)}</option>`;
        }
        return html;
    },

    // ─────────────────────────────────────────────────────────────────────
    // M1 · Connect-dots — Asset Picker (modal grid) + Live Preview + Badge
    // Podmiana na thumbnailowy picker zamiast surowego <select>.
    // Backend bez zmian: czytamy z `StudioState.compactAssets` (list_assets_compact).
    // Role są filtrowane po `roleHint` z sh_assets (layer_top_down / modifier_hero).
    // ─────────────────────────────────────────────────────────────────────

    _findAssetById(id) {
        const aid = parseInt(id, 10) || 0;
        if (!aid) return null;
        const assets = window.StudioState.compactAssets || [];
        return assets.find(a => parseInt(a.id, 10) === aid) || null;
    },

    _roleHintsForSlot(role) {
        // Jakie role_hint w sh_assets akceptujemy dla danego slotu w modyfikatorze.
        // Permisywnie — pozwalamy managerowi użyć assetu z dowolnym role_hint
        // (preferujemy dopasowane, ale nie blokujemy).
        if (role === 'layer_top_down') return ['layer_top_down', 'layer', 'topping', 'scatter'];
        if (role === 'modifier_hero')  return ['modifier_hero', 'hero', 'product', 'companion'];
        return [];
    },

    _buildPickerButtonHtml(role, assetId) {
        const a = this._findAssetById(assetId);
        const hiddenClass = role === 'layer_top_down' ? 'opt-asset-layer-id' : 'opt-asset-hero-id';
        const roleLabel = role === 'layer_top_down' ? 'layer_top_down' : 'modifier_hero';
        const slotTitle = role === 'layer_top_down' ? 'Warstwa rzut z góry' : 'Hero dodatku';

        const thumbHtml = a && a.previewUrl
            ? `<img src="${this._esc(a.previewUrl)}" alt="" class="w-full h-full object-cover" loading="lazy">`
            : `<i class="fa-solid fa-image text-slate-700 text-xl"></i>`;

        const title = a ? (a.asciiKey || '—') : '— kliknij aby wybrać —';
        const sub = a ? (a.category ? a.category.toUpperCase() : 'asset') : 'brak wybranego';

        return `
            <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">${slotTitle} <span class="text-violet-500">(${roleLabel})</span></label>
            <button type="button" class="opt-asset-picker w-full bg-black/50 border border-white/10 rounded-xl p-2 flex items-center gap-3 hover:border-violet-500/50 hover:bg-black/60 transition min-h-[72px] text-left" data-role="${role}">
                <div class="asset-thumb w-14 h-14 bg-black/60 rounded-lg flex items-center justify-center overflow-hidden border border-white/5 shrink-0">
                    ${thumbHtml}
                </div>
                <div class="asset-meta flex-1 overflow-hidden">
                    <div class="asset-title text-[10px] font-black text-white truncate">${this._esc(title)}</div>
                    <div class="asset-sub text-[8px] text-slate-500 uppercase truncate">${this._esc(sub)}</div>
                </div>
                <i class="fa-solid fa-chevron-right text-slate-700 text-[9px] shrink-0"></i>
            </button>
            <input type="hidden" class="${hiddenClass}" value="${a ? parseInt(a.id, 10) : 0}">
        `;
    },

    _refreshPickerButton(row, role) {
        if (!row) return;
        const hiddenCls = role === 'layer_top_down' ? '.opt-asset-layer-id' : '.opt-asset-hero-id';
        const hidden = row.querySelector(hiddenCls);
        const assetId = hidden ? parseInt(hidden.value, 10) || 0 : 0;
        const btn = row.querySelector(`.opt-asset-picker[data-role="${role}"]`);
        if (!btn) return;
        const a = this._findAssetById(assetId);
        const thumbEl = btn.querySelector('.asset-thumb');
        const titleEl = btn.querySelector('.asset-title');
        const subEl   = btn.querySelector('.asset-sub');
        if (thumbEl) {
            thumbEl.innerHTML = a && a.previewUrl
                ? `<img src="${this._esc(a.previewUrl)}" alt="" class="w-full h-full object-cover" loading="lazy">`
                : `<i class="fa-solid fa-image text-slate-700 text-xl"></i>`;
        }
        if (titleEl) titleEl.textContent = a ? (a.asciiKey || '—') : '— kliknij aby wybrać —';
        if (subEl)   subEl.textContent   = a ? (a.category ? a.category.toUpperCase() : 'asset') : 'brak wybranego';
    },

    _computeRowBadge(row) {
        if (!row) return { cls: '', label: '', tone: '' };
        const impact = !!row.querySelector('.opt-visual-impact')?.checked;
        const layer  = parseInt(row.querySelector('.opt-asset-layer-id')?.value, 10) || 0;
        const hero   = parseInt(row.querySelector('.opt-asset-hero-id')?.value, 10) || 0;

        if (!impact) {
            return { label: 'text only', tone: 'bg-slate-800 text-slate-500 border-white/10', icon: 'fa-circle' };
        }
        if (layer && hero) {
            return { label: 'surface ready', tone: 'bg-green-900/30 text-green-400 border-green-500/40', icon: 'fa-check-circle' };
        }
        if (layer || hero) {
            return { label: 'incomplete', tone: 'bg-yellow-900/30 text-yellow-400 border-yellow-500/40', icon: 'fa-triangle-exclamation' };
        }
        return { label: 'flag · brak assetów', tone: 'bg-red-900/30 text-red-400 border-red-500/40', icon: 'fa-circle-exclamation' };
    },

    _refreshRowBadge(row) {
        if (!row) return;
        const badge = row.querySelector('.vis-status-badge');
        if (!badge) return;
        const b = this._computeRowBadge(row);
        badge.className = `vis-status-badge text-[8px] font-black uppercase tracking-widest px-2 py-1 rounded-full border inline-flex items-center gap-1 ${b.tone}`;
        badge.innerHTML = `<i class="fa-solid ${b.icon} text-[8px]"></i> ${this._esc(b.label)}`;
    },

    _refreshRowPreview(row) {
        if (!row) return;
        const box = row.querySelector('.vis-preview-box');
        if (!box) return;
        const impact = !!row.querySelector('.opt-visual-impact')?.checked;
        const layerId = parseInt(row.querySelector('.opt-asset-layer-id')?.value, 10) || 0;
        const heroId  = parseInt(row.querySelector('.opt-asset-hero-id')?.value, 10) || 0;
        const layer = this._findAssetById(layerId);
        const hero  = this._findAssetById(heroId);

        const dim = impact ? '' : 'opacity-30 grayscale';
        const layerImg = (impact && layer && layer.previewUrl)
            ? `<img src="${this._esc(layer.previewUrl)}" alt="" class="absolute inset-0 w-full h-full object-contain ${dim}" loading="lazy">`
            : '';
        const heroImg = (impact && hero && hero.previewUrl)
            ? `<img src="${this._esc(hero.previewUrl)}" alt="" class="absolute bottom-1 right-1 w-8 h-8 object-contain rounded-full bg-black/60 border border-white/20 shadow-lg ${dim}" loading="lazy">`
            : '';

        box.innerHTML = `
            <div class="absolute inset-0 rounded-full bg-gradient-to-br from-amber-900/30 via-orange-950/40 to-black border border-white/5"></div>
            ${layerImg}
            ${heroImg}
            ${!impact ? '<div class="absolute inset-0 flex items-center justify-center text-[8px] text-slate-600 font-black uppercase tracking-widest">text only</div>' : ''}
            ${impact && !layerId && !heroId ? '<div class="absolute inset-0 flex items-center justify-center text-[8px] text-slate-600 font-black uppercase tracking-widest opacity-60">brak assetów</div>' : ''}
        `;
    },

    _refreshRowVisual(row) {
        this._refreshPickerButton(row, 'layer_top_down');
        this._refreshPickerButton(row, 'modifier_hero');
        this._refreshRowBadge(row);
        this._refreshRowPreview(row);
    },

    openAssetPickerModal(row, role) {
        if (!row) return;
        const hiddenCls = role === 'layer_top_down' ? '.opt-asset-layer-id' : '.opt-asset-hero-id';
        const hidden = row.querySelector(hiddenCls);
        const currentId = hidden ? parseInt(hidden.value, 10) || 0 : 0;

        const preferred = this._roleHintsForSlot(role);
        const assets = (window.StudioState.compactAssets || []).slice();

        assets.sort((a, b) => {
            const aPref = preferred.includes(String(a.roleHint || '').toLowerCase()) ? 0 : 1;
            const bPref = preferred.includes(String(b.roleHint || '').toLowerCase()) ? 0 : 1;
            if (aPref !== bPref) return aPref - bPref;
            return (a.asciiKey || '').localeCompare(b.asciiKey || '');
        });

        const categories = Array.from(new Set(assets.map(a => a.category).filter(Boolean))).sort();

        let host = document.getElementById('sh-asset-picker-modal');
        if (host) host.remove();
        host = document.createElement('div');
        host.id = 'sh-asset-picker-modal';
        host.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm p-4';
        host.innerHTML = `
            <div class="bg-[#0a0a0f] border border-white/10 rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden">
                <header class="flex items-center justify-between px-6 py-4 border-b border-white/5 shrink-0">
                    <div>
                        <div class="text-[9px] font-black uppercase text-violet-400 tracking-widest">Asset Picker</div>
                        <div class="text-[14px] font-black text-white">${role === 'layer_top_down' ? 'Warstwa rzut z góry (scatter)' : 'Hero dodatku'}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" class="picker-clear text-[9px] font-black uppercase text-red-400 hover:text-red-300 px-3 py-2 rounded-lg border border-red-500/30 hover:bg-red-900/20 transition">
                            <i class="fa-solid fa-ban mr-1"></i> Wyczyść slot
                        </button>
                        <button type="button" class="picker-close text-slate-500 hover:text-white w-9 h-9 rounded-lg hover:bg-white/5 transition flex items-center justify-center">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </header>
                <div class="px-6 py-4 border-b border-white/5 flex flex-wrap gap-3 items-center shrink-0">
                    <div class="flex-1 min-w-[220px] relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-600 text-[11px]"></i>
                        <input type="text" class="picker-search w-full bg-black/50 border border-white/10 rounded-xl pl-9 pr-3 py-2.5 text-white text-[11px] outline-none focus:border-violet-500 transition" placeholder="Szukaj po nazwie, kategorii lub SKU...">
                    </div>
                    <select class="picker-cat bg-black/50 border border-white/10 rounded-xl px-3 py-2.5 text-white text-[11px] outline-none focus:border-violet-500 transition">
                        <option value="">Wszystkie kategorie</option>
                        ${categories.map(c => `<option value="${this._esc(c)}">${this._esc(c.toUpperCase())}</option>`).join('')}
                    </select>
                    <label class="flex items-center gap-2 text-[10px] font-black uppercase text-slate-400 cursor-pointer">
                        <input type="checkbox" class="picker-only-matching w-4 h-4 rounded border-white/10 bg-black/50" checked>
                        Tylko pasujące role
                    </label>
                </div>
                <div class="picker-grid flex-1 overflow-y-auto p-6 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3"></div>
                <footer class="px-6 py-3 border-t border-white/5 text-[9px] font-black uppercase text-slate-600 tracking-wider shrink-0">
                    <span class="picker-count">0</span> assetów · kliknij aby przypisać
                </footer>
            </div>
        `;
        document.body.appendChild(host);

        const gridEl = host.querySelector('.picker-grid');
        const searchEl = host.querySelector('.picker-search');
        const catEl = host.querySelector('.picker-cat');
        const onlyMatchingEl = host.querySelector('.picker-only-matching');
        const countEl = host.querySelector('.picker-count');

        const renderGrid = () => {
            const q = (searchEl.value || '').trim().toLowerCase();
            const cat = catEl.value || '';
            const onlyMatching = !!onlyMatchingEl.checked;
            const filtered = assets.filter(a => {
                if (cat && a.category !== cat) return false;
                if (q) {
                    const hay = `${a.asciiKey || ''} ${a.category || ''} ${a.subType || ''} ${a.roleHint || ''}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                if (onlyMatching) {
                    const hint = String(a.roleHint || '').toLowerCase();
                    if (!preferred.includes(hint)) return false;
                }
                return true;
            });
            countEl.textContent = String(filtered.length);
            if (filtered.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-span-full text-center py-16 text-slate-600 text-[10px] uppercase font-black tracking-widest">
                        <i class="fa-solid fa-inbox text-3xl mb-3 opacity-40"></i>
                        <div>Brak pasujących assetów.</div>
                        <div class="text-slate-700 mt-2 text-[9px]">Odznacz "Tylko pasujące role" lub wyczyść filtry.</div>
                    </div>
                `;
                return;
            }
            gridEl.innerHTML = filtered.map(a => {
                const isSel = parseInt(a.id, 10) === currentId;
                const thumb = a.previewUrl
                    ? `<img src="${this._esc(a.previewUrl)}" alt="" class="w-full h-full object-cover" loading="lazy">`
                    : `<div class="w-full h-full flex items-center justify-center"><i class="fa-solid fa-image text-slate-700 text-2xl"></i></div>`;
                return `
                    <button type="button" class="picker-card group relative bg-black/40 border ${isSel ? 'border-violet-500' : 'border-white/5'} rounded-2xl overflow-hidden hover:border-violet-500/70 transition flex flex-col text-left" data-asset-id="${parseInt(a.id, 10)}">
                        <div class="aspect-square bg-black/60 overflow-hidden">${thumb}</div>
                        <div class="p-2.5 flex-1">
                            <div class="text-[10px] font-black text-white truncate">${this._esc(a.asciiKey || '—')}</div>
                            <div class="text-[8px] text-slate-500 uppercase truncate mt-0.5">${this._esc((a.category || '').toUpperCase())}${a.subType ? ' · ' + this._esc(a.subType) : ''}</div>
                            <div class="text-[7px] text-violet-500/70 uppercase truncate mt-1 tracking-widest">${this._esc((a.roleHint || '').toLowerCase())}</div>
                        </div>
                        ${isSel ? '<div class="absolute top-2 right-2 w-6 h-6 bg-violet-500 rounded-full flex items-center justify-center shadow-lg"><i class="fa-solid fa-check text-white text-[10px]"></i></div>' : ''}
                    </button>
                `;
            }).join('');
        };

        const close = () => host.remove();
        const apply = (assetId) => {
            if (hidden) hidden.value = String(parseInt(assetId, 10) || 0);
            this._refreshRowVisual(row);
            close();
        };

        host.addEventListener('click', (e) => {
            if (e.target === host) close();
            const closeBtn = e.target.closest('.picker-close');
            if (closeBtn) { close(); return; }
            const clearBtn = e.target.closest('.picker-clear');
            if (clearBtn) { apply(0); return; }
            const card = e.target.closest('.picker-card');
            if (card) { apply(card.dataset.assetId); return; }
        });
        searchEl.addEventListener('input', renderGrid);
        catEl.addEventListener('change', renderGrid);
        onlyMatchingEl.addEventListener('change', renderGrid);
        document.addEventListener('keydown', function escHandler(ev) {
            if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); }
        });

        renderGrid();
        setTimeout(() => searchEl.focus(), 50);
    },

    toAutoSlug(value, prefix) {
        const polishMap = {
            'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
            'Ą': 'A', 'Ć': 'C', 'Ę': 'E', 'Ł': 'L', 'Ń': 'N', 'Ó': 'O', 'Ś': 'S', 'Ź': 'Z', 'Ż': 'Z'
        };

        const asciiBase = (value || '')
            .split('')
            .map(ch => polishMap[ch] || ch)
            .join('')
            .replace(/\s+/g, '_')
            .replace(/[^a-zA-Z0-9_]/g, '')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toUpperCase();

        return asciiBase ? `${prefix}${asciiBase}` : '';
    },

    bindGroupAutoSlug() {
        const groupNameInput = document.getElementById('mod-group-name');
        const groupAsciiInput = document.getElementById('mod-group-ascii');
        if (!groupNameInput || !groupAsciiInput) return;

        groupNameInput.addEventListener('input', () => {
            const groupId = parseInt(document.getElementById('mod-group-id')?.value, 10) || 0;
            if (groupId !== 0) return;
            groupAsciiInput.value = this.toAutoSlug(groupNameInput.value, 'GRP_');
        });
    },

    bindOptionAutoSlug(row) {
        const optionNameInput = row.querySelector('.opt-name');
        const optionAsciiInput = row.querySelector('.opt-ascii');
        const optionIdInput = row.querySelector('.opt-id');
        if (!optionNameInput || !optionAsciiInput || !optionIdInput) return;

        optionNameInput.addEventListener('input', () => {
            const optionId = parseInt(optionIdInput.value, 10) || 0;
            if (optionId !== 0) return;
            optionAsciiInput.value = this.toAutoSlug(optionNameInput.value, 'OPT_');
        });
    },

    async init() {
        if (window.StudioState.products.length === 0) {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', { action: 'get_recipes_init' });
            if (result.success && result.data) {
                window.StudioState.products = result.data.products || [];
            } else {
                console.warn("[ModifierInspector] Nie udało się pobrać słownika magazynu.", result.message);
            }
        }
        if (window.StudioState.warehouseItems.length === 0) {
            const whResult = await window.WarehouseApi.stockList();
            if (whResult.success && Array.isArray(whResult.data)) {
                window.StudioState.warehouseItems = whResult.data;
            } else {
                console.warn("[ModifierInspector] Nie udało się pobrać surowców z magazynu (GET_STOCK).", whResult.message);
            }
        }
        await this.loadModifiersFromDB();
        await this.loadCompactAssets();
    },

    async loadModifiersFromDB() {
        const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', { action: 'get_modifiers_full' });
        if (result.success === true && result.data) {
            window.StudioState.modifierGroups = result.data;
        } else {
            console.error("[ModifierInspector] Błąd pobierania modyfikatorów z bazy:", result.message);
        }
    },

    renderInit() {
        const container = document.getElementById('modifiers-view');
        if (!container) return;
        
        container.innerHTML = `
        <header class="h-20 border-b border-white/5 bg-black/60 flex items-center justify-between px-10 sticky top-0 z-50 backdrop-blur-xl">
            <div class="flex items-center gap-2 text-[9px] font-black uppercase text-slate-500">
                <span>Modyfikatory</span>
                <i class="fa-solid fa-chevron-right text-[7px] opacity-30"></i>
                <span id="insp-mod-name" class="text-blue-400">Wybierz Grupę z lewej</span>
            </div>
            <button id="btn-save-modifier" onclick="window.ModifierInspector.saveModifierGroup()" class="bg-blue-600 hover:bg-blue-500 text-white h-11 px-8 rounded-xl font-black uppercase text-[11px] shadow-lg opacity-50 cursor-not-allowed transition-all">
                <i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę
            </button>
        </header>

        <div class="p-10 max-w-5xl mx-auto w-full pb-40 space-y-10">
            <input type="hidden" id="mod-group-id" value="0">
            
            <section class="grid grid-cols-2 gap-8">
                <div class="bg-[#0a0a0f]/60 p-8 rounded-3xl border border-white/10 shadow-2xl space-y-6">
                    <h4 class="text-[10px] font-black uppercase text-blue-400"><i class="fa-solid fa-sliders mr-2"></i> Konfiguracja Grupy (POS)</h4>
                    
                    <div>
                        <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Nazwa Grupy</label>
                        <input type="text" id="mod-group-name" placeholder="np. Wybierz rodzaj ciasta" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-white font-black outline-none focus:border-blue-500 transition">
                    </div>

                    <div>
                        <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Klucz Systemowy Grupy (SKU)</label>
                        <input type="text" id="mod-group-ascii" placeholder="np. GRP_CIASTO" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-white font-mono uppercase outline-none focus:border-blue-500 transition">
                        <p class="text-[7px] text-red-400/70 uppercase mt-1 font-bold"><i class="fa-solid fa-lock mr-1"></i> Zablokowane na stałe po pierwszym zapisie</p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Min. Wyborów</label>
                            <input type="number" id="mod-min" value="0" min="0" class="w-full bg-black/50 border border-white/10 rounded-xl p-3 text-white text-center font-black outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Max. Wyborów</label>
                            <input type="number" id="mod-max" value="1" min="1" class="w-full bg-black/50 border border-white/10 rounded-xl p-3 text-white text-center font-black outline-none focus:border-blue-500">
                        </div>
                    </div>

                    <div class="pt-4 border-t border-white/5 space-y-4">
                        <div>
                            <label class="block text-[9px] font-black uppercase text-yellow-500 mb-2"><i class="fa-solid fa-gift mr-1"></i> Darmowy Limit (Promocja)</label>
                            <input type="number" id="mod-free-limit" value="0" min="0" class="w-full bg-yellow-900/10 border border-yellow-500/30 rounded-xl p-3 text-yellow-400 text-center font-black outline-none focus:border-yellow-500" placeholder="0 = Brak darmowych">
                            <p class="text-[7px] text-slate-500 uppercase mt-1 font-bold">np. Wpisz "2", aby pierwsze dwa składniki były za 0 PLN.</p>
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="mod-multi-qty" class="sr-only peer">
                                <div class="w-9 h-5 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500 shadow-inner"></div>
                                <span class="ml-3 text-[9px] font-black text-slate-400 uppercase tracking-wide">Pozwól na wielokrotność (np. Bekon x3)</span>
                            </label>
                        </div>
                    </div>
                    <div class="pt-4 border-t border-white/5 space-y-3">
                        <label class="block text-[9px] font-black uppercase text-cyan-400 mb-2"><i class="fa-solid fa-globe mr-1"></i> Panel Publikacji</label>
                        <select id="mod-publication-status" class="w-full bg-black/50 border border-white/10 rounded-xl p-3 text-white text-[10px] font-black uppercase outline-none focus:border-cyan-500 transition">
                            <option value="Draft">Draft</option>
                            <option value="Live">Live</option>
                            <option value="Archived">Archived</option>
                        </select>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">validFrom</label>
                                <input type="datetime-local" id="mod-valid-from" class="w-full bg-black/50 border border-white/10 rounded-xl p-2 text-white text-[10px] outline-none focus:border-cyan-500 transition">
                            </div>
                            <div>
                                <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">validTo</label>
                                <input type="datetime-local" id="mod-valid-to" class="w-full bg-black/50 border border-white/10 rounded-xl p-2 text-white text-[10px] outline-none focus:border-cyan-500 transition">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-blue-900/10 to-transparent p-8 rounded-3xl border border-blue-500/20 flex flex-col justify-center items-center text-center">
                    <i class="fa-solid fa-link text-4xl text-blue-500 mb-4 opacity-50"></i>
                    <h3 class="text-[12px] font-black uppercase text-white mb-2">Bliźniak Cyfrowy Modyfikatorów</h3>
                    <p class="text-[9px] text-slate-400 font-bold uppercase leading-relaxed mb-4">Rozwiń "Zaawansowane" przy opcji, aby powiązać ją z magazynem (Dodaj / Usuń Surowiec).</p>
                    <div class="flex gap-2 text-[8px] font-bold uppercase">
                        <span class="bg-green-900/30 text-green-400 px-2 py-1 rounded border border-green-500/30">ADD (Dodaje do Food Costu)</span>
                        <span class="bg-red-900/30 text-red-400 px-2 py-1 rounded border border-red-500/30">REMOVE (Odejmuje z Receptury)</span>
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <div id="modifier-items-list" class="grid grid-cols-1 gap-3">
                    <p class="text-center py-10 text-slate-600 text-[9px] uppercase font-bold tracking-widest">Wybierz grupę z listy po lewej stronie</p>
                </div>
            </section>
        </div>
        `;

        this.bindGroupAutoSlug();
    },

    async renderGroupList() {
        const container = document.getElementById('dynamic-tree-container');
        if(!container) return;

        const groups = window.StudioState.modifierGroups || [];
        
        if(groups.length === 0) {
            container.innerHTML = `<button onclick="window.ModifierInspector.createNewGroup().catch(()=>{})" class="w-full mb-4 py-3 bg-green-900/40 text-green-400 border border-green-500/30 rounded flex items-center justify-center hover:bg-green-600 hover:text-white transition text-[10px] font-black uppercase"><i class="fa-solid fa-plus mr-2"></i> Nowa Grupa</button>` + 
            '<div class="text-center mt-10 text-slate-500 font-bold text-[10px] uppercase">Brak zapisanych grup w bazie.</div>'; 
            return; 
        }

        container.innerHTML = `<button onclick="window.ModifierInspector.createNewGroup().catch(()=>{})" class="w-full mb-4 py-3 bg-green-900/40 text-green-400 border border-green-500/30 rounded flex items-center justify-center hover:bg-green-600 hover:text-white transition text-[10px] font-black uppercase"><i class="fa-solid fa-plus mr-2"></i> Nowa Grupa</button>` + 
        groups.map(g => `
            <div onclick="window.ModifierInspector.selectGroup(${g.id}).catch(()=>{})" class="p-4 bg-white/5 border border-white/5 rounded-xl mb-2 cursor-pointer hover:border-blue-500/50 transition flex justify-between items-center group ${g.id === window.StudioState.activeModGroupId ? 'border-blue-500 bg-blue-900/10' : ''}">
                <div><h3 class="text-[11px] font-black uppercase text-white group-hover:text-blue-400 transition">${g.name}</h3></div>
                <i class="fa-solid fa-chevron-right text-[10px] text-slate-700"></i>
            </div>`).join('');
    },

    async createNewGroup() {
        window.StudioState.activeModGroupId = 0;
        this.renderGroupList(); 
        
        document.getElementById('insp-mod-name').innerText = "Nowa Grupa";
        document.getElementById('mod-group-id').value = "0";
        document.getElementById('mod-group-name').value = "";
        
        // ZEROWANIE I ODBLOKOWANIE SKU GRUPY
        const asciiInput = document.getElementById('mod-group-ascii');
        if(asciiInput) {
            asciiInput.value = "";
            asciiInput.disabled = false;
            asciiInput.classList.remove('opacity-50', 'cursor-not-allowed');
        }

        document.getElementById('mod-min').value = "0";
        document.getElementById('mod-max').value = "1";
        document.getElementById('mod-free-limit').value = "0";
        document.getElementById('mod-multi-qty').checked = false;
        document.getElementById('mod-publication-status').value = "Draft";
        document.getElementById('mod-valid-from').value = "";
        document.getElementById('mod-valid-to').value = "";
        document.getElementById('btn-save-modifier').classList.remove('opacity-50', 'cursor-not-allowed');
        
        await this.renderModifierItems([]);
        this.addOptionRow(); 
        this.applyFranchiseShieldForGroup(false);
    },

    async selectGroup(id) {
        window.StudioState.activeModGroupId = id;
        this.renderGroupList(); 

        const group = window.StudioState.modifierGroups.find(g => g.id === id);
        if(!group) return;

        document.getElementById('insp-mod-name').innerText = group.name;
        document.getElementById('mod-group-id').value = group.id;
        document.getElementById('mod-group-name').value = group.name;

        // USTAWIENIE I ZABLOKOWANIE SKU GRUPY (JEŚLI ISTNIEJE W BAZIE)
        const asciiInput = document.getElementById('mod-group-ascii');
        if(asciiInput) {
            asciiInput.value = group.asciiKey || '';
            if (id > 0) {
                asciiInput.disabled = true;
                asciiInput.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                asciiInput.disabled = false;
                asciiInput.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        document.getElementById('mod-min').value = group.min || 0;
        document.getElementById('mod-max').value = group.max || 1;
        document.getElementById('mod-free-limit').value = group.freeLimit || 0;
        document.getElementById('mod-multi-qty').checked = !!group.multiQty;
        document.getElementById('mod-publication-status').value = group.publicationStatus || 'Draft';
        document.getElementById('mod-valid-from').value = group.validFrom || '';
        document.getElementById('mod-valid-to').value = group.validTo || '';
        document.getElementById('btn-save-modifier').classList.remove('opacity-50', 'cursor-not-allowed');

        const itemsToRender = group.options || [];
        await this.renderModifierItems(itemsToRender);
        this.applyFranchiseShieldForGroup(!!group.isLockedByHq);
    },

    async renderModifierItems(items) {
        await this.loadCompactAssets();
        const list = document.getElementById('modifier-items-list');
        let html = `
            <div class="flex items-center justify-between bg-blue-900/20 border border-blue-500/30 p-4 rounded-2xl mb-4 shadow-lg">
               <div class="flex flex-col">
                   <span class="text-[9px] font-black text-blue-400 uppercase tracking-widest"><i class="fa-solid fa-bolt mr-2"></i>Szybka zmiana cen</span>
               </div>
               <div class="flex items-center gap-2">
                   <input type="number" id="quick-mod-price" step="0.01" class="w-20 bg-black p-2 text-[11px] font-bold rounded-xl border border-blue-500/30 text-right text-white outline-none" placeholder="0.00">
                   <button onclick="window.ModifierInspector.quickAdjust('add')" class="bg-blue-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-blue-500 transition shadow"><i class="fa-solid fa-plus text-[10px]"></i></button>
                   <button onclick="window.ModifierInspector.quickAdjust('sub')" class="bg-red-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-red-500 transition shadow"><i class="fa-solid fa-minus text-[10px]"></i></button>
                   <button onclick="window.ModifierInspector.quickAdjust('set')" class="bg-green-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-green-500 transition shadow"><i class="fa-solid fa-equals text-[10px]"></i></button>
               </div>
            </div>

            <div id="modifier-creator-slot" class="hidden mb-4"></div>
            
            <div class="grid grid-cols-12 gap-2 px-6 text-[9px] font-black uppercase text-slate-500 tracking-wider mb-2">
                <div class="col-span-4">Nazwa (POS)</div>
                <div class="col-span-4">Klucz Systemowy (SKU)</div>
                <div class="col-span-3 text-center">Macierz Cenowa (PLN)</div>
                <div class="col-span-1"></div>
            </div>
            <div id="actual-options-list" class="flex flex-col gap-2"></div>
        `;
        list.innerHTML = html;
        items.forEach(item => this.addOptionRow(item));

        const addBtn = document.createElement('button');
        addBtn.id = 'btn-add-option-row';
        addBtn.className = 'w-full mt-4 py-5 border-2 border-dashed border-green-500/20 rounded-2xl text-[10px] font-black uppercase text-slate-500 hover:text-green-400 hover:border-green-500/50 transition flex items-center justify-center gap-2';
        addBtn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> Kreator Nowego Dodatku';
        addBtn.onclick = () => window.ModifierInspector.openCreatorPanel();
        list.appendChild(addBtn);
    },

    addOptionRow(opt = {}) {
        const list = document.getElementById('actual-options-list');
        if (!list) return;

        const id = opt.id || 0;
        const name = opt.name || '';
        const asciiKey = opt.asciiKey || '';
        const priceTiers = opt.priceTiers || [];
        const posTier = priceTiers.find(t => t.channel === 'POS');
        const takeawayTier = priceTiers.find(t => t.channel === 'Takeaway');
        const deliveryTier = priceTiers.find(t => t.channel === 'Delivery');
        const pricePos = posTier ? parseFloat(posTier.price).toFixed(2) : (opt.price !== undefined ? parseFloat(opt.price).toFixed(2) : '0.00');
        const priceTakeaway = takeawayTier ? parseFloat(takeawayTier.price).toFixed(2) : (opt.priceTakeaway !== undefined ? parseFloat(opt.priceTakeaway).toFixed(2) : '0.00');
        const priceDelivery = deliveryTier ? parseFloat(deliveryTier.price).toFixed(2) : (opt.priceDelivery !== undefined ? parseFloat(opt.priceDelivery).toFixed(2) : '0.00');
        const isEdit = id > 0;

        const hasVisualImpact = opt.hasVisualImpact !== undefined ? !!opt.hasVisualImpact : true;
        const layerAid = opt.layerTopDownAssetId ? parseInt(opt.layerTopDownAssetId, 10) : 0;
        const heroAid = opt.modifierHeroAssetId ? parseInt(opt.modifierHeroAssetId, 10) : 0;
        const layerPickerHtml = this._buildPickerButtonHtml('layer_top_down', layerAid);
        const heroPickerHtml  = this._buildPickerButtonHtml('modifier_hero', heroAid);
        const mapperHref = asciiKey
            ? `../online_studio/index.html?tab=magic&modifier=${encodeURIComponent(asciiKey)}`
            : '#';

        const isDefault = opt.isDefault ? 'checked' : '';
        const actionType = opt.actionType || 'NONE';
        const linkedSku = opt.linkedWarehouseSku || opt.sku || '';
        const qty = opt.linkedQuantity !== undefined ? opt.linkedQuantity : (opt.qty || '');

        const warehouseSource = window.StudioState.warehouseItems.length
            ? window.StudioState.warehouseItems
            : window.StudioState.products;
        let skuOptions = '<option value="">-- Wybierz surowiec --</option>';
        warehouseSource.forEach(p => {
            const selected = p.sku === linkedSku ? 'selected' : '';
            const baseUnit = p.base_unit || '';
            skuOptions += `<option value="${p.sku}" data-base-unit="${baseUnit}" ${selected}>${p.name} (${p.sku})</option>`;
        });

        const row = document.createElement('div');
        row.className = 'mod-option-row flex flex-col bg-white/5 p-3 rounded-xl border border-white/5 transition-all group';
        row.innerHTML = `
            <input type="hidden" class="opt-id" value="${id}">
            
            <div class="grid grid-cols-12 gap-2 items-center">
                <div class="col-span-4 flex items-center gap-3 lock-opt-name">
                    <i class="fa-solid fa-grip-vertical text-slate-700 cursor-grab opacity-50 hover:opacity-100"></i>
                    <input type="text" class="opt-name w-full bg-transparent text-white font-black text-[11px] outline-none focus:text-blue-400" placeholder="np. Ciasto Grube" value="${name}">
                </div>
                <div class="col-span-4 flex flex-col gap-1 lock-opt-name">
                    <input type="text" class="opt-ascii w-full bg-black/50 border border-white/10 text-slate-400 rounded p-2 text-[10px] focus:border-blue-500 outline-none font-mono uppercase ${isEdit ? 'cursor-not-allowed opacity-50' : ''}" placeholder="OPT_GRUBE" value="${asciiKey}" ${isEdit ? 'disabled' : ''}>
                </div>
                <div class="col-span-3 flex items-center justify-end gap-2">
                    <div class="grid grid-cols-3 gap-1 w-full">
                        <div class="flex flex-col gap-1">
                            <span class="text-[7px] font-black text-slate-500 text-center">POS</span>
                            <input type="number" step="0.01" class="opt-price-tier opt-price-pos bg-black/40 p-1.5 rounded text-blue-400 w-full text-right font-black border border-white/5 outline-none transition-colors text-[10px]" placeholder="0.00" value="${pricePos}">
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[7px] font-black text-slate-500 text-center">TAK</span>
                            <input type="number" step="0.01" class="opt-price-tier opt-price-takeaway bg-black/40 p-1.5 rounded text-blue-400 w-full text-right font-black border border-white/5 outline-none transition-colors text-[10px]" placeholder="0.00" value="${priceTakeaway}">
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[7px] font-black text-slate-500 text-center">DEL</span>
                            <input type="number" step="0.01" class="opt-price-tier opt-price-delivery bg-black/40 p-1.5 rounded text-blue-400 w-full text-right font-black border border-white/5 outline-none transition-colors text-[10px]" placeholder="0.00" value="${priceDelivery}">
                        </div>
                    </div>
                </div>
                <div class="col-span-1 text-right flex flex-col items-end gap-2 lock-opt-warehouse">
                    <button type="button" onclick="this.closest('.mod-option-row').remove()" class="opt-remove-btn text-slate-600 hover:text-red-500 transition px-2" title="Usuń z grupy"><i class="fa-solid fa-trash-can"></i></button>
                    <button type="button" onclick="window.ModifierInspector.toggleAdvanced(this)" class="opt-advanced-btn text-[8px] uppercase font-bold text-slate-500 hover:text-blue-400 transition" title="Logika Magazynowa"><i class="fa-solid fa-gear"></i></button>
                </div>
            </div>

            <div class="advanced-panel hidden mt-3 pt-3 border-t border-white/5 grid grid-cols-12 gap-4">
                <div class="col-span-3 flex items-center gap-2">
                    <input type="checkbox" class="opt-default w-4 h-4 rounded border-white/10 bg-black/50" ${isDefault}>
                    <label class="text-[8px] font-black uppercase text-slate-400">Domyślnie wybrane na POS</label>
                </div>
                
                <div class="col-span-3 lock-opt-warehouse">
                    <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">Akcja Magazynowa</label>
                    <select class="opt-action w-full bg-black/50 border border-white/10 text-white text-[9px] rounded p-2 outline-none cursor-pointer" onchange="window.ModifierInspector.handleActionChange(this)">
                        <option value="NONE" ${actionType==='NONE'?'selected':''}>Tylko Tekst (NONE)</option>
                        <option value="ADD" ${actionType==='ADD'?'selected':''}>Dodaj Surowiec (ADD)</option>
                        <option value="REMOVE" ${actionType==='REMOVE'?'selected':''}>Usuń z receptury (REMOVE)</option>
                    </select>
                </div>

                <div class="col-span-4 lock-opt-warehouse">
                    <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">Powiązany Surowiec</label>
                    <select class="opt-sku w-full bg-black/50 border border-white/10 text-white text-[9px] rounded p-2 outline-none font-mono ${actionType==='NONE' ? 'opacity-30 cursor-not-allowed' : ''}" ${actionType==='NONE' ? 'disabled' : ''}>
                        ${skuOptions}
                    </select>
                </div>

                <div class="col-span-2 lock-opt-warehouse">
                    <label class="block text-[8px] font-black uppercase text-slate-500 mb-1">linkedQuantity (ułamek)</label>
                    <input type="number" step="0.001" class="opt-qty w-full bg-black/50 border border-white/10 text-white text-[9px] rounded p-2 outline-none text-center ${actionType!=='ADD' ? 'opacity-30 cursor-not-allowed' : ''}" placeholder="0.000" value="${qty}" ${actionType!=='ADD' ? 'disabled' : ''}>
                </div>

                <div class="col-span-12 pt-4 mt-2 border-t border-violet-500/25 visual-mod-panel lock-opt-visual">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-[9px] font-black uppercase text-violet-400 tracking-wider"><i class="fa-solid fa-layer-group mr-2"></i>Surface — wizualne sloty</div>
                        <span class="vis-status-badge text-[8px] font-black uppercase tracking-widest px-2 py-1 rounded-full border inline-flex items-center gap-1 bg-slate-800 text-slate-500 border-white/10"><i class="fa-solid fa-circle text-[8px]"></i> —</span>
                    </div>
                    <label class="flex items-center gap-3 mb-4 cursor-pointer">
                        <input type="checkbox" class="opt-visual-impact w-4 h-4 rounded border-white/10 bg-black/50" ${hasVisualImpact ? 'checked' : ''}>
                        <span class="text-[8px] font-black uppercase text-slate-400">Ma wpływ wizualny (The Surface / warstwy)</span>
                    </label>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="asset-picker-slot">${layerPickerHtml}</div>
                        <div class="asset-picker-slot">${heroPickerHtml}</div>
                        <div class="flex flex-col items-center justify-center">
                            <label class="block text-[8px] font-black uppercase text-slate-500 mb-1 self-start">Podgląd LIVE</label>
                            <div class="vis-preview-wrap w-full bg-black/30 border border-white/5 rounded-xl p-2 flex items-center justify-center">
                                <div class="vis-preview-box relative w-full aspect-square max-w-[120px] rounded-full overflow-hidden shadow-inner"></div>
                            </div>
                        </div>
                    </div>
                    <p class="text-[7px] text-slate-600 mt-3 leading-relaxed">
                        Zapis: przycisk <strong class="text-slate-500">Zapisz Grupę</strong>.
                        ${asciiKey ? `<a href="${mapperHref}" target="_blank" rel="noopener" class="text-violet-400 hover:text-violet-300 ml-2 font-bold uppercase">Modifier Mapper (Online Studio) →</a>` : '<span class="text-slate-700 ml-2">(SKU opcji — wtedy link do Mapper)</span>'}
                    </p>
                </div>
            </div>
        `;
        list.appendChild(row);
        this.bindOptionAutoSlug(row);

        row.querySelectorAll('.opt-asset-picker').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const role = btn.dataset.role;
                window.ModifierInspector.openAssetPickerModal(row, role);
            });
        });
        const impactBox = row.querySelector('.opt-visual-impact');
        if (impactBox) {
            impactBox.addEventListener('change', () => {
                window.ModifierInspector._refreshRowBadge(row);
                window.ModifierInspector._refreshRowPreview(row);
            });
        }
        this._refreshRowVisual(row);

        const group = window.StudioState.modifierGroups.find(g => g.id === window.StudioState.activeModGroupId);
        if (group && group.isLockedByHq) this.applyFranchiseShieldForGroup(true);
    },

    toggleAdvanced(btn) {
        const panel = btn.closest('.mod-option-row').querySelector('.advanced-panel');
        panel.classList.toggle('hidden');
        if(!panel.classList.contains('hidden') && window.StudioState.products.length === 0) {
            this.init(); 
        }
    },

    handleActionChange(selectEl) {
        const row = selectEl.closest('.advanced-panel');
        const skuSelect = row.querySelector('.opt-sku');
        const qtyInput = row.querySelector('.opt-qty');
        const action = selectEl.value;

        if (action === 'NONE') {
            skuSelect.disabled = true; skuSelect.classList.add('opacity-30', 'cursor-not-allowed'); skuSelect.value = "";
            qtyInput.disabled = true; qtyInput.classList.add('opacity-30', 'cursor-not-allowed'); qtyInput.value = "";
        } else if (action === 'REMOVE') {
            skuSelect.disabled = false; skuSelect.classList.remove('opacity-30', 'cursor-not-allowed');
            qtyInput.disabled = true; qtyInput.classList.add('opacity-30', 'cursor-not-allowed'); qtyInput.value = "";
        } else if (action === 'ADD') {
            skuSelect.disabled = false; skuSelect.classList.remove('opacity-30', 'cursor-not-allowed');
            qtyInput.disabled = false; qtyInput.classList.remove('opacity-30', 'cursor-not-allowed');
        }
    },

    applyFranchiseShieldForGroup(isLocked) {
        const groupSelectors = [
            '#mod-group-name',
            '#mod-group-ascii',
            '#mod-min',
            '#mod-max',
            '#mod-free-limit',
            '#mod-multi-qty',
            '#mod-publication-status',
            '#mod-valid-from',
            '#mod-valid-to'
        ];

        groupSelectors.forEach(selector => {
            const el = document.querySelector(selector);
            if (!el) return;
            el.disabled = !!isLocked;
            if (isLocked) {
                el.classList.add('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            } else {
                el.classList.remove('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            }
        });

        document.querySelectorAll('.mod-option-row').forEach(row => {
            row.querySelectorAll('.lock-opt-name, .lock-opt-warehouse, .lock-opt-visual').forEach(block => {
                if (isLocked) block.classList.add('pointer-events-none', 'opacity-50');
                else block.classList.remove('pointer-events-none', 'opacity-50');
            });

            row.querySelectorAll('.opt-name, .opt-ascii, .opt-action, .opt-sku, .opt-qty, .opt-default, .opt-visual-impact, .opt-asset-picker').forEach(input => {
                if (input) input.disabled = !!isLocked;
            });
        });

        const addBtn = document.getElementById('btn-add-option-row');
        if (addBtn) {
            addBtn.disabled = !!isLocked;
            if (isLocked) addBtn.classList.add('opacity-50', 'pointer-events-none', 'cursor-not-allowed');
            else addBtn.classList.remove('opacity-50', 'pointer-events-none', 'cursor-not-allowed');
        }
    },

    openCreatorPanel() {
        const slot = document.getElementById('modifier-creator-slot');
        if (!slot) return;

        const warehouseSource = window.StudioState.warehouseItems.length
            ? window.StudioState.warehouseItems
            : window.StudioState.products;

        const skuOptionsHtml = '<option value="">-- Wybierz surowiec --</option>' +
            warehouseSource.map(p =>
                `<option value="${p.sku}" data-base-unit="${p.base_unit || ''}">${p.name} (${p.sku})</option>`
            ).join('');

        const activeGroup = window.StudioState.modifierGroups.find(g => g.id === window.StudioState.activeModGroupId);
        const prefilledGroup = activeGroup ? activeGroup.name : '';

        const datalistOptions = window.StudioState.modifierGroups.map(g =>
            `<option value="${g.name}">`
        ).join('');

        slot.innerHTML = `
        <div class="bg-gradient-to-br from-green-900/10 to-transparent border border-green-500/20 rounded-3xl p-6 space-y-5 shadow-[0_0_20px_rgba(34,197,94,0.05)]">
            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                <h4 class="text-[10px] font-black uppercase text-green-400 tracking-widest flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle"></i> Kreator Nowego Dodatku / Modyfikatora
                </h4>
                <button onclick="window.ModifierInspector.closeCreatorPanel()" class="text-slate-500 hover:text-red-400 transition w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-900/20">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[8px] font-black uppercase text-slate-500 mb-1.5">Grupa Modyfikatora</label>
                    <input list="modifier-groups-datalist" id="creator-group" value="${prefilledGroup}"
                        class="w-full bg-black/50 border border-white/10 rounded-xl p-3 text-white text-[10px] outline-none focus:border-green-500 transition"
                        placeholder="np. Sosy, Płatne Dodatki...">
                    <datalist id="modifier-groups-datalist">${datalistOptions}</datalist>
                </div>
                <div>
                    <label class="block text-[8px] font-black uppercase text-slate-500 mb-1.5">Nazwa Dodatku</label>
                    <input type="text" id="creator-name"
                        class="w-full bg-black/50 border border-white/10 rounded-xl p-3 text-white text-[10px] outline-none focus:border-green-500 transition"
                        placeholder="np. Dodatkowa Mozzarella">
                </div>
            </div>

            <div>
                <label class="block text-[8px] font-black uppercase text-blue-400 mb-1.5">
                    <i class="fa-solid fa-tags mr-1"></i> Macierz Cenowa Omnichannel (PLN)
                </label>
                <div class="grid grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[8px] font-black text-slate-500 uppercase text-center">POS</span>
                        <input type="number" step="0.01" min="0" id="creator-price-pos"
                            class="bg-black/50 border border-white/10 rounded-xl p-3 text-blue-400 text-center font-black text-[11px] outline-none focus:border-blue-500 transition"
                            placeholder="0.00" value="0.00">
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[8px] font-black text-slate-500 uppercase text-center">TAKEAWAY</span>
                        <input type="number" step="0.01" min="0" id="creator-price-takeaway"
                            class="bg-black/50 border border-white/10 rounded-xl p-3 text-blue-400 text-center font-black text-[11px] outline-none focus:border-blue-500 transition"
                            placeholder="0.00" value="0.00">
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[8px] font-black text-slate-500 uppercase text-center">DELIVERY</span>
                        <input type="number" step="0.01" min="0" id="creator-price-delivery"
                            class="bg-black/50 border border-white/10 rounded-xl p-3 text-blue-400 text-center font-black text-[11px] outline-none focus:border-blue-500 transition"
                            placeholder="0.00" value="0.00">
                    </div>
                </div>
            </div>

            <div class="border-t border-white/5 pt-5 space-y-4">
                <label class="block text-[8px] font-black uppercase text-amber-400">
                    <i class="fa-solid fa-boxes-stacked mr-1"></i> Powiązanie z Magazynem (Food Cost / Bliźniak Cyfrowy)
                </label>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[7px] font-black uppercase text-slate-500 mb-1">Typ Akcji</label>
                        <select id="creator-action" onchange="window.ModifierInspector.handleCreatorActionChange(this)"
                            class="w-full bg-black/50 border border-white/10 rounded-xl p-2.5 text-white text-[9px] outline-none focus:border-amber-500 transition cursor-pointer">
                            <option value="NONE">Neutralny (NONE)</option>
                            <option value="ADD">+ Dodaje surowiec (ADD)</option>
                            <option value="REMOVE">− Usuwa surowiec (REMOVE)</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-[7px] font-black uppercase text-slate-500 mb-1">Surowiec z Magazynu</label>
                        <select id="creator-sku" onchange="window.ModifierInspector.onCreatorSkuChange(this)"
                            class="w-full bg-black/50 border border-white/10 rounded-xl p-2.5 text-white text-[9px] font-mono outline-none focus:border-amber-500 transition opacity-30 cursor-not-allowed" disabled>
                            ${skuOptionsHtml}
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[7px] font-black uppercase text-slate-500 mb-1">Ilość</label>
                        <input type="number" step="0.001" min="0" id="creator-qty"
                            class="w-full bg-black/50 border border-white/10 rounded-xl p-2.5 text-white text-[9px] text-center outline-none focus:border-amber-500 transition opacity-30 cursor-not-allowed"
                            placeholder="0.000" disabled>
                    </div>
                    <div>
                        <label class="block text-[7px] font-black uppercase text-slate-500 mb-1">Jednostka</label>
                        <select id="creator-unit" class="w-full bg-black/50 border border-white/10 rounded-xl p-2.5 text-white text-[9px] outline-none focus:border-amber-500 transition opacity-30 cursor-not-allowed" disabled>
                            <option value="g">g</option>
                            <option value="ml">ml</option>
                            <option value="kg">kg</option>
                            <option value="l">l</option>
                            <option value="szt" selected>szt</option>
                            <option value="por">por</option>
                            <option value="opak">opak</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[7px] font-black uppercase text-slate-500 mb-1">Ubytek (%)</label>
                        <input type="number" step="0.1" min="0" max="100" id="creator-waste"
                            class="w-full bg-black/50 border border-white/10 rounded-xl p-2.5 text-white text-[9px] text-center outline-none focus:border-amber-500 transition"
                            placeholder="0" value="0">
                    </div>
                    <div class="flex flex-col justify-end">
                        <span id="creator-base-unit-label" class="text-[8px] text-amber-400/70 font-bold uppercase text-center py-2.5 bg-amber-900/10 rounded-xl border border-amber-500/10 tracking-wider">
                            JN. BAZOWA
                        </span>
                        <span id="creator-base-unit-info" class="text-[11px] text-amber-300 font-black uppercase text-center mt-1">—</span>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-white/5 flex gap-3">
                <button id="btn-save-modifier-draft" onclick="window.ModifierInspector.saveModifier()"
                    class="flex-1 bg-green-600 hover:bg-green-500 active:scale-95 text-white font-black text-[11px] uppercase tracking-widest py-3 rounded-xl shadow-[0_0_15px_rgba(34,197,94,0.3)] transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Zapisz Modyfikator
                </button>
                <button onclick="window.ModifierInspector.closeCreatorPanel()"
                    class="px-6 bg-white/5 border border-white/10 text-slate-400 hover:text-white font-black text-[10px] uppercase rounded-xl transition">
                    Anuluj
                </button>
            </div>
        </div>
        `;

        slot.classList.remove('hidden');
        slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('creator-name')?.focus();
    },

    closeCreatorPanel() {
        const slot = document.getElementById('modifier-creator-slot');
        if (slot) slot.classList.add('hidden');
    },

    handleCreatorActionChange(selectEl) {
        const action = selectEl.value;
        const skuSelect = document.getElementById('creator-sku');
        const qtyInput = document.getElementById('creator-qty');
        const unitSelect = document.getElementById('creator-unit');

        if (action === 'NONE') {
            [skuSelect, qtyInput, unitSelect].forEach(el => {
                if (!el) return;
                el.disabled = true;
                el.classList.add('opacity-30', 'cursor-not-allowed');
            });
            if (skuSelect) skuSelect.value = '';
            document.getElementById('creator-base-unit-info').textContent = '—';
        } else {
            skuSelect.disabled = false;
            skuSelect.classList.remove('opacity-30', 'cursor-not-allowed');
            unitSelect.disabled = false;
            unitSelect.classList.remove('opacity-30', 'cursor-not-allowed');

            if (action === 'REMOVE') {
                qtyInput.disabled = true;
                qtyInput.classList.add('opacity-30', 'cursor-not-allowed');
            } else {
                qtyInput.disabled = false;
                qtyInput.classList.remove('opacity-30', 'cursor-not-allowed');
            }
        }
    },

    onCreatorSkuChange(selectEl) {
        const selected = selectEl.options[selectEl.selectedIndex];
        const baseUnit = selected ? (selected.dataset.baseUnit || '—') : '—';
        const infoEl = document.getElementById('creator-base-unit-info');
        if (infoEl) infoEl.textContent = baseUnit;

        const unitSelect = document.getElementById('creator-unit');
        if (unitSelect && baseUnit !== '—') {
            const matchingOption = Array.from(unitSelect.options).find(o => o.value === baseUnit);
            if (matchingOption) unitSelect.value = baseUnit;
        }
    },

    async saveModifier() {
        const groupInput    = document.getElementById('creator-group');
        const nameInput     = document.getElementById('creator-name');
        const pricePos      = parseFloat(document.getElementById('creator-price-pos')?.value) || 0;
        const priceTakeaway = parseFloat(document.getElementById('creator-price-takeaway')?.value) || 0;
        const priceDelivery = parseFloat(document.getElementById('creator-price-delivery')?.value) || 0;
        const actionSelect  = document.getElementById('creator-action');
        const skuSelect     = document.getElementById('creator-sku');
        const qtyInput      = document.getElementById('creator-qty');
        const unitSelect    = document.getElementById('creator-unit');
        const wasteInput    = document.getElementById('creator-waste');

        const name = nameInput ? nameInput.value.trim() : '';
        if (!name) {
            nameInput?.focus();
            nameInput?.classList.add('border-red-500');
            setTimeout(() => nameInput?.classList.remove('border-red-500'), 2000);
            return;
        }

        const selectedSkuOpt = (skuSelect && skuSelect.selectedIndex > 0)
            ? skuSelect.options[skuSelect.selectedIndex]
            : null;

        const payload = {
            action:    'save_modifier_quick',
            groupName: groupInput ? groupInput.value.trim() : '',
            name,
            asciiKey:  this.toAutoSlug(name, 'OPT_'),
            priceTiers: [
                { channel: 'POS',      price: pricePos },
                { channel: 'Takeaway', price: priceTakeaway },
                { channel: 'Delivery', price: priceDelivery },
            ],
            warehouseLink: {
                actionType:           actionSelect ? actionSelect.value : 'NONE',
                warehouseSku:         skuSelect    ? skuSelect.value    : '',
                warehouseSkuBaseUnit: selectedSkuOpt ? (selectedSkuOpt.dataset.baseUnit || '') : '',
                quantity:             parseFloat(qtyInput?.value)  || 0,
                unit:                 unitSelect  ? unitSelect.value  : 'szt',
                wastePercent:         parseFloat(wasteInput?.value) || 0,
            },
        };

        const btn = document.getElementById('btn-save-modifier-draft');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Zapisywanie...';
        }

        try {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);

            if (!result.success) {
                throw new Error(result.message || 'Błąd API.');
            }

            this.closeCreatorPanel();
            await this.loadModifiersFromDB();
            this.renderGroupList();

        } catch (err) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-2"></i> Błąd — spróbuj ponownie';
                btn.classList.replace('bg-green-600', 'bg-red-600');
                setTimeout(() => {
                    btn.innerHTML = '<i class="fa-solid fa-terminal mr-2"></i> Podgląd Draftu (Console)';
                    btn.classList.replace('bg-red-600', 'bg-green-600');
                    btn.disabled = false;
                }, 3000);
            }
            console.error('[SliceHub] SAVE_MODIFIER error:', err.message);
        }
    },

    quickAdjust(type) {
        const val = parseFloat(document.getElementById('quick-mod-price').value) || 0;
        if(val === 0 && type !== 'set') return;
        
        document.querySelectorAll('.opt-price-tier').forEach(input => {
            let current = parseFloat(input.value) || 0;
            if(type === 'add') current += val;
            if(type === 'sub') current = Math.max(0, current - val);
            if(type === 'set') current = val;
            
            if (typeof window.SliceValidator !== 'undefined') {
                const validated = window.SliceValidator.validatePrice(current);
                if (validated === null) current = 0;
            }

            input.value = current.toFixed(2);
            input.classList.add('bg-blue-500/20'); 
            setTimeout(() => input.classList.remove('bg-blue-500/20'), 500);
        });
    },

    async saveModifierGroup() {
        const btn = document.getElementById('btn-save-modifier');
        if(btn.classList.contains('opacity-50')) return;
        
        const groupId = parseInt(document.getElementById('mod-group-id').value) || 0;
        const groupName = document.getElementById('mod-group-name').value.trim();
        
        // POBIERANIE I CZYSZCZENIE SKU GRUPY
        const rawGroupAscii = document.getElementById('mod-group-ascii').value;
        const groupAsciiKey = rawGroupAscii.replace(/[^a-zA-Z0-9_-]/g, '').toUpperCase();
        
        const minSelection = parseInt(document.getElementById('mod-min').value) || 0;
        const maxSelection = parseInt(document.getElementById('mod-max').value) || 1;
        const freeLimit = parseInt(document.getElementById('mod-free-limit').value) || 0;
        const multiQty = document.getElementById('mod-multi-qty').checked;
        const publicationStatus = document.getElementById('mod-publication-status').value || 'Draft';
        const validFrom = document.getElementById('mod-valid-from').value || '';
        const validTo = document.getElementById('mod-valid-to').value || '';

        if (!groupName) { alert("Nazwa grupy jest wymagana!"); return; }
        if (!groupAsciiKey) { alert("SKU Grupy jest wymagane i nie może zawierać polskich znaków ani spacji!"); return; }
        if (minSelection > maxSelection) { alert("Minimum nie może być większe niż maksimum!"); return; }

        const options = [];
        let hasValidationError = false;

        document.querySelectorAll('.mod-option-row').forEach(row => {
            const id = parseInt(row.querySelector('.opt-id').value) || 0;
            const name = row.querySelector('.opt-name').value.trim();
            const rawAscii = row.querySelector('.opt-ascii').value;
            const cleanAscii = rawAscii.replace(/[^a-zA-Z0-9_-]/g, '').toUpperCase();
            const pricePos = parseFloat(row.querySelector('.opt-price-pos').value) || 0.00;
            const priceTakeaway = parseFloat(row.querySelector('.opt-price-takeaway').value) || 0.00;
            const priceDelivery = parseFloat(row.querySelector('.opt-price-delivery').value) || 0.00;
            
            const isDefault = row.querySelector('.opt-default').checked;
            const actionType = row.querySelector('.opt-action').value;
            const linkedSku = row.querySelector('.opt-sku').value;
            const linkedQty = parseFloat(row.querySelector('.opt-qty').value) || 0;

            if (!name || !cleanAscii) return; 

            if (typeof window.SliceValidator !== 'undefined') {
                const channelLabels = ['POS', 'Takeaway', 'Delivery'];
                [pricePos, priceTakeaway, priceDelivery].forEach((channelPrice, idx) => {
                    const validated = window.SliceValidator.validatePrice(channelPrice);
                    if (validated === null) {
                        alert(`Błąd ceny w opcji "${name}" (${channelLabels[idx]}): wartość musi być liczbą >= 0.`);
                        hasValidationError = true;
                    }
                });
            }

            if (actionType !== 'NONE' && !linkedSku) {
                alert(`Opcja "${name}" ma ustawioną akcję magazynową, ale nie wybrano surowca!`);
                hasValidationError = true;
            }

            const layerHidden = row.querySelector('.opt-asset-layer-id');
            const heroHidden  = row.querySelector('.opt-asset-hero-id');
            const vImpact = row.querySelector('.opt-visual-impact');
            const layerTopDownAssetId = layerHidden && layerHidden.value ? parseInt(layerHidden.value, 10) : null;
            const modifierHeroAssetId = heroHidden  && heroHidden.value  ? parseInt(heroHidden.value, 10)  : null;
            options.push({ 
                id,
                name,
                asciiKey: cleanAscii,
                priceTiers: [
                    { channel: 'POS', price: pricePos },
                    { channel: 'Takeaway', price: priceTakeaway },
                    { channel: 'Delivery', price: priceDelivery }
                ],
                isDefault,
                actionType,
                linkedWarehouseSku: linkedSku,
                linkedQuantity: parseFloat(linkedQty),
                hasVisualImpact: vImpact ? !!vImpact.checked : true,
                layerTopDownAssetId: layerTopDownAssetId && layerTopDownAssetId > 0 ? layerTopDownAssetId : null,
                modifierHeroAssetId: modifierHeroAssetId && modifierHeroAssetId > 0 ? modifierHeroAssetId : null
            });
        });

        if (hasValidationError) return;
        if (options.length === 0) { alert("Dodaj przynajmniej jedną poprawną opcję!"); return; }

        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> ZAPISYWANIE...';

        const payload = {
            action: 'save_modifier_group',
            groupId: groupId,
            groupAsciiKey: groupAsciiKey, // Dodane SKU Grupy do payloadu
            name: groupName,
            minSelection: minSelection,
            maxSelection: maxSelection,
            freeLimit: freeLimit,
            allowMultiQty: multiQty,
            publicationStatus: publicationStatus,
            validFrom: validFrom,
            validTo: validTo,
            options: options
        };

        try {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);

            if (result.success === true) {
                btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> ZAPISANO!';
                setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę'; }, 2000);
                
                const returnedId = result.data ? result.data.id : null;
                if(groupId === 0 && returnedId) {
                    document.getElementById('mod-group-id').value = returnedId;
                    document.getElementById('mod-group-ascii').disabled = true;
                    document.getElementById('mod-group-ascii').classList.add('opacity-50', 'cursor-not-allowed');
                }

                if (groupId === 0) {
                    const newGroupId = returnedId ? parseInt(returnedId, 10) : 0;
                    if (newGroupId > 0) {
                        window.StudioState.modifierGroups.push({
                            id: newGroupId,
                            name: groupName,
                            asciiKey: groupAsciiKey,
                            min: minSelection,
                            max: maxSelection,
                            freeLimit: freeLimit,
                            multiQty: multiQty,
                            publicationStatus: publicationStatus,
                            validFrom: validFrom,
                            validTo: validTo,
                            options: options
                        });
                        window.StudioState.activeModGroupId = newGroupId;
                    }
                } else {
                    const existingGroup = window.StudioState.modifierGroups.find(g => g.id === groupId);
                    if (existingGroup) {
                        existingGroup.name = groupName;
                        existingGroup.asciiKey = groupAsciiKey;
                        existingGroup.min = minSelection;
                        existingGroup.max = maxSelection;
                        existingGroup.freeLimit = freeLimit;
                        existingGroup.multiQty = multiQty;
                        existingGroup.publicationStatus = publicationStatus;
                        existingGroup.validFrom = validFrom;
                        existingGroup.validTo = validTo;
                        existingGroup.options = options;
                    }
                }

                window.ModifierInspector.renderGroupList();
            } else {
                alert("Błąd API: " + result.message);
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę';
            }
        } catch (error) {
            alert("Błąd połączenia z bazą danych.");
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę';
        }
    }
};

window.addEventListener('load', () => {
    window.ModifierInspector.init();

    if(window.Core && typeof window.Core.switchView === 'function') {
        const originalSwitch = window.Core.switchView;
        window.Core.switchView = function(viewId) {
            originalSwitch.call(window.Core, viewId);
            
            if (viewId === 'modifiers') {
                if(!document.getElementById('insp-mod-name')) {
                    window.ModifierInspector.renderInit();
                }
                window.ModifierInspector.renderGroupList(); 
            } else if (viewId === 'menu') {
                if (window.Core.renderTree) window.Core.renderTree(); 
            }
        };
    }
});