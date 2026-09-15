-- =====================================================================
-- CREDISAN CONTROL · Batería de la Fase 3 (terminal y marcación)
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

insert into auth.users (id, email) values ('11111111-1111-1111-1111-111111111111','ceo@credisan.test');
insert into profiles (id, full_name, role) values ('11111111-1111-1111-1111-111111111111','Dirección','ceo');
insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
select id, 'MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15' from branches where code='MCB';

-- Jornada anclada a la hora actual: la clasificación sale determinista
-- corra la prueba a la hora que corra.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', d, d, true, false,
       (t - interval '2 minutes')::time, (t + interval '5 minutes')::time,
       (t + interval '10 minutes')::time, (t + interval '15 minutes')::time, 'prueba'
from employees e, (select (now() at time zone 'America/Caracas')::date d,
                          (now() at time zone 'America/Caracas')::time t) x
where e.internal_code = 'MCB-001';

do $$
declare
  v_emp uuid; v_term uuid; v_code text; r jsonb; v_token text; v_tid uuid;
  v_ticket uuid; v_pin text; v_ev uuid;
begin
  select id into v_emp  from employees where internal_code = 'MCB-001';
  select id into v_term from terminals where code = 'MCB-01';

  -- 1 · El PIN se genera por la vía de la Edge Function y sale una sola vez
  r := public.edge_asignar_pin(v_emp, 'PEPPER-PRUEBA', '11111111-1111-1111-1111-111111111111');
  assert (r->>'ok')::boolean, 'FALLA: no asignó PIN';
  v_pin := r->>'pin';
  assert v_pin ~ '^[0-9]{6}$', 'FALLA: PIN mal formado: ' || v_pin;
  assert (select pin_hash from employees where id = v_emp) not like '%' || v_pin || '%',
         'FALLA GRAVE: el PIN aparece dentro del hash';

  -- 2 · Emparejamiento del dispositivo
  v_code := app.create_pairing_code(v_term, '11111111-1111-1111-1111-111111111111');
  r := public.edge_emparejar('MCB-01', v_code, 'Android de prueba');
  assert (r->>'ok')::boolean and length(r->>'token') > 20, 'FALLA: emparejamiento';
  v_token := r->>'token';

  -- 3 · Sólo el token verdadero autentica
  v_tid := public.edge_autenticar_terminal('MCB-01', v_token);
  assert v_tid = v_term, 'FALLA: el token válido no autenticó';
  assert public.edge_autenticar_terminal('MCB-01', 'token-falso') is null,
         'FALLA GRAVE: un token falso autenticó';

  -- 4 · PIN incorrecto: informa, frena y deja rastro (no lanza excepción,
  --     que revertiría el propio registro del intento)
  r := public.edge_verificar_pin(v_tid, '000001', 'PEPPER-PRUEBA');
  assert not (r->>'ok')::boolean and r->>'motivo' = 'PIN_INVALIDO', 'FALLA: PIN falso aceptado';
  assert (r->>'espera_ms')::int > 0, 'FALLA: sin espera progresiva';
  assert (select count(*) from pin_attempts where success = false) = 1,
         'FALLA: el intento fallido no quedó registrado';

  -- 5 · PIN correcto: identidad y evento esperado
  r := public.edge_verificar_pin(v_tid, v_pin, 'PEPPER-PRUEBA');
  assert (r->>'ok')::boolean, 'FALLA: PIN correcto rechazado: ' || (r->>'motivo');
  assert r->>'nombre' = 'Ana Pérez', 'FALLA: identidad incorrecta';
  assert r->>'evento' = 'entrada', 'FALLA: evento esperado incorrecto';
  v_ticket := (r->>'ticket')::uuid;

  -- 6 · La marcación la clasifica el servidor
  r := public.edge_registrar(v_ticket, gen_random_uuid());
  assert r->>'status' = 'puntual', 'FALLA: clasificación: ' || (r->>'status');
  v_ev := (r->>'id')::uuid;

  -- 7 · Evidencia, con su fecha de purga
  r := public.edge_evidencia(v_ev, 'ruta/prueba.jpg', 45000, now());
  assert (r->>'ok')::boolean, 'FALLA: evidencia';
  assert (select evidence_status from attendance_events where id = v_ev) = 'almacenada',
         'FALLA: la marcación no quedó con evidencia';
  assert (select expires_on from attendance_evidence where event_id = v_ev) = current_date + 180,
         'FALLA: retención distinta de 180 días';

  -- 8 · Desemparejar invalida el token en el acto, sin tocar el historial
  perform set_config('request.jwt.claims',
    '{"sub":"11111111-1111-1111-1111-111111111111","app_role":"ceo"}', true);
  r := public.desemparejar_terminal(v_term);
  assert (r->>'ok')::boolean, 'FALLA: desemparejar';
  assert public.edge_autenticar_terminal('MCB-01', v_token) is null,
         'FALLA GRAVE: el token sigue sirviendo tras desemparejar';
  assert (select count(*) from attendance_events where terminal_id = v_term) = 1,
         'FALLA: desemparejar borró marcaciones';

  assert jsonb_array_length(public.terminales()) = 3, 'FALLA: terminales()';
  assert jsonb_array_length((public.marcaciones_hoy(
           (select id from branches where code='MCB')))->'marcaciones') = 1,
         'FALLA: marcaciones_hoy';
end $$;

-- 9 · El panel no puede saltarse la Edge Function
begin;
  select set_config('request.jwt.claims','{"sub":"11111111-1111-1111-1111-111111111111","app_role":"ceo"}',true);
  set local role authenticated;
  do $$ declare ok boolean; begin
    ok := false;
    begin perform public.edge_asignar_pin(gen_random_uuid(), 'x', null);
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: el panel puede generar PIN sin pasar por la Edge Function';

    ok := false;
    begin perform public.edge_verificar_pin(gen_random_uuid(), '1', 'x');
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: el panel puede verificar PIN directamente';
  end $$;
rollback;

\echo ''
\echo '  ✔  BATERÍA DE FASE 3 COMPLETA — todas las validaciones pasaron'
\echo ''
