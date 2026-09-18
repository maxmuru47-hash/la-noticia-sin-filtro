-- =====================================================================
--
--   CREDISAN CONTROL · FASE 13 · el documento de una novedad
--
-- =====================================================================
--
--   Su base ya está instalada. Esto NO BORRA NADA: añade dos columnas
--   de configuración, cuatro funciones y una comprobación.
--
--   PARA QUÉ SIRVE
--   --------------
--   El personal se dirige al jefe operativo, que es quien pasa la
--   novedad. Pero un PERMISO lo da gerencia y un REPOSO lo da un médico:
--   ninguno de los dos nace en el mostrador. Hasta ahora esos papeles
--   vivían en un teléfono o en una gaveta, y administración tenía que
--   pagarle la semana completa a alguien fiándose de que existían.
--
--   Desde ahora:
--
--     · Se puede ADJUNTAR el documento (PDF o foto) a cualquier novedad,
--       al crearla o después, cuando el papel llegue.
--     · Un PERMISO o un REPOSO no se pueden APROBAR sin él. Y como sólo
--       una novedad aprobada justifica el día, nadie cobra una semana
--       completa por una ausencia sostenida de palabra.
--     · Cualquiera de los SOCIOS puede cargar el permiso que autorizó
--       gerencia — siempre con el documento encima.
--     · Administración ve el número de pendientes en el propio botón de
--       Novedades, y cuáles esperan papel.
--     · Abrir un reposo médico deja anotado quién lo abrió.
--
--   En el panel: Novedades → «+ Nueva», o «Adjuntar documento» en una
--   novedad que ya esté registrada.
--
--   1. SQL Editor → New query   2. Pegue esto   3. Run
--
-- =====================================================================

-- =====================================================================
-- FASE 13 · el documento de una novedad
-- =====================================================================
-- El personal se dirige al jefe operativo, que es quien pasa la novedad.
-- Pero un permiso lo da GERENCIA, y un reposo lo da un MÉDICO: ninguno
-- de los dos nace en el mostrador. Hasta aquí esos papeles vivían en un
-- teléfono, en un grupo de WhatsApp o en una gaveta, y administración
-- tenía que pagarle la semana completa a alguien fiándose de que el
-- permiso existía.
--
-- LO QUE YA ESTABA
-- ----------------
-- Más de lo que parecía, y por eso esta fase no inventa infraestructura:
--
--   · El depósito `novedades` existe desde la fase 1, privado, y acepta
--     PDF e imágenes hasta 10 MB.
--   · `incidents.evidence_path` existe desde la fase 1.
--   · `registrar_novedad()` ya aceptaba una ruta de documento.
--   · Aprobar una novedad ya deja el día como JUSTIFICADO, y el cierre
--     semanal ya cuenta un día justificado como día cumplido. Es decir:
--     el camino para «cancelarle completo al personal» ya estaba.
--
-- Nada de eso estaba conectado al panel. Existía la cerradura y existía
-- la llave; no existía la puerta.
--
-- LO QUE ESTA FASE DECIDE
-- -----------------------
-- 1. UN REPOSO O UN PERMISO NO SE APRUEBAN SIN EL PAPEL. No se bloquea
--    crearlos —el jefe reporta hoy lo que pasó hoy, aunque el documento
--    llegue mañana— pero SÍ se bloquea aprobarlos. Y como sólo una
--    novedad aprobada justifica el día, nadie cobra una semana completa
--    por una ausencia sostenida de palabra.
--
-- 2. EL SOCIO CARGA, NO CUENTA. Un socio puede crear la novedad del
--    permiso que autorizó gerencia, pero SÓLO con el documento adjunto y
--    sólo de los tipos que representan una autorización. Sin documento
--    sería un reporte de oídas de alguien que no está en la operación.
--
-- 3. LA RUTA TIENE QUE EXISTIR DE VERDAD. Guardar un texto en
--    `evidence_path` no es tener un documento. La base comprueba que el
--    archivo esté subido, en el depósito correcto y en la carpeta de la
--    sede de esa novedad.
-- =====================================================================

do $$
begin
  if to_regclass('public.incidents') is null then
    raise exception 'CREDISAN no está instalado. Ejecute primero INSTALAR.sql.';
  end if;
end $$;

-- ── 1 · Qué exige cada tipo, y quién puede cargarlo ──────────────────
-- Configuración, no código: mañana se añade «vacaciones» exigiendo
-- documento sin tocar una línea de esto.
alter table incident_kinds
  add column if not exists requiere_documento boolean not null default false,
  add column if not exists socio_puede        boolean not null default false;

