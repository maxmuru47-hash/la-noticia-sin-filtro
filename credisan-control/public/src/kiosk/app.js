/* CrediSan Control · terminal de marcación
   ---------------------------------------------------------------------
   Lo que este archivo NO hace, y es deliberado:
     · no sabe la hora oficial — la pone el servidor
     · no sabe en qué sede está — la impone el terminal emparejado
     · no decide si alguien llegó puntual — eso se calcula en la base
     · no guarda ni un PIN: lo envía una vez y lo olvida
   Su trabajo es capturar seis dígitos y una foto, y enseñar el resultado. */

import { config, estaConfigurado, porNuestroCamino } from '../core/config.js';
import { cola, siguienteSeq, anclarReloj, ahoraFiable, cerrarSobre, sincronizar }
  from './offline.js';

const $ = (id) => document.getElementById(id);

const EVENTOS = {
  entrada:          'ENTRADA',
  salida_almuerzo:  'SALIDA A ALMUERZO',
  regreso_almuerzo: 'REGRESO DE ALMUERZO',
  salida:           'SALIDA'
};

const ESTADOS = {
  anticipado: { texto: 'ANTICIPADO', tono: 'bien',  icono: '★' },
  puntual:    { texto: 'PUNTUAL',    tono: 'bien',  icono: '✓' },
  tolerancia: { texto: 'A TIEMPO',   tono: 'bien',  icono: '✓' },
  retraso:    { texto: 'CON RETRASO',tono: 'aviso', icono: '!' },
  justificado:{ texto: 'JUSTIFICADO',tono: 'bien',  icono: '✓' }
};

const MOTIVOS = {
  TERMINAL_NO_AUTORIZADO: 'Este dispositivo ya no está vinculado. Avise a administración.',
  TERMINAL_INACTIVO: 'Este terminal está desactivado en el panel. En cuanto lo activen, '
                   + 'vuelve a funcionar solo: NO hace falta volver a vincularlo.',
  TERMINAL_INVALIDO:      'Terminal no reconocido.',
  PIN_INVALIDO:           'PIN incorrecto. Intente de nuevo.',
  EMPLEADO_INACTIVO:      'Su ficha está desactivada. Consulte con administración.',
  EMPLEADO_OTRA_SEDE:     'Su PIN pertenece a otra sede.',
  SIN_EVENTO_PENDIENTE:   'No hay marcaciones pendientes para hoy.',
  YA_REGISTRADO:          'Ya registró esa marcación.',
  TICKET_VENCIDO:         'Se acabó el tiempo. Vuelva a marcar su PIN.',
  TICKET_YA_USADO:        'Esa marcación ya se registró.',
  CODIGO_INVALIDO:        'Código incorrecto.',
  CODIGO_VENCIDO:         'El código ya venció. Pida uno nuevo.',
  CODIGO_YA_USADO:        'Ese código ya se usó. Pida uno nuevo.',
  CODIGO_REVOCADO:        'Ese código fue anulado. Pida uno nuevo.',
  TERMINAL_INEXISTENTE:   'No existe un terminal con ese código.',
  FALTA_PEPPER:           'Falta configurar el servidor. Avise a administración.',
  SIN_CONEXION:           'Sin conexión. Intente de nuevo en un momento.',
  SIN_LLAVE_PUBLICA:      'Este terminal no puede marcar sin conexión. Avise a administración.',
  FALTA_LLAVE_OFFLINE:    'Falta configurar el servidor. Avise a administración.',
  LOTE_DEMASIADO_GRANDE:  'Hay demasiadas marcaciones en espera. Avise a administración.'
};

const almacen = {
  leer:    (k) => { try { return localStorage.getItem('credisan.' + k); } catch { return null; } },
  guardar: (k, v) => { try { localStorage.setItem('credisan.' + k, v); } catch { /* modo privado */ } },
  borrar:  (k) => { try { localStorage.removeItem('credisan.' + k); } catch { /* nada */ } }
};

let dispositivo = {
  terminal: almacen.leer('terminal'),
  token:    almacen.leer('token'),
  sede:     almacen.leer('sede'),
  branch:   almacen.leer('branch')
};

