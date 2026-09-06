/* =====================================================================
   PANEL · Editor por bloques y guardado automatico
   ===================================================================== */

(function () {
  'use strict';

  var contenedor = document.querySelector('[data-bloques]');
  var campoJson  = document.querySelector('[data-bloques-json]');
  var plantilla  = document.querySelector('[data-plantilla-bloques]');
  var formulario = document.querySelector('[data-editor]');

  if (!contenedor || !campoJson || !formulario) { return; }

  var CAMPOS = {
    parrafo:   [{ n: 'texto',  l: 'Texto', t: 'textarea' }],
    subtitulo: [{ n: 'texto',  l: 'Subtítulo', t: 'input' }],
    cita:      [{ n: 'texto',  l: 'Cita', t: 'textarea' }, { n: 'autor', l: 'Quién lo dijo', t: 'input' }],
    lista:     [{ n: 'items',  l: 'Un elemento por línea', t: 'textarea' }, { n: 'ordenada', l: 'Numerada', t: 'checkbox' }],
    imagen:    [{ n: 'ruta',   l: 'Ruta de la imagen', t: 'input' }, { n: 'alt', l: 'Texto alternativo', t: 'input' },
                { n: 'pie',    l: 'Pie de foto', t: 'input' }, { n: 'credito', l: 'Crédito', t: 'input' },
                { n: 'sintetica', l: 'Generada con IA', t: 'checkbox' }],
    video:     [{ n: 'video_id', l: 'Identificador del video relacionado', t: 'input' }],
    dato:      [{ n: 'valor',  l: 'Dato', t: 'input' }, { n: 'nota', l: 'Qué significa', t: 'input' },
                { n: 'fuente', l: 'Fuente', t: 'input' }],
    fuente:    [{ n: 'texto',  l: 'Texto del enlace', t: 'input' }, { n: 'url', l: 'URL', t: 'input' }],
    aviso:     [{ n: 'texto',  l: 'Aviso editorial', t: 'textarea' }],
    separador: []
  };

  var NOMBRES = {
    parrafo: 'Párrafo', subtitulo: 'Subtítulo', cita: 'Cita', lista: 'Lista',
    imagen: 'Imagen', video: 'Video', dato: 'Dato destacado', fuente: 'Fuente citada',
    aviso: 'Aviso editorial', separador: 'Separador'
  };

  function crearBloque(tipo, datos) {
    datos = datos || {};

    var bloque = document.createElement('div');
    bloque.className = 'bloque';
    bloque.setAttribute('data-tipo', tipo);

    var cabeza = document.createElement('div');
    cabeza.className = 'bloque__cabeza';

    var etiqueta = document.createElement('span');
    etiqueta.className = 'bloque__tipo';
    etiqueta.textContent = NOMBRES[tipo] || tipo;
    cabeza.appendChild(etiqueta);

    var acciones = document.createElement('div');
    acciones.className = 'bloque__acciones';
    acciones.appendChild(boton('↑', 'Subir', function () { mover(bloque, -1); }));
    acciones.appendChild(boton('↓', 'Bajar', function () { mover(bloque, 1); }));
    acciones.appendChild(boton('✕', 'Quitar', function () { bloque.remove(); sincronizar(); }, true));
    cabeza.appendChild(acciones);
    bloque.appendChild(cabeza);

    var cuerpo = document.createElement('div');
    cuerpo.className = 'bloque__cuerpo';

    (CAMPOS[tipo] || []).forEach(function (campo) {
      var envoltura = document.createElement('div');
      envoltura.className = 'campo';

      var etiquetaCampo = document.createElement('label');
      etiquetaCampo.textContent = campo.l;

      var control;
      if (campo.t === 'textarea') {
        control = document.createElement('textarea');
        control.value = campo.n === 'items' && Array.isArray(datos.items)
          ? datos.items.join('\n')
          : (datos[campo.n] || '');
      } else if (campo.t === 'checkbox') {
        control = document.createElement('input');
        control.type = 'checkbox';
        control.checked = !!datos[campo.n];
        envoltura.className = 'campo campo--casilla';
      } else {
        control = document.createElement('input');
        control.type = 'text';
        control.value = datos[campo.n] || '';
      }

      control.setAttribute('data-campo', campo.n);
      control.addEventListener('input', sincronizar);
      control.addEventListener('change', sincronizar);

      if (campo.t === 'checkbox') {
        envoltura.appendChild(control);
        envoltura.appendChild(etiquetaCampo);
      } else {
        envoltura.appendChild(etiquetaCampo);
        envoltura.appendChild(control);
      }
      cuerpo.appendChild(envoltura);
    });

    bloque.appendChild(cuerpo);
    return bloque;
  }

  function boton(texto, titulo, alPulsar, peligro) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'mini-boton' + (peligro ? ' mini-boton--peligro' : '');
    b.textContent = texto;
    b.title = titulo;
    b.setAttribute('aria-label', titulo);
    b.addEventListener('click', alPulsar);
    return b;
  }

  function mover(bloque, direccion) {
    var hermano = direccion < 0 ? bloque.previousElementSibling : bloque.nextElementSibling;
    if (!hermano) { return; }
    if (direccion < 0) { contenedor.insertBefore(bloque, hermano); }
    else { contenedor.insertBefore(hermano, bloque); }
    sincronizar();
  }

  function sincronizar() {
    var bloques = [];

    contenedor.querySelectorAll('.bloque').forEach(function (bloque) {
      var tipo = bloque.getAttribute('data-tipo');
      var dato = { tipo: tipo };

      bloque.querySelectorAll('[data-campo]').forEach(function (control) {
        var nombre = control.getAttribute('data-campo');
        if (control.type === 'checkbox') {
          dato[nombre] = control.checked;
        } else if (nombre === 'items') {
          dato.items = control.value.split('\n').filter(function (l) { return l.trim() !== ''; });
        } else {
          dato[nombre] = control.value;
        }
      });

      bloques.push(dato);
    });

    campoJson.value = JSON.stringify(bloques);
    marcarSucio();
  }

  /* Bloques existentes */
  if (plantilla) {
    var existentes = [];
    try { existentes = JSON.parse(plantilla.innerHTML.trim() || '[]'); } catch (e) { existentes = []; }
    existentes.forEach(function (dato) {
      contenedor.appendChild(crearBloque(dato.tipo || 'parrafo', dato));
    });
  }

  if (!contenedor.children.length) {
    contenedor.appendChild(crearBloque('parrafo', {}));
  }
  sincronizar();

  document.querySelectorAll('[data-agregar-bloque]').forEach(function (b) {
    b.addEventListener('click', function () {
      contenedor.appendChild(crearBloque(b.getAttribute('data-agregar-bloque'), {}));
      sincronizar();
    });
  });

  /* -------------------------------------------------------------------
     Guardado automatico. Solo en piezas ya creadas: una pieza nueva se
     guarda por primera vez a mano, para que su URL sea una decision.
     ------------------------------------------------------------------- */
  var idArticulo = formulario.getAttribute('data-articulo-id');
  var indicador  = document.querySelector('[data-autoguardado]');
  var sucio      = false;
  var guardando  = false;

  function marcarSucio() {
    sucio = true;
    if (indicador && idArticulo) { indicador.textContent = 'Cambios sin guardar'; }
  }

  formulario.addEventListener('input', marcarSucio);
  formulario.addEventListener('submit', function () { sucio = false; });

  if (idArticulo) {
    window.setInterval(function () {
      if (!sucio || guardando) { return; }
      guardando = true;
      if (indicador) { indicador.textContent = 'Guardando...'; }

      var datos = new FormData(formulario);

      fetch('/panel/noticias/' + idArticulo + '/autoguardar', {
        method: 'POST',
        body: datos,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          guardando = false;
          if (d.ok) {
            sucio = false;
            if (indicador) { indicador.textContent = 'Guardado automático · ' + (d.hora || ''); }
          } else if (indicador) {
            indicador.textContent = 'No se pudo guardar solo. Guarda a mano.';
          }
        })
        .catch(function () {
          guardando = false;
          if (indicador) { indicador.textContent = 'Sin conexión. Guarda a mano.'; }
        });
    }, 45000);
  }

  window.addEventListener('beforeunload', function (evento) {
    if (!sucio) { return; }
    evento.preventDefault();
    evento.returnValue = '';
  });
})();
