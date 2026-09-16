
-- =====================================================================
--   COMPROBACIÓN FINAL
-- =====================================================================
do $$
declare v_tablas int; v_pol int; v_sedes int; v_term int; v_cfg int; v_sin_rls int;
begin
  select count(*) into v_tablas from pg_tables where schemaname = 'public';
  select count(*) into v_pol    from pg_policies where schemaname in ('public','storage');
  select count(*) into v_sedes  from branches;
  select count(*) into v_term   from terminals;
  select count(*) into v_cfg    from system_settings;

  select count(*) into v_sin_rls
    from pg_tables t
   where t.schemaname = 'public'
     and not exists (select 1 from pg_class c
                      join pg_namespace n on n.oid = c.relnamespace
                     where c.relname = t.tablename and n.nspname = 'public'
                       and c.relrowsecurity);

  raise notice '';
  raise notice '  CREDISAN CONTROL — instalación terminada';
  raise notice '  ---------------------------------------';
  raise notice '  Tablas .................. %', v_tablas;
  raise notice '  Políticas de seguridad .. %', v_pol;
  raise notice '  Sedes ................... %', v_sedes;
  raise notice '  Terminales .............. %', v_term;
  raise notice '  Parámetros .............. %', v_cfg;
  raise notice '';

  if v_sin_rls > 0 then
    raise exception 'ATENCION: % tabla(s) sin seguridad a nivel de fila.', v_sin_rls;
  end if;
  if v_sedes < 3 or v_term < 3 then
    raise exception 'ATENCION: faltan sedes o terminales.';
  end if;

  raise notice '  Todo correcto. Siguiente paso: Settings -> API,';
  raise notice '  copie Project URL y anon public en config/env.js';
  raise notice '';
end $$;