let pin = '';
let sesion = null;        // identidad + ticket devueltos por el servidor
let flujo = null;         // cámara activa
let camaraPedida = false; // ¿la queremos encendida AHORA? (ver abrirCamara)
let regreso = null;       // temporizador de vuelta al teclado
let ocupado = false;

/* ── Comunicación con el servidor ──────────────────────────────────── */

async function llamar(cuerpo, cabeceras = {}) {
  const r = await fetch(config.supabaseUrl + '/functions/v1/credisan', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      apikey: config.supabaseAnonKey,
      Authorization: 'Bearer ' + config.supabaseAnonKey,
      ...cabeceras
    },
    body: JSON.stringify(cuerpo)
  });

  // Cada respuesta del servidor trae su fecha: es la única hora de fiar
  // que ve el terminal, y es la que ancla el reloj monotónico.
  const fecha = r.headers.get('date');
  if (fecha) anclarReloj(fecha);

  return r.json();
}

const explicar = (motivo) => MOTIVOS[motivo] || 'No se pudo completar la operación.';

/* ── Navegación entre pantallas ────────────────────────────────────── */

function mostrar(id, tono) {
  document.querySelectorAll('.pantalla').forEach((p) => { p.hidden = true; });
  const p = $(id);
  if (tono) p.dataset.tono = tono; else delete p.dataset.tono;
  p.hidden = false;
}

function aviso(el, texto, tipo = 'info') {
  el.textContent = texto;
  el.dataset.t = tipo;
}

/* ── Arranque ──────────────────────────────────────────────────────── */

if (!estaConfigurado()) {
  mostrar('p-emparejar');
  aviso($('aviso-emparejar'), 'Falta conectar el servidor en config/env.js.', 'error');
  $('form-emparejar').hidden = true;
} else if (dispositivo.token) {
  irAlTeclado();
} else {
  mostrar('p-emparejar');
}

/* ── Emparejamiento ────────────────────────────────────────────────── */

$('form-emparejar').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('btn-emparejar');
  btn.disabled = true;
  btn.textContent = 'Vinculando…';
  aviso($('aviso-emparejar'), '');

  try {
    const r = await llamar({
      accion: 'emparejar',
      terminal: $('e-terminal').value.trim().toUpperCase(),
      codigo: $('e-codigo').value.trim().toUpperCase(),
      dispositivo: $('e-dispositivo').value.trim()
    });

    if (!r.ok) {
      aviso($('aviso-emparejar'), explicar(r.motivo), 'error');
      return;
    }

    // La clave de firma se guardará cuando exista el modo sin conexión;
    // hoy sólo se conserva lo que hace falta para autenticar el aparato.
    dispositivo = { terminal: r.terminal, token: r.token, sede: r.sede, branch: r.branch_id };
    almacen.guardar('terminal', r.terminal);
    almacen.guardar('token', r.token);
    almacen.guardar('sede', r.sede);
    almacen.guardar('branch', r.branch_id);

    irAlTeclado();
  } catch {
    aviso($('aviso-emparejar'), explicar('SIN_CONEXION'), 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Vincular';
  }
});

/* ── Teclado ───────────────────────────────────────────────────────── */

function irAlTeclado() {
  pin = '';
  sesion = null;
  pintarPuntos();
  aviso($('aviso-pin'), '');
  $('pin-sede').textContent = dispositivo.sede
    ? `${dispositivo.sede} · ${dispositivo.terminal}` : dispositivo.terminal || '';
  $('pie').textContent = 'CrediSan Control · ' + (dispositivo.terminal || '');
  cerrarCamara();
  mostrar('p-pin');
}

function pintarPuntos() {
  document.querySelectorAll('#puntos .k-punto').forEach((p, i) => {
    p.dataset.lleno = i < pin.length ? '1' : '0';
  });
}

$('teclado').addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b || ocupado) return;

  if (b.dataset.accion === 'limpiar') { pin = ''; pintarPuntos(); aviso($('aviso-pin'), ''); return; }
  if (b.dataset.accion === 'borrar')  { pin = pin.slice(0, -1); pintarPuntos(); return; }

  if (pin.length < 6) {
    pin += b.dataset.d;
    pintarPuntos();
    aviso($('aviso-pin'), '');
    if (pin.length === 6) verificarPin();
  }
});

