/**
 * RW — rozchód wewnętrzny / strata (V2 → batch_rw.php, 1 dokument = N linii).
 */
(function () {
    'use strict';
    const _e = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    let stockRows = [];
    let currentItems = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadStockForSelect();
    });

    async function loadStockForSelect() {
        await loadWarehousePicker();

        const wid = document.getElementById('warehouse_id')?.value || window.WarehouseApi.DEFAULT_WAREHOUSE_ID;
        const res = await window.WarehouseApi.stockList(wid);
        if (!res.success || !Array.isArray(res.data)) {
            console.error('[RW] stock_list', res.message);
            return;
        }
        stockRows = res.data;
        populateProductSelect();

        const whEl = document.getElementById('warehouse_id');
        if (whEl) {
            whEl.addEventListener('change', async () => {
                const r = await window.WarehouseApi.stockList(whEl.value);
                if (r.success && Array.isArray(r.data)) {
                    stockRows = r.data;
                    populateProductSelect();
                }
            });
        }
    }

    function populateProductSelect() {
        const sel = document.getElementById('product_selector');
        if (!sel) return;
        sel.innerHTML = '<option value="">— wybierz surowiec —</option>';
        stockRows.forEach((r) => {
            const av = parseFloat(r.current_avco_price) || 0;
            const opt = document.createElement('option');
            opt.value = r.sku;
            opt.textContent = `${r.name} [${r.sku}] — ${av.toFixed(2)} PLN / ${r.base_unit || ''}`;
            sel.appendChild(opt);
        });
    }

    async function loadWarehousePicker() {
        const wh = document.getElementById('warehouse_id');
        if (!wh) return;
        const res = await window.WarehouseApi.getWarehouseList();
        if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            wh.innerHTML = '';
            res.data.forEach(item => {
                const id = typeof item === 'string' ? item : (item.warehouse_id || item.id);
                const label = typeof item === 'string' ? item : (item.name || item.warehouse_id || item.id);
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = label;
                wh.appendChild(opt);
            });
        } else {
            const wid = window.WarehouseApi.DEFAULT_WAREHOUSE_ID;
            wh.innerHTML = `<option value="${wid}" selected>Magazyn główny (${wid})</option>`;
        }
        const saved = localStorage.getItem('sh_warehouse_id');
        if (saved) {
            const exists = Array.from(wh.options).some(o => o.value === saved);
            if (exists) wh.value = saved;
        }
    }

    function rowBySku(sku) {
        return stockRows.find((r) => r.sku === sku);
    }

    window.sanitizeDocNumber = function () {
        const input = document.getElementById('doc_number');
        if (!input) return;
        if (typeof SliceValidator !== 'undefined' && SliceValidator.sanitizeKey) {
            input.value = SliceValidator.sanitizeKey(input.value).toUpperCase();
        } else {
            input.value = String(input.value || '')
                .replace(/\s+/g, '_')
                .replace(/[^A-Za-z0-9_/-]/g, '')
                .toUpperCase();
        }
    };

    window.addItemToTable = function () {
        const sku = document.getElementById('product_selector')?.value;
        const rawQty = parseFloat(document.getElementById('prod_qty')?.value);
        const reason = document.getElementById('prod_reason')?.value || '';

        if (!sku || !Number.isFinite(rawQty) || rawQty <= 0) {
            alert('Wybierz surowiec i podaj ilość > 0.');
            return;
        }

        const row = rowBySku(sku);
        if (!row) return;

        let finalQty = rawQty;
        if (typeof SliceValidator !== 'undefined' && SliceValidator.standardizeUnit) {
            const inputUnit = document.getElementById('prod_unit')?.value || row.base_unit;
            const conversion = SliceValidator.standardizeUnit(rawQty, inputUnit);
            finalQty = conversion.value;
        }

        const av = parseFloat(row.current_avco_price) || 0;
        const itemCost = finalQty * av;

        currentItems.push({
            sku,
            name: row.name,
            sys_qty: finalQty,
            reason,
            cost: itemCost,
        });

        document.getElementById('prod_qty').value = '1';
        renderTable();
    };

    function renderTable() {
        const tbody = document.getElementById('added_items_list');
        if (!tbody) return;

        const countEl = document.getElementById('items-count');
        if (countEl) countEl.innerText = `Pozycji: ${currentItems.length}`;

        let totalBleed = 0;

        if (currentItems.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="py-20 text-center text-slate-600 font-bold uppercase text-xs tracking-widest italic">Dokument jest pusty.</td></tr>';
            const tb = document.getElementById('total_bleed');
            if (tb) tb.innerText = '0.00';
            return;
        }

        tbody.innerHTML = '';
        currentItems.forEach((item, index) => {
            totalBleed += item.cost;
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-white/5 transition group';
            tr.innerHTML = `
                <td class="p-4 border-b border-white/5">
                    <div class="font-bold text-red-400 text-xs">${_e(item.name)}</div>
                    <div class="text-[9px] text-slate-600 tracking-widest mt-1 uppercase">SKU: ${_e(item.sku)}</div>
                </td>
                <td class="p-4 border-b border-white/5 text-center font-mono text-white text-sm">${item.sys_qty.toFixed(3)}</td>
                <td class="p-4 border-b border-white/5 text-xs font-bold text-slate-400">${_e(item.reason)}</td>
                <td class="p-4 border-b border-white/5 text-right font-mono text-red-500 font-bold">-${item.cost.toFixed(2)} PLN</td>
                <td class="p-4 border-b border-white/5 text-center">
                    <button type="button" class="text-slate-600 hover:text-red-500 transition px-2" data-idx="${index}">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>`;
            tr.querySelector('button').addEventListener('click', () => {
                currentItems.splice(index, 1);
                renderTable();
            });
            tbody.appendChild(tr);
        });

        const tb = document.getElementById('total_bleed');
        if (tb) tb.innerText = totalBleed.toFixed(2);
    }

    window.saveDocument = async function () {
        const doc = document.getElementById('doc_number')?.value.trim();
        const wid = document.getElementById('warehouse_id')?.value;
        const reqPin = document.getElementById('req_pin')?.checked;
        let totalBleed = currentItems.reduce((s, i) => s + i.cost, 0);

        if (!doc) {
            alert('Podaj numer dokumentu (referencyjny).');
            return;
        }
        if (!wid) {
            alert('Wybierz magazyn.');
            return;
        }
        if (currentItems.length === 0) {
            alert('Brak pozycji na liście.');
            return;
        }

        if (reqPin && totalBleed > 50) {
            if (!confirm(`Strata przekracza 50 PLN netto (${totalBleed.toFixed(2)} PLN). Czy potwierdzasz zaksięgowanie?`)) {
                return;
            }
        }

        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.classList.remove('hidden');

        try {
            const reasons = [...new Set(currentItems.map(i => i.reason).filter(Boolean))];
            const fullReason = [doc, ...reasons].join(' | ').slice(0, 500);

            const res = await window.WarehouseApi.postBatchRw({
                warehouse_id: wid,
                reason: fullReason,
                lines: currentItems.map(item => ({ sku: item.sku, qty: item.sys_qty })),
            });
            if (!res.success) {
                throw new Error(res.message || 'Błąd RW');
            }
            alert(res.message || `Zaksięgowano ${currentItems.length} poz. rozchodu wewnętrznego.`);
            currentItems = [];
            renderTable();
            document.getElementById('doc_number').value = '';
        } catch (e) {
            alert(e.message || String(e));
        } finally {
            if (overlay) overlay.classList.add('hidden');
        }
    };
})();
