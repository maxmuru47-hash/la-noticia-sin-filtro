<?php
/**
 * PORTADA
 *
 * La portada selecciona y ordena. No almacena noticias ni las elimina
 * cuando cambia la seleccion.
 */
$heroFuente = $config['hero']['enabled'] ? $config['hero']['video_path'] : null;
$heroPoster = $config['hero']['poster_path'];
?>

<!-- 1. HERO CINEMATOGRAFICO
     Una sola historia, no un mosaico de veinte titulares. Si el video
     no carga, o si la pantalla no es adecuada, queda esta misma portada
     con la imagen compuesta: completa, no una disculpa. -->
<section class="hero<?= $heroFuente === null ? ' hero--estatico' : '' ?>"
         data-hero
         <?= $heroFuente !== null ? 'data-hero-fuente="' . atributo($heroFuente) . '"' : '' ?>
         aria-labelledby="hero-titulo">
  <div class="hero__escenario">
    <div class="hero__marco">
      <img class="hero__poster" src="<?= atributo($heroPoster) ?>" alt="" aria-hidden="true" fetchpriority="high" decoding="async">
      <?php if ($heroFuente !== null): ?>
        <video class="hero__video" data-hero-video muted playsinline preload="none" aria-hidden="true" tabindex="-1"></video>
      <?php endif; ?>
      <div class="hero__velo" aria-hidden="true"></div>

      <div class="hero__contenido contenedor">
        <p class="hero__sello">La noticia viva</p>

        <?php if ($hero !== null): ?>
          <h1 class="hero__pregunta" id="hero-titulo">
            <?= e($hero['lead_question'] ?: $hero['title']) ?>
          </h1>
          <p class="hero__bajada"><?= e(extracto((string) ($hero['summary'] ?? $hero['subtitle'] ?? ''), 220)) ?></p>
          <div class="hero__acciones">
            <a class="boton boton--rojo" href="/noticia/<?= atributo($hero['slug']) ?>">Entrar a la historia</a>
            <a class="boton boton--claro" href="#pulso-del-dia">Responder el pulso</a>
          </div>
        <?php else: ?>
          <h1 class="hero__pregunta" id="hero-titulo"><?= e($config['brand']['promise']) ?></h1>
          <p class="hero__bajada">Medio venezolano de comprensión y conversación. Todavía no hay una historia principal seleccionada.</p>
          <div class="hero__acciones">
            <a class="boton boton--rojo" href="/archivo">Ver el archivo</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($heroFuente !== null): ?>
        <div class="hero__carga" data-hero-carga hidden>
          <span>Cargando</span>
          <span class="hero__carga-pista"><span class="hero__carga-barra" data-hero-barra></span></span>
        </div>
        <p class="hero__pista" data-hero-pista>Desplázate</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- 2 y 3. PREGUNTA PRINCIPAL Y PULSO DEL DIA -->
<?php if ($pulso !== null): ?>
<section class="seccion seccion--hueca" id="pulso-del-dia">
  <div class="contenedor lectura">
    <?= \App\Support\View::partial('partials/pulso', [
        'pulso'           => $pulso,
        'pulsoOpciones'   => $pulsoOpciones,
        'pulsoResultados' => $pulsoResultados,
        'etapa'           => 'inicial',
        'yaVoto'          => false,
    ]) ?>
    <?php if (!empty($pulso['article_slug'])): ?>
      <p style="text-align:center">
        <a class="seccion__enlace" href="/noticia/<?= atributo($pulso['article_slug']) ?>">Ver el contexto completo</a>
      </p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- 4. LO ESENCIAL AHORA · maximo tres asuntos con consecuencia practica -->
<?php if ($esencial !== []): ?>
<section class="seccion">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Lo esencial ahora</h2>
      <p class="seccion__nota">Tres asuntos, no veinte titulares.</p>
    </div>
    <div class="rejilla rejilla--3">
      <?php foreach (array_slice($esencial, 0, 3) as $indice => $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', [
            'pieza'     => $pieza,
            'destacada' => true,
            'numero'    => $indice + 1,
        ]) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 5. MAX LO ANALIZA · rotulado siempre como analisis u opinion -->
