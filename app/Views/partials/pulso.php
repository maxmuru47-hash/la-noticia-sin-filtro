<?php
/**
 * El Pulso que cambia. La misma pregunta antes y despues.
 *
 * Nunca bloquea la lectura: es un formulario mas, y la pieza se lee
 * entera sin responderlo.
 *
 * @var array       $pulso
 * @var array       $pulsoOpciones
 * @var array|null  $pulsoResultados
 * @var string      $etapa  'inicial' | 'informado'
 * @var bool        $yaVoto
 */
$etapa  = $etapa ?? 'inicial';
$yaVoto = $yaVoto ?? false;
$idPulso = (int) $pulso['id'];
$claveResultados = $etapa . '-' . $idPulso;
$mostrarResultados = $yaVoto && $pulsoResultados !== null;
?>
<section class="pulso<?= $etapa === 'informado' ? ' pulso--informado' : '' ?>" aria-labelledby="pulso-<?= atributo($claveResultados) ?>">
  <p class="pulso__etapa"><?= $etapa === 'inicial' ? 'Pulso inicial · antes de leer' : 'Pulso informado · después de leer' ?></p>
  <h2 class="pulso__pregunta" id="pulso-<?= atributo($claveResultados) ?>"><?= e($pulso['question']) ?></h2>

  <form class="formulario"
        method="post"
        action="/api/pulso/<?= $idPulso ?>"
        data-pulso="<?= atributo($claveResultados) ?>"
        <?= $mostrarResultados ? 'hidden' : '' ?>>
    <?= \App\Support\Csrf::field() ?>
    <input type="hidden" name="etapa" value="<?= atributo($etapa) ?>">

    <fieldset class="pulso__opciones" style="border:0;padding:0;margin:0">
      <legend class="solo-lectores">Elige una opción</legend>
      <?php foreach ($pulsoOpciones as $opcion): ?>
        <label class="pulso__opcion">
          <input type="radio" name="opcion" value="<?= (int) $opcion['id'] ?>">
          <span><?= e($opcion['label']) ?></span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <p class="respuesta-formulario" data-respuesta hidden></p>
    <button class="boton boton--rojo" type="submit">Registrar mi respuesta</button>
  </form>

  <div class="pulso__resultado"
       data-pulso-resultados="<?= atributo($claveResultados) ?>"
       <?= $mostrarResultados ? '' : 'hidden' ?>>
    <?php if ($mostrarResultados): ?>
      <?php foreach ($pulsoResultados['opciones'] as $fila): ?>
        <?php $pct = $etapa === 'informado' ? $fila['informado_pct'] : $fila['inicial_pct']; ?>
        <div class="pulso__fila">
          <div class="pulso__fila-cabeza">
            <span><?= e($fila['label']) ?></span>
            <strong><?= e(number_format((float) $pct, 1)) ?>%</strong>
          </div>
          <div class="pulso__pista">
            <div class="pulso__barra<?= $etapa === 'informado' ? ' pulso__barra--informado' : '' ?>" style="width:<?= (float) $pct ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($etapa === 'informado' && !empty($pulsoCambio) && $pulsoCambio['suficiente']): ?>
    <div class="pulso__movimiento">
      <p>De quienes respondieron antes y después de leer,
        <strong><?= e(number_format((float) $pulsoCambio['porcentaje'], 1)) ?>%</strong> cambió de postura.</p>
      <p style="margin:0;font-size:0.875rem;opacity:0.8">
        <?= (int) $pulsoCambio['cambiaron'] ?> de <?= (int) $pulsoCambio['completaron'] ?> personas que completaron las dos etapas.
      </p>
    </div>
  <?php endif; ?>

  <p class="pulso__metodologia">
    <?php if (!empty($pulso['methodology_note'])): ?>
      <?= e($pulso['methodology_note']) ?>
    <?php else: ?>
      Voto anónimo y no representativo. No es una encuesta científica: es una señal de cómo se mueve la conversación
      entre quienes leen esta pieza. Los resultados no se editan nunca.
    <?php endif; ?>
    <?php if ($pulsoResultados !== null && !$pulsoResultados['suficiente']): ?>
      Todavía hay pocas respuestas para mostrar un resultado con sentido.
    <?php endif; ?>
  </p>
</section>
