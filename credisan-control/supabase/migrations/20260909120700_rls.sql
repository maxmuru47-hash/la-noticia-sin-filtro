-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0008 · RBAC + Row Level Security
-- =====================================================================
-- Regla: esconder botones no es seguridad. Todo lo que sigue se aplica
-- dentro de PostgreSQL, aunque alguien use la API con su propio token.
--
-- Matriz efectiva
--   recurso                | ceo        | admin (su sede) | supervisor (su sede)
--   empleados              | RW nacional| RW              | sólo lectura
--   salario                | RW         | RW              | SIN ACCESO
--   marcaciones            | R          | R               | R
--   evidencia (metadatos)  | R          | R               | SIN ACCESO
--   correcciones           | RW         | RW              | SIN ACCESO
--   incidencias            | crear+resolver | crear+resolver | sólo crear
--   cierres semanales      | RW         | RW              | SIN ACCESO
--   auditoría              | R nacional | R su sede       | SIN ACCESO
--   configuración          | RW         | R               | R
--   intentos de PIN        | R          | R               | SIN ACCESO
-- =====================================================================

-- ── Punto de partida: nadie puede nada ───────────────────────────────
revoke all on all tables    in schema public from anon, authenticated;
revoke all on all functions in schema public from public, anon, authenticated;
revoke all on all functions in schema app    from public, anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;

grant usage on schema app to authenticated;   -- necesario para evaluar las políticas

-- Ayudantes de alcance: los usa la RLS, se ejecutan con el rol del usuario.
grant execute on function app.jwt_claim(text)        to authenticated;
grant execute on function app.current_role_name()    to authenticated;
grant execute on function app.current_branch()       to authenticated;
grant execute on function app.is_ceo()               to authenticated;
grant execute on function app.is_admin()             to authenticated;
grant execute on function app.is_supervisor()        to authenticated;
grant execute on function app.can_see_branch(uuid)   to authenticated;
grant execute on function app.setting(text, uuid)    to authenticated;
grant execute on function app.setting_int(text, uuid, int) to authenticated;
grant execute on function app.effective_day(uuid, date)    to authenticated;
grant execute on function app.expected_events(uuid, date)  to authenticated;
grant execute on function app.expected_minutes(uuid, date) to authenticated;

-- El hook de claims lo ejecuta únicamente el servicio de autenticación.
grant execute on function public.custom_access_token_hook(jsonb) to supabase_auth_admin;
grant usage  on schema public to supabase_auth_admin;
grant select on table public.profiles to supabase_auth_admin;

-- ── RLS activa en todo ───────────────────────────────────────────────
alter table branches               enable row level security;
alter table profiles               enable row level security;
alter table terminals              enable row level security;
alter table terminal_pairing_codes enable row level security;
alter table employees              enable row level security;
alter table employee_compensation  enable row level security;
alter table work_schedules         enable row level security;
alter table schedule_days          enable row level security;
alter table schedule_assignments   enable row level security;
alter table schedule_exceptions    enable row level security;
alter table attendance_events      enable row level security;
alter table attendance_evidence    enable row level security;
alter table attendance_corrections enable row level security;
alter table attendance_daily       enable row level security;
alter table pin_attempts           enable row level security;
alter table punch_tickets          enable row level security;
alter table incidents              enable row level security;
alter table incident_kinds         enable row level security;
alter table weekly_closures        enable row level security;
alter table audit_logs             enable row level security;
alter table system_settings        enable row level security;

-- ── SEDES ────────────────────────────────────────────────────────────
grant select on branches to authenticated;
grant insert, update on branches to authenticated;

create policy branches_select on branches for select to authenticated
  using (app.can_see_branch(id));
create policy branches_insert on branches for insert to authenticated
  with check (app.is_ceo());
create policy branches_update on branches for update to authenticated
  using (app.is_ceo()) with check (app.is_ceo());

-- ── PERFILES ─────────────────────────────────────────────────────────
grant select on profiles to authenticated;
grant insert, update on profiles to authenticated;

create policy profiles_select on profiles for select to authenticated
  using (id = auth.uid() or app.is_ceo() or (app.is_admin() and app.can_see_branch(branch_id)));
