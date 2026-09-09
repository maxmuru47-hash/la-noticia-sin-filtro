# Despliegue — control.sinfiltroconmax.com (Hostinger)

El subdominio ya existe en el plan Business como sitio **PHP/HTML**. Sirve tal
cual: CrediSan Control es **estático** (HTML + CSS + JS) y todo el backend vive
en Supabase. No hace falta Node, ni base de datos de Hostinger, ni PHP.

## Qué se sube

Sólo `public/` y `src/`. `supabase/`, `docs/` y `.env` **no se suben**.

```
public_html/            ← raíz del subdominio en Hostinger
├── index.html
├── kiosk/  panel/
├── manifest.webmanifest
├── sw.js
├── assets/
├── config/env.js       ← se crea en el servidor, no está en el repositorio
└── src/
```

## Pasos

1. **hPanel → Sitios web → control.sinfiltroconmax.com → Administrador de archivos**
   (o FTP/SFTP; también sirve el despliegue por Git de Hostinger apuntando a la
   rama, con `public/` como carpeta raíz).
2. Suba el contenido de `public/` a `public_html/`.
3. Cree `public_html/config/env.js` a partir de `config/env.example.js` con la
   URL y la *anon key* del proyecto Supabase. **La `service_role key` no va
   aquí jamás.**
4. **SSL**: hPanel → Seguridad → SSL. Certificado activo y *forzar HTTPS*.
   Sin HTTPS no hay cámara, ni PWA, ni service worker.
5. Suba el `.htaccess` de `public/.htaccess` (ya incluido).

## Dominio configurable

El dominio no está escrito en ningún otro sitio que `config/env.js`
(`appBaseUrl`). Mudarse a otro dominio es cambiar ese archivo y añadir el nuevo
origen en **Supabase → Authentication → URL Configuration**.

## En Supabase

**Authentication → URL Configuration**:

- Site URL: `https://control.sinfiltroconmax.com`
- Redirect URLs: `https://control.sinfiltroconmax.com/**`

## Comprobación

- [ ] `https://control.sinfiltroconmax.com` carga por HTTPS con candado
- [ ] El navegador ofrece «Instalar aplicación» (PWA)
- [ ] `sw.js` responde con `Cache-Control: no-cache`
- [ ] `config/env.js` **no** contiene la `service_role key`
- [ ] El terminal Android instala la PWA y abre la cámara
