-- =====================================================================
-- CREDISAN CONTROL · Batería de validación de la Fase 1
-- Verifica lo que no se puede comprobar leyendo: RLS por sede, aislamiento
-- del salario, inmutabilidad de las marcaciones, PIN, idempotencia,
-- ventana offline y auditoría.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
\pset pager off
set client_min_messages = warning;

-- ── Datos de prueba ──────────────────────────────────────────────────
select id as mcb from branches where code = 'MCB' \gset
select id as css from branches where code = 'CSS' \gset
select id as term_mcb from terminals where code = 'MCB-01' \gset
select id as term_css from terminals where code = 'CSS-01' \gset

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111', 'ceo@credisan.test'),
  ('22222222-2222-2222-2222-222222222222', 'admin.mcb@credisan.test'),
  ('33333333-3333-3333-3333-333333333333', 'jefe.mcb@credisan.test'),
  ('44444444-4444-4444-4444-444444444444', 'admin.css@credisan.test');

insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111', 'Dirección General', 'ceo',        null),
  ('22222222-2222-2222-2222-222222222222', 'Admin Maracaibo',   'admin',      :'mcb'),
  ('33333333-3333-3333-3333-333333333333', 'Jefe Maracaibo',    'supervisor', :'mcb'),
  ('44444444-4444-4444-4444-444444444444', 'Admin Caja Seca',   'admin',      :'css');

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb', 'MCB-001', 'Ana',  'Pérez',  'V-12345678', 'Cajera',    '2026-01-15'),
       (:'css', 'CSS-001', 'Luis', 'Rojas',  'V-87654321', 'Asesor',    '2026-02-01');

select id as emp_mcb from employees where internal_code = 'MCB-001' \gset
select id as emp_css from employees where internal_code = 'CSS-001' \gset
select set_config('credisan.mcb', :'mcb', false), set_config('credisan.css', :'css', false),
       set_config('credisan.emp_mcb', :'emp_mcb', false), set_config('credisan.emp_css', :'emp_css', false);

insert into employee_compensation (employee_id, weekly_base, effective_from)
values (:'emp_mcb', 120.00, '2026-01-15');

-- Jornada especial de HOY, anclada a la hora actual: la clasificación
-- resultante es determinista sin depender de cuándo se corra la prueba.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to,
        is_working, is_continuous, entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', :'emp_mcb', 'jornada_especial', d, d, true, false,
       (t - interval '3 minutes')::time, (t + interval '5 minutes')::time,
       (t + interval '10 minutes')::time, (t + interval '15 minutes')::time,
       'Jornada de prueba anclada a la hora actual'
from (select (now() at time zone 'America/Caracas')::date as d,
             (now() at time zone 'America/Caracas')::time as t) x;

-- ── 1. PIN ───────────────────────────────────────────────────────────
select app.set_employee_pin(:'emp_mcb', '481902', 'PEPPER-DE-PRUEBA', null);
select app.set_employee_pin(:'emp_css', '750341', 'PEPPER-DE-PRUEBA', null);

do $$ begin
  assert (select pin_hash   from employees where internal_code = 'MCB-001') not like '%481902%',
         'FALLA: el PIN aparece en el hash';
  assert (select pin_lookup from employees where internal_code = 'MCB-001') is not null,
         'FALLA: no se generó el índice ciego';
end $$;

-- PIN débil rechazado
do $$ declare ok boolean := false; begin
  begin
    perform app.set_employee_pin((select id from employees where internal_code='MCB-001'),
                                 '123456', 'PEPPER-DE-PRUEBA', null);
  exception when others then ok := (sqlerrm like '%PIN_DEBIL%'); end;
  assert ok, 'FALLA: se aceptó un PIN trivial';
end $$;

-- PIN repetido rechazado (unicidad garantizada por el índice ciego)
do $$ declare ok boolean := false; begin
  begin
    perform app.set_employee_pin((select id from employees where internal_code='CSS-001'),
                                 '481902', 'PEPPER-DE-PRUEBA', null);
  exception when others then ok := (sqlerrm like '%PIN_EN_USO%'); end;
  assert ok, 'FALLA: dos trabajadores pudieron compartir PIN';
end $$;

