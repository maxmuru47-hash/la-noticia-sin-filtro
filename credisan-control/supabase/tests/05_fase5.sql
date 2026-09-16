-- =====================================================================
-- CREDISAN CONTROL · Batería de la Fase 5 (cierres, reportes, auditoría)
-- =====================================================================
-- La comprobación que manda aquí es la promesa hecha a Max: el cierre
-- semanal mide e informa, pero NUNCA descuenta dinero. Se verifica leyendo
-- el código fuente de las funciones: si alguna llegara a consultar la
-- tabla de salarios, esta batería se cae.
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

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Luis','Rojas','V-2','Asesor','2026-01-15'),
       (:'css','CSS-001','Mara','Silva','V-3','Supervisora','2026-01-15');
select id as e1 from employees where internal_code='MCB-001' \gset
select id as e3 from employees where internal_code='CSS-001' \gset
select set_config('t.e1', :'e1', false), set_config('t.e3', :'e3', false);

insert into employee_compensation (employee_id, weekly_base, effective_from)
values (:'e1', 150.00, '2026-01-15'), (:'e3', 200.00, '2026-01-15');

-- El lunes de la semana pasada: cerrada del todo, sin marcaciones.
select (date_trunc('week', current_date) - interval '7 days')::date as lunes \gset
select set_config('t.lunes', :'lunes', false);

-- =====================================================================
--  0. LA PROMESA: el cierre no sabe de dinero
-- =====================================================================
do $$
declare v_fn text; v_cols text;
begin
  -- Ninguna función del cierre CONSULTA la tabla de salarios. Se busca la
  -- referencia real —el nombre de la tabla o de la columna— y no la palabra
  -- «salario»: `auditoria` la lleva dentro, en la etiqueta legible «Cambio
  -- de salario», que es texto de pantalla y no una consulta. Buscar la
  -- palabra suelta es el mismo error que ya se cometió en la Fase 4
  -- encontrando «150» dentro de un UUID: una prueba que se cae sola.
  select string_agg(p.proname, ', ') into v_fn
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public'
     and p.proname in ('calcular_cierre_semana','cierres','revisar_cierre',
                       'reporte_asistencia')
     and p.prosrc ~* 'employee_compensation|weekly_base';
  assert v_fn is null,
    'FALLA GRAVE: una función del cierre consulta datos salariales: ' || v_fn;

  -- Y la tabla del cierre no guarda ninguna cifra de dinero.
  select string_agg(column_name, ', ') into v_cols
    from information_schema.columns
   where table_name = 'weekly_closures'
     and (column_name ~* 'salar|monto|amount|pago|deduc|descuent|bono|base'
          or data_type = 'money');
  assert v_cols is null,
    'FALLA GRAVE: weekly_closures tiene columnas de dinero: ' || v_cols;
end $$;
\echo '  ✔  0. El cierre no toca dinero, ni en el código ni en la tabla'

-- =====================================================================
--  1. Calcular la semana
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                       current_setting('t.lunes')::date);
    assert (r->>'ok')::boolean, 'FALLA: no se pudo calcular el cierre';
    assert (r->>'calculados')::int = 2, 'FALLA: calculados = ' || (r->>'calculados');
  end $$;

  -- Una semana sin marcaciones y con días laborables es 0% de asistencia:
  -- el cierre lo dice, no lo disimula.
  do $$ declare t jsonb; c jsonb; begin
    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    assert (t->>'ok')::boolean, 'FALLA: cierres';
    assert (t->>'calculado')::boolean, 'FALLA: la semana figura como no calculada';
    assert jsonb_array_length(t->'cierres') = 2, 'FALLA: no son dos cierres';
    assert (t->'total'->>'pendientes')::int = 2, 'FALLA: deberían estar los dos pendientes';

    c := t->'cierres'->0;
    assert (c->>'estado') = 'pendiente', 'FALLA: estado inicial';
    assert (c->>'ausencias')::int = 5, 'FALLA: ausencias = ' || (c->>'ausencias');
    assert (c->>'asistencia')::numeric = 0, 'FALLA: asistencia = ' || (c->>'asistencia');

    -- Ni rastro de dinero en lo que viaja al navegador
    assert not (t::text ~* 'salario|salary|weekly_base|150|200'),
           'FALLA GRAVE: el cierre expone información salarial';
  end $$;
rollback;
\echo '  ✔  1. La semana se calcula y el sábado apagado no cuenta como falta'

