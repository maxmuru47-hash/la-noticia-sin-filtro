-- =====================================================================
-- CREDISAN CONTROL · Batería de los socios
-- =====================================================================
-- Un socio ve las sedes que se le asignen, y nada más. Aquí no se
-- comprueba que el panel le esconda los botones —eso es cortesía—, sino
-- que la BASE DE DATOS no le entregue lo que no le toca, pregunte como
-- pregunte.
--
-- Los cinco socios son los reales, con sus sedes reales, porque una
-- prueba con «socio1» y «socio2» no habría detectado que Eliana y
-- Marilyn tienen que ver cosas distintas.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as css from branches where code = 'CSS' \gset
select id as mcb from branches where code = 'MCB' \gset
select id as mcy from branches where code = 'MCY' \gset
select set_config('t.css', :'css', false),
       set_config('t.mcb', :'mcb', false),
       set_config('t.mcy', :'mcy', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','direccion@credisan.test'),
  ('22222222-2222-2222-2222-222222222222','admin.mcb@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000001','eliana@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000002','reynaldo@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000003','ramiro@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000004','marilyn@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000005','marienny@credisan.test'),
  ('55555555-5555-5555-5555-555555555555','jefe.mcb@credisan.test');

insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección General','ceo', null),
  ('22222222-2222-2222-2222-222222222222','Admin Maracaibo','admin', :'mcb'),
  ('55555555-5555-5555-5555-555555555555','Jefe Maracaibo','supervisor', :'mcb');

-- Un trabajador por sede, con su salario, para tener qué mirar
insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'css','CSS-001','Ana','Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-001','Beto','Ruiz','V-2','Asesor','2026-01-15'),
       (:'mcy','MCY-001','Carla','Soto','V-3','Cajera','2026-01-15');

insert into employee_compensation (employee_id, weekly_base, effective_from)
select id, 50, '2026-01-15' from employees;

-- =====================================================================
--  1. Dirección reparte las sedes, y sólo dirección
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.guardar_socio('eliana@credisan.test',  'Eliana González',
           array[current_setting('t.mcb')::uuid, current_setting('t.mcy')::uuid]);
    assert (r->>'ok')::boolean and (r->>'sedes')::int = 2, 'FALLA: no se creó Eliana: ' || r::text;

    r := public.guardar_socio('ramiro@credisan.test', 'Ramiro Araujo',
           array[current_setting('t.css')::uuid, current_setting('t.mcb')::uuid,
                 current_setting('t.mcy')::uuid]);
    assert (r->>'sedes')::int = 3, 'FALLA: Ramiro debía quedar con las tres sedes';

    r := public.guardar_socio('marilyn@credisan.test', 'Marilyn González',
           array[current_setting('t.css')::uuid]);
    assert (r->>'sedes')::int = 1, 'FALLA: Marilyn debía quedar sólo con Caja Seca';

    -- Un socio sin sedes no vería nada: mejor impedirlo que dejarlo ciego
    begin
      r := public.guardar_socio('marienny@credisan.test', 'Marienny González', array[]::uuid[]);
      assert false, 'FALLA: se aceptó un socio sin ninguna sede';
    exception when others then
      assert sqlerrm like '%SIN_SEDES%', 'FALLA: el error no fue el esperado: ' || sqlerrm;
    end;

    -- Y una sede inventada tampoco
    begin
      r := public.guardar_socio('marienny@credisan.test', 'Marienny González',
             array[gen_random_uuid()]);
      assert false, 'FALLA: se aceptó una sede que no existe';
    exception when others then
      assert sqlerrm like '%SEDE_DESCONOCIDA%', 'FALLA: ' || sqlerrm;
    end;
  end $$;

  -- La administradora de Maracaibo no reparte accesos de nadie
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', current_setting('t.mcb'))::text, true);
  do $$ declare ok boolean := false; begin
    begin perform public.guardar_socio('marienny@credisan.test','Marienny',
             array[current_setting('t.css')::uuid]);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: administración pudo crear un socio';
  end $$;
rollback;
\echo '  ✔  1. Sólo dirección reparte sedes, y ni vacías ni inventadas'

-- Se dejan creados para el resto de la batería
select set_config('request.jwt.claims',
  json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, false);
select public.guardar_socio('eliana@credisan.test','Eliana González',
         array[:'mcb'::uuid, :'mcy'::uuid]);
select public.guardar_socio('ramiro@credisan.test','Ramiro Araujo',
         array[:'css'::uuid, :'mcb'::uuid, :'mcy'::uuid]);
select public.guardar_socio('marilyn@credisan.test','Marilyn González', array[:'css'::uuid]);

