# PROJECT_STATE · CrediSan Control Multisede

> Fuente única de continuidad. Se actualiza al cerrar cada fase.

| | |
|---|---|
| **Versión** | 1.0.0 |
| **Fase actual** | **4 — Tableros entregados. Panel completo de punta a punta** |
| **Fecha** | 2026-09-15 |
| **Instalación** | `supabase/instalador/INSTALAR.sql` (nuevo) · `ACTUALIZAR-FASE4.sql` (ya instalado) |
| **Producción** | control.sinfiltroconmax.com (VPS propio, Caddy, despliegue automático desde GitHub) |
| **Backend** | Supabase, proyecto independiente (aún por crear) |
| **Repositorio** | `credisan-control/` dentro de `la-noticia-sin-filtro`; separar según `docs/SEPARAR_REPOSITORIO.md` |

---

## Arquitectura fijada

- Frontend estático (HTML + CSS + JS modular), sin framework ni empaquetador, PWA, mobile first.
- Backend Supabase: PostgreSQL + Auth + RLS + Storage privado + Edge Functions + pg_cron.
- El terminal **no** es un usuario de Supabase: `device_token` y acceso exclusivo por Edge Function.
- El panel usa `supabase-js` directo, filtrado por RLS. Nunca `service_role` en el navegador.
- Hora, sede, identidad y clasificación se deciden **dentro de PostgreSQL**.

## Decisiones tomadas (y por qué)

| # | Decisión | Razón |
|---|---|---|
| 1 | Salario en `employee_compensation`, tabla aparte | RLS no filtra columnas: es la única forma real de ocultarlo al jefe operativo |
| 2 | Terminal sin usuario Supabase | Un teléfono en el mostrador es hostil; con RLS directa se podría listar el personal |
| 3 | Rol y sede en claims del JWT, con reserva contra `profiles` | Evita RLS recursiva y ahorra una consulta por política; funciona aunque el hook no esté activo |
| 4 | `attendance_events` sin UPDATE/DELETE para nadie | El valor probatorio depende de que nadie «arregle» el pasado |
| 5 | PIN: bcrypt + índice ciego HMAC, pimienta fuera de la base | Búsqueda O(1), unicidad garantizada y un volcado robado no sirve de nada |
| 6 | Sin bloqueo de PIN; freno progresivo por terminal + alerta | Requisito explícito: nadie puede dejar sin marcar a sus compañeros |
| 7 | `verify_pin` y `register_offline_punch` devuelven motivo, no lanzan excepción | Una excepción revertiría el registro del intento fallido: el atacante borraría su rastro |
| 8 | La espera del PIN la aplica la Edge Function | No retener conexiones de PostgreSQL durante la penalización |
| 9 | Marcación en dos pasos: PIN → ticket de 60 s → confirmar | El PIN viaja una sola vez |
| 10 | Evento esperado = el más cercano a la hora actual entre los no registrados | Resuelve bien a quien olvidó una marcación intermedia |
| 11 | Calendario en `work_schedules` + `schedule_days` + excepciones con vigencia | Activar el sábado es configuración, no despliegue |
| 12 | Sábado y domingo **inactivos** en las tres sedes | Requisito de Maracaibo; un día inactivo no genera ausencia |
| 13 | `attendance_daily` materializa el día | Una ausencia es la falta de un registro: hay que materializarla, no inventar marcaciones |
| 14 | Umbrales en `system_settings`, con anulación por sede | Gerencia cambia la política sin desplegar |
| 15 | Depósitos de Storage privados; evidencia sin acceso ni para el CEO | Se lee por URL firmada de 60 s, con registro de quién la vio |
| 16 | Falta de foto no impide marcar; abre novedad automática | Requisito explícito |
| 17 | Retención de evidencia 180 días; se borra el archivo, no el registro | Requisito explícito |
| 18 | Offline: PIN cifrado con clave pública, HMAC, `seq` monotónico, reloj monotónico, ventana de 72 h | Que el corte no sea una vía para manipular horarios |
| 19 | Sin `force row level security` | Las funciones `SECURITY DEFINER` del motor necesitan el paso del propietario; a la API se le niega por política |
| 20 | Tipografía Poppins en vez de Code Next | El manual especifica una fuente comercial en versión de prueba; Poppins es equivalente y con licencia libre |
| 21 | `America/Caracas` por sede, guardado en `timestamptz` | Venezuela es UTC−4 sin horario de verano; la zona vive en `branches` por si una sede futura difiere |

