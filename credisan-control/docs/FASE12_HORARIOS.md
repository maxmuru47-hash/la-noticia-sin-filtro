# Fase 12 · El horario propio de un trabajador

No todo el mundo hace el horario de su sede. El vigilante entra más tarde,
alguien hace jornada corrida donde los demás la hacen partida, y a una persona
se le aprobó salir antes por un asunto que no es de este sistema.

Medir a esa persona contra el horario general la convierte en **impuntual todos
los días por una diferencia que la empresa aprobó**. Y el sistema existe para
medir, así que medir mal no es un detalle cosmético: es la única cosa que no
puede fallar.

## Lo que ya estaba, y no por suerte

Casi todo. El calendario se diseñó en la fase 1 con esta prioridad escrita en
su cabecera:

```
excepción de empleado > excepción de sede > excepción global
> horario de EMPLEADO > horario de SEDE
```

`app.effective_day()` ya buscaba primero el horario del trabajador. Y como
**toda** la medición pasa por ahí —las marcaciones esperadas, la
clasificación, los minutos previstos, el cierre semanal—, un horario propio ya
se respetaba en todos esos sitios.

Las reglas de escritura también estaban: `sched_assign_write` exige «ceo o
admin» **y** que la sede sea visible. La administradora de Maracaibo podía
tocar a los suyos y a nadie más.

Lo que faltaba era **la puerta**. Hacerlo requería escribir SQL a mano en tres
tablas, y eso no se le pide a una administradora.

## Lo que se añadió

Cuatro funciones, ninguna tabla nueva:

| Función | Qué hace |
|---|---|
| `horario_de_trabajador()` | Devuelve el horario con el que se le mide hoy, y **dice cuál es**: el suyo o el de su sede |
| `dar_horario_propio()` | Se lo pone o se lo cambia, validando los siete días |
| `quitar_horario_propio()` | Lo devuelve al horario general de su sede |
| `horarios_propios()` | Quiénes lo tienen, para marcarlos en la lista |

En el panel: **Personal → el trabajador → «Horario»**. El formulario arranca
relleno con el horario que hoy se le aplica, así que cambiar una hora de
entrada es cambiar una hora, no rellenar catorce casillas.

## Quién puede

El usuario lo pidió con estas palabras: *«solo puede hacerlo las
administradoras en cada sede»*.

| | Puede |
|---|---|
| Dirección | En las tres sedes |
| Administradora | **Sólo en la suya** |
| Jefe operativo | No |
| Socio | Mirar sí, cambiar no |

El jefe operativo queda fuera a propósito. Ve su sede y reporta novedades, pero
**con qué vara se mide a alguien no es operación del día: es una decisión**. El
socio puede consultar el horario porque entender por qué a alguien se le mide
distinto es parte de leer el reporte; cambiarlo, no.

## El pasado no se reescribe

Es la decisión de fondo de esta fase, y la que más fácil habría sido hacer mal.

Ayer una persona entró a las 09:00 y fue **puntual**, porque su horario decía
09:00. Si hoy se le cambia a las 07:00 y el cambio rigiera hacia atrás, el
mantenimiento nocturno —que recalcula días pasados— convertiría aquel día en
dos horas de retraso. Sin que nadie se entere, y en un sistema del que depende
una conversación con un trabajador.

Así que ni cambiar ni quitar borran nada:

- **Cambiar** cierra el horario anterior *ayer* y empieza el nuevo *hoy*.
- **Quitar** hace lo mismo: cierra con fecha, no borra la fila.

Las dos operaciones tienen el mismo día de corte —hoy— porque si no, nadie
sabría cuál manda. La única excepción es un horario asignado **hoy mismo**: ése
no rigió ningún día cerrado, así que se corrige en sitio y no deja un horario
huérfano por cada rectificación de la misma mañana.

## Lo que se comprobó

**Siete secciones SQL**, con la medición real de una jornada entera: dos
personas de la misma sede fichan **a la misma hora, las 09:00**, y una llega
una hora tarde mientras la otra llega a su hora. Quien hace jornada corrida
cierra el día como *completo* con dos marcaciones, sin salir *incompleto* por
no haber ido a almorzar a una hora que no tiene. Y al cambiarle el horario, el
día de ayer se recalcula y **sigue saliendo puntual**.

Las pruebas se comprobaron al revés, que es la única forma de saber que sirven:
se rompió el motor para que ignorara el horario del trabajador, se rompió la
preservación del pasado y se quitó el control de rol. Las tres roturas salieron
en rojo, cada una en la comprobación que le tocaba.

**Treinta y una comprobaciones de navegador**: que el botón esté en la ficha de
la persona; que se mande al servidor exactamente lo marcado en pantalla —con el
almuerzo fuera cuando la jornada es corrida, que es justo lo que la base
rechazaría—; y que al jefe operativo y al socio el panel **no les ofrezca un
botón que la base les va a rechazar**.

Esconder un botón es cortesía. Quien decide es la base de datos.
