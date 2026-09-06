<?php /** ARCHIVO PERMANENTE. Incluye las piezas archivadas: ese es el punto. */ ?>
<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <h1 class="seccion__titulo"><?= e($encabezado) ?></h1>
    <p class="seccion__nota">Ninguna pieza publicada se elimina. Las archivadas siguen aquí.</p>
  </div>

  <nav class="archivo-nav" aria-label="Archivo por año">
    <a href="/archivo" <?= $anio === null ? 'aria-current="page"' : '' ?>>Todo</a>
    <?php foreach ($anios as $fila): ?>
      <a href="/archivo/<?= (int) $fila['anio'] ?>" <?= $anio === (int) $fila['anio'] && $mes === null ? 'aria-current="page"' : '' ?>>
        <?= (int) $fila['anio'] ?> (<?= (int) $fila['total'] ?>)
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($meses !== []): ?>
    <nav class="archivo-nav" aria-label="Archivo por mes">
      <?php foreach ($meses as $fila): ?>
        <a href="/archivo/<?= (int) $anio ?>/<?= str_pad((string) $fila['mes'], 2, '0', STR_PAD_LEFT) ?>"
           <?= $mes === (int) $fila['mes'] ? 'aria-current="page"' : '' ?>>
          <?= e(ucfirst(\App\Support\Dates::monthName((int) $fila['mes']))) ?> (<?= (int) $fila['total'] ?>)
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if ($piezas === []): ?>
    <div class="vacio">
      <h2>No hay piezas en este periodo</h2>
      <p>Prueba con otro año o usa el <a href="/buscar">buscador</a>.</p>
    </div>
  <?php else: ?>
    <div class="rejilla rejilla--3" style="margin-top:var(--e5)">
      <?php foreach ($piezas as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
