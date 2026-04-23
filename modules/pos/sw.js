/**
 * SliceHub POS — Service Worker (Resilient POS · Phase 1 · PWA Foundation)
 *
 * Filozofia: "Local-first, cloud-synced"
 *   - Kasjer nigdy nie widzi dino-pagea ani spinnera "Brak połączenia".
 *   - UI POS-a zawsze odpala się natychmiast z cache.
 *   - Ten SW to TYLKO warstwa 1: precache UI + offline fallback.
 *   - Kolejkowanie mutacji (outbox) + multi-device mirror przyjdzie w P3/P4.
 *
 * Strategie:
 *   - navigate (HTML)          → network-first, fallback cache, fallback offline.html
 *   - static (css/js/svg/font) → stale-while-revalidate
 *   - api GET-only             → network-first z short timeout, fallback cache
 *   - api mutation (POST)      → NETWORK ONLY (P1 nie kolejkuje — to będzie P3)
 *
 * Wersjonowanie: bump CACHE_VERSION przy każdej zmianie precache listy. Po
 * aktywacji nowa wersja czyści poprzednie cache'e i wysyła 'SW_UPDATED' do
 * wszystkich klientów — `pos_sw_register.js` pokazuje toast z opcją reload.
 */

const CACHE_VERSION = 'slicehub-pos-v5';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const API_CACHE     = `${CACHE_VERSION}-api`;

const OFFLINE_URL = '/slicehub/modules/pos/offline.html';

const PRECACHE = [
    '/slicehub/modules/pos/index.html',
    '/slicehub/modules/pos/offline.html',
    '/slicehub/modules/pos/manifest.webmanifest',
    '/slicehub/modules/pos/icon.svg',
    '/slicehub/modules/pos/icon-maskable.svg',
    '/slicehub/modules/pos/icons/shortcut-new.svg',
    '/slicehub/modules/pos/screenshots/wide.svg',
    '/slicehub/modules/pos/screenshots/narrow.svg',
    '/slicehub/modules/pos/css/style.css',
    '/slicehub/modules/pos/js/pos_app.js',
    '/slicehub/modules/pos/js/pos_api.js',
    '/slicehub/modules/pos/js/pos_ui.js',
    '/slicehub/modules/pos/js/pos_cart.js',
    '/slicehub/modules/pos/js/pos_sw_register.js',
    '/slicehub/modules/pos/js/PosLocalStore.js',
    '/slicehub/modules/pos/js/PosSyncEngine.js',
    '/slicehub/modules/pos/js/PosApiOutbox.js',
];

// Tylko read-only akcje API — pozwalamy na stale-while-revalidate. Mutacje
// (process_order, accept_order, settle_and_close itd.) idą tylko przez sieć.
const API_READ_ACTIONS = new Set([
    'get_init_data', 'get_item_details', 'get_orders',
]);

const NAV_TIMEOUT_MS = 3500;
const API_TIMEOUT_MS = 2500;

// ═══════════════════════════════════════════════════════════════════════════
// INSTALL — precache kluczowych assetów. skipWaiting żeby nowa wersja była
// aktywna bez konieczności zamknięcia wszystkich tabów (POS zostaje otwarty
// cały dzień u klienta — czekanie na "natural close" byłoby bez sensu).
// ═══════════════════════════════════════════════════════════════════════════
self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(PRECACHE.map(async (url) => {
            try {
                const res = await fetch(url, { cache: 'reload', credentials: 'same-origin' });
                if (res.ok) await cache.put(url, res.clone());
            } catch (_) { /* ignore — zbudujemy cache przy pierwszym udanym fetchu */ }
        }));
        await self.skipWaiting();
    })());
});

// ═══════════════════════════════════════════════════════════════════════════
// ACTIVATE — czyści stare cache'e, przejmuje kontrolę nad wszystkimi tabami,
// powiadamia klientów o nowej wersji (toast "Zaktualizowano — odśwież aby
// zastosować zmiany").
// ═══════════════════════════════════════════════════════════════════════════
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const toDelete = keys.filter(k =>
            !k.startsWith(CACHE_VERSION) &&
            (k.startsWith('slicehub-pos-') || k === 'slicehub-pos')
        );
        await Promise.all(toDelete.map(k => caches.delete(k)));
        await self.clients.claim();

        const clientsList = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
        clientsList.forEach(c => {
            try { c.postMessage({ type: 'SW_UPDATED', version: CACHE_VERSION }); } catch (_) {}
        });
    })());
});

