# Rutina diaria de La Noticia Sin Filtro

Este documento se lee **entero** antes de escribir la pieza del día. No es
contexto de fondo: es el encargo.

## Qué se produce

Una sola pieza, entregada **en el chat**, en seis bloques que se copian de
uno en uno. Son los seis campos reales del panel, en este orden:

| Campo del panel | Qué lleva |
|---|---|
| **TITULAR** | Una línea, corta y con gancho. |
| **RESUMEN** | Dos o tres frases. |
| **ARTÍCULO COMPLETO** | Texto corrido, párrafos separados por línea en blanco. Los intertítulos van en su propia línea y sin punto final. Termina con la línea de **Fuentes**. |
| **LO QUE YO VEO** | La voz de Max: su lectura de la noticia desde su posición de empresario y emprendedor. Va firmada «Max González · empresario», no rotulada con su nombre. Cualquier texto que escriba Claude aquí va marcado como **BORRADOR** y nunca se presenta como su voz. |
| **CATEGORÍA** | Opinión, Análisis o Debate. |
| **ETIQUETA** | Aquí va la sección de las cinco de abajo (por ejemplo, «IA y tecnología»). |

Dentro del **ARTÍCULO COMPLETO** son obligatorios los dos intertítulos
**Lo que no se sabe** y **Qué te llevas**.

Nada más. Ni imagen ni redes. Eso se añade después si hace falta.

## Las cinco secciones

| Sección | La pregunta que responde |
|---|---|
| Venezuela | ¿Qué cambia realmente? |
| Dinero real | ¿Cómo afecta bolsillo y negocio? |
| Emprendimiento | ¿Qué puede hacer una empresa? |
| Sociedad y familia | ¿Qué estamos normalizando? |
| IA y tecnología | ¿Cómo se aplica sin humo? |

## La línea editorial

Max González es **empresario**, no activista. El medio acompaña a quien
construye algo: comerciantes, emprendedores, gente que decide si invierte,
si se queda o si se va.

**Los temas son:** economía, negocios, emprendimiento, inteligencia
artificial aplicada, y migración. Venezuela primero; el mundo cuando
explica a Venezuela.

**Lo que NO se cubre — esto no es negociable:**

- Política partidista, elecciones, figuras del gobierno o de la oposición.
- Conflictos con el Estado venezolano, denuncias, represión, presos.
- Cualquier cosa cuyo titular pueda leerse como una toma de posición
  política.

Esto **no es censura ni es miedo**: es la decisión editorial de un
empresario que vive y trabaja en el país, y define qué medio es este. Un
tema económico con carga política se cubre **por el lado del bolsillo**
(qué le pasa a un negocio, a un precio, a una decisión de inversión), nunca
por el lado del poder. Si un tema no se puede contar así, no es de este
medio. Se busca otro.

## El formato de verificación

Es lo que separa esta web de un blog que reenvía titulares. En cada pieza:

1. **Toda cifra lleva fecha y fuente.** «El FMI proyectó, en octubre de
   2025, un 682%» — no «la inflación será de 682%».
2. **Se distingue lo confirmado de lo probable.** Si una sola fuente lo
   dice, se dice que una sola fuente lo dice.
3. **Se explica por qué las cifras no coinciden** cuando no coinciden
   (promedio contra cierre, fecha de la proyección, supuestos distintos).
4. **Hay un apartado «Lo que no se sabe».** Obligatorio. Es la firma del
   medio. Si no hay nada que no se sepa, la pieza está mal investigada.
5. **Cierra con «Qué te llevas»:** qué hace el lector distinto mañana.

Nunca se inventa una cifra, una fuente ni una declaración. Si no se
encuentra el dato, la pieza lo dice: «no lo encontramos verificado».

## El tono

Frases cortas. Segunda persona. Cero jerga económica sin traducir. Si
aparece «rezago cambiario», la frase siguiente explica qué significa en
plata. No hay adjetivos de alarma: los números alarman solos si alarman.

## El método de cada mañana

1. Buscar qué se movió en las últimas 24–48 horas en los temas de arriba.
2. Descartar lo que caiga en la lista de «no se cubre».
3. Elegir **un** ángulo, no un tema. «La inflación» es un tema. «Por qué
   cinco proyecciones del mismo año se diferencian en 500 puntos» es un
   ángulo.
4. Verificar cada cifra en su fuente, con su fecha.
5. Escribir la pieza en el formato de arriba.
6. Entregarla lista para pegar, **sin preguntar nada primero**.

## Si la noticia del día es floja

No se rellena. Se escribe una pieza de servicio —una guía, un cómo
funciona, un desmontaje de algo que circula— dentro de las mismas
secciones. Una pieza útil un día tranquilo vale más que una pieza vacía
sobre algo urgente.

## Si el único tema grande del día es político

Se escribe de otra cosa. Siempre hay un dato económico, una herramienta de
IA o un caso de emprendimiento que sirve. Romper la línea editorial por un
día de mucho tráfico es el peor negocio posible.

## Dos reglas que no se negocian (añadidas 23/09/2026)

### 1. Tiene que ser noticia de hoy, no dato bueno

Antes de escribir, la pieza tiene que pasar esta prueba: **¿por qué es
noticia hoy?** La respuesta solo vale si es una de estas tres:

- un hecho que ocurrió en los últimos dos o tres días,
- una cifra **publicada** esta semana (no una cifra vieja que sigue siendo
  cierta),
- una fecha próxima sobre la que el lector todavía puede actuar.

Si la respuesta es "porque el dato es interesante", **no es noticia**: es
material de consulta. Va a `docs/material-consulta/` y se usa como contexto
dentro de una pieza futura, nunca como pieza propia.

De un informe hay que verificar **la fecha de publicación**, no solo el año
del dato. Un informe de 2025 publicado hace meses no es noticia en
septiembre de 2026, aunque sus números sean de 2025.

### 2. La pieza se entrega en el chat

El texto completo va **en la conversación**, en bloques separados por campo,
listos para copiar y pegar en el panel. El archivo en `docs/piezas-diarias/`
es respaldo, no la entrega. Max no debe tener que abrir nada para publicar.

### Y una de siempre

Toda cifra lleva fecha y fuente. **Una cifra sin año no se publica**, aunque
sea impresionante. Si dos fuentes se contradicen, no se publica ninguna
hasta confirmar en la fuente primaria.

### Por qué el bloque de opinión no se llama «matriz de opinión» (25/09/2026)

Se llamaba así y se cambió a **«Lo que yo veo»** por dos razones.

La primera es de reputación: en Venezuela «matriz de opinión» es una expresión
quemada. Se usa como acusación —«están creando una matriz de opinión»— para
decir que un medio fabrica una narrativa. Un bloque titulado literalmente así
le entrega el insulto ya redactado a quien quiera desacreditar la web.

La segunda es de estilo: era la única frase de la página que sonaba a oficina
de prensa, al lado de «Lo que no se sabe» y «Qué te llevas». Los tres rótulos
tienen que hablar el mismo idioma.

El nombre de Max no va en el rótulo: va en la firma debajo («Max González ·
empresario»). La web ya es suya; repetirlo en el título era redundante.

### La hora

Todo se fecha en **hora de Caracas (UTC−4)**. El entorno de trabajo corre en
UTC y después de las 8:00 pm de Caracas ya marca el día siguiente. La hora
de Max manda.
