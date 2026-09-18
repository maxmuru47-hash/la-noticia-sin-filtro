-- =====================================================================
-- CREDISAN CONTROL · El documento de una novedad
-- =====================================================================
-- Lo que esta batería exige, en el orden en que importa:
--
--   1. Que un REPOSO o un PERMISO no se puedan aprobar sin el papel. De
--      esto depende que nadie cobre una semana completa por una
--      ausencia sostenida de palabra.
--   2. Que un SOCIO pueda cargar el permiso que dio gerencia —con el
--      documento— y nada más que eso.
--   3. Que una ruta inventada no cuente como documento.
--   4. Que abrir un reposo médico deje rastro, y que no lo abra
--      cualquiera.
--   5. Que el jefe operativo NO haya perdido nada de lo suyo.
--
-- La quinta es la lección de la fase 11: cerrar una puerta es fácil;
-- cerrarla sólo para quien sobra es el trabajo.
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
  ('aaaaaaaa-0000-0000-0000-000000000001','ramiro@credisan.test'),
  ('aaaaaaaa-0000-0000-0000-000000000002','eliana@credisan.test');

insert into profiles (id, full_name, role, branch_id) values
  ('11111111-1111-1111-1111-111111111111','Dirección General','ceo',        null),
  ('22222222-2222-2222-2222-222222222222','Admin Maracaibo',  'admin',      :'mcb'),
  ('33333333-3333-3333-3333-333333333333','Admin Caja Seca',  'admin',      :'css'),
  ('55555555-5555-5555-5555-555555555555','Jefe Maracaibo',   'supervisor', :'mcb');

begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','11111111-1111-1111-1111-111111111111','app_role','ceo')::text, true);
  set local role authenticated;
  select public.guardar_socio('ramiro@credisan.test','Ramiro Araujo', array[:'mcb'::uuid, :'css'::uuid]);
  select public.guardar_socio('eliana@credisan.test','Eliana González', array[:'mcb'::uuid]);
commit;

insert into employees (branch_id, internal_code, first_name, last_name, national_id, position, hired_on)
values (:'mcb','MCB-001','Ana', 'Pérez','V-1','Cajera','2026-01-15'),
       (:'mcb','MCB-002','Beto','Ruiz', 'V-2','Asesor','2026-01-15'),
       (:'css','CSS-001','Carla','Soto','V-3','Cajera','2026-01-15');

select id as e_ana  from employees where internal_code = 'MCB-001' \gset
select id as e_beto from employees where internal_code = 'MCB-002' \gset
select id as e_carla from employees where internal_code = 'CSS-001' \gset
select set_config('t.ana', :'e_ana', false), set_config('t.beto', :'e_beto', false),
       set_config('t.carla', :'e_carla', false);

-- «El archivo está ahí». Salta la regla de subida a propósito: quién
-- puede subir y dónde tiene su propia comprobación más abajo, y si este
-- andamio la respetara no se podría montar el caso de un archivo
-- guardado en la carpeta equivocada.
create or replace function pg_temp.subir(p_branch uuid, p_nombre text, p_duenio uuid)
returns text language plpgsql security definer as $$
declare v_ruta text := p_branch::text || '/' || p_nombre;
begin
  insert into storage.objects (bucket_id, name, owner) values ('novedades', v_ruta, p_duenio)
  on conflict do nothing;
  return v_ruta;
end $$;

