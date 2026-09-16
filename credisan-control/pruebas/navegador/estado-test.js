/* La página de estado tiene un solo trabajo: decir qué falta. Si se
   equivoca, manda a alguien a buscar en el sitio equivocado. */
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-pasar-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok = (c, t, e = '') => { console.log((c?'  ✔ ':'  ✘ ')+t+(e?'  → '+e:'')); if(!c) fallos++; };

async function abrir(b, responder) {
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 }, serviceWorkers: 'block' });
  const p = await ctx.newPage();
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType:'application/javascript', body: ENV }));
  await p.route('**/rest/v1/**', (r) => r.fulfill({ status: 200, contentType:'application/json', body: '[]' }));
  await p.route('**/functions/v1/credisan', responder);
  await p.goto('http://localhost:8099/index.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);
  return p;
}
const leer = async (p) => ({
  titulo: (await p.textContent('#e-funcion .estado__texto strong')).trim(),
  detalle: (await p.textContent('#e-funcion .estado__texto span')).trim(),
  nivel: await p.getAttribute('#e-funcion .estado__icono', 'data-e')
});

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});

  console.log('\n1 · La función NO está publicada (el caso de Max ahora mismo)');
  {
    const p = await abrir(b, (r) => r.fulfill({ status: 404, contentType:'application/json',
      body: JSON.stringify({ code: 404, message: 'Function not found' }) }));
    const e = await leer(p);
    ok(e.nivel === 'error', 'sale marcado como error', e.nivel);
    ok(/no está publicada/i.test(e.titulo), 'dice exactamente qué pasa', e.titulo);
    ok(/PIN pendiente/.test(e.detalle), 'CONECTA con lo que se ve en el panel', e.detalle);
    ok(/Poner en marcha|EMPEZAR-AQUI/.test(e.detalle), 'y dice dónde arreglarlo');
    await p.screenshot({ path: 'est-1-sin-funcion.png', fullPage: true });
    await p.close();
  }

  console.log('\n2 · Publicada pero sin la pimienta');
  {
    const p = await abrir(b, (r) => r.fulfill({ status: 500, contentType:'application/json',
      body: JSON.stringify({ ok: false, motivo: 'FALTA_PEPPER' }) }));
    const e = await leer(p);
    ok(e.nivel === 'error', 'también es error', e.nivel);
    ok(/secreto del PIN/i.test(e.titulo), 'distingue este caso del anterior', e.titulo);
    ok(/CREDISAN_PIN_PEPPER/.test(e.detalle), 'y nombra el secreto que falta');
    await p.close();
  }

  console.log('\n3 · Todo en orden');
  {
    const p = await abrir(b, (r) => r.fulfill({ contentType:'application/json',
      body: JSON.stringify({ ok: true, pimienta: true, sin_conexion: true }) }));
    const e = await leer(p);
    ok(e.nivel === 'ok', 'sale en verde', e.nivel);
    ok(/publicada/i.test(e.titulo), 'confirma que está lista', e.titulo);
    ok(/generar PIN/i.test(e.detalle), 'y dice qué se desbloquea', e.detalle);
    await p.screenshot({ path: 'est-2-todo-listo.png', fullPage: true });
    await p.close();
  }

  console.log('\n3b · Publicada, con pimienta, pero sin llave de sin-conexión');
  {
    const p = await abrir(b, (r) => r.fulfill({ contentType:'application/json',
      body: JSON.stringify({ ok: true, pimienta: true, sin_conexion: false }) }));
    const e = await leer(p);
    ok(e.nivel === 'ok', 'sigue siendo verde: se puede marcar', e.nivel);
    ok(/sin conexión/i.test(e.detalle), 'pero avisa de lo que falta', e.detalle);
    await p.close();
  }

  console.log('\n4 · Las otras cuatro comprobaciones siguen funcionando');
  {
    const p = await abrir(b, (r) => r.fulfill({ contentType:'application/json',
      body: JSON.stringify({ ok: true, pimienta: true, sin_conexion: true }) }));
    const n = await p.$$eval('.estado li', (x) => x.length);
    ok(n === 5, 'la lista tiene cinco puntos', String(n));
    const esperas = await p.$$eval('.estado__icono', (x) => x.filter((i) => i.dataset.e === 'espera').length);
    ok(esperas === 0, 'ninguno se queda en «comprobando» para siempre', String(esperas));
    await p.close();
  }

  await b.close();
  console.log(fallos === 0
    ? '\n✔ PÁGINA DE ESTADO — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
