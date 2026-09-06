<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <h2>De dónde sale lo que publicamos</h2>
    <p>Cada pieza distingue tres niveles y los muestra por separado en la página:</p>
    <ul>
      <li><strong>Confirmado.</strong> Verificado con documento, registro o al menos dos fuentes independientes.</li>
      <li><strong>Probable.</strong> Sostenido por indicios o por una sola fuente, y así se declara.</li>
      <li><strong>Todavía desconocido.</strong> Lo que no sabemos, dicho en voz alta.</li>
    </ul>

    <h2>Fuentes abiertas</h2>
    <p>Cuando existe un documento original, lo enlazamos o lo archivamos. Cada fuente listada en una pieza
      lleva su nivel de certeza. Si una fuente pide reserva de identidad, explicamos por qué se la concedimos.</p>

    <h2>Cómo verificamos</h2>
    <ul>
      <li>Toda cita, cifra, nombre y fecha se verifica antes de publicar.</li>
      <li>Ninguna pieza se publica solo con la información de una parte interesada.</li>
      <li>Cuando una afirmación no puede verificarse, se dice que no pudo verificarse.</li>
      <li>Las imágenes generadas o alteradas se identifican de forma visible.</li>
    </ul>

    <h2>El Pulso</h2>
    <p>El Pulso es un voto anónimo, abierto y <strong>no representativo</strong>. Mide cómo se mueve la
      conversación entre quienes leen una pieza, no la opinión de la población. No es una encuesta científica
      y nunca lo presentamos como tal. Los resultados se calculan directamente de los votos registrados
      y no se editan.</p>
  </div>
</div>
