# Guía de video

El video es parte estructural de este medio, no decoración.

---

## 1. Los dos tipos de video, y por qué no se mezclan

| | Hero cinematográfico | Video editorial |
|---|---|---|
| Dónde vive | **Solo la portada** | Dentro de una noticia |
| Quién lo controla | El desplazamiento del lector | El lector, con el botón de play |
| Audio | Ninguno, nunca | Sí, cuando corresponde |
| Fotogramas clave | Cada 8 cuadros | Los que ponga el codificador |
| Peso objetivo | Menos de 8 MB | Lo que haga falta |
| Subtítulos | No aplica | Obligatorios |

**El hero pertenece únicamente a la portada.** Repetirlo en cada noticia
perjudicaría la lectura, la velocidad y la claridad editorial. Esa decisión
ya está tomada y el código la respeta.

---

## 2. Hero cinematográfico

### Qué debe ser el video

- **Una sola trayectoria visual.** Un recorrido, no un montaje de cortes.
- **El último fotograma es una llegada.** Estable, compuesta, que aguante
  quedarse quieta en pantalla.
- **Espacio negativo reservado para el texto**, abajo a la izquierda: ahí
  van la pregunta y la bajada.
- **Nunca texto quemado dentro del video.** Los textos son HTML: se pueden
  corregir, se pueden traducir, y un lector de pantalla los lee.
- Movimiento lento y continuo. Un barrido rápido marea.

### Procesarlo

```bash
./scripts/video/procesar-hero.sh storage/originals/hero/aprobado.mp4
```

Genera tres cosas:

```
public/assets/video/hero-scrub.mp4      el video del barrido
public/assets/images/hero-poster.jpg    el primer fotograma
public/assets/images/hero-final.jpg     la llegada
```

### Por qué el encode es así

```bash
-g 8 -keyint_min 8 -sc_threshold 0
```

Este es **el parámetro que decide si el hero funciona o no**. El navegador
solo puede saltar a un fotograma clave. Con el intervalo por defecto (250
cuadros), el video avanzaría a tirones grandes al desplazarte. Con uno cada
8 cuadros, el movimiento sigue al ratón. Cuesta tamaño de archivo, y vale
cada byte.

```bash
-pix_fmt yuv420p     # sin esto, Safari no reproduce nada
-movflags +faststart # el índice al principio: se puede usar antes de terminar de bajar
-an                  # sin audio: el hero nunca suena
```

### Si pesa más de 8 MB

El script te avisa. `hero.js` descarga el video mostrando el progreso real,
así que la primera impresión no se congela mientras baja. Aun así, revisa en
este orden:

1. **Duración.** 5 o 6 segundos bastan. Es el recorrido, no una película.
2. **Resolución.** 1280 o 1600 de ancho es suficiente: la portada lo recorta.
3. **CRF.** `CRF=23 ./scripts/video/procesar-hero.sh ...`
4. **Complejidad.** Grano, humo y partículas encarecen mucho el archivo.

### Quién NO ve el hero

Cinco condiciones. Cualquiera de ellas y el visitante recibe la portada
estática, que es un diseño terminado, no una disculpa:

1. Pantallas de hasta 720 px.
2. Tabletas en vertical hasta 1024 px.
3. Puntero grueso en vertical.
4. Teléfono acostado con menos de 560 px de alto.
5. `prefers-reduced-motion: reduce`.

Estas cinco condiciones están escritas **carácter por carácter iguales** en
`public/assets/css/componentes.css` y en `public/assets/js/hero.js`. Si
alguien cambia una y no la otra, un lado cargaría lo que el otro esconde.
Si tocas una, toca las dos.

La decisión se toma **en vivo**: si el visitante gira la tableta o cambia su
preferencia de movimiento a mitad de sesión, el barrido se arma o se
desarma solo.

### Si el video no carga

La portada queda completa igual. El póster se queda de fondo, los textos se
leen, los botones funcionan. Para comprobarlo, renombra el archivo:

```bash
mv public/assets/video/hero-scrub.mp4 public/assets/video/hero-scrub.mp4.apagado
# abre la portada: debe verse entera y bien
mv public/assets/video/hero-scrub.mp4.apagado public/assets/video/hero-scrub.mp4
```

---

## 3. Video editorial

### Procesarlo

```bash
./scripts/video/procesar-editorial.sh storage/originals/entrevista.mov resumen-tarifa
```

Genera el MP4, el póster y la miniatura. El póster se toma al 10% de la
duración, porque el primer fotograma casi siempre está en negro.