// Teclado físico, por si el terminal es una tablet con funda
document.addEventListener('keydown', (e) => {
  if ($('p-pin').hidden || ocupado) return;
  if (/^[0-9]$/.test(e.key) && pin.length < 6) {
    pin += e.key; pintarPuntos();
    if (pin.length === 6) verificarPin();
  } else if (e.key === 'Backspace') {
    pin = pin.slice(0, -1); pintarPuntos();
  }
});

async function verificarPin() {
  ocupado = true;
  aviso($('aviso-pin'), 'Un momento…');

  // Si el navegador ya sabe que no hay red, no se gasta el intento: se
  // encola directamente. Ahorra unos segundos de espera inútil delante
  // de una persona que sólo quiere marcar e irse.
  if (navigator.onLine === false) {
    const guardado = await marcarSinConexion();
    ocupado = false;
    return guardado;
  }

  // La cámara se enciende AQUÍ, no en la pantalla de identidad.
  //
  // Un sensor tarda entre medio segundo y segundo y medio en dar su
  // primera imagen. Encendiéndolo al pintar la identidad, quien se sabe
  // su PIN pulsa «Confirmar» antes de que haya nada que capturar, y su
  // marcación queda SIN FOTO. No es teoría: es de donde salen casi
  // todas las novedades automáticas de «marcación sin evidencia».
  //
  // Esta llamada al servidor —la que averigua quién es— es tiempo
  // muerto que ya estábamos gastando. El sensor calienta dentro de él.
  // Y no se enciende antes: sólo cuando alguien ya tecleó seis dígitos
  // y está marcando, que es el instante justo antes de su fotografía.
  //
  // Sin `await` a propósito: encender la cámara no debe retrasar ni un
  // milisegundo la identificación.
  abrirCamara();

  try {
    const r = await llamar({
      accion: 'pin',
      terminal: dispositivo.terminal,
      token: dispositivo.token,
      pin
    });

    if (!r.ok) {
      pin = '';
      pintarPuntos();
      cerrarCamara();            // el PIN no era: no se deja encendida
      aviso($('aviso-pin'), explicar(r.motivo), 'error');
      // Sólo se borra la vinculación cuando se perdió DE VERDAD. Si el
      // terminal está apagado desde el panel, su llave sigue siendo
      // buena: borrarla obligaría a emparejar de nuevo, y en una sede
      // eso es que nadie marca hasta que alguien con acceso al panel
      // genere un código. Un interruptor no debe costar una visita.
      if (r.motivo === 'TERMINAL_NO_AUTORIZADO') olvidarDispositivo();
      return;
    }

    pin = '';
    pintarPuntos();
    sesion = r;
    mostrarIdentidad(r);
  } catch {
    // La red se cayó a mitad. Se encola en vez de perder la marcación.
    await marcarSinConexion();
  } finally {
    ocupado = false;
  }
}

/* ── Marcar sin conexión ────────────────────────────────────────────
   Aquí NO se puede decir quién es ni si llegó a tiempo: el PIN no se
   puede comprobar sin el servidor. Se guarda cifrado y se avisa con
   claridad de que está guardada pero aún no confirmada. Prometer más
   sería mentir, y el trabajador se iría creyendo que marcó cuando
   quizá su PIN estaba mal. */

async function marcarSinConexion() {
  const elPin = pin;
  pin = '';
  pintarPuntos();

  if (!config.offlinePublicKey) {
    aviso($('aviso-pin'), explicar('SIN_LLAVE_PUBLICA'), 'error');
    return;
  }

  try {
    const reloj = ahoraFiable();
    const foto = await fotoRapida();

    await cola.guardar({
      id: crypto.randomUUID(),
      seq: siguienteSeq(),
      ts: reloj.ts,
      desfase: reloj.desfase,
      sobre: await cerrarSobre(elPin, config.offlinePublicKey),
      foto: foto || '',
      creado: Date.now()
    });

    mostrarGuardadaSinConexion(reloj);
  } catch (e) {
    aviso($('aviso-pin'), 'No se pudo guardar la marcación. Avise a administración.', 'error');
  }
  await pintarConexion();
}

/* Sin conexión no hay pantalla de identidad donde encender la cámara, así
   que se abre, se dispara y se cierra. Si no hay cámara, se encola sin
   foto: que falte la fotografía nunca impide marcar. */
