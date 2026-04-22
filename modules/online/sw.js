const CACHE_VERSION = 'slicehub-online-v1';
const OFFLINE_URL = '/slicehub/modules/online/offline.html';
const PRECACHE = [
  '/slicehub/modules/online/index.html',
  '/slicehub/modules/online/track.html',
  '/slicehub/modules/online/offline.html',
  '/slicehub/modules/online/manifest.webmanifest',
  '/slicehub/modules/online/icon.svg',
  '/slicehub/modules/online/css/style.css',
  '/slicehub/modules/online/css/track.css',
  '/slicehub/modules/online/css/doorway.css',
  '/slicehub/modules/online/css/living-scene.css',
  '/slicehub/modules/online/js/online_api.js',
  '/slicehub/modules/online/js/online_app.js',
  '/slicehub/modules/online/js/online_ui.js',
  '/slicehub/modules/online/js/online_table.js',
  '/slicehub/modules/online/js/online_renderer.js',
  '/slicehub/modules/online/js/online_checkout.js',
  '/slicehub/modules/online/js/online_track.js',
  '/slicehub/modules/online/js/online_doorway.js',
  '/slicehub/modules/online/js/surface/ModifierOrchestrator.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          return cached || caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  const isStaticAsset = /\.(?:css|js|svg|png|webp|ico|html|json)$/i.test(url.pathname);
  if (!isStaticAsset) return;

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy)).catch(() => {});
        return response;
      });
    })
  );
});
