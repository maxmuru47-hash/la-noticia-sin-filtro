/* CrediSan Control · terminal de marcación
   ---------------------------------------------------------------------
   Lo que este archivo NO hace, y es deliberado:
     · no sabe la hora oficial — la pone el servidor
     · no sabe en qué sede está — la impone el terminal emparejado
     · no decide si alguien llegó puntual — eso se calcula en la base
     · no guarda ni un PIN: lo envía una vez y lo olvida
   Su trabajo es capturar seis dígitos y una foto, y enseñar el resultado. */

import { config, estaConfigurado } from '../core/config.js';

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
  SIN_CONEXION:           'Sin conexión. Intente de nuevo en un momento.'
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

  try {
    const r = await llamar({
      accion: 'pin',
      terminal: dispositivo.terminal,
      token: dispositivo.token,
      pin
    });

    pin = '';
    pintarPuntos();

    if (!r.ok) {
      aviso($('aviso-pin'), explicar(r.motivo), 'error');
      if (r.motivo === 'TERMINAL_NO_AUTORIZADO') olvidarDispositivo();
      return;
    }

    sesion = r;
    mostrarIdentidad(r);
  } catch {
    pin = '';
    pintarPuntos();
    aviso($('aviso-pin'), explicar('SIN_CONEXION'), 'error');
  } finally {
    ocupado = false;
  }
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
  retrato.textContent = iniciales.toUpperCase();
  retrato.innerHTML = retrato.textContent;   // sin foto de ficha todavía

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
  try {
    flujo = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } },
      audio: false
    });
    $('video').srcObject = flujo;
    $('camara').hidden = false;
  } catch {
    flujo = null;
    $('camara').hidden = true;
  }
}

function cerrarCamara() {
  if (flujo) { flujo.getTracks().forEach((t) => t.stop()); flujo = null; }
  $('camara').hidden = true;
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
