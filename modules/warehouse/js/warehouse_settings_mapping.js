/**
 * Mapowanie nazw faktur → SKU (sh_product_mapping, V2).
 */
(function () {
    'use strict';

    async function refresh() {
        const res = await window.WarehouseApi.getMappingList();
        if (!res.success || !res.data) {
            alert(res.message || 'Błąd pobierania mapowań.');
            return;
        }

        const items = res.data.items || [];
        const mappings = res.data.mappings || [];

        const sel = document.getElementById('map-int-id');
        if (sel) {
            sel.innerHTML =
                '<option value="">-- wybierz surowiec (SKU) --</option>' +
                items
                    .map(
                        (p) =>
                            `<option value="${escapeAttr(p.sku)}">${escapeHtml(p.name)} [${escapeHtml(p.sku)}]</option>`
                    )
                    .join('');
        }

        const tbody = document.getElementById('mapping-list');
        const cnt = document.getElementById('mapping-count');
        if (cnt) cnt.innerText = String(mappings.length);

        if (!tbody) return;

        if (mappings.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="3" class="text-center py-10 text-slate-500 font-bold">Brak reguł. Dodaj pierwszą powyżej.</td></tr>';
            return;
        }

        tbody.innerHTML = mappings
            .map(
                (m) => `
            <tr class="border-b border-white/5 hover:bg-white/5 transition group">
                <td class="py-4 font-bold text-slate-300 text-sm tracking-wide">
                    <i class="fa-solid fa-file-invoice text-slate-600 mr-2 text-[10px]"></i> ${escapeHtml(m.external_name)}
                </td>
                <td class="py-4 font-black text-blue-400 text-sm italic">
                    <i class="fa-solid fa-box text-blue-900 mr-2 text-[10px]"></i> ${escapeHtml(m.internal_name)} <span class="text-slate-500 font-mono text-[10px]">(${escapeHtml(m.internal_sku)})</span>
                </td>
                <td class="py-4 text-right">
                    <button type="button" class="text-slate-600 hover:text-red-500 transition p-2 bg-black/30 rounded-lg" data-del="${m.id}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>`
            )
            .join('');

        tbody.querySelectorAll('button[data-del]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.getAttribute('data-del'), 10);
                if (!confirm('Usunąć to mapowanie?')) return;
                const r = await window.WarehouseApi.deleteMapping(id);
                if (r.success) await refresh();
                else alert(r.message || 'Błąd usuwania');
            });
        });
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

    window.saveMapping = async function () {
        let extName = document.getElementById('map-ext-name')?.value.trim() || '';
        const sku = document.getElementById('map-int-id')?.value || '';

        if (!extName || !sku) {
            alert('Wypełnij nazwę z faktury i wybierz surowiec (SKU).');
            return;
        }

        extName = extName.replace(/</g, '').replace(/>/g, '').trim();

        const res = await window.WarehouseApi.saveMapping(extName, sku);
        if (res.success) {
            document.getElementById('map-ext-name').value = '';
            await refresh();
        } else {
            alert(res.message || 'Błąd zapisu');
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        refresh();
    });
})();
