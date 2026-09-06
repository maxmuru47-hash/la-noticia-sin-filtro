<?php
/** @var \App\Support\Paginator $paginador */
if ($paginador->totalPages <= 1) { return; }
?>
<nav class="paginacion" aria-label="Paginación">
  <?php if ($paginador->hasPrevious()): ?>
    <a class="paginacion__enlace" href="<?= atributo($paginador->url($paginador->currentPage - 1)) ?>" rel="prev">← Anterior</a>
  <?php endif; ?>

  <?php if ($paginador->currentPage > 3): ?>
    <a class="paginacion__enlace" href="<?= atributo($paginador->url(1)) ?>">1</a>
    <span aria-hidden="true">…</span>
  <?php endif; ?>

  <?php foreach ($paginador->window() as $pagina): ?>
    <a class="paginacion__enlace" href="<?= atributo($paginador->url($pagina)) ?>"
       <?= $pagina === $paginador->currentPage ? 'aria-current="page"' : '' ?>><?= (int) $pagina ?></a>
  <?php endforeach; ?>

  <?php if ($paginador->currentPage < $paginador->totalPages - 2): ?>
    <span aria-hidden="true">…</span>
    <a class="paginacion__enlace" href="<?= atributo($paginador->url($paginador->totalPages)) ?>"><?= (int) $paginador->totalPages ?></a>
  <?php endif; ?>

  <?php if ($paginador->hasNext()): ?>
    <a class="paginacion__enlace" href="<?= atributo($paginador->url($paginador->currentPage + 1)) ?>" rel="next">Siguiente →</a>
  <?php endif; ?>

  <p class="paginacion__info">
    Página <?= (int) $paginador->currentPage ?> de <?= (int) $paginador->totalPages ?> ·
    <?= (int) $paginador->total ?> <?= $paginador->total === 1 ? 'pieza' : 'piezas' ?>
  </p>
</nav>
