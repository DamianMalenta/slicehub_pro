window.RecipeMapper = {
    state: { 
        products: [], 
        currentRecipe: [],
        currentMenuItemSku: null
    },

    async fetchApi(action, payload = {}) {
        payload.action = action;
        return await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);
    },

    async init() {
        const response = await this.fetchApi('get_recipes_init');
        if (response.success === true && response.data) {
            this.state.products = response.data.products || [];
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
            option.dataset.baseUnit = prod.baseUnit || 'kg';
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
        
        if (response.success === true && response.data) {
            this.state.currentRecipe = response.data.ingredients || [];
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
                        baseUnit: prod.baseUnit || 'kg',
                        unit: prod.baseUnit || 'kg',
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
    addManual(warehouseSku, quantityBase, unit = 'g', wastePercent = 0, isPackaging = false) {
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

        // Read baseUnit from the DOM dataset as authoritative source (falls back to product state).
        const selectEl = document.getElementById('manual-ingredient-select');
        const selectedOpt = selectEl?.options[selectEl.selectedIndex];
        const baseUnit = selectedOpt?.dataset.baseUnit || prod.baseUnit || 'kg';
        const usageUnit = unit || baseUnit;

        if (typeof SliceValidator !== 'undefined') {
            const result = SliceValidator.validateRecipeRow(qty, usageUnit, baseUnit);
            if (result && (result.status === 'error')) {
                alert(`[Blokada] Niezgodność jednostek: nie można przeliczyć "${usageUnit}" na "${baseUnit}". Wybierz zgodną jednostkę.`);
                return;
            }
        }

        const existingRow = this.state.currentRecipe.find(r => r.warehouseSku === warehouseSku);
        if (existingRow) {
            existingRow.quantityBase = parseFloat(existingRow.quantityBase) + qty;
            existingRow.unit     = usageUnit;
            existingRow.baseUnit = baseUnit;
        } else {
            this.state.currentRecipe.push({
                warehouseSku: prod.sku,
                name:         prod.name,
                baseUnit:     baseUnit,
                unit:         usageUnit,
                quantityBase: qty,
                wastePercent: parseFloat(wastePercent),
                isPackaging:  !!isPackaging
            });
        }
        
        // UX: Czyszczenie okienek po dodaniu
        const qtyEl  = document.getElementById('manual-ingredient-qty');
        const unitEl = document.getElementById('add-ingredient-unit');
        if (selectEl) selectEl.value = '';
        if (qtyEl)    qtyEl.value = '';
        if (unitEl)   unitEl.value = 'g';
        
        this.renderRecipeList();
    },

    updateQty(index, newQty) {
        const row = this.state.currentRecipe[index];
        if (!row) return;

        if (typeof SliceValidator !== 'undefined') {
            const rowBaseUnit  = row.baseUnit || 'kg';
            const rowUsageUnit = row.unit     || rowBaseUnit;
            const result = SliceValidator.validateRecipeRow(newQty, rowUsageUnit, rowBaseUnit);
            if (result && result.status === 'error') {
                alert(`[Blokada] Niezgodność jednostek: nie można przeliczyć "${rowUsageUnit}" na "${rowBaseUnit}".`);
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

    _triggerMarginUpdate() {
        if (!window.MarginGuardian || !window.MarginGuardian.initialized) return;
        const vatRate = parseFloat(document.getElementById('item-vat-rate')?.value)         || 0;
        const priceTiers = [
            { channel: 'POS',      price: parseFloat(document.getElementById('item-price-pos')?.value)      || 0, vatRate },
            { channel: 'Takeaway', price: parseFloat(document.getElementById('item-price-takeaway')?.value) || 0, vatRate },
            { channel: 'Delivery', price: parseFloat(document.getElementById('item-price-delivery')?.value) || 0, vatRate },
        ];
        const results = window.MarginGuardian.calculate(priceTiers, this.state.currentRecipe);
        window.MarginGuardian.render('margin-container', results);
    },

    renderRecipeList() {
        const container = document.getElementById('recipe-ingredients-list');
        if (!container) return;
        
        if (this.state.currentRecipe.length === 0) {
            container.innerHTML = '<div class="text-slate-500 text-[10px] italic p-4 text-center">Brak przypisanych składników.</div>';
            this._triggerMarginUpdate();
            return;
        }

        const _avcoDict = (window.MarginGuardian && window.MarginGuardian.initialized)
            ? window.MarginGuardian.avcoDict
            : {};

        let html = '<div class="recipe-grid w-full space-y-2">';
        this.state.currentRecipe.forEach((ing, index) => {
            // --- Defensive per-row cost ---
            const unitAvco  = _avcoDict[ing.warehouseSku] ?? 0;
            const rawQty    = parseFloat(ing.quantityBase) || 0;
            const wastePct  = parseFloat(ing.wastePercent) || 0;
            const usageUnit = ing.unit || ing.baseUnit;

            const targetBaseUnit = ing.baseUnit || 'kg';

            let actualQtyInBaseUnits = 0;
            if (typeof window.SliceValidator !== 'undefined') {
                const conversion = window.SliceValidator.convert(rawQty, usageUnit, targetBaseUnit);
                if (conversion.success) {
                    actualQtyInBaseUnits = conversion.value;
                } else {
                    console.warn(
                        `[SliceHub Math] Unit mismatch or error for ${ing.warehouseSku}:`,
                        conversion.msg || conversion.error
                    );
                    // Fallback to 0 — prevents catastrophic Food Cost inflation.
                }
            } else {
                actualQtyInBaseUnits = rawQty;
            }

            const qtyWithWaste = actualQtyInBaseUnits * (1 + wastePct / 100);
            const rowCost      = qtyWithWaste * unitAvco;
            const rowCostStr   = (rowCost > 0)
                ? rowCost.toLocaleString('pl-PL', { style: 'currency', currency: 'PLN', minimumFractionDigits: 2, maximumFractionDigits: 4 })
                : '—';

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
                    <span class="text-slate-400 text-[10px] font-bold uppercase w-8">${usageUnit}</span>
                    <span class="text-slate-500 text-[10px] font-mono w-16 text-right" title="Koszt wiersza (po konwersji jednostek)">${rowCostStr}</span>
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
        this._triggerMarginUpdate();
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
        
        if (response.success === true) {
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