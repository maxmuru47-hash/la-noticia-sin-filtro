<div class="contenedor seccion">
  <div class="lectura">
    <div class="tarjeta__meta" style="margin-bottom:var(--e3)">
      <span class="etiqueta-tipo"><?= e(\App\Models\Video::TYPES[$video['video_type']] ?? 'Video') ?></span>
      <?= selloDemo($video['is_demo'] ?? 0) ?>
    </div>

    <h1><?= e($video['title']) ?></h1>

    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      <time datetime="<?= atributo(fechaIso($video['published_at'])) ?>"><?= e(fechaLarga($video['published_at'])) ?></time>
      <?php if (!empty($video['duration_seconds'])): ?> · <?= e(duracion((int) $video['duration_seconds'])) ?><?php endif; ?>
      <?php if (!empty($video['captions_path'])): ?> · Con subtítulos<?php endif; ?>
    </p>

    <?= \App\Support\View::partial('partials/reproductor', ['video' => $video]) ?>

    <?php if (!empty($video['description'])): ?>
      <div class="cuerpo"><p><?= nl2br(e($video['description'])) ?></p></div>
    <?php endif; ?>

    <?php if ($capitulos !== []): ?>
      <h2>Capítulos</h2>
      <ul class="capitulos">
        <?php foreach ($capitulos as $capitulo): ?>
          <li><a href="#video-<?= (int) $video['id'] ?>" data-capitulo="<?= (int) $capitulo['starts_at'] ?>" data-video="<?= (int) $video['id'] ?>">
            <time><?= e(duracion((int) $capitulo['starts_at'])) ?></time><span><?= e($capitulo['title']) ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($transcripcion !== null): ?>
      <details class="transcripcion" data-capa-evento="transcripcion_abierta" open>
        <summary>Transcripción completa</summary>
        <div class="transcripcion__texto"><?= e($transcripcion['content']) ?></div>
      </details>
      <p class="campo__ayuda">Esta transcripción forma parte del contenido indexable: el buscador encuentra esta pieza por lo que se dijo en cámara.</p>
    <?php endif; ?>

    <?php if ($noticias !== []): ?>
      <h2>Noticias relacionadas</h2>
      <ul class="lista-piezas">
        <?php foreach ($noticias as $n): ?>
          <li>
            <div class="tarjeta__meta"><?= etiquetaTipo((string) $n['editorial_type']) ?></div>
            <a href="/noticia/<?= atributo($n['slug']) ?>" style="font-family:var(--titulares);font-weight:700"><?= e($n['title']) ?></a>
            <span style="font-size:var(--t-sm);color:var(--tinta-tenue)"><?= e(\App\Models\Video::ROLES[$n['editorial_role']] ?? '') ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