comment on column incident_kinds.requiere_documento is
  'No se puede APROBAR sin documento adjunto. Crearla sí: el papel puede llegar después.';
comment on column incident_kinds.socio_puede is
  'Un socio puede crear esta novedad, siempre con documento. El resto son del jefe operativo.';

update incident_kinds set requiere_documento = true
 where code in ('permiso', 'reposo');

update incident_kinds set socio_puede = true
 where code in ('permiso', 'reposo', 'comision', 'salida_autorizada');

-- ── 2 · La regla, en la base, para cualquier camino ──────────────────
-- En un disparador y no en la función que llama el panel: así vale
-- también para un UPDATE hecho a mano, y no hay forma de rodearla.
create or replace function app.incidente_documento_valido()
returns trigger language plpgsql security definer
set search_path = app, public, storage, pg_temp as $$
declare v_exige boolean;
begin
  -- Una ruta que no apunta a un archivo real no es un documento.
  if new.evidence_path is not null
     and new.evidence_path is distinct from coalesce(old.evidence_path, '') then

    if app.path_branch(new.evidence_path) is distinct from new.branch_id then
      raise exception 'DOCUMENTO_DE_OTRA_SEDE'
        using hint = 'El archivo tiene que estar en la carpeta de la sede de esta novedad.';
    end if;

    if not exists (select 1 from storage.objects o
                    where o.bucket_id = 'novedades' and o.name = new.evidence_path) then
      raise exception 'DOCUMENTO_NO_SUBIDO'
        using hint = 'Primero se sube el archivo y después se guarda la novedad.';
    end if;
  end if;

  -- Un permiso o un reposo no se aprueban de palabra.
  if new.status = 'aprobada' and coalesce(old.status, 'pendiente') <> 'aprobada' then
    select k.requiere_documento into v_exige
      from incident_kinds k where k.code = new.kind;

    if coalesce(v_exige, false) and new.evidence_path is null then
      raise exception 'FALTA_DOCUMENTO'
        using hint = 'Este tipo de novedad no se puede aprobar sin el documento adjunto.';
    end if;
  end if;

  return new;
end $$;

drop trigger if exists incidentes_documento on incidents;
create trigger incidentes_documento
  before insert or update on incidents
  for each row execute function app.incidente_documento_valido();

-- ── 3 · El socio carga documentos; no reporta ────────────────────────
-- La fase 11 le cerró la puerta entera. Ésta la entreabre lo justo: los
-- tipos que son una AUTORIZACIÓN, y siempre con el papel encima.
drop policy if exists incidents_insert on incidents;
create policy incidents_insert on incidents for insert to authenticated
  with check (
    app.can_see_branch(branch_id)
    and status = 'pendiente'
    and source = 'usuario'
    and reported_by = auth.uid()
    and (
      (app.is_ceo() or app.is_admin() or app.is_supervisor())
      or (app.is_socio()
          and evidence_path is not null
          and exists (select 1 from incident_kinds k
                       where k.code = kind and k.socio_puede))
    ));