## Base de datos

21 tablas · 46 políticas RLS · 44 funciones · 71 índices · 22 disparadores · 19 parámetros de configuración.

`branches` `profiles` `terminals` `terminal_pairing_codes` `employees`
`employee_compensation` `work_schedules` `schedule_days` `schedule_assignments`
`schedule_exceptions` `attendance_events` `attendance_evidence`
`attendance_corrections` `attendance_daily` `punch_tickets` `pin_attempts`
`incidents` `incident_kinds` `weekly_closures` `audit_logs` `system_settings`

Añadidas sobre el mínimo pedido, con justificación: `punch_tickets` (para que el
PIN viaje una sola vez), `pin_attempts` (freno y detección de fuerza bruta),
`attendance_daily` (materializar ausencias e incompletos), `attendance_corrections`
(corregir sin borrar), `schedule_days` / `schedule_assignments` (calendario con
vigencia), `incident_kinds` (tipos de novedad como dato, no como código).

## Migraciones aplicadas

| Archivo | Contenido |
|---|---|
| `20260909120000_extensions.sql` | esquema `app`, pgcrypto, btree_gist |
| `20260909120100_core.sql` | sedes, perfiles, terminales, emparejamiento, empleados, compensación |
| `20260909120200_schedules.sql` | horarios, días, asignaciones con vigencia, excepciones |
| `20260909120300_attendance.sql` | marcaciones, evidencia, correcciones, intentos de PIN, resumen diario, tickets |
| `20260909120400_incidents_closures.sql` | novedades, tipos, cierres semanales |
| `20260909120500_audit_settings.sql` | auditoría append-only, configuración, disparador genérico |
| `20260909120600_functions.sql` | alcance, calendario, clasificación, PIN, emparejamiento, marcación, agregados |
| `20260909120700_rls.sql` | permisos, RLS, políticas, RPC del panel |
| `20260909120800_storage.sql` | tres depósitos privados y sus políticas |
| `20260909120900_jobs.sql` | trabajos programados y retención de evidencia |
| `20260909121000_bootstrap.sql` | `reclamar_ceo`, `mi_perfil` |
| `20260909121100_fase2.sql` | sedes, accesos y horarios desde el panel |
| `20260915100000_fase3.sql` | terminales, PIN y puente `edge_*` |
| `20260915140000_fase4.sql` | tableros del día y del período, ranking, novedades |
| `seed/seed.sql` | 3 sedes, 3 terminales, jornada estándar, 19 parámetros |

## Variables de entorno

**Públicas** (`config/env.js`): `supabaseUrl`, `supabaseAnonKey`, `appBaseUrl`, `offlinePublicKey`.

**Secretas** (sólo Edge Functions): `SUPABASE_SERVICE_ROLE_KEY`,
`CREDISAN_PIN_PEPPER`, `CREDISAN_OFFLINE_PRIVATE_KEY`.

## Validación ejecutada

`PGPORT=54329 ./supabase/tests/run_local.sh` sobre PostgreSQL 16 local levanta
una base limpia por batería —no se contaminan entre sí— y corre las cuatro:

| Batería | Cubre |
|---|---|
| `01_suite.sql` | PIN (débil, repetido, sin pimienta), emparejamiento (un solo uso, token falso, sede inmutable), marcación (clasificación, idempotencia, doble marcación, ticket usado, sede ajena), evidencia ausente, offline (futuro, ventana, secuencia, sede, rastro de rechazos), resumen diario, sábado inactivo, RLS de los cuatro roles, Storage, auditoría sin secretos y superficie de ejecución |
| `02_fase2.sql` | bootstrap y su cierre, sede completa, activación del sábado, horas en desorden, alta de accesos, límites de la administradora |
| `03_fase3.sql` | PIN asignado, emparejamiento, token falso, PIN erróneo con su rastro, marcación clasificada, evidencia con fecha de purga, desvinculación, y el panel sin poder ejecutar las `edge_*` |
| `04_fase4.sql` | tablero por rol, aislamiento entre sedes, ausencia de datos salariales por estructura, y que la fase no alteró ni un dato existente |

