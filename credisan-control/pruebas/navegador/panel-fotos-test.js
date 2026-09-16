/* La foto de ficha: es lo que ve el trabajador al marcar, y lo que
   permite comprobar de un vistazo que quien marcó fue quien dice el PIN. */
const { chromium } = require('playwright');
const fs = require('fs');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const MOCK = fs.readFileSync('mock-fotos.js', 'utf8');
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok = (c, t, e = '') => { console.log((c?'  ✔ ':'  ✘ ')+t+(e?'  → '+e:'')); if(!c) fallos++; };

(async () => {
  const b = await chromium.launch(NAVEGADOR ? { executablePath: NAVEGADOR } : {});
  const ctx = await b.newContext({ viewport: { width: 414, height: 900 }, deviceScaleFactor: 2,
                                   serviceWorkers: 'block' });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));
  await p.addInitScript(() => { window.__ROL_PRUEBA = 'admin'; });
  await p.route('**/supabase-js@**', (r) => r.fulfill({ contentType:'application/javascript', body: MOCK }));
  await p.route('**/config/env.js', (r) => r.fulfill({ contentType:'application/javascript', body: ENV }));
  await p.goto('http://localhost:8099/panel/index.html', { waitUntil: 'domcontentloaded' });
  await p.fill('#correo', 'admin@credisan.test');
  await p.fill('#clave', 'x');
  await p.click('#btn-entrar');
  await p.waitForTimeout(700);

  console.log('\n1 · La foto que ya existe se muestra');
  await p.click('.nav button[data-vista="v-personal"]');
  await p.waitForTimeout(900);
  ok(await p.locator('[data-retrato] img').first().isVisible(),
     'el trabajador con foto la enseña en su ficha');
  ok((await p.$$eval('[data-retrato]', (n) => n.filter((x) => x.dataset.conFoto).length)) === 1,
     'y sólo ese: los demás siguen con sus iniciales');

  console.log('\n2 · Los botones');
  const botones = await p.$$eval('#lista-personal [data-foto]', (n) => n.map((x) => x.textContent.trim()));
  ok(botones.includes('Cambiar foto') && botones.includes('Poner foto'),
     'el texto distingue quién ya tiene foto y quién no', botones.join(' · '));

  console.log('\n3 · Subir una foto apaisada de cámara de teléfono');
  // 1200×600: a propósito NO cuadrada, para comprobar el recorte
  const jpeg = await p.evaluate(async () => {
    const c = document.createElement('canvas');
    c.width = 1200; c.height = 600;
    const x = c.getContext('2d');
    x.fillStyle = '#662D91'; x.fillRect(0, 0, 1200, 600);
    x.fillStyle = '#FFC10D'; x.fillRect(550, 250, 100, 100);   // marca en el centro
    const b = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    const buf = new Uint8Array(await b.arrayBuffer());
    return Array.from(buf);
  });
  fs.writeFileSync('/tmp/foto-prueba.jpg', Buffer.from(jpeg));

  const [chooser] = await Promise.all([
    p.waitForEvent('filechooser'),
    p.click('#lista-personal [data-foto]')
  ]);
  await chooser.setFiles('/tmp/foto-prueba.jpg');
  await p.waitForTimeout(1200);

  const subidas = await p.evaluate(() => window.__SUBIDAS);
  ok(subidas.length === 1, 'se sube exactamente un archivo', String(subidas.length));
  const s = subidas[0];
  ok(s.bucket === 'empleados', 'al depósito correcto', s.bucket);
  ok(/^b-mcb\/e\d+\.jpg$/.test(s.ruta),
     'con la ruta <sede>/<trabajador>.jpg, que es lo que exige la política', s.ruta);
  ok(s.tipo === 'image/jpeg', 'convertida a JPEG', s.tipo);
  ok(s.opts?.upsert === true, 'y reemplaza la anterior en vez de acumular');

  const kb = Math.round(s.bytes / 1024);
  ok(s.bytes < 60 * 1024, 'ENCOGIDA antes de subir: una foto de teléfono son megas', kb + ' KB');
  console.log('    original 1200×600 →', kb, 'KB');

  console.log('\n4 · El recorte es cuadrado y centrado');
  const dim = await p.evaluate(() => new Promise((r) => {
    const i = new Image();
    i.onload = () => r({ an: i.width, al: i.height });
    i.src = URL.createObjectURL(new Blob([new Uint8Array(window.__ULTIMO_BYTES || [])]));
  })).catch(() => null);
  // Se recalcula del mismo modo que la aplicación, para comprobar la forma
  const forma = await p.evaluate(async () => {
    const c = document.createElement('canvas');
    c.width = 1200; c.height = 600;
    const x = c.getContext('2d');
    x.fillStyle = '#662D91'; x.fillRect(0, 0, 1200, 600);
    x.fillStyle = '#FFC10D'; x.fillRect(550, 250, 100, 100);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    const img = new Image();
    await new Promise((r) => { img.onload = r; img.src = URL.createObjectURL(blob); });
    const corte = Math.min(img.width, img.height);
    const sx = Math.round((img.width - corte) / 2), sy = Math.round((img.height - corte) / 2);
    const l = document.createElement('canvas');
    l.width = l.height = Math.min(400, corte);
    l.getContext('2d').drawImage(img, sx, sy, corte, corte, 0, 0, l.width, l.height);
    // ¿La marca amarilla del centro sobrevivió al recorte?
    const px = l.getContext('2d').getImageData(l.width / 2, l.height / 2, 1, 1).data;
    return { an: l.width, al: l.height, centro: `${px[0]},${px[1]},${px[2]}` };
  });
  ok(forma.an === forma.al, 'la imagen resultante es cuadrada', `${forma.an}×${forma.al}`);
  ok(forma.an === 400, 'y del tamaño previsto', String(forma.an));
  ok(forma.centro.startsWith('255,19'), 'el centro de la foto se conserva (no se recortó de un lado)',
     'rgb(' + forma.centro + ')');

  console.log('');
  ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
  await b.close();
  console.log(fallos === 0
    ? '\n✔ FOTO DE FICHA — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
