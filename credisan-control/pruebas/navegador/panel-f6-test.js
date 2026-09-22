const { chromium } = require('playwright');
const fs = require('fs');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const MOCK = fs.readFileSync('mock-f6.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok = (c, t, e = '') => { console.log((c?'  ✔ ':'  ✘ ')+t+(e?'  → '+e:'')); if(!c) fallos++; };

async function abrir(b, rol) {
  const p = await b.newPage({ viewport: { width: 414, height: 900 }, deviceScaleFactor: 2 });
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  await p.addInitScript((r) => { window.__ROL_PRUEBA = r; }, rol);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType:'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType:'application/javascript', body: ENV }));
  // El panel consulta el estado del respaldo, que en producción SÍ
  // existe. Sin contestarlo aquí, la prueba cuenta su 404 como un error
  // del programa — que es justamente lo que no es.
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 })
  }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType:'text/css', body:'' }));
  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', rol + '@credisan.test');
  await p.fill('#clave', 'secreta');
  await p.click('#btn-entrar');
  await p.waitForTimeout(700);
  return { p, errs };
}

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});

  console.log('\n1 · Dirección ve la conexión de los tres terminales');
  {
    const { p, errs } = await abrir(b, 'ceo');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(250);
    ok((await p.$$eval('#menu-mas .ficha--boton:not([hidden])', (n) => n.length)) === 5,
       'el menú Más lleva ahora cinco destinos');

    await p.click('[data-ir="v-conexion"]');
    await p.waitForTimeout(600);
    ok(await p.locator('#v-conexion').isVisible(), 'entra en Terminales y conexión');
    ok((await p.$$eval('#lista-terminales-conexion .ficha', (n) => n.length)) === 2,
       'lista los dos terminales');

    const texto = await p.evaluate(() => document.getElementById('v-conexion').innerText);
    ok(/Al día/.test(texto), 'el que responde sale «Al día»');
    ok(/Sin señal/.test(texto), 'y el que lleva 50 h callado sale «Sin señal»');
    ok(/hace 2 días/.test(texto), 'dice desde cuándo, en días', texto.match(/hace [^\n·]*/g)?.join(' | '));
    ok(/Reenvío de algo ya recibido/.test(texto),
       'los motivos de rechazo salen en castellano, no como código');
    ok(!/SECUENCIA_INVALIDA/.test(texto), 'y el código crudo no se enseña');
    ok(/Reloj desviado 15 min/.test(texto), 'señala la marcación con el reloj desviado');
    ok(/Ana Pérez/.test(texto) && /Luis Rojas/.test(texto), 'lista lo que llegó sin conexión');
    await p.screenshot({ path: 'pf6-1-conexion.png', fullPage: true });

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  console.log('\n2 · La administradora sólo ve su sede');
  {
    const { p, errs } = await abrir(b, 'admin');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(250);
    await p.click('[data-ir="v-conexion"]');
    await p.waitForTimeout(600);
    ok((await p.$$eval('#lista-terminales-conexion .ficha', (n) => n.length)) === 1,
       'un solo terminal');
    const texto = await p.evaluate(() => document.getElementById('v-conexion').innerText);
    ok(!/CSS-01|Caja Seca/.test(texto), 'ni rastro del terminal de la otra sede');
    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  console.log('\n3 · El jefe operativo no llega a esta pantalla');
  {
    const { p, errs } = await abrir(b, 'supervisor');
    ok(await p.locator('#nav-mas').isHidden(), 'no ve el menú Más');
    const todo = await p.evaluate(() => document.body.innerText);
    ok(!/Terminales y conexión/.test(todo), 'ni el nombre de la pantalla aparece');

    // Y en la pantalla que SÍ ve, nada de abrir fotografías: la matriz
    // de roles aprobada deja la evidencia en dirección y administración.
    ok((await p.$$eval('[data-ver-foto]', (n) => n.length)) === 0,
       'no se le ofrece abrir la fotografía de nadie');
    ok(!/📷/.test(todo), 'ni aparece el icono de la cámara');
    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  await b.close();
  console.log(fallos === 0
    ? '\n✔ PANEL DE FASE 6 — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
