-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0007 · Motor: seguridad, calendario,
--                                      clasificación, PIN y marcación
-- =====================================================================
-- Toda decisión que importa ocurre aquí, dentro de PostgreSQL.
-- El navegador nunca decide hora, sede, identidad ni clasificación.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- 1. IDENTIDAD Y ALCANCE
-- ─────────────────────────────────────────────────────────────────────

create or replace function app.jwt_claim(p_name text)
returns text language sql stable as $$
  select nullif(
    coalesce(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> p_name, ''),
  '')
$$;

-- Rol y sede se leen del JWT (rápido, sin consulta). Si el hook de claims
-- todavía no está activo, se resuelve contra profiles: el sistema funciona
-- igual, sólo un poco más lento.
create or replace function app.current_role_name()
returns app_role language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_claim text; v_uid uuid; v_role app_role;
begin
  v_claim := app.jwt_claim('app_role');
  if v_claim is not null and v_claim <> 'none' then
    begin
      return v_claim::app_role;
    exception when invalid_text_representation then
      null;
    end;
  end if;
  v_uid := auth.uid();
  if v_uid is null then return null; end if;
  select p.role into v_role from profiles p where p.id = v_uid and p.is_active;
  return v_role;
end $$;

create or replace function app.current_branch()
returns uuid language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_claim text; v_uid uuid; v_branch uuid;
begin
  if app.jwt_claim('app_role') is not null then
    v_claim := app.jwt_claim('branch_id');
    if v_claim is not null then return v_claim::uuid; end if;
  end if;
  v_uid := auth.uid();
  if v_uid is null then return null; end if;
  select p.branch_id into v_branch from profiles p where p.id = v_uid and p.is_active;
  return v_branch;
end $$;

create or replace function app.is_ceo() returns boolean
language sql stable as $$ select app.current_role_name() = 'ceo' $$;

create or replace function app.is_admin() returns boolean
language sql stable as $$ select app.current_role_name() = 'admin' $$;

create or replace function app.is_supervisor() returns boolean
language sql stable as $$ select app.current_role_name() = 'supervisor' $$;

-- Regla única de alcance por sede, usada por TODA la RLS.
create or replace function app.can_see_branch(p_branch uuid)
returns boolean language sql stable as $$
  select case
    when app.current_role_name() is null then false
    when app.is_ceo() then true
    else p_branch is not null and p_branch = app.current_branch()
  end
$$;

-- Hook de Supabase Auth: inyecta rol y sede en el access token.
create or replace function public.custom_access_token_hook(event jsonb)
returns jsonb language plpgsql stable security definer
set search_path = public, pg_temp as $$
declare claims jsonb; p record;
begin
  select pr.role, pr.branch_id, pr.is_active into p
    from public.profiles pr
   where pr.id = (event ->> 'user_id')::uuid;

  claims := coalesce(event -> 'claims', '{}'::jsonb);

  if p is null or not p.is_active then
    claims := jsonb_set(claims, '{app_role}', '"none"');
    claims := jsonb_set(claims, '{branch_id}', 'null'::jsonb);
  else
    claims := jsonb_set(claims, '{app_role}',  to_jsonb(p.role::text));
    claims := jsonb_set(claims, '{branch_id}', coalesce(to_jsonb(p.branch_id::text), 'null'::jsonb));
  end if;

  return jsonb_set(event, '{claims}', claims);
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 2. CONFIGURACIÓN
-- ─────────────────────────────────────────────────────────────────────

create or replace function app.setting(p_key text, p_branch uuid default null)
returns jsonb language sql stable security definer
set search_path = app, public, pg_temp as $$
  select s.value
    from system_settings s
   where s.key = p_key
     and (s.scope_branch_id is null or s.scope_branch_id = p_branch)
   order by s.scope_branch_id nulls last
   limit 1
$$;

