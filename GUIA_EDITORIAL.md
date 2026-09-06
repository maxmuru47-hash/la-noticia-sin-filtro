# Guía editorial

Cómo se publica en La Noticia SIN FILTRO, y por qué el sistema está hecho así.

> HECHO no es ANÁLISIS. ANÁLISIS no es OPINIÓN. OPINIÓN no es PUBLICIDAD.

---

## 1. La regla que nunca se negocia

**Ninguna pieza publicada se elimina.**

No es una preferencia: está construido dentro del sistema. El panel no tiene
un botón de borrar para uso normal. Lo que existe es esto:

| Situación | Qué haces | Qué pasa |
|---|---|---|
| Ya no es actual | **Archivar** | Sale de portada. Conserva su URL, sigue en el buscador y en el archivo |
| Tenía un error | **Corregir** | La pieza se actualiza y el error queda registrado con fecha y motivo, visible |
| No debió publicarse | **Retirar** | La página permanece y explica por qué se retiró |
| Orden judicial | **Retirar** + noindex | Igual que arriba, y además se pide a los buscadores que no la indexen |
| Nunca, salvo obligación legal | **Eliminar** | Solo el rol superior, con motivo, escribiendo la URL exacta, y con copia completa en auditoría |

Si dudas entre archivar y eliminar, **archiva**. Siempre.

---

## 2. Los cinco tipos de pieza

La clasificación aparece visible arriba de cada pieza, en las tarjetas de la
portada y en los resultados del buscador. No es decorativa: es la promesa
que le haces al lector sobre lo que va a leer.

| Tipo | Responde | Regla |
|---|---|---|
| **Noticia** | ¿Qué ocurrió? | Separa confirmado, probable y desconocido. Sin adjetivos |
| **Análisis** | ¿Qué significa? | Es interpretación, y se dice. Nunca se mezcla con el hecho |
| **Opinión** | ¿Qué pienso? | Va firmada. Sale en franja roja rotulada |
| **Explicador** | ¿Cómo funciona esto? | Contenido duradero. Se actualiza, no se sustituye |
| **Verificación** | ¿Es cierto esto? | Con las fuentes abiertas. Si no se pudo verificar, se dice |

**Si una pieza necesita las dos cosas**, hecho y opinión, publica el hecho
como noticia y pon tu postura en el bloque «Opinión de Max». El sistema la
separa visualmente por ti.

---

## 3. Escribir una Noticia Viva

Entra en `/panel/noticias/nueva`. El orden del formulario es el orden en el
que conviene pensar.

### Paso 1 · La pregunta, antes que el titular

El campo **Pregunta principal** es lo primero que verá el lector. No es el
titular: es la duda que la pieza responde.

- Mal: «Nueva resolución sobre tarifas»
- Bien: «¿Quién termina pagando cuando cambia la tarifa?»

### Paso 2 · La dirección permanente

Al crear la pieza, el sistema genera su dirección desde el título. **Puedes
elegirla tú en ese momento, y solo en ese momento.** Después de publicar,
cambiarla exige un motivo registrado y deja una redirección para siempre.

Piénsala una vez, bien:

- Corta, en minúsculas, con guiones.
- Sin la fecha: si mañana corriges la fecha, la URL no debería mentir.
- Sin palabras que envejezcan («nuevo», «hoy», «último»).

### Paso 3 · Qué sabemos y qué no

Los tres campos —**Confirmado**, **Probable**, **Todavía desconocido**— son
lo que protege la confianza del medio. Rellénalos siempre.

- **Confirmado**: verificado con documento, registro o dos fuentes independientes.
- **Probable**: hay indicios, o solo una fuente. Se dice que es probable.
- **Todavía desconocido**: lo que no sabes. Decirlo en voz alta no debilita
  la pieza: la hace creíble.

### Paso 4 · El cuerpo por bloques

El editor guarda bloques, no un HTML suelto. Eso permite reordenar y volver
a maquetar sin reescribir nada.

| Bloque | Cuándo usarlo |
|---|---|
| Párrafo | El texto normal. Admite negrita, cursiva y enlaces |
| Subtítulo | Cada 3 o 4 párrafos. Ayuda a leer y a saltar |
| Cita | Una declaración textual, con quién la dijo |
| Lista | Enumeraciones. Mejor que un párrafo con comas |
| Imagen | Con texto alternativo obligatorio y pie |
| Dato destacado | Una cifra que importa, con su fuente |
| Fuente citada | Un enlace al documento original |
| Aviso editorial | Contexto que el lector necesita antes de seguir |
| Video | Un video ya registrado y relacionado con esta pieza |

**Todo lo que escribas se sanea al guardar.** Negrita, cursiva, enlaces y
listas se conservan. Cualquier script, estilo, iframe o atributo de evento
se elimina. Es a propósito, y no se puede desactivar.

### Paso 5 · Las dos miradas fuertes

Escribe el **mejor** argumento de cada lado, no una caricatura del que no te
gusta. Si no puedes escribir el argumento contrario de forma que su defensor
lo firmaría, todavía no lo entiendes lo suficiente para publicar.

### Paso 6 · Qué significa para ti

Las consecuencias concretas, por perfil: familia, negocio, trabajador,
diáspora. Cada una con su nivel de certeza. Aquí es donde una noticia deja
de ser información y empieza a ser útil.

