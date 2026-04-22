/**
 * SLICEHUB WAITER — Mobile Waiter App
 * Dedicated, lightweight floor app for waiters.
 * Pure Vanilla JS (ES6). Bridges to POS module for cart operations.
 */

const WaiterApp = (() => {
    const TENANT_ID = parseInt(document.querySelector('meta[name="sh-tenant-id"]')?.content, 10) || 1;
    const API_BASE = '/slicehub/api';
    const TOKEN_KEY = 'sh_token';
    const USER_KEY = 'sh_user';
    const POLL_MS = 5000;
    const PIN_LENGTH = 4;

    let _token = localStorage.getItem(TOKEN_KEY) || '';
    let _user = null;
    let _pin = '';
    let _tables = [];
    let _allTables = [];
    let _pollTimer = null;
    let _clockTimer = null;
    let _generation = 0;

    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    // =========================================================================
    // API LAYER
    // =========================================================================
    async function _post(endpoint, payload = {}) {
        const headers = { 'Content-Type': 'application/json' };
        if (_token) headers['Authorization'] = `Bearer ${_token}`;
        try {
            const res = await fetch(`${API_BASE}${endpoint}`, {
                method: 'POST', headers, body: JSON.stringify(payload),
            });
            if (res.status === 401) { _forceLogout(); return { success: false, message: 'Session expired' }; }
            const json = await res.json();
            return { success: json.success === true, message: json.message || '', data: json.data || null };
        } catch {
            return { success: false, message: 'Brak połączenia z serwerem', data: null };
        }
    }

    function _forceLogout() {
        _token = '';
        _user = null;
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        _stopPolling();
        _showView('pin');
    }

    // =========================================================================
    // INIT
    // =========================================================================
    function init() {
        _bindPinPad();
        _bindDashboard();
        _bindNewOrder();

        const stored = localStorage.getItem(USER_KEY);
        _token = localStorage.getItem(TOKEN_KEY) || '';
        if (stored && _token) {
            try {
                _user = JSON.parse(stored);
                _bootDashboard();
                return;
            } catch { /* fall through to PIN */ }
        }
        _showView('pin');
    }

    // =========================================================================
    // VIEW SWITCHING
    // =========================================================================
    function _showView(name) {
        ['pin', 'dashboard', 'new-order'].forEach(v => {
            const el = $(`#view-${v}`);
            if (!el) return;
            if (v === name) el.classList.remove('hidden');
            else el.classList.add('hidden');
        });
    }

    // =========================================================================
    // PIN PAD
    // =========================================================================
    function _bindPinPad() {
        $$('#view-pin .pin-key').forEach(btn => {
            btn.addEventListener('click', () => _handlePinKey(btn.dataset.val));
        });
    }

    function _handlePinKey(val) {
        const errEl = $('#pin-error');
        if (errEl) { errEl.classList.remove('visible'); errEl.textContent = ''; }

        if (val === 'clear') {
            _pin = '';
            _renderPinDots();
            return;
        }
        if (val === 'go') {
            if (_pin.length === PIN_LENGTH) _submitPin(_pin);
            return;
        }
        if (_pin.length >= PIN_LENGTH) return;

        _pin += val;
        _renderPinDots();

        if (_pin.length === PIN_LENGTH) {
            setTimeout(() => _submitPin(_pin), 120);
        }
    }

    function _renderPinDots() {
        const container = $('#pin-dots');
        if (!container) return;
        container.innerHTML = Array.from({ length: PIN_LENGTH }, (_, i) =>
            `<div class="pin-dot ${i < _pin.length ? 'filled' : ''}"></div>`
        ).join('');
    }

    async function _submitPin(pin) {
        const res = await _post('/auth/login.php', {
            mode: 'kiosk',
            tenant_id: TENANT_ID,
            pin_code: pin,
        });

        if (res.success && res.data) {
            _token = res.data.token;
            _user = res.data.user;
            localStorage.setItem(TOKEN_KEY, _token);
            localStorage.setItem(USER_KEY, JSON.stringify(_user));
            _pin = '';
            _renderPinDots();
            _toast(`Witaj, ${_user.name || _user.username}!`, 'success');
            _bootDashboard();
        } else {
            _pin = '';
            const dots = $$('#pin-dots .pin-dot');
            dots.forEach(d => d.classList.add('error'));
            const errEl = $('#pin-error');
            if (errEl) { errEl.textContent = res.message || 'Nieprawidłowy PIN'; errEl.classList.add('visible'); }
            setTimeout(_renderPinDots, 700);
        }
    }

    // =========================================================================
    // DASHBOARD BOOT
    // =========================================================================
    function _bootDashboard() {
        _showView('dashboard');
        const nameEl = $('#dash-name');
        if (nameEl && _user) nameEl.textContent = _user.name || _user.username || 'Kelner';
        _startClock();
        _fetchTables(true);
        _startPolling();
    }

    function _bindDashboard() {
        const logoutBtn = $('#btn-logout');
        if (logoutBtn) logoutBtn.addEventListener('click', _forceLogout);

        const refreshBtn = $('#btn-refresh');
        if (refreshBtn) refreshBtn.addEventListener('click', () => {
            refreshBtn.style.transform = 'rotate(360deg)';
            refreshBtn.style.transition = 'transform 0.5s';
            setTimeout(() => { refreshBtn.style.transform = ''; refreshBtn.style.transition = ''; }, 500);
            _syncNow();
        });

        const fabBtn = $('#fab-new');
        if (fabBtn) fabBtn.addEventListener('click', _openNewOrder);
    }

    // =========================================================================
    // CLOCK
    // =========================================================================
    function _startClock() {
        if (_clockTimer) clearInterval(_clockTimer);
        const update = () => {
            const el = $('#dash-clock');
            if (el) el.textContent = new Date().toLocaleTimeString('pl', { hour: '2-digit', minute: '2-digit' });
        };
        update();
        _clockTimer = setInterval(update, 30000);
    }

    // =========================================================================
    // DATA FETCH
    // =========================================================================
    async function _fetchTables(forceFull) {
        const res = await _post('/tables/engine.php', { action: 'get_floor_status' });
        if (!res.success || !res.data) return;

        const allTables = res.data.tables || [];
        _allTables = allTables;

        const myId = _user?.id ? String(_user.id) : null;
        const prevTables = _tables;

        _tables = allTables.filter(t => {
            if (!t.order) return false;
            return myId && String(t.order.waiter_id) === myId;
        });

        if (!forceFull && _generation > 0) {
            _diffDashboard(prevTables);
        } else {
            _renderDashboard();
        }
    }

    function _startPolling() {
        _stopPolling();
        _pollTimer = setInterval(() => _fetchTables(false), POLL_MS);
    }

    function _stopPolling() {
        if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
    }

    function _syncNow() {
        _stopPolling();
        _fetchTables(false).then(_startPolling);
    }

    // =========================================================================
    // RENDER: DASHBOARD — Clean list layout
    // =========================================================================
    function _renderDashboard() {
        _generation++;
        const grid = $('#table-grid');
        if (!grid) return;

        _updateStats();

        if (_tables.length === 0) {
            grid.innerHTML = `
                <div class="dash-empty">
                    <div class="dash-empty-icon"><i class="fa-solid fa-mug-saucer"></i></div>
                    <div class="dash-empty-text">Brak aktywnych stolików</div>
                    <div class="dash-empty-hint">Kliknij <span style="color:var(--accent);font-weight:800">+</span> aby otworzyć zamówienie</div>
                </div>`;
            return;
        }

        const now = Date.now();
        grid.innerHTML = _tables.map(t => _buildRowHtml(t, now)).join('');
        _bindRowClicks(grid);
    }

    function _buildRowHtml(t, now) {
        const o = t.order;
        const tNum = t.table_number || t.id;
        const guests = o.guest_count || '-';
        const total = o.grand_total ? (parseInt(o.grand_total) / 100).toFixed(2) : '0.00';

        const created = o.created_at ? new Date(o.created_at).getTime() : now;
        const elapsed = Math.floor((now - created) / 60000);
        const timerCls = elapsed >= 60 ? 'row-timer--red' : elapsed >= 30 ? 'row-timer--orange' : '';

        const status = o.status || 'pending';
        const statusMap = { pending: 'Nowe', preparing: 'Kuchnia', ready: 'Gotowe' };
        const statusLabel = statusMap[status] || status;
        const readyCls = status === 'ready' ? ' row--ready' : '';

        return `<div class="order-row${readyCls}" data-table-id="${t.id}" data-order-id="${o.id}" data-status="${status}">
            <div class="row-left">
                <div class="row-table-num">${_esc(tNum)}</div>
                <div class="row-badge row-badge--${status}">${statusLabel}</div>
            </div>
            <div class="row-center">
                <div class="row-detail"><i class="fa-solid fa-users"></i> ${guests}</div>
                <div class="row-detail"><span class="row-timer ${timerCls}">${elapsed} min</span></div>
            </div>
            <div class="row-right">
                <div class="row-total">${total} zł</div>
                <i class="fa-solid fa-chevron-right row-arrow"></i>
            </div>
        </div>`;
    }

    function _bindRowClicks(grid) {
        grid.querySelectorAll('.order-row').forEach(row => {
            row.addEventListener('click', () => {
                const tableId = row.dataset.tableId;
                const orderId = row.dataset.orderId;
                const t = _tables.find(x => String(x.id) === String(tableId));
                if (!t) return;

                const access = _checkOrderAccess(t);
                if (!access.allowed) {
                    _showAccessDenied(access.waiterName);
                    return;
                }

                _navigateToPOS({
                    edit_order_id: orderId,
                    table_id: tableId,
                    table_number: t.table_number || tableId,
                    guest_count: t.order?.guest_count || 1,
                });
            });
        });
    }

    function _updateStats() {
        const totalGuests = _tables.reduce((s, t) => s + (parseInt(t.order?.guest_count) || 0), 0);
        const totalRevenue = _tables.reduce((s, t) => s + (parseInt(t.order?.grand_total) || 0), 0);
        const statTables = $('#stat-tables');
        const statGuests = $('#stat-guests');
        const statRevenue = $('#stat-revenue');
        if (statTables) statTables.textContent = _tables.length;
        if (statGuests) statGuests.textContent = totalGuests;
        if (statRevenue) statRevenue.textContent = (totalRevenue / 100).toFixed(2).replace('.', ',');
    }

    // =========================================================================
    // DIFF: Surgical DOM patch
    // =========================================================================
    function _diffDashboard(prevTables) {
        const grid = $('#table-grid');
        if (!grid) return;

        const prevIds = new Set(prevTables.map(t => String(t.id)));
        const currIds = new Set(_tables.map(t => String(t.id)));

        if (prevIds.size !== currIds.size || [...currIds].some(id => !prevIds.has(id))) {
            _renderDashboard();
            return;
        }

        _updateStats();

        if (_tables.length === 0 && prevTables.length === 0) return;
        if (_tables.length === 0) { _renderDashboard(); return; }

        const STATUS_LABELS = { pending: 'Nowe', preparing: 'Kuchnia', ready: 'Gotowe' };
        const now = Date.now();
        const prevById = new Map(prevTables.map(t => [String(t.id), t]));

        _tables.forEach(t => {
            const row = grid.querySelector(`.order-row[data-table-id="${t.id}"]`);
            if (!row) return;

            const o = t.order;
            const prev = prevById.get(String(t.id));
            const prevStatus = prev?.order?.status || 'pending';
            const currStatus = o?.status || 'pending';

            if (prevStatus !== currStatus) {
                row.dataset.status = currStatus;
                const badge = row.querySelector('.row-badge');
                if (badge) {
                    badge.className = `row-badge row-badge--${currStatus}`;
                    badge.textContent = STATUS_LABELS[currStatus] || currStatus;
                }
            }

            row.classList.toggle('row--ready', currStatus === 'ready');

            const totalEl = row.querySelector('.row-total');
            if (totalEl) {
                const total = o?.grand_total ? (parseInt(o.grand_total) / 100).toFixed(2) + ' zł' : '0.00 zł';
                if (totalEl.textContent !== total) totalEl.textContent = total;
            }

            const timerEl = row.querySelector('.row-timer');
            if (timerEl && o?.created_at) {
                const created = new Date(o.created_at).getTime();
                const elapsed = Math.floor((now - created) / 60000);
                const str = elapsed + ' min';
                if (timerEl.textContent !== str) timerEl.textContent = str;
                timerEl.classList.toggle('row-timer--red', elapsed >= 60);
                timerEl.classList.toggle('row-timer--orange', elapsed >= 30 && elapsed < 60);
            }
        });
    }

    // =========================================================================
    // VIEW C: NEW ORDER — Table optional
    // =========================================================================
    function _bindNewOrder() {
        const backBtn = $('#btn-back-dash');
        if (backBtn) backBtn.addEventListener('click', () => _showView('dashboard'));

        const noTableBtn = $('#btn-no-table');
        if (noTableBtn) noTableBtn.addEventListener('click', () => {
            _navigateToPOS({
                order_type: 'dine_in',
                guest_count: 1,
            });
        });
    }

    function _openNewOrder() {
        _showView('new-order');
        _renderFreeTables();
    }

    function _renderFreeTables() {
        const grid = $('#sel-grid');
        if (!grid) return;

        const sorted = [..._allTables].sort((a, b) => {
            const na = parseInt(a.table_number) || 0;
            const nb = parseInt(b.table_number) || 0;
            return na - nb;
        });

        if (sorted.length === 0) {
            grid.innerHTML = '<div class="sel-empty">Brak stolików w systemie</div>';
            return;
        }

        grid.innerHTML = sorted.map(t => {
            const isFree = !t.order && (
                t.physical_status === 'free' || t.physical_status === 'reserved' || !t.physical_status
            );
            const cls = isFree ? '' : 'sel-table--occupied';
            return `<button class="sel-table ${cls}" data-tid="${t.id}" data-tnum="${_esc(t.table_number || t.id)}" data-seats="${t.seats || '?'}">
                <span class="sel-table-num">${_esc(t.table_number || t.id)}</span>
                <span class="sel-table-seats">${t.seats || '?'} <i class="fa-solid fa-chair" style="font-size:9px"></i></span>
            </button>`;
        }).join('');

        grid.querySelectorAll('.sel-table:not(.sel-table--occupied)').forEach(btn => {
            btn.addEventListener('click', () => {
                _showGuestModal({
                    table_id: btn.dataset.tid,
                    table_number: btn.dataset.tnum,
                    seats: btn.dataset.seats,
                });
            });
        });
    }

    // =========================================================================
    // GUEST COUNT MODAL (bottom sheet)
    // =========================================================================
    function _showGuestModal(tableCtx) {
        let guestCount = 2;

        let overlay = $('#guest-modal');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'guest-modal';
            overlay.className = 'modal-overlay';
            document.body.appendChild(overlay);
        }

        function render() {
            overlay.innerHTML = `
                <div class="modal-backdrop" data-close></div>
                <div class="modal-sheet">
                    <div class="modal-handle"></div>
                    <div class="modal-title">Stolik ${_esc(tableCtx.table_number)}</div>
                    <div class="modal-subtitle">Ile osób?</div>
                    <div class="guest-picker">
                        <button class="guest-btn" id="guest-minus">−</button>
                        <span class="guest-count-display" id="guest-val">${guestCount}</span>
                        <button class="guest-btn" id="guest-plus">+</button>
                    </div>
                    <button class="modal-confirm-btn" id="guest-go">Otwórz stolik <i class="fa-solid fa-arrow-right"></i></button>
                </div>`;
            overlay.classList.add('active');

            overlay.querySelector('#guest-minus')?.addEventListener('click', () => {
                guestCount = Math.max(1, guestCount - 1);
                const el = overlay.querySelector('#guest-val');
                if (el) el.textContent = guestCount;
            });
            overlay.querySelector('#guest-plus')?.addEventListener('click', () => {
                guestCount = Math.min(20, guestCount + 1);
                const el = overlay.querySelector('#guest-val');
                if (el) el.textContent = guestCount;
            });
            overlay.querySelector('[data-close]')?.addEventListener('click', () => {
                overlay.classList.remove('active');
            });
            overlay.querySelector('#guest-go')?.addEventListener('click', () => {
                overlay.classList.remove('active');
                _navigateToPOS({
                    table_id: tableCtx.table_id,
                    table_number: tableCtx.table_number,
                    guest_count: guestCount,
                    order_type: 'dine_in',
                });
            });
        }

        render();
    }

    // =========================================================================
    // ORDER OWNERSHIP
    // =========================================================================
    function _checkOrderAccess(table) {
        if (!table.order) return { allowed: true };
        const myId = String(_user?.id || '');
        const waiterId = String(table.order.waiter_id || '');
        if (myId && waiterId && myId === waiterId) return { allowed: true };
        const role = (_user?.role || '').toLowerCase();
        if (role === 'manager' || role === 'admin') return { allowed: true };
        return {
            allowed: false,
            waiterName: table.order.waiter_name || table.order.created_by_name || 'inny kelner',
        };
    }

    function _showAccessDenied(waiterName) {
        let overlay = document.getElementById('waiter-ad-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'waiter-ad-overlay';
            document.body.appendChild(overlay);
        }

        overlay.className = 'waiter-ad-overlay';
        overlay.innerHTML = `
            <div class="waiter-ad-backdrop"></div>
            <div class="waiter-ad-card">
                <div class="waiter-ad-icon"><i class="fa-solid fa-lock"></i></div>
                <div class="waiter-ad-title">Brak uprawnień</div>
                <div class="waiter-ad-sub">Zamówienie obsługuje:</div>
                <div class="waiter-ad-name">${_esc(waiterName)}</div>
                <button class="waiter-ad-btn" id="waiter-ad-close">Rozumiem</button>
            </div>
        `;

        requestAnimationFrame(() => overlay.classList.add('visible'));

        const dismiss = () => {
            overlay.classList.remove('visible');
            setTimeout(() => overlay.remove(), 300);
        };

        overlay.querySelector('.waiter-ad-backdrop').onclick = dismiss;
        overlay.querySelector('#waiter-ad-close').onclick = dismiss;
        setTimeout(dismiss, 4000);
    }

    // =========================================================================
    // POS BRIDGE
    // =========================================================================
    function _navigateToPOS(params) {
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') qs.set(k, v);
        });
        window.location.href = `../pos/index.html?${qs.toString()}`;
    }

    // =========================================================================
    // TOAST
    // =========================================================================
    function _toast(msg, type = 'info') {
        const container = $('#toast-container');
        if (!container) return;
        const el = document.createElement('div');
        el.className = `wt-toast wt-toast--${type}`;
        el.textContent = msg;
        container.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 300);
        }, 3000);
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================
    function _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    return Object.freeze({ init });
})();

document.addEventListener('DOMContentLoaded', () => WaiterApp.init());
