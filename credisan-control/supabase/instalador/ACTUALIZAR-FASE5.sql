-- =====================================================================
--
--   CREDISAN CONTROL · ACTUALIZACIÓN A LA FASE 5
--   Cierre semanal, reportes y auditoría visible
--
-- =====================================================================
--
--   Su base ya está instalada y con datos. Esto NO BORRA NADA y no crea
--   ninguna tabla: las dos que hacen falta —cierres y auditoría— existen
--   desde el primer día, sólo que hasta ahora nadie las usaba.
--
--   Añade seis funciones de consulta y ajusta el disparador de auditoría
--   (se explica dentro por qué).
--
--   Puede ejecutarlo las veces que quiera: el resultado es el mismo.
--
--   1. SQL Editor → New query
--   2. Pegue todo este archivo
--   3. Run
--
--   UNA ACLARACIÓN QUE IMPORTA
--   --------------------------
--   El cierre semanal MIDE E INFORMA. NUNCA DESCUENTA DINERO. Ninguna
--   de estas funciones consulta los salarios, y la tabla del cierre no
--   tiene ni una columna monetaria. La decisión, si la hay, la toma una
--   persona fuera del sistema.
--
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception
      'CREDISAN no está instalado en este proyecto. Ejecute primero INSTALAR.sql.';
  end if;
  if to_regproc('public.tablero_hoy') is null then
    raise exception
      'Falta la Fase 4 en esta base. Ejecute antes ACTUALIZAR-FASE4.sql.';
  end if;
  if to_regproc('public.calcular_cierre_semana') is not null then
    raise notice 'La Fase 5 ya estaba aplicada. Se actualizarán las funciones.';
  end if;
end $$;

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0015 · Cierres, reportes y auditoría (Fase 5)
-- =====================================================================
-- SÓLO AÑADE. Las tablas `weekly_closures` y `audit_logs` existen desde la
-- Fase 1 con su RLS puesta; aquí se les da uso, no se las modifica.
--
-- LA REGLA QUE MANDA EN ESTE ARCHIVO
-- ----------------------------------
-- El cierre semanal MIDE E INFORMA. NUNCA DESCUENTA DINERO.
--
-- Por eso ninguna función de aquí consulta `employee_compensation`, y
-- `weekly_closures` no tiene ni una columna monetaria. No es un olvido:
-- es la garantía. Un sistema que calcula solo cuánto descontar acaba
-- descontando solo. Aquí se entregan minutos y conteos, y la decisión
-- —si la hay— la toma una persona, fuera del sistema.
--
-- La batería `05_fase5.sql` lo comprueba leyendo el código fuente de
-- estas funciones: si alguna llegara a mencionar la tabla de salarios,
-- la prueba se cae.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- AJUSTE AL DISPARADOR DE AUDITORÍA
-- ─────────────────────────────────────────────────────────────────────
-- Este es el ÚNICO punto de la Fase 5 que cambia algo que ya existía, y
-- conviene explicar por qué.
--
-- `app.tg_audit` descarta las columnas de puro latido antes de comparar,
-- para no auditar cambios que no son cambios. `computed_at` no estaba en
-- esa lista porque hasta ahora nadie escribía en `weekly_closures`.
--
-- Desde esta fase sí: el cierre se recalcula. Si `computed_at` siguiera
-- contando, cada recálculo nocturno escribiría una fila de auditoría por
-- trabajador —decenas al día— diciendo únicamente «se volvió a calcular a
-- otra hora». Ese ruido no informa de nada y entierra lo que sí importa.
--
-- Con este cambio, un recálculo que no altera ningún número no deja
-- rastro; uno que sí lo altera, lo deja entero, con su antes y su después.
-- No se pierde información: se deja de fabricar la que no lo es.
create or replace function app.tg_audit()
returns trigger language plpgsql security definer set search_path = app, public, pg_temp as $$
declare
  v_before jsonb;
  v_after  jsonb;
  v_id     uuid;
  v_branch uuid;
  v_actor  uuid := auth.uid();
