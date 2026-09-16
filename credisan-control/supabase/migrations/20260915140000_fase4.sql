-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0014 · Tableros (Fase 4)
-- =====================================================================
-- SÓLO AÑADE. Ni una tabla se modifica, ni un dato se toca: todo lo que
-- sigue son funciones de lectura y dos operaciones sobre novedades.
--
-- Las tres pantallas leen por la misma puerta que el resto del sistema:
-- `app.can_see_branch`. El jefe operativo no ve salarios porque el salario
-- vive en otra tabla y aquí no se consulta nunca.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- HOY · la pantalla que más se va a mirar
-- ─────────────────────────────────────────────────────────────────────
-- Se calcula en vivo contra el calendario y las marcaciones. No depende
-- de que ningún trabajo programado haya corrido: lo que se ve es lo que
-- hay en este instante.
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
      'origen',    v_entrada.origin
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

-- ─────────────────────────────────────────────────────────────────────
-- PERÍODO · el tablero de dirección y administración
-- ─────────────────────────────────────────────────────────────────────
-- Se calcula sobre las marcaciones, que existen siempre. Las ausencias
-- salen del resumen diario, que materializa el trabajo programado; si aún
-- no ha corrido, se informa en vez de mentir con un cero.
create or replace function public.tablero_periodo(
  p_desde date, p_hasta date, p_branch uuid default null)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_sedes jsonb := '[]'::jsonb; b record; v_total jsonb; v_dias int;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  if p_hasta < p_desde then raise exception 'RANGO_INVALIDO'; end if;
  if p_hasta - p_desde > 370 then raise exception 'RANGO_DEMASIADO_AMPLIO'; end if;

  for b in
    select id, name, code from branches
     where deleted_at is null and app.can_see_branch(id)
       and (p_branch is null or id = p_branch)
     order by code
  loop
    v_sedes := v_sedes || (
      select jsonb_build_object(
        'id', b.id, 'code', b.code, 'sede', b.name,
        'marcaciones',  count(*),
        'puntuales',    count(*) filter (where a.status in ('puntual','tolerancia')),
        'anticipados',  count(*) filter (where a.status = 'anticipado'),
        'retrasos',     count(*) filter (where a.status = 'retraso' and a.delta_minutes > 0),
        'minutos_retraso', coalesce(sum(a.delta_minutes)
                            filter (where a.delta_minutes > 0 and a.event in ('entrada','regreso_almuerzo')), 0),
        'sin_evidencia',count(*) filter (where a.evidence_status = 'sin_evidencia'),
        'offline',      count(*) filter (where a.origin = 'offline'),
        'trabajadores', (select count(*) from employees e
                          where e.branch_id = b.id and e.is_active and e.deleted_at is null),
        'puntualidad',  case when count(*) > 0
                          then round(100.0 * count(*) filter (
                                 where a.status in ('puntual','tolerancia','anticipado')) / count(*), 1)
                          else null end,
        'promedio_retraso', case when count(*) filter (where a.delta_minutes > 0) > 0
                          then round(avg(a.delta_minutes) filter (where a.delta_minutes > 0), 1)
                          else 0 end,
        'ausencias',    (select count(*) from attendance_daily ad
                          where ad.branch_id = b.id and ad.work_date between p_desde and p_hasta
                            and ad.status = 'ausente'),
        'incompletos',  (select count(*) from attendance_daily ad
                          where ad.branch_id = b.id and ad.work_date between p_desde and p_hasta
                            and ad.status = 'incompleto'),
        'novedades',    (select count(*) from incidents i
                          where i.branch_id = b.id and i.work_date between p_desde and p_hasta)
      )
      from attendance_events a
      where a.branch_id = b.id and a.work_date between p_desde and p_hasta);
  end loop;

  select jsonb_build_object(
    'marcaciones',   coalesce(sum((s->>'marcaciones')::int), 0),
    'puntuales',     coalesce(sum((s->>'puntuales')::int), 0),
    'anticipados',   coalesce(sum((s->>'anticipados')::int), 0),
    'retrasos',      coalesce(sum((s->>'retrasos')::int), 0),
    'ausencias',     coalesce(sum((s->>'ausencias')::int), 0),
    'incompletos',   coalesce(sum((s->>'incompletos')::int), 0),
    'sin_evidencia', coalesce(sum((s->>'sin_evidencia')::int), 0),
    'novedades',     coalesce(sum((s->>'novedades')::int), 0),
    'trabajadores',  coalesce(sum((s->>'trabajadores')::int), 0),
    'puntualidad',   case when coalesce(sum((s->>'marcaciones')::int), 0) > 0
                       then round(100.0 * (coalesce(sum((s->>'puntuales')::int), 0)
                                         + coalesce(sum((s->>'anticipados')::int), 0))
                                  / sum((s->>'marcaciones')::int), 1)
                       else null end)
    into v_total
    from jsonb_array_elements(v_sedes) s;

  -- ¿Se ha materializado el resumen diario del período? Si no, las
  -- ausencias que se muestran no son de fiar y hay que decirlo.
  select count(*) into v_dias from attendance_daily
   where work_date between p_desde and p_hasta
     and (p_branch is null or branch_id = p_branch);

  return jsonb_build_object(
    'ok', true, 'desde', p_desde, 'hasta', p_hasta,
    'total', v_total, 'sedes', v_sedes,
    'resumen_diario_calculado', v_dias > 0);