-- =====================================================================
--  2. El jefe operativo no entra aquí
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','supervisor','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare ok boolean; begin
    ok := false;
    begin perform public.cierres(current_setting('t.mcb')::uuid,
                                 current_setting('t.lunes')::date);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe operativo vio los cierres';

    ok := false;
    begin perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                                current_setting('t.lunes')::date);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe operativo calculó un cierre';

    ok := false;
    begin perform public.reporte_asistencia(current_setting('t.mcb')::uuid,
             current_setting('t.lunes')::date, current_setting('t.lunes')::date);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe operativo exportó un reporte';
  end $$;

  -- La auditoría es `security invoker`: no lanza excepción, simplemente la
  -- RLS no le entrega ni una fila. Que es exactamente lo que debe pasar.
  do $$ declare a jsonb; begin
    a := public.auditoria();
    assert (a->>'filas')::int = 0,
           'FALLA GRAVE: el jefe operativo leyó la auditoría: ' || (a->>'filas') || ' filas';
  end $$;
rollback;
\echo '  ✔  2. El jefe operativo no ve cierres, ni reportes, ni auditoría'

-- =====================================================================
--  3. Revisar, reabrir, y que recalcular no borre la decisión
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v_id uuid; t jsonb; begin
    perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                          current_setting('t.lunes')::date);
    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    v_id := (t->'cierres'->0->>'id')::uuid;

    -- Una observación sin explicación no se acepta
    declare ok boolean := false; begin
      begin perform public.revisar_cierre(v_id, 'con_observacion', '   ');
      exception when others then ok := (sqlerrm like '%FALTA_LA_OBSERVACION%'); end;
      assert ok, 'FALLA: se aceptó una observación vacía';
    end;

    r := public.revisar_cierre(v_id, 'con_observacion', 'Semana de inventario');
    assert (r->>'ok')::boolean, 'FALLA: no se pudo revisar';

    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    assert (t->'cierres'->0->>'estado') = 'con_observacion', 'FALLA: no quedó observado';
    assert (t->'cierres'->0->>'cerrado_por') = 'Admin MCB',
           'FALLA: no quedó firmado por quien cerró';

    -- Y ahora lo importante: recalcular NO puede borrar esa decisión
    r := public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                       current_setting('t.lunes')::date);
    assert (r->>'ya_revisados')::int = 1, 'FALLA: no se respetó el cierre revisado';
    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    assert (t->'cierres'->0->>'estado') = 'con_observacion',
           'FALLA GRAVE: recalcular borró la decisión de una persona';
    assert (t->'cierres'->0->>'nota') = 'Semana de inventario',
           'FALLA GRAVE: recalcular borró la observación escrita';

    -- Reabrir quita la firma, como exige la restricción de la tabla
    r := public.revisar_cierre(v_id, 'pendiente', null);
    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    assert (t->'cierres'->0->>'estado') = 'pendiente', 'FALLA: no se reabrió';
    assert (t->'cierres'->0->>'cerrado_por') is null, 'FALLA: quedó firma tras reabrir';
  end $$;
rollback;
\echo '  ✔  3. Se revisa, se reabre, y recalcular respeta lo ya decidido'

-- =====================================================================
--  4. Una sede no cierra la otra
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.calcular_cierre_semana(current_setting('t.css')::uuid,
                                                current_setting('t.lunes')::date);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: la administradora cerró la semana de otra sede';
  end $$;

  do $$ declare r jsonb; begin
    r := public.reporte_asistencia(null, current_setting('t.lunes')::date, current_date);
    assert not (r::text like '%Silva%'),
           'FALLA GRAVE: el reporte incluyó a la trabajadora de otra sede';
    assert not (r::text ~* 'V-1|V-2|V-3'),
           'FALLA GRAVE: el reporte lleva cédulas';
  end $$;
rollback;
\echo '  ✔  4. Ni se cierra ni se exporta la sede ajena, y el reporte no lleva cédulas'

-- =====================================================================
--  5. El lunes es lunes, y no se cierra el futuro
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare ok boolean; begin
    ok := false;
    begin perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                                current_setting('t.lunes')::date + 2);
    exception when others then ok := (sqlerrm like '%NO_ES_LUNES%'); end;
    assert ok, 'FALLA: se aceptó una semana que no empieza en lunes';

    ok := false;
    begin perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
             (date_trunc('week', current_date) + interval '7 days')::date);
    exception when others then ok := (sqlerrm like '%SEMANA_FUTURA%'); end;
    assert ok, 'FALLA: se aceptó cerrar una semana que aún no ha pasado';
  end $$;
