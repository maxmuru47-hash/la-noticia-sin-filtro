<?php
/**
 * PAGINA INDIVIDUAL DE NOTICIA
 *
 * SEPARACION VISIBLE OBLIGATORIA:
 * HECHO no es ANALISIS. ANALISIS no es OPINION. OPINION no es PUBLICIDAD.
 */
$a  = $articulo;
$id = (int) $a['id'];
?>
<article class="pieza" data-articulo-id="<?= $id ?>">

  <?php if ($vistaPrevia): ?>
    <div class="contenedor"><p class="aviso aviso--fuerte">
      <strong>Vista previa.</strong> Esta pieza está en estado
      &laquo;<?= e(\App\Models\Article::STATUSES[$a['status']] ?? $a['status']) ?>&raquo; y todavía no es visible para el público.
    </p></div>
  <?php endif; ?>

  <?php if ($a['status'] === 'retirada'): ?>
    <div class="contenedor"><div class="aviso aviso--error">
      <strong>Esta pieza fue retirada.</strong>
      <p style="margin:var(--e1) 0 0"><?= $a['withdrawn_reason'] ?? 'Sin motivo registrado.' ?></p>
      <p style="margin:var(--e1) 0 0;font-size:var(--t-sm)">La página permanece publicada porque retirar no es borrar el registro de lo que se publicó.</p>
    </div></div>
  <?php elseif ($a['status'] === 'archivada'): ?>
    <div class="contenedor"><p class="aviso">
      <strong>Pieza archivada.</strong> Ya no aparece en portada, pero conserva su dirección permanente
      y se puede encontrar en el buscador y en el archivo.
    </p></div>
  <?php endif; ?>

  <?php if (!empty($a['is_demo']) && $config['app']['demo_badge']): ?>
    <div class="contenedor"><p class="aviso aviso--demo">
      <strong>Contenido de demostración.</strong> Esta pieza es ficticia y existe solo para probar la plataforma.
      No es una noticia real, y sus datos, citas y fuentes no describen hechos ocurridos.
    </p></div>
  <?php endif; ?>

  <!-- 1 a 3. CLASIFICACION, TITULO, FIRMA -->
  <header class="pieza__cabeza contenedor">
    <div class="pieza__meta-superior">
      <?= etiquetaTipo((string) $a['editorial_type']) ?>
      <?php if (!empty($a['category_name'])): ?>
        <a href="/categoria/<?= atributo($a['category_slug']) ?>"><?= e($a['category_name']) ?></a>
      <?php endif; ?>
      <?= selloEstado((string) $a['status']) ?>
      <?php if (!empty($a['dossier_slug'])): ?>
        <a class="etiqueta-tipo" href="/expediente/<?= atributo($a['dossier_slug']) ?>">Expediente: <?= e($a['dossier_title']) ?></a>
      <?php endif; ?>
    </div>

    <?php if (!empty($a['lead_question'])): ?>
      <p class="pieza__pregunta"><?= e($a['lead_question']) ?></p>
    <?php endif; ?>

    <h1 class="pieza__titulo"><?= e($a['title']) ?></h1>

    <?php if (!empty($a['subtitle'])): ?>
      <p class="pieza__subtitulo"><?= e($a['subtitle']) ?></p>
    <?php endif; ?>

    <?php if ($herencia !== null && !empty($herencia['parent_slug'])): ?>
      <p class="herencia">
        Esta pieza nació de <a href="/noticia/<?= atributo($herencia['parent_slug']) ?>"><?= e($herencia['parent_title']) ?></a>
        <?php if (!empty($herencia['question_body'])): ?>
          y de esta duda de la audiencia: &laquo;<?= e($herencia['question_body']) ?>&raquo;
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="pieza__firma">
      <?php if (!empty($a['author_photo'])): ?>
        <img src="<?= atributo($a['author_photo']) ?>" alt="<?= atributo($a['author_photo_alt'] ?? '') ?>" width="44" height="44" loading="lazy">
      <?php endif; ?>
      <div class="pieza__firma-datos">
        <?php if (!empty($a['author_name'])): ?>
          <strong><a href="/autor/<?= atributo($a['author_slug']) ?>"><?= e($a['author_name']) ?></a></strong>
          <?php if (!empty($a['author_role'])): ?><span><?= e($a['author_role']) ?></span><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($a['editor_name'])): ?>
          <span>Editor responsable: <?= e($a['editor_name']) ?></span>
        <?php endif; ?>
      </div>
      <div class="pieza__firma-datos">
        <span>Publicado: <time datetime="<?= atributo(fechaIso($a['published_at'])) ?>"><?= e(fechaHora($a['published_at'])) ?></time></span>
        <?php if (!empty($a['updated_content_at'])): ?>
          <span>Actualizado: <time datetime="<?= atributo(fechaIso($a['updated_content_at'])) ?>"><?= e(fechaHora($a['updated_content_at'])) ?></time></span>
        <?php endif; ?>
        <?php if (!empty($a['reading_minutes'])): ?>
          <span><?= (int) $a['reading_minutes'] ?> minutos de lectura</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- MODOS DE PROFUNDIDAD · los tres viven en esta MISMA URL -->
    <?php if (!empty($a['summary_60s']) || !empty($a['summary_5min'])): ?>
      <div class="profundidad" data-profundidad role="group" aria-label="Elige cuánta profundidad quieres">
        <?php if (!empty($a['summary_60s'])): ?>
          <button class="profundidad__boton" type="button" data-modo="60s" aria-pressed="false">60 segundos</button>
        <?php endif; ?>
        <?php if (!empty($a['summary_5min'])): ?>
          <button class="profundidad__boton" type="button" data-modo="5min" aria-pressed="false">5 minutos</button>
        <?php endif; ?>
        <button class="profundidad__boton" type="button" data-modo="sinfiltro" aria-pressed="true">Sin Filtro</button>
      </div>
    <?php endif; ?>
  </header>

  <!-- 4. IMAGEN O VIDEO PRINCIPAL -->
  <?php
  $videoPrincipal = null;
  foreach ($videos as $v) {
      if ($v['editorial_role'] === 'principal') { $videoPrincipal = $v; break; }
  }
  ?>
  <div class="contenedor lectura pieza__medio">
    <?php if ($videoPrincipal !== null): ?>
      <?= \App\Support\View::partial('partials/reproductor', ['video' => $videoPrincipal]) ?>
    <?php elseif (!empty($a['hero_path'])): ?>
      <figure>
        <img src="<?= atributo($a['hero_path']) ?>"
             alt="<?= atributo($a['hero_alt'] ?: ($a['hero_media_alt'] ?? '')) ?>"
             <?= !empty($a['hero_width']) ? 'width="' . (int) $a['hero_width'] . '" height="' . (int) $a['hero_height'] . '"' : '' ?>
             fetchpriority="high" decoding="async">
        <?php if (!empty($a['hero_is_synthetic'])): ?>
          <figcaption><span class="cuerpo__sintetica">Imagen generada con inteligencia artificial</span></figcaption>
        <?php endif; ?>
      </figure>
    <?php endif; ?>
  </div>

  <div class="contenedor">

    <!-- 5. PULSO INICIAL · nunca bloquea la lectura -->
    <?php if ($pulso !== null): ?>
      <div class="lectura">
        <?= \App\Support\View::partial('partials/pulso', [
            'pulso'           => $pulso,
            'pulsoOpciones'   => $pulsoOpciones,
            'pulsoResultados' => $pulsoResultados,
            'etapa'           => 'inicial',
            'yaVoto'          => $votoInicial,
        ]) ?>
      </div>
    <?php endif; ?>

    <!-- CAPAS DE PROFUNDIDAD -->
    <?php if (!empty($a['summary_60s'])): ?>
      <div class="lectura capa" data-capa="60s" hidden>
        <h2>Lo esencial en 60 segundos</h2>
        <div class="cuerpo"><?= \App\Support\Html::sanitize((string) $a['summary_60s']) ?></div>
        <p class="campo__ayuda">Este es el resumen corto. Cambia arriba a &laquo;5 minutos&raquo; o &laquo;Sin Filtro&raquo; para más contexto. La dirección de esta página no cambia.</p>
      </div>
    <?php endif; ?>

    <?php if (!empty($a['summary_5min'])): ?>
      <div class="lectura capa" data-capa="5min" hidden>
        <h2>En 5 minutos</h2>
        <div class="cuerpo"><?= \App\Support\Html::sanitize((string) $a['summary_5min']) ?></div>
      </div>
    <?php endif; ?>

    <div class="capa" data-capa="sinfiltro">

      <!-- 6. CONFIRMADO, PROBABLE Y TODAVIA DESCONOCIDO -->
      <?php if (!empty($a['facts_confirmed']) || !empty($a['facts_probable']) || !empty($a['facts_unknown'])): ?>
        <div class="lectura">
          <h2>Qué sabemos y qué no</h2>
          <div class="tres-puntos">
            <?php if (!empty($a['facts_confirmed'])): ?>
              <div class="tres-puntos__caja tres-puntos__caja--confirmado">
                <p class="tres-puntos__titulo certeza--confirmado">Confirmado</p>
                <p><?= nl2br(e($a['facts_confirmed'])) ?></p>
              </div>
            <?php endif; ?>
            <?php if (!empty($a['facts_probable'])): ?>
              <div class="tres-puntos__caja tres-puntos__caja--probable">
                <p class="tres-puntos__titulo certeza--probable">Probable</p>
                <p><?= nl2br(e($a['facts_probable'])) ?></p>
              </div>
            <?php endif; ?>
            <?php if (!empty($a['facts_unknown'])): ?>
              <div class="tres-puntos__caja tres-puntos__caja--desconocido">
                <p class="tres-puntos__titulo">Todavía desconocido</p>
                <p><?= nl2br(e($a['facts_unknown'])) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 7. DESARROLLO COMPLETO -->
      <?php if (!empty($a['summary'])): ?>
        <div class="lectura"><p style="font-size:var(--t-lg);color:var(--tinta-suave)"><?= e($a['summary']) ?></p></div>
      <?php endif; ?>

      <div class="cuerpo"><?= $cuerpo ?></div>

      <!-- 8. FUENTES ABIERTAS -->
      <?php if ($fuentes !== []): ?>
        <div class="lectura">
          <details class="transcripcion" data-capa-evento="fuentes_abiertas">
            <summary>Fuentes de esta pieza (<?= count($fuentes) ?>)</summary>
            <div style="padding:0 var(--e3) var(--e3)">
              <ul class="fuentes">
                <?php foreach ($fuentes as $fuente): ?>
                  <li>
                    <span class="certeza certeza--<?= atributo($fuente['certainty']) ?>"><?= e($fuente['certainty']) ?></span><br>
                    <?php if (!empty($fuente['url'])): ?>
                      <a href="<?= atributo($fuente['url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($fuente['title']) ?></a>
                    <?php else: ?>
                      <?= e($fuente['title']) ?>
                    <?php endif; ?>
                    <?php if (!empty($fuente['publisher'])): ?><br><span style="color:var(--tinta-tenue);font-size:var(--t-sm)"><?= e($fuente['publisher']) ?></span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </details>
        </div>
      <?php endif; ?>

      <!-- 9. CRONOLOGIA Y ACTORES -->
      <?php if ($cronologia !== [] || $actores !== []): ?>
        <div class="lectura">
          <?php if ($cronologia !== []): ?>
            <h2>Cronología</h2>
            <ol class="cronologia">
              <?php foreach ($cronologia as $evento): ?>
                <li>
                  <p class="cronologia__fecha"><?= e(fechaLarga($evento['occurred_on'] . ' 12:00:00')) ?></p>
                  <p class="cronologia__titulo"><?= e($evento['title']) ?></p>
                  <?php if (!empty($evento['detail'])): ?><p style="margin:0"><?= e($evento['detail']) ?></p><?php endif; ?>
                  <p style="margin:0"><span class="certeza certeza--<?= atributo($evento['certainty']) ?>"><?= e($evento['certainty']) ?></span>
                    <?php if (!empty($evento['source_url'])): ?>
                      · <a href="<?= atributo($evento['source_url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($evento['source_title']) ?></a>
                    <?php endif; ?>
                  </p>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

          <?php if ($actores !== []): ?>
            <h2>Quién es quién</h2>
            <ul class="fuentes">
              <?php foreach ($actores as $actor): ?>
                <li><strong><?= e($actor['name']) ?></strong>
                  <?php if (!empty($actor['role_note'])): ?><br><?= e($actor['role_note']) ?><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- 10. DOS PERSPECTIVAS FUERTES -->
      <?php if (!empty($a['perspective_a_body']) || !empty($a['perspective_b_body'])): ?>
        <div class="lectura">
          <h2>Las dos miradas más fuertes</h2>
          <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">El mejor argumento de cada lado, sin caricaturas. Esto es análisis, no la postura del medio.</p>
          <div class="perspectivas" data-capa-evento="perspectivas_comparadas">
            <?php if (!empty($a['perspective_a_body'])): ?>
              <article class="perspectiva">
                <h3 class="perspectiva__titulo"><?= e($a['perspective_a_title'] ?: 'A favor') ?></h3>
                <p><?= nl2br(e($a['perspective_a_body'])) ?></p>
              </article>
            <?php endif; ?>
            <?php if (!empty($a['perspective_b_body'])): ?>
              <article class="perspectiva">
                <h3 class="perspectiva__titulo"><?= e($a['perspective_b_title'] ?: 'En contra') ?></h3>
                <p><?= nl2br(e($a['perspective_b_body'])) ?></p>
              </article>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 11. CONSECUENCIAS PARA TI -->
      <?php if ($impactos !== []): ?>
        <div class="lectura">
          <h2>Qué significa para ti</h2>
          <div class="impactos">
            <?php foreach ($impactos as $impacto): ?>
              <div class="impacto">
                <p class="impacto__perfil"><?= e($impacto['profile']) ?></p>
                <p style="margin:0;font-family:var(--titulares);font-weight:700"><?= e($impacto['headline']) ?></p>
                <?php if (!empty($impacto['detail'])): ?><p style="margin:0"><?= e($impacto['detail']) ?></p><?php endif; ?>
                <p style="margin:0"><span class="certeza certeza--<?= atributo($impacto['certainty']) ?>"><?= e($impacto['certainty']) ?></span></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 12. OPINION DE MAX · separada y firmada -->
      <?php if (!empty($a['max_opinion'])): ?>
        <div class="lectura">
          <div class="franja-opinion">
            <span class="franja-opinion__sello">Opinión firmada · esto no es un hecho reportado</span>
            <?= nl2br(e($a['max_opinion'])) ?>
            <p style="margin:var(--e2) 0 0;font-size:var(--t-sm);color:var(--tinta-tenue)">
              Max González, <?= e($config['brand']['name']) ?>.
            </p>
          </div>
        </div>
      <?php endif; ?>
    </div><!-- fin capa sinfiltro -->

    <!-- 13. PULSO INFORMADO -->
    <?php if ($pulso !== null): ?>
      <div class="lectura">
        <?= \App\Support\View::partial('partials/pulso', [
            'pulso'           => $pulso,
            'pulsoOpciones'   => $pulsoOpciones,
            'pulsoResultados' => $pulsoResultados,
            'pulsoCambio'     => $pulsoCambio,
            'etapa'           => 'informado',
            'yaVoto'          => $votoInformado,
        ]) ?>
      </div>
    <?php endif; ?>

    <!-- 14. PREGUNTA VIVA Y PREGUNTAS DE LA COMUNIDAD -->
    <?php if (!empty($a['legacy_question'])): ?>
      <div class="lectura">
        <div class="pregunta-viva">
          <p class="pregunta-viva__sello">La pregunta que queda abierta</p>
          <p class="pregunta-viva__texto"><?= e($a['legacy_question']) ?></p>
          <p style="margin:0;opacity:0.8;font-size:var(--t-sm)">La próxima pieza sobre este tema partirá de aquí.</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="lectura">
      <h2>¿Qué duda te queda a ti?</h2>
      <?= \App\Support\View::partial('partials/preguntar', ['articuloId' => $id]) ?>

      <?php if ($preguntas !== []): ?>
        <h3 style="margin-top:var(--e5)">Preguntas ya publicadas sobre esta pieza</h3>
        <ul class="lista-piezas">
          <?php foreach ($preguntas as $pregunta): ?>
            <li>
              <p style="margin:0;font-weight:700">&laquo;<?= e($pregunta['body']) ?>&raquo;</p>
              <?php if (!empty($pregunta['answer'])): ?>
                <p style="margin:0"><?= nl2br(e($pregunta['answer'])) ?></p>
              <?php endif; ?>
              <p style="margin:0;font-size:var(--t-sm);color:var(--tinta-tenue)">
                <?= e($pregunta['author_name'] ?: 'Anónimo') ?> · <?= e(\App\Models\Question::STATUSES[$pregunta['status']] ?? '') ?>
              </p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- 15 y 16. VIDEOS RELACIONADOS, LIVE, TRANSCRIPCION Y CAPITULOS -->
    <?php
    $otrosVideos = array_filter($videos, static fn (array $v): bool => $v['editorial_role'] !== 'principal');
    ?>
    <?php if ($otrosVideos !== []): ?>
      <div class="lectura">
        <h2>En video</h2>
        <?php foreach ($otrosVideos as $v): ?>
          <?= \App\Support\View::partial('partials/reproductor', ['video' => $v]) ?>
          <?php $capitulos = \App\Models\Video::chapters((int) $v['id']); ?>
          <?php if ($capitulos !== []): ?>
            <h3 style="font-size:var(--t-base)">Capítulos</h3>
            <ul class="capitulos">
              <?php foreach ($capitulos as $capitulo): ?>
                <li><a href="#video-<?= (int) $v['id'] ?>" data-capitulo="<?= (int) $capitulo['starts_at'] ?>" data-video="<?= (int) $v['id'] ?>">
                  <time><?= e(duracion((int) $capitulo['starts_at'])) ?></time><span><?= e($capitulo['title']) ?></span>
                </a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php $transcripcion = \App\Models\Video::transcript((int) $v['id']); ?>
          <?php if ($transcripcion !== null): ?>
            <details class="transcripcion" data-capa-evento="transcripcion_abierta">
              <summary>Transcripción completa</summary>
              <div class="transcripcion__texto"><?= e($transcripcion['content']) ?></div>
            </details>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($lives !== []): ?>
      <div class="lectura">
        <h2>En el programa</h2>
        <?php foreach ($lives as $l): ?>
          <div class="live-destacado" style="margin-bottom:var(--e3)">
            <p><span class="live-estado live-estado--<?= atributo($l['status']) ?>"><?= e(\App\Models\Live::STATUSES[$l['status']] ?? '') ?></span></p>
            <h3 style="margin:0"><a href="/live/<?= atributo($l['slug']) ?>"><?= e($l['title']) ?></a></h3>
            <?php if (!empty($l['starts_at'])): ?>
              <p class="live-fecha"><time datetime="<?= atributo(fechaIso($l['starts_at'])) ?>"><?= e(fechaHora($l['starts_at'])) ?></time></p>
            <?php endif; ?>
            <p style="margin:0;font-size:var(--t-sm);opacity:0.75">Relacionado <?= $l['moment'] === 'antes' ? 'antes del programa' : 'después del programa' ?>.</p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- 17. HISTORIAL DE ACTUALIZACIONES Y CORRECCIONES -->
    <?php if ($correcciones !== []): ?>
      <div class="lectura">
        <h2>Historial de cambios</h2>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Corregir no resta autoridad. Ocultar una corrección sí.</p>
        <ul class="correcciones">
          <?php foreach ($correcciones as $correccion): ?>
            <li class="correccion">
              <p class="correccion__tipo"><?= e($correccion['kind']) ?></p>
              <p class="correccion__fecha"><time datetime="<?= atributo(fechaIso($correccion['corrected_at'])) ?>"><?= e(fechaHora($correccion['corrected_at'])) ?></time></p>
              <p style="margin:0"><strong><?= e($correccion['reason']) ?></strong></p>
              <?php if (!empty($correccion['detail'])): ?><p style="margin:0"><?= nl2br(e($correccion['detail'])) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ETIQUETAS Y TEMAS -->
    <?php if ($etiquetas !== [] || $temas !== []): ?>
      <div class="lectura">
        <div class="archivo-nav" style="margin-top:var(--e5)">
          <?php foreach ($temas as $tema): ?>
            <a href="/tema/<?= atributo($tema['slug']) ?>">Tema: <?= e($tema['name']) ?></a>
          <?php endforeach; ?>
          <?php foreach ($etiquetas as $etiqueta): ?>
            <a href="/etiqueta/<?= atributo($etiqueta['slug']) ?>"><?= e($etiqueta['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</article>

<!-- 18. CONTENIDO SIGUIENTE, RELACIONADO POR TEMA Y CONTEXTO -->
<?php if ($relacionadas !== []): ?>
<section class="seccion seccion--hueca">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Para seguir entendiendo</h2>
      <p class="seccion__nota">Relacionado por tema y contexto, no por popularidad.</p>
    </div>
    <div class="rejilla rejilla--4">
      <?php foreach ($relacionadas as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
