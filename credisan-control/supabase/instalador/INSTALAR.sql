-- =====================================================================
--
--   CREDISAN CONTROL MULTISEDE v1.0 · INSTALADOR COMPLETO
--   "Pensamos en Ti"
--
-- =====================================================================
--
--   CÓMO USARLO
--   -----------
--   1. Entre a su proyecto en supabase.com
--   2. Menú izquierdo → SQL Editor → New query
--   3. Pegue TODO este archivo
--   4. Pulse RUN (o Ctrl+Enter)
--   5. Espere el mensaje "Success". Tarda unos 10 segundos.
--
--   Eso es todo. Crea 21 tablas, 46 políticas de seguridad, las 3 sedes,
--   los 3 terminales, los horarios y toda la configuración.
--
--   IMPORTANTE: está pensado para un proyecto RECIÉN CREADO y vacío.
--   Si detecta que ya está instalado, se detiene y avisa, en lugar de
--   dejar la base a medias.
--
-- =====================================================================

-- ── ¿Ya estaba instalado? ────────────────────────────────────────────
do $$
begin
  if to_regclass('public.branches') is not null then
    raise exception
      'CREDISAN ya está instalado en este proyecto. No hace falta ejecutar de nuevo. Si quiere empezar de cero, cree un proyecto nuevo en Supabase.';
  end if;
end $$;



-- ####################################################################
-- ##  20260909120000_extensions.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0001 · Extensiones y esquema interno
-- =====================================================================
-- El esquema `app` guarda toda la lógica interna (funciones de seguridad,
-- motor de horarios, clasificación). No se expone vía PostgREST.
-- El esquema `public` guarda únicamente tablas y RPC destinadas al cliente.
-- =====================================================================

create schema if not exists app;
create schema if not exists extensions;

create extension if not exists pgcrypto  with schema extensions;  -- crypt/gen_salt/hmac/digest
create extension if not exists btree_gist with schema extensions; -- exclusiones sin solapamiento

comment on schema app is
  'Lógica interna de CrediSan Control. No expuesta por la API REST.';

-- `app` nunca se expone: sólo las funciones SECURITY DEFINER que lo usan.
revoke all on schema app from public;

-- ####################################################################
-- ##  20260909120100_core.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0002 · Núcleo: sedes, usuarios, terminales,
--                                      empleados y compensación
-- =====================================================================

-- ── Tipos ────────────────────────────────────────────────────────────
create type app_role as enum ('ceo', 'admin', 'supervisor');
comment on type app_role is
  'ceo = acceso nacional · admin = administradora de una sede · supervisor = jefe operativo de una sede';

-- ── SEDES ────────────────────────────────────────────────────────────
create table branches (
  id          uuid primary key default gen_random_uuid(),
  code        text not null,
  name        text not null,
  timezone    text not null default 'America/Caracas',
  address     text,
  is_active   boolean not null default true,
  deleted_at  timestamptz,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint branches_code_format check (code ~ '^[A-Z]{2,6}$')
);
create unique index branches_code_key on branches (code) where deleted_at is null;
comment on table branches is 'Sedes de CrediSan. Se agregan sin tocar código.';

-- ── USUARIOS ADMINISTRATIVOS ─────────────────────────────────────────
-- Un perfil por usuario de Supabase Auth. Los TRABAJADORES no tienen perfil:
-- no acceden al panel, sólo al terminal mediante PIN.
create table profiles (
  id          uuid primary key references auth.users (id) on delete cascade,
  full_name   text not null,
  role        app_role not null,
  branch_id   uuid references branches (id) on delete restrict,
  phone       text,
  is_active   boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  -- El CEO es nacional y no puede quedar atado a una sede;
  -- admin y supervisor SIEMPRE pertenecen a exactamente una sede.
  constraint profiles_scope_coherente check (
    (role  = 'ceo' and branch_id is null) or
    (role <> 'ceo' and branch_id is not null)
  )
);
create index profiles_branch_idx on profiles (branch_id) where is_active;

