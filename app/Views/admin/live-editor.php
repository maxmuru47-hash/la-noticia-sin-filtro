<?php
use App\Models\Live;
$esNuevo = $live === null;
$accion  = $esNuevo ? '/panel/lives/nuevo' : '/panel/lives/' . (int) $live['id'];
?>
<div class="panel__cabeza"><h1><?= $esNuevo ? 'Nuevo Live' : 'Editar Live' ?></h1></div>

<form method="post" action="<?= atributo($accion) ?>" class="editor">
  <?= \App\Support\Csrf::field() ?>

  <div>
    <div class="panel__caja">
      <div class="campo">
        <label for="titulo">Título</label>
        <input type="text" id="titulo" name="titulo" required maxlength="255" value="<?= atributo($live['title'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="pregunta_principal">Pregunta principal del programa</label>
        <input type="text" id="pregunta_principal" name="pregunta_principal" maxlength="500" value="<?= atributo($live['lead_question'] ?? '') ?>">
      </div>
      <div class="campo">
        <label for="resumen">Resumen</label>
        <textarea id="resumen" name="resumen"><?= e($live['summary'] ?? '') ?></textarea>
      </div>
      <div class="campo">
        <label for="invitados">Invitados</label>
        <textarea id="invitados" name="invitados" rows="3"><?= e($live['guests'] ?? '') ?></textarea>
      </div>
      <div class="campo">
        <label for="url_transmision">Enlace de transmisión (https)</label>
        <input type="url" id="url_transmision" name="url_transmision" value="<?= atributo($live['stream_url'] ?? '') ?>">
      </div>
    </div>

    <div class="panel__caja">
      <h2>Después del programa</h2>
      <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
        Cuando el Live termina, su página no desaparece: se convierte en expediente.
      </p>
      <div class="campo">
        <label for="resumen_posterior">Qué se respondió</label>
        <textarea id="resumen_posterior" name="resumen_posterior"><?= e($live['aftermath'] ?? '') ?></textarea>
      </div>
      <div class="campo">
        <label for="pendientes">Qué quedó pendiente</label>
        <textarea id="pendientes" name="pendientes"><?= e($live['pending_matters'] ?? '') ?></textarea>
      </div>
      <div class="campo">
        <label for="grabacion">Grabación</label>
        <select id="grabacion" name="grabacion">
          <option value="">Sin grabación</option>
          <?php foreach ($grabaciones as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= (int) ($live['recording_video_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="campo__ayuda">Crea primero el video con tipo &laquo;Grabación de Live&raquo; en <a href="/panel/videos/nuevo">Videos</a>.</span>
      </div>
    </div>

    <?php if (!empty($preguntas)): ?>
    <div class="panel__caja">
      <h2>Preguntas de la comunidad para este Live</h2>
      <ul>
        <?php foreach ($preguntas as $p): ?>
          <li>&laquo;<?= e($p['body']) ?>&raquo; — <em><?= e(\App\Models\Question::STATUSES[$p['status']] ?? '') ?></em></li>
        <?php endforeach; ?>
      </ul>
      <p><a href="/panel/moderacion">Ir a moderación</a></p>
    </div>
    <?php endif; ?>
  </div>

  <aside class="editor__lateral">
    <div class="panel__caja">
      <button class="boton boton--rojo" type="submit" style="width:100%">Guardar</button>
    </div>
    <div class="panel__caja">
      <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
          <?php foreach (Live::STATUSES as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>" <?= ($live['status'] ?? 'anunciado') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="empieza">Empieza (hora de Venezuela)</label>
        <input type="datetime-local" id="empieza" name="empieza" value="<?= atributo(\App\Support\Dates::toLocalInput($live['starts_at'] ?? null)) ?>">
      </div>
      <div class="campo">
        <label for="termina">Termina</label>
        <input type="datetime-local" id="termina" name="termina" value="<?= atributo(\App\Support\Dates::toLocalInput($live['ends_at'] ?? null)) ?>">
      </div>
      <div class="campo campo--casilla">
        <input type="checkbox" id="es_demo" name="es_demo" value="1" <?= !empty($live['is_demo']) ? 'checked' : '' ?>>
        <label for="es_demo">Contenido de demostración</label>
      </div>
    </div>
  </aside>
</form>
