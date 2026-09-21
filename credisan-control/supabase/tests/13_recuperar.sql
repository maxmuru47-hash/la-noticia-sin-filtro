-- =====================================================================
-- CREDISAN CONTROL · El mantenimiento recupera las noches que se perdió
-- =====================================================================
-- Esto no es una hipótesis: entre el 18 y el 21 de septiembre de 2026 el
-- mantenimiento nocturno falló TRES noches seguidas porque la contraseña
-- de la base dejó de funcionar.
--
-- Que falle va a volver a pasar. Lo que no puede pasar es que una noche
-- perdida se pierda para siempre, que es lo que ocurría: la tarea cerraba
-- literalmente AYER, así que al recuperarse la conexión los días de en
-- medio se quedaban sin calcular y nadie se enteraba hasta mirar un
-- reporte que no cuadraba.
--
-- Lo que esta batería exige:
--   1. Que el cierre nocturno alcance VARIOS días hacia atrás.
--   2. Que repetirlo no cambie nada: recalcular es seguro.
--   3. Que NO toque una semana que administración ya revisó.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select set_config('t.mcb', :'mcb', false);

insert into auth.users (id, email) values
  ('22222222-2222-2222-2222-222222222222','admin.mcb@credisan.test');
insert into profiles (id, full_name, role, branch_id) values
  ('22222222-2222-2222-2222-222222222222','Admin Maracaibo','admin', :'mcb');

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15');
select id as e_ana from employees where internal_code = 'MCB-001' \gset
select set_config('t.ana', :'e_ana', false);

-- Tres días laborables seguidos, ya cerrados. Se fuerzan con una
-- excepción de EMPLEADO para que la prueba no dependa de en qué día de
-- la semana se ejecute: ésa fue la trampa que hizo fallar otra batería
-- todos los lunes.
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
values ('employee', :'e_ana', 'jornada_especial', current_date - 3, current_date - 1,
        true, false, '08:00', '12:00', '14:00', '18:00', 'días de la prueba');

-- =====================================================================
--  1. Tres noches caídas se recuperan en una
-- =====================================================================
do $$ declare n int; v_dias int; begin
  -- Nadie marcó esos tres días: son tres ausencias que deberían quedar
  -- firmes. Antes de la tarea, no existe ni una fila.
  assert (select count(*) from attendance_daily
           where employee_id = current_setting('t.ana')::uuid) = 0,
    'FALLA: la prueba arranca con días ya calculados';

  n := app.job_close_yesterday();

  select count(*) into v_dias from attendance_daily
   where employee_id = current_setting('t.ana')::uuid
     and work_date between current_date - 3 and current_date - 1;

  assert v_dias = 3,
    'FALLA GRAVE: el cierre nocturno sólo alcanzó ' || v_dias
    || ' de los 3 días perdidos. Una noche caída se perdería para siempre.';

  assert (select count(*) from attendance_daily
           where employee_id = current_setting('t.ana')::uuid
             and work_date between current_date - 3 and current_date - 1
             and status = 'ausente') = 3,
    'FALLA GRAVE: los días recuperados no quedaron como ausencias firmes';
end $$;
\echo '  ✔  1. Una noche caída se recupera en la siguiente'

-- =====================================================================
--  2. Repetirlo no cambia nada
-- =====================================================================
-- Si todas las noches funcionaron, la tarea repasa días ya calculados.
-- Eso tiene que ser inocuo, o el remedio sería peor que la enfermedad.
do $$ declare antes text; despues text; v_n1 int; v_n2 int; begin
  select string_agg(status::text || ':' || registered_events, ',' order by work_date), count(*)
    into antes, v_n1 from attendance_daily where employee_id = current_setting('t.ana')::uuid;

  perform app.job_close_yesterday();
  perform app.job_close_yesterday();

  select string_agg(status::text || ':' || registered_events, ',' order by work_date), count(*)
    into despues, v_n2 from attendance_daily where employee_id = current_setting('t.ana')::uuid;

  assert antes = despues,
    'FALLA GRAVE: repasar los mismos días cambió el resultado. Antes «'
    || antes || '», después «' || despues || '»';

  -- Se comparan los recuentos entre sí, no contra un número fijo: cuántos
  -- días repasa la tarea es configurable, y una prueba que dé por hecho
  -- que son tres se rompería el día que alguien cambie ese ajuste.
  assert v_n1 = v_n2,
    'FALLA: repetir la tarea duplicó filas del día (' || v_n1 || ' → ' || v_n2 || ')';
