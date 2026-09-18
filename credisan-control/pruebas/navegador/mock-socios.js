/* Servidor simulado para la Fase 4.
   Imita lo que Supabase devolvería CON las políticas ya aplicadas: si el
   usuario es jefe operativo de Maracaibo, aquí sencillamente no existe
   Caja Seca. Así la prueba distingue "el panel lo oculta" de "el servidor
   no lo manda". */
(function () {
  const ROL = window.__ROL_PRUEBA || 'ceo';
  // Un socio no pertenece a una sede: su alcance es una lista. El
  // simulado hace lo que haría el servidor con las políticas puestas —
  // devolver sólo lo suyo— para que la prueba distinga «el panel lo
  // esconde» de «el servidor no lo manda».
  const SEDES_SOCIO = ['b-mcb'];
  const MI_SEDE = (ROL === 'ceo' || ROL === 'socio') ? null : 'b-mcb';
  const hoyISO = new Date().toISOString().slice(0, 10);

  const TODAS = [
    { id: 'b-mcb', code: 'MCB', name: 'Maracaibo', empleados: 2, terminales: 1, emparejados: 0 },
    { id: 'b-css', code: 'CSS', name: 'Caja Seca', empleados: 1, terminales: 1, emparejados: 1 }
  ];
  const SEDES = ROL === 'socio' ? TODAS.filter((s) => SEDES_SOCIO.includes(s.id))
              : MI_SEDE          ? TODAS.filter((s) => s.id === MI_SEDE)
              : TODAS;

  const TODOS_EMP = [
    { id:'e1', branch_id:'b-mcb', internal_code:'MCB-001', first_name:'Ana',  last_name:'Pérez',
      national_id:'V-12345678', position:'Cajera', hired_on:'2026-01-15', is_active:true,  pin_updated_at:null },
    { id:'e2', branch_id:'b-mcb', internal_code:'MCB-002', first_name:'Luis', last_name:'Rojas',
      national_id:'V-87654321', position:'Asesor', hired_on:'2026-02-01', is_active:true, pin_updated_at:'2026-03-01' },
    { id:'e3', branch_id:'b-css', internal_code:'CSS-001', first_name:'Mará', last_name:'Silva',
      national_id:'V-11223344', position:'Supervisora', hired_on:'2026-01-02', is_active:true, pin_updated_at:'2026-03-02' }
  ];
  const EMPLEADOS = ROL === 'socio' ? TODOS_EMP.filter((e) => SEDES_SOCIO.includes(e.branch_id))
                  : MI_SEDE          ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE)
                  : TODOS_EMP;

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
      desde: hoyISO + 'T08:14:00Z', estado:'pendiente', origen:'sistema', reportada_por:null, nota:null,
      tiene_documento:false, requiere_documento:false, puede_aprobarse:true },
    // La que espera el papel: no se puede aprobar todavía
    { id:'n3', empleado:'Luis Rojas', empleado_id:'e2', sede:'Maracaibo', branch_id:'b-mcb',
      tipo:'reposo', tipo_nombre:'Reposo médico', descripcion:'Reposo de tres días',
      desde: hoyISO + 'T08:00:00Z', estado:'pendiente', origen:'manual',
      reportada_por:'Jefe Maracaibo', nota:null,
      tiene_documento:false, requiere_documento:true, puede_aprobarse:false },
    // Pendiente pero CON papel: el contraste con la de arriba. Ésta sí
    // se puede aprobar, y es la que se abre.
    { id:'n4', empleado:'Ana Pérez', empleado_id:'e1', sede:'Maracaibo', branch_id:'b-mcb',
      tipo:'permiso', tipo_nombre:'Permiso', descripcion:'Permiso de gerencia consignado',
      desde: hoyISO + 'T09:00:00Z', estado:'pendiente', origen:'manual',
      reportada_por:'Socio', nota:null,
      tiene_documento:true, requiere_documento:true, puede_aprobarse:true },
    { id:'n2', empleado:'Mará Silva', empleado_id:'e3', sede:'Caja Seca', branch_id:'b-css',
      tipo:'permiso', tipo_nombre:'Permiso', descripcion:'Cita médica',
      desde: hoyISO + 'T10:00:00Z', estado:'aprobada', origen:'manual',
      reportada_por:'Administración', nota:'Consignó constancia',
      tiene_documento:true, requiere_documento:true, puede_aprobarse:false }
  ];

  const DIAS = [0,1,2,3,4,5,6].map((w) => ({
    id: 'd' + w, weekday: w, is_working: w >= 1 && w <= 5, is_continuous: false,
    entry_time: w >= 1 && w <= 5 ? '08:00' : null,
    lunch_out_time: w >= 1 && w <= 5 ? '12:00' : null,
    lunch_in_time:  w >= 1 && w <= 5 ? '14:00' : null,
    exit_time:      w >= 1 && w <= 5 ? '18:00' : null
  }));

  const CON_HORARIO_PROPIO = ['e2'];   // Luis, el que hace jornada corrida

  let sesion = null;
  window.__llamadas = [];
  window.__subidas  = [];
  window.__firmadas = [];

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
            nombre: ROL === 'ceo'   ? 'Dirección General'
                  : ROL === 'admin' ? 'Administración Maracaibo'
                  : ROL === 'socio' ? 'Eliana González' : 'Jefe Maracaibo',
            sede: MI_SEDE ? 'Maracaibo' : null
          });

          if (nombre === 'socios') {
            if (ROL !== 'ceo') return E('NO_AUTORIZADO');
            return R([
              { id:'s1', nombre:'Eliana González', correo:'eliana@credisan.test', activo:true,
                sedes:[{ id:'b-mcb', nombre:'Maracaibo', code:'MCB' }] },
              { id:'s2', nombre:'Ramiro Araujo', correo:'ramiro@credisan.test', activo:true,
                sedes:[{ id:'b-mcb', nombre:'Maracaibo', code:'MCB' },
                       { id:'b-css', nombre:'Caja Seca', code:'CSS' }] }
            ]);
          }
          if (nombre === 'guardar_socio') {
            if (ROL !== 'ceo') return E('NO_AUTORIZADO');
            return R({ ok:true, id:'s3', sedes:(args.p_sedes || []).length, nuevas:1, quitadas:0 });
          }
          if (nombre === 'quitar_socio') {
            if (ROL !== 'ceo') return E('NO_AUTORIZADO');
            return R({ ok:true });
          }
          if (nombre === 'resumen_sedes') return R(SEDES);

          if (nombre === 'tablero_hoy') {
            const s = args.p_branch;
            if (ROL === 'socio' && !SEDES_SOCIO.includes(s)) return E('NO_AUTORIZADO');
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
          // Una base anterior a la fase 13: la función existe pero no
          // manda las tres banderas, y la del contador no existe.
          if (nombre === 'novedades' && window.__BASE_VIEJA) {
            const vis = MI_SEDE ? NOVEDADES.filter((x) => x.branch_id === MI_SEDE) : NOVEDADES;
            return R(vis.filter((x) => !args.p_estado || x.estado === args.p_estado)
              .map(({ tiene_documento, requiere_documento, puede_aprobarse, ...resto }) => resto));
          }
          if (nombre === 'novedades_pendientes' && window.__BASE_VIEJA) {
            return E('function public.novedades_pendientes(uuid) does not exist');
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
          if (nombre === 'registrar_novedad') {
            // Como la base: un socio sólo con documento y sólo de los
            // tipos que son una autorización.
            if (ROL === 'socio') {
              const k = ['permiso','reposo','comision','salida_autorizada'];
              if (!args.p_evidencia || !k.includes(args.p_tipo)) {
                return E('new row violates row-level security policy for table "incidents"');
              }
            }
            return R({ ok: true, id: 'n9' });
          }
          if (nombre === 'adjuntar_documento') {
            const n = NOVEDADES.find((x) => x.id === args.p_id);
            if (n) { n.tiene_documento = true; n.puede_aprobarse = n.estado === 'pendiente'; }
            return R({ ok: true, sustituido: false });
          }
          if (nombre === 'documento_de_novedad') {
            const n = NOVEDADES.find((x) => x.id === args.p_id);
            if (!n || !n.tiene_documento) return R({ ok:false, reason:'SIN_DOCUMENTO' });
            // Un reposo lo abren dirección y administración, no el jefe
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            return R({ ok:true, ruta: n.branch_id + '/doc.pdf', nombre: n.empleado,
                       tipo: n.tipo, fecha: hoyISO });
          }
          if (nombre === 'novedades_pendientes') {
            const vis = MI_SEDE ? NOVEDADES.filter((x) => x.branch_id === MI_SEDE)
                      : ROL === 'socio' ? NOVEDADES.filter((x) => SEDES_SOCIO.includes(x.branch_id))
                      : NOVEDADES;
            const p = vis.filter((x) => x.estado === 'pendiente');
            return R({ ok:true, total: p.length,
                       listas: p.filter((x) => !x.requiere_documento || x.tiene_documento).length,
                       sin_documento: p.filter((x) => x.requiere_documento && !x.tiene_documento).length });
          }
          if (nombre === 'recalcular_rango')  return R({ ok: true, dias: 7 });

          // ── Fase 5 ──────────────────────────────────────────────────
          if (nombre === 'cierres') {
            if (ROL === 'supervisor') return E('NO_AUTORIZADO');
            if (ROL === 'socio' && !SEDES_SOCIO.includes(args.p_branch)) return E('NO_AUTORIZADO');
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
          if (nombre === 'sincronizacion') {
            const t = [
              { id:'t1', code:'MCB-01', sede:'Maracaibo', branch_id:'b-mcb',
                emparejado:true, aparato:'Tableta mostrador',
                ultima_senal:new Date().toISOString(), ultima_sincronizacion:new Date().toISOString(),
                ultima_secuencia:143, horas_sin_senal:0.2,
                marcaciones_offline:7, rechazos:2,
                motivos:{ SECUENCIA_INVALIDA:1, PIN_INVALIDO:1 } },
              { id:'t2', code:'CSS-01', sede:'Caja Seca', branch_id:'b-css',
                emparejado:true, aparato:'Tablet vieja',
                ultima_senal:new Date(Date.now()-50*3600e3).toISOString(),
                ultima_sincronizacion:null, ultima_secuencia:0, horas_sin_senal:50,
                marcaciones_offline:0, rechazos:0, motivos:{} }
            ];
            return R({ ok:true, dias:7,
              terminales: MI_SEDE ? t.filter((x) => x.branch_id === MI_SEDE) : t });
          }
          if (nombre === 'marcaciones_offline') {
            const m = [
              { id:'a1', fecha:new Date().toISOString().slice(0,10), nombre:'Ana Pérez',
                cargo:'Cajera', sede:'Maracaibo', terminal:'MCB-01', evento:'entrada',
                estado:'retraso', minutos:9, registrada:new Date().toISOString(),
                declarada:new Date().toISOString(), recibida:new Date().toISOString(),
                secuencia:141, desfase_seg:900, evidencia:'almacenada' },
              { id:'a2', fecha:new Date().toISOString().slice(0,10), nombre:'Luis Rojas',
                cargo:'Asesor', sede:'Maracaibo', terminal:'MCB-01', evento:'salida',
                estado:'puntual', minutos:0, registrada:new Date().toISOString(),
                declarada:new Date().toISOString(), recibida:new Date().toISOString(),
                secuencia:142, desfase_seg:3, evidencia:'almacenada' }
            ];
            return R({ ok:true, desde:'2026-09-09', filas:m.length, marcaciones:m });
          }
          if (nombre === 'horario_sede')  return R({ ok:true, schedule_id:'s1', dias: DIAS });

          // ── Fase 12 · horario propio de un trabajador ───────────────
          // Luis hace jornada corrida; los demás, la de la sede. El
          // simulado rechaza igual que la base: sólo ceo y admin mandan.
          if (nombre === 'horarios_propios') {
            const vis = MI_SEDE ? TODOS_EMP.filter((e) => e.branch_id === MI_SEDE)
                      : ROL === 'socio' ? TODOS_EMP.filter((e) => SEDES_SOCIO.includes(e.branch_id))
                      : TODOS_EMP;
            return R(vis.filter((e) => CON_HORARIO_PROPIO.includes(e.id)).map((e) => e.id));
          }
          if (nombre === 'horario_de_trabajador') {
            const e = TODOS_EMP.find((x) => x.id === args.p_employee);
            if (!e) return E('TRABAJADOR_INEXISTENTE');
            const propio = CON_HORARIO_PROPIO.includes(e.id);
            return R({ ok:true, nombre: e.first_name + ' ' + e.last_name,
              sede: TODAS.find((s) => s.id === e.branch_id).name,
              propio, desde: propio ? hoyISO : null,
              dias: DIAS.map((d) => propio
                ? { dia:d.weekday, trabaja:d.weekday >= 1 && d.weekday <= 6, continua:true,
                    entrada:'09:00:00', salida:'15:00:00', salida_almuerzo:null, regreso:null }
                : { dia:d.weekday, trabaja:d.is_working, continua:false,
                    entrada:d.entry_time, salida_almuerzo:d.lunch_out_time,
                    regreso:d.lunch_in_time, salida:d.exit_time }) });
          }
          if (nombre === 'dar_horario_propio') {
            if (ROL !== 'ceo' && ROL !== 'admin') return E('NO_AUTORIZADO');
            if (!CON_HORARIO_PROPIO.includes(args.p_employee)) CON_HORARIO_PROPIO.push(args.p_employee);
            return R({ ok:true, nuevo:true, schedule_id:'h1' });
          }
          if (nombre === 'quitar_horario_propio') {
            if (ROL !== 'ceo' && ROL !== 'admin') return E('NO_AUTORIZADO');
            const i = CON_HORARIO_PROPIO.indexOf(args.p_employee);
            if (i < 0) return E('NO_TIENE_HORARIO_PROPIO');
            CON_HORARIO_PROPIO.splice(i, 1);
            return R({ ok:true, accion:'cerrado' });
          }
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
        storage: {
          from(deposito) {
            return {
              upload(ruta, cuerpo, opciones) {
                window.__subidas.push({ deposito, ruta, tipo: opciones?.contentType,
                                        bytes: cuerpo?.size ?? null });
                return Promise.resolve({ data: { path: ruta }, error: null });
              },
              createSignedUrl(ruta, segundos) {
                window.__firmadas.push({ deposito, ruta, segundos });
                return Promise.resolve({
                  data: { signedUrl: 'https://demo.supabase.co/firmada/' + ruta }, error: null });
              }
            };
          }
        },
        from(tabla) {
          if (tabla === 'employees') return consulta(EMPLEADOS.slice());
          if (tabla === 'incident_kinds') {
            // Una base anterior a la fase 13 no tiene esas dos columnas, y
            // PostgREST responde con un error a la consulta ENTERA: no
            // devuelve las filas sin ellas. Por eso el panel se quedaba
            // sin tipos que ofrecer.
            if (window.__BASE_VIEJA) {
              return { select: (campos) => ({ order: () =>
                Promise.resolve(/requiere_documento/.test(campos)
                  ? { data: null, error: { code: '42703',
                      message: 'column incident_kinds.requiere_documento does not exist' } }
                  : { data: [
                      { code:'permiso', label:'Permiso', sort_order:10,
                        system_only:false, is_active:true },
                      { code:'olvido_marcacion', label:'Olvido de marcación',
                        sort_order:40, system_only:false, is_active:true }
                    ], error: null }) }) };
            }
            return consulta([
              { code:'permiso', label:'Permiso', sort_order:10, system_only:false,
                is_active:true, requiere_documento:true,  socio_puede:true },
              { code:'reposo',  label:'Reposo médico', sort_order:20, system_only:false,
                is_active:true, requiere_documento:true,  socio_puede:true },
              { code:'olvido_marcacion', label:'Olvido de marcación', sort_order:40,
                system_only:false, is_active:true, requiere_documento:false, socio_puede:false }
            ]);
          }
          return consulta([]);
        }
      };
    }
  };
})();
