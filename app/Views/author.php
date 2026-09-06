<div class="contenedor seccion">
  <header style="display:flex;gap:var(--e4);align-items:center;flex-wrap:wrap;margin-bottom:var(--e5)">
    <?php if (!empty($autor['photo_path'])): ?>
      <img src="<?= atributo($autor['photo_path']) ?>" alt="<?= atributo($autor['photo_alt'] ?? '') ?>"
           width="96" height="96" style="border-radius:50%;object-fit:cover">
    <?php endif; ?>
    <div>
      <h1 style="margin:0"><?= e($autor['name']) ?></h1>
      <?php if (!empty($autor['role_title'])): ?><p style="margin:0;color:var(--tinta-tenue)"><?= e($autor['role_title']) ?></p><?php endif; ?>
      <p style="margin:var(--e1) 0 0;font-size:var(--t-sm)">
        <?php if (!empty($autor['instagram'])): ?>
          <a href="https://www.instagram.com/<?= atributo($autor['instagram']) ?>" rel="noopener noreferrer me" target="_blank">@<?= e($autor['instagram']) ?></a>
        <?php endif; ?>
        <?php if (!empty($autor['tiktok'])): ?>
          · <a href="https://www.tiktok.com/@<?= atributo($autor['tiktok']) ?>" rel="noopener noreferrer me" target="_blank">TikTok</a>
        <?php endif; ?>
      </p>
    </div>
  </header>

  <?php if (!empty($autor['bio'])): ?>
    <div class="lectura" style="margin-bottom:var(--e5)"><p><?= nl2br(e($autor['bio'])) ?></p></div>
  <?php endif; ?>

  <div class="seccion__cabeza">
    <h2 class="seccion__titulo">Piezas firmadas</h2>
    <p class="seccion__nota"><?= (int) $total ?> en total</p>
  </div>

  <?php if ($piezas === []): ?>
    <div class="vacio"><p>Todavía no hay piezas publicadas con esta firma.</p></div>
  <?php else: ?>
    <div class="rejilla rejilla--3">
      <?php foreach ($piezas as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
