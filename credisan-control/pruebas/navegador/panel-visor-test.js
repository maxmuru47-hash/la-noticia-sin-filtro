/* Panel · el visor de la fotografía dice QUÉ pasó
   ---------------------------------------------------------------------
   Administración abre la foto de una marcación y lee:

       «No se pudo cargar la fotografía.»

   Ese mensaje tapaba CUATRO causas distintas y no distinguía ninguna:
   que no haya permiso, que el archivo no esté en el depósito, que el
   enlace apunte a otro servidor, o que se cayera la descarga. Con él,
   nadie —ni administración ni quien tenga que arreglarlo— sabe por
   dónde empezar.

   Esta batería exige que cada causa tenga su nombre, y que la única que
   se recupera sola —un tropiezo de descarga— ofrezca reintentar.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const fs = require('fs');
const MOCK = fs.readFileSync('mock-socios.js', 'utf8');
// EL MONTAJE REAL: el panel NO habla con Supabase directamente, sino a
// través del propio VPS. Así es como está CrediSan en producción, y es
// justo lo que destapó el fallo de las fotos: los enlaces que firma
// Supabase vienen con SU dominio y se salían de este camino.
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://control.sinfiltroconmax.com/api',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

// Un JPEG de 1×1 DE VERDAD. Con bytes inventados el panel avisaba de
// que la imagen llegó dañada — que es lo correcto, pero no es lo que
// esta sección quiere probar.
const JPEG_REAL = Buffer.from(
  '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
  + 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
  + 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==', 'base64');

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

// `respuesta` es lo que contesta la función del servidor al pedir la foto.
async function abrirVisor(respuesta, codigoDeposito = 404) {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 } });
  // Dos de estas pruebas TUMBAN la imagen a propósito, y el navegador
  // anota ese 404 como error de consola. Eso no es un fallo del
  // programa: es justo la condición que se está simulando. Se separan
  // los errores de JavaScript de los recursos que la prueba derriba.
  const errs = [];
  p.on('console', (m) => {
    const t = m.text();
    if (m.type() === 'error' && !/Failed to load resource/.test(t)) errs.push(t);
  });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.addInitScript(`window.__ROL_PRUEBA = 'admin';`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) => {
    const cuerpo = JSON.parse(r.request().postData() || '{}');
    if (cuerpo.accion === 'evidencia') {
      return r.fulfill({ contentType: 'application/json', body: JSON.stringify(respuesta) });
    }
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) });
  });
  // El depósito contesta lo que pida cada caso: así se comprueba que el
  // panel distinga un 404 (el archivo no está) de un 400 (el enlace no
  // sirve) de una imagen que sí llega.
  // La fotografía SÓLO se entrega si se pide por el camino del VPS
  // (/api/...). Pedida al dominio de Supabase no contesta nadie, que es
  // exactamente lo que pasaba en producción.
  await p.route('https://control.sinfiltroconmax.com/api/storage/**', (r) => codigoDeposito === 200
    ? r.fulfill({ status: 200, contentType: 'image/jpeg', body: JPEG_REAL })
    : r.fulfill({ status: codigoDeposito, body: 'no' }));
  await p.route('https://proyecto.supabase.co/**', (r) => r.abort('connectionrefused'));
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
  await p.waitForTimeout(900);

  // Abrir el visor sobre una marcación cualquiera del tablero de hoy
  await p.locator('[data-ver-foto]').first().click();
  await p.waitForTimeout(900);
  return { b, p, errs, texto: (await p.textContent('#visor-cuerpo')).trim() };
}

(async () => {
  // ── 1 · El archivo no está en el depósito ─────────────────────────
  console.log('\n1 · La base dice que hay foto y el depósito dice que no');
  {
    const { b, p, errs, texto } = await abrirVisor({ ok: false, motivo: 'EVIDENCIA_NO_ESTA' });
    si('se dice con todas las letras', texto.includes('no está en el depósito'), texto);
    si('y se aclara que no es culpa de quien mira',
       texto.includes('no es un problema de su pantalla'));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · El enlace de Supabase viaja por el camino del VPS ────────
  // EL FALLO QUE MAX VEÍA. El panel habla por /api del VPS; Supabase
  // firma con su propio dominio. Ese enlace se salía del camino y el
  // navegador no llegaba — mientras el resto de la pantalla funcionaba,
  // que es lo que lo hacía tan difícil de entender.
  console.log('\n2 · El enlace firmado se trae por el camino del VPS');
  {
    const foto = { ok: true, nombre: 'Florived Andrade', cuando: new Date().toISOString(),
                   url: 'https://proyecto.supabase.co/storage/v1/object/sign/evidencia/f.jpg?token=abc' };
    const { b, p, errs, texto } = await abrirVisor(foto, 200);

    si('la fotografía SE VE', await p.locator('#visor-cuerpo img').count() > 0, texto || 'sin mensaje');
    si('y ya no se habla de «otro servidor»', !texto.includes('otro servidor'), texto);

    // Se comprobó pidiéndola por /api: al dominio de Supabase no
    // contesta nadie en esta prueba, así que si se ve, viajó por donde debe.
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n3 · El depósito contesta 404: el archivo no está');
  {
    const foto = { ok: true, nombre: 'Marina López', cuando: new Date().toISOString(),
                   url: 'https://proyecto.supabase.co/storage/v1/object/sign/evidencia/f.jpg?token=abc' };
    const { b, p, errs, texto } = await abrirVisor(foto, 404);
    si('se dice que el archivo no está', texto.includes('no está en el depósito'), texto);
    si('y se da el número exacto para soporte', texto.includes('404'), texto);
    si('con opción de reintentar', await p.locator('#visor-cuerpo button').isVisible());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n3b · El depósito contesta 400: el enlace no sirve');
  {
    const foto = { ok: true, nombre: 'Marina López', cuando: new Date().toISOString(),
                   url: 'https://proyecto.supabase.co/storage/v1/object/sign/evidencia/f.jpg?token=abc' };
    const { b, errs, texto } = await abrirVisor(foto, 400);
    si('NO se confunde con un archivo ausente',
       !texto.includes('no está en el depósito'), texto);
    si('y también da el número', texto.includes('400'), texto);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n3c · La fotografía llega: se ve');
  {
    const foto = { ok: true, nombre: 'Marina López', cuando: new Date().toISOString(),
                   url: 'https://proyecto.supabase.co/storage/v1/object/sign/evidencia/f.jpg?token=abc' };
    const { b, p, errs } = await abrirVisor(foto, 200);
    si('se pinta la imagen', await p.locator('#visor-cuerpo img').count() > 0);
    si('y no queda ningún mensaje de fallo',
       !(await p.textContent('#visor-cuerpo')).includes('no se pudo'));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · Las causas que ya se explicaban siguen explicándose ───────
  console.log('\n4 · Lo que ya funcionaba sigue funcionando');
  {
    const { b, p, errs, texto } = await abrirVisor({ ok: false, reason: 'SIN_EVIDENCIA' });
    si('una marcación sin foto se explica igual que antes',
       texto.includes('sin fotografía'), texto);
    si('y menciona la novedad abierta', texto.includes('novedad'));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }
  {
    const { b, p, errs, texto } = await abrirVisor({ ok: false, motivo: 'NO_AUTORIZADO' });
    si('y un permiso denegado también', texto.includes('permiso'), texto);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — cada fallo de la foto dice cuál es\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
