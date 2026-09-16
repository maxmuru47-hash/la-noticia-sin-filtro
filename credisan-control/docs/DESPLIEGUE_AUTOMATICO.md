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

---

# La función del servidor, también automática

Hay un segundo flujo, **«Desplegar función CrediSan»**, que publica la Edge
Function en Supabase y se encarga de sus dos secretos. Con esto desaparece el
paso de copiar el código a mano en el panel de Supabase.

## Lo que hay que hacer una vez

Dos secretos más en **Settings → Secrets and variables → Actions**:

| Secreto | De dónde sale |
|---|---|
| `SUPABASE_ACCESS_TOKEN` | supabase.com → su foto arriba a la derecha → **Access Tokens** → *Generate new token* |
| `SUPABASE_PROJECT_REF` | La parte del medio de la dirección de su proyecto: `https://`**`esto`**`.supabase.co` |

Ninguno de los dos se escribe en el chat. Se pegan en GitHub y ya.

Después, en la pestaña **Actions** → **Desplegar función CrediSan** → **Run
workflow**. A partir de ahí se publica sola cada vez que cambie la función.

## Qué hace, y qué NO hace

Publica la función con **Verify JWT desactivado** —que es como tiene que estar,
explicado en `FASE3_TERMINAL.md`— y comprueba que responde.

Con los secretos hace algo que conviene entender bien:

> **Un secreto que ya existe no se toca nunca.**

Sólo los crea si no existen:

- **La pimienta del PIN.** Si no está, la genera. No la ve nadie y no hace
  falta: su único trabajo es no cambiar jamás. Si cambiara, **ningún PIN de
  ningún trabajador volvería a funcionar**, todos a la vez.
- **Las llaves del modo sin conexión.** Si no están, genera el par, guarda la
  privada en Supabase y deja la pública en el `config/env.js` del servidor
  —haciendo antes una copia con fecha y cambiando sólo esa línea—. Si
  cambiaran, las marcaciones que estuvieran esperando en un terminal quedarían
  ilegibles para siempre.

Por eso no hay un botón de «regenerar». Si alguna vez hiciera falta rotarlas,
es una decisión consciente que se toma sabiendo lo que se rompe.

---

## Lo que sigue siendo manual, a propósito

Las **migraciones SQL** se siguen aplicando a mano, pegando el archivo
`ACTUALIZAR-FASEn.sql` en el SQL Editor.

No es que no se pueda automatizar. Es que una publicación de código no debería
cambiar la estructura de la base de datos sin que nadie mire, y además hay una
razón concreta en este proyecto: la base se creó pegando `INSTALAR.sql` en el
editor, así que Supabase no tiene registro de qué migraciones se aplicaron. La
herramienta oficial (`supabase db push`) intentaría aplicarlas todas desde
cero y fallaría.

Se puede automatizar igualmente —los archivos `ACTUALIZAR-*.sql` están hechos
para ejecutarse dos veces sin consecuencias—, pero haría falta guardar la
contraseña de la base como secreto y aceptar que un `git push` cambie la
estructura de la base de producción. Es una decisión que conviene tomar a
propósito, no de rebote.
