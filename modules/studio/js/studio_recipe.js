const _eR = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

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
        // M1: Warehouse items (quantity + avco) — używane przez stock badge i fuzzy search.
        // ModifierInspector też to ładuje; dzielimy cache w StudioState.warehouseItems.
        window.StudioState = window.StudioState || {};
        if (!Array.isArray(window.StudioState.warehouseItems) || window.StudioState.warehouseItems.length === 0) {
            try {
                if (window.WarehouseApi && typeof window.WarehouseApi.stockList === 'function') {
                    const wh = await window.WarehouseApi.stockList();
                    if (wh && wh.success && Array.isArray(wh.data)) {
                        window.StudioState.warehouseItems = wh.data;
                    }
                }
            } catch (err) {
                console.warn('[RecipeMapper] Warehouse stockList fallback:', err);
            }
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

        this.populateIngredientDropdown();
        this.wireUpSearchUI();
    },

    // ═══════════════════════════════════════════════════════════════════════
    // M1 · Menu Studio Polish — Fuzzy Search + QuickAdd + Bulk Add
    // ═══════════════════════════════════════════════════════════════════════

    wireUpSearchUI() {
        const searchEl = document.getElementById('ingredient-search');
        if (!searchEl || searchEl.dataset.wired === '1') return;
        searchEl.dataset.wired = '1';

        searchEl.addEventListener('input', () => {
            this.renderSearchResults(searchEl.value);
        });
        searchEl.addEventListener('focus', () => {
            this.renderSearchResults(searchEl.value);
        });
        searchEl.addEventListener('keydown', (e) => {
            const results = document.getElementById('ingredient-search-results');
            if (!results || results.classList.contains('hidden')) return;
            const cards = Array.from(results.querySelectorAll('.search-result-card'));
            if (cards.length === 0) return;
            let idx = cards.findIndex(c => c.classList.contains('active'));
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                idx = Math.min(cards.length - 1, idx + 1);
                cards.forEach(c => c.classList.remove('active', 'bg-blue-500/20'));
                cards[idx].classList.add('active', 'bg-blue-500/20');
                cards[idx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                idx = Math.max(0, idx - 1);
                cards.forEach(c => c.classList.remove('active', 'bg-blue-500/20'));
                cards[idx].classList.add('active', 'bg-blue-500/20');
                cards[idx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const target = idx >= 0 ? cards[idx] : cards[0];
                if (target) this.selectSearchResult(target.dataset.sku);
            } else if (e.key === 'Escape') {
                results.classList.add('hidden');
            }
        });

        document.addEventListener('click', (e) => {
            const results = document.getElementById('ingredient-search-results');
            if (!results || results.classList.contains('hidden')) return;
            if (!e.target.closest('#ingredient-search') && !e.target.closest('#ingredient-search-results')) {
                results.classList.add('hidden');
            }
        });

        const quickQty = document.getElementById('quickadd-qty');
        if (quickQty && quickQty.dataset.wired !== '1') {
            quickQty.dataset.wired = '1';
            quickQty.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); this.confirmQuickAdd(); }
                else if (e.key === 'Escape') { e.preventDefault(); this.cancelQuickAdd(); }
            });
        }
    },

    _fuzzyScore(query, entry) {
        const q = this._normalizeToken(query);
        if (!q) return 0;
        const name = entry.nameNorm;
        if (name === q) return 100;
        if (name.startsWith(q)) return 80;
        if (name.includes(q)) return 60;
        for (const alias of entry.aliasNorm) {
            if (alias === q) return 70;
            if (alias.startsWith(q)) return 50;
            if (alias.includes(q)) return 35;
        }
        // SKU match as last resort
        const sku = this._normalizeToken(entry.sku);
        if (sku.includes(q)) return 20;
        return 0;
    },

    renderSearchResults(query) {
        const results = document.getElementById('ingredient-search-results');
        if (!results) return;
        const q = (query || '').trim();

        const index = this._buildProductIndex();
        const alreadyAdded = new Set(this.state.currentRecipe.map(r => r.warehouseSku));

        let candidates;
        if (!q) {
            candidates = index.slice(0, 60).map(e => ({ entry: e, score: 1 }));
        } else {
            candidates = index.map(e => ({ entry: e, score: this._fuzzyScore(q, e) }))
                .filter(c => c.score > 0)
                .sort((a, b) => b.score - a.score)
                .slice(0, 40);
        }

        if (candidates.length === 0) {
            results.innerHTML = `
                <div class="p-4 text-center text-slate-600 text-[10px] uppercase font-black tracking-widest">
                    <i class="fa-solid fa-inbox text-xl mb-2 opacity-40 block"></i>
                    Brak dopasowań dla "${_eR(q)}"
                </div>
            `;
            results.classList.remove('hidden');
            return;
        }

        results.innerHTML = candidates.map(({ entry }) => {
            const stock = this._getStockInfo(entry.sku);
            const added = alreadyAdded.has(entry.sku);
            const stockHtml = stock
                ? `<span class="text-[8px] font-black uppercase ${stock.level === 'ok' ? 'text-green-400' : stock.level === 'low' ? 'text-yellow-400' : 'text-red-400'}">${stock.qty} ${stock.baseUnit}</span>`
                : '<span class="text-[8px] text-slate-700 font-black uppercase">—</span>';
            const avcoHtml = stock && stock.avco > 0
                ? `<span class="text-[8px] text-slate-500 font-mono">${stock.avco.toFixed(2)} zł/${stock.baseUnit}</span>`
                : '';
            const aliasHtml = entry.aliasTerms.length
                ? `<span class="text-[8px] text-slate-600 ml-1">· ${_eR(entry.aliasTerms.slice(0, 3).join(', '))}</span>`
                : '';
            return `
                <div class="search-result-card flex items-center justify-between gap-3 p-2.5 border-b border-white/5 cursor-pointer hover:bg-blue-500/20 transition ${added ? 'opacity-40' : ''}" data-sku="${_eR(entry.sku)}" onclick="window.RecipeMapper.selectSearchResult('${_eR(entry.sku)}')">
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-black text-white truncate">${_eR(entry.name)}${added ? ' <span class=\"text-[8px] text-green-400\">(już w recepturze)</span>' : ''}</div>
                        <div class="text-[8px] font-mono text-slate-500 truncate">${_eR(entry.sku)}${aliasHtml}</div>
                    </div>
                    <div class="flex flex-col items-end gap-0.5 shrink-0">
                        ${stockHtml}
                        ${avcoHtml}
                    </div>
                </div>
            `;
        }).join('');
        results.classList.remove('hidden');
    },

    selectSearchResult(sku) {
        const prod = this.state.products.find(p => p.sku === sku);
        if (!prod) return;
        const existing = this.state.currentRecipe.find(r => r.warehouseSku === sku);
        if (existing) {
            alert(`"${prod.name}" jest już w recepturze — edytuj ilość poniżej.`);
            return;
        }
        const quickadd = document.getElementById('ingredient-quickadd');
        const nameEl = document.getElementById('quickadd-name');
        const skuEl = document.getElementById('quickadd-sku');
        const qtyEl = document.getElementById('quickadd-qty');
        const unitEl = document.getElementById('quickadd-unit');
        const searchEl = document.getElementById('ingredient-search');
        const results = document.getElementById('ingredient-search-results');

        if (nameEl) nameEl.textContent = prod.name;
        if (skuEl) skuEl.textContent = prod.sku + ' · base: ' + (prod.baseUnit || 'kg');
        if (qtyEl) { qtyEl.value = ''; qtyEl.focus(); }
        if (unitEl) unitEl.value = prod.baseUnit === 'szt' ? 'szt' : 'g';
        if (quickadd) {
            quickadd.classList.remove('hidden');
            quickadd.dataset.activeSku = sku;
        }
        if (searchEl) searchEl.value = '';
        if (results) results.classList.add('hidden');
    },

    confirmQuickAdd() {
        const quickadd = document.getElementById('ingredient-quickadd');
        const qtyEl = document.getElementById('quickadd-qty');
        const unitEl = document.getElementById('quickadd-unit');
        if (!quickadd) return;
        const sku = quickadd.dataset.activeSku || '';
        const qty = parseFloat(qtyEl?.value) || 0;
        const unit = unitEl?.value || 'g';
        if (!sku || qty <= 0) {
            alert('Podaj ilość większą od 0.');
            return;
        }
        this.addManual(sku, qty, unit, 0);
        this.cancelQuickAdd();
    },

    cancelQuickAdd() {
        const quickadd = document.getElementById('ingredient-quickadd');
        if (quickadd) {
            quickadd.classList.add('hidden');
            delete quickadd.dataset.activeSku;
        }
        const qtyEl = document.getElementById('quickadd-qty');
        if (qtyEl) qtyEl.value = '';
    },

    // ─── Bulk Add Modal ────────────────────────────────────────────────────
    openBulkAdd() {
        let host = document.getElementById('sh-bulk-add-modal');
        if (host) host.remove();
        host = document.createElement('div');
        host.id = 'sh-bulk-add-modal';
        host.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm p-4';
        host.innerHTML = `
            <div class="bg-[#0a0a0f] border border-white/10 rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <header class="flex items-center justify-between px-6 py-4 border-b border-white/5 shrink-0">
                    <div>
                        <div class="text-[9px] font-black uppercase text-cyan-400 tracking-widest">Bulk Add</div>
                        <div class="text-[14px] font-black text-white">Wklej listę składników</div>
                    </div>
                    <button type="button" class="bulk-close text-slate-500 hover:text-white w-9 h-9 rounded-lg hover:bg-white/5 transition flex items-center justify-center">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>
                <div class="px-6 py-4 flex-1 overflow-y-auto">
                    <p class="text-[10px] text-slate-400 mb-3 leading-relaxed">
                        Jedna linia = jeden składnik. Format: <code class="text-cyan-400 bg-black/50 px-1 py-0.5 rounded">nazwa ilość jednostka</code>
                        <br>Przykład:<br>
                        <code class="text-slate-500 bg-black/50 px-1 py-0.5 rounded text-[9px] block mt-1">mozzarella 150 g<br>sos pomidorowy 80 ml<br>bazylia 5 g<br>mąka 250</code>
                    </p>
                    <textarea id="bulk-add-textarea" rows="10" class="w-full bg-black/50 border border-white/10 text-white rounded-xl p-3 text-[11px] font-mono focus:border-cyan-500 outline-none transition" placeholder="mozzarella 150 g&#10;sos pomidorowy 80 ml&#10;..."></textarea>
                    <div id="bulk-add-preview" class="mt-3 space-y-1 max-h-48 overflow-y-auto"></div>
                </div>
                <footer class="px-6 py-3 border-t border-white/5 flex items-center justify-between shrink-0">
                    <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest"><span id="bulk-add-count">0</span> linii · <span id="bulk-add-matched" class="text-green-400">0</span> dopasowanych · <span id="bulk-add-skipped" class="text-red-400">0</span> bez dopasowania</div>
                    <div class="flex gap-2">
                        <button type="button" class="bulk-close px-4 py-2 bg-white/5 border border-white/10 text-slate-400 hover:text-white rounded-lg text-[10px] font-black uppercase transition">Anuluj</button>
                        <button type="button" id="bulk-add-apply" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white rounded-lg text-[10px] font-black uppercase transition shadow-lg shadow-cyan-500/20 disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-check mr-1"></i> Dodaj dopasowane
                        </button>
                    </div>
                </footer>
            </div>
        `;
        document.body.appendChild(host);

        const textarea = host.querySelector('#bulk-add-textarea');
        const applyBtn = host.querySelector('#bulk-add-apply');

        const refreshPreview = () => {
            const parsed = this._parseBulkLines(textarea.value);
            host.querySelector('#bulk-add-count').textContent = String(parsed.length);
            host.querySelector('#bulk-add-matched').textContent = String(parsed.filter(p => p.matched).length);
            host.querySelector('#bulk-add-skipped').textContent = String(parsed.filter(p => !p.matched).length);
            const preview = host.querySelector('#bulk-add-preview');
            preview.innerHTML = parsed.map(p => {
                if (p.matched) {
                    const stock = this._getStockInfo(p.matched.sku);
                    const stockBadge = stock
                        ? `<span class="text-[8px] ${stock.level === 'ok' ? 'text-green-400' : stock.level === 'low' ? 'text-yellow-400' : 'text-red-400'} font-black uppercase">${stock.level}</span>`
                        : '';
                    return `<div class="flex items-center gap-2 p-1.5 bg-green-900/10 border border-green-500/30 rounded text-[10px]"><i class="fa-solid fa-check text-green-400 text-[9px]"></i><span class="text-slate-300 flex-1 truncate">${_eR(p.matched.name)} · <span class="text-slate-500">${p.qty} ${p.unit}</span></span>${stockBadge}</div>`;
                }
                return `<div class="flex items-center gap-2 p-1.5 bg-red-900/10 border border-red-500/30 rounded text-[10px]"><i class="fa-solid fa-xmark text-red-400 text-[9px]"></i><span class="text-slate-500 flex-1 truncate">"${_eR(p.raw)}" — brak w magazynie</span></div>`;
            }).join('');
            applyBtn.disabled = parsed.filter(p => p.matched).length === 0;
        };

        textarea.addEventListener('input', refreshPreview);

        const close = () => host.remove();
        host.addEventListener('click', (e) => {
            if (e.target === host) close();
            if (e.target.closest('.bulk-close')) close();
        });
        applyBtn.addEventListener('click', () => {
            const parsed = this._parseBulkLines(textarea.value);
            let added = 0;
            parsed.forEach(p => {
                if (!p.matched) return;
                const exists = this.state.currentRecipe.some(r => r.warehouseSku === p.matched.sku);
                if (exists) return;
                this.state.currentRecipe.push({
                    warehouseSku: p.matched.sku,
                    name:         p.matched.name,
                    baseUnit:     p.matched.baseUnit,
                    unit:         p.unit,
                    quantityBase: p.qty,
                    wastePercent: 0,
                    isPackaging:  false
                });
                added++;
            });
            this.renderRecipeList();
            close();
            if (added > 0) console.info(`[BulkAdd] Dodano ${added} składników do receptury.`);
        });

        textarea.focus();
        refreshPreview();
    },

    _parseBulkLines(text) {
        const index = this._buildProductIndex();
        return (text || '').split('\n').map(line => {
            const raw = line.trim();
            if (!raw) return null;
            const match = raw.match(/^(.+?)\s+(\d+(?:[\.,]\d+)?)\s*([a-zA-Z]+)?\s*$/);
            let namePart = raw, qty = 100, unit = 'g';
            if (match) {
                namePart = match[1].trim();
                qty = parseFloat(match[2].replace(',', '.')) || 100;
                unit = (match[3] || 'g').toLowerCase();
            }
            const q = this._normalizeToken(namePart);
            let best = null, bestScore = 0;
            for (const entry of index) {
                const score = this._fuzzyScore(namePart, entry);
                if (score > bestScore) {
                    bestScore = score;
                    best = entry;
                }
            }
            return {
                raw,
                qty,
                unit,
                matched: bestScore >= 35 ? { sku: best.sku, name: best.name, baseUnit: best.baseUnit } : null,
            };
        }).filter(x => x !== null);
    },

    // ─── Stock info helper ─────────────────────────────────────────────────
    _getStockInfo(sku) {
        const items = window.StudioState?.warehouseItems || [];
        const row = items.find(x => x.sku === sku);
        if (!row) return null;
        const qty = parseFloat(row.quantity) || 0;
        const avco = parseFloat(row.current_avco_price) || 0;
        let level = 'ok';
        if (qty <= 0) level = 'out';
        else if (qty < 5) level = 'low';
        return {
            qty: Number(qty.toFixed(3)),
            avco,
            baseUnit: row.base_unit || 'kg',
            level,
        };
    },

    _normalizeToken(str) {
        return String(str || '').toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim();
    },

    _tokenize(text) {
        const MIN_TOKEN_LEN = 3;
        return text.toLowerCase()
            .split(/[\s,.:;!?()\/\-]+/)
            .map(t => t.trim())
            .filter(t => t.length >= MIN_TOKEN_LEN);
    },

    _buildProductIndex() {
        if (this._productIndex && this._productIndexVersion === this.state.products.length) {
            return this._productIndex;
        }

        this._productIndex = this.state.products.map(prod => {
            const nameTerms = this._tokenize(prod.name);

            let aliasTerms = [];
            if (prod.aliases && typeof prod.aliases === 'string') {
                aliasTerms = prod.aliases
                    .split(',')
                    .map(a => a.trim().toLowerCase())
                    .filter(a => a.length >= 2);
            }

            const nameNorm  = this._normalizeToken(prod.name);
            const aliasNorm = aliasTerms.map(a => this._normalizeToken(a));

            return {
                sku:       prod.sku,
                name:      prod.name,
                baseUnit:  prod.baseUnit || 'kg',
                nameTerms,
                aliasTerms,
                nameNorm,
                aliasNorm,
            };
        });

        this._productIndexVersion = this.state.products.length;
        return this._productIndex;
    },

    autoScan() {
        const descInput = document.getElementById('insp-desc');
        if (!descInput) return;

        const rawText = descInput.value;
        if (!rawText.trim()) return;

        const tokens = this._tokenize(rawText);
        const tokensNorm = tokens.map(t => this._normalizeToken(t));
        if (tokens.length === 0) return;

        const index = this._buildProductIndex();
        let matchCount = 0;

        index.forEach(entry => {
            const alreadyExists = this.state.currentRecipe.some(
                r => r.warehouseSku === entry.sku
            );
            if (alreadyExists) return;

            let matched = false;

            for (let i = 0; i < tokens.length && !matched; i++) {
                const tok     = tokens[i];
                const tokNorm = tokensNorm[i];

                if (entry.nameNorm.includes(tokNorm)) {
                    matched = true;
                    break;
                }

                for (const term of entry.nameTerms) {
                    if (term.includes(tok) || tok.includes(term)) {
                        matched = true;
                        break;
                    }
                }
                if (matched) break;

                for (let j = 0; j < entry.aliasTerms.length; j++) {
                    const alias     = entry.aliasTerms[j];
                    const aliasNorm = entry.aliasNorm[j];

                    if (alias === tok || aliasNorm === tokNorm) {
                        matched = true;
                        break;
                    }
                    if (tok.length >= 4 && (alias.includes(tok) || tok.includes(alias))) {
                        matched = true;
                        break;
                    }
                    if (tokNorm.length >= 4 && (aliasNorm.includes(tokNorm) || tokNorm.includes(aliasNorm))) {
                        matched = true;
                        break;
                    }
                }
            }

            if (matched) {
                this.state.currentRecipe.push({
                    warehouseSku: entry.sku,
                    name:         entry.name,
                    baseUnit:     entry.baseUnit,
                    unit:         entry.baseUnit,
                    quantityBase: 0,
                    wastePercent: 0,
                    isPackaging:  false
                });
                matchCount++;
            }
        });

        this.renderRecipeList();

        console.info(
            `[AutoScan] Tokeny: [${tokens.join(', ')}] → dopasowano ${matchCount} surowców`
        );
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
        const vatDineIn   = parseFloat(document.getElementById('item-vat-dine-in')?.value)   || 0;
        const vatTakeaway = parseFloat(document.getElementById('item-vat-takeaway')?.value)  || 0;
        const priceTiers = [
            { channel: 'POS',      price: parseFloat(document.getElementById('item-price-pos')?.value)      || 0, vatRate: vatDineIn },
            { channel: 'Takeaway', price: parseFloat(document.getElementById('item-price-takeaway')?.value) || 0, vatRate: vatTakeaway },
            { channel: 'Delivery', price: parseFloat(document.getElementById('item-price-delivery')?.value) || 0, vatRate: vatTakeaway },
        ];
        const results = window.MarginGuardian.calculate(priceTiers, this.state.currentRecipe);
        window.MarginGuardian.render('margin-container', results);
    },

    updateWaste(index, newWaste) {
        const row = this.state.currentRecipe[index];
        if (!row) return;
        const w = Math.max(0, Math.min(100, parseFloat(newWaste) || 0));
        row.wastePercent = w;
        this.renderRecipeList();
    },

    updateUnit(index, newUnit) {
        const row = this.state.currentRecipe[index];
        if (!row) return;
        row.unit = newUnit || row.baseUnit;
        this.renderRecipeList();
    },

    renderRecipeList() {
        const container = document.getElementById('recipe-ingredients-list');
        if (!container) return;

        const _avcoDict = (window.MarginGuardian && window.MarginGuardian.initialized)
            ? window.MarginGuardian.avcoDict
            : {};

        let totalCost = 0;
        const rowDetails = this.state.currentRecipe.map((ing) => {
            const unitAvco  = _avcoDict[ing.warehouseSku] ?? (this._getStockInfo(ing.warehouseSku)?.avco || 0);
            const rawQty    = parseFloat(ing.quantityBase) || 0;
            const wastePct  = parseFloat(ing.wastePercent) || 0;
            const usageUnit = ing.unit || ing.baseUnit;
            const targetBaseUnit = ing.baseUnit || 'kg';

            let actualQtyInBaseUnits = 0;
            if (typeof window.SliceValidator !== 'undefined') {
                const conversion = window.SliceValidator.convert(rawQty, usageUnit, targetBaseUnit);
                actualQtyInBaseUnits = conversion.success ? conversion.value : 0;
            } else {
                actualQtyInBaseUnits = rawQty;
            }
            const qtyWithWaste = actualQtyInBaseUnits * (1 + wastePct / 100);
            const rowCost = qtyWithWaste * unitAvco;
            totalCost += rowCost;
            return { ing, unitAvco, rawQty, wastePct, usageUnit, qtyWithWaste, rowCost };
        });

        const costStr = totalCost > 0
            ? totalCost.toLocaleString('pl-PL', { style: 'currency', currency: 'PLN', minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '0,00 zł';

        const headerHtml = `
            <div class="recipe-header flex items-center justify-between bg-gradient-to-r from-purple-900/20 to-transparent border border-purple-500/20 p-3 rounded-xl mb-2">
                <div class="flex flex-col">
                    <span class="text-[8px] font-black uppercase text-purple-400 tracking-widest"><i class="fa-solid fa-receipt mr-1"></i> Food Cost (AVCO × qty + ubytek)</span>
                    <span class="text-[16px] font-black text-white mt-0.5">${costStr}</span>
                </div>
                <div class="flex flex-col items-end">
                    <span class="text-[8px] font-black uppercase text-slate-500 tracking-widest">Składników</span>
                    <span class="text-[14px] font-black text-slate-300">${this.state.currentRecipe.length}</span>
                </div>
            </div>
        `;

        if (this.state.currentRecipe.length === 0) {
            container.innerHTML = headerHtml + '<div class="text-slate-500 text-[10px] italic p-4 text-center border border-dashed border-white/10 rounded-xl">Brak przypisanych składników. Użyj wyszukiwarki powyżej lub AutoScan.</div>';
            this._triggerMarginUpdate();
            return;
        }

        let html = headerHtml + '<div class="recipe-grid w-full space-y-1.5">';
        rowDetails.forEach((d, index) => {
            const stock = this._getStockInfo(d.ing.warehouseSku);
            let stockBadge = '';
            if (stock) {
                const toneMap = {
                    ok:  'bg-green-900/30 border-green-500/40 text-green-400',
                    low: 'bg-yellow-900/30 border-yellow-500/40 text-yellow-400',
                    out: 'bg-red-900/30 border-red-500/40 text-red-400 animate-pulse',
                };
                const iconMap = {
                    ok:  'fa-check',
                    low: 'fa-triangle-exclamation',
                    out: 'fa-circle-xmark',
                };
                const labelMap = {
                    ok:  `${stock.qty} ${stock.baseUnit}`,
                    low: `niski ${stock.qty} ${stock.baseUnit}`,
                    out: 'BRAK',
                };
                stockBadge = `<span class="inline-flex items-center gap-1 text-[8px] font-black uppercase px-1.5 py-0.5 rounded border ${toneMap[stock.level]}" title="Stan magazynowy: ${stock.qty} ${stock.baseUnit}"><i class="fa-solid ${iconMap[stock.level]} text-[7px]"></i> ${labelMap[stock.level]}</span>`;
            } else {
                stockBadge = `<span class="inline-flex items-center gap-1 text-[8px] font-black uppercase px-1.5 py-0.5 rounded border bg-slate-800 border-white/10 text-slate-500" title="Brak w magazynie"><i class="fa-solid fa-question text-[7px]"></i> n/a</span>`;
            }

            const rowCostStr = d.rowCost > 0
                ? d.rowCost.toLocaleString('pl-PL', { style: 'currency', currency: 'PLN', minimumFractionDigits: 2, maximumFractionDigits: 4 })
                : '—';

            html += `
            <div class="recipe-row flex items-center justify-between gap-2 p-2 border border-white/10 bg-black/40 rounded-lg hover:border-white/20 transition" data-sku="${_eR(d.ing.warehouseSku)}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-white text-[11px] font-black truncate">${_eR(d.ing.name)}</span>
                        ${stockBadge}
                    </div>
                    <div class="text-slate-600 text-[8px] font-mono truncate">${_eR(d.ing.warehouseSku)} · base: ${_eR(d.ing.baseUnit || 'kg')}</div>
                </div>
                <input type="number" step="0.001" min="0" value="${d.ing.quantityBase}"
                       class="w-16 bg-transparent border border-white/20 text-white text-center text-[11px] p-1 rounded outline-none focus:border-blue-500"
                       onchange="window.RecipeMapper.updateQty(${index}, this.value)" title="Ilość w jednostce użytkowej">
                <select class="bg-black/50 border border-white/10 text-white text-[10px] rounded p-1 outline-none focus:border-blue-500 cursor-pointer"
                        onchange="window.RecipeMapper.updateUnit(${index}, this.value)" title="Jednostka">
                    ${['g','ml','kg','l','szt','por','opak'].map(u => `<option value="${u}" ${u === d.usageUnit ? 'selected' : ''}>${u}</option>`).join('')}
                </select>
                <input type="number" step="0.1" min="0" max="100" value="${d.wastePct}"
                       class="w-12 bg-transparent border border-amber-500/30 text-amber-300 text-center text-[10px] p-1 rounded outline-none focus:border-amber-500"
                       onchange="window.RecipeMapper.updateWaste(${index}, this.value)" title="Ubytek %">
                <span class="text-slate-400 text-[10px] font-mono w-20 text-right shrink-0" title="Koszt wiersza (AVCO × qty × (1+waste%))">${rowCostStr}</span>
                <button class="bg-red-900/30 hover:bg-red-600 text-red-200 hover:text-white w-7 h-7 rounded transition text-[10px] flex items-center justify-center shrink-0"
                        onclick="window.RecipeMapper.removeIngredient(${index})" title="Usuń z receptury">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
            `;
        });
        html += '</div>';

        html += `
            <div class="recipe-footer sticky bottom-0 mt-3 pt-3 border-t border-white/10 bg-[#0a0a0f]/95 backdrop-blur">
                <button type="button" id="btn-save-recipe"
                        onclick="window.RecipeMapper.saveItemRecipe()"
                        class="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-black text-[11px] uppercase tracking-widest py-3 rounded-xl shadow-[0_0_15px_rgba(147,51,234,0.3)] transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Zapisz Recepturę (${this.state.currentRecipe.length})
                </button>
            </div>
        `;

        container.innerHTML = html;
        this._triggerMarginUpdate();
    },

    async saveItemRecipe() {
        const btn = document.getElementById('btn-save-recipe');
        const origHtml = btn ? btn.innerHTML : '';

        if (!this.state.currentMenuItemSku) {
            alert("Błąd: Nie wybrano żadnego dania.");
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Zapisywanie...';
        }

        const payload = {
            menuItemSku: this.state.currentMenuItemSku,
            ingredients: this.state.currentRecipe
        };

        try {
            const response = await this.fetchApi('save_recipe', payload);

            if (response.success === true) {
                console.log("[RecipeMapper] Sukces:", response.message);
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Zapisano!';
                    btn.classList.replace('from-purple-600', 'from-green-600');
                    btn.classList.replace('to-blue-600', 'to-green-500');
                    setTimeout(() => {
                        btn.innerHTML = origHtml || `<i class="fa-solid fa-floppy-disk"></i> Zapisz Recepturę (${this.state.currentRecipe.length})`;
                        btn.classList.replace('from-green-600', 'from-purple-600');
                        btn.classList.replace('to-green-500', 'to-blue-600');
                        btn.disabled = false;
                    }, 1800);
                }
            } else {
                console.error("[RecipeMapper] Błąd:", response.message);
                alert("Wystąpił błąd podczas zapisu: " + response.message);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml || `<i class="fa-solid fa-floppy-disk"></i> Zapisz Recepturę`;
                }
            }
        } catch (err) {
            console.error('[RecipeMapper] network error:', err);
            alert('Krytyczny błąd sieci.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml || `<i class="fa-solid fa-floppy-disk"></i> Zapisz Recepturę`;
            }
        }
    }
};

window.addEventListener('DOMContentLoaded', () => {
    window.RecipeMapper.init();
});