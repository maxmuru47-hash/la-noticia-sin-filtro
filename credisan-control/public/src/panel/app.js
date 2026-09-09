/* CrediSan Control · panel administrativo (Fase 2)
   ---------------------------------------------------------------------
   Este archivo pinta y pide datos. No decide permisos: cada consulta va
   filtrada por las políticas de la base de datos, así que lo que se
   oculta aquí es por comodidad, nunca por seguridad. */

import { estaConfigurado } from '../core/config.js';
import { crearCliente, traducir } from '../core/supabase.js';

const $ = (id) => document.getElementById(id);
const DIAS = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
const ROLES = { ceo: 'Dirección general', admin: 'Administración', supervisor: 'Jefe operativo' };

const sb = crearCliente();
let yo = null;          // { nombre, rol, branch_id, sede }
let sedes = [];         // sedes visibles para quien entró
let editando = null;    // id del trabajador en edición, o null

/* ── Utilidades de pantalla ─────────────────────────────────────────── */

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

/* ── Arranque ───────────────────────────────────────────────────────── */

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
    email: $('correo').value.trim(),
    password: $('clave').value
  });

  ocupado(btn, false, 'Entrar');
  if (error) { aviso($('acceso-aviso'), traducir(error)); return; }
  await entrar();
});

/* Primer arranque: quien entra sin perfil, si no hay nadie más, queda como CEO. */
$('form-ceo').addEventListener('submit', async (e) => {
  e.preventDefault();
  const { data, error } = await sb.rpc('reclamar_ceo', { p_nombre: $('nombre-ceo').value.trim() });
  if (error)      { aviso($('acceso-aviso'), traducir(error)); return; }
  if (!data.ok)   { aviso($('acceso-aviso'), traducir(new Error(data.reason))); return; }
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
    // Sin perfil: puede ser el primerísimo acceso, o alguien sin permisos.
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

  const esCeo = yo.rol === 'ceo';
  const puedeEditar = esCeo || yo.rol === 'admin';
  $('nav-usuarios').hidden       = !esCeo;
  $('btn-nueva-sede').hidden     = !esCeo;
  $('btn-nuevo-empleado').hidden = !puedeEditar;
  $('caja-salario').hidden       = !puedeEditar;

  await cargarSedes();
  await cargarPersonal();
}

/* ── Navegación ─────────────────────────────────────────────────────── */

document.querySelectorAll('.nav button').forEach((b) => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.vista').forEach((v) => { v.hidden = true; });
    document.querySelectorAll('.nav button').forEach((o) => o.setAttribute('aria-current', 'false'));
    $(b.dataset.vista).hidden = false;
    b.setAttribute('aria-current', 'true');
    if (b.dataset.vista === 'v-horarios') cargarHorario();
    if (b.dataset.vista === 'v-personal') cargarPersonal();
  });
});

document.querySelectorAll('[data-cerrar]').forEach((b) => {
  b.addEventListener('click', () => { $(b.dataset.cerrar).hidden = true; });
});

/* ── Sedes ──────────────────────────────────────────────────────────── */

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
              ${s.emparejados ? '(' + s.emparejados + ' activo)' : '(sin emparejar)'}</span>
      </div>
    </div>`).join('') : '<p class="vacio">No hay sedes visibles.</p>';

  // Los selectores de sede se alimentan de la misma lista.
  const opciones = sedes.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  ['emp-sede', 'horario-sede', 'usr-sede'].forEach((id) => { $(id).innerHTML = opciones; });
  $('filtro-sede').innerHTML = (sedes.length > 1 ? '<option value="">Todas las sedes</option>' : '') + opciones;
  $('filtro-sede-caja').hidden = sedes.length < 2;

  if (yo.branch_id) {
    ['emp-sede', 'horario-sede'].forEach((id) => { $(id).value = yo.branch_id; });
    $('emp-sede').disabled = yo.rol !== 'ceo';   // nadie da de alta fuera de su sede
  }
}

$('btn-nueva-sede').addEventListener('click', () => {
  $('form-sede').hidden = false;
  $('sede-codigo').focus();
});

$('form-sede').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  ocupado(btn, true, 'Crear sede');

  const { data, error } = await sb.rpc('crear_sede', {
    p_code: $('sede-codigo').value,
    p_name: $('sede-nombre').value,
    p_terminal: null
  });

  ocupado(btn, false, 'Crear sede');
  if (error) { avisoPanel(traducir(error), 'error'); return; }

  avisoPanel(`Sede creada, con su horario estándar y el terminal ${data.terminal}.`);
  e.target.reset();
  e.target.hidden = true;
  await cargarSedes();
});

/* ── Personal ───────────────────────────────────────────────────────── */

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

  const puedeEditar = yo.rol === 'ceo' || yo.rol === 'admin';
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
      ${puedeEditar ? `<button class="ficha__accion" data-editar="${e.id}">Editar</button>` : ''}
    </div>`).join('');

  caja.querySelectorAll('[data-editar]').forEach((b) => {
    b.addEventListener('click', () => abrirEmpleado(data.find((x) => x.id === b.dataset.editar)));
  });
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
  $('caja-salario').hidden = !!emp || !(yo.rol === 'ceo' || yo.rol === 'admin');
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

