/**
 * SliceHub Hub — Historia zamówień + Oś czasu (Audit Timeline).
 * RFC-001 Faza 1 (read-only).
 *
 * Widok tabeli wszystkich zamówień (w tym completed/cancelled) z filtrami,
 * paginacją i drawerem zawierającym oś czasu zagregowaną z 3 tabel
 * (sh_order_audit + sh_order_logs + sh_event_outbox).
 *
 * Konsumenci API:
 *   POST /api/orders/history.php  { action: "list", filters, pagination, sort }
 *   POST /api/orders/audit.php    { action: "timeline", order_id }
 *
 * Bezpieczeństwo: zero mutacji — wszystkie endpointy read-only.
 * Przyciski akcji (Edytuj metadane / Edytuj pozycje / Cofnij / Otwórz ponownie)
 * są renderowane jako disabled placeholder (Faza 2/3).
 */
const HubOrderHistory = (() => {
    'use strict';

    // ── Helpers ──────────────────────────────────────────────────────────
    function apiUrl(path) {
        if (globalThis.SliceHub && globalThis.SliceHub.apiUrl) return globalThis.SliceHub.apiUrl(path);
        const base = (globalThis.SliceHub && globalThis.SliceHub.getApiBase)
            ? globalThis.SliceHub.getApiBase()
            : '/api';
        return base + (path.startsWith('/') ? path : '/' + path);
    }

    const $ = (id) => document.getElementById(id);

    function _esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

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

    // ── State ────────────────────────────────────────────────────────────
    /** @type {{page:number, per_page:number, total:number, total_pages:number}} */
    let _pagination = { page: 1, per_page: 50, total: 0, total_pages: 1 };
    /** @type {object[]|null} aktualnie załadowane zamówienia (lista) */
    let _orders = null;
    /** @type {object|null} aktualnie otwarte zamówienie w drawerze (z audit.php) */
    let _detail = null;

    // ── Status / type / payment labels (PL) ──────────────────────────────
    const STATUS_LABELS = {
        new: 'Nowe', accepted: 'Zaakceptowane', preparing: 'W przygotowaniu',
        ready: 'Gotowe', dispatched: 'Wysłane', in_delivery: 'W dostawie',
        delivered: 'Dostarczone', completed: 'Zakończone', cancelled: 'Anulowane',
    };
    const STATUS_BADGE = {
        completed: '✓ Zakoń.', cancelled: '✗ Anulow.', new: '● Nowe',
        accepted: '● Zaakc.', preparing: '● Przyg.', ready: '● Gotowe',
        dispatched: '● Wysłane', in_delivery: '● Dostawa', delivered: '● Dostar.',
    };
    const TYPE_LABELS = { dine_in: 'Sala', takeaway: 'Wynos', delivery: 'Dostawa' };
    const PAY_LABELS = {
        to_pay: 'Do zapłaty', online_unpaid: 'Online (nieopłacone)',
        cash: 'Gotówka', card: 'Karta', online_paid: 'Online (opłacone)',
    };

    // ── Timeline dot colors per event_type (RFC §5.3) ────────────────────
    const EVENT_COLORS = {
        'order.created': '#22c55e', 'order.accepted': '#3b82f6',
        'order.preparing': '#3b82f6', 'order.ready': '#3b82f6',
        'order.completed': '#22c55e', 'order.cancelled': '#ef4444',
        'order.edited': '#eab308', 'order.recalled': '#eab308',
        'order.dispatched': '#8b5cf6', 'order.in_delivery': '#8b5cf6',
        'order.delivered': '#8b5cf6', 'reopen': '#a855f7', 'revert': '#a855f7',
        'force_edit': '#ef4444', 'metadata.edit': '#eab308',
        'payment.change': '#eab308', 'fiscal.print': '#14b8a6',
        'wh.consume': '#14b8a6', 'wh.reverse': '#14b8a6',
    };

    // =========================================================================
    // OrderTimelineStack — adaptacja HistoryStack.js (RFC §3.4)
    // Lightweight kursor po snapshotach z outboxu (undo/redo navigation).
    // =========================================================================
    class OrderTimelineStack {
        constructor(events) {
            this._stack = (events || []).filter((e) => e && e.snapshot).map((e) => ({
                snapshot: e.snapshot,
                label: e.label_pl,
                ts: e.ts,
                event_id: e.event_id,
                event_type: e.event_type,
            }));
            this._cursor = this._stack.length - 1;
        }
        canUndo() { return this._cursor > 0; }
        canRedo() { return this._cursor < this._stack.length - 1; }
        undo() { if (this.canUndo()) this._cursor--; return this.current(); }
        redo() { if (this.canRedo()) this._cursor++; return this.current(); }
        current() { return this._stack[this._cursor] || null; }
        labels() { return this._stack.map((s) => s.label); }
        size() { return this._stack.length; }
        cursor() { return this._cursor; }
        at(i) { return this._stack[i] || null; }
    }

    // ── View switching ────────────────────────────────────────────────────
    function _show(view) {
        $('hoh-overlay')?.classList.remove('hub-hidden');
        $('hoh-view-list')?.classList.toggle('hub-hidden', view !== 'list');
        $('hoh-view-drawer')?.classList.toggle('hub-hidden', view !== 'drawer');
        _setError('');
    }

    function _setError(msg) {
        const el = $('hoh-error');
        if (el) el.textContent = msg || '';
    }

    function close() {
        $('hoh-overlay')?.classList.add('hub-hidden');
        _orders = null;
        _detail = null;
    }

    // ── Filter bar → payload ──────────────────────────────────────────────
    function _collectFilters() {
        const f = {};
        const df = $('hoh-filter-date-from')?.value || '';
        const dt = $('hoh-filter-date-to')?.value || '';
        if (df) f.date_from = df;
        if (dt) f.date_to = dt;
        const st = $('hoh-filter-status')?.value || '';
        if (st) f.status = [st];
        const ot = $('hoh-filter-type')?.value || '';
        if (ot) f.order_type = [ot];
        const ps = $('hoh-filter-payment')?.value || '';
        if (ps) f.payment_status = [ps];
        const src = $('hoh-filter-source')?.value || '';
        if (src) f.source = [src];
        const sq = $('hoh-filter-search')?.value || '';
        if (sq.trim()) f.search = sq.trim();
        const fisc = $('hoh-filter-fiscalized')?.checked;
        if (fisc) f.fiscalized = true;
        const corr = $('hoh-filter-corrected')?.checked;
        // is_corrected filter — backend nie wspiera jeszcze (Faza 3 kolumna),
        // ignorujemy po stronie serwera; klient filtruje post-fetch.
        return { filters: f, _correctedOnly: !!corr };
    }

    // ── Lista zamówień (tabela) ───────────────────────────────────────────
    async function open() {
        _show('list');
        _pagination.page = 1;
        await _loadList();
    }

    async function _loadList() {
        const box = $('hoh-table-body');
        if (box) box.innerHTML = '<tr><td colspan="7" class="hoh-muted">Ładowanie…</td></tr>';
        const { filters, _correctedOnly } = _collectFilters();
        const payload = {
            action: 'list',
            filters,
            pagination: { page: _pagination.page, per_page: _pagination.per_page },
            sort: { field: 'created_at', dir: 'desc' },
        };
        const r = await _post('/orders/history.php', payload);
        if (!r.success || !r.data) {
            if (box) box.innerHTML = '';
            _setError(r.message || 'Nie udało się pobrać historii zamówień.');
            _renderPagination();
            return;
        }
        let orders = r.data.orders || [];
        if (_correctedOnly) {
            orders = orders.filter((o) => o.is_corrected === true);
        }
        _orders = orders;
        _pagination = r.data.pagination || _pagination;
        _renderTable(orders);
        _renderPagination();
    }

    function _renderTable(orders) {
        const box = $('hoh-table-body');
        if (!box) return;
        if (!orders.length) {
            box.innerHTML = '<tr><td colspan="7" class="hoh-muted">Brak zamówień dla wybranych filtrów.</td></tr>';
            return;
        }
        box.innerHTML = orders.map((o) => {
            const badge = STATUS_BADGE[o.status] || _esc(o.status);
            const rowClass = o.is_corrected ? 'hoh-row--corrected'
                : (o.status === 'completed' ? 'hoh-row--completed'
                    : (o.status === 'cancelled' ? 'hoh-row--cancelled' : ''));
            const corrBadge = o.is_corrected ? ' <span class="hoh-badge hoh-badge--corrected" title="Korygowane">⚠ Skor.</span>' : '';
            const fiscBadge = o.fiscal_receipt_number ? ' <span class="hoh-badge hoh-badge--fiscal" title="Sfiskalizowane">🧾</span>' : '';
            const time = (o.created_at || '').slice(11, 16);
            const cust = o.customer_name || '—';
            const type = TYPE_LABELS[o.order_type] || o.order_type || '—';
            const pay = PAY_LABELS[o.payment_status] || o.payment_status || '—';
            return `<tr class="${rowClass}" data-oid="${_esc(o.id)}">
                <td class="hoh-cell-num">#${_esc((o.order_number || '').split('/').pop() || o.order_number)}${fiscBadge}${corrBadge}</td>
                <td class="hoh-cell-cust">${_esc(cust)}</td>
                <td class="hoh-cell-status">${_esc(badge)}</td>
                <td class="hoh-cell-type">${_esc(type)}</td>
                <td class="hoh-cell-pay">${_esc(pay)}</td>
                <td class="hoh-cell-total">${_esc(o.grand_total_formatted)} zł</td>
                <td class="hoh-cell-time">${_esc(time)}</td>
            </tr>`;
        }).join('');
        box.querySelectorAll('[data-oid]').forEach((tr) => {
            tr.addEventListener('click', () => { void openDrawer(tr.getAttribute('data-oid')); });
        });
    }

    function _renderPagination() {
        const info = $('hoh-page-info');
        const btnPrev = $('hoh-btn-prev');
        const btnNext = $('hoh-btn-next');
        const perPageSel = $('hoh-per-page');
        if (info) {
            info.textContent = `Strona ${_pagination.page} z ${_pagination.total_pages} · ${_pagination.total} zamówień`;
        }
        if (btnPrev) btnPrev.disabled = _pagination.page <= 1;
        if (btnNext) btnNext.disabled = _pagination.page >= _pagination.total_pages;
        if (perPageSel && perPageSel.value != String(_pagination.per_page)) {
            perPageSel.value = String(_pagination.per_page);
        }
    }

    // ── Drawer ze szczegółami + Oś czasu ──────────────────────────────────
    async function openDrawer(orderId) {
        _show('drawer');
        const box = $('hoh-drawer-content');
        if (box) box.innerHTML = '<p class="hoh-muted">Ładowanie…</p>';
        const r = await _post('/orders/audit.php', { action: 'timeline', order_id: orderId });
        if (!r.success || !r.data) {
            if (box) box.innerHTML = '';
            _setError(r.message || 'Nie udało się pobrać osi czasu.');
            return;
        }
        _detail = r.data;
        _renderDrawer(r.data);
    }

    function _renderDrawer(data) {
        const box = $('hoh-drawer-content');
        if (!box) return;
        const tl = data.timeline || [];
        const stack = new OrderTimelineStack(tl);
        const status = data.status || '';
        const statusLabel = STATUS_LABELS[status] || status;
        const fisc = data.fiscal_receipt_number ? `Paragon fisk.: ${_esc(data.fiscal_receipt_number)}` : 'Brak paragonu fisk.';
        const receipt = data.receipt_printed ? 'Wydrukowany' : 'Niewydrukowany';

        // Header
        const header = `
            <div class="hoh-drawer-header">
                <h3>Zamówienie #${_esc((data.order_number || '').split('/').pop() || data.order_number)}</h3>
                <div class="hoh-drawer-meta">
                    <span class="hoh-badge hoh-badge--status hoh-badge--${_esc(status)}">${_esc(STATUS_BADGE[status] || statusLabel)}</span>
                </div>
            </div>`;

        // Order details (klient, płatność, pozycje z ostatniego snapshotu)
        const lastSnap = stack.current();
        const snapHeader = lastSnap && lastSnap.snapshot ? lastSnap.snapshot.header : null;
        const snapLines = lastSnap && lastSnap.snapshot ? (lastSnap.snapshot.lines || []) : [];

        const custName = snapHeader ? (snapHeader.customer_name || '—') : '—';
        const custPhone = snapHeader ? (snapHeader.customer_phone || '—') : '—';
        const custAddr = snapHeader ? (snapHeader.delivery_address || '—') : '—';
        const grandTotal = snapHeader ? (parseInt(snapHeader.grand_total, 10) / 100).toFixed(2) : '—';
        const payStatus = snapHeader ? (PAY_LABELS[snapHeader.payment_status] || snapHeader.payment_status || '—') : '—';

        const details = `
            <div class="hoh-section">
                <h4>Klient</h4>
                <div class="hoh-detail-grid">
                    <span class="hoh-detail-label">Imię</span><span>${_esc(custName)}</span>
                    <span class="hoh-detail-label">Telefon</span><span>${_esc(custPhone)}</span>
                    <span class="hoh-detail-label">Adres</span><span>${_esc(custAddr)}</span>
                </div>
            </div>
            <div class="hoh-section">
                <h4>Płatność</h4>
                <div class="hoh-detail-grid">
                    <span class="hoh-detail-label">Forma</span><span>${_esc(payStatus)}</span>
                    <span class="hoh-detail-label">Paragon</span><span>${_esc(fisc)} · ${_esc(receipt)}</span>
                    <span class="hoh-detail-label">Total</span><span>${_esc(grandTotal)} zł</span>
                </div>
            </div>
            <div class="hoh-section">
                <h4>Pozycje (${snapLines.length})</h4>
                <ul class="hoh-lines-list">
                    ${snapLines.length ? snapLines.map((l) => {
                        const lt = parseInt(l.line_total, 10) / 100;
                        return `<li><span>${_esc(l.snapshot_name)} × ${_esc(l.quantity)}</span><span>${lt.toFixed(2)} zł</span></li>`;
                    }).join('') : '<li class="hoh-muted">Brak danych snapshotu</li>'}
                </ul>
            </div>`;

        // Action buttons — Faza 1: disabled placeholders (Faza 2/3 aktywuje)
        const canRevert = data.can_revert === true;
        const canReopen = data.can_reopen === true;
        const canForce = data.can_force_edit === true;
        const actions = `
            <div class="hoh-actions">
                <button type="button" class="hoh-action-btn" disabled title="Dostępne w Fazie 2 — edycja metadanych zamkniętego zamówienia">
                    <i class="fa-solid fa-user-pen"></i> Edytuj metadane
                </button>
                <button type="button" class="hoh-action-btn" disabled ${canForce ? '' : 'data-unavailable'} title="Dostępne w Fazie 3 — korekta pozycji zamkniętego zamówienia (KOR magazynowy)">
                    <i class="fa-solid fa-pen-to-square"></i> Edytuj pozycje (force)
                </button>
                <button type="button" class="hoh-action-btn" disabled ${canRevert ? '' : 'data-unavailable'} title="Dostępne w Fazie 3 — cofnięcie do stanu ze snapshotu">
                    <i class="fa-solid fa-rotate-left"></i> Cofnij do snapshotu
                </button>
                <button type="button" class="hoh-action-btn" disabled ${canReopen ? '' : 'data-unavailable'} title="Dostępne w Fazie 3 — ponowne otwarcie zamówienia">
                    <i class="fa-solid fa-lock-open"></i> Otwórz ponownie
                </button>
            </div>`;

        // Timeline (oś czasu)
        const timeline = `
            <div class="hoh-section">
                <h4>Oś czasu (${tl.length})</h4>
                ${stack.size() > 0 ? `
                    <div class="hoh-snapshot-nav">
                        <button type="button" id="hoh-snap-prev" ${stack.canUndo() ? '' : 'disabled'} title="Poprzedni snapshot">←</button>
                        <span id="hoh-snap-info">Snapshot ${stack.cursor() + 1}/${stack.size()}</span>
                        <button type="button" id="hoh-snap-next" ${stack.canRedo() ? '' : 'disabled'} title="Następny snapshot">→</button>
                    </div>` : ''}
                <div class="hoh-timeline" id="hoh-timeline">
                    ${_renderTimeline(tl)}
                </div>
            </div>`;

        box.innerHTML = header + details + actions + timeline;

        // Wire snapshot navigation
        if (stack.size() > 0) {
            $('hoh-snap-prev')?.addEventListener('click', () => _navSnapshot(stack, -1));
            $('hoh-snap-next')?.addEventListener('click', () => _navSnapshot(stack, 1));
        }

        // Wire timeline point expand/collapse
        box.querySelectorAll('.hoh-tl-point').forEach((pt) => {
            pt.addEventListener('click', () => {
                const detail = pt.nextElementSibling;
                if (detail && detail.classList.contains('hoh-tl-detail')) {
                    detail.classList.toggle('hub-hidden');
                }
            });
        });
    }

    function _renderTimeline(timeline) {
        if (!timeline.length) {
            return '<p class="hoh-muted">Brak zdarzeń na osi czasu.</p>';
        }
        return timeline.map((e) => {
            const color = EVENT_COLORS[e.event_type] || '#64748b';
            const time = (e.ts || '').slice(11, 16);
            const actor = e.actor_name || (e.actor_type === 'system' ? 'System' : '—');
            const actorType = e.actor_type || 'system';
            const hasSnap = !!e.snapshot;
            const hasDelta = !!e.delta;

            // Detail block (snapshot, delta, detail)
            let detailHtml = '';
            if (e.detail || hasSnap || hasDelta) {
                const parts = [];
                if (e.old_status && e.new_status) {
                    parts.push(`<div><strong>Status:</strong> ${_esc(e.old_status)} → ${_esc(e.new_status)}</div>`);
                }
                if (hasDelta) {
                    const d = e.delta;
                    const added = (d.added || []).length;
                    const removed = (d.removed || []).length;
                    const modified = (d.modified || []).length;
                    parts.push(`<div class="hoh-delta"><strong>Delta:</strong> +${added} / −${removed} / ~${modified}</div>`);
                    if (d.added && d.added.length) {
                        parts.push('<div class="hoh-delta-added">Dodano: ' + d.added.map((a) => _esc(a.snapshot_name || JSON.stringify(a))).join(', ') + '</div>');
                    }
                    if (d.removed && d.removed.length) {
                        parts.push('<div class="hoh-delta-removed">Usunięto: ' + d.removed.map((a) => _esc(a.snapshot_name || JSON.stringify(a))).join(', ') + '</div>');
                    }
                }
                if (hasSnap) {
                    const h = e.snapshot.header || {};
                    const lines = e.snapshot.lines || [];
                    const gt = h.grand_total != null ? (parseInt(h.grand_total, 10) / 100).toFixed(2) + ' zł' : '—';
                    parts.push(`<div class="hoh-snap-info"><strong>Snapshot:</strong> ${lines.length} linii, ${_esc(gt)}</div>`);
                }
                if (e.detail && typeof e.detail === 'object' && Object.keys(e.detail).length) {
                    const dk = Object.keys(e.detail).filter((k) => k !== 'kitchen_delta');
                    if (dk.length) {
                        parts.push('<details class="hoh-detail-json"><summary>Szczegóły</summary><pre>' + _esc(JSON.stringify(e.detail, null, 2)) + '</pre></details>');
                    }
                }
                detailHtml = `<div class="hoh-tl-detail hub-hidden">${parts.join('')}</div>`;
            }

            return `<div class="hoh-tl-item">
                <div class="hoh-tl-point" data-eid="${_esc(e.event_id)}" style="--hoh-dot:${color}" title="${hasSnap ? 'Kliknij — zawiera snapshot' : 'Kliknij po szczegóły'}">
                    ${hasSnap ? '<span class="hoh-tl-snap-mark" title="Snapshot">📷</span>' : ''}
                </div>
                <div class="hoh-tl-body">
                    <div class="hoh-tl-time">${_esc(time)}</div>
                    <div class="hoh-tl-label">${_esc(e.label_pl)}</div>
                    <div class="hoh-tl-actor">${_esc(actor)} <span class="hoh-tl-actor-type">(${_esc(actorType)})</span></div>
                    ${detailHtml}
                </div>
            </div>`;
        }).join('');
    }

    function _navSnapshot(stack, dir) {
        const cur = dir < 0 ? stack.undo() : stack.redo();
        const info = $('hoh-snap-info');
        if (info) info.textContent = `Snapshot ${stack.cursor() + 1}/${stack.size()}`;
        const prev = $('hoh-snap-prev');
        const next = $('hoh-snap-next');
        if (prev) prev.disabled = !stack.canUndo();
        if (next) next.disabled = !stack.canRedo();
        // Highlight current snapshot point on timeline
        const tl = $('hoh-timeline');
        if (tl && cur) {
            tl.querySelectorAll('.hoh-tl-item').forEach((item) => {
                item.classList.remove('hoh-tl-item--active');
            });
            const target = tl.querySelector(`[data-eid="${_esc(cur.event_id)}"]`);
            if (target) {
                target.closest('.hoh-tl-item')?.classList.add('hoh-tl-item--active');
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    // ── Init / event wiring ────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        $('hub-card-order-history')?.addEventListener('click', (ev) => {
            ev.preventDefault();
            void open();
        });
        $('hoh-btn-close')?.addEventListener('click', close);
        $('hoh-btn-back')?.addEventListener('click', () => { void open(); });
        $('hoh-btn-apply')?.addEventListener('click', () => {
            _pagination.page = 1;
            void _loadList();
        });
        $('hoh-btn-prev')?.addEventListener('click', () => {
            if (_pagination.page > 1) { _pagination.page--; void _loadList(); }
        });
        $('hoh-btn-next')?.addEventListener('click', () => {
            if (_pagination.page < _pagination.total_pages) { _pagination.page++; void _loadList(); }
        });
        $('hoh-per-page')?.addEventListener('change', (ev) => {
            _pagination.per_page = parseInt(ev.target.value, 10) || 50;
            _pagination.page = 1;
            void _loadList();
        });
        $('hoh-filter-search')?.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                _pagination.page = 1;
                void _loadList();
            }
        });
        $('hoh-overlay')?.addEventListener('click', (ev) => {
            if (ev.target === $('hoh-overlay')) close();
        });
    });

    return Object.freeze({ open, close });
})();