-- ── TERMINALES ───────────────────────────────────────────────────────
create table terminals (
  id             uuid primary key default gen_random_uuid(),
  code           text not null unique,
  branch_id      uuid not null references branches (id) on delete restrict,
  label          text,
  -- Credenciales entregadas al dispositivo durante el emparejamiento.
  -- token_hash: bcrypt del device_token (el token en claro jamás se guarda).
  -- signing_key: secreto compartido para firmar lotes offline (HMAC-SHA256).
  token_hash     text,
  signing_key    bytea,
  paired_at      timestamptz,
  paired_by      uuid references profiles (id),
  device_label   text,
  last_seq       bigint not null default 0,
  last_seen_at   timestamptz,
  last_sync_at   timestamptz,
  is_active      boolean not null default true,
  deleted_at     timestamptz,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  constraint terminals_code_format check (code ~ '^[A-Z]{2,6}-[0-9]{2}$'),
  constraint terminals_pairing_coherente check (
    (token_hash is null and signing_key is null and paired_at is null) or
    (token_hash is not null and signing_key is not null and paired_at is not null)
  )
);
create index terminals_branch_idx on terminals (branch_id) where deleted_at is null;
comment on column terminals.branch_id is
  'La sede del terminal es la fuente de verdad de la sede de cada marcación. Inmutable (trigger).';

-- La sede de un terminal NO puede cambiar: cambiarla reescribiría el
-- significado histórico de todas sus marcaciones.
create or replace function app.tg_terminal_branch_inmutable()
returns trigger language plpgsql as $$
begin
  if new.branch_id is distinct from old.branch_id then
    raise exception 'La sede de un terminal es inmutable (terminal %). Cree un terminal nuevo.', old.code
      using errcode = 'check_violation';
  end if;
  return new;
end $$;

create trigger terminals_branch_inmutable
  before update on terminals
  for each row execute function app.tg_terminal_branch_inmutable();

-- ── CÓDIGOS DE EMPAREJAMIENTO ────────────────────────────────────────
create table terminal_pairing_codes (
  id           uuid primary key default gen_random_uuid(),
  terminal_id  uuid not null references terminals (id) on delete cascade,
  code_hash    bytea not null,          -- sha256(code || terminal_code)
  code_hint    text  not null,          -- primeros 3 caracteres, sólo para la UI
  expires_at   timestamptz not null,
  used_at      timestamptz,
  used_device  text,
  revoked_at   timestamptz,
  created_by   uuid references profiles (id),
  created_at   timestamptz not null default now(),
  constraint pairing_ventana check (expires_at > created_at)
);
create unique index pairing_code_hash_key on terminal_pairing_codes (code_hash);
create index pairing_terminal_idx on terminal_pairing_codes (terminal_id, created_at desc);
comment on table terminal_pairing_codes is
  'Código de 8 caracteres, un solo uso, 10 minutos de vigencia. Ver app.create_pairing_code().';

