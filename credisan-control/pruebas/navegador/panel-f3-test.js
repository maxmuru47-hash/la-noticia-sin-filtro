const { chromium } = require('playwright');
// Ruta al navegador: se toma del entorno si está, y si no se deja que
// Playwright use el suyo. Fijarla a pelo ata la prueba a una máquina.
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-supabase.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 }, deviceScaleFactor: 2 });
  const errs = []; let peticionPin = null;
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());

  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  // El panel consulta el estado del respaldo, que en producción SÍ
  // existe. Sin contestarlo aquí, la prueba cuenta su 404 como un error
  // del programa — que es justamente lo que no es.
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 })
  }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) => {
    peticionPin = { cuerpo: JSON.parse(r.request().postData()), auth: r.request().headers()['authorization'] };
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pin: '481902' }) });
  });

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'ceo@credisan.test');
  await p.fill('#clave', 'secreta');
  await p.click('#btn-entrar');
  await p.waitForTimeout(700);

  // Los terminales viven en «Más → Sedes» desde que el panel tiene vistas
  // (fase 4). Antes colgaban de la pantalla principal.
  await p.click('.nav button[data-vista="v-mas"]');
  await p.click('[data-ir="v-sedes"]');
  await p.waitForTimeout(600);

  console.log('1 · terminales listados:', await p.$$eval('#lista-terminales .ficha', (n) => n.length));
  console.log('   botones:', await p.$$eval('#lista-terminales .ficha__accion', (n) => n.map((x) => x.textContent.trim())));

  await p.click('[data-emparejar]');
  await p.waitForTimeout(400);
  console.log('2 · código revelado:', (await p.textContent('#rev-codigo')).trim(),
              '| visible:', await p.locator('#revelacion').isVisible());
  await p.screenshot({ path: 'pf3-codigo.png', fullPage: true });
  await p.click('#rev-cerrar');

  await p.click('.nav button[data-vista="v-personal"]');
  await p.waitForTimeout(500);
  console.log('3 · botones por trabajador:',
    await p.$$eval('#lista-personal .ficha__accion', (n) => n.map((x) => x.textContent.trim())));

  await p.click('[data-pin]');
  await p.waitForTimeout(600);
  console.log('4 · PIN revelado:', (await p.textContent('#rev-codigo')).trim());
  console.log('   acción enviada:', peticionPin.cuerpo.accion,
              '| usa el token del usuario:', peticionPin.auth === 'Bearer jwt-de-prueba');
  await p.screenshot({ path: 'pf3-pin.png', fullPage: true });

  console.log('errores JS:', errs.length ? errs : 'ninguno');
  await b.close();
})();
