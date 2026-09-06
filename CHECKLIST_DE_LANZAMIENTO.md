# Checklist de lanzamiento

Lo que hay que comprobar antes de decir que está terminado. Lo automatizable
lo cubre `php tests/ejecutar.php`. Lo demás pide ojos y manos.

Marca cada casilla solo cuando lo hayas comprobado **tú**, no cuando
supongas que funciona.

---

## 0. Automático

```bash
php tests/ejecutar.php
```

```
[ ] Termina en verde, sin ningún fallo
```

Cubre: permanencia, slugs, redirecciones, correcciones, sanitización,
permisos, buscador, fechas, SEO, programación y consultas preparadas.

---

## 1. Ciclo editorial completo

```
[ ] Creé una noticia desde el panel
[ ] La publiqué y comprobé su URL en el navegador
[ ] La actualicé y la URL NO cambió
[ ] Le cambié el título y la URL NO cambió
[ ] Registré una corrección y aparece con fecha y motivo al pie de la pieza
[ ] La archivé
[ ] Desapareció de la portada
[ ] Sigue abriéndose en su URL original
[ ] Sigue apareciendo en /archivo
[ ] Sigue apareciendo en su categoría
[ ] La encontré en el buscador por su título
[ ] La encontré por una palabra de su cuerpo
[ ] La encontré filtrando por su fecha
[ ] La encontré filtrando por su autor
[ ] Programé una pieza y se publicó sola a su hora
```

## 2. Video y Live

```
[ ] Relacioné un video con una noticia
[ ] El reproductor no arranca solo
[ ] El botón de play funciona con teclado (Tab y Enter)
[ ] Los subtítulos se activan y se leen
[ ] La transcripción se despliega
[ ] Busqué una palabra que SOLO está en la transcripción y la pieza apareció
[ ] El resultado indica que la coincidencia está en la transcripción
[ ] La alternativa textual se ve
[ ] Los capítulos llevan al segundo correcto
[ ] Relacioné un Live y aparece en la pieza
[ ] La página del Live finalizado muestra grabación, resumen y pendientes
[ ] Un video de YouTube NO carga su iframe hasta que pulso play
```

## 3. Pantallas

Con las herramientas de desarrollo del navegador, o en dispositivos reales.

```
[ ] Escritorio 1280 × 800
[ ] Escritorio 1440 × 900
[ ] Escritorio 1920 × 1080
[ ] Teléfono 375 × 812
[ ] Teléfono 375 × 667
[ ] Tableta 768 × 1024, en vertical y en horizontal
[ ] Teléfono acostado, 812 × 375
[ ] En NINGUNA hay desplazamiento horizontal accidental
[ ] Las tablas anchas se desplazan dentro de sí mismas, no arrastran la página
[ ] Ninguna imagen provoca un salto de maqueta al cargar
```

## 4. Hero cinematográfico

```
[ ] En escritorio, el desplazamiento mueve el video hacia adelante
[ ] Y también hacia atrás al subir
[ ] El movimiento va con el ratón, sin tirones
[ ] El anillo de carga muestra progreso real
[ ] El último fotograma es una llegada estable
[ ] Los textos se leen sobre CUALQUIER fotograma del video
[ ] En un teléfono aparece la portada estática, no el video
[ ] Con reducción de movimiento activada, aparece la estática
[ ] Activé la reducción de movimiento SIN recargar y el barrido se desarmó
[ ] La desactivé SIN recargar y el barrido volvió
[ ] Roté una tableta de vertical a horizontal y el hero se armó solo
[ ] Renombré el archivo del video y la portada sigue completa y bonita
```

## 5. Teclado y accesibilidad

Guarda el ratón. En serio.

```
[ ] Tab desde el principio muestra "Saltar al contenido"
[ ] Ese enlace lleva al contenido principal
[ ] Recorrí toda la portada solo con Tab
[ ] El foco SIEMPRE se ve, en todos los elementos
[ ] El orden del foco sigue el orden visual
[ ] Abrí el menú del teléfono con teclado
[ ] Voté en el Pulso solo con teclado
[ ] Envié una pregunta solo con teclado
[ ] Ningún control autónomo mide menos de 44 px
[ ] Los enlaces de metadatos en línea (categoría dentro de una fila de
    datos) miden al menos 24 px, el mínimo AA de WCAG 2.2, con espacio
    alrededor. Engordarlos a 44 px rompería la fila, y esa es la razón
    por la que se dejan así: es una decisión, no un descuido
[ ] Cada página tiene un solo h1
[ ] Los encabezados no saltan niveles (no hay h2 seguido de h4)
[ ] Toda imagen con contenido tiene texto alternativo
[ ] Las imágenes decorativas tienen alt vacío
[ ] El video del hero está fuera del orden de tabulación
[ ] Probé una página con un lector de pantalla
```

## 6. Contraste

Con un medidor de contraste (el de las herramientas del navegador sirve).