### Paso 7 · La opinión, si la hay

Va en su propio campo. El sistema la publica dentro de una franja roja que
dice «Opinión firmada · esto no es un hecho reportado». No hay forma de
publicar opinión disfrazada de reporte, y así debe ser.

### Paso 8 · La pregunta que queda abierta

Toda pieza termina con una duda sin resolver. La siguiente pieza sobre el
tema partirá de ahí, y el sistema mostrará esa herencia. Así se construye
memoria en lugar de acumular artículos sueltos.

### Paso 9 · Los tres modos de profundidad

**60 segundos**, **5 minutos** y **Sin Filtro** viven en la **misma
dirección**. Nunca se duplica la URL, nunca se parte la audiencia entre
versiones.

- 60 segundos: lo esencial y por qué importa.
- 5 minutos: contexto, datos y argumentos principales.
- Sin Filtro: todo, con fuentes y contradicciones.

Los dos primeros son opcionales. Si no los escribes, el selector no aparece.

---

## 4. Publicar

**Guardar no publica.** Son dos acciones distintas, a propósito.

| Acción | Quién puede | Qué hace |
|---|---|---|
| Guardar | Autor y superiores | Guarda el borrador. Nada es visible todavía |
| Publicar ahora | Editor y superiores | La pieza queda en línea, y su URL es permanente |
| Programar | Editor y superiores | Se publicará sola a la hora indicada, en hora de Venezuela |

Al publicar, la fecha original queda fija. Volver a publicar más adelante
**no** la sobrescribe: el archivo cronológico tiene que seguir siendo cierto.

---

## 5. Corregir

Entra en la pieza, bloque **Correcciones**.

| Tipo | Cuándo |
|---|---|
| Corrección | Había un error de hecho |
| Actualización | Hay información nueva que cambia la pieza |
| Aclaración | El texto se prestaba a confusión sin ser falso |

El motivo es obligatorio y **es público**. Aparece al pie de la pieza con su
fecha, y también en `/transparencia/correcciones`.

> Corregir no resta autoridad. Ocultar una corrección sí.

---

## 6. La portada

`/panel/portada`. Cada zona corresponde a una sección de la página.

**Quitar algo de la portada no borra nada.** La pieza sigue publicada, en su
URL, en el buscador y en el archivo. La portada solo decide qué se destaca
hoy.

| Zona | Qué va | Cuántos |
|---|---|---|
| Hero | La historia principal del momento | 1 |
| Pulso del día | La votación abierta | 1 |
| Lo esencial ahora | Los asuntos con consecuencia práctica | máximo 3 |
| Max lo analiza | Video y piezas de opinión o análisis | 1 video + 2 piezas |
| La conversación se movió | Piezas cuyo pulso ya tiene respuestas | 2 |
| Próximo Live | El programa agendado | 1 |
| Últimos videos | Lo más reciente en video | 4 |
| Expedientes vivos | Los temas abiertos | 3 |

**Si dejas una zona vacía, la portada no se rompe**: toma automáticamente lo
más reciente que corresponda.

---

## 7. El Pulso

La misma pregunta antes y después de leer. Mide si la información cambió la
postura de alguien, que es lo contrario de medir clics.

**Reglas que el sistema aplica solo:**

- Voto anónimo. No se guarda la IP en claro, solo una huella irreversible.
- Una respuesta por persona y etapa.
- Los resultados **no se pueden editar**. No existe la función.
- Con menos de 10 respuestas, no se presenta como resultado válido.
- Siempre aparece la nota de metodología: no es una encuesta científica.

Escribe preguntas que se puedan responder honestamente desde varios lados.
Una pregunta con una sola respuesta razonable no mide nada.

---

## 8. Preguntas de la comunidad

Todo lo que envía la audiencia pasa por `/panel/moderacion`. Nada se publica
solo.

| Estado | Significa |
|---|---|
| Pendiente | Recién llegada |
| Aprobada | Se publica junto a la pieza |
| Seleccionada | Irá al próximo Live |
| Respondida | Ya tiene respuesta, o una pieza que la responde |
| Rechazada | No se publica |

Cuando una pregunta origina una pieza nueva, enlázalas: el lector verá de
qué duda nació lo que está leyendo.

---

## 9. Ritual semanal

Cadencia sostenible. Es mejor cumplirla que aparentar una redacción grande.

| Día | Qué |
|---|---|
| Lunes | Elegir la pregunta de la semana. Publicar el Pulso inicial |
| Martes | Publicar «Lo esencial»: los tres asuntos |
| Miércoles | Completar el expediente: fuentes, cronología, perspectivas |
| Jueves | Video «Max lo analiza». Recoger preguntas |
| Viernes | Seleccionar las preguntas útiles |
| Quincenal | Live alimentado por esas preguntas |
| Después del Live | Actualizar el expediente y mostrar qué cambió |

---

## 10. Lo que no se publica

- Testimonios, cifras, fuentes o resultados de encuesta inventados.
- Titulares que prometen más de lo que la pieza sostiene.
- Contenido patrocinado sin identificar.
- Una opinión presentada como un hecho.
- Una cifra sin fuente, salvo diciendo que no pudo verificarse.
- Imágenes generadas con IA sin rotular.

Cuando dudes si algo se sostiene, no lo publiques todavía. El archivo es
permanente: lo que publiques hoy seguirá ahí dentro de diez años.