// ═══════════════════════════════════════════════════════════════════════════
// MESSAGE — kanał komunikacji z klientem (pos_sw_register.js).
// ═══════════════════════════════════════════════════════════════════════════
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (data.type === 'GET_VERSION') {
        event.ports?.[0]?.postMessage({ version: CACHE_VERSION });
    } else if (data.type === 'PING') {
        event.ports?.[0]?.postMessage({ type: 'PONG', at: Date.now() });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// FETCH — router strategii.
// ═══════════════════════════════════════════════════════════════════════════
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Tylko GET obsługujemy w SW — mutacje (POST) idą bez modyfikacji.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Tylko same-origin. Cross-origin (fonts.googleapis, CDN FA) niech
    // leci przez standardowy browser cache.
    if (url.origin !== self.location.origin) return;

    // POS operuje tylko w swoim scope — ignoruj reszty repo.
    if (!url.pathname.startsWith('/slicehub/modules/pos/') &&
        !url.pathname.startsWith('/slicehub/api/pos/') &&
        !url.pathname.startsWith('/slicehub/api/tables/') &&
        !url.pathname.startsWith('/slicehub/api/courses/') &&
        !url.pathname.startsWith('/slicehub/api/orders/') &&
        !url.pathname.startsWith('/slicehub/core/')) {
        return;
    }

    // Routing strategii:
    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(navigateStrategy(request));
        return;
    }

    if (url.pathname.startsWith('/slicehub/api/')) {
        event.respondWith(apiStrategy(request, url));
        return;
    }

    // Static asset (css/js/svg/ico/webmanifest/font)
    if (/\.(?:css|js|svg|png|webp|ico|json|webmanifest|woff2?)$/i.test(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
        return;
    }
});

// ─── Strategy: navigation ────────────────────────────────────────────────
// Network-first z krótkim timeoutem. Przy awarii → cache → offline.html.
async function navigateStrategy(request) {
    try {
        const res = await fetchWithTimeout(request, NAV_TIMEOUT_MS);
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, res.clone()).catch(() => {});
        return res;
    } catch (_) {
        const cached = await caches.match(request, { ignoreSearch: true }) ||
                       await caches.match('/slicehub/modules/pos/index.html');
        if (cached) return cached;
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        return new Response('POS offline — fallback niedostępny', {
            status: 503, statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
    }
}

// ─── Strategy: API ───────────────────────────────────────────────────────
// Read-only actions → network-first, fallback cache (stale data lepsze niż
// pusty ekran). Wszystko inne → network-only (żeby P1 nigdy nie udawał, że
// zamówienie zostało zapisane, gdy nie zostało — to będzie P3).
async function apiStrategy(request, url) {
    const isReadAction = sniffReadAction(url);
    if (!isReadAction) {
        // Pass-through. P1 świadomie nie dotyka mutacji.
        return fetch(request);
    }

    try {
        const res = await fetchWithTimeout(request, API_TIMEOUT_MS);
        if (res && res.ok) {
            const cache = await caches.open(API_CACHE);
            cache.put(request, res.clone()).catch(() => {});
        }
        return res;
    } catch (_) {
        const cached = await caches.match(request);
        if (cached) {
            // Oznacz odpowiedź jako stale, żeby klient wiedział.
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
            message: 'Offline — brak cache dla tego zapytania',
            data: null,
            _offline: true,
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
        });
    }
}

// Heurystyka: action= w query stringu albo wzorcem ścieżki. Bezpieczne.
function sniffReadAction(url) {
    const action = url.searchParams.get('action');
    if (action && API_READ_ACTIONS.has(action)) return true;
    // GET-y do orders/estimate.php itp — standardowo read-only.
    if (url.pathname.endsWith('/estimate.php')) return true;
    return false;
}

// ─── Strategy: stale-while-revalidate ────────────────────────────────────
async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    const networkPromise = fetch(request).then((res) => {
        if (res && res.ok) cache.put(request, res.clone()).catch(() => {});
        return res;
    }).catch(() => null);

    return cached || networkPromise || fetch(request);
}

// ─── Helper: fetch z timeoutem ───────────────────────────────────────────
function fetchWithTimeout(request, timeoutMs) {
    return new Promise((resolve, reject) => {
        const ctrl = new AbortController();
        const id = setTimeout(() => ctrl.abort(), timeoutMs);
        fetch(request, { signal: ctrl.signal })
            .then((res) => { clearTimeout(id); resolve(res); })
            .catch((err) => { clearTimeout(id); reject(err); });
    });
}
