/* Pantalla de estado: dice con exactitud qué falta para que el sistema
   quede en línea. Sin adornos y sin dar por bueno lo que no ha probado. */

import { config, estaConfigurado } from '../core/config.js';

const ICONOS = { ok: '✓', aviso: '!', error: '✕', espera: '·' };

function pintar(id, nivel, titulo, detalle) {
  const li = document.getElementById(id);
  if (!li) return;
  const icono = li.querySelector('.estado__icono');
  icono.dataset.e = nivel;
  icono.textContent = ICONOS[nivel];
  // Ojo: el icono también es un <span>. Hay que apuntar al bloque de texto.
  li.querySelector('.estado__texto strong').textContent = titulo;
  li.querySelector('.estado__texto span').textContent   = detalle;
}

/* 1 · Conexión segura — sin HTTPS no hay cámara ni instalación */
const seguro = location.protocol === 'https:' || location.hostname === 'localhost';
pintar('e-https', seguro ? 'ok' : 'error',
  seguro ? 'Conexión segura (HTTPS)' : 'Falta HTTPS',
  seguro ? 'La cámara del terminal podrá funcionar.'
         : 'Active «Forzar HTTPS» en hPanel. Sin esto no hay cámara ni instalación.');

/* 2 · Aplicación instalable */
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('./sw.js')
    .then(() => pintar('e-pwa', 'ok', 'Aplicación instalable',
                       'Se puede instalar en el teléfono del terminal.'))
    .catch(() => pintar('e-pwa', 'aviso', 'Instalación no disponible',
                        'El navegador rechazó el service worker.'));
} else {
  pintar('e-pwa', 'aviso', 'Instalación no disponible',
         'Este navegador no admite aplicaciones instalables.');
}

/* 3 · Configuración del servidor */
if (estaConfigurado()) {
  pintar('e-config', 'ok', 'Servidor configurado', config.supabaseUrl);
} else {
  pintar('e-config', 'aviso', 'Falta configurar el servidor',
         'Edite config/env.js con los dos datos de Supabase.');
}

/* 4 · Conexión real con Supabase — se comprueba, no se supone */
if (!estaConfigurado()) {
  pintar('e-supabase', 'espera', 'Sin comprobar',
         'Se comprobará en cuanto haya configuración.');
} else {
  pintar('e-supabase', 'espera', 'Comprobando…', config.supabaseUrl);

  const control = new AbortController();
  const tiempo = setTimeout(() => control.abort(), 8000);

  fetch(config.supabaseUrl + '/rest/v1/branches?select=code,name&order=code', {
    headers: { apikey: config.supabaseAnonKey, Accept: 'application/json' },
    signal: control.signal
  })
    .then((r) => r.json().then((cuerpo) => ({ ok: r.ok, estado: r.status, cuerpo })))
    .then(({ ok, estado, cuerpo }) => {
      if (ok && Array.isArray(cuerpo)) {
        // Sin sesión, la RLS devuelve 0 filas: eso es exactamente lo correcto.
        pintar('e-supabase', 'ok', 'Servidor conectado',
               `Respondió correctamente. Sedes visibles sin iniciar sesión: ${cuerpo.length} (debe ser 0).`);
      } else if (estado === 401 || estado === 403) {
        pintar('e-supabase', 'ok', 'Servidor conectado y protegido',
               'Rechaza el acceso sin sesión, que es el comportamiento correcto.');
      } else if (estado === 404) {
        pintar('e-supabase', 'aviso', 'Servidor conectado, sin base de datos',
               'Falta aplicar las migraciones (docs/SUPABASE_SETUP.md).');
      } else {
        pintar('e-supabase', 'error', 'El servidor respondió con un error',
               `Código ${estado}. Revise la URL y la clave anon.`);
      }
    })
    .catch((e) => pintar('e-supabase', 'error', 'No se pudo conectar',
                         e.name === 'AbortError' ? 'El servidor no respondió en 8 segundos.'
                                                 : 'Revise la URL del proyecto en config/env.js.'))
    .finally(() => clearTimeout(tiempo));
}

/* Botón de instalación: sólo aparece si el navegador lo ofrece de verdad */
let invitacion = null;
const boton = document.getElementById('instalar');

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  invitacion = e;
  if (boton) boton.hidden = false;
});

boton?.addEventListener('click', async () => {
  if (!invitacion) return;
  invitacion.prompt();
  await invitacion.userChoice;
  invitacion = null;
  boton.hidden = true;
});

document.getElementById('version').textContent = 'v' + config.version;

/* El panel sólo tiene sentido con servidor detrás: si no lo hay, se dice
   por qué en vez de dejar que el usuario choque contra una pantalla muerta. */
if (!estaConfigurado()) {
  const ir = document.getElementById('ir-panel');
  ir.classList.add('boton--secundario');
  ir.textContent = 'Entrar al panel (falta conectar el servidor)';
}
