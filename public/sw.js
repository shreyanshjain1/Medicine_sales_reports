/* sw.js — Pharmastar Reporting (offline shell + cache)
   NOTE: Keep this cache version bumped when changing PRECACHE.
*/
const CACHE_NAME = 'pharmastar-reporting-v3-realfix';

// Pre-cache the pages a rep commonly needs when truly offline.
// These pages are served from cache when the network is unavailable.
const PRECACHE = [
  './offline.html',
  './manifest.webmanifest',

  // Core pages (must work offline)
  './dashboard.php',
  './reports.php',
  './report_add.php',
  './profile.php',

  // Static assets
  './assets/style.css',
  './assets/calendar.css',
  './assets/app.js',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE_NAME);
      // Cache what we can; don't fail the whole SW install if one page returns 500/redirect.
      await Promise.allSettled(PRECACHE.map(async (u) => {
        try { await cache.add(u); } catch (e) { /* ignore */ }
      }));
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.map(k => (k === CACHE_NAME ? null : caches.delete(k)))))
      .then(() => self.clients.claim())
  );
});

function isSameOrigin(req) {
  try { return new URL(req.url).origin === self.location.origin; } catch(e){ return false; }
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only handle GET for same-origin
  if (req.method !== 'GET' || !isSameOrigin(req)) return;

  const url = new URL(req.url);

  // IMPORTANT: Don't cache dynamic PHP pages (prevents wrong ?id= pages being reused)
  if (url.pathname.endsWith('.php')) return;

  // Navigation: network-first, fallback to cache, then offline page
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy)).catch(()=>{});
          return res;
        })
        .catch(async () => {
          const cached = await caches.match(req);
          return cached || caches.match('./offline.html');
        })
    );
    return;
  }

  // API GET: network-first with cache fallback
  if (url.pathname.endsWith('/api_doctors.php') || url.pathname.endsWith('/api_events.php') || url.pathname.endsWith('/chart_data.php')) {
    event.respondWith(
      fetch(req)
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy)).catch(()=>{});
          return res;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  // Static assets: cache-first
  if (url.pathname.includes('/assets/') || url.pathname.includes('/uploads/')) {
    event.respondWith(
      caches.match(req).then(cached => cached || fetch(req).then(res => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(req, copy)).catch(()=>{});
        return res;
      }))
    );
    return;
  }

  // Default: try cache, else network
  event.respondWith(
    caches.match(req).then(cached => cached || fetch(req))
  );
});