/* Servidor simulado para el tablero de puntualidad.
   ---------------------------------------------------------------------
   Lo importante de este simulado es que HONRA `p_branch`: si el panel
   pide una sede, aquí se devuelve esa sede y no las tres. Sin eso, una
   prueba del filtro no probaría el filtro — probaría que la pantalla
   pinta lo que le den.

   Las tres sedes son las reales, con puntualidades distintas y a
   propósito desordenadas por código: Caja Seca va la primera por código
   y la última por puntualidad. Si el tablero las ordenara por código, se
   vería. */
(function () {
  const ROL = window.__ROL_PRUEBA || 'ceo';
  // El socio de la prueba tiene dos sedes; la administradora, una.
  const MI_SEDE = (ROL === 'admin' || ROL === 'supervisor') ? 'b-mcb' : null;
  const SEDES_SOCIO = ['b-mcb', 'b-mcy'];

  const TODAS = [
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', empleados: 6, terminales: 1, emparejados: 1,
      puntualidad: 68.4, marcaciones: 120, retrasos: 31, ausencias: 5 },
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', empleados: 11, terminales: 1, emparejados: 1,
      puntualidad: 94.2, marcaciones: 240, retrasos: 9,  ausencias: 1 },
    { id: 'b-mcy', code: 'MCY', name: 'Maracay',   empleados: 8,  terminales: 1, emparejados: 1,
      puntualidad: 81.0, marcaciones: 160, retrasos: 20, ausencias: 3 }
  ];

  const visibles = () =>
    ROL === 'socio' ? TODAS.filter((s) => SEDES_SOCIO.includes(s.id))
    : MI_SEDE       ? TODAS.filter((s) => s.id === MI_SEDE)
                    : TODAS;

  const EMPLEADOS = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana',  last_name:'Pérez',
      national_id:'V-1', position:'Cajera', hired_on:'2026-01-15', is_active:true, pin_updated_at:'2026-03-01' }
  ];

  const TABLERO_HOY = (sede) => ({
    fecha: new Date().toISOString().slice(0, 10),
    sede: (TODAS.find((s) => s.id === sede) || TODAS[1]).name,
    resumen: { esperados:1, presentes:1, faltantes:0, retrasados:0, anticipados:0,
               completos:0, descanso:0, sin_evidencia:0, novedades:0 },
    empleados: [{ id:'e1', nombre:'Ana Pérez', cargo:'Cajera', estado:'presente',
                  entrada:'08:00', salida:null, esperada:'08:00', minutos:0,
                  evidencia:'almacenada', origen:'online', evento_id:'m1' }]
  });

  let sesion = null;
  window.__llamadas = [];

  const consulta = (filas) => {
    const api = {
      select: () => api, order: () => api, limit: () => api,
      eq:  (c, v) => consulta(filas.filter((f) => f[c] === v)),
      gte: (c, v) => consulta(filas.filter((f) => f[c] >= v)),
      lte: (c, v) => consulta(filas.filter((f) => f[c] <= v)),
      in:  (c, v) => consulta(filas.filter((f) => (v || []).includes(f[c]))),
      single: () => Promise.resolve({ data: filas[0], error: null }),
      then: (r) => r({ data: filas, error: null })
    };
    return api;
  };

  window.supabase = {
    createClient() {
      return {
        auth: {
          getSession: () => Promise.resolve({ data: { session: sesion ? { ...sesion, access_token: 'jwt' } : null } }),
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
          const E = (m) => Promise.resolve({ data: null, error: { message: m, code: '42501' } });

          if (nombre === 'mi_perfil') return R({
            ok: true, rol: ROL, branch_id: MI_SEDE,
            nombre: ROL === 'ceo' ? 'Dirección General'
                  : ROL === 'admin' ? 'Administración Maracaibo'
                  : ROL === 'socio' ? 'Eliana González' : 'Jefe Maracaibo',
            sede: MI_SEDE ? 'Maracaibo' : null
          });
          if (nombre === 'resumen_sedes') return R(visibles());
          if (nombre === 'tablero_hoy') return R(TABLERO_HOY(args.p_branch));

          // AQUÍ ESTÁ LO QUE SE PRUEBA: se respeta `p_branch`.
          if (nombre === 'tablero_periodo') {
            if (args.p_branch && !visibles().some((s) => s.id === args.p_branch)) {
              return E('NO_AUTORIZADO');
            }
            const vis = args.p_branch ? visibles().filter((s) => s.id === args.p_branch) : visibles();
            const filas = vis.map((s) => ({
              id: s.id, code: s.code, sede: s.name, trabajadores: s.empleados,
              marcaciones: s.marcaciones, retrasos: s.retrasos,
              ausencias: s.ausencias, puntualidad: s.puntualidad
            }));
            const marc = filas.reduce((a, f) => a + f.marcaciones, 0);
            return R({
              desde: args.p_desde, hasta: args.p_hasta, sedes: filas,
              resumen_diario_calculado: true,
              total: {
                marcaciones: marc,
                retrasos: filas.reduce((a, f) => a + f.retrasos, 0),
                ausencias: filas.reduce((a, f) => a + f.ausencias, 0),
                puntualidad: marc
                  ? Math.round(10 * filas.reduce((a, f) => a + f.puntualidad * f.marcaciones, 0) / marc) / 10
                  : null
              }
            });
          }
          if (nombre === 'ranking_puntualidad') {
            const vis = args.p_branch ? visibles().filter((s) => s.id === args.p_branch) : visibles();
            return R(vis.map((s, i) => ({
              empleado_id: 'r' + i, nombre: 'Mejor de ' + s.name, cargo: 'Cajera',
              sede: s.name, marcaciones: 20 - i, puntualidad: 100 - i
            })));
          }
          if (nombre === 'novedades') return R([]);
          if (nombre === 'novedades_pendientes') return R(0);
          if (nombre === 'horarios_propios') return R([]);
          if (nombre === 'terminales') return R([]);
          if (nombre === 'reclamar_ceo') return R({ ok:false, reason:'YA_HAY_USUARIOS' });
          return R({ ok: true });
        },
        from(tabla) {
          if (tabla === 'employees') return consulta(EMPLEADOS.slice());
          if (tabla === 'branches')  return consulta(visibles().map((s) => ({ id: s.id, timezone: 'America/Caracas' })));
          return consulta([]);
        }
      };
    }
  };
})();
