-- =====================================================================
-- Sólo para pruebas locales: reproduce lo que Supabase ya trae hecho
-- (roles, esquema auth y esquema storage). NO se aplica en Supabase.
-- =====================================================================
do $$ begin
  if not exists (select 1 from pg_roles where rolname = 'anon')                then create role anon nologin; end if;
  if not exists (select 1 from pg_roles where rolname = 'authenticated')       then create role authenticated nologin; end if;
  if not exists (select 1 from pg_roles where rolname = 'service_role')        then create role service_role nologin bypassrls; end if;
  if not exists (select 1 from pg_roles where rolname = 'supabase_auth_admin') then create role supabase_auth_admin nologin; end if;
end $$;

create schema if not exists auth;
create schema if not exists storage;

create table if not exists auth.users (
  id uuid primary key default gen_random_uuid(),
  email text unique,
  created_at timestamptz not null default now()
);

create or replace function auth.uid() returns uuid language sql stable as $$
  select nullif(coalesce(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'sub', ''), '')::uuid
$$;

create table if not exists storage.buckets (
  id text primary key,
  name text not null,
  public boolean not null default false,
  file_size_limit bigint,
  allowed_mime_types text[],
  created_at timestamptz not null default now()
);

create table if not exists storage.objects (
  id uuid primary key default gen_random_uuid(),
  bucket_id text not null references storage.buckets (id),
  name text not null,
  owner uuid,
  created_at timestamptz not null default now()
);
alter table storage.objects enable row level security;

create or replace function storage.foldername(name text) returns text[] language plpgsql immutable as $$
declare parts text[];
begin
  parts := string_to_array(name, '/');
  return parts[1:greatest(array_length(parts, 1) - 1, 0)];
end $$;

grant usage on schema auth, storage to anon, authenticated, service_role;
grant select on storage.buckets to authenticated;
grant select, insert, update on storage.objects to authenticated;
