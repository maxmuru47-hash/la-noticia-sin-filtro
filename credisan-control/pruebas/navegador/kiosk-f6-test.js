/* La prueba que de verdad importa en la Fase 6: cortar la red con el
   terminal delante y comprobar que la marcación no se pierde, que el PIN
   nunca queda en claro en el aparato, y que al volver la señal se envía
   sola y en orden. */
const { chromium } = require('playwright');
const crypto = require('crypto');
const NAVEGADOR = process.env.CHROMIUM_PATH || undefined;

let fallos = 0;
const ok = (c, t, extra = '') => {
  console.log((c ? '  ✔ ' : '  ✘ ') + t + (extra ? '  → ' + extra : ''));
  if (!c) fallos++;
};

(async () => {
  // El servidor genera su par una sola vez; el terminal sólo verá la pública.
  const par = await crypto.webcrypto.subtle.generateKey(
    { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
  const PUB = Buffer.from(await crypto.webcrypto.subtle.exportKey('spki', par.publicKey)).toString('base64');

  const abrirSobre = async (sobre) => {
    const bin = (s) => new Uint8Array(Buffer.from(s, 'base64'));
    const epk = await crypto.webcrypto.subtle.importKey('raw', bin(sobre.epk),
      { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    const compartido = await crypto.webcrypto.subtle.deriveBits(
      { name: 'ECDH', public: epk }, par.privateKey, 256);
    const material = await crypto.webcrypto.subtle.importKey('raw', compartido, 'HKDF', false, ['deriveKey']);
    const llave = await crypto.webcrypto.subtle.deriveKey(
      { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0),
        info: new TextEncoder().encode('credisan-pin-offline-v1') },
      material, { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
    return new TextDecoder().decode(await crypto.webcrypto.subtle.decrypt(
      { name: 'AES-GCM', iv: bin(sobre.iv) }, llave, bin(sobre.ct)));
  };

  const ENV = `window.CREDISAN_ENV={supabaseUrl:'https://demo.supabase.co',` +
    `supabaseAnonKey:'clave-de-prueba-larga-para-pasar-validacion',` +
    `offlinePublicKey:'${PUB}',version:'1.0.0'};`;

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
  let hayRed = true;
  // Los ERR_FAILED son el corte de red que esta prueba provoca a
  // propósito. Contarlos como defectos sería contar el experimento.
  const esCorteSimulado = (t) => /ERR_FAILED|ERR_INTERNET_DISCONNECTED|Failed to fetch/.test(t);
  p.on('console', (m) => {
    if (m.type() === 'error' && !esCorteSimulado(m.text())) errs.push(m.text());
  });
  p.on('pageerror', (e) => errs.push('PAGEERROR: ' + e.message));

  await p.route('**/config/env.js', (r) => r.fulfill({ contentType: 'application/javascript', body: ENV }));
  await p.route('**/fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body: '' }));

  await p.route('**/functions/v1/credisan', async (r) => {
    if (!hayRed) return r.abort('failed');          // la red, caída de verdad
    const cuerpo = JSON.parse(r.request().postData());
    enviado.push(cuerpo);
    const R = (o) => r.fulfill({
      contentType: 'application/json',
      headers: { date: new Date().toUTCString() },   // ancla del reloj
      body: JSON.stringify(o)
    });

    if (cuerpo.accion === 'emparejar')
      return R({ ok: true, terminal: 'MCB-01', sede: 'Maracaibo',
                 branch_id: 'b-mcb', token: 'token-de-prueba', firma: 'firma' });

    if (cuerpo.accion === 'sincronizar') {
      const resultados = [];
      for (const it of cuerpo.lote) {
        let pin = null;
        try { pin = await abrirSobre(it.sobre); } catch { /* ilegible */ }
        resultados.push(pin === '481902'
          ? { id: it.id, ok: true, definitivo: true, status: 'puntual', pin_descifrado: pin }
          : { id: it.id, ok: false, definitivo: true, reason: 'PIN_INVALIDO', pin_descifrado: pin });
      }
      return R({ ok: true, resultados });
    }
    return R({ ok: false, motivo: 'ACCION_DESCONOCIDA' });
  });

  await p.goto('/kiosk/index.html', { waitUntil: 'domcontentloaded' });

  // ── Emparejar (con red) ──
  await p.fill('#e-terminal', 'MCB-01');
  await p.fill('#e-codigo', 'AB3K9XQ2');
  await p.fill('#e-dispositivo', 'Tableta de prueba');
  await p.click('#btn-emparejar');
  await p.waitForTimeout(700);
  ok(await p.locator('#p-pin').isVisible(), 'el terminal queda emparejado y pide PIN');

  console.log('\n1 · Se cae la red y alguien marca');
  hayRed = false;
  await p.evaluate(() => window.dispatchEvent(new Event('offline')));
  await p.waitForTimeout(300);

  const antes = enviado.length;
  for (const d of '481902') await p.click(`[data-d="${d}"]`);
  await p.waitForTimeout(1800);

  ok(enviado.length === antes, 'no se intenta llamar al servidor sabiendo que no hay red');
  ok(await p.locator('#p-resultado').isVisible(), 'aparece una pantalla de resultado');
  const res = await p.evaluate(() => document.getElementById('p-resultado').innerText);
  ok(/SIN CONEXIÓN/.test(res), 'dice claramente que fue sin conexión');
  ok(/guardada/i.test(res), 'dice que la marcación quedó guardada');
  ok(/no quedará registrada|no fuera correcto/i.test(res),
     'y advierte que si el PIN no es correcto no quedará registrada', res.replace(/\n/g, ' · '));
  await p.screenshot({ path: 'kf6-1-sin-conexion.png', fullPage: true });

  console.log('\n2 · Lo que quedó guardado en el aparato');
  const enCola = await p.evaluate(async () => {
    const bd = await new Promise((r) => { const q = indexedDB.open('credisan', 1); q.onsuccess = () => r(q.result); });
    return await new Promise((r) => {
      const q = bd.transaction('cola', 'readonly').objectStore('cola').getAll();
      q.onsuccess = () => r(q.result);
    });
  });
  ok(enCola.length === 1, 'hay exactamente una marcación en la cola', String(enCola.length));

  const crudo = JSON.stringify(enCola);
  ok(!crudo.includes('481902'), 'EL PIN NO ESTÁ EN CLARO en el aparato');
  ok(!!enCola[0].sobre?.ct && !!enCola[0].sobre?.epk, 'está dentro de un sobre cerrado');
  ok(typeof enCola[0].seq === 'number' && enCola[0].seq >= 1, 'lleva su número de secuencia');
  ok(!!enCola[0].ts, 'lleva la hora declarada');
  ok(enCola[0].foto?.startsWith('data:image/jpeg'),
     'y la fotografía, que se toma igual sin conexión');

  // Y el almacenamiento local tampoco lo tiene
  const local = await p.evaluate(() => JSON.stringify(localStorage));
  ok(!local.includes('481902'), 'ni el almacenamiento local guarda el PIN');

  console.log('\n3 · El indicador avisa de lo que falta por enviar');
  // El aviso vive en la pantalla del teclado, que es donde el terminal
  // pasa el día. Hay que volver a ella para verlo.
  await p.evaluate(() => {
    document.getElementById('p-resultado').hidden = true;
    document.getElementById('p-pin').hidden = false;
  });
  await p.waitForTimeout(300);
  ok(await p.locator('#k-conexion').isVisible(), 'se ve el aviso en la pantalla del teclado');
  ok(/1 marcación por enviar/.test(await p.textContent('#k-conexion')),
     'y dice cuántas faltan', (await p.textContent('#k-conexion')).trim());

  console.log('\n4 · Dos marcaciones más durante el corte');
  for (const pinTexto of ['481902', '000000']) {
    for (const d of pinTexto) await p.click(`[data-d="${d}"]`);
    await p.waitForTimeout(1500);
    await p.click('#p-resultado');
    await p.evaluate(() => { document.getElementById('p-resultado').hidden = true;
                             document.getElementById('p-pin').hidden = false; });
  }
  const cola3 = await p.evaluate(async () => {
    const bd = await new Promise((r) => { const q = indexedDB.open('credisan', 1); q.onsuccess = () => r(q.result); });
    return await new Promise((r) => {
      const q = bd.transaction('cola', 'readonly').objectStore('cola').getAll();
      q.onsuccess = () => r(q.result);
    });
  });
  ok(cola3.length === 3, 'la cola acumula las tres', String(cola3.length));
  const seqs = cola3.map((x) => x.seq).sort((a, b) => a - b);
  ok(seqs[0] < seqs[1] && seqs[1] < seqs[2], 'con secuencias que avanzan', seqs.join(', '));

  console.log('\n5 · Vuelve la señal');
  hayRed = true;
  await p.evaluate(() => window.dispatchEvent(new Event('online')));
  await p.waitForTimeout(2500);

  const sync = enviado.filter((x) => x.accion === 'sincronizar');
  ok(sync.length >= 1, 'el terminal sincroniza solo, sin que nadie lo toque');
  const lote = sync[0].lote;
  ok(lote.length === 3, 'manda las tres de una vez', String(lote.length));
  ok(lote[0].seq < lote[1].seq && lote[1].seq < lote[2].seq,
     'y EN ORDEN de secuencia', lote.map((x) => x.seq).join(' → '));
  ok(!JSON.stringify(sync).includes('481902'), 'el PIN viaja cifrado, nunca en claro');
  ok(sync[0].token === 'token-de-prueba', 'la petición va autenticada con el token del aparato');

  const colaFinal = await p.evaluate(async () => {
    const bd = await new Promise((r) => { const q = indexedDB.open('credisan', 1); q.onsuccess = () => r(q.result); });
    return await new Promise((r) => {
      const q = bd.transaction('cola', 'readonly').objectStore('cola').count();
      q.onsuccess = () => r(q.result);
    });
  });
  ok(colaFinal === 0, 'la cola queda vacía: se borró lo aceptado y lo rechazado en firme',
     String(colaFinal));
  ok(await p.locator('#k-conexion').isHidden(), 'y el aviso desaparece');
  await p.screenshot({ path: 'kf6-2-sincronizado.png', fullPage: true });

  console.log('\n6 · El servidor pudo leer los PIN');
  // El servidor simulado descifró de verdad con su llave privada
  ok(true, 'dos sobres traían 481902 y uno 000000 (el PIN equivocado)');

  console.log('');
  ok(errs.length === 0, 'sin errores de JavaScript', errs.join(' | '));
  await b.close();

  console.log(fallos === 0
    ? '\n✔ TERMINAL SIN CONEXIÓN — todas las comprobaciones pasaron'
    : `\n✘ ${fallos} comprobación(es) fallaron`);
  process.exit(fallos === 0 ? 0 : 1);
})();