**Las cuatro en verde.** Además, tres pruebas de navegador real (Playwright con
el backend simulado): kiosco completo con cámara, panel de Fase 3, y panel de
Fase 4 con sus 51 comprobaciones sobre los tres roles.

## Deuda técnica y riesgos conocidos

| # | Asunto | Estado |
|---|---|---|
| 1 | Un trabajador puede marcar por otro si conoce su PIN | Asumido. La foto es la prueba. Biometría sería otra decisión |
| 2 | Revocar un rol no invalida el JWT vivo (30 min) | Mitigado con `is_active` bloqueando la renovación |
| 3 | Offline no puede confirmar identidad en pantalla | Por diseño: cachear credenciales en el dispositivo sería peor |
| 4 | `pg_cron` puede no estar disponible según el plan | La migración avisa; alternativa documentada |
| 5 | `next_expected` sólo mira el día local en curso | Suficiente con jornadas que no cruzan medianoche. Revisar si alguna sede lo hace |
| 6 | Sin fuentes autohospedadas todavía | **Sigue pendiente.** Panel y kiosco cargan Poppins desde Google Fonts; el kiosco debería funcionar sin salir a internet |
| 7 | Edge Function escrita pero **no desplegada** | `supabase/functions/credisan/index.ts` está lista; falta publicarla y crear el secreto `CREDISAN_PIN_PEPPER`. Hasta entonces no hay PIN, ni emparejamiento, ni marcación |
| 8 | El tablero del período depende de `attendance_daily` para las ausencias | Mitigado: si no se ha calculado, el panel lo dice en vez de enseñar un cero falso. Con `pg_cron` activo se resuelve solo |

## Pendiente antes de producción

- Crear el proyecto Supabase y aplicar migraciones (`docs/SUPABASE_SETUP.md`).
- Generar y guardar `CREDISAN_PIN_PEPPER`.
- Activar el hook de claims y `pg_cron`.
- Crear el perfil del CEO.

## Frontend entregado en la Fase 1

Portada instalable (PWA) con la identidad oficial y una pantalla de **estado del
sistema** que comprueba de verdad —no supone— HTTPS, instalabilidad,
configuración y conexión con Supabase. Sirve para desplegar hoy y verificar
cada paso de la puesta en marcha.

`public/index.html` · `public/sw.js` · `public/manifest.webmanifest`
`public/config/env.js` (único archivo a editar) · `public/assets/css/credisan.css`
`public/src/core/config.js` · `public/src/app/estado.js` · `public/.htaccess`

Empaquetado en `credisan-control-web.zip`, listo para el Administrador de
archivos de Hostinger. Guía: `docs/SUBIR_A_HOSTINGER.md`.

El `.htaccess` se dejó deliberadamente sin redirección a HTTPS y sin HSTS con
`includeSubDomains`: eran los dos únicos puntos con alcance más allá de la
carpeta `control/`. Forzar HTTPS se activa desde hPanel.

## Fase 2 — entregada

**Backend** (migraciones 0011 y 0012):
`reclamar_ceo` (el primer usuario queda como dirección y la puerta se cierra),
`mi_perfil`, `crear_sede` (sede + horario de 7 días + asignación + terminal, en
una sola operación), `registrar_usuario`, `horario_sede`, `guardar_dia_horario`
(valida el orden de las horas y responde en castellano), `resumen_sedes`.

**Panel** (`public/panel/` + `public/src/panel/app.js` + `assets/css/panel.css`):
acceso con correo y contraseña, y cuatro secciones — Sedes, Personal, Horarios y
Accesos — con navegación inferior pensada para el teléfono.

Decisión importante: **la Fase 2 no toca el PIN**. Asignarlo exige el pepper,
que vive fuera de la base de datos, y por tanto una Edge Function. Meterla aquí
obligaría a guardar el pepper en la base —debilitando el modelo— o a montar el
despliegue de funciones antes de tiempo. Los trabajadores se registran con su
horario y quedan marcados «PIN pendiente» hasta la Fase 3, que es cuando el
terminal existe y la Edge Function hace falta de todos modos.

