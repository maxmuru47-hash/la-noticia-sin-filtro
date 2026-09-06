# Prompt para la siguiente sesión

Copia **todo** lo que hay debajo de la línea y pégalo como primer mensaje en
una sesión nueva de Claude Code sobre este repositorio.

Antes de pegarlo, rellena los cinco datos marcados con `⟨…⟩`. Si no sabes
alguno, escribe «no lo sé» y Claude te dirá cómo averiguarlo.

---

# LA NOTICIA SIN FILTRO · Continuar y terminar la plataforma

Eres arquitecto de software senior, diseñador editorial y especialista en
accesibilidad, rendimiento, SEO y video. Vas a **continuar** un proyecto que
ya existe y está a medio camino. No empieces de cero y no rediseñes lo que
ya está probado.

## 1. Qué es esto

`lanoticia.sinfiltroconmax.com` es la plataforma editorial de **SIN FILTRO
con Max** (Max González, Venezuela). No es un portal de titulares: es un
medio de comprensión y conversación.

> La noticia no termina cuando la lees. Comienza cuando la entiendes.

**Trabaja en la rama `claude/new-session-sv6rbv`.** Ya tiene dos commits con
la plataforma completa. Léelos antes de tocar nada:

```bash
git log --oneline
git show --stat HEAD~1
```

Lee también, en este orden: `README.md`, `GUIA_EDITORIAL.md`,
`GUIA_DE_VIDEO.md`, `GUIA_DE_ALOJAMIENTO.md`, `GUIA_DE_RESPALDOS.md` y
`CHECKLIST_DE_LANZAMIENTO.md`.

## 2. La regla que está por encima de todo

**Ninguna pieza publicada se elimina jamás.** Está construida dentro del
sistema, no confiada a la disciplina de quien lo use, y hay pruebas
automáticas que lo verifican. No la debilites por conveniencia:

- Cada pieza tiene una dirección permanente. Cambiar el título **nunca** la cambia.
- Archivar saca de portada, pero la pieza conserva su URL y sigue en el
  buscador, en el archivo y en sus taxonomías.
- Cambiar una URL exige motivo, crea redirección 301 y quema la anterior
  para siempre en `reserved_slugs`.
- Toda corrección se publica con fecha y motivo.
- Un retiro conserva la página explicando por qué.
- Eliminar de verdad exige rol superior, motivo y escribir la URL exacta.
- La portada **selecciona** en `homepage_slots`: vaciar una zona no toca
  ninguna noticia.

Toda la lógica vive en `app/Services/ArticleService.php`. Ningún controlador
escribe directamente en la tabla `articles`, y debe seguir siendo así.

Regla editorial visible en pantalla, también innegociable:
**HECHO no es ANÁLISIS. ANÁLISIS no es OPINIÓN. OPINIÓN no es PUBLICIDAD.**

## 3. Qué ya está hecho y verificado

No lo rehagas. Está medido, no supuesto.

- PHP 8.2+ sin framework, sin compilación, MySQL/MariaDB, 41 tablas InnoDB utf8mb4.
- Buscador en servidor con FULLTEXT y respaldo LIKE ponderado, filtros,
  paginación y fragmento resaltado. **Indexa las transcripciones de video.**
- Hero cinematográfico controlado por desplazamiento: verificado que avanza
  al bajar (0 → 1,26s → 2,65s → 4,04s → 5s) y retrocede simétricamente.
  Doble fuente MP4 + WebM elegida con `canPlayType()`. Las cinco compuertas
  del hero estático están escritas **idénticas** en `componentes.css` y en
  `hero.js`: si tocas una, toca la otra.
- Reproductor accesible: nada suena solo, subtítulos, alternativa textual, y
  ningún iframe de terceros hasta que la persona pulsa play.
- Panel con roles por capacidad, editor por bloques con autoguardado,
  medios, videos, Lives, moderación, redirecciones, auditoría y métricas.
- Modos de profundidad 60s / 5min / Sin Filtro en la **misma** URL.
- SEO: canónica, Open Graph, JSON-LD, cuatro sitemaps, RSS, robots.
- Seguridad: Argon2id, CSRF, consultas preparadas en el 100% del código
  (hay una prueba que lo verifica), saneamiento por lista blanca,
  validación de cargas por MIME real, PHP apagado en `uploads`.
- Respaldo y restauración probados de punta a punta con datos reales.
- **102 pruebas automáticas en verde.**
- **0 desbordamiento horizontal** a 375 px en las siete rutas medidas.
- **1.082 textos medidos en 14 páginas, 0 por debajo del mínimo WCAG.**
- 0 errores de consola, foco visible siempre, un solo h1 sin saltos de nivel.

