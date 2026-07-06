/**
 * SliceHub — jeden prefiks API dla wszystkich modułów (SSOT).
 * Kolejność: meta sh-api-base → window.__SH_API_BASE__ → pathname przed /modules/
 * → pierwszy segment ścieżki + /api (gdy brak /modules/) → fallback (/api lub /slicehub/api).
 * Fallback: /api (hosting root); /slicehub/api tylko gdy w URL jest folder slicehub.
 */
(function () {
    'use strict';

    function pathnameHasSlicehubFolder(pathname) {
        const p = pathname || '';
        return p.indexOf('/slicehub/') !== -1 || p === '/slicehub' || p.startsWith('/slicehub');
    }

    /** Gdy heurystyka nie trafi — bezpieczny default pod hosting root (uti.pl). */
    function getApiFallback(pathname) {
        const p = pathname != null
            ? String(pathname)
            : (typeof window !== 'undefined' ? (window.location.pathname || '') : '');
        return pathnameHasSlicehubFolder(p) ? '/slicehub/api' : '/api';
    }

    function getApiBase() {
        if (typeof document !== 'undefined') {
            const meta = document.querySelector('meta[name="sh-api-base"]');
            if (meta && meta.content) {
                const b = String(meta.content).trim().replace(/\/+$/, '');
                if (b) return b;
            }
        }
        if (typeof window !== 'undefined' && typeof window.__SH_API_BASE__ === 'string') {
            const env = window.__SH_API_BASE__.trim().replace(/\/+$/, '');
            if (env) return env;
        }
        if (typeof window === 'undefined') return '/api';
        const path = window.location.pathname || '';
        const marker = '/modules/';
        const idx = path.indexOf(marker);
        if (idx > 0) return path.slice(0, idx) + '/api';
        if (idx === 0) return '/api';
        const m = path.match(/^\/([^/]+)(?:\/|$)/);
        if (m && m[1] && m[1] !== 'api') return '/' + m[1] + '/api';
        return getApiFallback(path);
    }

    function apiUrl(path) {
        const base = getApiBase();
        const p = String(path || '').trim();
        if (!p) return base;
        return base + (p.startsWith('/') ? p : '/' + p);
    }

    /** Prefiks aplikacji (bez /api): '' na hosting root, '/slicehub' lokalnie. */
    function getAppBase() {
        const api = getApiBase();
        if (api.endsWith('/api')) {
            const app = api.slice(0, -4);
            return app === '/' ? '' : app;
        }
        const p = typeof window !== 'undefined' ? (window.location.pathname || '') : '';
        return pathnameHasSlicehubFolder(p) ? '/slicehub' : '';
    }

    function appUrl(path) {
        const base = getAppBase();
        const p = String(path || '').trim();
        if (!p) return base || '/';
        const segment = p.startsWith('/') ? p : '/' + p;
        return (base || '') + segment;
    }

    const root = window.SliceHub || {};
    root.getApiBase = getApiBase;
    root.getApiFallback = getApiFallback;
    root.apiUrl = apiUrl;
    root.getAppBase = getAppBase;
    root.appUrl = appUrl;
    window.SliceHub = root;
})();
