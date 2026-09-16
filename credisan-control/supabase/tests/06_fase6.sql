-- =====================================================================
-- CREDISAN CONTROL · Batería de la Fase 6 (modo sin conexión)
-- =====================================================================
-- Lo que de verdad hay que demostrar aquí es que un aparato en manos de
-- cualquiera no puede colar marcaciones falsas: ni repetirlas, ni
-- fecharlas hacia atrás, ni marcar por gente de otra sede, ni probar PIN
-- al azar sin dejar rastro.
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

select id as mcb from branches where code = 'MCB' \gset
select id as css from branches where code = 'CSS' \gset
select set_config('t.mcb', :'mcb', false), set_config('t.css', :'css', false);

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','ceo@credisan.test'),
  ('33333333-3333-3333-3333-333333333333','jefe.mcb@credisan.test');
insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección','ceo', null),
  ('33333333-3333-3333-3333-333333333333','Jefe MCB','supervisor', :'mcb');

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana','Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Luis','Rojas','V-2','Asesor','2026-01-15'),
       (:'css','CSS-001','Mara','Silva','V-3','Supervisora','2026-01-15');
select id as e1 from employees where internal_code='MCB-001' \gset
select id as e2 from employees where internal_code='MCB-002' \gset
select id as e3 from employees where internal_code='CSS-001' \gset
select set_config('t.e1', :'e1', false), set_config('t.e2', :'e2', false),
       set_config('t.e3', :'e3', false);

-- Jornada de hoy anclada a la hora actual, para que haya evento pendiente
insert into schedule_exceptions (scope, employee_id, kind, date_from, date_to, is_working, is_continuous,
        entry_time, lunch_out_time, lunch_in_time, exit_time, description)
select 'employee', e.id, 'jornada_especial', x.d, x.d, true, false,
       (x.t - interval '2 minutes')::time, (x.t + interval '5 minutes')::time,
       (x.t + interval '10 minutes')::time, (x.t + interval '15 minutes')::time, 'prueba'
from employees e, (select (now() at time zone 'America/Caracas')::date d,
                          (now() at time zone 'America/Caracas')::time t) x;

-- PIN y terminal emparejado
do $$ declare v_term uuid; r jsonb; v_code text; begin
  select id into v_term from terminals where code = 'MCB-01';
  r := public.edge_asignar_pin(current_setting('t.e1')::uuid, 'PEPPER', null);
  perform set_config('t.pin', r->>'pin', false);
  r := public.edge_asignar_pin(current_setting('t.e2')::uuid, 'PEPPER', null);
  perform set_config('t.pin2', r->>'pin', false);
  r := public.edge_asignar_pin(current_setting('t.e3')::uuid, 'PEPPER', null);
  perform set_config('t.pin3', r->>'pin', false);
  v_code := app.create_pairing_code(v_term, null);
  r := public.edge_emparejar('MCB-01', v_code, 'Tableta de prueba');
  perform set_config('t.term', v_term::text, false);
end $$;

-- =====================================================================
--  1. Una marcación sin conexión se registra con la hora del aparato
-- =====================================================================
do $$ declare r jsonb; v_hace timestamptz; begin
  v_hace := now() - interval '3 hours';
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), v_hace, 1, 12);

  assert (r->>'ok')::boolean, 'FALLA: no se registró la marcación offline: ' || (r->>'reason');
  assert (r->>'origin') = 'offline' or (select origin from attendance_events
            where id = (r->>'id')::uuid) = 'offline', 'FALLA: no quedó marcada como offline';
  assert (select device_seq from attendance_events where id = (r->>'id')::uuid) = 1,
         'FALLA: no se guardó la secuencia';
  assert (select device_timestamp from attendance_events where id = (r->>'id')::uuid) = v_hace,
         'FALLA: no se respetó la hora declarada por el aparato';
  assert (select recorded_at from attendance_events where id = (r->>'id')::uuid) = v_hace,
         'FALLA GRAVE: la hora que cuenta no es la del aparato';
end $$;
\echo '  ✔  1. La marcación sin conexión entra con la hora del aparato'

-- =====================================================================
--  2. La secuencia no puede repetirse ni retroceder
-- =====================================================================
do $$ declare r jsonb; begin
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() - interval '2 hours', 1, 0);
  assert not (r->>'ok')::boolean and (r->>'reason') = 'SECUENCIA_INVALIDA',
         'FALLA GRAVE: se aceptó una secuencia repetida: ' || r::text;

  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() - interval '2 hours', 0, 0);
  assert not (r->>'ok')::boolean and (r->>'reason') = 'SECUENCIA_INVALIDA',
         'FALLA GRAVE: se aceptó una secuencia hacia atrás';

  -- Y cada rechazo deja su rastro
  assert (select count(*) from audit_logs
           where action = 'offline.rechazado' and metadata->>'motivo' = 'SECUENCIA_INVALIDA') = 2,
         'FALLA: los rechazos por secuencia no quedaron auditados';
end $$;
\echo '  ✔  2. Repetir o retroceder la secuencia se rechaza y se audita'

