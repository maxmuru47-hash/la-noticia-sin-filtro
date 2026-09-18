-- =====================================================================
-- CREDISAN CONTROL · Batería de la Fase 4 (tableros y novedades)
-- =====================================================================
-- Además de comprobar que los números salen, verifica lo que Max pidió
-- expresamente: que estas pantallas nuevas no filtren ni un dato de los
-- trabajadores fuera de quien tiene derecho a verlo.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select id as css from branches where code = 'CSS' \gset
select set_config('t.mcb', :'mcb', false), set_config('t.css', :'css', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','ceo@credisan.test'),
  ('22222222-2222-2222-2222-222222222222','admin.mcb@credisan.test'),
  ('33333333-3333-3333-3333-333333333333','jefe.mcb@credisan.test');
insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección','ceo', null),
  ('22222222-2222-2222-2222-222222222222','Admin MCB','admin', :'mcb'),
  ('33333333-3333-3333-3333-333333333333','Jefe MCB','supervisor', :'mcb');

-- Dos de Maracaibo y una de Caja Seca, con salario cargado
insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Luis','Rojas','V-2','Asesor','2026-01-15'),
       (:'css','CSS-001','Mara','Silva','V-3','Supervisora','2026-01-15');
select id as e1 from employees where internal_code='MCB-001' \gset
select id as e2 from employees where internal_code='MCB-002' \gset
select id as e3 from employees where internal_code='CSS-001' \gset
select set_config('t.e1', :'e1', false), set_config('t.e2', :'e2', false);

insert into employee_compensation (employee_id, weekly_base, effective_from)
values (:'e1', 150.00, '2026-01-15'), (:'e3', 200.00, '2026-01-15');

-- Jornada de hoy anclada a la hora actual: Ana llega puntual, Luis no llega.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', x.d, x.d, true, false,
       (x.t - interval '2 minutes')::time, (x.t + interval '5 minutes')::time,
       (x.t + interval '10 minutes')::time, (x.t + interval '15 minutes')::time, 'prueba'
from employees e, (select (now() at time zone 'America/Caracas')::date d,
                          (now() at time zone 'America/Caracas')::time t) x
where e.internal_code in ('MCB-001','MCB-002');

do $$
declare v_term uuid; v_pin text; r jsonb; v_tid uuid; v_tk uuid; v_code text;
begin
  select id into v_term from terminals where code = 'MCB-01';
  r := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  v_pin := r->>'pin';
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Prueba');
  v_tid := public.edge_autenticar_terminal('MCB-01', r->>'token');
  r := public.edge_verificar_pin(v_tid, v_pin, 'PEPPER');
  v_tk := (r->>'ticket')::uuid;
  r := public.edge_registrar(v_tk, gen_random_uuid());
  assert r->>'status' = 'puntual', 'FALLA: la marcación de prueba no salió puntual';
end $$;

-- ── 1. HOY, visto por el jefe operativo ──────────────────────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','supervisor','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare t jsonb; r jsonb; begin
    t := public.tablero_hoy(current_setting('t.mcb')::uuid);
    r := t->'resumen';
    assert (t->>'ok')::boolean, 'FALLA: tablero_hoy';
    assert (r->>'esperados')::int = 2, 'FALLA: esperados = ' || (r->>'esperados');
    assert (r->>'presentes')::int = 1, 'FALLA: presentes = ' || (r->>'presentes');
    assert jsonb_array_length(t->'empleados') = 2,
           'FALLA GRAVE: el tablero lista trabajadores de otra sede';
    -- Nadie de Caja Seca puede asomarse aquí. Se busca el apellido: el
    -- nombre "Mara" aparecería dentro de "Maracaibo" y daría falso positivo.
    assert not (t::text like '%Silva%'), 'FALLA GRAVE: fuga de datos entre sedes';
    -- Y en ningún caso aparece dinero. No se busca el número "150" suelto:
    -- esos dígitos salen por casualidad dentro de cualquier UUID y darían
    -- una falla falsa. Se comprueba algo más fuerte: que la ficha de cada
    -- trabajador tenga EXACTAMENTE los campos previstos. Así no se cuela
    -- ni el salario ni ningún otro dato que no debería viajar.
    -- `evento_id` se añadió al habilitar la consulta de la fotografía: sin
    -- él el panel sabe que hay foto pero no puede pedir cuál. Es el
    -- identificador de la marcación, no un dato del trabajador.
    assert (select bool_and(campos = array['cargo','entrada','esperada','estado',
                                           'evento_id','evidencia','id','minutos',
                                           'nombre','origen','salida'])
              from (select array(select jsonb_object_keys(e) order by 1) campos
                      from jsonb_array_elements(t->'empleados') e) k),
           'FALLA GRAVE: el tablero devuelve campos no previstos: ' ||
           (select string_agg(distinct k, ', ')
              from jsonb_array_elements(t->'empleados') e,
                   jsonb_object_keys(e) k);
    assert not (t::text ~* 'salario|salary|weekly_base|compensation'),
           'FALLA GRAVE: el tablero nombra información salarial';
  end $$;

  -- El jefe no puede mirar la sede ajena aunque pregunte por ella
  do $$ declare ok boolean := false; begin
    begin perform public.tablero_hoy(current_setting('t.css')::uuid);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe operativo vio el tablero de otra sede';
  end $$;

  -- Reporta una novedad: sólo pendiente y a su nombre (lo impone la RLS)
  do $$ declare r jsonb; begin
    r := public.registrar_novedad(current_setting('t.e2')::uuid, 'permiso',
           'Permiso médico de la mañana');
    assert (r->>'ok')::boolean, 'FALLA: el jefe no pudo reportar';
    assert (select status from incidents where id = (r->>'id')::uuid) = 'pendiente',
           'FALLA: la novedad no nació pendiente';
    assert (select reported_by from incidents where id = (r->>'id')::uuid)
           = '33333333-3333-3333-3333-333333333333', 'FALLA: autoría incorrecta';
  end $$;

  -- Pero no puede resolverla
  do $$ declare r jsonb; begin
    r := public.resolver_novedad(
      (select id from incidents where kind='permiso' order by created_at desc limit 1), true, null);
    assert not (r->>'ok')::boolean, 'FALLA GRAVE: el jefe operativo aprobó una novedad';
  end $$;

  -- Ni ver los tableros de dirección de otras sedes
  do $$ declare t jsonb; begin
    t := public.tablero_periodo(current_date - 7, current_date);
    assert jsonb_array_length(t->'sedes') = 1, 'FALLA GRAVE: ve más de una sede';
    assert (t->'sedes'->0->>'sede') = 'Maracaibo', 'FALLA: sede incorrecta';
  end $$;
rollback;

-- ── 2. La administradora resuelve ────────────────────────────────────
-- Era un «permiso» hasta la fase 13, que decidió que un permiso no se
-- aprueba sin el documento. Esta batería mide OTRA cosa —que quien
-- resuelve sea administración y que el listado no cruce sedes— así que
-- se cambia por un tipo que no exige papel, en vez de debilitar la
-- regla nueva para que la prueba vieja siga pasando.
--
-- Que la exigencia del documento se cumpla se comprueba entera en
-- supabase/tests/12_documentos.sql.
insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date, reported_by)
values (:'e2', :'mcb', 'olvido_marcacion', 'Permiso a resolver', now(), current_date,
        '33333333-3333-3333-3333-333333333333');

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v_id uuid; begin
    select id into v_id from incidents where description = 'Permiso a resolver';
    r := public.resolver_novedad(v_id, true, 'Autorizado por dirección de sede');
    assert (r->>'ok')::boolean and r->>'estado' = 'aprobada', 'FALLA: no pudo resolver';
    assert (select reviewed_by from incidents where id = v_id)
           = '22222222-2222-2222-2222-222222222222', 'FALLA: revisor incorrecto';

    assert jsonb_array_length(public.novedades(null, 'aprobada')) = 1, 'FALLA: listado de novedades';
    assert not (public.novedades()::text like '%Silva%'),
           'FALLA GRAVE: novedades de otra sede visibles';
  end $$;
