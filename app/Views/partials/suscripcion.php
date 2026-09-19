<?php
/**
 * Va al FINAL de la pieza, cuando la persona ya decidió que merecía la
 * pena. Nunca en una ventana que tape lo que vino a leer.
 */
use App\Models\Subscriber;
?>
<section class="suscribirse" aria-labelledby="suscribirse-titulo">
  <div class="suscribirse__texto">
    <h2 class="suscribirse__titulo" id="suscribirse-titulo">¿Te sirvió esto?</h2>
    <p class="suscribirse__nota">
      Publicamos una pieza cada día, con la misma regla: toda cifra con su fecha y su fuente,
      y un apartado de lo que no se sabe. Déjanos tu correo y te llega el resumen sin que
      tengas que acordarte de volver.
    </p>
  </div>

  <form class="suscribirse__form formulario" method="post" action="/suscribirse" data-envio-json>
    <?= \App\Support\Csrf::field() ?>

    <div class="campo">
      <label for="suscribirse-correo">Tu correo</label>
      <input type="email" id="suscribirse-correo" name="correo" required maxlength="190"
             autocomplete="email" placeholder="nombre@correo.com">
    </div>

    <fieldset class="campo campo--grupo">
      <legend>¿Qué quieres recibir?</legend>
      <?php foreach (Subscriber::PROPOSITOS as $clave => $etiqueta): ?>
        <div class="campo--casilla">
          <input type="checkbox" id="interes-<?= atributo($clave) ?>" name="intereses[]"
                 value="<?= atributo($clave) ?>" <?= $clave === 'resumen_semanal' ? 'checked' : '' ?>>
          <label for="interes-<?= atributo($clave) ?>"><?= e($etiqueta) ?></label>
        </div>
      <?php endforeach; ?>
    </fieldset>

    <div class="campo campo--casilla">
      <input type="checkbox" id="suscribirse-consiento" name="consiento" value="1" required>
      <label for="suscribirse-consiento">
        Autorizo que guarden mi correo para enviarme lo que he marcado. Puedo darme de baja
        cuando quiera, con un enlace en cada correo.
      </label>
    </div>

    <p class="respuesta-formulario" data-respuesta hidden></p>
    <button class="boton boton--rojo" type="submit">Quiero recibirlo</button>

    <p class="suscribirse__honesto">
      Todavía no hemos empezado a enviar. Cuando lo hagamos, lo primero que recibirás es un
      correo para confirmar: si no lo confirmas, no te escribimos. Tu dirección no se vende,
      no se cede y no se usa para nada más.
    </p>
  </form>
</section>