-- =====================================================================
--  3. No se puede fechar fuera de la ventana ni en el futuro
-- =====================================================================
do $$ declare r jsonb; begin
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() + interval '30 minutes', 10, 0);
  assert (r->>'reason') = 'HORA_FUTURA',
         'FALLA GRAVE: se aceptó una marcación del futuro: ' || r::text;

  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() - interval '100 hours', 11, 0);
  assert (r->>'reason') = 'FUERA_DE_VENTANA_OFFLINE',
         'FALLA GRAVE: se aceptó una marcación de hace cuatro días: ' || r::text;

  -- Anterior a la última sincronización: es un reenvío disfrazado
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() - interval '10 hours', 12, 0);
  assert (r->>'reason') = 'ANTERIOR_A_ULTIMA_SINCRONIZACION',
         'FALLA GRAVE: se coló una marcación anterior a la última sincronía: ' || r::text;
end $$;
\echo '  ✔  3. Ni futuro, ni fuera de la ventana de 72 h, ni antes de la última sincronía'

-- =====================================================================
--  4. El PIN sigue mandando: ni ajeno, ni de otra sede
-- =====================================================================
do $$ declare r jsonb; v_antes int; begin
  select count(*) into v_antes from pin_attempts;

  r := public.edge_offline(current_setting('t.term')::uuid, '000000',
        'PEPPER', gen_random_uuid(), now() - interval '1 hour', 20, 0);
  assert (r->>'reason') = 'PIN_INVALIDO', 'FALLA: un PIN inexistente no fue rechazado';

  -- Con la pimienta equivocada, el mismo PIN bueno tampoco sirve
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'OTRA_PIMIENTA', gen_random_uuid(), now() - interval '1 hour', 21, 0);
  assert (r->>'reason') = 'PIN_INVALIDO',
         'FALLA GRAVE: el PIN se validó sin la pimienta correcta';

  -- La trabajadora de Caja Seca no marca en el terminal de Maracaibo
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin3'),
        'PEPPER', gen_random_uuid(), now() - interval '1 hour', 22, 0);
  assert (r->>'reason') = 'EMPLEADO_OTRA_SEDE',
         'FALLA GRAVE: se marcó en la sede ajena sin conexión: ' || r::text;

  -- Todo intento deja rastro, y marcado como offline
  assert (select count(*) from pin_attempts) > v_antes,
         'FALLA GRAVE: probar PIN sin conexión no deja rastro';
  assert (select count(*) from pin_attempts where reason like 'offline:%') >= 2,
         'FALLA: los intentos no se distinguen de los de en línea';
end $$;
\echo '  ✔  4. PIN inexistente, pimienta falsa y sede ajena: rechazados y con rastro'

-- =====================================================================
--  5. UNA COLA ENTERA tras un corte largo entra completa
-- =====================================================================
-- Ésta es la prueba que importa, y la que destapó el fallo: `last_sync_at`
-- se guardaba con la hora del SERVIDOR, así que la primera marcación de la
-- cola dejaba el listón en «ahora» y rechazaba a todas sus compañeras por
-- ser más antiguas. Un terminal tres horas sin señal sincronizaba una sola
-- marcación y perdía el resto.
do $$
declare r jsonb; v_base timestamptz; v_ok int := 0; v_seq bigint := 100;
        v_fallos text := '';
begin
  -- Cuatro marcaciones encoladas y enviadas en orden. Se arranca DESPUÉS
  -- de lo último que declaró este aparato: una cola real siempre avanza,
  -- y retroceder es justo lo que el motor debe seguir rechazando.
  select coalesce(last_sync_at, now() - interval '4 hours') + interval '5 minutes'
    into v_base from terminals where id = current_setting('t.term')::uuid;
  for i in 0..3 loop
    r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin2'),
          'PEPPER', gen_random_uuid(), v_base + make_interval(mins => i * 20), v_seq, 3);
    v_seq := v_seq + 1;
    if (r->>'ok')::boolean then v_ok := v_ok + 1;
    else v_fallos := v_fallos || ' [' || i || ':' || (r->>'reason') || ']'; end if;
  end loop;

  assert v_ok = 4,
    'FALLA GRAVE: de 4 marcaciones encoladas sólo entraron ' || v_ok || '.' || v_fallos;

  -- Y el listón quedó en la hora que declaró el aparato, no en la del servidor
  -- Y el listón quedó donde lo dejó el APARATO —la última de la cola—,
  -- no en la hora del servidor, que es lo que rompía todo.
  assert (select last_sync_at from terminals where id = current_setting('t.term')::uuid)
         = v_base + interval '60 minutes',
    'FALLA GRAVE: last_sync_at no refleja la hora declarada por el aparato: '
    || (select last_sync_at from terminals where id = current_setting('t.term')::uuid)::text;
  assert (select last_sync_at from terminals where id = current_setting('t.term')::uuid)
         < now() - interval '30 minutes',
    'FALLA GRAVE: last_sync_at se puso con la hora del servidor otra vez';
end $$;
\echo '  ✔  5. Una cola de cuatro marcaciones tras un corte largo entra entera'

