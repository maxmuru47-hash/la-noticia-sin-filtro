# Despliegue automático — se acabó el ZIP

Desde ahora, cada cambio que se publique en el repositorio sube solo a
`control.sinfiltroconmax.com`. Usted no vuelve a subir, borrar ni extraer nada.

**Configuración: una sola vez, 5 minutos.**

---

## Lo que hay que hacer

### 1 · Sacar las credenciales FTP de Hostinger *(2 min)*

hPanel → **Archivos** → **Cuentas FTP**

Anote tres datos de esa pantalla:

| Dato | Ejemplo |
|---|---|
| **Servidor FTP** | `ftp.sinfiltroconmax.com` o una IP |
| **Nombre de usuario** | `u884991609` |
| **Contraseña** | la de esa cuenta FTP |

> Si no recuerda la contraseña, en esa misma pantalla puede cambiarla.
> Es la contraseña del FTP, no la de su cuenta de Hostinger.

### 2 · Guardarlos en GitHub *(3 min)*

Abra el repositorio en GitHub →
**Settings** → **Secrets and variables** → **Actions** → **New repository secret**

Cree tres, uno por uno, con estos nombres exactos:

| Name | Secret |
|---|---|
| `FTP_SERVIDOR` | el servidor FTP |
| `FTP_USUARIO` | el nombre de usuario |
| `FTP_CLAVE` | la contraseña |

> Los secretos de GitHub se guardan cifrados y no se pueden volver a leer,
> ni siquiera por usted. Sólo los usa el despliegue.

### 3 · Ya está

En la pestaña **Actions** verá el flujo *«Desplegar CrediSan Control»*. Pulse
**Run workflow** para lanzarlo a mano la primera vez y comprobar que sube bien.

---

## Cómo funciona a partir de ahora

1. Se publica un cambio en `credisan-control/public/`
2. GitHub lo detecta y lanza el despliegue
3. En menos de un minuto, la web está actualizada

Puede seguirlo en la pestaña **Actions**: verde es que subió, rojo es que algo
falló y ahí mismo dice qué.

## Lo que el despliegue NO toca

**`config/env.js` del servidor.** Está en la lista de exclusión, y por una
razón concreta: en el repositorio ese archivo viaja **vacío**, y si se
sobrescribiera, la web se quedaría sin servidor en cada publicación.

El del servidor, con sus claves reales, se queda como está para siempre. Si
algún día cambia de proyecto Supabase, lo edita a mano una vez y listo.

**Sus otras webs.** El despliegue apunta sólo a `public_html/control/`. Ni
`lanoticiasinfiltroconmax.com` ni `sinfiltroconmax.com` se rozan.

## El guardián

Antes de subir nada, el flujo comprueba que `config/env.js` del repositorio
sigue vacío. Si alguien pegara ahí una clave real y la publicara, el despliegue
**se detiene y avisa** en lugar de dejar sus credenciales escritas en el
historial de GitHub para siempre.

## Lo que sigue siendo manual

El despliegue automático cubre la web. Estas dos cosas siguen pidiendo una
visita a Supabase, porque tocan la base de datos y no deben pasar solas:

- **Migraciones SQL** — cuando una fase nueva cambie el esquema, le paso el
  archivo y lo pega en el SQL Editor.
- **La función del servidor** — se publica desde Edge Functions.

Es deliberado: que una publicación de código altere la estructura de la base de
datos sin que nadie mire es exactamente como se pierden datos.
