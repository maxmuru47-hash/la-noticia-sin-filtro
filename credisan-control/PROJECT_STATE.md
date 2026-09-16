# PROJECT_STATE · CrediSan Control Multisede

> Fuente única de continuidad. Se actualiza al cerrar cada fase.

| | |
|---|---|
| **Versión** | 1.0.0 |
| **Fase actual** | **6 — Las seis fases entregadas. Sistema completo** |
| **Fecha** | 2026-09-16 |
| **Instalación** | `supabase/instalador/INSTALAR.sql` (nuevo) · `ACTUALIZAR-FASE6.sql` (ya instalado) |
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
| 22 | El cierre semanal no tiene ni una columna ni una consulta de dinero | Requisito explícito. Un sistema que calcula solo cuánto descontar acaba descontando solo |
| 23 | Recalcular una semana nunca pisa un cierre ya revisado | La decisión de una persona no la puede borrar un trabajo automático |
| 24 | `computed_at` fuera de la comparación del disparador de auditoría | Si no, cada recálculo escribiría una fila por trabajador diciendo sólo «se calculó a otra hora» |
| 25 | El disparador de auditoría deriva la sede cuando la tabla no la lleva | La RLS filtra por sede: sin esto, la administradora no veía ni sus propios cambios de horario |
| 26 | El CSV se arma en el navegador, no en el servidor | El archivo no pasa por ningún sitio ni se queda guardado en ninguna parte |
| 27 | El reporte exportable no lleva cédulas ni salarios | Un archivo que circula por correo y se queda en carpetas de descargas |
| 28 | El PIN offline va en sobre cerrado (ECDH P-256 + HKDF + AES-GCM) | Una tableta robada no entrega ni un PIN: guarda algo que ni ella puede leer |
| 29 | **Sin** firma HMAC por elemento, al contrario de lo planeado en la Fase 1 | El `device_token` y la clave de firma viven en el mismo almacenamiento: firmar no añade ninguna garantía que el token no dé ya |
| 30 | `last_sync_at` guarda la hora más avanzada **declarada por el aparato** | Con la hora del servidor, una cola de 12 marcaciones sincronizaba 1 y perdía 11 |
| 31 | Sin conexión no se muestra identidad ni resultado | No se puede comprobar el PIN sin servidor; decir «listo» sería mentir |
| 32 | La foto se encola junto a la marcación | Perder la evidencia de 72 horas de marcaciones debilitaría el valor probatorio |

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
| `20260916100000_fase5.sql` | cierre semanal, reporte exportable, auditoría legible |
| `20260916150000_fase6.sql` | puerta de la marcación sin conexión, salud de terminales |
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
| `05_fase5.sql` | que el cierre no toca dinero (leído del código fuente), recalcular respetando lo revisado, reporte sin cédulas, y la auditoría vista por cada rol |
| `06_fase6.sql` | cola entera tras un corte largo, secuencia que no retrocede, ventana de 72 h, pimienta obligatoria, sede ajena, reloj desviado y la puerta cerrada al panel |

**Las seis en verde.** Además, seis pruebas de navegador real (Playwright con
el backend simulado), en `pruebas/navegador/`: kiosco con cámara, panel de
Fase 3, panel de Fase 4 (52), panel de Fase 5 (47, con descarga real del CSV),
panel de Fase 6 (16) y **el terminal sin conexión** (24), que corta la red de
verdad, abre IndexedDB para comprobar que el PIN no está en claro, y descifra
los sobres con una llave privada real.

## Deuda técnica y riesgos conocidos

| # | Asunto | Estado |
|---|---|---|
| 1 | Un trabajador puede marcar por otro si conoce su PIN | Asumido. La foto es la prueba. Biometría sería otra decisión |
| 2 | Revocar un rol no invalida el JWT vivo (30 min) | Mitigado con `is_active` bloqueando la renovación |
| 3 | Offline no puede confirmar identidad en pantalla | Por diseño: cachear credenciales en el dispositivo sería peor |
| 4 | `pg_cron` puede no estar disponible según el plan | La migración avisa; alternativa documentada |
| 5 | `next_expected` sólo mira el día local en curso | Suficiente con jornadas que no cruzan medianoche. Revisar si alguna sede lo hace |
| 6 | ~~Sin fuentes autohospedadas~~ | **Resuelto.** Poppins se sirve desde el propio servidor (52 KB, sólo subconjuntos latinos). El sistema no pide nada a Google |
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
- ~~La foto de ficha no se muestra en el terminal.~~ **Resuelto.** En el panel,
  cada ficha tiene «Poner foto» —en el teléfono abre la cámara directa—. Se
  recorta en cuadrado y se encoge a 400 px **en el navegador antes de subir**:
  una foto de teléfono son varios megas, y la pantalla de identidad del
  terminal dura cuatro segundos. El depósito es privado, así que la función
  del servidor emite una URL firmada de 90 s con la respuesta del PIN; la ruta
  interna nunca viaja al terminal.

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

