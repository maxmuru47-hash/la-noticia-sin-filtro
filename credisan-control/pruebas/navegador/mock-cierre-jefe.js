/* Servidor simulado para «el jefe operativo ve su cierre».
   ---------------------------------------------------------------------
   Este simulado tiene un interruptor que los demás no tienen:
   `window.__BASE_ACTUALIZADA`. Con él apagado, el servidor contesta
   NO_AUTORIZADO al jefe operativo, que es exactamente lo que hace la
   base de CrediSan hoy, con la actualización de la Fase 15 todavía sin
   aplicar.

   Las dos situaciones importan por igual. La Fase 13 se publicó contra
   una migración sin aplicar y el desplegable de novedades salió vacío en
   producción. Aquí se exige que, mientras la base diga que no, el jefe
   operativo no vea ni la pestaña — y que el día que diga que sí, la vea
   sin tocar una línea del panel. */
(function () {
  const ROL = window.__ROL_PRUEBA || 'supervisor';
  const MI_SEDE = ROL === 'ceo' ? null : 'b-mcb';
  const hoyISO = new Date().toISOString().slice(0, 10);

  const TODAS = [
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', empleados: 2, terminales: 1, emparejados: 1 },
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', empleados: 1, terminales: 1, emparejados: 1 }
  ];
  const SEDES = MI_SEDE ? TODAS.filter((s) => s.id === MI_SEDE) : TODAS;

  const EMPLEADOS = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana', last_name:'Pérez',
      national_id:'V-1', position:'Cajera', hired_on:'2026-01-15', is_active:true, pin_updated_at:'2026-03-01' }
  ];

  const CIERRES = {
    ok: true, sede: 'Maracaibo', semana: '2026-09-28', hasta: '2026-10-04', calculado: true,
    total: { trabajadores: 2, pendientes: 2, minutos_retraso: 501, ausencias: 1,
             asistencia: 72.6, puntualidad: 91.0 },
    cierres: [
      { id:'c1', empleado_id:'e1', nombre:'Ana Pérez', cargo:'Cajera',
        minutos_esperados: 2760, minutos_trabajados: 2364, asistencia: 83.33, puntualidad: 100,
        anticipados: 0, retrasos: 0, minutos_retraso: 0, ausencias: 0, incompletos: 1,
        novedades: 0, sin_evidencia: 0, estado: 'pendiente', nota: null,
        calculado: hoyISO, cerrado_por: null, cerrado_en: null },
      { id:'c2', empleado_id:'e2', nombre:'Luis Rojas', cargo:'Asesor',
        minutos_esperados: 2760, minutos_trabajados: 1740, asistencia: 61.9, puntualidad: 82,
        anticipados: 0, retrasos: 3, minutos_retraso: 501, ausencias: 1, incompletos: 0,
        novedades: 0, sin_evidencia: 0, estado: 'pendiente', nota: null,
        calculado: hoyISO, cerrado_por: null, cerrado_en: null }
    ]
  };

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
          const E = (m) => Promise.resolve({ data: null, error: { message: m, code: 'P0001' } });

          if (nombre === 'mi_perfil') return R({
            ok: true, rol: ROL, branch_id: MI_SEDE,
            nombre: ROL === 'ceo' ? 'Dirección General'
                  : ROL === 'admin' ? 'Administración Maracaibo' : 'Jefe Maracaibo',
            sede: MI_SEDE ? 'Maracaibo' : null
          });
          if (nombre === 'resumen_sedes') return R(SEDES);
          if (nombre === 'tablero_hoy') return R({
            fecha: hoyISO, sede: 'Maracaibo',
            resumen: { esperados:1, presentes:1, faltantes:0, retrasados:0, anticipados:0,
                       completos:0, descanso:0, sin_evidencia:0, novedades:0 },
            empleados: []
          });

          // EL INTERRUPTOR. Así contesta hoy la base de CrediSan al jefe
          // operativo: con la Fase 15 sin aplicar, NO_AUTORIZADO.
          if (nombre === 'cierres') {
            if (ROL === 'supervisor' && !window.__BASE_ACTUALIZADA) return E('NO_AUTORIZADO');
            return R(CIERRES);
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
          if (tabla === 'branches')  return consulta(SEDES.map((s) => ({ id: s.id, timezone: 'America/Caracas' })));
          return consulta([]);
        }
      };
    }
  };
})();
