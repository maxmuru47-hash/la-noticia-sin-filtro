/* CrediSan Control · panel administrativo
   ---------------------------------------------------------------------
   Este archivo pinta y pide datos. No decide permisos: cada consulta va
   filtrada por las políticas de la base de datos, así que lo que se
   oculta aquí es por comodidad, nunca por seguridad. */

import { config, estaConfigurado } from '../core/config.js';
import { crearCliente, traducir } from '../core/supabase.js';

const $ = (id) => document.getElementById(id);
const DIAS = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
const ROLES = { ceo: 'Dirección general', admin: 'Administración', supervisor: 'Jefe operativo' };

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

async function entrar() {
  const { data, error } = await sb.rpc('mi_perfil');
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
    await sb.auth.signOut();
    return;
  }

  yo = data;
  $('mi-nombre').textContent = yo.nombre;
  $('mi-rol').textContent = ROLES[yo.rol] + (yo.sede ? ' · ' + yo.sede : ' · Nacional');
  $('pantalla-acceso').hidden = true;
  $('pantalla-panel').hidden = false;

  // El jefe operativo tiene un panel deliberadamente corto: la operación
  // del día y las novedades. Nada de horarios, sedes ni estadísticas.
  $('nav-horarios').hidden       = !puedeEditar();
  $('nav-sedes').hidden          = !puedeEditar();
  $('bloque-accesos').hidden     = !esCeo();
  $('btn-nueva-sede').hidden     = !esCeo();
  $('btn-nuevo-empleado').hidden = !puedeEditar();
  $('caja-salario').hidden       = !puedeEditar();
  $('periodos').hidden           = !puedeEditar();
  $('btn-novedad-rapida').hidden = false;

  await cargarSedes();
  await cargarHoy();
}

/* ── Navegación ─────────────────────────────────────────────────────── */

