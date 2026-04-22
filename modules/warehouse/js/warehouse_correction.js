/**
 * KOR — korekta WZ (V2 → correction.php / KorEngine).
 */
(function () {
    'use strict';

    window.submitKOR = async function () {
        const orderId = document.getElementById('kor-order-id')?.value.trim();
        const reason  = document.getElementById('kor-reason')?.value || '';
        const note    = document.getElementById('kor-note')?.value.trim() || '';
        const fullReason = note ? `${reason} — ${note}` : reason;

        if (!orderId) { alert('Podaj ID zamówienia (order_id).'); return; }

        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.classList.remove('hidden');

        const res = await window.WarehouseApi.postCorrection({
            order_id: orderId,
            reason:   fullReason,
        });

        if (overlay) overlay.classList.add('hidden');

        if (res.success) {
            const doc = res.data?.kor_document;
            const linesHtml = (doc?.lines || []).map(l =>
                `<tr class="border-b border-white/5">
                    <td class="py-2 text-xs font-mono text-slate-300">${esc(l.sku)}</td>
                    <td class="py-2 text-center text-sm font-mono text-emerald-400">+${parseFloat(l.quantity_returned).toFixed(3)}</td>
                    <td class="py-2 text-right text-xs font-mono text-slate-500">${l.avco_at_original} PLN</td>
                </tr>`
            ).join('');

            const preview = document.getElementById('kor-preview');
            const tbody   = document.getElementById('kor-lines');
            if (tbody && preview) {
                tbody.innerHTML = linesHtml || '<tr><td colspan="3" class="text-slate-500 py-3 text-center">Brak linii</td></tr>';
                preview.classList.remove('hidden');
            }

            alert(`Korekta ${doc?.doc_number || ''} zaksięgowana. Surowce wróciły do magazynu.`);
            document.getElementById('kor-order-id').value = '';
        } else {
            alert(res.message || 'Błąd korekty.');
        }
    };

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
})();
