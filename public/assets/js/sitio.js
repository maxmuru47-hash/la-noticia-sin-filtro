/* =====================================================================
   LA NOTICIA SIN FILTRO · Comportamiento del sitio publico

   Todo lo de este archivo es progresivo: si no se ejecuta, la web sigue
   leyendose entera. El servidor ya entrego el contenido.
   ===================================================================== */

(function () {
  'use strict';

  var reduceMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* -------------------------------------------------------------------
     Menu en pantallas pequenas
     ------------------------------------------------------------------- */
  var botonMenu = document.querySelector('[data-menu-boton]');
  var navegacion = document.querySelector('[data-navegacion]');

  if (botonMenu && navegacion) {
    botonMenu.addEventListener('click', function () {
      var abierto = navegacion.classList.toggle('abierta');
      botonMenu.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    });
  }

  /* -------------------------------------------------------------------
     Entradas por observador. Sin JavaScript el contenido ya es visible:
     la clase .entra solo se aplica desde aqui.
     ------------------------------------------------------------------- */
  var animables = document.querySelectorAll('[data-entra]');

  if (animables.length && 'IntersectionObserver' in window && !reduceMovimiento.matches) {
    animables.forEach(function (el) { el.classList.add('entra'); });

    var observador = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (entrada) {
        if (!entrada.isIntersecting) { return; }
        var el = entrada.target;
        el.classList.add('dentro');
        // Retirar el desfase cuando la entrada termina, o cada hover
        // posterior arrastraria el retardo para siempre.
        window.setTimeout(function () { el.classList.add('asentado'); }, 900);
        observador.unobserve(el);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    animables.forEach(function (el) { observador.observe(el); });
  }

  /* Pausa global de animaciones con la pestana oculta. */
  document.addEventListener('visibilitychange', function () {
    document.body.classList.toggle('pausado', document.hidden);
  });

  /* -------------------------------------------------------------------
     Barra de progreso de lectura y eventos de profundidad
     ------------------------------------------------------------------- */
  var relleno = document.querySelector('[data-progreso]');
  var articulo = document.querySelector('[data-articulo-id]');

  if (relleno || articulo) {
    var marcado50 = false;
    var marcado90 = false;
    var idArticulo = articulo ? parseInt(articulo.getAttribute('data-articulo-id'), 10) : null;
    var tictac = null;

    var alScroll = function () {
      if (tictac) { return; }
      tictac = window.requestAnimationFrame(function () {
        tictac = null;

        var altura = document.documentElement.scrollHeight - window.innerHeight;
        var avance = altura > 0 ? Math.min(1, window.scrollY / altura) : 0;

        if (relleno) { relleno.style.width = (avance * 100).toFixed(1) + '%'; }

        if (idArticulo) {
          if (!marcado50 && avance >= 0.5) { marcado50 = true; registrar('lectura_50', idArticulo); }
          if (!marcado90 && avance >= 0.9) { marcado90 = true; registrar('lectura_90', idArticulo); }
        }
      });
    };

    window.addEventListener('scroll', alScroll, { passive: true });
    alScroll();
  }

  /* -------------------------------------------------------------------
     Analitica propia. Sin terceros, sin datos personales.
     ------------------------------------------------------------------- */
  function registrar(evento, id, tipo) {
    var cuerpo = new FormData();
    cuerpo.append('evento', evento);
    if (id) { cuerpo.append('id', String(id)); }
    cuerpo.append('tipo', tipo || 'articulo');

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/evento', cuerpo);
    } else {
      fetch('/api/evento', { method: 'POST', body: cuerpo, credentials: 'same-origin', keepalive: true })
        .catch(function () { /* la analitica nunca rompe la lectura */ });
    }
  }

  /* -------------------------------------------------------------------
     Modos de profundidad: 60 segundos, 5 minutos, Sin Filtro.
     Los tres viven en la MISMA URL. Nunca se duplica la direccion.
     ------------------------------------------------------------------- */
  var selectorModos = document.querySelector('[data-profundidad]');

  if (selectorModos) {
    var botones = selectorModos.querySelectorAll('[data-modo]');
    var capas   = document.querySelectorAll('[data-capa]');
    var idPieza = articulo ? parseInt(articulo.getAttribute('data-articulo-id'), 10) : null;

    var aplicarModo = function (modo, avisar) {
      botones.forEach(function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-modo') === modo ? 'true' : 'false');
      });
      capas.forEach(function (c) {
        c.hidden = c.getAttribute('data-capa') !== modo;
      });
      if (avisar && idPieza) { registrar('modo_profundidad', idPieza); }
      try { window.sessionStorage.setItem('lnsf_modo', modo); } catch (e) { /* modo privado */ }
    };

    botones.forEach(function (b) {
      b.addEventListener('click', function () { aplicarModo(b.getAttribute('data-modo'), true); });
    });

    var guardado = null;
    try { guardado = window.sessionStorage.getItem('lnsf_modo'); } catch (e) { /* sin almacenamiento */ }
    aplicarModo(guardado || 'sinfiltro', false);
  }

  /* Capas de contexto: fuentes, cronologia, perspectivas. */
  document.querySelectorAll('[data-capa-evento]').forEach(function (detalle) {
    detalle.addEventListener('toggle', function () {
      if (!detalle.open) { return; }
      var id = articulo ? parseInt(articulo.getAttribute('data-articulo-id'), 10) : null;
      registrar(detalle.getAttribute('data-capa-evento'), id);
    });
  });

  /* -------------------------------------------------------------------
     PULSO
     ------------------------------------------------------------------- */
  document.querySelectorAll('[data-pulso]').forEach(function (formulario) {
    formulario.addEventListener('submit', function (evento) {
      evento.preventDefault();

      var opcion = formulario.querySelector('input[name="opcion"]:checked');
      var aviso  = formulario.querySelector('[data-respuesta]');

      if (!opcion) {
        mostrar(aviso, 'Elige una opción para registrar tu respuesta.', 'error');
        return;
      }

      var boton = formulario.querySelector('button[type="submit"]');
      if (boton) { boton.disabled = true; boton.textContent = 'Registrando...'; }

      fetch(formulario.action, {
        method: 'POST',
        body: new FormData(formulario),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, datos: d }; }); })
        .then(function (respuesta) {
          if (boton) { boton.disabled = false; boton.textContent = 'Registrar mi respuesta'; }

          if (!respuesta.ok) {
            mostrar(aviso, respuesta.datos.error || 'No se pudo registrar tu respuesta.', 'error');
            if (respuesta.datos.resultados) { pintarResultados(formulario, respuesta.datos.resultados); }
            return;
          }

          mostrar(aviso, 'Respuesta registrada. Gracias.', 'ok');
          pintarResultados(formulario, respuesta.datos.resultados, respuesta.datos.cambio);
        })
        .catch(function () {
          if (boton) { boton.disabled = false; boton.textContent = 'Registrar mi respuesta'; }
          mostrar(aviso, 'No hay conexión ahora mismo. Inténtalo en un momento.', 'error');
        });
    });
  });

  function pintarResultados(formulario, resultados, cambio) {
    if (!resultados) { return; }

    var contenedor = document.querySelector('[data-pulso-resultados="' + formulario.getAttribute('data-pulso') + '"]');
    if (!contenedor) { return; }

    var etapa = formulario.querySelector('input[name="etapa"]');
    var esInformado = etapa && etapa.value === 'informado';

    var html = '';
    resultados.opciones.forEach(function (opcion) {
      var pct = esInformado ? opcion.informado_pct : opcion.inicial_pct;
      html += '<div class="pulso__fila">'
        + '<div class="pulso__fila-cabeza"><span></span><strong>' + pct.toFixed(1) + '%</strong></div>'
        + '<div class="pulso__pista"><div class="pulso__barra' + (esInformado ? ' pulso__barra--informado' : '') + '" style="width:' + pct + '%"></div></div>'
        + '</div>';
      // El texto de la opcion se inserta como texto, nunca como HTML.
      var temporal = document.createElement('div');
      temporal.innerHTML = html;
      var ultimo = temporal.querySelectorAll('.pulso__fila-cabeza span');
      if (ultimo.length) { ultimo[ultimo.length - 1].textContent = opcion.label; }
      html = temporal.innerHTML;
    });

    if (cambio && cambio.suficiente) {
      html += '<p class="pulso__metodologia">De quienes respondieron antes y después, '
        + cambio.porcentaje.toFixed(1) + '% cambió de postura ('
        + cambio.cambiaron + ' de ' + cambio.completaron + ' personas).</p>';
    }

    contenedor.innerHTML = html;
    contenedor.hidden = false;
    formulario.hidden = true;
  }

  /* -------------------------------------------------------------------
     PREGUNTAS DE LA COMUNIDAD Y RECORDATORIOS
     ------------------------------------------------------------------- */
  document.querySelectorAll('[data-envio-json]').forEach(function (formulario) {
    formulario.addEventListener('submit', function (evento) {
      evento.preventDefault();

      var aviso = formulario.querySelector('[data-respuesta]');
      var boton = formulario.querySelector('button[type="submit"]');
      var textoOriginal = boton ? boton.textContent : '';

      if (boton) { boton.disabled = true; boton.textContent = 'Enviando...'; }

      fetch(formulario.action, {
        method: 'POST',
        body: new FormData(formulario),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, datos: d }; }); })
        .then(function (respuesta) {
          if (boton) { boton.disabled = false; boton.textContent = textoOriginal; }
          if (respuesta.ok) {
            mostrar(aviso, respuesta.datos.mensaje || 'Recibido.', 'ok');
            formulario.reset();
          } else {
            mostrar(aviso, respuesta.datos.error || 'No se pudo enviar.', 'error');
          }
        })
        .catch(function () {
          if (boton) { boton.disabled = false; boton.textContent = textoOriginal; }
          mostrar(aviso, 'No hay conexión ahora mismo.', 'error');
        });
    });
  });

  function mostrar(nodo, mensaje, tipo) {
    if (!nodo) { return; }
    nodo.textContent = mensaje;
    nodo.className = 'respuesta-formulario respuesta-formulario--' + tipo;
    nodo.hidden = false;
  }

  /* -------------------------------------------------------------------
     REPRODUCTORES
     Nada se reproduce solo. El iframe externo se inserta solo cuando la
     persona lo pide: ningun tercero carga sin permiso.
     ------------------------------------------------------------------- */
  document.querySelectorAll('[data-reproductor]').forEach(function (marco) {
    var portada = marco.querySelector('[data-reproductor-portada]');
    if (!portada) { return; }

    portada.addEventListener('click', function () {
      var embebido = marco.getAttribute('data-embebido');
      var idVideo  = parseInt(marco.getAttribute('data-video-id'), 10);
      var propio   = marco.querySelector('video');

      if (propio) {
        portada.remove();
        propio.hidden = false;
        propio.controls = true;
        propio.play().catch(function () { /* el navegador decide */ });
      } else if (embebido) {
        var iframe = document.createElement('iframe');
        iframe.src = embebido + (embebido.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1&rel=0';
        iframe.title = marco.getAttribute('data-titulo') || 'Video';
        iframe.allow = 'accelerometer; encrypted-media; picture-in-picture; fullscreen';
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('loading', 'lazy');
        portada.replaceWith(iframe);
      }

      if (idVideo) { registrar('video_iniciado', idVideo, 'video'); }
    });
  });

  /* Capitulos: llevan el video propio al segundo indicado. */
  document.querySelectorAll('[data-capitulo]').forEach(function (enlace) {
    enlace.addEventListener('click', function (evento) {
      var marco = document.querySelector('[data-reproductor][data-video-id="' + enlace.getAttribute('data-video') + '"]');
      var video = marco ? marco.querySelector('video') : null;
      if (!video) { return; }

      evento.preventDefault();
      var portada = marco.querySelector('[data-reproductor-portada]');
      if (portada) { portada.remove(); }
      video.hidden = false;
      video.controls = true;
      video.currentTime = parseInt(enlace.getAttribute('data-capitulo'), 10) || 0;
      video.play().catch(function () { /* el navegador decide */ });
    });
  });

  /* -------------------------------------------------------------------
     BUSCADOR: envio con Enter y limpieza de parametros vacios
     ------------------------------------------------------------------- */
  var formularioBusqueda = document.querySelector('[data-formulario-busqueda]');
  if (formularioBusqueda) {
    formularioBusqueda.addEventListener('submit', function () {
      formularioBusqueda.querySelectorAll('select, input').forEach(function (campo) {
        if (campo.value === '' && campo.name !== 'q') { campo.disabled = true; }
      });
    });
  }

  /* -------------------------------------------------------------------
     ASISTENTE DE LA REDACCION

     Sin JavaScript el formulario lleva al buscador y la persona encuentra
     lo mismo por otro camino. Con JavaScript, se contesta aqui mismo.

     Nada de lo que devuelve el servidor se inserta como HTML: se escapa
     y solo despues se permiten las negritas que el propio servidor marca.
     Un asistente que pinta HTML ajeno es una puerta abierta.
     ------------------------------------------------------------------- */
  var asistente = document.querySelector('[data-asistente]');
  if (asistente) {
    var hilo       = asistente.querySelector('[data-hilo]');
    var formulario = asistente.querySelector('[data-formulario]');
    var entrada    = asistente.querySelector('[data-entrada]');
    var atajos     = asistente.querySelector('[data-atajos]');
    var enviando   = false;

    function escapar(texto) {
      var d = document.createElement('div');
      d.textContent = texto == null ? '' : String(texto);
      return d.innerHTML;
    }

    // El servidor marca énfasis con dos asteriscos. Se aplica DESPUES de
    // escapar, asi que lo unico que puede producir es <strong>.
    function conNegritas(texto) {
      return escapar(texto).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    }

    function turno(clase) {
      var div = document.createElement('div');
      div.className = 'asistente__turno asistente__turno--' + clase;
      hilo.appendChild(div);
      hilo.scrollTop = hilo.scrollHeight;
      return div;
    }

    function decir(clase, texto, piezas) {
      var div = turno(clase);
      var p = document.createElement('p');
      p.className = 'asistente__texto';
      p.innerHTML = conNegritas(texto);
      div.appendChild(p);

      if (piezas && piezas.length) {
        var lista = document.createElement('ul');
        lista.className = 'asistente__piezas';
        piezas.forEach(function (pieza) {
          var li = document.createElement('li');
          li.className = 'asistente__pieza';
          var a = document.createElement('a');
          a.href = pieza.url;                 // la arma el servidor, no la persona
          a.textContent = pieza.titulo;
          if (pieza.fecha) {
            var span = document.createElement('span');
            span.className = 'asistente__pieza-fecha';
            span.textContent = pieza.fecha.slice(0, 10);
            a.appendChild(span);
          }
          li.appendChild(a);
          lista.appendChild(li);
        });
        div.appendChild(lista);
      }

      hilo.scrollTop = hilo.scrollHeight;
      return div;
    }

    function preguntar(texto) {
      if (enviando || !texto) { return; }
      enviando = true;

      decir('persona', texto);
      var espera = turno('casa');
      espera.innerHTML = '<p class="asistente__pensando">Buscando…</p>';

      var datos = new FormData();
      datos.append('pregunta', texto);
      var token = formulario.querySelector('input[name="_token"]');
      if (token) { datos.append('_token', token.value); }

      fetch('/api/asistente', { method: 'POST', body: datos, headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (datos) {
          espera.remove();
          if (!datos) {
            decir('casa', 'No pude responder ahora mismo. Prueba el buscador de abajo.');
            return;
          }
          if (datos.ok === false) {
            decir('casa', datos.error || 'No pude responder ahora mismo.');
            return;
          }
          decir('casa', datos.respuesta || '', datos.piezas);
        })
        .catch(function () {
          espera.remove();
          decir('casa', 'Se cortó la conexión. Prueba otra vez, o usa el buscador de abajo.');
        })
        .then(function () {
          enviando = false;
          entrada.focus();
        });
    }

    formulario.addEventListener('submit', function (evento) {
      var texto = entrada.value.trim();
      if (!texto) { return; }
      evento.preventDefault();
      entrada.value = '';
      preguntar(texto);
    });

    if (atajos) {
      atajos.addEventListener('click', function (evento) {
        var boton = evento.target.closest('.asistente__atajo');
        if (boton) { preguntar(boton.textContent.trim()); }
      });
    }
  }

})();
