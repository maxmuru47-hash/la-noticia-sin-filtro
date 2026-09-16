const { chromium } = require('playwright');
// Ruta al navegador: se toma del entorno si está, y si no se deja que
// Playwright use el suyo. Fijarla a pelo ata la prueba a una máquina.
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-f4.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok = (cond, texto, extra = '') => {
  console.log((cond ? '  ✔ ' : '  ✘ ') + texto + (extra ? '  → ' + extra : ''));
  if (!cond) fallos++;
};

async function abrir(b, rol) {
  const p = await b.newPage({ viewport: { width: 414, height: 900 }, deviceScaleFactor: 2 });
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  p.on('dialog', (d) => d.accept());
  await p.addInitScript((r) => { window.__ROL_PRUEBA = r; }, rol);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  // El panel comprueba si la función del servidor está publicada, para
  // poder explicar por qué nadie tiene PIN. Sin simularla, la petición
  // sale a la red de verdad y ensucia la consola.
  await p.route('**/functions/v1/credisan', (r) => r.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, pimienta: true, sin_conexion: true })
  }));
  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', rol + '@credisan.test');
  await p.fill('#clave', 'secreta');
  await p.click('#btn-entrar');
  await p.waitForTimeout(700);
  return { p, errs };
}

const textoDe = (p, sel) => p.textContent(sel).then((t) => t.replace(/\s+/g, ' ').trim());

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});

  /* ══ 1 · DIRECCIÓN (ceo) ══════════════════════════════════════════ */
  console.log('\n1 · Dirección general');
  {
    const { p, errs } = await abrir(b, 'ceo');
    ok(await p.locator('#pantalla-acceso').isHidden(), 'la pantalla de acceso desaparece');
    ok((await p.$$eval('#hoy-kpis .kpi', (n) => n.length)) === 4, 'cuatro indicadores del día');
    const kpisHoy = await p.$$eval('#hoy-kpis .kpi',
      (n) => n.map((k) => k.querySelector('strong').textContent + ' ' + k.querySelector('span').textContent));
    ok(kpisHoy.join('|') === '1 Presentes|1 Faltantes|1 Retrasados|1 Novedades',
       'los cuatro números salen del servidor', kpisHoy.join(' | '));
    ok((await p.$$eval('#hoy-lista .ficha', (n) => n.length)) === 2, 'dos trabajadores de Maracaibo');
    ok((await textoDe(p, '#hoy-lista')).includes('Sin foto'), 'avisa la marcación sin evidencia');
    ok((await textoDe(p, '#hoy-lista')).includes('+14 min'), 'muestra los minutos de retraso');
    ok(await p.locator('#periodos').isVisible(), 'puede cambiar de período');
    ok(await p.locator('#hoy-sede-caja').isVisible(), 'puede cambiar de sede');
    await p.screenshot({ path: 'pf4-1-hoy-ceo.png', fullPage: true });

    // Cambiar de sede debe recargar contra la otra sede
    await p.selectOption('#hoy-sede', 'b-css');
    await p.waitForTimeout(400);
    ok((await textoDe(p, '#hoy-titulo')).startsWith('Caja Seca'), 'al cambiar de sede cambia el tablero',
       await textoDe(p, '#hoy-titulo'));

    // Período
    await p.click('#periodos button[data-periodo="semana"]');
    await p.waitForTimeout(500);
    ok(await p.locator('#bloque-periodo').isVisible(), 'aparece el comparativo del período');
    ok(await p.locator('#bloque-hoy').isHidden(), 'se oculta el detalle del día');
    ok((await p.$$eval('#periodo-sedes .ficha', (n) => n.length)) === 2, 'compara las dos sedes');
    const kpisPer = await p.$$eval('#periodo-kpis .kpi',
      (n) => n.map((k) => k.querySelector('strong').textContent + ' ' + k.querySelector('span').textContent));
    ok(kpisPer.join('|') === '27 Marcaciones|88.9% Puntualidad|3 Retrasos|0 Ausencias',
       'suma las dos sedes y calcula la puntualidad', kpisPer.join(' | '));
    ok(await p.locator('#aviso-recalculo').isVisible(),
       'advierte que el cero de ausencias no está calculado');
    ok(await p.locator('#btn-recalcular').isVisible(), 'ofrece calcularlo');
    ok((await p.$$eval('#periodo-ranking .ficha', (n) => n.length)) === 3, 'ranking con los tres');
    ok(await p.locator('#hoy-sede-caja').isHidden(),
       'en el período se retira el selector de sede (ahí no filtra nada)');
    const per = await textoDe(p, '#bloque-periodo');
    ok(!/salario|USD|\$\d/i.test(per), 'el período no enseña dinero');
    await p.screenshot({ path: 'pf4-2-periodo-ceo.png', fullPage: true });

    // Y vuelve al pulsar «Hoy»
    await p.click('#periodos button[data-periodo="hoy"]');
    await p.waitForTimeout(400);
    ok(await p.locator('#hoy-sede-caja').isVisible(), 'y vuelve al volver a Hoy');

    // Novedades
    await p.click('.nav button[data-vista="v-novedades"]');
    await p.waitForTimeout(500);
    ok((await p.$$eval('#lista-novedades .novedad', (n) => n.length)) === 1,
       'por defecto sólo las pendientes');
    ok((await p.$$eval('#lista-novedades [data-aprobar]', (n) => n.length)) === 1,
       'dirección puede aprobar');
    await p.click('#filtro-novedades button[data-estado=""]');
    await p.waitForTimeout(400);
    ok((await p.$$eval('#lista-novedades .novedad', (n) => n.length)) === 2, 'el filtro «Todas» las trae');
    await p.screenshot({ path: 'pf4-3-novedades-ceo.png', fullPage: true });

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  /* ══ 2 · JEFE OPERATIVO ═══════════════════════════════════════════ */
  console.log('\n2 · Jefe operativo (sólo Maracaibo, sin dinero)');
  {
    const { p, errs } = await abrir(b, 'supervisor');
    ok((await textoDe(p, '#mi-rol')) === 'Jefe operativo · Maracaibo', 'se identifica su sede',
       await textoDe(p, '#mi-rol'));
    ok(await p.locator('#nav-mas').isHidden(), 'no ve el menú Más (horarios, sedes, auditoría)');
    ok(await p.locator('#nav-cierres').isHidden(), 'no ve Cierres');
    ok(await p.locator('#periodos').isHidden(), 'no ve estadísticas de período');
    ok(await p.locator('#hoy-sede-caja').isHidden(), 'no puede elegir otra sede');
    ok(await p.locator('#btn-nuevo-empleado').isHidden(), 'no da de alta personal');
    ok((await p.$$eval('.nav button:not([hidden])', (n) => n.length)) === 3, 'navegación de tres botones');
    ok((await p.$$eval('#hoy-lista .ficha', (n) => n.length)) === 2, 've a su gente del día');

    const todo = await p.evaluate(() => document.body.innerText);
    ok(!/Silva/.test(todo), 'NINGÚN rastro de la trabajadora de la otra sede');
    ok(!/Caja Seca/.test(todo), 'ni el nombre de la otra sede');
    ok(!/salario|Salario/.test(todo), 'ninguna palabra de salario en pantalla');
    await p.screenshot({ path: 'pf4-4-hoy-jefe.png', fullPage: true });

    // Personal: sin salario ni botón de PIN
    await p.click('.nav button[data-vista="v-personal"]');
    await p.waitForTimeout(500);
    ok(await p.locator('#caja-salario').isHidden(), 'el campo de salario no existe para él');
    ok((await p.$$eval('#lista-personal .ficha', (n) => n.length)) === 2, 'sólo su personal');
    const pers = await p.evaluate(() => document.getElementById('v-personal').innerText);
    ok(!/Silva/.test(pers), 'el listado de personal no filtra a la otra sede');

    // Novedades: puede reportar, no resolver
    await p.click('.nav button[data-vista="v-novedades"]');
    await p.waitForTimeout(500);
    ok((await p.$$eval('#lista-novedades .novedad', (n) => n.length)) === 1, 've la novedad de su sede');
    ok((await p.$$eval('#lista-novedades [data-aprobar]', (n) => n.length)) === 0,
       'NO puede aprobar novedades');
    ok(await p.locator('#btn-nueva-novedad').isVisible(), 'sí puede reportar una');

    await p.click('#btn-nueva-novedad');
    await p.waitForTimeout(400);
    const opciones = await p.$$eval('#nov-empleado option', (n) => n.map((o) => o.textContent));
    ok(opciones.length === 2 && !opciones.join(' ').includes('Silva'),
       'el desplegable de trabajadores es sólo el suyo', opciones.join(', '));
    await p.screenshot({ path: 'pf4-5-novedad-jefe.png', fullPage: true });

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  /* ══ 3 · ADMINISTRADORA ═══════════════════════════════════════════ */
  console.log('\n3 · Administradora (su sede, con datos autorizados)');
  {
    const { p, errs } = await abrir(b, 'admin');
    // Desde la Fase 5 la barra tiene cinco huecos y Horarios y Sedes
    // cuelgan de «Más». Lo que importa no es dónde está el botón, sino
    // que ella pueda llegar y el jefe operativo no.
    ok(await p.locator('#nav-mas').isVisible(), 'sí ve el menú Más');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(250);
    ok(await p.locator('[data-ir="v-horarios"]').isVisible(), 'y desde ahí llega a Horarios');
    ok(await p.locator('[data-ir="v-sedes"]').isVisible(), 'y a Sedes');
    await p.click('.nav button[data-vista="v-hoy"]');
    await p.waitForTimeout(250);
    ok(await p.locator('#periodos').isVisible(), 'sí ve el período');
    ok(await p.locator('#bloque-accesos').isHidden(), 'pero NO reparte accesos al panel');
    ok(await p.locator('#btn-nueva-sede').isHidden(), 'ni crea sedes');

    await p.click('#periodos button[data-periodo="mes"]');
    await p.waitForTimeout(500);
    ok((await p.$$eval('#periodo-sedes .ficha', (n) => n.length)) === 1, 'el período es de su sede sola');
    const per = await p.evaluate(() => document.getElementById('bloque-periodo').innerText);
    ok(!/Caja Seca/.test(per), 'no aparece la otra sede en el comparativo');

    await p.click('.nav button[data-vista="v-personal"]');
    await p.waitForTimeout(400);
    ok(await p.locator('#btn-nuevo-empleado').isVisible(), 'puede dar de alta');
    await p.click('#btn-nuevo-empleado');
    await p.waitForTimeout(300);
    ok(await p.locator('#caja-salario').isVisible(), 'sí tiene el campo de salario (dato autorizado)');
    await p.screenshot({ path: 'pf4-6-admin.png', fullPage: true });

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  await b.close();
  console.log(fallos === 0
    ? '\n✔ PANEL DE FASE 4 — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
