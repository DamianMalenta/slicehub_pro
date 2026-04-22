/**
 * Centrala magazynowa — słownik sys_items + matryca stanów (V2).
 */
(function () {
    'use strict';

    function loadUnitDictionary() {
        const unitSelect = document.getElementById('new-item-unit');
        if (!unitSelect) return;

        if (typeof SliceValidator !== 'undefined' && SliceValidator.UNITS_CANON) {
            let options = '<option value="">-- jednostka --</option>';
            for (const [key, data] of Object.entries(SliceValidator.UNITS_CANON)) {
                if (data.factor === 1) {
                    options += `<option value="${key}">${data.base} (${key})</option>`;
                }
            }
            unitSelect.innerHTML = options;
            return;
        }

        unitSelect.innerHTML = `
            <option value="">-- jednostka --</option>
            <option value="kg">kg</option>
            <option value="l">l</option>
            <option value="szt">szt</option>
            <option value="pcs">pcs</option>`;
    }

    window.createItem = async function () {
        const nameInput = document.getElementById('new-item-name');
        const unitInput = document.getElementById('new-item-unit');
        const name = nameInput?.value.trim() || '';
        const unit = unitInput?.value || '';

        if (!name || !unit) {
            alert('Wypełnij nazwę i jednostkę bazową.');
            return;
        }

        let sku;
        if (typeof SliceValidator !== 'undefined' && SliceValidator.sanitizeKey) {
            sku = String(SliceValidator.sanitizeKey(name) || '')
                .toUpperCase()
                .slice(0, 64);
        } else {
            sku = name
                .replace(/\s+/g, '_')
                .replace(/[^A-Za-z0-9_]/g, '')
                .toUpperCase()
                .slice(0, 64);
        }
        if (!sku) sku = 'SKU_' + Date.now();

        const res = await window.WarehouseApi.postAddItem({
            name,
            base_unit: unit,
            sku,
        });

        if (res.success) {
            nameInput.value = '';
            unitInput.value = '';
            await window.initCatalogMatrix();
        } else {
            alert(res.message || 'Błąd zapisu surowca.');
        }
    };

    window.initCatalogMatrix = async function () {
        const tbody = document.getElementById('matrix-body');
        const thead = document.getElementById('matrix-head');
        if (!tbody || !thead) return;

        tbody.innerHTML =
            '<tr><td colspan="4" class="py-10 text-center text-blue-500 font-bold uppercase text-xs"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Ładowanie…</td></tr>';

        const res = await window.WarehouseApi.stockList();

        thead.innerHTML = `
            <th class="pb-4 pr-4">Surowiec (sys_items)</th>
            <th class="pb-4 pr-4 text-center">J.m.</th>
            <th class="pb-4 text-center">Stan (${window.WarehouseApi.DEFAULT_WAREHOUSE_ID})</th>
            <th class="pb-4 text-right">AVCO netto</th>`;

        if (!res.success || !Array.isArray(res.data)) {
            tbody.innerHTML =
                '<tr><td colspan="4" class="py-10 text-center text-red-500 font-bold uppercase text-xs">Błąd pobierania danych</td></tr>';
            return;
        }

        if (res.data.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="4" class="py-10 text-center text-slate-500 font-bold uppercase text-xs">Brak pozycji — dodaj pierwszy surowiec.</td></tr>';
            return;
        }

        tbody.innerHTML = res.data
            .map((r) => {
                const qty = parseFloat(r.quantity) || 0;
                const av = parseFloat(r.current_avco_price) || 0;
                const qtyClass =
                    qty < 0 ? 'negative-stock' : qty > 0 ? 'positive-stock' : 'text-slate-500';
                return `<tr class="border-b border-white/5 hover:bg-white/5 transition group">
                    <td class="py-4 pr-4 font-bold text-xs uppercase text-slate-200 tracking-wide">${escapeHtml(r.name)}</td>
                    <td class="py-4 pr-4 text-center text-slate-500 text-[10px] font-black uppercase bg-black/20 rounded-lg">${escapeHtml(r.base_unit || '')}</td>
                    <td class="py-4 text-center text-sm font-mono ${qtyClass}">${qty.toFixed(3)}</td>
                    <td class="py-4 text-right text-sm font-mono text-slate-300">${av.toFixed(4)}</td>
                </tr>`;
            })
            .join('');
    };

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadUnitDictionary();
        window.initCatalogMatrix();
    });
})();
