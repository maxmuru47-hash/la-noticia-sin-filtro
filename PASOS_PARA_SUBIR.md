# Cómo subirlo · guía corta

Diez pasos. No hace falta línea de comandos: todo se puede hacer desde el
panel de tu hosting.

La guía larga, con Nginx, cron y detalles, está en `GUIA_DE_ALOJAMIENTO.md`.

---

## 1 · Sube los archivos

Descomprime el paquete y sube **todo** a tu hosting, por FTP o desde el
gestor de archivos.

Lo ideal es dejarlo así:

```
/home/tu_usuario/la-noticia/     ← todo el proyecto aquí
```

Y apuntar el dominio a la carpeta `public` de dentro:

```
/home/tu_usuario/la-noticia/public
```

**Solo la carpeta `public` puede ser visible desde internet.** Todo lo
demás tiene que quedar por fuera.

> **¿Tu plan no deja cambiar esa carpeta?**
> Entonces sube el contenido de `public/` dentro de `public_html/`, y el
> resto del proyecto a una carpeta hermana, por ejemplo `la-noticia-privado/`.
> Después abre `public_html/index.php` y en la primera línea cambia
> `/../app/bootstrap.php` por `/../la-noticia-privado/app/bootstrap.php`.
> Si te trabas aquí, dímelo y lo vemos juntos.

---

## 2 · Crea la base de datos

En el panel de tu hosting, sección **Bases de datos MySQL**:

1. Crea una base nueva. Apunta su **nombre**.
2. Crea un usuario. Apunta su **nombre** y su **contraseña**.
3. Asigna ese usuario a esa base, con **todos los permisos**.

Guarda esos tres datos, los necesitas en el paso 4.

---

## 3 · Importa la estructura

Entra a **phpMyAdmin**, elige tu base y ve a la pestaña **Importar**.

Importa estos dos archivos, **en este orden**:

1. `database/schema.sql` ← crea las 41 tablas
2. `database/seed.sql` ← crea los roles y las categorías

Al terminar deberías ver 41 tablas en la lista de la izquierda.

---

## 4 · Configura el archivo `.env`

En la carpeta del proyecto hay un archivo llamado `.env.example`.
**Renómbralo a `.env`** y ábrelo con el editor de tu hosting.

Cambia estas líneas:

```
APP_ENV=produccion
APP_DEBUG=false
APP_URL=https://lanoticia.sinfiltroconmax.com

APP_KEY=          ← mira el paso 5

DB_HOST=localhost
DB_DATABASE=el_nombre_de_tu_base
DB_USERNAME=el_usuario_que_creaste
DB_PASSWORD=su_contraseña

SESSION_SECURE_COOKIES=true

EDITORIAL_EMAIL=tu_correo@dominio.com
```

**`APP_DEBUG` tiene que quedar en `false`.** Con `true`, un error mostraría
detalles internos a cualquiera que pase por ahí.

---

## 5 · Genera la clave `APP_KEY`

Es la que hace que el voto del Pulso sea de verdad anónimo. Sin ella la
plataforma **no arranca**, a propósito.

Abre esta dirección en tu navegador, copia lo que salga y pégalo en `.env`
después de `APP_KEY=`:

```
https://lanoticia.sinfiltroconmax.com/clave.php
```

Después **borra el archivo `public/clave.php`**.

Si prefieres no usarlo: sirve cualquier texto aleatorio de 64 caracteres
con letras y números.

---

## 6 · Permisos de carpetas

Estas cuatro carpetas tienen que poder escribirse. En el gestor de
archivos, clic derecho → Permisos → **755** (si no funciona, **775**):

```
storage/logs
storage/backups
storage/originals
public/uploads
```

---

## 7 · Crea tu cuenta

Abre en el navegador:

```
https://lanoticia.sinfiltroconmax.com/instalar.php
```

Verás tres bloques:

1. **El servidor** · una lista con ✓ y ✗. Si hay alguna ✗, arréglala y recarga.
2. **La base de datos** · debe decir «Conectada. 41 tablas y 6 roles».
3. **Tu cuenta** · escribe tu nombre, tu correo y una contraseña de al
   menos 12 caracteres.

Al terminar, **el instalador se borra solo**. Si te avisa de que no pudo,
bórralo tú desde el gestor de archivos. No lo dejes ahí.

---

## 8 · Entra y mira

```
https://lanoticia.sinfiltroconmax.com/panel/entrar
```

Ya puedes ver el sitio completo. Trae contenido de demostración para que no
lo veas vacío: cinco piezas, dos Lives, videos, un expediente y un pulso.

**Todo eso está rotulado como demostración en la propia página.** Nada se
presenta como una noticia real.

Cuando quieras borrarlo, cada pieza tiene su botón. O dímelo y te preparo un
botón que lo quite todo de una vez.

---

## 9 · Las tareas automáticas

En el panel de tu hosting, sección **Cron jobs** o **Tareas programadas**,
añade estas dos. Cambia `/home/tu_usuario/la-noticia` por tu ruta real:

**Publicar las piezas programadas** (cada 5 minutos):
```
*/5 * * * *  /usr/bin/php /home/tu_usuario/la-noticia/scripts/cron.php
```

**Respaldo diario** (a las 3:15 de la madrugada):
```
15 3 * * *  /bin/bash /home/tu_usuario/la-noticia/scripts/respaldo.sh
```

Si tu plan no tiene cron, no pasa nada: las piezas programadas se publican
igual cuando llega una visita. Pero el respaldo sí conviene tenerlo.

---

## 10 · Comprueba que quedó bien

Abre estas direcciones. Las tres primeras **deben dar error 403 o 404**.
Si alguna muestra texto, avísame de inmediato:

```
https://lanoticia.sinfiltroconmax.com/.env
https://lanoticia.sinfiltroconmax.com/app/bootstrap.php
https://lanoticia.sinfiltroconmax.com/storage/
```

Y estas deben funcionar:

```
https://lanoticia.sinfiltroconmax.com/
https://lanoticia.sinfiltroconmax.com/archivo
https://lanoticia.sinfiltroconmax.com/buscar?q=tarifa
https://lanoticia.sinfiltroconmax.com/sitemap.xml
https://lanoticia.sinfiltroconmax.com/rss.xml
```

---

## Si algo sale mal

| Lo que ves | Qué pasa |
|---|---|
| **Página en blanco** | Falta el `.env`, o los datos de la base están mal |
| **«Falta APP_KEY»** | Paso 5 |
| **«No se pudo conectar a la base de datos»** | Revisa nombre, usuario y contraseña en `.env` |
| **Error 500** | Mira `storage/logs/`. Ahí está el motivo |
| **El CSS no carga** | El dominio no apunta a la carpeta `public` |
| **Error 404 en todo menos la portada** | Falta `mod_rewrite`, o el hosting ignora `.htaccess` |
| **«El instalador ya cumplió su función»** | Ya hay una cuenta. Entra por `/panel/entrar` |

Mándame una captura de lo que veas y te digo qué es.

---

## Lo que todavía no funciona

Para que no te sorprenda:

- **No se envían correos.** Los avisos de Live y el resumen semanal se
  guardan, pero todavía no sale ningún correo. Es lo siguiente que voy a
  construir.
- **Las tipografías oficiales no están.** El sitio usa una alternativa del
  sistema hasta que me pases League Spartan y Montserrat.
- **El logo es un marcador.** Pásame el oficial y lo cambio.
- **El video del hero es de demostración**, generado por mí. Cuando tengas
  material tuyo, lo proceso.
