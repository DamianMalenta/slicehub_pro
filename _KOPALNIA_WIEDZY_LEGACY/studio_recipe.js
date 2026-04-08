// 🪄 SLICEHUB STUDIO - MODUŁ RECEPTUR I MAGII
const RecipeMapper = {
    state: { products: [], currentRecipe: [] },

    async init() {
        try {
            const r = await fetch('api_recipes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include', // <--- NAPRAWA SESJI
                body: JSON.stringify({ action: 'get_recipes_init' })
            });
            const d = await r.json();
            if(d.status === 'success') this.state.products = d.payload.products;
        } catch(e) { console.error("Błąd ładowania magazynu", e); }
    },

    async loadItemRecipe(itemId) {
        if(this.state.products.length === 0) await this.init();
        const r = await fetch('api_recipes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include', // <--- NAPRAWA SESJI
            body: JSON.stringify({ action: 'get_recipes_init' })
        });
        const d = await r.json();
        if(d.status === 'success') {
            this.state.currentRecipe = d.payload.recipes.filter(rec => rec.menu_item_id == itemId);
            this.renderRecipeList();
        }
    },

    autoScan() {
        const desc = document.getElementById('insp-desc').value.toLowerCase();
        if(!desc || desc.trim() === '') { alert("Opis dania jest pusty!"); return; }
        let foundCount = 0;
        this.state.products.forEach(prod => {
            if(desc.includes(prod.name.toLowerCase()) && !this.state.currentRecipe.find(r => r.product_id == prod.id)) {
                this.state.currentRecipe.push({ product_id: prod.id, quantity: 0.1, waste_percent: 0, product_name: prod.name, unit: prod.unit });
                foundCount++;
            }
        });
        this.renderRecipeList();
        if(foundCount > 0) alert(`✨ Magia zadziałała! Dodano ${foundCount} składników.`);
        else alert("Nie znaleziono pasujących produktów w magazynie.");
    },

    renderRecipeList() {
        const container = document.getElementById('recipe-ingredients-list');
        if(!container) return;
        if(this.state.currentRecipe.length === 0) {
            container.innerHTML = `<p class="text-[9px] text-slate-500 uppercase font-bold text-center py-4">Brak składników. Kliknij "Autoskan".</p>`;
        } else {
            container.innerHTML = this.state.currentRecipe.map((rec, index) => {
                const prodName = rec.product_name || this.state.products.find(p => p.id == rec.product_id)?.name || 'Nieznany';
                const unit = rec.unit || this.state.products.find(p => p.id == rec.product_id)?.unit || 'kg';
                return `<div class="flex items-center justify-between bg-black/40 border border-white/5 p-3 rounded-xl mb-2"><span class="text-[10px] font-bold text-blue-400">${prodName}</span><div class="flex items-center gap-3"><input type="number" step="0.01" value="${rec.quantity}" onchange="RecipeMapper.updateQty(${index}, this.value)" class="w-16 bg-white/10 border border-white/10 p-2 text-white font-bold rounded-lg text-right text-[10px] outline-none"><span class="text-[8px] text-slate-500 w-4">${unit}</span><button onclick="RecipeMapper.removeIngredient(${index})" class="text-slate-600 hover:text-red-500 transition px-2"><i class="fa-solid fa-trash"></i></button></div></div>`;
            }).join('');
        }
        let options = '<option value="">-- Wybierz ręcznie --</option>';
        this.state.products.forEach(p => options += `<option value="${p.id}">${p.name} (${p.unit})</option>`);
        container.innerHTML += `<div class="mt-4 flex gap-2"><select id="recipe-manual-add" class="flex-1 bg-black/40 border border-white/10 p-2 rounded-xl text-[10px] font-bold text-white outline-none">${options}</select><button onclick="RecipeMapper.addManual()" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-xl text-[10px] transition"><i class="fa-solid fa-plus"></i></button></div>`;
    },

    updateQty(index, val) { this.state.currentRecipe[index].quantity = parseFloat(val) || 0; },
    removeIngredient(index) { this.state.currentRecipe.splice(index, 1); this.renderRecipeList(); },
    addManual() {
        const sel = document.getElementById('recipe-manual-add'); if(!sel.value) return;
        const prod = this.state.products.find(p => p.id == sel.value);
        if(!this.state.currentRecipe.find(r => r.product_id == prod.id)) { this.state.currentRecipe.push({ product_id: prod.id, quantity: 0, waste_percent: 0, product_name: prod.name, unit: prod.unit }); this.renderRecipeList(); }
        sel.value = '';
    },

    async saveItemRecipe(itemId) {
        if(this.state.currentRecipe.length === 0) return; 
        try {
            await fetch('api_recipes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include', // <--- NAPRAWA SESJI
                body: JSON.stringify({ action: 'save_recipe', menu_item_id: itemId, ingredients: this.state.currentRecipe })
            });
        } catch(e) { console.error("Błąd zapisu receptury", e); }
    }
};
window.addEventListener('DOMContentLoaded', () => RecipeMapper.init());