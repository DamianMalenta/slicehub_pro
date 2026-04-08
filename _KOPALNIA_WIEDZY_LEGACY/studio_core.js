// 🧠 SLICEHUB STUDIO CORE (Główny Dyrygent)
const Core = {
    state: {
        categories: [], items: [], modifierGroups: [],
        activeView: 'menu', activeItemId: null, activeCatId: null, activeModGroupId: null, bulkSelectedItems: []
    },

    // 2. KOMUNIKACJA Z BAZĄ (TUTAJ DODALIŚMY CREDENTIALS!)
    async api(action, payload = {}) {
        payload.action = action;
        try {
            const r = await fetch('api_menu_studio.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include', // <--- TO NAPRAWIA SESJĘ!
                body: JSON.stringify(payload)
            });
            const response = await r.json();
            if (response.status === 'error') console.error("API Error:", response.error);
            return response;
        } catch(e) {
            console.error("Connection Error:", e);
            return { status: 'error', error: 'Błąd połączenia z serwerem' };
        }
    },

    async init() { await this.loadData(); this.switchView('menu'); },

    async loadData() {
        const d = await this.api('get_menu_tree');
        if (d.status === 'success') { this.state.categories = d.payload.categories; this.state.items = d.payload.items; }
    },

    switchView(view) {
        this.state.activeView = view; this.state.activeItemId = null; this.state.activeModGroupId = null;
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        if (view !== 'bulk') { const btn = document.getElementById('nav-' + view); if (btn) btn.classList.add('active'); }
        const container = document.getElementById('view-container');
        if (container) container.innerHTML = ''; 
        if (view === 'menu' && window.ItemInspector) ItemInspector.renderInit();
        else if (view === 'bulk' && window.BulkEditor) BulkEditor.renderInit();
        else if (view === 'modifiers' && window.ModifierInspector) ModifierInspector.renderInit();
        this.renderMenuTree(); 
    },

    renderMenuTree() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const isBulk = this.state.activeView === 'bulk';
        container.innerHTML = this.state.categories.map(cat => {
            const items = this.state.items.filter(i => i.category_id == cat.id);
            const allChecked = items.length > 0 && items.every(i => this.state.bulkSelectedItems.includes(i.id));
            return `<div class="group border border-white/5 rounded-2xl bg-black/40 overflow-hidden mb-3">
                <div class="p-3 bg-white/5 flex justify-between items-center cursor-pointer">
                    <div class="flex items-center gap-3">
                        ${isBulk ? `<input type="checkbox" id="cb-cat-${cat.id}" ${allChecked ? 'checked' : ''} onchange="BulkEditor.toggleCategory(${cat.id}, this.checked)" class="w-4 h-4 accent-yellow-500 cursor-pointer">` : `<i class="fa-solid fa-folder text-yellow-500/50 text-[10px]"></i>`}
                        <h3 class="font-black text-[11px] uppercase text-slate-200 tracking-wider">${cat.name}</h3>
                    </div>
                    ${!isBulk ? `<button onclick="Core.addItem(${cat.id})" class="text-green-500 hover:text-white transition"><i class="fa-solid fa-plus text-[10px]"></i></button>` : ''}
                </div>
                <div class="p-1 space-y-1">${items.map(i => {
                    const isChecked = this.state.bulkSelectedItems.includes(i.id);
                    return `
                    <div ${!isBulk ? `onclick="ItemInspector.selectItem(${i.id}, ${cat.id})"` : ''} class="tree-item p-3 rounded-xl ${!isBulk ? 'cursor-pointer' : ''} text-[10px] font-bold text-slate-400 hover:bg-white/5 transition flex items-center justify-between ${i.id === this.state.activeItemId ? 'active-tree-item border border-blue-500/30 text-white' : ''}">
                        <div class="flex items-center gap-3">
                            ${isBulk ? `<input type="checkbox" id="cb-item-${i.id}" ${isChecked ? 'checked' : ''} onchange="BulkEditor.toggleItem(${i.id}, ${cat.id}, this.checked)" class="w-4 h-4 accent-purple-500 cursor-pointer">` : `<span class="w-1.5 h-1.5 rounded-full ${i.is_active == 1 ? 'bg-green-500' : 'bg-red-500'}"></span>`}
                            ${i.name} ${i.badge_type && i.badge_type !== 'none' ? `<span class="ml-1 text-[7px] bg-purple-500/20 text-purple-400 px-1 py-0.5 rounded uppercase">${i.badge_type}</span>` : ''}
                            ${i.is_secret == 1 ? `<i class="fa-solid fa-user-secret text-slate-600 ml-1" title="Tajne Menu"></i>` : ''}
                        </div>
                        ${i.stock_count >= 0 ? `<span class="text-[8px] bg-white/10 px-2 py-0.5 rounded text-slate-400">Magazyn: ${i.stock_count}</span>` : ''}
                    </div>`;
                }).join('')}
                </div>
            </div>`;
        }).join('');
    },

    async addCategory() { const n = prompt("Nazwa nowej kategorii:"); if (n) { await this.api('add_category', {name: n}); this.init(); } },
    async addItem(catId) { const n = prompt("Nazwa nowego dania:"); if (n) { const d = await this.api('add_item', {category_id: catId, name: n}); await this.loadData(); if (window.ItemInspector) ItemInspector.selectItem(d.payload.id, catId); } }
};
window.addEventListener('DOMContentLoaded', () => Core.init());