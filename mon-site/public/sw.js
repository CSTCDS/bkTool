// Service worker inertified for development: remove caches and always use network
self.addEventListener('install', event => {
  // Activate immediately
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  // Remove any existing caches to avoid serving stale assets
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

// Always fall back to network (no caching)
self.addEventListener('fetch', event => {
  event.respondWith(fetch(event.request));
});
