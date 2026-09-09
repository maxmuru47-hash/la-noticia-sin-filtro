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