async function fotoRapida() {
  try {
    await abrirCamara();
    if (!flujo) return '';
    await camaraLista();          // la misma espera que en línea, en un solo sitio
    const foto = capturarFoto();
    cerrarCamara();
    return foto || '';
  } catch {
    cerrarCamara();
    return '';
  }
}

function mostrarGuardadaSinConexion(reloj) {
  const hora = new Date(reloj.ts).toLocaleTimeString('es-VE',
    { hour: '2-digit', minute: '2-digit' });

  $('res-icono').textContent   = '⏱';
  $('res-nombre').textContent  = 'Marcación guardada';
  $('res-hora').textContent    = hora;
  $('res-estado').textContent  = 'SIN CONEXIÓN';
  $('res-detalle').textContent =
    'Se enviará sola cuando vuelva la señal. Si su PIN no fuera correcto, no quedará registrada.';

  mostrar('p-resultado', 'aviso');
  clearTimeout(regreso);
  regreso = setTimeout(irAlTeclado, 6000);
}

/* Si el dispositivo fue desvinculado desde el panel, el terminal vuelve a
   pedir emparejamiento en lugar de quedarse repitiendo el mismo error. */
function olvidarDispositivo() {
  ['terminal', 'token', 'sede', 'branch'].forEach(almacen.borrar);
  dispositivo = { terminal: null, token: null, sede: null, branch: null };
  setTimeout(() => mostrar('p-emparejar'), 2500);
}

/* ── Identidad y confirmación ──────────────────────────────────────── */

function mostrarIdentidad(r) {
  const iniciales = (r.nombre || '?').split(' ').map((p) => p[0]).slice(0, 2).join('');
  const retrato = $('retrato');

  // Las iniciales primero, siempre: si la foto tarda o no carga, el
  // trabajador ve algo en vez de un hueco. La foto las tapa cuando llega.
  retrato.textContent = iniciales.toUpperCase();
  retrato.removeAttribute('data-con-foto');

  if (r.foto_url) {
    const img = new Image();
    img.alt = '';
    img.onload = () => {
      // Comprobar que sigue siendo esta persona: si alguien marcó mientras
      // la foto viajaba, pegarla ahora enseñaría la cara equivocada.
      if ($('ident-nombre').textContent !== r.nombre) return;
      retrato.textContent = '';
      retrato.appendChild(img);
      retrato.dataset.conFoto = '1';
    };
    img.onerror = () => { /* se quedan las iniciales */ };
    // Igual que la del panel: la firma Supabase con su dominio y hay
    // que traerla por el camino de este sistema, o el trabajador ve sus
    // iniciales en vez de su cara.
    img.src = porNuestroCamino(r.foto_url);
  }

  $('ident-nombre').textContent = r.nombre;
  $('ident-cargo').textContent  = r.cargo || '';
  $('ident-evento').textContent = EVENTOS[r.evento] || r.evento;

  mostrar('p-identidad');
  abrirCamara();

  // Si nadie confirma, la pantalla no se queda con el nombre de alguien a la vista.
  clearTimeout(regreso);
  regreso = setTimeout(irAlTeclado, 25000);
}

$('btn-cancelar').addEventListener('click', () => { clearTimeout(regreso); irAlTeclado(); });
$('btn-confirmar').addEventListener('click', confirmar);

async function confirmar() {
  if (!sesion || ocupado) return;
  ocupado = true;
  clearTimeout(regreso);
  $('btn-confirmar').textContent = 'Registrando…';

  // El botón ya dice «Registrando…», así que esta espera no es una
  // pantalla congelada: es el tiempo que el sensor necesita, y sólo se
  // gasta cuando de verdad hace falta.
  await camaraLista();
  const foto = capturarFoto();
  cerrarCamara();

  try {
    const r = await llamar({
      accion: 'confirmar',
      terminal: dispositivo.terminal,
      token: dispositivo.token,
      ticket: sesion.ticket,
      evento_id: crypto.randomUUID(),
      branch_id: dispositivo.branch,
      foto: foto || '',
      motivo_sin_foto: foto ? '' : 'camara_no_disponible'
    });

    if (!r.ok) { mostrarError(explicar(r.motivo)); return; }
    mostrarResultado(r);
  } catch {
    mostrarError(explicar('SIN_CONEXION'));
  } finally {
    ocupado = false;
    $('btn-confirmar').textContent = 'Confirmar';
  }
}

