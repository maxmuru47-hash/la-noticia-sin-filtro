/* CrediSan Control · service worker
   Estrategia deliberadamente simple:
     · HTML y configuración → red primero (que una corrección se vea ya)
     · imágenes y estilos   → caché primero (que el kiosco arranque rápido)
   Nada relacionado con marcaciones se cachea: eso llega en la fase 6. */

const CACHE = 'credisan-v1';
const BASE  = new URL('./', self.location).pathname;

const ESENCIALES = [
  BASE,
  BASE + 'index.html',
  BASE + 'assets/css/credisan.css',
  BASE + 'assets/brand/credisan-logo-blanco.png',
  BASE + 'assets/icons/icon-192.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(ESENCIALES))
      .catch(() => null)          // un fallo de caché no debe impedir instalar
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;   // nunca se toca Supabase

  const esDocumento = req.mode === 'navigate' || url.pathname.endsWith('env.js');

  if (esDocumento) {
    e.respondWith(fetch(req).catch(() => caches.match(req).then((r) => r || caches.match(BASE))));
    return;
  }

  e.respondWith(
    caches.match(req).then((cacheada) => cacheada || fetch(req).then((res) => {
      const copia = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copia)).catch(() => null);
      return res;
    }))
  );
});
