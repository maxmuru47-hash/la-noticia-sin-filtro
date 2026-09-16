-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0016 · Modo sin conexión (Fase 6)
-- =====================================================================
-- SÓLO AÑADE. El motor de la marcación offline —`register_offline_punch`,
-- con su ventana de 72 h, su secuencia monotónica y su desfase de reloj—
-- existe desde la Fase 1. Lo que faltaba era la puerta de entrada.
--
-- EL PROBLEMA QUE RESUELVE ESTE ARCHIVO
-- -------------------------------------
-- Sin conexión, el terminal NO PUEDE comprobar un PIN: la pimienta vive
-- en la función del servidor y jamás baja al dispositivo. Tampoco puede
-- guardar el PIN en claro mientras espera: una tableta robada entregaría
-- el PIN de toda la sede.
--
-- Por eso el terminal cifra el PIN con una LLAVE PÚBLICA y lo encola. Sólo
-- el servidor, que tiene la privada, puede abrirlo. El dispositivo guarda
-- algo que ni él mismo puede leer.
--
-- Al sincronizar hace falta identificar a la persona por su PIN pero SIN
-- crear un ticket, porque el evento no se resuelve con la hora del
-- servidor sino con la del dispositivo. Eso es `identificar_por_pin`.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- CORRECCIÓN AL MOTOR OFFLINE
-- ─────────────────────────────────────────────────────────────────────
-- Éste es el único punto de la Fase 6 que cambia algo que ya existía, y
-- corrige un fallo que sólo se ve cuando se sincroniza de verdad.
--
-- `register_offline_punch` guardaba `last_sync_at = now()` con cada
-- marcación aceptada, y a la vez rechazaba toda marcación anterior a ese
-- valor («ANTERIOR_A_ULTIMA_SINCRONIZACION»). Suena razonable hasta que se
-- mira lo que pasa con una cola real:
--
--   Un terminal pasa tres horas sin señal y acumula doce marcaciones.
--   Vuelve la red y las manda en orden. La primera —la de hace tres
--   horas— entra, y al entrar pone `last_sync_at` en la hora ACTUAL.
--   Las once siguientes declaran horas anteriores a esa, así que TODAS
--   se rechazan.
--
-- Es decir: el modo sin conexión sólo funcionaba si había exactamente una
-- marcación en la cola. En el escenario para el que existe, se perdía casi
-- todo.
--
-- La corrección es entender qué debe significar ese campo: no «cuándo
-- sincronizamos» —para eso ya está `last_seen_at`— sino «cuál es la hora
-- más avanzada que este aparato nos ha declarado». Comparada contra eso,
-- una cola en orden entra entera, y un reenvío viejo se sigue rechazando,
-- que era lo que se quería proteger.
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
    -- Contra la hora MÁS AVANZADA QUE DECLARÓ EL APARATO, no contra la del
    -- servidor. Los cinco minutos de holgura absorben una corrección de
    -- reloj por NTP sin tirar la marcación.
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
     set last_seq      = greatest(t.last_seq, p_seq),
         -- La hora más avanzada declarada por el aparato (ver arriba).
         last_sync_at  = greatest(coalesce(t.last_sync_at, p_device_ts), p_device_ts),
         -- Cuándo se supo de él por última vez: eso sí es ahora.
         last_seen_at  = now()
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
-- IDENTIFICAR POR PIN, SIN TICKET
-- ─────────────────────────────────────────────────────────────────────
-- Igual que `verify_pin` en lo que autentica —índice ciego para localizar,
-- bcrypt para autenticar—, pero sin crear ticket y sin aplicar la espera
-- progresiva: en una sincronización por lotes la espera no protege de
-- nada, sólo alargaría la transacción.
--
-- El rastro del intento SÍ se escribe, y marcado como offline: si alguien
-- se lleva la tableta y encola mil PIN a ver cuál cae, esto es lo que lo
-- deja por escrito y lo que dispara la alerta de fuerza bruta.
create or replace function app.identificar_por_pin(
  p_terminal uuid, p_pin text, p_pepper text)
