<?php
/**
 * Reproductor accesible.
 *
 * Reglas: no reproduce audio solo, la persona inicia, controles por
 * teclado, subtitulos disponibles, relacion de aspecto estable y
 * alternativa textual siempre presente.
 *
 * @var array $video
 */
use App\Models\Video;

$propio    = Video::isSelfHosted($video);
$embebido  = Video::embedUrl($video);
$poster    = $video['poster_path'] ?: $video['thumbnail_path'];
$clase     = 'reproductor';
if (($video['orientation'] ?? '') === 'vertical')  { $clase .= ' reproductor--vertical'; }
if (($video['orientation'] ?? '') === 'cuadrada')  { $clase .= ' reproductor--cuadrada'; }
$idMarco   = 'video-' . (int) $video['id'];
?>
<figure class="<?= atributo($clase) ?>">
  <div class="reproductor__marco"
       data-reproductor
       data-video-id="<?= (int) $video['id'] ?>"
       data-titulo="<?= atributo($video['title']) ?>"
       <?= $embebido !== null && !$propio ? 'data-embebido="' . atributo($embebido) . '"' : '' ?>>

    <?php if ($propio): ?>
      <video id="<?= atributo($idMarco) ?>"
             preload="none"
             playsinline
             hidden
             <?= $poster ? 'poster="' . atributo($poster) . '"' : '' ?>>
        <source src="<?= atributo($video['storage_path']) ?>" type="video/mp4">
        <?php if (!empty($video['captions_path'])): ?>
          <track kind="captions" src="<?= atributo($video['captions_path']) ?>" srclang="es" label="Español" default>
        <?php endif; ?>
        Tu navegador no puede reproducir este video. Debajo está la alternativa en texto.
      </video>
    <?php endif; ?>

    <?php if ($propio || $embebido !== null): ?>
      <button class="reproductor__portada" type="button" data-reproductor-portada>
        <?php if ($poster): ?>
          <img src="<?= atributo($poster) ?>" alt="" loading="lazy" decoding="async">
        <?php endif; ?>
        <span class="reproductor__play" aria-hidden="true">▶</span>
        <span class="solo-lectores">Reproducir: <?= e($video['title']) ?><?= !empty($video['duration_seconds']) ? ', duración ' . duracion((int) $video['duration_seconds']) : '' ?></span>
      </button>
    <?php else: ?>
      <?php if ($poster): ?><img src="<?= atributo($poster) ?>" alt="<?= atributo($video['title']) ?>" loading="lazy"><?php endif; ?>
    <?php endif; ?>
  </div>

  <figcaption class="reproductor__pie">
    <strong><?= e($video['title']) ?></strong>
    <?php if (!empty($video['duration_seconds'])): ?>
      <span aria-hidden="true">·</span><span><?= e(duracion((int) $video['duration_seconds'])) ?></span>
    <?php endif; ?>
    <?php if (!empty($video['captions_path'])): ?>
      <span aria-hidden="true">·</span><span>Con subtítulos</span>
    <?php endif; ?>
    <?php if (($video['provider'] ?? 'propio') !== 'propio'): ?>
      <span aria-hidden="true">·</span><span>Alojado en <?= e(ucfirst((string) $video['provider'])) ?></span>
    <?php endif; ?>
    <?= selloDemo($video['is_demo'] ?? 0) ?>
  </figcaption>

  <?php if (!empty($video['text_alternative'])): ?>
    <div class="reproductor__alternativa">
      <strong>Si no puedes ver el video:</strong>
      <p><?= nl2br(e($video['text_alternative'])) ?></p>
    </div>
  <?php elseif ($embebido === null && !$propio): ?>
    <div class="reproductor__alternativa">
      <p>Este video todavía no está disponible para reproducción.</p>
    </div>
  <?php endif; ?>
</figure>
