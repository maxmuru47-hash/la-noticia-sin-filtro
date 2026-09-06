<header class="expediente-cabeza">
  <div class="contenedor lectura">
    <p class="expediente__estado">Expediente <?= e(str_replace('_', ' ', (string) $expediente['status'])) ?> <?= selloDemo($expediente['is_demo'] ?? 0) ?></p>
    <h1><?= e($expediente['title']) ?></h1>
    <?php if (!empty($expediente['lead_question'])): ?>
      <p style="font-size:var(--t-lg)"><?= e($expediente['lead_question']) ?></p>
    <?php endif; ?>
    <?php if (!empty($expediente['summary'])): ?>
      <p style="opacity:0.85"><?= nl2br(e($expediente['summary'])) ?></p>
    <?php endif; ?>
  </div>
</header>

<div class="contenedor seccion">
  <div class="lectura">
    <?php if ($cronologia !== []): ?>
      <h2>Cronología del expediente</h2>
      <ol class="cronologia">
        <?php foreach ($cronologia as $evento): ?>
          <li>
            <p class="cronologia__fecha"><?= e(fechaLarga($evento['occurred_on'] . ' 12:00:00')) ?></p>
            <p class="cronologia__titulo"><?= e($evento['title']) ?></p>
            <?php if (!empty($evento['detail'])): ?><p style="margin:0"><?= e($evento['detail']) ?></p><?php endif; ?>
            <p style="margin:0">
              <span class="certeza certeza--<?= atributo($evento['certainty']) ?>"><?= e($evento['certainty']) ?></span>
              <?php if (!empty($evento['article_slug'])): ?>
                · <a href="/noticia/<?= atributo($evento['article_slug']) ?>">Leer la pieza</a>
              <?php endif; ?>
              <?php if (!empty($evento['source_url'])): ?>
                · <a href="<?= atributo($evento['source_url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($evento['source_title']) ?></a>
              <?php endif; ?>
            </p>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <?php if ($abiertas !== []): ?>
      <div class="pregunta-viva">
        <p class="pregunta-viva__sello">Preguntas todavía abiertas</p>
        <ul style="margin:0;padding-left:1.2rem">
          <?php foreach ($abiertas as $abierta): ?>
            <li style="margin-bottom:var(--e1)">
              <?= e($abierta['legacy_question']) ?>
              <br><a href="/noticia/<?= atributo($abierta['slug']) ?>" style="font-size:var(--t-sm);opacity:0.8">Nació en: <?= e($abierta['title']) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($piezas !== []): ?>
    <div class="seccion__cabeza" style="margin-top:var(--e6)">
      <h2 class="seccion__titulo">Todas las piezas del expediente</h2>
      <p class="seccion__nota"><?= count($piezas) ?> en total, incluidas las archivadas.</p>
    </div>
    <div class="rejilla rejilla--3">
      <?php foreach ($piezas as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
