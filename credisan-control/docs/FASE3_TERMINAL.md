# Fase 3 · Poner a marcar los terminales

Seis pasos. Los tres primeros se hacen una sola vez; los tres últimos, una
vez por sede.

---

## 1 · Actualizar la base de datos *(2 min)*

Supabase → **SQL Editor** → **New query** → pegue **`ACTUALIZAR-FASE3.sql`**
entero → **Run** → acepte el aviso de operaciones destructivas.

> Ese aviso sale por los `revoke`, que son justamente los que cierran la
> puerta de las funciones nuevas a todo el mundo menos al servidor.

No borra nada: sus sedes, su personal y sus horarios quedan igual.

## 2 · Publicar la función del servidor

Es la pieza que separa el teléfono del mostrador de la base de datos.

### Lo normal: que se publique sola *(5 min, una vez)*

Hay un flujo de GitHub que la publica, la configura y comprueba que
responde. Se configura una sola vez y después no se vuelve a tocar:
**`docs/DESPLIEGUE_AUTOMATICO.md`**, sección «La función del servidor,
también automática».

Ese camino además crea los secretos del paso 3 solo, así que si lo usa
puede saltarse el paso siguiente.

### A mano, si lo prefiere

1. Menú izquierdo → **Edge Functions**
2. **Deploy a new function** → **Via Editor**
3. **Nombre:** `credisan` — exactamente así, en minúsculas
4. Borre el ejemplo que trae y pegue el contenido de
   **`supabase/functions/credisan/index.ts`**
5. **Desactive «Verify JWT»** *(importante — ver abajo)*
6. **Deploy**

### Por qué se desactiva «Verify JWT»

Esa opción exige que cada llamada traiga un token de sesión de Supabase. El
terminal no tiene sesión y no debe tenerla: es un teléfono en un mostrador,
y darle credenciales de usuario sería regalar acceso a quien lo desbloquee.

La función hace su propia comprobación, que es más estricta:

- **Marcar** exige el `device_token` del dispositivo emparejado. Sin él, 401.
- **Generar un PIN** exige el token de la persona que lo pide, y además
  pregunta a la base de datos si esa persona manda en esa sede.
- **Emparejar** exige un código de 8 caracteres que vive 10 minutos y se usa
  una sola vez.

## 3 · Guardar el secreto del PIN *(2 min)*

**Edge Functions → Secrets** → **Add new secret**

| Campo | Valor |
|---|---|
| Name | `CREDISAN_PIN_PEPPER` |
| Value | una cadena larga y aleatoria, mínimo 40 caracteres |

Para generarla, en el SQL Editor:

```sql
select encode(gen_random_bytes(36), 'base64');
```

Copie el resultado y péguelo como valor del secreto.

> **Guárdelo también fuera de Supabase**, en el gestor de claves de la empresa.
>
> Este es el dato que hace que un volcado robado de la base de datos no sirva
> para recuperar ningún PIN: la base guarda el resultado de mezclar el PIN con
> esta cadena, pero nunca la cadena. **Si se pierde o se cambia, hay que
> regenerar todos los PIN.** No hay forma de recuperarlo.

## 4 · Actualizar la web *(3 min)*

Suba el ZIP nuevo como siempre: `public_html` → borre la carpeta `control` →
suba el ZIP → **Extract** con nombre de carpeta `control` → borre el ZIP.

## 5 · Dar los PIN *(1 min por trabajador)*

Panel → **Personal** → botón **Dar PIN** en cada ficha.

El PIN aparece en pantalla **una sola vez**. Anótelo, entrégueselo a la persona
y cierre la tarjeta. No se puede volver a consultar: ni usted, ni el sistema,
ni nadie. Si se pierde, se genera otro y el anterior deja de servir en el acto.

## 6 · Vincular el teléfono de cada sede *(3 min por sede)*

**En su panel:**

Sedes → sección **Terminales de marcación** → botón **Vincular** en el terminal
que toque. Sale un código de 8 caracteres que vale 10 minutos.

**En el teléfono de la sede:**

1. Abra `https://control.sinfiltroconmax.com/kiosk/`
2. Escriba el terminal (`MCB-01`, `CSS-01` o `MCY-01`), el código y un nombre
   para el aparato
3. **Vincular**
4. Acepte el permiso de cámara cuando lo pida
5. Menú **⋮** → **Instalar aplicación**, para que quede como app a pantalla
   completa

A partir de ahí el teléfono ya no pide nada más. Se queda en la pantalla del
teclado, esperando.

---

## El día a día

El trabajador llega, teclea sus seis dígitos, ve su nombre y qué va a marcar,
confirma, la cámara toma la foto y aparece el resultado en verde, ámbar o rojo.
Menos de diez segundos.

**Lo que el trabajador no puede tocar:** la hora la pone el servidor, la sede la
impone el terminal, y si le toca entrada o salida lo decide el calendario. No
hay nada que elegir, y por eso no hay nada que manipular.

**Si falla la cámara** —permiso denegado, lente tapada, aparato viejo— la
marcación **se registra igual** y el sistema abre una novedad automática para
que administración lo revise. Nunca se deja a alguien sin marcar por una foto.

## Si algo va mal

| Lo que dice el terminal | Qué pasa |
|---|---|
| «Este dispositivo ya no está vinculado» | Lo desvincularon desde el panel. Genere un código nuevo |
| «PIN incorrecto» | Se equivocó. Tras varios fallos seguidos el terminal responde más lento, pero **nunca se bloquea**: nadie puede dejar sin marcar a sus compañeros |
| «Su PIN pertenece a otra sede» | Está marcando en la sede equivocada. Queda registrado como novedad |
| «No hay marcaciones pendientes» | Ya completó su jornada, o hoy es su día de descanso |
| «Se acabó el tiempo» | Tardó más de un minuto en confirmar. Vuelva a teclear el PIN |
| «Falta configurar el servidor» | Falta el secreto del paso 3 |

## Si se pierde un teléfono

Panel → Sedes → Terminales → **Desvincular**.

La credencial del aparato deja de servir en ese mismo instante. Las marcaciones
que ya registró no se tocan: siguen ahí, con su hora y su foto.
