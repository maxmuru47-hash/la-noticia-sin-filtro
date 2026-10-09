-- =====================================================================
-- CREDISAN CONTROL · Las cuatro horas, y quién puede verlas
-- =====================================================================
-- El panel pasó a enseñar las CUATRO horas de cada jornada: entrada,
-- salida a almuerzo, regreso del almuerzo y salida. Y a enseñárselas
-- también al jefe operativo y al socio, que antes sólo veían dos.
--
-- Ampliar lo que alguien ve es justo donde se cometen las fugas. Esta
-- batería no comprueba lo que el panel pinta —eso lo hace
-- `pruebas/navegador/panel-horas-test.js`— sino lo único que de verdad
-- protege los datos: qué entrega la BASE a cada acceso, pregunte como
-- pregunte, con la consulta EXACTA que ahora hace el panel.
--
-- Lo que se exige:
--   1. Las cuatro marcaciones quedan guardadas, con sus cuatro horas.
--   2. El jefe operativo las ve TODAS, de su sede.
--   3. De otra sede no ve ni una.
--   4. Un socio ve las sedes que se le asignaron, y sólo esas.
--   5. Ver las horas NO abre la puerta a la fotografía ni al salario.
--   6. El historial por rango de fechas respeta el rango y la sede.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as css from branches where code = 'CSS' \gset
select id as mcb from branches where code = 'MCB' \gset
select set_config('t.css', :'css', false), set_config('t.mcb', :'mcb', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','direccion@credisan.test'),
  ('44444444-4444-4444-4444-444444444444','jefe.css@credisan.test'),
  ('33333333-3333-3333-3333-333333333333','admin.css@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-0000000000c1','socio.css@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-0000000000d1','socio.mcb@credisan.test');

insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección General','ceo', null),
  ('44444444-4444-4444-4444-444444444444','Jefe Caja Seca','supervisor', :'css'),
  ('33333333-3333-3333-3333-333333333333','Admin Caja Seca','admin', :'css');

-- Dos trabajadores en sedes distintas: uno para mirar, otro para que NO
-- se vea desde la sede de al lado.
insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'css','CSS-801','Ana','Jornada','V-801','Cajera','2026-01-15'),
       (:'mcb','MCB-801','Beto','Ajeno',  'V-802','Asesor','2026-01-15');

select id as e1 from employees where internal_code='CSS-801' \gset
select id as e2 from employees where internal_code='MCB-801' \gset
select set_config('t.e1', :'e1', false), set_config('t.e2', :'e2', false);

insert into employee_compensation (employee_id, weekly_base, effective_from)
select id, 60, '2026-01-15' from employees where internal_code = 'CSS-801';

-- La jornada de la prueba: 08:00–12:00 / 14:00–18:00, forzada con una
-- excepción de empleado para no depender del día de la semana en que se
-- ejecute la batería.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', current_date - 1, current_date,
       true, false, '08:00', '12:00', '14:00', '18:00', 'jornada de la prueba'
  from employees e where e.internal_code in ('CSS-801','MCB-801');

select id as tcss from terminals where code = 'CSS-01' \gset
select id as tmcb from terminals where code = 'MCB-01' \gset
select set_config('t.tcss', :'tcss', false), set_config('t.tmcb', :'tmcb', false);

-- Se marca por el MISMO camino que el terminal: el motor decide qué
-- marcación toca y la escribe. Una prueba que imita el código prueba la
-- imitación.
create or replace function pg_temp.marcar(p_emp uuid, p_term uuid, p_dia date, p_hora time)
returns text language plpgsql as $$
declare v record; v_momento timestamptz; r jsonb;
begin
  v_momento := (p_dia + p_hora) at time zone 'America/Caracas';
  select * into v from app.next_expected(p_emp, v_momento);
  if not found then return 'SIN_EVENTO_PENDIENTE'; end if;
  r := app._insert_punch(p_emp, p_term, v_momento, 'online',
                         gen_random_uuid(), v.event, v.expected_at, v.is_arrival);
  return r->>'event';
end $$;

