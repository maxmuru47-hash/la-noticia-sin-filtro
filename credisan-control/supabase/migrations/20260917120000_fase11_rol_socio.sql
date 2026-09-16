-- =====================================================================
-- FASE 11 (1 de 2) · el rol SOCIO
-- =====================================================================
-- Este archivo hace UNA sola cosa, y está separado del resto de la fase
-- por una razón de PostgreSQL, no de gusto:
--
--   Un valor de enum recién añadido NO SE PUEDE USAR en la misma
--   transacción que lo añadió. PostgreSQL lo rechaza con «unsafe use of
--   new value». Y cada archivo de migración se aplica en una sola
--   transacción, a propósito, para que un fallo a mitad no deje la base
--   a medias.
--
--   Así que el valor se confirma aquí, y el archivo siguiente —que sí
--   lo necesita para la restricción de `profiles` y para las reglas—
--   ya lo encuentra existiendo.
--
-- Probado: escribiendo las dos cosas juntas, la migración falla.
-- =====================================================================

alter type app_role add value if not exists 'socio';

comment on type app_role is
  'ceo = acceso nacional · admin = administradora de una sede · '
  'supervisor = jefe operativo de una sede · '
  'socio = consulta de las sedes que se le asignen, sin modificar nada';
