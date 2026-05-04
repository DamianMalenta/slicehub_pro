/**
 * SLICEHUB POS V2 — API Wrapper Layer
 * Wszystkie wywołania to HTTP POST do `api/...` (kontrakt serwera bez zmian).
 * Prefiks katalogu API: opcjonalnie <meta name="sh-api-base" content="/slicehub/api">,
 * inaczej z `location.pathname` (np. …/slicehub/modules/pos/ → …/slicehub/api), na końcu /slicehub/api.
 */
function getApiBase() {
    if (typeof document !== 'undefined') {
        const meta = document.querySelector('meta[name="sh-api-base"]');
        if (meta && meta.content) {
            const b = String(meta.content).trim().replace(/\/+$/, '');
            if (b) return b;
        }
    }
    if (typeof window === 'undefined') return '/slicehub/api';
    const path = window.location.pathname || '';
    const marker = '/modules/';
    const idx = path.indexOf(marker);
    if (idx > 0) return path.slice(0, idx) + '/api';
    if (idx === 0) return '/api';
    const m = path.match(/^\/([^/]+)(?:\/|$)/);
    if (m && m[1] && m[1] !== 'api') return '/' + m[1] + '/api';
    return '/slicehub/api';
}

const PosAPI = (() => {
    const BASE = getApiBase();
    let _token = localStorage.getItem('sh_token') || '';

    function setToken(t) { _token = t; localStorage.setItem('sh_token', t); }
    function getToken() { return _token; }
    function clearToken() {
        _token = '';
        localStorage.removeItem('sh_token');
        localStorage.removeItem('sh_user');
        fetch(`${BASE}/auth/logout.php`, {
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
            const res = await fetch(`${BASE}${endpoint}`, {
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
        settleAndClose: (orderId, paymentMethod, printReceipt) => engine('settle_and_close', { order_id: orderId, payment_method: paymentMethod, print_receipt: printReceipt ? 1 : 0 }),
        cancelOrder:    (orderId, returnStock) => engine('cancel_order', { order_id: orderId, return_stock: returnStock ? 1 : 0 }),
        panicMode:      () => engine('panic_mode'),
        assignRoute:            (driverId, orderIds) => _post('/courses/engine.php', { action: 'dispatch', driver_id: driverId, order_ids: orderIds }),
        createCourse:           (orderIds) => _post('/courses/engine.php', { action: 'create_course', order_ids: orderIds }),
        assignDriverToCourse:   (courseId, driverId) => _post('/courses/engine.php', { action: 'assign_driver_to_course', course_id: courseId, driver_id: driverId }),

        // Tables — fetch available tables for Dine-In selector
        getAvailableTables:     () => _post('/tables/engine.php', { action: 'get_floor_status' }),

        /** HR clock — api/backoffice/hr/engine.php (JWT jak reszta POS) */
        hrClock: (action, body = {}) => _post('/backoffice/hr/engine.php', { action, ...body }),
    });
})();

export default PosAPI;
