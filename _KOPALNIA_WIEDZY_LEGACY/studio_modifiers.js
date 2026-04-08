// 🪄 SLICEHUB STUDIO - MODUŁ MODYFIKATORÓW (Dodatki, Sosy)
const ModifierInspector = {
    // 1. INICJALIZACJA WIDOKU MODYFIKATORÓW
    renderInit() {
        const container = document.getElementById('view-container');
        container.innerHTML = `
        <div id="modifiers-view" class="flex flex-col h-full animate-fade-in">
            <header class="h-20 border-b border-white/5 bg-black/60 flex items-center justify-between px-10 sticky top-0 z-50 backdrop-blur-xl">
                <div class="flex items-center gap-2 text-[9px] font-black uppercase text-slate-500">
                    <span>Modyfikatory</span>
                    <i class="fa-solid fa-chevron-right text-[7px] opacity-30"></i>
                    <span id="insp-mod-name" class="text-blue-400">Wybierz Grupę</span>
                </div>
                <button id="btn-save-modifier" onclick="ModifierInspector.saveModifierGroup()" class="bg-blue-600 hover:bg-blue-500 text-white h-11 px-8 rounded-xl font-black uppercase text-[11px] shadow-lg opacity-50 cursor-not-allowed transition-all">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę
                </button>
            </header>

            <div class="p-10 max-w-5xl mx-auto w-full pb-40 space-y-10">
                <section class="grid grid-cols-2 gap-8">
                    <div class="bg-white/5 p-8 rounded-3xl border border-white/5 space-y-6">
                        <h4 class="text-[10px] font-black uppercase text-blue-400"><i class="fa-solid fa-sliders mr-2"></i> Ustawienia Grupy</h4>
                        <div>
                            <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Nazwa Grupy Wyświetlana na POS</label>
                            <input type="text" id="mod-group-name" placeholder="np. Wybierz Sosy" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-white font-black outline-none focus:border-blue-500 transition">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Minimum Wyborów</label>
                                <input type="number" id="mod-min" value="0" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-white font-black outline-none">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 mb-2">Maksimum Wyborów</label>
                                <input type="number" id="mod-max" value="1" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-white font-black outline-none">
                            </div>
                        </div>
                        <div class="flex items-center h-[50px] bg-black/40 border border-white/10 rounded-xl px-4 justify-between">
                            <span class="text-[10px] font-bold text-green-400 uppercase">Grupa Aktywna</span>
                            <input type="checkbox" id="mod-group-active" class="w-6 h-6 accent-green-500 cursor-pointer">
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-900/10 to-transparent p-8 rounded-3xl border border-blue-500/20 flex flex-col justify-center items-center text-center">
                        <i class="fa-solid fa-wand-magic-sparkles text-4xl text-blue-500 mb-4 opacity-50"></i>
                        <h3 class="text-[12px] font-black uppercase text-white mb-2">Zarządzanie Opcjami</h3>
                        <p class="text-[9px] text-slate-400 font-bold uppercase leading-relaxed">Tutaj dodasz konkretne składniki do tej grupy (np. Ketchup, Czosnkowy). Ustal limity po lewej stronie, aby wymusić na kelnerze lub kliencie odpowiedni wybór.</p>
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="flex items-center justify-between border-b border-white/5 pb-4">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Składniki w Grupie</h3>
                    </div>
                    <div id="modifier-items-list" class="grid grid-cols-1 gap-3">
                        <p class="text-center py-10 text-slate-600 text-[9px] uppercase font-bold tracking-widest">Wybierz grupę z listy po lewej stronie</p>
                    </div>
                </section>
            </div>
        </div>
        `;
    },

    // 2. ŁADOWANIE DANYCH GRUPY (Po kliknięciu w lewej kolumnie)
    async selectGroup(id) {
        Core.state.activeModGroupId = id;
        Core.state.activeItemId = null;
        
        // Podświetlenie na liście
        this.renderGroupList();

        const group = Core.state.modifierGroups.find(g => g.id == id);
        document.getElementById('insp-mod-name').innerText = group.name;
        document.getElementById('mod-group-name').value = group.name;
        document.getElementById('mod-min').value = group.min_selection || 0;
        document.getElementById('mod-max').value = group.max_selection || 1;
        document.getElementById('mod-group-active').checked = group.is_active == 1;

        document.getElementById('btn-save-modifier').classList.remove('opacity-50', 'cursor-not-allowed');

        document.getElementById('modifier-items-list').innerHTML = '<div class="py-10 flex justify-center"><i class="fa-solid fa-circle-notch fa-spin text-2xl text-blue-500"></i></div>';
        
        // Pobranie konkretnych opcji dla grupy z API
        const d = await Core.api('get_modifier_group_details', { group_id: id });
        if(d.status === 'success') this.renderModifierItems(d.payload.modifiers);
    },

    // 3. RENDEROWANIE SKŁADNIKÓW I SZYBKIE CENY
    renderModifierItems(items) {
        const list = document.getElementById('modifier-items-list');
        let html = `
            <div class="flex items-center justify-between bg-blue-900/20 border border-blue-500/30 p-4 rounded-2xl mb-4 shadow-lg">
               <div class="flex flex-col">
                   <span class="text-[9px] font-black text-blue-400 uppercase tracking-widest"><i class="fa-solid fa-bolt mr-2"></i>Szybka zmiana cen</span>
                   <span class="text-[7px] text-blue-400/50 uppercase font-bold">Dotyczy wszystkich dodatków w grupie</span>
               </div>
               <div class="flex items-center gap-2">
                   <input type="number" id="quick-mod-price" step="0.01" class="w-20 bg-black p-2 text-[11px] font-bold rounded-xl border border-blue-500/30 text-right text-white outline-none" placeholder="0.00">
                   <button onclick="ModifierInspector.quickAdjust('.mod-price', 'add')" class="bg-blue-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-blue-500 transition shadow"><i class="fa-solid fa-plus text-[10px]"></i></button>
                   <button onclick="ModifierInspector.quickAdjust('.mod-price', 'sub')" class="bg-red-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-red-500 transition shadow"><i class="fa-solid fa-minus text-[10px]"></i></button>
                   <button onclick="ModifierInspector.quickAdjust('.mod-price', 'set')" class="bg-green-600 text-white w-9 h-9 rounded-xl flex items-center justify-center hover:bg-green-500 transition shadow"><i class="fa-solid fa-equals text-[10px]"></i></button>
               </div>
            </div>`;
        
        html += items.map(m => `
            <div class="modifier-row flex items-center justify-between bg-white/5 border border-white/5 p-4 rounded-2xl group transition" data-id="${m.id}">
                <div class="flex items-center gap-4">
                    <i class="fa-solid fa-grip-vertical text-slate-800"></i>
                    <input type="text" value="${m.name}" class="mod-name bg-transparent font-black text-[11px] text-white outline-none focus:text-blue-400 w-40" placeholder="Nazwa dodatku">
                </div>
                <div class="flex items-center gap-4">
                    <input type="number" step="0.01" value="${m.price}" class="mod-price bg-black/40 p-2 rounded-lg text-blue-400 w-20 text-right font-black border border-white/5 outline-none transition-colors">
                    <span class="text-[8px] font-black text-slate-500 mr-2">PLN</span>
                    <input type="checkbox" ${m.is_active==1?'checked':''} class="mod-active w-4 h-4 accent-blue-500">
                    <button onclick="this.closest('.modifier-row').remove()" class="text-slate-600 hover:text-red-500 transition px-2"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </div>`).join('');
        html += `<button onclick="ModifierInspector.addModifierRow()" class="w-full py-5 border-2 border-dashed border-white/10 rounded-2xl text-[10px] font-black uppercase text-slate-500 hover:text-white transition hover:border-white/30">+ Dodaj Nowy Składnik</button>`;
        list.innerHTML = html;
    },

    quickAdjust(selector, type) {
        const val = parseFloat(document.getElementById('quick-mod-price').value) || 0;
        if(val === 0 && type !== 'set') return;
        document.querySelectorAll(selector).forEach(input => {
            let current = parseFloat(input.value) || 0;
            if(type === 'add') current += val;
            if(type === 'sub') current = Math.max(0, current - val);
            if(type === 'set') current = val;
            input.value = current.toFixed(2);
            input.classList.add('bg-blue-500/20'); setTimeout(() => input.classList.remove('bg-blue-500/20'), 500);
        });
    },

    addModifierRow() {
        const list = document.getElementById('modifier-items-list'); const btn = list.querySelector('button');
        const div = document.createElement('div'); div.className = "modifier-row flex items-center justify-between bg-white/5 border border-white/5 p-4 rounded-2xl mb-2"; div.setAttribute('data-id', 'new');
        div.innerHTML = `<div class="flex items-center gap-4"><i class="fa-solid fa-grip-vertical text-slate-800"></i><input type="text" value="Nowy" class="mod-name bg-transparent font-black text-[11px] text-white w-40 outline-none"></div><div class="flex items-center gap-4"><input type="number" step="0.01" value="0.00" class="mod-price bg-black/40 p-2 rounded-lg text-blue-400 w-20 text-right font-black border border-white/5 outline-none"><span class="text-[8px] font-black text-slate-500 mr-2">PLN</span><input type="checkbox" checked class="mod-active w-4 h-4 accent-blue-500"><button onclick="this.closest('.modifier-row').remove()" class="text-red-500 px-2"><i class="fa-solid fa-trash-can"></i></button></div>`;
        list.insertBefore(div, btn);
    },

    // 4. ZAPIS DO BAZY
    async saveModifierGroup() {
        const btn = document.getElementById('btn-save-modifier');
        if(btn.classList.contains('opacity-50') || !Core.state.activeModGroupId) return;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> ZAPISYWANIE...';
        
        const items = Array.from(document.querySelectorAll('.modifier-row')).map(r => ({
            id: r.getAttribute('data-id'), 
            name: r.querySelector('.mod-name').value, 
            price: r.querySelector('.mod-price').value, 
            is_active: r.querySelector('.mod-active').checked ? 1 : 0
        }));

        const payload = {
            group_id: Core.state.activeModGroupId,
            name: document.getElementById('mod-group-name').value,
            min: document.getElementById('mod-min').value,
            max: document.getElementById('mod-max').value,
            is_active: document.getElementById('mod-group-active').checked ? 1 : 0,
            items: items
        };

        const d = await Core.api('update_modifier_group_full', payload);
        if(d.status === 'success') {
            btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> ZAPISANO!';
            await Core.api('get_modifier_groups').then(res => { if(res.status==='success') Core.state.modifierGroups = res.payload.groups; });
            this.renderGroupList();
            setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Grupę'; }, 2000);
        }
    },

    // 5. RENDEROWANIE LEWEJ KOLUMNY (Drzewko Modyfikatorów)
    async renderGroupList() {
        const container = document.getElementById('dynamic-tree-container');
        if(!container) return;
        
        // Jeśli nie mamy grup w stanie (np. po odświeżeniu), pobieramy je
        if(Core.state.modifierGroups.length === 0) {
            const d = await Core.api('get_modifier_groups');
            if(d.status === 'success') Core.state.modifierGroups = d.payload.groups;
        }

        if(Core.state.modifierGroups.length === 0) {
            container.innerHTML = '<p class="text-center py-10 text-slate-600 text-[9px] uppercase font-bold">Brak grup. Kliknij +</p>'; 
            return; 
        }

        container.innerHTML = Core.state.modifierGroups.map(g => `
            <div onclick="ModifierInspector.selectGroup(${g.id})" class="p-4 bg-white/5 border border-white/5 rounded-xl mb-2 cursor-pointer hover:border-blue-500/50 transition flex justify-between items-center group ${g.id === Core.state.activeModGroupId ? 'border-blue-500 bg-blue-900/10' : ''}">
                <div><h3 class="text-[11px] font-black uppercase text-white group-hover:text-blue-400 transition">${g.name}</h3></div>
                <i class="fa-solid fa-chevron-right text-[10px] text-slate-700"></i>
            </div>`).join('');
    }
};

// Dodajemy podpięcie renderowania listy dla głównego widoku
// W studio_core.js jest `if (view === 'modifiers' && window.ModifierInspector) { ModifierInspector.renderInit(); }`
// Ale musimy też zainicjować listę po lewej:
const originalRenderInit = ModifierInspector.renderInit;
ModifierInspector.renderInit = function() {
    originalRenderInit.call(ModifierInspector);
    ModifierInspector.renderGroupList();
};