-- Pepper distinto = PIN inexistente (el pepper no está en la base de datos)
do $$ declare r record; begin
  select * into r from app.verify_pin((select id from terminals where code='MCB-01'),
                                      '481902', 'PEPPER-EQUIVOCADO');
  assert not r.ok and r.reason = 'PIN_INVALIDO', 'FALLA: el PIN se validó sin el pepper correcto';
  assert r.delay_ms > 0, 'FALLA: no se aplicó espera progresiva';
end $$;

-- ── 2. Emparejamiento del terminal ───────────────────────────────────
select app.create_pairing_code(:'term_mcb', '22222222-2222-2222-2222-222222222222') as pcode \gset
select set_config('credisan.pcode', :'pcode', false);
select device_token as dtoken from app.redeem_pairing_code('MCB-01', :'pcode', 'Android de prueba') \gset
select set_config('credisan.dtoken', :'dtoken', false);

do $$ begin
  assert (select token_hash from terminals where code = 'MCB-01') is not null, 'FALLA: terminal sin token';
  assert (select used_at from terminal_pairing_codes order by created_at desc limit 1) is not null,
         'FALLA: el código no quedó marcado como usado';
end $$;

-- El código es de un solo uso
do $$ declare ok boolean := false; begin
  begin perform app.redeem_pairing_code('MCB-01', current_setting('credisan.pcode'), 'Otro dispositivo');
  exception when others then ok := true; end;
  assert ok, 'FALLA: el código de emparejamiento se reutilizó';
end $$;

do $$ begin
  assert app.authenticate_terminal('MCB-01', current_setting('credisan.dtoken')) is not null,
         'FALLA: el token válido no autenticó';
  assert app.authenticate_terminal('MCB-01', 'token-falso') is null,
         'FALLA: un token falso autenticó';
end $$;

-- La sede de un terminal es inmutable
do $$ declare ok boolean := false; begin
  begin update terminals set branch_id = (select id from branches where code='CSS') where code='MCB-01';
  exception when others then ok := (sqlerrm like '%inmutable%'); end;
  assert ok, 'FALLA: se pudo mover un terminal de sede';
end $$;

-- ── 3. Marcación ─────────────────────────────────────────────────────
select ticket as tk1, event as ev1, ok as ok1 from app.verify_pin(:'term_mcb', '481902', 'PEPPER-DE-PRUEBA') \gset
select set_config('credisan.tk1', :'tk1', false), set_config('credisan.ev1', :'ev1', false);
do $$ begin
  assert current_setting('credisan.ev1') = 'entrada', 'FALLA: el evento esperado no es la entrada';
end $$;

select (app.register_punch(:'tk1', '99999999-0000-0000-0000-000000000001'::uuid)) as punch1 \gset
select set_config('credisan.punch1', :'punch1', false);
do $$
declare j jsonb := current_setting('credisan.punch1')::jsonb;
begin
  assert j ->> 'status' = 'puntual',    'FALLA: clasificación incorrecta: ' || (j ->> 'status');
  assert (j ->> 'delta_minutes')::int between 2 and 4, 'FALLA: delta inesperado: ' || (j ->> 'delta_minutes');
  assert (j ->> 'duplicado')::boolean = false, 'FALLA: marcó como duplicado';
end $$;

-- Idempotencia: el mismo client_event_id no crea una segunda marcación
select ticket as tk2 from app.verify_pin(:'term_mcb', '481902', 'PEPPER-DE-PRUEBA') \gset
select (app.register_punch(:'tk2', '99999999-0000-0000-0000-000000000001'::uuid)) as punch2 \gset
select set_config('credisan.punch2', :'punch2', false);
do $$ begin
  assert (current_setting('credisan.punch2')::jsonb ->> 'duplicado')::boolean,
         'FALLA: el reintento no se reconoció como duplicado';
  assert (select count(*) from attendance_events) = 1, 'FALLA: se duplicó la marcación';
end $$;

-- Doble marcación del mismo evento del día
do $$ declare ok boolean := false; begin
  begin
    perform app._insert_punch(
      current_setting('credisan.emp_mcb')::uuid,
      (select id from terminals where code = 'MCB-01'),
      now(), 'online', gen_random_uuid(), 'entrada', now(), true);
  exception when others then ok := (sqlerrm like '%YA_REGISTRADO%'); end;
  assert ok, 'FALLA: se aceptó una segunda ENTRADA el mismo día';
end $$;

