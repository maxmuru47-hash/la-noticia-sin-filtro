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
