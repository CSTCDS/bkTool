const CACHE_NAME = 'bktool-v1';
const ASSETS = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/assets/icons/icon-192.svg'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
});

self.addEventListener('fetch', e => {
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
});
const CACHE_NAME = 'bktool-v1';
const ASSETS = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/assets/icons/icon-192.svg'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
});

self.addEventListener('fetch', e => {
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
});
