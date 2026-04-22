const _e = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

window.Core = {
    switchView: function(viewId) {
        console.log("[UI] Przełączanie na widok:", viewId);
        const container = document.getElementById('view-container');
        if (!container) return;
        Array.from(container.children).forEach(child => {
            if(child.id.includes('-view')) child.classList.add('hidden');
        });
        const targetView = document.getElementById(viewId + '-view');
        const bulkView = document.getElementById('bulk-inspector-view');
        if (viewId === 'bulk' && bulkView) bulkView.classList.remove('hidden');
        else if (targetView) targetView.classList.remove('hidden');
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
        const navBtn = document.getElementById('nav-' + viewId);
        if (navBtn) navBtn.classList.add('active');

        if (viewId === 'modifiers' && window.ModifierInspector) {
            window.ModifierInspector.renderInit();
            window.ModifierInspector.renderGroupList();
        }
    },
    renderTree: function() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const categories = window.StudioState?.categories || [];
        const items = window.StudioState?.items || [];
        
        window.StudioState.bulkSelectedItems = window.StudioState.bulkSelectedItems || [];

        if (categories.length === 0) {
            container.innerHTML = '<div class="text-center mt-10 text-slate-500 font-bold text-[10px] uppercase">Brak kategorii w bazie.</div>';
            return;
        }
        let html = '<div class="space-y-3">';
        categories.forEach(cat => {
            const catItems = items.filter(item => item.categoryId == cat.id);
            html += `
            <div class="bg-black/40 border border-white/5 rounded-xl overflow-hidden shadow-[0_4px_15px_rgba(0,0,0,0.5)]">
                <div class="p-3 bg-white/5 flex items-center justify-between cursor-pointer hover:bg-white/10 transition" onclick="window.Core.toggleCategory(${cat.id})">
                    <div class="flex items-center gap-2">
                        <i id="icon-cat-${cat.id}" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform"></i>
                        <span class="text-[11px] font-black uppercase text-white tracking-wider">${_e(cat.name)}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="text-[9px] font-bold text-slate-500 bg-black/50 px-2 py-0.5 rounded border border-white/5">${catItems.length} dań</span>
                        ${cat.layoutMode && cat.layoutMode !== 'legacy_list' ? `
                        <button onclick="event.stopPropagation(); window.CategoryTableEditor && window.CategoryTableEditor.open(${cat.id})" class="text-slate-600 hover:text-violet-400 text-[10px] px-1 transition" title="Układ stołu kategorii (The Table)">
                            <i class="fa-solid fa-table-cells"></i>
                        </button>` : ''}
                        <button onclick="event.stopPropagation(); window.Core.editCategory(${cat.id})" class="text-slate-600 hover:text-blue-400 text-[10px] px-1 transition" title="Edytuj kategorię (VAT)">
                            <i class="fa-solid fa-gear"></i>
                        </button>
                        <button onclick="event.stopPropagation(); window.Core.addNewItem(${cat.id})" class="text-green-400 hover:text-white text-[10px] font-black uppercase">
                            <i class="fa-solid fa-plus"></i> Nowe
                        </button>
                    </div>
                </div>
                <div id="cat-items-${cat.id}" class="flex flex-col border-t border-white/5 transition-all">
            `;
            if (catItems.length === 0) {
                html += `<div class="p-3 text-[10px] text-slate-600 italic pl-8">Kategoria jest pusta</div>`;
            } else {
                catItems.forEach(item => {
                    const posTier = item.priceTiers ? item.priceTiers.find(t => t.channel === 'POS') : null;
                    const displayPrice = posTier ? posTier.price.toFixed(2) : (item.price ? parseFloat(item.price).toFixed(2) : "0.00");
                    const statusIcon = item.isActive 
                        ? '<i class="fa-solid fa-circle-check text-green-500 text-[10px]" title="Aktywne"></i>' 
                        : '<i class="fa-solid fa-eye-slash text-red-500 text-[10px]" title="Ukryte na POS"></i>';
                    
                    const isChecked = window.StudioState.bulkSelectedItems.includes(item.id) ? 'checked' : '';

                    // M1 · thumbnail z AssetResolver::injectHeros (get_menu_tree)
                    const thumbHtml = item.imageUrl
                        ? `<img src="${_e(item.imageUrl)}" alt="" class="w-full h-full object-cover" loading="lazy" onerror="this.remove(); this.parentElement.innerHTML='<i class=\\'fa-solid fa-image text-slate-700 text-[11px]\\'></i>';">`
                        : `<i class="fa-solid fa-image text-slate-700 text-[11px]" title="Brak zdjęcia — dodaj w Asset Studio"></i>`;
                    const thumbRing = item.imageUrl
                        ? 'border-white/10'
                        : 'border-amber-500/20 bg-amber-900/10';

                    html += `
                    <div class="p-3 pl-8 flex items-center justify-between border-b border-white/5 last:border-0 hover:bg-blue-500/10 cursor-pointer transition group" data-item-id="${item.id}" data-item-sku="${_e(item.asciiKey)}">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <input type="checkbox" ${isChecked} class="w-4 h-4 rounded bg-black/50 border-white/20 cursor-pointer accent-cyan-500 shrink-0" onclick="event.stopPropagation(); window.Core.toggleBulkSelection(${item.id})">
                            <i class="fa-solid fa-grip-vertical text-slate-600 text-[10px] opacity-0 group-hover:opacity-100 transition-opacity shrink-0"></i>
                            <div class="item-thumb w-9 h-9 rounded-lg bg-black/60 border ${thumbRing} overflow-hidden flex items-center justify-center shrink-0 shadow-inner">${thumbHtml}</div>
                            <span class="text-[11px] font-bold text-slate-300 group-hover:text-blue-400 transition-colors truncate">${_e(item.name)}</span>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-[9px] text-slate-600 font-mono hidden group-hover:block transition-all">${_e(item.asciiKey)}</span>
                            <span class="text-[10px] text-yellow-500 font-bold font-mono bg-black/40 px-2 py-0.5 rounded border border-white/5">${displayPrice} PLN</span>
                            ${statusIcon}
                        </div>
                    </div>
                    `;
                });
            }
            html += `</div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
        container.querySelectorAll('[data-item-id][data-item-sku]').forEach(el => {
            el.addEventListener('click', () => window.Core.openItemEditor(parseInt(el.dataset.itemId), el.dataset.itemSku));
        });
    },
    toggleBulkSelection: function(itemId) {
        window.StudioState.bulkSelectedItems = window.StudioState.bulkSelectedItems || [];
        
        const index = window.StudioState.bulkSelectedItems.indexOf(itemId);
        if (index > -1) {
            window.StudioState.bulkSelectedItems.splice(index, 1);
        } else {
            window.StudioState.bulkSelectedItems.push(itemId);
        }
        
        const bulkView = document.getElementById('bulk-inspector-view');
        if (bulkView) {
            const spans = bulkView.querySelectorAll('span, div');
            spans.forEach(el => {
                if (el.innerText.includes('ZAZNACZONO:')) {
                    el.innerText = `ZAZNACZONO: ${window.StudioState.bulkSelectedItems.length} DAŃ`;
                }
            });
        }
        
        window.Core.renderTree(); // Odśwież widok, aby checkbox zareagował
    },
    toggleCategory: function(catId) {
        const container = document.getElementById(`cat-items-${catId}`);
        const icon = document.getElementById(`icon-cat-${catId}`);
        if (container) { container.classList.toggle('hidden'); if (icon) icon.classList.toggle('-rotate-90'); }
    },
    openItemEditor: async function(itemId, asciiKey) {
        console.log("[UI] Wybrano danie SKU:", asciiKey);
        window.Core.switchView('menu');
        
        // 1. Zasilenie listy kategorii w select (żeby menedżer miał z czego wybierać)
        const catSelect = document.getElementById('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            const categories = window.StudioState?.categories || [];
            categories.forEach(cat => {
                catSelect.add(new Option(cat.name, cat.id));
            });
        }

        // 2. Uderzenie do bazy po PEŁNE dane księgowe (Cena, VAT, Drukarka)
        try {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', { action: 'get_item_details', itemId: itemId });

            if (result.success === true && window.ItemEditor) {
                // Wstrzykujemy twarde dane z bazy do formularza po lewej stronie
                window.ItemEditor.loadItemDataToForm(result.data);
            } else {
                console.error("[UI] Błąd pobierania detali dania:", result.message);
            }
        } catch (e) { 
            console.error("[UI] Błąd komunikacji z API przy pobieraniu dania:", e); 
        }

        // 3. Zasilenie Receptur i aktualizacja nagłówka dla prawej kolumny
        const skuDisplay = document.getElementById('current-sku-display');
        if(skuDisplay) skuDisplay.innerText = "SKU: " + asciiKey;

        if(typeof window.RecipeMapper !== 'undefined') {
            window.RecipeMapper.loadItemRecipe(asciiKey);
        }
    },
    addNewItem: function(categoryId) {
        window.Core.switchView('menu');

        const catSelect = document.getElementById('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            const categories = window.StudioState?.categories || [];
            categories.forEach(cat => {
                catSelect.add(new Option(cat.name, cat.id));
            });
        }

        const cat = (window.StudioState?.categories || []).find(c => c.id == categoryId);
        const vatDineIn = cat?.defaultVatDineIn ?? 8;
        const vatTakeaway = cat?.defaultVatTakeaway ?? 5;

        if (window.ItemEditor && typeof window.ItemEditor.loadItemDataToForm === 'function') {
            window.ItemEditor.loadItemDataToForm({
                id: 0,
                categoryId: categoryId,
                name: '',
                asciiKey: '',
                isActive: true,
                vatRateDineIn: vatDineIn,
                vatRateTakeaway: vatTakeaway,
                priceMatrix: { POS: 0, Takeaway: 0, Delivery: 0 },
                kdsStationId: 'NONE',
                publicationStatus: 'Draft'
            });
        }
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
        const itemTemplates = (window.StudioState?.sceneTemplates || []).filter(t => t.kind === 'item');
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
        <div class="bg-[#0c0f1a] border border-white/10 rounded-2xl p-8 w-[520px] shadow-2xl my-auto">
            <h3 class="text-white text-sm font-black uppercase tracking-widest mb-6">${title}</h3>
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Nazwa Kategorii</label>
                    <input type="text" id="cat-modal-name" value="${name}" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:outline-none transition" placeholder="np. Pizze, Napoje, Desery...">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Domyślne Stawki VAT dla dań w tej kategorii</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[8px] text-slate-500 font-bold uppercase">Na Sali (Dine-in)</span>
                            <select id="cat-modal-vat-dinein" class="bg-black/50 border border-white/10 text-white rounded p-2.5 text-xs focus:border-blue-500 focus:outline-none transition cursor-pointer">
                                <option value="23" ${vatDI==23?'selected':''}>23%</option>
                                <option value="8" ${vatDI==8?'selected':''}>8%</option>
                                <option value="5" ${vatDI==5?'selected':''}>5%</option>
                                <option value="0" ${vatDI==0?'selected':''}>0%</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[8px] text-slate-500 font-bold uppercase">Wynos / Dostawa</span>
                            <select id="cat-modal-vat-takeaway" class="bg-black/50 border border-white/10 text-white rounded p-2.5 text-xs focus:border-blue-500 focus:outline-none transition cursor-pointer">
                                <option value="23" ${vatTA==23?'selected':''}>23%</option>
                                <option value="8" ${vatTA==8?'selected':''}>8%</option>
                                <option value="5" ${vatTA==5?'selected':''}>5%</option>
                                <option value="0" ${vatTA==0?'selected':''}>0%</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-[8px] text-slate-600 mt-1">Nowe dania dodawane do tej kategorii odziedziczą te stawki. Możesz je nadpisać per danie.</p>
                </div>

                <!-- M022: Default Composition Profile -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Domyślny Profil Kompozycji (Scene Studio)</label>
                    <select id="cat-modal-composition-profile" class="bg-black/50 border border-white/10 text-white rounded p-2.5 text-xs focus:border-blue-500 focus:outline-none transition cursor-pointer">
                        ${profileOptions}
                    </select>
                    <p class="text-[8px] text-slate-600 mt-1">Nowe dania w tej kategorii dziedziczą ten profil. Pizza = warstwy z góry, pozostałe = 1 gotowe zdjęcie.</p>
                </div>

                <!-- M022: Layout Mode dla The Table (klient) -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Widok klienta — The Table</label>
                    <div class="flex flex-col gap-2 bg-black/30 rounded-xl p-3 border border-white/5">
                        ${layoutOption('legacy_list',
                            'Klasyczna lista',
                            'Standard — lista dań z miniaturkami. Używane dziś, działa wszędzie.')}
                        ${layoutOption('grouped',
                            'Jeden wspólny stół',
                            'Cała kategoria na jednej dioramie (np. 5 sosów na drewnianej desce). Dobre dla kategorii &#8804;6 pozycji.')}
                        ${layoutOption('individual',
                            'Sekwencja scen per danie',
                            'Każde danie ma własną dioramę (swipe w lewo/prawo). Stół zostaje — zmienia się tylko centerpiece.')}
                        ${layoutOption('hybrid',
                            'Banner + sekwencja',
                            'Pierwsza diorama = banner kategorii, kolejne = osobne dania. Premium feeling.')}
                    </div>
                    <p class="text-[8px] text-slate-600 mt-1">
                        <i class="fa-solid fa-circle-info text-[7px] mr-1 opacity-40"></i>
                        Widok klienta (The Table) jest w Fazie 3 — dziś zapisujesz wybór, klient zobaczy go gdy front zostanie zbudowany.
                    </p>
                </div>
            </div>
            <div class="flex gap-3 mt-8">
                <button id="cat-modal-save" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-black text-xs uppercase tracking-widest py-3 rounded-lg transition shadow-lg">
                    <i class="fa-solid fa-check mr-2"></i>${isEdit ? 'Zapisz' : 'Dodaj'}
                </button>
                <button id="cat-modal-cancel" class="px-6 bg-white/5 border border-white/10 text-slate-400 hover:text-white font-black text-xs uppercase rounded-lg transition">
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
        const result = await window.Core._renderCategoryModal(null);
        if (!result || !result.name) return;

        try {
            const response = await window.apiStudio('add_category', result);
            if (response && response.success === true) {
                await window.loadMenuTree();
                window.Core.renderTree();
            } else {
                alert("Błąd: " + (response?.message || "Nie udało się dodać kategorii."));
            }
        } catch (e) {
            alert("Błąd sieci przy dodawaniu kategorii.");
            console.error("[UI] addCategory error:", e);
        }
    },

    editCategory: async function(catId) {
        const cat = (window.StudioState?.categories || []).find(c => c.id == catId);
        if (!cat) return;

        const result = await window.Core._renderCategoryModal(cat);
        if (!result || !result.name) return;

        try {
            const response = await window.apiStudio('update_category', { categoryId: catId, ...result });
            if (response && response.success === true) {
                await window.loadMenuTree();
                window.Core.renderTree();
            } else {
                alert("Błąd: " + (response?.message || "Nie udało się zaktualizować kategorii."));
            }
        } catch (e) {
            alert("Błąd sieci przy edycji kategorii.");
            console.error("[UI] editCategory error:", e);
        }
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    if (window.MarginGuardian) await window.MarginGuardian.init();

    const treeContainer = document.getElementById('dynamic-tree-container');
    if (typeof window.loadMenuTree !== 'function') {
        if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd Krytyczny: Brak pliku Mózgu (studio_core.js)</div>';
        return;
    }

    // M022: Scene templates loading (parallel, non-blocking — cache'owane)
    if (typeof window.loadSceneTemplates === 'function') {
        window.loadSceneTemplates().catch(() => {
            // cicho — migracja 022 może jeszcze nie przejść, formularz dania użyje fallback hard-coded listy
        });
    }

    const data = await window.loadMenuTree();
    if (data) window.Core.renderTree();
    else if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd pobierania danych z API. Sprawdź konsolę.</div>';
});