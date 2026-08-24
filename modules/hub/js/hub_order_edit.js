/**
 * SliceHub Hub — modal "Edytuj zamówienie".
 * Lista aktywnych zamówień → edycja pozycji (ilość, usunięcie, dodanie,
 * modyfikatory, komentarz) → POST api/orders/edit.php (DeltaEngine).
 */
const HubOrderEdit = (() => {
    'use strict';

    function apiUrl(path) {
        if (globalThis.SliceHub && globalThis.SliceHub.apiUrl) return globalThis.SliceHub.apiUrl(path);
        const base = (globalThis.SliceHub && globalThis.SliceHub.getApiBase)
            ? globalThis.SliceHub.getApiBase()
            : '/api';
        return base + (path.startsWith('/') ? path : '/' + path);
    }

    const $ = (id) => document.getElementById(id);

    /** @type {{order:object, lines:object[], menu_items:object[], modifiers:object[]}|null} */
    let _ctx = null;
    /** Robocze linie modala (istniejące + nowe). */
    let _draft = [];

    async function _post(path, payload) {
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
        const token = localStorage.getItem('sh_token') || '';
        if (token) headers['Authorization'] = 'Bearer ' + token;
        try {
            const res = await fetch(apiUrl(path), {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const json = await res.json().catch(() => null);
            if (!json) return { success: false, message: `Błąd serwera (HTTP ${res.status}).` };
            return json;
        } catch {
            return { success: false, message: 'Brak połączenia z serwerem.' };
        }
    }

    function _esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function _setError(msg) {
        const el = $('hoe-error');
        if (el) el.textContent = msg || '';
    }

    function _show(view) {
        $('hoe-overlay')?.classList.remove('hub-hidden');
        $('hoe-view-list')?.classList.toggle('hub-hidden', view !== 'list');
        $('hoe-view-edit')?.classList.toggle('hub-hidden', view !== 'edit');
        _setError('');
    }

    function close() {
        $('hoe-overlay')?.classList.add('hub-hidden');
        _ctx = null;
        _draft = [];
    }

    // ── Lista zamówień ────────────────────────────────────────────────────
    async function open() {
        _show('list');
        const box = $('hoe-order-list');
        box.innerHTML = '<p class="hoe-muted">Ładowanie…</p>';
        const r = await _post('/orders/get_for_edit.php', { action: 'list_orders' });
        if (!r.success) {
            box.innerHTML = '';
            _setError(r.message || 'Nie udało się pobrać zamówień.');
            return;
        }
        const orders = (r.data && r.data.orders) || [];
        if (!orders.length) {
            box.innerHTML = '<p class="hoe-muted">Brak aktywnych zamówień.</p>';
            return;
        }
        box.innerHTML = orders.map((o) => `
            <button type="button" class="hoe-order-row" data-oid="${_esc(o.id)}">
                <span class="hoe-order-num">#${_esc((o.order_number || '').split('/').pop())}</span>
                <span class="hoe-order-meta">${_esc(o.status)} · ${_esc(o.order_type)}${o.customer_name ? ' · ' + _esc(o.customer_name) : ''}</span>
                <span class="hoe-order-total">${_esc(o.grand_total_formatted)} zł</span>
            </button>`).join('');
        box.querySelectorAll('[data-oid]').forEach((btn) => {
            btn.addEventListener('click', () => { void loadOrder(btn.getAttribute('data-oid')); });
        });
    }

    // ── Edycja zamówienia ─────────────────────────────────────────────────
    async function loadOrder(orderId) {
        _show('edit');
        const box = $('hoe-lines');
        box.innerHTML = '<p class="hoe-muted">Ładowanie…</p>';
        const r = await _post('/orders/get_for_edit.php', { action: 'get_order', order_id: orderId });
        if (!r.success || !r.data) {
            _setError(r.message || 'Nie udało się pobrać zamówienia.');
            box.innerHTML = '';
            return;
        }
        _ctx = r.data;
        _draft = (_ctx.lines || []).map((l) => ({
            line_id: l.id,
            item_sku: l.item_sku,
            snapshot_name: l.snapshot_name,
            quantity: parseInt(l.quantity, 10) || 1,
            added_modifier_skus: _skusFromJson(l.modifiers_json),
            removed_ingredient_skus: _skusFromJson(l.removed_ingredients_json),
            comment: l.comment || '',
        }));
        $('hoe-edit-title').textContent =
            'Zamówienie #' + ((_ctx.order.order_number || '').split('/').pop());
        const typeSel = $('hoe-order-type');
        if (typeSel) typeSel.value = _ctx.order.order_type || 'dine_in';
        const addr = $('hoe-delivery-address');
        if (addr) addr.value = _ctx.order.delivery_address || '';
        _syncAddressVisibility();
        _fillAddSelect();
        renderLines();
    }

    function _syncAddressVisibility() {
        const isDelivery = ($('hoe-order-type')?.value || '') === 'delivery';
        $('hoe-delivery-address')?.classList.toggle('hub-hidden', !isDelivery);
    }

    function _skusFromJson(raw) {
        if (!raw) return [];
        try {
            const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (!Array.isArray(arr)) return [];
            // CartEngine zapisuje 'sku', starsze wpisy POS — 'ascii_key'
            return arr.map((m) => (m && (m.sku || m.ascii_key)) || '').filter(Boolean);
        } catch { return []; }
    }

    function _fillAddSelect() {
        const sel = $('hoe-add-item');
        sel.replaceChildren();
        const def = document.createElement('option');
        def.value = '';
        def.textContent = '— wybierz danie —';
        sel.appendChild(def);
        (_ctx.menu_items || []).forEach((mi) => {
            const o = document.createElement('option');
            o.value = mi.sku;
            o.textContent = mi.name;
            sel.appendChild(o);
        });
    }

    function _modName(sku) {
        const m = (_ctx.modifiers || []).find((x) => x.sku === sku);
        return m ? m.name : sku;
    }

    function renderLines() {
        const box = $('hoe-lines');
        if (!_draft.length) {
            box.innerHTML = '<p class="hoe-muted">Brak pozycji — dodaj danie poniżej.</p>';
            return;
        }
        box.innerHTML = _draft.map((l, i) => {
            const mods = (l.added_modifier_skus || [])
                .map((sku) => `<span class="hoe-mod-chip">${_esc(_modName(sku))}<button type="button" data-act="del-mod" data-i="${i}" data-sku="${_esc(sku)}">×</button></span>`)
                .join('');
            return `<div class="hoe-line${l.line_id ? '' : ' hoe-line--new'}">
                <div class="hoe-line-head">
                    <span class="hoe-line-name">${_esc(l.snapshot_name)}${l.line_id ? '' : ' <em>(nowa)</em>'}</span>
                    <div class="hoe-qty">
                        <button type="button" data-act="qty" data-i="${i}" data-d="-1">−</button>
                        <span>${l.quantity}</span>
                        <button type="button" data-act="qty" data-i="${i}" data-d="1">+</button>
                    </div>
                    <button type="button" class="hoe-line-del" data-act="del-line" data-i="${i}" title="Usuń pozycję"><i class="fa-solid fa-trash"></i></button>
                </div>
                <div class="hoe-line-mods">
                    ${mods}
                    <select data-act="add-mod" data-i="${i}">
                        <option value="">+ modyfikator</option>
                        ${(_ctx.modifiers || [])
                            .filter((m) => !(l.added_modifier_skus || []).includes(m.sku))
                            .map((m) => `<option value="${_esc(m.sku)}">${_esc(m.name)}</option>`).join('')}
                    </select>
                </div>
                <input type="text" class="hoe-line-comment" data-act="comment" data-i="${i}"
                       placeholder="Komentarz dla kuchni" value="${_esc(l.comment)}">
            </div>`;
        }).join('');

        box.querySelectorAll('[data-act="qty"]').forEach((b) => b.addEventListener('click', () => {
            const l = _draft[+b.dataset.i];
            l.quantity = Math.max(1, l.quantity + (+b.dataset.d));
            renderLines();
        }));
        box.querySelectorAll('[data-act="del-line"]').forEach((b) => b.addEventListener('click', () => {
            _draft.splice(+b.dataset.i, 1);
            renderLines();
        }));
        box.querySelectorAll('[data-act="del-mod"]').forEach((b) => b.addEventListener('click', () => {
            const l = _draft[+b.dataset.i];
            l.added_modifier_skus = l.added_modifier_skus.filter((s) => s !== b.dataset.sku);
            renderLines();
        }));
        box.querySelectorAll('[data-act="add-mod"]').forEach((s) => s.addEventListener('change', () => {
            if (!s.value) return;
            _draft[+s.dataset.i].added_modifier_skus.push(s.value);
            renderLines();
        }));
        box.querySelectorAll('[data-act="comment"]').forEach((inp) => inp.addEventListener('input', () => {
            _draft[+inp.dataset.i].comment = inp.value;
        }));
    }

    function addLine() {
        const sel = $('hoe-add-item');
        const sku = sel.value;
        if (!sku) return;
        const mi = (_ctx.menu_items || []).find((x) => x.sku === sku);
        _draft.push({
            line_id: null,
            item_sku: sku,
            snapshot_name: (mi && mi.name) || sku,
            quantity: 1,
            added_modifier_skus: [],
            removed_ingredient_skus: [],
            comment: '',
        });
        sel.value = '';
        renderLines();
    }

    function _lineToPayload(l) {
        const out = {
            quantity: l.quantity,
            added_modifier_skus: l.added_modifier_skus || [],
            removed_ingredient_skus: l.removed_ingredient_skus || [],
            comment: l.comment || '',
        };
        if (l.line_id) out.line_id = l.line_id;
        // Half-half: item_sku zapisany jako "SKU_A+SKU_B" (CartEngine composite)
        if (l.item_sku.includes('+')) {
            const [a, b] = l.item_sku.split('+');
            out.is_half = true;
            out.half_a_sku = a;
            out.half_b_sku = b;
        } else {
            out.item_sku = l.item_sku;
        }
        return out;
    }

    /** CartEngine oczekuje kanonicznych kanałów; starsze zamówienia mają lowercase. */
    function _canonChannel(ch) {
        const map = { pos: 'POS', takeaway: 'Takeaway', delivery: 'Delivery' };
        return map[String(ch || '').toLowerCase()] || ch;
    }

    async function save() {
        if (!_ctx) return;
        if (!_draft.length) {
            _setError('Zamówienie musi mieć co najmniej jedną pozycję.');
            return;
        }
        const orderType = $('hoe-order-type')?.value || _ctx.order.order_type;
        const address = ($('hoe-delivery-address')?.value || '').trim();
        if (orderType === 'delivery' && !address) {
            _setError('Zamówienie z dostawą wymaga adresu.');
            return;
        }
        const btn = $('hoe-btn-save');
        btn.disabled = true;
        _setError('');
        const payload = {
            order_id: _ctx.order.id,
            channel: _canonChannel(_ctx.order.channel),
            order_type: orderType,
            lines: _draft.map(_lineToPayload),
        };
        if (orderType === 'delivery') payload.delivery_address = address;
        const r = await _post('/orders/edit.php', payload);
        btn.disabled = false;
        if (!r.success) {
            _setError(r.message || 'Nie udało się zapisać zmian.');
            return;
        }
        const delta = r.data && r.data.delta;
        const TYPE_LABELS = { dine_in: 'Sala', takeaway: 'Wynos', delivery: 'Dostawa' };
        let info;
        if (delta) {
            info = `Zapisano. Zmiany: +${(delta.added || []).length} / −${(delta.removed || []).length} / ~${(delta.modified || []).length}.`;
            if (delta.order_type) {
                info += ` Typ: ${TYPE_LABELS[delta.order_type.old] || delta.order_type.old} → ${TYPE_LABELS[delta.order_type.new] || delta.order_type.new}.`;
            }
            info += ' KDS zobaczy różnice na bilecie.';
        } else {
            info = r.message || 'Brak zmian.';
        }
        alert(info);
        close();
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('hub-card-order-edit')?.addEventListener('click', (ev) => {
            ev.preventDefault();
            void open();
        });
        $('hoe-btn-close')?.addEventListener('click', close);
        $('hoe-btn-back')?.addEventListener('click', () => { void open(); });
        $('hoe-btn-add')?.addEventListener('click', addLine);
        $('hoe-order-type')?.addEventListener('change', _syncAddressVisibility);
        $('hoe-btn-save')?.addEventListener('click', () => { void save(); });
        $('hoe-overlay')?.addEventListener('click', (ev) => {
            if (ev.target === $('hoe-overlay')) close();
        });
    });

    return Object.freeze({ open, close });
})();
