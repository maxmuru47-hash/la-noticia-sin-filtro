-- =====================================================================
-- FASE 12 · horario propio de un trabajador
-- =====================================================================
-- Hay gente que no hace el horario de su sede: entra más tarde, sale
-- antes, o trabaja jornada corrida donde los demás hacen partida. A esa
-- persona hay que medirla contra SU horario. Medirla contra el general
-- la convierte en impuntual todos los días por una diferencia que la
-- empresa aprobó.
--
-- LO QUE YA ESTABA
-- ----------------
-- Casi todo, y no por suerte: el calendario se diseñó en la fase 1 con
-- esta prioridad escrita en su cabecera —
--
--   excepción de empleado > excepción de sede > excepción global
--   > asignación de horario de EMPLEADO > asignación de horario de sede
--
-- `app.effective_day()` ya busca primero el horario del trabajador y
-- sólo si no encuentra usa el de la sede. Y como TODA la medición pasa
-- por ahí —las marcaciones esperadas, la clasificación, los minutos
-- previstos, el cierre semanal—, un horario propio ya se respetaba en
-- todos esos sitios. No hacía falta tocar el motor.
--
-- Las reglas de escritura también estaban: `sched_assign_write` exige
-- «ceo o admin» Y que la sede sea visible. La administradora de
-- Maracaibo puede darle horario a los suyos y a nadie más.
--
-- LO QUE FALTABA
-- --------------
-- La puerta. No había forma de hacerlo sin escribir SQL a mano en tres
-- tablas, y eso no se le pide a una administradora. Estas funciones lo
-- dejan en una sola operación, con sus comprobaciones.
-- =====================================================================

do $$
begin
  if to_regclass('public.schedule_assignments') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
end $$;

-- ── Leer el horario con el que se mide a alguien ─────────────────────
-- Devuelve el horario EFECTIVO: el suyo si lo tiene, el de su sede si
-- no. Y dice cuál de los dos es, porque quien mira la pantalla tiene
-- derecho a saber contra qué se le está midiendo.
create or replace function public.horario_de_trabajador(p_employee uuid)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_nombre text; v_sede text;
        v_sched uuid; v_propio boolean := false; v_desde date; v_dias jsonb;
begin
  select e.branch_id, e.first_name || ' ' || e.last_name, b.name
    into v_branch, v_nombre, v_sede
    from employees e join branches b on b.id = e.branch_id
   where e.id = p_employee and e.deleted_at is null;

  if v_branch is null then raise exception 'TRABAJADOR_INEXISTENTE'; end if;
  if not app.can_see_branch(v_branch) then raise exception 'NO_AUTORIZADO'; end if;

  -- ¿Tiene horario propio vigente?
  select a.schedule_id, a.valid_from into v_sched, v_desde
    from schedule_assignments a
    join work_schedules w on w.id = a.schedule_id
   where a.employee_id = p_employee
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null
   limit 1;

  if v_sched is not null then
    v_propio := true;
  else
    select a.schedule_id into v_sched
      from schedule_assignments a
      join work_schedules w on w.id = a.schedule_id
     where a.branch_id = v_branch
       and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
       and w.is_active and w.deleted_at is null
     limit 1;
  end if;

  select coalesce(jsonb_agg(jsonb_build_object(
           'dia',      d.weekday,
           'trabaja',  d.is_working,
           'continua', d.is_continuous,
           'entrada',  d.entry_time,
           'salida_almuerzo', d.lunch_out_time,
           'regreso',  d.lunch_in_time,
           'salida',   d.exit_time
         ) order by d.weekday), '[]'::jsonb) into v_dias
    from schedule_days d where d.schedule_id = v_sched;

  return jsonb_build_object(
    'ok', true, 'nombre', v_nombre, 'sede', v_sede,
    'propio', v_propio, 'desde', v_desde, 'dias', v_dias);
end $$;

-- ── Darle (o cambiarle) su horario ───────────────────────────────────
create or replace function public.dar_horario_propio(p_employee uuid, p_dias jsonb)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_nombre text; v_sched uuid; v_nuevo boolean := false;
        v_asig uuid; v_desde date; v_solo_suyo boolean; v_repintar boolean := false;
        d jsonb; v_dia int; v_trabaja boolean; v_continua boolean;
        v_e time; v_sa time; v_r time; v_s time; v_vistos int[] := '{}';
