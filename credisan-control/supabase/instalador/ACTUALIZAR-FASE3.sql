-- =====================================================================
--
--   CREDISAN CONTROL · ACTUALIZACIÓN A LA FASE 3
--   Terminal de marcación
--
-- =====================================================================
--
--   Su base ya está instalada. Esto sólo AÑADE lo que hace falta para
--   el terminal: no borra nada, no toca sus sedes, su personal ni sus
--   horarios.
--
--   1. SQL Editor → New query
--   2. Pegue todo este archivo
--   3. Run  (aceptando el aviso de operaciones destructivas: es por los
--      `revoke`, que son precisamente los que cierran la puerta)
--
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception
      'CREDISAN no está instalado en este proyecto. Ejecute primero INSTALAR.sql.';
  end if;
  if to_regproc('public.edge_verificar_pin') is not null then
    raise notice 'La Fase 3 ya estaba aplicada. Se actualizarán las funciones.';
  end if;
end $$;

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0013 · Terminal de marcación (Fase 3)
-- =====================================================================
-- Piezas que necesita la Edge Function para autorizar sin la llave maestra,
-- y las operaciones de terminal que faltaban: desemparejar y consultar
-- el estado de cada dispositivo.
-- =====================================================================

-- ¿Puede quien pregunta gestionar a este trabajador? La Edge Function la
-- llama CON EL TOKEN DE LA PERSONA, no con la llave maestra: así el permiso
-- lo decide la misma RLS que gobierna el resto del sistema.
create or replace function public.puedo_gestionar_empleado(p_employee uuid)
returns boolean language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid;
begin
  select branch_id into v_branch
    from employees where id = p_employee and deleted_at is null;
  if v_branch is null then return false; end if;
  return (app.is_ceo() or app.is_admin()) and app.can_see_branch(v_branch);
end $$;
grant execute on function public.puedo_gestionar_empleado(uuid) to authenticated;