-- El ticket es de un solo uso
do $$ declare ok boolean := false; begin
  begin perform app.register_punch(current_setting('credisan.tk1')::uuid, gen_random_uuid());
  exception when others then ok := (sqlerrm like '%TICKET_YA_USADO%'); end;
  assert ok, 'FALLA: el ticket se reutilizó';
end $$;

-- Un trabajador no marca en una sede que no es la suya
do $$ declare r record; begin
  select * into r from app.verify_pin((select id from terminals where code='MCB-01'),
                                      '750341', 'PEPPER-DE-PRUEBA');
  assert not r.ok and r.reason = 'EMPLEADO_OTRA_SEDE', 'FALLA: se permitió marcar en otra sede';
  assert (select count(*) from incidents where kind = 'marcacion_foranea') = 1,
         'FALLA: no se abrió la novedad de sede ajena (¿la excepción revirtió el rastro?)';
end $$;

-- ── 4. Evidencia ─────────────────────────────────────────────────────
select id as ev_id from attendance_events limit 1 \gset
select set_config('credisan.ev_id', :'ev_id', false);
select app.mark_missing_evidence(:'ev_id', 'permiso_denegado');
do $$ begin
  assert (select evidence_status from attendance_events where id = current_setting('credisan.ev_id')::uuid)
         = 'sin_evidencia', 'FALLA: no se marcó la falta de evidencia';
  assert (select count(*) from incidents where kind = 'sin_evidencia') = 1,
         'FALLA: no se abrió la novedad automática por falta de evidencia';
end $$;

-- ── 5. Offline ───────────────────────────────────────────────────────
do $$
declare t uuid; e uuid; r jsonb;
begin
  select id into t from terminals where code = 'MCB-01';
  select id into e from employees where internal_code = 'MCB-001';

  -- Nada del futuro
  r := app.register_offline_punch(t, e, gen_random_uuid(), now() + interval '30 minutes', 1, 0);
  assert r ->> 'reason' = 'HORA_FUTURA', 'FALLA: se aceptó una marcación offline con hora futura';

  -- Nada más viejo que la ventana permitida
  r := app.register_offline_punch(t, e, gen_random_uuid(), now() - interval '96 hours', 2, 0);
  assert r ->> 'reason' = 'FUERA_DE_VENTANA_OFFLINE', 'FALLA: se aceptó una marcación offline vieja';

  -- La secuencia no retrocede (antirreenvío)
  update terminals set last_seq = 10 where code = 'MCB-01';
  r := app.register_offline_punch(t, e, gen_random_uuid(), now() - interval '5 minutes', 5, 0);
  assert r ->> 'reason' = 'SECUENCIA_INVALIDA', 'FALLA: se aceptó una secuencia repetida o menor';

  -- Un empleado de otra sede tampoco entra por la puerta de atrás
  r := app.register_offline_punch(t, (select id from employees where internal_code='CSS-001'),
                                  gen_random_uuid(), now() - interval '5 minutes', 11, 0);
  assert r ->> 'reason' = 'EMPLEADO_OTRA_SEDE', 'FALLA: sincronizó una marcación de otra sede';

  -- Cada rechazo deja rastro (no se revierte al rechazar)
  assert (select count(*) from audit_logs where action = 'offline.rechazado') = 4,
         'FALLA: los rechazos offline no quedaron auditados';
end $$;

-- ── 6. Resumen diario ────────────────────────────────────────────────
select app.recompute_daily(:'emp_mcb', (now() at time zone 'America/Caracas')::date);
do $$ declare d record; begin
  select * into d from attendance_daily
   where employee_id = current_setting('credisan.emp_mcb')::uuid;
  assert d.expected_events = 4,  'FALLA: eventos esperados: ' || d.expected_events;
  assert d.registered_events = 1,'FALLA: eventos registrados: ' || d.registered_events;
  assert d.status = 'en_curso',  'FALLA: estado del día: ' || d.status;
  assert d.missing_evidence = 1, 'FALLA: no contó la marcación sin evidencia';
end $$;

-- El sábado inactivo NO es ausencia
do $$ declare d record; v_sab date; begin
  v_sab := (date_trunc('week', current_date) + interval '5 days')::date;   -- sábado de esta semana
  perform app.recompute_daily(current_setting('credisan.emp_css')::uuid, v_sab);
  select * into d from attendance_daily
   where employee_id = current_setting('credisan.emp_css')::uuid and work_date = v_sab;
  assert d.status = 'descanso', 'FALLA: un sábado inactivo se contó como ' || d.status;
  assert d.expected_minutes = 0, 'FALLA: un sábado inactivo espera horas';