-- =====================================================================
--  1. Un reposo no se aprueba de palabra
-- =====================================================================
-- LA prueba de la fase. El jefe reporta que Ana faltó por reposo; el
-- papel todavía no llegó. Administración NO puede aprobarlo, y por
-- tanto el día no se justifica y la semana no se paga completa.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.registrar_novedad(current_setting('t.ana')::uuid, 'reposo',
           'Reposo médico de tres días', now() - interval '2 hours', null, null);
    assert (r->>'ok')::boolean, 'FALLA: el jefe operativo no pudo reportar el reposo';
    perform set_config('t.nov', r->>'id', false);
  end $$;

  -- Administración intenta aprobarlo sin el papel
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare r jsonb; ok boolean := false; begin
    begin
      r := public.resolver_novedad(current_setting('t.nov')::uuid, true, 'Va');
    exception when others then ok := sqlerrm like '%FALTA_DOCUMENTO%'; end;
    assert ok,
      'FALLA GRAVE: se aprobó un reposo médico sin el documento. Eso es pagarle '
      'una semana completa a alguien por un papel que nadie ha visto.';

    assert (select status from incidents where id = current_setting('t.nov')::uuid) = 'pendiente',
      'FALLA GRAVE: la novedad cambió de estado pese al rechazo';

    -- Y el listado lo dice, para que nadie se pregunte por qué no puede
    declare v jsonb; begin
      v := public.novedades(current_setting('t.mcb')::uuid, 'pendiente', 50) -> 0;
      assert not (v->>'tiene_documento')::boolean, 'FALLA: dice tener documento y no lo tiene';
      assert (v->>'requiere_documento')::boolean,  'FALLA: un reposo debe exigir documento';
      assert not (v->>'puede_aprobarse')::boolean, 'FALLA: se ofrece aprobar algo que no se puede';
    end;
  end $$;
rollback;
\echo '  ✔  1. Un reposo sin papel no se aprueba, y el panel dice por qué'

-- =====================================================================
--  2. Con el papel, sí — y la semana se paga completa
-- =====================================================================
-- El caso real entero: el jefe reporta el lunes, el documento llega
-- después, administración aprueba, y el día deja de ser una ausencia.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; v_dia date; begin
    -- Un día laborable YA CERRADO, para que la ausencia sea firme
    v_dia := (select case when extract(isodow from current_date - 1) between 1 and 5
                          then current_date - 1
                          else (date_trunc('week', current_date) - interval '3 days')::date end);
    perform set_config('t.dia', v_dia::text, false);

    -- Ana y Beto faltan los dos el mismo día. Sólo Ana traerá reposo.
    r := public.registrar_novedad(current_setting('t.ana')::uuid, 'reposo',
           'Reposo médico', (v_dia + time '08:00') at time zone 'America/Caracas', null, null);
    perform set_config('t.nov', r->>'id', false);
  end $$;

  -- El socio sube el papel que le hizo llegar la familia
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  do $$ declare r jsonb; v_ruta text; begin
    v_ruta := pg_temp.subir(current_setting('t.mcb')::uuid, 'reposo-ana.pdf',
                            'aaaaaaaa-0000-0000-0000-000000000001');
    r := public.adjuntar_documento(current_setting('t.nov')::uuid, v_ruta);
    assert (r->>'ok')::boolean, 'FALLA: el socio no pudo adjuntar el reposo: ' || r::text;
    assert not (r->>'sustituido')::boolean, 'FALLA: dice que sustituyó algo que no existía';
  end $$;

  -- Ahora sí, administración aprueba
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare r jsonb; v_dia date := current_setting('t.dia')::date; begin
    r := public.resolver_novedad(current_setting('t.nov')::uuid, true, 'Reposo consignado');
    assert (r->>'ok')::boolean, 'FALLA GRAVE: con el documento adjunto sigue sin poder aprobarse';

    -- El día de Ana deja de ser una ausencia
    perform app.recompute_daily(current_setting('t.ana')::uuid, v_dia);
    perform app.recompute_daily(current_setting('t.beto')::uuid, v_dia);

    assert (select status::text from attendance_daily
             where employee_id = current_setting('t.ana')::uuid and work_date = v_dia) = 'justificado',
      'FALLA GRAVE: el reposo aprobado no justificó el día';
    assert (select status::text from attendance_daily
             where employee_id = current_setting('t.beto')::uuid and work_date = v_dia) = 'ausente',
      'FALLA: Beto faltó sin justificar y no quedó como ausente';

    -- Y eso es lo que permite cancelarle completo: Ana tiene UNA
    -- ausencia menos que Beto, que faltó el mismo día sin papel.
    declare v_lunes date; v_ana int; v_beto int; a_ana numeric; a_beto numeric; begin
      v_lunes := date_trunc('week', v_dia)::date;
      -- Por el camino del panel, no por la función interna: así se
      -- comprueba de paso que ese camino sigue entero.
      perform public.calcular_cierre_semana(current_setting('t.mcb')::uuid, v_lunes);

      select absence_count, attendance_pct into v_ana, a_ana from weekly_closures
       where employee_id = current_setting('t.ana')::uuid and week_start = v_lunes;
      select absence_count, attendance_pct into v_beto, a_beto from weekly_closures
       where employee_id = current_setting('t.beto')::uuid and week_start = v_lunes;

      assert v_ana = v_beto - 1,
        'FALLA GRAVE: el reposo aprobado no llegó al cierre de la semana. Ana tiene '
        || v_ana || ' ausencias y Beto, que faltó igual y sin papel, ' || v_beto;
      assert a_ana > a_beto,
        'FALLA GRAVE: la asistencia de Ana no mejoró al justificarse el día ('
        || a_ana || ' vs ' || a_beto || ')';
    end;
  end $$;
