/* CrediSan Control · panel administrativo
   ---------------------------------------------------------------------
   Este archivo pinta y pide datos. No decide permisos: cada consulta va
   filtrada por las políticas de la base de datos, así que lo que se
   oculta aquí es por comodidad, nunca por seguridad. */

import { config, estaConfigurado } from '../core/config.js';
import { crearCliente, traducir } from '../core/supabase.js';

const $ = (id) => document.getElementById(id);

// Enseñar u ocultar algo sin romperse si ese algo no está en esta
// versión del HTML. Devuelve el elemento por si hace falta seguir.
const ver = (id, visible) => {
  const el = $(id);
  if (el) el.hidden = !visible;
  return el;
};
const DIAS = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
const ROLES = { ceo: 'Dirección general', admin: 'Administración',
                supervisor: 'Jefe operativo', socio: 'Socio' };

const ESTADOS_HOY = {
  presente:   'Presente',
  completo:   'Jornada completa',
  anticipado: 'Llegó antes',
  retrasado:  'Llegó tarde',
  faltante:   'No ha llegado',
  esperado:   'Aún no es su hora',
  descanso:   'Descanso'
};

const sb = crearCliente();
let yo = null;
let sedes = [];
let editando = null;
let periodo = 'hoy';
let filtroNovedades = 'pendiente';
let tiposNovedad = [];
let conHorarioPropio = new Set();   // quién no hace el horario de su sede

/* ── Utilidades ─────────────────────────────────────────────────────── */

function aviso(el, texto, tipo = 'error') {
  el.textContent = texto;
  el.dataset.t = tipo;
  el.hidden = !texto;
}

function avisoPanel(texto, tipo = 'ok') {
  const el = $('panel-aviso');
  aviso(el, texto, tipo);
  if (texto) {
    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    clearTimeout(avisoPanel._t);
    avisoPanel._t = setTimeout(() => { el.hidden = true; }, 6000);
  }
}

