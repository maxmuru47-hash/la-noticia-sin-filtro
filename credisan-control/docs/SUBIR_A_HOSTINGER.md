# Subir CrediSan Control a Hostinger — 5 minutos

## Antes de empezar: nada de esto toca tus otras webs

La carpeta **`control`** que ya existe dentro de `public_html` es la raíz del
subdominio `control.sinfiltroconmax.com`. Todo lo de CrediSan vive **dentro**
de esa carpeta.

- `índice.html`, la imagen y el PDF que están fuera → **no se tocan**
- `lanoticiasinfiltroconmax.com` → **no se toca**
- `sinfiltroconmax.com` → **no se toca**

El `.htaccess` incluido sólo tiene efecto dentro de `control/` y de sus
subcarpetas. No lleva redirecciones ni reglas que salgan de ahí: a propósito.

---

## Paso 1 · Subir el archivo

1. Descarga **`credisan-control-web.zip`**.
2. hPanel → **Administrador de archivos** → entra en `public_html` → entra en
   la carpeta **`control`**.
3. Botón **Subir archivos** → elige el ZIP.
4. Clic derecho sobre el ZIP → **Extraer** → confirma.
5. Borra el ZIP (ya no hace falta).

Dentro de `control` debe quedar así:

```
control/
├── index.html
├── manifest.webmanifest
├── sw.js
├── .htaccess
├── assets/
├── config/env.js
└── src/
```

> Si al extraer te queda una carpeta `public` dentro de `control`, entra en ella,
> selecciona todo y muévelo un nivel arriba. El `index.html` tiene que quedar
> directamente dentro de `control`.

## Paso 2 · Activar HTTPS

hPanel → **Seguridad → SSL** para `control.sinfiltroconmax.com` → certificado
activo → activa **Forzar HTTPS**.

Sin HTTPS no hay cámara ni instalación de la aplicación. Es obligatorio.

## Paso 3 · Abrir

<https://control.sinfiltroconmax.com>

Verás la portada de CrediSan con el estado del sistema. Es normal que el tercer
y el cuarto punto salgan en ámbar: el servidor todavía no existe.

## Paso 4 · Crear el servidor (Supabase)

1. Entra en <https://supabase.com> y crea una cuenta.
2. **New project** → nombre `credisan-control` → región `East US` → guarda la
   contraseña que te pida en un sitio seguro.
3. Espera unos dos minutos a que termine de crearse.
4. **SQL Editor** → **New query** → pega el contenido de cada archivo de
   `supabase/migrations/` **en orden de nombre** (son diez) y ejecuta cada uno.
   Después haz lo mismo con `supabase/seed/seed.sql`.
5. **Settings → API** → copia **Project URL** y la clave **anon public**.

## Paso 5 · Conectar

Administrador de archivos → `control/config/env.js` → clic derecho → **Editar**.

Pega los dos valores entre las comillas:

```js
window.CREDISAN_ENV = {
  supabaseUrl:     'https://abcdefgh.supabase.co',
  supabaseAnonKey: 'eyJhbGciOiJIUzI1NiIs...',
  version:         '1.0.0'
};
```

Guarda, recarga la página y los cuatro puntos deben quedar en verde.

> La clave `anon` es pública por diseño: está pensada para el navegador y toda
> la seguridad real vive en las políticas de la base de datos. La clave
> `service_role` **nunca** se pega en este archivo.

## Paso 6 · Avisarme

Con los cuatro puntos en verde, arranco la Fase 2: inicio de sesión, sedes,
trabajadores y horarios.

---

## Si algo falla

| Lo que ves | Qué pasa |
|---|---|
| Página en blanco | El `index.html` no quedó directamente dentro de `control/` |
| Sin candado / sin HTTPS | Falta activar SSL y Forzar HTTPS en hPanel |
| «No se pudo conectar» | La URL de Supabase está mal escrita en `env.js` |
| «Sin base de datos» | Faltan migraciones por ejecutar en el SQL Editor |
| «Respondió con error 401» | La clave anon está incompleta o mal copiada |
