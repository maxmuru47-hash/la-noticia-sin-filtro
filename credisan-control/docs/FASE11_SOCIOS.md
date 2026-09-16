# Fase 11 · Socios: una persona, varias sedes

Hasta aquí, cada usuario del panel pertenecía a **una** sede. Los socios de
CrediSan no encajan en eso: hay quien participa en Maracaibo y Maracay, quien
está en las tres y quien sólo en Caja Seca.

```
Eliana González    → Maracaibo, Maracay
Reynaldo González  → Maracaibo, Maracay
Ramiro Araujo      → Caja Seca, Maracaibo, Maracay
Marilyn González   → Caja Seca
Marienny González  → Caja Seca
```

## Lo que un socio puede y no puede

| Puede | No puede |
|---|---|
| Ver quién llegó, a qué hora y quién faltó, en sus sedes | Ver salarios |
| Ver el cierre de la semana | Ver las fotografías de las marcaciones |
| | Dar de alta personal, dar PIN, cambiar horarios |
| | Reportar novedades ni justificar faltas |
| | Cerrar o reabrir una semana |
| | Ver ninguna sede que no se le haya marcado |

## Por qué salió barato

Todo el sistema pregunta «¿esta persona puede ver esta sede?» en **78 sitios**,
y los 78 le preguntan a **una sola función**: `app.can_see_branch()`. Pasar de
«una sede» a «una lista de sedes» se toca ahí, y los 78 obedecen la regla nueva
sin modificarse. Esa decisión se tomó en la fase 1 y es la que hace que esto sea
una tarde de trabajo en vez de una revisión de setenta y ocho reglas.

## La puerta que había que cerrar

Ampliar el alcance no da permiso de escribir: **todas** las reglas de escritura
exigen ya el rol —«ceo o admin»—, nunca sólo la sede. Un socio no es ninguno de
los dos, así que quedaba fuera por construcción.

Con una excepción, que es justo lo que había que encontrar: `incidents_insert`
pedía sólo la sede, **a propósito**, para que el jefe operativo pudiera reportar
novedades. Tal cual, un socio habría podido crear novedades. Se le añadió el rol
a esa regla, y hay una prueba que comprueba las dos mitades: que el socio ya no
puede, y que **el jefe operativo sigue pudiendo**. Cerrar una puerta es fácil;
cerrarla sólo para quien sobra es el trabajo.

## Lo que no hizo falta escribir

Ni los salarios ni las fotografías necesitaron una línea: `compensation_select`
y `evidence_select` ya exigen ceo o admin. Se comprueban igual en la batería,
porque son promesas, no detalles de implementación.

El **reporte** se dejó fuera del alcance del socio. No porque tenga nada que
esconder —no lleva cédulas ni salarios— sino porque el panel no se lo ofrece, y
un permiso que nadie usa es una puerta que alguien tendrá que acordarse de
vigilar.

## Cómo se da un acceso

1. En Supabase: **Authentication → Users → Add user**, con el correo del socio y
   una clave. (Mejor su correo real: así puede recuperarla solo.)
2. En el panel, entrando como dirección: **Más → Sedes → Socios**.
3. Escriba ese mismo correo, su nombre, y **marque las sedes** con casillas.
4. Entrégale el correo y la clave. Él abre
   `control.sinfiltroconmax.com/panel/` y se la instala como app.

Para cambiarle las sedes, **Cambiar** y marque distinto. Quitar una sede le
cierra ese acceso **en el acto**, no cuando alguien se acuerde de revisarlo — y
hay una prueba que lo exige.

## Por qué la fase son dos archivos

PostgreSQL **no deja usar un valor de rol recién creado en la misma transacción
que lo creó**. Y cada archivo se aplica en una sola transacción, a propósito,
para que un fallo a mitad no deje la base a medias. Así que el rol se confirma
en `ACTUALIZAR-FASE11-1-ROL.sql` y todo lo demás va en el `-2`.

En una instalación nueva no aparece el problema: ahí el tipo se crea ya con los
cuatro roles de una vez. Los dos caminos están probados por separado —una base
desde cero en una sola transacción, y una réplica del estado de producción
recibiendo la cadena entera— porque el que no se prueba es el que falla.

## Lo que se comprobó

**Diez baterías SQL**, entre ellas la de socios con estas comprobaciones: que
Eliana ve dos sedes y Marilyn una; que ninguno ve un salario ni una fotografía;
que no crea novedades, personal, horarios ni salarios; que no calcula cierres ni
ve los accesos de los demás; que consulta lo suyo y sólo lo suyo; que retirar
una sede cierra el acceso en el acto; y que un correo repetido no degrada a
quien ya tenía otro acceso.

**Veinte comprobaciones de navegador**: que dirección vea la pantalla y
administración no; que se mande al servidor exactamente lo marcado; y que el
panel de un socio no le ofrezca ningún botón que la base le vaya a rechazar.

Esconder un botón es cortesía. Quien decide es la base de datos.
