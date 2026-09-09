-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0004 · Marcaciones, evidencia y resumen diario
-- =====================================================================

create type event_type   as enum ('entrada', 'salida_almuerzo', 'regreso_almuerzo', 'salida');
create type punch_status as enum ('anticipado','puntual','tolerancia','retraso','ausencia','incompleto','justificado');
create type punch_origin as enum ('online', 'offline');
create type evidence_status as enum ('pendiente', 'almacenada', 'sin_evidencia', 'purgada');
create type day_status   as enum ('descanso','completo','incompleto','ausente','justificado','en_curso');

-- ── MARCACIONES (INMUTABLES) ─────────────────────────────────────────
-- No existe política de UPDATE ni DELETE para ningún rol, ni siquiera CEO.
-- Corregir es insertar en attendance_corrections, nunca reescribir aquí.
create table attendance_events (
  id                 uuid primary key default gen_random_uuid(),
  employee_id        uuid not null references employees (id) on delete restrict,
  branch_id          uuid not null references branches  (id) on delete restrict,
  terminal_id        uuid not null references terminals (id) on delete restrict,
  work_date          date not null,                 -- fecha local de la sede
  event              event_type not null,
  expected_at        timestamptz not null,          -- según calendario efectivo
  -- Tres relojes distintos, nunca mezclados:
  recorded_at        timestamptz not null,          -- hora que cuenta (servidor si online)
  device_timestamp   timestamptz,                   -- lo que declaró el dispositivo
  server_timestamp   timestamptz not null default now(),
  sync_timestamp     timestamptz,                   -- cuándo llegó (sólo offline)
  clock_drift_sec    integer,                       -- device - server, medido en la última sincronía
  delta_minutes      integer not null,              -- negativo = antes de lo esperado
  status             punch_status not null,
  origin             punch_origin not null default 'online',
  device_seq         bigint,                        -- contador monotónico del terminal (offline)
  evidence_status    evidence_status not null default 'pendiente',
  client_event_id    uuid not null,                 -- idempotencia extremo a extremo
  created_at         timestamptz not null default now(),
  constraint attendance_offline_coherente check (
    (origin = 'online'  and device_seq is null and sync_timestamp is null)
    or (origin = 'offline' and device_seq is not null and device_timestamp is not null)
  )
);
create unique index attendance_client_event_key on attendance_events (client_event_id);
-- Impide la doble marcación accidental del mismo evento en el mismo día.
create unique index attendance_evento_unico     on attendance_events (employee_id, work_date, event);
create index attendance_branch_date_idx  on attendance_events (branch_id, work_date desc);
create index attendance_emp_date_idx     on attendance_events (employee_id, work_date desc);
create index attendance_terminal_seq_idx on attendance_events (terminal_id, device_seq desc) where device_seq is not null;
create index attendance_status_idx       on attendance_events (branch_id, status, work_date desc);

comment on table attendance_events is
  'Registro inmutable. Sin políticas de UPDATE/DELETE. La corrección vive en attendance_corrections.';

-- ── EVIDENCIA FOTOGRÁFICA ────────────────────────────────────────────
create table attendance_evidence (
  id            uuid primary key default gen_random_uuid(),
  event_id      uuid not null unique references attendance_events (id) on delete cascade,
  branch_id     uuid not null references branches (id) on delete restrict,
  storage_path  text not null,
  mime_type     text not null default 'image/jpeg',
  byte_size     integer check (byte_size is null or byte_size > 0),
  captured_at   timestamptz,
  expires_on    date not null,          -- fecha de purga según política de retención
  purged_at     timestamptz,            -- se borra el archivo, nunca la fila
  created_at    timestamptz not null default now()
);
create index evidence_expira_idx on attendance_evidence (expires_on) where purged_at is null;
create index evidence_branch_idx on attendance_evidence (branch_id, created_at desc);
comment on table attendance_evidence is
  'Bucket privado. Al expirar se borra SÓLO el archivo: la marcación, su hora y su clasificación permanecen.';

