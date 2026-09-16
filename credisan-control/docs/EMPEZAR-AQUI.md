# Empezar aquí · lo único que falta

Todo el sistema está construido, probado y publicado. Lo que queda son
**tres secretos** que sólo puede pegar usted, porque son las llaves de su
cuenta y no deben pasar por un chat.

**Tiempo: unos 10 minutos.** Después, todo lo demás va solo.

---

## Antes de empezar: tenga a mano

- Su cuenta de **supabase.com** abierta.
- Su repositorio en **github.com** abierto, en
  **Settings → Secrets and variables → Actions**.

Va a copiar tres valores de la primera a la segunda. Nada más.

---

## Secreto 1 · `SUPABASE_ACCESS_TOKEN`

Es el permiso para que GitHub publique la función del servidor.

1. En supabase.com, pulse su **foto de perfil** (arriba a la derecha).
2. **Access Tokens** → **Generate new token**.
3. Nombre: `github-credisan`. Copie el texto que aparece.
   *(Sólo se enseña una vez.)*
4. En GitHub: **New repository secret**
   · Name: `SUPABASE_ACCESS_TOKEN`
   · Secret: lo que copió.

## Secreto 2 · `SUPABASE_PROJECT_REF`

Es el nombre corto de su proyecto.

Mire la dirección de su proyecto en Supabase:

```
https://abcdefghijklm.supabase.co
        ↑─────────────↑
        esto es lo que se copia
```

En GitHub: **New repository secret**
· Name: `SUPABASE_PROJECT_REF`
· Secret: esa parte del medio.

## Secreto 3 · `SUPABASE_DB_URL`

Es la conexión a la base de datos.

1. En su proyecto de Supabase, botón **Connect** (arriba).
2. Pestaña **Session pooler** ← *importante, no «Direct connection»*
3. Copie la cadena entera. Se parece a:
   `postgresql://postgres.abcdef:[YOUR-PASSWORD]@aws-0-us-east-1.pooler.supabase.com:5432/postgres`
4. Sustituya `[YOUR-PASSWORD]` (corchetes incluidos) por la contraseña de
   su base de datos.
5. En GitHub: **New repository secret**
   · Name: `SUPABASE_DB_URL`
   · Secret: la cadena ya con su contraseña.

> **Por qué Session pooler y no Direct connection:** la conexión directa
> sólo responde por IPv6, y los servidores de GitHub no llegan hasta ahí.
> Es el motivo número uno de que esto falle.

---

## Ahora, dos botones

En GitHub, pestaña **Actions**:

1. **Migrar base CrediSan** → *Run workflow* → esperar a que quede verde.
2. **Desplegar función CrediSan** → *Run workflow* → esperar a que quede verde.

En ese orden: el primero pone al día la base, el segundo publica la función
y crea sus llaves.

Cada uno tarda menos de dos minutos y al terminar escribe un resumen en
castellano diciendo qué hizo.

---

## Cómo saber que quedó bien

Entre a **control.sinfiltroconmax.com/panel/** con su usuario:

| Debería ver | Significa que |
|---|---|
| Pestaña **Hoy** con las tres sedes | La base está en la fase 6 |
| Pestaña **Cierres** | La fase 5 está aplicada |
| **Más → Auditoría** con movimientos | La auditoría funciona |
| **Más → Terminales y conexión** | La fase 6 está aplicada |
| En **Personal**, el botón **PIN** genera un PIN de 6 dígitos | La función del servidor está publicada y funcionando |

Ese último es la prueba de fuego: si el PIN sale, **todo el circuito
funciona**, de punta a punta.

---

## Después ya puede usarlo de verdad

1. **Personal → + Nuevo** para cada trabajador de cada sede.
2. A cada uno, botón **PIN**: se enseña una vez, anótelo y entrégueselo.
3. **Más → Sedes → terminal → Vincular**: da un código de 8 caracteres
   que vive 10 minutos.
4. En el teléfono o tableta del mostrador, abra
   **control.sinfiltroconmax.com/kiosk/**, escriba el código, y ese
   aparato queda atado a esa sede para siempre.
5. Ya se puede marcar.

---

## Si algo sale en rojo

Los tres flujos escriben en castellano qué pasó y qué revisar. No hay que
leer registros técnicos: el resumen lo dice.

Y si un flujo de base de datos falla, **no dejó nada a medias**: cada
archivo se aplica en una sola transacción, así que la base queda
exactamente como estaba antes de intentarlo.

Si se atasca, pegue aquí el mensaje del resumen y lo vemos.
