-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0010 · Trabajos programados
-- =====================================================================

-- Recalcula el día en curso de todas las sedes activas.
create or replace function app.job_recompute_today()
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare b record; n int := 0;
begin
  for b in select id, timezone from branches where is_active and deleted_at is null loop
    n := n + app.recompute_daily_branch(b.id, (now() at time zone b.timezone)::date);
  end loop;
  return n;
end $$;

-- Cierra el día anterior: es cuando las ausencias y los registros
-- incompletos quedan firmes.
create or replace function app.job_close_yesterday()
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare b record; n int := 0;
begin
  for b in select id, timezone from branches where is_active and deleted_at is null loop
    n := n + app.recompute_daily_branch(b.id, (now() at time zone b.timezone)::date - 1);
  end loop;
  return n;
end $$;

-- Cierre semanal de la semana pasada (se ejecuta los lunes).
create or replace function app.job_weekly_closures()
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare e record; v_week date; n int := 0;
begin
  v_week := (date_trunc('week', current_date) - interval '7 days')::date;
  for e in select id from employees where is_active and deleted_at is null loop
    perform app.compute_weekly_closure(e.id, v_week);
    n := n + 1;
  end loop;
  return n;
end $$;

-- Higiene: tickets y códigos de emparejamiento vencidos.
create or replace function app.job_cleanup()
returns void language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  delete from punch_tickets where expires_at < now() - interval '1 day';
  update terminal_pairing_codes
     set revoked_at = now()
   where used_at is null and revoked_at is null and expires_at < now();
  delete from pin_attempts where created_at < now() - interval '180 days';
end $$;

-- Retención de evidencia. PostgreSQL no borra archivos de Storage: entrega
-- la lista y la Edge Function `purgar-evidencia` borra y confirma.
-- La marcación, su hora, su clasificación y su auditoría permanecen.
create or replace function app.evidence_due_for_purge(p_limit int default 500)
returns table (id uuid, storage_path text) language sql stable security definer
set search_path = app, public, pg_temp as $$
  select e.id, e.storage_path
    from attendance_evidence e
   where e.purged_at is null and e.expires_on <= current_date
   order by e.expires_on
   limit p_limit
$$;

create or replace function app.confirm_evidence_purged(p_ids uuid[])
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare n int;
begin
  update attendance_evidence set purged_at = now() where id = any (p_ids) and purged_at is null;
  get diagnostics n = row_count;
  update attendance_events set evidence_status = 'purgada'
   where id in (select event_id from attendance_evidence where id = any (p_ids));
  insert into audit_logs (actor_kind, action, entity, metadata)
  values ('system', 'evidencia.purgada', 'attendance_evidence',
          jsonb_build_object('cantidad', n, 'fecha', current_date));
  return n;
end $$;

grant execute on function app.job_recompute_today()          to service_role;
grant execute on function app.job_close_yesterday()          to service_role;
grant execute on function app.job_weekly_closures()          to service_role;
grant execute on function app.job_cleanup()                  to service_role;
grant execute on function app.evidence_due_for_purge(int)    to service_role;
grant execute on function app.confirm_evidence_purged(uuid[]) to service_role;

-- Programación (requiere la extensión pg_cron habilitada en el proyecto).
-- Las horas están en UTC; Venezuela es UTC-4 todo el año.
do $$
begin
  if exists (select 1 from pg_available_extensions where name = 'pg_cron') then
    execute 'create extension if not exists pg_cron';

    perform cron.schedule('credisan_recalculo_dia',   '*/15 * * * *', 'select app.job_recompute_today()');
    perform cron.schedule('credisan_cierre_dia',      '30 4 * * *',   'select app.job_close_yesterday()');   -- 00:30 Caracas
    perform cron.schedule('credisan_cierre_semanal',  '0 5 * * 1',    'select app.job_weekly_closures()');   -- lunes 01:00 Caracas
    perform cron.schedule('credisan_higiene',         '0 * * * *',    'select app.job_cleanup()');
  else
    raise notice 'pg_cron no disponible: programe los trabajos manualmente (ver docs/SUPABASE_SETUP.md).';
  end if;
end $$;
