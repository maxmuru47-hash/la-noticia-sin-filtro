# La Noticia SIN FILTRO

Plataforma editorial de **SIN FILTRO con Max**.

> La noticia no termina cuando la lees. Comienza cuando la entiendes.

Esta no es una maqueta. Es un sistema editorial funcional: se crean, editan,
programan, publican, actualizan, buscan y archivan noticias **sin perder
ninguna publicación y sin cambiar su dirección permanente**.

---

## La regla que manda sobre todas las demás

**Ninguna pieza publicada se elimina.** Todo lo demás en este código está
subordinado a eso:

| Regla | Dónde vive en el código |
|---|---|
| Cada pieza tiene una dirección permanente | `articles.slug` con índice único |
| Cambiar el título **no** cambia la URL | `ArticleService::update()` no toca el slug |
| Archivar **no** es eliminar | `ArticleService::archive()` cambia el estado, nada más |
| Una archivada sigue en buscador y archivo | `Article::ARCHIVED_STATUSES` |
| Una URL cambiada deja redirección 301 | `ArticleService::changeSlug()` |
| Una URL usada jamás se reutiliza | tabla `reserved_slugs` |
| Toda corrección lleva fecha y motivo | tabla `article_corrections` |
| Un retiro conserva la página y el motivo | `ArticleService::withdraw()` |
| Eliminar de verdad exige rol superior | `ArticleService::destroy()` |
| La portada selecciona, no almacena | tabla `homepage_slots` |

Estas reglas están cubiertas por pruebas automáticas. Si alguien las rompe
en el futuro, `php tests/ejecutar.php` lo dice.

---

## Requisitos

- PHP **8.2 o superior**, con `pdo_mysql`, `mbstring`, `gd`, `intl`, `fileinfo`, `json`, `dom`
- MySQL **8.0+** o MariaDB **10.4+**
- Apache con `mod_rewrite`, o Nginx (ver `GUIA_DE_ALOJAMIENTO.md`)
- `ffmpeg` solo en la máquina donde se procesa video, **no** en el servidor

Sin Composer obligatorio. Sin Node. Sin compilación. Se sube y funciona.

---

## Instalación

```bash
# 1. Configuración
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"     # pega el resultado en APP_KEY
# edita .env: base de datos, APP_URL, correo editorial

# 2. Base de datos
mysql -u USUARIO -p BASE < database/schema.sql
mysql -u USUARIO -p BASE < database/seed.sql

# 3. Primera cuenta
php scripts/crear-admin.php "Tu nombre" tu@correo.com "una-contrasena-larga"

# 4. Contenido de demostración (opcional, y borrable)
php scripts/sembrar-demo.php

# 5. Probar en local
php -S localhost:8080 -t public scripts/router-local.php
```

Abre `http://localhost:8080` y entra al panel en `/panel/entrar`.

---

## Estructura

```
public/          Lo único accesible desde internet
  index.php      Front controller: todo entra por aquí
  assets/        CSS, JS, imágenes y el hero
  uploads/       Medios subidos. Con .htaccess que apaga la ejecución de PHP
app/
  Controllers/   Rutas públicas y del panel
  Models/        Lectura de datos
  Services/      Las reglas del negocio, incluidas las de permanencia
  Views/         Plantillas PHP planas
  Middleware/    Sesión, capacidades y límite de intentos
  Support/       Base de datos, enrutador, saneamiento, fechas
config/          Lectura de .env
database/        schema.sql, seed.sql y migraciones
storage/         Originales, registros y respaldos. FUERA de public
scripts/         Cron, respaldos, alta de usuarios y procesado de video
tests/           Pruebas automáticas
```

**Qué es accesible desde internet:** solo `public/`. Si tu alojamiento no te
deja apuntar el dominio a una subcarpeta, lee `GUIA_DE_ALOJAMIENTO.md`.

---

## Uso diario

| Quiero... | Voy a... |
|---|---|
| Escribir una pieza | `/panel/noticias/nueva` |
| Publicarla | Botón **Publicar ahora** en su ficha |
| Programarla | Campo de fecha en la misma ficha |
| Corregirla | Bloque **Correcciones**: motivo obligatorio, queda visible |
| Sacarla de portada | `/panel/portada` → **Quitar**. No se borra nada |
| Archivarla | Bloque **Archivo** en su ficha |
| Subir un video | `/panel/videos/nuevo` |
| Agendar un Live | `/panel/lives/nuevo` |
| Moderar preguntas | `/panel/moderacion` |
| Ver quién hizo qué | `/panel/auditoria` |

Guías completas: `GUIA_EDITORIAL.md`, `GUIA_DE_VIDEO.md`, `GUIA_DE_RESPALDOS.md`.

---

## Tareas programadas

Una sola línea en el cron del alojamiento cubre la publicación programada y
el estado de los Lives:

```
*/5 * * * *  /usr/bin/php /ruta/al/proyecto/scripts/cron.php >> /ruta/al/proyecto/storage/logs/cron.log 2>&1
15 3 * * *   /bin/bash  /ruta/al/proyecto/scripts/respaldo.sh >> /ruta/al/proyecto/storage/logs/respaldo.log 2>&1
```

Si tu alojamiento no ofrece cron, el front controller publica igualmente las
piezas programadas, como máximo una comprobación por minuto. Es un respaldo,
no un sustituto: sin visitas, no se ejecuta.

---

## Pruebas

```bash
php tests/ejecutar.php
```

Cubre permanencia, sanitización, permisos, buscador, fechas, SEO y
programación. Lo que necesita ojos humanos (teclado, foco, movimiento
reducido, rendimiento real) está en `CHECKLIST_DE_LANZAMIENTO.md`.

---

## Seguridad

- Contraseñas con Argon2id
- Sesión con cookies `HttpOnly`, `Secure` y `SameSite`, con regeneración al entrar
- Token CSRF en cada formulario y en cada llamada de la API que escribe
- **Todas** las consultas preparadas, sin excepción, verificado por prueba
- Saneamiento por lista blanca de todo el HTML del editor
- Validación de cargas por tipo MIME real, no por el nombre del archivo
- Ejecución de PHP apagada en la carpeta de subidas
- Límite de intentos de acceso con retardo progresivo
- Registro de auditoría de toda acción administrativa
- Credenciales solo en `.env`, nunca en el repositorio

## Accesibilidad

Enlace para saltar al contenido, jerarquía correcta de encabezados, foco
visible siempre, objetivos táctiles de 44 px, contraste WCAG AA,
`prefers-reduced-motion` respetado en vivo, subtítulos y alternativa textual
en todo video, y dimensiones reservadas para que la maqueta no salte.

---

## Licencia y propiedad

Propiedad de **SIN FILTRO con Max**. Todos los derechos reservados.