```
[ ] Texto normal sobre fondo: al menos 4.5:1
[ ] Texto grande: al menos 3:1
[ ] Botones y bordes de campos: al menos 3:1
[ ] Los textos del hero sobre el fotograma más claro del video
[ ] Las etiquetas de tipo editorial sobre su fondo
```

## 7. Seguridad

```
[ ] /panel redirige a /panel/entrar sin sesión
[ ] Cinco intentos fallidos bloquean el acceso temporalmente
[ ] Un formulario sin token CSRF es rechazado
[ ] Entré como AUTOR: no veo el botón de publicar
[ ] Entré como EDITOR: publico, pero no gestiono usuarios
[ ] Entré como EDITOR: no puedo eliminar definitivamente
[ ] Intenté guardar <script>alert(1)</script> en el cuerpo: se eliminó
[ ] Intenté un enlace javascript: en un bloque de fuente: perdió el destino
[ ] Intenté subir un .php como imagen: fue rechazado
[ ] Cambié la extensión de un .php a .jpg y lo subí: fue rechazado igual
[ ] https://midominio.com/.env devuelve 403 o 404
[ ] https://midominio.com/app/bootstrap.php devuelve 403 o 404
[ ] https://midominio.com/storage/ devuelve 403 o 404
[ ] APP_DEBUG=false en producción
[ ] El sitio corre bajo HTTPS y SESSION_SECURE_COOKIES=true
```

## 8. SEO y distribución

```
[ ] Cada página tiene su etiqueta canónica correcta
[ ] Los metadatos Open Graph aparecen al compartir en WhatsApp
[ ] Y en Instagram
[ ] /sitemap.xml es XML válido
[ ] /sitemap-noticias.xml es XML válido
[ ] /sitemap-videos.xml es XML válido
[ ] /rss.xml es XML válido
[ ] /robots.txt apunta a los sitemaps con el dominio REAL
[ ] Ningún borrador aparece en ningún sitemap ni en el RSS
[ ] Los datos estructurados pasan el validador de Google
[ ] Las URL de imagen y video son absolutas
[ ] Cada autor tiene su página
[ ] Las ocho páginas de transparencia cargan
```

## 9. Rendimiento

Con Lighthouse, en modo móvil y con red 4G simulada.

```
[ ] La portada carga en menos de 3 segundos en 4G
[ ] Una noticia carga en menos de 2 segundos
[ ] LCP por debajo de 2.5 s
[ ] CLS por debajo de 0.1
[ ] INP por debajo de 200 ms
[ ] La consola no muestra NINGÚN error
[ ] storage/logs/ no tiene errores del día
[ ] El hero pesa menos de 8 MB, o muestra progreso honesto
```

## 10. Respaldos

```
[ ] bash scripts/respaldo.sh genera los dos archivos
[ ] El volcado contiene al menos 40 tablas
[ ] Restauré ese volcado en una base de PRUEBAS
[ ] Los acentos sobrevivieron a la restauración
[ ] El cron de respaldo está configurado
[ ] Existe una copia de los respaldos FUERA del servidor
[ ] .env está guardado aparte, en un gestor de contraseñas
```

## 11. Contenido y confianza

```
[ ] Todo el contenido de demostración está rotulado como demostración
[ ] O se eliminó por completo antes de lanzar
[ ] No hay ninguna cifra, fuente ni testimonio inventado presentado como real
[ ] Las páginas de transparencia tienen el correo editorial real
[ ] Los perfiles de autor tienen su biografía real
[ ] Las cuentas de Instagram y TikTok son las correctas
[ ] El logo y las fotos oficiales sustituyeron a los marcadores
```

## 12. La última mirada

Cierra todo. Abre el sitio como si fuera la primera vez.

```
[ ] ¿Entiendo en 5 segundos de qué va este medio?
[ ] ¿La pregunta principal me da ganas de entrar?
[ ] ¿Distingo sin esfuerzo un hecho de una opinión?
[ ] ¿Se siente venezolano, moderno y confiable?
[ ] ¿O se siente como una plantilla?
[ ] ¿Hay algo que sobre?
[ ] ¿Volvería mañana?
```

Si la respuesta a la última es «no», el trabajo no ha terminado, por muy
verdes que estén todas las demás casillas.

---

## Criterio de finalización

El proyecto está terminado cuando:

```
[ ] Max puede publicar sin tocar una línea de código
[ ] Cada noticia tiene una URL permanente
[ ] Una noticia archivada se encuentra años después
[ ] Todos los cambios quedan registrados y visibles
[ ] El buscador consulta noticias Y transcripciones
[ ] Los videos tienen póster, subtítulos y alternativa textual
[ ] El hero no perjudica al móvil, ni a la accesibilidad, ni a la velocidad
[ ] El panel tiene permisos y seguridad reales
[ ] Existen respaldos y una guía de restauración probada
[ ] La plataforma está probada en el alojamiento real
[ ] El resultado conserva la identidad de SIN FILTRO
```