-- Estado de los terminales visibles. El token y la clave de firma no salen:
-- sólo si está emparejado, con qué dispositivo y cuándo se le vio.
create or replace function public.terminales()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;

  select jsonb_agg(x order by x->>'code') into v
  from (
    select jsonb_build_object(
      'id', t.id, 'code', t.code, 'label', t.label,
      'branch_id', t.branch_id, 'sede', b.name,
      'emparejado', t.paired_at is not null,
      'paired_at', t.paired_at,
      'device_label', t.device_label,
      'last_seen_at', t.last_seen_at,
      'is_active', t.is_active,
      'marcaciones_hoy', (
        select count(*) from attendance_events a
         where a.terminal_id = t.id
           and a.work_date = (now() at time zone b.timezone)::date)
    ) as x
    from terminals t
    join branches b on b.id = t.branch_id
    where t.deleted_at is null and app.can_see_branch(t.branch_id)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;
grant execute on function public.terminales() to authenticated;

-- Desvincular un dispositivo: si el teléfono se pierde o se cambia, su token
-- deja de servir en el acto. El terminal sigue existiendo y su historial
-- intacto; lo único que se borra es la credencial del aparato.
create or replace function public.desemparejar_terminal(p_terminal uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_t record;
begin
  select t.* into v_t from terminals t where t.id = p_terminal and t.deleted_at is null;
  if not found then raise exception 'TERMINAL_INEXISTENTE'; end if;
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_t.branch_id) then
    raise exception 'NO_AUTORIZADO';
  end if;

  update terminals t
     set token_hash = null, signing_key = null, paired_at = null,
         device_label = null, last_seq = 0
   where t.id = p_terminal;

  update terminal_pairing_codes pc
     set revoked_at = now()
   where pc.terminal_id = p_terminal and pc.used_at is null and pc.revoked_at is null;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (auth.uid(), 'user', 'terminal.desemparejado', 'terminals', p_terminal, v_t.branch_id,
          jsonb_build_object('device', v_t.device_label));

  return jsonb_build_object('ok', true);
end $$;
grant execute on function public.desemparejar_terminal(uuid) to authenticated;

-- Marcaciones del día de una sede, para la pantalla de operación y para que
-- el terminal pueda mostrar "ya marcaste" sin exponer nada más.
create or replace function public.marcaciones_hoy(p_branch uuid)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb; v_tz text; v_fecha date;
begin
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;
  select timezone into v_tz from branches where id = p_branch;
  v_fecha := (now() at time zone v_tz)::date;

  select jsonb_agg(x order by x->>'recorded_at') into v
  from (
    select jsonb_build_object(
      'id', a.id,
      'empleado', e.first_name || ' ' || e.last_name,
      'cargo', e.position,
      'evento', a.event,
      'hora', to_char(a.recorded_at at time zone v_tz, 'HH24:MI'),
      'estado', a.status,
      'minutos', a.delta_minutes,
      'origen', a.origin,
      'evidencia', a.evidence_status,
      'recorded_at', a.recorded_at
    ) as x
    from attendance_events a
    join employees e on e.id = a.employee_id
    where a.branch_id = p_branch and a.work_date = v_fecha
  ) s;

  return jsonb_build_object('ok', true, 'fecha', v_fecha, 'marcaciones', coalesce(v, '[]'::jsonb));
end $$;
grant execute on function public.marcaciones_hoy(uuid) to authenticated;

-- ─────────────────────────────────────────────────────────────────────
-- Puente para la Edge Function
-- ─────────────────────────────────────────────────────────────────────
-- PostgREST sólo publica el esquema `public`, y el motor vive en `app`.
-- Estas envolturas son la única puerta, y sólo la llave maestra puede
-- cruzarla: `revoke` a todo el mundo, `grant` únicamente a service_role.

create or replace function public.edge_emparejar(
  p_terminal_code text, p_code text, p_device text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare r record;
begin
  select * into r from app.redeem_pairing_code(p_terminal_code, p_code, p_device);
  return jsonb_build_object(
    'ok', true,
    'terminal_id', r.terminal_id, 'terminal', r.terminal_code,
    'branch_id', r.branch_id, 'sede', r.branch_name,
    'token', r.device_token, 'firma', r.signing_key);
end $$;

create or replace function public.edge_autenticar_terminal(p_code text, p_token text)
returns uuid language sql volatile security definer
set search_path = app, public, pg_temp as $$
  select app.authenticate_terminal(p_code, p_token)
$$;

create or replace function public.edge_verificar_pin(p_terminal uuid, p_pin text, p_pepper text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare r record;
begin
  select * into r from app.verify_pin(p_terminal, p_pin, p_pepper);
  return jsonb_build_object(
    'ok', r.ok, 'motivo', r.reason, 'espera_ms', r.delay_ms,
    'empleado_id', r.employee_id, 'nombre', r.full_name, 'cargo', r.cargo,
    'foto', r.photo_path, 'sede', r.branch_name,
    'evento', r.event, 'esperado', r.expected_at, 'es_llegada', r.is_arrival,
    'ticket', r.ticket, 'ticket_vence', r.ticket_expires);
end $$;

create or replace function public.edge_registrar(p_ticket uuid, p_client uuid)
returns jsonb language sql volatile security definer
set search_path = app, public, pg_temp as $$
  select app.register_punch(p_ticket, p_client)
$$;

create or replace function public.edge_evidencia(
  p_event uuid, p_path text, p_bytes int, p_captured timestamptz)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  perform app.attach_evidence(p_event, p_path, p_bytes, 'image/jpeg', p_captured);
  return jsonb_build_object('ok', true);
end $$;

create or replace function public.edge_sin_evidencia(p_event uuid, p_motivo text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  perform app.mark_missing_evidence(p_event, p_motivo);
  return jsonb_build_object('ok', true);
end $$;

-- Genera y asigna el PIN. El pepper llega desde el entorno de la función y
-- no se guarda en ningún sitio; el PIN en claro se devuelve UNA vez y ya no
-- vuelve a existir en ninguna parte del sistema.
create or replace function public.edge_asignar_pin(
  p_employee uuid, p_pepper text, p_actor uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_pin text; i int := 0;
begin
  loop
    i := i + 1;
    v_pin := app.generate_pin();
    begin
      perform app.set_employee_pin(p_employee, v_pin, p_pepper, p_actor);
      return jsonb_build_object('ok', true, 'pin', v_pin);
    exception when others then
      -- Colisión con el PIN de otro trabajador: se reintenta con otro.
      if sqlerrm not like '%PIN_EN_USO%' or i > 20 then raise; end if;
    end;
  end loop;
end $$;

revoke all on function public.edge_emparejar(text, text, text)                  from public, anon, authenticated;
revoke all on function public.edge_autenticar_terminal(text, text)              from public, anon, authenticated;
revoke all on function public.edge_verificar_pin(uuid, text, text)              from public, anon, authenticated;
revoke all on function public.edge_registrar(uuid, uuid)                        from public, anon, authenticated;
revoke all on function public.edge_evidencia(uuid, text, int, timestamptz)      from public, anon, authenticated;
revoke all on function public.edge_sin_evidencia(uuid, text)                    from public, anon, authenticated;
revoke all on function public.edge_asignar_pin(uuid, text, uuid)                from public, anon, authenticated;

grant execute on function public.edge_emparejar(text, text, text)               to service_role;
grant execute on function public.edge_autenticar_terminal(text, text)           to service_role;
grant execute on function public.edge_verificar_pin(uuid, text, text)           to service_role;
grant execute on function public.edge_registrar(uuid, uuid)                     to service_role;
grant execute on function public.edge_evidencia(uuid, text, int, timestamptz)   to service_role;
grant execute on function public.edge_sin_evidencia(uuid, text)                 to service_role;
grant execute on function public.edge_asignar_pin(uuid, text, uuid)             to service_role;

-- =====================================================================
do $$
begin
  raise notice '';
  raise notice '  Fase 3 lista en la base de datos.';
  raise notice '  Siguiente paso: publicar la función `credisan` en Edge Functions';
  raise notice '  y guardar el secreto CREDISAN_PIN_PEPPER.';
  raise notice '';
end $$;
