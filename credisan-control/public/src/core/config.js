/* Configuración del cliente. Único punto de lectura de env.js:
   ningún otro módulo toca window.CREDISAN_ENV directamente. */

const env = window.CREDISAN_ENV || {};

export const config = {
  supabaseUrl:     (env.supabaseUrl     || '').trim().replace(/\/+$/, ''),
  supabaseAnonKey: (env.supabaseAnonKey || '').trim(),
  // Llave pública del modo sin conexión. Es pública de verdad: sólo sirve
  // para CERRAR el sobre del PIN, nunca para abrirlo. Si falta, el
  // terminal funciona igual mientras haya red, y lo dice si no la hay.
  offlinePublicKey: (env.offlinePublicKey || '').trim(),
  version:         env.version || '1.0.0'
};

export const estaConfigurado = () =>
  config.supabaseUrl.startsWith('https://') && config.supabaseAnonKey.length > 20;

/* UN ENLACE FIRMADO TIENE QUE VIAJAR POR NUESTRO CAMINO
   ---------------------------------------------------------------------
   Este sistema habla con Supabase A TRAVÉS del VPS: `supabaseUrl` es
   algo como https://control.sinfiltroconmax.com/api, y el servidor
   reenvía desde ahí. Todo el panel y todo el terminal funcionan así.

   Pero los enlaces de las fotografías los firma Supabase, y los firma
   con SU propio dominio. Ese enlace se sale del camino, el navegador no
   llega, y la foto no se ve — mientras el resto de la pantalla funciona
   con normalidad, que es lo que hacía tan difícil de entender el fallo.

   Pasaba en los dos sitios donde firma el servidor: la fotografía de
   cada marcación en el panel, y la cara del trabajador en el terminal.
   Las que firma el navegador por su cuenta nunca tuvieron el problema,
   porque ya salían con la dirección buena.

   Aquí se le cambia el origen —y se respeta el prefijo, que es el /api
   por el que reenvía el servidor— para que vaya por donde va el resto. */
export function porNuestroCamino(url) {
  try {
    const firmada = new URL(url);
    const base = new URL(config.supabaseUrl);
    if (firmada.origin === base.origin) return url;
    return base.origin + base.pathname.replace(/\/+$/, '') + firmada.pathname + firmada.search;
  } catch {
    return url;                       // si no se puede leer, se deja igual
  }
}
