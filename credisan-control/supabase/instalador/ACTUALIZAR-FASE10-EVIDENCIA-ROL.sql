-- =====================================================================
--
--   CREDISAN CONTROL · LA EVIDENCIA VUELVE A LA MATRIZ APROBADA
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA: cambia una condición.
--
--   QUÉ CAMBIA
--   ----------
--   Al habilitar la consulta de la fotografía dejé que el JEFE OPERATIVO
--   también pudiera verla. La matriz de roles que usted aprobó dice que
--   no. Vuelve a dirección y administración, cada una en su sede.
--
--   El jefe operativo sigue viendo quién marcó, a qué hora y si fue con
--   foto o sin ella. Si algo no le cuadra, lo reporta como novedad y
--   administración mira la fotografía.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
  if to_regproc('public.evidencia_de') is null then
    raise exception 'Falta ACTUALIZAR-FASE7-EVIDENCIA.sql.';
  end if;
end $$;

-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0020 · La evidencia vuelve a la matriz
-- de roles aprobada
-- =====================================================================
-- SÓLO CAMBIA UNA CONDICIÓN.
--
-- Al habilitar la consulta de la fotografía decidí, por mi cuenta, que
-- el JEFE OPERATIVO también pudiera verla. El argumento era bueno: es
-- quien está en el mostrador y quien notaría que la cara no corresponde
-- al PIN.
--
-- Pero la matriz de roles de la Fase 1 —la que se aprobó antes de
-- escribir una línea— dice que la evidencia es SIN ACCESO para ese rol,
-- y la política `evidence_select` de la migración 0008 dice lo mismo.
-- Ensanchar por mi cuenta un permiso sobre la fotografía de la cara de
-- una persona no es una decisión que me corresponda: es de lo más
-- sensible que guarda este sistema.
--
-- Vuelve a dirección y administración, cada una en su sede.
--
-- LO OPERATIVO NO SE PIERDE
-- ------------------------
-- El jefe operativo sigue viendo QUIÉN marcó, A QUÉ HORA y si fue con
-- foto o sin ella. Si algo no le cuadra, lo reporta como novedad y
-- administración —que sí puede— mira la fotografía. Es un paso más, y
-- es el paso correcto: mirar la cara de alguien debería costar algo.
-- =====================================================================

create or replace function public.evidencia_de(p_event uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_a record; v_ruta text; v_actor uuid := auth.uid();
begin
  -- La condición que cambia. Antes bastaba con tener sesión y ver la
  -- sede; ahora hay que mandar en ella.
  if not (app.is_ceo() or app.is_admin()) then raise exception 'NO_AUTORIZADO'; end if;

  select a.id, a.branch_id, a.employee_id, a.work_date, a.event,
         a.recorded_at, a.evidence_status,
         e.first_name || ' ' || e.last_name as nombre
    into v_a
    from attendance_events a
    join employees e on e.id = a.employee_id
   where a.id = p_event;

  if not found then raise exception 'MARCACION_NO_ENCONTRADA'; end if;
  if not app.can_see_branch(v_a.branch_id) then raise exception 'NO_AUTORIZADO'; end if;

  if v_a.evidence_status = 'sin_evidencia' then
    return jsonb_build_object('ok', false, 'reason', 'SIN_EVIDENCIA',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;
  if v_a.evidence_status = 'purgada' then
    return jsonb_build_object('ok', false, 'reason', 'PURGADA',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;

  select ev.storage_path into v_ruta
    from attendance_evidence ev where ev.event_id = p_event;

  if v_ruta is null then
    return jsonb_build_object('ok', false, 'reason', 'PENDIENTE',
      'nombre', v_a.nombre, 'cuando', v_a.recorded_at);
  end if;

  -- Mirar sigue dejando rastro. Siempre, y antes de entregar nada.
  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (v_actor, 'user', 'evidencia.consultada', 'attendance_events', p_event, v_a.branch_id,
          jsonb_build_object('empleado', v_a.employee_id, 'nombre', v_a.nombre,
                             'fecha', v_a.work_date, 'evento', v_a.event));

  return jsonb_build_object('ok', true, 'ruta', v_ruta,
    'nombre', v_a.nombre, 'cuando', v_a.recorded_at,
    'evento', v_a.event, 'fecha', v_a.work_date);
end $$;
grant execute on function public.evidencia_de(uuid) to authenticated;


do $$
declare v_emp int;
begin
  select count(*) into v_emp from employees;
  raise notice '';
  raise notice '  CREDISAN CONTROL — permiso de evidencia ajustado';
  raise notice '  ----------------------------------------------';
  raise notice '  Trabajadores (intactos) ..... %', v_emp;
  raise notice '';
  raise notice '  La fotografía de una marcación la ven dirección y administración,';
  raise notice '  cada una en su sede. El jefe operativo, no.';
  raise notice '  Toda consulta sigue quedando registrada.';
  raise notice '';
end $$;
