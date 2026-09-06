<?php
/**
 * Tarjeta de pieza. Siempre muestra clasificacion editorial y fecha.
 * @var array $pieza
 */
$destacada = $destacada ?? false;
$numero    = $numero ?? null;
$ruta      = '/noticia/' . $pieza['slug'];
?>
<article class="tarjeta<?= $destacada ? ' tarjeta--esencial' : '' ?>" data-entra>
  <?php if (!empty($pieza['hero_path'])): ?>
    <div class="tarjeta__medio">
      <img src="<?= atributo($pieza['hero_path']) ?>"
           alt="<?= atributo($pieza['hero_media_alt'] ?? $pieza['hero_alt'] ?? '') ?>"
           <?= !empty($pieza['hero_width']) ? 'width="' . (int) $pieza['hero_width'] . '" height="' . (int) $pieza['hero_height'] . '"' : '' ?>
           loading="lazy" decoding="async">
      <?php if (!empty($pieza['video_duration'])): ?>
        <span class="tarjeta__duracion"><?= e(duracion((int) $pieza['video_duration'])) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="tarjeta__cuerpo">
    <div class="tarjeta__meta">
      <?php if ($numero !== null): ?><span class="numero-esencial" aria-hidden="true"><?= (int) $numero ?></span><?php endif; ?>
      <?= etiquetaTipo((string) $pieza['editorial_type']) ?>
      <?= selloEstado((string) $pieza['status']) ?>
      <?= selloDemo($pieza['is_demo'] ?? 0) ?>
    </div>

    <h3 class="tarjeta__titulo">
      <a href="<?= atributo($ruta) ?>"><?= e($pieza['override_title'] ?? $pieza['title']) ?></a>
    </h3>

    <?php if (!empty($pieza['summary'])): ?>
      <p class="tarjeta__resumen"><?= e(extracto((string) $pieza['summary'], 150)) ?></p>
    <?php endif; ?>

    <div class="tarjeta__pie">
      <?php if (!empty($pieza['category_name'])): ?>
        <a href="/categoria/<?= atributo($pieza['category_slug']) ?>"><?= e($pieza['category_name']) ?></a>
        <span aria-hidden="true">·</span>
      <?php endif; ?>
      <time datetime="<?= atributo(fechaIso($pieza['published_at'])) ?>"><?= e(fechaLarga($pieza['published_at'])) ?></time>
      <?php if (!empty($pieza['reading_minutes'])): ?>
        <span aria-hidden="true">·</span><span><?= (int) $pieza['reading_minutes'] ?> min de lectura</span>
      <?php endif; ?>
      <?php if (!empty($pieza['videos_total'])): ?>
        <span aria-hidden="true">·</span><span>Con video</span>
      <?php endif; ?>
    </div>
  </div>
</article>
