/**
 * SLICEHUB — Smart Floor Plan · Main Application Controller (Production)
 * 5s heartbeat with surgical DOM diff, tap-to-teleport edit, POS redirect.
 * Zero drag-and-drop. Zero external libraries.
 */
(function () {
    'use strict';

    const POLL_INTERVAL = 5_000;
    const PIN_LENGTH    = 4;
    const GRID_SNAP     = 100 / 24; // ~4.167% — 24-column magnetic grid

    // ── State ────────────────────────────────────────────────────────────
    const state = {
        tables: [],
        zones: [],
        activeZone: 'all',
        editMode: false,
        selectedTableId: null,
        pendingPositions: {},
        pollTimer: null,
        user: null,
        generation: 0,       // tracks whether a full render has ever happened
    };

    const $ = id => document.getElementById(id);
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
    function snap(v) { return Math.round(v / GRID_SNAP) * GRID_SNAP; }

    // ── PIN Login ────────────────────────────────────────────────────────
    function initPin() {
        let pin = '';
        const dots = $('pin-dots');
        dots.innerHTML = Array.from({ length: PIN_LENGTH }, () => '<div class="pin-dot"></div>').join('');

        function refreshDots() {
            dots.querySelectorAll('.pin-dot').forEach((d, i) => d.classList.toggle('filled', i < pin.length));
        }

        async function tryLogin() {
            const res = await TablesAPI.login(pin);
            if (res.success && res.data) {
                if (res.data.token) localStorage.setItem('sh_token', res.data.token);
                state.user = res.data.user || res.data;
                localStorage.setItem('sh_user', JSON.stringify(state.user));
                const uname = state.user.name || state.user.username || 'KELNER';
                const navBadge = $('nav-user-badge');
                if (navBadge) navBadge.textContent = uname;
                $('pin-screen').classList.add('hidden');
                $('tables-app').classList.remove('hidden');
                startApp();
            } else {
                pin = '';
                refreshDots();
                dots.classList.add('shake');
                setTimeout(() => dots.classList.remove('shake'), 500);
                TablesUI.toast(res.message || 'Nieprawidłowy PIN', 'error');
            }
        }

        document.querySelectorAll('#pin-screen .pin-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const v = btn.dataset.pin;
                if (v === 'clear') pin = '';
                else if (v === 'back') pin = pin.slice(0, -1);
                else if (pin.length < PIN_LENGTH) pin += v;
                refreshDots();
                if (pin.length === PIN_LENGTH) setTimeout(tryLogin, 150);
            });
        });

        if (localStorage.getItem('sh_token')) {
            const storedUser = localStorage.getItem('sh_user');
            if (storedUser) {
                try { state.user = JSON.parse(storedUser); } catch { /* ignore */ }
            }
            const navBadge = $('nav-user-badge');
            const name = state.user?.name || state.user?.username || 'KELNER';
            if (navBadge) navBadge.textContent = name;
            $('pin-screen').classList.add('hidden');
            $('tables-app').classList.remove('hidden');
            startApp();
        }
    }

    // ── Data Loading ─────────────────────────────────────────────────────
    async function loadFloor(silent) {
        const zp = state.activeZone === 'all' ? null : state.activeZone;
        const res = await TablesAPI.getFloorStatus(zp);
        if (!res.success) {
            if (!silent) TablesUI.toast(res.message || 'Nie udało się załadować sali', 'error');
            return;
        }
        const prevTables = state.tables;
        state.tables = res.data.tables || [];
        state.zones  = res.data.zones || [];

        if (silent && state.generation > 0 && !state.editMode) {
            diffPatch(prevTables);
        } else {
            render();
        }
    }

    // ── Full Rendering (initial + structural changes) ─────────────────────
    function render() {
        state.generation++;
        TablesUI.renderZoneTabs(state.zones, state.activeZone, switchZone);

        let tables = getFilteredTables();
        TablesUI.renderFloor(tables, state.editMode, state.selectedTableId, {
            onClick:      handleLiveClick,
            onEditSelect: handleEditSelect,
            onDelete:     handleDelete,
        });
    }

    function getFilteredTables() {
        let tables = state.tables;
        if (state.activeZone !== 'all') {
            tables = tables.filter(t => String(t.zone_id) === String(state.activeZone));
        }
        return tables.map(t => {
            const p = state.pendingPositions[t.id];
            return p ? { ...t, pos_x: p.x, pos_y: p.y } : t;
        });
    }

    function switchZone(id) {
        state.activeZone = id;
        state.selectedTableId = null;
        render();
    }

    // ── Surgical DOM Diff (heartbeat path — no innerHTML nuke) ────────────
    function diffPatch(prevTables) {
        const tables = getFilteredTables();
        const prevById = new Map(prevTables.map(t => [String(t.id), t]));
        const currById = new Map(tables.map(t => [String(t.id), t]));

        // Structural change (table added/removed) → fall back to full render
        if (prevById.size !== currById.size || [...currById.keys()].some(k => !prevById.has(k))) {
            render();
            return;
        }

        const grid = $('floor-grid');

        tables.forEach(t => {
            const prev = prevById.get(String(t.id));
            const node = grid?.querySelector(`.table-node[data-table-id="${t.id}"]`);
            if (!node || !prev) return;

            const prevVs = TablesUI.deriveVisualStatus(prev);
            const currVs = TablesUI.deriveVisualStatus(t);

            // Patch status class
            if (prevVs !== currVs && !TablesUI.isObstacle(t)) {
                node.classList.remove('status-' + prevVs);
                node.classList.add('status-' + currVs);
            }

            // Patch SLA warning
            const SLA_WARN_MS = 20 * 60 * 1000;
            const shouldWarn = !TablesUI.isObstacle(t) &&
                (currVs === 'occupied' || currVs === 'preparing') &&
                t.order && (Date.now() - new Date(t.order.created_at).getTime()) > SLA_WARN_MS;
            node.classList.toggle('pulse-warning', shouldWarn);

            // Patch timer text
            const timerEl = node.querySelector('.table-timer');
            if (timerEl && t.order?.created_at) {
                const m = Math.floor((Date.now() - new Date(t.order.created_at).getTime()) / 60000);
                const str = m < 0 ? '0m' : m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
                if (timerEl.textContent !== str) timerEl.textContent = str;
            }

            // Patch guest count badge
            const guestEl = node.querySelector('.table-guests');
            if (guestEl && t.order?.guest_count) {
                const gc = String(t.order.guest_count);
                if (guestEl.textContent !== gc) guestEl.textContent = gc;
            }

            // Patch waiter name
            const waiterEl = node.querySelector('.table-waiter');
            const waiterName = t.order?.waiter_name || t.order?.created_by_name || '';
            if (waiterEl && waiterName) {
                if (waiterEl.textContent !== waiterName) waiterEl.textContent = waiterName;
            }

            // Patch order badge
            const orderBadgeEl = node.querySelector('.table-order-badge');
            if (orderBadgeEl && t.order?.order_number) {
                const badge = '#' + t.order.order_number;
                if (orderBadgeEl.textContent !== badge) orderBadgeEl.textContent = badge;
            }
        });

        // Always update footer stats
        TablesUI.updateStats(tables);
    }

    // ── Order Ownership Gate ─────────────────────────────────────────────
    function canAccessOrder(table) {
        if (!table.order) return { allowed: true };
        const myId = String(state.user?.id || '');
        const waiterOnOrder = String(table.order.waiter_id || '');
        if (myId && waiterOnOrder && myId === waiterOnOrder) return { allowed: true };
        const role = (state.user?.role || '').toLowerCase();
        if (role === 'manager' || role === 'admin') return { allowed: true };
        return {
            allowed: false,
            waiterName: table.order.waiter_name || table.order.created_by_name || 'inny kelner',
        };
    }

    function isManager() {
        const role = (state.user?.role || '').toLowerCase();
        return role === 'manager' || role === 'admin';
    }

    // ── LIVE MODE — Context-Aware Click ──────────────────────────────────
    function handleLiveClick(table, vs, el) {
        TablesUI.dismissPanel();

        switch (vs) {
            case 'free':
                TablesUI.showGuestPanel(el, table, count => handleOpenTable(table, count));
                break;

            case 'occupied':
            case 'preparing': {
                const access = canAccessOrder(table);
                if (!access.allowed) {
                    TablesUI.showAccessDenied(access.waiterName);
                    return;
                }
                redirectToPOS(table);
                break;
            }

            case 'ready': {
                const access = canAccessOrder(table);
                if (!access.allowed) {
                    TablesUI.showAccessDenied(access.waiterName);
                    return;
                }
                TablesUI.showActionPanel(el, table, {
                    icon: 'fa-solid fa-bell-concierge',
                    iconBg: 'rgba(59,130,246,0.15)', iconColor: 'var(--accent-blue)',
                    subtitle: 'Dania gotowe — podaj do stolika!',
                    btnClass: 'blue', btnIcon: 'fa-solid fa-check-double',
                    btnLabel: 'Dania Podane',
                    onAction: () => handleMarkServed(table),
                });
                break;
            }

            case 'dirty':
                TablesUI.showActionPanel(el, table, {
                    icon: 'fa-solid fa-broom',
                    iconBg: 'rgba(100,116,139,0.15)', iconColor: 'var(--table-dirty)',
                    subtitle: 'Wymaga posprzątania',
                    btnClass: '', btnIcon: 'fa-solid fa-sparkles',
                    btnLabel: 'Wyczyść Stolik',
                    onAction: () => handleMarkClean(table),
                });
                break;

            case 'reserved':
                TablesUI.showGuestPanel(el, table, count => handleOpenReserved(table, count));
                break;
        }
    }

    // ── Action Handlers ──────────────────────────────────────────────────

    // Cart-First: no DB call — pass intent via URL, order is created at POS checkout
    function handleOpenTable(table, guestCount) {
        const count = Math.max(1, parseInt(guestCount) || 1);
        TablesUI.toast(`Stolik ${table.table_number} → POS`, 'success');
        window.location.href = `../pos/index.html?table_id=${table.id}`
            + `&table_number=${encodeURIComponent(table.table_number)}`
            + `&guest_count=${count}&order_type=dine_in`;
    }

    async function handleOpenReserved(table, count) {
        await TablesAPI.updateTableStatus(table.id, 'free');
        handleOpenTable(table, count);
    }

    function redirectToPOS(table) {
        const oid = table.order?.id || '';
        window.location.href = `../pos/index.html?edit_order_id=${oid}`
            + `&table_id=${table.id}`
            + `&table_number=${encodeURIComponent(table.table_number)}`;
    }

    async function handleMarkClean(table) {
        optimistic(table.id, 'free');
        const res = await TablesAPI.updateTableStatus(table.id, 'free');
        if (res.success) TablesUI.toast(`Stolik ${table.table_number} gotowy!`, 'success');
        else TablesUI.toast(res.message || 'Błąd', 'error');
        syncNow();
    }

    async function handleMarkServed(table) {
        TablesUI.toast(`Stolik ${table.table_number} — dania podane`, 'success');
        syncNow();
    }

    async function handleFireCourse(table) {
        const o = table.order;
        if (!o?.next_unfired_course) { TablesUI.toast('Brak następnej zmiany', 'warning'); return; }
        const res = await TablesAPI.fireCourse(o.id, o.next_unfired_course);
        if (res.success) TablesUI.toast(`Zmiana ${o.next_unfired_course} wysłana na kuchnię!`, 'success');
        else TablesUI.toast(res.message || 'Błąd', 'error');
        syncNow();
    }

    async function handleMerge(table) {
        TablesUI.showMergeModal(table, state.tables, async targetId => {
            const res = await TablesAPI.mergeTables(table.id, targetId, true);
            if (res.success) TablesUI.toast('Stoliki połączone!', 'success');
            else TablesUI.toast(res.message || 'Nie udało się połączyć', 'error');
            syncNow();
        });
    }

    function optimistic(tableId, newStatus) {
        const n = document.querySelector(`.table-node[data-table-id="${tableId}"]`);
        if (n) n.className = n.className.replace(/status-\w+/, 'status-' + newStatus);
    }

    // ── EDIT MODE — Tap-to-Teleport ──────────────────────────────────────
    function handleEditSelect(table, el) {
        if (String(table.id) === String(state.selectedTableId)) {
            state.selectedTableId = null;
        } else {
            state.selectedTableId = table.id;
        }
        document.querySelectorAll('.table-node.selected').forEach(n => n.classList.remove('selected'));
        if (state.selectedTableId) el.classList.add('selected');
    }

    function handleFloorClick(e) {
        if (!state.editMode || !state.selectedTableId) return;
        if (e.target.closest('.table-node')) return;

        const grid = $('floor-grid');
        const rect = grid.getBoundingClientRect();
        const rawX = ((e.clientX - rect.left) / rect.width) * 100;
        const rawY = ((e.clientY - rect.top)  / rect.height) * 100;
        const posX = snap(clamp(rawX, 0, 92));
        const posY = snap(clamp(rawY, 0, 90));

        const node = document.querySelector(`.table-node[data-table-id="${state.selectedTableId}"]`);
        if (node) {
            node.classList.add('teleporting');
            node.style.left = posX + '%';
            node.style.top  = posY + '%';
            setTimeout(() => node.classList.remove('teleporting'), 400);
        }

        state.pendingPositions[state.selectedTableId] = { x: posX, y: posY };
        state.selectedTableId = null;
        document.querySelectorAll('.table-node.selected').forEach(n => n.classList.remove('selected'));
    }

    function enterEditMode() {
        state.editMode = true;
        state.selectedTableId = null;
        state.pendingPositions = {};
        stopPolling();
        TablesUI.dismissPanel();

        $('status-bar').classList.add('hidden');
        $('edit-toolbar').classList.remove('hidden');
        $('floor-canvas').classList.add('edit-mode');
        render();
        TablesUI.toast('Tryb edycji — kliknij stolik, potem kliknij puste miejsce', 'info');
    }

    async function exitEditMode() {
        const pending = Object.keys(state.pendingPositions);
        if (pending.length > 0) {
            await saveLayout();
        }
        state.editMode = false;
        state.selectedTableId = null;
        state.pendingPositions = {};

        $('edit-toolbar').classList.add('hidden');
        $('status-bar').classList.remove('hidden');
        $('floor-canvas').classList.remove('edit-mode');
        await loadFloor();
        startPolling();
        TablesUI.toast('Układ zapisany', 'success');
    }

    async function saveLayout() {
        const positions = Object.entries(state.pendingPositions).map(([id, p]) => ({
            table_id: parseInt(id), pos_x: Math.round(p.x), pos_y: Math.round(p.y),
        }));
        if (positions.length === 0) { TablesUI.toast('Brak zmian do zapisania', 'info'); return; }

        const res = await TablesAPI.saveLayout(positions);
        if (res.success) {
            state.pendingPositions = {};
            TablesUI.toast(`Zapisano ${positions.length} pozycji`, 'success');
        } else {
            TablesUI.toast(res.message || 'Błąd zapisu układu', 'error');
        }
    }

    async function handleAddTable(data) {
        const res = await TablesAPI.createTable(data);
        if (res.success) {
            TablesUI.toast(`Stolik ${data.table_number} dodany`, 'success');
            await loadFloor();  // full render for structural change
        } else {
            TablesUI.toast(res.message || 'Nie udało się dodać', 'error');
        }
    }

    async function handleAddObstacle(data) {
        const res = await TablesAPI.createTable(data);
        if (res.success) {
            TablesUI.toast(`Element "${data.table_number}" dodany`, 'success');
            await loadFloor();  // full render for structural change
        } else {
            TablesUI.toast(res.message || 'Nie udało się dodać', 'error');
        }
    }

    async function handleDelete(table) {
        TablesUI.showDeleteConfirm(table, async () => {
            const res = await TablesAPI.deleteTable(table.id);
            if (res.success) {
                TablesUI.toast(`Usunięto: ${table.table_number}`, 'success');
                await loadFloor();  // full render for structural change
            } else {
                TablesUI.toast(res.message || 'Nie udało się usunąć', 'error');
            }
        });
    }

    // ── Heartbeat Polling (5s, silent diff) ─────────────────────────────
    function startPolling() {
        stopPolling();
        state.pollTimer = setInterval(() => loadFloor(true), POLL_INTERVAL);
    }
    function stopPolling() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }
    function syncNow() {
        stopPolling();
        loadFloor(true).then(startPolling);
    }

    // ── Event Wiring ─────────────────────────────────────────────────────
    let _wired = false;
    function wireEvents() {
        if (_wired) return;
        _wired = true;

        // Universal nav bar — hard URL redirects (micro-frontend routing)
        document.querySelectorAll('#nav-tabs .nav-tab[data-href]').forEach(tab => {
            tab.addEventListener('click', () => { window.location.href = tab.dataset.href; });
        });

        $('btn-refresh').addEventListener('click', () => {
            const icon = $('btn-refresh').querySelector('i');
            icon.classList.add('spin');
            syncNow();
            setTimeout(() => icon.classList.remove('spin'), 600);
        });

        // Settings vault button — manager/admin only
        const settingsBtn = $('btn-settings');
        if (settingsBtn) {
            settingsBtn.addEventListener('click', () => {
                if (!isManager()) return;
                if (state.editMode) {
                    exitEditMode();
                } else {
                    enterEditMode();
                }
            });
        }

        $('btn-exit-edit').addEventListener('click', exitEditMode);
        $('btn-save-layout').addEventListener('click', saveLayout);

        $('btn-add-table').addEventListener('click', () => {
            TablesUI.showCreateTableModal(state.zones, handleAddTable);
        });
        $('btn-add-obstacle').addEventListener('click', () => {
            TablesUI.showCreateObstacleModal(handleAddObstacle);
        });

        $('btn-logout').addEventListener('click', () => {
            localStorage.removeItem('sh_token');
            localStorage.removeItem('sh_user');
            state.user = null;
            stopPolling();
            $('tables-app').classList.add('hidden');
            $('pin-screen').classList.remove('hidden');
        });

        $('floor-grid').addEventListener('click', handleFloorClick);

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                TablesUI.dismissPanel();
                TablesUI.closeModal();
                if (state.editMode) {
                    state.selectedTableId = null;
                    document.querySelectorAll('.table-node.selected').forEach(n => n.classList.remove('selected'));
                }
            }
        });

        window.addEventListener('beforeunload', e => {
            if (state.editMode && Object.keys(state.pendingPositions).length > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    async function startApp() {
        wireEvents();

        // Show Settings vault only for managers/admins
        const settingsBtn = $('btn-settings');
        if (settingsBtn) {
            settingsBtn.classList.toggle('hidden', !isManager());
        }

        await loadFloor();
        startPolling();
    }

    document.addEventListener('DOMContentLoaded', initPin);
})();
