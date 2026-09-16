/* CrediSan Control · service worker
   ---------------------------------------------------------------------
   Lo que decide todo lo de aquí abajo: EL TERMINAL TIENE QUE ARRANCAR SIN
   INTERNET. La Fase 6 le dio una cola para guardar marcaciones cuando se
   cae la red, pero eso no sirve de nada si la propia página no carga.

   Así que el kiosco se guarda entero —su HTML, sus estilos, sus tres
   módulos y su configuración— en cuanto se instala. Un aparato de
   mostrador tiene que poder encenderse un lunes sin señal y funcionar.

   Estrategias:
     · documentos, configuración Y EL PROGRAMA (.js, .css) → red
       primero, con copia de respaldo para cuando no haya señal
     · lo que no cambia (tipografías, imágenes, iconos) → caché primero
     · Supabase → nunca se toca; de eso se encarga la cola de la Fase 6

   POR QUÉ EL PROGRAMA VA POR RED PRIMERO
   --------------------------------------
   Antes iba por caché, como las imágenes, y eso partía la aplicación en
   dos: el HTML llegaba nuevo —porque el HTML sí iba por red— y el
   JavaScript se quedaba en la versión vieja para siempre. Con esa
   mezcla, una pantalla nueva no aparecía nunca (el programa viejo no
   sabe que existe) o el panel se quedaba en «Cargando» sin avanzar (el
   programa nuevo busca algo que el HTML viejo no tiene).

   Le pasó a Max, en su PC y en su teléfono a la vez, y desde fuera
   parecían dos fallos distintos.

   El HTML y el JavaScript de una aplicación son una sola pieza: o
   llegan los dos nuevos, o los dos viejos. Nunca mezclados.
*/

const CACHE = 'credisan-v3';
const BASE  = new URL('./', self.location).pathname;

/* El kiosco va completo y a propósito: es el único que tiene que
   funcionar con el router apagado. Del panel se guarda lo que se vaya
   usando, porque administración siempre trabaja con conexión. */
const ESENCIALES = [
  BASE,
  BASE + 'index.html',
  BASE + 'config/env.js',

  BASE + 'kiosk/',
  BASE + 'kiosk/index.html',
  BASE + 'src/kiosk/app.js',
  BASE + 'src/kiosk/offline.js',
  BASE + 'src/core/config.js',

  BASE + 'assets/css/credisan.css',
  BASE + 'assets/css/kiosk.css',
  BASE + 'assets/fuentes/poppins.css',
  BASE + 'assets/fuentes/poppins-400-latin.woff2',
  BASE + 'assets/fuentes/poppins-500-latin.woff2',
  BASE + 'assets/fuentes/poppins-600-latin.woff2',
  BASE + 'assets/fuentes/poppins-700-latin.woff2',

  BASE + 'assets/brand/credisan-logo-blanco.png',
  BASE + 'assets/icons/icon-192.png',
  BASE + 'manifest.webmanifest'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      // Uno a uno, no `addAll`: con addAll, un solo archivo que falte
      // tira la instalación entera y el terminal se queda sin nada
      // guardado. Vale más guardar quince de dieciséis que ninguno.
      .then((c) => Promise.all(ESENCIALES.map(
        (u) => c.add(u).catch(() => null))))
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

  // El programa viaja con el documento: si uno es nuevo, el otro también.
  const esPrograma = url.pathname.endsWith('.js') || url.pathname.endsWith('.css');
  const esDocumento = req.mode === 'navigate'
                   || url.pathname.endsWith('env.js')
                   || esPrograma;

  if (esDocumento) {
    // Red primero, para que una corrección se vea el mismo día. Pero
    // guardando copia: antes no se guardaba, así que el respaldo de la
    // línea siguiente nunca tenía nada que devolver y sin internet el
    // terminal se quedaba en blanco.
    //
    // Sin señal se responde con la copia, así que el kiosco sigue
    // arrancando un lunes sin router, que es la razón de que exista
    // todo esto.
    e.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.ok) {
            const copia = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copia)).catch(() => null);
          }
          return res;
        })
        // `ignoreSearch` porque el HTML pide el programa con un sello de
        // versión —app.js?v=abc123— para que ningún navegador pueda
        // quedarse con el viejo. Sin señal ese sello no debe impedir
        // encontrar la copia guardada: da igual la versión, lo que hace
        // falta es que el terminal encienda.
        .catch(() => caches.match(req, { ignoreSearch: true })
          .then((r) => r || caches.match(BASE + 'kiosk/index.html'))
          .then((r) => r || caches.match(BASE)))
    );
    return;
  }

  e.respondWith(
    caches.match(req, { ignoreSearch: true }).then((cacheada) => cacheada || fetch(req).then((res) => {
      if (res && res.ok) {
        const copia = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copia)).catch(() => null);
      }
      return res;
    }))
  );
});