rollback;
\echo '  ✔  2. Con el papel se aprueba, el día se justifica y la semana lo refleja'

-- =====================================================================
--  3. El socio carga el permiso de gerencia, y nada más
-- =====================================================================
-- Aquí manda ELIANA, que sólo ve Maracaibo: así la última comprobación
-- —que no toque Caja Seca— prueba algo. Con Ramiro, que ve las tres,
-- habría pasado sin significar nada.
begin;
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000002','app_role','socio')::text, true);
  set local role authenticated;
  do $$ declare r jsonb; ok boolean; v_ruta text;
        v_yo uuid := 'aaaaaaaa-0000-0000-0000-000000000002'; begin
    -- 3a · Con documento y de un tipo que es una autorización: SÍ
    v_ruta := pg_temp.subir(current_setting('t.mcb')::uuid, 'permiso-beto.pdf', v_yo);
    r := public.registrar_novedad(current_setting('t.beto')::uuid, 'permiso',
           'Permiso autorizado por gerencia', now(), null, v_ruta);
    assert (r->>'ok')::boolean, 'FALLA: el socio no pudo cargar el permiso de gerencia';

    -- 3b · Sin documento: NO. Sería un reporte de oídas.
    ok := false;
    begin perform public.registrar_novedad(current_setting('t.beto')::uuid, 'permiso',
            'Permiso que me contaron', now(), null, null);
    exception when others then ok := sqlerrm like '%row-level security%'; end;
    assert ok, 'FALLA GRAVE: un socio creó una novedad sin documento';

    -- 3c · De un tipo operativo: NO, aunque traiga papel
    v_ruta := pg_temp.subir(current_setting('t.mcb')::uuid, 'nota.pdf', v_yo);
    ok := false;
    begin perform public.registrar_novedad(current_setting('t.beto')::uuid, 'olvido_marcacion',
            'Se le olvidó marcar', now(), null, v_ruta);
    exception when others then ok := sqlerrm like '%row-level security%'; end;
    assert ok, 'FALLA GRAVE: un socio reportó una novedad operativa';

    -- 3d · En una sede que no es suya: NO
    ok := false;
    begin perform public.registrar_novedad(current_setting('t.carla')::uuid, 'permiso',
            'Permiso en otra sede', now(), null,
            pg_temp.subir(current_setting('t.css')::uuid, 'x.pdf', v_yo));
    exception when others then ok := true; end;
    assert ok, 'FALLA GRAVE: Eliana creó una novedad en Caja Seca, que no ve';
    assert (select count(*) from incidents i
             where i.branch_id = current_setting('t.css')::uuid) = 0,
      'FALLA GRAVE: quedó una novedad en una sede que ese socio no ve';

    -- 3e · Y sigue sin poder aprobar nada.
    --
    -- Ojo con cómo se comprueba: la regla de la base no lanza un error,
    -- FILTRA. El UPDATE del socio no encuentra ninguna fila que pueda
    -- tocar, así que la función responde «no se pudo» con toda calma.
    -- Exigir una excepción aquí habría hecho fallar una prueba correcta.
    r := public.resolver_novedad(
           (select id from incidents where status = 'pendiente' limit 1), true, null);
    assert not (r->>'ok')::boolean, 'FALLA GRAVE: un socio aprobó una novedad';
    assert (select count(*) from incidents where status = 'aprobada') = 0,
      'FALLA GRAVE: quedó una novedad aprobada por un socio';
  end $$;
