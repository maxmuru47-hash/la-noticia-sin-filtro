-- =====================================================================
-- FASE 15 · EL JEFE OPERATIVO VE EL CIERRE DE SU SEMANA
-- =====================================================================
-- Max lo pidió expreso, mirando la pantalla de cierres.
--
-- QUÉ CAMBIA, Y QUÉ NO
--
-- Cambia: el jefe operativo puede LEER el cierre semanal de su sede.
-- Ve lo mismo que ya ve a diario —quién vino, a qué hora, cuánto
-- trabajó— pero sumado por semana. No es una categoría nueva de
-- información: es la misma, agrupada.
--
-- NO cambia: revisar, aprobar, reabrir y calcular la semana siguen
-- siendo de dirección y administración. Eso no lo guarda un `if` dentro
-- de una función sino la política de UPDATE de `weekly_closures`, que
-- aquí no se toca. Tampoco se le abre el reporte de asistencia ni la
-- auditoría, que siguen cerrados.
--
-- Y sigue sin haber una sola cifra de dinero en lo que devuelve: el
-- cierre mide e informa, no calcula descuentos.
-- =====================================================================

-- ── 1 · La función de lectura ─────────────────────────────────
-- Idéntica a la de la Fase 5 salvo la línea que decide quién entra.
create or replace function public.cierres(p_branch uuid, p_lunes date)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb; v_total jsonb; v_sede text;
begin
  -- El jefe operativo LEE el de su sede. No revisa, no cierra y no
  -- calcula: eso sigue siendo de dirección y administración, y lo
  -- impide la política de UPDATE de la tabla, no este `if`.
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if not app.can_see_branch(p_branch) then raise exception 'NO_AUTORIZADO'; end if;

  select name into v_sede from branches where id = p_branch;

  select coalesce(jsonb_agg(f order by f->>'nombre'), '[]'::jsonb) into v_filas
    from (
      select jsonb_build_object(
        'id', c.id,
        'empleado_id', c.employee_id,
        'nombre', e.first_name || ' ' || e.last_name,
        'cargo', e.position,
        'minutos_esperados', c.expected_minutes,
        'minutos_trabajados', c.worked_minutes,
        'asistencia', c.attendance_pct,
        'puntualidad', c.punctuality_pct,
        'anticipados', c.early_count,
        'retrasos', c.late_count,
        'minutos_retraso', c.late_minutes,
        'ausencias', c.absence_count,
        'incompletos', c.incomplete_count,
        'novedades', c.incident_count,
        'sin_evidencia', c.no_evidence_count,
        'estado', c.status,
        'nota', c.note,
        'calculado', c.computed_at,
        'cerrado_por', p.full_name,
        'cerrado_en', c.closed_at) f
        from weekly_closures c
        join employees e on e.id = c.employee_id
        left join profiles p on p.id = c.closed_by
       where c.branch_id = p_branch and c.week_start = p_lunes) s;

  select jsonb_build_object(
    'trabajadores',    count(*),
    'pendientes',      count(*) filter (where (f->>'estado') = 'pendiente'),
    'minutos_retraso', coalesce(sum((f->>'minutos_retraso')::int), 0),
    'ausencias',       coalesce(sum((f->>'ausencias')::int), 0),
    'asistencia',      case when count(*) > 0
                         then round(avg((f->>'asistencia')::numeric), 1) else null end,
    'puntualidad',     case when count(*) > 0
                         then round(avg((f->>'puntualidad')::numeric), 1) else null end)
    into v_total from jsonb_array_elements(v_filas) f;

  return jsonb_build_object('ok', true, 'sede', v_sede, 'semana', p_lunes,
    'hasta', p_lunes + 6, 'calculado', jsonb_array_length(v_filas) > 0,
    'total', v_total, 'cierres', v_filas);
end $$;
grant execute on function public.cierres(uuid, date) to authenticated;

-- ── 2 · La tabla ────────────────────────────────────────
-- La función de arriba es `security definer` y pasa por encima de la
-- política, pero el panel también consulta la tabla directamente en
-- algún sitio, y dos reglas distintas para el mismo dato acaban
-- contradiciéndose. Se abre la LECTURA al jefe operativo de su sede.
--
-- La de ESCRITURA se deja exactamente como estaba: `closures_update`
-- no se toca en todo este archivo.
drop policy if exists closures_select on weekly_closures;
create policy closures_select on weekly_closures for select to authenticated
  using (app.can_see_branch(branch_id));

comment on policy closures_select on weekly_closures is
  'Lectura por sede, cualquier acceso. Escribir —revisar, aprobar, reabrir— '
  'sigue siendo de dirección y administración: véase closures_update.';
