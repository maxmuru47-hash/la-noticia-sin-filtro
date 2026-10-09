/* Servidor simulado para «las cuatro horas de la jornada».
   ---------------------------------------------------------------------
   Imita lo que Supabase devolvería CON las políticas ya aplicadas: si
   quien consulta es el jefe operativo de Maracaibo, Caja Seca no existe
   en los datos que llegan. Así la prueba distingue «el panel lo oculta»
   de «el servidor no lo manda».

   Las horas están puestas a propósito en hora de Maracaibo (UTC−4) y
   guardadas como instantes UTC, que es como las guarda la base. El
   navegador de la prueba corre en otra zona horaria: si el panel pintara
   la hora del navegador en vez de la de la sede, se vería. */
(function () {
  const ROL = window.__ROL_PRUEBA || 'ceo';
  const MI_SEDE = ROL === 'ceo' ? null : 'b-mcb';

  const ZONA = 'America/Caracas';                 // UTC−4, sin cambio de hora
  // El día de HOY en la sede, que es el que cuenta para una jornada.
  const HOY = new Date().toLocaleDateString('en-CA', { timeZone: ZONA });
  const antes = (iso, n) => {
    const d = new Date(iso + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() - n);
    return d.toISOString().slice(0, 10);
  };
  const AYER = antes(HOY, 1);
  const VIEJO = antes(HOY, 9);                    // dentro de los 14 días
  const ANTIGUO = antes(HOY, 40);                 // fuera de los 14 días

  // Una hora de reloj de la sede, guardada como el instante que es.
  const enSede = (fecha, hhmm) => new Date(`${fecha}T${hhmm}:00-04:00`).toISOString();

  const TODAS = [
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', timezone: ZONA, empleados: 3, terminales: 1, emparejados: 1 },
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', timezone: ZONA, empleados: 1, terminales: 1, emparejados: 1 }
  ];
  const SEDES = MI_SEDE ? TODAS.filter((s) => s.id === MI_SEDE) : TODAS;

  const TODOS_EMP = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana',   last_name:'Pérez',
      national_id:'V-12345678', position:'Cajera',  hired_on:'2026-01-15', is_active:true,  pin_updated_at:'2026-03-01' },
    { id:'e2', branch_id:'b-mcb', internal_code:'MCB-002', first_name:'Luis',  last_name:'Rojas',
      national_id:'V-87654321', position:'Asesor',  hired_on:'2026-02-01', is_active:true,  pin_updated_at:'2026-03-01' },
    { id:'e3', branch_id:'b-mcb', internal_code:'MCB-003', first_name:'Zoe',   last_name:'Zamora',
      national_id:'V-55667788', position:'Cajera',  hired_on:'2026-02-10', is_active:false, pin_updated_at:'2026-03-01' },
    { id:'e9', branch_id:'b-css', internal_code:'CSS-001', first_name:'Mará',  last_name:'Silva',
      national_id:'V-11223344', position:'Supervisora', hired_on:'2026-01-02', is_active:true, pin_updated_at:'2026-03-02' }
  ];
  const EMPLEADOS = MI_SEDE ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE) : TODOS_EMP;

  // Las marcaciones. Ana hizo la jornada entera: entró, salió a almorzar
  // tarde, volvió, y salió. Luis entró y se fue a almorzar, y todavía no
  // ha vuelto. Zoe ya no trabaja aquí pero AYER marcó: su historial no
  // se borra.
  let seq = 0;
  const marca = (emp, sede, fecha, ev, hhmm, delta, estado) => ({
    id: 'm' + (++seq), employee_id: emp, branch_id: sede, work_date: fecha,
    event: ev, recorded_at: enSede(fecha, hhmm), delta_minutes: delta,
    status: estado, evidence_status: 'almacenada', origin: 'online'
  });

  const MARCACIONES = [
    // Ana se fue a almorzar 41 minutos tarde y volvió 22 tarde: hora y
    // media larga de almuerzo. Los veredictos son los que de verdad pone
    // el motor, comprobados en `supabase/tests/15_cuatro_horas.sql`:
    // irse tarde a almorzar NO es falta —se trabajó más antes de ir—,
    // volver tarde SÍ. Un simulador que invente otra cosa no prueba el
    // sistema: prueba el invento.
    marca('e1', 'b-mcb', HOY, 'entrada',          '08:02',  2, 'puntual'),
    marca('e1', 'b-mcb', HOY, 'salida_almuerzo',  '12:41', 41, 'puntual'),
    marca('e1', 'b-mcb', HOY, 'regreso_almuerzo', '14:22', 22, 'retraso'),
    marca('e1', 'b-mcb', HOY, 'salida',           '18:07',  7, 'puntual'),

    marca('e2', 'b-mcb', HOY, 'entrada',          '08:19', 19, 'retraso'),
    marca('e2', 'b-mcb', HOY, 'salida_almuerzo',  '12:00',  0, 'puntual'),

    marca('e1', 'b-mcb', AYER, 'entrada',          '07:58', -2, 'puntual'),
    marca('e1', 'b-mcb', AYER, 'salida_almuerzo',  '12:03',  3, 'puntual'),
    marca('e1', 'b-mcb', AYER, 'regreso_almuerzo', '14:00',  0, 'puntual'),
    marca('e1', 'b-mcb', AYER, 'salida',           '17:55', -5, 'puntual'),
    marca('e3', 'b-mcb', AYER, 'entrada',          '08:05',  5, 'puntual'),

    marca('e1', 'b-mcb', VIEJO,   'entrada', '08:00',  0, 'puntual'),
    marca('e1', 'b-mcb', ANTIGUO, 'entrada', '09:30', 90, 'retraso'),

    marca('e9', 'b-css', HOY, 'entrada', '07:30', -30, 'anticipado'),
    marca('e9', 'b-css', HOY, 'salida',  '19:00',  60, 'puntual')
  ];
  // La política de la base: de otra sede no llega NADA.
  const VISIBLES = MI_SEDE ? MARCACIONES.filter((m) => m.branch_id === MI_SEDE) : MARCACIONES;

  const TABLERO = {
    'b-mcb': {
      fecha: HOY, sede: 'Maracaibo',
      resumen: { esperados:2, presentes:2, faltantes:0, retrasados:1, anticipados:0,
                 completos:1, descanso:0, sin_evidencia:0, novedades:0 },
      empleados: [
        { id:'e1', nombre:'Ana Pérez', cargo:'Cajera', estado:'completo',
          entrada:'08:02', salida:'18:07', esperada:'08:00', minutos:2,
          evidencia:'almacenada', origen:'online', evento_id:'m1' },
        { id:'e2', nombre:'Luis Rojas', cargo:'Asesor', estado:'retrasado',
          entrada:'08:19', salida:null, esperada:'08:00', minutos:19,
          evidencia:'almacenada', origen:'online', evento_id:'m5' },
        { id:'e3', nombre:'Zoe Zamora', cargo:'Cajera', estado:'esperado',
          entrada:null, salida:null, esperada:'08:00', minutos:null,
          evidencia:null, origen:null, evento_id:null }
      ]
    },
    'b-css': {
      fecha: HOY, sede: 'Caja Seca',
      resumen: { esperados:1, presentes:1, faltantes:0, retrasados:0, anticipados:1,
                 completos:1, descanso:0, sin_evidencia:0, novedades:0 },
      empleados: [
        { id:'e9', nombre:'Mará Silva', cargo:'Supervisora', estado:'completo',
          entrada:'07:30', salida:'19:00', esperada:'08:00', minutos:-30,
          evidencia:'almacenada', origen:'online', evento_id:'m14' }
      ]
    }
  };

  const DIAS = [0,1,2,3,4,5,6].map((w) => ({
    id: 'd' + w, weekday: w, is_working: w >= 1 && w <= 5, is_continuous: false,
    entry_time: w >= 1 && w <= 5 ? '08:00' : null,
    lunch_out_time: w >= 1 && w <= 5 ? '12:00' : null,
    lunch_in_time:  w >= 1 && w <= 5 ? '14:00' : null,
    exit_time:      w >= 1 && w <= 5 ? '18:00' : null
  }));

  let sesion = null;
  window.__llamadas = [];
  window.__FECHAS = { HOY, AYER, VIEJO, ANTIGUO };

  const consulta = (filas) => {
    const api = {
      select: () => api, order: () => api, limit: () => api,
      eq:  (c, v) => consulta(filas.filter((f) => f[c] === v)),
      gte: (c, v) => consulta(filas.filter((f) => f[c] >= v)),
      lte: (c, v) => consulta(filas.filter((f) => f[c] <= v)),
      in:  (c, v) => consulta(filas.filter((f) => (v || []).includes(f[c]))),
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
        storage: { from: () => ({
          createSignedUrl: () => Promise.resolve({ data: { signedUrl: 'https://ejemplo.test/f.jpg' }, error: null })
        }) },
        rpc(nombre, args) {
          window.__llamadas.push([nombre, args]);
          const R = (d) => Promise.resolve({ data: d, error: null });
          const E = (m) => Promise.resolve({ data: null, error: { message: m, code: '42501' } });

          if (nombre === 'mi_perfil') return R({
            ok: true, rol: ROL, branch_id: MI_SEDE,
            nombre: ROL === 'ceo' ? 'Dirección General'
                  : ROL === 'admin' ? 'Administración Maracaibo'
                  : ROL === 'socio' ? 'Socio' : 'Jefe Maracaibo',
            sede: MI_SEDE ? 'Maracaibo' : null
          });
          if (nombre === 'resumen_sedes') return R(SEDES);
          if (nombre === 'tablero_hoy') {
            if (MI_SEDE && args.p_branch !== MI_SEDE) return E('permission denied');
            return R(TABLERO[args.p_branch] || TABLERO['b-mcb']);
          }
          if (nombre === 'horario_sede') return R({ ok:true, schedule_id:'s1', dias: DIAS });
          if (nombre === 'horarios_propios') return R([]);
          // La base rechaza al jefe operativo mientras la actualización de
          // la Fase 15 no esté aplicada, que es como está hoy. El panel se
          // lo pregunta al entrar, así que el simulado tiene que
          // contestar lo mismo que contestaría el servidor de verdad.
          if (nombre === 'cierres' && ROL === 'supervisor') return E('NO_AUTORIZADO');
          if (nombre === 'novedades') return R([]);
          if (nombre === 'novedades_pendientes') return R(0);
          if (nombre === 'terminales') return R([]);
          if (nombre === 'reclamar_ceo') return R({ ok:false, reason:'YA_HAY_USUARIOS' });
          return R({ ok: true });
        },
        from(tabla) {
          if (tabla === 'employees') return consulta(EMPLEADOS.slice());
          if (tabla === 'branches')  return consulta(SEDES.slice());
          if (tabla === 'attendance_events') return consulta(VISIBLES.slice());
          if (tabla === 'incident_kinds') return consulta([]);
          return consulta([]);
        }
      };
    }
  };
})();
