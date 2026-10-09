/* Panel · el cierre semanal del jefe operativo
   ---------------------------------------------------------------------
   Max pidió que el jefe operativo vea el cierre de su semana. Quien lo
   impide no es el panel: es la base de datos, y hace falta aplicarle la
   actualización de la Fase 15, que hoy sigue pendiente.

   La tentación es enseñar la pestaña igualmente. Eso ya se hizo una vez
   —la Fase 13 se publicó contra una migración sin aplicar y el
   desplegable de novedades salió VACÍO en producción, delante del
   personal— y de ahí sale esta batería.

   El panel no adivina: pregunta al servidor una vez al entrar. Y esta
   batería exige las dos mitades de esa promesa:

     · Con la base sin actualizar, el jefe operativo no ve ni la pestaña
       y no se encuentra con ningún error por pantalla.
     · Con la base actualizada, la ve, y dentro ve su semana pero NO los
       botones de aprobar, observar, reabrir ni calcular: leer sí,
       escribir no.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-cierre-jefe.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://control.sinfiltroconmax.com/api',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrir(rol, baseActualizada) {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 } });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());

  await p.addInitScript(`window.__ROL_PRUEBA = ${JSON.stringify(rol)};
                         window.__BASE_ACTUALIZADA = ${baseActualizada ? 'true' : 'false'};`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 }) }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(1100);
  return { b, p, errs };
}

const veces = (p, nombre) => p.evaluate((n) =>
  window.__llamadas.filter((x) => x[0] === n).length, nombre);

(async () => {
  // ── 1 · La base todavía no está actualizada ──────────────────────
  console.log('\n1 · Base sin actualizar: ni pestaña, ni error por pantalla');
  {
    const { b, p, errs } = await abrir('supervisor', false);

    si('el panel arranca igual', await p.locator('#pantalla-panel').isVisible());
    si('NO se le ofrece la pestaña de cierres',
       await p.locator('#nav-cierres').isHidden());
    si('la barra inferior es la de siempre',
       (await p.$$eval('.nav button:not([hidden])', (n) => n.map((x) => x.dataset.vista).join(','))) === 'v-hoy,v-horas,v-personal,v-novedades');

    const todo = await p.evaluate(() => document.body.innerText);
    si('y en ninguna parte le aparece un «no autorizado»',
       !/no autorizado|NO_AUTORIZADO/i.test(todo));

    si('pero el panel SÍ se lo preguntó al servidor', (await veces(p, 'cierres')) === 1);
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Con la base actualizada ──────────────────────────────────
  console.log('\n2 · Base actualizada: la pestaña aparece sola');
  {
    const { b, p, errs } = await abrir('supervisor', true);

    si('ahora SÍ se le ofrece', await p.locator('#nav-cierres').isVisible());
    await p.click('.nav button[data-vista="v-cierres"]');
    await p.waitForTimeout(800);

    const texto = await p.textContent('#lista-cierres');
    si('ve la semana de su gente', texto.includes('Ana Pérez') && texto.includes('Luis Rojas'));
    si('con sus cifras', texto.includes('83.33%') && texto.includes('61.9%'));

    const kpis = await p.$$eval('#cierre-kpis .kpi', (n) => n.length);
    si('y el resumen de la semana', kpis === 4, kpis);

    // LEER sí. ESCRIBIR no.
    si('no puede aprobar',  (await p.locator('[data-aprobar-cierre]').count()) === 0);
    si('no puede observar', (await p.locator('[data-observar]').count()) === 0);
    si('no puede reabrir',  (await p.locator('[data-reabrir]').count()) === 0);
    si('ni calcular la semana', await p.locator('#btn-calcular-semana').isHidden());

    const todo = await p.evaluate(() => document.body.innerText);
    si('y sigue sin una cifra de dinero ni un salario',
       !/salario|sueldo|USD|\$\d/i.test(todo));
    // La palabra «nómina» sí sale, y tiene que salir: está en la promesa
    // de cabecera, que es lo contrario de una fuga. Se comprueba que
    // también la lea él.
    si('y a él también se le dice que esto no toca la nómina',
       /no calcula descuentos ni toca la nómina/i.test(todo));
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Administración no pierde nada ────────────────────────────
  console.log('\n3 · Administración sigue igual que antes');
  {
    const { b, p, errs } = await abrir('admin', true);
    si('ve la pestaña desde el principio', await p.locator('#nav-cierres').isVisible());
    // A ella no se le pregunta: su pestaña no depende de la respuesta.
    si('y a ella no se le pregunta al entrar', (await veces(p, 'cierres')) === 0);

    await p.click('.nav button[data-vista="v-cierres"]');
    await p.waitForTimeout(800);
    si('sí puede aprobar',  (await p.locator('[data-aprobar-cierre]').count()) === 2);
    si('sí puede observar', (await p.locator('[data-observar]').count()) === 2);
    si('y calcular la semana', await p.locator('#btn-calcular-semana').isVisible());
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos ? `\n✖ ${fallos} comprobación(es) fallaron`
                     : '\n✔ CIERRE DEL JEFE OPERATIVO — todas las comprobaciones pasaron');
  process.exit(fallos ? 1 : 0);
})();
