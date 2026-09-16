# Fase 4 · Los tableros

Un solo paso en Supabase, y el panel se llena de información.

---

## 1 · Actualizar la base de datos *(2 min)*

Supabase → **SQL Editor** → **New query** → pegue **`ACTUALIZAR-FASE4.sql`**
entero → **Run**.

Al terminar verá un mensaje así:

```
CREDISAN CONTROL — Fase 4 aplicada
Funciones de tablero ....... 7 de 7
Trabajadores (intactos) .... 12
Marcaciones (intactas) ..... 340
```

Esos dos números están ahí a propósito: son **sus datos, contados después**
de la actualización. Esta fase no crea tablas, no borra nada y no cambia una
sola fila. Sólo añade siete consultas. Si los números no coinciden con lo que
usted esperaba, no siga y avise.

Puede ejecutarlo dos veces si duda: el resultado es idéntico.

---

## 2 · Qué verá cada persona

El panel decide qué enseñar según quién entra. No es una cortesía de
pantalla: la base de datos entrega sólo lo que a esa persona le corresponde.

### Dirección general

Cinco pestañas y el país entero.

- **Hoy** — elige la sede y ve, en vivo, quién llegó, quién no ha llegado,
  quién llegó tarde y con cuántos minutos.
- **Semana / Mes** — comparativo entre las tres sedes, con porcentaje de
  puntualidad, y el ranking de los cinco más puntuales.
- Personal, Novedades, Horarios y Sedes, como hasta ahora.

### Administradora

Lo mismo, pero **sólo su sede**. No puede crear sedes ni repartir accesos al
panel. Sí ve el salario, porque es un dato de su trabajo.

### Jefe operativo

Tres pestañas: **Hoy**, **Personal** y **Novedades**. Nada de estadísticas de
gerencia, nada de horarios, nada de sedes y **ninguna cifra de dinero** —el
salario vive en otra tabla y su usuario no tiene permiso para abrirla—.

Puede **reportar** una novedad. No puede **aprobarla**: eso es de
administración.

---

## 3 · Dos detalles que conviene entender

### Los colores del día

| Punto | Significa |
|---|---|
| Verde | Llegó a tiempo, antes de hora, o ya cumplió la jornada completa |
| Amarillo | Llegó tarde (se indican los minutos) |
| Rojo | No ha llegado y su hora ya pasó |
| Gris | Todavía no es su hora, o es su día de descanso |

Un día de descanso **nunca** cuenta como ausencia. Si algún sábado se activa,
basta con encender el interruptor en **Horarios**: no hay que tocar nada más.

### El aviso de las ausencias

En Semana o Mes puede aparecer este mensaje:

> *Las ausencias de este período aún no se han calculado. El cero de arriba
> no es un dato: es que nadie lo ha mirado todavía.*

Es deliberado. Una ausencia es la **falta** de una marcación, y para contarla
hay que repasar el calendario día por día. Mientras eso no se haya hecho,
enseñar un «0 ausencias» sería mentir con un número. El botón
**«Calcular ausencias del período»** lo repasa en el momento.

Cuando `pg_cron` esté activo en Supabase (ver `docs/SUPABASE_SETUP.md`), ese
repaso ocurre solo cada noche y el aviso deja de salir.

---

## 4 · Las novedades

Una novedad es cualquier cosa que explique lo que el reloj no explica: un
permiso, un reposo, una diligencia del banco.

Hay dos maneras de que aparezca:

- **Automática.** El sistema la abre solo. La más común: alguien marcó y la
  cámara no respondió. La marcación **se registra igual** —nunca se deja a una
  persona sin marcar por un fallo del teléfono— y queda señalada como
  «Sin foto» con su novedad abierta.
- **Manual.** Cualquiera con panel la reporta desde **+ Nueva**.

Toda novedad nace **pendiente**. Administración la aprueba o la rechaza, y
ahí queda el rastro de quién decidió y cuándo.

---

## 5 · Lo que esta fase todavía no hace

Para que no lo busque:

- **No cierra la semana** ni calcula descuentos. Eso es la Fase 5 — y aun
  entonces el sistema medirá e informará, pero **nunca descontará dinero
  solo**.
- **No exporta a Excel ni a PDF.** También Fase 5.
- **Nada de esto funciona sin la Edge Function de la Fase 3.** Si aún no la
  publicó, no hay PIN, no hay marcaciones y los tableros saldrán vacíos —
  correctamente vacíos, pero vacíos—. Vea `docs/FASE3_TERMINAL.md`.