end $$;
\echo '  ✔  2. Repasar días ya calculados no cambia nada ni duplica nada'

-- =====================================================================
--  3. Una semana ya revisada no se toca
-- =====================================================================
-- La de más adentro. El cierre semanal ahora repasa también la semana
-- anterior, y eso sólo es aceptable si lo que administración aprobó se
-- queda como lo aprobó.
do $$ declare v_lunes date; v_id uuid; begin
  v_lunes := (date_trunc('week', current_date) - interval '7 days')::date;
  perform app.compute_weekly_closure(current_setting('t.ana')::uuid, v_lunes);

  select id into v_id from weekly_closures
   where employee_id = current_setting('t.ana')::uuid and week_start = v_lunes;
  assert v_id is not null, 'FALLA: no se calculó el cierre de la semana pasada';

  -- Administración la revisa y la deja aprobada, con su nota
  update weekly_closures
     set status = 'aprobado', note = 'Revisada por administración',
         closed_by = '22222222-2222-2222-2222-222222222222', closed_at = now(),
         absence_count = 99                    -- una cifra imposible, a propósito
   where id = v_id;

  -- Llega la tarea nocturna y repasa esa semana
  perform app.job_weekly_closures();

  declare v_estado text; v_nota text; v_aus int; begin
    select status::text, note, absence_count into v_estado, v_nota, v_aus
      from weekly_closures where id = v_id;

    assert v_estado = 'aprobado',
      'FALLA GRAVE: la tarea nocturna deshizo una semana ya aprobada (quedó «' || v_estado || '»)';
    assert v_nota = 'Revisada por administración',
      'FALLA GRAVE: se perdió la nota de quien revisó la semana';
    -- Si las cifras se hubieran recalculado, el 99 habría desaparecido.
    -- Que siga ahí demuestra que la fila no se tocó EN ABSOLUTO.
    assert v_aus = 99,
      'FALLA GRAVE: se recalcularon las cifras de una semana ya cerrada';
  end;
end $$;
\echo '  ✔  3. Lo que administración aprobó no lo deshace ninguna tarea'

-- =====================================================================
--  4. Una semana pendiente sí se refresca
-- =====================================================================
-- El otro lado de la misma moneda: si nadie la ha revisado, repasarla
-- tiene que servir para algo.
do $$ declare v_lunes date; v_id uuid; v_aus int; begin
  v_lunes := date_trunc('week', current_date)::date;
  perform app.compute_weekly_closure(current_setting('t.ana')::uuid, v_lunes);
  select id into v_id from weekly_closures
   where employee_id = current_setting('t.ana')::uuid and week_start = v_lunes;

  update weekly_closures set absence_count = 99 where id = v_id;
  perform app.job_weekly_closures();

  -- Esta semana entra en el repaso sólo si el lunes de hoy cae dentro;
  -- lo que importa es que una PENDIENTE sí admite recálculo.
  perform app.compute_weekly_closure(current_setting('t.ana')::uuid, v_lunes);
  select absence_count into v_aus from weekly_closures where id = v_id;

  assert v_aus <> 99,
    'FALLA GRAVE: una semana PENDIENTE no se refresca; el recálculo no sirve de nada';
end $$;
\echo '  ✔  4. Una semana pendiente sí se refresca con las cifras nuevas'

\echo ''
\echo '  ✔  BATERÍA DE RECUPERACIÓN COMPLETA — todas las validaciones pasaron'
