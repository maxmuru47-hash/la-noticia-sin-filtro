<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <p style="font-size:var(--t-lg)">Nuestra regla de oro es que cada capa se vea sin esfuerzo:
      <strong>HECHO no es ANÁLISIS. ANÁLISIS no es OPINIÓN. OPINIÓN no es PUBLICIDAD.</strong></p>

    <h2>Cómo clasificamos cada pieza</h2>
    <ul>
      <li><strong>Noticia.</strong> Qué ocurrió, con lo confirmado separado de lo probable y de lo que todavía no se sabe.</li>
      <li><strong>Análisis.</strong> Qué significa. Interpretación identificada como tal, nunca mezclada con el hecho.</li>
      <li><strong>Opinión.</strong> Postura firmada por quien la sostiene. Siempre marcada, siempre separada.</li>
      <li><strong>Explicador.</strong> Contenido duradero que explica un asunto desde su base.</li>
      <li><strong>Verificación.</strong> Comprobación de una afirmación concreta, con fuentes abiertas.</li>
    </ul>
    <p>La clasificación aparece siempre visible en la parte superior de cada pieza, en las tarjetas de portada
      y en los resultados del buscador.</p>

    <h2>Permanencia del archivo</h2>
    <p>Ninguna pieza publicada se elimina. Estas son las reglas que la plataforma aplica por diseño:</p>
    <ul>
      <li>Cada pieza tiene una dirección permanente que no cambia al corregir el título.</li>
      <li>Archivar no es eliminar: una pieza archivada sale de portada, pero conserva su dirección
        y sigue apareciendo en el buscador, en el archivo y en sus categorías.</li>
      <li>Si una dirección cambia de forma excepcional, la anterior redirige de forma permanente
        y queda reservada para siempre. Nunca se reutiliza para otra pieza.</li>
      <li>Un retiro excepcional conserva la página explicando el motivo, salvo prohibición legal.</li>
    </ul>

    <h2>Qué no publicamos</h2>
    <ul>
      <li>Testimonios, cifras, fuentes o resultados de encuesta inventados.</li>
      <li>Titulares que prometan más de lo que la pieza sostiene.</li>
      <li>Contenido patrocinado sin identificar como tal.</li>
      <li>Resultados del Pulso manipulados para apoyar una postura. Nunca se editan.</li>
    </ul>

    <h2>Cadencia</h2>
    <p>Preferimos la disciplina y la profundidad a aparentar una redacción de gran escala.
      La frecuencia solo aumenta cuando podemos verificar, actualizar y distribuir cada pieza.</p>
  </div>
</div>
