/* Terminal · la fotografía del trabajador apresurado
   ---------------------------------------------------------------------
   De aquí salían casi todas las novedades automáticas de «marcación sin
   evidencia fotográfica».
   
   La cámara de una tableta tarda entre medio segundo y segundo y medio
   en dar su PRIMERA imagen. Quien se sabe su PIN de memoria pulsa
   «Confirmar» mucho antes de eso, y `videoWidth` todavía vale 0: no hay
   nada que capturar, y la marcación queda sin foto.
   
   La otra batería del kiosco esperaba 1,3 segundos antes de confirmar,
   así que nunca lo vio. Ésta no espera NADA, que es lo que hace una
   persona con prisa.
   
   Y comprueba la otra mitad, que es igual de importante: que la espera
   tenga TOPE. La regla de este terminal es que una foto que falla nunca
   impide marcar. Si no hay cámara, se marca igual y sin demora.
*/
const { chromium } = require('playwright');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-pasar-validacion',version:'1.0.0'};`;

let fallos = 0;
const ok  = (t, v) => console.log(`  ✔ ${t}${v !== undefined ? '  → ' + v : ''}`);
const mal = (t, v) => { fallos++; console.log(`  ✖ ${t}  → ${v}`); };
const si  = (t, c, v) => (c ? ok(t, v) : mal(t, v));

async function abrirTerminal({ conCamara = true } = {}) {
  const b = await chromium.launch({
    ...(NAVEGADOR ? { executablePath: NAVEGADOR } : {}),
    args: conCamara
      ? ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-capture']
      : ['--use-fake-ui-for-media-capture']
  });
  const ctx = await b.newContext({
    viewport: { width: 414, height: 820 }, deviceScaleFactor: 2,
    permissions: conCamara ? ['camera'] : [],
    baseURL: 'http://localhost:8099', serviceWorkers: 'block'
  });
  const p = await ctx.newPage();
  const errs = []; const enviado = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));

  // Sin cámara de verdad: se le quita el acceso al aparato por completo,
  // como una tableta con la cámara tapada o el permiso denegado.
  if (!conCamara) {
    await p.addInitScript(() => {
      Object.defineProperty(navigator, 'mediaDevices', { get: () => undefined });
    });
  } else {
    // Anotar EL INSTANTE en que el terminal pide la cámara, para poder
    // comprobar que la pide antes de enseñar la identidad y no después.
    await p.addInitScript(() => {
      const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
      window.__camaraPedidaEn = null;
      navigator.mediaDevices.getUserMedia = (...a) => {
        if (window.__camaraPedidaEn === null) window.__camaraPedidaEn = Date.now();
        return original(...a);
      };
    });
  }

  await p.route('**/functions/v1/credisan', (r) => {
    const cuerpo = JSON.parse(r.request().postData());
    enviado.push(cuerpo);
    const R = (o) => r.fulfill({ contentType: 'application/json', body: JSON.stringify(o) });
    if (cuerpo.accion === 'emparejar') {
      return R({ ok: true, terminal: 'MCY-01', sede: 'Maracay',
                 branch_id: 'b-mcy', token: 'token-de-prueba-largo-123456', firma: 'firma' });
    }
    if (cuerpo.accion === 'pin') {
      if (cuerpo.pin !== '481902') return R({ ok: false, motivo: 'PIN_INVALIDO' });
      return R({ ok: true, empleado_id: 'e1', nombre: 'Ana Pérez', cargo: 'Cajera',
                 sede: 'Maracay', evento: 'entrada', es_llegada: true,
                 ticket: 'tk-1', ticket_vence: new Date(Date.now() + 60000).toISOString() });
    }
    if (cuerpo.accion === 'confirmar') {
      return R({ ok: true, id: 'ev-1', event: 'entrada', status: 'puntual',
                 delta_minutes: 0, recorded_local: '08:00:02',
                 evidencia: cuerpo.foto ? 'almacenada' : 'sin_evidencia' });
    }
    return R({ ok: false, motivo: 'ACCION_DESCONOCIDA' });
  });

  await p.goto('/kiosk/index.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(400);
  await p.fill('#e-terminal', 'MCY-01');
  await p.fill('#e-codigo', 'AB3K9XQ2');
  await p.fill('#e-dispositivo', 'Tableta de Maracay');
  await p.click('#btn-emparejar');
  await p.waitForTimeout(500);
  return { b, p, errs, enviado };
}

