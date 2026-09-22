/* Panel · avisar cuando la base lleva días sin respaldo
   ---------------------------------------------------------------------
   El respaldo estuvo CUATRO noches seguidas fallando —una contraseña
   que caducó— y no se supo hasta que alguien fue a mirarlo por
   casualidad. Una copia de seguridad que falla en silencio da la misma
   protección que no tenerla, con la tranquilidad añadida de creer que
   sí.
   
   Esta batería exige que el panel lo diga. Y también lo contrario: que
   se calle cuando todo va bien, porque un aviso que sale siempre deja
   de leerse a la semana.
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

const haceDias = (n) => new Date(Date.now() - n * 86400000).toISOString();

// `estado` es lo que el servidor deja en estado-respaldo.json.
// `null` = el archivo no existe (todavía no se instaló).
async function abrir(estado, rol = 'admin') {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const p = await b.newPage({ viewport: { width: 414, height: 900 } });
  const errs = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.addInitScript(`window.__ROL_PRUEBA = ${JSON.stringify(rol)};`);
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType: 'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await p.route('**/functions/v1/credisan', (r) =>
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));
  await p.route('**/estado-respaldo.json', (r) => estado === null
    ? r.fulfill({ status: 404, body: 'no existe' })
    : r.fulfill({ contentType: 'application/json', body: JSON.stringify(estado) }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(1200);
  const visible = await p.locator('#aviso-respaldo').isVisible();
  const texto = visible ? (await p.textContent('#aviso-respaldo')).trim() : '';
  return { b, p, errs, visible, texto };
}

(async () => {
  console.log('\n1 · Todo al día: el panel se calla');
  {
    const { b, errs, visible } = await abrir({ ultimo: haceDias(0), ok: true, copias: 14 });
    si('no se avisa de nada', !visible);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n2 · El respaldo de anoche falló');
  {
    const { b, errs, visible, texto } = await abrir({ ultimo: haceDias(0), ok: false, motivo: 'pg_dump_fallo' });
    si('se avisa', visible);
    si('y se dice lo que de verdad importa: que no hay copia',
       texto.includes('no tienen copia'), texto);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n3 · Cuatro noches sin respaldo — lo que pasó de verdad');
  {
    const { b, errs, visible, texto } = await abrir({ ultimo: haceDias(4), ok: true, copias: 14 });
    si('se avisa aunque el último saliera bien', visible);
    si('y se dicen los días', texto.includes('4 días'), texto);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n4 · Un día de retraso no alarma a nadie');
  // Un aviso que sale por cualquier cosa deja de leerse.
  {
    const { b, errs, visible } = await abrir({ ultimo: haceDias(1), ok: true, copias: 14 });
    si('con un día no se avisa', !visible);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n5 · Sin instalar todavía: no se inventa una alarma');
  {
    const { b, errs, visible } = await abrir(null);
    si('si el archivo no existe, no se asusta a nadie', !visible);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log('\n6 · Al jefe operativo no le toca este aviso');
  {
    const { b, errs, visible } = await abrir({ ultimo: haceDias(9), ok: false }, 'supervisor');
    si('no se le enseña: no es asunto del mostrador', !visible);
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — un respaldo que falla ya no lo hace en silencio\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
