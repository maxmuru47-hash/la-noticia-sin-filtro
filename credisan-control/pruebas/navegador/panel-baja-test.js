/* Panel · dar de baja a un trabajador
   ---------------------------------------------------------------------
   Hay DOS cosas distintas aquí, y confundirlas sería grave.

   Quien ya marcó tiene un historial, y ese historial es un registro
   laboral: a qué hora entró y salió cada día. Eso NO se borra nunca.

   Quien nunca marcó es otra cosa: una ficha repetida, una cédula mal
   tecleada. Ahí no hay nada que proteger y lo correcto es quitarla,
   dejando libres su cédula y su código.

   Esta batería exige que el sistema distinga los dos casos SOLO,
   mirando las marcaciones, y que lo diga antes de tocar nada.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-socios.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://control.sinfiltroconmax.com/api',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrir(rol = 'admin') {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 } });
  const errs = []; const dialogos = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => { dialogos.push(d.message()); d.accept(); });

  await p.addInitScript(`window.__ROL_PRUEBA = ${JSON.stringify(rol)};`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) =>
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 }) }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(800);
  await p.click('.nav button[data-vista="v-personal"]');
  await p.waitForTimeout(800);
  return { b, p, errs, dialogos };
}

const ultimoUpdate = (p) => p.evaluate(() =>
  (window.__llamadas.filter((x) => x[0] === 'update').pop() || [])[1]);

(async () => {
  // ── 1 · Con historial: se da de baja, NO se borra nada ────────────
  console.log('\n1 · Ana tiene marcaciones: su historial no se toca');
  {
    const { b, p, errs, dialogos } = await abrir();
    await p.evaluate(() => { window.__MARCACIONES = 37; window.__llamadas.length = 0; });

    await p.locator('.ficha:has-text("Ana Pérez") [data-baja]').click();
    await p.waitForTimeout(700);

    const aviso = dialogos.join(' ');
    si('se le avisa antes de tocar nada', dialogos.length > 0);
    si('y se le dice cuántas marcaciones tiene', aviso.includes('37'), aviso.slice(0, 90));
    si('con la promesa en mayúsculas: no se borra ninguna',
       aviso.includes('NO SE BORRA NINGUNA'));
    si('y que se puede reactivar', aviso.includes('reactivar'));

    const env = await ultimoUpdate(p);
    si('se manda la baja', env && env.is_active === false, JSON.stringify(env || null));
    si('y NO se marca como eliminada: el historial se conserva',
       env && !('deleted_at' in env), JSON.stringify(env || null));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Sin una sola marcación: era una ficha por error ───────────
  console.log('\n2 · Nunca marcó: la ficha se creó por error');
  {
    const { b, p, errs, dialogos } = await abrir();
    await p.evaluate(() => { window.__MARCACIONES = 0; window.__llamadas.length = 0; });

    await p.locator('.ficha:has-text("Ana Pérez") [data-baja]').click();
    await p.waitForTimeout(700);

    const aviso = dialogos.join(' ');
    si('se dice que no tiene ni una marcación', aviso.includes('ni una marcación'), aviso.slice(0, 90));
    si('y que la cédula queda libre', aviso.includes('cédula'));

    const env = await ultimoUpdate(p);
    si('ahora SÍ se elimina la ficha',
       env && env.is_active === false && !!env.deleted_at, JSON.stringify(env || null));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Si se arrepiente, no pasa nada ───────────────────────────
  console.log('\n3 · Cancelar no toca nada');
  {
    const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
    const p = await b.newPage({ viewport: { width: 414, height: 900 } });
    p.on('dialog', (d) => d.dismiss());          // decir que NO
    await p.addInitScript(`window.__ROL_PRUEBA = 'admin';`);
    await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
    await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
    await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
    await p.route('**/functions/v1/credisan', (r) =>
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));
    await p.route('**/estado-respaldo.json', (r) => r.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 }) }));
    await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
    await p.fill('#correo', 'x@credisan.test'); await p.fill('#clave', 'x');
    await p.click('#btn-entrar'); await p.waitForTimeout(800);
    await p.click('.nav button[data-vista="v-personal"]'); await p.waitForTimeout(800);
    await p.evaluate(() => { window.__MARCACIONES = 5; window.__llamadas.length = 0; });

    await p.locator('.ficha:has-text("Ana Pérez") [data-baja]').click();
    await p.waitForTimeout(600);
    const env = await ultimoUpdate(p);
    si('no se manda nada al servidor', !env, JSON.stringify(env || null));
    await b.close();
  }

  // ── 4 · Un inactivo se puede devolver ────────────────────────────
  console.log('\n4 · Quien está de baja se puede reactivar');
  {
    const { b, p, errs } = await abrir();
    si('a quien está inactivo se le ofrece Reactivar',
       await p.locator('.ficha:has-text("Mará Silva") [data-alta]').count() > 0
       || await p.locator('[data-alta]').count() > 0,
       'botones Reactivar: ' + await p.locator('[data-alta]').count());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 5 · Al jefe operativo no se le ofrece ────────────────────────
  console.log('\n5 · El jefe operativo no da de baja a nadie');
  {
    const { b, p, errs } = await abrir('supervisor');
    const n = await p.locator('#lista-personal [data-baja], #lista-personal [data-alta]').count();
    si('no se le ofrece ni dar de baja ni reactivar', n === 0, String(n));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — un historial laboral no se borra nunca\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
