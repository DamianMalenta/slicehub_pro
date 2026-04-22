/**
 * Rejestr dokumentów magazynowych (V2 → documents_list.php).
 */
(function () {
    'use strict';

    let currentType = '';
    let currentOffset = 0;
    const PAGE = 30;
    let total = 0;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                currentType = btn.dataset.type || '';
                currentOffset = 0;
                load();
            });
        });
        load();
    });

    async function load() {
        const params = { limit: String(PAGE), offset: String(currentOffset) };
        if (currentType) params.type = currentType;

        const res = await window.WarehouseApi.getDocumentsList(params);
        if (!res.success || !res.data) { alert(res.message || 'Błąd'); return; }

        total = res.data.total || 0;
        const docs = res.data.documents || [];

        document.getElementById('doc-count').textContent =
            `Wyświetlono ${docs.length} z ${total}` + (currentType ? ` (typ: ${currentType})` : '');

        document.getElementById('btn-prev').disabled = currentOffset === 0;
        document.getElementById('btn-next').disabled = currentOffset + PAGE >= total;

        const tbody = document.getElementById('doc-body');
        if (docs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="py-16 text-center text-slate-600 font-bold uppercase text-xs">Brak dokumentów.</td></tr>';
            return;
        }

        tbody.innerHTML = docs.map(d => {
            const t = (d.type || '').toUpperCase();
            const badge = `badge-${t.toLowerCase()}`;
            const wh = [d.warehouse_id, d.target_warehouse_id].filter(Boolean).join(' → ');
            const val = parseFloat(d.total_net_value || 0).toFixed(2);
            const date = (d.created_at || '').replace('T', ' ').slice(0, 19);
            return `<tr class="border-b border-white/5 hover:bg-white/5 transition">
                <td class="py-3 text-xs font-mono text-slate-200">${esc(d.doc_number || '—')}</td>
                <td class="py-3"><span class="px-2 py-0.5 rounded text-[10px] font-black uppercase ${badge}">${t}</span></td>
                <td class="py-3 text-[10px] font-bold uppercase tracking-widest ${d.status === 'pending_approval' ? 'text-amber-400' : d.status === 'rejected' ? 'text-red-400' : 'text-slate-500'}">${esc(d.status)}</td>
                <td class="py-3 text-xs text-slate-400">${esc(wh)}</td>
                <td class="py-3 text-center text-sm font-mono text-white">${d.line_count}</td>
                <td class="py-3 text-right text-sm font-mono text-slate-300">${val} zł</td>
                <td class="py-3 text-right text-xs text-slate-600">${date}</td>
            </tr>`;
        }).join('');
    }

    window.prevPage = function () { if (currentOffset >= PAGE) { currentOffset -= PAGE; load(); } };
    window.nextPage = function () { if (currentOffset + PAGE < total) { currentOffset += PAGE; load(); } };

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
})();