## Fase 5 — entregada

**Backend** (migración 0015): `calcular_cierre_semana`, `cierres`,
`revisar_cierre`, `reporte_asistencia`, `auditoria`, `acciones_auditadas`.

### La promesa, y cómo está construida

El cierre **mide e informa, nunca descuenta dinero**. No es una nota en un
manual:

- `weekly_closures` no tiene ni una columna monetaria.
- Ninguna función del cierre consulta `employee_compensation`.
- La batería lo comprueba **leyendo el código fuente de las funciones** en
  el catálogo de PostgreSQL, así que sigue siendo cierto aunque alguien las
  edite mañana.
- `ACTUALIZAR-FASE5.sql` repite esa comprobación al terminar y **se niega a
  darse por instalado** si dejara de cumplirse.

### Tres decisiones del cierre

- **Recalcular no pisa lo revisado.** Si una semana ya se aprobó u observó,
  se deja como está y se informa de cuántas se respetaron. Un trabajo
  automático no puede borrar la decisión de una persona.
- **Observar exige explicar.** `con_observacion` sin nota se rechaza en la
  base de datos, no en el navegador.
- **Reabrir borra la firma**, porque lo exige la restricción de la tabla y
  porque ya no sería cierto que esté cerrado.

### Dos huecos reales que aparecieron al construir la auditoría

1. **`revisar_cierre` no podía escribir su propia auditoría.** Es
   `security invoker` —para que decida la RLS y no un `if`— y
   `authenticated` no tiene INSERT sobre `audit_logs`, que es append-only a
   propósito. La salida no fue abrir esa puerta: `weekly_closures` ya tiene
   disparador desde la Fase 1, y deja mejor rastro —con el antes y el
   después de la fila entera—. Se quitó la escritura manual.
2. **La auditoría era medio ciega para la administradora.** La RLS filtra
   por sede, y varias tablas se auditaban sin sede: los días de horario, la
   compensación, las excepciones. Resultado: ella cambiaba el horario de su
   sede y no podía consultar ni su propio rastro. Ahora el disparador
   deriva la sede donde el vínculo es directo. Lo que es de verdad global
   —sedes, configuración sin sede— sigue sin sede, y por tanto sólo a la
   vista de dirección.

### Panel

La barra inferior se reorganizó: **cinco huecos como mucho**, porque en un
teléfono seis ya no se leen. Quedó **Hoy · Personal · Novedades · Cierres ·
Más**, y Horarios, Sedes, Auditoría y Reportes viven dentro de «Más», que
se queda encendido mientras se está en una de ellas.

La auditoría traduce lo que enseña: `weekly_closures.update` se lee «Se
revisó un cierre semanal», `status` se lee «estado», y los valores salen
sin comillas de JSON y con «sí/no» en vez de `true/false`. La traducción
vive en un solo sitio.

El CSV se arma en el navegador —el archivo no pasa por ningún servidor—,
con BOM para que Excel respete los acentos y punto y coma como separador,
que es lo que espera un Excel configurado en español.

Validado con `05_fase5.sql` (8 bloques) y con una prueba de navegador real
de **45 comprobaciones** sobre los tres roles, incluida la descarga del CSV
de verdad y la comprobación de que no lleva cédulas, ni salarios, ni gente
de otra sede.

## Fase 6 — entregada

**Backend** (migración 0016): `app.identificar_por_pin`, `public.edge_offline`
—cerrada a todo el mundo menos al servidor—, `sincronizacion` y
`marcaciones_offline`.

**Función del servidor**: acción `sincronizar`, que abre los sobres, resuelve
cada elemento por separado y sube la fotografía por el mismo camino que el
flujo en línea (esa lógica se extrajo a `guardarEvidencia`, compartida).

**Terminal** (`public/src/kiosk/offline.js`): cola en IndexedDB, secuencia
monotónica persistente, reloj monotónico anclado a la cabecera `Date` de cada
respuesta del servidor, sobre cerrado, y sincronización automática al volver
la señal, al arrancar y cada dos minutos.

### El sobre cerrado

