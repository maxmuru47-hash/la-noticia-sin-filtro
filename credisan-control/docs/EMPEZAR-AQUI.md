# Empezar aquí · lo único que falta

El sistema está construido y probado entero. Lo que queda son **unas llaves
que sólo puede pegar usted**, porque son de su cuenta y no deben pasar por un
chat, y **un botón**.

**Tiempo: unos 15 minutos.** Después, todo lo demás va solo.

---

## Antes de empezar: tenga a mano

- Su cuenta de **supabase.com** abierta.
- Los datos de su **VPS** (la IP, el usuario y la llave de entrar por SSH).
- Su repositorio en **github.com**, en
  **Settings → Secrets and variables → Actions**.

Va a pegar seis valores ahí dentro. Uno por uno, con el botón
**New repository secret**. Ninguno se ve después: GitHub los guarda tapados.

---

# Paso 1 · Las llaves de Supabase

## `SUPABASE_ACCESS_TOKEN`

Es el permiso para que GitHub publique la función del servidor.

1. En supabase.com, pulse su **foto de perfil** (arriba a la derecha).
2. **Access Tokens** → **Generate new token**.
3. Nombre: `github-credisan`. Copie el texto que aparece.
   *(Sólo se enseña una vez.)*

## `SUPABASE_PROJECT_REF`

Es el nombre corto de su proyecto. Mírelo en su dirección:

```
https://pnbeudvizovmlbvzzezn.supabase.co
        ↑──────────────────↑
        esto es lo que se copia
```

## `SUPABASE_DB_URL`

Es la conexión a la base de datos.

1. En su proyecto de Supabase, botón **Connect** (arriba).
2. Pestaña **Session pooler** ← *importante, no «Direct connection»*
3. Copie la cadena entera. Se parece a:
   `postgresql://postgres.abcdef:[YOUR-PASSWORD]@aws-0-us-east-1.pooler.supabase.com:5432/postgres`
4. Sustituya `[YOUR-PASSWORD]` (corchetes incluidos) por la contraseña de
   su base de datos.

> **Por qué Session pooler y no Direct connection:** la conexión directa
> sólo responde por IPv6, y los servidores de GitHub no llegan hasta ahí.
> Es el motivo número uno de que esto falle.

---

# Paso 2 · Las llaves de su servidor

Son las que permiten publicar la web y guardar los respaldos. Si alguna vez
subió el sitio a mano, estos son los mismos datos que usó entonces.

| Secreto | Qué es |
|---|---|
| `VPS_HOST` | la IP de su servidor |
| `VPS_USER` | el usuario con el que entra por SSH |
| `VPS_SSH_KEY` | la llave privada de despliegue, **el contenido completo** del archivo, desde `-----BEGIN` hasta `-----END` |
| `VPS_PORT` | sólo si su SSH no usa el puerto 22. Si usa el 22, no hace falta crearlo |

> Si todavía no tiene una llave sólo para publicar, cómo crearla está en
> `docs/DESPLIEGUE_AUTOMATICO.md`. **No reutilice su llave personal**: ésta
> se puede revocar sin dejarle a usted fuera del servidor.

---

# Paso 3 · Arrancar

Los flujos **se lanzan solos cuando cambia el código**. Así han estado
funcionando desde el principio, y así se dispara todo la primera vez.

> **Sobre el botón «Run workflow»:** GitHub sólo lo ofrece para los flujos
> que viven en la **rama principal** del repositorio. Mientras estos estén
> en la rama de trabajo, ese botón no aparece en su pantalla — no es un
> fallo suyo ni de permisos. Cuando el proyecto se pase a la rama
> principal, el botón aparece solo y entonces sí: **Actions → Poner en
> marcha CrediSan → Run workflow**.

«Poner en marcha» hace las tres cosas en el orden correcto:

1. Pone la **base de datos** al día.
2. Publica la **función del servidor** y crea sus llaves.
3. Lleva la **web** a su servidor.

Tarda unos cuatro minutos y al terminar escribe un resumen en castellano
diciendo qué hizo y qué comprobar.

> Los tres pasos existen también por separado —*Migrar base*, *Desplegar
> función* y *Desplegar CrediSan Control*— por si alguna vez hace falta
> lanzar sólo uno. Para empezar, el botón único es suficiente.

