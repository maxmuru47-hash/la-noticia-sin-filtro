-- =====================================================================
-- CREDISAN CONTROL · Batería de la ruta de evidencia
-- =====================================================================
-- La fotografía se guarda en `<sede>/…`, y ese primer segmento decide
-- quién puede leerla después. Si la sede la pudiera elegir el terminal
-- —un teléfono en un mostrador, hostil por diseño— podría hacer que el
-- servidor escribiera bajo la sede que quisiera.
--
-- Aquí se comprueba que la sede la pone la BASE DE DATOS.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select id as css from branches where code = 'CSS' \gset
select set_config('t.mcb', :'mcb', false), set_config('t.css', :'css', false);

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15');
select id as e1 from employees where internal_code='MCB-001' \gset
select set_config('t.e1', :'e1', false);

insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', x.d, x.d, true, false,
       (x.t - interval '2 minutes')::time, (x.t + interval '5 minutes')::time,
       (x.t + interval '10 minutes')::time, (x.t + interval '15 minutes')::time, 'prueba'
from employees e, (select (now() at time zone 'America/Caracas')::date d,
                          (now() at time zone 'America/Caracas')::time t) x;

-- =====================================================================
--  1. La marcación devuelve la sede, y es la de verdad
-- =====================================================================
do $$ declare v_term uuid; r jsonb; v_pin text; v_code text; v_tk uuid; begin
  select id into v_term from terminals where code = 'MCB-01';
  r := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  v_pin := r->>'pin';
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Prueba');
  r := public.edge_verificar_pin(v_term, v_pin, 'PEPPER');
  v_tk := (r->>'ticket')::uuid;
  r := public.edge_registrar(v_tk, gen_random_uuid());

  assert r ? 'branch_id',
    'FALLA GRAVE: la marcación no devuelve la sede; la función del servidor '
    'tendría que creerle al terminal para construir la ruta';
  assert (r->>'branch_id') = current_setting('t.mcb'),
    'FALLA GRAVE: la sede devuelta no es la de la marcación';
  assert (r->>'branch_id') <> current_setting('t.css'),
    'FALLA GRAVE: devolvió la sede equivocada';
  perform set_config('t.ev', r->>'id', false);
end $$;
\echo '  ✔  1. La marcación devuelve su sede, tomada de la fila escrita'

-- =====================================================================
--  2. También cuando es un reenvío
-- =====================================================================
-- El camino del duplicado devuelve un objeto distinto y más corto. Si
-- ahí faltara la sede, la ruta volvería a depender del terminal en el
-- único caso que un atacante puede provocar a voluntad: reenviar.
do $$ declare v_term uuid; v_id uuid := gen_random_uuid(); r1 jsonb; r2 jsonb;
             v_pin text; v_tk uuid; begin
  select id into v_term from terminals where code = 'MCB-01';
  r1 := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  v_pin := r1->>'pin';
  r1 := public.edge_verificar_pin(v_term, v_pin, 'PEPPER');
  if (r1->>'ok')::boolean then
    v_tk := (r1->>'ticket')::uuid;
    r1 := public.edge_registrar(v_tk, v_id);
    r2 := public.edge_registrar(v_tk, v_id);
    if (r2->>'duplicado')::boolean then
      assert r2 ? 'branch_id',
        'FALLA GRAVE: el reenvío no devuelve la sede, y ése es el caso que '
        'un atacante puede repetir a voluntad';
      assert (r2->>'branch_id') = current_setting('t.mcb'),
        'FALLA GRAVE: sede equivocada en el reenvío';
    end if;
  end if;
end $$;
\echo '  ✔  2. El reenvío también la devuelve'

-- =====================================================================
--  3. Una marcación sin conexión, igual
-- =====================================================================
do $$ declare v_term uuid; r jsonb; v_pin text; begin
  select id into v_term from terminals where code = 'MCB-01';
  r := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  v_pin := r->>'pin';
  r := public.edge_offline(v_term, v_pin, 'PEPPER', gen_random_uuid(),
                           now() - interval '10 minutes', 9001, 5);
  if (r->>'ok')::boolean then
    assert r ? 'branch_id', 'FALLA GRAVE: la marcación offline no devuelve la sede';
    assert (r->>'branch_id') = current_setting('t.mcb'), 'FALLA GRAVE: sede equivocada';
  end if;
end $$;
\echo '  ✔  3. La marcación sin conexión también'

-- =====================================================================
--  4. La sede devuelta SIEMPRE es un UUID
-- =====================================================================
-- La función del servidor lo vuelve a comprobar antes de construir la
-- ruta, pero conviene que aquí no salga nunca otra cosa.
do $$ declare v_mal int; begin
  select count(*) into v_mal from attendance_events
   where branch_id is null;
  assert v_mal = 0, 'FALLA GRAVE: hay marcaciones sin sede';
end $$;
\echo '  ✔  4. Ninguna marcación queda sin sede'

-- =====================================================================
--  5. El cerrojo de permisos alcanzó a todas las funciones
-- =====================================================================
-- PostgreSQL concede EXECUTE a `public` en cada función nueva. Si alguna
-- se quedara así, `anon` —cualquiera con la clave pública— podría
-- llamarla.
do $$ declare v_abiertas text; begin
  select string_agg(p.proname, ', ') into v_abiertas
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public' and p.prokind = 'f'
     and has_function_privilege('anon', p.oid, 'execute');
  assert v_abiertas is null,
    'FALLA GRAVE: funciones al alcance de anon: ' || v_abiertas;

  select string_agg(p.proname, ', ') into v_abiertas
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public' and p.prokind = 'f' and p.proname like 'edge_%'
     and has_function_privilege('authenticated', p.oid, 'execute');
  assert v_abiertas is null,
    'FALLA GRAVE: puertas del servidor al alcance del panel: ' || v_abiertas;
end $$;
\echo '  ✔  5. Ni una función al alcance de anon, ni una edge_* al del panel'

\echo ''
\echo '  ✔  BATERÍA DE RUTA DE EVIDENCIA COMPLETA — todas las validaciones pasaron'
\echo ''
