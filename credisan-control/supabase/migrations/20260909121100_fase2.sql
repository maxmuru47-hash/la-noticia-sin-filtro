-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0012 · Operaciones del panel (Fase 2)
-- =====================================================================
-- Tres operaciones que, hechas a mano, serían varias inserciones
-- coordinadas y fáciles de dejar a medias. Aquí van completas o no van.
-- =====================================================================

-- ── Crear una sede ───────────────────────────────────────────────────
-- Una sede sin horario no espera nada de nadie, y una sede sin terminal
-- no puede registrar marcaciones. Así que crear una sede es crear las
-- tres cosas de una vez.
create or replace function public.crear_sede(
  p_code text, p_name text, p_terminal text default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_sched uuid; v_term text; d int;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;

  p_code := upper(btrim(p_code));
  p_name := btrim(p_name);
  if p_code !~ '^[A-Z]{2,6}$' then
    raise exception 'CODIGO_INVALIDO' using hint = 'De 2 a 6 letras, sin números ni espacios.';
  end if;
  if length(p_name) < 3 then raise exception 'NOMBRE_MUY_CORTO'; end if;
  if exists (select 1 from branches where code = p_code and deleted_at is null) then
    raise exception 'SEDE_YA_EXISTE';
  end if;

  insert into branches (code, name) values (p_code, p_name) returning id into v_branch;

  -- Jornada estándar de arranque: lunes a viernes, sábado y domingo
  -- inactivos. Se ajusta después desde el panel, sin tocar código.
  insert into work_schedules (branch_id, name, description, created_by)
  values (v_branch, 'Jornada estándar',
          'Lunes a viernes 08:00–12:00 y 14:00–18:00. Sábado y domingo inactivos.',
          auth.uid())
  returning id into v_sched;

  for d in 0..6 loop
    if d between 1 and 5 then
      insert into schedule_days (schedule_id, weekday, is_working, is_continuous,
                                 entry_time, lunch_out_time, lunch_in_time, exit_time)
      values (v_sched, d, true, false, '08:00', '12:00', '14:00', '18:00');
    else
      insert into schedule_days (schedule_id, weekday, is_working) values (v_sched, d, false);
    end if;
  end loop;

  insert into schedule_assignments (schedule_id, branch_id, valid_from, note, created_by)
  values (v_sched, v_branch, current_date, 'Asignación inicial de la sede ' || p_name, auth.uid());

  v_term := coalesce(nullif(btrim(p_terminal), ''), p_code || '-01');
  insert into terminals (code, branch_id, label)
  values (v_term, v_branch, 'Terminal ' || p_name);

  return jsonb_build_object('ok', true, 'branch_id', v_branch, 'terminal', v_term);
end $$;
grant execute on function public.crear_sede(text, text, text) to authenticated;

-- ── Dar acceso al panel a una persona ────────────────────────────────
-- El usuario se crea antes en Supabase (Authentication → Add user), que
-- es lo único que el navegador no puede hacer sin la llave maestra.
-- Aquí sólo se le asigna rol y sede.
create or replace function public.registrar_usuario(
  p_email text, p_nombre text, p_rol text, p_branch uuid default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid; v_rol app_role;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;

  begin v_rol := p_rol::app_role;
  exception when invalid_text_representation then raise exception 'ROL_INVALIDO'; end;

  if v_rol = 'ceo' and p_branch is not null then raise exception 'CEO_SIN_SEDE'; end if;
  if v_rol <> 'ceo' and p_branch is null    then raise exception 'FALTA_SEDE'; end if;

  select id into v_uid from auth.users where lower(email) = lower(btrim(p_email));
  if v_uid is null then
    raise exception 'USUARIO_NO_EXISTE'
      using hint = 'Créelo primero en Supabase: Authentication → Users → Add user.';
  end if;

  if exists (select 1 from profiles where id = v_uid) then
    raise exception 'YA_TIENE_ACCESO';
  end if;

  insert into profiles (id, full_name, role, branch_id)
  values (v_uid, btrim(p_nombre), v_rol, p_branch);

  return jsonb_build_object('ok', true, 'id', v_uid);
end $$;
grant execute on function public.registrar_usuario(text, text, text, uuid) to authenticated;

-- ── Horario de una sede, en una sola consulta ────────────────────────
-- Devuelve los siete días con su horario vigente. El panel lo pinta tal cual.
create or replace function public.horario_sede(p_branch uuid)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_sched uuid; v_res jsonb;
begin
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select a.schedule_id into v_sched
    from schedule_assignments a
    join work_schedules w on w.id = a.schedule_id
   where a.branch_id = p_branch
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null
   limit 1;

  if v_sched is null then
    return jsonb_build_object('ok', false, 'reason', 'SIN_HORARIO');
  end if;

  select jsonb_agg(jsonb_build_object(
           'id', d.id, 'weekday', d.weekday, 'is_working', d.is_working,
           'is_continuous', d.is_continuous, 'entry_time', d.entry_time,
           'lunch_out_time', d.lunch_out_time, 'lunch_in_time', d.lunch_in_time,
           'exit_time', d.exit_time) order by d.weekday)
    into v_res
    from schedule_days d where d.schedule_id = v_sched;

  return jsonb_build_object('ok', true, 'schedule_id', v_sched, 'dias', coalesce(v_res, '[]'::jsonb));
end $$;
grant execute on function public.horario_sede(uuid) to authenticated;

-- Guardar un día del horario. Valida el orden de las horas antes de escribir
-- para que el error llegue en castellano y no como una violación de constraint.
create or replace function public.guardar_dia_horario(
  p_dia uuid, p_trabaja boolean, p_corrida boolean,
  p_entrada time default null, p_salida_almuerzo time default null,
  p_regreso time default null, p_salida time default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid;
begin
  select w.branch_id into v_branch
    from schedule_days d join work_schedules w on w.id = d.schedule_id
   where d.id = p_dia;
  if v_branch is null then raise exception 'DIA_INEXISTENTE'; end if;
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  if not p_trabaja then
    update schedule_days set is_working = false, is_continuous = false,
           entry_time = null, lunch_out_time = null, lunch_in_time = null, exit_time = null
     where id = p_dia;
    return jsonb_build_object('ok', true, 'estado', 'descanso');
  end if;

  if p_entrada is null or p_salida is null then
    raise exception 'FALTAN_HORAS' using hint = 'Un día laborable necesita hora de entrada y de salida.';
  end if;

  if p_corrida then
    if p_salida <= p_entrada then
      raise exception 'ORDEN_INVALIDO' using hint = 'La salida debe ser posterior a la entrada.';
    end if;
    update schedule_days set is_working = true, is_continuous = true,
           entry_time = p_entrada, exit_time = p_salida,
           lunch_out_time = null, lunch_in_time = null
     where id = p_dia;
  else
    if p_salida_almuerzo is null or p_regreso is null then
      raise exception 'FALTAN_HORAS' using hint = 'Una jornada partida necesita las cuatro horas.';
    end if;
    if not (p_entrada < p_salida_almuerzo
            and p_salida_almuerzo < p_regreso
            and p_regreso < p_salida) then
      raise exception 'ORDEN_INVALIDO'
        using hint = 'Las horas deben ir en orden: entrada, salida a almuerzo, regreso, salida.';
    end if;
    update schedule_days set is_working = true, is_continuous = false,
           entry_time = p_entrada, lunch_out_time = p_salida_almuerzo,
           lunch_in_time = p_regreso, exit_time = p_salida
     where id = p_dia;
  end if;

  return jsonb_build_object('ok', true, 'estado', 'guardado');
end $$;
grant execute on function public.guardar_dia_horario(uuid, boolean, boolean, time, time, time, time) to authenticated;

-- ── Resumen para la portada del panel ────────────────────────────────
create or replace function public.resumen_sedes()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;

  select jsonb_agg(x order by x->>'code') into v
  from (
    select jsonb_build_object(
      'id', b.id, 'code', b.code, 'name', b.name,
      'empleados', (select count(*) from employees e
                     where e.branch_id = b.id and e.is_active and e.deleted_at is null),
      'terminales', (select count(*) from terminals t
                      where t.branch_id = b.id and t.deleted_at is null),
      'emparejados', (select count(*) from terminals t
                       where t.branch_id = b.id and t.deleted_at is null and t.paired_at is not null)
    ) as x
    from branches b
    where b.deleted_at is null and app.can_see_branch(b.id)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;
grant execute on function public.resumen_sedes() to authenticated;