returns table (ok boolean, reason text, employee_id uuid)
language plpgsql volatile security definer
set search_path = app, public, extensions, pg_temp as $$
declare v_t record; v_emp record;
begin
  select t.* into v_t from terminals t
   where t.id = p_terminal and t.is_active and t.deleted_at is null;
  if not found then
    return query select false, 'TERMINAL_INVALIDO', null::uuid; return;
  end if;

  select e.* into v_emp from employees e
   where e.pin_lookup = app.pin_lookup_value(p_pin, p_pepper)
     and e.deleted_at is null;

  if not found then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, null, false, 'offline:pin_inexistente', 0);
    perform app.pin_check_bruteforce(v_t.id, v_t.branch_id);
    return query select false, 'PIN_INVALIDO', null::uuid; return;
  end if;

  if v_emp.pin_hash is null
     or crypt(p_pin || p_pepper, v_emp.pin_hash) <> v_emp.pin_hash then
    perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, false, 'offline:hash_no_coincide', 0);
    perform app.pin_check_bruteforce(v_t.id, v_t.branch_id);
    return query select false, 'PIN_INVALIDO', null::uuid; return;
  end if;

  perform app.log_pin_attempt(v_t.id, v_t.branch_id, v_emp.id, true, 'offline:ok', 0);
  return query select true, 'OK', v_emp.id;
end $$;