rollback;
\echo '  ✔  3. El socio carga el papel de gerencia; ni reporta ni aprueba'

-- =====================================================================
--  4. Una ruta inventada no es un documento
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare ok boolean; v_ruta text; begin
    -- 4·0 · Antes que nada: la administradora de Maracaibo no puede
    -- siquiera DEPOSITAR un archivo en la carpeta de Caja Seca.
    ok := false;
    begin
      insert into storage.objects (bucket_id, name, owner)
      values ('novedades', current_setting('t.css') || '/intruso.pdf',
              '22222222-2222-2222-2222-222222222222');
    exception when others then ok := sqlerrm like '%row-level security%'; end;
    assert ok, 'FALLA GRAVE: se subió un documento a la carpeta de otra sede';

    -- 4a · Un archivo que nadie subió
    ok := false;
    begin perform public.registrar_novedad(current_setting('t.ana')::uuid, 'permiso',
            'Permiso con papel imaginario', now(), null,
            current_setting('t.mcb') || '/no-existe.pdf');
    exception when others then ok := sqlerrm like '%DOCUMENTO_NO_SUBIDO%'; end;
    assert ok, 'FALLA GRAVE: se aceptó como documento una ruta que no existe';

    -- 4b · Un archivo real, pero en la carpeta de otra sede
    v_ruta := pg_temp.subir(current_setting('t.css')::uuid, 'ajeno.pdf',
                            '22222222-2222-2222-2222-222222222222');
    ok := false;
    begin perform public.registrar_novedad(current_setting('t.ana')::uuid, 'permiso',
            'Permiso guardado donde no va', now(), null, v_ruta);
    exception when others then ok := sqlerrm like '%DOCUMENTO_DE_OTRA_SEDE%'; end;
    assert ok, 'FALLA GRAVE: se aceptó un documento guardado en otra sede';
  end $$;
rollback;
\echo '  ✔  4. Una ruta inventada o de otra sede no cuenta como documento'