const esc = (t) => String(t ?? '').replace(/[&<>"']/g,
  (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

function ocupado(boton, si, textoOriginal) {
  boton.disabled = si;
  boton.textContent = si ? 'Un momento…' : textoOriginal;
}

const puedeEditar = () => yo && (yo.rol === 'ceo' || yo.rol === 'admin');
const esCeo = () => yo && yo.rol === 'ceo';
const esSocio = () => yo && yo.rol === 'socio';
// El socio mira el cierre; quien lo revisa y lo cierra es administración.
const puedeVerCierres = () => puedeEditar() || esSocio();

// La fotografía de una marcación es de lo más sensible que guarda el
// sistema: la cara de una persona. La matriz de roles aprobada la deja
// en dirección y administración. El jefe operativo ve QUIÉN marcó, a qué
// hora y si fue con foto o sin ella; si algo no le cuadra lo reporta
// como novedad, y administración mira.
//
// Esto oculta el botón. Quien decide de verdad es `evidencia_de`, en la
// base de datos: ocultar aquí es por cortesía, nunca por seguridad.
const puedeVerEvidencia = () => puedeEditar();

function rango(cual) {
  const hoy = new Date();
  const fin = hoy.toISOString().slice(0, 10);
  const ini = new Date(hoy);
  if (cual === 'semana') ini.setDate(hoy.getDate() - 6);
  if (cual === 'mes')    ini.setDate(hoy.getDate() - 29);
  return { desde: ini.toISOString().slice(0, 10), hasta: fin };
}

/* ── Arranque y acceso ──────────────────────────────────────────────── */

if (!estaConfigurado() || !sb) {
  aviso($('acceso-aviso'),
    'Falta conectar el servidor: edite config/env.js con los datos de Supabase.', 'error');
  $('form-acceso').hidden = true;
} else {
  const { data } = await sb.auth.getSession();
  if (data.session) await entrar();
}

$('form-acceso').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('btn-entrar');
  aviso($('acceso-aviso'), '');
  ocupado(btn, true, 'Entrar');
  const { error } = await sb.auth.signInWithPassword({
    email: $('correo').value.trim(), password: $('clave').value
  });
  ocupado(btn, false, 'Entrar');
  if (error) { aviso($('acceso-aviso'), traducir(error)); return; }
  await entrar();
});

$('form-ceo').addEventListener('submit', async (e) => {
  e.preventDefault();
  const { data, error } = await sb.rpc('reclamar_ceo', { p_nombre: $('nombre-ceo').value.trim() });
  if (error)    { aviso($('acceso-aviso'), traducir(error)); return; }
  if (!data.ok) { aviso($('acceso-aviso'), traducir(new Error(data.reason))); return; }
  $('form-ceo').hidden = true;
  await entrar();
});

$('btn-salir').addEventListener('click', async () => {
  await sb.auth.signOut();
  location.reload();
});

/* POR QUÉ ESTA FUNCIÓN ES TAN CUIDADOSA AL FALLAR
   ---------------------------------------------------------------------
   Es lo primero que corre al abrir el panel con una sesión guardada, y
   de ella depende que a alguien le toque volver a escribir su
   contraseña o no.

   Dos tropiezos PASAJEROS se trataban como si fueran definitivos:

     · Un fallo de red al arrancar —un teléfono con mala señal, el wifi
       de la sede reconectando— dejaba al usuario mirando el formulario
       de acceso con su sesión intacta guardada al lado.

     · `SIN_SESION` quiere decir que ESA petición viajó sin credencial:
       normalmente porque el token estaba renovándose justo entonces.
       Y la respuesta era `signOut()`, que BORRA la sesión de verdad.
       Es decir: ante la duda, el panel destruía lo que quizá estaba
       perfectamente bien, y convertía un parpadeo en «vuelva a escribir
       su contraseña».

   Ahora se reintenta una vez, y sólo se cierra la sesión cuando el
   servidor dice algo DEFINITIVO —que el acceso está desactivado, por
   ejemplo—. Ante una duda pasajera, la sesión se deja en paz. */
async function entrar(reintento = false) {
  const { data, error } = await sb.rpc('mi_perfil');

  if ((error || data?.reason === 'SIN_SESION') && !reintento) {
    await new Promise((r) => setTimeout(r, 1200));
    return entrar(true);
  }

  if (error) { aviso($('acceso-aviso'), traducir(error)); return; }

  if (!data.ok) {
    if (data.reason === 'SIN_PERFIL') {
      const { data: r } = await sb.rpc('reclamar_ceo', { p_nombre: '' });
      if (r?.reason === 'YA_HAY_USUARIOS') {
        aviso($('acceso-aviso'),
          'Su usuario no tiene acceso asignado. Pida a dirección que lo registre en «Accesos».');
        await sb.auth.signOut();
      } else {
        $('form-acceso').hidden = true;
        $('form-ceo').hidden = false;
      }
      return;
    }
    aviso($('acceso-aviso'), traducir(new Error(data.reason)));
    // `INACTIVO` es definitivo y sí cierra. `SIN_SESION`, no: borrar la
    // sesión ahí es exactamente lo que hacía que hubiera que teclear la
    // contraseña otra vez.
    if (data.reason !== 'SIN_SESION') await sb.auth.signOut();
    return;
  }

  yo = data;
  $('mi-nombre').textContent = yo.nombre;
  // El sello con el que se publicó este programa. Lo pone el despliegue
  // en la dirección del script (app.js?v=abc123) y aquí se lee de vuelta.
  //
  // Está a la vista por una razón práctica: cuando algo «no aparece»,
  // la primera pregunta es si el navegador está corriendo la versión
  // nueva o una guardada de antes. Sin este dato, esa pregunta sólo se
  // puede contestar adivinando, y se pierde media tarde.
  const sello = (document.currentScript?.src
                 || import.meta.url || '').split('?v=')[1] || 'local';

  $('mi-rol').textContent = ROLES[yo.rol]
    + (yo.sede ? ' · ' + yo.sede : ' · Nacional')
    + ' · v' + sello;
  $('pantalla-acceso').hidden = true;
  $('pantalla-panel').hidden = false;

  // El jefe operativo tiene un panel deliberadamente corto: la operación
  // del día y las novedades. Nada de horarios, sedes ni estadísticas.
  //
  // Se usa `ver()` y no `$(id).hidden` a propósito. Si por lo que sea el
  // HTML de este navegador es de una versión y este programa de otra,
  // uno de estos identificadores no existirá, `$()` devolverá null y la
  // línea reventaría llevándose por delante TODO lo que viene después:
  // las sedes, el tablero, la sesión entera. El panel se quedaría en
  // «Cargando» para siempre por un botón que falta.
  //
  // Pasó de verdad. Un elemento ausente puede costar un botón invisible;
  // nunca la aplicación.
  ver('nav-cierres',        puedeVerCierres());
  ver('nav-mas',            puedeEditar());
  ver('bloque-accesos',     esCeo());
  ver('bloque-socios',      esCeo());
  // Calcular la semana la lanza quien la revisa, no quien la consulta.
  ver('btn-calcular-semana', puedeEditar());
  ver('btn-nueva-sede',     esCeo());
  ver('btn-nuevo-empleado', puedeEditar());
  ver('caja-salario',       puedeEditar());
  ver('periodos',           puedeEditar());
  // El socio carga el permiso que dio gerencia —con el documento—, así
  // que el botón vuelve para él. Lo que no puede es reportar novedades
  // operativas: eso se resuelve limitándole los tipos, no el botón.
  ver('btn-novedad-rapida', true);

  // Dentro de «Más», sólo dirección crea sedes y reparte accesos; la
  // auditoría la ven dirección y administración, cada una lo suyo.
  const irSedes = document.querySelector('[data-ir="v-sedes"]');
  if (irSedes) irSedes.hidden = !puedeEditar();

  await cargarSedes();
  if (esCeo()) cargarSocios();
  await cargarHoy();
  refrescarInsignia();      // el aviso, sin tener que entrar a mirar
}

/* ── Navegación ─────────────────────────────────────────────────────── */

// Las vistas que no tienen botón propio abajo cuelgan de «Más»; mientras
// se está en una de ellas, «Más» se queda encendido para no perder el sitio.
const DENTRO_DE_MAS = {
  'v-horarios': 'v-mas', 'v-sedes': 'v-mas',
  'v-auditoria': 'v-mas', 'v-reportes': 'v-mas', 'v-conexion': 'v-mas'
};

const CARGADORES = {
  'v-hoy': cargarHoy,
  'v-personal': cargarPersonal,
  'v-novedades': cargarNovedades,
  'v-cierres': cargarCierres,
  'v-horarios': cargarHorario,
  'v-auditoria': cargarAuditoria,
  'v-conexion': cargarConexion,
  'v-sedes': () => { cargarSedes(); cargarTerminales(); }
};

function mostrar(vista) {
  document.querySelectorAll('.vista').forEach((v) => { v.hidden = true; });
  $(vista).hidden = false;

  const encendido = DENTRO_DE_MAS[vista] || vista;
  document.querySelectorAll('.nav button').forEach((o) =>
    o.setAttribute('aria-current', o.dataset.vista === encendido ? 'true' : 'false'));

  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (CARGADORES[vista]) CARGADORES[vista]();
}

document.querySelectorAll('.nav button').forEach((b) => {
  b.addEventListener('click', () => mostrar(b.dataset.vista));
});
document.querySelectorAll('[data-ir]').forEach((b) => {
  b.addEventListener('click', () => mostrar(b.dataset.ir));
});
document.querySelectorAll('[data-volver]').forEach((b) => {
  b.addEventListener('click', () => mostrar(b.dataset.volver));
});

document.querySelectorAll('[data-cerrar]').forEach((b) => {
  b.addEventListener('click', () => { $(b.dataset.cerrar).hidden = true; });
});

/* ── HOY · la operación del día ─────────────────────────────────────── */

$('hoy-sede').addEventListener('change', cargarHoy);

document.querySelectorAll('#periodos button').forEach((b) => {
  b.addEventListener('click', () => {
    periodo = b.dataset.periodo;
    document.querySelectorAll('#periodos button').forEach((o) => o.setAttribute('aria-current', 'false'));
    b.setAttribute('aria-current', 'true');
    cargarHoy();
  });
});

function kpi(valor, etiqueta, tono) {
  return `<div class="kpi" data-t="${tono}"><strong>${valor}</strong><span>${etiqueta}</span></div>`;
}

async function cargarHoy() {
  const verPeriodo = periodo !== 'hoy' && puedeEditar();
  $('bloque-hoy').hidden = verPeriodo;
  $('bloque-periodo').hidden = !verPeriodo;
  // El comparativo abarca todas las sedes visibles, así que el selector de
  // sede no pinta nada ahí: dejarlo puesto sugeriría que filtra, y no filtra.
  $('hoy-sede-caja').hidden = verPeriodo || sedes.length < 2;
  if (verPeriodo) return cargarPeriodo();

  const sede = $('hoy-sede').value || yo.branch_id || sedes[0]?.id;
  if (!sede) { $('hoy-lista').innerHTML = '<p class="vacio">No hay sedes visibles.</p>'; return; }

  $('hoy-lista').innerHTML = '<p class="cargando">Cargando…</p>';
  const { data, error } = await sb.rpc('tablero_hoy', { p_branch: sede });
  if (error) { $('hoy-lista').innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  // Si la respuesta no trae la forma esperada —una base a medio actualizar,
  // por ejemplo— se avisa en vez de reventar: una excepción aquí se lleva
  // por delante el resto del arranque del panel.
  if (!data || !data.resumen) {
    $('hoy-lista').innerHTML = '<p class="vacio">El tablero de hoy no está disponible todavía. Revise <a href="../index.html">estado del sistema</a>.</p>';
    return;
  }

  const r = data.resumen;
  $('hoy-kpis').innerHTML =
      kpi(r.presentes,  'Presentes',  r.presentes > 0 ? 'bien' : 'info')
    + kpi(r.faltantes,  'Faltantes',  r.faltantes > 0 ? 'mal' : 'bien')
    + kpi(r.retrasados, 'Retrasados', r.retrasados > 0 ? 'aviso' : 'bien')
    + kpi(r.novedades,  'Novedades',  r.novedades > 0 ? 'aviso' : 'info');

  const fecha = new Date(data.fecha + 'T12:00:00').toLocaleDateString('es-VE',
    { weekday: 'long', day: 'numeric', month: 'long' });
  $('hoy-titulo').textContent = `${data.sede} · ${fecha}`;

  if (!data.empleados.length) {
    $('hoy-lista').innerHTML = '<p class="vacio">Esta sede todavía no tiene trabajadores registrados.</p>';
    return;
  }

  $('hoy-lista').innerHTML = data.empleados.map((e) => `
    <div class="ficha"${(puedeVerEvidencia() && e.evento_id && e.evidencia === 'almacenada')
      ? ` data-ver-foto="${e.evento_id}" data-nombre="${esc(e.nombre)}" style="cursor:pointer"` : ''}>
      <span class="estado-punto" data-e="${e.estado}"></span>
      <div class="ficha__cuerpo">
        <strong>${esc(e.nombre)}</strong>
        <span>${esc(e.cargo)} · ${ESTADOS_HOY[e.estado] || e.estado}</span>
        ${e.evidencia === 'sin_evidencia'
          ? '<span class="pastilla pastilla--pin" style="margin-top:.25rem">Sin foto</span>' : ''}
        ${e.origen === 'offline'
          ? '<span class="pastilla pastilla--pin" style="margin-top:.25rem">Sin conexión</span>' : ''}
      </div>
      <div class="horas">
        ${e.entrada ? `<strong>${e.entrada}</strong>` : (e.esperada ? e.esperada : '—')}
        ${e.entrada && e.minutos ? `<br>${e.minutos > 0 ? '+' : ''}${e.minutos} min` : ''}
        ${e.salida ? `<br>↩ ${e.salida}` : ''}
        ${(puedeVerEvidencia() && e.evento_id && e.evidencia === 'almacenada')
          ? '<br><span class="ver-foto">📷 ver</span>' : ''}
      </div>
    </div>`).join('');

  $('hoy-lista').querySelectorAll('[data-ver-foto]').forEach((f) =>
    f.addEventListener('click', () => verEvidencia(f.dataset.verFoto, f.dataset.nombre)));
}

/* ── Comparativo del período ────────────────────────────────────────── */

async function cargarPeriodo() {
  const { desde, hasta } = rango(periodo);
  $('periodo-sedes').innerHTML = '<p class="cargando">Cargando…</p>';

  const { data, error } = await sb.rpc('tablero_periodo', {
    p_desde: desde, p_hasta: hasta,
    p_branch: esCeo() ? null : yo.branch_id
  });
  if (error) { $('periodo-sedes').innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  const t = data.total;
  $('periodo-kpis').innerHTML =
      kpi(t.marcaciones, 'Marcaciones', 'info')
    + kpi(t.puntualidad === null ? '—' : t.puntualidad + '%', 'Puntualidad',
          t.puntualidad === null ? 'info' : t.puntualidad >= 90 ? 'bien' : t.puntualidad >= 75 ? 'aviso' : 'mal')
    + kpi(t.retrasos, 'Retrasos', t.retrasos > 0 ? 'aviso' : 'bien')
    + kpi(t.ausencias, 'Ausencias', t.ausencias > 0 ? 'mal' : 'bien');

  const tope = Math.max(...data.sedes.map((s) => s.marcaciones || 0), 1);
  $('periodo-sedes').innerHTML = data.sedes.map((s) => `
    <div class="ficha" style="display:block">
      <div style="display:flex;align-items:baseline;gap:.5rem">
        <strong style="flex:1">${esc(s.sede)}</strong>
        <span class="horas"><strong>${s.puntualidad === null ? '—' : s.puntualidad + '%'}</strong> puntualidad</span>
      </div>
      <span style="font-size:.82rem;color:var(--texto-suave)">
        ${s.marcaciones} marcaciones · ${s.retrasos} retrasos · ${s.ausencias} ausencias
        · ${s.trabajadores} ${s.trabajadores === 1 ? 'trabajador' : 'trabajadores'}
      </span>
      <div class="barra"><i style="width:${Math.round(100 * (s.marcaciones || 0) / tope)}%"></i></div>
    </div>`).join('');

  // Las ausencias salen del resumen diario. Si nunca se ha calculado, ese
  // cero no significa "no faltó nadie": significa "no se ha mirado".
  const falta = !data.resumen_diario_calculado;
  $('aviso-recalculo').hidden = !falta;
  $('btn-recalcular').hidden = !falta || !puedeEditar();
  if (falta) {
    aviso($('aviso-recalculo'),
      'Las ausencias de este período aún no se han calculado. El cero de arriba no es un dato: es que nadie lo ha mirado todavía.',
      'info');
  }

  const { data: top } = await sb.rpc('ranking_puntualidad', {
    p_desde: desde, p_hasta: hasta,
    p_branch: esCeo() ? null : yo.branch_id, p_limite: 5
  });
  $('periodo-ranking').innerHTML = (top && top.length) ? top.map((e, i) => `
    <div class="ficha">
      <div class="ficha__inicial" style="background:${i === 0 ? 'var(--amarillo)' : 'var(--morado)'};
           color:${i === 0 ? 'var(--negro)' : 'var(--blanco)'}">${i + 1}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(e.nombre)}</strong>
        <span>${esc(e.cargo)} · ${esc(e.sede)}</span>
      </div>
      <div class="horas"><strong>${e.puntualidad}%</strong><br>${e.marcaciones} marcaciones</div>
    </div>`).join('')
    : '<p class="vacio">Todavía no hay marcaciones suficientes para un ranking.</p>';
}

$('btn-recalcular').addEventListener('click', async (ev) => {
  const { desde, hasta } = rango(periodo);
  const btn = ev.currentTarget;
  ocupado(btn, true, 'Calcular ausencias del período');
  const sede = yo.branch_id || $('hoy-sede').value || sedes[0]?.id;
  const { error } = await sb.rpc('recalcular_rango', { p_branch: sede, p_desde: desde, p_hasta: hasta });
  ocupado(btn, false, 'Calcular ausencias del período');
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel('Período calculado.');
  cargarPeriodo();
});

/* ── NOVEDADES ──────────────────────────────────────────────────────── */

document.querySelectorAll('#filtro-novedades button').forEach((b) => {
  b.addEventListener('click', () => {
    filtroNovedades = b.dataset.estado;
    document.querySelectorAll('#filtro-novedades button')
      .forEach((o) => o.setAttribute('aria-current', 'false'));
    b.setAttribute('aria-current', 'true');
    cargarNovedades();
  });
});

async function cargarNovedades() {
  const caja = $('lista-novedades');
  caja.innerHTML = '<p class="cargando">Cargando…</p>';

  // Un socio no pertenece a una sede sino a varias: se pide sin filtro y
  // la base le entrega las suyas y sólo las suyas.
  const { data, error } = await sb.rpc('novedades', {
    p_branch: (esCeo() || esSocio()) ? null : yo.branch_id,
    p_estado: filtroNovedades || null
  });
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.length) {
    caja.innerHTML = `<p class="vacio">${filtroNovedades === 'pendiente'
      ? 'No hay novedades pendientes. Todo al día.' : 'Todavía no hay novedades.'}</p>`;
    return;
  }

  caja.innerHTML = data.map((n) => `
    <div class="novedad" data-e="${n.estado}">
      <h3>${esc(n.empleado)}</h3>
      <p>${esc(n.tipo_nombre)} — ${esc(n.descripcion)}</p>
      <div class="novedad__pie">
        <span>${esc(n.sede)}</span>
        <span>·</span>
        <span>${new Date(n.desde).toLocaleString('es-VE',
          { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
        ${n.origen === 'sistema'
          ? '<span class="pastilla pastilla--pin">Automática</span>'
          : `<span>· ${esc(n.reportada_por || '')}</span>`}
        ${n.tiene_documento
          ? '<span class="pastilla pastilla--documento">Con documento</span>'
          : (n.requiere_documento
              ? '<span class="pastilla pastilla--falta">Falta el documento</span>' : '')}
        <span class="pastilla pastilla--${n.estado === 'aprobada' ? 'activo' : 'inactivo'}"
              style="margin-left:auto">${n.estado}</span>
      </div>
      ${n.nota ? `<p style="margin-top:.5rem;font-size:.82rem">«${esc(n.nota)}»</p>` : ''}
      ${(n.estado === 'pendiente' && n.requiere_documento && !n.tiene_documento) ? `
        <p class="ayuda" style="margin:.5rem 0 0">
          Sin el documento no se puede aprobar, y hasta que no se apruebe el día
          no queda justificado.</p>` : ''}
      <div class="novedad__acciones">
        ${n.tiene_documento
          ? `<button data-documento="${n.id}">Ver documento</button>` : ''}
        ${(n.estado === 'pendiente' && n.tiene_documento === false)
          ? `<button data-adjuntar="${n.id}" data-sede="${n.branch_id}">Adjuntar documento</button>` : ''}
        ${(n.estado === 'pendiente' && puedeEditar()) ? `
          <button class="btn-rechazar" data-rechazar="${n.id}">Rechazar</button>
          ${n.puede_aprobarse !== false
            ? `<button class="btn-aprobar" data-aprobar="${n.id}">Aprobar</button>` : ''}` : ''}
      </div>
    </div>`).join('');

  caja.querySelectorAll('[data-aprobar]').forEach((b) =>
    b.addEventListener('click', () => resolver(b.dataset.aprobar, true)));
  caja.querySelectorAll('[data-rechazar]').forEach((b) =>
    b.addEventListener('click', () => resolver(b.dataset.rechazar, false)));
  caja.querySelectorAll('[data-documento]').forEach((b) =>
    b.addEventListener('click', () => verDocumento(b.dataset.documento, b)));
  caja.querySelectorAll('[data-adjuntar]').forEach((b) =>
    b.addEventListener('click', () => adjuntarDocumento(b.dataset.adjuntar, b.dataset.sede, b)));

  refrescarInsignia();
}

/* ── EL DOCUMENTO DE UNA NOVEDAD ────────────────────────────────────
   Un permiso lo da gerencia y un reposo lo da un médico: ninguno de los
   dos nace en el mostrador. Aquí se suben, se abren y se cuentan.

   Un detalle del listado que parece un descuido y no lo es: el botón
   Aprobar se esconde con `puede_aprobarse !== false`, no con
   `puede_aprobarse`. Si el servidor no manda ese dato —un panel abierto
   antes de la actualización, por ejemplo— se ofrece igual, y quien
   rechaza es la base. Al revés, un navegador con el programa viejo se
   quedaría sin poder aprobar NADA, y esconder un botón nunca debe costar
   una función. */

// Una foto de un papel no necesita doce megas, pero tampoco puede salir
// recortada en cuadrado como un retrato: se escala entera.
function encogerDocumento(archivo, lado = 1600) {
  return new Promise((listo, falla) => {
    const img = new Image();
    img.onload = () => {
      const escala = Math.min(1, lado / Math.max(img.width, img.height));
      const lienzo = document.createElement('canvas');
      lienzo.width  = Math.round(img.width  * escala);
      lienzo.height = Math.round(img.height * escala);
      lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
      URL.revokeObjectURL(img.src);
      lienzo.toBlob((b) => b ? listo(b) : falla(new Error('no se pudo convertir')),
                    'image/jpeg', 0.85);
    };
    img.onerror = () => falla(new Error('no es una imagen'));
    img.src = URL.createObjectURL(archivo);
  });
}

// Sube el archivo y devuelve la ruta. La sede va delante: la regla de
// seguridad sólo deja escribir dentro de la carpeta de la sede que se ve.
async function subirDocumento(archivo, sede) {
  const esPdf = archivo.type === 'application/pdf';
  const cuerpo = esPdf ? archivo : await encogerDocumento(archivo);
  const nombre = (crypto.randomUUID?.() || String(Date.now())) + (esPdf ? '.pdf' : '.jpg');
  const ruta = `${sede}/${nombre}`;

  const { error } = await sb.storage.from('novedades')
    .upload(ruta, cuerpo, { contentType: esPdf ? 'application/pdf' : 'image/jpeg' });
  if (error) throw error;
  return ruta;
}

function pedirArchivo() {
  return new Promise((listo) => {
    const entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.accept = 'application/pdf,image/*';
    entrada.addEventListener('change', () => listo(entrada.files?.[0] || null));
    entrada.click();
  });
}

async function adjuntarDocumento(id, sede, boton) {
  const archivo = await pedirArchivo();
  if (!archivo) return;
  const texto = boton.textContent;
  ocupado(boton, true, texto);
  try {
    const ruta = await subirDocumento(archivo, sede);
    const { error } = await sb.rpc('adjuntar_documento', { p_id: id, p_ruta: ruta });
    if (error) throw error;
    avisoPanel('Documento adjuntado. Administración ya puede resolverla.');
    cargarNovedades();
  } catch (err) {
    avisoPanel(traducir(err), 'error');
  } finally {
    ocupado(boton, false, texto);
  }
}

// Pedir la ruta y abrirla son dos pasos a propósito: el primero deja
// anotado en la auditoría quién abrió el papel de un médico.
async function verDocumento(id, boton) {
  const texto = boton.textContent;
  ocupado(boton, true, texto);
  try {
    const { data, error } = await sb.rpc('documento_de_novedad', { p_id: id });
    if (error) throw error;
    if (!data.ok) { avisoPanel('Esta novedad no tiene documento.', 'error'); return; }

    const { data: firmada, error: e2 } = await sb.storage.from('novedades')
      .createSignedUrl(data.ruta, 120);
    if (e2) throw e2;
    window.open(firmada.signedUrl, '_blank', 'noopener');
  } catch (err) {
    avisoPanel(traducir(err), 'error');
  } finally {
    ocupado(boton, false, texto);
  }
}

// «Que administración sea notificada» es, dentro del sistema, que el
// número esté a la vista sin tener que ir a mirar.
async function refrescarInsignia() {
  const el = $('insignia-novedades');
  if (!el) return;
  const { data, error } = await sb.rpc('novedades_pendientes', { p_branch: null });
  if (error || !data?.ok || !data.total) { el.hidden = true; return; }
  el.textContent = data.total > 99 ? '99+' : String(data.total);
  el.title = data.sin_documento
    ? `${data.listas} por resolver · ${data.sin_documento} esperando documento`
    : `${data.total} por resolver`;
  el.hidden = false;
}

async function resolver(id, aprobar) {
  const nota = prompt(aprobar
    ? 'Nota de la aprobación (opcional):'
    : 'Motivo del rechazo (opcional):');
  if (nota === null) return;               // canceló

  const { data, error } = await sb.rpc('resolver_novedad',
    { p_id: id, p_aprobar: aprobar, p_nota: nota });
  if (error)    { avisoPanel(traducir(error), 'error'); return; }
  if (!data.ok) { avisoPanel('No se pudo resolver: puede que ya la hayan revisado.', 'error'); return; }

  avisoPanel(aprobar ? 'Novedad aprobada.' : 'Novedad rechazada.');
  cargarNovedades();
}

/* Los tipos de novedad, contra una base de cualquiera de las dos edades.
   ---------------------------------------------------------------------
   Las dos columnas que dicen qué tipo exige documento y cuál puede
   cargar un socio llegaron con la fase 13. Si el panel se publica antes
   que la base —o si alguien abre una copia guardada en el teléfono—,
   pedirlas hace fallar la consulta ENTERA y el desplegable de tipos sale
   vacío: una pantalla rota por un dato de adorno.

   Así que se piden, y si la base todavía no las tiene se vuelve a
   preguntar sin ellas. El panel sigue sirviendo; lo que falta son los
   avisos, no la función. */
async function cargarTiposNovedad() {
  const util = (d) => (d || []).filter((t) => t.is_active && !t.system_only);
  const base = 'code, label, sort_order, system_only, is_active';

  const { data, error } = await sb.from('incident_kinds')
    .select(base + ', requiere_documento, socio_puede').order('sort_order');
  if (!error) return util(data);

  const { data: viejo } = await sb.from('incident_kinds').select(base).order('sort_order');
  return util(viejo);
}

$('btn-nueva-novedad').addEventListener('click', abrirNovedad);
$('btn-novedad-rapida').addEventListener('click', () => {
  document.querySelector('.nav button[data-vista="v-novedades"]').click();
  abrirNovedad();
});

async function abrirNovedad() {
  if (!tiposNovedad.length) tiposNovedad = await cargarTiposNovedad();

  // El socio carga autorizaciones —permiso, reposo, comisión, salida—,
  // no novedades operativas. Ofrecerle las demás sería ofrecerle botones
  // que la base le va a rechazar.
  const ofrecidos = esSocio() ? tiposNovedad.filter((t) => t.socio_puede) : tiposNovedad;
  $('nov-tipo').innerHTML = ofrecidos
    .map((t) => `<option value="${t.code}">${esc(t.label)}</option>`).join('');
  ayudaDocumento();

  // La sede sale del trabajador elegido: es donde va a guardarse el
  // documento, y la regla de seguridad la comprueba por la ruta.
  let q = sb.from('employees').select('id, first_name, last_name, branch_id').order('last_name');
  if (!esCeo() && !esSocio()) q = q.eq('branch_id', yo.branch_id);
  const { data: emp } = await q;
  $('nov-empleado').innerHTML = (emp || [])
    .map((e) => `<option value="${e.id}" data-sede="${e.branch_id}">${esc(e.first_name)} ${esc(e.last_name)}</option>`)
    .join('');

  $('nov-documento').value = '';
  $('form-novedad').hidden = false;
  $('form-novedad').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Decirlo ANTES de rellenar el formulario, no después de rechazarlo.
function ayudaDocumento() {
  const el = $('ayuda-documento');
  if (!el) return;
  const t = tiposNovedad.find((x) => x.code === $('nov-tipo').value);
  if (esSocio()) {
    el.textContent = 'Obligatorio: el socio carga el documento que respalda la autorización.';
  } else if (t?.requiere_documento) {
    el.textContent = 'Este tipo no se puede APROBAR sin el documento. '
                   + 'Puede registrarlo ahora y adjuntarlo cuando llegue el papel.';
  } else {
    el.textContent = 'Opcional. Puede ser un PDF o una foto del papel.';
  }
}

$('nov-tipo').addEventListener('change', ayudaDocumento);

$('form-novedad').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  const archivo = $('nov-documento').files?.[0] || null;

  if (esSocio() && !archivo) {
    avisoPanel('Adjunte el documento: un socio carga el papel, no el reporte.', 'error');
    return;
  }

  ocupado(btn, true, 'Registrar');
  try {
    // El archivo PRIMERO, y la novedad con su ruta: la base comprueba
    // que el documento exista de verdad antes de aceptarla.
    let ruta = null;
    if (archivo) {
      const sede = $('nov-empleado').selectedOptions[0]?.dataset.sede;
      if (!sede) throw new Error('No se pudo determinar la sede del trabajador.');
      ruta = await subirDocumento(archivo, sede);
    }

    const cuando = $('nov-cuando').value;
    const { error } = await sb.rpc('registrar_novedad', {
      p_employee: $('nov-empleado').value,
      p_tipo: $('nov-tipo').value,
      p_descripcion: $('nov-descripcion').value.trim(),
      p_desde: cuando ? new Date(cuando).toISOString() : null,
      p_evidencia: ruta
    });
    if (error) throw error;

    avisoPanel(archivo
      ? 'Novedad registrada con su documento. Queda pendiente de revisión.'
      : 'Novedad registrada. Queda pendiente de revisión.');
    e.target.reset();
    e.target.hidden = true;
    cargarNovedades();
  } catch (err) {
    avisoPanel(traducir(err), 'error');
  } finally {
    ocupado(btn, false, 'Registrar');
  }
});

/* ── SEDES Y TERMINALES ─────────────────────────────────────────────── */

async function cargarSedes() {
  const { data, error } = await sb.rpc('resumen_sedes');
  if (error) { $('lista-sedes').innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  sedes = data || [];
  $('lista-sedes').innerHTML = sedes.length ? sedes.map((s) => `
    <div class="ficha">
      <div class="ficha__inicial">${esc(s.code)}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(s.name)}</strong>
        <span>${s.empleados} ${s.empleados === 1 ? 'trabajador' : 'trabajadores'} ·
              ${s.terminales} ${s.terminales === 1 ? 'terminal' : 'terminales'}
              ${s.emparejados ? '(' + s.emparejados + ' activo)' : '(sin vincular)'}</span>
      </div>
    </div>`).join('') : '<p class="vacio">No hay sedes visibles.</p>';

  const opciones = sedes.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  ['emp-sede', 'horario-sede', 'usr-sede', 'hoy-sede', 'cierre-sede']
    .forEach((id) => { $(id).innerHTML = opciones; });

  // Estos dos sí admiten «todas»: un reporte o una auditoría de varias
  // sedes tiene sentido; cerrar la semana de varias a la vez, no.
  const conTodas = (sedes.length > 1 ? '<option value="">Todas las sedes</option>' : '') + opciones;
  ['filtro-sede', 'rep-sede', 'audit-sede'].forEach((id) => { $(id).innerHTML = conTodas; });

  pintarSedesDeSocio();

  $('filtro-sede-caja').hidden = sedes.length < 2;
  $('hoy-sede-caja').hidden = sedes.length < 2;

  if (yo.branch_id) {
    ['emp-sede', 'horario-sede', 'hoy-sede', 'cierre-sede']
      .forEach((id) => { $(id).value = yo.branch_id; });
    $('emp-sede').disabled = !esCeo();          // nadie da de alta fuera de su sede
  }
}

async function cargarTerminales() {
  const caja = $('lista-terminales');
  const { data, error } = await sb.rpc('terminales');
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.length) { caja.innerHTML = '<p class="vacio">No hay terminales.</p>'; return; }

  caja.innerHTML = data.map((t) => `
    <div class="ficha">
      <div class="ficha__inicial" style="font-size:.72rem">${esc(t.code.split('-')[1] || '01')}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(t.code)} · ${esc(t.sede)}</strong>
        <span>${t.emparejado
          ? esc(t.device_label || 'Dispositivo vinculado') + ' · ' + t.marcaciones_hoy + ' marcaciones hoy'
          : 'Sin vincular · esperando dispositivo'}</span>
        <span style="margin-top:.25rem;display:block">
          <span class="pastilla pastilla--${t.emparejado ? 'activo' : 'inactivo'}">
            ${t.emparejado ? 'Vinculado' : 'Sin vincular'}</span>
        </span>
      </div>
      ${puedeEditar() ? (t.emparejado
        ? `<button class="ficha__accion" data-desvincular="${t.id}" data-code="${esc(t.code)}">Desvincular</button>`
        : `<button class="ficha__accion" data-emparejar="${t.id}" data-code="${esc(t.code)}">Vincular</button>`) : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-emparejar]').forEach((b) =>
    b.addEventListener('click', () => generarCodigo(b.dataset.emparejar, b.dataset.code)));
  caja.querySelectorAll('[data-desvincular]').forEach((b) =>
    b.addEventListener('click', () => desvincular(b.dataset.desvincular, b.dataset.code)));
}

async function generarCodigo(id, code) {
  const { data, error } = await sb.rpc('crear_codigo_emparejamiento', { p_terminal: id });
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  revelar('Código de vinculación',
    `En el teléfono de la sede, abra <strong>${location.origin}/kiosk/</strong>, escriba
     el terminal <strong>${esc(code)}</strong> y este código:`,
    data, 'Vale 10 minutos y se usa una sola vez. Si vence, genere otro.');
  await cargarTerminales();
}

async function desvincular(id, code) {
  if (!confirm(`¿Desvincular el dispositivo de ${code}?\n\nEl teléfono dejará de poder marcar en el acto. Las marcaciones ya registradas no se tocan.`)) return;
  const { error } = await sb.rpc('desemparejar_terminal', { p_terminal: id });
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel('Dispositivo desvinculado. Su credencial ya no sirve.');
  await cargarTerminales();
}

function revelar(titulo, texto, codigo, nota) {
  $('rev-titulo').textContent = titulo;
  $('rev-texto').innerHTML = texto;
  $('rev-codigo').textContent = codigo;
  $('rev-vence').textContent = nota;
  $('revelacion').hidden = false;
}
$('rev-cerrar').addEventListener('click', () => { $('revelacion').hidden = true; });

$('btn-nueva-sede').addEventListener('click', () => {
  $('form-sede').hidden = false;
  $('sede-codigo').focus();
});

$('form-sede').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  ocupado(btn, true, 'Crear sede');
  const { data, error } = await sb.rpc('crear_sede', {
    p_code: $('sede-codigo').value, p_name: $('sede-nombre').value, p_terminal: null
  });
  ocupado(btn, false, 'Crear sede');
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel(`Sede creada, con su horario estándar y el terminal ${data.terminal}.`);
  e.target.reset();
  e.target.hidden = true;
  await cargarSedes();
  await cargarTerminales();
});

/* ── PERSONAL ───────────────────────────────────────────────────────── */

$('filtro-sede').addEventListener('change', cargarPersonal);

async function cargarPersonal() {
  const caja = $('lista-personal');
  caja.innerHTML = '<p class="cargando">Cargando…</p>';

  let q = sb.from('employees')
    .select('id, branch_id, internal_code, first_name, last_name, national_id, position, hired_on, is_active, pin_updated_at, photo_path')
    .order('last_name');
  const filtro = $('filtro-sede').value;
  if (filtro) q = q.eq('branch_id', filtro);

  const { data, error } = await q;
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.length) {
    caja.innerHTML = '<p class="vacio">Todavía no hay trabajadores registrados.</p>';
    return;
  }

  // Quién se mide con un horario distinto al de su sede. Se marca en la
  // lista a propósito: nadie debería enterarse de que a alguien se le
  // mide distinto sólo si se le ocurre abrir su ficha.
  const { data: propios } = await sb.rpc('horarios_propios', { p_branch: filtro || null });
  conHorarioPropio = new Set(Array.isArray(propios) ? propios : []);

  const nombreSede = (id) => sedes.find((s) => s.id === id)?.name || '';

  caja.innerHTML = data.map((e) => `
    <div class="ficha">
      <div class="ficha__inicial" data-retrato="${e.id}">${esc((e.first_name[0] || '') + (e.last_name[0] || ''))}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(e.first_name)} ${esc(e.last_name)}</strong>
        <span>${esc(e.position)} · ${esc(nombreSede(e.branch_id))} · ${esc(e.internal_code)}</span>
        <span style="margin-top:.25rem;display:block">
          <span class="pastilla pastilla--${e.is_active ? 'activo' : 'inactivo'}">
            ${e.is_active ? 'Activo' : 'Inactivo'}</span>
          ${e.pin_updated_at ? '' : '<span class="pastilla pastilla--pin">PIN pendiente</span>'}
          ${conHorarioPropio.has(e.id) ? '<span class="pastilla pastilla--horario">Horario propio</span>' : ''}
        </span>
      </div>
      ${puedeEditar() ? `<div style="display:grid;gap:.35rem">
        <button class="ficha__accion" data-editar="${e.id}">Editar</button>
        <button class="ficha__accion" data-pin="${e.id}">${e.pin_updated_at ? 'Nuevo PIN' : 'Dar PIN'}</button>
        <button class="ficha__accion" data-foto="${e.id}">${e.photo_path ? 'Cambiar foto' : 'Poner foto'}</button>
        <button class="ficha__accion" data-horario="${e.id}">Horario</button>
      </div>` : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-editar]').forEach((b) =>
    b.addEventListener('click', () => abrirEmpleado(data.find((x) => x.id === b.dataset.editar))));
  caja.querySelectorAll('[data-pin]').forEach((b) =>
    b.addEventListener('click', () => generarPin(data.find((x) => x.id === b.dataset.pin), b)));
  caja.querySelectorAll('[data-foto]').forEach((b) =>
    b.addEventListener('click', () => pedirFoto(data.find((x) => x.id === b.dataset.foto), b)));
  caja.querySelectorAll('[data-horario]').forEach((b) =>
    b.addEventListener('click', () => abrirHorarioPropio(data.find((x) => x.id === b.dataset.horario))));

  // Las fotos que ya existen, cada una con su URL firmada. Se piden
  // después de pintar la lista para que la lista salga ya.
  data.filter((e) => e.photo_path).forEach((e) => pintarRetrato(e));

  // Si alguien está sin PIN, conviene saber si es que falta dárselo o si
  // es que el sistema todavía no puede dárselo a nadie.
  if (puedeEditar() && data.some((e) => !e.pin_updated_at)) {
    comprobarFuncion().then(avisoFuncion);
  }
}

