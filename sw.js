const CACHE_NAME = 'health2you-v1';
const urlsToCache = [
  './',
  './index.php',
  './login.php',
  './verificar_2fa.php',
  './style.css'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => response || fetch(event.request))
  );
});