create policy profiles_insert on profiles for insert to authenticated
  with check (app.is_ceo());
create policy profiles_update on profiles for update to authenticated
  using (app.is_ceo()) with check (app.is_ceo());

-- ── TERMINALES ───────────────────────────────────────────────────────
-- El token y la clave de firma nunca salen por la API, ni para el CEO.
grant select (id, code, branch_id, label, paired_at, paired_by, device_label,
              last_seen_at, last_sync_at, is_active, created_at, updated_at)
  on terminals to authenticated;
grant insert on terminals to authenticated;
grant update (label, is_active) on terminals to authenticated;

create policy terminals_select on terminals for select to authenticated
  using (app.can_see_branch(branch_id) and deleted_at is null);
create policy terminals_insert on terminals for insert to authenticated
  with check (app.is_ceo());
create policy terminals_update on terminals for update to authenticated
  using (app.is_ceo()) with check (app.is_ceo());

grant select (id, terminal_id, code_hint, expires_at, used_at, used_device, revoked_at, created_by, created_at)
  on terminal_pairing_codes to authenticated;

create policy pairing_select on terminal_pairing_codes for select to authenticated
  using (exists (select 1 from terminals t
                  where t.id = terminal_pairing_codes.terminal_id
                    and app.can_see_branch(t.branch_id)));

-- ── EMPLEADOS ────────────────────────────────────────────────────────
-- El PIN no se expone jamás, ni en lectura ni en escritura directa.
grant select (id, branch_id, internal_code, first_name, last_name, national_id,
              photo_path, phone, email, position, hired_on, pin_updated_at,
              is_active, notes, created_at, updated_at)
  on employees to authenticated;
grant insert (branch_id, internal_code, first_name, last_name, national_id,
              photo_path, phone, email, position, hired_on, notes, created_by)
  on employees to authenticated;
grant update (internal_code, first_name, last_name, national_id, photo_path,
              phone, email, position, hired_on, is_active, notes, deleted_at)
  on employees to authenticated;

create policy employees_select on employees for select to authenticated
  using (app.can_see_branch(branch_id) and deleted_at is null);
create policy employees_insert on employees for insert to authenticated
  with check ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));
-- El `with check` impide además mover un trabajador a otra sede.
create policy employees_update on employees for update to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id))
  with check ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));

-- ── SALARIO ──────────────────────────────────────────────────────────
-- El jefe operativo no tiene ninguna política aquí: para él la tabla
-- devuelve cero filas, siempre, consulte como consulte.
grant select, insert, update on employee_compensation to authenticated;

create policy compensation_select on employee_compensation for select to authenticated
  using ((app.is_ceo() or app.is_admin())
         and exists (select 1 from employees e
                      where e.id = employee_compensation.employee_id
                        and app.can_see_branch(e.branch_id)));
create policy compensation_insert on employee_compensation for insert to authenticated
  with check ((app.is_ceo() or app.is_admin())
              and exists (select 1 from employees e
                           where e.id = employee_compensation.employee_id
                             and app.can_see_branch(e.branch_id)));
create policy compensation_update on employee_compensation for update to authenticated
  using ((app.is_ceo() or app.is_admin())
         and exists (select 1 from employees e
                      where e.id = employee_compensation.employee_id
                        and app.can_see_branch(e.branch_id)))
  with check (app.is_ceo() or app.is_admin());

-- ── CALENDARIO ───────────────────────────────────────────────────────
grant select, insert, update on work_schedules       to authenticated;
grant select, insert, update, delete on schedule_days        to authenticated;
grant select, insert, update, delete on schedule_assignments to authenticated;
grant select, insert, update, delete on schedule_exceptions  to authenticated;

create policy schedules_select on work_schedules for select to authenticated
  using (branch_id is null or app.can_see_branch(branch_id));
create policy schedules_write on work_schedules for insert to authenticated
  with check ((app.is_ceo() or app.is_admin())
              and (branch_id is null and app.is_ceo() or app.can_see_branch(branch_id)));
create policy schedules_update on work_schedules for update to authenticated
  using ((app.is_ceo() or app.is_admin()) and (branch_id is null and app.is_ceo() or app.can_see_branch(branch_id)))
  with check (app.is_ceo() or app.is_admin());