/* ── HORARIO PROPIO DE UN TRABAJADOR ────────────────────────────────
   El horario de la sede está en «Más → Horarios». Éste es el de una
   persona, y por eso vive en su ficha.

   El formulario arranca con el horario que HOY se le aplica —el suyo si
   lo tiene, el de la sede si no—, para que cambiar una hora de entrada
   sea cambiar una hora, no rellenar catorce casillas. */

let horarioPropioDe = null;             // a quién se le está editando

async function abrirHorarioPropio(emp) {
  if (!emp) return;
  horarioPropioDe = emp;
  const form = $('form-horario-propio');
  const caja = $('dias-horario-propio');

  $('horario-propio-titulo').textContent = `Horario de ${emp.first_name} ${emp.last_name}`;
  $('horario-propio-origen').textContent = '';
  aviso($('horario-propio-aviso'), '');
  caja.innerHTML = '<p class="cargando">Cargando…</p>';
  ver('btn-quitar-horario-propio', false);
  form.hidden = false;
  form.scrollIntoView({ behavior: 'smooth', block: 'start' });

  const { data, error } = await sb.rpc('horario_de_trabajador', { p_employee: emp.id });
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  $('horario-propio-origen').textContent = data.propio
    ? 'Hoy se le mide con su propio horario.'
    : `Hoy se le mide con el horario general de ${data.sede}. Si guarda, pasará a tener el suyo.`;
  ver('btn-quitar-horario-propio', !!data.propio);

  // Los siete días, vengan o no en la respuesta: un horario sin definir
  // el domingo no puede dejar el domingo fuera del formulario.
  const porDia = new Map((data.dias || []).map((d) => [Number(d.dia), d]));
  caja.innerHTML = [0, 1, 2, 3, 4, 5, 6].map((n) => {
    const d = porDia.get(n) || {};
    const hora = (v, porDefecto) => (v ? String(v).slice(0, 5) : porDefecto);
    return `
    <div class="dia" data-dia="${n}">
      <div class="dia__cabeza">
        <strong>${DIAS[n]}</strong>
        <label class="suiche">
          <input type="checkbox" data-campo="trabaja" ${d.trabaja ? 'checked' : ''}
                 aria-label="${DIAS[n]} laborable"><i></i>
        </label>
      </div>
      <div class="dia__horas" ${d.trabaja ? '' : 'hidden'}>
        <div style="grid-column:1/-1">
          <label>
            <input type="checkbox" data-campo="corrida" ${d.continua ? 'checked' : ''}
                   style="width:auto;min-height:0;margin-right:.4rem">
            Jornada corrida (sin almuerzo)
          </label>
        </div>
        <div><label>Entrada</label>
          <input type="time" data-campo="entrada" value="${hora(d.entrada, '08:00')}"></div>
        <div data-almuerzo ${d.continua ? 'hidden' : ''}><label>Salida a almuerzo</label>
          <input type="time" data-campo="salida_almuerzo" value="${hora(d.salida_almuerzo, '12:00')}"></div>
        <div data-almuerzo ${d.continua ? 'hidden' : ''}><label>Regreso</label>
          <input type="time" data-campo="regreso" value="${hora(d.regreso, '14:00')}"></div>
        <div><label>Salida</label>
          <input type="time" data-campo="salida" value="${hora(d.salida, '18:00')}"></div>
      </div>
    </div>`;
  }).join('');

  // A diferencia del horario de sede, aquí un día no se guarda solo: se
  // guardan los siete de una vez, al pulsar Guardar.
  caja.querySelectorAll('.dia').forEach((fila) => {
    const dato = (campo) => fila.querySelector(`[data-campo="${campo}"]`);
    dato('trabaja').addEventListener('change', (e) => {
      fila.querySelector('.dia__horas').hidden = !e.target.checked;
    });
    dato('corrida').addEventListener('change', (e) => {
      fila.querySelectorAll('[data-almuerzo]').forEach((n) => { n.hidden = e.target.checked; });
    });
  });
}

