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
            <div class="flex gap-1.5 items-start">
                <input type="text" placeholder="Nazwa z faktury (AutoScan)..."
                    class="flex-1 bg-black/50 border border-white/5 rounded-lg p-3 text-xs text-slate-300 outline-none focus:border-green-500 transition invoice-name">
                <button type="button" class="autoscan-btn shrink-0 px-2 py-2 bg-blue-600/80 hover:bg-blue-500 text-white rounded-lg text-[10px] font-bold uppercase tracking-wide transition"
                        title="Sprawdź sugestię z AutoScan (F2.5)">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </button>
            </div>
            <div class="autoscan-status hidden mt-1.5 text-[10px] flex items-center gap-1.5"></div>
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

        // F2.5 (2026-05-11): AutoScan suggest button + auto-on-blur
        const btnScan = tr.querySelector('.autoscan-btn');
        const nameInput = tr.querySelector('.invoice-name');
        const handler = () => runAutoScan(tr);
        if (btnScan) btnScan.addEventListener('click', handler);
        if (nameInput) {
            nameInput.addEventListener('blur', () => {
                if ((nameInput.value || '').trim().length >= 3) handler();
            });
        }
    };

    // =========================================================================
    // F2.5 — Shared AutoScan suggest (woła /api/procurement/suggest.php).
    // Konstytucja v5 § Prawo IV: frontend wysyła tylko external_name,
    // serwer zwraca {sku, confidence, candidates}. Auto-fill SKU select
    // gdy should_auto_accept=true; w przeciwnym wypadku pokazuje
    // listę candidates z confidence pill (manual confirm).
    // =========================================================================
    async function runAutoScan(rowEl) {
        const nameInput = rowEl.querySelector('.invoice-name');
        const skuSelect = rowEl.querySelector('.system-sku');
        const statusEl  = rowEl.querySelector('.autoscan-status');
        if (!nameInput || !skuSelect || !statusEl) return;

        const extName = (nameInput.value || '').trim();
        if (extName.length < 3) {
            statusEl.className = 'autoscan-status mt-1.5 text-[10px] text-slate-500';
            statusEl.innerHTML = '<i class="fa-solid fa-circle-info"></i> Wpisz min. 3 znaki';
            statusEl.classList.remove('hidden');
            return;
        }

        statusEl.classList.remove('hidden');
        statusEl.className = 'autoscan-status mt-1.5 text-[10px] text-slate-400';
        statusEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> AutoScan…';

        try {
            const token = localStorage.getItem('sh_token') || '';
            const fb = (window.SliceHub && window.SliceHub.getApiFallback)
                ? window.SliceHub.getApiFallback()
                : '/api';
            const suggestUrl = (window.SliceHub && window.SliceHub.apiUrl)
                ? window.SliceHub.apiUrl('procurement/suggest.php')
                : fb + '/procurement/suggest.php';
            const res = await fetch(suggestUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': token ? 'Bearer ' + token : '',
                },
                body: JSON.stringify({ action: 'suggest', external_name: extName }),
            });
            const json = await res.json();
            if (!json.success) {
                statusEl.className = 'autoscan-status mt-1.5 text-[10px] text-red-400';
                statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (json.message || 'Błąd AutoScan');
                return;
            }

            const d = json.data;
            renderAutoScanResult(rowEl, d, skuSelect, statusEl);
        } catch (e) {
            statusEl.className = 'autoscan-status mt-1.5 text-[10px] text-red-400';
            statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Błąd sieci: ' + (e.message || e);
        }
    }

    function renderAutoScanResult(rowEl, data, skuSelect, statusEl) {
        const { match_type, confidence, sku, sku_name, should_auto_accept, should_auto_learn, candidates } = data;

        // Pill color per confidence level
        let cls = 'text-slate-400';
        let icon = 'fa-question';
        if (match_type === 'EXACT') { cls = 'text-emerald-300'; icon = 'fa-check-double'; }
        else if (match_type === 'ALIAS') { cls = 'text-emerald-300'; icon = 'fa-link'; }
        else if (match_type === 'NAME') { cls = 'text-amber-300'; icon = 'fa-check'; }
        else if (match_type === 'FUZZY') { cls = 'text-orange-300'; icon = 'fa-magnifying-glass'; }
        else { cls = 'text-red-400'; icon = 'fa-circle-xmark'; }

        const learnBadge = should_auto_learn
            ? ' <span class="ml-1 px-1.5 py-0.5 bg-emerald-500/20 text-emerald-300 rounded text-[9px]">+LEARN</span>'
            : '';

        let html = `<span class="${cls}"><i class="fa-solid ${icon}"></i> ${escapeHtml(match_type)} ${confidence}%</span>`;
        if (sku) {
            html += ` → <code class="text-green-400">${escapeHtml(sku)}</code> <span class="text-slate-500">${escapeHtml(sku_name || '')}</span>${learnBadge}`;
        }

        // Auto-fill SKU select gdy should_auto_accept
        if (should_auto_accept && sku) {
            const exists = Array.from(skuSelect.options).some(o => o.value === sku);
            if (exists) {
                skuSelect.value = sku;
                html += ' <span class="ml-1 text-emerald-300 text-[9px] uppercase font-bold">[wypełniono]</span>';
            }
        }

        // Multiple candidates → expandable list z one-click apply
        if (candidates && candidates.length > 1) {
            html += '<details class="ml-2 inline-block"><summary class="cursor-pointer text-slate-500 hover:text-slate-300 text-[10px]">+' + (candidates.length - 1) + ' inne</summary>';
            html += '<div class="mt-1 pl-3 border-l border-slate-700 space-y-0.5">';
            candidates.slice(1).forEach(c => {
                const ccls = c.match_type === 'EXACT' || c.match_type === 'ALIAS' ? 'text-emerald-400'
                          : c.match_type === 'NAME' ? 'text-amber-400'
                          : 'text-slate-400';
                html += `<button type="button" class="block text-left text-[10px] ${ccls} hover:underline" onclick="window.applyAutoScanCandidate(this, '${escapeAttr(c.sku)}')"><i class="fa-solid fa-arrow-right text-[8px]"></i> ${escapeHtml(c.sku)} ${c.confidence}% (${escapeHtml(c.match_type)}) — ${escapeHtml(c.name)}</button>`;
            });
            html += '</div></details>';
        }

        statusEl.className = 'autoscan-status mt-1.5 text-[10px] flex items-center gap-1.5 flex-wrap';
        statusEl.innerHTML = html;
    }

    window.applyAutoScanCandidate = function (btn, sku) {
        const tr = btn.closest('tr');
        if (!tr) return;
        const skuSelect = tr.querySelector('.system-sku');
        if (!skuSelect) return;
        const exists = Array.from(skuSelect.options).some(o => o.value === sku);
        if (exists) {
            skuSelect.value = sku;
            const statusEl = tr.querySelector('.autoscan-status');
            if (statusEl) {
                statusEl.innerHTML += ' <span class="ml-2 text-emerald-300 font-bold text-[10px]">✓ wybrano ' + sku + '</span>';
            }
        }
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
            const autoLearned = res.data?.pz_document?.auto_learned || 0;
            let msg = 'PZ zaksięgowane. Stany i AVCO zaktualizowane.';
            if (autoLearned > 0) {
                msg += `\n\n🧠 AutoScan zapamiętał ${autoLearned} nowych mapowań — następna dostawa od tego dostawcy będzie szybsza.`;
            }
            alert(msg);
            document.getElementById('pz-number').value = '';
            document.getElementById('pz-items').innerHTML = '';
            window.addRow();
        } else {
            // F2.5: jeśli backend zwrócił candidates (UNMAPPED_PRODUCT), pokaż user-friendly hint
            const data = res.data || {};
            if (data.candidates && data.candidates.length > 0) {
                const top = data.candidates[0];
                alert((res.message || 'Błąd PZ') + `\n\nNajlepsza propozycja: ${top.sku} '${top.name}' @ ${top.confidence}% ${top.match_type}.\n\nKliknij ikonkę 🪄 obok pola "Nazwa z faktury" żeby zobaczyć więcej i wybrać.`);
            } else {
                alert(res.message || 'Błąd PZ');
            }
        }
    };
})();