create policy sched_days_select on schedule_days for select to authenticated
  using (exists (select 1 from work_schedules w where w.id = schedule_days.schedule_id
                  and (w.branch_id is null or app.can_see_branch(w.branch_id))));
create policy sched_days_write on schedule_days for all to authenticated
  using ((app.is_ceo() or app.is_admin())
         and exists (select 1 from work_schedules w where w.id = schedule_days.schedule_id
                      and (w.branch_id is null and app.is_ceo() or app.can_see_branch(w.branch_id))))
  with check ((app.is_ceo() or app.is_admin())
         and exists (select 1 from work_schedules w where w.id = schedule_days.schedule_id
                      and (w.branch_id is null and app.is_ceo() or app.can_see_branch(w.branch_id))));

create policy sched_assign_select on schedule_assignments for select to authenticated
  using (app.can_see_branch(branch_id)
         or exists (select 1 from employees e where e.id = schedule_assignments.employee_id
                     and app.can_see_branch(e.branch_id)));
create policy sched_assign_write on schedule_assignments for all to authenticated
  using ((app.is_ceo() or app.is_admin())
         and (app.can_see_branch(branch_id)
              or exists (select 1 from employees e where e.id = schedule_assignments.employee_id
                          and app.can_see_branch(e.branch_id))))
  with check ((app.is_ceo() or app.is_admin())
         and (app.can_see_branch(branch_id)
              or exists (select 1 from employees e where e.id = schedule_assignments.employee_id
                          and app.can_see_branch(e.branch_id))));

create policy sched_exc_select on schedule_exceptions for select to authenticated
  using (scope = 'global'
         or app.can_see_branch(branch_id)
         or exists (select 1 from employees e where e.id = schedule_exceptions.employee_id
                     and app.can_see_branch(e.branch_id)));
create policy sched_exc_write on schedule_exceptions for all to authenticated
  using ((app.is_ceo() or app.is_admin())
         and (scope = 'global' and app.is_ceo()
              or app.can_see_branch(branch_id)
              or exists (select 1 from employees e where e.id = schedule_exceptions.employee_id
                          and app.can_see_branch(e.branch_id))))
  with check ((app.is_ceo() or app.is_admin())
         and (scope = 'global' and app.is_ceo()
              or app.can_see_branch(branch_id)
              or exists (select 1 from employees e where e.id = schedule_exceptions.employee_id
                          and app.can_see_branch(e.branch_id))));

-- ── MARCACIONES (SÓLO LECTURA PARA TODOS) ────────────────────────────
-- No hay GRANT de INSERT/UPDATE/DELETE ni políticas de escritura.
-- Ni el CEO puede alterar una marcación desde la API. Sólo las funciones
-- SECURITY DEFINER del motor escriben aquí.
grant select on attendance_events to authenticated;
create policy attendance_select on attendance_events for select to authenticated
  using (app.can_see_branch(branch_id));

grant select on attendance_daily to authenticated;
create policy daily_select on attendance_daily for select to authenticated
  using (app.can_see_branch(branch_id));

-- Metadatos de evidencia: el archivo se sirve aparte, con URL firmada.
grant select on attendance_evidence to authenticated;
create policy evidence_select on attendance_evidence for select to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));

grant select, insert on attendance_corrections to authenticated;
create policy corrections_select on attendance_corrections for select to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));
create policy corrections_insert on attendance_corrections for insert to authenticated
  with check ((app.is_ceo() or app.is_admin())
              and app.can_see_branch(branch_id)
              and corrected_by = auth.uid());

-- ── SEGURIDAD DEL PIN ────────────────────────────────────────────────
grant select on pin_attempts to authenticated;
create policy pin_attempts_select on pin_attempts for select to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));

-- punch_tickets: sin GRANT y sin políticas. Nadie lo ve por la API.

-- ── NOVEDADES ────────────────────────────────────────────────────────
grant select on incident_kinds to authenticated;
create policy kinds_select on incident_kinds for select to authenticated using (true);

grant select on incidents to authenticated;
grant insert (employee_id, branch_id, kind, description, occurred_from, occurred_to,
              work_date, evidence_path, reported_by, related_event) on incidents to authenticated;
