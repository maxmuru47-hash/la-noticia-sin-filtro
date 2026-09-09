-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0003 · Calendario laboral configurable
-- =====================================================================
-- Ninguna jornada está en el código. Todo se resuelve leyendo estas tablas.
-- Prioridad al resolver un día:
--   excepción de empleado > excepción de sede > excepción global
--   > asignación de horario de empleado > asignación de horario de sede
-- =====================================================================

create type exception_kind as enum (
  'feriado', 'vacaciones', 'permiso', 'reposo',
  'jornada_especial', 'dia_extraordinario', 'suspension'
);

-- ── HORARIOS ─────────────────────────────────────────────────────────
create table work_schedules (
  id          uuid primary key default gen_random_uuid(),
  branch_id   uuid references branches (id) on delete restrict,  -- null = plantilla corporativa
  name        text not null,
  description text,
  is_active   boolean not null default true,
  deleted_at  timestamptz,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  created_by  uuid references profiles (id)
);
create index work_schedules_branch_idx on work_schedules (branch_id) where deleted_at is null;

-- Un renglón por día de la semana. 0 = domingo (coincide con extract(dow)).
create table schedule_days (
  id              uuid primary key default gen_random_uuid(),
  schedule_id     uuid not null references work_schedules (id) on delete cascade,
  weekday         smallint not null check (weekday between 0 and 6),
  is_working      boolean not null default false,   -- false = descanso
  is_continuous   boolean not null default false,   -- true  = jornada corrida (sin almuerzo)
  entry_time      time,
  lunch_out_time  time,
  lunch_in_time   time,
  exit_time       time,
  unique (schedule_id, weekday),
  constraint schedule_day_coherente check (
    -- Día de descanso: sin horas.
    (is_working = false
       and entry_time is null and lunch_out_time is null
       and lunch_in_time is null and exit_time is null)
    -- Jornada corrida: entrada y salida.
    or (is_working and is_continuous
       and entry_time is not null and exit_time is not null
       and lunch_out_time is null and lunch_in_time is null
       and exit_time > entry_time)
    -- Jornada partida: las cuatro marcaciones, en orden estricto.
    or (is_working and not is_continuous
       and entry_time is not null and lunch_out_time is not null
       and lunch_in_time is not null and exit_time is not null
       and entry_time < lunch_out_time
       and lunch_out_time < lunch_in_time
       and lunch_in_time < exit_time)
  )
);
comment on table schedule_days is
  'Un sábado inactivo es is_working=false: NO genera ausencia. Activarlo es configuración, no código.';

-- ── ASIGNACIÓN DE HORARIOS (con vigencia) ────────────────────────────
create table schedule_assignments (
  id          uuid primary key default gen_random_uuid(),
  schedule_id uuid not null references work_schedules (id) on delete restrict,
  branch_id   uuid references branches  (id) on delete cascade,
  employee_id uuid references employees (id) on delete cascade,
  valid_from  date not null default current_date,
  valid_to    date,
  note        text,
  created_at  timestamptz not null default now(),
  created_by  uuid references profiles (id),
  period      daterange generated always as (daterange(valid_from, valid_to, '[]')) stored,
  constraint assignment_un_solo_alcance check ((branch_id is not null) <> (employee_id is not null)),
  constraint assignment_vigencia        check (valid_to is null or valid_to >= valid_from),
  -- Un empleado (o una sede) no puede tener dos horarios vigentes el mismo día.
  constraint assignment_sin_solape_empleado
    exclude using gist (employee_id with =, period with &&) where (employee_id is not null),
  constraint assignment_sin_solape_sede
    exclude using gist (branch_id with =, period with &&) where (branch_id is not null)
);
create index schedule_assignments_emp_idx    on schedule_assignments (employee_id);
create index schedule_assignments_branch_idx on schedule_assignments (branch_id);

-- ── EXCEPCIONES ──────────────────────────────────────────────────────
create table schedule_exceptions (
  id             uuid primary key default gen_random_uuid(),
  scope          text not null check (scope in ('global', 'branch', 'employee')),
  branch_id      uuid references branches  (id) on delete cascade,
  employee_id    uuid references employees (id) on delete cascade,
  kind           exception_kind not null,
  date_from      date not null,
  date_to        date not null,
  is_working     boolean not null default false,  -- feriado=false, jornada_especial=true
  is_continuous  boolean not null default false,
  entry_time     time,
  lunch_out_time time,
  lunch_in_time  time,
  exit_time      time,
  description    text not null,
  created_at     timestamptz not null default now(),
  created_by     uuid references profiles (id),
  constraint exception_rango  check (date_to >= date_from),
  constraint exception_alcance check (
    (scope = 'global'   and branch_id is null     and employee_id is null) or
    (scope = 'branch'   and branch_id is not null and employee_id is null) or
    (scope = 'employee' and employee_id is not null)
  ),
  constraint exception_horas check (
    (is_working = false)
    or (is_continuous     and entry_time is not null and exit_time is not null and exit_time > entry_time)
    or (not is_continuous and entry_time is not null and lunch_out_time is not null
        and lunch_in_time is not null and exit_time is not null
        and entry_time < lunch_out_time and lunch_out_time < lunch_in_time and lunch_in_time < exit_time)
  )
);
create index exceptions_lookup_idx on schedule_exceptions (date_from, date_to);
create index exceptions_branch_idx on schedule_exceptions (branch_id) where branch_id is not null;
create index exceptions_emp_idx    on schedule_exceptions (employee_id) where employee_id is not null;

create trigger work_schedules_touch before update on work_schedules
  for each row execute function app.tg_touch_updated_at();