-- ── 4 · Adjuntar el documento después ────────────────────────────────
-- El caso real: el jefe reporta el lunes que alguien faltó, el socio
-- sube el permiso el martes, administración aprueba el miércoles.
--
-- `definer` a propósito: `evidence_path` no está entre las columnas que
-- el panel puede actualizar, y ensanchar ese permiso dejaría reescribir
-- la ruta de cualquier novedad. Aquí se comprueba caso por caso.
create or replace function public.adjuntar_documento(p_id uuid, p_ruta text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_i incidents; v_previo text;
begin
  select * into v_i from incidents where id = p_id;
  if not found then raise exception 'NOVEDAD_NO_ENCONTRADA'; end if;
  if not app.can_see_branch(v_i.branch_id) then raise exception 'NO_AUTORIZADO'; end if;

  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;

  if v_i.status <> 'pendiente' then
    raise exception 'NOVEDAD_YA_RESUELTA'
      using hint = 'Una novedad ya revisada no admite documentos nuevos.';
  end if;

  -- Sustituir un documento que ya está es borrar una prueba. Sólo
  -- dirección y administración, y queda anotado quién lo hizo.
  v_previo := v_i.evidence_path;
  if v_previo is not null and not (app.is_ceo() or app.is_admin()) then
    raise exception 'YA_TIENE_DOCUMENTO'
      using hint = 'Esta novedad ya tiene documento. Sólo administración puede sustituirlo.';
  end if;

  update incidents set evidence_path = p_ruta, updated_at = now() where id = p_id;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (auth.uid(), 'user',
          case when v_previo is null then 'novedad.documento_adjuntado'
                                     else 'novedad.documento_sustituido' end,
          'incidents', p_id, v_i.branch_id,
          jsonb_build_object('empleado', v_i.employee_id, 'tipo', v_i.kind,
                             'tenia_documento', v_previo is not null));

  return jsonb_build_object('ok', true, 'sustituido', v_previo is not null);
end $$;

-- ── 5 · Abrir el documento deja rastro ───────────────────────────────
-- Un reposo médico es un dato de salud. Quien lo abra queda anotado,
-- igual que con la fotografía de una marcación (fase 9).
--
-- El panel NO recibe la ruta en el listado: la única forma de obtenerla
-- es por aquí, y aquí siempre se registra antes de entregarla.
create or replace function public.documento_de_novedad(p_id uuid)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_i incidents; v_nombre text; v_subio uuid;
begin
  -- Por separado, y no en un solo INTO: PostgreSQL no admite una
  -- variable de registro acompañada de otras en la misma lista.
  select * into v_i from incidents where id = p_id;
  if not found then raise exception 'NOVEDAD_NO_ENCONTRADA'; end if;

  select e.first_name || ' ' || e.last_name into v_nombre
    from employees e where e.id = v_i.employee_id;
  if not app.can_see_branch(v_i.branch_id) then raise exception 'NO_AUTORIZADO'; end if;

  if v_i.evidence_path is null then
    return jsonb_build_object('ok', false, 'reason', 'SIN_DOCUMENTO');
  end if;

  -- Quién puede abrirlo: dirección, administración de esa sede, y quien
  -- lo subió. El jefe operativo reporta la novedad pero no abre el
  -- reposo: mirar el papel de un médico debería costar algo.
  select o.owner into v_subio
    from storage.objects o
   where o.bucket_id = 'novedades' and o.name = v_i.evidence_path;

  if not (app.is_ceo() or app.is_admin() or v_subio = auth.uid()) then
    raise exception 'NO_AUTORIZADO'
      using hint = 'El documento lo abren dirección y administración, y quien lo subió.';
  end if;

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, branch_id, metadata)
  values (auth.uid(), 'user', 'novedad.documento_consultado', 'incidents', p_id, v_i.branch_id,
          jsonb_build_object('empleado', v_i.employee_id, 'nombre', v_nombre,
                             'tipo', v_i.kind, 'fecha', v_i.work_date));

  return jsonb_build_object('ok', true, 'ruta', v_i.evidence_path,
                            'nombre', v_nombre, 'tipo', v_i.kind, 'fecha', v_i.work_date);
end $$;

-- ── 6 · El listado dice si hay papel y si hace falta ─────────────────
-- Sin entregar la ruta: eso pasa por la función de arriba, que anota.
create or replace function public.novedades(
  p_branch uuid default null, p_estado text default null, p_limite int default 50)
returns jsonb language plpgsql stable security invoker
set search_path = app, public, pg_temp as $$
declare v jsonb;
begin
  select jsonb_agg(x order by x->>'creada' desc) into v
  from (
    select jsonb_build_object(
      'id', i.id,
      'empleado', e.first_name || ' ' || e.last_name,
      'empleado_id', i.employee_id,
      'cargo', e.position,
      'sede', b.name,
      'branch_id', i.branch_id,
      'tipo', i.kind,
      'tipo_nombre', k.label,
      'descripcion', i.description,
      'desde', i.occurred_from,
      'hasta', i.occurred_to,
      'fecha', i.work_date,
      'estado', i.status,
      'origen', i.source,
      'reportada_por', p.full_name,
      'revisada_por', r.full_name,
      'nota', i.review_note,
      'creada', i.created_at,
      -- Lo nuevo: si hay documento, si hace falta para aprobarla, y si
      -- por tanto se puede aprobar ya. El panel no adivina la regla.
      'tiene_documento',    i.evidence_path is not null,
      'requiere_documento', k.requiere_documento,
      'puede_aprobarse',    i.status = 'pendiente'
                            and (not k.requiere_documento or i.evidence_path is not null)
    ) as x
    from incidents i
    join employees e on e.id = i.employee_id
    join branches  b on b.id = i.branch_id
    join incident_kinds k on k.code = i.kind
    left join profiles p on p.id = i.reported_by
    left join profiles r on r.id = i.reviewed_by
    where (p_branch is null or i.branch_id = p_branch)
      and (p_estado is null or i.status::text = p_estado)
    order by i.created_at desc
    limit greatest(p_limite, 1)
  ) s;

  return coalesce(v, '[]'::jsonb);
