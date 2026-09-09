// =====================================================================
// CREDISAN CONTROL · Configuración del cliente
// Copie a config/env.js y complete. env.js está en .gitignore.
// Aquí sólo van datos públicos: la anon key está pensada para el
// navegador y toda la seguridad real vive en las políticas RLS.
// El dominio NO se codifica en ningún otro sitio: cambiarlo es cambiar
// este archivo.
// =====================================================================
window.CREDISAN_ENV = {
  supabaseUrl:     'https://xxxxxxxxxxxx.supabase.co',
  supabaseAnonKey: 'eyJhbGciOi...',
  appBaseUrl:      'https://control.sinfiltroconmax.com',
  // Clave pública del cifrado offline (la privada nunca sale del servidor).
  offlinePublicKey: '',
  version: '1.0.0'
};
