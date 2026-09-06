# Guía de alojamiento

Cómo poner La Noticia SIN FILTRO en línea, y cómo comprobar que quedó bien.

---

## 1. Qué debes exigirle a tu proveedor

Antes de contratar o de decidir, confirma estas seis cosas. Si falta alguna,
la plataforma no funciona completa.

| Requisito | Mínimo | Por qué |
|---|---|---|
| PHP | 8.2 | El código usa sintaxis de PHP 8.1+ y tipos modernos |
| Extensiones PHP | `pdo_mysql`, `mbstring`, `gd`, `intl`, `fileinfo`, `dom`, `json` | Base de datos, acentos, imágenes, fechas y validación de cargas |
| MySQL / MariaDB | MySQL 8.0 o MariaDB 10.4 | `FULLTEXT` sobre InnoDB, JSON y `utf8mb4` |
| Raíz del dominio | Apuntable a una subcarpeta | Solo `public/` puede ser accesible |
| Reescritura de URL | `mod_rewrite` o equivalente | URL limpias y permanentes |
| Tareas programadas | Cron cada 5 minutos | Publicación programada y estado de los Lives |

**Comprueba PHP y sus extensiones** subiendo este archivo y abriéndolo una
sola vez (bórralo después, siempre):

```php
<?php
foreach (['pdo_mysql','mbstring','gd','intl','fileinfo','dom','json','openssl'] as $e) {
    printf("%-12s %s\n", $e, extension_loaded($e) ? 'OK' : 'FALTA');
}
echo "PHP " . PHP_VERSION;
```

---

## 2. La decisión que más importa: dónde apunta el dominio

**Solo `public/` puede ser accesible desde internet.** Todo lo demás
(`app/`, `config/`, `storage/`, `.env`) tiene que quedar fuera del alcance
de cualquier visitante.

### Caso A · Puedes apuntar el dominio a una subcarpeta (lo ideal)

En Hostinger, cPanel o Plesk, en la sección de dominios o subdominios, pon
como **document root**:

```
/home/TU_USUARIO/la-noticia-sin-filtro/public
```

Sube el proyecto completo a `/home/TU_USUARIO/la-noticia-sin-filtro/`.
Listo. Es la opción más segura y no requiere nada más.

### Caso B · El dominio apunta obligatoriamente a `public_html`

Algunos planes compartidos no dejan cambiar el document root. Entonces:

1. Sube el contenido de `public/` **dentro** de `public_html/`.
2. Sube el resto del proyecto a `/home/TU_USUARIO/lnsf-privado/`, es decir,
   **al mismo nivel** que `public_html`, nunca dentro.
3. Edita `public_html/index.php` y cambia la primera línea:

```php
$config = require __DIR__ . '/../lnsf-privado/app/bootstrap.php';
```

4. En `config/config.php`, la constante `LNSF_ROOT` ya apunta sola al
   directorio correcto, porque se calcula desde `app/bootstrap.php`.

Comprueba que quedó bien pidiendo estas direcciones. **Las cuatro deben dar
403 o 404, nunca contenido**:

```
https://tudominio.com/.env
https://tudominio.com/app/bootstrap.php
https://tudominio.com/config/config.php
https://tudominio.com/storage/logs/
```

Si alguna devuelve texto, **detente**: tus credenciales están expuestas.
Cambia la contraseña de la base de datos y arregla la estructura antes de
seguir.

---

## 3. Instalación paso a paso

```bash
# 1. Subir los archivos (por SFTP, por Git o por el gestor del panel)

# 2. Permisos: el servidor web debe poder escribir aquí, y solo aquí
chmod -R 755 .
chmod -R 775 storage public/uploads
chmod 640 .env

# 3. Base de datos: créala desde el panel del proveedor y luego
mysql -u USUARIO -p BASE < database/schema.sql
mysql -u USUARIO -p BASE < database/seed.sql

# 4. Configuración
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"   # el resultado va en APP_KEY
nano .env
```

En `.env`, para producción:

```
APP_ENV=produccion
APP_DEBUG=false                  # NUNCA true en producción
APP_URL=https://lanoticia.sinfiltroconmax.com
SESSION_SECURE_COOKIES=true      # exige HTTPS activo
```

