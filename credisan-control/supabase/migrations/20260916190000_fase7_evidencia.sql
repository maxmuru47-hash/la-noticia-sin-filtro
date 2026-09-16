-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0017 · Ver la evidencia fotográfica
-- =====================================================================
-- SÓLO AÑADE.
--
-- EL HUECO QUE CIERRA
-- -------------------
-- Desde la Fase 3, cada marcación guarda una fotografía del momento. Es
-- la prueba de que quien marcó es la persona del PIN, y se conserva 180
-- días. Pero NADIE PODÍA MIRARLA: el depósito no tiene políticas para
-- usuarios —deliberadamente— y la función que debía emitir el enlace,
-- que las propias políticas mencionan por su nombre, nunca se escribió.
--
-- Una prueba que no se puede consultar no prueba nada. Un trabajador
-- podría reclamar «yo no marqué eso» y no habría forma de resolverlo.
--
-- QUIÉN PUEDE VERLA
-- -----------------
-- Dirección, administración Y el jefe operativo, cada uno en su sede.
-- Se incluye al jefe a propósito: es quien está en el mostrador y quien
-- va a notar que la cara no corresponde. Dejarlo fuera haría la prueba
-- inútil justo donde hace falta.
--
-- A cambio, MIRAR DEJA RASTRO. Cada consulta escribe en `audit_logs`
-- quién miró la foto de quién y cuándo. Es lo que separa revisar de
-- fisgonear, y era parte del diseño original de las políticas.
-- =====================================================================

