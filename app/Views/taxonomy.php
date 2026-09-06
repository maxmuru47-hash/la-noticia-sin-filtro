<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <div>
      <p style="font-size:var(--t-sm);color:var(--tinta-tenue);text-transform:uppercase;letter-spacing:0.1em;margin:0"><?= e($tipoListado) ?></p>
      <h1 class="seccion__titulo"><?= e($encabezado) ?></h1>
    </div>
    <p class="seccion__nota"><?= (int) $total ?> <?= $total === 1 ? 'pieza' : 'piezas' ?>, incluidas las archivadas.</p>
  </div>

  <?php if (!empty($bajada)): ?>
    <p style="font-size:var(--t-lg);max-width:var(--ancho-lectura)"><?= e($bajada) ?></p>
  <?php endif; ?>

  <?php if ($piezas === []): ?>
    <div class="vacio"><h2>Todavía no hay piezas aquí</h2><p><a href="/archivo">Ver el archivo completo</a></p></div>
  <?php else: ?>
    <div class="rejilla rejilla--3" style="margin-top:var(--e4)">
      <?php foreach ($piezas as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
