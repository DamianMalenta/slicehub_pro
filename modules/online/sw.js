const CACHE_VERSION = 'slicehub-online-v4';
const SW_DIR = self.location.pathname.replace(/\/sw\.js$/, '');
const BASE_PATH = SW_DIR.replace(/\/modules\/online$/, '');
const OFFLINE_URL = BASE_PATH + '/modules/online/offline.html';
const PRECACHE = [
  BASE_PATH + '/modules/online/index.html',
  BASE_PATH + '/modules/online/track.html',
  BASE_PATH + '/modules/online/offline.html',
  BASE_PATH + '/modules/online/manifest.webmanifest',
  BASE_PATH + '/modules/online/icon.svg',
  BASE_PATH + '/modules/online/icon-maskable.svg',
  BASE_PATH + '/modules/online/screenshots/wide.svg',
  BASE_PATH + '/modules/online/screenshots/narrow.svg',
  BASE_PATH + '/modules/online/css/style.css',
  BASE_PATH + '/modules/online/css/track.css',
  BASE_PATH + '/modules/online/css/doorway.css',
  BASE_PATH + '/modules/online/css/living-scene.css',
  BASE_PATH + '/modules/online/js/online_api.js',
  BASE_PATH + '/modules/online/js/online_app.js',
  BASE_PATH + '/modules/online/js/online_ui.js',
  BASE_PATH + '/modules/online/js/online_table.js',
  BASE_PATH + '/modules/online/js/online_renderer.js',
  BASE_PATH + '/modules/online/js/online_checkout.js',
  BASE_PATH + '/modules/online/js/online_track.js',
  BASE_PATH + '/modules/online/js/online_doorway.js',
  BASE_PATH + '/modules/online/js/surface/ModifierOrchestrator.js'
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
