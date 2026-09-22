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
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

// `respuesta` es lo que contesta la función del servidor al pedir la foto.
async function abrirVisor(respuesta) {
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
  // Cualquier imagen firmada: se responde con un 404, como haría el
  // depósito con una ruta que no existe.
  await p.route('**/firmada/**', (r) => r.fulfill({ status: 404, body: 'Object not found' }));
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

  // ── 2 · El enlace apunta a otro servidor ──────────────────────────
  // Un ajuste mal puesto se veía exactamente igual que una foto perdida.
  console.log('\n2 · El enlace firmado apunta a otro servidor');
  {
    const { b, p, errs, texto } = await abrirVisor({
      ok: true, nombre: 'Marina López', cuando: new Date().toISOString(),
      url: 'https://otro-servidor.example.com/firmada/foto.jpg'
    });
    si('se nombra el problema real', texto.includes('otro servidor'), texto);
    si('y se dice que es un ajuste del sistema',
       texto.includes('ajuste del sistema'));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · La descarga se cayó: se puede reintentar ──────────────────
  console.log('\n3 · La descarga falla, y el enlace dura 60 segundos');
  {
    const { b, p, errs, texto } = await abrirVisor({
      ok: true, nombre: 'Marina López', cuando: new Date().toISOString(),
      url: 'https://demo.supabase.co/firmada/foto.jpg'
    });
    si('se distingue de las otras causas', texto.includes('No se pudo descargar'), texto);
    si('se explica que el enlace caduca', texto.includes('60 segundos'));
    si('y se ofrece intentarlo otra vez',
       await p.locator('#visor-cuerpo button').isVisible());
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
