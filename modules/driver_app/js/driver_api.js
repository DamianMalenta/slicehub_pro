/**
 * SLICEHUB DRIVER APP — API Wrapper Layer
 * All network calls to api/courses/engine.php centralized here.
 * Uses JWT from localStorage. No jQuery.
 */
const DriverAPI = (() => {
    function apiFallback() {
        if (globalThis.SliceHub && globalThis.SliceHub.getApiFallback) return globalThis.SliceHub.getApiFallback();
        return '/api';
    }
    function engineUrl() {
        return (globalThis.SliceHub && globalThis.SliceHub.apiUrl)
            ? globalThis.SliceHub.apiUrl('courses/engine.php')
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
            return { ok: res.ok, httpCode: res.status, success: json.success === true, message: json.message || '', data: json.data ?? null };
        } catch (e) {
            console.error(`[DriverAPI] ${action} network error:`, e);
            return { ok: false, success: false, message: 'Brak połączenia z serwerem', data: null };
        }
    }

    async function _hrPost(action, maxAttempts = 3) {
        const hrUrl = (globalThis.SliceHub && globalThis.SliceHub.apiUrl)
            ? globalThis.SliceHub.apiUrl('backoffice/hr/engine.php')
            : apiFallback() + '/backoffice/hr/engine.php';
        let last = { success: false, message: 'Brak połączenia', transient: true };
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const res = await fetch(hrUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${_token}` },
                    body: JSON.stringify({ action, auth: { self: true }, source: 'mobile' }),
                });
                const json = await res.json();
                json.transient = false;
                if (json.success === true || res.status < 500) return json;
                last = json;
                last.transient = true;
            } catch (e) {
                last = { success: false, message: 'Brak połączenia', transient: true };
            }
            if (attempt < maxAttempts) {
                await new Promise(r => setTimeout(r, attempt * 1000));
            }
        }
        return last;
    }

    return Object.freeze({
        setToken, getToken,

        /** Login + hasło (aplikacja mobilna kierowcy). */
        loginSystem: (username, password) => {
            const headers = { 'Content-Type': 'application/json' };
            return fetch((globalThis.SliceHub && globalThis.SliceHub.apiUrl)
                ? globalThis.SliceHub.apiUrl('auth/login.php')
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
        linkHrSession:    (sessionUuid)   => _post('link_hr_session', { work_session_uuid: sessionUuid }),
        checkRecall:      ()              => _post('check_recall'),
        clearRecall:      ()              => _post('clear_recall'),
        setDriverStatus:  (status)        => _post('set_driver_status', { driver_user_id: '', status }),
        reconcile:        (countedCash)   => _post('reconcile', { counted_cash: countedCash }),
        sseUrl: () => {
            const base = (globalThis.SliceHub && globalThis.SliceHub.apiUrl)
                ? globalThis.SliceHub.apiUrl('courses/sse_driver.php')
                : apiFallback() + '/courses/sse_driver.php';
            return base + '?token=' + encodeURIComponent(_token);
        },

        /**
         * HR clock_in / clock_out — best effort, does not block shift start
         * or reconcile. Retries transient (network) failures with backoff;
         * business errors from the HR engine are returned as-is.
         */
        hrClockIn:  () => _hrPost('clock_in'),
        hrClockOut: () => _hrPost('clock_out'),
    });
})();
