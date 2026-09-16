/* Servidor simulado para la Fase 4.
   Imita lo que Supabase devolvería CON las políticas ya aplicadas: si el
   usuario es jefe operativo de Maracaibo, aquí sencillamente no existe
   Caja Seca. Así la prueba distingue "el panel lo oculta" de "el servidor
   no lo manda". */
(function () {
  const ROL = window.__ROL_PRUEBA || 'ceo';
  const MI_SEDE = ROL === 'ceo' ? null : 'b-mcb';
  const hoyISO = new Date().toISOString().slice(0, 10);

  const TODAS = [
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', empleados: 2, terminales: 1, emparejados: 0 },
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', empleados: 1, terminales: 1, emparejados: 1 }
  ];
  const SEDES = MI_SEDE ? TODAS.filter((s) => s.id === MI_SEDE) : TODAS;

  const TODOS_EMP = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana',  last_name:'Pérez',
      national_id:'V-12345678', position:'Cajera', hired_on:'2026-01-15', is_active:true,  pin_updated_at:null },
    { id:'e2', branch_id:'b-mcb', internal_code:'MCB-002', first_name:'Luis', last_name:'Rojas',
      national_id:'V-87654321', position:'Asesor', hired_on:'2026-02-01', is_active:true, pin_updated_at:'2026-03-01' },
    { id:'e3', branch_id:'b-css', internal_code:'CSS-001', first_name:'Mará', last_name:'Silva',
      national_id:'V-11223344', position:'Supervisora', hired_on:'2026-01-02', is_active:true, pin_updated_at:'2026-03-02' }
  ];
  const EMPLEADOS = MI_SEDE ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE) : TODOS_EMP;

  const TABLERO = {
    'b-mcb': {
      fecha: hoyISO, sede: 'Maracaibo',
      resumen: { trabajadores:2, presentes:1, faltantes:1, retrasados:1, novedades:1, completos:0 },
      empleados: [
        { id:'e1', nombre:'Ana Pérez', cargo:'Cajera', estado:'retrasado',
          entrada:'08:14', salida:null, esperada:'08:00', minutos:14,
          evidencia:'sin_evidencia', origen:'terminal' },
        { id:'e2', nombre:'Luis Rojas', cargo:'Asesor', estado:'faltante',
          entrada:null, salida:null, esperada:'08:00', minutos:null,
          evidencia:null, origen:null }
      ]
    },
    'b-css': {
      fecha: hoyISO, sede: 'Caja Seca',
      resumen: { trabajadores:1, presentes:1, faltantes:0, retrasados:0, novedades:0, completos:1 },
      empleados: [
        { id:'e3', nombre:'Mará Silva', cargo:'Supervisora', estado:'completo',
          entrada:'07:56', salida:'18:02', esperada:'08:00', minutos:-4,
          evidencia:'ok', origen:'terminal' }
      ]
    }
  };

  const NOVEDADES = [
    { id:'n1', empleado:'Ana Pérez', empleado_id:'e1', sede:'Maracaibo', branch_id:'b-mcb',
      tipo:'sin_evidencia', tipo_nombre:'Marcación sin foto', descripcion:'La cámara no respondió',
      desde: hoyISO + 'T08:14:00Z', estado:'pendiente', origen:'sistema', reportada_por:null, nota:null },
    { id:'n2', empleado:'Mará Silva', empleado_id:'e3', sede:'Caja Seca', branch_id:'b-css',
      tipo:'permiso', tipo_nombre:'Permiso', descripcion:'Cita médica',
      desde: hoyISO + 'T10:00:00Z', estado:'aprobada', origen:'manual',
      reportada_por:'Administración', nota:'Consignó constancia' }
  ];

  const DIAS = [0,1,2,3,4,5,6].map((w) => ({
    id: 'd' + w, weekday: w, is_working: w >= 1 && w <= 5, is_continuous: false,
    entry_time: w >= 1 && w <= 5 ? '08:00' : null,
    lunch_out_time: w >= 1 && w <= 5 ? '12:00' : null,
    lunch_in_time:  w >= 1 && w <= 5 ? '14:00' : null,
    exit_time:      w >= 1 && w <= 5 ? '18:00' : null
  }));

  let sesion = null;
  window.__llamadas = [];

  const consulta = (filas) => {
    const api = {
      select: () => api, order: () => api, limit: () => api,
      eq: (c, v) => consulta(filas.filter((f) => f[c] === v)),
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
          const E = (m) => Promise.resolve({ data: null, error: { message: m, code: '42501' } });

          if (nombre === 'mi_perfil') return R({
            ok: true, rol: ROL, branch_id: MI_SEDE,
            nombre: ROL === 'ceo' ? 'Dirección General'
                  : ROL === 'admin' ? 'Administración Maracaibo' : 'Jefe Maracaibo',
            sede: MI_SEDE ? 'Maracaibo' : null
          });
          if (nombre === 'resumen_sedes') return R(SEDES);

          if (nombre === 'tablero_hoy') {
            const s = args.p_branch;
            if (MI_SEDE && s !== MI_SEDE) return E('permission denied');
            return R(TABLERO[s] || TABLERO['b-mcb']);
          }
          if (nombre === 'tablero_periodo') {
            const vis = MI_SEDE ? TODAS.filter((x) => x.id === MI_SEDE) : TODAS;
            const filas = vis.map((s) => ({
              branch_id: s.id, sede: s.name, trabajadores: s.empleados,
              marcaciones: s.id === 'b-mcb' ? 18 : 9,
              retrasos: s.id === 'b-mcb' ? 3 : 0, ausencias: 0,
              puntualidad: s.id === 'b-mcb' ? 83.3 : 100
            }));
            return R({
              desde: args.p_desde, hasta: args.p_hasta, sedes: filas,
              resumen_diario_calculado: false,
              total: { marcaciones: filas.reduce((a, f) => a + f.marcaciones, 0),
                       retrasos: filas.reduce((a, f) => a + f.retrasos, 0),
                       ausencias: 0, puntualidad: 88.9 }
            });
          }
          if (nombre === 'ranking_puntualidad') {
            const vis = MI_SEDE ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE) : TODOS_EMP;
            return R(vis.map((e, i) => ({
              empleado_id: e.id, nombre: e.first_name + ' ' + e.last_name, cargo: e.position,
              sede: TODAS.find((s) => s.id === e.branch_id).name,
              marcaciones: 9 - i, puntualidad: 100 - i * 6
            })));
          }
          if (nombre === 'novedades') {
            const est = args.p_estado;
            let n = MI_SEDE ? NOVEDADES.filter((x) => x.branch_id === MI_SEDE) : NOVEDADES;
            if (est) n = n.filter((x) => x.estado === est);
            return R(n);
          }
          if (nombre === 'resolver_novedad') {
            if (ROL === 'supervisor') return E('permission denied for table incidents');
            return R({ ok: true });
          }
          if (nombre === 'registrar_novedad') return R({ ok: true, incident_id: 'n9' });
          if (nombre === 'recalcular_rango')  return R({ ok: true, dias: 7 });

          // ── Fase 5 ──────────────────────────────────────────────────
          if (nombre === 'cierres') {
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            if (MI_SEDE && args.p_branch !== MI_SEDE) return E('NO_AUTORIZADO');
            if (!window.__SEMANA_CALCULADA) {
              return R({ ok:true, sede:'Maracaibo', semana:args.p_lunes, calculado:false,
                         total:{}, cierres:[] });
            }
            return R({ ok:true, sede:'Maracaibo', semana:args.p_lunes, calculado:true,
              total:{ trabajadores:2, pendientes:1, minutos_retraso:37, ausencias:1,
                      asistencia:90.0, puntualidad:83.3 },
              cierres:[
                { id:'c1', empleado_id:'e1', nombre:'Ana Pérez', cargo:'Cajera',
                  minutos_esperados:2400, minutos_trabajados:2363, asistencia:100.0,
                  puntualidad:83.3, anticipados:0, retrasos:3, minutos_retraso:37,
                  ausencias:0, incompletos:0, novedades:1, sin_evidencia:1,
                  estado:'pendiente', nota:null, calculado:new Date().toISOString(),
                  cerrado_por:null, cerrado_en:null },
                { id:'c2', empleado_id:'e2', nombre:'Luis Rojas', cargo:'Asesor',
                  minutos_esperados:2400, minutos_trabajados:1920, asistencia:80.0,
                  puntualidad:100.0, anticipados:1, retrasos:0, minutos_retraso:0,
                  ausencias:1, incompletos:0, novedades:0, sin_evidencia:0,
                  estado:'aprobado', nota:'Permiso consignado',
                  calculado:new Date().toISOString(),
                  cerrado_por:'Administración Maracaibo', cerrado_en:new Date().toISOString() }
              ] });
          }
          if (nombre === 'calcular_cierre_semana') {
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            if (MI_SEDE && args.p_branch !== MI_SEDE) return E('NO_AUTORIZADO');
            window.__SEMANA_CALCULADA = true;
            return R({ ok:true, semana:args.p_lunes, calculados:2, ya_revisados:1 });
          }
          if (nombre === 'revisar_cierre') {
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            window.__ULTIMA_REVISION = args;
            return R({ ok:true, antes:'pendiente', estado:args.p_estado });
          }
          if (nombre === 'reporte_asistencia') {
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            const vis = MI_SEDE ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE) : TODOS_EMP;
            return R({ ok:true, desde:args.p_desde, hasta:args.p_hasta, filas:vis.length,
              datos: vis.map((e) => ({
                fecha:args.p_desde, sede:TODAS.find((s)=>s.id===e.branch_id).name,
                codigo:e.internal_code, nombre:e.first_name+' '+e.last_name, cargo:e.position,
                dia_laborable:true, estado:'completo', marcaciones:4, esperadas:4,
                minutos_esperados:480, minutos_trabajados:478, minutos_retraso:2,
                retrasos:1, anticipados:0, sin_evidencia:0, justificado:false })) });
          }
          if (nombre === 'auditoria') {
            if (ROL === 'supervisor') return R({ ok:true, filas:0, registros:[] });
            const filas = [
              { id:1, cuando:new Date().toISOString(), quien:'Administración Maracaibo',
                tipo_actor:'user', accion:'weekly_closures.update',
                etiqueta:'Se revisó un cierre semanal', entidad:'weekly_closures',
                entidad_id:'c1', sede:'Maracaibo', detalle:null,
                cambios:[{ campo:'status', antes:'pendiente', despues:'aprobado' },
                         { campo:'note', antes:null, despues:'Permiso consignado' }],
                valores:null },
              { id:2, cuando:new Date().toISOString(), quien:'El sistema',
                tipo_actor:'system', accion:'incidents.insert',
                etiqueta:'Se reportó una novedad', entidad:'incidents',
                entidad_id:'n1', sede:'Maracaibo', detalle:null, cambios:null,
                valores:{ kind:'sin_evidencia' } }
            ];
            const v = MI_SEDE ? filas : filas.concat([{ id:3,
              cuando:new Date().toISOString(), quien:'Dirección General', tipo_actor:'user',
              accion:'branches.insert', etiqueta:'Se creó una sede', entidad:'branches',
              entidad_id:'b-css', sede:'Caja Seca', detalle:null, cambios:null,
              valores:{ name:'Caja Seca' } }]);
            return R({ ok:true, filas:v.length, registros:v });
          }
          if (nombre === 'horario_sede')  return R({ ok:true, schedule_id:'s1', dias: DIAS });
          if (nombre === 'guardar_dia_horario') return R({ ok:true, estado: args.p_trabaja ? 'guardado' : 'descanso' });
          if (nombre === 'crear_sede')    return R({ ok:true, branch_id:'b-nueva', terminal:'VAL-01' });
          if (nombre === 'registrar_usuario') return R({ ok:true });
          if (nombre === 'terminales') return R([
            { id:'t1', code:'MCB-01', label:'Terminal Maracaibo', branch_id:'b-mcb', sede:'Maracaibo',
              emparejado:false, device_label:null, last_seen_at:null, is_active:true, marcaciones_hoy:0 }
          ]);
          if (nombre === 'crear_codigo_emparejamiento') return R('AB3K9XQ2');
          if (nombre === 'desemparejar_terminal') return R({ ok:true });
          if (nombre === 'reclamar_ceo')  return R({ ok:false, reason:'YA_HAY_USUARIOS' });
          return R({ ok:true });
        },
        from(tabla) {
          if (tabla === 'employees') return consulta(EMPLEADOS.slice());
          if (tabla === 'incident_kinds') return consulta([
            { code:'permiso', name:'Permiso', requires_approval:true },
            { code:'reposo',  name:'Reposo médico', requires_approval:true }
          ]);
          return consulta([]);
        }
      };
    }
  };
})();
