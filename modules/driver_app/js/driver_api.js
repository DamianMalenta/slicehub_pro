/**
 * SLICEHUB DRIVER APP — API Wrapper Layer
 * All network calls to api/courses/engine.php centralized here.
 * Uses JWT from localStorage. No jQuery.
 */
const DriverAPI = (() => {
    function apiFallback() {
        if (window.SliceHub && window.SliceHub.getApiFallback) return window.SliceHub.getApiFallback();
        return '/api';
    }
    function engineUrl() {
        return (window.SliceHub && window.SliceHub.apiUrl)
            ? window.SliceHub.apiUrl('courses/engine.php')
            : apiFallback() + '/courses/engine.php';
    }
    let _token = localStorage.getItem('sh_token') || '';

    function setToken(t) { _token = t; localStorage.setItem('sh_token', t); }
    function getToken() { return _token; }

    async function _post(action, data = {}) {
        const headers = { 'Content-Type': 'application/json' };
        if (_token) headers['Authorization'] = `Bearer ${_token}`;
        try {
            const res = await fetch(engineUrl(), {
                method: 'POST', headers,
                body: JSON.stringify({ action, ...data }),
            });
            const json = await res.json();
            if (!res.ok) {
                console.warn(`[DriverAPI] ${action} HTTP ${res.status}:`, json);
            }
            return { ok: res.ok, success: json.success === true, message: json.message || '', data: json.data ?? null };
        } catch (e) {
            console.error(`[DriverAPI] ${action} network error:`, e);
            return { ok: false, success: false, message: 'Brak połączenia z serwerem', data: null };
        }
    }

    return Object.freeze({
        setToken, getToken,

        /** Login + hasło (aplikacja mobilna kierowcy). */
        loginSystem: (username, password) => {
            const headers = { 'Content-Type': 'application/json' };
            return fetch((window.SliceHub && window.SliceHub.apiUrl)
                ? window.SliceHub.apiUrl('auth/login.php')
                : apiFallback() + '/auth/login.php', {
                method: 'POST', headers,
                body: JSON.stringify({
                    mode: 'system',
                    username: String(username || '').trim(),
                    password: String(password || ''),
                }),
            }).then(r => r.json()).catch(() => ({ success: false, message: 'Brak połączenia' }));
        },

        getDriverRuns:    ()              => _post('get_driver_runs'),
        getDriverWallet:  ()              => _post('get_driver_wallet'),
        collectPayment:   (orderId, type) => _post('collect_payment', { order_id: orderId, collection_type: type }),
        deliverOrder:     (orderId)       => _post('deliver_order', { order_id: orderId }),
        cancelOrder:      (orderId, reason) => _post('cancel_order', { order_id: orderId, reason }),
        updateLocation:   (lat, lng, heading, speed, accuracy) => _post('update_location', { lat, lng, heading, speed, accuracy }),
        startShift:       (initialCash)   => _post('start_shift', { initial_cash: initialCash }),
        checkRecall:      ()              => _post('check_recall'),
        clearRecall:      ()              => _post('clear_recall'),
        setDriverStatus:  (status)        => _post('set_driver_status', { driver_user_id: '', status }),
    });
})();