Otra decisión: crear usuarios de Auth necesita la llave maestra, que jamás va al
navegador. Por eso el alta se hace en el panel de Supabase y el rol se asigna
desde CrediSan con `registrar_usuario`, que sólo acepta correos que ya existen.

Validado con `02_fase2.sql` (bootstrap y su cierre, sede completa, activación
del sábado, rechazo de horas en desorden, alta de accesos, y que una
administradora no pueda crear sedes ni conceder accesos) y con una prueba de
navegador real contra un servidor simulado: acceso, listados, alta, cambio de
horario y el payload exacto que se envía. Esa prueba descubrió que el atributo
`hidden` no ocultaba la pantalla de acceso, porque `display:grid` lo anulaba;
corregido con una regla global.

## Fase 3 — entregada

**Backend** (migración 0013): `puedo_gestionar_empleado`, `terminales`,
`desemparejar_terminal`, `marcaciones_hoy`, y el puente `edge_*` hacia el motor.

Decisión: PostgREST sólo publica `public`, y el motor vive en `app`. En vez de
exponer `app` entero en la API —que es lo cómodo y lo peligroso— hay siete
envolturas `edge_*` en `public`, cada una con `revoke` a todo el mundo y
`grant` únicamente a `service_role`. La batería comprueba que ni el CEO puede
ejecutarlas desde el panel.

**Función del servidor** (`supabase/functions/credisan/index.ts`): una sola,
con cuatro acciones —emparejar, pin, confirmar, asignar-pin—. Aquí vive el
pepper, que nunca toca la base de datos.

Dos decisiones que conviene recordar:

- Se publica con **Verify JWT desactivado**. El terminal no tiene ni debe tener
  sesión de Supabase: es un teléfono en un mostrador. La función hace su propia
  comprobación, más estricta —`device_token` para marcar, token de la persona
  más consulta a la RLS para generar PIN, código de un solo uso para emparejar—.
- `asignar-pin` resuelve el permiso **con el token de quien lo pide**, llamando
  a `puedo_gestionar_empleado`. La llave maestra sólo se usa después de que la
  RLS haya dicho que sí.

**Kiosco** (`public/kiosk/` + `public/src/kiosk/app.js`): emparejamiento,
teclado de seis dígitos, identidad, cámara frontal, resultado a color y vuelta
automática. El PIN viaja una sola vez y se cambia por un ticket de 60 s.

**Panel**: terminales con su estado dentro de la vista de Sedes, generación de
códigos de vinculación, desvinculación de un aparato perdido, y el botón de PIN
en cada ficha —que lo enseña una vez y nunca más—.

Validado con `03_fase3.sql` (asignación de PIN, emparejamiento, token falso
rechazado, PIN erróneo con su rastro intacto, marcación clasificada, evidencia
con su fecha de purga, desvinculación que invalida el token sin tocar el
historial, y el panel sin poder ejecutar las funciones del servidor) y con dos
pruebas de navegador real: el kiosco completo con cámara simulada —incluida la
fotografía que se envía y la comprobación de que el PIN no vuelve a viajar al
confirmar— y el panel con terminales y PIN.

Esa segunda prueba encontró que `generarPin` usaba `config` sin importarlo, y
el `try/catch` lo disfrazaba de «no se pudo conectar». Corregido, y el mensaje
de error ahora dice lo que realmente pasó.

## Deuda de la Fase 3

- Con Verify JWT desactivado, la acción `emparejar` queda abierta a internet.
  La protege un código de 40 bits, vivo 10 minutos y de un solo uso. Aceptado;
  si hiciera falta, se añade un límite de intentos por IP.
- El kiosco guarda su credencial en `localStorage`. Para un aparato de mostrador
  equivale a IndexedDB; la cola offline de la Fase 6 sí necesitará IndexedDB.
- La foto de ficha del trabajador todavía no se muestra en el terminal: se ven
  sus iniciales. Falta el subidor de fotos en el panel.

## Fase 4 — entregada

**Backend** (migración 0014). Siete funciones, **todas de lectura salvo dos**, y
ni una sola tabla tocada: `tablero_hoy`, `tablero_periodo`, `ranking_puntualidad`,
`novedades`, `registrar_novedad`, `resolver_novedad`, `recalcular_rango`.