end $$;

-- ── 7 · El aviso a administración ────────────────────────────────────
-- «Que administración pueda ser notificada» es, dentro del sistema, que
-- no tenga que ir a mirar: el número va en el botón de Novedades, y
-- distingue las que ya se pueden resolver de las que esperan el papel.
create or replace function public.novedades_pendientes(p_branch uuid default null)
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_total int; v_listas int; v_sin int;
begin
  if app.current_role_name() is null then raise exception 'NO_AUTORIZADO'; end if;
  if p_branch is not null and not app.can_see_branch(p_branch) then
    raise exception 'NO_AUTORIZADO';
  end if;

  select count(*),
         count(*) filter (where not k.requiere_documento or i.evidence_path is not null),
         count(*) filter (where k.requiere_documento and i.evidence_path is null)
    into v_total, v_listas, v_sin
    from incidents i join incident_kinds k on k.code = i.kind
   where i.status = 'pendiente'
     and app.can_see_branch(i.branch_id)
     and (p_branch is null or i.branch_id = p_branch);

  return jsonb_build_object('ok', true, 'total', v_total,
                            'listas', v_listas, 'sin_documento', v_sin);
end $$;

-- ── 8 · Quién abre el depósito ───────────────────────────────────────
-- Estaba abierto a cualquiera que viera la sede. Con papeles de médico
-- dentro, eso es demasiada gente. Se cierra al mismo círculo que la
-- función de arriba. (No se pierde nada: el depósito está vacío, la
-- función nunca se usó desde el panel.)
drop policy if exists novedades_leer on storage.objects;
create policy novedades_leer on storage.objects for select to authenticated
  using (bucket_id = 'novedades'
         and app.can_see_branch(app.path_branch(name))
         and (app.is_ceo() or app.is_admin() or owner = auth.uid()));

-- Subir sí puede quien pueda crear una novedad: el jefe operativo, el
-- socio, y administración. La sede sigue mandando en la ruta.
drop policy if exists novedades_subir on storage.objects;
create policy novedades_subir on storage.objects for insert to authenticated
  with check (bucket_id = 'novedades'
              and app.can_see_branch(app.path_branch(name))
              and (app.is_ceo() or app.is_admin()
                   or app.is_supervisor() or app.is_socio()));

-- ── 9 · Permisos ─────────────────────────────────────────────────────
revoke all on function public.adjuntar_documento(uuid, text)   from public, anon;
revoke all on function public.documento_de_novedad(uuid)       from public, anon;
revoke all on function public.novedades_pendientes(uuid)       from public, anon;
revoke all on function public.novedades(uuid, text, int)       from public, anon;

grant execute on function public.adjuntar_documento(uuid, text) to authenticated;
grant execute on function public.documento_de_novedad(uuid)     to authenticated;
grant execute on function public.novedades_pendientes(uuid)     to authenticated;
grant execute on function public.novedades(uuid, text, int)     to authenticated;

-- ── Comprobación final ───────────────────────────────────────────────
do $$
declare v_kinds int;
begin
  if to_regprocedure('public.adjuntar_documento(uuid, text)') is null
     or to_regprocedure('public.documento_de_novedad(uuid)') is null
     or to_regprocedure('public.novedades_pendientes(uuid)') is null then
    raise exception 'La fase 13 no quedó completa: falta alguna función.';
  end if;

  select count(*) into v_kinds from incident_kinds where requiere_documento;
  if v_kinds = 0 then
    raise exception 'La fase 13 no quedó completa: ningún tipo exige documento.';
  end if;

  raise notice ' ';
  raise notice '  CREDISAN CONTROL — documentos de novedades activados';
  raise notice '  ---------------------------------------------------';
  raise notice '  Tipos que exigen documento para aprobarse ... %', v_kinds;
  raise notice '  Trabajadores (intactos) ..................... %',
    (select count(*) from employees where deleted_at is null);
  raise notice ' ';
  raise notice '  Un permiso o un reposo ya no se aprueban sin el papel.';
  raise notice '  Panel → Novedades.';
  raise notice ' ';
end $$;
