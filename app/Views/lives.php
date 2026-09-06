<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <h1 class="seccion__titulo">Lives</h1>
    <p class="seccion__nota">Cuando un programa termina, su página no desaparece: se convierte en expediente.</p>
  </div>

  <?php if ($proximos !== []): ?>
    <h2>Próximos</h2>
    <div class="rejilla rejilla--2" style="margin-bottom:var(--e6)">
      <?php foreach ($proximos as $l): ?>
        <div class="live-destacado" data-entra>
          <p><span class="live-estado live-estado--<?= atributo($l['status']) ?>"><?= e(\App\Models\Live::STATUSES[$l['status']] ?? '') ?></span> <?= selloDemo($l['is_demo'] ?? 0) ?></p>
          <h3 style="margin:0"><a href="/live/<?= atributo($l['slug']) ?>"><?= e($l['title']) ?></a></h3>
          <?php if (!empty($l['lead_question'])): ?><p style="margin:0"><?= e($l['lead_question']) ?></p><?php endif; ?>
          <?php if (!empty($l['starts_at'])): ?>
            <p class="live-fecha"><time datetime="<?= atributo(fechaIso($l['starts_at'])) ?>"><?= e(fechaHora($l['starts_at'])) ?></time></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>Archivo de programas</h2>
  <?php if ($pasados === []): ?>
    <div class="vacio"><p>Todavía no hay programas finalizados en el archivo.</p></div>
  <?php else: ?>
    <div class="rejilla rejilla--3">
      <?php foreach ($pasados as $l): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__cuerpo">
            <div class="tarjeta__meta">
              <span class="etiqueta-tipo">Live finalizado</span>
              <?= selloDemo($l['is_demo'] ?? 0) ?>
            </div>
            <h3 class="tarjeta__titulo"><a href="/live/<?= atributo($l['slug']) ?>"><?= e($l['title']) ?></a></h3>
            <?php if (!empty($l['summary'])): ?><p class="tarjeta__resumen"><?= e(extracto((string) $l['summary'], 130)) ?></p><?php endif; ?>
            <div class="tarjeta__pie">
              <time datetime="<?= atributo(fechaIso($l['starts_at'])) ?>"><?= e(fechaLarga($l['starts_at'])) ?></time>
              <?php if (!empty($l['recording_slug'])): ?><span aria-hidden="true">·</span><span>Con grabación</span><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