```bash
# 5. Primera cuenta
php scripts/crear-admin.php "Max González" tu@correo.com "una-contrasena-muy-larga"

# 6. Verificación
php scripts/cron.php               # debe imprimir una línea de resumen
php tests/ejecutar.php             # debe terminar en verde
```

---

## 4. Si tu servidor es Nginx

`.htaccess` no existe en Nginx. Esta es la configuración equivalente:

```nginx
server {
    listen 443 ssl http2;
    server_name lanoticia.sinfiltroconmax.com;

    root /home/usuario/la-noticia-sin-filtro/public;
    index index.php;

    charset utf-8;
    client_max_body_size 210M;   # súbelo si vas a alojar video propio

    # Todo lo que no sea un archivo real entra por el front controller.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Los archivos subidos NUNCA se ejecutan.
    location ^~ /uploads/ {
        location ~ \.(php|phtml|phar|cgi|pl|py|sh)$ { deny all; }
        add_header X-Content-Type-Options nosniff;
    }

    # Nada que empiece por punto se sirve jamás.
    location ~ /\. { deny all; }

    # Cache larga para estáticos, ninguna para el HTML.
    location ~* \.(css|js|jpg|jpeg|png|webp|avif|svg|mp4|webm|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}
```

---

## 5. Tareas programadas

En el panel de tu proveedor, sección **Cron jobs** o **Tareas programadas**:

```
*/5 * * * *   /usr/bin/php /home/usuario/la-noticia-sin-filtro/scripts/cron.php >> /home/usuario/la-noticia-sin-filtro/storage/logs/cron.log 2>&1
15 3 * * *    /bin/bash /home/usuario/la-noticia-sin-filtro/scripts/respaldo.sh >> /home/usuario/la-noticia-sin-filtro/storage/logs/respaldo.log 2>&1
```

Averigua la ruta real de PHP con `which php`. En algunos planes compartidos
es `/usr/local/bin/php` o `/opt/alt/php82/usr/bin/php`.

**Si tu plan no ofrece cron:** el front controller publica igualmente las
piezas programadas, con una comprobación por minuto como máximo. Funciona,
pero depende de que llegue una visita. Si programas una pieza para las 3 de
la mañana y nadie entra hasta las 7, se publicará a las 7.

---

## 6. HTTPS

Activa el certificado gratuito (Let's Encrypt) desde el panel de tu
proveedor. Después:

1. Pon `SESSION_SECURE_COOKIES=true` en `.env`.
2. Comprueba que `APP_URL` empieza por `https://`.
3. La redirección a HTTPS ya está en `public/.htaccess`.

Sin HTTPS, las cookies de sesión viajan en claro y cualquiera en la misma
red puede robar la sesión del panel.

---

## 7. Ancho de banda si alojas video propio

Un video de 5 MB visto 10.000 veces son 50 GB de transferencia. Antes de
alojar Lives completos en tu servidor, mira cuánta transferencia incluye tu
plan.

Recomendación, y es la estrategia que el CMS ya soporta:

- **Hero y clips cortos**: alojados por ti. Son pocos MB y los controlas.
- **Lives largos**: incrustados desde YouTube, **pero con la transcripción,
  los capítulos, el póster y el resumen guardados en tu base de datos**. Si
  mañana el video desaparece de la plataforma, tu contenido editorial sigue
  vivo y el buscador sigue encontrándolo.

---

## 8. Comprobación final antes de anunciar el sitio

```
[ ] https://tudominio.com/.env             → 403 o 404
[ ] https://tudominio.com/app/             → 403 o 404
[ ] https://tudominio.com/storage/         → 403 o 404
[ ] La portada carga
[ ] Una noticia carga por su URL permanente
[ ] El buscador devuelve resultados
[ ] /sitemap.xml devuelve XML válido
[ ] /rss.xml devuelve XML válido
[ ] /robots.txt apunta a los sitemaps con el dominio real
[ ] El panel pide contraseña
[ ] APP_DEBUG=false
[ ] El candado de HTTPS aparece en el navegador
[ ] php scripts/cron.php se ejecuta sin errores
[ ] bash scripts/respaldo.sh genera un archivo
[ ] Restauraste ese respaldo en una base de prueba y funcionó
```

La lista completa de lanzamiento está en `CHECKLIST_DE_LANZAMIENTO.md`.