rollback;

-- ── 3. Dirección ve las tres sedes ───────────────────────────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare t jsonb; begin
    t := public.tablero_periodo(current_date - 7, current_date);
    assert jsonb_array_length(t->'sedes') = 3, 'FALLA: dirección no ve las tres sedes';
    assert (t->'total'->>'marcaciones')::int = 1, 'FALLA: total de marcaciones';
    assert not (t::text ~* 'salario|salary|weekly_base|compensation'),
           'FALLA GRAVE: el tablero ejecutivo expone salarios';
    -- Misma comprobación estructural que en el tablero del día: la fila de
    -- cada sede lleva sólo cifras de asistencia, nunca de nómina.
    assert (select bool_and(campos = array['anticipados','ausencias','code','id',
                                           'incompletos','marcaciones','minutos_retraso',
                                           'novedades','offline','promedio_retraso',
                                           'puntuales','puntualidad','retrasos','sede',
                                           'sin_evidencia','trabajadores'])
              from (select array(select jsonb_object_keys(s) order by 1) campos
                      from jsonb_array_elements(t->'sedes') s) k),
           'FALLA GRAVE: el comparativo devuelve campos no previstos: ' ||
           (select string_agg(distinct k, ', ')
              from jsonb_array_elements(t->'sedes') s, jsonb_object_keys(s) k);

    t := public.tablero_hoy(current_setting('t.css')::uuid);
    assert (t->>'ok')::boolean, 'FALLA: dirección no pudo ver Caja Seca';
  end $$;
rollback;

-- ── 4. Ningún dato existente se tocó ─────────────────────────────────
do $$ begin
  assert (select count(*) from employees where deleted_at is null) = 3,
         'FALLA GRAVE: la Fase 4 alteró la lista de trabajadores';
  assert (select count(*) from employee_compensation) = 2,
         'FALLA GRAVE: la Fase 4 alteró los salarios';
  assert (select count(*) from attendance_events) = 1,
         'FALLA GRAVE: la Fase 4 alteró las marcaciones';
  assert (select weekly_base from employee_compensation
           where employee_id = current_setting('t.e1')::uuid) = 150.00,
         'FALLA GRAVE: un salario cambió de valor';
end $$;

\echo ''
\echo '  ✔  BATERÍA DE FASE 4 COMPLETA — todas las validaciones pasaron'
\echo ''
