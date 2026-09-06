<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <h1 class="seccion__titulo">Expedientes vivos</h1>
    <p class="seccion__nota">Temas que no se cierran cuando termina la noticia.</p>
  </div>

  <?php if ($expedientes === []): ?>
    <div class="vacio"><p>Todavía no hay expedientes abiertos.</p></div>
  <?php else: ?>
    <div class="rejilla rejilla--3">
      <?php foreach ($expedientes as $exp): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__cuerpo">
            <p class="expediente__estado" style="color:var(--rojo)"><?= e(str_replace('_', ' ', (string) $exp['status'])) ?></p>
            <h2 class="tarjeta__titulo"><a href="/expediente/<?= atributo($exp['slug']) ?>"><?= e($exp['title']) ?></a></h2>
            <?php if (!empty($exp['lead_question'])): ?><p class="tarjeta__resumen"><?= e($exp['lead_question']) ?></p><?php endif; ?>
            <div class="tarjeta__pie">Abierto el <?= e(fechaLarga($exp['opened_at'])) ?> <?= selloDemo($exp['is_demo'] ?? 0) ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
