-- =====================================================================
--
--   CREDISAN CONTROL · FASE 14 · el mantenimiento recupera lo perdido
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA: cambia dos funciones
--   del mantenimiento nocturno y añade un ajuste.
--
--   PARA QUÉ SIRVE
--   --------------
--   Entre el 18 y el 21 de septiembre el mantenimiento nocturno falló
--   TRES noches seguidas, porque la contraseña de la base dejó de
--   funcionar y ninguna tarea pudo conectarse.
--
--   Que una noche falle va a volver a pasar. Lo que no puede pasar es
--   que esa noche se pierda PARA SIEMPRE, y eso es lo que ocurría: la
--   tarea cerraba literalmente AYER, así que al recuperarse la conexión
--   los días de en medio se quedaban sin calcular y nadie se enteraba
--   hasta mirar un reporte que no cuadraba.
--
--   Desde ahora el cierre nocturno repasa los ÚLTIMOS CUATRO DÍAS y el
--   cierre semanal repasa también la semana anterior. Si todo funcionó,
--   repasar no cambia nada. Si faltó una noche, se recupera sola.
--
--   LO QUE NO SE TOCA: lo que administración ya revisó. Una semana
--   aprobada no se recalcula — ni sus cifras, ni su nota, ni quién la
--   cerró.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

-- =====================================================================
-- FASE 14 · el mantenimiento recupera las noches que se perdió
-- =====================================================================
-- LO QUE PASÓ, Y POR QUÉ ESTO EXISTE
-- ----------------------------------
-- Entre el 18 y el 21 de septiembre de 2026 el mantenimiento nocturno
-- falló TRES noches seguidas. La causa no fue el código: la contraseña
-- de la base dejó de funcionar y ninguna tarea pudo conectarse.
--
-- El problema no fue que fallara —eso va a volver a pasar: una clave
-- caduca, un servidor no contesta, una red se cae—. El problema es que
-- `job_close_yesterday()` cerraba literalmente AYER. Una noche perdida
-- se perdía PARA SIEMPRE: al arreglarse la conexión, la tarea de la
-- noche siguiente volvía a cerrar sólo su propio ayer, y los días de en
-- medio se quedaban sin calcular sin que nadie se enterara.
--
-- En un sistema de asistencia eso significa ausencias que no aparecen y
-- semanas que no cuadran, hasta que alguien lo nota mirando un reporte.
--
-- LO QUE CAMBIA
-- -------------
-- El cierre nocturno repasa los últimos días, no sólo el último. Si
-- todas las noches funcionaron, los demás días ya estaban calculados y
-- volver a calcularlos no cambia nada: `recompute_daily` reescribe la
-- misma fila con el mismo resultado. Si faltó alguna, se recupera sola.
--
-- Recalcular el pasado ya era una operación normal de este sistema
-- —el panel la ofrece en «Recalcular»— y las fases anteriores están
-- construidas contando con ella: por eso la fase 12 cierra los horarios
-- con fecha en vez de borrarlos, para que un recálculo de un día viejo
-- lo mida con el horario que regía ESE día.
--
-- Cuántos días se repasan es configuración, no código.
-- =====================================================================

do $$
begin
  if to_regprocedure('app.job_close_yesterday()') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
end $$;

-- El «where scope_branch_id is null» no sobra: el índice único de los
-- ajustes globales es PARCIAL, y sin repetir su condición PostgreSQL no
-- lo reconoce y rechaza el ON CONFLICT entero.
insert into system_settings (key, value, description) values
  ('maintenance.catchup_days', '4',
   'Días hacia atrás que repasa el cierre nocturno. Sirve para recuperar noches en que la tarea no pudo ejecutarse.')
on conflict (key) where scope_branch_id is null do nothing;

-- ── El cierre nocturno, con memoria ──────────────────────────────────
-- Se conserva el nombre: es el que llama el flujo de GitHub, y cambiarlo
-- obligaría a tocar también la rama principal del repositorio. Lo que
-- cambia es cuánto mira hacia atrás.
create or replace function app.job_close_yesterday()
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare b record; n int := 0; v_dias int; d int;
begin
  v_dias := greatest(app.setting_int('maintenance.catchup_days', null, 4), 1);

  for b in select id, timezone from branches where is_active and deleted_at is null loop
    -- Del más viejo al más reciente: si algo falla a mitad, lo que queda
    -- hecho es lo más antiguo, que es lo que llevaba más tiempo sin
    -- calcularse.
    for d in reverse v_dias .. 1 loop
      n := n + app.recompute_daily_branch(b.id, (now() at time zone b.timezone)::date - d);
    end loop;
  end loop;

  return n;
end $$;

-- ── El cierre semanal, con la misma memoria ──────────────────────────
-- Corre los lunes. Si el lunes la tarea no pudo ejecutarse, esa semana
-- se quedaba sin cerrar hasta que alguien lo pidiera a mano. Ahora
-- repasa también la anterior.
--
-- Es seguro, y no por poco: `compute_weekly_closure` lleva escrito
-- `where status = 'pendiente'`, así que una semana YA REVISADA no se
-- recalcula en absoluto. Lo que administración aprobó se queda como lo
-- aprobó; sólo se refrescan las semanas que nadie ha tocado todavía.
create or replace function app.job_weekly_closures()
returns integer language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare e record; v_week date; n int := 0; s int;
begin
  for s in 1 .. 2 loop
    v_week := (date_trunc('week', current_date) - make_interval(days => 7 * s))::date;
    for e in select id from employees where is_active and deleted_at is null loop
      perform app.compute_weekly_closure(e.id, v_week);
      n := n + 1;
    end loop;
  end loop;
  return n;
end $$;

grant execute on function app.job_close_yesterday() to service_role;
grant execute on function app.job_weekly_closures() to service_role;

-- ── Comprobación final ───────────────────────────────────────────────
do $$
declare v_dias int;
begin
  v_dias := app.setting_int('maintenance.catchup_days', null, 0);
  if v_dias < 1 then
    raise exception 'La fase 14 no quedó completa: falta el ajuste de días a repasar.';
  end if;

  raise notice ' ';
  raise notice '  CREDISAN CONTROL — el mantenimiento ya recupera lo perdido';
  raise notice '  --------------------------------------------------------';
  raise notice '  Días que repasa cada noche ......... %', v_dias;
  raise notice '  Trabajadores (intactos) ............ %',
    (select count(*) from employees where deleted_at is null);
  raise notice ' ';
  raise notice '  Una noche caída se recupera en la siguiente.';
  raise notice '  Lo que administración aprobó no lo deshace ninguna tarea.';
  raise notice ' ';
end $$;
