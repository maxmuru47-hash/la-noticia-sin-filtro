/* Panel · horario propio de un trabajador
   ---------------------------------------------------------------------
   La seguridad de verdad está en la base de datos y tiene su propia
   batería (supabase/tests/11_horarios.sql). Aquí se comprueba lo otro:

     1. Que la administradora encuentre el botón donde tiene que estar
        —en la ficha de la persona— y que lo que se manda al servidor
        sea exactamente lo que marcó en pantalla.
     2. Que al jefe operativo y al socio el panel NO les ofrezca un botón
        que la base les va a rechazar. Ofrecer un botón que siempre falla
        es un panel roto.
     3. Que en la lista se vea de un vistazo a quién se le mide distinto,
        sin tener que abrir su ficha.
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
  // La lista de personal comprueba si la función del servidor está
  // publicada, porque Ana no tiene PIN. Sin contestar aquí, la prueba
  // saldría a internet de verdad y contaría el fallo de red como error.
  await p.route('**/functions/v1/credisan', (r) =>
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));
  // El panel consulta el estado del respaldo, que en producción SÍ
  // existe. Sin contestarlo aquí, la prueba cuenta su 404 como un error
  // del programa — que es justamente lo que no es.
  await p.route('**/estado-respaldo.json', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ultimo: new Date().toISOString(), ok: true, copias: 14 })
  }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(800);
  await p.click('.nav button[data-vista="v-personal"]');
  await p.waitForTimeout(700);
  return { b, p, errs };
}

