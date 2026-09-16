-- =====================================================================
--
--   CREDISAN CONTROL · FASE 11 (1 de 2) · el rol SOCIO
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA: añade un rol.
--
--   POR QUÉ SON DOS ARCHIVOS Y NO UNO
--   ---------------------------------
--   PostgreSQL no deja usar un rol recién creado en la misma operación
--   que lo crea. Así que el rol se confirma aquí, y el archivo 2 —que
--   lo necesita para las reglas— ya lo encuentra existiendo.
--
--   Ejecute PRIMERO éste y DESPUÉS el 2. Si los invierte, el 2 se
--   detiene solo y le dice que falta éste.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
end $$;

alter type app_role add value if not exists 'socio';

comment on type app_role is
  'ceo = acceso nacional · admin = administradora de una sede · '
  'supervisor = jefe operativo de una sede · '
  'socio = consulta de las sedes que se le asignen, sin modificar nada';

do $$ begin
  raise notice 'Rol SOCIO disponible. Ahora ejecute ACTUALIZAR-FASE11-2-SOCIOS.sql.';
end $$;