end $$;

-- ── 7. RLS: jefe operativo ───────────────────────────────────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','supervisor','branch_id', :'mcb')::text, true);
  set local role authenticated;

  do $$ begin
    assert (select count(*) from employee_compensation) = 0,
           'FALLA GRAVE: el jefe operativo vio el salario';
    assert (select count(*) from employees) = 1,
           'FALLA: el jefe operativo ve empleados de otra sede';
    assert (select count(*) from employees where branch_id = current_setting('credisan.css')::uuid) = 0,
           'FALLA: fuga de datos entre sedes';
    assert (select count(*) from audit_logs) = 0,
           'FALLA: el jefe operativo accedió a la auditoría';
    assert (select count(*) from weekly_closures) = 0,
           'FALLA: el jefe operativo accedió a los cierres';
    assert (select count(*) from attendance_evidence) = 0,
           'FALLA: el jefe operativo accedió a la evidencia';
    assert (select count(*) from pin_attempts) = 0,
           'FALLA: el jefe operativo accedió a los intentos de PIN';
    assert (select count(*) from attendance_events) = 1,
           'FALLA: el jefe operativo no ve las marcaciones de su sede';
  end $$;

  -- Puede reportar una novedad, sólo pendiente y a su nombre
  insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date, reported_by)
  values (current_setting('credisan.emp_mcb')::uuid, current_setting('credisan.mcb')::uuid,
          'permiso', 'Permiso de prueba reportado por el jefe', now(), current_date,
          '33333333-3333-3333-3333-333333333333');

  do $$ declare ok boolean := false; begin
    begin
      insert into incidents (employee_id, branch_id, kind, description, occurred_from, work_date,
                             reported_by, status, reviewed_by, reviewed_at)
      values (current_setting('credisan.emp_mcb')::uuid, current_setting('credisan.mcb')::uuid,
              'permiso', 'Intento de autoaprobación', now(), current_date,
              '33333333-3333-3333-3333-333333333333', 'aprobada',
              '33333333-3333-3333-3333-333333333333', now());
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: el jefe operativo aprobó su propia novedad';
  end $$;

  -- No puede resolver novedades
  do $$ declare n int; begin
    update incidents set status = 'aprobada',
           reviewed_by = '33333333-3333-3333-3333-333333333333', reviewed_at = now()
     where kind = 'permiso';
    get diagnostics n = row_count;
    assert n = 0, 'FALLA GRAVE: el jefe operativo aprobó una novedad';
  exception when insufficient_privilege then null;
  end $$;
rollback;

-- ── 8. RLS: administradora de otra sede ──────────────────────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','44444444-4444-4444-4444-444444444444',
                      'app_role','admin','branch_id', :'css')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employees) = 1, 'FALLA: alcance de sede incorrecto';
    assert (select count(*) from employees where internal_code = 'CSS-001') = 1, 'FALLA: no ve su propia sede';
    assert (select count(*) from employee_compensation) = 0,
           'FALLA GRAVE: vio el salario de un empleado de otra sede';
    assert (select count(*) from attendance_events) = 0,
           'FALLA GRAVE: vio marcaciones de otra sede';
  end $$;

  -- No puede llevarse un trabajador a su sede
  do $$ declare n int := 0; ok boolean := false; begin
    begin
      update employees set branch_id = current_setting('credisan.css')::uuid
       where internal_code = 'MCB-001';
      get diagnostics n = row_count;
      ok := (n = 0);
    exception when insufficient_privilege then ok := true;   -- ni siquiera tiene la columna
    end;
    assert ok, 'FALLA GRAVE: una administradora movió un trabajador de sede';
  end $$;
rollback;

-- ── 9. RLS: administradora de su sede ────────────────────────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employee_compensation) = 1, 'FALLA: no ve el salario de su sede';
    assert (select count(*) from audit_logs where branch_id = current_setting('credisan.css')::uuid) = 0,
           'FALLA: ve auditoría de otra sede';
  end $$;

  -- El PIN no se toca desde la API, ni siendo administradora
  do $$ declare ok boolean := false; begin
    begin update employees set pin_hash = 'lo-que-sea' where internal_code = 'MCB-001';
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: se pudo escribir el PIN por la API';
  end $$;
rollback;