-- =====================================================================
--  2. Cada socio ve exactamente sus sedes
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employees) = 2,
      'FALLA GRAVE: Eliana ve ' || (select count(*) from employees) || ' trabajadores, debían ser 2';
    assert not exists (select 1 from employees where branch_id = current_setting('t.css')::uuid),
      'FALLA GRAVE: Eliana vio personal de Caja Seca, que no es suya';
    assert (select count(*) from branches) = 2,
      'FALLA: Eliana debía ver dos sedes en el selector';
  end $$;
rollback;

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000004','app_role','socio')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employees) = 1,
      'FALLA GRAVE: Marilyn ve más de una sede';
    assert exists (select 1 from employees where branch_id = current_setting('t.css')::uuid),
      'FALLA: Marilyn no ve su propia sede';
  end $$;
rollback;

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000003','app_role','socio')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employees) = 3, 'FALLA: Ramiro debía ver las tres sedes';
  end $$;
rollback;
\echo '  ✔  2. Eliana ve dos sedes, Marilyn una y Ramiro las tres'

-- =====================================================================
--  3. Ningún socio ve un salario
-- =====================================================================
-- No hace falta ninguna regla nueva para esto: la política del salario
-- exige ceo o admin. Se comprueba porque es una promesa, no un detalle.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000003','app_role','socio')::text, true);
  set local role authenticated;
  do $$ begin
    assert (select count(*) from employee_compensation) = 0,
      'FALLA GRAVE: un socio vio salarios';
  end $$;
rollback;
\echo '  ✔  3. Los salarios devuelven cero filas para un socio'

-- =====================================================================
--  4. Ningún socio abre una fotografía
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000003','app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.evidencia_de(gen_random_uuid());
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio pudo pedir la ruta de una fotografía';

    assert (select count(*) from attendance_evidence) = 0,
      'FALLA GRAVE: un socio ve metadatos de evidencia';
  end $$;
rollback;
\echo '  ✔  4. La evidencia fotográfica le está cerrada'

-- =====================================================================
--  5. UN SOCIO NO ESCRIBE NADA
-- =====================================================================
-- Ésta es la batería que de verdad importa. La puerta de las novedades
-- estaba abierta —pedía sede, no rol— porque el jefe operativo tiene
-- que poder reportar. Se cerró en esta misma fase.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000003','app_role','socio')::text, true);
  set local role authenticated;

  do $$ declare ok boolean; v_emp uuid; begin
    select id into v_emp from employees limit 1;

    -- Novedades: la puerta que había que cerrar.
    --
    -- El insert va EXACTAMENTE como lo haría el jefe operativo, sin
    -- nombrar status ni source: esas dos columnas no están en el GRANT
    -- y nombrarlas hace que falle por permisos de columna ANTES de que
    -- la regla opine. La primera versión de esta prueba hacía justo eso
    -- y pasaba sin comprobar nada.
    ok := false;
    begin
      insert into incidents (employee_id, branch_id, kind, description,
                             occurred_from, work_date, reported_by)
      select v_emp, e.branch_id, (select code from incident_kinds limit 1),
             'inventada', now(), current_date, auth.uid()
        from employees e where e.id = v_emp;
    exception when others then
      ok := sqlerrm like '%row-level security%';
      assert ok, 'FALLA: la novedad se rechazó, pero no por la regla de seguridad: ' || sqlerrm;
    end;
    assert ok, 'FALLA GRAVE: un socio creó una novedad';

    -- Personal
    ok := false;
    begin
      insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
      values (current_setting('t.mcb')::uuid,'X-999','Falso','Trabajador','V-999','Cajero','2026-01-01');
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: un socio dio de alta a un trabajador';

    ok := false;
    begin update employees set position = 'Gerente' where id = v_emp;
      ok := not found;
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: un socio modificó a un trabajador';

    -- Horarios
    ok := false;
    begin update schedule_days set entry_time = '10:00';
      ok := not found;
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: un socio cambió un horario';

    -- Salario
    ok := false;
    begin
      insert into employee_compensation (employee_id, weekly_base, effective_from)
      values (v_emp, 1, '2026-06-01');
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: un socio escribió un salario';
  end $$;
rollback;
\echo '  ✔  5. No crea novedades, ni personal, ni horarios, ni salarios'

