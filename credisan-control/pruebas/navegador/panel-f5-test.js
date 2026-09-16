const { chromium } = require('playwright');
const fs = require('fs');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const MOCK = fs.readFileSync('mock-f5.js', 'utf8');
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
  await p.addInitScript((r) => { window.__ROL_PRUEBA = r; }, rol);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', rol + '@credisan.test');
  await p.fill('#clave', 'secreta');
  await p.click('#btn-entrar');
  await p.waitForTimeout(700);
  return { p, errs };
}

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});

  /* ══ 1 · ADMINISTRADORA: el cierre semanal ════════════════════════ */
  console.log('\n1 · Cierre semanal (administradora)');
  {
    const { p, errs } = await abrir(b, 'admin');
    p.on('dialog', (d) => d.accept('Semana de inventario'));

    ok((await p.$$eval('.nav button:not([hidden])', (n) => n.length)) === 5,
       'la barra inferior tiene cinco botones, no siete');
    ok(await p.locator('#nav-cierres').isVisible(), 'aparece Cierres');
    ok(await p.locator('#nav-mas').isVisible(), 'aparece Más');

    await p.click('.nav button[data-vista="v-cierres"]');
    await p.waitForTimeout(500);

    const semana = await p.inputValue('#cierre-semana');
    const dow = new Date(semana + 'T12:00:00').getDay();
    ok(dow === 1, 'la semana por defecto empieza en lunes', semana + ' (día ' + dow + ')');
    ok(new Date(semana) < new Date(), 'y es una semana ya pasada, no la actual');

    const vacio = await p.textContent('#lista-cierres');
    ok(/todavía no se ha calculado/.test(vacio), 'dice que la semana no se ha calculado aún');
    ok((await p.textContent('#v-cierres')).includes('mide e informa'),
       'la pantalla declara que el sistema no descuenta');

    await p.click('#btn-calcular-semana');
    await p.waitForTimeout(600);
    ok((await p.$$eval('#lista-cierres .novedad', (n) => n.length)) === 2, 'salen los dos cierres');
    ok((await p.$$eval('#cierre-kpis .kpi', (n) => n.length)) === 4, 'cuatro indicadores');
    const kpis = await p.$$eval('#cierre-kpis .kpi',
      (n) => n.map((k) => k.querySelector('strong').textContent + ' ' + k.querySelector('span').textContent));
    ok(kpis.join('|') === '2 Trabajadores|90% Asistencia|0h 37m Retraso acumulado|1 Sin revisar',
       'los indicadores salen del servidor y los minutos se muestran en horas', kpis.join(' | '));
    ok((await p.textContent('#panel-aviso')).includes('ya revisado'),
       'avisa de que respetó los cierres ya revisados');

    const texto = await p.evaluate(() => document.getElementById('v-cierres').innerText);
    ok(!/salario|\$|USD/i.test(texto), 'NO aparece ni una cifra de dinero');
    ok(/Revisado por Administración Maracaibo/.test(texto), 'consta quién revisó');
    await p.screenshot({ path: 'pf5-1-cierres.png', fullPage: true });

    // Botones según el estado
    ok((await p.$$eval('#lista-cierres [data-aprobar-cierre]', (n) => n.length)) === 1,
       'sólo el pendiente ofrece aprobar');
    ok((await p.$$eval('#lista-cierres [data-reabrir]', (n) => n.length)) === 1,
       'el ya aprobado ofrece reabrir');

    await p.click('[data-observar]');
    await p.waitForTimeout(500);
    const rev = await p.evaluate(() => window.__ULTIMA_REVISION);
    ok(rev && rev.p_estado === 'con_observacion' && rev.p_nota === 'Semana de inventario',
       'la observación viaja con su explicación', JSON.stringify(rev));

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  /* ══ 2 · MÁS, auditoría y reportes ════════════════════════════════ */
  console.log('\n2 · Más · auditoría y reportes');
  {
    const { p, errs } = await abrir(b, 'admin');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(300);
    // Se comprueban los destinos que esta fase necesita, no cuántos hay:
    // contar obliga a tocar esta prueba cada vez que se añade uno.
    for (const destino of ['v-horarios', 'v-sedes', 'v-auditoria', 'v-reportes']) {
      ok(await p.locator(`[data-ir="${destino}"]`).isVisible(),
         `el menú Más lleva a ${destino.replace('v-', '')}`);
    }
    await p.screenshot({ path: 'pf5-2-mas.png', fullPage: true });

    await p.click('[data-ir="v-auditoria"]');
    await p.waitForTimeout(500);
    ok(await p.locator('#v-auditoria').isVisible(), 'entra en Auditoría');
    ok((await p.getAttribute('#nav-mas', 'aria-current')) === 'true',
       'Más se queda encendido dentro de una subvista');
    ok((await p.$$eval('#lista-auditoria .ficha', (n) => n.length)) === 2, 'dos movimientos');

    const aud = await p.evaluate(() => document.getElementById('lista-auditoria').innerText);
    ok(/Se revisó un cierre semanal/.test(aud), 'la acción se lee en castellano, no «weekly_closures.update»');
    ok(!/weekly_closures\.update/.test(aud), 'y el código crudo no se enseña');
    ok(/estado/.test(aud) && /pendiente/.test(aud) && /aprobado/.test(aud),
       'muestra el antes y el después del cambio');
    ok(!/\bstatus\b/.test(aud) && !/\bnote\b/.test(aud),
       'los nombres de columna salen en castellano, no en inglés técnico');
    ok(!/"aprobado"/.test(aud), 'y los valores sin comillas de JSON');
    ok(/observación/.test(aud), 'traduce también «note»');
    ok(!/Caja Seca/.test(aud), 'la administradora no ve el rastro de la otra sede');
    await p.screenshot({ path: 'pf5-3-auditoria.png', fullPage: true });

    // Volver
    await p.click('#v-auditoria [data-volver]');
    await p.waitForTimeout(300);
    ok(await p.locator('#v-mas').isVisible(), 'el botón «← Más» vuelve al menú');

    // Reportes: descarga real
    await p.click('[data-ir="v-reportes"]');
    await p.waitForTimeout(300);
    await p.fill('#rep-desde', '2026-09-01');
    await p.fill('#rep-hasta', '2026-09-07');
    const [descarga] = await Promise.all([
      p.waitForEvent('download', { timeout: 5000 }),
      p.click('#btn-exportar-reporte')
    ]);
    const ruta = await descarga.path();
    const csv = fs.readFileSync(ruta, 'utf8');
    ok(descarga.suggestedFilename() === 'asistencia-2026-09-01-a-2026-09-07.csv',
       'el archivo se llama por su rango', descarga.suggestedFilename());
    ok(csv.charCodeAt(0) === 0xFEFF, 'lleva BOM, para que Excel respete los acentos');
    // Quitar el BOM antes de comparar: si no, la cabecera "empieza" por él.
    const cabecera = csv.replace(/^\ufeff/, '').split('\r\n')[0];
    ok(cabecera.startsWith('Fecha;Sede;Código;Trabajador'),
       'cabecera con punto y coma (Excel en español)', cabecera.slice(0, 45));
    ok(/Pérez/.test(csv), 'lleva los datos');
    ok(!/V-1|V-2|V-3/.test(csv), 'NO lleva cédulas');
    ok(!/salario|weekly_base/i.test(csv), 'NO lleva salarios');
    ok(!/Silva/.test(csv), 'NO lleva a la trabajadora de la otra sede');
    console.log('    · CSV:', JSON.stringify(csv.split('\r\n')[1].slice(0, 70)) + '…');

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  /* ══ 3 · JEFE OPERATIVO: nada de esto es suyo ═════════════════════ */
  console.log('\n3 · Jefe operativo');
  {
    const { p, errs } = await abrir(b, 'supervisor');
    ok(await p.locator('#nav-cierres').isHidden(), 'no ve Cierres');
    ok(await p.locator('#nav-mas').isHidden(), 'no ve Más');
    ok((await p.$$eval('.nav button:not([hidden])', (n) => n.length)) === 3,
       'sigue con tres botones');

    const todo = await p.evaluate(() => document.body.innerText);
    ok(!/Cierre semanal|Auditoría|Reportes/.test(todo), 'ni el nombre de esas pantallas aparece');
    ok(!/Silva|Caja Seca/.test(todo), 'ni rastro de la otra sede');
    await p.screenshot({ path: 'pf5-4-jefe.png', fullPage: true });

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  /* ══ 4 · DIRECCIÓN: lo ve todo ════════════════════════════════════ */
  console.log('\n4 · Dirección general');
  {
    const { p, errs } = await abrir(b, 'ceo');
    await p.click('.nav button[data-vista="v-mas"]');
    await p.waitForTimeout(250);
    await p.click('[data-ir="v-auditoria"]');
    await p.waitForTimeout(500);
    ok((await p.$$eval('#lista-auditoria .ficha', (n) => n.length)) === 3,
       'dirección ve también lo global');
    const aud = await p.evaluate(() => document.getElementById('lista-auditoria').innerText);
    ok(/Se creó una sede/.test(aud), 'incluido el rastro de creación de sedes');

    const opciones = await p.$$eval('#audit-sede option', (n) => n.map((o) => o.textContent));
    ok(opciones[0] === 'Todas las sedes', 'puede auditar todas las sedes a la vez', opciones.join(', '));

    ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
    await p.close();
  }

  await b.close();
  console.log(fallos === 0
    ? '\n✔ PANEL DE FASE 5 — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