-- ── 10. RLS: CEO y la inmutabilidad de las marcaciones ───────────────
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111',
                      'app_role','ceo')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employees) = 2, 'FALLA: el CEO no ve las tres sedes';
    assert (select count(*) from employee_compensation) = 1, 'FALLA: el CEO no ve la nómina';
  end $$;

  do $$ declare ok boolean := false; begin
    begin update attendance_events set recorded_at = now() - interval '2 hours';
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: el CEO pudo reescribir una marcación';
  end $$;

  do $$ declare ok boolean := false; begin
    begin delete from attendance_events;
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: se pudo borrar una marcación';
  end $$;

  do $$ declare ok boolean := false; begin
    begin delete from audit_logs;
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: se pudo borrar la auditoría';
  end $$;

  -- El token del terminal no se expone ni al CEO
  do $$ declare ok boolean := false; begin
    begin perform token_hash from terminals limit 1;
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: el token del terminal es legible por la API';
  end $$;
rollback;

-- ── 11. RLS: anónimo ─────────────────────────────────────────────────
begin;
  select set_config('request.jwt.claims', '', true);
  set local role anon;
  do $$ declare ok boolean := false; begin
    begin perform count(*) from employees;
    exception when insufficient_privilege then ok := true; end;
    assert ok, 'FALLA GRAVE: un anónimo leyó la tabla de empleados';
  end $$;
rollback;

-- ── 12. Storage ──────────────────────────────────────────────────────
insert into storage.objects (bucket_id, name)
values ('evidencia', (select id::text from branches where code='MCB') || '/2026/09/09/prueba.jpg'),
       ('empleados', (select id::text from branches where code='MCB') || '/foto.jpg'),
       ('empleados', (select id::text from branches where code='CSS') || '/foto.jpg');

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from storage.objects where bucket_id = 'evidencia') = 0,
           'FALLA GRAVE: la evidencia es accesible directamente desde Storage';
    assert (select count(*) from storage.objects where bucket_id = 'empleados') = 1,
           'FALLA: fuga de fotos entre sedes';
  end $$;
rollback;

-- ── 13. Auditoría ────────────────────────────────────────────────────
do $$ begin
  assert (select count(*) from audit_logs where action = 'employees.insert') = 2,
         'FALLA: no se auditó el alta de trabajadores';
  assert (select count(*) from audit_logs where action = 'employee.pin_regenerado') >= 2,
         'FALLA: no se auditó la asignación de PIN';
  assert (select count(*) from audit_logs where action = 'terminal.emparejado') = 1,
         'FALLA: no se auditó el emparejamiento';
  assert (select count(*) from audit_logs where after ? 'pin_hash' or after ? 'token_hash') = 0,
         'FALLA GRAVE: la auditoría copió secretos';
end $$;

-- ── 14. Intentos de PIN ──────────────────────────────────────────────
do $$ begin
  assert (select count(*) from pin_attempts where success = false) >= 1,
         'FALLA: no se registraron los intentos fallidos';
  assert (select count(*) from pin_attempts where success = true) >= 1,
         'FALLA: no se registraron los intentos correctos';
end $$;

-- ── 15. Superficie de ejecución ──────────────────────────────────────
-- Un usuario del panel no puede invocar el motor: ni verificar PIN, ni
-- registrar marcaciones, ni asignar claves.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$
  declare fn text; ok boolean;
  begin
    foreach fn in array array[
      'select app.verify_pin(gen_random_uuid(), ''481902'', ''x'')',
      'select app.register_punch(gen_random_uuid(), gen_random_uuid())',
      'select app.set_employee_pin(gen_random_uuid(), ''481902'', ''x'', null)',
      'select app.generate_pin()',
      'select app.authenticate_terminal(''MCB-01'', ''x'')',
      'select app.redeem_pairing_code(''MCB-01'', ''x'', ''y'')',
      'select app.attach_evidence(gen_random_uuid(), ''r'', null, ''image/jpeg'', null)',
      'select app.recompute_daily_branch(gen_random_uuid(), current_date)'
    ] loop
      ok := false;
      begin execute fn;
      exception
        when insufficient_privilege then ok := true;
        when others then ok := false;
      end;
      assert ok, 'FALLA GRAVE: el panel puede ejecutar ' || fn;
    end loop;
  end $$;
rollback;

\echo ''
\echo '  ✔  BATERÍA DE FASE 1 COMPLETA — todas las validaciones pasaron'
\echo ''