-- =====================================================================
--  6. Idempotencia: el mismo envío dos veces no duplica
-- =====================================================================
do $$ declare v_id uuid := gen_random_uuid(); r1 jsonb; r2 jsonb; v_n int; begin
  select count(*) into v_n from attendance_events;
  r1 := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
         'PEPPER', v_id, now() - interval '30 minutes', 110, 0);
  r2 := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
         'PEPPER', v_id, now() - interval '30 minutes', 111, 0);

  assert (r1->>'ok')::boolean, 'FALLA: el primer envío no entró: ' || (r1->>'reason');
  assert (r2->>'ok')::boolean, 'FALLA: el reenvío debería responder que ya estaba';
  assert (r2->>'duplicado')::boolean, 'FALLA GRAVE: el reenvío no se reconoció como duplicado';
  assert (select count(*) from attendance_events) = v_n + 1,
         'FALLA GRAVE: el reenvío creó una marcación de más';
end $$;
\echo '  ✔  6. Reenviar lo mismo no duplica la marcación'

-- =====================================================================
--  7. Un reloj desviado no se pierde: se manda a revisión
-- =====================================================================
do $$ declare r jsonb; begin
  r := public.edge_offline(current_setting('t.term')::uuid, current_setting('t.pin'),
        'PEPPER', gen_random_uuid(), now() - interval '20 minutes', 120, 900);

  assert (r->>'ok')::boolean, 'FALLA GRAVE: un reloj desviado tumbó la marcación';
  assert exists (select 1 from incidents
                  where kind = 'desfase_reloj' and related_event = (r->>'id')::uuid),
         'FALLA: no se abrió la novedad por desfase de reloj';
  assert (select clock_drift_sec from attendance_events where id = (r->>'id')::uuid) = 900,
         'FALLA: no se guardó el desfase medido';
end $$;
\echo '  ✔  7. Un reloj desviado registra la marcación y abre novedad'

-- =====================================================================
--  8. El panel no puede llamar a la puerta del servidor
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare ok boolean := false; begin
    begin perform public.edge_offline(current_setting('t.term')::uuid, '123456',
            'PEPPER', gen_random_uuid(), now(), 99, 0);
    exception when insufficient_privilege then ok := true;
              when others then ok := (sqlerrm like '%permission denied%'); end;
    assert ok, 'FALLA GRAVE: el CEO pudo ejecutar edge_offline desde el panel';
  end $$;
rollback;
\echo '  ✔  8. Ni el CEO puede marcar por la puerta del servidor'

-- =====================================================================
--  9. Lo que el panel sí puede ver
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  do $$ declare s jsonb; t jsonb; m jsonb; begin
    s := public.sincronizacion(7);
    assert (s->>'ok')::boolean, 'FALLA: sincronizacion';
    assert jsonb_array_length(s->'terminales') = 3, 'FALLA: no salen los tres terminales';

    select f into t from jsonb_array_elements(s->'terminales') f
     where f->>'code' = 'MCB-01';
    assert (t->>'emparejado')::boolean, 'FALLA: el terminal figura sin emparejar';
    assert (t->>'marcaciones_offline')::int >= 3, 'FALLA: no cuenta las marcaciones offline';
    assert (t->>'rechazos')::int >= 5, 'FALLA: no cuenta los rechazos';
    assert (t->'motivos'->>'SECUENCIA_INVALIDA')::int = 2, 'FALLA: no desglosa los motivos';
    assert (t->>'ultima_secuencia')::bigint >= 120, 'FALLA: no refleja la última secuencia';

    m := public.marcaciones_offline(null, 7);
    assert (m->>'filas')::int >= 3, 'FALLA: no lista las marcaciones offline';
    assert m::text like '%Ana Pérez%', 'FALLA: no dice de quién son';
    assert not (m::text ~* 'salario|weekly_base|pin_hash'),
           'FALLA GRAVE: expone datos que no debe';
  end $$;
rollback;
\echo '  ✔  9. El panel ve la salud de cada terminal y qué llegó sin conexión'

-- =====================================================================
-- 10. El jefe operativo ve su sede, y sólo su sede
-- =====================================================================
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','33333333-3333-3333-3333-333333333333',
                      'app_role','supervisor','branch_id', :'mcb')::text, true);
  set local role authenticated;
  do $$ declare s jsonb; m jsonb; ok boolean := false; begin
    s := public.sincronizacion(7);
    assert jsonb_array_length(s->'terminales') = 1,
           'FALLA GRAVE: el jefe vio terminales de otras sedes';
    assert not (s::text like '%CSS-01%'), 'FALLA GRAVE: fuga de terminal ajeno';

    m := public.marcaciones_offline(null, 7);
    assert not (m::text like '%Silva%'), 'FALLA GRAVE: fuga entre sedes';

    begin perform public.marcaciones_offline(current_setting('t.css')::uuid, 7);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: el jefe consultó la sede ajena';
  end $$;
rollback;
\echo '  ✔ 10. El jefe operativo ve su terminal y ninguno más'

\echo ''
\echo '  ✔  BATERÍA DE FASE 6 COMPLETA — todas las validaciones pasaron'
\echo ''
