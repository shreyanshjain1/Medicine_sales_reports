/* Service worker — modular PWA cache strategy for the restructured app */
const CACHE_NAME = 'pharmastar-reporting-v4-modular';
const OFFLINE_URL = './offline.html';

const PRECACHE = [
  './offline.html',
  './manifest.webmanifest',
  './dashboard.php',
  './reports/reports.php',
  './reports/report_add.php',
  './auth/profile.php',
  './assets/style.css',
  './assets/app.js',
  './assets/js/core.js',
  './assets/js/quick-task.js',
  './assets/js/offline-reports.js',
  './assets/js/pwa.js',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.allSettled(PRECACHE.map(async (url) => {
      try { await cache.add(url); } catch (err) {}
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((key) => key === CACHE_NAME ? Promise.resolve() : caches.delete(key)));
    await self.clients.claim();
  })());
});

function isSameOrigin(request) {
  try { return new URL(request.url).origin === self.location.origin; } catch (err) { return false; }
}

function isApiRequest(url) {
  return url.pathname.includes('/api/');
}

function isStaticAsset(url) {
  return url.pathname.includes('/assets/') || url.pathname.includes('/uploads/');
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET' || !isSameOrigin(request)) return;

  const url = new URL(request.url);

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, response.clone()).catch(() => {});
        return response;
      } catch (err) {
        const cached = await caches.match(request);
        return cached || caches.match(OFFLINE_URL);
      }
    })());
    return;
  }

  if (isApiRequest(url)) {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, response.clone()).catch(() => {});
        return response;
      } catch (err) {
        return caches.match(request);
      }
    })());
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      const response = await fetch(request);
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone()).catch(() => {});
      return response;
    })());
    return;
  }

  event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
});