---

# Paso 4 · Comprobar que quedó bien

## La prueba rápida

Entre a **control.sinfiltroconmax.com** — la portada del sitio *es* la página
de estado. Son cinco comprobaciones y deben salir las cinco en verde. Si
alguna sale en rojo, ahí mismo dice qué falta y cómo arreglarlo: no hay que
adivinar.

## La prueba de fuego

Entre al **panel** con su usuario, vaya a **Personal** y pulse **PIN** en
cualquier trabajador.

**Si sale un PIN de seis dígitos, todo el circuito funciona**: el panel habla
con la base, la base con la función del servidor, y la función tiene sus
llaves. Es el paso que lo prueba todo de una vez.

Mientras eso no esté publicado, el personal aparece con la etiqueta
**«PIN pendiente»** y el panel avisa arriba de la lista. No es un error suyo:
es el sistema diciendo exactamente qué le falta.

## Y de paso, que esté completo

| Debería ver | Significa que |
|---|---|
| Pestaña **Hoy** con las sedes | La base llegó a la fase 6 |
| Pestaña **Cierres** | La fase 5 está aplicada |
| **Más → Auditoría** con movimientos | La auditoría funciona |
| **Más → Terminales y conexión** | La fase 6 está aplicada |

---

# Paso 5 · Ya se puede usar

1. **Personal → + Nuevo** para cada trabajador de cada sede.
2. A cada uno, botón **Poner foto**. Desde el teléfono abre la cámara: una
   foto de frente y ya. Es la que verá al marcar, y la que le permite a usted
   comprobar de un vistazo que quien marcó fue quien dice el PIN.
3. A cada uno, botón **PIN**: se enseña una vez, anótelo y entrégueselo.
4. **Más → Sedes → terminal → Vincular**: da un código de 8 caracteres que
   vive 10 minutos.
5. En el teléfono o tableta del mostrador, abra
   **control.sinfiltroconmax.com/kiosk/**, escriba el código, y ese aparato
   queda atado a esa sede para siempre.
6. **Instálelo como app**: en el menú del navegador, *Añadir a pantalla de
   inicio*. Aparece un icono llamado **Marcar** que abre directo el teclado,
   sin barra de navegador. A partir de ahí el aparato funciona aunque se
   caiga el internet.
7. Ya se puede marcar.

Repita los pasos 4 y 5 en cada sede: Caja Seca, Maracaibo y Maracay.

---

# Paso 6 · Los socios

Cada socio consulta las sedes que usted le marque, desde su propio teléfono.
Ve quién llegó, a qué hora, quién faltó y el cierre de la semana. **No ve
salarios ni fotografías, y no puede modificar nada.**

1. En Supabase: **Authentication → Users → Add user**, con su correo y una
   clave. Mejor el correo real de cada uno: así recuperan la clave solos.
2. En el panel: **Más → Sedes → Socios**. Escriba ese mismo correo, su
   nombre, y marque las sedes con casillas.
3. Entrégueles el correo y la clave.

Cambiar las sedes de alguien es marcar distinto y guardar. Quitarle una sede
le cierra ese acceso en el acto.

Está explicado con detalle en `docs/FASE11_SOCIOS.md`.

Y los **horarios personalizados** —cuando alguien no hace el horario de su
sede— en `docs/FASE12_HORARIOS.md`.

---

# Lo que a partir de ahí pasa solo

| Cuándo | Qué |
|---|---|
| Cada noche, 00:40 | Se cierra el día, se calculan ausencias y retrasos, y se borran las fotos que cumplieron 180 días |
| Cada noche, 01:10 | Se guarda una copia completa de la base en su VPS (14 días de historial) |
| Cada vez que se cambie algo | La web, la función y la base se publican solas |

Usted no tiene que acordarse de nada de eso.

---

## Si algo sale en rojo

Los flujos escriben en castellano qué pasó y qué revisar. No hay que leer
registros técnicos: el resumen lo dice.

Y si un flujo de base de datos falla, **no dejó nada a medias**: cada archivo
se aplica en una sola transacción, así que la base queda exactamente como
estaba antes de intentarlo.

Si se atasca, pegue aquí el mensaje del resumen y lo vemos.