function mostrarResultado(r) {
  const e = ESTADOS[r.status] || { texto: String(r.status || '').toUpperCase(), tono: 'aviso', icono: '•' };

  $('res-icono').textContent  = e.icono;
  $('res-nombre').textContent = sesion.nombre;
  $('res-hora').textContent   = (r.recorded_local || '').slice(0, 5);
  $('res-estado').textContent = e.texto;

  const partes = [EVENTOS[r.event] || r.event];
  if (r.delta_minutes > 0) partes.push(`${r.delta_minutes} min después de lo previsto`);
  if (r.delta_minutes < 0) partes.push(`${Math.abs(r.delta_minutes)} min antes`);
  if (r.evidencia === 'sin_evidencia') partes.push('sin fotografía');
  $('res-detalle').textContent = partes.join(' · ');

  mostrar('p-resultado', e.tono);
  clearTimeout(regreso);
  regreso = setTimeout(irAlTeclado, 5000);
}

function mostrarError(texto) {
  $('res-icono').textContent  = '✕';
  $('res-nombre').textContent = sesion?.nombre || '';
  $('res-hora').textContent   = '';
  $('res-estado').textContent = 'NO REGISTRADO';
  $('res-detalle').textContent = texto;
  mostrar('p-resultado', 'mal');
  clearTimeout(regreso);
  regreso = setTimeout(irAlTeclado, 5000);
}

/* ── Cámara ────────────────────────────────────────────────────────── */
/* Que falte la foto NUNCA impide marcar: se registra la marcación y el
   sistema abre una novedad automática para que administración lo revise. */

async function abrirCamara() {
  if (!navigator.mediaDevices?.getUserMedia) return;
  camaraPedida = true;
  // Ya encendida: no se vuelve a pedir. Pedirla otra vez reemplazaría el
  // flujo y tiraría a la basura el calentamiento que se acaba de ganar.
  if (flujo) { $('camara').hidden = false; return; }
  try {
    const nuevo = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } },
      audio: false
    });

    // MIENTRAS EL PERMISO VIAJABA PUDIERON CANCELAR.
    //
    // Encender la cámara tarda, y en ese rato el PIN pudo salir
    // equivocado o la pantalla pudo volver al teclado. `cerrarCamara()`
    // ya se ejecutó, pero sobre un flujo que TODAVÍA NO EXISTÍA: no
    // apagó nada, y el sensor se encendía después, solo, con el
    // terminal en reposo y su luz puesta delante de la gente.
    //
    // Por eso no basta con mirar `flujo`: hay que preguntar si a estas
    // alturas seguimos queriéndola.
    if (!camaraPedida) { nuevo.getTracks().forEach((t) => t.stop()); return; }

    flujo = nuevo;
    $('video').srcObject = flujo;
    $('camara').hidden = false;
  } catch {
    flujo = null;
    $('camara').hidden = true;
  }
}

function cerrarCamara() {
  camaraPedida = false;
  if (flujo) { flujo.getTracks().forEach((t) => t.stop()); flujo = null; }
  $('video').srcObject = null;
  $('camara').hidden = true;
}

/* Esperar a que el sensor dé su PRIMERA imagen, con tope.
   ---------------------------------------------------------------------
   `videoWidth` sigue siendo 0 hasta que llega ese primer fotograma,
   aunque `getUserMedia` ya haya contestado. Capturar antes devuelve
   nada.

   El tope no es un detalle: la regla de este terminal es que una foto
   que falla NUNCA impide marcar. Así que se espera un poco —lo que
   tarda un sensor normal— y, si no llega, se marca sin fotografía
   exactamente como antes. Nadie se queda delante de una tableta
   esperando a una cámara. */
async function camaraLista(tope = 1200) {
  const hasta = Date.now() + tope;
  while (Date.now() < hasta) {
    if (flujo && $('video').videoWidth) return true;
    await new Promise((r) => setTimeout(r, 40));
  }
  return !!(flujo && $('video').videoWidth);
}

