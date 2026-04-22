/**
 * MM — przesunięcie międzymagazynowe (V2 → transfer.php / MmEngine).
 */
(function () {
    'use strict';

    let warehouses = [];
    let stockRows = [];
    let lines = [];

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        const whRes = await window.WarehouseApi.getWarehouseList();
        if (whRes.success && Array.isArray(whRes.data)) {
            warehouses = whRes.data;
        }
        if (warehouses.length === 0) warehouses = [{ warehouse_id: 'MAIN' }];

        fillSelect('mm-source', warehouses);
        fillSelect('mm-target', warehouses);

        const src = document.getElementById('mm-source');
        if (src) src.addEventListener('change', loadSourceStock);
        await loadSourceStock();
    }

    function fillSelect(id, list) {
        const sel = document.getElementById(id);
        if (!sel) return;
        sel.innerHTML = list.map(w =>
            `<option value="${esc(w.warehouse_id)}">${esc(w.warehouse_id)}</option>`
        ).join('');
    }

    async function loadSourceStock() {
        const wid = document.getElementById('mm-source')?.value || 'MAIN';
        const res = await window.WarehouseApi.stockList(wid);
        stockRows = res.success && Array.isArray(res.data) ? res.data : [];
        const sel = document.getElementById('mm-sku');
        if (!sel) return;
        sel.innerHTML = '<option value="">— surowiec —</option>' +
            stockRows.map(r =>
                `<option value="${esc(r.sku)}">${esc(r.name)} [${esc(r.sku)}] — ${parseFloat(r.quantity || 0).toFixed(3)} ${esc(r.base_unit || '')}</option>`
            ).join('');
    }

    window.mmAddLine = function () {
        const sku = document.getElementById('mm-sku')?.value;
        const qty = parseFloat(document.getElementById('mm-qty')?.value);
        if (!sku || !Number.isFinite(qty) || qty <= 0) { alert('Wybierz SKU i ilość > 0.'); return; }
        const row = stockRows.find(r => r.sku === sku);
        lines.push({ sku, name: row?.name || sku, qty });
        document.getElementById('mm-qty').value = '1';
        render();
    };

    function render() {
        const tbody = document.getElementById('mm-lines');
        if (!tbody) return;
        if (lines.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="py-16 text-center text-slate-600 font-bold uppercase text-xs italic">Dodaj pozycje powyżej</td></tr>';
            return;
        }
        tbody.innerHTML = lines.map((l, i) => `
            <tr class="border-b border-white/5 hover:bg-white/5 transition">
                <td class="py-3"><span class="font-bold text-xs text-slate-200">${esc(l.name)}</span> <span class="text-[9px] text-slate-600 ml-2">${esc(l.sku)}</span></td>
                <td class="py-3 text-center font-mono text-white text-sm">${l.qty.toFixed(3)}</td>
                <td class="py-3 text-right"><button type="button" class="text-slate-600 hover:text-red-500 transition" data-i="${i}"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
        `).join('');
        tbody.querySelectorAll('button[data-i]').forEach(btn => {
            btn.addEventListener('click', () => { lines.splice(+btn.dataset.i, 1); render(); });
        });
    }

    window.submitMM = async function () {
        const source = document.getElementById('mm-source')?.value;
        const target = document.getElementById('mm-target')?.value;
        if (!source || !target) { alert('Wybierz oba magazyny.'); return; }
        if (source === target) { alert('Magazyn źródłowy i docelowy muszą być różne.'); return; }
        if (lines.length === 0) { alert('Dodaj co najmniej jedną pozycję.'); return; }

        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.classList.remove('hidden');

        const res = await window.WarehouseApi.postTransfer({
            source_warehouse_id: source,
            target_warehouse_id: target,
            lines: lines.map(l => ({ sku: l.sku, quantity: l.qty })),
        });

        if (overlay) overlay.classList.add('hidden');

        if (res.success) {
            alert('Przesunięcie zaksięgowane.');
            lines = [];
            render();
            await loadSourceStock();
        } else {
            alert(res.message || 'Błąd MM');
        }
    };

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
})();
