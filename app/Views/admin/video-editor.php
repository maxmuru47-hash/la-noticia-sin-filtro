<?php
use App\Models\Video;
$esNuevo = $video === null;
$accion  = $esNuevo ? '/panel/videos/nuevo' : '/panel/videos/' . (int) $video['id'];
?>
<div class="panel__cabeza"><h1><?= $esNuevo ? 'Nuevo video' : 'Editar video' ?></h1></div>

<form method="post" action="<?= atributo($accion) ?>" enctype="multipart/form-data" class="editor">
  <?= \App\Support\Csrf::field() ?>

  <div>
    <div class="panel__caja">
      <div class="campo">
        <label for="titulo">Título</label>
        <input type="text" id="titulo" name="titulo" required maxlength="255" value="<?= atributo($video['title'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion"><?= e($video['description'] ?? '') ?></textarea>
      </div>
      <div class="campo">
        <label for="alternativa_textual">Alternativa textual (obligatoria en la práctica)</label>
        <textarea id="alternativa_textual" name="alternativa_textual"><?= e($video['text_alternative'] ?? '') ?></textarea>
        <span class="campo__ayuda">Qué se cuenta en el video, para quien no puede verlo o cuando el video no carga.</span>
      </div>
    </div>

    <div class="panel__caja">
      <h2>Archivo y proveedor</h2>
      <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
        Estrategia mixta: hero y clips cortos alojados por nosotros; Lives largos incrustados, pero con
        transcripción y resumen guardados aquí, para que el contenido editorial sobreviva al embed.
      </p>
      <div class="campo">
        <label for="proveedor">Proveedor</label>
        <select id="proveedor" name="proveedor">
          <?php foreach (['propio' => 'Archivo propio', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'instagram' => 'Instagram', 'otro' => 'Otro'] as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($video['provider'] ?? 'propio') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="proveedor_id">Identificador en el proveedor</label>
        <input type="text" id="proveedor_id" name="proveedor_id" maxlength="120" value="<?= atributo($video['provider_video_id'] ?? '') ?>">
        <span class="campo__ayuda">Para YouTube, el código que aparece después de <code>v=</code>.</span>
      </div>
      <div class="campo">
        <label for="url_origen">URL de origen</label>
        <input type="url" id="url_origen" name="url_origen" value="<?= atributo($video['source_url'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="archivo_video">Subir archivo propio (MP4 o WebM)</label>
        <input type="file" id="archivo_video" name="archivo_video" accept="video/mp4,video/webm">
        <?php if (!empty($video['storage_path'])): ?>
          <span class="campo__ayuda">Actual: <code><?= e($video['storage_path']) ?></code></span>
        <?php endif; ?>
      </div>
      <div class="campo">
        <label for="poster">Póster (imagen)</label>
        <input type="file" id="poster" name="poster" accept="image/jpeg,image/png,image/webp">
        <?php if (!empty($video['poster_path'])): ?>
          <span class="campo__ayuda">Actual: <code><?= e($video['poster_path']) ?></code></span>
        <?php endif; ?>
      </div>
      <div class="campo">
        <label for="subtitulos">Subtítulos WebVTT</label>
        <input type="file" id="subtitulos" name="subtitulos" accept=".vtt,text/vtt">
        <?php if (!empty($video['captions_path'])): ?>
          <span class="campo__ayuda">Actual: <code><?= e($video['captions_path']) ?></code></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel__caja">
      <h2>Capítulos y transcripción</h2>
      <div class="campo">
        <label for="capitulos">Capítulos</label>
        <textarea id="capitulos" name="capitulos" rows="6" placeholder="00:00 Introducción&#10;02:15 El dato que cambia todo"><?php
          foreach ($capitulos as $c) { echo e(duracion((int) $c['starts_at']) . ' ' . $c['title']) . "\n"; }
        ?></textarea>
        <span class="campo__ayuda">Un capítulo por línea, con la marca de tiempo al inicio.</span>
      </div>
      <div class="campo">
        <label for="transcripcion">Transcripción</label>
        <textarea id="transcripcion" name="transcripcion" rows="12"><?= e($transcripcion['content'] ?? '') ?></textarea>
        <span class="campo__ayuda">Entra al buscador: la noticia se podrá encontrar por lo que se dijo en cámara.</span>
      </div>
      <div class="campo">
        <label for="estado_transcripcion">Estado de la transcripción</label>
        <select id="estado_transcripcion" name="estado_transcripcion">
          <?php foreach (['ninguna' => 'Ninguna', 'pendiente' => 'Pendiente', 'borrador' => 'Borrador automático', 'revisada' => 'Revisada por una persona'] as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($video['transcript_status'] ?? 'ninguna') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <aside class="editor__lateral">
    <div class="panel__caja">
      <button class="boton boton--rojo" type="submit" style="width:100%">Guardar</button>
    </div>

    <div class="panel__caja">
      <div class="campo">
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo">
          <?php foreach (Video::TYPES as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($video['video_type'] ?? 'resumen') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
          <?php foreach (['borrador' => 'Borrador', 'publicado' => 'Publicado', 'archivado' => 'Archivado'] as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($video['status'] ?? 'borrador') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="orientacion">Orientación</label>
        <select id="orientacion" name="orientacion">
          <?php foreach (['horizontal' => 'Horizontal 16:9', 'vertical' => 'Vertical 9:16', 'cuadrada' => 'Cuadrada 1:1'] as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($video['orientation'] ?? 'horizontal') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="relacion_aspecto">Relación de aspecto</label>
        <input type="text" id="relacion_aspecto" name="relacion_aspecto" maxlength="12" value="<?= atributo($video['aspect_ratio'] ?? '16:9') ?>">
      </div>
      <div class="campo">
        <label for="duracion">Duración en segundos</label>
        <input type="number" id="duracion" name="duracion" min="0" value="<?= atributo($video['duration_seconds'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="publicado_en">Publicado el (hora de Venezuela)</label>
        <input type="datetime-local" id="publicado_en" name="publicado_en" value="<?= atributo(\App\Support\Dates::toLocalInput($video['published_at'] ?? null)) ?>">
      </div>
      <div class="campo campo--casilla">
        <input type="checkbox" id="autoplay" name="autoplay" value="1" <?= !empty($video['autoplay_allowed']) ? 'checked' : '' ?>>
        <label for="autoplay">Permitir reproducción automática (solo hero y fondo, siempre sin audio)</label>
      </div>
      <div class="campo campo--casilla">
        <input type="checkbox" id="es_demo" name="es_demo" value="1" <?= !empty($video['is_demo']) ? 'checked' : '' ?>>
        <label for="es_demo">Contenido de demostración</label>
      </div>
    </div>

    <?php if (!empty($noticias)): ?>
    <div class="panel__caja">
      <h2>Noticias relacionadas</h2>
      <ul>
        <?php foreach ($noticias as $n): ?>
          <li><a href="/panel/noticias/<?= (int) $n['id'] ?>"><?= e($n['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </aside>
</form>