-- =====================================================================
--  5. Abrir un reposo médico deja rastro, y no lo abre cualquiera
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.registrar_novedad(current_setting('t.ana')::uuid, 'reposo',
           'Reposo médico', now(), null, null);
    perform set_config('t.nov', r->>'id', false);
  end $$;

  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  do $$ declare r jsonb; begin
    r := public.adjuntar_documento(current_setting('t.nov')::uuid,
           pg_temp.subir(current_setting('t.mcb')::uuid, 'reposo-2.pdf',
                         'aaaaaaaa-0000-0000-0000-000000000001'));
    assert (r->>'ok')::boolean, 'FALLA: el socio no pudo adjuntar';

    -- Quien lo subió puede volver a abrirlo
    r := public.documento_de_novedad(current_setting('t.nov')::uuid);
    assert (r->>'ok')::boolean, 'FALLA: quien subió el documento no puede abrirlo';
  end $$;

  -- El OTRO socio de la misma sede, no: un reposo es un dato de salud
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000002','app_role','socio')::text, true);
  do $$ declare ok boolean := false; begin
    begin perform public.documento_de_novedad(current_setting('t.nov')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: un socio abrió el reposo médico que subió otro';
  end $$;

  -- El jefe operativo tampoco, aunque él reportó la novedad
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare ok boolean := false; begin
    begin perform public.documento_de_novedad(current_setting('t.nov')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: el jefe operativo abrió un reposo médico';
  end $$;

  -- La administradora de OTRA sede, menos todavía
  select set_config('request.jwt.claims', json_build_object('sub','33333333-3333-3333-3333-333333333333',
    'app_role','admin','branch_id',current_setting('t.css'))::text, true);
  do $$ declare ok boolean := false; begin
    begin perform public.documento_de_novedad(current_setting('t.nov')::uuid);
    exception when others then ok := sqlerrm like '%NO_AUTORIZADO%'; end;
    assert ok, 'FALLA GRAVE: la administradora de Caja Seca abrió un documento de Maracaibo';
  end $$;

  -- Administración de SU sede sí, y queda anotada
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare r jsonb; begin
    r := public.documento_de_novedad(current_setting('t.nov')::uuid);
    assert (r->>'ok')::boolean, 'FALLA: administración no pudo abrir el documento';
    assert r->>'ruta' is not null, 'FALLA: no devolvió la ruta';

    assert exists (select 1 from audit_logs
                    where action = 'novedad.documento_consultado'
                      and entity_id = current_setting('t.nov')::uuid
                      and actor_id = '22222222-2222-2222-2222-222222222222'),
      'FALLA GRAVE: se abrió un reposo médico y no quedó rastro de quién';
  end $$;

  -- Y el listado NO entrega la ruta: abrir el documento tiene que pasar
  -- por la función que anota, o el rastro sería opcional.
  do $$ declare v jsonb; begin
    v := public.novedades(current_setting('t.mcb')::uuid, null, 50) -> 0;
    assert v->'ruta' is null and v->'evidence_path' is null,
      'FALLA GRAVE: el listado entrega la ruta y se puede abrir sin dejar rastro';
    assert (v->>'tiene_documento')::boolean, 'FALLA: no dice que tiene documento';
  end $$;
rollback;
\echo '  ✔  5. El documento lo abren dirección, administración y quien lo subió — y queda anotado'

-- =====================================================================
--  6. Adjuntar después: una vez, y sin borrar pruebas
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.registrar_novedad(current_setting('t.ana')::uuid, 'permiso',
           'Permiso de gerencia', now(), null, null);
    perform set_config('t.nov', r->>'id', false);
    r := public.adjuntar_documento(current_setting('t.nov')::uuid,
           pg_temp.subir(current_setting('t.mcb')::uuid, 'p1.pdf',
                         '55555555-5555-5555-5555-555555555555'));
    assert (r->>'ok')::boolean, 'FALLA: el jefe no pudo adjuntar el documento';
  end $$;

  -- Un socio no puede sustituir un documento que ya está
  select set_config('request.jwt.claims',
    json_build_object('sub','aaaaaaaa-0000-0000-0000-000000000001','app_role','socio')::text, true);
  do $$ declare ok boolean := false; begin
    begin perform public.adjuntar_documento(current_setting('t.nov')::uuid,
            pg_temp.subir(current_setting('t.mcb')::uuid, 'p2.pdf',
                          'aaaaaaaa-0000-0000-0000-000000000001'));
    exception when others then ok := sqlerrm like '%YA_TIENE_DOCUMENTO%'; end;
    assert ok, 'FALLA GRAVE: un socio sustituyó el documento de una novedad';
  end $$;

  -- Administración sí, y queda anotado que sustituyó
  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare r jsonb; begin
    r := public.adjuntar_documento(current_setting('t.nov')::uuid,
           pg_temp.subir(current_setting('t.mcb')::uuid, 'p3.pdf',
                         '22222222-2222-2222-2222-222222222222'));
    assert (r->>'sustituido')::boolean, 'FALLA: no reconoció que estaba sustituyendo';
    assert exists (select 1 from audit_logs where action = 'novedad.documento_sustituido'
                    and entity_id = current_setting('t.nov')::uuid),
      'FALLA GRAVE: sustituir un documento no dejó rastro';

    -- Resuelta la novedad, ya no se le cuelgan papeles
    perform public.resolver_novedad(current_setting('t.nov')::uuid, true, null);
    declare ok boolean := false; begin
      begin perform public.adjuntar_documento(current_setting('t.nov')::uuid,
              pg_temp.subir(current_setting('t.mcb')::uuid, 'p4.pdf',
                            '22222222-2222-2222-2222-222222222222'));
      exception when others then ok := sqlerrm like '%NOVEDAD_YA_RESUELTA%'; end;
      assert ok, 'FALLA: se adjuntó un documento a una novedad ya revisada';
    end;
  end $$;
rollback;
\echo '  ✔  6. El documento se adjunta después, una vez, y sustituirlo deja rastro'

-- =====================================================================
--  7. El aviso a administración, sede por sede
-- =====================================================================
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    -- Una que espera papel y otra que no lo necesita
    perform public.registrar_novedad(current_setting('t.ana')::uuid, 'reposo',
              'Reposo sin papel todavía', now(), null, null);
    perform public.registrar_novedad(current_setting('t.beto')::uuid, 'olvido_marcacion',
              'Olvidó marcar la salida', now(), null, null);
  end $$;

  select set_config('request.jwt.claims', json_build_object('sub','22222222-2222-2222-2222-222222222222',
    'app_role','admin','branch_id',current_setting('t.mcb'))::text, true);
  do $$ declare r jsonb; begin
    r := public.novedades_pendientes(null);
    assert (r->>'total')::int = 2, 'FALLA: no cuenta las dos pendientes: ' || r::text;
    assert (r->>'listas')::int = 1,
      'FALLA: sólo una se puede resolver ya, y dice ' || (r->>'listas');
    assert (r->>'sin_documento')::int = 1,
      'FALLA: una espera papel, y dice ' || (r->>'sin_documento');
  end $$;

  -- La administradora de Caja Seca no ve las de Maracaibo
  select set_config('request.jwt.claims', json_build_object('sub','33333333-3333-3333-3333-333333333333',
    'app_role','admin','branch_id',current_setting('t.css'))::text, true);
  do $$ declare r jsonb; begin
    r := public.novedades_pendientes(null);
    assert (r->>'total')::int = 0,
      'FALLA GRAVE: Caja Seca ve novedades pendientes de Maracaibo: ' || r::text;
  end $$;
rollback;
\echo '  ✔  7. El contador avisa a administración, y sólo de su sede'

-- =====================================================================
--  8. El jefe operativo no perdió nada de lo suyo
-- =====================================================================
-- La lección de la fase 11: cerrar una puerta es fácil; cerrarla sólo
-- para quien sobra es el trabajo.
begin;
  select set_config('request.jwt.claims', json_build_object('sub','55555555-5555-5555-5555-555555555555',
    'app_role','supervisor','branch_id',current_setting('t.mcb'))::text, true);
  set local role authenticated;
  do $$ declare r jsonb; t text; begin
    -- Sigue reportando CUALQUIER tipo, con papel o sin él
    foreach t in array array['permiso','reposo','olvido_marcacion','falla_tecnica','emergencia'] loop
      r := public.registrar_novedad(current_setting('t.ana')::uuid, t,
             'Reporte del jefe operativo: ' || t, now(), null, null);
      assert (r->>'ok')::boolean, 'FALLA GRAVE: el jefe operativo ya no puede reportar «' || t || '»';
    end loop;

    -- Y sigue viendo su lista
    assert jsonb_array_length(public.novedades(current_setting('t.mcb')::uuid, 'pendiente', 50)) = 5,
      'FALLA: el jefe operativo no ve las novedades que acaba de reportar';

    -- Lo que sigue sin poder es resolver
    declare ok boolean := false; begin
      begin perform public.resolver_novedad(
        (public.novedades(current_setting('t.mcb')::uuid,'pendiente',1)->0->>'id')::uuid, true, null);
      exception when others then ok := true; end;
      assert ok or (select count(*) from incidents where status = 'aprobada') = 0,
        'FALLA GRAVE: el jefe operativo aprobó una novedad';
    end;
  end $$;
rollback;
\echo '  ✔  8. El jefe operativo sigue reportando todo lo suyo, y sigue sin resolver'

\echo ''
\echo '  ✔  BATERÍA DE DOCUMENTOS COMPLETA — todas las validaciones pasaron'