begin
  if tg_op <> 'INSERT' then v_before := to_jsonb(old); end if;
  if tg_op <> 'DELETE' then v_after  := to_jsonb(new); end if;

  v_id     := nullif(coalesce(v_after, v_before) ->> 'id', '')::uuid;
  v_branch := nullif(coalesce(v_after, v_before) ->> 'branch_id', '')::uuid;

  -- Averiguar la sede cuando la tabla no la lleva encima.
  --
  -- Esto arregla un hueco que sólo se ve cuando la auditoría se mira desde
  -- el panel: la RLS de `audit_logs` filtra a la administradora por sede,
  -- así que una fila sin sede es una fila que ella no ve NUNCA. Los días de
  -- horario son el caso sangrante: los cambia ella —activar el sábado es
  -- suyo— y no podía consultar ni su propio rastro.
  --
  -- Se deriva sólo donde el vínculo es directo y sin ambigüedad. Lo que
  -- es de verdad global —sedes, configuración sin sede— se queda sin sede,
  -- y por tanto sólo a la vista de dirección. Que es lo correcto.
  if v_branch is null then
    v_branch := case tg_table_name
      when 'schedule_days' then
        (select ws.branch_id from work_schedules ws
          where ws.id = nullif(coalesce(v_after, v_before) ->> 'schedule_id', '')::uuid)
      when 'schedule_exceptions' then
        (select e.branch_id from employees e
          where e.id = nullif(coalesce(v_after, v_before) ->> 'employee_id', '')::uuid)
      when 'employee_compensation' then
        -- La administradora está autorizada a los datos de nómina de su
        -- sede, así que también a su rastro. El jefe operativo no tiene
        -- política sobre `audit_logs`: para él esto sigue sin existir.
        (select e.branch_id from employees e
          where e.id = nullif(coalesce(v_after, v_before) ->> 'employee_id', '')::uuid)
      when 'system_settings' then
        nullif(coalesce(v_after, v_before) ->> 'scope_branch_id', '')::uuid
      else null end;
  end if;

  v_before := v_before - 'pin_hash' - 'pin_lookup' - 'token_hash' - 'signing_key' - 'code_hash'
                       - 'updated_at' - 'last_seen_at' - 'last_sync_at' - 'last_seq'
                       - 'computed_at';
  v_after  := v_after  - 'pin_hash' - 'pin_lookup' - 'token_hash' - 'signing_key' - 'code_hash'
                       - 'updated_at' - 'last_seen_at' - 'last_sync_at' - 'last_seq'
                       - 'computed_at';

  if tg_op = 'UPDATE' and v_before = v_after then
    return new;
  end if;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, before, after)
  values (
    v_actor,
    case when v_actor is null then 'system' else 'user' end,
    tg_table_name || '.' || lower(tg_op),
    tg_table_name, v_id, v_branch, v_before, v_after
  );

  return coalesce(new, old);
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- CALCULAR EL CIERRE DE UNA SEMANA
-- ─────────────────────────────────────────────────────────────────────
-- Primero materializa los siete días —porque una ausencia es la falta de
-- una marcación y hay que ir a buscarla— y después agrega.
--
-- Nunca pisa un cierre que alguien ya revisó. Recalcular no puede borrar
-- la decisión de una persona: si la semana ya se aprobó o se observó, se
-- deja como está y se informa de cuántas se respetaron.
create or replace function public.calcular_cierre_semana(
  p_branch uuid, p_lunes date)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare
  e record; d date; v_nuevos int := 0; v_actualizados int := 0; v_respetados int := 0;
  v_dom date := p_lunes + 6;
