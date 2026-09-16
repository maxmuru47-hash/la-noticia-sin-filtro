-- =====================================================================
--
--   CREDISAN CONTROL · ACTUALIZACIÓN DE SEGURIDAD
--   La ruta de la fotografía sale de la base, no del terminal
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA y no crea tablas:
--   añade un campo a lo que dos funciones ya devolvían, y vuelve a
--   cerrar los permisos de todas las funciones.
--
--   QUÉ CORRIGE
--   -----------
--   La fotografía de cada marcación se guarda bajo la carpeta de su
--   sede, y esa carpeta es lo que decide quién puede leer el archivo.
--   Pero la sede la mandaba el TERMINAL, que es un teléfono en un
--   mostrador y hay que tratarlo como si estuviera en malas manos.
--   Cambiando un valor podía hacer que el servidor escribiera la foto
--   bajo otra sede, o incluso fuera del depósito.
--
--   Ahora la sede la pone la base de datos, que es quien la sabe.
--
--   Aplíquelo aunque todavía no haya marcaciones: cuanto antes, menos
--   fotografías archivadas donde no toca.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
  if to_regproc('public.edge_evidencia_por_purgar') is null then
    raise exception 'Falta la actualización anterior. Ejecute ACTUALIZAR-FASE8-PURGA.sql.';
  end if;
end $$;

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0019 · La ruta de la evidencia sale de la
-- base de datos, no de la petición
-- =====================================================================
-- SÓLO AÑADE (un campo a lo que ya se devolvía).
--
-- EL FALLO QUE CORRIGE
-- --------------------
-- La fotografía de cada marcación se guarda en `<sede>/<año>/<mes>/<día>/`,
-- y ese primer segmento es lo que `app.path_branch()` usa para decidir
-- quién puede leer el archivo. Es la bisagra de toda la separación por
-- sedes en el almacenamiento.
--
-- Pero la sede la mandaba el TERMINAL, en el cuerpo de la petición. Y el
-- terminal es un teléfono en un mostrador: un aparato hostil por diseño,
-- que sólo tiene que cambiar un valor en su propio almacenamiento para
-- que la función del servidor —con la llave maestra— escriba donde él
-- diga. Incluso fuera del depósito, porque `..` en una ruta lo resuelve
-- el propio navegador antes de llegar.
--
-- La corrección es no preguntarle. `_insert_punch` ya sabe la sede: es
-- la de la marcación que acaba de escribir. Basta con que la devuelva.
-- =====================================================================