-- ── CORRECCIONES ADMINISTRATIVAS ─────────────────────────────────────
create table attendance_corrections (
  id            uuid primary key default gen_random_uuid(),
  event_id      uuid references attendance_events (id) on delete restrict,
  employee_id   uuid not null references employees (id) on delete restrict,
  branch_id     uuid not null references branches  (id) on delete restrict,
  work_date     date not null,
  field         text not null check (field in ('recorded_at','status','event','alta_manual','anulacion')),
  old_value     jsonb,
  new_value     jsonb not null,
  reason        text not null check (length(btrim(reason)) >= 10),
  corrected_by  uuid not null references profiles (id),
  created_at    timestamptz not null default now(),
  -- Una corrección sobre un evento existente exige el valor original;
  -- un alta manual (olvido de marcación) no tiene evento previo.
  constraint correccion_coherente check (
    (field = 'alta_manual' and event_id is null and old_value is null)
    or (field <> 'alta_manual' and event_id is not null)
  )
);
create index corrections_event_idx on attendance_corrections (event_id, created_at desc);
create index corrections_emp_idx   on attendance_corrections (employee_id, work_date desc);

-- ── INTENTOS DE PIN ──────────────────────────────────────────────────
-- No se bloquea al trabajador: el terminal es compartido y un bloqueo sería
-- una forma trivial de dejar sin marcar a un compañero. Se frena, se audita
-- y se alerta.
create table pin_attempts (
  id           bigint generated always as identity primary key,
  terminal_id  uuid references terminals (id) on delete set null,
  branch_id    uuid references branches  (id) on delete set null,
  employee_id  uuid references employees (id) on delete set null,  -- null = PIN inexistente
  success      boolean not null,
  reason       text,
  delay_ms     integer,
  created_at   timestamptz not null default now()
);
create index pin_attempts_terminal_idx on pin_attempts (terminal_id, created_at desc);
create index pin_attempts_emp_idx      on pin_attempts (employee_id, created_at desc) where employee_id is not null;
create index pin_attempts_fallos_idx   on pin_attempts (created_at desc) where success = false;

-- ── RESUMEN DIARIO (materializado por trabajo programado) ────────────
-- Una ausencia es la AUSENCIA de una marcación: hay que materializarla para
-- poder consultarla. No se insertan marcaciones falsas para representarla.
create table attendance_daily (
  id                uuid primary key default gen_random_uuid(),
  employee_id       uuid not null references employees (id) on delete cascade,
  branch_id         uuid not null references branches  (id) on delete restrict,
  work_date         date not null,
  is_working_day    boolean not null,
  expected_events   smallint not null default 0,
  registered_events smallint not null default 0,
  expected_minutes  integer  not null default 0,
  worked_minutes    integer  not null default 0,
  late_minutes      integer  not null default 0,
  early_count       smallint not null default 0,
  late_count        smallint not null default 0,
  missing_evidence  smallint not null default 0,
  status            day_status not null,
  justified_by      uuid,   -- FK a incidents: se agrega en la migración 0005
  computed_at       timestamptz not null default now(),
  unique (employee_id, work_date)
);
create index daily_branch_date_idx on attendance_daily (branch_id, work_date desc);
create index daily_status_idx      on attendance_daily (branch_id, status, work_date desc);

-- ── TICKETS DE CONFIRMACIÓN ──────────────────────────────────────────
-- El PIN viaja UNA sola vez. La pantalla de confirmación usa este ticket
-- de un solo uso y vida corta, para no reenviar el PIN al confirmar.
create table punch_tickets (
  id           uuid primary key default gen_random_uuid(),
  employee_id  uuid not null references employees (id) on delete cascade,
  terminal_id  uuid not null references terminals (id) on delete cascade,
  event        event_type not null,
  expected_at  timestamptz not null,
  issued_at    timestamptz not null default now(),
  expires_at   timestamptz not null,
  used_at      timestamptz
);
create index punch_tickets_expira_idx on punch_tickets (expires_at) where used_at is null;
