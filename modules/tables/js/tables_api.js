/**
 * SLICEHUB — Smart Floor Plan · API Layer (Production)
 * Strict Bearer auth on every call. 401 → force PIN re-login.
 */
const TablesAPI = (function () {
    'use strict';

    const AUTH_URL   = '/slicehub/api/auth/login.php';
    const ENGINE_URL = '/slicehub/api/tables/engine.php';

    function _token() { return localStorage.getItem('sh_token'); }

    function _headers() {
        const h = { 'Content-Type': 'application/json' };
        const t = _token();
        if (t) h['Authorization'] = 'Bearer ' + t;
        return h;
    }

    function _forceLogin() {
        localStorage.removeItem('sh_token');
        const app = document.getElementById('tables-app');
        const pin = document.getElementById('pin-screen');
        if (app) app.classList.add('hidden');
        if (pin) pin.classList.remove('hidden');
    }

    async function _post(url, payload) {
        const token = _token();
        if (!token && url !== AUTH_URL) {
            _forceLogin();
            return { success: false, message: 'Brak tokenu — zaloguj się ponownie.', data: null };
        }

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: _headers(),
                body: JSON.stringify(payload),
            });

            if (res.status === 401 || res.status === 403) {
                _forceLogin();
                return { success: false, message: 'Sesja wygasła. Zaloguj się ponownie.', data: null };
            }

            const json = await res.json();

            if (!json.success && typeof json.message === 'string' &&
                json.message.toLowerCase().includes('unauthorized')) {
                _forceLogin();
            }

            return {
                success: json.success === true,
                message: json.message ?? '',
                data:    json.data ?? null,
            };
        } catch (e) {
            return { success: false, message: e.message || 'Błąd sieci', data: null };
        }
    }

    function _engine(action, data = {}) {
        return _post(ENGINE_URL, { action, ...data });
    }

    return Object.freeze({
        login(pin) { return _post(AUTH_URL, { pin_code: pin }); },

        getFloorStatus(zoneId) {
            const p = {};
            if (zoneId != null) p.zone_id = zoneId;
            return _engine('get_floor_status', p);
        },

        updateTableStatus(tableId, status) {
            return _engine('update_table_status', { table_id: tableId, physical_status: status });
        },

        openTable(tableId, guestCount) {
            return _engine('open_table', { table_id: tableId, guest_count: guestCount });
        },

        fireCourse(orderId, courseNumber) {
            return _engine('fire_course', { order_id: orderId, course_number: courseNumber });
        },

        completeDineIn(orderId) {
            return _engine('complete_dine_in', { order_id: orderId });
        },

        saveLayout(positions) {
            return _engine('save_layout', { positions });
        },

        createTable(data) {
            return _engine('create_table', data);
        },

        deleteTable(tableId) {
            return _engine('delete_table', { table_id: tableId });
        },

        mergeTables(parentId, childId, consolidate = false) {
            return _engine('merge_tables', {
                table_id_1: parentId,
                table_id_2: childId,
                consolidate_orders: consolidate,
            });
        },

        unmergeTables(tableId) {
            return _engine('unmerge_tables', { table_id: tableId });
        },
    });
})();
