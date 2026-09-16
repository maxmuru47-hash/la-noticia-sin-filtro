# Fase 6 · Marcar sin conexión

Tres pasos. El primero en Supabase, los otros dos son las llaves.

---

## El problema que resuelve

Se va el internet en la sede. La gente sigue llegando y sigue teniendo que
marcar. Hasta ahora, el terminal decía «sin conexión» y no había nada que
hacer: o se apuntaba a mano en un papel, o esa hora se perdía.

Desde esta fase, el terminal **guarda la marcación y la envía solo** cuando
vuelve la señal. El trabajador marca igual, ve que quedó guardada, y se va.

---

## 1 · Actualizar la base de datos *(2 min)*

Supabase → **SQL Editor** → **New query** → pegue **`ACTUALIZAR-FASE6.sql`**
entero → **Run** → acepte el aviso de operaciones destructivas.

> Ese aviso sale por los `revoke`, que son los que cierran la puerta nueva
> a todo el mundo menos al servidor.

---

## 2 · Generar las llaves *(3 min)*

Aquí hay algo que entender, y es el corazón de esta fase.

Sin internet, **el terminal no puede comprobar un PIN**: la clave secreta
que lo valida vive en el servidor y nunca baja al aparato. Y guardar el PIN
tal cual mientras espera sería regalarle el PIN de toda la sede a cualquiera
que se lleve la tableta.

La solución: el terminal mete el PIN en un **sobre cerrado** que sólo el
servidor puede abrir. El aparato guarda algo que **ni él mismo puede leer**.

Para eso hacen falta dos llaves. En su computador, dentro de la carpeta del
proyecto:

```
node supabase/functions/generar-llaves.mjs
```

Imprime dos textos largos:

| Llave | Dónde va | ¿Se puede enseñar? |
|---|---|---|
| **Pública** | `public/config/env.js`, como `offlinePublicKey` | Sí. Sólo sirve para **cerrar** sobres |
| **Privada** | Supabase → Edge Functions → Secrets, como `CREDISAN_OFFLINE_PRIVATE_KEY` | **No.** Nunca. Ni por chat |

> Si pierde la privada, las marcaciones que estén esperando en un terminal
> no se podrán recuperar. Guárdela donde guarda lo importante.

---

## 3 · Pegar la pública en el servidor *(2 min)*

El despliegue automático **no toca** `config/env.js` a propósito, para no
borrarle sus claves. Así que esa línea hay que añadirla en el servidor:

En el VPS, el archivo está en
`/opt/proyectos/credisan-control/public/config/env.js`. Queda así:

```js
window.CREDISAN_ENV = {
  supabaseUrl:      'https://....supabase.co',
  supabaseAnonKey:  '....',
  offlinePublicKey: 'MFkwEwYHKoZIzj0CAQYIKoZ....',
  version:          '1.0.0'
};
```

Después, en el terminal: cerrar la aplicación y volver a abrirla.

---

## Qué ve el trabajador

Igual que siempre: marca su PIN. La diferencia es la pantalla final.

**Con internet** — su nombre, su foto y si llegó a tiempo.

**Sin internet** — una pantalla naranja que dice:

> **Marcación guardada** · 08:14
> **SIN CONEXIÓN**
> *Se enviará sola cuando vuelva la señal. Si su PIN no fuera correcto, no
> quedará registrada.*

Esa última frase está puesta a propósito y no se debe quitar. Sin conexión
el sistema **no puede** saber si el PIN es correcto. Decir «listo, marcado»
sería mentirle a alguien que se iría tranquilo creyendo que marcó.

Arriba de la pantalla del teclado aparece un aviso discreto — *«3
marcaciones por enviar»* — que desaparece solo cuando se envían.

---

## Qué ve administración

**Más → Terminales y conexión**.

Cada terminal con su estado:

| Etiqueta | Significa |
|---|---|
| **Al día** | Se supo de él hace menos de un día |
| **Sin señal** | Lleva más de 24 horas callado. Esto es lo que hay que mirar |
| **Sin vincular** | Nunca se emparejó |

Y debajo, las marcaciones que llegaron sin conexión, con la hora que puso
el aparato. Valen igual que las demás; se listan aparte porque conviene
poder revisarlas.

Si algo llegó y fue rechazado, se explica por qué, en castellano:
*«Reenvío de algo ya recibido»*, *«Reloj del aparato adelantado»*, *«PIN
incorrecto»*.

---

## Lo que el sistema NO deja hacer

Esto es lo que hace que una marcación sin conexión valga tanto como una en
directo:

| Intento | Qué pasa |
|---|---|
| Mandar dos veces la misma marcación | Se detecta y no se duplica |
| Reenviar una vieja como si fuera nueva | Rechazada: la secuencia no puede retroceder |
| Atrasar el reloj de la tableta para salir puntual | Se registra, se mide el desfase y **se abre una novedad** para que administración lo mire |
| Marcar con fecha futura | Rechazada |
| Guardar marcaciones cuatro días y soltarlas | Rechazadas: la ventana es de **72 horas**, configurable |
| Marcar por alguien de otra sede | Rechazada |
| Probar PIN al azar en la tableta robada | Cada intento queda registrado y dispara la alerta de fuerza bruta |

Cada rechazo queda en la auditoría con su motivo.

---

## Un detalle que quizá le sorprenda

**La hora que cuenta es la del aparato, no la de cuando llegó al servidor.**

Es lo correcto: si alguien marcó a las 8:14 y la señal volvió a las 11:00,
su hora de entrada son las 8:14. Por eso importa tanto el control del
reloj: el terminal no usa la hora del sistema —que se puede cambiar a
mano— sino una calculada desde la última vez que habló con el servidor,
más un contador que nadie puede mover.

La diferencia entre las dos se mide y se manda. Si es grande, la marcación
entra igual pero con una novedad abierta.

---

## Si no hace los pasos 2 y 3

No pasa nada malo: el terminal funciona exactamente como antes, marcando
con internet. Si se va la señal, dirá que no puede marcar sin conexión y
que avisen a administración. El modo sin conexión simplemente queda
apagado hasta que existan las llaves.
