/**
 * SLICEHUB POS V2 — API Wrapper Layer
 * All network calls centralized. UI logic never touches fetch() directly.
 */

const PosAPI = (() => {
    const BASE = '/slicehub/api';
    let _token = localStorage.getItem('sh_token') || '';

    function setToken(t) { _token = t; localStorage.setItem('sh_token', t); }
    function getToken() { return _token; }
    function clearToken() { _token = ''; localStorage.removeItem('sh_token'); localStorage.removeItem('sh_user'); }

    async function _post(endpoint, payload = {}) {
        const headers = { 'Content-Type': 'application/json' };
        if (_token) headers['Authorization'] = `Bearer ${_token}`;
        try {
            const res = await fetch(`${BASE}${endpoint}`, {
                method: 'POST', headers, body: JSON.stringify(payload),
            });
            const json = await res.json();
            return { ok: res.ok, status: res.status, success: json.success === true, message: json.message || '', data: json.data || null };
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
    });
})();

export default PosAPI;