function leerDiasHorarioPropio() {
  return [...$('dias-horario-propio').querySelectorAll('.dia')].map((fila) => {
    const dato = (campo) => fila.querySelector(`[data-campo="${campo}"]`);
    const trabaja = dato('trabaja').checked;
    const continua = trabaja && dato('corrida').checked;
    return {
      dia: Number(fila.dataset.dia),
      trabaja,
      continua,
      entrada: trabaja ? dato('entrada').value : null,
      salida:  trabaja ? dato('salida').value  : null,
      salida_almuerzo: trabaja && !continua ? dato('salida_almuerzo').value : null,
      regreso:         trabaja && !continua ? dato('regreso').value : null
    };
  });
}

$('form-horario-propio').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!horarioPropioDe) return;
  const btn = e.target.querySelector('button[type=submit]');
  ocupado(btn, true, 'Guardar horario');
  aviso($('horario-propio-aviso'), '');

  const { error } = await sb.rpc('dar_horario_propio', {
    p_employee: horarioPropioDe.id,
    p_dias: leerDiasHorarioPropio()
  });
  ocupado(btn, false, 'Guardar horario');

  if (error) { aviso($('horario-propio-aviso'), traducir(error)); return; }
  $('form-horario-propio').hidden = true;
  avisoPanel('Horario guardado. Rige desde hoy.');
  await cargarPersonal();
});

