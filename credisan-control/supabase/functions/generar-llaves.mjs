#!/usr/bin/env node
/* Genera el par de llaves del modo sin conexión.
   ---------------------------------------------------------------------
   Se ejecuta UNA SOLA VEZ, en su propio computador:

       node supabase/functions/generar-llaves.mjs

   Imprime dos llaves:

     · La PÚBLICA va en `public/config/env.js`. Puede verla cualquiera:
       sólo sirve para CERRAR sobres, nunca para abrirlos.

     · La PRIVADA va en los secretos de Edge Functions, como
       CREDISAN_OFFLINE_PRIVATE_KEY. Ésta no se enseña, no se manda por
       chat y no se guarda en el repositorio.

   Si pierde la privada, las marcaciones que estén encoladas sin enviar no
   se podrán recuperar. Guárdela donde guarda las cosas importantes.
*/

const b64 = (b) => Buffer.from(b).toString('base64');

const par = await crypto.subtle.generateKey(
  { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);

const publica = b64(await crypto.subtle.exportKey('spki',  par.publicKey));
const privada = b64(await crypto.subtle.exportKey('pkcs8', par.privateKey));

console.log(`
═══════════════════════════════════════════════════════════════════
  LLAVES DEL MODO SIN CONEXIÓN · CrediSan Control
═══════════════════════════════════════════════════════════════════

1) PÚBLICA — va en public/config/env.js

   offlinePublicKey: '${publica}',

2) PRIVADA — va en Supabase → Edge Functions → Secrets

   Nombre:  CREDISAN_OFFLINE_PRIVATE_KEY
   Valor:   ${privada}

═══════════════════════════════════════════════════════════════════
  La privada NO se sube al repositorio ni se manda por chat.
  Sin ella, una marcación encolada no se puede recuperar.
═══════════════════════════════════════════════════════════════════
`);