(async () => {
  // ── 1 · La administradora: el botón, el formulario y lo que se manda ──
  console.log('\n1 · Administración: poner un horario a alguien');
  {
    const { b, p, errs } = await abrir('admin');

    const botones = await p.$$eval('#lista-personal [data-horario]', (n) => n.length);
    es('hay un botón «Horario» por trabajador', botones, 2);

    const pastillas = await p.$$eval('#lista-personal .pastilla--horario', (n) => n.length);
    es('y sólo uno sale marcado con horario propio', pastillas, 1);

    // El marcado tiene que ser Luis, no cualquiera
    const deLuis = await p.$$eval('#lista-personal .ficha', (fichas) => fichas
      .filter((f) => f.querySelector('.pastilla--horario'))
      .map((f) => f.querySelector('strong').textContent.trim()));
    es('el marcado es quien tiene el horario distinto', deLuis.join(), 'Luis Rojas');

    // Abrir el de Ana, que NO tiene horario propio
    await p.click('#lista-personal .ficha:has-text("Ana Pérez") [data-horario]');
    await p.waitForTimeout(500);

    si('se abre el formulario de su horario', await p.locator('#form-horario-propio').isVisible());
    si('dice con qué horario se le mide hoy',
       (await p.textContent('#horario-propio-origen')).includes('horario general'),
       await p.textContent('#horario-propio-origen'));
    si('a quien no lo tiene no se le ofrece quitarlo',
       !(await p.locator('#btn-quitar-horario-propio').isVisible()));

    const dias = await p.$$eval('#dias-horario-propio .dia', (n) => n.length);
    es('están los siete días, no sólo los laborables', dias, 7);

    // Arranca con el horario de la sede: cambiar una hora es cambiar una hora
    const entradaLunes = await p.inputValue('#dias-horario-propio .dia[data-dia="1"] [data-campo="entrada"]');
    es('viene relleno con el horario que hoy se le aplica', entradaLunes, '08:00');

    const domingoApagado = await p.locator('#dias-horario-propio .dia[data-dia="0"] .dia__horas').isHidden();
    si('el domingo viene apagado y sin horas a la vista', domingoApagado);

    // Cambiar el lunes: entrar a las 09:00 y jornada corrida hasta las 15:00
    await p.fill('#dias-horario-propio .dia[data-dia="1"] [data-campo="entrada"]', '09:00');
    await p.check('#dias-horario-propio .dia[data-dia="1"] [data-campo="corrida"]');
    await p.fill('#dias-horario-propio .dia[data-dia="1"] [data-campo="salida"]', '15:00');
    await p.waitForTimeout(150);

    si('al marcar jornada corrida desaparecen las horas de almuerzo',
       await p.locator('#dias-horario-propio .dia[data-dia="1"] [data-almuerzo]').first().isHidden());

    await p.click('#form-horario-propio button[type=submit]');
    await p.waitForTimeout(600);

    const env = await p.evaluate(() =>
      (window.__llamadas.filter((x) => x[0] === 'dar_horario_propio').pop() || [])[1]);
    si('se manda al servidor', !!env, JSON.stringify(env || null));
    if (env) {
      es('con los siete días', env.p_dias.length, 7);
      const lunes = env.p_dias.find((d) => d.dia === 1);
      es('el lunes va con la hora escrita', lunes.entrada, '09:00');
      es('marcado como jornada corrida', lunes.continua, 'true');
      si('y SIN horas de almuerzo: si viajaran, la base lo rechazaría',
         lunes.salida_almuerzo === null && lunes.regreso === null,
         JSON.stringify(lunes));
      const domingo = env.p_dias.find((d) => d.dia === 0);
      si('el día de descanso viaja sin horas', domingo.trabaja === false && domingo.entrada === null,
         JSON.stringify(domingo));
      si('va el trabajador que se abrió, no otro', env.p_employee === 'e1', env.p_employee);
    }

    si('el formulario se cierra al guardar', await p.locator('#form-horario-propio').isHidden());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Quitarlo: se ofrece sólo a quien lo tiene ──────────────────
  console.log('\n2 · Devolver a alguien al horario de su sede');
  {
    const { b, p, errs } = await abrir('admin');
    await p.click('#lista-personal .ficha:has-text("Luis Rojas") [data-horario]');
    await p.waitForTimeout(500);

    si('a quien sí lo tiene se le ofrece quitarlo',
       await p.locator('#btn-quitar-horario-propio').isVisible());
    si('y el formulario lo dice', (await p.textContent('#horario-propio-origen')).includes('propio'),
       await p.textContent('#horario-propio-origen'));

    const entradaLunes = await p.inputValue('#dias-horario-propio .dia[data-dia="1"] [data-campo="entrada"]');
    es('relleno con SU horario, no con el de la sede', entradaLunes, '09:00');
    si('y con el almuerzo oculto, porque hace jornada corrida',
       await p.locator('#dias-horario-propio .dia[data-dia="1"] [data-almuerzo]').first().isHidden());

    await p.click('#btn-quitar-horario-propio');
    await p.waitForTimeout(700);

    const env = await p.evaluate(() =>
      (window.__llamadas.filter((x) => x[0] === 'quitar_horario_propio').pop() || [])[1]);
    si('se pide quitarlo, y de quien se abrió', env && env.p_employee === 'e2', JSON.stringify(env || null));

    const pastillas = await p.$$eval('#lista-personal .pastilla--horario', (n) => n.length);
    es('y la lista deja de marcarlo', pastillas, 0);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · A quien no le toca, el panel no se lo ofrece ───────────────
  console.log('\n3 · Jefe operativo y socio: ni el botón');
  for (const rol of ['supervisor', 'socio']) {
    const { b, p, errs } = await abrir(rol);
    const botones = await p.$$eval('#lista-personal [data-horario]', (n) => n.length);
    es(`${rol}: no se le ofrece el botón «Horario»`, botones, 0);

    const pedidas = await p.evaluate(() =>
      window.__llamadas.filter((x) => x[0] === 'dar_horario_propio' || x[0] === 'quitar_horario_propio').length);
    es(`${rol}: el panel no intenta cambiar ningún horario`, pedidas, 0);

    // Pero mirar sí: la lista le enseña a quién se le mide distinto
    const pastillas = await p.$$eval('#lista-personal .pastilla--horario', (n) => n.length);
    si(`${rol}: sí ve marcado a quien tiene horario propio`, pastillas === 1, String(pastillas));
    si(`${rol}: sin un solo error de JavaScript`, errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — el horario propio se pone donde debe y sólo quien debe\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
