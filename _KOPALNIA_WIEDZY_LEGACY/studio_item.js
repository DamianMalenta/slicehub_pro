// 🍔 SLICEHUB STUDIO - MODUŁ INSPEKTORA DANIA (Item Inspector)
const ItemInspector = {
    // 1. INICJALIZACJA WIDOKU (Wstrzykiwanie HTML)
    renderInit() {
        const container = document.getElementById('view-container');
        container.innerHTML = `
        <div id="single-inspector-view" class="flex flex-col h-full animate-fade-in">
            <header class="h-20 border-b border-white/5 bg-black/60 flex items-center justify-between px-10 sticky top-0 z-50 backdrop-blur-xl">
                <div class="flex flex-col">
                    <div class="flex items-center gap-2 text-[9px] font-black uppercase text-slate-500">
                        <span id="insp-header-cat">Kategoria</span>
                        <i class="fa-solid fa-chevron-right text-[7px] opacity-30"></i>
                        <span id="insp-header-name" class="text-blue-400">Wybierz element</span>
                    </div>
                    <div class="flex gap-6 mt-2">
                        <button onclick="ItemInspector.switchTab('tab-identity')" class="tab-link active text-[9px] font-black uppercase tracking-widest pb-1 border-b-2 border-blue-500 text-white transition">Tożsamość</button>
                        <button onclick="ItemInspector.switchTab('tab-prices')" class="tab-link text-[9px] font-black uppercase tracking-widest pb-1 border-b-2 border-transparent text-slate-500 hover:text-white transition">Ceny i Warianty</button>
                        <button onclick="ItemInspector.switchTab('tab-logistics')" class="tab-link text-[9px] font-black uppercase tracking-widest pb-1 border-b-2 border-transparent text-slate-500 hover:text-white transition">Logistyka i Receptura</button>
                        <button onclick="ItemInspector.switchTab('tab-marketing')" class="tab-link text-[9px] font-black uppercase tracking-widest pb-1 border-b-2 border-transparent text-slate-500 hover:text-white transition">Marketing & QR</button>
                    </div>
                </div>
                <button id="btn-save-item" onclick="ItemInspector.saveItem()" class="bg-blue-600 hover:bg-blue-500 text-white h-11 px-8 rounded-xl font-black uppercase text-[11px] shadow-lg opacity-50 cursor-not-allowed transition-all">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Zmiany
                </button>
            </header>

            <div class="p-10 max-w-5xl mx-auto w-full pb-40">
                
                <div id="tab-identity" class="tab-content space-y-10">
                    <section class="flex gap-8">
                        <div class="w-40 h-40 rounded-3xl border-2 border-dashed border-white/10 bg-white/5 flex flex-col items-center justify-center text-slate-600 hover:border-blue-500 hover:text-blue-400 transition cursor-pointer shrink-0">
                            <i class="fa-solid fa-cloud-arrow-up text-4xl mb-3"></i>
                            <span class="text-[9px] font-black uppercase">Wgraj Foto</span>
                        </div>
                        <div class="flex-1 space-y-6">
                            <div class="grid grid-cols-2 gap-6">
                                <div class="col-span-2">
                                    <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Nazwa (UTF-8) <span class="text-blue-500 opacity-50 ml-2">Auto-ASCII ✨</span></label>
                                    <input type="text" id="insp-name" onkeyup="ItemInspector.generateAscii()" class="w-full bg-white/5 border border-white/10 rounded-xl p-4 text-xl font-black text-white outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-[9px] font-black uppercase text-red-500 mb-2">Klucz Techniczny (ASCII)</label>
                                    <input type="text" id="insp-ascii" class="w-full bg-black/40 border border-red-900/30 rounded-xl p-4 text-[12px] font-mono font-bold text-red-400 outline-none uppercase">
                                </div>
                                <div>
                                    <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Kod PLU / EAN</label>
                                    <input type="text" id="insp-plu" class="w-full bg-white/5 border border-white/10 rounded-xl p-4 text-[12px] font-bold text-white outline-none">
                                </div>
                            </div>
                        </div>
                    </section>
                    <section>
                        <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Opis Dania (Menu Online i Autoskan)</label>
                        <textarea id="insp-desc" rows="3" class="w-full bg-white/5 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-slate-300 outline-none focus:border-blue-500 transition" placeholder="Wpisz składniki... posłużą one do magicznego zmapowania receptury."></textarea>
                    </section>
                </div>

                <div id="tab-prices" class="tab-content hidden space-y-8">
                    <div id="inspector-dynamic-list" class="grid grid-cols-1 gap-3">
                        <p class="text-center py-10 text-slate-600 text-[9px] uppercase font-bold tracking-widest">Wybierz element z listy po lewej</p>
                    </div>
                </div>

                <div id="tab-logistics" class="tab-content hidden space-y-8">
                    <div class="grid grid-cols-2 gap-8">
                        <div class="bg-white/5 p-6 rounded-3xl border border-white/5 space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-blue-400"><i class="fa-solid fa-boxes-stacked mr-2"></i> Stan Magazynowy</h4>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Dostępna Ilość (Porcji)</label>
                                <input type="number" id="insp-stock" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-white font-black outline-none" placeholder="-1 = no limit">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Jednostka Miary</label>
                                <select id="insp-unit" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none">
                                    <option value="szt">Sztuka (szt)</option><option value="porcja">Porcja</option><option value="kg">Kilogram (kg)</option>
                                </select>
                            </div>
                        </div>
                        <div class="bg-white/5 p-6 rounded-3xl border border-white/5 space-y-6">
                            <h4 class="text-[10px] font-black uppercase text-green-400"><i class="fa-solid fa-kitchen-set mr-2"></i> Produkcja na Kuchni</h4>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Czas przygotowania (min)</label>
                                <input type="number" id="insp-prep" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-white font-black outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Stawka VAT</label>
                                    <select id="insp-vat" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none">
                                        <option value="1">VAT 5% (Danie)</option><option value="2">VAT 8% (Dostawa)</option><option value="3">VAT 23% (Napój)</option><option value="4">VAT 0%</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Drukarka Bonowa</label>
                                    <select id="insp-printer" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none">
                                        <option value="KITCHEN_1">Główna Kuchnia</option><option value="BAR">Bar Zimny</option><option value="NONE">Brak</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-blue-900/10 to-purple-900/10 p-8 rounded-3xl border border-blue-500/20">
                        <div class="flex items-center justify-between mb-6">
                            <h4 class="text-[11px] font-black uppercase text-blue-400"><i class="fa-solid fa-receipt mr-2"></i> Receptura (RW)</h4>
                            <button onclick="if(window.RecipeMapper) RecipeMapper.autoScan()" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-lg text-[9px] font-black uppercase transition shadow-lg flex items-center gap-2">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Autoskan z Opisu
                            </button>
                        </div>
                        <div id="recipe-ingredients-list" class="space-y-2">
                            <p class="text-[9px] text-slate-500 uppercase font-bold text-center py-4">Kliknij "Autoskan", aby system wygenerował recepturę na podstawie opisu dania.</p>
                        </div>
                    </div>
                </div>

                <div id="tab-marketing" class="tab-content hidden space-y-8">
                    <div class="grid grid-cols-3 gap-6">
                        <div class="bg-gradient-to-br from-purple-900/20 to-transparent p-6 rounded-3xl border border-purple-500/20 flex flex-col items-center justify-center">
                            <div id="qrcode-container" class="bg-white p-2 rounded-xl mb-4 w-32 h-32 flex items-center justify-center">
                                <i class="fa-solid fa-qrcode text-4xl text-slate-300"></i>
                            </div>
                            <span class="text-[9px] font-black uppercase text-purple-400">Live QR Code</span>
                        </div>
                        <div class="col-span-2 space-y-6">
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Odznaka (Badge)</label>
                                    <select id="insp-badge" class="w-full bg-white/5 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none">
                                        <option value="none">Brak odznaki</option><option value="new">NOWOŚĆ</option><option value="hot">OSTRE! 🔥</option><option value="bestseller">BESTSELLER</option>
                                    </select>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Dostępność</label>
                                        <div class="flex items-center h-[50px] bg-white/5 border border-white/10 rounded-xl px-4 justify-between">
                                            <span class="text-[10px] font-bold text-green-400 uppercase">Aktywne na POS</span>
                                            <input type="checkbox" id="insp-status" class="w-6 h-6 accent-green-500 cursor-pointer">
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex items-center h-[50px] bg-white/5 border border-white/10 rounded-xl px-4 justify-between">
                                            <span class="text-[10px] font-bold text-purple-400 uppercase">Tajne Menu (Tylko QR)</span>
                                            <input type="checkbox" id="insp-secret" class="w-6 h-6 accent-purple-500 cursor-pointer">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        `;
    },

    // 2. PRZEŁĄCZANIE ZAKŁADEK
    switchTab(tabId) {
        document.querySelectorAll('.tab-link').forEach(link => {
            link.classList.remove('active', 'border-blue-500', 'text-white');
            link.classList.add('border-transparent', 'text-slate-500');
        });
        const activeLink = document.querySelector(`[onclick="ItemInspector.switchTab('${tabId}')"]`);
        if (activeLink) {
            activeLink.classList.add('active', 'border-blue-500', 'text-white');
            activeLink.classList.remove('border-transparent', 'text-slate-500');
        }
        document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
        document.getElementById(tabId).classList.remove('hidden');
    },

    // 3. GENERATOR ASCII W LOCIE
    generateAscii() {
        const nameField = document.getElementById('insp-name').value;
        const asciiField = document.getElementById('insp-ascii');
        const charMap = {'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z'};
        let cleanStr = nameField.toLowerCase().replace(/[ąćęłńóśźż]/g, match => charMap[match] || match);
        cleanStr = cleanStr.replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        asciiField.value = cleanStr;
    },

    // 4. GENERATOR KODÓW QR
    generateLiveQR(itemId) {
        const qrContainer = document.getElementById('qrcode-container');
        const menuLink = window.location.origin + "/menu_online.php?item=" + itemId;
        qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(menuLink)}&color=6b21a8" class="w-full h-full object-cover rounded-xl shadow-inner">`;
    },

    // 5. ŁADOWANIE DANYCH DANIA DO ZAKŁADEK
    async selectItem(id, catId) {
        Core.state.activeItemId = id; 
        Core.state.activeCatId = catId; 
        Core.renderMenuTree();

        const item = Core.state.items.find(i => i.id == id);
        const cat = Core.state.categories.find(c => c.id == catId);

        document.getElementById('insp-header-cat').innerText = cat.name;
        document.getElementById('insp-header-name').innerText = item.name;
        
        // TAB 1
        document.getElementById('insp-name').value = item.name;
        document.getElementById('insp-ascii').value = item.ascii_key || '';
        document.getElementById('insp-plu').value = item.plu_code || '';
        document.getElementById('insp-desc').value = item.description || '';
        
        // TAB 3
        document.getElementById('insp-stock').value = item.stock_count ?? -1;
        document.getElementById('insp-unit').value = item.unit || 'szt';
        document.getElementById('insp-prep').value = item.prep_time || 15;
        document.getElementById('insp-vat').value = item.vat_id || 1;
        document.getElementById('insp-printer').value = item.printer_group || 'KITCHEN_1';

        // TAB 4
        document.getElementById('insp-badge').value = item.badge_type || 'none';
        document.getElementById('insp-status').checked = item.is_active == 1;
        document.getElementById('insp-secret').checked = item.is_secret == 1;

        this.generateLiveQR(item.id);
        
        // Wczytanie receptur (jeśli moduł RecipeMapper istnieje)
        if(window.RecipeMapper) RecipeMapper.loadItemRecipe(id);

        document.getElementById('btn-save-item').classList.remove('opacity-50', 'cursor-not-allowed');
        this.switchTab('tab-identity');

        // Ceny i Warianty (Pobierane z API)
        document.getElementById('inspector-dynamic-list').innerHTML = '<div class="py-10 flex justify-center"><i class="fa-solid fa-circle-notch fa-spin text-2xl text-blue-500"></i></div>';
        const d = await Core.api('get_item_details', { item_id: id });
        if(d.status === 'success') this.renderVariants(d.payload.variants);
    },

    // 6. RENDEROWANIE CENNIKA (Przyciski +, -, =)
    renderVariants(variants) {
        const list = document.getElementById('inspector-dynamic-list');
        let html = `
            <div class="flex items-center justify-between bg-blue-900/20 border border-blue-500/30 p-4 rounded-2xl mb-4 shadow-lg">
               <div class="flex flex-col">
                   <span class="text-[9px] font-black text-blue-400 uppercase tracking-widest"><i class="fa-solid fa-bolt mr-2"></i>Szybka zmiana cen</span>
                   <span class="text-[7px] text-blue-400/50 uppercase font-bold">Dotyczy wszystkich wariantów</span>
               </div>
               <div class="flex items-center gap-2">
                   <input type="number" id="quick-var-price" step="0.01" class="w-20 bg-black p-2 text-[11px] font-bold rounded-xl border border-blue-500/30 text-right text-white outline-none" placeholder="0.00">
                   <button onclick="ItemInspector.quickAdjustPrices('.var-price', 'add')" class="bg-blue-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-blue-500 transition shadow"><i class="fa-solid fa-plus text-[10px]"></i></button>
                   <button onclick="ItemInspector.quickAdjustPrices('.var-price', 'sub')" class="bg-red-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-red-500 transition shadow"><i class="fa-solid fa-minus text-[10px]"></i></button>
                   <button onclick="ItemInspector.quickAdjustPrices('.var-price', 'set')" class="bg-green-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-green-500 transition shadow"><i class="fa-solid fa-equals text-[10px]"></i></button>
               </div>
            </div>`;
        
        html += variants.map(v => `
            <div class="variant-row flex items-center justify-between bg-white/5 border border-white/5 p-4 rounded-2xl group transition" data-id="${v.id}">
                <div class="flex items-center gap-4">
                    <i class="fa-solid fa-grip-vertical text-slate-800"></i>
                    <input type="text" value="${v.name}" class="var-name bg-transparent font-black text-[11px] text-white outline-none focus:text-blue-400 w-32" placeholder="Rozmiar np. 32cm">
                </div>
                <div class="flex items-center gap-4">
                    <input type="number" step="0.01" value="${v.price}" class="var-price bg-black/40 p-2 rounded-lg text-green-500 w-20 text-right font-black border border-white/5 outline-none transition-colors">
                    <span class="text-[8px] font-black text-slate-500 mr-2">PLN</span>
                    <input type="checkbox" ${v.is_active==1?'checked':''} class="var-active w-4 h-4 accent-green-500">
                    <button onclick="this.closest('.variant-row').remove()" class="text-slate-600 hover:text-red-500 transition px-2"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </div>`).join('');
        html += `<button onclick="ItemInspector.addVariantRow()" class="w-full py-5 border-2 border-dashed border-white/10 rounded-2xl text-[10px] font-black uppercase text-slate-500 hover:text-white transition hover:border-white/30">+ Dodaj Nowy Wariant / Rozmiar</button>`;
        list.innerHTML = html;
    },

    quickAdjustPrices(selector, type) {
        const val = parseFloat(document.getElementById('quick-var-price').value) || 0;
        if(val === 0 && type !== 'set') return;
        document.querySelectorAll(selector).forEach(input => {
            let current = parseFloat(input.value) || 0;
            if(type === 'add') current += val;
            if(type === 'sub') current = Math.max(0, current - val);
            if(type === 'set') current = val;
            input.value = current.toFixed(2);
            input.classList.add('bg-green-500/20'); setTimeout(() => input.classList.remove('bg-green-500/20'), 500);
        });
    },

    addVariantRow() {
        const list = document.getElementById('inspector-dynamic-list'); const btn = list.querySelector('button');
        const div = document.createElement('div'); div.className = "variant-row flex items-center justify-between bg-white/5 border border-white/5 p-4 rounded-2xl mb-2"; div.setAttribute('data-id', 'new');
        div.innerHTML = `<div class="flex items-center gap-4"><i class="fa-solid fa-grip-vertical text-slate-800"></i><input type="text" value="Nowy" class="var-name bg-transparent font-black text-[11px] text-white w-32 outline-none"></div><div class="flex items-center gap-4"><input type="number" step="0.01" value="0.00" class="var-price bg-black/40 p-2 rounded-lg text-green-500 w-20 text-right font-black border border-white/5 outline-none"><span class="text-[8px] font-black text-slate-500 mr-2">PLN</span><input type="checkbox" checked class="var-active w-4 h-4 accent-green-500"><button onclick="this.closest('.variant-row').remove()" class="text-red-500 px-2"><i class="fa-solid fa-trash-can"></i></button></div>`;
        list.insertBefore(div, btn);
    },

    // 7. GŁÓWNY ZAPIS ZBIORCZY DLA DANIA
    async saveItem() {
        const btn = document.getElementById('btn-save-item');
        if(btn.classList.contains('opacity-50') || !Core.state.activeItemId) return;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> ZAPIS...';
        
        const variants = Array.from(document.querySelectorAll('.variant-row')).map(r => ({
            id: r.getAttribute('data-id'), 
            name: r.querySelector('.var-name').value, 
            price: r.querySelector('.var-price').value, 
            is_active: r.querySelector('.var-active').checked ? 1 : 0
        }));

        const payload = {
            item_id: Core.state.activeItemId,
            name: document.getElementById('insp-name').value,
            ascii: document.getElementById('insp-ascii').value,
            plu_code: document.getElementById('insp-plu').value,
            description: document.getElementById('insp-desc').value,
            is_active: document.getElementById('insp-status').checked ? 1 : 0,
            stock_count: document.getElementById('insp-stock').value,
            unit: document.getElementById('insp-unit').value,
            prep_time: document.getElementById('insp-prep').value,
            vat_id: document.getElementById('insp-vat').value,
            printer_group: document.getElementById('insp-printer').value,
            badge_type: document.getElementById('insp-badge').value,
            is_secret: document.getElementById('insp-secret').checked ? 1 : 0,
            variants: variants
        };

        const d = await Core.api('update_item_full', payload);
        
        // Zapis receptur jeśli są zdefiniowane
        if(window.RecipeMapper && typeof RecipeMapper.saveItemRecipe === 'function') {
            await RecipeMapper.saveItemRecipe(Core.state.activeItemId);
        }

        if(d.status === 'success') {
            btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> ZAPISANO!';
            await Core.loadData(); 
            Core.renderMenuTree(); 
            setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Zmiany'; }, 2000);
        }
    }
};