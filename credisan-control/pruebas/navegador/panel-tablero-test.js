/* Panel · el tablero de puntualidad por sede
   ---------------------------------------------------------------------
   La pregunta que se hace todos los lunes: ¿cómo va cada sede? Hasta
   ahora sólo la podían mirar dirección y administración, y sin poder
   elegir una sede: salían todas o nada.

   Esta batería exige cuatro cosas:

   1. Que estén las dos formas de mirarlo: todas las sedes juntas para
      compararlas, y una sola cuando ya se sabe cuál interesa.
   2. Que el filtro FILTRE de verdad — que la petición viaje con esa sede
      y no se quede en un adorno de pantalla.
   3. Que lo vean los socios y las administradoras, cada uno con sus
      sedes y con ninguna más.
   4. Que ahí dentro no haya un solo dato de nómina: es lo que permite
      enseñárselo a un socio. Y que el jefe operativo siga sin entrar,
      que su panel es el del día.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-tablero.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://control.sinfiltroconmax.com/api',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrir(rol = 'ceo') {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 } });
  const p = await ctx.newPage();
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());

  await p.addInitScript(`window.__ROL_PRUEBA = ${JSON.stringify(rol)};`);
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
  await p.waitForTimeout(900);
  return { b, p, errs };
}

const aSemana = async (p) => {
  await p.click('#periodos button[data-periodo="semana"]');
  await p.waitForTimeout(700);
};
const ultimaPeticion = (p, nombre) => p.evaluate((n) =>
  (window.__llamadas.filter((x) => x[0] === n).pop() || [])[1], nombre);

(async () => {
  // ── 1 · Dirección: las tres sedes, ordenadas por puntualidad ──────
  console.log('\n1 · Todas las sedes juntas, para compararlas');
  {
    const { b, p, errs } = await abrir('ceo');
    await aSemana(p);

    const fichas = await p.$$eval('#periodo-sedes .ficha', (n) => n.length);
    si('salen las tres sedes', fichas === 3, fichas);

    const orden = await p.$$eval('#periodo-sedes .sede__codigo', (n) => n.map((x) => x.textContent).join(','));
    // Por código serían CSS, MCB, MCY. Por puntualidad son MCB (94,2),
    // MCY (81,0) y CSS (68,4): la peor, la última.
    si('ordenadas por puntualidad y no por código', orden === 'MCB,MCY,CSS', orden);

    const texto = await p.textContent('#periodo-sedes');
    si('cada sede con su puntualidad', texto.includes('94.2%') && texto.includes('68.4%'));
    si('y con sus cifras detrás', texto.includes('240') && texto.includes('31'));

    const kpis = await p.$$eval('#periodo-kpis .kpi',
      (n) => n.map((k) => k.querySelector('strong').textContent + ' ' + k.querySelector('span').textContent));
    si('la puntualidad abre el tablero', /Puntualidad/.test(kpis[0]), kpis.join(' | '));

    // El color de la barra y el de la cifra tienen que decir lo mismo.
    const peor = await p.getAttribute('#periodo-sedes .ficha:last-child .barra', 'data-t');
    const peorPct = await p.getAttribute('#periodo-sedes .ficha:last-child .sede__pct', 'data-t');
    si('una puntualidad de 68% se marca en rojo', peor === 'mal', peor);
    si('y la barra dice lo mismo que la cifra', peor === peorPct);

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Una sola sede, cuando ya se sabe cuál ─────────────────────
  console.log('\n2 · El filtro de sede filtra de verdad');
  {
    const { b, p, errs } = await abrir('ceo');
    await aSemana(p);

    si('el selector está, y abre por «todas»',
       (await p.inputValue('#periodo-sede')) === '');

    await p.selectOption('#periodo-sede', 'b-css');
    await p.waitForTimeout(700);

    // Lo que importa no es lo que se pinta, sino lo que se PIDE.
    const pedido = await ultimaPeticion(p, 'tablero_periodo');
    si('la petición viaja con esa sede', pedido.p_branch === 'b-css', pedido.p_branch);
    const pedidoTop = await ultimaPeticion(p, 'ranking_puntualidad');
    si('y el ranking también, no se queda en todas', pedidoTop.p_branch === 'b-css', pedidoTop.p_branch);

    const fichas = await p.$$eval('#periodo-sedes .ficha', (n) => n.length);
    si('queda una sola sede', fichas === 1, fichas);
    const texto = await p.textContent('#periodo-sedes');
    si('la elegida', texto.includes('Caja Seca'));
    si('y ninguna otra', !texto.includes('Maracaibo') && !texto.includes('Maracay'));

    await p.selectOption('#periodo-sede', '');
    await p.waitForTimeout(700);
    si('y se puede volver a verlas todas',
       (await p.$$eval('#periodo-sedes .ficha', (n) => n.length)) === 3);

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · El socio ──────────────────────────────────────────────────
  console.log('\n3 · El socio ve el tablero, de sus sedes');
  {
    const { b, p, errs } = await abrir('socio');
    si('tiene el selector de período', await p.locator('#periodos').isVisible());
    await aSemana(p);

    const texto = await p.textContent('#periodo-sedes');
    si('ve sus dos sedes', texto.includes('Maracaibo') && texto.includes('Maracay'));
    si('y NO la que no le asignaron', !texto.includes('Caja Seca'));
    si('con la puntualidad de cada una, siempre con un decimal',
       texto.includes('94.2%') && texto.includes('81.0%'));

    const todo = await p.evaluate(() => document.body.innerText);
    si('ni una palabra de dinero en el tablero',
       !/salario|sueldo|nómina|USD|\$\d/i.test(todo));
    si('no se le ofrece recalcular el período',
       !(await p.locator('#btn-recalcular').isVisible()));

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · La administradora ─────────────────────────────────────────
  console.log('\n4 · La administradora, de su sede');
  {
    const { b, p, errs } = await abrir('admin');
    si('tiene el selector de período', await p.locator('#periodos').isVisible());
    await aSemana(p);
    const texto = await p.textContent('#periodo-sedes');
    si('ve su sede', texto.includes('Maracaibo'));
    si('y ninguna otra', !texto.includes('Caja Seca') && !texto.includes('Maracay'));
    si('con una sola sede, el selector no estorba',
       !(await p.locator('#periodo-sede-caja').isVisible()));
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 5 · El jefe operativo sigue fuera ─────────────────────────────
  console.log('\n5 · El jefe operativo sigue con el panel del día');
  {
    const { b, p, errs } = await abrir('supervisor');
    si('no ve el selector de período', await p.locator('#periodos').isHidden());
    si('ni el tablero', await p.locator('#bloque-periodo').isHidden());
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos ? `\n✖ ${fallos} comprobación(es) fallaron`
                     : '\n✔ TABLERO DE PUNTUALIDAD — todas las comprobaciones pasaron');
  process.exit(fallos ? 1 : 0);
})();
