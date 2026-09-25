/* Terminal · cuándo se pierde la vinculación, y cuándo NO
   ---------------------------------------------------------------------
   En Caja Seca el terminal «se salió» y volvió a pedir un código. Cuando
   eso pasa, en esa sede NO MARCA NADIE hasta que alguien con acceso al
   panel genere un código nuevo — y un mostrador no siempre tiene a esa
   persona cerca.

   Tiene sentido cuando la vinculación se perdió de verdad: alguien la
   retiró, o vinculó otro aparato a ese mismo terminal. Ahí hay que
   volver a emparejar.

   No lo tiene si el terminal sólo está DESACTIVADO. Su llave sigue
   siendo buena; en cuanto lo activen podría seguir marcando solo.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-pasar-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

// `motivoPin` es lo que contesta el servidor al mandar el PIN.
async function abrir(motivoPin) {
  const b = await chromium.launch({
    ...(NAVEGADOR ? { executablePath: NAVEGADOR } : {}),
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-capture']
  });
  const ctx = await b.newContext({
    viewport: { width: 414, height: 820 }, permissions: ['camera'],
    baseURL: 'http://localhost:8099', serviceWorkers: 'block'
  });
  const p = await ctx.newPage();
  // Dos de estas pruebas provocan un 401 A PROPÓSITO, y el navegador lo
  // anota como error de consola. Eso no es un fallo del programa: es la
  // condición que se está simulando.
  const errs = [];
  p.on('console', (m) => {
    const t = m.text();
    if (m.type() === 'error' && !/Failed to load resource/.test(t)) errs.push(t);
  });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) => {
    const c = JSON.parse(r.request().postData());
    const R = (o, s = 200) => r.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(o) });
    if (c.accion === 'emparejar') {
      return R({ ok: true, terminal: 'CSS-01', sede: 'Caja Seca',
                 branch_id: 'b-css', token: 'token-de-prueba-largo-123456', firma: 'firma' });
    }
    if (c.accion === 'pin') {
      return motivoPin ? R({ ok: false, motivo: motivoPin }, 401)
                       : R({ ok: true, empleado_id: 'e1', nombre: 'Ana Pérez', cargo: 'Cajera',
                            sede: 'Caja Seca', evento: 'salida', es_llegada: false,
                            ticket: 'tk-1', ticket_vence: new Date(Date.now() + 60000).toISOString() });
    }
    return R({ ok: false, motivo: 'ACCION_DESCONOCIDA' });
  });

  await p.goto('/kiosk/index.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(400);
  await p.fill('#e-terminal', 'CSS-01');
  await p.fill('#e-codigo', 'AB3K9XQ2');
  await p.fill('#e-dispositivo', 'Tableta de Caja Seca');
  await p.click('#btn-emparejar');
  await p.waitForTimeout(500);

  for (const d of '481902') await p.click(`.k-tecla[data-d="${d}"]`);
  await p.waitForTimeout(3200);           // el borrado tarda 2,5 s en llevar a emparejar

  const guardado = await p.evaluate(() => {
    try { return !!localStorage.getItem('credisan.terminal'); } catch { return null; }
  });
  return { b, p, errs, guardado,
           pidiendoCodigo: await p.locator('#p-emparejar').isVisible(),
           aviso: await p.textContent('#aviso-pin').catch(() => '') };
}

(async () => {
  // ── 1 · Desvinculado de verdad: hay que volver a emparejar ────────
  console.log('\n1 · La vinculación se perdió de verdad');
  {
    const { b, errs, guardado, pidiendoCodigo } = await abrir('TERMINAL_NO_AUTORIZADO');
    si('se borra la vinculación', guardado === false, String(guardado));
    si('y vuelve a pedir el código, como debe', pidiendoCodigo);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Sólo desactivado: NO se borra nada ───────────────────────
  // El caso que dejaba una sede entera sin poder marcar por un
  // interruptor del panel.
  console.log('\n2 · El terminal sólo está apagado en el panel');
  {
    const { b, errs, guardado, pidiendoCodigo, aviso } = await abrir('TERMINAL_INACTIVO');
    si('NO se borra la vinculación', guardado === true, String(guardado));
    si('y NO se le pide un código nuevo', !pidiendoCodigo);
    si('se le dice que se arregla solo al activarlo',
       aviso.includes('NO hace falta'), aviso.trim());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Todo bien: se marca la salida ────────────────────────────
  console.log('\n3 · Con el terminal en orden, la salida se marca');
  {
    const { b, p, errs, guardado } = await abrir(null);
    si('la vinculación se conserva', guardado === true);
    si('y sale la pantalla de confirmar', await p.locator('#p-identidad').isVisible());
    si('diciendo que lo que toca es la SALIDA',
       (await p.textContent('#ident-evento')).toUpperCase().includes('SALIDA'),
       (await p.textContent('#ident-evento')).trim());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — un interruptor ya no cuesta una vinculación\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