create or replace function app._insert_punch(
  p_employee uuid, p_terminal uuid, p_when timestamptz, p_origin punch_origin,
  p_client_event uuid, p_event event_type, p_expected_at timestamptz, p_is_arrival boolean,
  p_device_ts timestamptz default null, p_seq bigint default null, p_drift int default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_tz text; v_branch uuid; v_date date; v_delta int; v_status punch_status; v_row attendance_events;
begin
  select t.branch_id, b.timezone into v_branch, v_tz
    from terminals t join branches b on b.id = t.branch_id
   where t.id = p_terminal;

  v_date   := (p_when at time zone v_tz)::date;
  v_delta  := round(extract(epoch from (p_when - p_expected_at)) / 60.0);
  v_status := app.classify(v_delta, p_is_arrival, v_branch);

  begin
    insert into attendance_events (
      employee_id, branch_id, terminal_id, work_date, event, expected_at,
      recorded_at, device_timestamp, sync_timestamp, clock_drift_sec,
      delta_minutes, status, origin, device_seq, client_event_id)
    values (
      p_employee, v_branch, p_terminal, v_date, p_event, p_expected_at,
      p_when, p_device_ts,
      case when p_origin = 'offline' then now() end,
      p_drift, v_delta, v_status, p_origin, p_seq, p_client_event)
    returning * into v_row;
  exception when unique_violation then
    -- Reintento de red: se devuelve la marcación original, no se duplica.
    select * into v_row from attendance_events where client_event_id = p_client_event;
    if found then
      return jsonb_build_object('id', v_row.id, 'duplicado', true, 'event', v_row.event,
                                'branch_id', v_row.branch_id,
                                'recorded_at', v_row.recorded_at, 'status', v_row.status,
                                'delta_minutes', v_row.delta_minutes);
    end if;
    -- Doble marcación del mismo evento del día.
    select * into v_row from attendance_events
     where employee_id = p_employee and work_date = v_date and event = p_event;
    raise exception 'YA_REGISTRADO'
      using detail = to_char(v_row.recorded_at at time zone v_tz, 'HH24:MI'),
            hint   = v_row.event::text;
  end;

  perform app.recompute_daily(p_employee, v_date);

  return jsonb_build_object(
    'id',            v_row.id,
    'duplicado',     false,
    'event',         v_row.event,
    -- La sede, tomada de la fila recién escrita. Es lo único que se añade,
    -- y es lo que permite dejar de creerle al terminal.
    'branch_id',     v_row.branch_id,
    'work_date',     v_row.work_date,
    'recorded_at',   v_row.recorded_at,
    'recorded_local',to_char(v_row.recorded_at at time zone v_tz, 'HH24:MI:SS'),
    'expected_at',   v_row.expected_at,
    'delta_minutes', v_row.delta_minutes,
    'status',        v_row.status,
    'origin',        v_row.origin);
end $$;

create or replace function app.register_punch(p_ticket uuid, p_client_event uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_tk record; v_existing attendance_events;
begin
  select * into v_existing from attendance_events where client_event_id = p_client_event;
  if found then
    -- La sede también aquí. Éste es el camino del REENVÍO, que es
    -- justamente el que cualquiera puede repetir a voluntad: si faltara,
    -- la ruta de la fotografía volvería a depender de lo que diga el
    -- terminal en el único caso que él controla por completo.
    return jsonb_build_object('id', v_existing.id, 'duplicado', true, 'event', v_existing.event,
                              'branch_id', v_existing.branch_id,
                              'recorded_at', v_existing.recorded_at, 'status', v_existing.status,
                              'delta_minutes', v_existing.delta_minutes);
  end if;

  select * into v_tk from punch_tickets where id = p_ticket for update;
  if not found                  then raise exception 'TICKET_INVALIDO'; end if;
  if v_tk.used_at is not null    then raise exception 'TICKET_YA_USADO'; end if;
  if v_tk.expires_at <= now()    then raise exception 'TICKET_VENCIDO';  end if;

  update punch_tickets set used_at = now() where id = v_tk.id;

  return app._insert_punch(
    v_tk.employee_id, v_tk.terminal_id, now(), 'online', p_client_event,
    v_tk.event, v_tk.expected_at,
    v_tk.event in ('entrada','regreso_almuerzo'));
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- CERROJO PARA LO QUE VENGA DESPUÉS
-- ─────────────────────────────────────────────────────────────────────
-- PostgreSQL concede EXECUTE a `public` por defecto en cada función
-- nueva. La migración 0008 lo revocó en bloque, pero eso sólo alcanzó a
-- las que existían entonces: cada función añadida después nace otra vez
-- con la puerta abierta.
--
-- Hoy ninguna de ellas filtra nada —todas comprueban rol o sede antes de
-- devolver— pero es una trampa esperando a la próxima que se escriba sin
-- acordarse. Se vuelve a cerrar en bloque y se devuelve el permiso sólo
-- a quien lo necesita.
do $$
declare f record;
begin
  for f in
    select p.oid::regprocedure as firma, p.proname
      from pg_proc p join pg_namespace n on n.oid = p.pronamespace
     where n.nspname = 'public' and p.prokind = 'f'
  loop
    execute format('revoke all on function %s from public, anon', f.firma);
    -- Las `edge_*` son la puerta del servidor: sólo `service_role`.
    if f.proname like 'edge_%' then
      execute format('revoke all on function %s from authenticated', f.firma);
      execute format('grant execute on function %s to service_role', f.firma);
    else
      execute format('grant execute on function %s to authenticated', f.firma);
    end if;
  end loop;
end $$;


do $$
declare v_abiertas text; v_emp int;
begin
  select string_agg(p.proname, ', ') into v_abiertas
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public' and p.prokind = 'f'
     and has_function_privilege('anon', p.oid, 'execute');

  select count(*) into v_emp from employees;

  raise notice '';
  raise notice '  CREDISAN CONTROL — corrección de seguridad aplicada';
  raise notice '  --------------------------------------------------';
  raise notice '  Trabajadores (intactos) ..... %', v_emp;
  raise notice '';

  if v_abiertas is not null then
    raise exception 'ATENCION: quedan funciones al alcance de anon: %', v_abiertas;
  end if;

  raise notice '  La sede de cada fotografía la pone ahora la base de datos.';
  raise notice '  Ni una función queda al alcance de un visitante sin sesión.';
  raise notice '';
end $$;