begin
  select e.branch_id, e.first_name || ' ' || e.last_name
    into v_branch, v_nombre
    from employees e where e.id = p_employee and e.deleted_at is null;

  if v_branch is null then raise exception 'TRABAJADOR_INEXISTENTE'; end if;

  -- Quién puede: dirección, y la administradora DE ESA SEDE. El jefe
  -- operativo no: cambiar el horario de alguien cambia con qué vara se
  -- le mide, y eso no es operación del día, es una decisión.
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  if p_dias is null or jsonb_typeof(p_dias) <> 'array' or jsonb_array_length(p_dias) <> 7 then
    raise exception 'FALTAN_DIAS'
      using hint = 'Hay que mandar los siete días de la semana, de domingo a sábado.';
  end if;

  -- ¿Ya tiene una asignación que cubra hoy? Se busca SIN mirar si su
  -- horario está activo: una asignación apagada sigue ocupando el sitio
  -- —la base no admite dos horarios vigentes a la vez para la misma
  -- persona— así que hay que encontrarla para reaprovecharla.
  select a.id, a.schedule_id, a.valid_from into v_asig, v_sched, v_desde
    from schedule_assignments a
   where a.employee_id = p_employee
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
   limit 1;

  -- EL PASADO NO SE REESCRIBE.
  --
  -- Si el horario vigente ya rigió días cerrados, sus horas no se tocan:
  -- se cierra ayer y el nuevo empieza hoy. Cambiarlo en sitio convertiría
  -- en retrasos, de un día para otro, entradas que en su momento fueron
  -- puntuales — y el mantenimiento nocturno, que recalcula días pasados,
  -- lo haría sin que nadie se entere.
  --
  -- Sólo se corrige en sitio lo que empezó HOY y no rigió ningún día
  -- cerrado. Si no, cada rectificación de la misma mañana dejaría un
  -- horario huérfano más en la sede.
  if v_asig is not null then
    select count(*) = 1 and bool_and(w.is_active and w.deleted_at is null)
      into v_solo_suyo
      from schedule_assignments a join work_schedules w on w.id = a.schedule_id
     where a.schedule_id = v_sched;

    if v_desde >= current_date and v_solo_suyo then
      v_repintar := true;                    -- corrección del mismo día
    elsif v_desde < current_date then
      update schedule_assignments
         set valid_to = current_date - 1,
             note = coalesce(note || ' · ', '') || 'Sustituido el ' || current_date
       where id = v_asig;
      v_asig := null;                        -- abajo se crea otra asignación
      v_sched := null;
    else
      v_sched := null;                       -- se crea horario nuevo y se
    end if;                                  -- repunta la asignación de hoy
  end if;

  if v_sched is null then
    v_nuevo := true;
    insert into work_schedules (branch_id, name, description, created_by)
    values (v_branch,
            'Horario propio · ' || v_nombre || ' · desde ' || current_date,
            'Horario individual. Se mide a esta persona contra estas horas, '
            || 'no contra el horario general de la sede.', auth.uid())
    returning id into v_sched;
  end if;

  for d in select * from jsonb_array_elements(p_dias) loop
    v_dia      := (d->>'dia')::int;
    v_trabaja  := coalesce((d->>'trabaja')::boolean, false);
    v_continua := coalesce((d->>'continua')::boolean, false);

    if v_dia is null or v_dia < 0 or v_dia > 6 then
      raise exception 'DIA_INVALIDO' using hint = 'Los días van de 0 (domingo) a 6 (sábado).';
    end if;
    if v_dia = any (v_vistos) then
      raise exception 'DIA_REPETIDO' using hint = 'Cada día de la semana una sola vez.';
    end if;
    v_vistos := v_vistos || v_dia;

    if not v_trabaja then
      v_e := null; v_sa := null; v_r := null; v_s := null;
    else
      v_e  := (d->>'entrada')::time;
      v_s  := (d->>'salida')::time;
      v_sa := (d->>'salida_almuerzo')::time;
      v_r  := (d->>'regreso')::time;

      if v_e is null or v_s is null then
        raise exception 'FALTAN_HORAS'
          using hint = 'Un día laborable necesita hora de entrada y de salida.';
      end if;

      if v_continua then
        v_sa := null; v_r := null;
        if not (v_e < v_s) then
          raise exception 'ORDEN_INVALIDO'
            using hint = 'La salida tiene que ser después de la entrada.';
        end if;
      else
        if v_sa is null or v_r is null then
          raise exception 'FALTAN_HORAS'
            using hint = 'Una jornada partida necesita las cuatro horas.';
        end if;
        if not (v_e < v_sa and v_sa < v_r and v_r < v_s) then
          raise exception 'ORDEN_INVALIDO'
            using hint = 'Las cuatro horas van en orden: entrada, salida a almorzar, regreso, salida.';
        end if;
      end if;
    end if;

    insert into schedule_days (schedule_id, weekday, is_working, is_continuous,
                               entry_time, lunch_out_time, lunch_in_time, exit_time)
    values (v_sched, v_dia, v_trabaja, v_trabaja and v_continua, v_e, v_sa, v_r, v_s)
    on conflict (schedule_id, weekday) do update
      set is_working     = excluded.is_working,
          is_continuous  = excluded.is_continuous,
          entry_time     = excluded.entry_time,
          lunch_out_time = excluded.lunch_out_time,
          lunch_in_time  = excluded.lunch_in_time,
          exit_time      = excluded.exit_time;
  end loop;

  if v_asig is not null and not v_repintar then
    -- Empezaba hoy: no rigió ningún día cerrado, así que la asignación
    -- se aprovecha —apuntándola al horario nuevo— en vez de borrarla.
    update schedule_assignments
       set schedule_id = v_sched, valid_to = null,
           note = 'Horario individual asignado desde el panel'
     where id = v_asig;
  elsif v_asig is null then
    insert into schedule_assignments (schedule_id, employee_id, valid_from, note, created_by)
    values (v_sched, p_employee, current_date,
            'Horario individual asignado desde el panel', auth.uid());
  end if;

  return jsonb_build_object('ok', true, 'nuevo', v_nuevo,
                            'nombre', v_nombre, 'schedule_id', v_sched);
