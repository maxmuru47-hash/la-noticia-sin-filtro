<?php
/**
 * El asistente de la redacción.
 *
 * Funciona sin JavaScript hasta donde puede: si el navegador no ejecuta
 * nada, el formulario lleva al buscador de siempre y la persona encuentra
 * lo mismo por otro camino. Con JavaScript, contesta en la propia página.
 */
?>
<section class="seccion seccion--carbon" id="asistente">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Pregunta a la redacción</h2>
      <p class="seccion__nota">
        Busca en el archivo por ti y explica cómo funciona esta casa.
        No inventa: si no hay nada publicado sobre algo, te lo dice.
      </p>
    </div>

    <div class="asistente" data-asistente>
      <div class="asistente__hilo" data-hilo role="log" aria-live="polite" aria-label="Conversación con el asistente">
        <div class="asistente__turno asistente__turno--casa">
          <p class="asistente__texto">
            Puedo buscarte lo que se haya publicado sobre un tema, o contarte cómo
            funcionan el archivo permanente, las correcciones y el Pulso.
          </p>
        </div>
      </div>

      <div class="asistente__atajos" data-atajos>
        <button class="asistente__atajo" type="button">¿Qué es el Pulso?</button>
        <button class="asistente__atajo" type="button">¿Cómo corrigen un error?</button>
        <button class="asistente__atajo" type="button">¿Se borra alguna noticia?</button>
        <button class="asistente__atajo" type="button">¿Cómo escribo a Max?</button>
      </div>

      <form class="asistente__campo" action="/buscar" method="get" data-formulario>
        <?= \App\Support\Csrf::field() ?>
        <label class="solo-lectores" for="asistente-pregunta">Tu pregunta</label>
        <input type="text" id="asistente-pregunta" name="q" data-entrada
               maxlength="<?= (int) \App\Services\AssistantService::LIMITE_PREGUNTA ?>"
               placeholder="Escribe tu pregunta o un tema" autocomplete="off">
        <button class="boton boton--rojo" type="submit">Preguntar</button>
      </form>

      <p class="asistente__aviso">
        Asistente de reglas, no inteligencia artificial. Responde con lo que está
        escrito o con lo que hay publicado. No guarda tu pregunta.
      </p>
    </div>
  </div>
</section>
