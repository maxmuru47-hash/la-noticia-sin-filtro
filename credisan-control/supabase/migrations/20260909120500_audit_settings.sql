-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0006 · Auditoría y configuración
-- =====================================================================

-- ── AUDITORÍA (append-only) ──────────────────────────────────────────
-- Sin políticas de UPDATE ni DELETE: nadie reescribe la historia, tampoco el CEO.
create table audit_logs (
  id          bigint generated always as identity primary key,
  actor_id    uuid references profiles (id) on delete set null,
  actor_kind  text not null default 'user' check (actor_kind in ('user','terminal','system')),
  action      text not null,
  entity      text not null,
  entity_id   uuid,
  branch_id   uuid references branches (id) on delete set null,
  before      jsonb,
  after       jsonb,
  metadata    jsonb,
  created_at  timestamptz not null default now()
);
create index audit_entity_idx on audit_logs (entity, entity_id, created_at desc);
create index audit_branch_idx on audit_logs (branch_id, created_at desc);
create index audit_actor_idx  on audit_logs (actor_id, created_at desc);
create index audit_action_idx on audit_logs (action, created_at desc);

-- ── CONFIGURACIÓN ────────────────────────────────────────────────────
-- Ninguna regla de negocio vive en el código. Todo se lee de aquí.
-- Una clave con scope_branch_id anula a la misma clave global para esa sede.
create table system_settings (
  id              uuid primary key default gen_random_uuid(),
  key             text not null,
  value           jsonb not null,
  scope_branch_id uuid references branches (id) on delete cascade,
  description     text,
  updated_by      uuid references profiles (id),
  updated_at      timestamptz not null default now(),
  created_at      timestamptz not null default now()
);
create unique index settings_global_key on system_settings (key) where scope_branch_id is null;
create unique index settings_branch_key on system_settings (key, scope_branch_id) where scope_branch_id is not null;

create trigger settings_touch before update on system_settings
  for each row execute function app.tg_touch_updated_at();

-- ── DISPARADOR GENÉRICO DE AUDITORÍA ─────────────────────────────────
-- Se dispara dentro de la transacción: si la auditoría falla, el cambio falla.
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

  -- Nunca se copian secretos al registro de auditoría, ni columnas de
  -- puro latido (última conexión, contadores) que sólo generarían ruido.
  v_before := v_before - 'pin_hash' - 'pin_lookup' - 'token_hash' - 'signing_key' - 'code_hash'
                       - 'updated_at' - 'last_seen_at' - 'last_sync_at' - 'last_seq';
  v_after  := v_after  - 'pin_hash' - 'pin_lookup' - 'token_hash' - 'signing_key' - 'code_hash'
                       - 'updated_at' - 'last_seen_at' - 'last_sync_at' - 'last_seq';

  -- Un UPDATE que, quitado lo anterior, no cambió nada, no se audita.
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

create trigger audit_branches     after insert or update or delete on branches              for each row execute function app.tg_audit();
create trigger audit_profiles     after insert or update or delete on profiles              for each row execute function app.tg_audit();
create trigger audit_terminals    after insert or update or delete on terminals             for each row execute function app.tg_audit();
create trigger audit_employees    after insert or update or delete on employees             for each row execute function app.tg_audit();
create trigger audit_compensation after insert or update or delete on employee_compensation for each row execute function app.tg_audit();
create trigger audit_schedules    after insert or update or delete on work_schedules        for each row execute function app.tg_audit();
create trigger audit_sched_days   after insert or update or delete on schedule_days         for each row execute function app.tg_audit();
create trigger audit_sched_assign after insert or update or delete on schedule_assignments  for each row execute function app.tg_audit();
create trigger audit_sched_exc    after insert or update or delete on schedule_exceptions   for each row execute function app.tg_audit();
create trigger audit_incidents    after insert or update or delete on incidents             for each row execute function app.tg_audit();
create trigger audit_closures     after insert or update or delete on weekly_closures       for each row execute function app.tg_audit();
create trigger audit_settings     after insert or update or delete on system_settings       for each row execute function app.tg_audit();
create trigger audit_corrections  after insert                     on attendance_corrections for each row execute function app.tg_audit();
create trigger audit_pairing      after insert or update           on terminal_pairing_codes for each row execute function app.tg_audit();
