/* CrediSan Control · terminal sin conexión
   ---------------------------------------------------------------------
   Tres piezas, y cada una resuelve un problema concreto:

   1. EL SOBRE CERRADO. Sin conexión el terminal no puede comprobar un PIN:
      la pimienta vive en el servidor y jamás baja al aparato. Guardar el
      PIN en claro mientras espera sería regalar el PIN de toda la sede a
      quien se lleve la tableta. Así que se cifra con la llave pública del
      servidor: el aparato guarda algo que ni él mismo puede volver a leer.

   2. EL RELOJ MONOTÓNICO. La hora del sistema se puede cambiar a mano. Si
      alguien atrasa el reloj de la tableta, sus marcaciones saldrían
      puntuales. Por eso la hora de cada marcación se calcula desde un
      ancla tomada la última vez que hubo servidor, más lo que ha corrido
      un contador que nadie puede mover. El desfase contra el reloj del
      sistema se manda también: si es grande, el servidor abre una novedad.

   3. LA COLA. En IndexedDB, no en localStorage: hay fotografías de por
      medio y localStorage se queda corto enseguida.
*/

const BD = 'credisan';
const ALMACEN = 'cola';
const INFO = 'credisan.reloj';
const SEQ  = 'credisan.seq';

/* ── Cola en IndexedDB ──────────────────────────────────────────────── */

function abrirBD() {
  return new Promise((listo, falla) => {
    const p = indexedDB.open(BD, 1);
    p.onupgradeneeded = () => {
      const bd = p.result;
      if (!bd.objectStoreNames.contains(ALMACEN)) {
        bd.createObjectStore(ALMACEN, { keyPath: 'id' }).createIndex('seq', 'seq');
      }
    };
    p.onsuccess = () => listo(p.result);
    p.onerror   = () => falla(p.error);
  });
}

function conAlmacen(modo, hacer) {
  return abrirBD().then((bd) => new Promise((listo, falla) => {
    const tx = bd.transaction(ALMACEN, modo);
    const pedido = hacer(tx.objectStore(ALMACEN));
    tx.oncomplete = () => listo(pedido?.result);
    tx.onerror    = () => falla(tx.error);
  }));
}

export const cola = {
  guardar: (item) => conAlmacen('readwrite', (a) => a.put(item)),
  todo:    ()     => conAlmacen('readonly',  (a) => a.getAll()),
  borrar:  (id)   => conAlmacen('readwrite', (a) => a.delete(id)),
  contar:  ()     => conAlmacen('readonly',  (a) => a.count())
};

/* ── Secuencia monotónica del terminal ──────────────────────────────── */
/* El servidor rechaza cualquier secuencia que no avance. Es lo que impide
   que alguien reenvíe una marcación vieja haciéndola pasar por nueva. */

export function siguienteSeq() {
  let n = 0;
  try { n = parseInt(localStorage.getItem(SEQ) || '0', 10) || 0; } catch { n = 0; }
  n += 1;
  try { localStorage.setItem(SEQ, String(n)); } catch { /* modo privado */ }
  return n;
}

/* ── Reloj monotónico ───────────────────────────────────────────────── */

/* Se llama cada vez que el servidor contesta: ahí se sabe la hora buena. */
export function anclarReloj(horaServidor) {
  try {
    localStorage.setItem(INFO, JSON.stringify({
      servidor: new Date(horaServidor).getTime(),
      monotono: Math.round(performance.now())
    }));
  } catch { /* modo privado */ }
}

/* Devuelve la hora que se declara, y cuánto se desvía el reloj del
   sistema. Si nunca hubo ancla, no queda más remedio que creerle al
   sistema, y se dice que el desfase es desconocido. */
export function ahoraFiable() {
  let ancla = null;
  try { ancla = JSON.parse(localStorage.getItem(INFO) || 'null'); } catch { ancla = null; }
  const sistema = Date.now();

  if (!ancla || typeof ancla.servidor !== 'number') {
    return { ts: new Date(sistema).toISOString(), desfase: null, anclado: false };
  }

  const transcurrido = Math.round(performance.now()) - ancla.monotono;
  const estimada = ancla.servidor + transcurrido;

  return {
    ts: new Date(estimada).toISOString(),
    desfase: Math.round((sistema - estimada) / 1000),   // segundos
    anclado: true
  };
}

/* ── El sobre cerrado ───────────────────────────────────────────────── */

const b64 = (b) => btoa(String.fromCharCode(...new Uint8Array(b)));
const bin = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

export async function cerrarSobre(pin, publicaBase64) {
  if (!publicaBase64) throw new Error('SIN_LLAVE_PUBLICA');

  const publicaServidor = await crypto.subtle.importKey(
    'spki', bin(publicaBase64), { name: 'ECDH', namedCurve: 'P-256' }, false, []);

  // Un par nuevo por cada sobre: dos marcaciones del mismo PIN no se
  // pueden relacionar entre sí mirando la cola.
  const efimero = await crypto.subtle.generateKey(
    { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);

  const compartido = await crypto.subtle.deriveBits(
    { name: 'ECDH', public: publicaServidor }, efimero.privateKey, 256);

  const material = await crypto.subtle.importKey('raw', compartido, 'HKDF', false, ['deriveKey']);
  const llave = await crypto.subtle.deriveKey(
    { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0),
      info: new TextEncoder().encode('credisan-pin-offline-v1') },
    material, { name: 'AES-GCM', length: 256 }, false, ['encrypt']);

  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv }, llave, new TextEncoder().encode(pin));

  return {
    epk: b64(await crypto.subtle.exportKey('raw', efimero.publicKey)),
    iv:  b64(iv),
    ct:  b64(ct)
  };
}

/* ── Sincronización ─────────────────────────────────────────────────── */

/* Manda lo encolado y limpia lo que el servidor resolvió. Lo que falló por
   red se queda para el próximo intento; lo que el servidor rechazó por un
   motivo definitivo se borra, porque reintentarlo sería eterno. */
export async function sincronizar(llamar, dispositivo, maximo = 50) {
  const pendientes = (await cola.todo()).sort((a, b) => a.seq - b.seq);
  if (!pendientes.length) return { enviadas: 0, aceptadas: 0, rechazadas: 0, quedan: 0 };

  const lote = pendientes.slice(0, maximo);
  const r = await llamar({
    accion: 'sincronizar',
    terminal: dispositivo.terminal,
    token: dispositivo.token,
    branch_id: dispositivo.branch,
    lote: lote.map((x) => ({
      id: x.id, seq: x.seq, ts: x.ts, drift: x.desfase, sobre: x.sobre, foto: x.foto || ''
    }))
  });

  if (!r.ok) return { enviadas: lote.length, aceptadas: 0, rechazadas: 0,
                      quedan: pendientes.length, motivo: r.motivo };

  let aceptadas = 0, rechazadas = 0;
  for (const res of r.resultados || []) {
    if (res.ok) { aceptadas++; await cola.borrar(res.id); }
    else if (res.definitivo) { rechazadas++; await cola.borrar(res.id); }
  }

  return { enviadas: lote.length, aceptadas, rechazadas, quedan: await cola.contar() };
}
