window.RecipeMapper = {
    state: { 
        products: [], 
        currentRecipe: [],
        currentMenuItemSku: null
    },

    async fetchApi(action, payload = {}) {
        payload.action = action;
        const authToken = 'mock_jwt_token_123';
        try {
            const response = await fetch('../../api/backoffice/api_recipes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                },
                body: JSON.stringify(payload)
            });
            if (!response.ok) throw new Error('Network error');
            return await response.json();
        } catch (e) {
            return { status: 'error', message: 'Błąd sieci podczas łączenia z API.' };
        }
    },

    async init() {
        const response = await this.fetchApi('get_recipes_init');
        if (response.status === 'success' && response.payload) {
            this.state.products = response.payload.products || [];
        } else {
            console.error("[RecipeMapper] Błąd inicjalizacji:", response.message);
        }
    },

    // --- NOWA FUNKCJA ZABEZPIECZAJĄCA (Wstrzykiwanie surowców) ---
    populateIngredientDropdown() {
        const select = document.getElementById('manual-ingredient-select');
        if (!select) {
            console.warn("[RecipeMapper] Okienko select jeszcze nie istnieje!");
            return;
        }

        // Zabezpieczenie przed dublowaniem listy
        if (select.options.length > 1) return;

        select.innerHTML = '<option value="">Wybierz surowiec z magazynu...</option>';

        this.state.products.forEach(prod => {
            const option = document.createElement('option');
            option.value = prod.sku;
            option.textContent = `${prod.name} (${prod.baseUnit})`; 
            select.appendChild(option);
        });
    },

    async loadItemRecipe(menuItemSku) {
        if (this.state.products.length === 0) {
            await this.init();
        }
        
        this.state.currentMenuItemSku = menuItemSku;
        const response = await this.fetchApi('get_item_recipe', { menuItemSku: menuItemSku });
        
        if (response.status === 'success' && response.payload) {
            this.state.currentRecipe = response.payload.ingredients || [];
            this.renderRecipeList();
        } else {
            console.error("[RecipeMapper] Błąd pobierania receptury:", response.message);
        }

        // --- ROZWIĄZANIE "WYŚCIGU CZASU" ---
        // Ładujemy listę surowców dopiero wtedy, gdy klikniesz pizzę (bo okienko już istnieje)
        this.populateIngredientDropdown();
    },

    autoScan() {
        const descInput = document.getElementById('insp-desc');
        if (!descInput) return;
        
        const desc = descInput.value.toLowerCase();
        if (!desc) return;

        this.state.products.forEach(prod => {
            let searchTerms = [prod.name.toLowerCase()];
            
            if (prod.aliases) {
                const aliasArray = prod.aliases.split(',').map(a => a.trim().toLowerCase()).filter(a => a !== '');
                searchTerms = searchTerms.concat(aliasArray);
            }

            const escapedTerms = searchTerms.map(term => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
            const regex = new RegExp('(^|[\\s,.;!?])(' + escapedTerms.join('|') + ')([\\s,.;!?]|$)', 'iu');
            
            if (regex.test(desc)) {
                const alreadyExists = this.state.currentRecipe.some(r => r.warehouseSku === prod.sku);
                if (!alreadyExists) {
                    this.state.currentRecipe.push({
                        warehouseSku: prod.sku,
                        name: prod.name,
                        baseUnit: prod.baseUnit,
                        quantityBase: 0.1,
                        wastePercent: 0,
                        isPackaging: false
                    });
                }
            }
        });
        
        this.renderRecipeList();
    },

    // --- POPRAWIONE RĘCZNE DODAWANIE (Walidacja i UX) ---
    addManual(warehouseSku, quantityBase, wastePercent = 0, isPackaging = false) {
        if (!warehouseSku) {
            alert("Wybierz surowiec z listy!");
            return;
        }
        
        const qty = parseFloat(quantityBase);
        if (!qty || qty <= 0) {
            alert("Podaj prawidłową ilość!");
            return;
        }

        const prod = this.state.products.find(p => p.sku === warehouseSku);
        if (!prod) return;

        if (typeof SliceValidator !== 'undefined') {
            const result = SliceValidator.validateRecipeRow(qty, prod.baseUnit, prod.baseUnit);
            if (result && result.error) {
                alert(`[Blokada] Błąd walidacji: Nieprawidłowa wartość ilości dla jednostki ${prod.baseUnit}`);
                return;
            }
        }

        const existingRow = this.state.currentRecipe.find(r => r.warehouseSku === warehouseSku);
        if (existingRow) {
            existingRow.quantityBase = parseFloat(existingRow.quantityBase) + qty;
        } else {
            this.state.currentRecipe.push({
                warehouseSku: prod.sku,
                name: prod.name,
                baseUnit: prod.baseUnit,
                quantityBase: qty,
                wastePercent: parseFloat(wastePercent),
                isPackaging: !!isPackaging
            });
        }
        
        // UX: Czyszczenie okienek po dodaniu
        const selectEl = document.getElementById('manual-ingredient-select');
        const qtyEl = document.getElementById('manual-ingredient-qty');
        if(selectEl) selectEl.value = '';
        if(qtyEl) qtyEl.value = '';
        
        this.renderRecipeList();
    },

    updateQty(index, newQty) {
        const row = this.state.currentRecipe[index];
        if (!row) return;

        if (typeof SliceValidator !== 'undefined') {
            const result = SliceValidator.validateRecipeRow(newQty, row.baseUnit, row.baseUnit);
            if (result && result.error) {
                alert(`[Blokada] Błąd walidacji: Nieprawidłowa wartość ilości dla jednostki ${row.baseUnit}`);
                this.renderRecipeList(); 
                return;
            }
        }

        row.quantityBase = parseFloat(newQty);
        this.renderRecipeList();
    },

    removeIngredient(index) {
        this.state.currentRecipe.splice(index, 1);
        this.renderRecipeList();
    },

    renderRecipeList() {
        const container = document.getElementById('recipe-ingredients-list');
        if (!container) return;
        
        if (this.state.currentRecipe.length === 0) {
            container.innerHTML = '<div class="text-slate-500 text-[10px] italic p-4 text-center">Brak przypisanych składników.</div>';
            return;
        }

        let html = '<div class="recipe-grid w-full space-y-2">';
        this.state.currentRecipe.forEach((ing, index) => {
            html += `
            <div class="recipe-row flex items-center justify-between p-2 border border-white/10 bg-black/40 rounded" data-sku="${ing.warehouseSku}">
                <div class="flex flex-col">
                    <span class="text-white text-[11px] font-bold">${ing.name}</span>
                    <span class="text-slate-500 text-[9px] font-mono">SKU: ${ing.warehouseSku}</span>
                </div>
                <div class="flex items-center gap-2">
                    <input type="number" 
                           step="0.001" 
                           value="${ing.quantityBase}" 
                           class="w-20 bg-transparent border border-white/20 text-white text-center text-[11px] p-1 rounded outline-none focus:border-blue-500"
                           onchange="window.RecipeMapper.updateQty(${index}, this.value)" />
                    <span class="text-slate-400 text-[10px] font-bold uppercase w-8">${ing.baseUnit}</span>
                    <button class="bg-red-900/50 hover:bg-red-600 text-red-200 px-3 py-1 rounded transition text-[10px] font-bold uppercase" 
                            onclick="window.RecipeMapper.removeIngredient(${index})">
                        Usuń
                    </button>
                </div>
            </div>
            `;
        });
        html += '</div>';
        
        container.innerHTML = html;
    },

    async saveItemRecipe() {
        if (!this.state.currentMenuItemSku) {
            alert("Błąd: Nie wybrano żadnego dania.");
            return;
        }

        const payload = {
            menuItemSku: this.state.currentMenuItemSku,
            ingredients: this.state.currentRecipe
        };

        const response = await this.fetchApi('save_recipe', payload);
        
        if (response.status === 'success') {
            console.log("[RecipeMapper] Sukces:", response.message);
            alert("Receptura została zapisana!");
        } else {
            console.error("[RecipeMapper] Błąd:", response.message);
            alert("Wystąpił błąd podczas zapisu: " + response.message);
        }
    }
};

window.addEventListener('DOMContentLoaded', () => {
    window.RecipeMapper.init();
});