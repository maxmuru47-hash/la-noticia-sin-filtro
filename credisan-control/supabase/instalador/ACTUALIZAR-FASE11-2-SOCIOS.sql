-- =====================================================================
--
--   CREDISAN CONTROL · FASE 11 (2 de 2) · socios: una persona, varias sedes
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA: añade una tabla,
--   amplía una regla y CIERRA una puerta que estaba abierta.
--
--   Ejecute antes ACTUALIZAR-FASE11-1-ROL.sql.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

do $$
begin
  if not exists (select 1 from pg_enum e join pg_type t on t.oid = e.enumtypid
                  where t.typname = 'app_role' and e.enumlabel = 'socio') then
    raise exception 'Falta ACTUALIZAR-FASE11-1-ROL.sql. Ejecútelo primero.';
  end if;
end $$;

-- =====================================================================
-- FASE 11 (2 de 2) · socios: una persona, varias sedes
-- =====================================================================
-- Hasta aquí, cada usuario del panel pertenecía a UNA sede. Los socios
-- de CrediSan no encajan en eso: hay quien participa en Maracaibo y
-- Maracay, quien está en las tres y quien sólo en Caja Seca.
--
-- LO QUE HACE ESTE ARCHIVO
-- ------------------------
--   · Una tabla que dice qué sedes ve cada socio.
--   · La regla única de alcance —app.can_see_branch()— aprende a
--     consultarla. Es el único sitio donde había que tocar: los 78
--     lugares que preguntan «¿puede ver esta sede?» le preguntan a esa
--     función, así que todos obedecen la regla nueva sin modificarse.
--   · Se CIERRA una puerta que estaba abierta (ver más abajo).
--
-- EL SOCIO MIRA, NO TOCA
-- ----------------------
-- Todas las reglas de escritura del sistema exigen ya el rol —«ceo o
-- admin»—, nunca sólo la sede. Por eso ampliar el alcance no le da a
-- nadie permiso para escribir: un socio no es ninguno de los dos.
--
-- Con UNA excepción, que es justo lo que había que encontrar:
-- `incidents_insert` pedía sólo la sede, deliberadamente, para que el
-- jefe operativo pudiera REPORTAR novedades. Tal cual, un socio habría
-- podido crear novedades. Se le añade el rol a esa regla.
--
-- SIN SALARIOS Y SIN FOTOGRAFÍAS
-- ------------------------------
-- No hace falta escribir nada para eso: `compensation_select` y
-- `evidence_select` ya exigen ceo o admin, así que el socio queda fuera
-- por construcción. Y los cierres semanales no llevan dinero —minutos,
-- porcentajes y cuentas—, así que sí puede verlos.
-- =====================================================================

do $$
begin
  if to_regclass('public.branches') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
  if to_regproc('public.cierres') is null then
    raise exception 'Falta ACTUALIZAR-FASE5.sql.';
  end if;
end $$;

-- ── 1 · Un socio no pertenece a una sede, igual que el CEO ───────────
-- Su alcance vive en la tabla de abajo, no en esta columna.
alter table profiles drop constraint if exists profiles_scope_coherente;
alter table profiles add constraint profiles_scope_coherente check (
  (role in ('ceo', 'socio')     and branch_id is null) or
  (role not in ('ceo', 'socio') and branch_id is not null)
);

-- ── 2 · Qué sedes ve cada socio ──────────────────────────────────────
create table if not exists socio_branches (
  profile_id  uuid not null references profiles (id) on delete cascade,
  branch_id   uuid not null references branches (id) on delete restrict,
  created_at  timestamptz not null default now(),
  created_by  uuid references profiles (id),
  primary key (profile_id, branch_id)
);
comment on table socio_branches is
  'Sedes que puede consultar cada socio. Una fila por sede concedida.';
create index if not exists socio_branches_branch_idx on socio_branches (branch_id);

-- ── 3 · ¿Quién es socio? ─────────────────────────────────────────────
create or replace function app.is_socio() returns boolean
language sql stable as $$ select app.current_role_name() = 'socio' $$;

