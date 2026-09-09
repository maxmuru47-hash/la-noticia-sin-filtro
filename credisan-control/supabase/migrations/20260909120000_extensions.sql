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