Sin conexión el terminal **no puede** comprobar un PIN: la pimienta vive en el
servidor. Y guardarlo en claro mientras espera entregaría el PIN de toda la
sede con la tableta. Así que se cifra con la llave pública del servidor
—ECDH P-256 efímero, HKDF-SHA256, AES-GCM—: el aparato guarda algo que ni él
mismo puede volver a leer. Par nuevo por cada sobre, así que dos marcaciones
del mismo PIN no se pueden relacionar mirando la cola.

Comprobado en el navegador: la prueba abre IndexedDB y `localStorage` y exige
que el PIN no aparezca en ninguno de los dos.

### Una decisión contra el plan original

La Fase 1 preveía **firmar cada elemento con HMAC**. Al implementarlo se vio
que no protege de nada: el `device_token` y la clave de firma viven los dos en
el almacenamiento del mismo navegador —quien tenga uno tiene el otro— y el
tránsito ya lo cubre TLS. Se descartó, y se dejó escrito por qué en el propio
código. Lo que sí protege de verdad —que el PIN vaya cifrado con una llave que
el dispositivo no posee— sí está.

### El fallo que sólo aparece sincronizando de verdad

`register_offline_punch` guardaba `last_sync_at = now()` con cada marcación
aceptada, y rechazaba toda marcación anterior a ese valor. Con una cola real:

> Un terminal pasa tres horas sin señal y acumula doce marcaciones. La
> primera —la de hace tres horas— entra, y al entrar pone `last_sync_at` en
> la hora actual. **Las once siguientes se rechazan todas.**

Es decir: el modo sin conexión sólo funcionaba con exactamente una marcación
en la cola. En el escenario para el que existe, se perdía casi todo.

La corrección fue entender qué debe significar el campo: no «cuándo
sincronizamos» —para eso está `last_seen_at`— sino «la hora más avanzada que
este aparato nos ha declarado». Hay una prueba dedicada que manda cuatro
marcaciones de una jornada y exige que entren las cuatro.

### Puesta en marcha

`node supabase/functions/generar-llaves.mjs` imprime el par. La pública va en
`config/env.js`; la privada, en el secreto `CREDISAN_OFFLINE_PRIVATE_KEY`. Sin
las llaves el terminal funciona igual con internet y lo dice si no lo hay.

## La foto de ficha

Era la última deuda de la Fase 3 y cierra el requisito original: el trabajador
marca su PIN y el terminal enseña **su nombre, su foto y su cargo**.

Tres piezas, porque el depósito de fotos es privado y el terminal no tiene
sesión de Supabase:

- **Panel**: botón por trabajador; en el teléfono abre la cámara frontal
  directamente. Recorte cuadrado centrado y escalado a 400 px en el navegador,
  antes de subir. La ruta es `<sede>/<trabajador>.jpg`, que es lo que exige la
  política de seguridad del depósito.
- **Servidor**: con la respuesta del PIN emite una URL firmada de 90 segundos
  —lo que dura la pantalla de identidad— y **borra la ruta interna** de la
  respuesta: es un dato menos viajando a un aparato de mostrador.
- **Terminal**: las iniciales primero siempre, y la foto las tapa cuando
  carga. Si tarda o falla, se quedan las iniciales; nunca impide marcar. Antes
  de pegarla comprueba que sigue siendo la misma persona en pantalla: si
  alguien marcó mientras la imagen viajaba, enseñaría la cara equivocada.

Probado en navegador con una foto apaisada de 1200×600 —como la da una cámara
de teléfono—: sale cuadrada de 400×400, pesa 2 KB, y **el centro de la imagen
sobrevive al recorte**, que es lo que falla cuando se recorta mal.

## El terminal funciona de verdad sin internet

Al cerrar la Fase 6 quedó un fallo de los que sólo se ven al juntar las
piezas: **el service worker nunca se actualizó**. Su propio comentario decía
«nada relacionado con marcaciones se cachea: eso llega en la fase 6», y la
Fase 6 llegó sin volver aquí.

Consecuencia: el terminal no guardaba su propia página ni sus módulos, y
—peor— **ni siquiera registraba el service worker**, porque eso sólo lo hacía
la portada y un aparato de mostrador nunca abre la portada. Sin internet no
cargaba nada. La cola que se había construido para guardar marcaciones no
llegaba a existir.

Corregido en tres frentes:

- El kiosco **se registra a sí mismo** y precachea su concha entera: HTML, los
  tres módulos, estilos, tipografías y `config/env.js`. Uno a uno y no con
  `addAll`, porque con `addAll` un solo archivo que falte tira la instalación
  completa y el terminal se queda sin nada.
- Los documentos pasaron a **guardar copia**. Antes eran «red primero con
  respaldo en caché», pero nadie guardaba nada en esa caché: el respaldo nunca
  tenía qué devolver.