$('btn-quitar-horario-propio').addEventListener('click', async () => {
  if (!horarioPropioDe) return;
  const quien = `${horarioPropioDe.first_name} ${horarioPropioDe.last_name}`;
  if (!confirm(`¿Devolver a ${quien} al horario general de su sede?\n\n`
             + 'Rige desde hoy. Los días ya cerrados se quedan como se midieron.')) return;

  const { error } = await sb.rpc('quitar_horario_propio', { p_employee: horarioPropioDe.id });
  if (error) { aviso($('horario-propio-aviso'), traducir(error)); return; }
  $('form-horario-propio').hidden = true;
  avisoPanel(`${quien} vuelve al horario de su sede.`);
  await cargarPersonal();
});

/* ── ¿Está publicada la función del servidor? ───────────────────────
   Sin ella no hay PIN, y el panel enseña a todo el personal como «PIN
   pendiente» sin decir por qué. Se comprueba una vez al entrar y se
   avisa arriba de la lista, donde se está mirando el problema. */

let funcionPublicada = null;          // null = todavía no se sabe

async function comprobarFuncion() {
  if (funcionPublicada !== null) return funcionPublicada;
  try {
    const r = await fetch(config.supabaseUrl + '/functions/v1/credisan', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        apikey: config.supabaseAnonKey,
        Authorization: 'Bearer ' + config.supabaseAnonKey
      },
      body: JSON.stringify({ accion: 'estado' })
    });
    const t = await r.text();
    if (t.includes('FALTA_PEPPER')) funcionPublicada = 'sin_pimienta';
    else if (r.ok) {
      const d = JSON.parse(t);
      funcionPublicada = d.pimienta ? true : 'sin_pimienta';
    } else {
      funcionPublicada = false;
    }
  } catch {
    funcionPublicada = false;
  }
  return funcionPublicada;
}

function avisoFuncion() {
  const caja = $('aviso-funcion');
  if (!caja) return;
  if (funcionPublicada === true || funcionPublicada === null) { caja.hidden = true; return; }

  caja.innerHTML = funcionPublicada === 'sin_pimienta'
    ? '<strong>Falta el secreto del PIN.</strong> La función del servidor está publicada, '
      + 'pero le falta <code>CREDISAN_PIN_PEPPER</code>. Sin él no se puede generar ningún PIN.'
    : '<strong>Todavía no se pueden generar PIN.</strong> La función del servidor no está '
      + 'publicada, y por eso todo el personal aparece como «PIN pendiente». '
      + 'Se resuelve en GitHub → <em>Actions</em> → <em>Poner en marcha CrediSan</em>. '
      + 'Está explicado paso a paso en <code>docs/EMPEZAR-AQUI.md</code>.';
  caja.dataset.t = 'error';
  caja.hidden = false;
}

/* ── Foto de ficha ──────────────────────────────────────────────────
   Es la que ve el trabajador en el terminal al marcar, y la que permite
   comprobar de un vistazo que quien marcó fue quien dice el PIN. */

async function pintarRetrato(e) {
  const caja = document.querySelector(`[data-retrato="${e.id}"]`);
  if (!caja || !e.photo_path) return;
  // El depósito es privado: no hay URL fija, hay que firmarla cada vez.
  const { data } = await sb.storage.from('empleados').createSignedUrl(e.photo_path, 600);
  if (!data?.signedUrl) return;
  const img = new Image();
  img.alt = '';
  img.onload = () => { caja.textContent = ''; caja.appendChild(img); caja.dataset.conFoto = '1'; };
  img.src = data.signedUrl;
}

/* La foto se encoge AQUÍ, antes de subir. Una cámara de teléfono da 4 MB
   por retrato; en el mostrador eso serían varios segundos de espera cada
   vez que alguien marca, y la pantalla de identidad dura cuatro. */
function encoger(archivo, lado = 400) {
  return new Promise((listo, falla) => {
    const img = new Image();
    img.onload = () => {
      // Recorte cuadrado centrado del original y escalado, en una sola
      // operación: el terminal la enseña dentro de un círculo, así que
      // una foto apaisada tiene que perder los lados, no deformarse.
      const corte = Math.min(img.width, img.height);
      const sx = Math.round((img.width  - corte) / 2);
      const sy = Math.round((img.height - corte) / 2);

      const lienzo = document.createElement('canvas');
      lienzo.width = lienzo.height = Math.min(lado, corte);
      lienzo.getContext('2d')
        .drawImage(img, sx, sy, corte, corte, 0, 0, lienzo.width, lienzo.height);

      URL.revokeObjectURL(img.src);
      lienzo.toBlob((b) => b ? listo(b) : falla(new Error('no se pudo convertir')),
                    'image/jpeg', 0.85);
    };
    img.onerror = () => falla(new Error('no es una imagen'));
    img.src = URL.createObjectURL(archivo);
  });
}

async function pedirFoto(emp, boton) {
  const entrada = document.createElement('input');
  entrada.type = 'file';
  entrada.accept = 'image/*';
  entrada.capture = 'user';          // en el teléfono abre la cámara directa
  entrada.addEventListener('change', async () => {
    const archivo = entrada.files?.[0];
    if (!archivo) return;

    const texto = boton.textContent;
    ocupado(boton, true, texto);
    try {
      const recortada = await encoger(archivo);
      // La ruta manda: la política de seguridad sólo deja escribir dentro
      // de la carpeta de la sede a la que se tiene acceso.
      const ruta = `${emp.branch_id}/${emp.id}.jpg`;
      const { error } = await sb.storage.from('empleados')
        .upload(ruta, recortada, { contentType: 'image/jpeg', upsert: true });
      if (error) throw error;

      const { error: e2 } = await sb.from('employees')
        .update({ photo_path: ruta }).eq('id', emp.id);
      if (e2) throw e2;

      avisoPanel('Foto guardada. El trabajador la verá al marcar.');
      cargarPersonal();
    } catch (err) {
      avisoPanel(traducir(err), 'error');
    } finally {
      ocupado(boton, false, texto);
    }
  });
  entrada.click();
}

$('btn-nuevo-empleado').addEventListener('click', () => abrirEmpleado(null));

function abrirEmpleado(emp) {
  editando = emp?.id || null;
  $('form-empleado-titulo').textContent = emp ? 'Editar trabajador' : 'Nuevo trabajador';
  $('emp-nombres').value   = emp?.first_name   || '';
  $('emp-apellidos').value = emp?.last_name    || '';
  $('emp-cedula').value    = emp?.national_id  || '';
  $('emp-codigo').value    = emp?.internal_code|| '';
  $('emp-cargo').value     = emp?.position     || '';
  $('emp-ingreso').value   = emp?.hired_on     || '';
  $('emp-sede').value      = emp?.branch_id    || yo.branch_id || sedes[0]?.id || '';
  $('emp-telefono').value  = '';
  $('emp-correo').value    = '';
  $('emp-salario').value   = '';

  // El salario se registra al dar de alta; cambiarlo después es un historial
  // con vigencia, y eso corresponde a la fase de nómina.
  $('caja-salario').hidden = !!emp || !puedeEditar();
  $('form-empleado').hidden = false;
  $('form-empleado').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

$('form-empleado').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  ocupado(btn, true, 'Guardar');

  const campos = {
    branch_id:     $('emp-sede').value,
    internal_code: $('emp-codigo').value.trim().toUpperCase(),
    first_name:    $('emp-nombres').value.trim(),
    last_name:     $('emp-apellidos').value.trim(),
    national_id:   $('emp-cedula').value.trim().toUpperCase(),
    position:      $('emp-cargo').value.trim(),
    hired_on:      $('emp-ingreso').value,
    phone:         $('emp-telefono').value.trim() || null,
    email:         $('emp-correo').value.trim() || null
  };

  let error, nuevo;
  if (editando) {
    delete campos.branch_id;                    // trasladar de sede no es editar
    ({ error } = await sb.from('employees').update(campos).eq('id', editando));
  } else {
    ({ data: nuevo, error } = await sb.from('employees').insert(campos).select('id').single());
  }

  if (!error && nuevo && $('emp-salario').value) {
    const { error: e2 } = await sb.from('employee_compensation').insert({
      employee_id: nuevo.id,
      weekly_base: Number($('emp-salario').value),
      effective_from: campos.hired_on
    });
    if (e2) {
      ocupado(btn, false, 'Guardar');
      avisoPanel('El trabajador se guardó, pero el salario no: ' + traducir(e2), 'error');
      await cargarPersonal();
      return;
    }
  }

  ocupado(btn, false, 'Guardar');
  if (error) { avisoPanel(traducir(error), 'error'); return; }

  avisoPanel(editando ? 'Trabajador actualizado.' : 'Trabajador registrado.');
  e.target.hidden = true;
  editando = null;
  await cargarPersonal();
  await cargarSedes();
});

/* El PIN se genera en el servidor y se ve UNA sola vez. Ni el sistema ni
   esta pantalla pueden volver a mostrarlo: si se pierde, se genera otro. */
async function generarPin(emp, boton) {
  const tenia = !!emp.pin_updated_at;
  if (tenia && !confirm(
    `¿Generar un PIN nuevo para ${emp.first_name} ${emp.last_name}?\n\n` +
    'El PIN anterior deja de funcionar de inmediato.')) return;

  const original = boton.textContent;
  boton.disabled = true;
  boton.textContent = '…';

  try {
    const { data: { session } } = await sb.auth.getSession();
    const r = await fetch(config.supabaseUrl + '/functions/v1/credisan', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        apikey: config.supabaseAnonKey,
        Authorization: 'Bearer ' + session.access_token
      },
      body: JSON.stringify({ accion: 'asignar-pin', empleado: emp.id })
    });
    const datos = await r.json().catch(() => ({}));

    // Un 404 aquí no es un error del trabajador ni de la red: es que la
    // función del servidor todavía no se ha publicado. Decir «no se pudo
    // generar el PIN» y callar el motivo deja a alguien buscando en el
    // sitio equivocado, que es justo lo que pasó.
    if (r.status === 404) {
      funcionPublicada = false;
      avisoFuncion();
      avisoPanel('La función del servidor no está publicada todavía. Por eso nadie tiene PIN.', 'error');
      return;
    }

    if (!datos.ok) {
      avisoPanel(datos.motivo === 'FALTA_PEPPER'
        ? 'La función está publicada pero le falta el secreto CREDISAN_PIN_PEPPER. Sin él no se puede generar ningún PIN.'
        : traducir(new Error(datos.motivo || 'ERROR')), 'error');
      return;
    }

    funcionPublicada = true;
    avisoFuncion();

    revelar(`PIN de ${emp.first_name} ${emp.last_name}`,
      'Anótelo y entrégueselo en persona. <strong>No se podrá volver a ver.</strong>',
      datos.pin,
      'Con este PIN marcará en el terminal de su sede. Si lo olvida, se genera otro.');
    await cargarPersonal();
  } catch (e) {
    avisoPanel('No se pudo generar el PIN: ' + (e?.message || 'sin conexión con el servidor.'), 'error');
  } finally {
    boton.disabled = false;
    boton.textContent = original;
  }
}

