# Fase 14 · El mantenimiento recupera las noches que se perdió

## Lo que pasó

Entre el **18 y el 21 de septiembre de 2026** el mantenimiento nocturno de
CrediSan falló tres noches seguidas. El respaldo diario, también.

La causa no fue el código: **la contraseña de la base dejó de funcionar**, y
ninguna de las dos tareas pudo conectarse. El mismo motivo impidió que se
aplicara la fase 13.

Eso no es lo interesante. Una clave caduca, un servidor no contesta, una red se
cae: que una noche falle va a volver a pasar. Lo interesante es lo que el
sistema hacía **después**.

## El fallo real: no había forma de volver atrás

`app.job_close_yesterday()` cerraba literalmente **ayer**:

```sql
n := n + app.recompute_daily_branch(b.id, (now() at time zone b.timezone)::date - 1);
```

Así que una noche perdida se perdía **para siempre**. Al recuperarse la
conexión, la tarea de la noche siguiente volvía a cerrar sólo su propio ayer, y
los días de en medio se quedaban sin calcular sin que nadie se enterara.

En un sistema de asistencia eso significa **ausencias que no aparecen y semanas
que no cuadran**, hasta que alguien lo nota mirando un reporte. El dato en bruto
—las marcaciones— nunca se pierde; lo que falta es el cálculo. Pero nadie mira
marcaciones en bruto: se mira el reporte.

## Lo que cambia

- El **cierre diario** repasa los últimos cuatro días, no sólo el último.
- El **cierre semanal** repasa también la semana anterior, no sólo la última.

Si todas las noches funcionaron, repasar no cambia nada: `recompute_daily`
reescribe la misma fila con el mismo resultado. Si faltó una noche, se recupera
sola, sin que nadie tenga que acordarse.

Cuántos días se repasan es **configuración, no código**:
`maintenance.catchup_days`, hoy 4.

Recalcular el pasado ya era una operación normal aquí —el panel la ofrece en
«Recalcular»— y las fases anteriores están construidas contando con ella: por
eso la fase 12 cierra los horarios con fecha en vez de borrarlos, para que un
recálculo de un día viejo lo mida con el horario que regía **ese** día.

## Lo que no se toca

Lo que administración ya revisó. `compute_weekly_closure` lleva escrito
`where status = 'pendiente'`, así que una semana **ya aprobada no se recalcula
en absoluto**: ni sus cifras, ni su nota, ni quién la cerró.

Eso no se dio por hecho. La batería lo comprueba plantando a propósito una cifra
imposible (99 ausencias) en una semana aprobada y exigiendo que siga ahí después
de pasar la tarea nocturna. Si la fila se hubiera recalculado, el 99 habría
desaparecido.

## Lo que se comprobó

**Cuatro secciones SQL**: que tres noches caídas se recuperen en una; que
repasar días ya calculados no cambie nada ni duplique filas; que una semana
aprobada quede intacta; y que una semana pendiente sí se refresque —el otro lado
de la moneda, porque si no se refrescara, repasar no serviría de nada.

Comprobada al revés: se devolvió la función a su comportamiento anterior y la
batería falló nombrando la consecuencia real —«sólo alcanzó 1 de los 3 días
perdidos»—, no un número abstracto.

## Un apunte sobre otra prueba

Revisando el sistema, la batería `09_dia_completo.sql` falló. No por el
producto: la prueba elegía «ayer, o el viernes anterior si ayer fue fin de
semana», y ejecutada un **lunes** apuntaba al viernes —más de 72 horas atrás—,
así que el sistema rechazaba sus ocho marcaciones con `FUERA_DE_VENTANA_OFFLINE`.

Rechazarlas es correcto: es la regla de las marcaciones sin conexión. Era la
prueba la que pedía algo imposible, y **sólo fallaba los lunes**. Esquivar el fin
de semana además sobraba, porque la propia prueba fuerza el día con una excepción
de alcance empleado, que manda por encima del horario de la sede.

Ahora usa siempre ayer. Hoy es lunes, así que «ayer» fue domingo: la corrección
queda probada justo en el caso que antes se esquivaba.