-- ── 4 · ¿Le concedieron esta sede? ───────────────────────────────────
-- SECURITY DEFINER a propósito: esta función la llama can_see_branch(),
-- que a su vez decide la RLS de media base. Si leyera socio_branches
-- como usuario normal, la RLS de esa tabla llamaría a can_see_branch()
-- otra vez y se mordería la cola.
create or replace function app.socio_ve_sede(p_branch uuid)
returns boolean language sql stable security definer
set search_path = app, public, pg_temp as $$
  select exists (
    select 1 from socio_branches sb
     where sb.profile_id = auth.uid()
       and sb.branch_id  = p_branch
  )
$$;

-- ── 5 · La regla única, con el socio dentro ──────────────────────────
create or replace function app.can_see_branch(p_branch uuid)
returns boolean language sql stable as $$
  select case
    when app.current_role_name() is null then false
    when app.is_ceo()   then true
    when app.is_socio() then p_branch is not null and app.socio_ve_sede(p_branch)
    else p_branch is not null and p_branch = app.current_branch()
  end
$$;

-- ── 6 · La tabla de alcances se ve, pero sólo la reparte dirección ───
alter table socio_branches enable row level security;
grant select on socio_branches to authenticated;

drop policy if exists socio_branches_select on socio_branches;
create policy socio_branches_select on socio_branches for select to authenticated
  using (profile_id = auth.uid() or app.is_ceo());

-- Sin GRANT ni política de escritura: las sedes se conceden y se quitan
-- por las funciones de abajo, que comprueban que quien lo pide es el CEO.

-- ── 7 · La puerta que había que cerrar ───────────────────────────────
-- El jefe operativo reporta novedades; el socio no reporta nada.
drop policy if exists incidents_insert on incidents;
create policy incidents_insert on incidents for insert to authenticated
  with check ((app.is_ceo() or app.is_admin() or app.is_supervisor())
              and app.can_see_branch(branch_id)
              and status = 'pendiente'
              and source = 'usuario'
              and reported_by = auth.uid());

-- ── 8 · Los cierres, ahora también para los socios ───────────────────
-- Es la única consulta con el rol escrito a mano que el socio necesita.
-- El resto del panel pasa por can_see_branch() y ya obedece sin tocarse.
--
-- El REPORTE se deja como estaba, para dirección y administración. No
-- porque tenga nada que esconder —no lleva cédulas ni salarios— sino
-- porque el panel no se lo va a ofrecer al socio, y un permiso que nadie
-- usa es una puerta que alguien tendrá que acordarse de vigilar.
create or replace function public.cierres(p_branch uuid, p_lunes date)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_filas jsonb; v_total jsonb; v_sede text;
begin
  -- Los socios consultan; el resto de la comprobación de sede sigue igual.
  if not (app.is_ceo() or app.is_admin() or app.is_socio()) then raise exception 'NO_AUTORIZADO'; end if;
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



-- ── 9 · Lo que usa el panel ──────────────────────────────────────────

