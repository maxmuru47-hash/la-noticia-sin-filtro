-- =====================================================================
-- CREDISAN CONTROL · ¿Se puede marcar la SALIDA?
-- =====================================================================
-- Pregunta directa de Caja Seca. Se responde probándolo, no opinando.
--
-- El terminal no pregunta qué marcación es: la deduce. `next_expected`
-- elige, entre las que se esperan hoy y aún no están, la MÁS CERCANA en
-- el tiempo al momento de marcar. Esta batería exige que esa deducción
-- acierte en los cuatro casos que ocurren de verdad en un mostrador:
--
--   1. El día normal, las cuatro marcaciones en orden.
--   2. Quien OLVIDÓ las de almuerzo y llega a su salida.
--   3. Quien sale antes de tiempo.
--   4. Quien ya marcó todo y vuelve a intentarlo.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as css from branches where code = 'CSS' \gset
select set_config('t.css', :'css', false);

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'css','CSS-901','Ana','Salida','V-901','Cajera','2026-01-15'),
       (:'css','CSS-902','Luis','Olvido','V-902','Asesor','2026-01-15'),
       (:'css','CSS-903','Mara','Temprano','V-903','Cajera','2026-01-15');

select id as e1 from employees where internal_code='CSS-901' \gset
select id as e2 from employees where internal_code='CSS-902' \gset
select id as e3 from employees where internal_code='CSS-903' \gset
select set_config('t.e1', :'e1', false), set_config('t.e2', :'e2', false),
       set_config('t.e3', :'e3', false);

-- El horario real de la sede: 08:00–12:00 / 14:00–18:00. Se fuerza con
-- una excepción de EMPLEADO para que la prueba no dependa del día de la
-- semana en que se ejecute.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', current_date, current_date,
       true, false, '08:00', '12:00', '14:00', '18:00', 'jornada de la prueba'
  from employees e where e.internal_code in ('CSS-901','CSS-902','CSS-903');

-- Marcar POR EL MISMO CAMINO QUE EL TERMINAL: `next_expected` decide
-- qué marcación toca y `_insert_punch` la escribe. No se imita nada —
-- una prueba que imita el código prueba la imitación.
select id as term from terminals where code = 'CSS-01' \gset
select set_config('t.term', :'term', false);

create or replace function pg_temp.marcar(p_emp uuid, p_hora time)
returns text language plpgsql as $$
declare v record; v_momento timestamptz; r jsonb;
begin
  v_momento := (current_date + p_hora) at time zone 'America/Caracas';
  select * into v from app.next_expected(p_emp, v_momento);
  if not found then return 'SIN_EVENTO_PENDIENTE'; end if;

  r := app._insert_punch(p_emp, current_setting('t.term')::uuid, v_momento, 'online',
                         gen_random_uuid(), v.event, v.expected_at, v.is_arrival);
  return r->>'event';
end $$;

-- =====================================================================
--  1. El día normal: las cuatro, y la última es la SALIDA
-- =====================================================================
do $$ declare r text; begin
  assert pg_temp.marcar(current_setting('t.e1')::uuid, '08:00') = 'entrada',
    'FALLA: la primera marcación del día no fue la entrada';
  assert pg_temp.marcar(current_setting('t.e1')::uuid, '12:00') = 'salida_almuerzo',
    'FALLA: no reconoció la salida a almorzar';
  assert pg_temp.marcar(current_setting('t.e1')::uuid, '14:00') = 'regreso_almuerzo',
    'FALLA: no reconoció el regreso del almuerzo';

  r := pg_temp.marcar(current_setting('t.e1')::uuid, '18:00');
  assert r = 'salida',
    'FALLA GRAVE: a las 18:00, tras un día completo, la marcación salió como «' || r || '»';

  assert (select status::text from attendance_events
           where employee_id = current_setting('t.e1')::uuid and event='salida') = 'puntual',
    'FALLA: la salida a su hora no quedó como puntual';
