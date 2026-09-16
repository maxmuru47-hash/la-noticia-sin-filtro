-- =====================================================================
-- CREDISAN CONTROL · Batería de la evidencia fotográfica
-- =====================================================================
-- La fotografía es lo que convierte una hora en una prueba. Aquí se
-- comprueba que se puede consultar, que sólo por quien debe, y —lo que
-- de verdad importa— que MIRARLA DEJA RASTRO.
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
  ('33333333-3333-3333-3333-333333333333','jefe.mcb@credisan.test'),
  ('44444444-4444-4444-4444-444444444444','jefe.css@credisan.test');
insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección','ceo', null),
  ('22222222-2222-2222-2222-222222222222','Admin MCB','admin', :'mcb'),
  ('33333333-3333-3333-3333-333333333333','Jefe MCB','supervisor', :'mcb'),
  ('44444444-4444-4444-4444-444444444444','Jefe CSS','supervisor', :'css');

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

-- Una marcación con su fotografía guardada
do $$ declare v_term uuid; r jsonb; v_pin text; v_code text; v_tk uuid; begin
  select id into v_term from terminals where code = 'MCB-01';
  r := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  v_pin := r->>'pin';
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Prueba');
  r := public.edge_verificar_pin(v_term, v_pin, 'PEPPER');
  v_tk := (r->>'ticket')::uuid;
  r := public.edge_registrar(v_tk, gen_random_uuid());
  perform set_config('t.ev', r->>'id', false);
  perform public.edge_evidencia((r->>'id')::uuid, 'sede/2026/09/foto.jpg', 42000, now());
end $$;

-- =====================================================================
--  1. Quien manda en la sede la ve, y mirar deja rastro
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v_antes int; begin
    select count(*) into v_antes from audit_logs where action = 'evidencia.consultada';

    r := public.evidencia_de(current_setting('t.ev')::uuid);
    assert (r->>'ok')::boolean, 'FALLA: administración no pudo ver la evidencia: ' || r::text;
    assert (r->>'ruta') = 'sede/2026/09/foto.jpg', 'FALLA: no devuelve la ruta';
    assert (r->>'nombre') = 'Ana Pérez', 'FALLA: no dice de quién es';

    assert (select count(*) from audit_logs where action = 'evidencia.consultada') = v_antes + 1,
      'FALLA GRAVE: mirar la fotografía de alguien no dejó rastro';
    assert exists (select 1 from audit_logs
                    where action = 'evidencia.consultada'
                      and actor_id = '22222222-2222-2222-2222-222222222222'
                      and metadata->>'nombre' = 'Ana Pérez'),
      'FALLA GRAVE: el rastro no dice quién miró la foto de quién';
  end $$;
rollback;
\echo '  ✔  1. Administración la ve, y queda registrado quién miró la de quién'

-- =====================================================================
--  2. El jefe operativo NO la ve, ni siquiera en su propia sede
-- =====================================================================
-- Así lo dice la matriz de roles que se aprobó en la Fase 1. Una
-- fotografía de la cara de alguien es de lo más sensible que guarda
-- este sistema, y el jefe operativo sigue viendo quién marcó, a qué
-- hora y si fue con foto o sin ella: si algo no le cuadra, lo reporta
-- como novedad y administración mira la fotografía.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','supervisor','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.evidencia_de(current_setting('t.ev')::uuid);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe operativo vio la fotografía de un trabajador';
  end $$;

  -- Y el intento no figura como consulta: no miró nada
  do $$ begin
    assert not exists (select 1 from audit_logs
                        where action = 'evidencia.consultada'
                          and actor_id = '33333333-3333-3333-3333-333333333333'),
      'FALLA: un intento denegado quedó registrado como consulta';
  end $$;
rollback;
\echo '  ✔  2. El jefe operativo NO la ve: manda la matriz de roles aprobada'

-- =====================================================================
--  3. El de la OTRA sede no, por mucho que pregunte
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','44444444-4444-4444-4444-444444444444',
                      'app_role','supervisor','branch_id', :'css')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.evidencia_de(current_setting('t.ev')::uuid);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe de otra sede vio la fotografía';
  end $$;

  -- Y el intento fallido no ensucia el registro con un falso «la miró»
  do $$ begin
    assert not exists (select 1 from audit_logs
                        where action = 'evidencia.consultada'
                          and actor_id = '44444444-4444-4444-4444-444444444444'),
      'FALLA: un intento denegado quedó registrado como consulta';
  end $$;
rollback;
\echo '  ✔  3. La sede ajena no la ve, y su intento no figura como consulta'

-- =====================================================================
--  4. Los tres «no hay foto» se distinguen entre sí
-- =====================================================================
-- Los estados se cambian FUERA del rol de usuario, a propósito: la tabla
-- de marcaciones es inmutable y ni el CEO puede tocarla —ésa es la
-- garantía de la Fase 1—. Que esta prueba tuviera que salirse del rol
-- para montar el escenario confirma que esa puerta sigue cerrada.
begin;
  update attendance_events set evidence_status = 'sin_evidencia'
   where id = current_setting('t.ev')::uuid;

  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.evidencia_de(current_setting('t.ev')::uuid);
    assert (r->>'reason') = 'SIN_EVIDENCIA', 'FALLA: no distingue «se marcó sin foto»';
  end $$;
  reset role;

  update attendance_events set evidence_status = 'purgada'
   where id = current_setting('t.ev')::uuid;

  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.evidencia_de(current_setting('t.ev')::uuid);
    assert (r->>'reason') = 'PURGADA', 'FALLA: no distingue «ya se borró por antigüedad»';

    -- Y ninguno de los dos casos deja rastro de consulta: no se miró nada
    assert not exists (select 1 from audit_logs where action = 'evidencia.consultada'),
      'FALLA: se registró una consulta cuando no había nada que enseñar';
  end $$;
rollback;
\echo '  ✔  4. «Sin foto», «purgada» y «pendiente» se distinguen, y no cuentan como consulta'

-- =====================================================================
--  5. El panel no puede saltarse la puerta
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.edge_evidencia_ruta(current_setting('t.ev')::uuid);
    exception when insufficient_privilege then ok := true;
              when others then ok := (sqlerrm like '%permission denied%'); end;
    assert ok, 'FALLA GRAVE: el CEO obtuvo la ruta saltándose el registro';
  end $$;
rollback;
\echo '  ✔  5. Ni el CEO obtiene la ruta por la puerta de atrás'

-- =====================================================================
--  6. El tablero del día entrega el identificador de la marcación
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare t jsonb; e jsonb; begin
    t := public.tablero_hoy(current_setting('t.mcb')::uuid);
    select f into e from jsonb_array_elements(t->'empleados') f where f->>'nombre' = 'Ana Pérez';
    assert e ? 'evento_id', 'FALLA: sin el identificador el panel no puede pedir la foto';
    assert (e->>'evento_id') = current_setting('t.ev'), 'FALLA: identificador equivocado';
  end $$;
rollback;
\echo '  ✔  6. El tablero entrega el identificador para poder abrir la fotografía'

\echo ''
\echo '  ✔  BATERÍA DE EVIDENCIA COMPLETA — todas las validaciones pasaron'
\echo ''
