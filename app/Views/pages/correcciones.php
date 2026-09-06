<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <p style="font-size:var(--t-lg)">Corregir no resta autoridad. Ocultar una corrección sí.</p>

    <h2>Cómo corregimos</h2>
    <ul>
      <li>Toda corrección relevante queda registrada con su fecha, su motivo y qué se cambió.</li>
      <li>El historial aparece al pie de la pieza corregida, visible para cualquiera.</li>
      <li>La dirección de la pieza no cambia al corregirla.</li>
      <li>Si el error afecta al sentido de la pieza, lo señalamos también en la parte superior.</li>
    </ul>

    <h2>Cómo pedir una corrección</h2>
    <p>Escríbenos desde <a href="/transparencia/contacto">la página de contacto</a> indicando la dirección
      de la pieza y qué dato consideras incorrecto. Respondemos a toda solicitud, corrijamos o no.</p>

    <h2>Correcciones publicadas</h2>
    <?php if (empty($correcciones)): ?>
      <p>Todavía no hay correcciones registradas.</p>
    <?php else: ?>
      <ul class="correcciones">
        <?php foreach ($correcciones as $c): ?>
          <li class="correccion">
            <p class="correccion__tipo"><?= e($c['kind']) ?></p>
            <p class="correccion__fecha"><time datetime="<?= atributo(fechaIso($c['corrected_at'])) ?>"><?= e(fechaHora($c['corrected_at'])) ?></time></p>
            <p style="margin:0"><a href="/noticia/<?= atributo($c['slug']) ?>"><?= e($c['title']) ?></a></p>
            <p style="margin:0"><strong><?= e($c['reason']) ?></strong></p>
            <?php if (!empty($c['detail'])): ?><p style="margin:0"><?= e($c['detail']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
