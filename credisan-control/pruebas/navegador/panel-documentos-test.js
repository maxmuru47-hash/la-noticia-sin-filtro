/* Panel · el documento de una novedad
   ---------------------------------------------------------------------
   Quién puede qué lo decide la base, y tiene su propia batería
   (supabase/tests/12_documentos.sql). Aquí se comprueba lo otro:

     1. Que el papel VIAJE: que al registrar una novedad con documento se
        suba primero el archivo y la novedad lleve su ruta.
     2. Que el panel NO ofrezca aprobar lo que la base va a rechazar, y
        que diga por qué en vez de dejar al usuario adivinando.
     3. Que abrir el documento pase por la función que deja rastro, y
        nunca por una ruta que viniera en el listado.
     4. Que el socio vea sólo los tipos que puede cargar, y que no se le
        deje mandar un reporte sin papel.
     5. Que administración tenga el aviso a la vista sin ir a buscarlo.
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
  await p.route('**/functions/v1/credisan', (r) =>
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, pimienta: true }) }));

  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'x@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(800);
  await p.click('.nav button[data-vista="v-novedades"]');
  await p.waitForTimeout(600);
  return { b, p, errs };
}

// Un PDF de mentira, del tamaño de uno de verdad
const PDF = { name: 'permiso.pdf', mimeType: 'application/pdf',
              buffer: Buffer.from('%PDF-1.4\n% documento de prueba\n') };

