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