create or replace function app.setting_int(p_key text, p_branch uuid, p_default int)
returns integer language sql stable security definer
set search_path = app, public, pg_temp as $$
  select coalesce((app.setting(p_key, p_branch)) #>> '{}', p_default::text)::int
$$;

-- ─────────────────────────────────────────────────────────────────────
-- 3. CALENDARIO EFECTIVO
-- ─────────────────────────────────────────────────────────────────────
-- Resuelve, para un trabajador y una fecha, qué se esperaba de él.
-- Prioridad: excepción de empleado > de sede > global > horario de
-- empleado > horario de sede. Sin horario aplicable: no se espera nada.

create or replace function app.effective_day(p_employee uuid, p_date date)
returns table (
  is_working     boolean,
  is_continuous  boolean,
  entry_time     time,
  lunch_out_time time,
  lunch_in_time  time,
  exit_time      time,
  source         text
)
language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_exc record; v_sched uuid; v_day record;
begin
  select e.branch_id into v_branch from employees e where e.id = p_employee;
  if v_branch is null then
    return query select false, false, null::time, null::time, null::time, null::time, 'empleado_inexistente';
    return;
  end if;

  select x.* into v_exc
    from schedule_exceptions x
   where p_date between x.date_from and x.date_to
     and (
       (x.scope = 'employee' and x.employee_id = p_employee) or
       (x.scope = 'branch'   and x.branch_id  = v_branch)    or
       (x.scope = 'global')
     )
   order by case x.scope when 'employee' then 1 when 'branch' then 2 else 3 end,
            x.created_at desc
   limit 1;

  if found then
    return query select v_exc.is_working, v_exc.is_continuous,
                        v_exc.entry_time, v_exc.lunch_out_time,
                        v_exc.lunch_in_time, v_exc.exit_time,
                        'excepcion:' || v_exc.kind::text;
    return;
  end if;

  -- Horario del empleado; si no tiene, el de su sede.
  select a.schedule_id into v_sched
    from schedule_assignments a
    join work_schedules w on w.id = a.schedule_id
   where a.employee_id = p_employee
     and p_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null
   limit 1;

  if v_sched is null then
    select a.schedule_id into v_sched
      from schedule_assignments a
      join work_schedules w on w.id = a.schedule_id
     where a.branch_id = v_branch
       and p_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
       and w.is_active and w.deleted_at is null
     limit 1;
  end if;

  if v_sched is null then
    return query select false, false, null::time, null::time, null::time, null::time, 'sin_horario';
    return;
  end if;

  select d.* into v_day
    from schedule_days d
   where d.schedule_id = v_sched
     and d.weekday = extract(dow from p_date)::smallint;

  if not found then
    return query select false, false, null::time, null::time, null::time, null::time, 'dia_no_definido';
    return;
  end if;

  return query select v_day.is_working, v_day.is_continuous,
                      v_day.entry_time, v_day.lunch_out_time,
                      v_day.lunch_in_time, v_day.exit_time,
                      'horario';
end $$;

-- Marcaciones esperadas de un trabajador en una fecha, ya en hora absoluta.
create or replace function app.expected_events(p_employee uuid, p_date date)
returns table (event event_type, expected_at timestamptz, is_arrival boolean)
language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare d record; v_tz text;
begin
  select b.timezone into v_tz
    from employees e join branches b on b.id = e.branch_id
   where e.id = p_employee;
  if v_tz is null then return; end if;

  select * into d from app.effective_day(p_employee, p_date);
  if not found or not d.is_working then return; end if;

  return query
  select v.event, ((p_date + v.t) at time zone v_tz), v.arr
  from (values
      ('entrada'::event_type,          d.entry_time,     true),
      ('salida_almuerzo'::event_type,  d.lunch_out_time, false),
      ('regreso_almuerzo'::event_type, d.lunch_in_time,  true),
      ('salida'::event_type,           d.exit_time,      false)
  ) as v(event, t, arr)
  where v.t is not null
  order by v.t;
end $$;

create or replace function app.expected_minutes(p_employee uuid, p_date date)
returns integer language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare d record; v int;
begin
  select * into d from app.effective_day(p_employee, p_date);
  if not found or not d.is_working then return 0; end if;
  if d.is_continuous then
    v := extract(epoch from (d.exit_time - d.entry_time)) / 60;
  else
    v := (extract(epoch from (d.lunch_out_time - d.entry_time))
        + extract(epoch from (d.exit_time - d.lunch_in_time))) / 60;
  end if;
  return greatest(v, 0);
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 4. CLASIFICACIÓN
-- ─────────────────────────────────────────────────────────────────────
-- Ningún umbral está en el código: todos salen de system_settings y
-- pueden fijarse por sede.
--   Llegadas  (entrada, regreso): temprano = ANTICIPADO, tarde = RETRASO.
--   Salidas   (almuerzo, salida): salir antes de tiempo también se penaliza;
--                                 salir después nunca es falta.
create or replace function app.classify(p_delta int, p_is_arrival boolean, p_branch uuid)
returns punch_status language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_early int; v_grace int; v_tol int;
begin
  if p_is_arrival then
    v_early := app.setting_int('attendance.arrival.early_minutes',     p_branch, 15);
    v_grace := app.setting_int('attendance.arrival.grace_minutes',     p_branch, 5);
    v_tol   := app.setting_int('attendance.arrival.tolerance_minutes', p_branch, 10);
    if p_delta <= -v_early then return 'anticipado'; end if;
    if p_delta <=  v_grace then return 'puntual';    end if;
    if p_delta <=  v_tol   then return 'tolerancia'; end if;
    return 'retraso';
  else
    v_grace := app.setting_int('attendance.departure.grace_minutes',     p_branch, 5);
    v_tol   := app.setting_int('attendance.departure.tolerance_minutes', p_branch, 10);
    if p_delta >= -v_grace then return 'puntual';    end if;
    if p_delta >= -v_tol   then return 'tolerancia'; end if;
    return 'retraso';   -- salida anticipada; delta_minutes conserva el signo
  end if;
end $$;

-- Cuál marcación corresponde AHORA: la esperada aún no registrada más
-- cercana al instante actual. Resuelve correctamente el caso de quien
-- olvidó una marcación intermedia.
create or replace function app.next_expected(p_employee uuid, p_at timestamptz)
returns table (event event_type, expected_at timestamptz, is_arrival boolean, work_date date)
language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_tz text; v_date date;
begin
  select b.timezone into v_tz
    from employees e join branches b on b.id = e.branch_id
   where e.id = p_employee;
  if v_tz is null then return; end if;

  v_date := (p_at at time zone v_tz)::date;

  return query
  select x.event, x.expected_at, x.is_arrival, v_date
    from app.expected_events(p_employee, v_date) x
   where not exists (
     select 1 from attendance_events a
      where a.employee_id = p_employee
        and a.work_date   = v_date
        and a.event       = x.event
   )
   order by abs(extract(epoch from (p_at - x.expected_at)))
   limit 1;
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 5. PIN
-- ─────────────────────────────────────────────────────────────────────
-- El pepper NUNCA se guarda en la base de datos: viaja como argumento
-- desde la Edge Function, que lo lee de su entorno. Un volcado completo
-- de PostgreSQL no permite recuperar ni un solo PIN.

create or replace function app.pin_is_trivial(p_pin text)
returns boolean language plpgsql immutable as $$
declare i int; v_asc boolean := true; v_desc boolean := true; v_same boolean := true;
begin
  if p_pin !~ '^[0-9]{6}$' then return true; end if;
  for i in 2..6 loop
    if substr(p_pin, i, 1)::int <> substr(p_pin, i-1, 1)::int + 1 then v_asc  := false; end if;
    if substr(p_pin, i, 1)::int <> substr(p_pin, i-1, 1)::int - 1 then v_desc := false; end if;
    if substr(p_pin, i, 1) <> substr(p_pin, 1, 1)                 then v_same := false; end if;
  end loop;
  return v_asc or v_desc or v_same
      or p_pin in ('112233','123123','121212','102030','696969','abc123');
end $$;

create or replace function app.generate_pin()
returns text language plpgsql volatile
set search_path = app, public, extensions, pg_temp as $$
declare v text; i int := 0;
begin
  loop
    i := i + 1;
    v := lpad(((random() * 999999)::int)::text, 6, '0');
    exit when not app.pin_is_trivial(v) or i > 50;
  end loop;
  return v;
end $$;

create or replace function app.pin_lookup_value(p_pin text, p_pepper text)
returns bytea language sql immutable
set search_path = extensions, pg_temp as $$
  select hmac(p_pin::bytea, p_pepper::bytea, 'sha256')
$$;

-- Asigna o regenera el PIN. Al ejecutarse, el PIN anterior deja de servir
-- inmediatamente: se sobrescriben hash e índice ciego en la misma sentencia.
create or replace function app.set_employee_pin(
  p_employee uuid, p_pin text, p_pepper text, p_actor uuid default null)
returns void language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare v_branch uuid;
begin
  if app.pin_is_trivial(p_pin) then
    raise exception 'PIN_DEBIL' using hint = 'Seis dígitos, sin secuencias ni repeticiones.';
  end if;
  select branch_id into v_branch from employees where id = p_employee and deleted_at is null;
  if v_branch is null then raise exception 'EMPLEADO_INEXISTENTE'; end if;

  begin
    update employees
       set pin_hash       = crypt(p_pin || p_pepper, gen_salt('bf', 10)),
           pin_lookup     = app.pin_lookup_value(p_pin, p_pepper),
           pin_updated_at = now(),
           pin_updated_by = p_actor
     where id = p_employee;
  exception when unique_violation then
    raise exception 'PIN_EN_USO' using hint = 'Ese PIN ya pertenece a otro trabajador.';
  end;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (p_actor, case when p_actor is null then 'system' else 'user' end,
          'employee.pin_regenerado', 'employees', p_employee, v_branch,
          jsonb_build_object('at', now()));
end $$;

create or replace function app.log_pin_attempt(
  p_terminal uuid, p_branch uuid, p_employee uuid,
  p_success boolean, p_reason text, p_delay int)
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  insert into pin_attempts (terminal_id, branch_id, employee_id, success, reason, delay_ms)
  values (p_terminal, p_branch, p_employee, p_success, p_reason, p_delay);
end $$;

-- Freno progresivo POR TERMINAL. Nunca bloquea: un trabajador no puede
-- dejar sin marcar a sus compañeros. Sólo encarece el ensayo y error.
create or replace function app.pin_delay_ms(p_terminal uuid)
returns integer language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_fallos int; v_base int; v_max int; v_win int;
begin
  v_base := app.setting_int('pin.throttle_base_ms',       null, 300);
  v_max  := app.setting_int('pin.throttle_max_ms',        null, 3000);
  v_win  := app.setting_int('pin.throttle_window_minutes', null, 10);

  select count(*) into v_fallos
    from pin_attempts
   where terminal_id = p_terminal
     and success = false
     and created_at > now() - make_interval(mins => v_win);

  return least((v_base * power(2, least(v_fallos, 8)))::int, v_max);
end $$;

-- Detección de ensayo y error: no bloquea, alerta.
create or replace function app.pin_check_bruteforce(p_terminal uuid, p_branch uuid)
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_fallos int; v_umbral int; v_win int;
begin
  v_umbral := app.setting_int('pin.alert_failures', null, 12);
  v_win    := app.setting_int('pin.alert_window_minutes', null, 10);

  select count(*) into v_fallos
    from pin_attempts
   where terminal_id = p_terminal and success = false
     and created_at > now() - make_interval(mins => v_win);

  if v_fallos = v_umbral then     -- se alerta una sola vez por umbral cruzado
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
    values ('terminal', 'pin.posible_fuerza_bruta', 'terminals', p_terminal, p_branch,
            jsonb_build_object('fallos', v_fallos, 'ventana_min', v_win));
  end if;
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 6. TERMINAL: emparejamiento y autenticación
-- ─────────────────────────────────────────────────────────────────────

create or replace function app.create_pairing_code(p_terminal uuid, p_actor uuid)
returns text language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare
  v_alfabeto text := 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   -- sin 0/O/1/I
  v_code text := '';
  v_terminal record;
  v_min int;
  i int;
begin
  select * into v_terminal from terminals where id = p_terminal and deleted_at is null;
  if not found then raise exception 'TERMINAL_INEXISTENTE'; end if;

  v_min := app.setting_int('terminal.pairing_ttl_minutes', null, 10);

  for i in 1..8 loop
    v_code := v_code || substr(v_alfabeto, 1 + floor(random() * length(v_alfabeto))::int, 1);
  end loop;

  -- Los códigos vivos anteriores del mismo terminal quedan revocados.
  update terminal_pairing_codes
     set revoked_at = now()
   where terminal_id = p_terminal and used_at is null and revoked_at is null and expires_at > now();

  insert into terminal_pairing_codes (terminal_id, code_hash, code_hint, expires_at, created_by)
  values (p_terminal,
          digest(v_code || v_terminal.code, 'sha256'),
          substr(v_code, 1, 3),
          now() + make_interval(mins => v_min),
          p_actor);

  return v_code;   -- se muestra UNA vez; después sólo queda su hash
end $$;

create or replace function app.redeem_pairing_code(
  p_terminal_code text, p_code text, p_device_label text)
returns table (terminal_id uuid, terminal_code text, branch_id uuid, branch_name text,
               device_token text, signing_key text)
language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare v_t record; v_pc record; v_token text; v_key bytea;
begin
  select * into v_t from terminals where code = p_terminal_code and deleted_at is null and is_active;
  if not found then raise exception 'TERMINAL_INEXISTENTE'; end if;

  select pc.* into v_pc
    from terminal_pairing_codes pc
   where pc.terminal_id = v_t.id
     and pc.code_hash = digest(upper(btrim(p_code)) || v_t.code, 'sha256')
   for update;

  if not found then
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
    values ('terminal','terminal.pairing_fallido','terminals', v_t.id, v_t.branch_id,
            jsonb_build_object('device', p_device_label));
    raise exception 'CODIGO_INVALIDO';
  end if;
  if v_pc.used_at is not null    then raise exception 'CODIGO_YA_USADO'; end if;
  if v_pc.revoked_at is not null then raise exception 'CODIGO_REVOCADO'; end if;
  if v_pc.expires_at <= now()    then raise exception 'CODIGO_VENCIDO';  end if;

  v_token := encode(gen_random_bytes(32), 'base64');
  v_key   := gen_random_bytes(32);

  update terminal_pairing_codes pc
     set used_at = now(), used_device = p_device_label
   where pc.id = v_pc.id;

  update terminals t
     set token_hash   = crypt(v_token, gen_salt('bf', 10)),
         signing_key  = v_key,
         paired_at    = now(),
         paired_by    = v_pc.created_by,
         device_label = p_device_label,
         last_seq     = 0
   where t.id = v_t.id;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (v_pc.created_by, 'terminal', 'terminal.emparejado', 'terminals', v_t.id, v_t.branch_id,
          jsonb_build_object('device', p_device_label));

  return query
    select v_t.id, v_t.code, v_t.branch_id, b.name, v_token, encode(v_key, 'base64')
      from branches b where b.id = v_t.branch_id;
end $$;

create or replace function app.authenticate_terminal(p_terminal_code text, p_token text)
returns uuid language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare v_t record;
begin
  select * into v_t from terminals
   where code = p_terminal_code and is_active and deleted_at is null;
  if not found or v_t.token_hash is null then return null; end if;
  if crypt(p_token, v_t.token_hash) <> v_t.token_hash then
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id)
    values ('terminal','terminal.token_invalido','terminals', v_t.id, v_t.branch_id);
    return null;
  end if;
  update terminals set last_seen_at = now() where id = v_t.id;
  return v_t.id;
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 7. MARCACIÓN
-- ─────────────────────────────────────────────────────────────────────

