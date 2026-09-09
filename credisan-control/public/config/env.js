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
   ===================================================================== */
window.CREDISAN_ENV = {
  supabaseUrl:     '',
  supabaseAnonKey: '',
  version:         '1.0.0'
};