begin
  if not (app.is_ceo() or app.is_admin()) then raise exception 'NO_AUTORIZADO'; end if;
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;
  if extract(isodow from p_lunes) <> 1 then raise exception 'NO_ES_LUNES'; end if;
  if p_lunes > current_date then raise exception 'SEMANA_FUTURA'; end if;

  for e in
    select id from employees
     where branch_id = p_branch and deleted_at is null and is_active
  loop
    -- Materializar los siete días antes de sumarlos
    d := p_lunes;
    while d <= v_dom loop
      perform app.recompute_daily(e.id, d);
      d := d + 1;
    end loop;

    -- ¿Ya la revisó alguien? Entonces no se toca.
    if exists (select 1 from weekly_closures
                where employee_id = e.id and week_start = p_lunes
                  and status <> 'pendiente') then
      v_respetados := v_respetados + 1;
      continue;
    end if;

    insert into weekly_closures (
      employee_id, branch_id, week_start, week_end,
      expected_minutes, worked_minutes, attendance_pct, punctuality_pct,
      early_count, late_count, late_minutes,
      absence_count, incomplete_count, incident_count, no_evidence_count,
      computed_at)
    select
      e.id, p_branch, p_lunes, v_dom,
      coalesce(sum(ad.expected_minutes), 0),
      coalesce(sum(ad.worked_minutes), 0),
      -- Asistencia: días cumplidos sobre días que tocaba trabajar. Un día
      -- de descanso no entra en el denominador, así que un sábado apagado
      -- jamás baja el porcentaje de nadie.
      case when count(*) filter (where ad.is_working_day) > 0
        then round(100.0 * count(*) filter (
               where ad.is_working_day and ad.status in ('completo','justificado'))
             / count(*) filter (where ad.is_working_day), 2)
        else 100 end,
      -- Puntualidad: marcaciones a tiempo sobre marcaciones hechas
      case when coalesce(sum(ad.registered_events), 0) > 0
        then round(100.0 * greatest(coalesce(sum(ad.registered_events), 0)
                                  - coalesce(sum(ad.late_count), 0), 0)
             / sum(ad.registered_events), 2)
        else 100 end,
      coalesce(sum(ad.early_count), 0),
      coalesce(sum(ad.late_count), 0),
      coalesce(sum(ad.late_minutes), 0),
      count(*) filter (where ad.status = 'ausente'),
      count(*) filter (where ad.status = 'incompleto'),
      (select count(*) from incidents i
        where i.employee_id = e.id and i.work_date between p_lunes and v_dom),
      coalesce(sum(ad.missing_evidence), 0),
      now()
      from attendance_daily ad
     where ad.employee_id = e.id and ad.work_date between p_lunes and v_dom
    on conflict (employee_id, week_start) do update set
      expected_minutes  = excluded.expected_minutes,
      worked_minutes    = excluded.worked_minutes,
      attendance_pct    = excluded.attendance_pct,
      punctuality_pct   = excluded.punctuality_pct,
      early_count       = excluded.early_count,
      late_count        = excluded.late_count,
      late_minutes      = excluded.late_minutes,
      absence_count     = excluded.absence_count,
      incomplete_count  = excluded.incomplete_count,
      incident_count    = excluded.incident_count,
      no_evidence_count = excluded.no_evidence_count,
      computed_at       = now();

    if found then v_actualizados := v_actualizados + 1; end if;
  end loop;

  insert into audit_logs (actor_id, actor_kind, action, entity, branch_id, metadata)
  values (auth.uid(), 'user', 'cierre.calcular', 'weekly_closures', p_branch,
          jsonb_build_object('semana', p_lunes, 'calculados', v_actualizados,
                             'respetados', v_respetados));

  return jsonb_build_object('ok', true, 'semana', p_lunes, 'hasta', v_dom,
    'calculados', v_actualizados, 'ya_revisados', v_respetados);
