# PROJECT_STATE · CrediSan Control Multisede

> Fuente única de continuidad. Se actualiza al cerrar cada fase.

| | |
|---|---|
| **Versión** | 1.0.0 |
| **Fase actual** | **2 — Panel administrativo completo. Listo para instalar** |
| **Fecha** | 2026-09-09 |
| **Instalación** | `supabase/instalador/INSTALAR.sql` — un solo archivo, un solo RUN |
| **Producción** | control.sinfiltroconmax.com (Hostinger, plan Business, sitio PHP/HTML) |
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
| `seed/seed.sql` | 3 sedes, 3 terminales, jornada estándar, 19 parámetros |

## Variables de entorno

**Públicas** (`config/env.js`): `supabaseUrl`, `supabaseAnonKey`, `appBaseUrl`, `offlinePublicKey`.

**Secretas** (sólo Edge Functions): `SUPABASE_SERVICE_ROLE_KEY`,
`CREDISAN_PIN_PEPPER`, `CREDISAN_OFFLINE_PRIVATE_KEY`.

## Validación ejecutada

`PGPORT=54329 ./supabase/tests/run_local.sh` sobre PostgreSQL 16 local: **15
bloques, todos en verde**. Cubre PIN (débil, repetido, sin pimienta),
emparejamiento (un solo uso, token falso, sede inmutable), marcación
(clasificación, idempotencia, doble marcación, ticket usado, sede ajena),
evidencia ausente, offline (futuro, ventana, secuencia, sede, rastro de
rechazos), resumen diario, sábado inactivo, RLS de los cuatro roles, Storage,
auditoría sin secretos y superficie de ejecución.

## Deuda técnica y riesgos conocidos

| # | Asunto | Estado |
|---|---|---|
| 1 | Un trabajador puede marcar por otro si conoce su PIN | Asumido. La foto es la prueba. Biometría sería otra decisión |
| 2 | Revocar un rol no invalida el JWT vivo (30 min) | Mitigado con `is_active` bloqueando la renovación |
| 3 | Offline no puede confirmar identidad en pantalla | Por diseño: cachear credenciales en el dispositivo sería peor |
| 4 | `pg_cron` puede no estar disponible según el plan | La migración avisa; alternativa documentada |
| 5 | `next_expected` sólo mira el día local en curso | Suficiente con jornadas que no cruzan medianoche. Revisar si alguna sede lo hace |
| 6 | Sin fuentes autohospedadas todavía | Se añaden en la Fase 3 con el kiosco |
| 7 | Edge Functions sin implementar | Contratos fijados en `supabase/functions/README.md`; fases 2 y 3 |

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

## Siguiente paso

**Fase 3 — Terminal de marcación**: emparejamiento del dispositivo, teclado de
PIN, identificación del trabajador, marcación con hora de servidor, captura
fotográfica y pantalla de resultado. Incluye las Edge Functions `admin-pin`,
`terminal-pair`, `punch` y `punch-confirm`.
