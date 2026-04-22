/**
 * SLICEHUB ENTERPRISE — Global API Client v1.0
 * Cel: Jedno źródło prawdy dla całej komunikacji HTTP w systemie.
 *
 * Użycie:
 *   const res = await window.ApiClient.post('/api/endpoint.php', { action: 'DO_THING', id: 5 });
 *   const res = await window.ApiClient.get('/api/endpoint.php', { action: 'GET_LIST' });
 *
 * Gwarantowany format odpowiedzi: { success: bool, message: string, data: any }
 */
(function () {

    /**
     * Wewnętrzny silnik HTTP.
     * Zawsze zwraca bezpieczny obiekt — nigdy nie rzuca wyjątku do wywołującego.
     *
     * @param {string} endpoint
     * @param {Object} [options={}]
     * @returns {Promise<{success: boolean, message: string, data: any}>}
     */
    async function request(endpoint, options = {}) {
        try {
            const headers = { 'Content-Type': 'application/json' };

            const token = localStorage.getItem('sh_token');
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }

            const fetchOptions = {
                method:  options.method || 'GET',
                headers,
            };

            if (options.body !== undefined) {
                fetchOptions.body = options.body;
            }

            const response = await fetch(endpoint, fetchOptions);

            if (response.status === 401) {
                console.warn('[ApiClient] 401 — token expired or invalid. Redirecting to login.');
                localStorage.removeItem('sh_token');
                const loginPath = window.location.pathname.replace(/modules\/.*$/, '') + 'login.html';
                window.location.href = loginPath;
                return { success: false, message: 'Sesja wygasła. Przekierowanie do logowania...', data: null };
            }

            const json = await response.json();

            return {
                success: json.success === true,
                message: json.message ?? '',
                data:    json.data    ?? null,
            };

        } catch (e) {
            return {
                success: false,
                message: e.message || 'Błąd sieci lub krytyczny błąd serwera.',
                data:    null,
            };
        }
    }

    /**
     * Wysyła żądanie POST z payloadem serializowanym do JSON.
     *
     * @param {string} endpoint
     * @param {Object} payload
     * @returns {Promise<{success: boolean, message: string, data: any}>}
     */
    function post(endpoint, payload) {
        return request(endpoint, {
            method: 'POST',
            body:   JSON.stringify(payload),
        });
    }

    /**
     * Wysyła żądanie GET z parametrami dołączonymi jako query string.
     *
     * @param {string} endpoint
     * @param {Object} [params={}]
     * @returns {Promise<{success: boolean, message: string, data: any}>}
     */
    function get(endpoint, params = {}) {
        const qs  = new URLSearchParams(params).toString();
        const url = qs ? `${endpoint}?${qs}` : endpoint;
        return request(url, { method: 'GET' });
    }

    window.ApiClient = Object.freeze({ request, post, get });

})();
