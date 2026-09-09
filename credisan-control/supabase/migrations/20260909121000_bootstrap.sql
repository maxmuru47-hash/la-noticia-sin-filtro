-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · 0011 · Arranque del primer usuario
-- =====================================================================
-- Sin esto habría que crear el perfil del CEO a mano con SQL. Con esto,
-- la primera persona que entre al panel se convierte en CEO — y sólo la
-- primera: en cuanto existe un perfil, la función deja de conceder nada.
-- =====================================================================

create or replace function public.reclamar_ceo(p_nombre text)
returns jsonb language plpgsql volatile security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid := auth.uid(); v_total int; v_perfil profiles;
begin
  if v_uid is null then
    return jsonb_build_object('ok', false, 'reason', 'SIN_SESION');
  end if;

  -- ¿Ya tiene perfil? Se le devuelve el suyo, sin tocar nada.
  select * into v_perfil from profiles where id = v_uid;
  if found then
    return jsonb_build_object('ok', true, 'reason', 'YA_TENIA_PERFIL',
                              'role', v_perfil.role, 'branch_id', v_perfil.branch_id);
  end if;

  -- La puerta se cierra en cuanto existe el primer perfil.
  select count(*) into v_total from profiles;
  if v_total > 0 then
    return jsonb_build_object('ok', false, 'reason', 'YA_HAY_USUARIOS');
  end if;

  insert into profiles (id, full_name, role, branch_id)
  values (v_uid, coalesce(nullif(btrim(p_nombre), ''), 'Dirección General'), 'ceo', null);

  insert into audit_logs (actor_id, actor_kind, action, entity, entity_id, metadata)
  values (v_uid, 'user', 'profile.ceo_inicial', 'profiles', v_uid,
          jsonb_build_object('nombre', p_nombre));

  return jsonb_build_object('ok', true, 'reason', 'CEO_CREADO', 'role', 'ceo');
end $$;

grant execute on function public.reclamar_ceo(text) to authenticated;

-- Perfil de la sesión actual, en una sola llamada: rol, sede y nombre.
-- El panel lo usa al arrancar en lugar de armar dos consultas.
create or replace function public.mi_perfil()
returns jsonb language plpgsql stable security definer
set search_path = app, public, pg_temp as $$
declare v_uid uuid := auth.uid(); r record;
begin
  if v_uid is null then return jsonb_build_object('ok', false, 'reason', 'SIN_SESION'); end if;

  select p.full_name, p.role, p.branch_id, p.is_active, b.name as branch_name, b.code as branch_code
    into r
    from profiles p left join branches b on b.id = p.branch_id
   where p.id = v_uid;

  if not found then return jsonb_build_object('ok', false, 'reason', 'SIN_PERFIL'); end if;
  if not r.is_active then return jsonb_build_object('ok', false, 'reason', 'INACTIVO'); end if;

  return jsonb_build_object('ok', true, 'nombre', r.full_name, 'rol', r.role,
                            'branch_id', r.branch_id, 'sede', r.branch_name, 'sede_code', r.branch_code);
end $$;

grant execute on function public.mi_perfil() to authenticated;