<?php if ($maxAnaliza !== null || $maxPiezas !== []): ?>
<section class="seccion seccion--oscura">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Max lo analiza</h2>
      <p class="seccion__nota">Postura firmada. Esto es opinión y análisis, no reporte de hechos.</p>
    </div>

    <div class="rejilla rejilla--2">
      <?php if ($maxAnaliza !== null): ?>
        <div><?= \App\Support\View::partial('partials/reproductor', ['video' => $maxAnaliza]) ?></div>
      <?php endif; ?>

      <div class="rejilla rejilla--2" style="align-content:start">
        <?php foreach ($maxPiezas as $pieza): ?>
          <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 6. LA CONVERSACION SE MOVIO
     Solo aparece si hay respuestas suficientes. Nunca se fabrica un
     resultado para llenar la seccion. -->
<?php if ($conversacion !== []): ?>
<section class="seccion">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">La conversación se movió</h2>
      <p class="seccion__nota">Qué pensaba la audiencia antes de leer, y qué respondió después.</p>
    </div>

    <div class="rejilla rejilla--2">
      <?php foreach ($conversacion as $caso): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__cuerpo">
            <div class="tarjeta__meta"><?= etiquetaTipo((string) $caso['articulo']['editorial_type']) ?></div>
            <h3 class="tarjeta__titulo">
              <a href="/noticia/<?= atributo($caso['articulo']['slug']) ?>"><?= e($caso['articulo']['title']) ?></a>
            </h3>
            <p class="tarjeta__resumen"><?= e($caso['pulso']['question']) ?></p>

            <div class="pulso__resultado">
              <?php foreach ($caso['resultados']['opciones'] as $fila): ?>
                <div class="pulso__fila">
                  <div class="pulso__fila-cabeza">
                    <span><?= e($fila['label']) ?></span>
                    <strong>
                      <?= e(number_format((float) $fila['inicial_pct'], 0)) ?>% →
                      <?= e(number_format((float) $fila['informado_pct'], 0)) ?>%
                      <span class="pulso__cambio <?= $fila['cambio_pct'] >= 0 ? 'pulso__cambio--sube' : 'pulso__cambio--baja' ?>">
                        <?= $fila['cambio_pct'] >= 0 ? '+' : '' ?><?= e(number_format((float) $fila['cambio_pct'], 1)) ?>
                      </span>
                    </strong>
                  </div>
                  <div class="pulso__pista">
                    <div class="pulso__barra pulso__barra--informado" style="width:<?= (float) $fila['informado_pct'] ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <p class="pulso__metodologia">
              <?= e(number_format((float) $caso['cambio']['porcentaje'], 1)) ?>% de quienes completaron las dos etapas cambió de postura.
              Voto anónimo y no representativo.
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 7. PROXIMO LIVE · una sola fuente de verdad para fecha y estado -->
<?php if ($proximoLive !== null): ?>
<section class="seccion seccion--carbon">
  <div class="contenedor">
    <div class="live-destacado">
      <p><span class="live-estado live-estado--<?= atributo($proximoLive['status']) ?>">
        <?= e(\App\Models\Live::STATUSES[$proximoLive['status']] ?? $proximoLive['status']) ?>
      </span> <?= selloDemo($proximoLive['is_demo'] ?? 0) ?></p>

      <h2 style="margin:0"><?= e($proximoLive['title']) ?></h2>

      <?php if (!empty($proximoLive['lead_question'])): ?>
        <p style="font-size:var(--t-lg);margin:0"><?= e($proximoLive['lead_question']) ?></p>
      <?php endif; ?>

      <?php if (!empty($proximoLive['starts_at'])): ?>
        <p class="live-fecha">
          <time datetime="<?= atributo(fechaIso($proximoLive['starts_at'])) ?>"><?= e(fechaHora($proximoLive['starts_at'])) ?></time>
          <span style="font-size:var(--t-sm);opacity:0.7">(hora de Venezuela)</span>
        </p>
      <?php endif; ?>

      <div class="hero__acciones">
        <a class="boton boton--rojo" href="/live/<?= atributo($proximoLive['slug']) ?>">Ver el programa y dejar tu pregunta</a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 8. ULTIMOS VIDEOS -->
<?php if ($videos !== []): ?>
<section class="seccion">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Últimos videos</h2>
      <a class="seccion__enlace" href="/videos">Ver todos</a>
    </div>
    <div class="rejilla rejilla--4">
      <?php foreach ($videos as $v): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__medio">
            <?php if (!empty($v['poster_path']) || !empty($v['thumbnail_path'])): ?>
              <img src="<?= atributo($v['poster_path'] ?: $v['thumbnail_path']) ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
            <?php if (!empty($v['duration_seconds'])): ?>
              <span class="tarjeta__duracion"><?= e(duracion((int) $v['duration_seconds'])) ?></span>
            <?php endif; ?>
          </div>
          <div class="tarjeta__cuerpo">
            <div class="tarjeta__meta">
              <span class="etiqueta-tipo"><?= e(\App\Models\Video::TYPES[$v['video_type']] ?? 'Video') ?></span>
              <?= selloDemo($v['is_demo'] ?? 0) ?>
            </div>
            <h3 class="tarjeta__titulo"><a href="/video/<?= atributo($v['slug']) ?>"><?= e($v['title']) ?></a></h3>
            <div class="tarjeta__pie">
              <time datetime="<?= atributo(fechaIso($v['published_at'])) ?>"><?= e(fechaLarga($v['published_at'])) ?></time>
              <?php if (!empty($v['captions_path'])): ?><span aria-hidden="true">·</span><span>Subtítulos</span><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 9. EXPEDIENTES VIVOS -->