grant update (status, reviewed_by, reviewed_at, review_note) on incidents to authenticated;

create policy incidents_select on incidents for select to authenticated
  using (app.can_see_branch(branch_id));
-- El jefe operativo REPORTA: sólo puede crearlas pendientes y a su nombre.
create policy incidents_insert on incidents for insert to authenticated
  with check (app.can_see_branch(branch_id)
              and status = 'pendiente'
              and source = 'usuario'
              and reported_by = auth.uid());
-- Administración RESUELVE. El jefe operativo no aparece aquí.
create policy incidents_review on incidents for update to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id))
  with check ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));

-- ── CIERRE SEMANAL ───────────────────────────────────────────────────
grant select on weekly_closures to authenticated;
grant update (status, note, closed_by, closed_at) on weekly_closures to authenticated;
create policy closures_select on weekly_closures for select to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));
create policy closures_update on weekly_closures for update to authenticated
  using ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id))
  with check ((app.is_ceo() or app.is_admin()) and app.can_see_branch(branch_id));

-- ── AUDITORÍA (sólo lectura, para nadie más que dirección) ───────────
grant select on audit_logs to authenticated;
create policy audit_select on audit_logs for select to authenticated
  using (app.is_ceo() or (app.is_admin() and app.can_see_branch(branch_id)));

-- ── CONFIGURACIÓN ────────────────────────────────────────────────────
grant select on system_settings to authenticated;
grant insert, update on system_settings to authenticated;
create policy settings_select on system_settings for select to authenticated
  using (scope_branch_id is null or app.can_see_branch(scope_branch_id));
create policy settings_insert on system_settings for insert to authenticated
  with check (app.is_ceo());
create policy settings_update on system_settings for update to authenticated
  using (app.is_ceo()) with check (app.is_ceo());

-- ── RPC expuestas al panel ───────────────────────────────────────────
-- Generar el código de emparejamiento: administración de la sede o CEO.
create or replace function public.crear_codigo_emparejamiento(p_terminal uuid)
returns text language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_code text;
begin
  select branch_id into v_branch from terminals where id = p_terminal and deleted_at is null;
  if v_branch is null then raise exception 'TERMINAL_INEXISTENTE'; end if;
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  v_code := app.create_pairing_code(p_terminal, auth.uid());
  return v_code;
end $$;
grant execute on function public.crear_codigo_emparejamiento(uuid) to authenticated;

-- Recalcular el día de un trabajador (tras una corrección o una novedad).
create or replace function public.recalcular_dia(p_employee uuid, p_date date)
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid;
begin
  select branch_id into v_branch from employees where id = p_employee;
  if v_branch is null then raise exception 'EMPLEADO_INEXISTENTE'; end if;
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;
  perform app.recompute_daily(p_employee, p_date);
end $$;
grant execute on function public.recalcular_dia(uuid, date) to authenticated;

-- ── Funciones reservadas al backend (service_role) ───────────────────
grant usage on schema app to service_role;
grant execute on function app.verify_pin(uuid, text, text)                         to service_role;
grant execute on function app.register_punch(uuid, uuid)                           to service_role;
grant execute on function app.register_offline_punch(uuid, uuid, uuid, timestamptz, bigint, int) to service_role;
grant execute on function app.set_employee_pin(uuid, text, text, uuid)             to service_role;
grant execute on function app.generate_pin()                                       to service_role;
grant execute on function app.authenticate_terminal(text, text)                    to service_role;
grant execute on function app.redeem_pairing_code(text, text, text)                to service_role;
grant execute on function app.attach_evidence(uuid, text, int, text, timestamptz)  to service_role;
grant execute on function app.mark_missing_evidence(uuid, text)                    to service_role;
grant execute on function app.recompute_daily(uuid, date)                          to service_role;
grant execute on function app.recompute_daily_branch(uuid, date)                   to service_role;
grant execute on function app.compute_weekly_closure(uuid, date)                   to service_role;

-- anon no toca absolutamente nada.
revoke all on all tables    in schema public from anon;
revoke all on all functions in schema public from anon;
revoke usage on schema app  from anon;