document.querySelectorAll('.nav button').forEach((b) => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.vista').forEach((v) => { v.hidden = true; });
    document.querySelectorAll('.nav button').forEach((o) => o.setAttribute('aria-current', 'false'));
    $(b.dataset.vista).hidden = false;
    b.setAttribute('aria-current', 'true');
    if (b.dataset.vista === 'v-hoy')       cargarHoy();
    if (b.dataset.vista === 'v-personal')  cargarPersonal();
    if (b.dataset.vista === 'v-novedades') cargarNovedades();
    if (b.dataset.vista === 'v-horarios')  cargarHorario();
    if (b.dataset.vista === 'v-sedes')     { cargarSedes(); cargarTerminales(); }
  });
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
    <div class="ficha">
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
      </div>
    </div>`).join('');
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

  const { data, error } = await sb.rpc('novedades', {
    p_branch: esCeo() ? null : yo.branch_id,
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
        <span class="pastilla pastilla--${n.estado === 'aprobada' ? 'activo' : 'inactivo'}"
              style="margin-left:auto">${n.estado}</span>
      </div>
      ${n.nota ? `<p style="margin-top:.5rem;font-size:.82rem">«${esc(n.nota)}»</p>` : ''}
      ${(n.estado === 'pendiente' && puedeEditar()) ? `
        <div class="novedad__acciones">
          <button class="btn-rechazar" data-rechazar="${n.id}">Rechazar</button>
          <button class="btn-aprobar"  data-aprobar="${n.id}">Aprobar</button>
        </div>` : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-aprobar]').forEach((b) =>
    b.addEventListener('click', () => resolver(b.dataset.aprobar, true)));
  caja.querySelectorAll('[data-rechazar]').forEach((b) =>
    b.addEventListener('click', () => resolver(b.dataset.rechazar, false)));
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

$('btn-nueva-novedad').addEventListener('click', abrirNovedad);
$('btn-novedad-rapida').addEventListener('click', () => {
  document.querySelector('.nav button[data-vista="v-novedades"]').click();
  abrirNovedad();
});

async function abrirNovedad() {
  if (!tiposNovedad.length) {
    const { data } = await sb.from('incident_kinds')
      .select('code, label, sort_order, system_only, is_active').order('sort_order');
    tiposNovedad = (data || []).filter((t) => t.is_active && !t.system_only);
    $('nov-tipo').innerHTML = tiposNovedad
      .map((t) => `<option value="${t.code}">${esc(t.label)}</option>`).join('');
  }

  let q = sb.from('employees').select('id, first_name, last_name, branch_id').order('last_name');
  if (!esCeo()) q = q.eq('branch_id', yo.branch_id);
  const { data: emp } = await q;
  $('nov-empleado').innerHTML = (emp || [])
    .map((e) => `<option value="${e.id}">${esc(e.first_name)} ${esc(e.last_name)}</option>`).join('');

  $('form-novedad').hidden = false;
  $('form-novedad').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

$('form-novedad').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  ocupado(btn, true, 'Registrar');

  const cuando = $('nov-cuando').value;
  const { error } = await sb.rpc('registrar_novedad', {
    p_employee: $('nov-empleado').value,
    p_tipo: $('nov-tipo').value,
    p_descripcion: $('nov-descripcion').value.trim(),
    p_desde: cuando ? new Date(cuando).toISOString() : null
  });

  ocupado(btn, false, 'Registrar');
  if (error) { avisoPanel(traducir(error), 'error'); return; }

  avisoPanel('Novedad registrada. Queda pendiente de revisión.');
  e.target.reset();
  e.target.hidden = true;
  cargarNovedades();
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
  ['emp-sede', 'horario-sede', 'usr-sede', 'hoy-sede'].forEach((id) => { $(id).innerHTML = opciones; });
  $('filtro-sede').innerHTML = (sedes.length > 1 ? '<option value="">Todas las sedes</option>' : '') + opciones;
  $('filtro-sede-caja').hidden = sedes.length < 2;
  $('hoy-sede-caja').hidden = sedes.length < 2;

  if (yo.branch_id) {
    ['emp-sede', 'horario-sede', 'hoy-sede'].forEach((id) => { $(id).value = yo.branch_id; });
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
    .select('id, branch_id, internal_code, first_name, last_name, national_id, position, hired_on, is_active, pin_updated_at')
    .order('last_name');
  const filtro = $('filtro-sede').value;
  if (filtro) q = q.eq('branch_id', filtro);

  const { data, error } = await q;
  if (error) { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.length) {
    caja.innerHTML = '<p class="vacio">Todavía no hay trabajadores registrados.</p>';
    return;
  }

  const nombreSede = (id) => sedes.find((s) => s.id === id)?.name || '';

  caja.innerHTML = data.map((e) => `
    <div class="ficha">
      <div class="ficha__inicial">${esc((e.first_name[0] || '') + (e.last_name[0] || ''))}</div>
      <div class="ficha__cuerpo">
        <strong>${esc(e.first_name)} ${esc(e.last_name)}</strong>
        <span>${esc(e.position)} · ${esc(nombreSede(e.branch_id))} · ${esc(e.internal_code)}</span>
        <span style="margin-top:.25rem;display:block">
          <span class="pastilla pastilla--${e.is_active ? 'activo' : 'inactivo'}">
            ${e.is_active ? 'Activo' : 'Inactivo'}</span>
          ${e.pin_updated_at ? '' : '<span class="pastilla pastilla--pin">PIN pendiente</span>'}
        </span>
      </div>
      ${puedeEditar() ? `<div style="display:grid;gap:.35rem">
        <button class="ficha__accion" data-editar="${e.id}">Editar</button>
        <button class="ficha__accion" data-pin="${e.id}">${e.pin_updated_at ? 'Nuevo PIN' : 'Dar PIN'}</button>
      </div>` : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-editar]').forEach((b) =>
    b.addEventListener('click', () => abrirEmpleado(data.find((x) => x.id === b.dataset.editar))));
  caja.querySelectorAll('[data-pin]').forEach((b) =>
    b.addEventListener('click', () => generarPin(data.find((x) => x.id === b.dataset.pin), b)));
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
    const datos = await r.json();

    if (!datos.ok) {
      avisoPanel(datos.motivo === 'FALTA_PEPPER'
        ? 'Falta configurar el secreto CREDISAN_PIN_PEPPER en Supabase.'
        : traducir(new Error(datos.motivo || 'ERROR')), 'error');
      return;
    }

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
