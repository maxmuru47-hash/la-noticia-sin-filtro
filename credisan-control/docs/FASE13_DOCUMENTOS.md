# Fase 13 · El documento de una novedad

El personal se dirige al jefe operativo, que es quien pasa la novedad. Pero un
**permiso lo da gerencia** y un **reposo lo da un médico**: ninguno de los dos
nace en el mostrador.

Hasta aquí esos papeles vivían en un teléfono, en un grupo de WhatsApp o en una
gaveta. Administración tenía que decidir si le pagaba la semana completa a
alguien fiándose de que el permiso existía.

## Lo que ya estaba

Más de lo que parecía, y por eso esta fase no inventó infraestructura:

| Pieza | Desde |
|---|---|
| Depósito `novedades`, privado, PDF e imágenes hasta 10 MB | Fase 1 |
| Columna `incidents.evidence_path` | Fase 1 |
| `registrar_novedad()` aceptando una ruta de documento | Fase 4 |
| Aprobar una novedad deja el día **justificado** | Fase 1 |
| El cierre semanal cuenta un día justificado como día cumplido | Fase 5 |

Es decir: el camino para *cancelarle completo al personal* ya existía entero.
Lo que no existía era la puerta. Había cerradura y había llave; faltaba el
hueco en la pared.

## Las tres decisiones

### 1. Un reposo o un permiso no se aprueban de palabra

No se bloquea **crearlos** —el jefe reporta hoy lo que pasó hoy, aunque el papel
llegue mañana— pero sí se bloquea **aprobarlos**.

Y ahí está la fuerza de la regla: sólo una novedad aprobada justifica el día, y
sólo un día justificado cuenta como cumplido en el cierre de la semana. Así que
sin el papel, nadie cobra una semana completa por una ausencia.

Cuál tipo lo exige es **configuración, no código**: la columna
`incident_kinds.requiere_documento`. Hoy son permiso y reposo. Mañana se añade
vacaciones sin tocar una línea.

### 2. El socio carga, no cuenta

La fase 11 dejó a los socios fuera de las novedades por completo. Esta fase
entreabre esa puerta lo justo:

- Sólo los tipos que son una **autorización**: permiso, reposo, comisión,
  salida autorizada. (Columna `socio_puede`.)
- Y **siempre con el documento adjunto**.

Sin documento sería un reporte de oídas de alguien que no está en la operación.
El jefe operativo no perdió nada: sigue reportando todos los tipos, con papel o
sin él, y hay una prueba que lo exige.

### 3. La ruta tiene que existir de verdad

Guardar un texto en `evidence_path` no es tener un documento. La base comprueba,
antes de aceptar la novedad, que el archivo **esté subido**, en el depósito
correcto y en la carpeta de la sede de esa novedad.

La comprobación vive en un **disparador**, no en la función que llama el panel:
así vale también para un `UPDATE` hecho a mano, y no hay forma de rodearla.

## Quién abre el papel

Un reposo médico es un dato de salud. Se trata como la fotografía de una
marcación (fase 9):

| | Puede abrirlo |
|---|---|
| Dirección | Sí |
| Administración de esa sede | Sí |
| Quien lo subió | Sí |
| Jefe operativo | **No**, aunque él reportara la novedad |
| Otro socio de la misma sede | **No** |

Y **abrir deja rastro**: queda anotado en la auditoría quién abrió el papel de
un médico, cuándo y de quién.

Para que ese rastro no sea opcional, el listado de novedades **no entrega la
ruta**: sólo dice si hay documento o no. La única forma de obtener la ruta es
`documento_de_novedad()`, que anota antes de entregarla. Hay una prueba que
falla si la ruta se escapa por el listado.

## El aviso a administración

«Que administración sea notificada» es, dentro del sistema, que no tenga que ir
a mirar: el número de pendientes va en el propio botón de **Novedades**, y
distingue las que ya se pueden resolver de las que esperan el papel.

Es un aviso **dentro del panel**. No hay correo ni WhatsApp: eso es otra
infraestructura y no se prometió aquí.

## Lo que se comprobó

**Ocho secciones SQL**, con el caso real entero: Ana y Beto faltan el mismo día;
sólo Ana trae reposo; administración no puede aprobarlo hasta que el socio sube
el papel; una vez aprobado, el día de Ana queda justificado y en el cierre de la
semana tiene **una ausencia menos que Beto** y mejor asistencia. Eso es, medido,
poder cancharle completo.

Las pruebas se comprobaron al revés: se quitó la exigencia del documento, se
dejó al socio crear sin papel, y se hizo que el listado entregara la ruta. Las
tres roturas salieron en rojo, cada una en la comprobación que le tocaba.

**Treinta y ocho comprobaciones de navegador**: que el archivo se suba **antes**
de registrar la novedad (al revés, la base la rechazaría), que vaya a la carpeta
de la sede del trabajador, que un PDF no se convierta en foto, que el panel no
ofrezca **Aprobar** en lo que la base va a rechazar —y que sí lo ofrezca en la
que ya tiene su papel—, y que al jefe operativo se le niegue abrir un reposo.

### Dos cosas que encontraron las pruebas viejas

- **La batería de la fase 4 dejó de pasar**, porque aprobaba un permiso sin
  documento. Tenía razón en fallar: la regla cambió. Se cambió esa prueba por un
  tipo que no exige papel —no se debilitó la regla nueva para que la vieja
  siguiera pasando.
- **El botón Aprobar se escondía de más.** La prueba de la fase 4 corre contra
  un servidor simulado antiguo, que no manda el dato nuevo. Con él, el panel
  escondía *Aprobar* en todo. Ahora se esconde sólo cuando el servidor dice
  explícitamente que no se puede: un navegador con el programa viejo sigue
  funcionando, y quien rechaza, como siempre, es la base.

Esconder un botón es cortesía. Quien decide es la base de datos.
