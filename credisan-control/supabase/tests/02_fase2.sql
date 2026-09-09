-- =====================================================================
-- CREDISAN CONTROL · Batería de la Fase 2 (panel administrativo)
-- =====================================================================
\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

insert into auth.users (id, email) values
  ('11111111-1111-1111-1111-111111111111','ceo@credisan.test'),
  ('55555555-5555-5555-5555-555555555555','nuevo@credisan.test');

-- ── 1. El primer usuario se convierte en CEO ─────────────────────────
begin;
  select set_config('request.jwt.claims','{"sub":"11111111-1111-1111-1111-111111111111"}',true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.reclamar_ceo('Dirección General');
    assert (r->>'ok')::boolean and r->>'reason'='CEO_CREADO', 'FALLA: no creó el CEO inicial';
    assert public.mi_perfil()->>'rol' = 'ceo', 'FALLA: mi_perfil no devuelve el rol';
  end $$;
commit;

-- ── 2. La puerta se cierra tras el primero ───────────────────────────
begin;
  select set_config('request.jwt.claims','{"sub":"55555555-5555-5555-5555-555555555555"}',true);
  set local role authenticated;
  do $$ declare r jsonb; begin
    r := public.reclamar_ceo('Intruso');
    assert not (r->>'ok')::boolean and r->>'reason'='YA_HAY_USUARIOS',
           'FALLA GRAVE: un segundo usuario se hizo CEO';
  end $$;
rollback;

-- ── 3. Crear sede, horario y accesos ─────────────────────────────────
begin;
  select set_config('request.jwt.claims','{"sub":"11111111-1111-1111-1111-111111111111","app_role":"ceo"}',true);
  set local role authenticated;
  do $$ declare r jsonb; v uuid; id_sab uuid; ok boolean; begin
    r := public.crear_sede('VAL','Valencia');
    assert (r->>'ok')::boolean, 'FALLA: no creó la sede';
    v := (r->>'branch_id')::uuid;

    -- Una sede nace completa: terminal, horario de 7 días y asignación vigente
    assert (select count(*) from terminals where branch_id = v) = 1, 'FALLA: sin terminal';
    assert (select count(*) from schedule_days d join work_schedules w on w.id=d.schedule_id
             where w.branch_id = v) = 7, 'FALLA: sin los 7 días de horario';
    assert (select count(*) from schedule_assignments where branch_id = v) = 1, 'FALLA: sin asignación';

    r := public.horario_sede(v);
    assert (r->>'ok')::boolean and jsonb_array_length(r->'dias') = 7, 'FALLA: horario_sede';

    -- Activar el sábado es configuración, no despliegue
    select (d->>'id')::uuid into id_sab
      from jsonb_array_elements(r->'dias') d where (d->>'weekday')::int = 6;
    assert not (select is_working from schedule_days where id = id_sab),
           'FALLA: el sábado no nace inactivo';

    r := public.guardar_dia_horario(id_sab, true, true, '08:00', null, null, '14:00');
    assert (r->>'ok')::boolean, 'FALLA: no activó el sábado';
    assert (select is_working and is_continuous from schedule_days where id = id_sab),
           'FALLA: el sábado no quedó como jornada corrida';

    -- Horas en desorden: rechazo con mensaje claro, no violación de constraint
    ok := false;
    begin perform public.guardar_dia_horario(id_sab, true, true, '14:00', null, null, '08:00');
    exception when others then ok := (sqlerrm like '%ORDEN_INVALIDO%'); end;
    assert ok, 'FALLA: aceptó horas en desorden';

    r := public.registrar_usuario('nuevo@credisan.test','Admin Valencia','admin', v);
    assert (r->>'ok')::boolean, 'FALLA: no registró la administradora';
    assert (select role from profiles where id='55555555-5555-5555-5555-555555555555') = 'admin',
           'FALLA: rol incorrecto';

    -- Un usuario que no existe en Auth no recibe perfil fantasma
    ok := false;
    begin perform public.registrar_usuario('fantasma@credisan.test','X','admin', v);
    exception when others then ok := (sqlerrm like '%USUARIO_NO_EXISTE%'); end;
    assert ok, 'FALLA: creó un perfil sin usuario detrás';

    assert jsonb_array_length(public.resumen_sedes()) = 4, 'FALLA: resumen_sedes';
  end $$;
rollback;

-- ── 4. Una administradora no gobierna la empresa ─────────────────────
begin;
  select set_config('request.jwt.claims',
    '{"sub":"55555555-5555-5555-5555-555555555555","app_role":"admin","branch_id":"00000000-0000-0000-0000-000000000000"}',true);
  set local role authenticated;
  do $$ declare ok boolean; begin
    ok := false;
    begin perform public.crear_sede('XXX','Prueba');
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: una administradora creó una sede';

    ok := false;
    begin perform public.registrar_usuario('x@y.z','X','ceo',null);
    exception when others then ok := (sqlerrm like '%NO_AUTORIZADO%'); end;
    assert ok, 'FALLA GRAVE: una administradora dio acceso a alguien';
  end $$;
rollback;

\echo ''
\echo '  ✔  BATERÍA DE FASE 2 COMPLETA — todas las validaciones pasaron'
\echo ''
