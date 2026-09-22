/* Lo que le pasó a Max: todo el personal con «PIN pendiente» y ninguna
   pista de por qué. El panel tiene que decirlo donde se está mirando. */
const { chromium } = require('playwright');
const fs = require('fs');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const MOCK = fs.readFileSync('mock-fotos.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok = (c, t, e = '') => { console.log((c?'  ✔ ':'  ✘ ')+t+(e?'  → '+e:'')); if(!c) fallos++; };

async function abrir(b, responderFuncion) {
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 }, serviceWorkers: 'block' });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());
  await p.addInitScript(() => { window.__ROL_PRUEBA = 'admin'; });
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType:'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType:'application/javascript', body: ENV }));
  await p.route('**/functions/v1/credisan', responderFuncion);
  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'admin@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(600);
  await p.click('.nav button[data-vista="v-personal"]');
  await p.waitForTimeout(1200);
  return { p, errs };
}

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});

  console.log('\n1 · La función no está publicada');
  {
    const { p, errs } = await abrir(b, (r) => r.fulfill({ status: 404,
      contentType: 'application/json', body: JSON.stringify({ code: 404, message: 'Function not found' }) }));
  // El panel consulta el estado del respaldo, que en producción SÍ
  // existe. Sin contestarlo aquí, la prueba cuenta su 404 como un error
  // del programa — que es justamente lo que no es.
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 })
  }));

    ok(await p.locator('#aviso-funcion').isVisible(), 'sale un aviso en la lista de personal');
    const t = await p.textContent('#aviso-funcion');
    ok(/no está publicada/.test(t), 'dice exactamente qué falta');
    ok(/PIN pendiente/.test(t), 'CONECTA con la etiqueta que se ve en cada trabajador');
    ok(/Poner en marcha/.test(t), 'y dice dónde se arregla', t.trim().slice(0, 80) + '…');
    await p.screenshot({ path: 'psf-1-aviso.png', fullPage: true });

    // Y si además pulsa el botón, el mensaje tampoco es genérico
    await p.click('#lista-personal [data-pin]');
    await p.waitForTimeout(700);
    const aviso = await p.textContent('#panel-aviso');
    ok(/no está publicada/.test(aviso), 'al pulsar «Dar PIN» dice el motivo real', aviso.trim());
    ok(!/ERROR|No se pudo generar/.test(aviso), 'y NO el mensaje genérico de antes');

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  console.log('\n2 · Publicada pero sin la pimienta');
  {
    const { p, errs } = await abrir(b, (r) => r.fulfill({ status: 500,
      contentType: 'application/json', body: JSON.stringify({ ok: false, motivo: 'FALTA_PEPPER' }) }));
    const t = await p.textContent('#aviso-funcion');
    ok(/CREDISAN_PIN_PEPPER/.test(t), 'distingue este caso y nombra el secreto', t.trim().slice(0, 70) + '…');
    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  console.log('\n3 · Todo publicado: el panel no molesta');
  {
    const { p, errs } = await abrir(b, (r) => {
      const c = JSON.parse(r.request().postData());
      if (c.accion === 'asignar-pin') return r.fulfill({ contentType:'application/json',
        body: JSON.stringify({ ok: true, pin: '481902' }) });
      return r.fulfill({ contentType:'application/json',
        body: JSON.stringify({ ok: true, pimienta: true, sin_conexion: true }) });
    });
    ok(await p.locator('#aviso-funcion').isHidden(), 'no aparece ningún aviso');

    await p.click('#lista-personal [data-pin]');
    await p.waitForTimeout(700);
    ok(await p.locator('#revelacion').isVisible(), 'y el PIN se genera');
    ok((await p.textContent('#rev-codigo')).trim() === '481902', 'con sus seis dígitos');
    await p.screenshot({ path: 'psf-2-pin-ok.png', fullPage: true });
    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  await b.close();
  console.log(fallos === 0
    ? '\n✔ AVISO DE FUNCIÓN AUSENTE — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
