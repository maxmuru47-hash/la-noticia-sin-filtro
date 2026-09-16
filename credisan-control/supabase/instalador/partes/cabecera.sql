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

