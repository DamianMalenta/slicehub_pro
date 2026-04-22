/**
 * Kolejka akceptacji INW (V2 → documents_list + approve.php).
 */
(function () {
    'use strict';
    const _e = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    document.addEventListener('DOMContentLoaded', load);

    async function load() {
        const res = await window.WarehouseApi.getDocumentsList({ type: 'INW', status: 'pending_approval', limit: '100' });
        const container = document.getElementById('queue');
        if (!container) return;

        if (!res.success || !res.data) {
            container.innerHTML = '<p class="text-center text-red-400 py-10">' + _e(res.message || 'Błąd') + '</p>';
            return;
        }

        const docs = res.data.documents || [];
        if (docs.length === 0) {
            container.innerHTML = `
                <div class="glass p-12 rounded-3xl text-center">
                    <i class="fa-solid fa-circle-check text-5xl text-emerald-500 mb-4"></i>
                    <p class="text-lg font-bold text-white">Brak oczekujących inwentaryzacji</p>
                    <p class="text-slate-500 text-sm mt-2">Wszystkie dokumenty INW są zatwierdzone lub odrzucone.</p>
                </div>`;
            return;
        }

        container.innerHTML = docs.map(d => {
            const date = (d.created_at || '').replace('T', ' ').slice(0, 19);
            const level = d.required_approval_level || '—';
            return `
            <div class="glass p-6 rounded-2xl border-l-4 border-l-amber-500" data-id="${d.id}">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <span class="text-xs font-mono text-amber-400 font-bold">${esc(d.doc_number || '—')}</span>
                        <span class="ml-3 text-[10px] bg-amber-500/10 text-amber-300 px-2 py-0.5 rounded border border-amber-500/20 font-bold uppercase">${esc(level)}</span>
                    </div>
                    <span class="text-[10px] text-slate-600">${date}</span>
                </div>
                <div class="text-xs text-slate-400 mb-1">Magazyn: <span class="text-white font-bold">${esc(d.warehouse_id || '—')}</span> · Pozycji: <span class="text-white font-bold">${d.line_count}</span> · Wartość: <span class="text-white font-bold">${parseFloat(d.total_net_value || 0).toFixed(2)} zł</span></div>
                ${d.notes ? `<div class="text-[10px] text-slate-600 mt-1 italic">${esc(d.notes)}</div>` : ''}
                <div class="flex gap-3 mt-5">
                    <button type="button" class="btn-approve flex-1 bg-emerald-600 hover:bg-emerald-500 text-white py-3 rounded-xl font-black uppercase text-xs tracking-widest transition">
                        <i class="fa-solid fa-check mr-2"></i>Zatwierdź
                    </button>
                    <button type="button" class="btn-reject flex-1 bg-red-900/50 hover:bg-red-800/50 text-red-400 py-3 rounded-xl font-black uppercase text-xs tracking-widest transition border border-red-500/30">
                        <i class="fa-solid fa-xmark mr-2"></i>Odrzuć
                    </button>
                </div>
            </div>`;
        }).join('');

        container.querySelectorAll('.btn-approve').forEach(btn => {
            btn.addEventListener('click', () => decide(btn, 'approve'));
        });
        container.querySelectorAll('.btn-reject').forEach(btn => {
            btn.addEventListener('click', () => decide(btn, 'reject'));
        });
    }

    async function decide(btn, decision) {
        const card = btn.closest('[data-id]');
        const docId = parseInt(card?.dataset.id, 10);
        if (!docId) return;

        if (decision === 'approve' && !confirm('Zatwierdź inwentaryzację? Stany magazynowe zostaną wyrównane.')) return;
        if (decision === 'reject' && !confirm('Odrzucić inwentaryzację? Stany nie zostaną zmienione.')) return;

        const res = await window.WarehouseApi.postApproval({ document_id: docId, decision });
        if (res.success) {
            card.remove();
            const remaining = document.querySelectorAll('[data-id]');
            if (remaining.length === 0) load();
        } else {
            alert(res.message || 'Błąd akceptacji');
        }
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
})();
