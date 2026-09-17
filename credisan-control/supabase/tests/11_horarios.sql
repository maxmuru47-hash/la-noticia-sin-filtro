-- =====================================================================
-- CREDISAN CONTROL · Horario propio de un trabajador
-- =====================================================================
-- Hay gente que no hace el horario de su sede. Medirla contra el
-- general la convierte en impuntual todos los días por una diferencia
-- que la empresa aprobó.
--
-- Lo que esta batería exige:
--
--   1. Que a quien tiene horario propio se le mida CONTRA EL SUYO —no
--      contra el de la sede—, en la hora de entrada, en las marcaciones
--      que se le esperan y en el estado del día.
--   2. Que sólo la administradora DE SU SEDE (o dirección) pueda
--      dárselo. Ni el jefe operativo, ni la administradora de al lado,
--      ni un socio.
--   3. Que cambiarlo o quitarlo NO reescriba el pasado.
--
-- Las tres son promesas al trabajador, no detalles: de la primera
-- depende que no lo llamen impuntual sin serlo, y de la tercera que un
-- día que fue puntual siga siéndolo mañana.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select id as css from branches where code = 'CSS' \gset
select set_config('t.mcb', :'mcb', false), set_config('t.css', :'css', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','direccion@credisan.test'),
  ('22222222-2222-2222-2222-222222222222','admin.mcb@credisan.test'),
  ('33333333-3333-3333-3333-333333333333','admin.css@credisan.test'),
  ('55555555-5555-5555-5555-555555555555','jefe.mcb@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000001','socio@credisan.test');

insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección General','ceo',        null),
  ('22222222-2222-2222-2222-222222222222','Admin Maracaibo',  'admin',      :'mcb'),
  ('33333333-3333-3333-3333-333333333333','Admin Caja Seca',  'admin',      :'css'),
  ('55555555-5555-5555-5555-555555555555','Jefe Maracaibo',   'supervisor', :'mcb');

-- Un socio con Maracaibo, para comprobar que mirar no es mandar
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  select public.guardar_socio('socio@credisan.test','Socio de Maracaibo', array[:'mcb'::uuid]);
commit;

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana', 'Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Beto','Ruiz', 'V-2','Vigilante','2026-01-15'),
       (:'css','CSS-001','Carla','Soto','V-3','Cajera','2026-01-15');

select id as e_ana  from employees where internal_code = 'MCB-001' \gset
select id as e_beto from employees where internal_code = 'MCB-002' \gset
select id as e_carla from employees where internal_code = 'CSS-001' \gset
select set_config('t.ana', :'e_ana', false), set_config('t.beto', :'e_beto', false),
       set_config('t.carla', :'e_carla', false);

-- La sede abre los siete días. No es capricho: si el sábado estuviera
-- apagado, esta batería pasaría o fallaría según el día en que se
-- ejecute, y una prueba que depende del calendario no prueba nada.
update schedule_days set is_working = true, is_continuous = false,
       entry_time = '08:00', lunch_out_time = '12:00',
       lunch_in_time = '14:00', exit_time = '18:00'
 where schedule_id = (select id from work_schedules
                       where branch_id = current_setting('t.mcb')::uuid
                         and name = 'Jornada estándar')
   and weekday in (0, 6);

-- Beto es el vigilante: jornada corrida 09:00–15:00, todos los días.
-- Se le pone con fecha anterior —a mano, como estaba antes de esta
-- fase— para que la jornada de AYER ya caiga bajo su horario.
do $$ declare v_s uuid; d int; begin
  insert into work_schedules (branch_id, name, description)
  values (current_setting('t.mcb')::uuid, 'Horario propio · Beto Ruiz',
          'Vigilancia: jornada corrida 09:00–15:00.')
  returning id into v_s;
  for d in 0..6 loop
    insert into schedule_days (schedule_id, weekday, is_working, is_continuous, entry_time, exit_time)
    values (v_s, d, true, true, '09:00', '15:00');
  end loop;
  insert into schedule_assignments (schedule_id, employee_id, valid_from, note)
  values (v_s, current_setting('t.beto')::uuid, current_date - 7, 'Horario de vigilancia');
end $$;

-- =====================================================================
--  1. El motor mide a cada quien contra SU horario
-- =====================================================================
do $$ declare v_ana record; v_beto record; begin
  select * into v_ana  from app.effective_day(current_setting('t.ana')::uuid,  current_date);
  select * into v_beto from app.effective_day(current_setting('t.beto')::uuid, current_date);

  assert v_ana.entry_time = '08:00' and not v_ana.is_continuous,
    'FALLA: a Ana no se le aplica el horario de la sede';
  assert v_beto.entry_time = '09:00' and v_beto.is_continuous and v_beto.exit_time = '15:00',
    'FALLA GRAVE: a Beto se le está midiendo con el horario de la sede y no con el suyo';

  -- A Ana se le esperan cuatro marcaciones; a Beto dos. Si esto se
  -- confundiera, Beto saldría INCOMPLETO todos los días por no haber
  -- ido a almorzar a una hora que no tiene.
  assert (select count(*) from app.expected_events(current_setting('t.ana')::uuid, current_date)) = 4,
    'FALLA: a Ana no se le esperan sus cuatro marcaciones';
  assert (select count(*) from app.expected_events(current_setting('t.beto')::uuid, current_date)) = 2,
    'FALLA GRAVE: a Beto se le esperan marcaciones de almuerzo que su horario no tiene';

  assert app.expected_minutes(current_setting('t.ana')::uuid,  current_date) = 480,
    'FALLA: los minutos previstos de Ana no son ocho horas';
  assert app.expected_minutes(current_setting('t.beto')::uuid, current_date) = 360,
    'FALLA GRAVE: los minutos previstos de Beto salen de un horario que no es el suyo';
end $$;
\echo '  ✔  1. Cada quien se mide contra su propio horario'

-- =====================================================================
--  2. La misma hora: puntual para uno, retraso para el otro
-- =====================================================================
-- Ésta es LA prueba. Los dos fichan a las 09:00 de ayer. Ana llega una
-- hora tarde; Beto llega a su hora. Si el sistema no distinguiera,
-- Beto sería «impuntual» por cumplir.
do $$
declare v_term uuid; v_code text; r jsonb; v_seq bigint := 0;
        v_dia date := current_date - 1; m record; v_fallos text := '';
begin
  select id into v_term from terminals where code = 'MCB-01';
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Tableta del mostrador');
  perform set_config('t.term', v_term::text, false);

  r := public.edge_asignar_pin(current_setting('t.ana')::uuid,  'PEPPER', null);
  perform set_config('t.pin_ana', r->>'pin', false);
  r := public.edge_asignar_pin(current_setting('t.beto')::uuid, 'PEPPER', null);
  perform set_config('t.pin_beto', r->>'pin', false);

  for m in
    select * from (values
      ('09:00', 'ana',  'Ana ficha una hora tarde'),
      ('09:00', 'beto', 'Beto ficha a su hora'),
      ('12:00', 'ana',  'Ana sale a almorzar'),
      ('14:00', 'ana',  'Ana vuelve'),
      ('15:00', 'beto', 'Beto termina su jornada corrida'),
      ('18:00', 'ana',  'Ana sale')
    ) as t(hora, quien, que)
    order by t.hora, t.quien
  loop
    v_seq := v_seq + 1;
    r := public.edge_offline(v_term, current_setting('t.pin_' || m.quien), 'PEPPER',
           gen_random_uuid(), ((v_dia + m.hora::time) at time zone 'America/Caracas'), v_seq, 3);
    if not (r->>'ok')::boolean then
      v_fallos := v_fallos || E'\n     · ' || m.que || ' → ' || (r->>'reason');
    end if;
  end loop;
  assert v_fallos = '', 'FALLA GRAVE: no entraron las marcaciones:' || v_fallos;
  perform set_config('t.dia', v_dia::text, false);
end $$;

do $$ declare v_est text; v_min int; begin
  select a.status::text, a.delta_minutes into v_est, v_min
    from attendance_events a
   where a.employee_id = current_setting('t.ana')::uuid and a.event = 'entrada';
  assert v_min = 60 and v_est = 'retraso',
    'FALLA: Ana llegó una hora tarde y quedó como «' || v_est || '» con ' || v_min || ' minutos';

  select a.status::text, a.delta_minutes into v_est, v_min
    from attendance_events a
   where a.employee_id = current_setting('t.beto')::uuid and a.event = 'entrada';
  assert v_min = 0 and v_est = 'puntual',
    'FALLA GRAVE: Beto fichó a SU hora y el sistema lo llamó «' || v_est
    || '» con ' || v_min || ' minutos de diferencia';
end $$;
\echo '  ✔  2. A las 09:00 uno llega tarde y el otro llega a su hora'

-- =====================================================================
--  3. Dos marcaciones bastan para cerrar el día de quien hace corrido
-- =====================================================================
do $$ declare v_ana text; v_beto text; begin
  perform app.recompute_daily_branch(current_setting('t.mcb')::uuid, current_setting('t.dia')::date);

  select status::text into v_ana from attendance_daily
   where employee_id = current_setting('t.ana')::uuid and work_date = current_setting('t.dia')::date;
  select status::text into v_beto from attendance_daily
   where employee_id = current_setting('t.beto')::uuid and work_date = current_setting('t.dia')::date;

  assert v_ana = 'completo', 'FALLA: el día de Ana quedó como «' || v_ana || '»';
  assert v_beto = 'completo',
    'FALLA GRAVE: Beto cumplió su horario completo y el día le quedó «' || v_beto || '»';

  assert (select late_minutes from attendance_daily
           where employee_id = current_setting('t.ana')::uuid
             and work_date = current_setting('t.dia')::date) = 60,
    'FALLA: no se acumuló la hora de retraso de Ana';
  assert (select late_minutes from attendance_daily
           where employee_id = current_setting('t.beto')::uuid
             and work_date = current_setting('t.dia')::date) = 0,
    'FALLA GRAVE: a Beto se le anotaron minutos de retraso por cumplir su horario';
end $$;
\echo '  ✔  3. Quien hace jornada corrida no sale incompleto por no almorzar'

-- =====================================================================
--  4. Quién puede ponerle horario a quién
-- =====================================================================
-- El usuario lo pidió con estas palabras: «solo puede hacerlo las
-- administradoras en cada sede».
select jsonb_build_array(
  jsonb_build_object('dia',0,'trabaja',false),
  jsonb_build_object('dia',1,'trabaja',true,'continua',true,'entrada','10:00','salida','16:00'),
  jsonb_build_object('dia',2,'trabaja',true,'continua',true,'entrada','10:00','salida','16:00'),
  jsonb_build_object('dia',3,'trabaja',true,'continua',true,'entrada','10:00','salida','16:00'),
  jsonb_build_object('dia',4,'trabaja',true,'continua',true,'entrada','10:00','salida','16:00'),
  jsonb_build_object('dia',5,'trabaja',true,'continua',true,'entrada','10:00','salida','16:00'),
  jsonb_build_object('dia',6,'trabaja',false)
) as jornada \gset
select set_config('t.jornada', :'jornada', false);

-- 4a · La administradora de la sede SÍ
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.dar_horario_propio(current_setting('t.ana')::uuid, current_setting('t.jornada')::jsonb);
    assert (r->>'ok')::boolean, 'FALLA: la administradora de Maracaibo no pudo poner el horario';
    assert (select entry_time from app.effective_day(current_setting('t.ana')::uuid, current_date)) = '10:00',
      'FALLA: se guardó el horario pero no es con el que se mide a Ana';
  end $$;
rollback;

-- 4b · La administradora de OTRA sede no
begin;
  select set_config('request.jwt.claims', json_build_object('sub','33333333-3333-3333-3333-333333333333',
    'app_role','admin','branch_id',current_setting('t.css'))::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid, current_setting('t.jornada')::jsonb);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: la administradora de Caja Seca cambió el horario de alguien de Maracaibo';
  end $$;
rollback;

-- 4c · El jefe operativo no. Ve su sede, reporta novedades — pero con
--      qué vara se mide a alguien no es operación del día.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid, current_setting('t.jornada')::jsonb);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: el jefe operativo cambió el horario de un trabajador';

    ok := false;
    begin perform public.quitar_horario_propio(current_setting('t.beto')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: el jefe operativo le quitó el horario propio a un trabajador';
  end $$;
rollback;

-- 4d · Un socio tampoco, ni siquiera en la sede que sí ve
begin;
  select set_config('request.jwt.claims', json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001',
    'app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; r jsonb; begin
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid, current_setting('t.jornada')::jsonb);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio cambió el horario de un trabajador';

    -- Mirar sí: entender por qué a alguien se le mide distinto es parte
    -- de leer el reporte. Mandar, no.
    r := public.horario_de_trabajador(current_setting('t.beto')::uuid);
    assert (r->>'propio')::boolean, 'FALLA: el socio no puede consultar el horario de su sede';
  end $$;
rollback;

-- 4e · Dirección sí, en cualquier sede
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.dar_horario_propio(current_setting('t.carla')::uuid, current_setting('t.jornada')::jsonb);
    assert (r->>'ok')::boolean, 'FALLA: dirección no pudo poner un horario en Caja Seca';
  end $$;
rollback;
\echo '  ✔  4. Sólo la administradora de su sede (y dirección) pone horarios'

-- =====================================================================
--  5. Un horario que no se puede cumplir no se guarda
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare ok boolean; v jsonb; begin
    -- Salir antes de entrar
    v := jsonb_build_array(
      jsonb_build_object('dia',0,'trabaja',false), jsonb_build_object('dia',1,'trabaja',true,
        'continua',true,'entrada','16:00','salida','10:00'),
      jsonb_build_object('dia',2,'trabaja',false), jsonb_build_object('dia',3,'trabaja',false),
      jsonb_build_object('dia',4,'trabaja',false), jsonb_build_object('dia',5,'trabaja',false),
      jsonb_build_object('dia',6,'trabaja',false));
    ok := false;
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    exception when others then ok := sqlerrm like '%ORDEN_INVALIDO%'; end;
    assert ok, 'FALLA: se aceptó un horario que sale antes de entrar';

    -- Jornada partida sin las horas del almuerzo
    v := jsonb_build_array(
      jsonb_build_object('dia',0,'trabaja',false), jsonb_build_object('dia',1,'trabaja',true,
        'continua',false,'entrada','08:00','salida','18:00'),
      jsonb_build_object('dia',2,'trabaja',false), jsonb_build_object('dia',3,'trabaja',false),
      jsonb_build_object('dia',4,'trabaja',false), jsonb_build_object('dia',5,'trabaja',false),
      jsonb_build_object('dia',6,'trabaja',false));
    ok := false;
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    exception when others then ok := sqlerrm like '%FALTAN_HORAS%'; end;
    assert ok, 'FALLA: se aceptó una jornada partida sin hora de almuerzo';

    -- Media semana
    ok := false;
    begin perform public.dar_horario_propio(current_setting('t.ana')::uuid,
            jsonb_build_array(jsonb_build_object('dia',1,'trabaja',false)));
    exception when others then ok := sqlerrm like '%FALTAN_DIAS%'; end;
    assert ok, 'FALLA: se aceptó un horario con menos de siete días';

    -- Y nada de esto dejó basura detrás
    assert (select count(*) from work_schedules
             where name like 'Horario propio · Ana%') = 0,
      'FALLA: un horario rechazado dejó un horario a medias en la sede';
  end $$;
rollback;
\echo '  ✔  5. Un horario incoherente se rechaza entero, sin dejar restos'

-- =====================================================================
--  6. Cambiar el horario no reescribe el pasado
-- =====================================================================
-- La de más adentro. Ayer Beto entró a las 09:00 y fue PUNTUAL. Si hoy
-- se le cambia el horario a las 07:00 y el cambio rigiera hacia atrás,
-- el mantenimiento nocturno convertiría aquel día en dos horas de
-- retraso. Un día que fue puntual tiene que seguir siéndolo.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v jsonb; begin
    v := (select jsonb_agg(jsonb_build_object('dia', d, 'trabaja', true, 'continua', true,
                                              'entrada','07:00','salida','13:00'))
            from generate_series(0,6) d);
    r := public.dar_horario_propio(current_setting('t.beto')::uuid, v);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo cambiar el horario de Beto';

    assert (select entry_time from app.effective_day(current_setting('t.beto')::uuid, current_date)) = '07:00',
      'FALLA: el horario nuevo no rige desde hoy';
    assert (select entry_time from app.effective_day(current_setting('t.beto')::uuid, current_date - 1)) = '09:00',
      'FALLA GRAVE: el horario nuevo se aplicó hacia atrás y convirtió en retraso un día puntual';

    -- Y al recalcular ayer, sigue puntual
    perform app.recompute_daily(current_setting('t.beto')::uuid, current_date - 1);
    assert (select late_minutes from attendance_daily
             where employee_id = current_setting('t.beto')::uuid
               and work_date = current_date - 1) = 0,
      'FALLA GRAVE: al recalcular, un día que fue puntual salió con retraso';
  end $$;
rollback;

-- Quitarlo hace lo mismo: cierra, no borra.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.quitar_horario_propio(current_setting('t.beto')::uuid);
    assert r->>'accion' = 'cerrado', 'FALLA: quitar el horario lo borró en vez de cerrarlo';

    assert (select count(*) from schedule_assignments
             where employee_id = current_setting('t.beto')::uuid) = 1,
      'FALLA GRAVE: se borró el rastro del horario que rigió días ya medidos';
    assert (select entry_time from app.effective_day(current_setting('t.beto')::uuid, current_date - 1)) = '09:00',
      'FALLA GRAVE: al quitarle el horario, ayer pasó a medirse con el de la sede';
    -- Quitarlo rige DESDE HOY, igual que ponerlo: las dos operaciones
    -- tienen que tener el mismo día de corte o nadie sabría cuál manda.
    assert (select entry_time from app.effective_day(current_setting('t.beto')::uuid, current_date)) = '08:00',
      'FALLA: desde hoy Beto debía volver al horario de la sede';
    assert (select entry_time from app.effective_day(current_setting('t.beto')::uuid, current_date + 1)) = '08:00',
      'FALLA: de mañana en adelante Beto debía volver al horario de la sede';

    -- Y no se puede quitar dos veces
    begin
      perform public.quitar_horario_propio(current_setting('t.beto')::uuid);
      assert false, 'FALLA: se quitó un horario propio que ya no existía';
    exception when others then
      assert sqlerrm like '%NO_TIENE_HORARIO_PROPIO%', 'FALLA: error inesperado: ' || sqlerrm;
    end;
  end $$;
rollback;
\echo '  ✔  6. Cambiar o quitar el horario sólo rige de hoy en adelante'

-- =====================================================================
--  6b. Ponerlo, quitarlo y volverlo a poner el mismo día
-- =====================================================================
-- Un horario que empezaba HOY no rigió ningún día cerrado, así que
-- quitarlo no cierra nada: apaga el horario y deja la asignación
-- inerte. Esa fila sigue ocupando el sitio —la base no admite dos
-- horarios vigentes a la vez para la misma persona—, y si volver a
-- ponerle uno no la reaprovechara, la operación reventaría contra la
-- restricción de solape. Es el caso de una administradora que se
-- equivoca de hora y lo rehace en la misma mañana.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v jsonb; begin
    v := (select jsonb_agg(jsonb_build_object('dia', d, 'trabaja', true, 'continua', true,
                                              'entrada','10:00','salida','16:00'))
            from generate_series(0,6) d);
    r := public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo poner el horario de Ana';
    assert (select entry_time from app.effective_day(current_setting('t.ana')::uuid, current_date)) = '10:00',
      'FALLA: el horario nuevo de Ana no rige hoy';

    r := public.quitar_horario_propio(current_setting('t.ana')::uuid);
    assert r->>'accion' = 'retirado',
      'FALLA: quitar un horario que empezaba hoy debía retirarlo, no cerrarlo';
    assert (select entry_time from app.effective_day(current_setting('t.ana')::uuid, current_date)) = '08:00',
      'FALLA: Ana no volvió al horario de su sede';
    assert not (public.horarios_propios(null) @> to_jsonb(current_setting('t.ana'))),
      'FALLA: Ana sigue marcada con horario propio después de quitárselo';

    -- Y ahora otra vez, con la hora corregida
    v := (select jsonb_agg(jsonb_build_object('dia', d, 'trabaja', true, 'continua', true,
                                              'entrada','11:00','salida','17:00'))
            from generate_series(0,6) d);
    r := public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    assert (r->>'ok')::boolean, 'FALLA GRAVE: no se le puede volver a poner horario el mismo día';
    assert (select entry_time from app.effective_day(current_setting('t.ana')::uuid, current_date)) = '11:00',
      'FALLA GRAVE: se guardó pero se le sigue midiendo con el horario anterior';

    -- Una sola asignación vigente: dos serían una contradicción
    assert (select count(*) from schedule_assignments a
             where a.employee_id = current_setting('t.ana')::uuid
               and current_date between a.valid_from and coalesce(a.valid_to,'infinity'::date)) = 1,
      'FALLA GRAVE: a Ana le quedaron dos horarios vigentes el mismo día';

    -- Rehacerlo tres veces no llena la sede de horarios sueltos
    perform public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    perform public.dar_horario_propio(current_setting('t.ana')::uuid, v);
    assert (select count(*) from work_schedules
             where name like 'Horario propio · Ana%' and is_active) = 1,
      'FALLA: cada corrección del mismo día deja un horario huérfano en la sede';
  end $$;
rollback;
\echo '  ✔  6b. Se puede poner, quitar y rehacer el mismo día sin romper nada'

-- =====================================================================
--  7. El panel sabe a quién marcar, y sólo de su sede
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','33333333-3333-3333-3333-333333333333',
    'app_role','admin','branch_id',current_setting('t.css'))::text, true);
  set local role authenticated;
  do $$ declare v jsonb; ok boolean := false; begin
    v := public.horarios_propios(null);
    assert not (v @> to_jsonb(current_setting('t.beto'))),
      'FALLA GRAVE: la administradora de Caja Seca ve marcado a alguien de Maracaibo';

    begin perform public.horario_de_trabajador(current_setting('t.beto')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: la administradora de Caja Seca leyó el horario de alguien de Maracaibo';
  end $$;
rollback;

begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare v jsonb; r jsonb; begin
    v := public.horarios_propios(null);
    assert v @> to_jsonb(current_setting('t.beto')),
      'FALLA: Beto tiene horario propio y el panel no lo marcaría';
    assert not (v @> to_jsonb(current_setting('t.ana'))),
      'FALLA: Ana no tiene horario propio y saldría marcada';

    r := public.horario_de_trabajador(current_setting('t.ana')::uuid);
    assert not (r->>'propio')::boolean and jsonb_array_length(r->'dias') = 7,
      'FALLA: el horario de sede de Ana no se lee completo';
    assert (r->'dias'->1->>'entrada') = '08:00:00',
      'FALLA: el horario que se muestra de Ana no es el de su sede';

    r := public.horario_de_trabajador(current_setting('t.beto')::uuid);
    assert (r->>'propio')::boolean and (r->'dias'->1->>'entrada') = '09:00:00',
      'FALLA: no se muestra el horario propio de Beto';
  end $$;
rollback;
\echo '  ✔  7. El panel marca quién tiene horario propio, sede por sede'

\echo ''
\echo '  ✔  BATERÍA DE HORARIOS PROPIOS COMPLETA — todas las validaciones pasaron'
