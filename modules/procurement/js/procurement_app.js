/**
 * SliceHub — Inbox KSeF (Procurement) frontend
 * Vanilla JS + dark glass (Konstytucja v5 § Prawo VI).
 *
 * Flow:
 *   1. User dragguje FA(2) XML do dropzone → readAsText → POST upload_xml.
 *   2. Lista invoices ładuje się przez `list` action (stats badge).
 *   3. Klik na row → modal z `show` action: invoice + lines + match.
 *   4. Per linia: select SKU (manual override) z `update_line` action.
 *   5. Klik "Akceptuj" → `accept` action → PzEngine utworzy PZ → stany rosną.
 */
(function () {
    'use strict';

    const ENDPOINT = '/api/procurement/inbox.php';
    const SUGGEST_ENDPOINT = '/api/procurement/suggest.php';
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));

    let state = {
        loaded: false,
        invoices: [],
        stats: {},
        filterStatus: '',
        currentInvoice: null,
        currentLines: [],
        threshold: 70,
        skuOptions: [], // dla select-ów per linia (cache sys_items)
    };

    // -------------------------------------------------------------------------
    // API
    // -------------------------------------------------------------------------
    function getToken() {
        return localStorage.getItem('sh_token') || '';
    }

    async function api(action, body = {}, endpoint = ENDPOINT) {
        const tok = getToken();
        if (!tok) {
            return { success: false, message: 'Brak tokenu — zaloguj się w Hub.', code: 'NO_TOKEN' };
        }
        let res;
        try {
            res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + tok,
                },
                body: JSON.stringify({ action, ...body }),
            });
        } catch (e) {
            return { success: false, message: 'Błąd sieci: ' + (e.message || e), code: 'NETWORK' };
        }
        const text = await res.text();
        try { return JSON.parse(text); }
        catch (e) {
            return { success: false, message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').', raw: text.slice(0, 300) };
        }
    }

    // -------------------------------------------------------------------------
    // SKU options (z stock_list — fallback, ale jak masz dużo SKU, lepiej zostawić select pusty z search)
    // -------------------------------------------------------------------------
    async function loadSkuOptions() {
        try {
            const tok = getToken();
            const res = await fetch('/api/warehouse/stock_list.php?warehouse_id=MAIN', {
                headers: { 'Authorization': 'Bearer ' + tok },
            });
            const json = await res.json();
            if (json.success && Array.isArray(json.data)) {
                state.skuOptions = json.data.map(r => ({ sku: r.sku, name: r.name, unit: r.base_unit || '' }));
            }
        } catch (e) {
            console.warn('[procurement] loadSkuOptions failed:', e);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function formatPLN(minor) {
        const n = (parseInt(minor, 10) || 0) / 100;
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' zł';
    }

    function showError(msg) {
        const el = $('#pi-error-banner');
        el.textContent = msg;
        el.classList.remove('hidden');
        setTimeout(() => el.classList.add('hidden'), 6000);
    }

    function setUploadStatus(level, html) {
        const el = $('#pi-upload-status');
        el.className = 'pi-upload-status pi-upload-status--' + level;
        el.innerHTML = html;
        el.classList.remove('hidden');
    }

    // -------------------------------------------------------------------------
    // List load
    // -------------------------------------------------------------------------
    async function loadList() {
        if (!getToken()) {
            $('#pi-auth-banner').classList.remove('hidden');
            return;
        }
        $('#pi-auth-banner').classList.add('hidden');

        const r = await api('list', { status: state.filterStatus });
        if (!r.success) {
            if (r.code === 'NO_TOKEN' || (r.message || '').toLowerCase().includes('unauth')) {
                $('#pi-auth-banner').classList.remove('hidden');
            } else {
                showError(r.message || 'Błąd ładowania.');
            }
            return;
        }
        state.invoices = r.data.invoices || [];
        state.stats = r.data.stats || {};
        renderStats();
        renderList();
        state.loaded = true;
    }

    function renderStats() {
        const total = Object.values(state.stats).reduce((s, n) => s + (parseInt(n, 10) || 0), 0);
        $('#pi-stat-total').textContent = total;
        $('#pi-stat-draft').textContent = state.stats['draft'] || 0;
        $('#pi-stat-accepted').textContent = state.stats['accepted'] || 0;
        $('#pi-stat-rejected').textContent = state.stats['rejected'] || 0;
    }

    function renderList() {
        const el = $('#pi-invoices-list');
        if (state.invoices.length === 0) {
            el.innerHTML = `
                <div class="pi-empty">
                    <i class="fa-solid fa-inbox"></i>
                    <div>Brak faktur w inbox-ie${state.filterStatus ? ' (filtr: ' + state.filterStatus + ')' : ''}.</div>
                    <div class="pi-empty-hint">Wgraj FA(2) XML przez drop-zone powyżej.</div>
                </div>`;
            return;
        }
        el.innerHTML = state.invoices.map(inv => `
            <div class="pi-invoice-row" data-id="${inv.id}">
                <div>
                    <div class="pi-invoice-supplier">${escapeHtml(inv.supplier_name || '— bez nazwy —')}</div>
                    <div class="pi-invoice-supplier-nip">NIP: ${escapeHtml(inv.supplier_nip || '?')}</div>
                </div>
                <div>
                    <div class="pi-invoice-number">${escapeHtml(inv.invoice_number || '?')}</div>
                    <div class="pi-invoice-date">${escapeHtml(inv.issue_date || inv.fetched_at?.substring(0,10) || '?')}</div>
                </div>
                <div class="pi-invoice-amount${(parseInt(inv.total_gross_minor, 10) || 0) === 0 ? ' pi-invoice-amount--zero' : ''}">
                    ${formatPLN(inv.total_gross_minor)}
                </div>
                <div>${renderStatusPill(inv.status)}</div>
                <div>${inv.linked_wh_document_id ? '<span style="color:#86efac;font-size:0.75rem"><i class="fa-solid fa-link"></i> PZ #' + inv.linked_wh_document_id + '</span>' : ''}</div>
                <div><i class="fa-solid fa-chevron-right" style="color:#64748b"></i></div>
            </div>
        `).join('');
        // Click → modal
        $$('#pi-invoices-list .pi-invoice-row').forEach(row => {
            row.addEventListener('click', () => openInvoice(parseInt(row.dataset.id, 10)));
        });
    }

    function renderStatusPill(status) {
        const cls = 'pi-status-pill--' + (status || 'draft');
        return `<span class="pi-status-pill ${cls}">${escapeHtml(status || 'draft')}</span>`;
    }

    // -------------------------------------------------------------------------
    // Modal — open / render
    // -------------------------------------------------------------------------
    async function openInvoice(invoiceId) {
        const r = await api('show', { invoice_id: invoiceId });
        if (!r.success) {
            showError(r.message || 'Nie udało się załadować szczegółów.');
            return;
        }
        state.currentInvoice = r.data.invoice;
        state.currentLines = r.data.lines || [];
        state.threshold = r.data.threshold || 70;

        $('#pi-modal-backdrop').classList.remove('hidden');
        renderInvoiceDetails();
    }

    function closeModal() {
        $('#pi-modal-backdrop').classList.add('hidden');
        state.currentInvoice = null;
        state.currentLines = [];
    }

    function renderInvoiceDetails() {
        const inv = state.currentInvoice;
        const lines = state.currentLines;

        $('#pi-modal-title').textContent = inv.invoice_number || 'Faktura';
        $('#pi-modal-sub').textContent =
            (inv.supplier_name || '— bez nazwy —') + ' · NIP ' + (inv.supplier_nip || '?') +
            (inv.status ? ' · ' + inv.status : '');

        const stats = lines.reduce((s, l) => {
            const mt = l.match_type || 'NONE';
            s[mt] = (s[mt] || 0) + 1;
            return s;
        }, {});
        const unresolved = lines.filter(l => !l.resolved_sku).length;
        const isAccepted = inv.status === 'accepted';
        const isRejected = inv.status === 'rejected';

        const skuOptionsHtml = state.skuOptions.map(o =>
            `<option value="${escapeHtml(o.sku)}">${escapeHtml(o.name)} [${escapeHtml(o.sku)}] · ${escapeHtml(o.unit)}</option>`
        ).join('');

        let html = `
            <div class="pi-detail-grid">
                <div><div class="pi-detail-label">Dostawca</div><div class="pi-detail-value">${escapeHtml(inv.supplier_name || '?')}</div></div>
                <div><div class="pi-detail-label">NIP dostawcy</div><div class="pi-detail-value" style="font-family:ui-monospace,monospace">${escapeHtml(inv.supplier_nip || '?')}</div></div>
                <div><div class="pi-detail-label">Numer faktury</div><div class="pi-detail-value" style="font-family:ui-monospace,monospace">${escapeHtml(inv.invoice_number || '?')}</div></div>
                <div><div class="pi-detail-label">Data wystawienia</div><div class="pi-detail-value">${escapeHtml(inv.issue_date || '?')}</div></div>
                <div><div class="pi-detail-label">Data sprzedaży</div><div class="pi-detail-value">${escapeHtml(inv.sale_date || '?')}</div></div>
                <div><div class="pi-detail-label">Termin płatności</div><div class="pi-detail-value">${escapeHtml(inv.payment_due_date || '—')}</div></div>
            </div>

            <div class="pi-match-stats">
                <span>AutoScan (threshold ${state.threshold}%):</span>
                ${stats.EXACT ? `<span class="pi-match-stats-badge pi-match-pill--EXACT">EXACT ${stats.EXACT}</span>` : ''}
                ${stats.ALIAS ? `<span class="pi-match-stats-badge pi-match-pill--ALIAS">ALIAS ${stats.ALIAS}</span>` : ''}
                ${stats.NAME ? `<span class="pi-match-stats-badge pi-match-pill--NAME">NAME ${stats.NAME}</span>` : ''}
                ${stats.FUZZY ? `<span class="pi-match-stats-badge pi-match-pill--FUZZY">FUZZY ${stats.FUZZY}</span>` : ''}
                ${stats.NONE ? `<span class="pi-match-stats-badge pi-match-pill--NONE">NONE ${stats.NONE}</span>` : ''}
                ${stats.MANUAL ? `<span class="pi-match-stats-badge pi-match-pill--MANUAL">MANUAL ${stats.MANUAL}</span>` : ''}
                ${unresolved > 0 ? `<span style="color:#f87171;margin-left:auto"><i class="fa-solid fa-triangle-exclamation"></i> ${unresolved} bez SKU — musisz wybrać przed akceptacją</span>` : ''}
            </div>

            <table class="pi-lines-table">
                <thead>
                    <tr>
                        <th>#</th><th>Nazwa z faktury</th><th>Ilość</th><th>Cena netto</th>
                        <th>VAT</th><th>Match</th><th>SKU</th>
                    </tr>
                </thead>
                <tbody>
        `;
        lines.forEach(l => {
            const skuValue = l.resolved_sku || '';
            const isAutoAccept = (l.match_confidence || 0) >= state.threshold;
            html += `
                <tr data-line-id="${l.id}">
                    <td style="color:#64748b">${l.line_no}</td>
                    <td>
                        <div>${escapeHtml(l.external_name)}</div>
                        ${l.gtu_code ? `<div style="color:#94a3b8;font-size:0.7rem">${escapeHtml(l.gtu_code)}${l.pkwiu ? ' · PKWiU ' + escapeHtml(l.pkwiu) : ''}</div>` : ''}
                    </td>
                    <td style="font-family:ui-monospace,monospace">${parseFloat(l.qty).toFixed(3)} ${escapeHtml(l.unit || '')}</td>
                    <td style="font-family:ui-monospace,monospace;text-align:right">${parseFloat(l.unit_net).toFixed(2)}</td>
                    <td style="font-family:ui-monospace,monospace">${parseFloat(l.vat_rate).toFixed(0)}%</td>
                    <td>
                        <span class="pi-match-pill pi-match-pill--${l.match_type || 'NONE'}">${l.match_type || 'NONE'} ${l.match_confidence || 0}%</span>
                        ${isAutoAccept && skuValue && l.match_type !== 'MANUAL' ? '<div style="color:#86efac;font-size:0.65rem;margin-top:2px"><i class="fa-solid fa-check"></i> auto</div>' : ''}
                    </td>
                    <td>
                        ${isAccepted ? `<span class="pi-line-sku">${escapeHtml(skuValue || '?')}</span>` :
                            `<select class="pi-sku-select" data-line-id="${l.id}" ${isAccepted ? 'disabled' : ''}>
                                <option value="">— wybierz SKU —</option>
                                ${skuOptionsHtml.replace(new RegExp('value="' + escapeHtml(skuValue).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"'), m => m + ' selected')}
                            </select>`}
                    </td>
                </tr>
            `;
        });
        html += '</tbody></table>';

        html += `
            <div class="pi-totals-summary">
                <div><span class="pi-totals-label">Netto:</span> <span class="pi-totals-value">${formatPLN(inv.total_net_minor)}</span></div>
                <div><span class="pi-totals-label">VAT:</span> <span class="pi-totals-value">${formatPLN(inv.total_vat_minor)}</span></div>
                <div><span class="pi-totals-label">Brutto:</span> <span class="pi-totals-value" style="font-size:1.05rem">${formatPLN(inv.total_gross_minor)}</span></div>
            </div>
        `;

        $('#pi-modal-body').innerHTML = html;

        // Wire SKU select change
        $$('#pi-modal-body .pi-sku-select').forEach(sel => {
            sel.addEventListener('change', async (e) => {
                const lineId = parseInt(sel.dataset.lineId, 10);
                const sku = sel.value;
                if (!sku) return;
                const r = await api('update_line', { invoice_id: inv.id, line_id: lineId, sku });
                if (!r.success) {
                    showError(r.message || 'Update linii padł.');
                    return;
                }
                // Reload modal to refresh match badge
                openInvoice(inv.id);
            });
        });

        // Lock buttons gdy accepted/rejected
        $('#pi-modal-accept').disabled = isAccepted || isRejected;
        $('#pi-modal-reject').disabled = isAccepted || isRejected;
        $('#pi-modal-rescan').disabled = isAccepted;
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------
    async function handleFiles(files) {
        if (!files || files.length === 0) return;
        const results = [];
        for (const file of files) {
            if (!/\.xml$/i.test(file.name) && file.type && !file.type.includes('xml')) {
                results.push({ name: file.name, ok: false, msg: 'Nie XML — pominięto.' });
                continue;
            }
            const xml = await file.text();
            setUploadStatus('info', `<i class="fa-solid fa-circle-notch fa-spin"></i> Parsuję ${escapeHtml(file.name)}...`);
            const r = await api('upload_xml', { xml });
            if (r.success) {
                const d = r.data;
                results.push({
                    name: file.name, ok: true,
                    msg: `<strong>${escapeHtml(d.parsed.supplier.name || '— bez nazwy —')}</strong> · faktura ${escapeHtml(d.parsed.invoice.number || '?')} · ${d.match_stats.auto_accept}/${d.match_stats.total} linii auto-accept`,
                    invoice_id: d.invoice_id,
                });
            } else {
                results.push({ name: file.name, ok: false, msg: r.message || 'Błąd parsowania' });
            }
        }
        // Status summary
        const successes = results.filter(r => r.ok);
        const errors = results.filter(r => !r.ok);
        let html = '';
        if (successes.length > 0) {
            html += `<div style="color:#86efac"><i class="fa-solid fa-circle-check"></i> Dodano ${successes.length} faktur:</div>`;
            html += '<ul style="margin:0.4rem 0 0 1.2rem;padding:0">' + successes.map(r => `<li>${escapeHtml(r.name)} — ${r.msg}</li>`).join('') + '</ul>';
        }
        if (errors.length > 0) {
            html += `<div style="color:#fca5a5;margin-top:0.5rem"><i class="fa-solid fa-circle-xmark"></i> Błędy (${errors.length}):</div>`;
            html += '<ul style="margin:0.4rem 0 0 1.2rem;padding:0">' + errors.map(r => `<li>${escapeHtml(r.name)}: ${escapeHtml(r.msg)}</li>`).join('') + '</ul>';
        }
        setUploadStatus(errors.length > 0 ? 'err' : 'ok', html);
        loadList();
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    function bindEvents() {
        $('#pi-btn-refresh').addEventListener('click', loadList);

        // Stats filters
        $$('.pi-stat-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                $$('.pi-stat-pill').forEach(b => b.classList.remove('pi-stat-pill--active'));
                btn.classList.add('pi-stat-pill--active');
                state.filterStatus = btn.dataset.status || '';
                loadList();
            });
        });

        // Drop zone
        const dz = $('#pi-dropzone');
        const fileInput = $('#pi-file-input');
        $('#pi-btn-pick').addEventListener('click', (e) => { e.stopPropagation(); fileInput.click(); });
        dz.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => handleFiles(fileInput.files));

        ['dragenter', 'dragover'].forEach(ev => {
            dz.addEventListener(ev, (e) => { e.preventDefault(); e.stopPropagation(); dz.classList.add('drag-over'); });
        });
        ['dragleave', 'drop'].forEach(ev => {
            dz.addEventListener(ev, (e) => { e.preventDefault(); e.stopPropagation(); dz.classList.remove('drag-over'); });
        });
        dz.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
        });

        // Modal close
        $('#pi-modal-close').addEventListener('click', closeModal);
        $('#pi-modal-backdrop').addEventListener('click', (e) => {
            if (e.target.id === 'pi-modal-backdrop') closeModal();
        });

        // Modal actions
        $('#pi-modal-rescan').addEventListener('click', async () => {
            if (!state.currentInvoice) return;
            const r = await api('reparse', { invoice_id: state.currentInvoice.id });
            if (!r.success) { showError(r.message || 'Rescan padł.'); return; }
            openInvoice(state.currentInvoice.id);
        });

        $('#pi-modal-accept').addEventListener('click', async () => {
            if (!state.currentInvoice) return;
            if (!confirm('Akceptujesz fakturę? Powstanie PZ + stany magazynowe wzrosną.')) return;
            const r = await api('accept', { invoice_id: state.currentInvoice.id, warehouse_id: 'MAIN' });
            if (!r.success) {
                showError(r.message || 'Akceptacja padła.');
                return;
            }
            const pz = r.data.pz_document;
            alert(`✓ PZ utworzony: ${pz.doc_number}\n${pz.auto_learned > 0 ? '🧠 AutoScan zapamiętał ' + pz.auto_learned + ' nowych mapowań.' : ''}`);
            closeModal();
            loadList();
        });

        $('#pi-modal-reject').addEventListener('click', async () => {
            if (!state.currentInvoice) return;
            const reason = prompt('Powód odrzucenia (opcjonalnie):');
            if (reason === null) return;
            const r = await api('reject', { invoice_id: state.currentInvoice.id, reason });
            if (!r.success) { showError(r.message || 'Reject padł.'); return; }
            closeModal();
            loadList();
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        console.log('[procurement_app] booted', new Date().toISOString());
        bindEvents();
        await loadSkuOptions();
        await loadList();
    });
})();
