/* =====================================================================
   CREDISAN CONTROL · Configuración
   =====================================================================
   ESTE ES EL ÚNICO ARCHIVO QUE HAY QUE EDITAR.

   Mientras esté vacío, la aplicación funciona igual y avisa que falta
   conectar el servidor. Cuando cree el proyecto en Supabase, pegue los
   dos valores entre las comillas y guarde. Nada más.

   Dónde salen los valores:
     supabase.com → su proyecto → Settings → API
       · Project URL   →  supabaseUrl
       · anon public   →  supabaseAnonKey

   La clave `anon` está pensada para el navegador: es segura aquí.
   La clave `service_role` NO se pega nunca en este archivo.

   El tercer valor, `offlinePublicKey`, es para marcar sin conexión. Se
   genera una sola vez con:

       node supabase/functions/generar-llaves.mjs

   Ese comando imprime dos llaves: la pública se pega aquí abajo, y la
   privada va en los secretos de Supabase. También es pública de verdad:
   sólo sirve para CERRAR el sobre del PIN, nunca para abrirlo. Déjela
   vacía si todavía no va a usar el modo sin conexión; el terminal
   funciona igual mientras haya red.
   ===================================================================== */
window.CREDISAN_ENV = {
  supabaseUrl:      '',
  supabaseAnonKey:  '',
  offlinePublicKey: '',
  version:          '1.0.0'
};
