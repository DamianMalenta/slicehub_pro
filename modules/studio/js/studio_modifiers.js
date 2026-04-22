// 🪄 SLICEHUB STUDIO - MODUŁ MODYFIKATORÓW (V3.2 ENTERPRISE + SKU GRUPY)

window.StudioState = window.StudioState || {};
window.StudioState.modifierGroups = window.StudioState.modifierGroups || [];
window.StudioState.products = window.StudioState.products || [];
window.StudioState.warehouseItems = window.StudioState.warehouseItems || [];
window.StudioState.activeModGroupId = null;

window.ModifierInspector = {
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
            const whResult = await window.ApiClient.post('../../api/api_warehouse.php', { action: 'GET_STOCK' });
            if (whResult.success && Array.isArray(whResult.data)) {
                window.StudioState.warehouseItems = whResult.data;
            } else {
                console.warn("[ModifierInspector] Nie udało się pobrać surowców z magazynu (GET_STOCK).", whResult.message);
            }
        }
        await this.loadModifiersFromDB();
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
            container.innerHTML = `<button onclick="window.ModifierInspector.createNewGroup()" class="w-full mb-4 py-3 bg-green-900/40 text-green-400 border border-green-500/30 rounded flex items-center justify-center hover:bg-green-600 hover:text-white transition text-[10px] font-black uppercase"><i class="fa-solid fa-plus mr-2"></i> Nowa Grupa</button>` + 
            '<div class="text-center mt-10 text-slate-500 font-bold text-[10px] uppercase">Brak zapisanych grup w bazie.</div>'; 
            return; 
        }

        container.innerHTML = `<button onclick="window.ModifierInspector.createNewGroup()" class="w-full mb-4 py-3 bg-green-900/40 text-green-400 border border-green-500/30 rounded flex items-center justify-center hover:bg-green-600 hover:text-white transition text-[10px] font-black uppercase"><i class="fa-solid fa-plus mr-2"></i> Nowa Grupa</button>` + 
        groups.map(g => `
            <div onclick="window.ModifierInspector.selectGroup(${g.id})" class="p-4 bg-white/5 border border-white/5 rounded-xl mb-2 cursor-pointer hover:border-blue-500/50 transition flex justify-between items-center group ${g.id === window.StudioState.activeModGroupId ? 'border-blue-500 bg-blue-900/10' : ''}">
                <div><h3 class="text-[11px] font-black uppercase text-white group-hover:text-blue-400 transition">${g.name}</h3></div>
                <i class="fa-solid fa-chevron-right text-[10px] text-slate-700"></i>
            </div>`).join('');
    },

    createNewGroup() {
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
        
        this.renderModifierItems([]);
        this.addOptionRow(); 
        this.applyFranchiseShieldForGroup(false);
    },

    selectGroup(id) {
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
        this.renderModifierItems(itemsToRender);
        this.applyFranchiseShieldForGroup(!!group.isLockedByHq);
    },

    renderModifierItems(items) {
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
            </div>
        `;
        list.appendChild(row);
        this.bindOptionAutoSlug(row);
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
            row.querySelectorAll('.lock-opt-name, .lock-opt-warehouse').forEach(block => {
                if (isLocked) block.classList.add('pointer-events-none', 'opacity-50');
                else block.classList.remove('pointer-events-none', 'opacity-50');
            });

            row.querySelectorAll('.opt-name, .opt-ascii, .opt-action, .opt-sku, .opt-qty, .opt-default').forEach(input => {
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
            action:    'SAVE_MODIFIER',
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
            const result = await window.ApiClient.post('../../api/api_modifiers.php', payload);

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
                const valResult = window.SliceValidator.validatePrice(current);
                if (valResult && valResult.error) current = 0; 
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
                [pricePos, priceTakeaway, priceDelivery].forEach(channelPrice => {
                    const valResult = window.SliceValidator.validatePrice(channelPrice);
                    if (valResult && valResult.error) {
                        alert(`Błąd ceny w opcji "${name}": ` + valResult.message);
                        hasValidationError = true;
                    }
                });
            }

            if (actionType !== 'NONE' && !linkedSku) {
                alert(`Opcja "${name}" ma ustawioną akcję magazynową, ale nie wybrano surowca!`);
                hasValidationError = true;
            }

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
                linkedQuantity: parseFloat(linkedQty)
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