-- ── EMPLEADOS ────────────────────────────────────────────────────────
create table employees (
  id             uuid primary key default gen_random_uuid(),
  branch_id      uuid not null references branches (id) on delete restrict,
  internal_code  text not null,
  first_name     text not null,
  last_name      text not null,
  national_id    text not null,
  photo_path     text,
  phone          text,
  email          text,
  position       text not null,
  hired_on       date not null,
  -- PIN: nunca en claro. bcrypt para verificar, HMAC para localizar.
  -- El pepper vive fuera de la base de datos (env de la Edge Function).
  pin_hash       text,
  pin_lookup     bytea,
  pin_updated_at timestamptz,
  pin_updated_by uuid references profiles (id),
  is_active      boolean not null default true,
  deleted_at     timestamptz,
  notes          text,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  created_by     uuid references profiles (id),
  constraint employees_pin_coherente check (
    (pin_hash is null and pin_lookup is null) or
    (pin_hash is not null and pin_lookup is not null)
  ),
  constraint employees_email_format check (email is null or email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$')
);
create unique index employees_internal_code_key on employees (branch_id, internal_code) where deleted_at is null;
create unique index employees_national_id_key   on employees (national_id)             where deleted_at is null;
-- Índice ciego: garantiza que dos trabajadores NUNCA compartan PIN y
-- permite localizar al trabajador en O(1) sin poder revertir el PIN.
create unique index employees_pin_lookup_key    on employees (pin_lookup)              where deleted_at is null and pin_lookup is not null;
create index employees_branch_active_idx on employees (branch_id) where is_active and deleted_at is null;

comment on column employees.pin_lookup is
  'HMAC-SHA256(pin, pepper). El pepper NO está en la base de datos. Sirve para búsqueda y unicidad, no para autenticar.';
comment on column employees.pin_hash is
  'bcrypt(pin || pepper). Única fuente de verdad para autenticar.';

-- ── COMPENSACIÓN (AISLADA) ───────────────────────────────────────────
-- PostgreSQL no tiene RLS por columna. La única forma real de que el jefe
-- operativo no vea el salario es que el salario viva en otra tabla.
create table employee_compensation (
  id             uuid primary key default gen_random_uuid(),
  employee_id    uuid not null references employees (id) on delete restrict,
  weekly_base    numeric(14,2) not null check (weekly_base >= 0),
  currency       text not null default 'USD' check (currency ~ '^[A-Z]{3}$'),
  effective_from date not null,
  effective_to   date,
  note           text,
  created_at     timestamptz not null default now(),
  created_by     uuid references profiles (id),
  constraint compensation_vigencia check (effective_to is null or effective_to >= effective_from)
);
create index compensation_employee_idx on employee_compensation (employee_id, effective_from desc);
comment on table employee_compensation is
  'Salario base semanal. Sólo CEO y administradora de la sede. Referencia; el sistema nunca descuenta.';

-- ── updated_at automático ────────────────────────────────────────────
create or replace function app.tg_touch_updated_at()
returns trigger language plpgsql as $$
begin
  new.updated_at := now();
  return new;
end $$;

create trigger branches_touch  before update on branches  for each row execute function app.tg_touch_updated_at();
create trigger profiles_touch  before update on profiles  for each row execute function app.tg_touch_updated_at();
create trigger terminals_touch before update on terminals for each row execute function app.tg_touch_updated_at();
create trigger employees_touch before update on employees for each row execute function app.tg_touch_updated_at();

-- ####################################################################
-- ##  20260909120200_schedules.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909120300_attendance.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909120400_incidents_closures.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0005 · Novedades/incidencias y cierre semanal
-- =====================================================================

create type incident_status as enum ('pendiente', 'aprobada', 'rechazada');
create type incident_source as enum ('usuario', 'sistema');
create type closure_status  as enum ('pendiente', 'aprobado', 'justificado', 'con_observacion');

-- Los tipos de novedad son datos, no código: se agregan sin desplegar.
create table incident_kinds (
  code        text primary key,
  label       text not null,
  is_active   boolean not null default true,
  sort_order  smallint not null default 100,
  system_only boolean not null default false   -- generada por el sistema, no ofrecida en el formulario
);

create table incidents (
  id             uuid primary key default gen_random_uuid(),
  employee_id    uuid not null references employees (id) on delete restrict,
  branch_id      uuid not null references branches  (id) on delete restrict,
  kind           text not null references incident_kinds (code),
  description    text not null check (length(btrim(description)) >= 5),
  occurred_from  timestamptz not null,
  occurred_to    timestamptz,
  work_date      date not null,
  evidence_path  text,
  source         incident_source not null default 'usuario',
  status         incident_status not null default 'pendiente',
  reported_by    uuid references profiles (id),      -- null cuando source='sistema'
  reviewed_by    uuid references profiles (id),
  reviewed_at    timestamptz,
  review_note    text,
  related_event  uuid references attendance_events (id) on delete set null,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  constraint incident_rango check (occurred_to is null or occurred_to >= occurred_from),
  constraint incident_origen check (
    (source = 'usuario' and reported_by is not null) or
    (source = 'sistema' and reported_by is null)
  ),
  -- Una novedad revisada exige quién y cuándo. Una pendiente, ninguno de los dos.
  constraint incident_revision check (
    (status =  'pendiente' and reviewed_by is null and reviewed_at is null) or
    (status <> 'pendiente' and reviewed_by is not null and reviewed_at is not null)
  ),
  -- Nadie aprueba su propia novedad.
  constraint incident_no_autoaprobacion check (reviewed_by is null or reviewed_by is distinct from reported_by)
);
create index incidents_branch_idx   on incidents (branch_id, work_date desc);
create index incidents_emp_idx      on incidents (employee_id, work_date desc);
create index incidents_pendientes_idx on incidents (branch_id, created_at desc) where status = 'pendiente';

create trigger incidents_touch before update on incidents
  for each row execute function app.tg_touch_updated_at();

-- FK diferida desde attendance_daily (ver migración 0004).
alter table attendance_daily
  add constraint attendance_daily_justified_fk
  foreign key (justified_by) references incidents (id) on delete set null;

-- ── CIERRE SEMANAL ───────────────────────────────────────────────────
-- El sistema MIDE, CALCULA e INFORMA. No existe ninguna columna de descuento:
-- la decisión económica es humana y vive fuera de este software.
create table weekly_closures (
  id                uuid primary key default gen_random_uuid(),
  employee_id       uuid not null references employees (id) on delete restrict,
  branch_id         uuid not null references branches  (id) on delete restrict,
  week_start        date not null,     -- lunes
  week_end          date not null,     -- domingo
  expected_minutes  integer not null default 0,
  worked_minutes    integer not null default 0,
  attendance_pct    numeric(5,2) not null default 0 check (attendance_pct  between 0 and 100),
  punctuality_pct   numeric(5,2) not null default 0 check (punctuality_pct between 0 and 100),
  early_count       smallint not null default 0,
  late_count        smallint not null default 0,
  late_minutes      integer  not null default 0,
  absence_count     smallint not null default 0,
  incomplete_count  smallint not null default 0,
  incident_count    smallint not null default 0,
  no_evidence_count smallint not null default 0,
  status            closure_status not null default 'pendiente',
  note              text,
  computed_at       timestamptz not null default now(),
  closed_by         uuid references profiles (id),
  closed_at         timestamptz,
  unique (employee_id, week_start),
  constraint closure_semana check (week_end = week_start + 6),
  constraint closure_lunes  check (extract(isodow from week_start) = 1),
  constraint closure_cierre check (
    (status =  'pendiente' and closed_by is null and closed_at is null) or
    (status <> 'pendiente' and closed_by is not null and closed_at is not null)
  )
);
create index closures_branch_week_idx on weekly_closures (branch_id, week_start desc);
create index closures_emp_week_idx    on weekly_closures (employee_id, week_start desc);

insert into incident_kinds (code, label, sort_order, system_only) values
  ('permiso',            'Permiso',                      10, false),
  ('reposo',             'Reposo médico',                20, false),
  ('comision',           'Comisión laboral',             30, false),
  ('olvido_marcacion',   'Olvido de marcación',          40, false),
  ('falla_tecnica',      'Falla eléctrica / internet',   50, false),
  ('salida_autorizada',  'Salida autorizada',            60, false),
  ('emergencia',         'Emergencia',                   70, false),
  ('otra',               'Otra',                         80, false),
  ('sin_evidencia',      'Marcación sin evidencia fotográfica', 90, true),
  ('marcacion_foranea',  'Intento de marcación en sede ajena',  95, true),
  ('desfase_reloj',      'Marcación offline con reloj desviado', 96, true);

-- ####################################################################
-- ##  20260909120500_audit_settings.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909120600_functions.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909120700_rls.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909120800_storage.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0009 · Storage privado
-- =====================================================================
-- Tres depósitos, los tres PRIVADOS. Ninguno sirve archivos por URL pública.
--   evidencia  · foto de cada marcación   · sólo backend + URL firmada 60 s
--   empleados  · foto de ficha            · lectura por sede
--   novedades  · adjuntos de incidencias  · lectura por sede
-- La sede va en el primer segmento de la ruta: permite validar el alcance
-- sin consultar otras tablas.
-- =====================================================================

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values
  ('evidencia', 'evidencia', false, 2097152,  array['image/jpeg','image/webp']),
  ('empleados', 'empleados', false, 5242880,  array['image/jpeg','image/webp','image/png']),
  ('novedades', 'novedades', false, 10485760, array['image/jpeg','image/webp','image/png','application/pdf'])
on conflict (id) do nothing;

-- Una ruta malformada no debe reventar una política.
create or replace function app.uuid_or_null(p text)
returns uuid language plpgsql immutable as $$
begin
  return p::uuid;
exception when others then
  return null;
end $$;
grant execute on function app.uuid_or_null(text) to authenticated;

create or replace function app.path_branch(p_name text)
returns uuid language sql immutable
set search_path = app, storage, public, pg_temp as $$
  select app.uuid_or_null((storage.foldername(p_name))[1])
$$;
grant execute on function app.path_branch(text) to authenticated;

-- ── evidencia: sin políticas para `authenticated` ────────────────────
-- Ni el CEO lee este depósito directamente. La lectura pasa siempre por la
-- Edge Function `evidencia-url`, que valida el rol, comprueba la sede y
-- emite una URL firmada de 60 segundos, dejando registro en audit_logs.

-- ── empleados ────────────────────────────────────────────────────────
create policy empleados_leer on storage.objects for select to authenticated
  using (bucket_id = 'empleados' and app.can_see_branch(app.path_branch(name)));

create policy empleados_subir on storage.objects for insert to authenticated
  with check (bucket_id = 'empleados'
              and (app.is_ceo() or app.is_admin())
              and app.can_see_branch(app.path_branch(name)));

create policy empleados_reemplazar on storage.objects for update to authenticated
  using (bucket_id = 'empleados'
         and (app.is_ceo() or app.is_admin())
         and app.can_see_branch(app.path_branch(name)))
  with check (bucket_id = 'empleados' and app.can_see_branch(app.path_branch(name)));

-- ── novedades ────────────────────────────────────────────────────────
create policy novedades_leer on storage.objects for select to authenticated
  using (bucket_id = 'novedades' and app.can_see_branch(app.path_branch(name)));

create policy novedades_subir on storage.objects for insert to authenticated
  with check (bucket_id = 'novedades' and app.can_see_branch(app.path_branch(name)));

-- ####################################################################
-- ##  20260909120900_jobs.sql
-- ####################################################################

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

-- ####################################################################
-- ##  20260909121000_bootstrap.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0011 · Arranque del primer usuario
-- =====================================================================
-- Sin esto habría que crear el perfil del CEO a mano con SQL. Con esto,
-- la primera persona que entre al panel se convierte en CEO — y sólo la
-- primera: en cuanto existe un perfil, la función deja de conceder nada.
-- =====================================================================

create or replace function public.reclamar_ceo(p_nombre text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid := auth.uid(); v_total int; v_perfil profiles;
begin
  if v_uid is null then
    return jsonb_build_object('ok', false, 'reason', 'SIN_SESION');
  end if;

  -- ¿Ya tiene perfil? Se le devuelve el suyo, sin tocar nada.
  select * into v_perfil from profiles where id = v_uid;
  if found then
    return jsonb_build_object('ok', true, 'reason', 'YA_TENIA_PERFIL',
                              'role', v_perfil.role, 'branch_id', v_perfil.branch_id);
  end if;

  -- La puerta se cierra en cuanto existe el primer perfil.
  select count(*) into v_total from profiles;
  if v_total > 0 then
    return jsonb_build_object('ok', false, 'reason', 'YA_HAY_USUARIOS');
  end if;

  insert into profiles (id, full_name, role, branch_id)
  values (v_uid, coalesce(nullif(btrim(p_nombre), ''), 'Dirección General'), 'ceo', null);

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, metadata)
  values (v_uid, 'user', 'profile.ceo_inicial', 'profiles', v_uid,
          jsonb_build_object('nombre', p_nombre));

  return jsonb_build_object('ok', true, 'reason', 'CEO_CREADO', 'role', 'ceo');
end $$;

grant execute on function public.reclamar_ceo(text) to authenticated;

-- Perfil de la sesión actual, en una sola llamada: rol, sede y nombre.
-- El panel lo usa al arrancar en lugar de armar dos consultas.
create or replace function public.mi_perfil()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid := auth.uid(); r record;
begin
  if v_uid is null then return jsonb_build_object('ok', false, 'reason', 'SIN_SESION'); end if;

  select p.full_name, p.role, p.branch_id, p.is_active, b.name as branch_name, b.code as branch_code
    into r
    from profiles p left join branches b on b.id = p.branch_id
   where p.id = v_uid;

  if not found then return jsonb_build_object('ok', false, 'reason', 'SIN_PERFIL'); end if;
  if not r.is_active then return jsonb_build_object('ok', false, 'reason', 'INACTIVO'); end if;

  return jsonb_build_object('ok', true, 'nombre', r.full_name, 'rol', r.role,
                            'branch_id', r.branch_id, 'sede', r.branch_name, 'sede_code', r.branch_code);
end $$;

grant execute on function public.mi_perfil() to authenticated;

-- ####################################################################
-- ##  20260909121100_fase2.sql
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0012 · Operaciones del panel (Fase 2)
-- =====================================================================
-- Tres operaciones que, hechas a mano, serían varias inserciones
-- coordinadas y fáciles de dejar a medias. Aquí van completas o no van.
-- =====================================================================

-- ── Crear una sede ───────────────────────────────────────────────────
-- Una sede sin horario no espera nada de nadie, y una sede sin terminal
-- no puede registrar marcaciones. Así que crear una sede es crear las
-- tres cosas de una vez.
create or replace function public.crear_sede(
  p_code text, p_name text, p_terminal text default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid; v_sched uuid; v_term text; d int;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;

  p_code := upper(btrim(p_code));
  p_name := btrim(p_name);
  if p_code !~ '^[A-Z]{2,6}$' then
    raise exception 'CODIGO_INVALIDO' using hint = 'De 2 a 6 letras, sin números ni espacios.';
  end if;
  if length(p_name) < 3 then raise exception 'NOMBRE_MUY_CORTO'; end if;
  if exists (select 1 from branches where code = p_code and deleted_at is null) then
    raise exception 'SEDE_YA_EXISTE';
  end if;

  insert into branches (code, name) values (p_code, p_name) returning id into v_branch;

  -- Jornada estándar de arranque: lunes a viernes, sábado y domingo
  -- inactivos. Se ajusta después desde el panel, sin tocar código.
  insert into work_schedules (branch_id, name, description, created_by)
  values (v_branch, 'Jornada estándar',
          'Lunes a viernes 08:00–12:00 y 14:00–18:00. Sábado y domingo inactivos.',
          auth.uid())
  returning id into v_sched;

  for d in 0..6 loop
    if d between 1 and 5 then
      insert into schedule_days (schedule_id, weekday, is_working, is_continuous,
                                 entry_time, lunch_out_time, lunch_in_time, exit_time)
      values (v_sched, d, true, false, '08:00', '12:00', '14:00', '18:00');
    else
      insert into schedule_days (schedule_id, weekday, is_working) values (v_sched, d, false);
    end if;
  end loop;

  insert into schedule_assignments (schedule_id, branch_id, valid_from, note, created_by)
  values (v_sched, v_branch, current_date, 'Asignación inicial de la sede ' || p_name, auth.uid());

  v_term := coalesce(nullif(btrim(p_terminal), ''), p_code || '-01');
  insert into terminals (code, branch_id, label)
  values (v_term, v_branch, 'Terminal ' || p_name);

  return jsonb_build_object('ok', true, 'branch_id', v_branch, 'terminal', v_term);
end $$;
grant execute on function public.crear_sede(text, text, text) to authenticated;

-- ── Dar acceso al panel a una persona ────────────────────────────────
-- El usuario se crea antes en Supabase (Authentication → Add user), que
-- es lo único que el navegador no puede hacer sin la llave maestra.
-- Aquí sólo se le asigna rol y sede.
create or replace function public.registrar_usuario(
  p_email text, p_nombre text, p_rol text, p_branch uuid default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid; v_rol app_role;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;

  begin v_rol := p_rol::app_role;
  exception when invalid_text_representation then raise exception 'ROL_INVALIDO'; end;

  if v_rol = 'ceo' and p_branch is not null then raise exception 'CEO_SIN_SEDE'; end if;
  if v_rol <> 'ceo' and p_branch is null    then raise exception 'FALTA_SEDE'; end if;

  select id into v_uid from auth.users where lower(email) = lower(btrim(p_email));
  if v_uid is null then
    raise exception 'USUARIO_NO_EXISTE'
      using hint = 'Créelo primero en Supabase: Authentication → Users → Add user.';
  end if;

  if exists (select 1 from profiles where id = v_uid) then
    raise exception 'YA_TIENE_ACCESO';
  end if;

  insert into profiles (id, full_name, role, branch_id)
  values (v_uid, btrim(p_nombre), v_rol, p_branch);

  return jsonb_build_object('ok', true, 'id', v_uid);
end $$;
grant execute on function public.registrar_usuario(text, text, text, uuid) to authenticated;

-- ── Horario de una sede, en una sola consulta ────────────────────────
-- Devuelve los siete días con su horario vigente. El panel lo pinta tal cual.
create or replace function public.horario_sede(p_branch uuid)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_sched uuid; v_res jsonb;
begin
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select a.schedule_id into v_sched
    from schedule_assignments a
    join work_schedules w on w.id = a.schedule_id
   where a.branch_id = p_branch
     and current_date between a.valid_from and coalesce(a.valid_to, 'infinity'::date)
     and w.is_active and w.deleted_at is null
   limit 1;

  if v_sched is null then
    return jsonb_build_object('ok', false, 'reason', 'SIN_HORARIO');
  end if;

  select jsonb_agg(jsonb_build_object(
           'id', d.id, 'weekday', d.weekday, 'is_working', d.is_working,
           'is_continuous', d.is_continuous, 'entry_time', d.entry_time,
           'lunch_out_time', d.lunch_out_time, 'lunch_in_time', d.lunch_in_time,
           'exit_time', d.exit_time) order by d.weekday)
    into v_res
    from schedule_days d where d.schedule_id = v_sched;

  return jsonb_build_object('ok', true, 'schedule_id', v_sched, 'dias', coalesce(v_res, '[]'::jsonb));
end $$;
grant execute on function public.horario_sede(uuid) to authenticated;

-- Guardar un día del horario. Valida el orden de las horas antes de escribir
-- para que el error llegue en castellano y no como una violación de constraint.
create or replace function public.guardar_dia_horario(
  p_dia uuid, p_trabaja boolean, p_corrida boolean,
  p_entrada time default null, p_salida_almuerzo time default null,
  p_regreso time default null, p_salida time default null)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_branch uuid;
begin
  select w.branch_id into v_branch
    from schedule_days d join work_schedules w on w.id = d.schedule_id
   where d.id = p_dia;
  if v_branch is null then raise exception 'DIA_INEXISTENTE'; end if;
  if not (app.is_ceo() or app.is_admin()) or not app.can_see_branch(v_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  if not p_trabaja then
    update schedule_days set is_working = false, is_continuous = false,
           entry_time = null, lunch_out_time = null, lunch_in_time = null, exit_time = null
     where id = p_dia;
    return jsonb_build_object('ok', true, 'estado', 'descanso');
  end if;

  if p_entrada is null or p_salida is null then
    raise exception 'FALTAN_HORAS' using hint = 'Un día laborable necesita hora de entrada y de salida.';
  end if;

  if p_corrida then
    if p_salida <= p_entrada then
      raise exception 'ORDEN_INVALIDO' using hint = 'La salida debe ser posterior a la entrada.';
    end if;
    update schedule_days set is_working = true, is_continuous = true,
           entry_time = p_entrada, exit_time = p_salida,
           lunch_out_time = null, lunch_in_time = null
     where id = p_dia;
  else
    if p_salida_almuerzo is null or p_regreso is null then
      raise exception 'FALTAN_HORAS' using hint = 'Una jornada partida necesita las cuatro horas.';
    end if;
    if not (p_entrada < p_salida_almuerzo
            and p_salida_almuerzo < p_regreso
            and p_regreso < p_salida) then
      raise exception 'ORDEN_INVALIDO'
        using hint = 'Las horas deben ir en orden: entrada, salida a almuerzo, regreso, salida.';
    end if;
    update schedule_days set is_working = true, is_continuous = false,
           entry_time = p_entrada, lunch_out_time = p_salida_almuerzo,
           lunch_in_time = p_regreso, exit_time = p_salida
     where id = p_dia;
  end if;

  return jsonb_build_object('ok', true, 'estado', 'guardado');
end $$;
grant execute on function public.guardar_dia_horario(uuid, boolean, boolean, time, time, time, time) to authenticated;

-- ── Resumen para la portada del panel ────────────────────────────────
create or replace function public.resumen_sedes()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;

  select jsonb_agg(x order by x->>'code') into v
  from (
    select jsonb_build_object(
      'id', b.id, 'code', b.code, 'name', b.name,
      'empleados', (select count(*) from employees e
                     where e.branch_id = b.id and e.is_active and e.deleted_at is null),
      'terminales', (select count(*) from terminals t
                      where t.branch_id = b.id and t.deleted_at is null),
      'emparejados', (select count(*) from terminals t
                       where t.branch_id = b.id and t.deleted_at is null and t.paired_at is not null)
    ) as x
    from branches b
    where b.deleted_at is null and app.can_see_branch(b.id)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;
grant execute on function public.resumen_sedes() to authenticated;

-- ####################################################################
-- ##  seed.sql — sedes, terminales, horarios y configuración
-- ####################################################################

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · Semilla inicial
-- =====================================================================
-- Idempotente: puede ejecutarse varias veces sin duplicar nada.
-- No crea usuarios ni empleados: eso ocurre en la Fase 2, con auditoría.
-- =====================================================================

-- ── SEDES ────────────────────────────────────────────────────────────
insert into branches (code, name, timezone) values
  ('CSS', 'Caja Seca',  'America/Caracas'),
  ('MCB', 'Maracaibo',  'America/Caracas'),
  ('MCY', 'Maracay',    'America/Caracas')
on conflict do nothing;

-- ── TERMINALES (sin emparejar: sin token hasta el pairing) ───────────
insert into terminals (code, branch_id, label)
select v.code, b.id, v.label
  from (values
    ('CSS-01', 'CSS', 'Terminal Caja Seca'),
    ('MCB-01', 'MCB', 'Terminal Maracaibo'),
    ('MCY-01', 'MCY', 'Terminal Maracay')
  ) as v(code, branch_code, label)
  join branches b on b.code = v.branch_code
on conflict (code) do nothing;

-- ── HORARIOS ─────────────────────────────────────────────────────────
-- Jornada estándar de cada sede: lunes a viernes 08:00–12:00 / 14:00–18:00.
-- Sábado y domingo INACTIVOS: un sábado inactivo no genera ausencia.
-- Activar el sábado es cambiar schedule_days, no tocar código.
do $$
declare b record; v_sched uuid; d int;
begin
  for b in select id, code, name from branches loop
    select id into v_sched from work_schedules
     where branch_id = b.id and name = 'Jornada estándar' and deleted_at is null;

    if v_sched is null then
      insert into work_schedules (branch_id, name, description)
      values (b.id, 'Jornada estándar',
              'Lunes a viernes 08:00–12:00 y 14:00–18:00. Sábado y domingo inactivos.')
      returning id into v_sched;

      -- 0 = domingo … 6 = sábado
      for d in 0..6 loop
        if d between 1 and 5 then
          insert into schedule_days (schedule_id, weekday, is_working, is_continuous,
                                     entry_time, lunch_out_time, lunch_in_time, exit_time)
          values (v_sched, d, true, false, '08:00', '12:00', '14:00', '18:00');
        else
          insert into schedule_days (schedule_id, weekday, is_working) values (v_sched, d, false);
        end if;
      end loop;

      insert into schedule_assignments (schedule_id, branch_id, valid_from, note)
      values (v_sched, b.id, date '2026-01-01', 'Asignación inicial de la sede ' || b.name);
    end if;
  end loop;
end $$;

-- ── CONFIGURACIÓN ────────────────────────────────────────────────────
-- Todo umbral del sistema vive aquí. Gerencia lo cambia sin desplegar.
insert into system_settings (key, value, description) values
  ('attendance.arrival.early_minutes',     '15',  'Minutos antes de la hora para considerar ANTICIPADO'),
  ('attendance.arrival.grace_minutes',     '5',   'Margen que sigue siendo PUNTUAL'),
  ('attendance.arrival.tolerance_minutes', '10',  'Hasta aquí es TOLERANCIA; después, RETRASO'),
  ('attendance.departure.grace_minutes',   '5',   'Salir hasta 5 min antes sigue siendo PUNTUAL'),
  ('attendance.departure.tolerance_minutes','10', 'Salida anticipada tolerada'),
  ('attendance.absence.cutoff_minutes',    '120', 'Minutos tras la última marcación esperada para dar el día por cerrado'),
  ('evidence.retention_days',              '180', 'Días que se conserva la fotografía. Vencidos, se borra sólo el archivo'),
  ('evidence.max_width_px',                '640', 'Lado mayor de la fotografía capturada en el terminal'),
  ('pin.throttle_base_ms',                 '300', 'Espera inicial tras un PIN fallido en el terminal'),
  ('pin.throttle_max_ms',                  '3000','Tope de la espera progresiva. NUNCA se bloquea el terminal'),
  ('pin.throttle_window_minutes',          '10',  'Ventana de fallos que alimenta la espera progresiva'),
  ('pin.alert_failures',                   '12',  'Fallos en la ventana que disparan alerta de fuerza bruta'),
  ('pin.alert_window_minutes',             '10',  'Ventana de la alerta'),
  ('terminal.pairing_ttl_minutes',         '10',  'Vigencia del código de emparejamiento'),
  ('terminal.ticket_ttl_seconds',          '60',  'Vigencia del ticket de confirmación de marcación'),
  ('offline.max_age_hours',                '72',  'Antigüedad máxima aceptada de una marcación offline'),
  ('offline.max_drift_minutes',            '10',  'Desfase de reloj que manda la marcación a revisión'),
  ('ui.brand_name',                        '"CrediSan Control"', 'Nombre mostrado en la aplicación'),
  ('ui.slogan',                            '"Pensamos en Ti"',   'Eslogan corporativo')
on conflict do nothing;


-- =====================================================================
--   COMPROBACIÓN FINAL
-- =====================================================================
do $$
declare v_tablas int; v_pol int; v_sedes int; v_term int; v_cfg int; v_sin_rls int;
begin
  select count(*) into v_tablas from pg_tables where schemaname = 'public';
  select count(*) into v_pol    from pg_policies where schemaname in ('public','storage');
  select count(*) into v_sedes  from branches;
  select count(*) into v_term   from terminals;
  select count(*) into v_cfg    from system_settings;

  select count(*) into v_sin_rls
    from pg_tables t
   where t.schemaname = 'public'
     and not exists (select 1 from pg_class c
                      join pg_namespace n on n.oid = c.relnamespace
                     where c.relname = t.tablename and n.nspname = 'public'
                       and c.relrowsecurity);

  raise notice '';
  raise notice '  CREDISAN CONTROL — instalación terminada';
  raise notice '  ---------------------------------------';
  raise notice '  Tablas .................. %', v_tablas;
  raise notice '  Políticas de seguridad .. %', v_pol;
  raise notice '  Sedes ................... %', v_sedes;
  raise notice '  Terminales .............. %', v_term;
  raise notice '  Parámetros .............. %', v_cfg;
  raise notice '';

  if v_sin_rls > 0 then
    raise exception 'ATENCION: % tabla(s) sin seguridad a nivel de fila.', v_sin_rls;
  end if;
  if v_sedes < 3 or v_term < 3 then
    raise exception 'ATENCION: faltan sedes o terminales.';
  end if;

  raise notice '  Todo correcto. Siguiente paso: Settings -> API,';
  raise notice '  copie Project URL y anon public en config/env.js';
  raise notice '';
end $$;
