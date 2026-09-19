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
      y un apartado de lo que no se sabe.
    </p>
    <p class="suscribirse__nota">
      <strong>Para no perdértelas</strong>, añade esta dirección a tu lector de noticias:
      <a href="/rss.xml">lanoticia.sinfiltroconmax.com/rss.xml</a>. Funciona hoy, no hace falta
      dar ningún dato y no depende de ninguna plataforma.
    </p>
  </div>

  <form class="suscribirse__form formulario" method="post" action="/suscribirse" data-envio-json>
    <?= \App\Support\Csrf::field() ?>

    <div class="campo">
      <label for="suscribirse-correo">O déjanos tu correo para cuando empecemos</label>
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
    <button class="boton boton--rojo" type="submit">Apuntarme a la lista</button>

    <p class="suscribirse__honesto">
      <strong>Todavía no enviamos correos, y no tenemos fecha para empezar.</strong> Esto es una
      lista de espera: tu dirección se guarda en nuestro propio servidor y el día que empecemos,
      lo primero que recibirás será un correo para confirmar. Si no lo confirmas, no te
      escribimos. No se vende, no se cede y no pasa por ninguna empresa de fuera.
    </p>
  </form>
</section>
