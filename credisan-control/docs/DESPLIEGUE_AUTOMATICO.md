# Despliegue automático al VPS

Desde que esto quede configurado, cada cambio que se publique en el repositorio
sube solo a `control.sinfiltroconmax.com`. Nadie copia archivos a mano.

**Configuración: una sola vez.**

---

## Por qué así y no por SSH desde el chat

La sesión que escribe el código corre en un contenedor en la nube de Anthropic,
no en la PC de Max. Desde ahí **no** hay `ssh vps`, no existe `C:\Users\PC`, y el
puerto 22 del VPS no es alcanzable. Esa sesión puede escribir, probar y publicar
en GitHub; no puede copiar archivos al servidor.

Quien sí puede es **GitHub**: sus servidores alcanzan el VPS sin problema. Así
que el circuito queda:

```
la sesión escribe y prueba  →  publica en GitHub  →  GitHub copia al VPS  →  Caddy lo sirve
```

El resultado es el que se buscaba —nadie sube archivos a mano— y además queda
registro de cada publicación, con respaldo previo y comprobación posterior.

---

## Lo que hay que hacer una vez

### 1 · Crear una llave sólo para publicar

En la PC de Max (o en la sesión que tenga `ssh vps`):

```powershell
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\credisan_despliegue" -N '""' -C "despliegue-credisan"
ssh vps "mkdir -p ~/.ssh && chmod 700 ~/.ssh"
type "$env:USERPROFILE\.ssh\credisan_despliegue.pub" | ssh vps "cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

Esto crea una llave **nueva y exclusiva** para publicar. La llave personal de
Max no se toca ni se comparte.

### 2 · Guardarla en GitHub

Repositorio → **Settings** → **Secrets and variables** → **Actions** →
**New repository secret**. Tres secretos:

| Name | Valor |
|---|---|
| `VPS_HOST` | la IP del VPS |
| `VPS_USER` | el usuario con el que se entra por SSH |
| `VPS_SSH_KEY` | el contenido **completo** del archivo `credisan_despliegue` (el que **no** termina en `.pub`), desde `-----BEGIN` hasta `-----END` incluidos |

> Los secretos de GitHub se guardan cifrados y no se pueden volver a leer, ni
> siquiera por su dueño. Sólo los usa el despliegue.
>
> La llave privada **no se pega en el chat**. Se copia del archivo a la caja de
> GitHub directamente.

### 3 · Probar

Pestaña **Actions** → *Desplegar CrediSan Control* → **Run workflow**.

---

## Qué hace cada publicación

1. Comprueba que `config/env.js` del repositorio sigue vacío
2. **Respalda** lo que hay en producción: `respaldo-AAAAMMDD-HHMM.tar.gz`
   (se conservan los diez últimos)
3. Copia los archivos con `rsync`
4. Devuelve los permisos a `caddy:caddy`
5. Comprueba que `/`, `/panel/`, `/kiosk/` y `/sw.js` responden `200`

Si el último paso falla, el despliegue sale en rojo y el respaldo del paso 2
está ahí para volver atrás.

## Lo que nunca se toca

**`config/env.js` del servidor.** Está excluido del envío. En el repositorio
viaja vacío a propósito: si se copiara, la web se quedaría sin servidor en cada
publicación. El del servidor, con la URL de Supabase y la clave pública, se
queda como está.

**Los otros sitios del VPS.** El despliegue escribe únicamente en
`/opt/proyectos/credisan-control/public/`.

## Volver atrás

```bash
ssh vps
cd /opt/proyectos/credisan-control
ls -1t respaldo-*.tar.gz | head          # ver los disponibles
tar -xzf respaldo-AAAAMMDD-HHMM.tar.gz   # restaura la carpeta public
chown -R caddy:caddy public
```

## Lo que sigue siendo manual, a propósito

Las **migraciones SQL** y la **función del servidor** se siguen aplicando a mano
en Supabase. No es que no se pueda automatizar: es que una publicación de código
no debería cambiar la estructura de la base de datos sin que nadie mire. Son dos
o tres veces por fase, con un archivo que se pega y un botón que se pulsa.