rollback;
\echo '  ✔  5. No se cierra un miércoles ni una semana que no ha llegado'

-- =====================================================================
--  6. La auditoría deja rastro de quién decidió
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare t jsonb; a jsonb; v_id uuid; begin
    perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid,
                                          current_setting('t.lunes')::date);
    t := public.cierres(current_setting('t.mcb')::uuid, current_setting('t.lunes')::date);
    v_id := (t->'cierres'->0->>'id')::uuid;
    perform public.revisar_cierre(v_id, 'aprobado', 'Todo conforme');

    -- El cálculo deja su propia entrada; la revisión la deja el disparador
    -- de la Fase 1, con el antes y el después de la fila.
    a := public.auditoria();
    assert (a->>'filas')::int >= 2,
           'FALLA: la auditoría no registró el cálculo y la revisión';
    assert a::text like '%Se revisó un cierre semanal%',
           'FALLA: no consta la revisión con su etiqueta legible';
    assert a::text like '%Se calculó la semana completa%', 'FALLA: no consta el cálculo';
    assert a::text like '%Admin MCB%', 'FALLA: no consta quién la hizo';
    assert a::text like '%con_observacion%' or a::text like '%aprobado%',
           'FALLA: el rastro no dice en qué quedó el cierre';
    assert not (a::text ~* 'pepper|pin_hash|pin_lookup|service_role'),
           'FALLA GRAVE: la auditoría expone secretos';
  end $$;
rollback;
\echo '  ✔  6. Queda rastro de quién cerró y cuándo, sin filtrar secretos'

-- =====================================================================
--  7. La administradora ve el rastro de lo que ella misma gestiona
-- =====================================================================
-- Sin esto, la auditoría era medio ciega: la RLS filtra por sede, y varias
-- tablas se auditaban sin sede, así que la administradora no podía
-- consultar ni sus propios cambios de horario.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','22222222-2222-2222-2222-222222222222',
                      'app_role','admin','branch_id', :'mcb')::text, true);
  set local role authenticated;

  do $$ declare v_dia uuid; a jsonb; v_ent text; begin
    -- Activa el sábado de su sede, que es una gestión suya de la Fase 2
    select sd.id into v_dia
      from schedule_days sd
      join work_schedules ws on ws.id = sd.schedule_id
     where ws.branch_id = current_setting('t.mcb')::uuid and sd.weekday = 6;
    -- Sábado corrido de 8 a 13: es justo el caso que Max quiere poder activar
    perform public.guardar_dia_horario(v_dia, true, true, '08:00', null, null, '13:00');

    a := public.auditoria(null, null, null, 500);
    select string_agg(distinct x->>'entidad', ', ') into v_ent
      from jsonb_array_elements(a->'registros') x;

    assert a::text like '%schedule_days%',
      'FALLA: la administradora no ve su propio cambio de horario. Ve: ' || coalesce(v_ent,'nada');
    assert a::text like '%Cambio de horario%', 'FALLA: falta la etiqueta legible';
    assert a::text like '%is_working%',
      'FALLA: el rastro no dice qué campo cambió';
  end $$;

  -- Y lo que es global sigue siendo sólo de dirección
  do $$ declare a jsonb; begin
    a := public.auditoria(null, null, null, 500);
    assert not (a::text like '%"entidad": "branches"%'),
      'FALLA: la administradora ve el rastro de creación de sedes';
  end $$;
rollback;
\echo '  ✔  7. La administradora audita lo suyo; lo global sigue siendo de dirección'

-- =====================================================================
--  8. Nada de lo anterior se tocó
-- =====================================================================
do $$ begin
  assert (select count(*) from employees where deleted_at is null) = 3,
         'FALLA GRAVE: la Fase 5 alteró la lista de trabajadores';
  assert (select weekly_base from employee_compensation
           where employee_id = current_setting('t.e1')::uuid) = 150.00,
         'FALLA GRAVE: un salario cambió de valor';
  assert (select count(*) from weekly_closures) = 0,
         'FALLA: quedaron cierres de las pruebas (todas iban en transacción)';
end $$;

\echo ''
\echo '  ✔  BATERÍA DE FASE 5 COMPLETA — todas las validaciones pasaron'
\echo ''
