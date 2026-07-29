/**
 * SliceHub — Edytuj zamówienie (frontend, Faza E)
 * Vanilla JS, brak frameworków per Konstytucja §3.
 *
 * Auth: token JWT z localStorage['sh_token'] (zapisywany przy loginie w Hub).
 * API:
 *   - GET  api/orders/get.php?order_id=...        (read-only: order + lines)
 *   - POST api/orders/edit.php                    (CartEngine + DeltaEngine → kitchen_delta)
 *   - POST api/backoffice/api_menu_studio.php     (get_menu_tree — picker nowych linii)
 *
 * Prawo IV (Zero Zaufania): frontend NIE wysyła cen ani totali — tylko SKU + qty
 * (+ line_id dla istniejących linii, żeby DeltaEngine dopasował added/removed/modified).
 * Serwer przelicza CartEngine::calculate() i zwraca przeliczone totaly.
 */
(function () {
    'use strict';

    function apiUrl(path) {
        if (typeof window !== 'undefined' && window.SliceHub && window.SliceHub.apiUrl) {
            return window.SliceHub.apiUrl(path);
        }
        const base = (window.SliceHub && window.SliceHub.getApiBase)
            ? window.SliceHub.getApiBase()
            : ((window.SliceHub && window.SliceHub.getApiFallback) ? window.SliceHub.getApiFallback() : '/api');
        const p = String(path || '').trim();
        if (!p) return base;
        return base + (p.startsWith('/') ? p : '/' + p);
    }

    const GET_ENDPOINT = apiUrl('/orders/get.php');
    const EDIT_ENDPOINT = apiUrl('/orders/edit.php');
    const MENU_ENDPOINT = apiUrl('/backoffice/api_menu_studio.php');
    const $ = (sel) => document.querySelector(sel);

    let state = {
        order: null,        // header z get.php
        originalLines: [],  // linie z get.php (referencja do detekcji zmian w UI)
        workingLines: [],   // aktualny stan edycji [{line_id, item_sku, snapshot_name, quantity, _status}]
        menuItems: [],      // [{asciiKey, name, categoryId}] z get_menu_tree
        categories: [],
    };

    // -------------------------------------------------------------------------
    // API helpers
    // -------------------------------------------------------------------------
    function getToken() { return localStorage.getItem('sh_token') || ''; }

    function authHeaders(json) {
        const h = {};
        const tok = getToken();
        if (tok) h['Authorization'] = 'Bearer ' + tok;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async function getJson(url) {
        const tok = getToken();
        if (!tok) return { success: false, message: 'Brak tokenu — zaloguj się w Hub i wróć.', code: 'NO_TOKEN' };
        let res;
        try {
            res = await fetch(url, { method: 'GET', headers: authHeaders(false) });
        } catch (netErr) {
            return { success: false, message: 'Błąd sieci: ' + (netErr.message || netErr), code: 'NETWORK' };
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            return { success: false, message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').', code: 'NON_JSON', raw: text.slice(0, 300) };
        }
        return json;
    }

    async function postJson(url, body) {
        const tok = getToken();
        if (!tok) return { success: false, message: 'Brak tokenu — zaloguj się w Hub i wróć.', code: 'NO_TOKEN' };
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                headers: authHeaders(true),
                body: JSON.stringify(body),
            });
        } catch (netErr) {
            return { success: false, message: 'Błąd sieci: ' + (netErr.message || netErr), code: 'NETWORK' };
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            return { success: false, message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').', code: 'NON_JSON', raw: text.slice(0, 300) };
        }
        return json;
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------
    function _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function showBanner(id, html, show) {
        const el = $(id);
        if (!el) return;
        if (html !== null) el.innerHTML = html;
        el.classList.toggle('hidden', !show);
    }
    function fmtMoney(s) {
        const n = Number(s);
        if (!Number.isFinite(n)) return '—';
        return n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------
    function init() {
        if (!getToken()) {
            showBanner('#oe-auth-banner', null, true);
            return;
        }
        $('#oe-btn-load').addEventListener('click', onLoad);
        $('#oe-btn-reload').addEventListener('click', () => {
            const oid = $('#oe-order-id').value.trim();
            if (oid) onLoad();
        });
        $('#oe-btn-add-line').addEventListener('click', onAddLine);
        $('#oe-btn-save').addEventListener('click', onSave);
        $('#oe-btn-cancel').addEventListener('click', onCancel);

        // ?order_id=... w URL — auto-load
        const params = new URLSearchParams(window.location.search || '');
        const oid = (params.get('order_id') || '').trim();
        if (oid) {
            $('#oe-order-id').value = oid;
            onLoad();
        }
    }

    // -------------------------------------------------------------------------
    // Load order + menu (równolegle)
    // -------------------------------------------------------------------------
    async function onLoad() {
        const orderId = $('#oe-order-id').value.trim();
        if (!orderId) {
            showBanner('#oe-error-banner', 'Podaj order_id.', true);
            return;
        }
        showBanner('#oe-error-banner', null, false);
        showBanner('#oe-info-banner', null, false);
        $('#oe-form').classList.add('hidden');

        const [orderRes, menuRes] = await Promise.all([
            getJson(GET_ENDPOINT + '?order_id=' + encodeURIComponent(orderId)),
            state.menuItems.length ? Promise.resolve(null) : postJson(MENU_ENDPOINT, { action: 'get_menu_tree' }),
        ]);

        if (!orderRes.success) {
            showBanner('#oe-error-banner', _esc(orderRes.message || 'Nie udało się wczytać zamówienia.'), true);
            return;
        }
        if (menuRes) {
            if (menuRes.success) {
                const data = menuRes.data || {};
                state.categories = Array.isArray(data.categories) ? data.categories : [];
                state.menuItems = (Array.isArray(data.items) ? data.items : [])
                    .filter(it => it && it.asciiKey);
            } else {
                // Menu picker opcjonalny — edycja istniejących linii działa bez niego.
                state.menuItems = [];
            }
        }

        state.order = orderRes.data.order;
        state.originalLines = (orderRes.data.lines || []).map(l => ({ ...l }));
        state.workingLines = (orderRes.data.lines || []).map(l => ({
            line_id: l.line_id,
            item_sku: l.item_sku,
            snapshot_name: l.snapshot_name,
            quantity: l.quantity,
            _status: 'unchanged',
        }));

        $('#oe-order-num').textContent = state.order.order_number || state.order.id;
        $('#oe-form').classList.remove('hidden');
        renderLines();
        renderSummary();
    }

    // -------------------------------------------------------------------------
    // Render linii
    // -------------------------------------------------------------------------
    function renderLines() {
        const container = $('#oe-lines');
        if (state.workingLines.length === 0) {
            container.innerHTML = '<div class="oe-empty"><i class="fa-solid fa-inbox"></i>Brak linii — dodaj nową poniżej.</div>';
            return;
        }
        container.innerHTML = state.workingLines.map((l, idx) => {
            const statusCls = l._status === 'new' ? 'new' : l._status === 'modified' ? 'modified' : '';
            const statusTxt = l._status === 'new' ? 'NOWA' : l._status === 'modified' ? 'zmieniona' : 'istniejąca';
            const menuOpts = state.menuItems.length
                ? state.menuItems.map(mi =>
                    `<option value="${_esc(mi.asciiKey)}"${mi.asciiKey === l.item_sku ? ' selected' : ''}>${_esc(mi.name)}</option>`
                ).join('')
                : '';
            const skuCell = state.menuItems.length
                ? `<select class="oe-line-sku-select" data-idx="${idx}">${menuOpts}</select>
                   <div class="oe-line-name">${_esc(l.snapshot_name)}</div>`
                : `<div class="oe-line-sku"><code>${_esc(l.item_sku)}</code></div>
                   <div class="oe-line-name">${_esc(l.snapshot_name)}</div>`;
            return `<div class="oe-line">
                <div>${skuCell}</div>
                <div class="oe-line-qty"><input type="number" min="1" step="1" value="${l.quantity}" data-idx="${idx}" class="oe-line-qty-input"></div>
                <div class="oe-line-status ${statusCls}">${statusTxt}</div>
                <div><button type="button" class="oe-btn oe-btn--danger" data-idx="${idx}" title="Usuń linię"><i class="fa-solid fa-trash"></i></button></div>
            </div>`;
        }).join('');

        // Bind events
        container.querySelectorAll('.oe-line-qty-input').forEach(inp => {
            inp.addEventListener('change', (e) => onQtyChange(+e.target.dataset.idx, e.target.value));
        });
        container.querySelectorAll('.oe-line-sku-select').forEach(sel => {
            sel.addEventListener('change', (e) => onSkuChange(+e.target.dataset.idx, e.target.value));
        });
        container.querySelectorAll('.oe-btn--danger').forEach(btn => {
            btn.addEventListener('click', (e) => onRemoveLine(+e.currentTarget.dataset.idx));
        });
    }

    function onQtyChange(idx, val) {
        const q = Math.max(1, parseInt(val, 10) || 1);
        const orig = state.originalLines.find(o => o.line_id === state.workingLines[idx].line_id);
        const isModified = orig && (orig.quantity !== q || state.workingLines[idx].item_sku !== orig.item_sku);
        state.workingLines[idx].quantity = q;
        state.workingLines[idx]._status = state.workingLines[idx]._status === 'new' ? 'new' : (isModified ? 'modified' : 'unchanged');
        renderLines();
    }

    function onSkuChange(idx, sku) {
        const mi = state.menuItems.find(m => m.asciiKey === sku);
        state.workingLines[idx].item_sku = sku;
        state.workingLines[idx].snapshot_name = mi ? mi.name : sku;
        const orig = state.originalLines.find(o => o.line_id === state.workingLines[idx].line_id);
        const isModified = orig && (orig.item_sku !== sku || state.workingLines[idx].quantity !== orig.quantity);
        state.workingLines[idx]._status = state.workingLines[idx]._status === 'new' ? 'new' : (isModified ? 'modified' : 'unchanged');
        renderLines();
    }

    function onRemoveLine(idx) {
        if (!confirm('Usunąć tę linię z zamówienia?')) return;
        state.workingLines.splice(idx, 1);
        renderLines();
    }

    function onAddLine() {
        if (state.menuItems.length === 0) {
            showBanner('#oe-info-banner', 'Brak menu do wyboru — dodawanie nowych linii wymaga <code>get_menu_tree</code>.', true);
            return;
        }
        const first = state.menuItems[0];
        state.workingLines.push({
            line_id: null,           // brak line_id = "added" dla DeltaEngine
            item_sku: first.asciiKey,
            snapshot_name: first.name,
            quantity: 1,
            _status: 'new',
        });
        renderLines();
    }

    // -------------------------------------------------------------------------
    // Podsumowanie (lokalny podgląd — serwer przelicza ostatecznie)
    // -------------------------------------------------------------------------
    function renderSummary() {
        const sum = $('#oe-summary');
        const order = state.order;
        if (!order) { sum.innerHTML = ''; return; }
        const lineCount = state.workingLines.length;
        const added = state.workingLines.filter(l => l._status === 'new').length;
        const modified = state.workingLines.filter(l => l._status === 'modified').length;
        const removed = state.originalLines.length - state.workingLines.filter(l => l._status !== 'new').length;
        sum.innerHTML = `
            <div class="oe-stat"><div class="oe-stat-label">Status</div><div class="oe-stat-value" style="font-size:14px">${_esc(order.status)}</div></div>
            <div class="oe-stat"><div class="oe-stat-label">Kanał / Typ</div><div class="oe-stat-value" style="font-size:14px">${_esc(order.channel)} / ${_esc(order.order_type)}</div></div>
            <div class="oe-stat"><div class="oe-stat-label">Aktualny total</div><div class="oe-stat-value">${fmtMoney(order.grand_total)} zł</div></div>
            <div class="oe-stat"><div class="oe-stat-label">Linie</div><div class="oe-stat-value">${lineCount}</div></div>
            <div class="oe-delta-preview">
                <h4>Podgląd zmian (lokalnie)</h4>
                <div class="oe-delta-row"><span class="oe-delta-add">+ dodane: ${added}</span></div>
                <div class="oe-delta-row"><span class="oe-delta-mod">~ zmienione: ${modified}</span></div>
                <div class="oe-delta-row"><span class="oe-delta-rem">- usunięte: ${Math.max(0, removed)}</span></div>
            </div>`;
    }

    // -------------------------------------------------------------------------
    // Save → POST edit.php
    // -------------------------------------------------------------------------
    async function onSave() {
        const order = state.order;
        if (!order) return;
        if (state.workingLines.length === 0) {
            if (!confirm('Zamówienie będzie miało 0 linii — CartEngine odrzuci pusty koszyk. Kontynuować?')) return;
        }
        showBanner('#oe-info-banner', 'Zapisywanie…', true);
        showBanner('#oe-error-banner', null, false);

        // Prawo IV: tylko SKU + qty + line_id. Bez cen, bez totali.
        const lines = state.workingLines.map(l => ({
            line_id: l.line_id || null,
            item_sku: l.item_sku,
            quantity: l.quantity,
        }));

        const body = {
            order_id: order.id,
            channel: order.channel,
            order_type: order.order_type,
            lines,
        };

        const r = await postJson(EDIT_ENDPOINT, body);
        if (!r.success) {
            showBanner('#oe-info-banner', null, false);
            showBanner('#oe-error-banner', _esc(r.message || 'Błąd zapisu.'), true);
            return;
        }

        const delta = r.data && r.data.delta;
        const grandTotal = r.data && r.data.grand_total;
        let deltaHtml = '';
        if (delta) {
            const added = (delta.added || []).map(a => `<div class="oe-delta-row oe-delta-add">+ ${_esc(a.snapshot_name)} × ${a.quantity}</div>`).join('');
            const removed = (delta.removed || []).map(rm => `<div class="oe-delta-row oe-delta-rem">- ${_esc(rm.snapshot_name)} × ${rm.quantity}</div>`).join('');
            const modified = (delta.modified || []).map(m => `<div class="oe-delta-row oe-delta-mod">~ ${_esc(m.snapshot_name)}: ${Object.keys(m.changes || {}).join(', ')}</div>`).join('');
            deltaHtml = `<div class="oe-delta-preview"><h4>Kitchen delta (zapisane)</h4>${added}${removed}${modified || '<div class="oe-delta-row">brak zmian modyfikowanych</div>'}</div>`;
        } else {
            deltaHtml = '<div class="oe-info-line">Brak zmian (No changes detected).</div>';
        }

        showBanner('#oe-info-banner',
            `<strong>Zapisano.</strong> Nowy total: <strong>${fmtMoney(grandTotal)} zł</strong>. KDS zobaczy <code>kitchen_delta</code>. ${deltaHtml}`,
            true);

        // Przeład z serwera żeby zaktualizować originalLines
        await onLoad();
    }

    function onCancel() {
        if (!confirm('Odrzucić zmiany i przeładować z serwera?')) return;
        onLoad();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
