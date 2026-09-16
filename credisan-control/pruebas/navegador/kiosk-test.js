const { chromium } = require('playwright');
// Ruta al navegador: se toma del entorno si está, y si no se deja que
// Playwright use el suyo. Fijarla a pelo ata la prueba a una máquina.
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;
const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',supabaseAnonKey:'clave-de-prueba-larga-para-pasar-validacion',version:'1.0.0'};`;

(async () => {
  const b = await chromium.launch({
    ...(NAVEGADOR ? { executablePath: NAVEGADOR } : {}),
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-capture']
  });
  const ctx = await b.newContext({
    viewport: { width: 414, height: 820 }, deviceScaleFactor: 2,
    permissions: ['camera'], baseURL: 'http://localhost:8099'
  });
  const p = await ctx.newPage();
  const errs = []; const enviado = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));

  await p.route('**/functions/v1/credisan', (r) => {
    const cuerpo = JSON.parse(r.request().postData());
    enviado.push(cuerpo);
    const R = (o) => r.fulfill({ contentType: 'application/json', body: JSON.stringify(o) });

    if (cuerpo.accion === 'emparejar') {
      if (cuerpo.codigo !== 'AB3K9XQ2') return R({ ok: false, motivo: 'CODIGO_INVALIDO' });
      return R({ ok: true, terminal: 'MCB-01', sede: 'Maracaibo',
                 branch_id: 'b-mcb', token: 'token-de-prueba-largo-123456', firma: 'firma' });
    }
    if (cuerpo.accion === 'pin') {
      if (cuerpo.pin !== '481902') return R({ ok: false, motivo: 'PIN_INVALIDO' });
      return R({ ok: true, empleado_id: 'e1', nombre: 'Ana Pérez', cargo: 'Cajera',
                 sede: 'Maracaibo', evento: 'entrada', es_llegada: true,
                 ticket: 'tk-1', ticket_vence: new Date(Date.now() + 60000).toISOString() });
    }
    if (cuerpo.accion === 'confirmar') {
      return R({ ok: true, id: 'ev-1', event: 'entrada', status: 'puntual',
                 delta_minutes: 3, recorded_local: '08:03:12', evidencia: 'almacenada' });
    }
    return R({ ok: false, motivo: 'ACCION_DESCONOCIDA' });
  });

  await p.goto('/kiosk/index.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(400);

  console.log('1 · pantalla de emparejamiento:', await p.locator('#p-emparejar').isVisible());
  await p.screenshot({ path: 'k1-emparejar.png' });

  // Código equivocado
  await p.fill('#e-terminal', 'MCB-01');
  await p.fill('#e-codigo', 'ZZZZZZZZ');
  await p.fill('#e-dispositivo', 'Tablet mostrador');
  await p.click('#btn-emparejar');
  await p.waitForTimeout(400);
  console.log('2 · código malo →', (await p.textContent('#aviso-emparejar')).trim());

  // Código correcto
  await p.fill('#e-codigo', 'AB3K9XQ2');
  await p.click('#btn-emparejar');
  await p.waitForTimeout(500);
  console.log('3 · teclado visible:', await p.locator('#p-pin').isVisible(),
              '| sede:', (await p.textContent('#pin-sede')).trim());
  await p.screenshot({ path: 'k2-teclado.png' });

  // PIN incorrecto
  for (const d of '000000') await p.click(`.k-tecla[data-d="${d}"]`);
  await p.waitForTimeout(500);
  console.log('4 · PIN malo →', (await p.textContent('#aviso-pin')).trim());
  console.log('   puntos borrados:', await p.$$eval('#puntos .k-punto',
    (n) => n.every((x) => x.dataset.lleno === '0')));

  // PIN correcto
  for (const d of '481902') await p.click(`.k-tecla[data-d="${d}"]`);
  await p.waitForTimeout(900);
  console.log('5 · identidad:', (await p.textContent('#ident-nombre')).trim(),
              '|', (await p.textContent('#ident-evento')).trim());
  console.log('   cámara encendida:', await p.locator('#camara').isVisible());
  await p.screenshot({ path: 'k3-identidad.png' });

  // Confirmar
  await p.click('#btn-confirmar');
  await p.waitForTimeout(1200);
  const conf = enviado.find((x) => x.accion === 'confirmar');
  console.log('6 · foto enviada:', conf.foto.startsWith('data:image/jpeg;base64,'),
              '| tamaño:', Math.round(conf.foto.length * 0.75 / 1024) + ' KB');
  console.log('   el PIN NO viaja al confirmar:', !('pin' in conf));
  console.log('7 · resultado:', (await p.textContent('#res-estado')).trim(),
              '|', (await p.textContent('#res-hora')).trim(),
              '|', (await p.textContent('#res-detalle')).trim());
  console.log('   tono de fondo:', await p.locator('#p-resultado').getAttribute('data-tono'));
  await p.screenshot({ path: 'k4-resultado.png' });

  // Vuelta automática al teclado
  await p.waitForTimeout(5200);
  console.log('8 · volvió al teclado solo:', await p.locator('#p-pin').isVisible());

  // Al recargar recuerda el dispositivo
  await p.reload({ waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(500);
  console.log('9 · tras recargar sigue vinculado:', await p.locator('#p-pin').isVisible());

  console.log('errores JS:', errs.length ? errs : 'ninguno');
  await b.close();
})();