-- =====================================================================
--  5b. Y el jefe operativo SIGUE pudiendo reportar
-- =====================================================================
-- Cerrar una puerta es fácil; cerrarla sólo para quien sobra es el
-- trabajo. Esa regla existe para que el jefe operativo reporte lo que ve
-- en el mostrador, y eso no puede haberse roto de camino.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','55555555-5555-5555-5555-555555555555','app_role','supervisor',
                      'branch_id', current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare v_emp uuid; v_br uuid; begin
    select id, branch_id into v_emp, v_br from employees limit 1;
    insert into incidents (employee_id, branch_id, kind, description,
                           occurred_from, work_date, reported_by)
    values (v_emp, v_br, (select code from incident_kinds limit 1),
            'reporte legítimo', now(), current_date, auth.uid());
    assert found, 'FALLA GRAVE: el jefe operativo ya no puede reportar novedades';
  end $$;
rollback;
\echo '  ✔  5b. El jefe operativo no perdió nada: sigue reportando'

-- =====================================================================
--  6. Tampoco cierra semanas ni recalcula nada
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000003','app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare ok boolean; begin
    ok := false;
    begin perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid, date_trunc('week', current_date)::date);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio calculó un cierre';

    ok := false;
    begin perform public.recalcular_rango(current_setting('t.mcb')::uuid, current_date, current_date);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio recalculó el día';

    ok := false;
    begin perform public.socios();
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio vio la lista de accesos de los demás';
  end $$;
rollback;
\echo '  ✔  6. No calcula cierres, no recalcula y no ve los accesos ajenos'

-- =====================================================================
--  7. Lo que SÍ puede: consultar sus sedes
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; ok boolean := false; begin
    r := public.tablero_hoy(current_setting('t.mcb')::uuid);
    assert r ? 'resumen', 'FALLA: Eliana no pudo ver el tablero de Maracaibo';

    r := public.cierres(current_setting('t.mcy')::uuid, date_trunc('week', current_date)::date);
    assert r is not null, 'FALLA: Eliana no pudo ver los cierres de Maracay';

    -- El reporte NO entra en su alcance: el panel no se lo ofrece, así
    -- que tampoco se le concede por debajo.
    begin perform public.reporte_asistencia(current_setting('t.mcb')::uuid,
                    current_date - 7, current_date);
      assert false, 'FALLA: el socio alcanzó el reporte, que no es suyo';
    exception when others then
      assert sqlerrm like '%NO_AUTORIZADO%', 'FALLA: ' || sqlerrm;
    end;

    -- Pero Caja Seca no es suya, y da igual por qué puerta pregunte
    begin perform public.tablero_hoy(current_setting('t.css')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: Eliana vio el tablero de Caja Seca';

    ok := false;
    begin perform public.cierres(current_setting('t.css')::uuid, date_trunc('week', current_date)::date);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: Eliana vio los cierres de Caja Seca';
  end $$;
rollback;
\echo '  ✔  7. Consulta tablero y cierres de SUS sedes, y sólo de ésas'

-- =====================================================================
--  8. Quitar una sede se nota en el acto
-- =====================================================================
-- Si un socio sale de una sede, el acceso tiene que cerrarse ese mismo
-- día, no cuando alguien se acuerde de revisarlo.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.guardar_socio('eliana@credisan.test','Eliana González',
           array[current_setting('t.mcy')::uuid]);
    assert (r->>'quitadas')::int = 1, 'FALLA: no se retiró Maracaibo: ' || r::text;
  end $$;

  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  do $$ declare ok boolean := false; begin
    assert (select count(*) from employees) = 1,
      'FALLA GRAVE: Eliana sigue viendo Maracaibo después de que se la quitaran';
    begin perform public.tablero_hoy(current_setting('t.mcb')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: el tablero de Maracaibo sigue abierto para Eliana';
  end $$;
rollback;
\echo '  ✔  8. Retirar una sede cierra el acceso en el acto'

-- =====================================================================
--  9. Nadie se convierte en socio por accidente
-- =====================================================================
-- Guardar un socio con el correo de una administradora la degradaría y
-- le quitaría su sede. Eso no puede pasar por un dedazo.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.guardar_socio('admin.mcb@credisan.test','Admin Maracaibo',
             array[current_setting('t.css')::uuid]);
    exception when others then ok := sqlerrm like '%YA_TIENE_OTRO_ACCESO%'; end;
    assert ok, 'FALLA GRAVE: una administradora fue convertida en socia';

    assert (select role from profiles where id = '22222222-2222-2222-2222-222222222222') = 'admin',
      'FALLA GRAVE: el rol de la administradora cambió';
    assert (select branch_id from profiles where id = '22222222-2222-2222-2222-222222222222')
             = current_setting('t.mcb')::uuid,
      'FALLA GRAVE: la administradora perdió su sede';
  end $$;
rollback;
\echo '  ✔  9. Un correo repetido no degrada a quien ya tenía otro acceso'

\echo ''
\echo '  ✔  BATERÍA DE SOCIOS COMPLETA — todas las validaciones pasaron'
