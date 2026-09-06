<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <h2>Cómo se financia este medio</h2>
    <p>La Noticia SIN FILTRO forma parte del ecosistema <?= e($config['brand']['name']) ?>. Cuando una pieza
      esté patrocinada, lo dirá de forma visible antes del contenido, no en letra pequeña al final.</p>

    <h2>Reglas</h2>
    <ul>
      <li>La publicidad nunca se disfraza de contenido editorial.</li>
      <li>Un patrocinador no decide qué se publica, ni revisa una pieza antes de publicarse.</li>
      <li>Si cubrimos a una empresa con la que existe una relación comercial, lo declaramos en la propia pieza.</li>
      <li>La opinión firmada nunca se vende.</li>
    </ul>

    <h2>Conflictos de interés</h2>
    <p>Cuando quien firma una pieza tiene una relación personal, comercial o política con lo que se cubre,
      esa relación se declara dentro de la pieza.</p>

    <h2>Propuestas comerciales</h2>
    <p>Escríbenos desde <a href="/transparencia/contacto">la página de contacto</a>.</p>
  </div>
</div>
