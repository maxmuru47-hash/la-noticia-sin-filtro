-- =====================================================================
-- CREDISAN CONTROL MULTISEDE · Semilla inicial
-- =====================================================================
-- Idempotente: puede ejecutarse varias veces sin duplicar nada.
-- No crea usuarios ni empleados: eso ocurre en la Fase 2, con auditoría.
-- =====================================================================

-- ── SEDES ────────────────────────────────────────────────────────────
insert into branches (code, name, timezone) values
  ('CSS', 'Caja Seca',  'America/Caracas'),
  ('MCB', 'Maracaibo',  'America/Caracas'),
  ('MCY', 'Maracay',    'America/Caracas')
on conflict do nothing;

-- ── TERMINALES (sin emparejar: sin token hasta el pairing) ───────────
insert into terminals (code, branch_id, label)
select v.code, b.id, v.label
  from (values
    ('CSS-01', 'CSS', 'Terminal Caja Seca'),
    ('MCB-01', 'MCB', 'Terminal Maracaibo'),
    ('MCY-01', 'MCY', 'Terminal Maracay')
  ) as v(code, branch_code, label)
  join branches b on b.code = v.branch_code
on conflict (code) do nothing;

-- ── HORARIOS ─────────────────────────────────────────────────────────
-- Jornada estándar de cada sede: lunes a viernes 08:00–12:00 / 14:00–18:00.
-- Sábado y domingo INACTIVOS: un sábado inactivo no genera ausencia.
-- Activar el sábado es cambiar schedule_days, no tocar código.
do $$
declare b record; v_sched uuid; d int;
begin
  for b in select id, code, name from branches loop
    select id into v_sched from work_schedules
     where branch_id = b.id and name = 'Jornada estándar' and deleted_at is null;

    if v_sched is null then
      insert into work_schedules (branch_id, name, description)
      values (b.id, 'Jornada estándar',
              'Lunes a viernes 08:00–12:00 y 14:00–18:00. Sábado y domingo inactivos.')
      returning id into v_sched;

      -- 0 = domingo … 6 = sábado
      for d in 0..6 loop
        if d between 1 and 5 then
          insert into schedule_days (schedule_id, weekday, is_working, is_continuous,
                                     entry_time, lunch_out_time, lunch_in_time, exit_time)
          values (v_sched, d, true, false, '08:00', '12:00', '14:00', '18:00');
        else
          insert into schedule_days (schedule_id, weekday, is_working) values (v_sched, d, false);
        end if;
      end loop;

      insert into schedule_assignments (schedule_id, branch_id, valid_from, note)
      values (v_sched, b.id, date '2026-01-01', 'Asignación inicial de la sede ' || b.name);
    end if;
  end loop;
end $$;

-- ── CONFIGURACIÓN ────────────────────────────────────────────────────
-- Todo umbral del sistema vive aquí. Gerencia lo cambia sin desplegar.
insert into system_settings (key, value, description) values
  ('attendance.arrival.early_minutes',     '15',  'Minutos antes de la hora para considerar ANTICIPADO'),
  ('attendance.arrival.grace_minutes',     '5',   'Margen que sigue siendo PUNTUAL'),
  ('attendance.arrival.tolerance_minutes', '10',  'Hasta aquí es TOLERANCIA; después, RETRASO'),
  ('attendance.departure.grace_minutes',   '5',   'Salir hasta 5 min antes sigue siendo PUNTUAL'),
  ('attendance.departure.tolerance_minutes','10', 'Salida anticipada tolerada'),
  ('attendance.absence.cutoff_minutes',    '120', 'Minutos tras la última marcación esperada para dar el día por cerrado'),
  ('evidence.retention_days',              '180', 'Días que se conserva la fotografía. Vencidos, se borra sólo el archivo'),
  ('evidence.max_width_px',                '640', 'Lado mayor de la fotografía capturada en el terminal'),
  ('pin.throttle_base_ms',                 '300', 'Espera inicial tras un PIN fallido en el terminal'),
  ('pin.throttle_max_ms',                  '3000','Tope de la espera progresiva. NUNCA se bloquea el terminal'),
  ('pin.throttle_window_minutes',          '10',  'Ventana de fallos que alimenta la espera progresiva'),
  ('pin.alert_failures',                   '12',  'Fallos en la ventana que disparan alerta de fuerza bruta'),
  ('pin.alert_window_minutes',             '10',  'Ventana de la alerta'),
  ('terminal.pairing_ttl_minutes',         '10',  'Vigencia del código de emparejamiento'),
  ('terminal.ticket_ttl_seconds',          '60',  'Vigencia del ticket de confirmación de marcación'),
  ('offline.max_age_hours',                '72',  'Antigüedad máxima aceptada de una marcación offline'),
  ('offline.max_drift_minutes',            '10',  'Desfase de reloj que manda la marcación a revisión'),
  ('ui.brand_name',                        '"CrediSan Control"', 'Nombre mostrado en la aplicación'),
  ('ui.slogan',                            '"Pensamos en Ti"',   'Eslogan corporativo')
on conflict do nothing;
