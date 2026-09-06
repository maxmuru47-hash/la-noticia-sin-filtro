<?php
/**
 * Contribucion estructurada, no comentario libre. Siempre moderada.
 * @var int|null $articuloId
 * @var int|null $liveId
 */
?>
<form class="formulario" method="post" action="/api/pregunta" data-envio-json>
  <?= \App\Support\Csrf::field() ?>
  <?php if (!empty($articuloId)): ?><input type="hidden" name="articulo" value="<?= (int) $articuloId ?>"><?php endif; ?>
  <?php if (!empty($liveId)): ?><input type="hidden" name="live" value="<?= (int) $liveId ?>"><?php endif; ?>

  <div class="campo">
    <label for="pregunta-comunidad">¿Qué duda te queda?</label>
    <textarea id="pregunta-comunidad" name="pregunta" required minlength="12" maxlength="1000"
              placeholder="Escribe la pregunta que todavía no tiene respuesta."></textarea>
    <span class="campo__ayuda">La lee una persona antes de publicarla. Las mejores llegan al próximo Live.</span>
  </div>

  <div class="campo">
    <label for="nombre-comunidad">Tu nombre (opcional)</label>
    <input type="text" id="nombre-comunidad" name="nombre" maxlength="120" autocomplete="name">
  </div>

  <div class="campo">
    <label for="correo-comunidad">Tu correo (opcional)</label>
    <input type="email" id="correo-comunidad" name="correo" maxlength="190" autocomplete="email">
    <span class="campo__ayuda">Solo si quieres que te avisemos cuando respondamos.</span>
  </div>

  <div class="campo campo--casilla">
    <input type="checkbox" id="consiento-correo" name="consiento_correo" value="1">
    <label for="consiento-correo">Autorizo que guarden mi correo únicamente para avisarme sobre esta pregunta. Sin esta casilla, el correo no se guarda.</label>
  </div>

  <p class="respuesta-formulario" data-respuesta hidden></p>
  <button class="boton boton--rojo" type="submit">Enviar mi pregunta</button>
</form>