(async () => {
  // ── 1 · El trabajador con prisa ───────────────────────────────────
  console.log('\n1 · Confirma en cuanto aparece su nombre, sin esperar nada');
  {
    const { b, p, errs, enviado } = await abrirTerminal();

    for (const d of '481902') await p.click(`.k-tecla[data-d="${d}"]`);
    // Esperar a que salga su nombre, y NI UN MILISEGUNDO MÁS
    await p.waitForSelector('#p-identidad:not([hidden])', { timeout: 5000 });
    await p.click('#btn-confirmar');

    await p.waitForSelector('#p-resultado:not([hidden])', { timeout: 8000 });
    const conf = enviado.find((x) => x.accion === 'confirmar');

    si('la marcación se registra', !!conf);
    si('CON su fotografía, pese a la prisa',
       conf && typeof conf.foto === 'string' && conf.foto.startsWith('data:image/jpeg;base64,'),
       conf ? (conf.foto ? Math.round(conf.foto.length * 0.75 / 1024) + ' KB' : 'VACÍA') : '—');
    si('y sin motivo de «sin foto»', conf && !conf.motivo_sin_foto, conf?.motivo_sin_foto || 'ninguno');
    si('el trabajador no ve ningún aviso de fallo',
       !(await p.textContent('#res-detalle')).includes('sin fotografía'),
       (await p.textContent('#res-detalle')).trim());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 2 · La cámara se pide ANTES de enseñar la identidad ───────────
  // Aquí no se puede exigir que el sensor YA dé imagen: cuánto alcanza a
  // calentar depende de lo que tarde la red, y el servidor de esta
  // prueba contesta al instante. Lo que sí depende del código —y es lo
  // que se arregló— es el ORDEN: pedirla al mandar el PIN y no al
  // pintar la identidad. En el terminal real, esa llamada tarda entre
  // 200 y 800 ms, y son 200 a 800 ms de calentamiento regalados.
  console.log('\n2 · El sensor se pide al mandar el PIN, no al pintar la identidad');
  {
    const { b, p, errs } = await abrirTerminal();
    for (const d of '481902') await p.click(`.k-tecla[data-d="${d}"]`);
    await p.waitForSelector('#p-identidad:not([hidden])', { timeout: 5000 });
    const visto = Date.now();

    const pedida = await p.evaluate(() => window.__camaraPedidaEn);
    si('la cámara se pidió', pedida !== null, pedida ? 'sí' : 'NUNCA');
    si('y se pidió ANTES de que saliera la identidad',
       pedida !== null && pedida <= visto,
       pedida ? (visto - pedida) + ' ms de ventaja' : '—');
    si('y está a la vista del trabajador',
       !(await p.locator('#camara').isHidden()));
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 3 · Un PIN equivocado no deja la cámara encendida ─────────────
  console.log('\n3 · Si el PIN no era, la cámara se apaga');
  {
    const { b, p, errs } = await abrirTerminal();
    for (const d of '000000') await p.click(`.k-tecla[data-d="${d}"]`);
    await p.waitForTimeout(900);

    const encendida = await p.evaluate(() => {
      const v = document.getElementById('video');
      return !!(v.srcObject && v.srcObject.getTracks().some((t) => t.readyState === 'live'));
    });
    si('la cámara NO se queda encendida tras un PIN inválido', !encendida);
    si('y el terminal vuelve a pedir el PIN', await p.locator('#p-pin').isVisible());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  // ── 4 · Sin cámara se marca igual, y sin demora ───────────────────
  // La mitad que no se puede romper: la espera tiene tope, y una foto
  // que falla NUNCA impide marcar.
  console.log('\n4 · Sin cámara: se marca igual, y sin hacer esperar');
  {
    const { b, p, errs, enviado } = await abrirTerminal({ conCamara: false });

    for (const d of '481902') await p.click(`.k-tecla[data-d="${d}"]`);
    await p.waitForSelector('#p-identidad:not([hidden])', { timeout: 5000 });

    const t0 = Date.now();
    await p.click('#btn-confirmar');
    await p.waitForSelector('#p-resultado:not([hidden])', { timeout: 8000 });
    const tardo = Date.now() - t0;

    const conf = enviado.find((x) => x.accion === 'confirmar');
    si('la marcación se registra igual', !!conf);
    si('sin foto, y diciendo por qué',
       conf && conf.foto === '' && !!conf.motivo_sin_foto, conf?.motivo_sin_foto);
    si('y SIN hacer esperar al trabajador: la espera tiene tope',
       tardo < 3000, tardo + ' ms');
    si('el trabajador ve que quedó sin fotografía',
       (await p.textContent('#res-detalle')).includes('sin fotografía'),
       (await p.textContent('#res-detalle')).trim());
    si('sin un solo error de JavaScript', errs.length === 0, errs.join(' | '));
    await b.close();
  }

  console.log(fallos === 0
    ? '\n  ✔  TODO EN ORDEN — la prisa ya no cuesta una fotografía\n'
    : `\n  ✖  ${fallos} comprobaciones fallaron\n`);
  process.exit(fallos === 0 ? 0 : 1);
})();