## 4. LO QUE FALTA · en este orden

### PRIORIDAD 1 · El panel no permite construir una Noticia Viva completa

Este es el hueco más grave y es el primero que debes cerrar. Hoy Max puede
escribir y publicar una pieza básica, pero **no puede crear desde el panel**
nada de esto, que existe en la base de datos y se ve en la web pública
porque lo sembró un script:

| Falta en el panel | Tabla | Por qué importa |
|---|---|---|
| **Crear un Pulso** con sus opciones | `polls`, `poll_options` | Es la función que define al medio, y hoy no se puede crear sin SQL |
| Adjuntar fuentes a una pieza | `article_sources` | Las fuentes existen como catálogo pero no se enlazan |
| Cronología de una pieza o expediente | `timeline_events` | El expediente vivo se queda vacío |
| Actores (quién es quién) | `actors`, `article_actors` | El mapa de actores no se puede llenar |
| Consecuencias por perfil | `impact_profiles` | «Qué significa para ti» no se puede escribir |
| Pregunta heredada | `article_lineage` | La memoria editorial se rompe |
| Relacionar piezas a mano | `article_relations` | Solo hay relación automática por tema |
| Restaurar una versión anterior | `article_revisions` | El historial se ve pero no se puede volver atrás |

Añade estas interfaces dentro de la ficha de la pieza
(`app/Views/admin/noticia-editor.php`) y del expediente. Sigue el patrón que
ya existe para relacionar videos y Lives: formulario POST, token CSRF,
comprobación de capacidad, registro en auditoría y reindexado del buscador.

**No inventes tablas nuevas: todas existen ya.** Mira `database/schema.sql`.

### PRIORIDAD 2 · Cosas que el sistema promete y todavía no cumple

1. **Envío real de correo.** Las tablas `subscribers` y `notifications`
   existen y se llenan, pero nada envía nada. Hace falta: confirmación de
   suscripción con doble opt-in, aviso del Live, resumen semanal y baja con
   un clic. Usa `mail()` o SMTP según lo que permita el alojamiento.
2. **Cambio de contraseña obligatorio.** `users.must_change_password` se
   marca al crear una cuenta, pero no hay pantalla que lo exija.
3. **Imágenes responsivas.** Hoy se sirve una sola versión. Genera variantes
   al subir y emite `srcset` y `sizes`. Afecta directamente al LCP en móvil.
4. **Tipografías autoalojadas.** El CSS pide League Spartan y Montserrat y
   cae en una sans del sistema. Aloja los `.woff2` en
   `public/assets/fonts/`, declara `@font-face` con `font-display: swap` y
   precarga solo el peso del titular. La política de seguridad ya permite
   `font-src 'self'`.
5. **Página de baja de suscripción**, exigida por la política de privacidad
   que ya está publicada.

### PRIORIDAD 3 · Funciones del documento maestro que quedaron para después

Constrúyelas solo cuando lo anterior esté cerrado, y **solo si Max las
aprueba**. El propio documento maestro dice que una función no se lanza si
no mejora comprensión, confianza, participación o retorno:

- **Mapa de consecuencias**: visualización causal «si ocurre A, qué cambia
  en B», con nivel de certeza y fuente en cada conexión.
- **Por qué estás viendo esto**: cada recomendación explica su lógica y se
  puede apagar.
- **La silla abierta**: postulación estructurada de la audiencia para
  participar en un Live.
- **Índice de comprensión**: prueba breve y transparente. Nunca presentarlo
  como medición científica.

## 5. Datos que necesito de Max

Rellena esto antes de pegar el prompt:

- **Alojamiento**: ⟨proveedor, plan, versión de PHP, versión de MySQL o
  MariaDB, si hay SSH, si hay cron⟩
- **Correo editorial** para las páginas de transparencia: ⟨correo⟩
- **Dominio definitivo**: ⟨https://lanoticia.sinfiltroconmax.com u otro⟩
- **Material de video del hero**: ⟨ruta de un archivo grabado, o «generar
  con IA», o «dejar el de demostración»⟩
- **Logo, fotos y tipografías oficiales**: ⟨cómo te los hago llegar⟩

Si Max no adjunta el HTML actual de `sinfiltroconmax.com` ni el Google Apps
Script, **pídeselos**: en la sesión anterior la red no alcanzaba ese dominio
y no se pudieron auditar. No los des por perdidos ni los reinventes.

## 6. Cómo levantar el entorno

El contenedor arranca limpio. Esto funciona, ya se probó:

