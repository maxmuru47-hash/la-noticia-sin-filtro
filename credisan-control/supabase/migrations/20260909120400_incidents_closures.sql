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
