# Fase 5 · Cierre semanal, reportes y auditoría

Un solo paso en Supabase. Después, tres cosas nuevas en el panel.

---

## Lo primero, porque es lo que más importa

**El sistema mide e informa. Nunca descuenta dinero.**

No es una promesa escrita en un manual: está construido así. La tabla del
cierre no tiene ni una columna de dinero, y ninguna de las funciones del
cierre consulta los salarios. El propio archivo de actualización lo
comprueba al terminar y se niega a instalarse si eso dejara de ser cierto.

El cierre le dice: *esta persona acumuló 37 minutos de retraso y faltó un
día*. Qué hacer con eso lo decide usted, fuera del sistema.

---

## 1 · Actualizar la base de datos *(2 min)*

Supabase → **SQL Editor** → **New query** → pegue **`ACTUALIZAR-FASE5.sql`**
entero → **Run**.

Al terminar verá algo así:

```
CREDISAN CONTROL — Fase 5 aplicada
Funciones nuevas ........... 6 de 6
Trabajadores (intactos) .... 12
Marcaciones (intactas) ..... 340
Auditoría acumulada ........ 58 registros
El cierre no consulta salarios: comprobado.
```

No crea ninguna tabla. Las dos que hacen falta —cierres y auditoría—
existen desde el primer día; lo que faltaba era poder usarlas.

---

## 2 · El cierre semanal

Pestaña **Cierres**, nueva en la barra de abajo.

1. Elija la sede y la semana. Por defecto aparece **la semana pasada**,
   porque la actual todavía está ocurriendo.
2. Pulse **Calcular la semana**. El sistema repasa los siete días uno a
   uno y arma la ficha de cada trabajador.
3. Revise cada ficha y decida: **Aprobar** u **Observar**.

Cada ficha trae:

| Dato | Qué significa |
|---|---|
| Asistencia | Días cumplidos sobre días que tocaba trabajar |
| Puntualidad | Marcaciones a tiempo sobre marcaciones hechas |
| Horas | Trabajadas frente a esperadas |
| Retrasos | Cuántos, y cuántos minutos en total |
| Ausencias | Días laborables sin marcar y sin justificar |
| Sin foto | Marcaciones que se registraron sin evidencia |

Los días de descanso **no entran en el cálculo**. Un sábado apagado no le
baja la asistencia a nadie.

### Tres detalles pensados a propósito

**Recalcular no borra decisiones.** Si ya aprobó la semana de alguien y
vuelve a pulsar «Calcular», esa ficha se deja como está. El sistema le
dirá cuántas respetó. Nunca se pierde lo que una persona decidió.

**Observar exige explicar.** No se puede marcar «con observación» sin
escribir cuál. Una observación vacía no le sirve a nadie dentro de tres
meses.

**Reabrir borra la firma.** Si reabre un cierre, vuelve a quedar sin
revisar y desaparece el nombre de quien lo había cerrado — porque ya no
es cierto que esté cerrado.

---

## 3 · Los reportes

**Más → Reportes**. Elige sede y fechas, y descarga un **CSV** que se abre
en Excel con los acentos bien puestos.

Lleva la asistencia día por día: estado, marcaciones, minutos esperados y
trabajados, retrasos, ausencias.

**No lleva cédulas ni salarios.** Un archivo que va a circular por correo
y a quedarse en la carpeta de descargas de varias personas no tiene por
qué llevarlos.

También hay un **Descargar CSV** dentro de Cierres, para la semana que
esté viendo.

> Si le sale «no hay nada que exportar», casi siempre es que esos días aún
> no se han calculado. Ábralos en **Cierres** y púlselo allí.

---

## 4 · La auditoría

**Más → Auditoría**.

Todo lo que se cambia en el sistema queda registrado solo, desde el primer
día, sin que nadie tenga que acordarse de anotarlo. Lo que faltaba era
poder mirarlo sin entrar a la base de datos.

Cada línea dice **qué pasó, quién lo hizo, cuándo, y qué había antes**:

> **Se revisó un cierre semanal** — 16 sept., 06:02
> Administración Maracaibo · Maracaibo
> *estado* ~~pendiente~~ → **aprobado**
> *observación* ~~(vacío)~~ → **Permiso consignado**

Esta pantalla **sólo lee**. El registro no se puede editar ni borrar,
tampoco desde aquí, y eso es justamente lo que le da valor: si se pudiera
arreglar, no probaría nada.

### Quién ve qué

| | Ve |
|---|---|
| **Dirección** | Todo, de todas las sedes, incluido lo global (sedes, configuración) |
| **Administradora** | Lo de su sede: su personal, sus horarios, sus cierres, sus novedades |
| **Jefe operativo** | **Nada.** Esta pantalla ni siquiera le aparece |

---

## 5 · Lo que sigue sin estar

- **La marcación**, si aún no publicó la Edge Function de la Fase 3. Sin
  ella no hay PIN ni marcaciones, y los cierres saldrán con todo en cero
  —correctamente en cero, pero en cero—. Vea `docs/FASE3_TERMINAL.md`.
- **El modo sin conexión** es la Fase 6.
- **El cálculo automático de los cierres cada semana** necesita `pg_cron`
  activo. Mientras tanto, se pulsa el botón.
