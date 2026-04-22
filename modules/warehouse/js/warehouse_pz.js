/**
 * PZ — przyjęcie zewnętrzne (V2 → PzEngine przez receipt.php).
 */
(function () {
    'use strict';

    let stockRows = [];

    document.addEventListener('DOMContentLoaded', () => {
        initPz();
    });

    window.initPz = async function () {
        await loadWarehousePicker('pz-warehouse');

        const wid = document.getElementById('pz-warehouse')?.value || window.WarehouseApi.DEFAULT_WAREHOUSE_ID;
        const res = await window.WarehouseApi.stockList(wid);
        if (!res.success || !Array.isArray(res.data)) {
            console.error('[PZ] stock_list', res.message);
            alert(res.message || 'Brak danych magazynowych.');
            return;
        }
        stockRows = res.data;

        const whEl = document.getElementById('pz-warehouse');
        if (whEl) {
            whEl.addEventListener('change', async () => {
                const r = await window.WarehouseApi.stockList(whEl.value);
                if (r.success && Array.isArray(r.data)) stockRows = r.data;
            });
        }

        window.addRow();
    };

    async function loadWarehousePicker(selectId) {
        const sel = document.getElementById(selectId);
        if (!sel) return;
        const res = await window.WarehouseApi.getWarehouseList();
        if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            sel.innerHTML = '<option value="">— magazyn —</option>';
            res.data.forEach(wh => {
                const id = typeof wh === 'string' ? wh : (wh.warehouse_id || wh.id);
                const label = typeof wh === 'string' ? wh : (wh.name || wh.warehouse_id || wh.id);
                sel.innerHTML += `<option value="${escapeAttr(id)}">${escapeHtml(label)}</option>`;
            });
        } else {
            const wid = window.WarehouseApi.DEFAULT_WAREHOUSE_ID;
            sel.innerHTML = `<option value="">— magazyn —</option><option value="${wid}">Magazyn główny (${wid})</option>`;
        }
        const saved = localStorage.getItem('sh_warehouse_id');
        if (saved) {
            const exists = Array.from(sel.options).some(o => o.value === saved);
            if (exists) sel.value = saved;
        }
        if (!sel.value) sel.selectedIndex = 1;
    }

    function skuOptionsHtml() {
        return stockRows
            .map(
                (r) =>
                    `<option value="${escapeAttr(r.sku)}">${escapeHtml(r.name)} [${escapeHtml(r.sku)}] — ${escapeHtml(r.base_unit || '')}</option>`
            )
            .join('');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    window.addRow = function () {
        const tbody = document.getElementById('pz-items');
        if (!tbody) return;
        const rowId = Date.now();

        const tr = document.createElement('tr');
        tr.className =
            'group border-l-2 border-transparent hover:border-green-500 transition-colors duration-300';
        tr.id = `row-${rowId}`;
        tr.innerHTML = `
        <td class="py-4 pr-4">
            <input type="text" placeholder="Nazwa z faktury (mapowanie / KSeF)..."
                class="w-full bg-black/50 border border-white/5 rounded-lg p-3 text-xs text-slate-300 outline-none focus:border-green-500 transition invoice-name">
        </td>
        <td class="py-4 pr-4">
            <select class="w-full bg-black border border-white/10 rounded-lg p-3 text-xs font-bold text-green-400 outline-none focus:border-green-500 appearance-none transition system-sku">
                <option value="">— wybierz surowiec (SKU) —</option>
                ${skuOptionsHtml()}
            </select>
        </td>
        <td class="py-4 px-2 text-center">
            <input type="number" step="0.001" min="0" placeholder="0"
                class="w-28 bg-black border border-white/10 rounded-lg p-3 text-center text-white font-mono outline-none focus:border-green-500 transition invoice-qty">
        </td>
        <td class="py-4 px-2 text-center">
            <input type="number" step="0.01" min="0" placeholder="0.00"
                class="w-28 bg-black border border-white/10 rounded-lg p-3 text-center text-white font-mono outline-none focus:border-green-500 transition invoice-price">
        </td>
        <td class="py-4 text-right">
            <button type="button" onclick="window.removeRow(${rowId})" class="text-slate-600 hover:text-red-500 transition px-3 opacity-0 group-hover:opacity-100">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>`;
        tbody.appendChild(tr);
    };

    window.removeRow = function (rowId) {
        const row = document.getElementById(`row-${rowId}`);
        if (row) row.remove();
    };

    window.savePZ = async function () {
        const invoiceNo = document.getElementById('pz-number')?.value.trim();
        const contractor = document.getElementById('pz-contractor')?.value.trim() || 'Dostawca';
        const wid = document.getElementById('pz-warehouse')?.value;

        if (!invoiceNo || !wid) {
            alert('Podaj numer faktury / dokumentu i magazyn.');
            return;
        }

        const rows = document.querySelectorAll('#pz-items tr');
        const lines = [];

        rows.forEach((row) => {
            const extName = row.querySelector('.invoice-name')?.value.trim() || '';
            const sku = row.querySelector('.system-sku')?.value || '';
            const qty = parseFloat(row.querySelector('.invoice-qty')?.value);
            const price = parseFloat(row.querySelector('.invoice-price')?.value);

            if (!sku && !extName) return;
            if (!sku || !Number.isFinite(qty) || qty <= 0 || !Number.isFinite(price) || price < 0) return;
            lines.push({
                external_name: extName || sku,
                resolved_sku:  sku,
                quantity:      qty,
                unit_net_cost: price,
            });
        });

        if (lines.length === 0) {
            alert('Dodaj co najmniej jedną poprawną linię (SKU, ilość > 0, cena netto).');
            return;
        }

        const btn = document.getElementById('btn-save');
        const orig = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Zapis…';
        }

        const res = await window.WarehouseApi.postReceipt({
            warehouse_id:       wid,
            supplier_name:      contractor,
            supplier_invoice:   invoiceNo,
            lines,
        });

        if (btn) {
            btn.disabled = false;
            btn.innerHTML = orig;
        }

        if (res.success) {
            alert('PZ zaksięgowane. Stany i AVCO zaktualizowane.');
            document.getElementById('pz-number').value = '';
            document.getElementById('pz-items').innerHTML = '';
            window.addRow();
        } else {
            alert(res.message || 'Błąd PZ');
        }
    };
})();
