/**
 * SLICEHUB ENTERPRISE — Global API Client v1.0
 * Cel: Jedno źródło prawdy dla całej komunikacji HTTP w systemie.
 *
 * Endpointy względne (../../api/...) i absolutne (/api/...) są automatycznie
 * rozwiązywane przez SliceHub.apiUrl gdy załadowany jest core/js/sh_api_base.js.
 *
 * Callery (2026-05-21):
 *   modules/studio/**          — api_menu_studio.php (via apiStudio + bezpośrednie ApiClient.post)
 *   modules/warehouse/**       — warehouse_api.js → api/warehouse/*.php
 *   modules/tables/index.html  — api_client (sh_api_base załadowany)
 *
 * Użycie:
 *   const res = await globalThis.ApiClient.post('/api/endpoint.php', { action: 'DO_THING', id: 5 });
 *   const res = await globalThis.ApiClient.get('/api/endpoint.php', { action: 'GET_LIST' });
 *
 * Gwarantowany format odpowiedzi: { success: bool, message: string, data: any }
 */
(function () {

    /**
     * Rozwiązuje endpoint względem SSOT (SliceHub.apiUrl) gdy sh_api_base.js jest załadowany.
     * Obsługuje: /api/..., ../../api/..., ../api/...
     */
    function resolveEndpoint(endpoint) {
        const ep = String(endpoint || '').trim();
        if (!ep) return ep;
        if (/^(https?:|data:|blob:)/i.test(ep)) return ep;

        const sh = typeof globalThis !== 'undefined' && globalThis.SliceHub;
        if (!sh || typeof sh.apiUrl !== 'function') return ep;

        if (ep.startsWith('/api/')) {
            return sh.apiUrl(ep.slice(4));
        }
        const rel = ep.match(/^(?:\.\.\/)+api\/(.+)$/);
        if (rel) {
            return sh.apiUrl('/' + rel[1]);
        }
        return ep;
    }

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

            const response = await fetch(resolveEndpoint(endpoint), fetchOptions);

            if (response.status === 401) {
                console.warn('[ApiClient] 401 — token expired or invalid. Redirecting to login.');
                localStorage.removeItem('sh_token');
                const loginPath = globalThis.location.pathname.replace(/modules\/.*$/, '') + 'login.html';
                globalThis.location.href = loginPath;
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

    globalThis.ApiClient = Object.freeze({ request, post, get });

})();
