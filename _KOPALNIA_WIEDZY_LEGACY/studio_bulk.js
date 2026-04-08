// ⚡ SLICEHUB STUDIO - MODUŁ EDYCJI MASOWEJ (Bulk Editor)
const BulkEditor = {
    renderInit() {
        const container = document.getElementById('view-container');
        container.innerHTML = `
        <div id="bulk-inspector-view" class="flex flex-col h-full bg-[#0a0510] animate-fade-in">
            <header class="h-20 border-b border-purple-500/20 bg-purple-900/10 flex items-center justify-between px-10 sticky top-0 z-50">
                <div class="flex flex-col">
                    <h2 class="text-[14px] font-black uppercase text-purple-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-list-check"></i> Kreator Masowy</h2>
                    <span id="bulk-count-label" class="text-[9px] font-bold text-slate-400 uppercase mt-1">Zaznaczono: 0 dań</span>
                </div>
                <button id="btn-bulk-save" onclick="BulkEditor.executeBulkUpdate()" class="bg-purple-600 hover:bg-purple-500 text-white h-11 px-8 rounded-xl font-black uppercase text-[11px] shadow-lg transition-all"><i class="fa-solid fa-bolt mr-2"></i> Zastosuj do wszystkich</button>
            </header>
            
            <div class="p-10 max-w-3xl mx-auto w-full space-y-8 pb-32">
                <div class="bg-white/5 border border-white/10 p-8 rounded-3xl space-y-6 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                    <h3 class="text-[11px] font-black uppercase text-white tracking-widest"><i class="fa-solid fa-truck mr-2 text-blue-500"></i> Logistyka</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <select id="bulk-vat" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-blue-500"><option value="">-- VAT (Ignoruj) --</option><option value="1">VAT 5%</option><option value="2">VAT 8%</option><option value="3">VAT 23%</option><option value="4">VAT 0%</option></select>
                        <select id="bulk-printer" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-blue-500"><option value="">-- Drukarka (Ignoruj) --</option><option value="KITCHEN_1">Główna Kuchnia</option><option value="BAR">Bar</option><option value="NONE">Brak</option></select>
                    </div>
                </div>

                <div class="bg-white/5 border border-white/10 p-8 rounded-3xl space-y-6 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-yellow-500"></div>
                    <h3 class="text-[11px] font-black uppercase text-white tracking-widest"><i class="fa-solid fa-money-bill-wave mr-2 text-yellow-500"></i> Cennik Masowy</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <select id="bulk-price-action" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-yellow-500"><option value="">-- Akcja (Ignoruj) --</option><option value="add">Zwiększ (+)</option><option value="sub">Obniż (-)</option><option value="set">Ustaw Sztywno (=)</option></select>
                        <input type="number" id="bulk-price-value" step="0.01" placeholder="Wartość (PLN)" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-yellow-500">
                    </div>
                </div>

                <div class="bg-white/5 border border-white/10 p-8 rounded-3xl space-y-6 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-purple-500"></div>
                    <h3 class="text-[11px] font-black uppercase text-white tracking-widest"><i class="fa-solid fa-bullhorn mr-2 text-purple-500"></i> Marketing & Status</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <select id="bulk-badge" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-purple-500"><option value="">-- Odznaka (Ignoruj) --</option><option value="none">Usuń Odznaki</option><option value="new">NOWOŚĆ</option><option value="bestseller">BESTSELLER</option></select>
                        <select id="bulk-secret" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-purple-500"><option value="">-- Tajne Menu (Ignoruj) --</option><option value="1">Ustaw jako Tajne (Tylko QR)</option><option value="0">Zdejmij Tajne (Publiczne)</option></select>
                        <select id="bulk-active" class="w-full bg-black/50 border border-white/10 rounded-xl p-4 text-[11px] font-bold text-white outline-none focus:border-purple-500"><option value="">-- Status (Ignoruj) --</option><option value="1">Aktywuj wszystkie</option><option value="0">Status 86 (Zdejmij z menu)</option></select>
                    </div>
                </div>
            </div>
        </div>
        `;
        this.updateBulkCountLabel();
    },

    updateBulkCountLabel() {
        const label = document.getElementById('bulk-count-label');
        if(label) label.innerText = `Zaznaczono: ${Core.state.bulkSelectedItems.length} dań`;
    },

    toggleCategory(catId, isChecked) {
        Core.state.items.filter(i => i.category_id == catId).forEach(i => {
            const idx = Core.state.bulkSelectedItems.indexOf(i.id);
            if(isChecked && idx === -1) Core.state.bulkSelectedItems.push(i.id);
            if(!isChecked && idx !== -1) Core.state.bulkSelectedItems.splice(idx, 1);
            const cb = document.getElementById(`cb-item-${i.id}`); 
            if(cb) cb.checked = isChecked;
        });
        this.updateBulkCountLabel();
    },

    toggleItem(id, catId, isChecked) {
        const idx = Core.state.bulkSelectedItems.indexOf(id);
        if(isChecked && idx === -1) Core.state.bulkSelectedItems.push(id);
        if(!isChecked && idx !== -1) Core.state.bulkSelectedItems.splice(idx, 1);
        
        const itemsInCat = Core.state.items.filter(i => i.category_id == catId);
        const allChecked = itemsInCat.length > 0 && itemsInCat.every(i => Core.state.bulkSelectedItems.includes(i.id));
        const catCb = document.getElementById(`cb-cat-${catId}`); 
        if(catCb) catCb.checked = allChecked;
        
        this.updateBulkCountLabel();
    },

    async executeBulkUpdate() {
        if(Core.state.bulkSelectedItems.length === 0) { alert("Zaznacz przynajmniej jedno danie!"); return; }
        const btn = document.getElementById('btn-bulk-save'); 
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Przetwarzanie...';
        
        const payload = {
            item_ids: Core.state.bulkSelectedItems,
            vat_id: document.getElementById('bulk-vat').value,
            printer_group: document.getElementById('bulk-printer').value,
            badge_type: document.getElementById('bulk-badge').value,
            is_secret: document.getElementById('bulk-secret').value,
            is_active: document.getElementById('bulk-active').value,
            price_action: document.getElementById('bulk-price-action').value,
            price_value: document.getElementById('bulk-price-value').value
        };
        
        const d = await Core.api('bulk_update', payload);
        if(d.status === 'success') {
            btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> Gotowe!';
            await Core.loadData(); 
            setTimeout(() => { Core.switchView('menu'); }, 1000);
        } else { 
            alert("Błąd: " + d.error); 
            btn.innerHTML = 'Błąd'; 
        }
    }
};