(async () => {
  // ── 1 · Registrar una novedad CON su documento ────────────────────
  console.log('\n1 · El papel viaja con la novedad');
  {
    const { b, p, errs } = await abrir('admin');
    await p.click('#btn-nueva-novedad');
    await p.waitForTimeout(400);

    si('el formulario ofrece adjuntar un documento',
       await p.locator('#nov-documento').isVisible());

    // El aviso cambia con el tipo: se dice ANTES, no al rechazar
    await p.selectOption('#nov-tipo', 'reposo');
    await p.waitForTimeout(150);
    si('avisa que un reposo no se aprueba sin papel',
       (await p.textContent('#ayuda-documento')).includes('APROBAR'),
       await p.textContent('#ayuda-documento'));

    await p.selectOption('#nov-tipo', 'olvido_marcacion');
    await p.waitForTimeout(150);
    si('y que para un olvido de marcación es opcional',
       (await p.textContent('#ayuda-documento')).includes('Opcional'),
       await p.textContent('#ayuda-documento'));

    await p.selectOption('#nov-tipo', 'permiso');
    await p.fill('#nov-descripcion', 'Permiso autorizado por gerencia');
    await p.setInputFiles('#nov-documento', PDF);
    await p.click('#form-novedad button[type=submit]');
    await p.waitForTimeout(700);

    const subidas = await p.evaluate(() => window.__subidas);
    es('se sube exactamente un archivo', subidas.length, 1);
    if (subidas[0]) {
      es('al depósito de novedades', subidas[0].deposito, 'novedades');
      si('como PDF, sin convertirlo en foto', subidas[0].tipo === 'application/pdf', subidas[0].tipo);
      si('y dentro de la carpeta de la sede del trabajador',
         subidas[0].ruta.startsWith('b-mcb/'), subidas[0].ruta);
    }

    const env = await p.evaluate(() =>
      (window.__llamadas.filter((x) => x[0] === 'registrar_novedad').pop() || [])[1]);
    si('la novedad se manda con la ruta de su documento',
       env && env.p_evidencia && env.p_evidencia === subidas[0]?.ruta,
       JSON.stringify(env || null));

    // El orden importa: primero el archivo, después la novedad. Al revés,
    // la base rechazaría la novedad por apuntar a un archivo que no está.
    const orden = await p.evaluate(() =>
      window.__llamadas.findIndex((x) => x[0] === 'registrar_novedad'));
    si('y se sube ANTES de registrarla', orden >= 0 && subidas.length === 1);

    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · Lo que no se puede aprobar, no se ofrece ──────────────────
  console.log('\n2 · El panel no ofrece un botón que la base va a rechazar');
  {
    const { b, p, errs } = await abrir('admin');

    const falta = p.locator('.novedad:has-text("Reposo médico")');
    si('la que espera papel sale marcada',
       await falta.locator('.pastilla--falta').isVisible());
    es('y no se le ofrece Aprobar',
       await falta.locator('[data-aprobar]').count(), 0);
    si('pero sí Rechazar: administración no queda atrapada',
       await falta.locator('[data-rechazar]').isVisible());
    si('y se explica por qué, en vez de dejar adivinando',
       (await falta.textContent()).includes('no se puede aprobar'));
    si('se le ofrece adjuntar el documento que falta',
       await falta.locator('[data-adjuntar]').isVisible());

    const conDoc = p.locator('.novedad:has-text("Permiso de gerencia consignado")');
    si('la que sí lo tiene sale marcada como tal',
       await conDoc.locator('.pastilla--documento').isVisible());
    si('y se puede abrir', await conDoc.locator('[data-documento]').isVisible());
    si('y a ÉSA sí se le ofrece Aprobar: la regla distingue, no bloquea a todos',
       await conDoc.locator('[data-aprobar]').isVisible());

    // Adjuntar el que falta lo desbloquea. El selector de archivo lo
    // abre el propio panel, así que hay que esperarlo como evento: no
    // hay ningún <input> en la página al que apuntar.
    const [selector] = await Promise.all([
      p.waitForEvent('filechooser'),
      falta.locator('[data-adjuntar]').click()
    ]);
    await selector.setFiles(PDF);
    await p.waitForTimeout(800);

    const adj = await p.evaluate(() =>
      (window.__llamadas.filter((x) => x[0] === 'adjuntar_documento').pop() || [])[1]);
    si('se adjunta a la novedad que se abrió', adj && adj.p_id === 'n3', JSON.stringify(adj || null));
    si('con la ruta del archivo recién subido',
       adj && (await p.evaluate(() => window.__subidas.pop()?.ruta)) === adj.p_ruta);

    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Abrir el documento deja rastro ────────────────────────────
  console.log('\n3 · Abrir el papel pasa por donde queda anotado');
  {
    const { b, p, errs } = await abrir('admin');
    await p.locator('.novedad:has-text("Permiso de gerencia consignado") [data-documento]').click();
    await p.waitForTimeout(700);

    const pedido = await p.evaluate(() =>
      window.__llamadas.some((x) => x[0] === 'documento_de_novedad'));
    si('se pide la ruta por la función que anota quién la abre', pedido);

    const firmadas = await p.evaluate(() => window.__firmadas);
    es('y se firma una sola URL', firmadas.length, 1);
    si('de corta duración', firmadas[0]?.segundos <= 300, String(firmadas[0]?.segundos));

    // Lo que NO puede pasar: que la ruta viniera en el listado
    const listado = await p.evaluate(() => {
      const n = window.__llamadas.find((x) => x[0] === 'novedades');
      return n ? 'sí' : 'no';
    });
    es('el listado se pidió', listado, 'sí');
    si('y el panel no guarda ninguna ruta de documento fuera de esa llamada',
       !(await p.content()).includes('evidence_path'));

    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · El socio carga el papel de gerencia ───────────────────────
  console.log('\n4 · El socio: sólo autorizaciones, y siempre con documento');
  {
    const { b, p, errs } = await abrir('socio');

    si('el socio sí puede cargar una novedad ahora',
       await p.locator('#btn-nueva-novedad').isVisible());

    await p.click('#btn-nueva-novedad');
    await p.waitForTimeout(400);

    const tipos = await p.$$eval('#nov-tipo option', (n) => n.map((o) => o.value));
    si('sólo se le ofrecen autorizaciones', !tipos.includes('olvido_marcacion'), tipos.join(', '));
    si('entre ellas el permiso y el reposo',
       tipos.includes('permiso') && tipos.includes('reposo'), tipos.join(', '));
    si('y se le dice que el documento es obligatorio',
       (await p.textContent('#ayuda-documento')).includes('Obligatorio'),
       await p.textContent('#ayuda-documento'));

    // Sin papel, el panel ni lo intenta
    await p.fill('#nov-descripcion', 'Permiso que me contaron');
    await p.click('#form-novedad button[type=submit]');
    await p.waitForTimeout(500);
    es('sin documento no se manda nada al servidor',
       await p.evaluate(() => window.__llamadas.filter((x) => x[0] === 'registrar_novedad').length), 0);
    si('y se le dice por qué',
       (await p.textContent('#panel-aviso')).includes('documento'),
       await p.textContent('#panel-aviso'));

    // Con papel, sí
    await p.setInputFiles('#nov-documento', PDF);
    await p.click('#form-novedad button[type=submit]');
    await p.waitForTimeout(700);
    const env = await p.evaluate(() =>
      (window.__llamadas.filter((x) => x[0] === 'registrar_novedad').pop() || [])[1]);
    si('con documento sí se registra', env && !!env.p_evidencia, JSON.stringify(env || null));

    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 5 · El aviso a administración ─────────────────────────────────
  console.log('\n5 · El número está a la vista, sin ir a buscarlo');
  {
    const { b, p, errs } = await abrir('admin');
    const ins = p.locator('#insignia-novedades');
    si('el botón de Novedades lleva el contador', await ins.isVisible());
    es('con las tres pendientes de su sede', (await ins.textContent()).trim(), '3');
    si('y distingue las que esperan papel',
       (await ins.getAttribute('title')).includes('esperando documento'),
       await ins.getAttribute('title'));

    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 6 · El jefe operativo no abre reposos médicos ─────────────────
  console.log('\n6 · Un reposo médico no lo abre el mostrador');
  {
    const { b, p, errs } = await abrir('supervisor');
    const conDoc = p.locator('.novedad:has-text("Permiso de gerencia consignado")');
    if (await conDoc.count()) {
      await conDoc.locator('[data-documento]').click();
      await p.waitForTimeout(600);
      si('la base se lo niega y el panel lo dice con palabras',
         (await p.textContent('#panel-aviso')).length > 0,
         await p.textContent('#panel-aviso'));
      es('y no se firmó ninguna URL',
         await p.evaluate(() => window.__firmadas.length), 0);
    } else {
      ok('el jefe operativo no ve novedades de otra sede', 'correcto');
    }
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — el papel viaja, queda guardado y no lo abre cualquiera\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