end $$;
grant execute on function public.tablero_periodo(date, date, uuid) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- RANKING · reconocer, no sólo señalar
-- ─────────────────────────────────────────────────────────────────────
create or replace function public.ranking_puntualidad(
  p_desde date, p_hasta date, p_branch uuid default null, p_limite int default 10)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select jsonb_agg(x order by (x->>'puntualidad')::numeric desc,
                              (x->>'anticipaciones')::int desc) into v
  from (
    select jsonb_build_object(
      'id', e.id,
      'nombre', e.first_name || ' ' || e.last_name,
      'cargo', e.position,
      'sede', b.name,
      'marcaciones', count(*),
      'anticipaciones', count(*) filter (where a.status = 'anticipado'),
      'retrasos', count(*) filter (where a.status = 'retraso' and a.delta_minutes > 0),
      'puntualidad', round(100.0 * count(*) filter (
          where a.status in ('puntual','tolerancia','anticipado')) / count(*), 1)
    ) as x
    from attendance_events a
    join employees e on e.id = a.employee_id
    join branches  b on b.id = a.branch_id
    where a.work_date between p_desde and p_hasta
      and a.event in ('entrada','regreso_almuerzo')
      and app.can_see_branch(a.branch_id)
      and (p_branch is null or a.branch_id = p_branch)
      and e.is_active and e.deleted_at is null
    group by e.id, e.first_name, e.last_name, e.position, b.name
    having count(*) >= 3          -- con una o dos marcaciones no hay ranking que valga
    limit greatest(p_limite, 1)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;
grant execute on function public.ranking_puntualidad(date, date, uuid, int) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- NOVEDADES · el jefe reporta, administración resuelve
-- ─────────────────────────────────────────────────────────────────────
-- Estas dos NO son `security definer`: se ejecutan con los permisos de
-- quien llama, así que quien decide sigue siendo la RLS. Lo único que
-- aportan es calcular la fecha laboral en la zona horaria de la sede,
-- que el navegador no debe decidir.

create or replace function public.registrar_novedad(
  p_employee uuid, p_tipo text, p_descripcion text,
  p_desde timestamptz default null, p_hasta timestamptz default null,
  p_evidencia text default null)
returns jsonb language plpgsql volatile security invoker
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_tz text; v_id uuid; v_desde timestamptz;
begin
  -- Sin filtrar por `deleted_at`: la RLS ya esconde las fichas borradas, y
  -- esa columna no está entre las que el panel puede leer. Filtrarla aquí
  -- haría fallar la función con un "permiso denegado" que no explica nada.
  select e.branch_id, b.timezone into v_branch, v_tz
    from employees e join branches b on b.id = e.branch_id
   where e.id = p_employee;
  if v_branch is null then raise exception 'EMPLEADO_INEXISTENTE'; end if;

  v_desde := coalesce(p_desde, now());

  insert into incidents (employee_id, branch_id, kind, description,
                         occurred_from, occurred_to, work_date,
                         evidence_path, reported_by)
  values (p_employee, v_branch, p_tipo, btrim(p_descripcion),
          v_desde, p_hasta, (v_desde at time zone v_tz)::date,
          p_evidencia, auth.uid())
  returning id into v_id;

  return jsonb_build_object('ok', true, 'id', v_id);
end $$;
grant execute on function public.registrar_novedad(uuid, text, text, timestamptz, timestamptz, text) to authenticated;

create or replace function public.resolver_novedad(
  p_id uuid, p_aprobar boolean, p_nota text default null)
returns jsonb language plpgsql volatile security invoker
set search_path = app, public, pg_temp as $$
declare v_fila incidents; n int;
begin
  update incidents i
     set status      = case when p_aprobar then 'aprobada' else 'rechazada' end::incident_status,
         reviewed_by = auth.uid(),
         reviewed_at = now(),
         review_note = nullif(btrim(coalesce(p_nota, '')), '')
   where i.id = p_id and i.status = 'pendiente'
   returning * into v_fila;

  get diagnostics n = row_count;
  if n = 0 then
    -- O no existe, o ya estaba resuelta, o la RLS no deja verla. En los
    -- tres casos la respuesta honesta es la misma: no se pudo.
    return jsonb_build_object('ok', false, 'motivo', 'NO_SE_PUDO_RESOLVER');
  end if;

  -- Una novedad aprobada puede cambiar el estado del día del trabajador.
  perform app.recompute_daily(v_fila.employee_id, v_fila.work_date);

  return jsonb_build_object('ok', true, 'estado', v_fila.status);
end $$;
grant execute on function public.resolver_novedad(uuid, boolean, text) to authenticated;
-- recompute_daily es `definer` y sólo la ejecuta service_role; aquí se llama
-- desde una función `invoker`, así que hay que permitirlo explícitamente.
grant execute on function app.recompute_daily(uuid, date) to authenticated;

create or replace function public.novedades(
  p_branch uuid default null, p_estado text default null, p_limite int default 50)
returns jsonb language plpgsql stable security invoker
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  select jsonb_agg(x order by x->>'creada' desc) into v
  from (
    select jsonb_build_object(
      'id', i.id,
      'empleado', e.first_name || ' ' || e.last_name,
      'cargo', e.position,
      'sede', b.name,
      'tipo', i.kind,
      'tipo_nombre', k.label,
      'descripcion', i.description,
      'desde', i.occurred_from,
      'hasta', i.occurred_to,
      'fecha', i.work_date,
      'estado', i.status,
      'origen', i.source,
      'reportada_por', p.full_name,
      'revisada_por', r.full_name,
      'nota', i.review_note,
      'creada', i.created_at
    ) as x
    from incidents i
    join employees e on e.id = i.employee_id
    join branches  b on b.id = i.branch_id
    join incident_kinds k on k.code = i.kind
    left join profiles p on p.id = i.reported_by
    left join profiles r on r.id = i.reviewed_by
    where (p_branch is null or i.branch_id = p_branch)
      and (p_estado is null or i.status::text = p_estado)
    order by i.created_at desc
    limit greatest(p_limite, 1)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;
grant execute on function public.novedades(uuid, text, int) to authenticated;

-- Materializar el resumen diario de un rango, para que las ausencias
-- aparezcan aunque el trabajo programado no haya corrido.
create or replace function public.recalcular_rango(
  p_branch uuid, p_desde date, p_hasta date)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare d date; n int := 0;
begin
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  if p_hasta < p_desde or p_hasta - p_desde > 92 then
    raise exception 'RANGO_INVALIDO' using hint = 'Máximo tres meses por vez.';
  end if;

  d := p_desde;
  while d <= p_hasta loop
    n := n + app.recompute_daily_branch(p_branch, d);
    d := d + 1;
  end loop;

  return jsonb_build_object('ok', true, 'dias', p_hasta - p_desde + 1, 'calculos', n);
end $$;
grant execute on function public.recalcular_rango(uuid, date, date) to authenticated;
