/* Panel · socios
   ---------------------------------------------------------------------
   Dos cosas distintas que comprobar:

     1. Que DIRECCIÓN pueda repartir sedes con casillas, y que lo que se
        manda al servidor sea exactamente lo marcado.
     2. Que el panel de un SOCIO no le ofrezca nada que la base le vaya a
        rechazar. Esconder un botón no es seguridad —la seguridad está en
        la base de datos y tiene su propia batería— pero ofrecer un botón
        que siempre falla es un panel roto.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-socios.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const es  = (t, a, b) => (String(a) === String(b) ? ok(t, a) : mal(t, `${a} ≠ ${b}`));
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrir(rol) {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 }, deviceScaleFactor: 2 });
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());

  await p.addInitScript(`window.__ROL_PRUEBA = ${JSON.stringify(rol)};`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(800);
  return { b, p, errs };
}

(async () => {
  // ── 1 · Dirección reparte las sedes ──────────────────────────────
  console.log('\n1 · Dirección: la pantalla de socios');
  {
    const { b, p, errs } = await abrir('ceo');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.click('[data-ir="v-sedes"]');
    await p.waitForTimeout(600);

    si('la sección de socios se ve', await p.locator('#bloque-socios').isVisible());

    const casillas = await p.$$eval('#soc-sedes input[type=checkbox]', (n) => n.map((c) => c.value));
    es('hay una casilla por sede', casillas.length, 2);

    const fichas = await p.$$eval('#lista-socios .ficha strong', (n) => n.map((x) => x.textContent.trim()));
    si('lista los socios que ya tienen acceso', fichas.includes('Ramiro Araujo'), fichas.join(', '));

    const pastillas = await p.$$eval('#lista-socios .ficha .pastilla', (n) => n.map((x) => x.textContent.trim()));
    si('y enseña las sedes de cada uno', pastillas.filter((x) => x === 'Caja Seca').length === 1,
       pastillas.join(' · '));

    // Marcar dos sedes y guardar
    await p.fill('#soc-correo', 'marilyn@credisan.test');
    await p.fill('#soc-nombre', 'Marilyn González');
    await p.check('#soc-sedes input[value="b-css"]');
    await p.click('#form-socio button[type=submit]');
    await p.waitForTimeout(500);

    const env = await p.evaluate(() =>
      (window.__llamadas.find((x) => x[0] === 'guardar_socio') || [])[1]);
    si('se manda el correo y el nombre', env && env.p_email === 'marilyn@credisan.test', env && env.p_email);
    si('y exactamente las sedes marcadas', env && JSON.stringify(env.p_sedes) === '["b-css"]',
       env && JSON.stringify(env.p_sedes));

    // Sin marcar ninguna, ni se molesta al servidor
    await p.evaluate(() => { window.__llamadas.length = 0; });
    await p.fill('#soc-correo', 'nadie@credisan.test');
    await p.fill('#soc-nombre', 'Sin Sedes');
    await p.click('#form-socio button[type=submit]');
    await p.waitForTimeout(400);
    const hubo = await p.evaluate(() => window.__llamadas.some((x) => x[0] === 'guardar_socio'));
    si('un socio sin sedes no llega ni a enviarse', !hubo);

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Administración no reparte accesos ────────────────────────
  console.log('\n2 · Administración: ni ve la pantalla');
  {
    const { b, p, errs } = await abrir('admin');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(400);
    const visible = await p.locator('#bloque-socios').isVisible().catch(() => false);
    si('la sección de socios le está oculta', !visible);
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · El panel que ve un socio ─────────────────────────────────
  console.log('\n3 · El socio: consulta, y sólo eso');
  {
    const { b, p, errs } = await abrir('socio');
    es('se le nombra por su rol', await p.textContent('#mi-rol'), 'Socio · Nacional');

    si('tiene la pestaña de cierres', await p.locator('#nav-cierres').isVisible());
    si('NO tiene «Más» (sedes, horarios, auditoría)',
       !(await p.locator('#nav-mas').isVisible()));
    si('NO se le ofrece reportar una novedad',
       !(await p.locator('#btn-novedad-rapida').isVisible()));

    const sedes = await p.$$eval('#hoy-sede option', (n) => n.map((o) => o.textContent.trim()));
    si('sólo ve las sedes que le corresponden', sedes.length === 1 && sedes[0] === 'Maracaibo',
       sedes.join(', '));

    await p.click('.nav button[data-vista="v-personal"]');
    await p.waitForTimeout(500);
    const acciones = await p.$$eval('#lista-personal .ficha__accion', (n) => n.map((x) => x.textContent.trim()));
    si('en Personal no hay botón de PIN, foto ni editar', acciones.length === 0,
       acciones.join(', ') || 'ninguno');
    si('tampoco el de dar de alta', !(await p.locator('#btn-nuevo-empleado').isVisible()));

    const texto = await p.textContent('#lista-personal');
    si('y no asoma ningún salario', !/salario|\$|USD/i.test(texto));

    await p.click('.nav button[data-vista="v-cierres"]');
    await p.waitForTimeout(500);
    si('no puede lanzar el cálculo de la semana',
       !(await p.locator('#btn-calcular-semana').isVisible()));

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await p.screenshot({ path: 'socio-panel.png', fullPage: true });
    await b.close();
  }

  console.log(fallos ? `\n✖ ${fallos} comprobación(es) fallaron` : '\n✔ PANEL DE SOCIOS — todas las comprobaciones pasaron');
  process.exit(fallos ? 1 : 0);
})();