-- Paso 1: el PIN viaja una sola vez y se cambia por un ticket de 60 s.
--
-- Esta función NO lanza excepciones ante un PIN incorrecto: devuelve el
-- motivo. Si lanzara, PostgreSQL revertiría en el mismo instante el registro
-- del intento fallido y la alerta de fuerza bruta — es decir, el atacante
-- borraría su propio rastro con sólo fallar. Devolver el resultado mantiene
-- la auditoría intacta.
-- La espera progresiva se devuelve en `delay_ms` y la aplica la Edge Function,
-- para no retener una conexión de base de datos durante la penalización.
create or replace function app.verify_pin(p_terminal uuid, p_pin text, p_pepper text)
returns table (
  ok boolean, reason text, delay_ms integer,
  employee_id uuid, full_name text, cargo text, photo_path text,
  branch_id uuid, branch_name text,
  event event_type, expected_at timestamptz, is_arrival boolean,
  ticket uuid, ticket_expires timestamptz)
language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare
  v_t record; v_emp record; v_next record;
  v_delay int; v_ticket uuid; v_exp timestamptz; v_ttl int; v_tz text;
begin
  select t.*, b.name as branch_name into v_t
    from terminals t join branches b on b.id = t.branch_id
   where t.id = p_terminal and t.is_active and t.deleted_at is null;
  if not found then raise exception 'TERMINAL_INVALIDO'; end if;

  v_delay := app.pin_delay_ms(v_t.id);

  select e.* into v_emp
    from employees e
   where e.pin_lookup = app.pin_lookup_value(p_pin, p_pepper)
     and e.deleted_at is null;

  if not found then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, null, false, 'pin_inexistente', v_delay);
    perform app.pin_check_bruteforce(v_t.id, v_t.branch_id);
    return query select false, 'PIN_INVALIDO', v_delay, null::uuid, null::text, null::text, null::text,
                        null::uuid, null::text, null::event_type, null::timestamptz, null::boolean,
                        null::uuid, null::timestamptz;
    return;
  end if;

  -- El índice ciego localiza; bcrypt es lo único que autentica.
  if v_emp.pin_hash is null
     or crypt(p_pin || p_pepper, v_emp.pin_hash) <> v_emp.pin_hash then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, false, 'hash_no_coincide', v_delay);
    perform app.pin_check_bruteforce(v_t.id, v_t.branch_id);
    return query select false, 'PIN_INVALIDO', v_delay, null::uuid, null::text, null::text, null::text,
                        null::uuid, null::text, null::event_type, null::timestamptz, null::boolean,
                        null::uuid, null::timestamptz;
    return;
  end if;

  if not v_emp.is_active then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, false, 'empleado_inactivo', 0);
    return query select false, 'EMPLEADO_INACTIVO', 0, v_emp.id, null::text, null::text, null::text,
                        null::uuid, null::text, null::event_type, null::timestamptz, null::boolean,
                        null::uuid, null::timestamptz;
    return;
  end if;

  -- La sede la impone el terminal: nadie marca en una sede que no es la suya.
  if v_emp.branch_id <> v_t.branch_id then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, false, 'sede_ajena', 0);
    select b.timezone into v_tz from branches b where b.id = v_emp.branch_id;
    insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date, source)
    values (v_emp.id, v_emp.branch_id, 'marcacion_foranea',
            'Intento de marcación en el terminal ' || v_t.code || ' (sede distinta a la asignada).',
            now(), (now() at time zone v_tz)::date, 'sistema');
    return query select false, 'EMPLEADO_OTRA_SEDE', 0, v_emp.id, null::text, null::text, null::text,
                        null::uuid, null::text, null::event_type, null::timestamptz, null::boolean,
                        null::uuid, null::timestamptz;
    return;
  end if;

  select n.* into v_next from app.next_expected(v_emp.id, now()) n;
  if not found then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, true, 'sin_evento_pendiente', 0);
    return query select false, 'SIN_EVENTO_PENDIENTE', 0, v_emp.id,
                        v_emp.first_name || ' ' || v_emp.last_name, v_emp.position, v_emp.photo_path,
                        v_t.branch_id, v_t.branch_name,
                        null::event_type, null::timestamptz, null::boolean, null::uuid, null::timestamptz;
    return;
  end if;

  v_ttl := app.setting_int('terminal.ticket_ttl_seconds', v_t.branch_id, 60);
  v_exp := now() + make_interval(secs => v_ttl);

  insert into punch_tickets (employee_id, terminal_id, event, expected_at, expires_at)
  values (v_emp.id, v_t.id, v_next.event, v_next.expected_at, v_exp)
  returning id into v_ticket;

  perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, true, 'ok', 0);

  return query select
    true, 'OK', 0,
    v_emp.id,
    v_emp.first_name || ' ' || v_emp.last_name,
    v_emp.position,
    v_emp.photo_path,
    v_t.branch_id,
    v_t.branch_name,
    v_next.event,
    v_next.expected_at,
    v_next.is_arrival,
    v_ticket,
    v_exp;