create or replace function public.evidencia_de(p_event uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_a record; v_ruta text; v_actor uuid := auth.uid();
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;

  select a.id, a.branch_id, a.employee_id, a.work_date, a.event,
         a.recorded_at, a.evidence_status,
         e.first_name || ' ' || e.last_name as nombre
    into v_a
    from attendance_events a
    join employees e on e.id = a.employee_id
   where a.id = p_event;

  if not found then raise exception 'MARCACION_NO_ENCONTRADA'; end if;
  if not app.can_see_branch(v_a.branch_id) then raise exception 'NO_AUTORIZADO'; end if;

  -- Los tres estados en que no hay nada que enseñar se distinguen, porque
  -- significan cosas distintas y el panel tiene que poder decirlas.
  if v_a.evidence_status = 'sin_evidencia' then
    return jsonb_build_object('ok', false, 'reason', 'SIN_EVIDENCIA',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;
  if v_a.evidence_status = 'purgada' then
    return jsonb_build_object('ok', false, 'reason', 'PURGADA',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;

  select ev.storage_path into v_ruta
    from attendance_evidence ev where ev.event_id = p_event;

  if v_ruta is null then
    return jsonb_build_object('ok', false, 'reason', 'PENDIENTE',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;

  -- Mirar deja rastro. Siempre, y antes de entregar nada.
  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (v_actor, 'user', 'evidencia.consultada', 'attendance_events', p_event, v_a.branch_id,
          jsonb_build_object('empleado', v_a.employee_id, 'nombre', v_a.nombre,
                             'fecha', v_a.work_date, 'evento', v_a.event));

  return jsonb_build_object('ok', true, 'ruta', v_ruta,
    'nombre', v_a.nombre, 'cuando', v_a.recorded_at,
    'evento', v_a.event, 'fecha', v_a.work_date);
end $$;
grant execute on function public.evidencia_de(uuid) to authenticated;

-- El puente que usa la función del servidor: recibe la ruta ya autorizada
-- y no vuelve a decidir nada. Quien decide es `evidencia_de`, arriba, con
-- el token de la persona.
create or replace function public.edge_evidencia_ruta(p_event uuid)
returns text language sql stable security definer
set search_path = app, public, pg_temp as $$
  select ev.storage_path from attendance_evidence ev where ev.event_id = p_event
$$;
revoke all on function public.edge_evidencia_ruta(uuid) from public, anon, authenticated;
grant execute on function public.edge_evidencia_ruta(uuid) to service_role;


-- ─────────────────────────────────────────────────────────────────────
-- EL TABLERO DEL DÍA ENTREGA EL IDENTIFICADOR DE LA MARCACIÓN
-- ─────────────────────────────────────────────────────────────────────
-- Único cambio sobre algo existente, y es una adición: se añade un campo
-- a lo que ya devolvía `tablero_hoy`. Sin él, el panel sabe que hay una
-- fotografía pero no puede pedir cuál.
create or replace function public.tablero_hoy(p_branch uuid)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare
  v_tz text; v_fecha date; v_ahora timestamptz := now();
  v_tol int; v_corte int; v_lista jsonb := '[]'::jsonb;
  e record; d record; v_estado text; v_entrada record; v_salida record;
  v_esperados int := 0; v_presentes int := 0; v_faltantes int := 0;
  v_retrasados int := 0; v_anticipados int := 0; v_completos int := 0;
  v_descanso int := 0; v_sin_evidencia int := 0;
begin
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select timezone into v_tz from branches where id = p_branch;
  v_fecha := (v_ahora at time zone v_tz)::date;
  v_tol   := app.setting_int('attendance.arrival.tolerance_minutes', p_branch, 10);
  v_corte := app.setting_int('attendance.absence.cutoff_minutes',    p_branch, 120);

  for e in
    select id, first_name, last_name, position, photo_path
      from employees
     where branch_id = p_branch and is_active and deleted_at is null
       and hired_on <= v_fecha
     order by last_name, first_name
  loop
    select * into d from app.effective_day(e.id, v_fecha);

    select a.* into v_entrada from attendance_events a
     where a.employee_id = e.id and a.work_date = v_fecha and a.event = 'entrada';
    select a.* into v_salida from attendance_events a
     where a.employee_id = e.id and a.work_date = v_fecha and a.event = 'salida';

    if not coalesce(d.is_working, false) then
      v_estado := 'descanso';
      v_descanso := v_descanso + 1;
    else
      v_esperados := v_esperados + 1;

      if v_entrada.id is null then
        -- Faltante sólo cuando ya pasó la hora de entrada con su tolerancia.
        -- Antes de eso simplemente todavía no ha llegado.
        if v_ahora > ((v_fecha + d.entry_time) at time zone v_tz)
                     + make_interval(mins => v_tol + v_corte) then
          v_estado := 'faltante';
          v_faltantes := v_faltantes + 1;
        else
          v_estado := 'esperado';
        end if;
      else
        v_presentes := v_presentes + 1;
        if v_salida.id is not null then
          v_estado := 'completo';
          v_completos := v_completos + 1;
        elsif v_entrada.status = 'retraso' then
          v_estado := 'retrasado';
        elsif v_entrada.status = 'anticipado' then
          v_estado := 'anticipado';
        else
          v_estado := 'presente';
        end if;
        if v_entrada.status = 'retraso'    then v_retrasados   := v_retrasados + 1;   end if;
        if v_entrada.status = 'anticipado' then v_anticipados  := v_anticipados + 1;  end if;
        if v_entrada.evidence_status = 'sin_evidencia' then
          v_sin_evidencia := v_sin_evidencia + 1;
        end if;
      end if;
    end if;

    v_lista := v_lista || jsonb_build_object(
      'id',        e.id,
      'nombre',    e.first_name || ' ' || e.last_name,
      'cargo',     e.position,
      'estado',    v_estado,
      'esperada',  case when d.is_working then to_char(d.entry_time, 'HH24:MI') end,
      'entrada',   case when v_entrada.id is not null
                        then to_char(v_entrada.recorded_at at time zone v_tz, 'HH24:MI') end,
      'salida',    case when v_salida.id is not null
                        then to_char(v_salida.recorded_at at time zone v_tz, 'HH24:MI') end,
      'minutos',   v_entrada.delta_minutes,
      'evidencia', v_entrada.evidence_status,
      'origen',    v_entrada.origin,
      -- El identificador de la marcación de entrada: es lo que el panel
      -- necesita para pedir su fotografía. No es un dato sensible —sin
      -- permiso no se entrega nada— pero sin él no hay forma de abrirla.
      'evento_id', v_entrada.id
    );
  end loop;

  return jsonb_build_object(
    'ok', true,
    'fecha', v_fecha,
    'sede', (select name from branches where id = p_branch),
    'resumen', jsonb_build_object(
      'esperados',    v_esperados,
      'presentes',    v_presentes,
      'faltantes',    v_faltantes,
      'retrasados',   v_retrasados,
      'anticipados',  v_anticipados,
      'completos',    v_completos,
      'descanso',     v_descanso,
      'sin_evidencia',v_sin_evidencia,
      'novedades',    (select count(*) from incidents i
                        where i.branch_id = p_branch and i.status = 'pendiente')
    ),
    'empleados', v_lista);
end $$;
grant execute on function public.tablero_hoy(uuid) to authenticated;