/* ── Horarios ───────────────────────────────────────────────────────── */

$('horario-sede').addEventListener('change', cargarHorario);

async function cargarHorario() {
  const caja = $('lista-horario');
  const sede = $('horario-sede').value;
  if (!sede) { caja.innerHTML = '<p class="vacio">Elija una sede.</p>'; return; }

  caja.innerHTML = '<p class="cargando">Cargando…</p>';
  const { data, error } = await sb.rpc('horario_sede', { p_branch: sede });
  if (error)    { caja.innerHTML = `<p class="vacio">${esc(traducir(error))}</p>`; return; }
  if (!data.ok) { caja.innerHTML = `<p class="vacio">${esc(traducir(new Error(data.reason)))}</p>`; return; }

  const puedeEditar = yo.rol === 'ceo' || yo.rol === 'admin';

  caja.innerHTML = data.dias.map((d) => `
    <div class="dia" data-dia="${d.id}">
      <div class="dia__cabeza">
        <strong>${DIAS[d.weekday]}</strong>
        <label class="suiche">
          <input type="checkbox" data-campo="trabaja" ${d.is_working ? 'checked' : ''}
                 ${puedeEditar ? '' : 'disabled'}
                 aria-label="${DIAS[d.weekday]} laborable"><i></i>
        </label>
      </div>
      <div class="dia__horas" ${d.is_working ? '' : 'hidden'}>
        <div style="grid-column:1/-1">
          <label>
            <input type="checkbox" data-campo="corrida" ${d.is_continuous ? 'checked' : ''}
                   ${puedeEditar ? '' : 'disabled'} style="width:auto;min-height:0;margin-right:.4rem">
            Jornada corrida (sin almuerzo)
          </label>
        </div>
        <div><label>Entrada</label>
          <input type="time" data-campo="entrada" value="${d.entry_time || '08:00'}" ${puedeEditar ? '' : 'disabled'}></div>
        <div data-almuerzo ${d.is_continuous ? 'hidden' : ''}><label>Salida a almuerzo</label>
          <input type="time" data-campo="salida_almuerzo" value="${d.lunch_out_time || '12:00'}" ${puedeEditar ? '' : 'disabled'}></div>
        <div data-almuerzo ${d.is_continuous ? 'hidden' : ''}><label>Regreso</label>
          <input type="time" data-campo="regreso" value="${d.lunch_in_time || '14:00'}" ${puedeEditar ? '' : 'disabled'}></div>
        <div><label>Salida</label>
          <input type="time" data-campo="salida" value="${d.exit_time || '18:00'}" ${puedeEditar ? '' : 'disabled'}></div>
        ${puedeEditar ? '<button class="boton" data-guardar style="grid-column:1/-1;min-height:42px">Guardar</button>' : ''}
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

  if (error)  { avisoPanel(traducir(error), 'error'); return; }
  avisoPanel(data.estado === 'descanso' ? 'Día marcado como descanso.' : 'Horario guardado.');
}

/* ── Accesos al panel ───────────────────────────────────────────────── */

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
