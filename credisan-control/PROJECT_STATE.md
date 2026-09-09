# PROJECT_STATE · CrediSan Control Multisede

> Fuente única de continuidad. Se actualiza al cerrar cada fase.

| | |
|---|---|
| **Versión** | 1.0.0 |
| **Fase actual** | **1 — Backend completa. En espera de aprobación** |
| **Fecha** | 2026-09-09 |
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

## Siguiente paso

**Fase 2 — Autenticación y administración**: login, gestión de roles y sedes,
alta y ficha de trabajadores (con la Edge Function `admin-pin`), asignación de
horarios y edición del calendario por sede.

*No iniciar sin aprobación de la Fase 1.*
