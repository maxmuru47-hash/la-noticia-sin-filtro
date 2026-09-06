/* =====================================================================
   HERO CINEMATOGRAFICO CONTROLADO POR DESPLAZAMIENTO
   Exclusivo de la portada.

   Leyes que este archivo respeta:
   - Las CINCO COMPUERTAS del hero estatico son identicas, caracter por
     caracter, a las del CSS. Si divergen, un lado carga lo que el otro
     esconde.
   - La decision se toma EN VIVO: al rotar, redimensionar o cambiar la
     preferencia de movimiento, el scrub se arma o se desarma.
   - Progreso de carga honesto: se muestra lo que de verdad ha llegado.
   - Si el video falla, la portada queda completa con la imagen.
   ===================================================================== */

(function () {
  'use strict';

  var escenario = document.querySelector('[data-hero]');
  if (!escenario) { return; }

  var video    = escenario.querySelector('[data-hero-video]');
  var carga    = escenario.querySelector('[data-hero-carga]');
  var barra    = escenario.querySelector('[data-hero-barra]');
  var pista    = escenario.querySelector('[data-hero-pista]');
  /* Se ofrecen dos fuentes y se elige la que el navegador declara poder
     reproducir. El MP4 H.264 es el universal en navegadores reales; el
     WebM VP9 cubre las compilaciones sin codecs propietarios, donde el
     MP4 falla con DEMUXER_ERROR_NO_SUPPORTED_STREAMS. */
  var fuente = elegirFuente(
    escenario.getAttribute('data-hero-fuente'),
    escenario.getAttribute('data-hero-fuente-webm')
  );

  if (!video || !fuente) { return; }

  function elegirFuente(mp4, webm) {
    var prueba = document.createElement('video');

    if (mp4 && prueba.canPlayType('video/mp4; codecs="avc1.42E01E"') !== '') {
      return { url: mp4, tipo: 'video/mp4' };
    }
    if (webm && prueba.canPlayType('video/webm; codecs="vp9"') !== '') {
      return { url: webm, tipo: 'video/webm' };
    }
    if (mp4 && prueba.canPlayType('video/mp4') !== '') {
      return { url: mp4, tipo: 'video/mp4' };
    }
    return null;
  }

  /* LAS CINCO COMPUERTAS. Copia exacta de componentes.css. */
  var COMPUERTAS = [
    '(max-width: 720px)',
    '(orientation: portrait) and (max-width: 1024px)',
    '(orientation: portrait) and (pointer: coarse)',
    '(orientation: landscape) and (pointer: coarse) and (max-height: 560px)',
    '(prefers-reduced-motion: reduce)'
  ];

  var scrubActivo = false;
  var iniciado    = false;
  var objetivo    = 0;
  var mostrado    = 0;
  var buscando    = false;
  var pendiente   = null;
  var rafId       = null;
  var duracion    = 0;
  var objeto      = null;

  /* -------------------------------------------------------------------
     Carga como Blob, con progreso real.
     El video se descarga entero antes de permitir el barrido: buscar
     dentro de un archivo servido por rangos produce saltos y bloqueos.
     ------------------------------------------------------------------- */
  function cargarVideo() {
    if (iniciado) { return; }
    iniciado = true;

    if (carga) { carga.hidden = false; }

    fetch(fuente.url, { credentials: 'same-origin' })
      .then(function (respuesta) {
        if (!respuesta.ok || !respuesta.body) { throw new Error('respuesta no valida'); }

        var total    = parseInt(respuesta.headers.get('Content-Length') || '0', 10);
        var recibido = 0;
        var trozos   = [];
        var lector   = respuesta.body.getReader();

        function leer() {
          return lector.read().then(function (resultado) {
            if (resultado.done) { return new Blob(trozos, { type: fuente.tipo }); }
            trozos.push(resultado.value);
            recibido += resultado.value.length;
            if (total > 0 && barra) {
              barra.style.width = Math.min(100, Math.round((recibido / total) * 100)) + '%';
            }
            return leer();
          });
        }
        return leer();
      })
      .then(function (blob) {
        objeto = URL.createObjectURL(blob);
        video.src = objeto;
        video.load();
      })
      .catch(fallo);
  }

  /* Si el video no llega, se retira y queda la portada estatica. */
  function fallo() {
    if (objeto) { URL.revokeObjectURL(objeto); objeto = null; }
    video.remove();
    if (carga) { carga.hidden = true; }
    if (pista) { pista.hidden = true; }
    escenario.classList.add('hero--estatico');
    desarmar();
  }

  video.addEventListener('error', fallo);

  video.addEventListener('loadedmetadata', function () {
    duracion = video.duration || 0;
    video.classList.add('listo');
    if (carga) { carga.hidden = true; }
    alDesplazar();
  });

  /* -------------------------------------------------------------------
     Progreso del hero: 0 al entrar, 1 cuando el escenario termina.
     ------------------------------------------------------------------- */
  function progreso() {
    var caja    = escenario.getBoundingClientRect();
    var recorre = caja.height - window.innerHeight;
    if (recorre <= 0) { return 0; }
    var avance = -caja.top / recorre;
    return Math.max(0, Math.min(1, avance));
  }

  /* -------------------------------------------------------------------
     Interpolacion del tiempo mostrado. El bucle descansa cuando el
     video ya alcanzo su objetivo: no se deja un rAF girando en vacio.
     ------------------------------------------------------------------- */
  function bucle() {
    rafId = null;
    if (!scrubActivo || duracion <= 0) { return; }

    mostrado += (objetivo - mostrado) * 0.12;

    if (Math.abs(objetivo - mostrado) < 0.004) {
      mostrado = objetivo;
      buscar(mostrado);
      return;
    }

    buscar(mostrado);
    rafId = requestAnimationFrame(bucle);
  }

  /* Las busquedas se encolan de a una. Sin esto, el navegador acumula
     peticiones de seek y el video se queda congelado. */
  function buscar(tiempo) {
    if (buscando) { pendiente = tiempo; return; }
    buscando = true;
    try {
      video.currentTime = tiempo;
    } catch (e) {
      buscando = false;
    }
  }

  video.addEventListener('seeked', function () {
    buscando = false;
    if (pendiente !== null) {
      var siguiente = pendiente;
      pendiente = null;
      buscar(siguiente);
    }
  });

  function alDesplazar() {
    if (!scrubActivo || duracion <= 0) { return; }
    objetivo = progreso() * duracion;
    if (rafId === null) { rafId = requestAnimationFrame(bucle); }

    if (pista) {
      var visible = progreso() < 0.06;
      if (pista.hidden === visible) { pista.hidden = !visible; }
    }
  }

  /* -------------------------------------------------------------------
     Armado y desarmado en vivo.
     ------------------------------------------------------------------- */
  function armar() {
    if (scrubActivo) { return; }
    scrubActivo = true;
    escenario.classList.remove('hero--estatico');
    cargarVideo();
    window.addEventListener('scroll', alDesplazar, { passive: true });
    alDesplazar();
  }

  function desarmar() {
    if (!scrubActivo) { return; }
    scrubActivo = false;
    window.removeEventListener('scroll', alDesplazar);
    if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
  }

  var CONSULTAS = COMPUERTAS.map(function (q) { return window.matchMedia(q); });

  function aplicarModo() {
    var estatico = CONSULTAS.some(function (m) { return m.matches; });
    if (estatico) { desarmar(); } else { armar(); }
  }

  CONSULTAS.forEach(function (m) {
    if (m.addEventListener) { m.addEventListener('change', aplicarModo); }
    else if (m.addListener) { m.addListener(aplicarModo); }
  });

  window.addEventListener('resize', aplicarModo, { passive: true });

  /* Con la pestana oculta no se gasta ni un fotograma. */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
    } else if (scrubActivo) {
      alDesplazar();
    }
  });

  aplicarModo();
})();
