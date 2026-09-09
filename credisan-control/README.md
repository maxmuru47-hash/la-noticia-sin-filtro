# CrediSan Control Multisede v1.0

> **Pensamos en Ti**

Control de asistencia, puntualidad, horarios e incidencias del personal de
CrediSan en todas sus sedes. No es una maqueta: es un sistema multiusuario y
multisede pensado para uso real.

**Producción:** https://control.sinfiltroconmax.com

---

## La regla que manda sobre las demás

**Ninguna marcación se reescribe.** Todo lo demás está subordinado a eso:

| Regla | Dónde vive |
|---|---|
| La hora la pone el servidor | `app._insert_punch()` usa `now()`, nunca el cliente |
| La sede la impone el terminal | `attendance_events.branch_id` sale de `terminals` |
| Nadie edita una marcación, ni el CEO | `attendance_events` sin políticas de UPDATE/DELETE |
| Una corrección conserva el original | tabla `attendance_corrections` |
| El PIN no se guarda ni se ve | `bcrypt` + índice ciego, con pimienta fuera de la base |
| El jefe operativo no ve salarios | tabla `employee_compensation` aparte |
| Una administradora no sale de su sede | `app.can_see_branch()` en cada política |
| Ningún horario está en el código | `work_schedules` + `schedule_days` |
| Ningún umbral está en el código | `system_settings` |
| El sistema mide, no descuenta | `weekly_closures` no tiene columna de dinero |
| Falta de foto no impide marcar | `app.mark_missing_evidence()` abre una novedad |
| Cada rechazo deja rastro | las funciones devuelven el motivo, no lanzan excepción |

## Arquitectura

```
TERMINAL (kiosco)          PANEL (PWA)              SUPABASE
teclado PIN + cámara       CEO / Admin / Jefe       Auth + claims en el JWT
cola en IndexedDB          supabase-js con RLS      PostgreSQL + RLS
device_token               sin service_role         Storage privado
   │                             │                  Edge Functions + pg_cron
   └── Edge Function `punch` ────┴──────────────────► rpc SECURITY DEFINER
```

- **Frontend**: HTML + CSS + JavaScript modular, sin framework ni empaquetador.
  El kiosco tiene que arrancar en menos de dos segundos en un Android de gama
  baja. Se aloja como sitio estático en Hostinger.
- **Backend**: Supabase (PostgreSQL, Auth, RLS, Storage, Edge Functions).
- **PWA** instalable, preparada para trabajar sin conexión.

## Estructura

```
credisan-control/
├── public/            sitio estático (kiosco, panel, activos de marca, PWA)
├── src/               módulos JS: core, kiosk, panel, domain, ui
├── supabase/
│   ├── migrations/    esquema, funciones, RLS, Storage, trabajos programados
│   ├── seed/          3 sedes, 3 terminales, horarios y configuración
│   ├── tests/         batería de validación ejecutable en PostgreSQL local
│   └── functions/     Edge Functions (contratos definidos, fases 2–3)
├── config/            configuración del cliente (env.js no se versiona)
└── docs/              puesta en marcha, seguridad, despliegue
```

## Puesta en marcha

1. Backend: [`docs/SUPABASE_SETUP.md`](docs/SUPABASE_SETUP.md)
2. Seguridad: [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md)
3. Despliegue: [`docs/DESPLIEGUE.md`](docs/DESPLIEGUE.md)
4. Estado y avance: [`PROJECT_STATE.md`](PROJECT_STATE.md)

## Validar sin tocar Supabase

```bash
PGPORT=54329 ./supabase/tests/run_local.sh
```

Levanta el esquema completo en un PostgreSQL local y verifica el aislamiento por
sede, el salario oculto al jefe operativo, la inmutabilidad de las marcaciones,
el PIN, la ventana offline, el Storage privado y la auditoría.

## Marca

El logo es el oficial del manual de identidad, sin reinterpretar.
Ver [`public/assets/brand/PROCEDENCIA.md`](public/assets/brand/PROCEDENCIA.md).
