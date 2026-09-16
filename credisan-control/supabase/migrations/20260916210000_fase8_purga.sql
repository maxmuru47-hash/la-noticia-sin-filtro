-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0018 · Purga de evidencia (puentes)
-- =====================================================================
-- SÓLO AÑADE.
--
-- La retención de 180 días era un compromiso con los trabajadores: su
-- fotografía no se guarda para siempre. El motor para cumplirlo existe
-- desde la Fase 1 —`evidence_due_for_purge` y `confirm_evidence_purged`—
-- pero vive en el esquema `app`, que PostgREST no publica, así que la
-- función del servidor no podía llamarlo. Sin eso, las fotografías se
-- acumulaban indefinidamente y la promesa no se cumplía sola.
--
-- Aquí están los dos puentes, cerrados a todo el mundo menos al servidor,
-- igual que el resto de `edge_*`.
--
-- Qué se borra y qué no: se borra EL ARCHIVO. La marcación, su hora, su
-- clasificación y su auditoría permanecen para siempre. Lo que caduca es
-- la imagen de la cara de una persona, no el registro de que trabajó.
-- =====================================================================

create or replace function public.edge_evidencia_por_purgar(p_limite int default 200)
returns jsonb language sql stable security definer
set search_path = app, public, pg_temp as $$
  select coalesce(jsonb_agg(jsonb_build_object('id', id, 'ruta', storage_path)), '[]'::jsonb)
    from app.evidence_due_for_purge(least(greatest(coalesce(p_limite, 200), 1), 500))
$$;
revoke all on function public.edge_evidencia_por_purgar(int) from public, anon, authenticated;
grant execute on function public.edge_evidencia_por_purgar(int) to service_role;

create or replace function public.edge_confirmar_purga(p_ids uuid[])
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  if p_ids is null or array_length(p_ids, 1) is null then
    return jsonb_build_object('ok', true, 'purgadas', 0);
  end if;
  perform app.confirm_evidence_purged(p_ids);
  return jsonb_build_object('ok', true, 'purgadas', array_length(p_ids, 1));
end $$;
revoke all on function public.edge_confirmar_purga(uuid[]) from public, anon, authenticated;
grant execute on function public.edge_confirmar_purga(uuid[]) to service_role;
