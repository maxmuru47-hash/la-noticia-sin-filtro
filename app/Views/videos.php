<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <h1 class="seccion__titulo">Videos</h1>
    <p class="seccion__nota">Cada video con póster, subtítulos y alternativa en texto.</p>
  </div>

  <nav class="archivo-nav" aria-label="Filtrar por tipo de video">
    <a href="/videos" <?= $tipoActivo === null ? 'aria-current="page"' : '' ?>>Todos</a>
    <?php foreach (\App\Models\Video::TYPES as $clave => $nombre): ?>
      <?php if ($clave === 'hero' || $clave === 'fondo') { continue; } ?>
      <a href="/videos?tipo=<?= atributo($clave) ?>" <?= $tipoActivo === $clave ? 'aria-current="page"' : '' ?>><?= e($nombre) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($videos === []): ?>
    <div class="vacio"><h2>Todavía no hay videos publicados aquí</h2></div>
  <?php else: ?>
    <div class="rejilla rejilla--3" style="margin-top:var(--e4)">
      <?php foreach ($videos as $v): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__medio">
            <?php if (!empty($v['poster_path']) || !empty($v['thumbnail_path'])): ?>
              <img src="<?= atributo($v['poster_path'] ?: $v['thumbnail_path']) ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
            <?php if (!empty($v['duration_seconds'])): ?>
              <span class="tarjeta__duracion"><?= e(duracion((int) $v['duration_seconds'])) ?></span>
            <?php endif; ?>
          </div>
          <div class="tarjeta__cuerpo">
            <div class="tarjeta__meta">
              <span class="etiqueta-tipo"><?= e(\App\Models\Video::TYPES[$v['video_type']] ?? 'Video') ?></span>
              <?= selloDemo($v['is_demo'] ?? 0) ?>
            </div>
            <h2 class="tarjeta__titulo"><a href="/video/<?= atributo($v['slug']) ?>"><?= e($v['title']) ?></a></h2>
            <?php if (!empty($v['description'])): ?>
              <p class="tarjeta__resumen"><?= e(extracto((string) $v['description'], 130)) ?></p>
            <?php endif; ?>
            <div class="tarjeta__pie">
              <time datetime="<?= atributo(fechaIso($v['published_at'])) ?>"><?= e(fechaLarga($v['published_at'])) ?></time>
              <?php if (!empty($v['captions_path'])): ?><span aria-hidden="true">·</span><span>Subtítulos</span><?php endif; ?>
              <?php if ($v['transcript_status'] === 'revisada'): ?><span aria-hidden="true">·</span><span>Transcripción</span><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>