end $$;
grant execute on function public.calcular_cierre_semana(uuid, date) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- LEER LOS CIERRES DE UNA SEMANA
-- ─────────────────────────────────────────────────────────────────────
-- Devuelve minutos y conteos. Ni una cifra de dinero: ver la cabecera.
create or replace function public.cierres(p_branch uuid, p_lunes date)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb; v_total jsonb; v_sede text;
begin
  if not (app.is_ceo() or app.is_admin()) then raise exception 'NO_AUTORIZADO'; end if;
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select name into v_sede from branches where id = p_branch;

  select coalesce(jsonb_agg(f order by f->>'nombre'), '[]'::jsonb) into v_filas
    from (
      select jsonb_build_object(
        'id', c.id,
        'empleado_id', c.employee_id,
        'nombre', e.first_name || ' ' || e.last_name,
        'cargo', e.position,
        'minutos_esperados', c.expected_minutes,
        'minutos_trabajados', c.worked_minutes,
        'asistencia', c.attendance_pct,
        'puntualidad', c.punctuality_pct,
        'anticipados', c.early_count,
        'retrasos', c.late_count,
        'minutos_retraso', c.late_minutes,
        'ausencias', c.absence_count,
        'incompletos', c.incomplete_count,
        'novedades', c.incident_count,
        'sin_evidencia', c.no_evidence_count,
        'estado', c.status,
        'nota', c.note,
        'calculado', c.computed_at,
        'cerrado_por', p.full_name,
        'cerrado_en', c.closed_at) f
        from weekly_closures c
        join employees e on e.id = c.employee_id
        left join profiles p on p.id = c.closed_by
       where c.branch_id = p_branch and c.week_start = p_lunes) s;

  select jsonb_build_object(
    'trabajadores',    count(*),
    'pendientes',      count(*) filter (where (f->>'estado') = 'pendiente'),
    'minutos_retraso', coalesce(sum((f->>'minutos_retraso')::int), 0),
    'ausencias',       coalesce(sum((f->>'ausencias')::int), 0),
    'asistencia',      case when count(*) > 0
                         then round(avg((f->>'asistencia')::numeric), 1) else null end,
    'puntualidad',     case when count(*) > 0
                         then round(avg((f->>'puntualidad')::numeric), 1) else null end)
    into v_total from jsonb_array_elements(v_filas) f;

  return jsonb_build_object('ok', true, 'sede', v_sede, 'semana', p_lunes,
    'hasta', p_lunes + 6, 'calculado', jsonb_array_length(v_filas) > 0,
    'total', v_total, 'cierres', v_filas);
end $$;
grant execute on function public.cierres(uuid, date) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- REVISAR UN CIERRE
-- ─────────────────────────────────────────────────────────────────────
-- `security invoker` a propósito, igual que las novedades de la Fase 4:
-- quien decide si esta persona puede cerrar esta semana es la política de
-- la tabla, no un `if` dentro de la función. Si alguien cambiara este
-- código, la RLS seguiría negando.
create or replace function public.revisar_cierre(
  p_cierre uuid, p_estado text, p_nota text default null)
returns jsonb language plpgsql volatile security invoker
set search_path = app, public, pg_temp as $$
declare v_fila weekly_closures; v_antes closure_status;
begin
  if p_estado not in ('aprobado', 'justificado', 'con_observacion', 'pendiente') then
    raise exception 'ESTADO_INVALIDO';
  end if;
  if p_estado = 'con_observacion' and coalesce(btrim(p_nota), '') = '' then
    raise exception 'FALTA_LA_OBSERVACION';
  end if;

  select status into v_antes from weekly_closures where id = p_cierre;
  if v_antes is null then raise exception 'CIERRE_NO_ENCONTRADO'; end if;

  -- Reabrir es volver a pendiente: la restricción de la tabla exige que
  -- entonces no quede firma de quién cerró, y así es como debe ser.
  if p_estado = 'pendiente' then
    update weekly_closures
       set status = 'pendiente', note = p_nota, closed_by = null, closed_at = null
     where id = p_cierre returning * into v_fila;
  else
    update weekly_closures
       set status = p_estado::closure_status, note = p_nota,
           closed_by = auth.uid(), closed_at = now()
     where id = p_cierre returning * into v_fila;
  end if;

  -- Si la RLS no dejó tocar la fila, el UPDATE no devuelve nada. Esa es la
  -- única autorización que hay aquí, y es la que se quería.
  if v_fila.id is null then raise exception 'NO_AUTORIZADO'; end if;

  -- No se escribe auditoría a mano: `weekly_closures` ya tiene disparador
  -- desde la Fase 1, y deja un rastro mejor que el que escribiría aquí
  -- —con el antes y el después de la fila entera—. Además esta función es
  -- `invoker`, y `authenticated` no puede escribir en `audit_logs`: es
  -- append-only por diseño. Duplicar el registro habría exigido abrir esa
  -- puerta, que es justo lo que no se quiere abrir.
  return jsonb_build_object('ok', true, 'antes', v_antes, 'estado', p_estado);
end $$;
grant execute on function public.revisar_cierre(uuid, text, text) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- REPORTE PARA EXPORTAR
-- ─────────────────────────────────────────────────────────────────────
-- Filas planas, una por trabajador y día, listas para volcar a CSV en el
-- navegador. Sin dinero, y sin cédula: un reporte que circula por correo
-- no tiene por qué llevar el documento de identidad de nadie.
create or replace function public.reporte_asistencia(
  p_branch uuid, p_desde date, p_hasta date)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb;