-- =====================================================================
--  1. Las cuatro marcaciones, con sus cuatro horas
-- =====================================================================
do $$ begin
  assert pg_temp.marcar(current_setting('t.e1')::uuid, current_setting('t.tcss')::uuid,
                        current_date, '08:00') = 'entrada', 'FALLA: la entrada';
  assert pg_temp.marcar(current_setting('t.e1')::uuid, current_setting('t.tcss')::uuid,
                        current_date, '12:35') = 'salida_almuerzo', 'FALLA: la salida a almorzar';
  assert pg_temp.marcar(current_setting('t.e1')::uuid, current_setting('t.tcss')::uuid,
                        current_date, '14:22') = 'regreso_almuerzo', 'FALLA: el regreso del almuerzo';
  assert pg_temp.marcar(current_setting('t.e1')::uuid, current_setting('t.tcss')::uuid,
                        current_date, '18:07') = 'salida', 'FALLA: la salida';

  -- Y un día antes, para que el historial tenga de dónde tirar.
  assert pg_temp.marcar(current_setting('t.e1')::uuid, current_setting('t.tcss')::uuid,
                        current_date - 1, '08:00') = 'entrada', 'FALLA: la entrada de ayer';

  -- El de la otra sede también marca: si luego se ve desde Caja Seca,
  -- es que la política no filtra.
  assert pg_temp.marcar(current_setting('t.e2')::uuid, current_setting('t.tmcb')::uuid,
                        current_date, '08:00') = 'entrada', 'FALLA: la entrada del de la otra sede';
end $$;

do $$ declare v text; begin
  select string_agg(to_char(recorded_at at time zone 'America/Caracas','HH24:MI'), ' ' order by recorded_at)
    into v from attendance_events
   where employee_id = current_setting('t.e1')::uuid and work_date = current_date;
  assert v = '08:00 12:35 14:22 18:07',
    'FALLA: las cuatro horas guardadas no son las que se marcaron: «' || coalesce(v,'(nada)') || '»';

  -- Los minutos los midió el motor contra el horario de esa persona.
  -- El panel los enseña tal cual: si aquí no estuvieran, allí tampoco.
  assert (select delta_minutes from attendance_events
           where employee_id = current_setting('t.e1')::uuid
             and work_date = current_date and event = 'salida_almuerzo') = 35,
    'FALLA: los 35 minutos de retraso en salir a almorzar no quedaron medidos';

  -- Y AQUÍ ESTÁ LA REGLA, que conviene no confundir al leer el panel:
  -- irse TARDE a almorzar no es una falta —se trabajó más antes de ir—,
  -- pero VOLVER tarde sí. El motor lo sabe; el panel sólo pinta su
  -- veredicto, y por eso la casilla del almuerzo largo no se marca en
  -- ámbar y la del regreso sí. Si alguna vez se decide que un almuerzo
  -- de hora y media también es falta, se cambia aquí, en el motor, y el
  -- panel obedece sin tocar una línea.
  assert (select status from attendance_events
           where employee_id = current_setting('t.e1')::uuid
             and work_date = current_date and event = 'salida_almuerzo')::text = 'puntual',
    'CAMBIÓ LA REGLA: salir tarde a almorzar pasó a contar como falta';
  assert (select delta_minutes from attendance_events
           where employee_id = current_setting('t.e1')::uuid
             and work_date = current_date and event = 'regreso_almuerzo') = 22,
    'FALLA: los 22 minutos de tardanza en volver no quedaron medidos';
  assert (select status from attendance_events
           where employee_id = current_setting('t.e1')::uuid
             and work_date = current_date and event = 'regreso_almuerzo')::text = 'retraso',
    'FALLA GRAVE: volver 22 minutos tarde del almuerzo no quedó como retraso';
end $$;

-- =====================================================================
--  2. El jefe operativo ve las CUATRO horas de su sede
-- =====================================================================
-- Ésta es la consulta exacta que hace el panel para pintar un día.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','44444444-4444-4444-4444-444444444444',
                      'app_role','supervisor','branch_id', current_setting('t.css'))::text, true);
  set local role authenticated;

  do $$ declare v text; begin
    select string_agg(event::text, ',' order by recorded_at) into v
      from attendance_events
     where branch_id = current_setting('t.css')::uuid and work_date = current_date;
    assert v = 'entrada,salida_almuerzo,regreso_almuerzo,salida',
      'FALLA GRAVE: el jefe operativo no ve las cuatro marcaciones de su sede: «'
      || coalesce(v,'(nada)') || '»';

    -- 3. De la otra sede, ni una.
    assert (select count(*) from attendance_events
             where branch_id = current_setting('t.mcb')::uuid) = 0,
      'FUGA: el jefe operativo de Caja Seca ve marcaciones de Maracaibo';
    assert (select count(*) from attendance_events
             where employee_id = current_setting('t.e2')::uuid) = 0,
      'FUGA: preguntando por el trabajador, se cuela la marcación de otra sede';

    -- 5. Ver horas no es ver caras, ni sueldos.
    assert (select count(*) from attendance_evidence) = 0,
      'FUGA: el jefe operativo alcanza la evidencia fotográfica';
    assert (select count(*) from employee_compensation) = 0,
      'FUGA: el jefe operativo alcanza el salario';
  end $$;