function capturarFoto() {
  const video = $('video');
  if (!flujo || !video.videoWidth) return null;
  try {
    const lado = Math.min(video.videoWidth, video.videoHeight);
    const destino = 640;
    const lienzo = document.createElement('canvas');
    lienzo.width = lienzo.height = destino;
    lienzo.getContext('2d').drawImage(
      video,
      (video.videoWidth - lado) / 2, (video.videoHeight - lado) / 2, lado, lado,
      0, 0, destino, destino
    );
    return lienzo.toDataURL('image/jpeg', 0.7);
  } catch {
    return null;
  }
}

/* ── Reloj y modo kiosco ───────────────────────────────────────────── */

function reloj() {
  $('pin-reloj').textContent = new Date().toLocaleTimeString('es-VE', {
    hour: '2-digit', minute: '2-digit'
  });
}
reloj();
setInterval(reloj, 15000);

// La pantalla no debe apagarse mientras el terminal está en servicio.
if ('wakeLock' in navigator) {
  const mantener = () => navigator.wakeLock.request('screen').catch(() => null);
  mantener();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') mantener();
  });
}

// Un roce accidental no debe abrir menús ni seleccionar texto.
document.addEventListener('contextmenu', (e) => e.preventDefault());

/* ── Sincronización de lo que se marcó sin conexión ─────────────────
   Se intenta al arrancar, cuando el navegador avisa de que volvió la red,
   y cada dos minutos por si el aviso no llega —que en tabletas pasa—.
   Nunca interrumpe a quien está marcando. */

let sincronizando = false;

async function pintarConexion() {
  const caja = $('k-conexion');
  if (!caja) return;

  let n = 0;
  try { n = await cola.contar(); } catch { n = 0; }

  if (sincronizando && n) {
    caja.dataset.t = 'enviando';
    $('k-conexion-texto').textContent = `Enviando ${n} marcación${n === 1 ? '' : 'es'}…`;
    caja.hidden = false;
  } else if (n) {
    delete caja.dataset.t;
    $('k-conexion-texto').textContent =
      `${n} marcación${n === 1 ? '' : 'es'} por enviar`;
    caja.hidden = false;
  } else if (navigator.onLine === false) {
    delete caja.dataset.t;
    $('k-conexion-texto').textContent = 'Sin conexión';
    caja.hidden = false;
  } else {
    caja.hidden = true;
  }
}

async function intentarSincronizar() {
  if (sincronizando || !dispositivo.token || navigator.onLine === false) return;
  if (!(await cola.contar())) { pintarConexion(); return; }

  sincronizando = true;
  await pintarConexion();

  try {
    // Lotes sucesivos mientras quede algo y el servidor siga aceptando:
    // tras un corte largo puede haber más de un lote en espera.
    for (let vuelta = 0; vuelta < 10; vuelta++) {
      const r = await sincronizar(llamar, dispositivo);
      // Igual que arriba: un terminal apagado conserva su vinculación y
      // la cola espera. Lo que se marcó sin conexión no se pierde.
      if (r.motivo === 'TERMINAL_NO_AUTORIZADO') { olvidarDispositivo(); break; }
      if (r.motivo === 'TERMINAL_INACTIVO') break;
      if (!r.quedan || (!r.aceptadas && !r.rechazadas)) break;
    }
  } catch {
    // Sigue sin haber red. Se reintenta en el próximo aviso.
  } finally {
    sincronizando = false;
    await pintarConexion();
  }
}

window.addEventListener('online',  () => { pintarConexion(); intentarSincronizar(); });
window.addEventListener('offline', pintarConexion);
setInterval(intentarSincronizar, 120000);

// Al arrancar: pintar el estado y vaciar lo que haya quedado de ayer.
pintarConexion();
setTimeout(intentarSincronizar, 1500);

/* ── El terminal se guarda a sí mismo ───────────────────────────────
   Esto faltaba, y sin ello la Fase 6 no servía de nada: el service
   worker sólo se registraba desde la portada, así que un aparato de
   mostrador —que siempre abre esta página y ninguna otra— nunca llegaba
   a guardar nada. Sin internet no cargaba ni el teclado.

   El archivo vive en la raíz para poder cubrir todo el sitio; desde
   aquí se registra subiendo un nivel y fijando ese alcance. */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('../sw.js', { scope: '/' })
      .catch(() => { /* sin service worker se marca igual, pero sólo con red */ });
  });
}
