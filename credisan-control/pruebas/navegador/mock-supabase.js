/* Servidor simulado: reproduce lo que devolvería Supabase para poder
   probar el panel completo en un navegador real, sin proyecto de por medio. */
(function () {
  const SEDES = [
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', empleados: 2, terminales: 1, emparejados: 0 },
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', empleados: 1, terminales: 1, emparejados: 1 }
  ];
  const EMPLEADOS = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana',  last_name:'Pérez',
      national_id:'V-12345678', position:'Cajera', hired_on:'2026-01-15', is_active:true,  pin_updated_at:null },
    { id:'e2', branch_id:'b-mcb', internal_code:'MCB-002', first_name:'Luis', last_name:'Rojas',
      national_id:'V-87654321', position:'Asesor', hired_on:'2026-02-01', is_active:false, pin_updated_at:'2026-03-01' },
    { id:'e3', branch_id:'b-css', internal_code:'CSS-001', first_name:'Mará', last_name:'Silva',
      national_id:'V-11223344', position:'Supervisora', hired_on:'2026-01-02', is_active:true, pin_updated_at:'2026-03-02' }
  ];
  const DIAS = [0,1,2,3,4,5,6].map((w) => ({
    id: 'd' + w, weekday: w,
    is_working: w >= 1 && w <= 5, is_continuous: false,
    entry_time: w >= 1 && w <= 5 ? '08:00' : null,
    lunch_out_time: w >= 1 && w <= 5 ? '12:00' : null,
    lunch_in_time:  w >= 1 && w <= 5 ? '14:00' : null,
    exit_time:      w >= 1 && w <= 5 ? '18:00' : null
  }));

  let sesion = null;
  window.__llamadas = [];

  const consulta = (filas) => {
    const api = {
      select: () => api, order: () => api, eq: (c, v) => consulta(filas.filter((f) => f[c] === v)),
      single: () => Promise.resolve({ data: filas[0], error: null }),
      insert: (x) => { window.__llamadas.push(['insert', x]); return consulta([{ id: 'nuevo', ...x }]); },
      update: (x) => { window.__llamadas.push(['update', x]); return { eq: () => Promise.resolve({ error: null }) }; },
      then: (r) => r({ data: filas, error: null })
    };
    return api;
  };

  window.supabase = {
    createClient() {
      return {
        auth: {
          getSession: () => Promise.resolve({ data: { session: sesion ? { ...sesion, access_token: 'jwt-de-prueba' } : null } }),
          signInWithPassword: ({ email }) => {
            if (!email.includes('@')) return Promise.resolve({ error: { message: 'Invalid login credentials' } });
            sesion = { user: { email } };
            return Promise.resolve({ error: null });
          },
          signOut: () => { sesion = null; return Promise.resolve({}); }
        },
        rpc(nombre, args) {
          window.__llamadas.push([nombre, args]);
          const R = (d) => Promise.resolve({ data: d, error: null });
          if (nombre === 'mi_perfil')     return R({ ok:true, nombre:'Dirección General', rol:'ceo', branch_id:null, sede:null });
          if (nombre === 'resumen_sedes') return R(SEDES);
          if (nombre === 'horario_sede')  return R({ ok:true, schedule_id:'s1', dias: DIAS });
          if (nombre === 'guardar_dia_horario') return R({ ok:true, estado: args.p_trabaja ? 'guardado' : 'descanso' });
          if (nombre === 'crear_sede')    return R({ ok:true, branch_id:'b-nueva', terminal:'VAL-01' });
          if (nombre === 'registrar_usuario') return R({ ok:true });
          if (nombre === 'terminales') return R([
            { id:'t1', code:'MCB-01', label:'Terminal Maracaibo', branch_id:'b-mcb', sede:'Maracaibo',
              emparejado:false, device_label:null, last_seen_at:null, is_active:true, marcaciones_hoy:0 },
            { id:'t2', code:'CSS-01', label:'Terminal Caja Seca', branch_id:'b-css', sede:'Caja Seca',
              emparejado:true, device_label:'Tablet mostrador', last_seen_at:new Date().toISOString(),
              is_active:true, marcaciones_hoy:7 }
          ]);
          if (nombre === 'crear_codigo_emparejamiento') return R('AB3K9XQ2');
          if (nombre === 'desemparejar_terminal') return R({ ok:true });
          if (nombre === 'reclamar_ceo')  return R({ ok:false, reason:'YA_HAY_USUARIOS' });
          return R({ ok:true });
        },
        from(tabla) {
          return tabla === 'employees' ? consulta(EMPLEADOS.slice()) : consulta([]);
        }
      };
    }
  };
})();