end $$;

-- ── Devolverle el horario de su sede ─────────────────────────────────
create or replace function public.quitar_horario_propio(p_employee uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_id uuid; v_desde date; v_sched uuid; v_solo_suyo boolean;
begin
  select e.branch_id into v_branch
    from employees e where e.id = p_employee and e.deleted_at is null;
  if v_branch is null then raise exception 'TRABAJADOR_INEXISTENTE'; end if;

  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  select a.id, a.valid_from, a.schedule_id into v_id, v_desde, v_sched
    from schedule_assignments a
    join work_schedules w on w.id = a.schedule_id
   where a.employee_id = p_employee
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null
   limit 1;

  if v_id is null then raise exception 'NO_TIENE_HORARIO_PROPIO'; end if;

  -- SE CIERRA, NO SE BORRA. Y la diferencia no es un detalle:
  --
  -- Los días ya trabajados se midieron contra este horario. El
  -- mantenimiento nocturno vuelve a calcular días pasados, y si la
  -- asignación hubiera desaparecido los recalcularía contra el horario
  -- de la sede — convirtiendo en retrasos, de un día para otro, horas
  -- de entrada que en su momento fueron puntuales.
  --
  -- Cerrándola con fecha, el pasado sigue midiéndose como se midió y
  -- sólo cambia de mañana en adelante.
  if v_desde >= current_date then
    -- Empezaba hoy: nunca rigió un día cerrado, y la restricción de
    -- vigencia no admite cerrarlo antes de su propio comienzo. Se apaga
    -- el horario, que es lo que el motor consulta —pide que esté activo
    -- antes de aplicarlo—, y la asignación queda inerte sin borrar nada.
    select count(*) = 1 into v_solo_suyo
      from schedule_assignments where schedule_id = v_sched;

    if not v_solo_suyo then
      -- Un horario compartido con más gente no se apaga desde la ficha
      -- de una persona: apagarlo se lo quitaría también a los demás.
      raise exception 'HORARIO_COMPARTIDO'
        using hint = 'Ese horario lo usan varias personas. Cámbielo en Más → Horarios.';
    end if;

    update work_schedules set is_active = false, updated_at = now() where id = v_sched;
    return jsonb_build_object('ok', true, 'accion', 'retirado');
  end if;

  update schedule_assignments
     set valid_to = current_date - 1,
         note = coalesce(note || ' · ', '') || 'Retirado el ' || current_date
   where id = v_id;

  return jsonb_build_object('ok', true, 'accion', 'cerrado',
                            'vigente_hasta', current_date - 1);
end $$;

-- ── Quiénes tienen horario propio ────────────────────────────────────
-- Para que el panel lo marque en la lista de personal. Nadie debería
-- descubrir que a alguien se le mide distinto abriendo su ficha.
create or replace function public.horarios_propios(p_branch uuid default null)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  select coalesce(jsonb_agg(e.id), '[]'::jsonb) into v
    from employees e
    join schedule_assignments a on a.employee_id = e.id
    join work_schedules w on w.id = a.schedule_id
   where e.deleted_at is null
     and app.can_see_branch(e.branch_id)
     and (p_branch is null or e.branch_id = p_branch)
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null;

  return v;
end $$;

-- ── Permisos ─────────────────────────────────────────────────────────
revoke all on function public.horario_de_trabajador(uuid)     from public, anon;
revoke all on function public.dar_horario_propio(uuid, jsonb) from public, anon;
revoke all on function public.quitar_horario_propio(uuid)     from public, anon;
revoke all on function public.horarios_propios(uuid)          from public, anon;

grant execute on function public.horario_de_trabajador(uuid)     to authenticated;
grant execute on function public.dar_horario_propio(uuid, jsonb) to authenticated;
grant execute on function public.quitar_horario_propio(uuid)     to authenticated;
grant execute on function public.horarios_propios(uuid)          to authenticated;
