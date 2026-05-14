/**
 * SliceHub — Inbox KSeF (Procurement) frontend
 * Vanilla JS + dark glass (Konstytucja v5 § Prawo VI).
 *
 * Flow:
 *   1. User dragguje FA(2) XML do dropzone → readAsText → POST upload_xml.
 *   2. Lista invoices ładuje się przez `list` action (stats badge).
 *   3. Klik na row → modal z `show` action: invoice + lines + match.
 *   4. Per linia: combobox SKU (wyszukiwarka + lista) — zapis przez **Zapisz zmiany** lub przed Rescan / Akceptuj.
 *   5. Klik "Akceptuj" → `accept` (magazyn: PZ; inne kategorie: koszt bez PZ).
 */
(function () {
    'use strict';

    const ENDPOINT = '/api/procurement/inbox.php';
    const SUGGEST_ENDPOINT = '/api/procurement/suggest.php';
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));

    const STATUS_LABELS = {
        draft: 'Nowe',
        accepted: 'Zaakceptowane',
        rejected: 'Odrzucone',
        error: 'Błąd',
        new: 'Nowe',
        parsing: 'Parsowanie',
    };
    const COST_CATEGORY_LABELS = {
        magazyn: 'Magazyn (PZ)',
        media: 'Media / energia',
        uslugi: 'Usługi',
        inne: 'Inne koszty',
    };

    let state = {
        loaded: false,
        invoices: [],
        stats: {},
        filterStatus: '',
        currentInvoice: null,
        currentLines: [],
        threshold: 70,
        skuOptions: [], // dla comboboxów SKU (cache sys_items)
        lineOpexEnabled: false,
        expenseCategories: [],
    };

    /** Pływająca lista SKU (poza overflow modala) */
    const skuDropdown = { host: null, activeCombo: null, reposition: null };

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
    async function loadExpenseCategories() {
        const r = await api('list', {}, '/api/procurement/expense_categories.php');
        if (r.success && r.data && Array.isArray(r.data.categories)) {
            state.expenseCategories = r.data.categories;
        } else {
            state.expenseCategories = [];
        }
    }

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

    function flashModalFeedback(msg, kind) {
        const fb = $('#pi-modal-feedback');
        if (!fb) return;
        fb.textContent = msg;
        fb.classList.remove('hidden', 'pi-modal-feedback--ok', 'pi-modal-feedback--err');
        fb.classList.add(kind === 'err' ? 'pi-modal-feedback--err' : 'pi-modal-feedback--ok');
        clearTimeout(fb._t);
        fb._t = setTimeout(() => {
            fb.classList.add('hidden');
            fb.textContent = '';
        }, 5500);
    }

    function showError(msg) {
        const modalOpen = $('#pi-modal-backdrop') && !$('#pi-modal-backdrop').classList.contains('hidden');
        if (modalOpen) {
            flashModalFeedback(msg, 'err');
            return;
        }
        const el = $('#pi-error-banner');
        el.textContent = msg;
        el.classList.remove('hidden');
        $('#pi-success-banner').classList.add('hidden');
        clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.add('hidden'), 6000);
    }

    function showSuccess(msg) {
        const modalOpen = $('#pi-modal-backdrop') && !$('#pi-modal-backdrop').classList.contains('hidden');
        if (modalOpen) {
            flashModalFeedback(msg, 'ok');
            return;
        }
        const el = $('#pi-success-banner');
        el.textContent = msg;
        el.classList.remove('hidden');
        $('#pi-error-banner').classList.add('hidden');
        clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.add('hidden'), 5000);
    }

    function normSearch(s) {
        return String(s || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function skuOptionLabel(o) {
        return `${o.name} [${o.sku}] · ${o.unit || ''}`.trim();
    }

    function findSkuOption(sku) {
        const s = String(sku || '').trim();
        return state.skuOptions.find(o => o.sku === s) || null;
    }

    function filterSkuOptions(q) {
        const n = normSearch(q);
        const list = state.skuOptions;
        if (!Array.isArray(list) || list.length === 0) return [];
        if (!n) return list.slice(0, 60);
        const out = [];
        for (const o of list) {
            const hay = normSearch(`${o.sku} ${o.name} ${o.unit || ''}`);
            if (hay.includes(n)) out.push(o);
            if (out.length >= 100) break;
        }
        return out;
    }

    function getSkuDropdownHost() {
        let el = document.getElementById('pi-sku-global-dropdown');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pi-sku-global-dropdown';
            el.className = 'hidden';
            document.body.appendChild(el);
        }
        return el;
    }

    function closeSkuDropdown() {
        if (skuDropdown.activeCombo) {
            skuDropdown.activeCombo.classList.remove('pi-sku-combo-open');
        }
        const host = skuDropdown.host || document.getElementById('pi-sku-global-dropdown');
        if (host) host.classList.add('hidden');
        skuDropdown.activeCombo = null;
        skuDropdown.host = null;
        if (skuDropdown.reposition) {
            window.removeEventListener('scroll', skuDropdown.reposition, true);
            window.removeEventListener('resize', skuDropdown.reposition);
            skuDropdown.reposition = null;
        }
    }

    function positionSkuDropdown(combo, host) {
        const wrap = combo.querySelector('.pi-sku-combo-wrap');
        if (!wrap || !host) return;
        const rect = wrap.getBoundingClientRect();
        const pad = 6;
        let top = rect.bottom + 4;
        let maxH = window.innerHeight - top - pad;
        if (maxH < 100) {
            maxH = Math.max(120, rect.top - pad * 2);
            top = Math.max(pad, rect.top - maxH - 4);
        } else {
            maxH = Math.min(320, maxH);
        }
        host.style.top = top + 'px';
        host.style.left = Math.max(pad, rect.left) + 'px';
        host.style.width = Math.max(260, rect.width) + 'px';
        host.style.maxHeight = maxH + 'px';
    }

    function renderSkuDropdownContent(combo, query) {
        const list = filterSkuOptions(query);
        const host = getSkuDropdownHost();
        if (list.length === 0) {
            host.innerHTML = '<div class="pi-sku-dd-empty">Brak wyników — zmień wpis lub utwórz SKU (+ Nowy)</div>';
            return;
        }
        host.innerHTML = list.map(o => `
            <button type="button" class="pi-sku-dd-item" data-sku="${escapeHtml(o.sku)}">
                <span class="pi-sku-dd-name">${escapeHtml(o.name)}</span>
                <span class="pi-sku-dd-meta">[${escapeHtml(o.sku)}] · ${escapeHtml(o.unit || '')}</span>
            </button>
        `).join('');
        host.querySelectorAll('.pi-sku-dd-item').forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                applySkuToCombo(combo, btn.getAttribute('data-sku') || '');
                closeSkuDropdown();
            });
        });
    }

    function bindSkuDropdownReposition(combo, host) {
        const fn = () => {
            if (skuDropdown.activeCombo !== combo || host.classList.contains('hidden')) return;
            positionSkuDropdown(combo, host);
        };
        skuDropdown.reposition = fn;
        window.addEventListener('scroll', fn, true);
        window.addEventListener('resize', fn);
    }

    function openSkuDropdown(combo, query) {
        closeSkuDropdown();
        const host = getSkuDropdownHost();
        skuDropdown.host = host;
        skuDropdown.activeCombo = combo;
        combo.classList.add('pi-sku-combo-open');
        renderSkuDropdownContent(combo, query);
        positionSkuDropdown(combo, host);
        host.classList.remove('hidden');
        bindSkuDropdownReposition(combo, host);
    }

    function updateComboPickedDisplay(combo) {
        const valEl = combo.querySelector('.pi-sku-combo-value');
        const row = combo.querySelector('.pi-sku-combo-picked');
        const txt = combo.querySelector('.pi-sku-combo-picked-text');
        const sku = (valEl && valEl.value ? valEl.value : '').trim();
        if (!sku) {
            row.classList.add('hidden');
            txt.textContent = '';
            return;
        }
        const o = findSkuOption(sku);
        txt.textContent = o ? skuOptionLabel(o) : sku;
        row.classList.remove('hidden');
    }

    function syncComboDirty(combo) {
        const sku = (combo.querySelector('.pi-sku-combo-value').value || '').trim();
        const orig = (combo.dataset.originalSku || '').trim();
        combo.classList.toggle('pi-sku-combo--dirty', sku !== '' && sku !== orig);
    }

    function applySkuToCombo(combo, sku) {
        const valEl = combo.querySelector('.pi-sku-combo-value');
        const search = combo.querySelector('.pi-sku-combo-search');
        valEl.value = String(sku || '').trim();
        if (search) search.value = '';
        updateComboPickedDisplay(combo);
        syncComboDirty(combo);
        updateModalSaveButtonState();
    }

    function attachSkuCombos(root) {
        root.querySelectorAll('.pi-sku-combo').forEach(combo => {
            const valEl = combo.querySelector('.pi-sku-combo-value');
            const search = combo.querySelector('.pi-sku-combo-search');
            const chev = combo.querySelector('.pi-sku-combo-chev');
            const clearBtn = combo.querySelector('.pi-sku-combo-clear');
            updateComboPickedDisplay(combo);
            syncComboDirty(combo);

            clearBtn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                valEl.value = '';
                search.value = '';
                combo.classList.remove('pi-sku-combo-open');
                closeSkuDropdown();
                updateComboPickedDisplay(combo);
                syncComboDirty(combo);
                updateModalSaveButtonState();
            });

            search.addEventListener('focus', () => {
                openSkuDropdown(combo, search.value.trim());
            });

            search.addEventListener('input', () => {
                const q = search.value.trim();
                if (skuDropdown.activeCombo === combo) {
                    renderSkuDropdownContent(combo, q);
                    positionSkuDropdown(combo, getSkuDropdownHost());
                } else {
                    openSkuDropdown(combo, q);
                }
            });

            chev.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const host = document.getElementById('pi-sku-global-dropdown');
                if (skuDropdown.activeCombo === combo && host && !host.classList.contains('hidden')) {
                    closeSkuDropdown();
                } else {
                    search.focus();
                    openSkuDropdown(combo, search.value.trim());
                }
            });
        });
    }

    function handleGlobalSkuMouseDown(e) {
        const host = document.getElementById('pi-sku-global-dropdown');
        if (!host || host.classList.contains('hidden')) return;
        if (e.target.closest('#pi-sku-global-dropdown')) return;
        if (e.target.closest('.pi-sku-combo')) return;
        closeSkuDropdown();
    }

    function handleGlobalSkuKeydown(e) {
        if (e.key === 'Escape') closeSkuDropdown();
    }

    function hasPendingLineSkuChanges() {
        let any = false;
        $$('#pi-modal-body .pi-sku-combo').forEach(combo => {
            const sku = (combo.querySelector('.pi-sku-combo-value').value || '').trim();
            const orig = (combo.dataset.originalSku || '').trim();
            if (sku !== '' && sku !== orig) any = true;
        });
        return any;
    }

    function getActiveLineTypeFromRow(tr) {
        const a = tr.querySelector('.pi-seg-btn--active');
        return (a && a.dataset.lt) ? a.dataset.lt : 'INVENTORY';
    }

    function hasPendingOpexLineChanges() {
        let any = false;
        $$('#pi-modal-body .pi-line-editor-row').forEach(tr => {
            const curLt = getActiveLineTypeFromRow(tr);
            const origLt = (tr.dataset.origLineType || 'INVENTORY').toUpperCase();
            const combo = tr.querySelector('.pi-sku-combo');
            const sku = combo ? (combo.querySelector('.pi-sku-combo-value').value || '').trim() : '';
            const origSku = (tr.dataset.origSku || '').trim();
            const sel = tr.querySelector('.pi-exp-cat-select');
            const ec = sel ? String(sel.value || '') : '';
            const origEc = String(tr.dataset.origExpenseId || '');
            if (curLt !== origLt) any = true;
            if (curLt === 'INVENTORY' && sku !== '' && sku !== origSku) any = true;
            if (curLt === 'EXPENSE' && ec !== origEc) any = true;
        });
        return any;
    }

    function updateModalSaveButtonState() {
        const btn = $('#pi-modal-save');
        if (!btn) return;
        const inv = state.currentInvoice;
        if (!inv) {
            btn.disabled = true;
            return;
        }
        const locked = inv.status === 'accepted' || inv.status === 'rejected';
        if (state.lineOpexEnabled) {
            btn.disabled = locked || !hasPendingOpexLineChanges();
        } else {
            btn.disabled = locked || !hasPendingLineSkuChanges();
        }
    }

    /**
     * Zapisuje zmiany na liniach (SKU albo INVENTORY/EXPENSE + kategoria OPEX).
     * @return {{ok: boolean, saved: number, message?: string}}
     */
    async function savePendingLineSkus() {
        const inv = state.currentInvoice;
        if (!inv) return { ok: false, saved: 0, message: 'Brak faktury.' };

        if (!state.lineOpexEnabled) {
            const pending = [];
            $$('#pi-modal-body .pi-sku-combo').forEach(combo => {
                const lineId = parseInt(combo.dataset.lineId, 10);
                const sku = (combo.querySelector('.pi-sku-combo-value').value || '').trim();
                const orig = (combo.dataset.originalSku || '').trim();
                if (sku === '' || sku === orig) return;
                pending.push({ lineId, sku });
            });
            if (pending.length === 0) return { ok: true, saved: 0 };
            for (const p of pending) {
                const r = await api('update_line', { invoice_id: inv.id, line_id: p.lineId, sku: p.sku });
                if (!r.success) {
                    return { ok: false, saved: 0, message: r.message || 'Update linii padł.' };
                }
            }
            return { ok: true, saved: pending.length };
        }

        const pending = [];
        $$('#pi-modal-body .pi-line-editor-row').forEach(tr => {
            const lineId = parseInt(tr.dataset.lineId, 10);
            const curLt = getActiveLineTypeFromRow(tr);
            const origLt = (tr.dataset.origLineType || 'INVENTORY').toUpperCase();
            const combo = tr.querySelector('.pi-sku-combo');
            const sku = combo ? (combo.querySelector('.pi-sku-combo-value').value || '').trim() : '';
            const origSku = (tr.dataset.origSku || '').trim();
            const sel = tr.querySelector('.pi-exp-cat-select');
            const ec = sel ? String(sel.value || '') : '';
            const origEc = String(tr.dataset.origExpenseId || '');
            let dirty = curLt !== origLt;
            if (curLt === 'INVENTORY') dirty = dirty || (sku !== '' && sku !== origSku);
            if (curLt === 'EXPENSE') dirty = dirty || (ec !== origEc);
            if (!dirty) return;
            pending.push({ lineId, curLt, sku, expense_category_id: ec ? parseInt(ec, 10) : 0 });
        });
        if (pending.length === 0) return { ok: true, saved: 0 };
        let n = 0;
        for (const p of pending) {
            let body;
            if (p.curLt === 'EXPENSE') {
                if (!p.expense_category_id) {
                    return { ok: false, saved: 0, message: 'Wybierz kategorię OPEX dla linii kosztowej.' };
                }
                body = {
                    invoice_id: inv.id,
                    line_id: p.lineId,
                    line_type: 'EXPENSE',
                    expense_category_id: p.expense_category_id,
                };
            } else {
                if (!p.sku) {
                    return { ok: false, saved: 0, message: 'Wybierz SKU dla linii magazynowej.' };
                }
                body = {
                    invoice_id: inv.id,
                    line_id: p.lineId,
                    line_type: 'INVENTORY',
                    sku: p.sku,
                };
            }
            const r = await api('update_line', body);
            if (!r.success) {
                return { ok: false, saved: 0, message: r.message || 'Update linii padł.' };
            }
            n++;
        }
        return { ok: true, saved: n };
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
                    <div>Brak faktur w inbox-ie${state.filterStatus ? ' (filtr: ' + escapeHtml(STATUS_LABELS[state.filterStatus] || state.filterStatus) + ')' : ''}.</div>
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
                <div>
                    ${renderStatusPill(inv.status)}
                    ${inv.cost_category && inv.cost_category !== 'magazyn' ? `<div class="pi-inline-cost-cat">${escapeHtml(COST_CATEGORY_LABELS[inv.cost_category] || inv.cost_category)}</div>` : ''}
                </div>
                <div>${
                    inv.status === 'accepted'
                        ? (inv.linked_wh_document_id
                            ? '<span style="color:#86efac;font-size:0.75rem"><i class="fa-solid fa-link"></i> PZ #' + escapeHtml(String(inv.linked_wh_document_id)) + '</span>'
                            : '<span class="pi-inline-cost-only"><i class="fa-solid fa-chart-pie"></i> Koszt (bez PZ)</span>')
                        : ''
                }</div>
                <div><i class="fa-solid fa-chevron-right" style="color:#64748b"></i></div>
            </div>
        `).join('');
        // Click → modal
        $$('#pi-invoices-list .pi-invoice-row').forEach(row => {
            row.addEventListener('click', () => openInvoice(parseInt(row.dataset.id, 10)));
        });
    }

    function renderStatusPill(status) {
        const raw = status || 'draft';
        const cls = 'pi-status-pill--' + raw;
        const label = STATUS_LABELS[raw] || raw;
        return `<span class="pi-status-pill ${cls}">${escapeHtml(label)}</span>`;
    }

    // -------------------------------------------------------------------------
    // Modal — open / render
    // -------------------------------------------------------------------------
    async function openInvoice(invoiceId) {
        closeSkuDropdown();
        const r = await api('show', { invoice_id: invoiceId });
        if (!r.success) {
            showError(r.message || 'Nie udało się załadować szczegółów.');
            return;
        }
        state.lineOpexEnabled = !!r.data.line_opex_enabled;
        if (state.lineOpexEnabled) {
            await loadExpenseCategories();
        }
        state.currentInvoice = r.data.invoice;
        state.currentLines = r.data.lines || [];
        state.threshold = r.data.threshold || 70;

        $('#pi-modal-backdrop').classList.remove('hidden');
        renderInvoiceDetails();
    }

    function closeModal() {
        closeSkuDropdown();
        const fb = $('#pi-modal-feedback');
        if (fb) {
            fb.classList.add('hidden');
            fb.textContent = '';
        }
        $('#pi-modal-backdrop').classList.add('hidden');
        state.currentInvoice = null;
        state.currentLines = [];
        state.lineOpexEnabled = false;
    }

    function updateCostCategoryHint() {
        const hint = $('#pi-modal-cost-hint');
        const inv = state.currentInvoice;
        if (!hint || !inv) return;
        const c = inv.cost_category || 'magazyn';
        if (c === 'magazyn') {
            hint.textContent = 'Akceptacja w tej kategorii utworzy PZ — wymagane SKU na wszystkich liniach.';
        } else {
            hint.textContent = 'Koszt operacyjny: możesz zaakceptować bez PZ; SKU na liniach jest opcjonalne. Kwoty z nagłówka posłużą do podziału kosztów w statystykach.';
        }
    }

    async function onModalCostCategoryChange() {
        const inv = state.currentInvoice;
        const sel = $('#pi-modal-cost-category');
        if (!inv || !sel) return;
        const r = await api('set_cost_category', { invoice_id: inv.id, cost_category: sel.value });
        if (!r.success) {
            showError(r.message || 'Nie zapisano kategorii.');
            sel.value = inv.cost_category || 'magazyn';
            return;
        }
        inv.cost_category = sel.value;
        showSuccess('Kategoria kosztu zapisana.');
        await openInvoice(inv.id);
    }

    function bindLineEditorRows(root) {
        root.querySelectorAll('.pi-line-editor-row').forEach(tr => {
            tr.querySelectorAll('.pi-seg-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    tr.querySelectorAll('.pi-seg-btn').forEach(b => b.classList.remove('pi-seg-btn--active'));
                    btn.classList.add('pi-seg-btn--active');
                    const lt = btn.dataset.lt;
                    const invW = tr.querySelector('.pi-line-inv-wrap');
                    const expW = tr.querySelector('.pi-line-exp-wrap');
                    if (lt === 'INVENTORY') {
                        if (invW) invW.classList.remove('hidden');
                        if (expW) expW.classList.add('hidden');
                    } else {
                        if (invW) invW.classList.add('hidden');
                        if (expW) expW.classList.remove('hidden');
                        closeSkuDropdown();
                    }
                    updateModalSaveButtonState();
                });
            });
            const sel = tr.querySelector('.pi-exp-cat-select');
            if (sel) {
                sel.addEventListener('change', () => updateModalSaveButtonState());
            }
        });
    }

    function renderInvoiceDetails() {
        const inv = state.currentInvoice;
        const lines = state.currentLines;

        const fb = $('#pi-modal-feedback');
        if (fb) {
            fb.classList.add('hidden');
            fb.textContent = '';
        }

        $('#pi-modal-title').textContent = inv.invoice_number || 'Faktura';
        $('#pi-modal-sub').textContent =
            (inv.supplier_name || '— bez nazwy —') + ' · NIP ' + (inv.supplier_nip || '?') +
            (inv.status ? ' · ' + (STATUS_LABELS[inv.status] || inv.status) : '');

        const stats = lines.reduce((s, l) => {
            const mt = l.match_type || 'NONE';
            s[mt] = (s[mt] || 0) + 1;
            return s;
        }, {});
        const opex = state.lineOpexEnabled;
        const isAccepted = inv.status === 'accepted';
        const isRejected = inv.status === 'rejected';
        const costCat = inv.cost_category || 'magazyn';
        const overhead = costCat !== 'magazyn';
        let warnRight = '';
        if (opex) {
            const missInv = lines.filter(l => (String(l.line_type || 'INVENTORY').toUpperCase() !== 'EXPENSE') && !l.resolved_sku).length;
            const missExp = lines.filter(l => String(l.line_type || '').toUpperCase() === 'EXPENSE' && !l.expense_category_id).length;
            const bits = [];
            if (missInv) bits.push(`${missInv} towar. bez SKU`);
            if (missExp) bits.push(`${missExp} OPEX bez kategorii`);
            if (bits.length) {
                warnRight = `<span class="pi-warn-inline"><i class="fa-solid fa-triangle-exclamation"></i> ${bits.join(' · ')}</span>`;
            } else {
                warnRight = '<span style="color:#94a3b8;margin-left:auto;font-size:0.78rem">Towary → PZ + magazyn · OPEX → ewidencja bez AVCO.</span>';
            }
        } else {
            const unresolved = lines.filter(l => !l.resolved_sku).length;
            if (!overhead && unresolved > 0) {
                warnRight = `<span style="color:#f87171;margin-left:auto"><i class="fa-solid fa-triangle-exclamation"></i> ${unresolved} bez SKU — wymagane przed akceptacją (kategoria „Magazyn”)</span>`;
            } else if (overhead) {
                warnRight = '<span style="color:#94a3b8;margin-left:auto;font-size:0.78rem"><i class="fa-solid fa-circle-info"></i> Tryb kosztowy — SKU opcjonalne; akceptacja bez PZ.</span>';
            }
        }

        const costOptionKeys = ['magazyn', 'media', 'uslugi', 'inne'];
        const costOptsHtml = costOptionKeys.map(k =>
            `<option value="${k}">${escapeHtml(COST_CATEGORY_LABELS[k] || k)}</option>`
        ).join('');

        const costRowHtml = opex ? '' : `
            <div class="pi-cost-cat-row">
                <label class="pi-cost-cat-label" for="pi-modal-cost-category">Kategoria kosztu (nagłówek)</label>
                <select id="pi-modal-cost-category" class="pi-cost-cat-select"${isAccepted || isRejected ? ' disabled' : ''}>
                    ${costOptsHtml}
                </select>
                <p id="pi-modal-cost-hint" class="pi-cost-cat-hint"></p>
            </div>`;

        const mapHeaders = opex ? '<th>Typ</th><th>Mapowanie</th>' : '<th>SKU</th>';

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
                ${warnRight}
            </div>

            ${costRowHtml}

            <table class="pi-lines-table">
                <thead>
                    <tr>
                        <th>#</th><th>Nazwa z faktury</th><th>Ilość</th><th>Cena netto</th>
                        <th>VAT</th><th>Match</th>${mapHeaders}
                    </tr>
                </thead>
                <tbody>
        `;
        lines.forEach(l => {
            const skuValue = l.resolved_sku || '';
            const isAutoAccept = (l.match_confidence || 0) >= state.threshold;
            const lt = (String(l.line_type || 'INVENTORY').toUpperCase() === 'EXPENSE') ? 'EXPENSE' : 'INVENTORY';

            if (opex) {
                const ecId = l.expense_category_id != null ? String(l.expense_category_id) : '';
                const typeCell = isAccepted
                    ? `<span class="pi-type-pill pi-type-pill--${lt}">${lt === 'EXPENSE' ? '💸 OPEX' : '📦 Towar'}</span>`
                    : `<div class="pi-seg" role="group">
                        <button type="button" class="pi-seg-btn${lt === 'INVENTORY' ? ' pi-seg-btn--active' : ''}" data-lt="INVENTORY">📦 Towar</button>
                        <button type="button" class="pi-seg-btn${lt === 'EXPENSE' ? ' pi-seg-btn--active' : ''}" data-lt="EXPENSE">💸 OPEX</button>
                    </div>`;
                let mapCell;
                if (isAccepted) {
                    mapCell = lt === 'EXPENSE'
                        ? `<span class="pi-line-sku">${escapeHtml(l.expense_category_name || '—')}</span>`
                        : `<span class="pi-line-sku">${escapeHtml(skuValue || '?')}</span>`;
                } else {
                    const opts = (state.expenseCategories || []).map(c =>
                        `<option value="${String(c.id)}"${ecId === String(c.id) ? ' selected' : ''}>${escapeHtml(c.name)}</option>`
                    ).join('');
                    const skuCombo = `<div class="pi-sku-combo" data-line-id="${l.id}" data-original-sku="${escapeHtml(skuValue)}">
                    <div class="pi-sku-combo-picked hidden">
                        <span class="pi-sku-combo-picked-text"></span>
                        <button type="button" class="pi-sku-combo-clear" title="Wyczyść wybór" aria-label="Wyczyść">×</button>
                    </div>
                    <div class="pi-sku-combo-wrap">
                        <input type="text" class="pi-sku-combo-search" placeholder="Szukaj nazwy lub SKU…" autocomplete="off" spellcheck="false" />
                        <button type="button" class="pi-sku-combo-chev" aria-label="Rozwiń listę"><i class="fa-solid fa-chevron-down"></i></button>
                    </div>
                    <input type="hidden" class="pi-sku-combo-value" value="${escapeHtml(skuValue)}" />
                </div>`;
                    mapCell = `
                        <div class="pi-line-inv-wrap${lt === 'INVENTORY' ? '' : ' hidden'}"><div class="pi-sku-cell">${skuCombo}</div></div>
                        <div class="pi-line-exp-wrap${lt === 'EXPENSE' ? '' : ' hidden'}">
                            <select class="pi-exp-cat-select pi-sku-select">
                                <option value="">— kategoria OPEX —</option>
                                ${opts}
                            </select>
                        </div>`;
                }
                const trCls = !isAccepted ? ' class="pi-line-editor-row"' : '';
                const dataOrig = !isAccepted
                    ? ` data-line-id="${l.id}" data-orig-line-type="${lt}" data-orig-sku="${escapeHtml(skuValue)}" data-orig-expense-id="${ecId}"`
                    : ` data-line-id="${l.id}"`;
                html += `
                <tr${dataOrig}${trCls}>
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
                    <td>${typeCell}</td>
                    <td class="pi-map-cell">${mapCell}</td>
                </tr>`;
                return;
            }

            const skuCellInner = isAccepted
                ? `<span class="pi-line-sku">${escapeHtml(skuValue || '?')}</span>`
                : `<div class="pi-sku-combo" data-line-id="${l.id}" data-original-sku="${escapeHtml(skuValue)}">
                    <div class="pi-sku-combo-picked hidden">
                        <span class="pi-sku-combo-picked-text"></span>
                        <button type="button" class="pi-sku-combo-clear" title="Wyczyść wybór" aria-label="Wyczyść">×</button>
                    </div>
                    <div class="pi-sku-combo-wrap">
                        <input type="text" class="pi-sku-combo-search" placeholder="Szukaj nazwy lub SKU…" autocomplete="off" spellcheck="false" />
                        <button type="button" class="pi-sku-combo-chev" aria-label="Rozwiń listę"><i class="fa-solid fa-chevron-down"></i></button>
                    </div>
                    <input type="hidden" class="pi-sku-combo-value" value="${escapeHtml(skuValue)}" />
                </div>`;
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
                    <td class="pi-sku-cell">${skuCellInner}</td>
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

        const costSel = $('#pi-modal-cost-category');
        if (costSel && !opex) {
            costSel.value = costCat;
            if (!isAccepted && !isRejected) {
                costSel.addEventListener('change', onModalCostCategoryChange);
            }
        }
        if (!opex) {
            updateCostCategoryHint();
        }

        attachSkuCombos($('#pi-modal-body'));
        if (opex) {
            bindLineEditorRows($('#pi-modal-body'));
        }

        // Lock buttons gdy accepted/rejected
        $('#pi-modal-accept').disabled = isAccepted || isRejected;
        $('#pi-modal-reject').disabled = isAccepted || isRejected;
        $('#pi-modal-rescan').disabled = isAccepted;
        updateModalSaveButtonState();

        // F4.5: gdy accepted, pokaż przycisk Reverse + zamień Accept/Reject style
        let reverseBtn = $('#pi-modal-reverse');
        if (isAccepted) {
            if (!reverseBtn) {
                reverseBtn = document.createElement('button');
                reverseBtn.id = 'pi-modal-reverse';
                reverseBtn.type = 'button';
                reverseBtn.className = 'pi-btn pi-btn--danger';
                reverseBtn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Wycofaj PZ (KOR)';
                $('#pi-modal-accept').parentElement.insertBefore(reverseBtn, $('#pi-modal-accept'));
                reverseBtn.addEventListener('click', handleReverse);
            }
            reverseBtn.innerHTML = inv.linked_wh_document_id
                ? '<i class="fa-solid fa-rotate-left"></i> Wycofaj PZ (KOR)'
                : '<i class="fa-solid fa-rotate-left"></i> Cofnij akceptację';
            reverseBtn.style.display = '';
        } else if (reverseBtn) {
            reverseBtn.style.display = 'none';
        }

        // F4.5: NONE linie — przycisk Smart-create per linia (tylko INVENTORY / magazyn)
        $$('#pi-modal-body tr[data-line-id]').forEach(tr => {
            const lineId = parseInt(tr.dataset.lineId, 10);
            const line = lines.find(l => l.id === lineId);
            if (!line || isAccepted) return;
            if (opex && String(line.line_type || 'INVENTORY').toUpperCase() === 'EXPENSE') return;
            if ((line.match_type === 'NONE' || !line.resolved_sku) && !tr.querySelector('.pi-smart-create-btn')) {
                const skuCell = tr.querySelector('.pi-sku-cell') || tr.querySelector('.pi-line-inv-wrap .pi-sku-cell');
                if (skuCell) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pi-btn pi-smart-create-btn';
                    btn.style.marginLeft = '0.4rem';
                    btn.style.fontSize = '0.72rem';
                    btn.innerHTML = '<i class="fa-solid fa-plus"></i> Nowy';
                    btn.title = 'Utwórz nowy SKU (F4.5)';
                    btn.addEventListener('click', () => openSmartCreate(line));
                    skuCell.appendChild(btn);
                }
            }
        });
    }

    async function uploadXmlOnce(xml, duplicate_resolution) {
        const body = { xml };
        if (duplicate_resolution) {
            body.duplicate_resolution = duplicate_resolution;
        }
        return api('upload_xml', body);
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
            let r = await uploadXmlOnce(xml, '');
            if (!r.success && r.code === 'DUPLICATE_INVOICE' && r.data && r.data.duplicate) {
                const d = r.data;
                if (!d.can_replace) {
                    results.push({
                        name: file.name,
                        ok: false,
                        msg: `Duplikat numeru faktury (status: ${escapeHtml(String(d.existing_status || '?'))}) — zastąpienie zablokowane.`,
                    });
                    continue;
                }
                const q = `W systemie jest już ta faktura (nr ${escapeHtml(String(d.invoice_number || '?'))}, dostawca NIP ${escapeHtml(String(d.supplier_nip || '—'))}, status: ${escapeHtml(String(d.existing_status || '?'))}).\n\nZastąpić istniejący wpis tym plikiem XML?\nOK = zastąp, Anuluj = nie importuj.`;
                if (!confirm(q)) {
                    results.push({ name: file.name, ok: false, msg: 'Anulowano — duplikat numeru faktury.' });
                    continue;
                }
                setUploadStatus('info', `<i class="fa-solid fa-circle-notch fa-spin"></i> Zastępuję ${escapeHtml(file.name)}...`);
                r = await uploadXmlOnce(xml, 'replace');
            }
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
        document.addEventListener('mousedown', handleGlobalSkuMouseDown, true);
        document.addEventListener('keydown', handleGlobalSkuKeydown);
        const modalBodyEarly = $('#pi-modal-body');
        if (modalBodyEarly) {
            modalBodyEarly.addEventListener('scroll', () => closeSkuDropdown(), { passive: true });
        }

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
            const flush = await savePendingLineSkus();
            if (!flush.ok) {
                showError(flush.message || 'Nie udało się zapisać zmian na liniach.');
                return;
            }
            if (flush.saved > 0) {
                showSuccess(`Zapisano ${flush.saved} ${flush.saved === 1 ? 'linię' : 'linii'} przed rescanem.`);
            }
            const r = await api('reparse', { invoice_id: state.currentInvoice.id });
            if (!r.success) { showError(r.message || 'Rescan padł.'); return; }
            await openInvoice(state.currentInvoice.id);
        });

        $('#pi-modal-save').addEventListener('click', async () => {
            if (!state.currentInvoice) return;
            const btn = $('#pi-modal-save');
            btn.disabled = true;
            try {
                const res = await savePendingLineSkus();
                if (!res.ok) {
                    showError(res.message || 'Zapis padł.');
                    return;
                }
                if (res.saved === 0) {
                    showSuccess('Brak zmian do zapisania (SKU takie jak przy otwarciu lub pusty wybór).');
                } else {
                    showSuccess(`Zapisano ${res.saved} ${res.saved === 1 ? 'linię' : 'linii'}. Status faktury bez zmian.`);
                    await openInvoice(state.currentInvoice.id);
                }
            } finally {
                updateModalSaveButtonState();
            }
        });

        $('#pi-modal-accept').addEventListener('click', async () => {
            if (!state.currentInvoice) return;
            const inv = state.currentInvoice;
            const flush = await savePendingLineSkus();
            if (!flush.ok) {
                showError(flush.message || 'Nie udało się zapisać zmian na liniach.');
                return;
            }
            let confirmMsg;
            let costCategory = 'magazyn';
            if (state.lineOpexEnabled) {
                confirmMsg = 'Akceptujesz fakturę? Linie „Towar” utworzą PZ (magazyn); linie „OPEX” zapiszą się bez magazynu (bez AVCO).';
            } else {
                const catSel = $('#pi-modal-cost-category');
                costCategory = catSel ? catSel.value : (inv.cost_category || 'magazyn');
                const overhead = costCategory !== 'magazyn';
                const msgMag = 'Akceptujesz fakturę w kategorii „Magazyn”? Powstanie PZ i stany magazynowe wzrosną.';
                const msgOh = 'Akceptujesz fakturę jako koszt operacyjny („' + (COST_CATEGORY_LABELS[costCategory] || costCategory) + '”)? Bez dokumentu PZ — tylko ewidencja na statystyki.';
                confirmMsg = overhead ? msgOh : msgMag;
            }
            if (!confirm(confirmMsg)) return;
            const body = { invoice_id: inv.id, warehouse_id: 'MAIN' };
            if (!state.lineOpexEnabled) {
                body.cost_category = costCategory;
            }
            const r = await api('accept', body);
            if (!r.success) {
                showError(r.message || 'Akceptacja padła.');
                return;
            }
            if (r.data.expense_only_accept) {
                alert('✓ Zaakceptowano fakturę kosztową (100% OPEX, bez PZ).\nLinie zapisane z kategoriami OPEX.');
            } else if (r.data.cost_only) {
                const lbl = COST_CATEGORY_LABELS[r.data.cost_category] || r.data.cost_category;
                alert('✓ Zaakceptowano jako koszt (bez PZ).\nKategoria: ' + lbl + '.\nKwoty w nagłówku zachowane do przyszłego modułu statystyk.');
            } else {
                const pz = r.data.pz_document;
                const pzNum = pz && pz.doc_number ? pz.doc_number : '?';
                const learned = pz && pz.auto_learned > 0 ? '\n\n🧠 AutoScan zapamiętał ' + pz.auto_learned + ' nowych mapowań.' : '';
                alert('✓ PZ utworzony: ' + pzNum + learned);
            }
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

        // F4: KSeF Config modal
        $('#pi-btn-config').addEventListener('click', openConfigModal);
        $('#pi-btn-opex-cat').addEventListener('click', openOpexModal);
        $('#pi-opex-close').addEventListener('click', () => $('#pi-opex-backdrop').classList.add('hidden'));
        $('#pi-opex-done').addEventListener('click', () => $('#pi-opex-backdrop').classList.add('hidden'));
        $('#pi-opex-add').addEventListener('click', submitOpexAdd);
        $('#pi-cfg-close').addEventListener('click', () => $('#pi-cfg-backdrop').classList.add('hidden'));
        $('#pi-cfg-save').addEventListener('click', saveConfig);
        $('#pi-cfg-test').addEventListener('click', testConnection);

        // F4: Pull now button
        $('#pi-btn-poll-now').addEventListener('click', pollNow);

        // F4.5: Smart-create modal
        $('#pi-create-close').addEventListener('click', () => $('#pi-create-backdrop').classList.add('hidden'));
        $('#pi-create-submit').addEventListener('click', submitSmartCreate);
    }

    // -------------------------------------------------------------------------
    // Słownik kategorii OPEX
    // -------------------------------------------------------------------------
    function opexModalMsg(text, kind) {
        const el = $('#pi-opex-msg');
        if (!el) return;
        el.textContent = text || '';
        el.classList.remove('hidden', 'ok', 'err');
        if (text) el.classList.add(kind === 'err' ? 'err' : 'ok');
        else el.classList.add('hidden');
    }

    async function openOpexModal() {
        const r = await api('list', {}, '/api/procurement/expense_categories.php');
        if (!r.success) {
            showError(r.message || 'Nie udało się pobrać kategorii.');
            return;
        }
        state.expenseCategories = r.data.categories || [];
        const rows = state.expenseCategories.map(c => {
            const isSys = parseInt(String(c.is_system), 10) === 1;
            const actions = isSys
                ? '—'
                : `<input type="text" class="pi-sku-select pi-opex-rename" data-id="${c.id}" value="${escapeHtml(c.name)}" style="max-width:200px" maxlength="128"/>
                   <button type="button" class="pi-btn pi-opex-save" data-id="${c.id}" title="Zapisz"><i class="fa-solid fa-floppy-disk"></i></button>
                   <button type="button" class="pi-btn pi-btn--danger pi-opex-del" data-id="${c.id}" title="Usuń"><i class="fa-solid fa-trash"></i></button>`;
            return `<tr><td>${escapeHtml(c.name)}</td><td>${isSys ? '<span class="pi-type-pill pi-type-pill--INVENTORY">system</span>' : '—'}</td><td style="text-align:right;white-space:nowrap">${actions}</td></tr>`;
        }).join('');
        const wrap = $('#pi-opex-list-wrap');
        if (wrap) {
            wrap.innerHTML = `
                <table class="pi-lines-table">
                    <thead><tr><th>Nazwa</th><th></th><th style="text-align:right">Akcje</th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="3">Brak kategorii</td></tr>'}</tbody>
                </table>`;
        }
        const nn = $('#pi-opex-new-name');
        if (nn) nn.value = '';
        opexModalMsg('', 'ok');
        $('#pi-opex-backdrop').classList.remove('hidden');
        $$('.pi-opex-save').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.getAttribute('data-id') || '0', 10);
                const inp = document.querySelector(`.pi-opex-rename[data-id="${id}"]`);
                const name = inp ? String(inp.value || '').trim() : '';
                if (!name) return;
                const u = await api('update', { id, name }, '/api/procurement/expense_categories.php');
                if (!u.success) { opexModalMsg(u.message || 'Błąd zapisu', 'err'); return; }
                await openOpexModal();
                opexModalMsg('Zapisano nazwę.', 'ok');
            });
        });
        $$('.pi-opex-del').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Usunąć tę kategorię? (soft-delete)')) return;
                const id = parseInt(btn.getAttribute('data-id') || '0', 10);
                const d = await api('delete', { id }, '/api/procurement/expense_categories.php');
                if (!d.success) {
                    opexModalMsg(d.message || 'Nie można usunąć', 'err');
                    return;
                }
                await openOpexModal();
            });
        });
    }

    async function submitOpexAdd() {
        const name = ($('#pi-opex-new-name') && $('#pi-opex-new-name').value.trim()) || '';
        if (!name) return;
        const r = await api('create', { name }, '/api/procurement/expense_categories.php');
        if (!r.success) { opexModalMsg(r.message || 'Błąd', 'err'); return; }
        await openOpexModal();
        opexModalMsg('Dodano kategorię.', 'ok');
    }

    // -------------------------------------------------------------------------
    // F4: KSeF Config
    // -------------------------------------------------------------------------
    async function openConfigModal() {
        const r = await api('config_get', {}, '/api/procurement/ksef_config.php');
        if (!r.success) { showError(r.message || 'Nie udało się załadować konfiguracji.'); return; }
        const d = r.data;
        $('#pi-cfg-env').value = d.environment || 'mock';
        $('#pi-cfg-token').value = '';
        $('#pi-cfg-token-preview').textContent = d.token_preview ? 'Aktualny token: ' + d.token_preview : 'Brak tokenu.';
        $('#pi-cfg-auto-poll').checked = !!d.auto_poll_enabled;
        $('#pi-cfg-state').classList.add('hidden');
        $('#pi-cfg-backdrop').classList.remove('hidden');
    }

    async function saveConfig() {
        const env = $('#pi-cfg-env').value;
        const token = $('#pi-cfg-token').value.trim();
        const autoPoll = $('#pi-cfg-auto-poll').checked;

        const r = await api('config_save', { environment: env, token }, '/api/procurement/ksef_config.php');
        if (!r.success) { showError(r.message || 'Save padł.'); return; }

        // Auto-poll osobno (osobna akcja)
        const rA = await api('toggle_auto_poll', { enabled: autoPoll }, '/api/procurement/ksef_config.php');
        if (!rA.success) showError(rA.message || 'Toggle auto-poll padł.');

        const stateEl = $('#pi-cfg-state');
        stateEl.className = 'pi-cfg-state ok';
        stateEl.textContent = '✓ Zapisano. ' + r.message;
        stateEl.classList.remove('hidden');
        setTimeout(() => { $('#pi-cfg-backdrop').classList.add('hidden'); }, 1500);
    }

    async function testConnection() {
        const stateEl = $('#pi-cfg-state');
        stateEl.className = 'pi-cfg-state';
        stateEl.textContent = '⏳ Testuję połączenie...';
        stateEl.classList.remove('hidden');
        const r = await api('test_connection', {}, '/api/procurement/ksef_config.php');
        stateEl.className = 'pi-cfg-state ' + (r.success ? 'ok' : 'err');
        stateEl.textContent = (r.success ? '✓ ' : '✗ ') + (r.message || (r.data && r.data.message) || '');
    }

    async function pollNow() {
        const orig = $('#pi-btn-poll-now').innerHTML;
        $('#pi-btn-poll-now').disabled = true;
        $('#pi-btn-poll-now').innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Pobieram…';
        const r = await api('poll_now', {}, '/api/procurement/ksef_config.php');
        $('#pi-btn-poll-now').disabled = false;
        $('#pi-btn-poll-now').innerHTML = orig;
        if (!r.success) { showError(r.message || 'Poll padł.'); return; }
        const s = r.data.stats;
        alert(
            'KSeF — tylko odczyt (pobranie do SliceHub). Żadna faktura nie jest wysyłana do MF.\n' +
                `Środowisko: ${r.data.environment}\n` +
                `• Odczytane z API (pozycje na liście): ${s.fetched}\n` +
                `• Nowe w bazie (import): ${s.inserted}\n` +
                `• Pominięte (już były / duplikat): ${s.skipped}\n` +
                `• Błędy (parsowanie / sieć): ${s.errors}`
        );
        loadList();
    }

    // -------------------------------------------------------------------------
    // F4.5: Smart-create modal
    // -------------------------------------------------------------------------
    let smartCreateContext = null;

    function openSmartCreate(line) {
        smartCreateContext = { invoice_id: state.currentInvoice.id, line_id: line.id, external_name: line.external_name };
        $('#pi-create-from').value = line.external_name;
        // Auto-generate SKU z external_name: pierwsze 2-3 słowa, A-Z + _
        const auto = String(line.external_name || '')
            .toUpperCase().replace(/[ĄĆĘŁŃÓŚŹŻ]/g, c => ({Ą:'A',Ć:'C',Ę:'E',Ł:'L',Ń:'N',Ó:'O',Ś:'S',Ź:'Z',Ż:'Z'}[c] || c))
            .replace(/[^A-Z0-9 ]/g, '').trim().split(/\s+/).slice(0, 3).join('_').slice(0, 40);
        $('#pi-create-sku').value = auto || 'NEW_SKU_001';
        $('#pi-create-name').value = line.external_name;
        $('#pi-create-unit').value = (line.unit && ['kg','l','szt','m'].includes(line.unit)) ? line.unit : 'kg';
        $('#pi-create-backdrop').classList.remove('hidden');
    }

    async function submitSmartCreate() {
        if (!smartCreateContext) return;
        const sku = $('#pi-create-sku').value.trim().toUpperCase().replace(/[^A-Z0-9_]/g, '_');
        const name = $('#pi-create-name').value.trim();
        const unit = $('#pi-create-unit').value;
        if (!sku || !name) { alert('SKU i nazwa są wymagane.'); return; }

        const r = await api('smart_create_sku', {
            invoice_id: smartCreateContext.invoice_id,
            line_id: smartCreateContext.line_id,
            sku, name, unit,
        });
        if (!r.success) { showError(r.message || 'Smart-create padł.'); return; }
        alert(`✓ Utworzony SKU '${sku}'. Linia zaktualizowana. Network effect aktywny.`);
        $('#pi-create-backdrop').classList.add('hidden');
        smartCreateContext = null;
        // Reload modal żeby odświeżyć SKU select per linia
        openInvoice(state.currentInvoice.id);
        loadSkuOptions(); // refresh dropdown source
    }

    // -------------------------------------------------------------------------
    // F4.5: Reverse-PZ
    // -------------------------------------------------------------------------
    async function handleReverse() {
        if (!state.currentInvoice) return;
        const inv = state.currentInvoice;
        const costOnly = inv.status === 'accepted' && !inv.linked_wh_document_id;
        const reason = costOnly
            ? prompt('Powód cofnięcia akceptacji (bez PZ, bez magazynu):')
            : prompt('Powód wycofania (KOR + reverse magazynu):');
        if (reason === null) return;
        const confirmMsg = costOnly
            ? 'Cofnąć akceptację? Faktura wróci do „Nowe” — magazyn bez zmian (brak PZ).'
            : 'Wycofać zaakceptowaną fakturę? Magazyn zostanie pomniejszony przez KOR, faktura wróci do statusu „Nowe”.';
        if (!confirm(confirmMsg)) return;
        const r = await api('reverse', { invoice_id: inv.id, reason });
        if (!r.success) { showError(r.message || 'Reverse padł.'); return; }
        if (costOnly || r.data.cost_only) {
            alert('✓ Cofnięto akceptację. Faktura wróciła do „Nowe”.');
        } else {
            alert(`✓ Wycofano. KOR ${r.data.kor_doc_number} utworzony.`);
        }
        closeModal();
        loadList();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        console.log('[procurement_app] booted', new Date().toISOString());
        bindEvents();
        await loadSkuOptions();
        await loadList();
    });
})();
