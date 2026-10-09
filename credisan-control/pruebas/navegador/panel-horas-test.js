/* Panel · las cuatro horas de la jornada
   ---------------------------------------------------------------------
   El panel enseñaba dos horas de cada día: a la que llegó y a la que se
   fue. Con eso no se puede dirigir un turno. Falta el mediodía: a qué
   hora se fue a almorzar y a qué hora volvió al puesto —que es donde se
   va el tiempo de verdad y lo que un jefe operativo o un gerente de
   operaciones necesita mirar.

   Esta batería exige cuatro cosas:

   1. Que las CUATRO horas se vean, en «Hoy» y en cualquier día pasado.
   2. Que sean las de la SEDE. El navegador de la prueba corre en Madrid
      a propósito: si el panel pintara la hora del navegador, un almuerzo
      de las 12:41 de Maracaibo se leería «18:41» y nadie lo notaría
      hasta que alguien discutiera un descuento.
   3. Que las vea el jefe operativo y las vea el socio. Es información de
      operación, no de nómina.
   4. Que ninguno de ellos reciba datos de otra sede, ni la fotografía de
      nadie: eso sigue siendo de dirección y administración.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-horas.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://control.sinfiltroconmax.com/api',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrir(rol = 'ceo') {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  // Madrid, no Caracas: la hora de la sede no puede depender de dónde
  // esté el teléfono de quien mira.
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 }, timezoneId: 'Europe/Madrid' });
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

const hora = (p, vista, quien, cual) => p
  .locator(`${vista} .ficha:has-text("${quien}") [data-marca="${cual}"] .cuatro__hr`)
  .first().textContent();

const irAHoras = async (p) => {
  await p.click('.nav button[data-vista="v-horas"]');
  await p.waitForTimeout(700);
};

(async () => {
  // ── 1 · Dirección: las cuatro horas en «Hoy» ──────────────────────
  console.log('\n1 · «Hoy» enseña las cuatro horas, no dos');
  {
    const { b, p, errs } = await abrir('ceo');
    const texto = await p.textContent('#hoy-lista');

    si('aparece la etiqueta de la entrada',  /ENTRADA|Entrada/.test(texto));
    si('aparece la del almuerzo',            /ALMUERZO|Almuerzo/.test(texto));
    si('aparece la del regreso',             /REGRESO|Regreso/.test(texto));
    si('aparece la de la salida',            /SALIDA|Salida/.test(texto));

    si('la entrada de Ana', (await hora(p, '#hoy-lista', 'Ana Pérez', 'entrada')).trim() === '08:02');
    si('LA HORA EN QUE SE FUE A ALMORZAR',
       (await hora(p, '#hoy-lista', 'Ana Pérez', 'salida_almuerzo')).trim() === '12:41');
    si('LA HORA EN QUE VOLVIÓ DEL ALMUERZO',
       (await hora(p, '#hoy-lista', 'Ana Pérez', 'regreso_almuerzo')).trim() === '14:22');
    si('la salida de Ana', (await hora(p, '#hoy-lista', 'Ana Pérez', 'salida')).trim() === '18:07');

    // La hora es la de la sede. En Madrid ese almuerzo serían las 18:41.
    si('la hora es la de la SEDE, no la del navegador',
       texto.includes('12:41') && !texto.includes('18:41'));

    si('los minutos de diferencia se dicen en cada casilla',
       texto.includes('+41 min') && texto.includes('+22 min'));

    // El ámbar lo pone el veredicto del motor, no el panel. Y el motor
    // dice que irse tarde a almorzar no es falta —se trabajó más antes
    // de ir— mientras que volver tarde sí lo es. Si el panel pintara su
    // propia regla, acabaría contradiciendo al cálculo del cierre.
    const ida = await p.getAttribute(
      '#hoy-lista .ficha:has-text("Ana Pérez") [data-marca="salida_almuerzo"]', 'data-tono');
    si('irse tarde a almorzar no se pinta como falta', ida !== 'tarde', ida);
    const vuelta = await p.getAttribute(
      '#hoy-lista .ficha:has-text("Ana Pérez") [data-marca="regreso_almuerzo"]', 'data-tono');
    si('volver tarde del almuerzo sí queda marcado', vuelta === 'tarde', vuelta);

    // Luis entró y se fue a almorzar: no ha vuelto. Eso se ve.
    si('de quien no ha vuelto del almuerzo, el regreso queda en blanco',
       (await hora(p, '#hoy-lista', 'Luis Rojas', 'regreso_almuerzo')).trim() === '—');
    si('y su salida también',
       (await hora(p, '#hoy-lista', 'Luis Rojas', 'salida')).trim() === '—');

    // Quien aún no ha llegado enseña la hora que le toca, no un guion.
    si('de quien no ha marcado, la entrada enseña su hora prevista',
       (await hora(p, '#hoy-lista', 'Zoe Zamora', 'entrada')).trim() === '08:00');

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · La vista «Horas»: cualquier día, no sólo hoy ──────────────
  console.log('\n2 · «Horas»: el historial, día por día');
  {
    const { b, p, errs } = await abrir('ceo');
    const F = await p.evaluate(() => window.__FECHAS);

    si('hay un botón «Horas» en la navegación',
       await p.locator('.nav button[data-vista="v-horas"]').isVisible());

    await irAHoras(p);
    si('la vista abre en el día de hoy',
       (await p.textContent('#horas-fecha')).includes('hoy'),
       (await p.textContent('#horas-fecha')).trim());
    si('no se puede caminar hacia el futuro',
       await p.locator('#horas-siguiente').isDisabled());
    si('y «volver a hoy» no estorba estando en hoy',
       !(await p.locator('#horas-hoy').isVisible()));
    si('con las cuatro horas de hoy',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'salida_almuerzo')).trim() === '12:41');

    // Un día atrás
    await p.click('#horas-anterior');
    await p.waitForTimeout(600);
    si('un día atrás trae las horas de ayer',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'entrada')).trim() === '07:58');
    si('incluido su almuerzo de ayer',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'salida_almuerzo')).trim() === '12:03');
    si('ya no dice «hoy»', !(await p.textContent('#horas-fecha')).includes('hoy'));
    si('ahora sí se ofrece volver a hoy', await p.locator('#horas-hoy').isVisible());
    si('y el día siguiente deja de estar cerrado',
       !(await p.locator('#horas-siguiente').isDisabled()));

    // Zoe ya no trabaja ahí, pero ayer marcó: su historial no se borra.
    const ayer = await p.textContent('#horas-lista');
    si('quien ya no trabaja ahí aparece el día que marcó', ayer.includes('Zoe Zamora'));
    si('y se dice que ya no trabaja ahí', /ya no trabaja/.test(ayer));

    await p.click('#horas-hoy');
    await p.waitForTimeout(600);
    const hoy = await p.textContent('#horas-lista');
    si('y no llena la lista de hoy, donde no marcó', !hoy.includes('Zoe Zamora'));
    si('vuelve a hoy', (await p.textContent('#horas-fecha')).includes('hoy'));

    // Las dos últimas semanas de una persona
    await p.locator('#horas-lista .ficha:has-text("Ana Pérez") [data-quincena]').click();
    await p.waitForTimeout(600);
    const dias = await p.locator(`[data-historial="e1"] .historial__dia`).count();
    // Los catorce días ANTERIORES: el que se mira ya está pintado encima.
    si('sus días marcados de las dos semanas anteriores', dias === 2, dias);
    si('y el día que ya se está viendo no se repite ahí dentro',
       !(await p.textContent('[data-historial="e1"]')).includes('18:07'));
    si('y nada de hace más de dos semanas',
       !(await p.textContent('[data-historial="e1"]')).includes('09:30'));
    const quincena = await p.textContent('[data-historial="e1"]');
    si('cada día del historial con sus cuatro horas',
       quincena.includes('07:58') && quincena.includes('12:03')
       && quincena.includes('14:00') && quincena.includes('17:55'));

    await p.locator('#horas-lista .ficha:has-text("Ana Pérez") [data-quincena]').click();
    await p.waitForTimeout(300);
    si('y se puede cerrar', await p.locator('[data-historial="e1"]').isHidden());

    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · El atajo al horario de la sede ────────────────────────────
  console.log('\n3 · Quien puede cambiar el horario, llega a él de un toque');
  {
    const { b, p, errs } = await abrir('admin');
    await irAHoras(p);
    si('administración ve el atajo al horario de la sede',
       await p.locator('#horas-horario').isVisible());
    await p.click('#horas-horario');
    await p.waitForTimeout(600);
    si('y lleva al horario', await p.locator('#v-horarios').isVisible());
    si('con la sede ya puesta', (await p.inputValue('#horario-sede')) === 'b-mcb');
    const horario = await p.textContent('#lista-horario');
    si('donde están las cuatro horas de cada día',
       /Entrada/.test(horario) && /Salida a almuerzo/.test(horario)
       && /Regreso/.test(horario) && /Salida/.test(horario));
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · El jefe operativo: ve las horas, no las caras ─────────────
  console.log('\n4 · El jefe operativo ve las cuatro horas de SU sede');
  {
    const { b, p, errs } = await abrir('supervisor');
    si('tiene el botón «Horas»',
       await p.locator('.nav button[data-vista="v-horas"]').isVisible());
    await irAHoras(p);

    si('ve la hora de entrada',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'entrada')).trim() === '08:02');
    si('VE LA SALIDA AL ALMUERZO',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'salida_almuerzo')).trim() === '12:41');
    si('VE EL REGRESO DEL ALMUERZO',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'regreso_almuerzo')).trim() === '14:22');
    si('y la salida',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'salida')).trim() === '18:07');

    const texto = await p.textContent('#horas-lista');
    si('de la otra sede no ve a nadie', !texto.includes('Mará Silva'));
    si('ni una sola de sus horas', !texto.includes('07:30') && !texto.includes('19:00'));
    si('no se le ofrece cambiar el horario de la sede',
       !(await p.locator('#horas-horario').isVisible()));
    si('ni la fotografía de nadie',
       (await p.locator('#v-horas [data-ver-foto]').count()) === 0);
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 5 · El socio: consulta, y nada más ────────────────────────────
  console.log('\n5 · El socio también mira las horas');
  {
    const { b, p, errs } = await abrir('socio');
    await irAHoras(p);
    si('ve las cuatro horas',
       (await hora(p, '#horas-lista', 'Ana Pérez', 'regreso_almuerzo')).trim() === '14:22');
    si('sin el atajo al horario', !(await p.locator('#horas-horario').isVisible()));
    si('sin errores de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos ? `\n✖ ${fallos} comprobación(es) fallaron`
                     : '\n✔ LAS CUATRO HORAS — todas las comprobaciones pasaron');
  process.exit(fallos ? 1 : 0);
})();