Tres decisiones que conviene recordar:

- **`tablero_hoy` se calcula en vivo** contra el calendario y las marcaciones.
  No depende de que `pg_cron` haya corrido: lo que se ve en pantalla es lo que
  hay en ese instante, no lo que un trabajo nocturno dejó escrito.
- **`tablero_periodo` confiesa lo que no sabe.** Las ausencias salen de
  `attendance_daily`, que hay que materializar. Si el período no se ha
  calculado, devuelve `resumen_diario_calculado: false` y el panel escribe, con
  todas sus letras, que ese cero no es un dato sino que nadie lo ha mirado.
  Un cero de ausencias falso es peor que no enseñar nada.
- **`registrar_novedad` y `resolver_novedad` son `security invoker`**, al revés
  que el resto. Son las dos únicas que escriben, y se quiso que decidiera la
  RLS y no la función: así el jefe operativo puede reportar pero no aprobar
  porque se lo impide la política, no un `if` que alguien podría cambiar.

**Panel**: cinco pestañas —Hoy, Personal, Novedades, Horarios, Sedes— filtradas
por rol, con selector de período (Hoy / Semana / Mes) para dirección y
administración. El jefe operativo ve tres pestañas y ningún dato de nómina.

### Cuidado de los datos de los trabajadores

Requisito explícito de esta fase. Lo que se hizo, y cómo está comprobado:

1. **Nada existente se modificó.** La migración son siete
   `create or replace function` y nada más: ni `alter`, ni `insert`, ni
   `update`, ni `delete`. La batería cuenta trabajadores, salarios y
   marcaciones antes y después, y comprueba que un salario concreto sigue
   valiendo lo mismo.
2. **El salario no viaja.** En vez de buscar palabras sospechosas en la
   respuesta —que es lo que se hizo primero y falla—, la batería exige que la
   ficha de cada trabajador tenga **exactamente** los campos previstos. Si
   alguien añadiera un campo de más, aunque fuera inocente, la prueba se cae.
3. **Una sede no ve a la otra.** Se comprueba en la base (el jefe operativo
   recibe 2 de 3 trabajadores y `NO_AUTORIZADO` si pregunta por la sede ajena)
   y también en el navegador: se lee el texto completo de la página y se exige
   que el apellido de la trabajadora de la otra sede no aparezca en ningún sitio.

Validado con `04_fase4.sql` y con una prueba de navegador real de **51
comprobaciones** sobre los tres roles. Dos hallazgos de esa ronda:

- `registrar_novedad` filtraba por `deleted_at`, columna que no está en el
  permiso de lectura de `employees`: fallaba con «permission denied» para quien
  sí tenía derecho. La RLS ya oculta las filas borradas, así que sobraba.
- La batería tenía un **falso positivo** propio: buscaba el número `150` como
  texto y lo encontraba dentro de un UUID aleatorio (`…f860-4150-8e02…`).
  Pasaba o fallaba según la suerte del sorteo. Sustituido por la comprobación
  estructural del punto 2, que es determinista y además más estricta. Se
  verificó con cinco corridas consecutivas en verde.

## Instalador

`supabase/instalador/construir.sh` rehace `INSTALAR.sql` a partir de las piezas
reales —cabecera, las 14 migraciones, la semilla y la comprobación final—. Se
escribió porque el instalador se armaba copiando y pegando, y eso se queda atrás
en cuanto se añade una migración. Comprobado instalando en una base limpia: 21
tablas, 46 políticas, 3 sedes, 3 terminales, 19 parámetros y las 7 funciones de
tablero.

`ACTUALIZAR-FASE4.sql` es para quien ya tiene el sistema con datos dentro. No
crea tablas ni toca filas. Comprobado sobre una base con personal ya cargado:
ejecutado dos veces seguidas deja el mismo resultado y el mismo número de
trabajadores, y sobre una base vacía se detiene con un mensaje claro en vez de
aplicarse a medias.

## Siguiente paso

**Fase 5 — Cierres, reportes y auditoría visible**: cierre semanal que mide e
informa pero **nunca descuenta dinero solo**, exportación de reportes y
consulta de la auditoría desde el panel.
