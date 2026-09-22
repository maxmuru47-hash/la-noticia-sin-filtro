/* Panel · la sesión no se tira por un tropiezo
   ---------------------------------------------------------------------
   Los usuarios de las tres sedes se quejan de que «se les cierra la
   ventana» y tienen que volver a escribir la contraseña.

   Dos tropiezos PASAJEROS se trataban como definitivos:

     · Un fallo de red al arrancar dejaba al usuario mirando el
       formulario de acceso, con su sesión intacta guardada al lado.
     · `SIN_SESION` —que casi siempre significa «el token se estaba
       renovando justo en ese instante»— disparaba un signOut(), que
       BORRA la sesión de verdad.

   Esta batería exige lo contrario: que un tropiezo se reintente, y que
   sólo se cierre la sesión cuando el servidor diga algo definitivo.
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

// `guion` decide cómo contesta mi_perfil en cada llamada sucesiva.
async function abrir(guion) {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 } });
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.addInitScript(`window.__ROL_PRUEBA = 'admin';`);
  await p.addInitScript(`window.__GUION_PERFIL = ${JSON.stringify(guion)};`);
  // Arranca YA con sesión guardada, como quien vuelve a abrir el panel
  await p.addInitScript(`window.__SESION_GUARDADA = true;`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) =>
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(3000);       // margen para el reintento
  return { b, p, errs };
}

const dentro = (p) => p.locator('#pantalla-panel').isVisible();

(async () => {
  // ── 1 · Un tropiezo de red al arrancar ────────────────────────────
  console.log('\n1 · La red falla al abrir, y a la segunda contesta');
  {
    const { b, p, errs } = await abrir(['error', 'ok']);
    si('el usuario entra igual: se reintentó', await dentro(p));
    si('y NO se le pidió la contraseña',
       await p.locator('#pantalla-acceso').isHidden());
    si('la sesión sigue guardada', await p.evaluate(() => window.__SESION_VIVA === true));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · El token se estaba renovando ──────────────────────────────
  console.log('\n2 · La petición viaja sin credencial y a la segunda ya va');
  {
    const { b, p, errs } = await abrir(['sin_sesion', 'ok']);
    si('el usuario entra igual', await dentro(p));
    si('y su sesión NO fue destruida',
       await p.evaluate(() => window.__SESION_VIVA === true));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Ni aun fallando dos veces se borra la sesión ───────────────
  // Lo que de verdad dolía: que un mal momento costara la contraseña.
  console.log('\n3 · Falla dos veces: se queda fuera, pero no pierde la sesión');
  {
    const { b, p, errs } = await abrir(['sin_sesion', 'sin_sesion']);
    si('se le enseña el acceso', await p.locator('#pantalla-acceso').isVisible());
    si('pero su sesión NO se borró: al recargar vuelve a entrar sola',
       await p.evaluate(() => window.__SESION_VIVA === true));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · Un acceso desactivado SÍ se cierra ────────────────────────
  // El otro lado: cuando el servidor dice algo definitivo, hay que
  // obedecer. Si no, esto sería un agujero en vez de una cortesía.
  console.log('\n4 · Un acceso desactivado sí cierra la sesión');
  {
    const { b, p, errs } = await abrir(['inactivo', 'inactivo']);
    si('no entra', !(await dentro(p)));
    si('y su sesión SÍ se cerró, como debe',
       await p.evaluate(() => window.__SESION_VIVA === false));
    si('y se le dice por qué',
       (await p.textContent('#acceso-aviso')).length > 0,
       (await p.textContent('#acceso-aviso')).trim());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — un tropiezo ya no cuesta la contraseña\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
