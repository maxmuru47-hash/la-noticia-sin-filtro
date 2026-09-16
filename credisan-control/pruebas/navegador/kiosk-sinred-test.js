/* La prueba que faltaba: ¿ARRANCA el terminal sin internet?
   La Fase 6 le dio una cola para guardar marcaciones cuando se cae la
   red. Pero si la propia página no carga, esa cola no existe. Aquí se
   apaga la red de verdad, se recarga, y se exige que el teclado aparezca. */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;

// El service worker hace sus propias peticiones y no pasa por los
// simuladores de Playwright, así que aquí no se simula: se escribe un
// env.js de verdad durante la prueba y se restaura al terminar. Es el
// único modo honesto de comprobar que el terminal conserva su
// configuración cuando se va la luz del router.
const ENVJS = path.join(__dirname, '..', '..', 'public', 'config', 'env.js');
const ORIGINAL = fs.readFileSync(ENVJS, 'utf8');
const restaurar = () => { try { fs.writeFileSync(ENVJS, ORIGINAL); } catch {} };
process.on('exit', restaurar);
process.on('SIGINT', () => { restaurar(); process.exit(1); });

let fallos = 0;
const ok = (c, t, e = '') => { console.log((c?'  ✔ ':'  ✘ ')+t+(e?'  → '+e:'')); if(!c) fallos++; };

(async () => {
  const b = await chromium.launch({
    ...(NAVEGADOR ? { executablePath: NAVEGADOR } : {}),
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-capture']
  });
  // Contexto persistente NO: basta con no cerrar la página entre fases.
  const ctx = await b.newContext({
    viewport: { width: 414, height: 820 }, deviceScaleFactor: 2,
    permissions: ['camera'], baseURL: 'http://localhost:8099'
  });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  fs.writeFileSync(ENVJS, `window.CREDISAN_ENV = {
  supabaseUrl:      'https://prueba-sin-red.supabase.co',
  supabaseAnonKey:  'clave-de-prueba-larga-para-pasar-la-validacion',
  offlinePublicKey: 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAELzJ73G6NnuevaLLAVEDEPRUEBA',
  version:          '1.0.0'
};
`);

  console.log('\n1 · Primera visita, con internet');
  await p.goto('/kiosk/index.html', { waitUntil: 'load' });
  // Dar tiempo a que el service worker se instale y precachee
  await p.waitForFunction(() => navigator.serviceWorker.controller !== null, { timeout: 15000 })
    .catch(() => {});
  await p.waitForTimeout(2500);

  const sw = await p.evaluate(async () => {
    const r = await navigator.serviceWorker.getRegistration();
    return { registrado: !!r, activo: !!(r && r.active), controla: !!navigator.serviceWorker.controller };
  });
  ok(sw.registrado, 'el terminal registra su service worker');
  ok(sw.activo, 'y queda activo');

  const guardado = await p.evaluate(async () => {
    // El nombre de la caché lleva versión y sube cuando cambia la
    // estrategia. Escribirlo a mano aquí convertía una subida de versión
    // en ocho comprobaciones en rojo que no significaban nada. Lo que
    // importa es QUÉ se guardó, no en qué cajón.
    const nombres = await caches.keys();
    const cual = nombres.find((n) => n.startsWith('credisan-'));
    if (!cual) return [];
    const c = await caches.open(cual);
    const ks = await c.keys();
    return ks.map((r) => new URL(r.url).pathname).sort();
  });
  console.log('    guardados:', guardado.length, 'archivos');
  for (const nec of ['/kiosk/index.html', '/src/kiosk/app.js', '/src/kiosk/offline.js',
                     '/src/core/config.js', '/config/env.js', '/assets/css/kiosk.css',
                     '/assets/fuentes/poppins.css']) {
    ok(guardado.includes(nec), `guardó ${nec}`);
  }
  ok(guardado.some((x) => x.endsWith('.woff2')), 'y las tipografías, para no depender de Google');

  console.log('\n2 · Se corta el internet del todo y se recarga');
  await ctx.setOffline(true);
  await p.reload({ waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(1500);

  ok(await p.locator('#p-pin, #p-emparejar').first().isVisible(),
     'EL TERMINAL ARRANCA SIN INTERNET');

  const visible = await p.evaluate(() => document.body.innerText.trim().length);
  ok(visible > 20, 'y la pantalla tiene contenido, no está en blanco', visible + ' caracteres');

  const teclas = await p.$$eval('[data-d]', (n) => n.length).catch(() => 0);
  ok(teclas === 10, 'el teclado de diez dígitos está completo', String(teclas));

  // Lo que de verdad decide si el modo sin conexión sirve: sin la llave
  // pública guardada, el terminal no puede cerrar el sobre del PIN y no
  // podría marcar aunque la pantalla se vea.
  const cfg = await p.evaluate(() => ({ ...window.CREDISAN_ENV }));
  ok(cfg.supabaseUrl === 'https://prueba-sin-red.supabase.co',
     'CONSERVA su configuración sin red', cfg.supabaseUrl || '(vacía)');
  ok((cfg.offlinePublicKey || '').length > 40,
     'incluida la llave pública, sin la cual no podría cifrar el PIN');

  const fuente = await p.evaluate(() => {
    const f = getComputedStyle(document.body).fontFamily;
    return f;
  });
  ok(/Poppins/i.test(fuente), 'y la tipografía correcta se aplica sin red', fuente.slice(0, 40));

  await p.screenshot({ path: 'ksr-sin-internet.png', fullPage: true });

  console.log('\n3 · Y se puede marcar estando así');
  const antes = await p.evaluate(async () => {
    try {
      const bd = await new Promise((r, j) => { const q = indexedDB.open('credisan', 1); q.onsuccess = () => r(q.result); q.onerror = () => j(); });
      return await new Promise((r) => { const q = bd.transaction('cola','readonly').objectStore('cola').count(); q.onsuccess = () => r(q.result); });
    } catch { return -1; }
  });
  ok(antes >= 0, 'la cola de marcaciones existe y se puede abrir', String(antes));

  console.log('');
  ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
  await b.close();
  restaurar();
  console.log(fallos === 0
    ? '\n✔ EL TERMINAL FUNCIONA SIN INTERNET'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