-- Alta de un socio y reparto de sus sedes, en una sola operación.
-- Si el socio ya existe, se le ajustan las sedes: marcar y desmarcar
-- casillas tiene que ser reversible sin borrar a nadie.
create or replace function public.guardar_socio(
  p_email text, p_nombre text, p_sedes uuid[])
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid; v_sede uuid; v_nuevas int := 0; v_quitadas int := 0;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;
  if p_sedes is null or cardinality(p_sedes) = 0 then
    raise exception 'SIN_SEDES'
      using hint = 'Un socio sin sedes no vería absolutamente nada.';
  end if;

  -- Que ninguna sede sea inventada: si no, el socio quedaría con un
  -- permiso sobre algo que no existe y nadie lo notaría.
  foreach v_sede in array p_sedes loop
    if not exists (select 1 from branches where id = v_sede) then
      raise exception 'SEDE_DESCONOCIDA';
    end if;
  end loop;

  select id into v_uid from auth.users where lower(email) = lower(btrim(p_email));
  if v_uid is null then
    raise exception 'USUARIO_NO_EXISTE'
      using hint = 'Créelo primero en Supabase: Authentication → Users → Add user.';
  end if;

  insert into profiles (id, full_name, role, branch_id)
  values (v_uid, btrim(p_nombre), 'socio', null)
  on conflict (id) do update
    set full_name = excluded.full_name,
        role      = 'socio',
        branch_id = null,
        updated_at = now()
  -- Un ascenso accidental sería grave al revés: esto impide convertir
  -- en socio —y por tanto degradar— a una administradora o al CEO.
  where profiles.role = 'socio';

  if not exists (select 1 from profiles where id = v_uid and role = 'socio') then
    raise exception 'YA_TIENE_OTRO_ACCESO'
      using hint = 'Esa persona ya entra al panel con otro rol.';
  end if;

  with quitadas as (
    delete from socio_branches
     where profile_id = v_uid and branch_id <> all (p_sedes)
    returning 1
  ) select count(*) into v_quitadas from quitadas;

  with puestas as (
    insert into socio_branches (profile_id, branch_id, created_by)
    select v_uid, s, auth.uid() from unnest(p_sedes) as s
    on conflict (profile_id, branch_id) do nothing
    returning 1
  ) select count(*) into v_nuevas from puestas;

  return jsonb_build_object('ok', true, 'id', v_uid,
                            'sedes', cardinality(p_sedes),
                            'nuevas', v_nuevas, 'quitadas', v_quitadas);
end $$;

-- Retirar el acceso de un socio. No se borra a la persona de Supabase:
-- se le quita el perfil, que es lo que le abre el panel.
create or replace function public.quitar_socio(p_id uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;
  if not exists (select 1 from profiles where id = p_id and role = 'socio') then
    raise exception 'NO_ES_SOCIO';
  end if;

  delete from socio_branches where profile_id = p_id;
  update profiles set is_active = false, updated_at = now() where id = p_id;

  return jsonb_build_object('ok', true);
end $$;

-- La lista que pinta el panel: cada socio con las sedes que ve.
create or replace function public.socios()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if not app.is_ceo() then raise exception 'NO_AUTORIZADO'; end if;

  select coalesce(jsonb_agg(f order by f->>'nombre'), '[]'::jsonb) into v
    from (
      select jsonb_build_object(
        'id',      p.id,
        'nombre',  p.full_name,
        'correo',  u.email,
        'activo',  p.is_active,
        'sedes',   coalesce(
                     (select jsonb_agg(jsonb_build_object('id', b.id, 'nombre', b.name, 'code', b.code)
                             order by b.name)
                        from socio_branches sb join branches b on b.id = sb.branch_id
                       where sb.profile_id = p.id), '[]'::jsonb)
      ) as f
      from profiles p
      left join auth.users u on u.id = p.id
      where p.role = 'socio'
    ) t;

  return v;
end $$;

-- Las sedes del socio que está mirando. El panel las usa para llenar su
-- selector sin pedirle a la base nada que no le corresponda.
create or replace function public.mis_sedes()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  if auth.uid() is null then raise exception 'NO_AUTORIZADO'; end if;

  select coalesce(jsonb_agg(jsonb_build_object('id', b.id, 'nombre', b.name, 'code', b.code)
                  order by b.name), '[]'::jsonb) into v
    from branches b
   where app.can_see_branch(b.id);

  return v;
end $$;

-- ── 10 · Permisos ────────────────────────────────────────────────────
revoke all on function public.guardar_socio(text, text, uuid[]) from public, anon;
revoke all on function public.quitar_socio(uuid)                from public, anon;
revoke all on function public.socios()                          from public, anon;
revoke all on function public.mis_sedes()                       from public, anon;
revoke all on function app.socio_ve_sede(uuid)                  from public, anon;
revoke all on function app.is_socio()                           from public, anon;

grant execute on function public.guardar_socio(text, text, uuid[]) to authenticated;
grant execute on function public.quitar_socio(uuid)                to authenticated;
grant execute on function public.socios()                          to authenticated;
grant execute on function public.mis_sedes()                       to authenticated;
grant execute on function app.socio_ve_sede(uuid)                  to authenticated;
grant execute on function app.is_socio()                           to authenticated;