rollback;

-- =====================================================================
--  4. El socio ve las sedes que se le asignaron, y sólo esas
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.guardar_socio('socio.css@credisan.test', 'Socio de Caja Seca',
           array[current_setting('t.css')::uuid]);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo crear el socio de Caja Seca: ' || r::text;
    r := public.guardar_socio('socio.mcb@credisan.test', 'Socio de Maracaibo',
           array[current_setting('t.mcb')::uuid]);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo crear el socio de Maracaibo: ' || r::text;
  end $$;
commit;

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-0000000000c1','app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare v text; begin
    select string_agg(event::text, ',' order by recorded_at) into v
      from attendance_events
     where branch_id = current_setting('t.css')::uuid and work_date = current_date;
    assert v = 'entrada,salida_almuerzo,regreso_almuerzo,salida',
      'FALLA: el socio de Caja Seca no ve las cuatro horas de su sede: «' || coalesce(v,'(nada)') || '»';
    assert (select count(*) from attendance_events
             where branch_id = current_setting('t.mcb')::uuid) = 0,
      'FUGA: el socio de Caja Seca ve marcaciones de Maracaibo';
    assert (select count(*) from employee_compensation) = 0,
      'FUGA: el socio alcanza el salario';
  end $$;
rollback;

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-0000000000d1','app_role','socio')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from attendance_events
             where branch_id = current_setting('t.css')::uuid) = 0,
      'FUGA: un socio que NO tiene Caja Seca ve sus marcaciones';
  end $$;
rollback;

-- =====================================================================
--  6. El historial por rango: respeta el rango y respeta la sede
-- =====================================================================
-- La consulta de «14 días» del panel, tal cual.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','44444444-4444-4444-4444-444444444444',
                      'app_role','supervisor','branch_id', current_setting('t.css'))::text, true);
  set local role authenticated;
  do $$ declare n int; begin
    -- Los catorce días ANTERIORES al que se está mirando: el de hoy ya sale
    -- arriba, en su ficha, y repetirlo dentro del historial se leería como
    -- un fallo. Sólo debe aparecer el de ayer.
    select count(distinct work_date) into n from attendance_events
     where employee_id = current_setting('t.e1')::uuid
       and work_date >= current_date - 14
       and work_date <= current_date - 1;
    assert n = 1, 'FALLA: el historial de dos semanas trae ' || n || ' días en vez de 1';

    select count(*) into n from attendance_events
     where employee_id = current_setting('t.e1')::uuid
       and work_date >= current_date;
    assert n = 4, 'FALLA: el día que se está mirando no quedó fuera del rango';

    -- Y el mismo historial pedido del trabajador de la otra sede: nada.
    select count(*) into n from attendance_events
     where employee_id = current_setting('t.e2')::uuid
       and work_date >= current_date - 14
       and work_date <= current_date;
    assert n = 0, 'FUGA: el historial de 14 días de un trabajador de otra sede llega igual';
  end $$;
rollback;

-- =====================================================================
--  7. Administración, que es quien pone los horarios, también las ve
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','admin','branch_id', current_setting('t.css'))::text, true);
  set local role authenticated;
  do $$ declare v text; r jsonb; begin
    select string_agg(event::text, ',' order by recorded_at) into v
      from attendance_events
     where branch_id = current_setting('t.css')::uuid and work_date = current_date;
    assert v = 'entrada,salida_almuerzo,regreso_almuerzo,salida',
      'FALLA: administración no ve las cuatro horas de su sede';

    -- Y puede poner las cuatro horas del horario de SU sede: es lo que
    -- el panel le ofrece al lado del historial.
    r := public.horario_sede(current_setting('t.css')::uuid);
    assert (r->>'ok')::boolean, 'FALLA: administración no alcanza el horario de su sede';
    assert jsonb_array_length(r->'dias') = 7, 'FALLA: el horario no trae los siete días';
    r := public.guardar_dia_horario(
           (select (d->>'id')::uuid from jsonb_array_elements(public.horario_sede(
              current_setting('t.css')::uuid)->'dias') d where (d->>'weekday')::int = 3),
           true, false, '07:30', '12:30', '14:30', '17:30');
    assert (r->>'ok')::boolean, 'FALLA: administración no pudo cambiar las cuatro horas del miércoles: ' || r::text;
  end $$;
rollback;

\echo '  ✔ LAS CUATRO HORAS · guardadas, medidas, y visibles sólo a quien toca'
