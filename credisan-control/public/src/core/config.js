/* Configuración del cliente. Único punto de lectura de env.js:
   ningún otro módulo toca window.CREDISAN_ENV directamente. */

const env = window.CREDISAN_ENV || {};

export const config = {
  supabaseUrl:     (env.supabaseUrl     || '').trim().replace(/\/+$/, ''),
  supabaseAnonKey: (env.supabaseAnonKey || '').trim(),
  version:         env.version || '1.0.0'
};

export const estaConfigurado = () =>
  config.supabaseUrl.startsWith('https://') && config.supabaseAnonKey.length > 20;
