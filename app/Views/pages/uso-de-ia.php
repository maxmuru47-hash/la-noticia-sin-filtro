<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <p style="font-size:var(--t-lg)">La inteligencia artificial nos ayuda a trabajar más rápido.
      No decide qué es verdad.</p>

    <h2>Para qué la usamos</h2>
    <ul>
      <li>Resumir y clasificar material de trabajo.</li>
      <li>Transcribir video y audio, siempre con revisión humana posterior.</li>
      <li>Proponer relaciones entre piezas y temas.</li>
      <li>Apoyo técnico en el desarrollo de esta plataforma.</li>
    </ul>

    <h2>Para qué no la usamos</h2>
    <ul>
      <li>No publica hechos sin revisión humana.</li>
      <li>No genera citas, cifras, nombres ni fechas que no hayan sido verificados por una persona.</li>
      <li>No firma opinión. La opinión la firma quien la sostiene.</li>
      <li>No decide qué se destaca en portada.</li>
    </ul>

    <h2>Imágenes y video</h2>
    <p>Toda imagen generada o alterada con inteligencia artificial se identifica de forma visible
      dentro de la propia pieza.</p>

    <h2>Transcripciones</h2>
    <p>Las transcripciones pueden generarse de forma automática. Su estado se indica en cada video:
      pendiente, borrador o revisada. Solo las revisadas por una persona se presentan como definitivas.</p>
  </div>
</div>