begin
  if not (app.is_ceo() or app.is_admin()) then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  if p_hasta < p_desde then raise exception 'RANGO_INVALIDO'; end if;
  if p_hasta - p_desde > 370 then raise exception 'RANGO_DEMASIADO_AMPLIO'; end if;

  select coalesce(jsonb_agg(jsonb_build_object(
      'fecha', ad.work_date,
      'sede', b.name,
      'codigo', e.internal_code,
      'nombre', e.first_name || ' ' || e.last_name,
      'cargo', e.position,
      'dia_laborable', ad.is_working_day,
      'estado', ad.status,
      'marcaciones', ad.registered_events,
      'esperadas', ad.expected_events,
      'minutos_esperados', ad.expected_minutes,
      'minutos_trabajados', ad.worked_minutes,
      'minutos_retraso', ad.late_minutes,
      'retrasos', ad.late_count,
      'anticipados', ad.early_count,
      'sin_evidencia', ad.missing_evidence,
      'justificado', ad.justified_by is not null)
      order by ad.work_date, e.last_name), '[]'::jsonb)
    into v_filas
    from attendance_daily ad
    join employees e on e.id = ad.employee_id
    join branches  b on b.id = ad.branch_id
   where ad.work_date between p_desde and p_hasta
     and app.can_see_branch(ad.branch_id)
     and (p_branch is null or ad.branch_id = p_branch);

  return jsonb_build_object('ok', true, 'desde', p_desde, 'hasta', p_hasta,
    'filas', jsonb_array_length(v_filas), 'datos', v_filas);
end $$;
grant execute on function public.reporte_asistencia(uuid, date, date) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- AUDITORÍA VISIBLE
-- ─────────────────────────────────────────────────────────────────────
-- La auditoría ya se escribía desde la Fase 1; lo que faltaba era poder
-- leerla sin entrar a la base de datos. La RLS de `audit_logs` decide qué
-- filas salen: dirección lo ve todo, administración sólo su sede, y el
-- jefe operativo no tiene política, así que no ve nada.
--
-- `security invoker`: si esto fuera `definer` saltaría esa RLS y
-- cualquiera vería el rastro de todas las sedes.
create or replace function public.auditoria(
  p_branch uuid default null, p_desde date default null,
  p_accion text default null, p_limite int default 100)