end $$;
\echo '  ✔  1. Jornada completa: la última marcación es la SALIDA, y puntual'

-- =====================================================================
--  2. El que OLVIDÓ el almuerzo, y viene a marcar su salida
-- =====================================================================
-- Éste es el caso que de verdad preocupa. A las 18:00 le faltan TRES
-- marcaciones: las dos del almuerzo y la salida. Si el sistema eligiera
-- mal, el trabajador se iría creyendo que marcó su salida y en el
-- reporte aparecería sin salir.
do $$ declare r text; begin
  assert pg_temp.marcar(current_setting('t.e2')::uuid, '08:05') = 'entrada',
    'FALLA: no entró bien';

  r := pg_temp.marcar(current_setting('t.e2')::uuid, '18:00');
  assert r = 'salida',
    'FALLA GRAVE: olvidó marcar el almuerzo y su SALIDA se registró como «' || r
    || '». Se iría creyendo que marcó, y en el reporte no habría salido.';

  -- Y el almuerzo sigue pendiente, que es lo correcto: no se inventa
  -- una marcación que nadie hizo.
  assert (select count(*) from attendance_events
           where employee_id = current_setting('t.e2')::uuid) = 2,
    'FALLA: se registraron marcaciones que el trabajador no hizo';
end $$;
\echo '  ✔  2. Quien olvidó el almuerzo SÍ puede marcar su salida'

-- =====================================================================
--  3. El que sale antes de tiempo
-- =====================================================================
-- Sale a las 16:30, hora y media antes. Tiene que poder marcar —nadie
-- se queda sin marcar por irse antes— y tiene que quedar registrado
-- como lo que es.
do $$ declare r text; v_min int; begin
  perform pg_temp.marcar(current_setting('t.e3')::uuid, '08:00');
  perform pg_temp.marcar(current_setting('t.e3')::uuid, '12:00');
  perform pg_temp.marcar(current_setting('t.e3')::uuid, '14:00');

  r := pg_temp.marcar(current_setting('t.e3')::uuid, '16:30');
  assert r = 'salida',
    'FALLA GRAVE: quien sale antes no pudo marcar su salida; salió como «' || r || '»';

  select delta_minutes into v_min from attendance_events
   where employee_id = current_setting('t.e3')::uuid and event='salida';
  assert v_min = -90,
    'FALLA: la salida anticipada no quedó con sus 90 minutos: ' || v_min;
end $$;
\echo '  ✔  3. Quien sale antes también marca, y queda registrado como tal'

-- =====================================================================
--  4. El que ya marcó todo y lo intenta otra vez
-- =====================================================================
do $$ declare r text; begin
  r := pg_temp.marcar(current_setting('t.e1')::uuid, '18:10');
  assert r = 'SIN_EVENTO_PENDIENTE',
    'FALLA: se le permitió marcar una quinta vez y quedó como «' || r || '»';

  assert (select count(*) from attendance_events
           where employee_id = current_setting('t.e1')::uuid) = 4,
    'FALLA GRAVE: se registró una marcación de más';
end $$;
\echo '  ✔  4. Quien ya marcó todo no duplica nada'

-- =====================================================================
--  5. El día cierra como debe
-- =====================================================================
do $$ declare v text; begin
  perform app.recompute_daily_branch(current_setting('t.css')::uuid, current_date);

  select status::text into v from attendance_daily
   where employee_id = current_setting('t.e1')::uuid and work_date = current_date;
  assert v in ('completo','en_curso'),
    'FALLA: el día de quien marcó las cuatro quedó como «' || v || '»';

  select status::text into v from attendance_daily
   where employee_id = current_setting('t.e2')::uuid and work_date = current_date;
  assert v in ('incompleto','en_curso'),
    'FALLA: a quien le faltan dos marcaciones el día quedó como «' || v || '»';
end $$;
\echo '  ✔  5. El día refleja quién completó y a quién le faltó'

\echo ''
\echo '  ✔  BATERÍA DE SALIDAS COMPLETA — se puede marcar la salida'