end $$;

-- Núcleo compartido por la marcación en línea y la sincronización offline.
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
    'work_date',     v_row.work_date,
    'recorded_at',   v_row.recorded_at,
    'recorded_local',to_char(v_row.recorded_at at time zone v_tz, 'HH24:MI:SS'),
    'expected_at',   v_row.expected_at,
    'delta_minutes', v_row.delta_minutes,
    'status',        v_row.status,
    'origin',        v_row.origin);
end $$;

-- Paso 2 (en línea): el trabajador confirma. La hora es la del servidor.
create or replace function app.register_punch(p_ticket uuid, p_client_event uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_tk record; v_existing attendance_events;
begin
  select * into v_existing from attendance_events where client_event_id = p_client_event;
  if found then
    return jsonb_build_object('id', v_existing.id, 'duplicado', true, 'event', v_existing.event,
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

-- Sincronización offline. La Edge Function ya verificó la firma HMAC del
-- lote y el PIN; aquí se validan reloj, secuencia y ventana.
--
-- Tampoco lanza excepciones: un lote offline rechazado DEBE dejar rastro,
-- y una excepción revertiría ese rastro junto con el rechazo.
create or replace function app.register_offline_punch(
  p_terminal uuid, p_employee uuid, p_client_event uuid,
  p_device_ts timestamptz, p_seq bigint, p_drift int default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_t record; v_emp record; v_next record; v_max_h int; v_max_drift int;
        v_res jsonb; v_motivo text;
begin
  select t.* into v_t from terminals t
   where t.id = p_terminal and t.is_active and t.deleted_at is null for update;
  if not found then return jsonb_build_object('ok', false, 'reason', 'TERMINAL_INVALIDO'); end if;

  v_max_h     := app.setting_int('offline.max_age_hours',     null, 72);
  v_max_drift := app.setting_int('offline.max_drift_minutes', null, 10);

  select e.* into v_emp from employees e
   where e.id = p_employee and e.deleted_at is null and e.is_active;

  v_motivo := case
    when not found                                then 'EMPLEADO_INACTIVO'
    when v_emp.branch_id <> v_t.branch_id         then 'EMPLEADO_OTRA_SEDE'
    -- Contador monotónico: repetirlo o retroceder es un reenvío.
    when p_seq is null or p_seq <= v_t.last_seq   then 'SECUENCIA_INVALIDA'
    when p_device_ts > now() + interval '2 minutes' then 'HORA_FUTURA'
    when p_device_ts < now() - make_interval(hours => v_max_h) then 'FUERA_DE_VENTANA_OFFLINE'
    when v_t.last_sync_at is not null
         and p_device_ts < v_t.last_sync_at - interval '5 minutes'
                                                  then 'ANTERIOR_A_ULTIMA_SINCRONIZACION'
    else null end;

  if v_motivo is not null then
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
    values ('terminal', 'offline.rechazado', 'terminals', p_terminal, v_t.branch_id,
            jsonb_build_object('motivo', v_motivo, 'empleado', p_employee,
                               'device_ts', p_device_ts, 'seq', p_seq, 'drift_sec', p_drift));
    return jsonb_build_object('ok', false, 'reason', v_motivo);
  end if;

  select n.* into v_next from app.next_expected(p_employee, p_device_ts) n;
  if not found then
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
    values ('terminal', 'offline.rechazado', 'terminals', p_terminal, v_t.branch_id,
            jsonb_build_object('motivo', 'SIN_EVENTO_PENDIENTE', 'empleado', p_employee,
                               'device_ts', p_device_ts, 'seq', p_seq));
    return jsonb_build_object('ok', false, 'reason', 'SIN_EVENTO_PENDIENTE');
  end if;

  begin
    v_res := app._insert_punch(
      p_employee, p_terminal, p_device_ts, 'offline', p_client_event,
      v_next.event, v_next.expected_at, v_next.is_arrival,
      p_device_ts, p_seq, p_drift);
  exception when others then
    insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
    values ('terminal', 'offline.rechazado', 'terminals', p_terminal, v_t.branch_id,
            jsonb_build_object('motivo', sqlerrm, 'empleado', p_employee, 'seq', p_seq));
    return jsonb_build_object('ok', false, 'reason', sqlerrm);
  end;

  update terminals t
     set last_seq = greatest(t.last_seq, p_seq), last_sync_at = now(), last_seen_at = now()
   where t.id = p_terminal;

  -- Un reloj desviado no invalida la marcación: la manda a revisión.
  if p_drift is not null and abs(p_drift) > v_max_drift * 60 then
    insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date, source, related_event)
    values (p_employee, v_t.branch_id, 'desfase_reloj',
            'Marcación offline con desfase de reloj de ' || round(p_drift / 60.0) || ' minutos.',
            p_device_ts, (v_res ->> 'work_date')::date, 'sistema', (v_res ->> 'id')::uuid);
  end if;

  return v_res || jsonb_build_object('ok', true, 'reason', 'OK');
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 8. EVIDENCIA FOTOGRÁFICA
-- ─────────────────────────────────────────────────────────────────────
-- Nunca impide marcar. Si falta, se registra y se abre una novedad.

create or replace function app.attach_evidence(
  p_event uuid, p_path text, p_bytes int default null,
  p_mime text default 'image/jpeg', p_captured_at timestamptz default null)
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_ev attendance_events; v_dias int;
begin
  select * into v_ev from attendance_events where id = p_event;
  if not found then raise exception 'MARCACION_INEXISTENTE'; end if;

  v_dias := app.setting_int('evidence.retention_days', v_ev.branch_id, 180);

  insert into attendance_evidence (event_id, branch_id, storage_path, mime_type, byte_size, captured_at, expires_on)
  values (p_event, v_ev.branch_id, p_path, p_mime, p_bytes,
          coalesce(p_captured_at, v_ev.recorded_at),
          (v_ev.recorded_at::date + v_dias))
  on conflict (event_id) do update
    set storage_path = excluded.storage_path,
        byte_size    = excluded.byte_size,
        mime_type    = excluded.mime_type;

  update attendance_events set evidence_status = 'almacenada' where id = p_event;
end $$;

create or replace function app.mark_missing_evidence(p_event uuid, p_reason text default 'captura_fallida')
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_ev attendance_events;
begin
  select * into v_ev from attendance_events where id = p_event;
  if not found then raise exception 'MARCACION_INEXISTENTE'; end if;

  update attendance_events set evidence_status = 'sin_evidencia' where id = p_event;

  insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date, source, related_event)
  values (v_ev.employee_id, v_ev.branch_id, 'sin_evidencia',
          'Marcación registrada sin evidencia fotográfica (' || p_reason || ').',
          v_ev.recorded_at, v_ev.work_date, 'sistema', v_ev.id);

  perform app.recompute_daily(v_ev.employee_id, v_ev.work_date);
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- 9. RESUMEN DIARIO Y CIERRE SEMANAL
-- ─────────────────────────────────────────────────────────────────────
-- Una ausencia no es una marcación: es la falta de ella. Por eso se
-- materializa aquí y no se inventan registros en attendance_events.

create or replace function app.recompute_daily(p_employee uuid, p_date date)
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare
  d record; v_branch uuid;
  v_expected int := 0; v_registered int := 0; v_worked int := 0;
  v_late_min int := 0; v_early int := 0; v_late int := 0; v_no_ev int := 0;
  v_last timestamptz; v_cutoff int; v_status day_status; v_just uuid; v_cerrado boolean;
begin
  select e.branch_id into v_branch from employees e where e.id = p_employee;
  if v_branch is null then return; end if;

  select * into d from app.effective_day(p_employee, p_date);

  select count(*), max(x.expected_at) into v_expected, v_last
    from app.expected_events(p_employee, p_date) x;

  select count(*),
         count(*) filter (where a.status = 'anticipado'),
         count(*) filter (where a.status = 'retraso' and a.delta_minutes > 0),
         coalesce(sum(greatest(a.delta_minutes, 0))
                  filter (where a.event in ('entrada','regreso_almuerzo')), 0),
         count(*) filter (where a.evidence_status = 'sin_evidencia'),
         coalesce(
           case when coalesce(d.is_continuous, false) then
             extract(epoch from (max(a.recorded_at) filter (where a.event = 'salida')
                               - max(a.recorded_at) filter (where a.event = 'entrada'))) / 60
           else
             coalesce(extract(epoch from (max(a.recorded_at) filter (where a.event = 'salida_almuerzo')
                                        - max(a.recorded_at) filter (where a.event = 'entrada'))) / 60, 0)
           + coalesce(extract(epoch from (max(a.recorded_at) filter (where a.event = 'salida')
                                        - max(a.recorded_at) filter (where a.event = 'regreso_almuerzo'))) / 60, 0)
           end, 0)
    into v_registered, v_early, v_late, v_late_min, v_no_ev, v_worked
    from attendance_events a
   where a.employee_id = p_employee and a.work_date = p_date;

  select i.id into v_just
    from incidents i
   where i.employee_id = p_employee and i.work_date = p_date
     and i.status = 'aprobada' and i.source = 'usuario'
   order by i.created_at limit 1;

  v_cutoff  := app.setting_int('attendance.absence.cutoff_minutes', v_branch, 120);
  v_cerrado := v_last is null or now() > v_last + make_interval(mins => v_cutoff);

  if not coalesce(d.is_working, false) then
    v_status := 'descanso';
  elsif v_just is not null and v_registered < v_expected then
    v_status := 'justificado';
  elsif v_registered = 0 then
    v_status := case when v_cerrado then 'ausente' else 'en_curso' end;
  elsif v_registered < v_expected then
    v_status := case when v_cerrado then 'incompleto' else 'en_curso' end;
  else
    v_status := 'completo';
  end if;

  insert into attendance_daily (
    employee_id, branch_id, work_date, is_working_day, expected_events, registered_events,
    expected_minutes, worked_minutes, late_minutes, early_count, late_count,
    missing_evidence, status, justified_by, computed_at)
  values (
    p_employee, v_branch, p_date, coalesce(d.is_working, false), v_expected, v_registered,
    app.expected_minutes(p_employee, p_date), greatest(v_worked, 0), v_late_min, v_early, v_late,
    v_no_ev, v_status, v_just, now())
  on conflict (employee_id, work_date) do update set
    branch_id         = excluded.branch_id,
    is_working_day    = excluded.is_working_day,
    expected_events   = excluded.expected_events,
    registered_events = excluded.registered_events,
    expected_minutes  = excluded.expected_minutes,
    worked_minutes    = excluded.worked_minutes,
    late_minutes      = excluded.late_minutes,
    early_count       = excluded.early_count,
    late_count        = excluded.late_count,
    missing_evidence  = excluded.missing_evidence,
    status            = excluded.status,
    justified_by      = excluded.justified_by,
    computed_at       = now();
end $$;

create or replace function app.recompute_daily_branch(p_branch uuid, p_date date)
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare r record; n int := 0;
begin
  for r in select id from employees
            where branch_id = p_branch and is_active and deleted_at is null
              and hired_on <= p_date
  loop
    perform app.recompute_daily(r.id, p_date);
    n := n + 1;
  end loop;
  return n;
end $$;

-- Cierre semanal: MIDE, CALCULA e INFORMA. No descuenta nada.
create or replace function app.compute_weekly_closure(p_employee uuid, p_week_start date)
returns uuid language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; s record; v_id uuid; v_dias int; v_incidencias int;
begin
  if extract(isodow from p_week_start) <> 1 then
    raise exception 'LA_SEMANA_INICIA_LUNES';
  end if;
  select branch_id into v_branch from employees where id = p_employee;
  if v_branch is null then raise exception 'EMPLEADO_INEXISTENTE'; end if;

  select
    coalesce(sum(expected_minutes), 0)                                   as exp_min,
    coalesce(sum(worked_minutes), 0)                                     as wrk_min,
    coalesce(sum(late_minutes), 0)                                       as late_min,
    coalesce(sum(early_count), 0)                                        as early_n,
    coalesce(sum(late_count), 0)                                         as late_n,
    coalesce(sum(missing_evidence), 0)                                   as no_ev,
    count(*) filter (where status = 'ausente')                           as ausencias,
    count(*) filter (where status = 'incompleto')                        as incompletos,
    count(*) filter (where is_working_day)                               as dias_lab,
    count(*) filter (where is_working_day and status in ('completo','justificado')) as dias_ok,
    coalesce(sum(registered_events), 0)                                  as marcaciones,
    coalesce(sum(expected_events), 0)                                    as esperadas
    into s
    from attendance_daily
   where employee_id = p_employee
     and work_date between p_week_start and p_week_start + 6;

  select count(*) into v_incidencias
    from incidents
   where employee_id = p_employee
     and work_date between p_week_start and p_week_start + 6
     and source = 'usuario';

  insert into weekly_closures (
    employee_id, branch_id, week_start, week_end,
    expected_minutes, worked_minutes, attendance_pct, punctuality_pct,
    early_count, late_count, late_minutes, absence_count, incomplete_count,
    incident_count, no_evidence_count, computed_at)
  values (
    p_employee, v_branch, p_week_start, p_week_start + 6,
    s.exp_min, s.wrk_min,
    case when s.dias_lab   > 0 then round(100.0 * s.dias_ok     / s.dias_lab,   2) else 0 end,
    case when s.marcaciones > 0 then round(100.0 * (s.marcaciones - s.late_n) / s.marcaciones, 2) else 0 end,
    s.early_n, s.late_n, s.late_min, s.ausencias, s.incompletos,
    v_incidencias, s.no_ev, now())
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
    computed_at       = now()
  where weekly_closures.status = 'pendiente'   -- una semana ya cerrada no se recalcula sola
  returning id into v_id;

  return v_id;
end $$;
