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

---

# La base de datos, también automática

Tercer flujo, **«Migrar base CrediSan»**. Aplica los cambios de estructura sin
que nadie pegue SQL en el editor.

## Lo que hay que hacer una vez

Un secreto más: **`SUPABASE_DB_URL`**.

supabase.com → su proyecto → botón **Connect** (arriba) → pestaña **Session
pooler** → copie la cadena entera y sustituya `[YOUR-PASSWORD]` por su
contraseña.

> **Use Session pooler, no *Direct connection*.** La directa sólo responde por
> IPv6 y los servidores de GitHub no llegan hasta ahí. Es la causa número uno
> de que este flujo falle.

Esa cadena da acceso completo a la base. Como las demás: se pega en GitHub,
nunca en el chat.

## Por qué se puede automatizar esto sin miedo

Porque los archivos que aplica **no modifican ni una tabla**. Los cuatro
`ACTUALIZAR-*.sql` son exclusivamente `create or replace function`: cambian lo
que el sistema *sabe hacer*, nunca lo que *tiene guardado*.

Y no se confía en que siga siendo así: **antes de cada ejecución el flujo lee
los archivos** y se planta si aparece un `drop`, un `truncate` o un `delete`.
Se planta antes de conectarse a la base, así que no llega a tocarla.

## Las cuatro barreras

1. **Se lee antes de ejecutar.** Si un archivo trajera algo destructivo, el
   flujo aborta sin conectarse.
2. **Una transacción por archivo.** Si algo falla a mitad, la base queda
   exactamente como estaba. Nada a medias, nunca.
3. **Se cuenta antes y después.** Trabajadores y marcaciones. Si bajara
   cualquiera de los dos, el flujo falla y lo dice en rojo.
4. **La copia que se guarda es sólo la estructura**, sin una sola fila. Sacar
   los nombres, cédulas y horarios de su personal a un artefacto de GitHub
   sería llevárselos de donde deben estar. De los datos se encarga el respaldo
   nocturno que se guarda en su VPS (más abajo).

## Qué hace en cada ejecución

- Si la base está **vacía** → la instala entera con `INSTALAR.sql`.
- Si ya está **instalada** → aplica los `ACTUALIZAR-*.sql` en orden.

Los archivos están hechos para ejecutarse las veces que haga falta: si una fase
ya estaba, lo dice y sigue. Por eso el flujo **converge desde cualquier estado**
sin llevar la cuenta de nada — no hay un registro que se pueda desincronizar.

Probado contra una base que replica el estado real de producción —fases 1 a 3
aplicadas, con personal y salarios cargados—: la llevó hasta la fase 6 con los
doce trabajadores y sus doce salarios intactos, y una segunda pasada completa
no cambió absolutamente nada.


---

# El respaldo de la base, cada noche

El plan gratuito de Supabase **no hace respaldos automáticos**. Dicho sin
rodeos: si mañana esa base desapareciera, desaparecería con ella el registro
de quién entró y a qué hora desde el primer día. No hay botón de deshacer.

Por eso hay un flujo, **«Respaldo diario CrediSan»**, que cada noche saca una
copia completa y la deja en su VPS.

## Cómo funciona, en una frase

A la 1:10 de la madrugada (hora de Caracas), GitHub le pide a Supabase una
copia de la base y la manda **por una tubería** directa al servidor de usted,
a `/opt/proyectos/credisan-control/respaldos/`. Se guardan los **14 últimos
días**; el más viejo se borra solo.

## Lo que hay que hacer una vez

Nada nuevo, si ya publicó el sitio: usa los mismos secretos que el despliegue
(`VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_PORT`) más `SUPABASE_DB_URL`, que
es el mismo del flujo de la base de datos. Si falta alguno, el flujo no
intenta nada: lo dice y se detiene.

## Por qué está hecho así

Este archivo es **el más sensible de todo el proyecto**: lleva nombres,
cédulas, salarios y el historial completo de entradas y salidas. Un respaldo
mal guardado es una filtración con horario. De ahí cada decisión:

| Decisión | Por qué |
|---|---|
| Se guarda **fuera** de la carpeta que publica Caddy | Si cayera dentro, las cédulas y los salarios estarían a un clic en internet |
| Se comprueba por la ruta **antes** de escribir | Más vale detenerse que escribir en el sitio equivocado |
| Y después **se pide por internet** para ver que no responde | La teoría se equivoca; la prueba, no |
| Permisos `600` | Ni el usuario que sirve la web puede abrirlo |
| El volcado **nunca toca el disco de GitHub** | El runner es una máquina prestada que se apaga; no es sitio para esto |
| Se comprueba que el archivo esté íntegro y lleve las tablas | Un respaldo corrupto que nadie mira es peor que no tener ninguno: da falsa tranquilidad justo hasta el día que hace falta |

Si cualquiera de esas comprobaciones falla, el flujo sale en rojo y usted se
entera esa misma mañana, no el día de la desgracia.

## Qué lleva dentro y qué no

**Sí:** las tablas con el personal, las marcaciones, los horarios, los cierres,
la auditoría, y los permisos que esconden el salario de quien no debe verlo.

**No:** las fotos de evidencia (viven aparte, en el almacén de Supabase, y se
borran solas a los 180 días) ni los usuarios que entran al panel (esos se
vuelven a invitar por correo en un minuto).

## Si algún día hay que usarlo

Ojalá no, pero por si acaso, y en orden:

1. Crear un proyecto nuevo en Supabase.
2. Correr el flujo **«Poner en marcha CrediSan»** para dejar la base instalada.
3. Descargar del VPS el respaldo del día que se quiera recuperar:
   `scp usuario@servidor:/opt/proyectos/credisan-control/respaldos/credisan-AAAAMMDD-HHMM.sql.gz .`
4. Restaurarlo:
   `gunzip -c credisan-AAAAMMDD-HHMM.sql.gz | psql "<cadena del Session pooler>"`
5. Volver a invitar a los usuarios del panel.

El archivo trae sus propias órdenes de borrado antes de cada tabla, así que
restaurar sobre una base ya instalada la deja exactamente como estaba la noche
de la copia, sin restos de en medio.

> Una advertencia honesta: un respaldo que nunca se ha probado es una
> suposición. Cuando haya un rato tranquilo, vale la pena restaurar uno en un
> proyecto de prueba y verlo funcionar. Es la única manera de saber.