### Registrarlo en el panel

`/panel/videos/nuevo`. Los campos que **no** son opcionales en la práctica:

| Campo | Por qué importa |
|---|---|
| Duración en segundos | Se muestra antes de que el lector decida verlo |
| Póster | Sin él, el reproductor carga un rectángulo negro |
| Subtítulos `.vtt` | Accesibilidad, y mucha gente ve sin sonido |
| Transcripción | **Sin ella el buscador no encuentra este video** |
| Alternativa textual | Qué se cuenta, para quien no puede verlo |

### Las reglas del reproductor

Están construidas en el código, no dependen de que alguien se acuerde:

- **Nunca reproduce audio solo.** El lector pulsa play.
- Controles accesibles con teclado.
- Relación de aspecto fija: la maqueta no salta al cargar.
- Carga diferida fuera de pantalla.
- El póster se ve antes de que el reproductor exista.
- **Un video externo no carga su iframe hasta que el lector lo pide.**
  YouTube no pone una cookie en el navegador de tu lector solo por abrir la
  página.
- Siempre hay alternativa textual.

---

## 4. Vertical para redes

```bash
./scripts/video/vertical.sh original.mp4 nombre-salida
```

Por defecto usa **fondo desenfocado**: el cuadro completo al centro, sobre
una versión difuminada de sí mismo. No se pierde nada.

El recorte centrado existe (`... nombre-salida recorte`) pero **solo úsalo
si revisaste que la acción está en el centro**. Una cara cortada o un dato
fuera de cuadro es un error editorial, no un detalle técnico.

Cada versión se registra como un **video aparte** en el panel, relacionado
con la misma noticia. Horizontal, vertical y cuadrada conviven sin pisarse.

---

## 5. Subtítulos y transcripción

```bash
# 1. Transcribir (fuera de este proyecto)
whisper video.mp4 --language Spanish --output_format srt

# 2. Convertir y validar
./scripts/video/subtitulos.sh video.srt video.vtt
```

**La transcripción automática siempre la revisa una persona** antes de
marcarla como «revisada». Es la política de uso de IA del medio, y está
publicada en `/transparencia/uso-de-ia`.

El estado se muestra en la ficha del video:

| Estado | Significa |
|---|---|
| Ninguna | No hay transcripción |
| Pendiente | Falta hacerla |
| Borrador | Generada por máquina, sin revisar |
| Revisada | Una persona la leyó y la corrigió |

### Por qué la transcripción importa tanto

Va al índice del buscador. Una noticia se puede encontrar **por lo que se
dijo en cámara**, aunque esa frase no esté escrita en ninguna parte del
texto. Es de las cosas que más diferencian este archivo del de un portal
normal.

Compruébalo: busca una palabra que solo esté en una transcripción. El
resultado te dirá «La coincidencia está en la transcripción del video».

---

## 6. Capítulos

En la ficha del video, un capítulo por línea:

```
00:00 De qué va esto
02:15 El dato que cambia todo
08:40 Lo que queda abierto
1:04:20 Cierre
```

Se admite `mm:ss` y `h:mm:ss`. En un video propio, pulsar un capítulo lleva
el reproductor a ese segundo.

---

## 7. Lives

Un Live pasa por cinco estados: anunciado, programado, en vivo, finalizado
y cancelado. **La fecha y el estado viven en un solo lugar**: la ficha del
Live. Ninguna vista escribe una fecha a mano, así que nunca puede haber dos
fechas que se contradigan.

El cron mueve solo el estado cuando llega la hora de empezar y de terminar.

**Cuando un Live termina, su página no muere.** Se convierte en expediente:

- La grabación completa, con capítulos y transcripción.
- El resumen de qué se respondió.
- Lo que quedó pendiente.
- Las noticias de antes y las de después.

Eso es lo que cierra el círculo del que habla el documento maestro: la
conversación vuelve a la audiencia en lugar de terminar cuando se apaga la
transmisión.

---

## 8. Antes de publicar cualquier video

```
[ ] Tiene póster
[ ] Tiene duración registrada
[ ] Tiene subtítulos .vtt
[ ] Tiene transcripción, y está revisada por una persona
[ ] Tiene alternativa textual
[ ] Está relacionado con su noticia, con su función editorial
[ ] Si es vertical, se registró como recurso aparte
[ ] Se probó en un teléfono real
[ ] No se reproduce solo
[ ] Si es material generado con IA, está rotulado
```