- **Poppins se sirve desde el propio servidor.** Un terminal pensado para
  funcionar sin red no puede depender de Google para su tipografía.

Y el kiosco tiene **manifiesto propio**: instalado en la tableta se llama
«Marcar» y el icono abre directo el teclado, no la portada.

Probado con `kiosk-sinred-test.js`, que carga el terminal, **apaga la red del
todo**, recarga y exige que arranque con su teclado completo, su tipografía y
su configuración intacta —incluida la llave pública, sin la cual no podría
cifrar el PIN—.

Esa corrección destapó otra: `kiosk-test.js` empezó a decir que el terminal
perdía su vinculación al recargar. No era eso. El service worker hace sus
**propias** peticiones, que no pasan por los simuladores de Playwright, así
que cacheaba el `env.js` real del repositorio —vacío— en vez del simulado.
Las pruebas que simulan configuración y no prueban el service worker ahora lo
bloquean; la que sí lo prueba escribe un `env.js` de verdad y lo restaura.

## Despliegue automático

Dos flujos de GitHub, ambos en `.github/workflows/`:

| Flujo | Qué publica | Se dispara con |
|---|---|---|
| `desplegar-credisan.yml` | La web al VPS por SSH | cambios en `public/**` |
| `desplegar-funcion.yml` | La Edge Function a Supabase | cambios en `supabase/functions/**` |
| `migrar-base.yml` | La estructura de la base | cambios en `migrations/**` o `instalador/**` |

El segundo también se encarga de los secretos, con una regla que manda sobre
todo lo demás: **un secreto que ya existe no se toca nunca**. Los crea sólo si
faltan. Regenerar la pimienta invalidaría el PIN de todos los trabajadores a
la vez; regenerar la llave del modo sin conexión dejaría ilegibles las
marcaciones que estuvieran esperando en un terminal. Por eso no hay botón de
«regenerar».

Cuando genera las llaves del modo sin conexión, deja la pública en el
`config/env.js` del servidor: copia con fecha antes, y se cambia sólo esa
línea. Probado contra las dos formas que puede tener ese archivo —con la línea
y sin ella—, comprobando que la clave del cliente, la URL y la versión quedan
intactas y que sigue siendo JavaScript válido.

Dos apuntes del flujo, por si hiciera falta tocarlo:

- El contexto `secrets` **no se puede consultar dentro de un `if` de paso**:
  allí sale siempre vacío y la condición no se cumpliría nunca. Se resuelve en
  un paso previo y se pasa como salida.
- La función se publica con `--no-verify-jwt`, que es deliberado y está
  explicado en `docs/FASE3_TERMINAL.md`.

### Las migraciones

Se automatizaron a petición expresa. Se pudo hacer sin riesgo real porque los
cuatro `ACTUALIZAR-*.sql` son **exclusivamente `create or replace function`**:
cambian lo que el sistema sabe hacer, nunca lo que tiene guardado. Ni un
`alter table`, ni un `drop`, ni un `delete`.

Y no se confía en que siga siendo así. Cuatro barreras, en este orden:

1. **Se leen los archivos antes de ejecutarlos** y el flujo aborta —sin
   conectarse siquiera a la base— si aparece un `drop`, `truncate` o `delete`.
   Los comentarios se descartan antes de mirar, porque este proyecto explica
   mucho en castellano y esas palabras salen en las explicaciones.
2. **Una transacción por archivo.** Un fallo a mitad deja la base como estaba.
3. **Se cuentan trabajadores y marcaciones antes y después.** Si bajara
   cualquiera de los dos, el flujo falla en rojo.
4. **La copia guardada es sólo la estructura**, sin una fila. Sacar nombres,
   cédulas y horarios a un artefacto de GitHub sería llevárselos de donde deben
   estar; de los datos se encarga el respaldo de Supabase.

No hay registro de migraciones que pueda desincronizarse: los archivos son
idempotentes y se aplican todos en orden, así que el flujo **converge desde
cualquier estado**. Si la base está vacía, instala desde cero.

Probado contra una base que replica el estado real de producción —fases 1 a 3,
con doce trabajadores y sus salarios—: la llevó a la fase 6 con todo intacto, y
una segunda pasada completa no cambió nada. La barrera antidestrucción se probó
plantando un archivo con `drop table employees`: el flujo se detuvo antes de
conectarse.

## Estado: las seis fases entregadas

Falta únicamente lo que depende de la cuenta de Supabase del cliente, y que no
puede hacerse desde aquí porque son sus llaves: **tres secretos en GitHub y dos
botones**. Paso a paso en `docs/EMPEZAR-AQUI.md`.