<?php if ($expedientes !== []): ?>
<section class="seccion seccion--hueca">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Expedientes vivos</h2>
      <p class="seccion__nota">Temas que no se cierran cuando termina la noticia.</p>
    </div>
    <div class="rejilla rejilla--3">
      <?php foreach ($expedientes as $exp): ?>
        <article class="tarjeta" data-entra>
          <div class="tarjeta__cuerpo">
            <p class="expediente__estado"><?= e(str_replace('_', ' ', (string) $exp['status'])) ?></p>
            <h3 class="tarjeta__titulo"><a href="/expediente/<?= atributo($exp['slug']) ?>"><?= e($exp['title']) ?></a></h3>
            <?php if (!empty($exp['lead_question'])): ?>
              <p class="tarjeta__resumen"><?= e($exp['lead_question']) ?></p>
            <?php endif; ?>
            <div class="tarjeta__pie"><?= (int) ($exp['piezas'] ?? 0) ?> piezas en el expediente</div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 10. PREGUNTAS DE LA COMUNIDAD -->
<section class="seccion">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Preguntas de la comunidad</h2>
      <p class="seccion__nota">Dudas seleccionadas que están en investigación.</p>
    </div>

    <div class="rejilla rejilla--2">
      <div>
        <?php if ($preguntas === []): ?>
          <p>Todavía no hay preguntas publicadas. La primera puede ser la tuya.</p>
        <?php else: ?>
          <ul class="lista-piezas">
            <?php foreach ($preguntas as $pregunta): ?>
              <li>
                <p style="margin:0;font-family:var(--titulares);font-weight:700">&laquo;<?= e($pregunta['body']) ?>&raquo;</p>
                <p style="margin:0;font-size:var(--t-sm);color:var(--tinta-tenue)">
                  <?= e($pregunta['author_name'] ?: 'Anónimo') ?>
                  <?php if ($pregunta['status'] === 'respondida' && !empty($pregunta['answered_slug'])): ?>
                    · <a href="/noticia/<?= atributo($pregunta['answered_slug']) ?>">Respondida aquí</a>
                  <?php elseif ($pregunta['status'] === 'seleccionada'): ?>
                    · Seleccionada para el próximo Live
                  <?php endif; ?>
                </p>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div>
        <h3>Deja tu pregunta</h3>
        <?= \App\Support\View::partial('partials/preguntar', []) ?>
      </div>
    </div>
  </div>
</section>

<!-- 11. ARCHIVO Y BUSCADOR -->
<section class="seccion seccion--oscura">
  <div class="contenedor">
    <div class="seccion__cabeza">
      <h2 class="seccion__titulo">Archivo y buscador</h2>
      <p class="seccion__nota">Ninguna pieza publicada se elimina. Todo se puede encontrar años después.</p>
    </div>

    <form class="buscador__campo" action="/buscar" method="get" role="search">
      <label class="solo-lectores" for="buscar-portada">Buscar en el archivo permanente</label>
      <input type="search" id="buscar-portada" name="q" placeholder="Busca por título, contenido, autor o lo que se dijo en un video">
      <button class="boton boton--rojo" type="submit">Buscar</button>
    </form>

    <?php if ($recientes !== []): ?>
      <div class="rejilla rejilla--3" style="margin-top:var(--e5)">
        <?php foreach ($recientes as $pieza): ?>
          <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($categorias !== []): ?>
      <div class="archivo-nav" style="margin-top:var(--e5)">
        <?php foreach ($categorias as $categoria): ?>
          <a href="/categoria/<?= atributo($categoria['slug']) ?>"><?= e($categoria['name']) ?> (<?= (int) $categoria['total'] ?>)</a>
        <?php endforeach; ?>
        <a href="/archivo">Archivo completo</a>
      </div>
    <?php endif; ?>
  </div>
</section>
