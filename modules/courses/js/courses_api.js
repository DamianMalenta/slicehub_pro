/**
 * SLICEHUB DISPATCHER — API Wrapper Layer
 * All network calls to api/courses/engine.php centralized here.
 * Uses JWT from localStorage. No jQuery.
 */
const CoursesAPI = (() => {
    const ENDPOINT = '/slicehub/api/courses/engine.php';
    let _token = localStorage.getItem('sh_token') || '';

    function setToken(t) { _token = t; localStorage.setItem('sh_token', t); }
    function getToken() { return _token; }

    async function _post(action, data = {}) {
        const headers = { 'Content-Type': 'application/json' };
        if (_token) headers['Authorization'] = `Bearer ${_token}`;
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST', headers,
                body: JSON.stringify({ action, ...data }),
            });
            const json = await res.json();
            return { ok: res.ok, success: json.success === true, message: json.message || '', data: json.data || null };
        } catch (e) {
            return { ok: false, success: false, message: 'Network error', data: null };
        }
    }

    return Object.freeze({
        setToken, getToken,

        loginPin: (tenantId, pin) => {
            const headers = { 'Content-Type': 'application/json' };
            return fetch('/slicehub/api/auth/login.php', {
                method: 'POST', headers,
                body: JSON.stringify({ mode: 'kiosk', tenant_id: tenantId, pin_code: pin }),
            }).then(r => r.json()).catch(() => ({ success: false }));
        },

        getDashboard:     ()           => _post('get_dashboard'),
        dispatch:         (driverId, orderIds) => _post('dispatch', { driver_id: driverId, order_ids: orderIds }),
        cancelStop:       (orderId)    => _post('cancel_stop', { order_id: orderId }),
        setInitialCash:   (driverUserId, amount) => _post('set_initial_cash', { driver_user_id: driverUserId, amount }),
        startShift:       (driverUserId, initialCash) => _post('start_shift', { driver_user_id: driverUserId, initial_cash: initialCash }),
        setDriverStatus:  (driverUserId, status) => _post('set_driver_status', { driver_user_id: driverUserId, status }),
        reconcile:        (driverUserId, countedCash) => _post('reconcile', { driver_user_id: driverUserId, counted_cash: countedCash }),
        emergencyRecall:  (driverUserId) => _post('emergency_recall', { driver_user_id: driverUserId }),
        appendToCourse:       (courseId, orderIds) => _post('append_to_course', { course_id: courseId, order_ids: orderIds }),
        dispatchForce:        (driverId, orderIds) => _post('dispatch', { driver_id: driverId, order_ids: orderIds, force_new: true }),
        createCourse:         (orderIds) => _post('create_course', { order_ids: orderIds }),
        assignDriverToCourse: (courseId, driverId) => _post('assign_driver_to_course', { course_id: courseId, driver_id: driverId }),
        updateOrderStatus:    (orderId, newStatus) => _post('update_order_status', { order_id: orderId, new_status: newStatus }),
    });
})();
