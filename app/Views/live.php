<?php /** Un Live terminado se convierte en expediente, no en un enlace muerto. */ ?>
<div class="contenedor seccion">
  <div class="lectura">
    <p>
      <span class="live-estado live-estado--<?= atributo($live['status']) ?>"><?= e(\App\Models\Live::STATUSES[$live['status']] ?? '') ?></span>
      <?= selloDemo($live['is_demo'] ?? 0) ?>
    </p>

    <h1><?= e($live['title']) ?></h1>

    <?php if (!empty($live['lead_question'])): ?>
      <p class="pieza__pregunta"><?= e($live['lead_question']) ?></p>
    <?php endif; ?>

    <?php if (!empty($live['starts_at'])): ?>
      <p class="live-fecha">
        <time datetime="<?= atributo(fechaIso($live['starts_at'])) ?>"><?= e(fechaHora($live['starts_at'])) ?></time>
        <span style="font-size:var(--t-sm);color:var(--tinta-tenue)">(hora de Venezuela)</span>
      </p>
    <?php endif; ?>

    <?php if (!empty($live['guests'])): ?>
      <p><strong>Invitados:</strong> <?= e($live['guests']) ?></p>
    <?php endif; ?>

    <?php if (!empty($live['summary'])): ?>
      <div class="cuerpo"><p><?= nl2br(e($live['summary'])) ?></p></div>
    <?php endif; ?>

    <?php if ($live['status'] === 'en_vivo' && !empty($live['stream_url'])): ?>
      <p><a class="boton boton--rojo" href="<?= atributo($live['stream_url']) ?>" rel="noopener noreferrer" target="_blank">Ver la transmisión ahora</a></p>
    <?php endif; ?>

    <!-- Recordatorio, solo con consentimiento explicito -->
    <?php if (in_array($live['status'], ['anunciado', 'programado'], true)): ?>
      <section class="pulso">
        <h2 style="margin-top:0">Recibe el aviso</h2>
        <form class="formulario" method="post" action="/api/recordatorio/<?= (int) $live['id'] ?>" data-envio-json>
          <?= \App\Support\Csrf::field() ?>
          <div class="campo">
            <label for="correo-live">Tu correo</label>
            <input type="email" id="correo-live" name="correo" required autocomplete="email">
          </div>
          <div class="campo campo--casilla">
            <input type="checkbox" id="consiento-live" name="consiento" value="1" required>
            <label for="consiento-live">Acepto recibir un aviso por correo de este Live. Puedo darme de baja cuando quiera.</label>
          </div>
          <p class="respuesta-formulario" data-respuesta hidden></p>
          <button class="boton" type="submit">Avísame</button>
        </form>
      </section>
    <?php endif; ?>

    <!-- Grabacion: el programa terminado sigue disponible -->
    <?php if ($grabacion !== null): ?>
      <h2>Grabación completa</h2>
      <?= \App\Support\View::partial('partials/reproductor', ['video' => $grabacion]) ?>

      <?php if ($capitulos !== []): ?>
        <h3>Capítulos</h3>
        <ul class="capitulos">
          <?php foreach ($capitulos as $capitulo): ?>
            <li><a href="#video-<?= (int) $grabacion['id'] ?>" data-capitulo="<?= (int) $capitulo['starts_at'] ?>" data-video="<?= (int) $grabacion['id'] ?>">
              <time><?= e(duracion((int) $capitulo['starts_at'])) ?></time><span><?= e($capitulo['title']) ?></span>
            </a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($transcripcion !== null): ?>
        <details class="transcripcion" data-capa-evento="transcripcion_abierta">
          <summary>Transcripción del programa</summary>
          <div class="transcripcion__texto"><?= e($transcripcion['content']) ?></div>
        </details>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Expediente posterior -->
    <?php if (!empty($live['aftermath'])): ?>
      <h2>Qué se respondió</h2>
      <div class="cuerpo"><p><?= nl2br(e($live['aftermath'])) ?></p></div>
    <?php endif; ?>

    <?php if (!empty($live['pending_matters'])): ?>
      <div class="pregunta-viva">
        <p class="pregunta-viva__sello">Lo que quedó pendiente</p>
        <p style="margin:0"><?= nl2br(e($live['pending_matters'])) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($preguntas !== []): ?>
      <h2>Preguntas de la comunidad</h2>
      <ul class="lista-piezas">
        <?php foreach ($preguntas as $pregunta): ?>
          <li>
            <p style="margin:0;font-weight:700">&laquo;<?= e($pregunta['body']) ?>&raquo;</p>
            <?php if (!empty($pregunta['answer'])): ?><p style="margin:0"><?= nl2br(e($pregunta['answer'])) ?></p><?php endif; ?>
            <p style="margin:0;font-size:var(--t-sm);color:var(--tinta-tenue)">
              <?= e($pregunta['author_name'] ?: 'Anónimo') ?> · <?= e(\App\Models\Question::STATUSES[$pregunta['status']] ?? '') ?>
            </p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Deja tu pregunta para el programa</h2>
    <?= \App\Support\View::partial('partials/preguntar', ['liveId' => (int) $live['id']]) ?>

    <?php if ($antes !== []): ?>
      <h2>Antes del programa</h2>
      <ul class="lista-piezas">
        <?php foreach ($antes as $pieza): ?>
          <li><?= etiquetaTipo((string) $pieza['editorial_type']) ?>
            <a href="/noticia/<?= atributo($pieza['slug']) ?>" style="font-family:var(--titulares);font-weight:700"><?= e($pieza['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($despues !== []): ?>
      <h2>Después del programa</h2>
      <ul class="lista-piezas">
        <?php foreach ($despues as $pieza): ?>
          <li><?= etiquetaTipo((string) $pieza['editorial_type']) ?>
            <a href="/noticia/<?= atributo($pieza['slug']) ?>" style="font-family:var(--titulares);font-weight:700"><?= e($pieza['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