-- ─────────────────────────────────────────────────────────────────────
-- PUENTE PARA LA FUNCIÓN DEL SERVIDOR
-- ─────────────────────────────────────────────────────────────────────
-- Una marcación offline completa, en una sola transacción: identificar y
-- registrar. Si se hicieran en dos llamadas, un corte entre ambas dejaría
-- el intento contado y la marcación perdida.
--
-- Nunca lanza excepción: devuelve el motivo. Igual que en la Fase 3, una
-- excepción revertiría el propio rastro del rechazo.
create or replace function public.edge_offline(
  p_terminal uuid, p_pin text, p_pepper text, p_client_event uuid,
  p_device_ts timestamptz, p_seq bigint, p_drift int default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare r record; v_t record; v_res jsonb;
begin
  select * into r from app.identificar_por_pin(p_terminal, p_pin, p_pepper);
  if not r.ok then
    select t.* into v_t from terminals t where t.id = p_terminal;
    if found then
      insert into audit_logs (actor_kind, action, entity, entity_id, branch_id, metadata)
      values ('terminal', 'offline.rechazado', 'terminals', p_terminal, v_t.branch_id,
              jsonb_build_object('motivo', r.reason, 'seq', p_seq, 'device_ts', p_device_ts));
    end if;
    return jsonb_build_object('ok', false, 'reason', r.reason,
                              'client_event_id', p_client_event, 'seq', p_seq);
  end if;

  v_res := app.register_offline_punch(
    p_terminal, r.employee_id, p_client_event, p_device_ts, p_seq, p_drift);

  return v_res || jsonb_build_object('client_event_id', p_client_event, 'seq', p_seq);
end $$;

-- Como todos los `edge_*`: fuera del alcance de cualquiera que no sea el
-- servidor. Ni el CEO puede ejecutarla desde el panel.
revoke all on function public.edge_offline(uuid, text, text, uuid, timestamptz, bigint, int) from public;
revoke all on function public.edge_offline(uuid, text, text, uuid, timestamptz, bigint, int) from anon, authenticated;
grant execute on function public.edge_offline(uuid, text, text, uuid, timestamptz, bigint, int) to service_role;

-- ─────────────────────────────────────────────────────────────────────
-- SALUD DE LA SINCRONIZACIÓN, PARA EL PANEL
-- ─────────────────────────────────────────────────────────────────────
-- Lo que hay en la cola de un dispositivo sólo lo sabe ese dispositivo.
-- Lo que sí se puede decir desde aquí, y es lo que de verdad importa, es
-- cuándo se supo de él por última vez y qué llegó rechazado.
create or replace function public.sincronizacion(p_dias int default 7)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb; v_desde timestamptz;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  v_desde := now() - make_interval(days => greatest(least(coalesce(p_dias, 7), 90), 1));

  select coalesce(jsonb_agg(f order by f->>'code'), '[]'::jsonb) into v_filas
    from (
      select jsonb_build_object(
        'id', t.id, 'code', t.code, 'sede', b.name, 'branch_id', t.branch_id,
        'emparejado', t.token_hash is not null,
        'aparato', t.device_label,
        'ultima_senal', t.last_seen_at,
        'ultima_sincronizacion', t.last_sync_at,
        'ultima_secuencia', t.last_seq,
        -- Horas desde que se supo del aparato. Un terminal emparejado que
        -- lleva días callado es la señal que hay que mirar.
        'horas_sin_senal', case when t.last_seen_at is null then null
                           else round(extract(epoch from (now() - t.last_seen_at)) / 3600.0, 1) end,
        'marcaciones_offline', (
          select count(*) from attendance_events a
           where a.terminal_id = t.id and a.origin = 'offline' and a.recorded_at >= v_desde),
        'rechazos', (
          select count(*) from audit_logs l
           where l.entity = 'terminals' and l.entity_id = t.id
             and l.action = 'offline.rechazado' and l.created_at >= v_desde),
        'motivos', (
          select coalesce(jsonb_object_agg(m.motivo, m.n), '{}'::jsonb) from (
            select l.metadata->>'motivo' as motivo, count(*) as n
              from audit_logs l
             where l.entity = 'terminals' and l.entity_id = t.id
               and l.action = 'offline.rechazado' and l.created_at >= v_desde
               and l.metadata->>'motivo' is not null
             group by 1) m)
      ) f
      from terminals t
      join branches b on b.id = t.branch_id
     where t.deleted_at is null and app.can_see_branch(t.branch_id)) s;

  return jsonb_build_object('ok', true, 'dias', p_dias, 'terminales', v_filas);
end $$;
grant execute on function public.sincronizacion(int) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- MARCACIONES QUE LLEGARON SIN CONEXIÓN
-- ─────────────────────────────────────────────────────────────────────
-- Una marcación offline vale lo mismo que una en línea, pero conviene
-- poder revisarlas aparte: la hora la puso el dispositivo, no el servidor.
create or replace function public.marcaciones_offline(
  p_branch uuid default null, p_dias int default 7)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb; v_desde date;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  v_desde := current_date - greatest(least(coalesce(p_dias, 7), 90), 1);

  select coalesce(jsonb_agg(jsonb_build_object(
      'id', a.id,
      'fecha', a.work_date,
      'nombre', e.first_name || ' ' || e.last_name,
      'cargo', e.position,
      'sede', b.name,
      'terminal', t.code,
      'evento', a.event,
      'estado', a.status,
      'minutos', a.delta_minutes,
      'registrada', a.recorded_at,
      'declarada', a.device_timestamp,
      'recibida', a.sync_timestamp,
      'secuencia', a.device_seq,
      -- Segundos de desfase entre el reloj del aparato y el real. Si es
      -- grande, hay una novedad abierta y la marcación está en revisión.
      'desfase_seg', a.clock_drift_sec,
      'evidencia', a.evidence_status)
      order by a.recorded_at desc), '[]'::jsonb)
    into v_filas
    from attendance_events a
    join employees e on e.id = a.employee_id
    join branches  b on b.id = a.branch_id
    left join terminals t on t.id = a.terminal_id
   where a.origin = 'offline' and a.work_date >= v_desde
     and app.can_see_branch(a.branch_id)
     and (p_branch is null or a.branch_id = p_branch);

  return jsonb_build_object('ok', true, 'desde', v_desde,
    'filas', jsonb_array_length(v_filas), 'marcaciones', v_filas);
end $$;
grant execute on function public.marcaciones_offline(uuid, int) to authenticated;
