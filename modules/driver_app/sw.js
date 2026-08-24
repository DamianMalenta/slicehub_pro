/**
 * SliceHub Driver App — Service Worker
 *
 * Bazowany na modules/pos/sw.js (Resilient POS Phase 1).
 * Strategia: "Local-first app shell, network-first data"
 *   - App shell (HTML/CSS/JS) → cache-first, fallback network
 *   - API GET → network-first z short timeout, fallback cache
 *   - API POST (mutacje) → NETWORK ONLY (bez kolejki offline w tej wersji)
 *
 * Wersjonowanie: bump CACHE_VERSION przy każdej zmianie precache listy.
 */

const CACHE_VERSION = 'slicehub-driver-v2';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const SW_DIR = self.location.pathname.replace(/\/sw\.js$/, '');
const BASE_PATH = SW_DIR.replace(/\/modules\/driver_app$/, '');

const OFFLINE_URL = BASE_PATH + '/modules/driver_app/offline.html';

const PRECACHE = [
    BASE_PATH + '/modules/driver_app/index.html',
    BASE_PATH + '/modules/driver_app/offline.html',
    BASE_PATH + '/modules/driver_app/manifest.json',
    BASE_PATH + '/modules/driver_app/css/style.css',
    BASE_PATH + '/modules/driver_app/js/driver_app.js',
    BASE_PATH + '/modules/driver_app/js/driver_api.js',
];

const NAV_TIMEOUT_MS = 3500;
const API_TIMEOUT_MS = 3000;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(PRECACHE.map(async (url) => {
            try {
                const res = await fetch(url, { cache: 'reload', credentials: 'same-origin' });
                if (res.ok) await cache.put(url, res.clone());
            } catch (_) {}
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const toDelete = keys.filter(k =>
            !k.startsWith(CACHE_VERSION) &&
            (k.startsWith('slicehub-driver-') || k === 'slicehub-driver')
        );
        await Promise.all(toDelete.map(k => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (data.type === 'GET_VERSION') {
        event.ports?.[0]?.postMessage({ version: CACHE_VERSION });
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    if (!url.pathname.startsWith(BASE_PATH + '/modules/driver_app/') &&
        !url.pathname.startsWith(BASE_PATH + '/api/courses/') &&
        !url.pathname.startsWith(BASE_PATH + '/api/auth/') &&
        !url.pathname.startsWith(BASE_PATH + '/core/')) {
        return;
    }

    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(navigateStrategy(request));
        return;
    }

    if (url.pathname.startsWith(BASE_PATH + '/api/')) {
        event.respondWith(apiStrategy(request));
        return;
    }

    if (/\.(?:css|js|svg|png|webp|ico|json|webmanifest|woff2?)$/i.test(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
        return;
    }
});

async function navigateStrategy(request) {
    try {
        const res = await fetchWithTimeout(request, NAV_TIMEOUT_MS);
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, res.clone()).catch(() => {});
        return res;
    } catch (_) {
        const cached = await caches.match(request, { ignoreSearch: true }) ||
                       await caches.match(BASE_PATH + '/modules/driver_app/index.html');
        if (cached) return cached;
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        return new Response('Driver App offline — fallback niedostępny', {
            status: 503, statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
    }
}

async function apiStrategy(request) {
    try {
        const res = await fetchWithTimeout(request, API_TIMEOUT_MS);
        if (res && res.ok) {
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(request, res.clone()).catch(() => {});
        }
        return res;
    } catch (_) {
        const cached = await caches.match(request);
        if (cached) {
            const headers = new Headers(cached.headers);
            headers.set('X-SliceHub-Cache', 'stale');
            const body = await cached.clone().text();
            return new Response(body, {
                status: cached.status,
                statusText: cached.statusText,
                headers,
            });
        }
        return new Response(JSON.stringify({
            success: false,
            message: 'Offline — brak połączenia z serwerem',
            data: null,
            _offline: true,
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
        });
    }
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    const networkPromise = fetch(request).then((res) => {
        if (res && res.ok) cache.put(request, res.clone()).catch(() => {});
        return res;
    }).catch(() => null);

    return cached || networkPromise || fetch(request);
}

function fetchWithTimeout(request, timeoutMs) {
    return new Promise((resolve, reject) => {
        const ctrl = new AbortController();
        const id = setTimeout(() => ctrl.abort(), timeoutMs);
        fetch(request, { signal: ctrl.signal })
            .then((res) => { clearTimeout(id); resolve(res); })
            .catch((err) => { clearTimeout(id); reject(err); });
    });
}
