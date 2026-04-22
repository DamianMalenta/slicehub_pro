/**
 * IN — inwentaryzacja fizyczna (V2 → InwEngine przez inventory.php).
 */
(function () {
    'use strict';

    let isBlindMode = true;
    let inventoryData = [];

    document.addEventListener('DOMContentLoaded', () => {
        initWarehouseSelect();
    });

    window.toggleOption = function (el) {
        const icon = el.querySelector('i.fa-toggle-on, i.fa-toggle-off');
        if (!icon) return;
        const isOn = icon.classList.contains('fa-toggle-on');
        if (isOn) {
            icon.classList.replace('fa-toggle-on', 'fa-toggle-off');
            icon.classList.replace('text-amber-500', 'text-slate-600');
        } else {
            icon.classList.replace('fa-toggle-off', 'fa-toggle-on');
            icon.classList.replace('text-slate-600', 'text-amber-500');
        }
    };

    async function initWarehouseSelect() {
        const wh = document.getElementById('warehouse_id');
        if (!wh) return;

        const res = await window.WarehouseApi.getWarehouseList();
        if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            wh.innerHTML = '<option value="">— wybierz magazyn —</option>';
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
            wh.innerHTML =
                `<option value="">— wybierz magazyn —</option>` +
                `<option value="${wid}">Magazyn główny (${wid})</option>`;
        }

        const saved = localStorage.getItem('sh_warehouse_id');
        if (saved) {
            const exists = Array.from(wh.options).some(o => o.value === saved);
            if (exists) wh.value = saved;
        }
        if (wh.value) window.loadInventoryData();
    }

    window.loadInventoryData = async function () {
        const wid = document.getElementById('warehouse_id')?.value;
        if (!wid) return;

        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.classList.remove('hidden');

        const res = await window.WarehouseApi.stockList(wid);

        if (overlay) overlay.classList.add('hidden');

        if (!res.success || !Array.isArray(res.data)) {
            console.error('[IN] stock_list:', res.message);
            alert(res.message || 'Nie udało się pobrać stanów.');
            return;
        }

        inventoryData = res.data.map((row) => ({
            sku:       row.sku,
            name:      row.name,
            base_unit: row.base_unit || '',
            sys_qty:   parseFloat(row.quantity) || 0,
            actual_qty: null,
        }));
        window.renderInventory();
    };

    window.renderInventory = function () {
        const tbody = document.getElementById('inventory-list');
        if (!tbody) return;

        document.getElementById('items-count').innerText =
            `Wczytano: ${inventoryData.length} pozycji`;

        if (inventoryData.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="py-20 text-center text-slate-600 font-bold uppercase text-xs">Brak pozycji w słowniku sys_items.</td></tr>';
            return;
        }

        tbody.innerHTML = inventoryData
            .map(
                (item, idx) => `
        <tr class="hover:bg-white/5 transition group border-l-2 border-transparent hover:border-amber-500">
            <td class="py-4 pr-4">
                <div class="font-bold text-slate-200 text-xs uppercase tracking-wide">${escapeHtml(item.name)}</div>
                <div class="text-[9px] text-slate-600 font-black tracking-widest uppercase mt-1">SKU: ${escapeHtml(item.sku)}</div>
            </td>
            <td class="py-4 text-center sys-qty font-mono text-slate-500 text-sm italic">
                ${item.sys_qty.toFixed(3)}
            </td>
            <td class="py-4 px-4 text-center">
                <input type="number" step="0.001" min="0" placeholder="0.000"
                    class="w-32 bg-black border border-white/10 rounded-xl p-2 text-center text-amber-500 font-black outline-none focus:border-amber-500 transition shadow-inner"
                    oninput="window.updateActualQty(${idx}, this.value)">
            </td>
            <td class="py-4 text-center text-[10px] font-black text-slate-600 uppercase tracking-tighter bg-black/20 rounded-lg">
                ${escapeHtml(item.base_unit)}
            </td>
            <td class="py-4 text-right diff-col font-mono text-xs" id="diff-${idx}">--</td>
        </tr>`
            )
            .join('');

        window.applyBlindModeStyles();
    };

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    window.updateActualQty = function (idx, val) {
        const qty = parseFloat(val);
        inventoryData[idx].actual_qty = Number.isFinite(qty) ? qty : null;

        if (!isBlindMode && Number.isFinite(qty)) {
            const diff = qty - inventoryData[idx].sys_qty;
            const cell = document.getElementById(`diff-${idx}`);
            if (cell) {
                cell.innerText = (diff > 0 ? '+' : '') + diff.toFixed(3);
                cell.className =
                    'py-4 text-right diff-col font-mono text-xs ' +
                    (diff === 0 ? 'text-slate-500' : diff > 0 ? 'text-green-500' : 'text-red-500');
            }
        }
    };

    window.toggleBlindMode = function () {
        isBlindMode = !isBlindMode;
        const icon = document.getElementById('icon-blind');
        if (icon) {
            icon.className = isBlindMode
                ? 'fa-solid fa-toggle-on text-amber-500 text-xl transition-colors'
                : 'fa-solid fa-toggle-off text-slate-600 text-xl transition-colors';
        }
        window.applyBlindModeStyles();
        inventoryData.forEach((item, idx) => {
            const cell = document.getElementById(`diff-${idx}`);
            if (!cell) return;
            if (!isBlindMode && item.actual_qty !== null && Number.isFinite(item.actual_qty)) {
                const diff = item.actual_qty - item.sys_qty;
                cell.innerText = (diff > 0 ? '+' : '') + diff.toFixed(3);
                cell.className =
                    'py-4 text-right diff-col font-mono text-xs ' +
                    (diff === 0 ? 'text-slate-500' : diff > 0 ? 'text-green-500' : 'text-red-500');
            } else {
                cell.innerText = '--';
                cell.className = 'py-4 text-right diff-col font-mono text-xs';
            }
        });
    };

    window.applyBlindModeStyles = function () {
        const table = document.getElementById('in-table');
        if (!table) return;
        table.classList.toggle('blind-mode-active', isBlindMode);
    };

    window.saveInventory = async function () {
        const wid = document.getElementById('warehouse_id')?.value;
        if (!wid) {
            alert('Wybierz magazyn.');
            return;
        }

        const lines = inventoryData
            .filter((i) => i.actual_qty !== null && Number.isFinite(i.actual_qty))
            .map((i) => ({ sku: i.sku, counted_qty: i.actual_qty }));

        if (lines.length === 0) {
            alert('Wpisz co najmniej jedną ilość policzoną.');
            return;
        }

        if (
            !confirm(
                'Zatwierdzić inwentaryzację? Silnik INW (V2) utworzy dokumenty korygujące zgodnie z tolerancjami.'
            )
        ) {
            return;
        }

        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.classList.remove('hidden');

        const res = await window.WarehouseApi.postInventory({
            warehouse_id: wid,
            lines,
            tolerances: {
                auto_approve_pct: 2.0,
                critical_pct:     10.0,
            },
        });

        if (overlay) overlay.classList.add('hidden');

        if (res.success) {
            alert('Inwentaryzacja zaksięgowana.');
            window.location.reload();
        } else {
            alert(res.message || 'Błąd zapisu inwentaryzacji.');
        }
    };
})();