returns jsonb language plpgsql stable security invoker
set search_path = app, public, pg_temp as $$
declare v_filas jsonb;
begin
  select coalesce(jsonb_agg(f order by (f->>'cuando') desc), '[]'::jsonb) into v_filas
    from (
      select jsonb_build_object(
        'id', a.id,
        'cuando', a.created_at,
        'quien', coalesce(p.full_name,
                   case a.actor_kind when 'terminal' then 'Terminal de marcación'
                                     when 'system'   then 'El sistema'
                                     else 'Usuario retirado' end),
        'tipo_actor', a.actor_kind,
        'accion', a.action,
        -- El código crudo («weekly_closures.update») es exacto pero no se
        -- lee. Se traduce aquí, en un solo sitio, y no en el navegador.
        'etiqueta', case a.action
          when 'weekly_closures.insert' then 'Se calculó un cierre semanal'
          when 'weekly_closures.update' then 'Se revisó un cierre semanal'
          when 'cierre.calcular'        then 'Se calculó la semana completa'
          when 'employees.insert'       then 'Alta de un trabajador'
          when 'employees.update'       then 'Cambio en la ficha de un trabajador'
          when 'employee_compensation.insert' then 'Se asignó salario'
          when 'employee_compensation.update' then 'Cambio de salario'
          when 'incidents.insert'       then 'Se reportó una novedad'
          when 'incidents.update'       then 'Se resolvió una novedad'
          when 'profiles.insert'        then 'Se dio acceso al panel'
          when 'profiles.update'        then 'Cambio de rol o de sede'
          when 'terminals.insert'       then 'Terminal creado'
          when 'terminals.update'       then 'Cambio en un terminal'
          when 'terminal_pairing_codes.insert' then 'Se generó un código de vinculación'
          when 'terminal_pairing_codes.update' then 'Se usó un código de vinculación'
          when 'schedule_days.update'   then 'Cambio de horario'
          when 'branches.insert'        then 'Se creó una sede'
          when 'system_settings.update' then 'Cambio de configuración'
          when 'pin.asignado'           then 'Se generó un PIN'
          else a.action end,
        'entidad', a.entity,
        'entidad_id', a.entity_id,
        'sede', b.name,
        'detalle', a.metadata,
        -- Qué cambió exactamente. Una pantalla de auditoría que sólo dice
        -- «se revisó un cierre» no audita nada: hay que poder ver el antes
        -- y el después. Se listan únicamente los campos que de verdad
        -- cambiaron, no la fila entera, que sería ilegible.
        --
        -- Aquí no hace falta filtrar datos sensibles: el disparador ya
        -- quitó los secretos al escribir, y qué filas se ven lo decide la
        -- RLS de `audit_logs`, no esta consulta.
        'cambios', case when a.before is null or a.after is null then null else (
            select coalesce(jsonb_agg(jsonb_build_object(
                     'campo', k, 'antes', a.before -> k, 'despues', a.after -> k)
                   order by k), '[]'::jsonb)
              from jsonb_object_keys(a.after) k
             where (a.before -> k) is distinct from (a.after -> k)) end,
        'valores', case when a.before is null then a.after else null end) f
        from audit_logs a
        left join profiles p on p.id = a.actor_id
        left join branches b on b.id = a.branch_id
       where (p_branch is null or a.branch_id = p_branch)
         and (p_desde  is null or a.created_at >= p_desde)
         and (p_accion is null or a.action like p_accion || '%')
       order by a.created_at desc
       limit greatest(least(coalesce(p_limite, 100), 500), 1)) s;

  return jsonb_build_object('ok', true, 'filas', jsonb_array_length(v_filas),
    'registros', v_filas);
end $$;
grant execute on function public.auditoria(uuid, date, text, int) to authenticated;

-- Las acciones que se registran, para poder filtrarlas en el panel sin
-- que el código del navegador tenga que adivinarlas.
create or replace function public.acciones_auditadas()
returns jsonb language sql stable security invoker
set search_path = app, public, pg_temp as $$
  select coalesce(jsonb_agg(distinct action order by action), '[]'::jsonb)
    from audit_logs;
$$;
grant execute on function public.acciones_auditadas() to authenticated;


-- =====================================================================
--   COMPROBACIÓN FINAL
-- =====================================================================
do $$
declare v_fn int; v_emp int; v_mar int; v_aud int; v_dinero text;
begin
  select count(*) into v_fn from pg_proc p
    join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public'
     and p.proname in ('calcular_cierre_semana','cierres','revisar_cierre',
                       'reporte_asistencia','auditoria','acciones_auditadas');

  -- La promesa, comprobada aquí mismo y no sólo prometida en un comentario
  select string_agg(p.proname, ', ') into v_dinero
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public'
     and p.proname in ('calcular_cierre_semana','cierres','revisar_cierre','reporte_asistencia')
     and p.prosrc ~* 'employee_compensation|weekly_base';

  select count(*) into v_emp from employees;
  select count(*) into v_mar from attendance_events;
  select count(*) into v_aud from audit_logs;

  raise notice '';
  raise notice '  CREDISAN CONTROL — Fase 5 aplicada';
  raise notice '  ----------------------------------';
  raise notice '  Funciones nuevas ........... % de 6', v_fn;
  raise notice '  Trabajadores (intactos) .... %', v_emp;
  raise notice '  Marcaciones (intactas) ..... %', v_mar;
  raise notice '  Auditoría acumulada ........ % registros', v_aud;
  raise notice '';

  if v_fn < 6 then
    raise exception 'ATENCION: faltan funciones. La actualización no se completó.';
  end if;
  if v_dinero is not null then
    raise exception 'ATENCION: una función del cierre consulta salarios (%). No debe.', v_dinero;
  end if;

  raise notice '  El cierre no consulta salarios: comprobado.';
  raise notice '  Entre al panel: aparecen «Cierres» y, dentro de «Más», la auditoría.';
  raise notice '';
end $$;
