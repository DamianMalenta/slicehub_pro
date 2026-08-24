/**
 * SLICEHUB POS V2 — API Wrapper Layer
 * Wszystkie wywołania to HTTP POST do `api/...` (kontrakt serwera bez zmian).
 * Prefiks API: core/js/sh_api_base.js (SliceHub.getApiBase).
 */
function posApiBase() {
    if (typeof globalThis !== 'undefined' && globalThis.SliceHub && globalThis.SliceHub.getApiBase) {
        return globalThis.SliceHub.getApiBase();
    }
    if (typeof globalThis !== 'undefined' && globalThis.SliceHub && globalThis.SliceHub.getApiFallback) {
        return globalThis.SliceHub.getApiFallback();
    }
    return '/api';
}

const PosAPI = (() => {
    let _token = localStorage.getItem('sh_token') || '';

    function setToken(t) { _token = t; localStorage.setItem('sh_token', t); }
    function getToken() { return _token; }
    function clearToken() {
        _token = '';
        localStorage.removeItem('sh_token');
        localStorage.removeItem('sh_user');
        fetch(`${posApiBase()}/auth/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        }).catch(() => {});
    }

    async function _post(endpoint, payload = {}) {
        const headers = { 'Content-Type': 'application/json' };
        if (_token) headers['Authorization'] = `Bearer ${_token}`;
        try {
            const res = await fetch(`${posApiBase()}${endpoint}`, {
                method: 'POST', headers, body: JSON.stringify(payload),
            });
            const json = await res.json();
            return {
                ok: res.ok,
                status: res.status,
                success: json.success === true,
                message: json.message || '',
                code: json.code || '',
                data: json.data || null,
            };
        } catch (e) {
            return { ok: false, status: 0, success: false, message: 'Network error', data: null };
        }
    }

    const engine = (action, data = {}) => _post('/pos/engine.php', { action, ...data });

    return Object.freeze({
        setToken, getToken, clearToken,

        // Auth
        loginPin: (tenantId, pinCode) => _post('/auth/login.php', { mode: 'kiosk', tenant_id: tenantId, pin_code: pinCode }),

        // POS Engine
        getInitData:    () => engine('get_init_data'),
        getOrders:      () => engine('get_orders'),
        getItemDetails: (itemId, halfBId = 0) => engine('get_item_details', { item_id: itemId, half_b_id: halfBId }),
        processOrder:   (payload) => engine('process_order', payload),
        acceptOrder:    (orderId, customTime) => engine('accept_order', { order_id: orderId, custom_time: customTime }),
        updateStatus:   (orderId, status) => engine('update_status', { order_id: orderId, status }),
        printKitchen:   (orderId) => engine('print_kitchen', { order_id: orderId }),
        printReceipt:   (orderId, paymentMethod) => engine('print_receipt', { order_id: orderId, payment_method: paymentMethod }),
        settleAndClose: (orderId, paymentMethodOrPayments, printReceipt) => {
            const payload = { order_id: orderId, print_receipt: printReceipt ? 1 : 0 };
            if (Array.isArray(paymentMethodOrPayments)) {
                payload.payments = paymentMethodOrPayments;
            } else {
                payload.payment_method = paymentMethodOrPayments;
            }
            return engine('settle_and_close', payload);
        },
        cancelOrder:    (orderId, returnStock) => engine('cancel_order', { order_id: orderId, return_stock: returnStock ? 1 : 0 }),
        panicMode:      () => engine('panic_mode'),
        // Centrum Kontroli Czasu — selektywna bulk zmiana promised_time + opcjonalne mark_ready
        shiftTime:      (payload) => engine('shift_time', payload),

        // Fiscal printer — Elzab Zeta Online
        fiscalPrint:       (orderId, force) => engine('fiscal_print', { order_id: orderId, force: force ? 1 : 0 }),
        fiscalDailyReport: () => engine('fiscal_daily_report'),
        fiscalStatus:      () => engine('fiscal_status'),
        fiscalGetConfig:   () => engine('fiscal_get_config'),
        fiscalSaveConfig:  (cfg) => engine('fiscal_save_config', cfg),
        fiscalTest:        (host, port) => engine('fiscal_test', { host, port }),
        assignRoute:            (driverId, orderIds) => _post('/courses/engine.php', { action: 'dispatch', driver_id: driverId, order_ids: orderIds }),
        createCourse:           (orderIds) => _post('/courses/engine.php', { action: 'create_course', order_ids: orderIds }),
        assignDriverToCourse:   (courseId, driverId) => _post('/courses/engine.php', { action: 'assign_driver_to_course', course_id: courseId, driver_id: driverId }),

        // Time estimate — slots for order acceptance
        estimateSlots: async (channel, interval = 15, count = 10) => {
            const headers = {};
            if (_token) headers['Authorization'] = `Bearer ${_token}`;
            try {
                const url = `${posApiBase()}/orders/estimate.php?mode=slots&channel=${encodeURIComponent(channel)}&interval=${interval}&count=${count}`;
                const res = await fetch(url, { method: 'GET', headers });
                const json = await res.json();
                return { success: json.success === true, data: json.data || null, message: json.message || '' };
            } catch (e) {
                return { success: false, data: null, message: 'Network error' };
            }
        },

        // Tables — fetch available tables for Dine-In selector
        getAvailableTables:     () => _post('/tables/engine.php', { action: 'get_floor_status' }),

        /** HR clock — api/backoffice/hr/engine.php (JWT jak reszta POS) */
        hrClock: (action, body = {}) => _post('/backoffice/hr/engine.php', { action, ...body }),
    });
})();

export default PosAPI;