/* ── HORARIOS ───────────────────────────────────────────────────────── */

$('horario-sede').addEventListener('change', cargarHorario);

async function cargarHorario() {
  const caja = $('lista-horario');
  const sede = $('horario-sede').value;
  if (!sede) { caja.innerHTML = '<p class="vacio">Elija una sede.</p>'; return; }

  caja.innerHTML = '<p class="cargando">Cargando…</p>';
  const { data, error } = await sb.rpc('horario_sede', { p_branch: sede });
  if (error)    { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.ok) { caja.innerHTML = `<p class="vacio">${esc(traducir(new Error(data.reason)))}</p>`; return; }

  caja.innerHTML = data.dias.map((d) => `
    <div class="dia" data-dia="${d.id}">
      <div class="dia__cabeza">
        <strong>${DIAS[d.weekday]}</strong>
        <label class="suiche">
          <input type="checkbox" data-campo="trabaja" ${d.is_working ? 'checked' : ''}
                 ${puedeEditar() ? '' : 'disabled'}
                 aria-label="${DIAS[d.weekday]} laborable"><i></i>
        </label>
      </div>
      <div class="dia__horas" ${d.is_working ? '' : 'hidden'}>
        <div style="grid-column:1/-1">
          <label>
            <input type="checkbox" data-campo="corrida" ${d.is_continuous ? 'checked' : ''}
                   ${puedeEditar() ? '' : 'disabled'} style="width:auto;min-height:0;margin-right:.4rem">
            Jornada corrida (sin almuerzo)
          </label>
        </div>
        <div><label>Entrada</label>
          <input type="time" data-campo="entrada" value="${d.entry_time || '08:00'}" ${puedeEditar() ? '' : 'disabled'}></div>
        <div data-almuerzo ${d.is_continuous ? 'hidden' : ''}><label>Salida a almuerzo</label>
          <input type="time" data-campo="salida_almuerzo" value="${d.lunch_out_time || '12:00'}" ${puedeEditar() ? '' : 'disabled'}></div>
        <div data-almuerzo ${d.is_continuous ? 'hidden' : ''}><label>Regreso</label>
          <input type="time" data-campo="regreso" value="${d.lunch_in_time || '14:00'}" ${puedeEditar() ? '' : 'disabled'}></div>
        <div><label>Salida</label>
          <input type="time" data-campo="salida" value="${d.exit_time || '18:00'}" ${puedeEditar() ? '' : 'disabled'}></div>
        ${puedeEditar() ? '<button class="boton" data-guardar style="grid-column:1/-1;min-height:42px">Guardar</button>' : ''}
      </div>
    </div>`).join('');

  caja.querySelectorAll('.dia').forEach(prepararDia);
}

function prepararDia(fila) {
  const dato = (campo) => fila.querySelector(`[data-campo="${campo}"]`);
  const horas = fila.querySelector('.dia__horas');

  dato('trabaja').addEventListener('change', (e) => {
    horas.hidden = !e.target.checked;
    if (!e.target.checked) guardarDia(fila);      // apagar un día se aplica solo
  });

  dato('corrida')?.addEventListener('change', (e) => {
    fila.querySelectorAll('[data-almuerzo]').forEach((n) => { n.hidden = e.target.checked; });
  });

  fila.querySelector('[data-guardar]')?.addEventListener('click', () => guardarDia(fila));
}

async function guardarDia(fila) {
  const dato = (campo) => fila.querySelector(`[data-campo="${campo}"]`);
  const trabaja = dato('trabaja').checked;
  const corrida = trabaja && dato('corrida').checked;

  const { data, error } = await sb.rpc('guardar_dia_horario', {
    p_dia:             fila.dataset.dia,
    p_trabaja:         trabaja,
    p_corrida:         corrida,
    p_entrada:         trabaja ? dato('entrada').value : null,
    p_salida_almuerzo: trabaja && !corrida ? dato('salida_almuerzo').value : null,
    p_regreso:         trabaja && !corrida ? dato('regreso').value : null,
    p_salida:          trabaja ? dato('salida').value : null
  });

  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel(data.estado === 'descanso' ? 'Día marcado como descanso.' : 'Horario guardado.');
}

/* ── ACCESOS ────────────────────────────────────────────────────────── */

$('usr-rol').addEventListener('change', (e) => {
  $('caja-usr-sede').hidden = e.target.value === 'ceo';
});

$('form-usuario').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  const rol = $('usr-rol').value;
  ocupado(btn, true, 'Dar acceso');

  const { error } = await sb.rpc('registrar_usuario', {
    p_email:  $('usr-correo').value.trim(),
    p_nombre: $('usr-nombre').value.trim(),
    p_rol:    rol,
    p_branch: rol === 'ceo' ? null : $('usr-sede').value
  });

  ocupado(btn, false, 'Dar acceso');
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel('Acceso concedido. Ya puede entrar con su correo y contraseña.');
  e.target.reset();
  $('caja-usr-sede').hidden = false;
});

/* ── CIERRE SEMANAL ─────────────────────────────────────────────────
   El sistema mide e informa. No calcula descuentos: ninguna de estas
   funciones pide ni recibe una cifra de dinero, y la de la base de datos
   tampoco la consulta. La decisión la toma una persona, fuera de aquí. */

const ESTADOS_CIERRE = {
  pendiente:       'Sin revisar',
  aprobado:        'Aprobado',
  justificado:     'Justificado',
  con_observacion: 'Con observación'
};

// El lunes de la semana a la que pertenece una fecha
function lunesDe(fecha) {
  const d = new Date(fecha);
  const dow = (d.getDay() + 6) % 7;        // 0 = lunes
  d.setDate(d.getDate() - dow);
  return d.toISOString().slice(0, 10);
}

function horas(minutos) {
  const m = Math.max(0, Math.round(minutos || 0));
  return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
}

function semanaEnLetra(lunes) {
  const a = new Date(lunes + 'T12:00:00');
  const b = new Date(a); b.setDate(a.getDate() + 6);
  const f = (d) => d.toLocaleDateString('es-VE', { day: 'numeric', month: 'short' });
  return `${f(a)} — ${f(b)}`;
}

function moverSemana(dias) {
  const d = new Date($('cierre-semana').value + 'T12:00:00');
  d.setDate(d.getDate() + dias);
  const lunes = lunesDe(d);
  if (lunes > lunesDe(new Date())) { avisoPanel('Esa semana todavía no ha pasado.', 'error'); return; }
  $('cierre-semana').value = lunes;
  cargarCierres();
}

$('btn-semana-anterior').addEventListener('click', () => moverSemana(-7));
$('btn-semana-siguiente').addEventListener('click', () => moverSemana(7));
$('cierre-semana').addEventListener('change', () => {
  $('cierre-semana').value = lunesDe($('cierre-semana').value);
  cargarCierres();
});
$('cierre-sede').addEventListener('change', cargarCierres);

