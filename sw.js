const CACHE_NAME = 'health2you-v2'; // Cambiamos a v2 para forzar la actualización
const urlsToCache = [
  './',
  './style.css'
  // No cacheamos archivos .php para evitar problemas de sesión
];

self.addEventListener('install', event => {
  self.skipWaiting(); // Fuerza al nuevo SW a tomar el control inmediatamente
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('activate', event => {
  // Borra cachés antiguas
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(name => name !== CACHE_NAME).map(name => caches.delete(name))
      );
    })
  );
});

self.addEventListener('fetch', event => {
  // ESTRATEGIA: Para archivos PHP, ir SIEMPRE a la red (Network Only)
  if (event.request.url.includes('.php')) {
    return; // Si es PHP, no hacemos nada y dejamos que el navegador vaya al servidor
  }

  // Para lo demás (CSS, imágenes), intentar caché y si no, red
  event.respondWith(
    caches.match(event.request)
      .then(response => response || fetch(event.request))
  );
});
