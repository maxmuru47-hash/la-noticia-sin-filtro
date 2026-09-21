-- =====================================================================
-- CREDISAN CONTROL · Una jornada completa, de principio a fin
-- =====================================================================
-- Todas las baterías anteriores prueban PIEZAS. Ésta prueba el SISTEMA:
-- un día de trabajo real en Maracaibo, con tres personas que se portan
-- de tres maneras distintas, y después el camino completo hasta el
-- cierre de la semana y el reporte.
--
-- Es la prueba que encuentra lo que no encuentra ninguna otra: que cada
-- pieza funcione y el conjunto no.
--
-- LA JORNADA (horario 08:00–12:00 / 13:00–17:00, hora de Caracas)
--
--   Ana   · llega 07:58, almuerza, vuelve, sale 17:02   → jornada completa
--   Luis  · llega 08:12, almuerza, vuelve, sale 17:00   → llegó tarde
--   Mara  · no aparece                                   → ausencia
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select set_config('t.mcb', :'mcb', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','ceo@credisan.test'),
  ('22222222-2222-2222-2222-222222222222','admin@credisan.test');
insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección','ceo', null),
  ('22222222-2222-2222-2222-222222222222','Admin MCB','admin', :'mcb');

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Luis','Rojas','V-2','Asesor','2026-01-15'),
       (:'mcb','MCB-003','Mara','Silva','V-3','Supervisora','2026-01-15');

-- El día de trabajo: AYER, siempre. Ya está cerrado, así que las faltas
-- son firmes, y cae dentro de la ventana de 72 horas que acepta una
-- marcación sin conexión.
--
-- ANTES se esquivaba el fin de semana tomando el viernes anterior, con
-- el argumento de que un sábado apagado no genera ausencia. El argumento
-- era bueno y la consecuencia, mala: ejecutada un LUNES, la prueba
-- apuntaba al viernes —más de 72 horas atrás— y el sistema rechazaba
-- las ocho marcaciones con FUERA_DE_VENTANA_OFFLINE. Rechazarlas es
-- CORRECTO; era la prueba la que pedía algo imposible, y sólo fallaba
-- los lunes.
--
-- Esquivar el fin de semana además sobraba: la excepción de abajo es de
-- alcance EMPLEADO, y ésa manda por encima del horario de la sede. Un
-- domingo con jornada especial es un día laborable para quien la tiene.
select (current_date - 1) as dia \gset
select set_config('t.dia', :'dia', false);

insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', :'dia', :'dia', true, false,
       '08:00', '12:00', '13:00', '17:00', 'jornada de la prueba'
  from employees e where e.branch_id = :'mcb';

-- PIN para los tres y terminal emparejado
do $$ declare v_term uuid; r jsonb; v_code text; e record; i int := 0; begin
  select id into v_term from terminals where code = 'MCB-01';
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Tableta del mostrador');
  perform set_config('t.term', v_term::text, false);

  for e in select id, internal_code from employees order by internal_code loop
    i := i + 1;
    r := public.edge_asignar_pin(e.id, 'PEPPER', null);
    perform set_config('t.pin' || i, r->>'pin', false);
    perform set_config('t.e'  || i, e.id::text, false);
  end loop;
end $$;

-- =====================================================================
--  LA JORNADA
-- =====================================================================
-- Las marcaciones entran por el camino de «sin conexión», que es el
-- único que permite fijar la hora exacta. El motor las clasifica igual
-- que las de en línea: es la misma función la que decide.
--
-- Se mandan EN ORDEN DE RELOJ y con secuencia creciente, como haría un
-- terminal de verdad al recuperar la señal.
do $$
declare
  v_term uuid := current_setting('t.term')::uuid;
  v_dia  date := current_setting('t.dia')::date;
  v_seq  bigint := 0;
  m record; r jsonb; v_fallos text := '';
begin
  for m in
    select * from (values
      -- (hora, quién, qué se espera que pase)
      ('07:58', 1, 'entrada de Ana, dos minutos antes'),
      ('08:12', 2, 'entrada de Luis, doce minutos tarde'),
      ('12:00', 1, 'Ana sale a almorzar'),
      ('12:05', 2, 'Luis sale a almorzar'),
      ('13:00', 1, 'Ana vuelve'),
      ('13:03', 2, 'Luis vuelve'),
      ('17:00', 2, 'Luis sale'),
      ('17:02', 1, 'Ana sale')
    ) as t(hora, quien, que)
    order by t.hora, t.quien
  loop
    v_seq := v_seq + 1;
    r := public.edge_offline(
      v_term,
      current_setting('t.pin' || m.quien),
      'PEPPER',
      gen_random_uuid(),
      ((v_dia + m.hora::time) at time zone 'America/Caracas'),
      v_seq,
      3);
    if not (r->>'ok')::boolean then
      v_fallos := v_fallos || E'\n     · ' || m.que || ' → ' || (r->>'reason');
    end if;
  end loop;

  assert v_fallos = '',
    'FALLA GRAVE: no entraron todas las marcaciones de la jornada:' || v_fallos;
end $$;
\echo '  ✔  1. Las ocho marcaciones de la jornada entran, en orden'

-- =====================================================================
--  2. Cada marcación quedó clasificada como debe
-- =====================================================================
do $$ declare v_est text; v_min int; begin
  -- Ana llegó dos minutos antes: eso es anticipado o puntual, según la
  -- tolerancia configurada, pero NUNCA retraso.
  select a.status::text, a.delta_minutes into v_est, v_min
    from attendance_events a
   where a.employee_id = current_setting('t.e1')::uuid and a.event = 'entrada';
  assert v_min = -2, 'FALLA: los minutos de Ana no son -2, son ' || v_min;
  assert v_est in ('anticipado','puntual'),
    'FALLA GRAVE: llegar dos minutos ANTES quedó como «' || v_est || '»';

  -- Luis llegó doce minutos tarde: eso es tolerancia o retraso, jamás
  -- puntual. Cuál de los dos lo decide la tolerancia de la sede, que es
  -- configurable: por eso se acepta cualquiera de los dos.
  select a.status::text, a.delta_minutes into v_est, v_min
    from attendance_events a
   where a.employee_id = current_setting('t.e2')::uuid and a.event = 'entrada';
  assert v_min = 12, 'FALLA: los minutos de Luis no son 12, son ' || v_min;
  assert v_est in ('tolerancia','retraso'),
    'FALLA GRAVE: llegar doce minutos TARDE quedó como «' || v_est || '»';

  -- Y las cuatro marcaciones de cada uno, ni una más
  assert (select count(*) from attendance_events
           where employee_id = current_setting('t.e1')::uuid) = 4,
    'FALLA: Ana no tiene sus cuatro marcaciones';
  assert (select count(*) from attendance_events
           where employee_id = current_setting('t.e3')::uuid) = 0,
    'FALLA: Mara no marcó y sin embargo tiene marcaciones';
end $$;
\echo '  ✔  2. Llegar antes no es retraso, y llegar tarde no es puntual'

-- =====================================================================
--  3. El día, materializado: quién cumplió y quién faltó
-- =====================================================================
do $$ declare v_ana text; v_luis text; v_mara text; begin
  perform app.recompute_daily_branch(current_setting('t.mcb')::uuid,
                                     current_setting('t.dia')::date);

  select status::text into v_ana  from attendance_daily
   where employee_id = current_setting('t.e1')::uuid and work_date = current_setting('t.dia')::date;
  select status::text into v_luis from attendance_daily
   where employee_id = current_setting('t.e2')::uuid and work_date = current_setting('t.dia')::date;
  select status::text into v_mara from attendance_daily
   where employee_id = current_setting('t.e3')::uuid and work_date = current_setting('t.dia')::date;

  assert v_ana  = 'completo', 'FALLA: el día de Ana quedó como «' || v_ana || '»';
  assert v_luis = 'completo', 'FALLA: el día de Luis quedó como «' || v_luis || '»';
  -- Ésta es la que importa: una ausencia es la FALTA de una marcación.
  -- Nadie la registra al no venir; hay que ir a buscarla.
  assert v_mara = 'ausente',
    'FALLA GRAVE: quien no vino a trabajar no quedó como ausente, sino «' || v_mara || '»';

  -- Y los minutos de retraso se acumulan donde toca
  assert (select late_minutes from attendance_daily
           where employee_id = current_setting('t.e2')::uuid
             and work_date = current_setting('t.dia')::date) >= 12,
    'FALLA: no se acumularon los doce minutos de Luis';
  assert (select late_minutes from attendance_daily
           where employee_id = current_setting('t.e1')::uuid
             and work_date = current_setting('t.dia')::date) = 0,
    'FALLA GRAVE: a Ana, que llegó antes, se le anotaron minutos de retraso';
end $$;
\echo '  ✔  3. Dos jornadas completas y una ausencia, que nadie tuvo que registrar'

-- =====================================================================
--  4. Las horas trabajadas son las que se trabajaron
-- =====================================================================
do $$ declare v_ana int; v_esp int; begin
  select worked_minutes, expected_minutes into v_ana, v_esp
    from attendance_daily
   where employee_id = current_setting('t.e1')::uuid
     and work_date = current_setting('t.dia')::date;

  -- Ana: 07:58→12:00 (242 min) + 13:00→17:02 (242 min) = 484
  assert v_ana between 480 and 488,
    'FALLA: las horas trabajadas de Ana no cuadran: ' || v_ana || ' minutos (deberían ser ~484)';
  -- Esperadas: 08:00→12:00 + 13:00→17:00 = 480
  assert v_esp = 480,
    'FALLA: los minutos esperados no son 480, son ' || v_esp;
end $$;
\echo '  ✔  4. Las horas trabajadas cuadran con el reloj, no con el horario'

-- =====================================================================
--  5. El cierre de esa semana cuenta lo que pasó
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; t jsonb; c jsonb; v_lunes date; begin
    v_lunes := date_trunc('week', current_setting('t.dia')::date)::date;
    r := public.calcular_cierre_semana(current_setting('t.mcb')::uuid, v_lunes);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo cerrar la semana: ' || r::text;

    t := public.cierres(current_setting('t.mcb')::uuid, v_lunes);
    assert jsonb_array_length(t->'cierres') = 3, 'FALLA: no son tres cierres';

    -- Aquí hay que medir con cuidado, y la primera versión de esta
    -- prueba se equivocó: el cierre abarca la SEMANA ENTERA, y esta
    -- simulación sólo llena UN día. Los demás días laborables de esa
    -- semana son ausencias de verdad para los tres, porque nadie marcó.
    -- Exigir «Ana: cero ausencias» era pedirle al sistema que mintiera.
    --
    -- Lo que de verdad distingue a Mara es tener UNA MÁS que los otros
    -- dos, que en todo lo demás son idénticos. Eso sí es la jornada.
    declare v_ana int; v_luis int; v_mara int; begin
      select (f->>'ausencias')::int into v_ana  from jsonb_array_elements(t->'cierres') f
       where f->>'nombre' = 'Ana Pérez';
      select (f->>'ausencias')::int into v_luis from jsonb_array_elements(t->'cierres') f
       where f->>'nombre' = 'Luis Rojas';
      select (f->>'ausencias')::int into v_mara from jsonb_array_elements(t->'cierres') f
       where f->>'nombre' = 'Mara Silva';

      assert v_ana = v_luis,
        'FALLA: Ana y Luis trabajaron los mismos días y no tienen las mismas ausencias ('
        || v_ana || ' vs ' || v_luis || ')';
      assert v_mara = v_ana + 1,
        'FALLA GRAVE: la ausencia del día simulado no llegó al cierre. Mara tiene '
        || v_mara || ' y los que sí vinieron, ' || v_ana;
    end;

    -- Luis llegó tarde: tiene que constar, con sus minutos
    select f into c from jsonb_array_elements(t->'cierres') f where f->>'nombre' = 'Luis Rojas';
    assert (c->>'retrasos')::int >= 1, 'FALLA: el retraso de Luis no llegó al cierre';
    assert (c->>'minutos_retraso')::int >= 12,
      'FALLA: los doce minutos de Luis no llegaron al cierre';

    -- Ana llegó ANTES: por mucho que falten otros días, no puede tener
    -- ni un retraso ni un minuto acumulado.
    select f into c from jsonb_array_elements(t->'cierres') f where f->>'nombre' = 'Ana Pérez';
    assert (c->>'retrasos')::int = 0, 'FALLA GRAVE: a Ana, que llegó antes, le aparece un retraso';
    assert (c->>'minutos_retraso')::int = 0, 'FALLA GRAVE: a Ana le aparecen minutos de retraso';

    -- Y ni una cifra de dinero en todo el cierre
    assert not (t::text ~* 'salario|salary|weekly_base'),
      'FALLA GRAVE: el cierre de la semana enseña dinero';
  end $$;
rollback;
\echo '  ✔  5. El cierre semanal refleja la jornada: la falta, el retraso y sus minutos'

-- =====================================================================
--  6. El reporte exportable dice lo mismo
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; f jsonb; begin
    r := public.reporte_asistencia(current_setting('t.mcb')::uuid,
                                   current_setting('t.dia')::date,
                                   current_setting('t.dia')::date);
    assert (r->>'filas')::int = 3, 'FALLA: el reporte del día no tiene tres filas';

    select x into f from jsonb_array_elements(r->'datos') x where x->>'nombre' = 'Mara Silva';
    assert (f->>'estado') = 'ausente', 'FALLA: el reporte no marca la ausencia';
    assert (f->>'marcaciones')::int = 0, 'FALLA: el reporte le cuenta marcaciones a quien no vino';

    select x into f from jsonb_array_elements(r->'datos') x where x->>'nombre' = 'Luis Rojas';
    assert (f->>'minutos_retraso')::int >= 12, 'FALLA: el reporte pierde los minutos de retraso';

    -- Lo que NO debe llevar un archivo que va a circular por correo
    assert not (r::text ~* 'V-1|V-2|V-3'), 'FALLA GRAVE: el reporte lleva cédulas';
    assert not (r::text ~* 'salario|weekly_base'), 'FALLA GRAVE: el reporte lleva salarios';
  end $$;
rollback;
\echo '  ✔  6. El reporte coincide con el cierre, y sigue sin cédulas ni salarios'

-- =====================================================================
--  7. El tablero del día enseña la jornada tal cual fue
-- =====================================================================
-- Se mira el día de la jornada, no «hoy»: `tablero_hoy` calcula contra
-- el calendario en vivo, así que aquí se comprueba por la vía del
-- período, que es la que mira días pasados.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare t jsonb; s jsonb; begin
    t := public.tablero_periodo(current_setting('t.dia')::date,
                                current_setting('t.dia')::date);
    select f into s from jsonb_array_elements(t->'sedes') f where f->>'sede' = 'Maracaibo';

    assert (s->>'marcaciones')::int = 8, 'FALLA: no cuenta las ocho marcaciones';
    assert (s->>'ausencias')::int = 1, 'FALLA: no cuenta la ausencia de Mara';
    assert (s->>'trabajadores')::int = 3, 'FALLA: no cuenta a los tres trabajadores';
    assert (t->>'resumen_diario_calculado')::boolean,
      'FALLA: dice que el resumen no está calculado, y sí lo está';

    -- El porcentaje de puntualidad tiene que ser creíble: ocho
    -- marcaciones, una de ellas tarde.
    assert (s->>'puntualidad')::numeric between 80 and 100,
      'FALLA: la puntualidad sale en ' || (s->>'puntualidad') || '%, que no cuadra';
  end $$;
rollback;
\echo '  ✔  7. El tablero del período cuadra con lo que ocurrió'

-- =====================================================================
--  8. Y de todo ello queda rastro
-- =====================================================================
do $$ begin
  assert (select count(*) from attendance_events
           where work_date = current_setting('t.dia')::date) = 8,
    'FALLA: las marcaciones del día no son ocho';
  -- Inmutables: ni una corrección encubierta
  assert not exists (
    select 1 from pg_policies
     where tablename = 'attendance_events' and cmd in ('UPDATE','DELETE')),
    'FALLA GRAVE: apareció una política que permite modificar marcaciones';
end $$;
\echo '  ✔  8. Las ocho marcaciones quedan, y nadie puede reescribirlas'

\echo ''
\echo '  ✔  JORNADA COMPLETA — el sistema entero funciona de punta a punta'
\echo ''