async function cargarCierres() {
  const caja = $('lista-cierres');
  if (!$('cierre-semana').value) {
    // Por defecto, la semana pasada: la actual todavía está ocurriendo.
    const d = new Date(); d.setDate(d.getDate() - 7);
    $('cierre-semana').value = lunesDe(d);
  }
  const sede = $('cierre-sede').value || yo.branch_id || sedes[0]?.id;
  if (!sede) { caja.innerHTML = '<p class="vacio">No hay sedes visibles.</p>'; return; }

  caja.innerHTML = '<p class="cargando">Cargando…</p>';
  const { data, error } = await sb.rpc('cierres',
    { p_branch: sede, p_lunes: $('cierre-semana').value });
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  const t = data.total || {};
  $('cierre-kpis').innerHTML = data.calculado
    ? kpi(t.trabajadores, 'Trabajadores', 'info')
      + kpi(t.asistencia === null ? '—' : t.asistencia + '%', 'Asistencia',
            t.asistencia >= 95 ? 'bien' : t.asistencia >= 80 ? 'aviso' : 'mal')
      + kpi(horas(t.minutos_retraso), 'Retraso acumulado', t.minutos_retraso > 0 ? 'aviso' : 'bien')
      + kpi(t.pendientes, 'Sin revisar', t.pendientes > 0 ? 'aviso' : 'bien')
    : '';

  if (!data.calculado) {
    caja.innerHTML = `<p class="vacio">La semana del ${esc(semanaEnLetra(data.semana))}
      todavía no se ha calculado. Pulse «Calcular la semana».</p>`;
    return;
  }

  caja.innerHTML = data.cierres.map((c) => `
    <div class="novedad" data-e="${c.estado === 'pendiente' ? 'pendiente' : 'aprobada'}">
      <h3>${esc(c.nombre)}</h3>
      <p>${esc(c.cargo)}</p>
      <div class="cierre-cifras">
        <span><strong>${c.asistencia}%</strong> asistencia</span>
        <span><strong>${c.puntualidad}%</strong> puntualidad</span>
        <span><strong>${horas(c.minutos_trabajados)}</strong> de ${horas(c.minutos_esperados)}</span>
      </div>
      <div class="novedad__pie">
        ${c.ausencias  ? `<span>${c.ausencias} ausencia${c.ausencias === 1 ? '' : 's'}</span>` : ''}
        ${c.incompletos ? `<span>${c.incompletos} día(s) incompleto(s)</span>` : ''}
        ${c.retrasos   ? `<span>${c.retrasos} retraso(s) · ${horas(c.minutos_retraso)}</span>` : ''}
        ${c.sin_evidencia ? `<span>${c.sin_evidencia} sin foto</span>` : ''}
        ${c.novedades  ? `<span>${c.novedades} novedad(es)</span>` : ''}
        <span class="pastilla pastilla--${c.estado === 'pendiente' ? 'inactivo' : 'activo'}"
              style="margin-left:auto">${ESTADOS_CIERRE[c.estado] || c.estado}</span>
      </div>
      ${c.nota ? `<p style="margin-top:.5rem;font-size:.82rem">«${esc(c.nota)}»</p>` : ''}
      ${c.cerrado_por
        ? `<p class="ayuda" style="margin-top:.35rem">Revisado por ${esc(c.cerrado_por)}</p>` : ''}
      ${puedeEditar() ? `
        <div class="novedad__acciones">
          ${c.estado === 'pendiente' ? `
            <button class="btn-rechazar" data-observar="${c.id}">Observar</button>
            <button class="btn-aprobar"  data-aprobar-cierre="${c.id}">Aprobar</button>`
          : `<button class="btn-rechazar" data-reabrir="${c.id}">Reabrir</button>`}
        </div>` : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-aprobar-cierre]').forEach((b) =>
    b.addEventListener('click', () => revisar(b.dataset.aprobarCierre, 'aprobado')));
  caja.querySelectorAll('[data-observar]').forEach((b) =>
    b.addEventListener('click', () => revisar(b.dataset.observar, 'con_observacion')));
  caja.querySelectorAll('[data-reabrir]').forEach((b) =>
    b.addEventListener('click', () => revisar(b.dataset.reabrir, 'pendiente')));
}

async function revisar(id, estado) {
  let nota = null;
  if (estado === 'con_observacion') {
    nota = prompt('¿Qué hay que observar de esta semana?');
    if (nota === null) return;
    if (!nota.trim()) { avisoPanel('Una observación sin explicación no sirve de nada.', 'error'); return; }
  } else if (estado === 'aprobado') {
    nota = prompt('Nota (opcional):');
    if (nota === null) return;
  } else if (!confirm('Reabrir borra la revisión y la semana vuelve a quedar sin revisar. ¿Seguro?')) {
    return;
  }

  const { data, error } = await sb.rpc('revisar_cierre',
    { p_cierre: id, p_estado: estado, p_nota: nota || null });
  if (error)    { avisoPanel(traducir(error), 'error'); return; }
  if (!data.ok) { avisoPanel('No se pudo revisar.', 'error'); return; }

  avisoPanel(estado === 'pendiente' ? 'Cierre reabierto.' : 'Cierre revisado.');
  cargarCierres();
}

$('btn-calcular-semana').addEventListener('click', async (ev) => {
  const btn = ev.currentTarget;
  ocupado(btn, true, 'Calcular la semana');
  const sede = $('cierre-sede').value || yo.branch_id || sedes[0]?.id;
  const { data, error } = await sb.rpc('calcular_cierre_semana',
    { p_branch: sede, p_lunes: $('cierre-semana').value });
  ocupado(btn, false, 'Calcular la semana');
  if (error) { avisoPanel(traducir(error), 'error'); return; }

  avisoPanel(data.ya_revisados
    ? `Semana calculada. ${data.ya_revisados} cierre(s) ya revisado(s) se dejaron como estaban.`
    : 'Semana calculada.');
  cargarCierres();
});

/* ── EXPORTAR ───────────────────────────────────────────────────────
   El CSV se arma y se descarga en el propio navegador: el archivo no
   pasa por ningún servidor intermedio ni se queda guardado en ninguna
   parte. Se abre en Excel y se acabó. */

function aCSV(filas, columnas) {
  const escapar = (v) => {
    const t = v === null || v === undefined ? '' : String(v);
    return /[",;\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  };
  // Punto y coma: es lo que espera un Excel configurado en español.
  const lineas = [columnas.map((c) => escapar(c.titulo)).join(';')];
  filas.forEach((f) => lineas.push(columnas.map((c) => escapar(c.valor(f))).join(';')));
  // El BOM es lo que hace que Excel respete los acentos.
  return '﻿' + lineas.join('\r\n');
}

function descargar(nombre, texto) {
  const url = URL.createObjectURL(new Blob([texto], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url; a.download = nombre;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const SI_NO = (v) => (v ? 'sí' : 'no');

$('btn-exportar-cierre').addEventListener('click', async () => {
  const sede = $('cierre-sede').value || yo.branch_id || sedes[0]?.id;
  const { data, error } = await sb.rpc('cierres',
    { p_branch: sede, p_lunes: $('cierre-semana').value });
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  if (!data.calculado) { avisoPanel('Primero calcule la semana.', 'error'); return; }

  descargar(`cierre-${data.sede}-${data.semana}.csv`, aCSV(data.cierres, [
    { titulo: 'Trabajador',          valor: (c) => c.nombre },
    { titulo: 'Cargo',               valor: (c) => c.cargo },
    { titulo: 'Asistencia %',        valor: (c) => c.asistencia },
    { titulo: 'Puntualidad %',       valor: (c) => c.puntualidad },
    { titulo: 'Minutos esperados',   valor: (c) => c.minutos_esperados },
    { titulo: 'Minutos trabajados',  valor: (c) => c.minutos_trabajados },
    { titulo: 'Retrasos',            valor: (c) => c.retrasos },
    { titulo: 'Minutos de retraso',  valor: (c) => c.minutos_retraso },
    { titulo: 'Ausencias',           valor: (c) => c.ausencias },
    { titulo: 'Días incompletos',    valor: (c) => c.incompletos },
    { titulo: 'Novedades',           valor: (c) => c.novedades },
    { titulo: 'Sin evidencia',       valor: (c) => c.sin_evidencia },
    { titulo: 'Estado',              valor: (c) => ESTADOS_CIERRE[c.estado] || c.estado },
    { titulo: 'Observación',         valor: (c) => c.nota },
    { titulo: 'Revisado por',        valor: (c) => c.cerrado_por }
  ]));
  avisoPanel('Archivo descargado.');
});

$('btn-exportar-reporte').addEventListener('click', async (ev) => {
  const btn = ev.currentTarget;
  const desde = $('rep-desde').value, hasta = $('rep-hasta').value;
  if (!desde || !hasta) { avisoPanel('Indique desde y hasta.', 'error'); return; }
  if (hasta < desde)    { avisoPanel('La fecha final va después de la inicial.', 'error'); return; }

  ocupado(btn, true, 'Descargar CSV');
  const { data, error } = await sb.rpc('reporte_asistencia',
    { p_branch: $('rep-sede').value || null, p_desde: desde, p_hasta: hasta });
  ocupado(btn, false, 'Descargar CSV');
  if (error) { avisoPanel(traducir(error), 'error'); return; }

  if (!data.filas) {
    aviso($('rep-aviso'),
      'No hay nada que exportar en ese rango. Puede que la asistencia de esos días aún no se haya calculado: ábralos en Cierres y púlselo allí.',
      'info');
    return;
  }

  descargar(`asistencia-${desde}-a-${hasta}.csv`, aCSV(data.datos, [
    { titulo: 'Fecha',              valor: (f) => f.fecha },
    { titulo: 'Sede',               valor: (f) => f.sede },
    { titulo: 'Código',             valor: (f) => f.codigo },
    { titulo: 'Trabajador',         valor: (f) => f.nombre },
    { titulo: 'Cargo',              valor: (f) => f.cargo },
    { titulo: 'Día laborable',      valor: (f) => SI_NO(f.dia_laborable) },
    { titulo: 'Estado',             valor: (f) => f.estado },
    { titulo: 'Marcaciones',        valor: (f) => f.marcaciones },
    { titulo: 'Esperadas',          valor: (f) => f.esperadas },
    { titulo: 'Minutos esperados',  valor: (f) => f.minutos_esperados },
    { titulo: 'Minutos trabajados', valor: (f) => f.minutos_trabajados },
    { titulo: 'Minutos de retraso', valor: (f) => f.minutos_retraso },
    { titulo: 'Retrasos',           valor: (f) => f.retrasos },
    { titulo: 'Anticipados',        valor: (f) => f.anticipados },
    { titulo: 'Sin evidencia',      valor: (f) => f.sin_evidencia },
    { titulo: 'Justificado',        valor: (f) => SI_NO(f.justificado) }
  ]));
  aviso($('rep-aviso'), `${data.filas} fila(s) descargadas.`, 'ok');
});

/* ── AUDITORÍA ──────────────────────────────────────────────────────
   Sólo lee. El registro es de sólo añadir: no hay forma de editarlo ni
   de borrarlo, tampoco desde aquí, y eso es lo que le da valor. */

// Los nombres de las columnas son ingleses y técnicos. Quien mira esta
// pantalla no tiene por qué saber qué es `is_working`.
const CAMPOS = {
  status:'estado', note:'observación', is_active:'activo', is_working:'trabaja ese día',
  is_continuous:'jornada corrida', entry_time:'hora de entrada', exit_time:'hora de salida',
  lunch_out_time:'salida a almorzar', lunch_in_time:'regreso de almorzar',
  first_name:'nombres', last_name:'apellidos', national_id:'cédula', position:'cargo',
  branch_id:'sede', hired_on:'fecha de ingreso', phone:'teléfono', email:'correo',
  full_name:'nombre', role:'rol', weekly_base:'salario semanal base',
  closed_by:'revisado por', closed_at:'revisado el', label:'nombre',
  device_label:'aparato', name:'nombre', code:'código', value:'valor',
  deleted_at:'fecha de baja', resolved_by:'resuelto por', description:'descripción'
};

// Y los valores: sin comillas de JSON, y con palabras en vez de true/false.
function valorLegible(v) {
  if (v === null || v === undefined) return '(vacío)';
  if (v === true)  return 'sí';
  if (v === false) return 'no';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

$('audit-sede').addEventListener('change', cargarAuditoria);
$('audit-desde').addEventListener('change', cargarAuditoria);

async function cargarAuditoria() {
  const caja = $('lista-auditoria');
  caja.innerHTML = '<p class="cargando">Cargando…</p>';

  const { data, error } = await sb.rpc('auditoria', {
    p_branch: $('audit-sede').value || null,
    p_desde:  $('audit-desde').value || null,
    p_accion: null,
    p_limite: 200
  });
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.filas) {
    caja.innerHTML = '<p class="vacio">No hay movimientos registrados en ese filtro.</p>';
    return;
  }

  caja.innerHTML = data.registros.map((r) => `
    <div class="ficha" style="display:block">
      <div style="display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap">
        <strong style="flex:1">${esc(r.etiqueta)}</strong>
        <span class="horas">${new Date(r.cuando).toLocaleString('es-VE',
          { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
      </div>
      <span style="font-size:.82rem;color:var(--texto-suave)">
        ${esc(r.quien)}${r.sede ? ' · ' + esc(r.sede) : ''}
      </span>
      ${(r.cambios && r.cambios.length) ? `
        <ul class="cambios">
          ${r.cambios.slice(0, 6).map((c) => `
            <li><b>${esc(CAMPOS[c.campo] || c.campo)}</b>
              <s>${esc(valorLegible(c.antes))}</s> →
              <i>${esc(valorLegible(c.despues))}</i></li>`).join('')}
          ${r.cambios.length > 6
            ? `<li>y ${r.cambios.length - 6} campo(s) más</li>` : ''}
        </ul>` : ''}
    </div>`).join('');
}

/* ── CONEXIÓN DE LOS TERMINALES ─────────────────────────────────────
   Lo que hay en la cola de un aparato sólo lo sabe ese aparato. Lo que sí
   se puede decir desde aquí —y es lo que de verdad importa— es cuándo se
   supo de él por última vez y qué llegó rechazado. */

const MOTIVOS_OFFLINE = {
  SECUENCIA_INVALIDA:                'Reenvío de algo ya recibido',
  HORA_FUTURA:                       'Reloj del aparato adelantado',
  FUERA_DE_VENTANA_OFFLINE:          'Más de 72 horas sin enviarse',
  ANTERIOR_A_ULTIMA_SINCRONIZACION:  'Anterior a lo ya recibido',
  SIN_EVENTO_PENDIENTE:              'No le tocaba marcar',
  PIN_INVALIDO:                      'PIN incorrecto',
  EMPLEADO_OTRA_SEDE:                'De otra sede',
  EMPLEADO_INACTIVO:                 'Trabajador dado de baja'
};

function desdeCuando(horas) {
  if (horas === null || horas === undefined) return 'nunca';
  if (horas < 1)  return 'hace menos de una hora';
  if (horas < 24) return `hace ${Math.round(horas)} h`;
  const d = Math.round(horas / 24);
  return `hace ${d} día${d === 1 ? '' : 's'}`;
}

async function cargarConexion() {
  const caja = $('lista-terminales-conexion');
  caja.innerHTML = '<p class="cargando">Cargando…</p>';

  const { data, error } = await sb.rpc('sincronizacion', { p_dias: 7 });
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  caja.innerHTML = data.terminales.map((t) => {
    // Un aparato emparejado que lleva más de un día callado es lo único
    // que de verdad hay que mirar en esta pantalla.
    const callado = t.emparejado && (t.horas_sin_senal === null || t.horas_sin_senal > 24);
    const motivos = Object.entries(t.motivos || {});
    return `
    <div class="ficha" style="display:block">
      <div style="display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap">
        <strong style="flex:1">${esc(t.code)} · ${esc(t.sede)}</strong>
        <span class="pastilla pastilla--${t.emparejado ? (callado ? 'inactivo' : 'activo') : 'inactivo'}">
          ${t.emparejado ? (callado ? 'Sin señal' : 'Al día') : 'Sin vincular'}
        </span>
      </div>
      <span style="font-size:.82rem;color:var(--texto-suave)">
        ${t.aparato ? esc(t.aparato) + ' · ' : ''}Última señal ${esc(desdeCuando(t.horas_sin_senal))}
      </span>
      <div class="cierre-cifras">
        <span><strong>${t.marcaciones_offline}</strong> sin conexión</span>
        <span><strong>${t.rechazos}</strong> rechazadas</span>
      </div>
      ${motivos.length ? `
        <ul class="cambios">
          ${motivos.map(([m, n]) =>
            `<li><b>${esc(MOTIVOS_OFFLINE[m] || m)}</b> — ${n}</li>`).join('')}
        </ul>` : ''}
    </div>`;
  }).join('') || '<p class="vacio">No hay terminales visibles.</p>';

  const { data: off, error: e2 } = await sb.rpc('marcaciones_offline',
    { p_branch: esCeo() ? null : yo.branch_id, p_dias: 7 });
  const lista = $('lista-offline');
  if (e2) { lista.innerHTML = `<p class="vacio">${esc(traducir(e2))}</p>`; return; }
  if (!off.filas) {
    lista.innerHTML = '<p class="vacio">Ninguna. Todas las marcaciones llegaron en directo.</p>';
    return;
  }

  lista.innerHTML = off.marcaciones.map((m) => `
    <div class="ficha"${(puedeVerEvidencia() && m.evidencia === 'almacenada')
      ? ` data-ver-foto="${m.id}" data-nombre="${esc(m.nombre)}" style="cursor:pointer"` : ''}>
      <span class="estado-punto" data-e="${m.estado === 'retraso' ? 'retrasado' : 'presente'}"></span>
      <div class="ficha__cuerpo">
        <strong>${esc(m.nombre)}</strong>
        <span>${esc(m.sede)} · ${esc(m.terminal || '—')} · ${esc(m.evento)}</span>
        ${Math.abs(m.desfase_seg || 0) > 600
          ? `<span class="pastilla pastilla--pin" style="margin-top:.25rem">Reloj desviado
             ${Math.round(m.desfase_seg / 60)} min</span>` : ''}
      </div>
      <div class="horas">
        <strong>${new Date(m.registrada).toLocaleTimeString('es-VE',
          { hour: '2-digit', minute: '2-digit' })}</strong><br>
        ${new Date(m.fecha + 'T12:00:00').toLocaleDateString('es-VE',
          { day: 'numeric', month: 'short' })}
      </div>
    </div>`).join('');

  lista.querySelectorAll('[data-ver-foto]').forEach((f) =>
    f.addEventListener('click', () => verEvidencia(f.dataset.verFoto, f.dataset.nombre)));
}

/* ── VER LA EVIDENCIA ───────────────────────────────────────────────
   La fotografía del momento de marcar es lo que convierte una hora en
   una prueba. Hasta ahora se guardaba y nadie podía mirarla.

   El depósito no tiene políticas para usuarios —ni dirección lo lee
   directamente—: se pasa por la función del servidor, que firma un
   enlace de 60 segundos. Y la base de datos deja escrito quién miró la
   foto de quién. Eso es lo que separa revisar de fisgonear. */

const MOTIVOS_EVIDENCIA = {
  SIN_EVIDENCIA: 'Esta marcación se registró sin fotografía. Hay una novedad abierta.',
  PURGADA:       'La fotografía ya se borró: se conservan 180 días.',
  PENDIENTE:     'La fotografía todavía se está guardando.',
  NO_AUTORIZADO: 'No tiene permiso para ver esta fotografía.',
  // La base dice que hay foto y el depósito dice que no. No es un fallo
  // de esta pantalla: es una contradicción entre dos sistemas, y hay que
  // decirlo con esas palabras para que alguien pueda ir a mirarlo.
  EVIDENCIA_NO_ESTA: 'La marcación dice tener fotografía, pero el archivo no está en el '
                   + 'depósito. Avise a soporte: no es un problema de su pantalla.',
  NO_SE_PUDO_FIRMAR: 'El servidor no pudo preparar el enlace de la fotografía. Inténtelo otra vez.',
  SIN_CONEXION:  'Sin conexión con el servidor. Revise su internet e inténtelo otra vez.'
};

$('visor-cerrar').addEventListener('click', cerrarVisor);
$('visor').addEventListener('click', (e) => { if (e.target.id === 'visor') cerrarVisor(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarVisor(); });

function cerrarVisor() {
  $('visor').hidden = true;
  $('visor-cuerpo').innerHTML = '';   // la imagen no se queda en memoria
  clearTimeout(cerrarVisor._t);
}

async function verEvidencia(eventoId, nombre) {
  $('visor-nombre').textContent = nombre || 'Marcación';
  $('visor-cuando').textContent = '';
  $('visor-cuerpo').innerHTML = '<p class="cargando">Cargando…</p>';
  $('visor').hidden = false;

  const { data: sesion } = await sb.auth.getSession();
  const r = await fetch(config.supabaseUrl + '/functions/v1/credisan', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      apikey: config.supabaseAnonKey,
      Authorization: 'Bearer ' + sesion.session.access_token
    },
    body: JSON.stringify({ accion: 'evidencia', evento: eventoId })
  }).then((x) => x.json()).catch(() => ({ ok: false, motivo: 'SIN_CONEXION' }));

  if (!r.ok) {
    $('visor-cuerpo').innerHTML =
      `<p class="vacio">${esc(MOTIVOS_EVIDENCIA[r.reason || r.motivo] || traducir(new Error(r.motivo || r.reason)))}</p>`;
    return;
  }

  $('visor-nombre').textContent = r.nombre;
  $('visor-cuando').textContent = new Date(r.cuando).toLocaleString('es-VE',
    { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

  // Si el enlace firmado no apunta al mismo servidor que el resto del
  // panel, el navegador nunca va a poder traerlo. Merece un mensaje
  // propio: es una configuración mal puesta, no una foto perdida, y las
  // dos se veían exactamente igual.
  let mismoServidor = true;
  try {
    mismoServidor = new URL(r.url).origin === new URL(config.supabaseUrl).origin;
  } catch { mismoServidor = false; }

  const img = new Image();
  img.alt = '';
  img.style.cssText = 'width:100%;border-radius:12px;display:block';
  img.onload = () => { $('visor-cuerpo').innerHTML = ''; $('visor-cuerpo').appendChild(img); };
  img.onerror = () => {
    // Aquí el permiso YA estaba concedido y el archivo YA estaba en el
    // depósito —las dos cosas se comprobaron antes—, así que lo que
    // falló fue la descarga. Decirlo así, y ofrecer reintentar: el
    // enlace dura 60 segundos y una foto no se pierde por un tropiezo.
    $('visor-cuerpo').innerHTML = mismoServidor
      ? '<p class="vacio">No se pudo descargar la fotografía. El enlace dura 60 segundos.</p>'
      : '<p class="vacio">El enlace de la fotografía apunta a otro servidor y el navegador '
        + 'no puede abrirlo. Es un ajuste del sistema, no un problema suyo.</p>';

    const otra = document.createElement('button');
    otra.className = 'boton boton--secundario';
    otra.textContent = 'Intentar de nuevo';
    otra.addEventListener('click', () => verEvidencia(eventoId, nombre));
    $('visor-cuerpo').appendChild(otra);
  };
  img.src = r.url;

  // El enlace caduca a los 60 s. Se cierra antes para no dejar en
  // pantalla una imagen que ya no se podría volver a pedir.
  clearTimeout(cerrarVisor._t);
  cerrarVisor._t = setTimeout(cerrarVisor, 55000);
}

/* ── SOCIOS ─────────────────────────────────────────────────────────
   Un socio consulta las sedes que dirección le marque. No edita nada:
   eso no lo decide esta pantalla —que sólo esconde botones— sino la
   base de datos, que a un socio le devuelve cero filas de lo que no le
   toca y rechaza cualquier escritura. */

async function cargarSocios() {
  const caja = $('lista-socios');
  // Si esta pantalla no está en el HTML de este navegador, no hay nada
  // que pintar y tampoco hay por qué molestar al servidor.
  if (!caja) return;

  const { data, error } = await sb.rpc('socios');
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }

  if (!data.length) {
    caja.innerHTML = '<p class="vacio">Todavía no hay socios con acceso.</p>';
    return;
  }

  caja.innerHTML = data.map((s) => `
    <div class="ficha">
      <div class="ficha__inicial">${esc((s.nombre || '?').trim()[0].toUpperCase())}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(s.nombre)}</strong>
        <span>${esc(s.correo || 'sin correo')}</span>
        <span style="margin-top:.25rem;display:block">
          ${s.sedes.length
            ? s.sedes.map((b) => `<span class="pastilla pastilla--activo">${esc(b.name || b.nombre)}</span>`).join(' ')
            : '<span class="pastilla pastilla--inactivo">Sin sedes</span>'}
          ${s.activo ? '' : '<span class="pastilla pastilla--inactivo">Acceso retirado</span>'}
        </span>
      </div>
      ${s.activo
        ? `<button class="ficha__accion" data-editar-socio="${s.id}">Cambiar</button>
           <button class="ficha__accion" data-quitar-socio="${s.id}"
                   data-nombre="${esc(s.nombre)}">Quitar</button>`
        : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-editar-socio]').forEach((b) =>
    b.addEventListener('click', () => {
      const s = data.find((x) => x.id === b.dataset.editarSocio);
      $('soc-correo').value = s.correo || '';
      $('soc-nombre').value = s.nombre;
      const suyas = s.sedes.map((x) => x.id);
      $('soc-sedes').querySelectorAll('input[type=checkbox]')
        .forEach((c) => { c.checked = suyas.includes(c.value); });
      $('soc-correo').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));

  caja.querySelectorAll('[data-quitar-socio]').forEach((b) =>
    b.addEventListener('click', () => quitarSocio(b.dataset.quitarSocio, b.dataset.nombre)));
}

// Las casillas se pintan con las sedes reales, no con una lista escrita
// a mano: si mañana se abre una sede, aparece aquí sola.
function pintarSedesDeSocio() {
  const caja = $('soc-sedes');
  if (!caja) return;
  caja.innerHTML = sedes.map((s) => `
    <label style="display:flex;align-items:center;gap:.6rem;font-weight:500">
      <input type="checkbox" id="soc-sede-${esc(s.id)}" value="${esc(s.id)}"
             style="width:1.15rem;height:1.15rem;accent-color:var(--morado)">
      ${esc(s.name)}
    </label>`).join('');
}

$('form-socio').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  const marcadas = [...$('soc-sedes').querySelectorAll('input:checked')].map((c) => c.value);

  if (!marcadas.length) {
    avisoPanel('Marque al menos una sede: un socio sin sedes no vería nada.', 'error');
    return;
  }

  ocupado(btn, true, 'Guardar socio');
  const { data, error } = await sb.rpc('guardar_socio', {
    p_email:  $('soc-correo').value.trim(),
    p_nombre: $('soc-nombre').value.trim(),
    p_sedes:  marcadas
  });
  ocupado(btn, false, 'Guardar socio');

  if (error) { avisoPanel(traducir(error), 'error'); return; }

  avisoPanel(data.quitadas
    ? `Guardado. Ahora consulta ${data.sedes} sede(s); se le retiró ${data.quitadas}.`
    : `Guardado. Ya puede entrar y consultar ${data.sedes} sede(s).`);
  e.target.reset();
  cargarSocios();
});

async function quitarSocio(id, nombre) {
  if (!confirm(`Retirar el acceso de ${nombre}.\n\nDeja de ver todas las sedes al instante. `
             + `No se borra nada de lo que hay registrado.\n\n¿Seguro?`)) return;

  const { error } = await sb.rpc('quitar_socio', { p_id: id });
  if (error) { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel('Acceso retirado.');
  cargarSocios();
}