```bash
# Base de datos y video
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server ffmpeg
(mariadbd-safe --user=mysql &) ; sleep 10

mariadb -e "CREATE DATABASE lanoticia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS 'lanoticia'@'localhost' IDENTIFIED BY 'desarrollo_local';
            GRANT ALL ON lanoticia.* TO 'lanoticia'@'localhost'; FLUSH PRIVILEGES;"
mariadb lanoticia < database/schema.sql
mariadb lanoticia < database/seed.sql

# .env local
cp .env.example .env
php -r 'echo bin2hex(random_bytes(32));'   # va en APP_KEY
# pon APP_ENV=local, APP_DEBUG=true, APP_URL=http://localhost:8080,
# SESSION_SECURE_COOKIES=false y los datos de la base de arriba

php scripts/crear-admin.php "Max González" max@ejemplo.local "clave-de-prueba-local-2026"
php scripts/sembrar-demo.php

php -S 127.0.0.1:8080 -t public scripts/router-local.php
```

Para el hero, si hace falta regenerarlo:
`bash scripts/video/procesar-hero.sh storage/originals/hero/hero-aprobado.mp4`

## 7. Cómo verificar · esto no es opcional

**No declares nada terminado sin evidencia.** Nada de «debería funcionar».

```bash
php tests/ejecutar.php      # 102 pruebas. Tienen que seguir en verde
```

Y revisa en un navegador real, no leyendo el código. Chromium está
preinstalado en `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` y
`playwright-core` se instala con `npm i playwright-core`. En la sesión
anterior se midió así, y así encontró fallos que el código no delataba:

- Desbordamiento horizontal a 375 px, en todas las rutas.
- Contraste de **cada** texto, componiendo las capas semitransparentes
  sobre lo que tienen detrás. Sin componer el alfa, un fondo al 6% se lee
  como color sólido y da falsos positivos.
- Objetivos táctiles, acreditando la etiqueta que envuelve a un control y
  el área ampliada con `::after`.
- El hero: que avance al bajar y **retroceda al subir**.
- Con `prefers-reduced-motion`: que el video ni siquiera se descargue.
- Que renombrar el video deje la portada completa.

Si añades pruebas, mételas en `tests/ejecutar.php` siguiendo el marco que ya
existe en `tests/marco.php`.

## 8. Cómo quiero que trabajes

- **Habla en español**, directo y sin adornos.
- **Verifica antes de afirmar.** Si algo falla, dilo con la salida real.
- **Corrige la causa, no el síntoma.** En la sesión anterior el
  desbordamiento tenía tres causas distintas y se arreglaron las tres, no se
  puso un `overflow:hidden` encima.
- **Cuando una prueba falle, pregúntate primero si la prueba está mal.**
  Ya pasó: una prueba buscaba la cadena `onerror` en el HTML y la encontraba
  escapada como texto inofensivo. La prueba estaba mal, el código bien.
  Pero en ese mismo repaso apareció un fallo **real** de seguridad: los
  bloques de imagen y de fuente no validaban el esquema de la URL. Se
  corrigió. Mantén esa disciplina.
- **No inventes nada que pueda pasar por real**: ni cifras, ni fuentes, ni
  testimonios, ni resultados de encuesta. Todo el contenido de demostración
  va marcado con `is_demo` y se rotula en pantalla.
- **Compromételo y súbelo** a `claude/new-session-sv6rbv` con mensajes que
  expliquen el porqué, no solo el qué.
- Si encuentras una contradicción entre materiales, aplica esta prioridad:
  1. Permanencia, credibilidad y seguridad editorial.
  2. Documento maestro de La Noticia SIN FILTRO.
  3. Requisitos funcionales.
  4. Identidad de SIN FILTRO.
  5. Recursos visuales y animaciones.

## 9. Cuándo está terminado

Cuando todo esto sea cierto y esté comprobado:

- Max publica una **Noticia Viva completa** desde el panel, con pulso,
  fuentes, cronología, actores y consecuencias, sin tocar código ni SQL.
- Cada noticia tiene URL permanente y una archivada se encuentra años después.
- El buscador consulta noticias y transcripciones.
- Los videos tienen póster, subtítulos y alternativa textual.
- El hero no perjudica al móvil, ni a la accesibilidad, ni a la velocidad.
- Los correos de aviso salen de verdad.
- Existen respaldos y una restauración **probada**.
- La plataforma corre en el alojamiento real, con HTTPS y `APP_DEBUG=false`.
- El contenido de demostración se retiró o está rotulado.
- `CHECKLIST_DE_LANZAMIENTO.md` está recorrido entero.

**Empieza leyendo el repositorio y dime qué encontraste antes de escribir
código.**
