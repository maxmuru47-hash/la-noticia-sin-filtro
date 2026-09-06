<?php
/**
 * BUSCADOR. Consulta resuelta en el servidor, siempre paginada.
 * Encuentra por titulo, contenido, fecha, autor, categoria, etiqueta,
 * tema y transcripcion de video.
 */
$f = $filtros;
?>
<div class="contenedor seccion">
  <div class="seccion__cabeza">
    <h1 class="seccion__titulo">Buscador</h1>
    <p class="seccion__nota">Busca en todo el archivo, incluido lo que se dijo en cámara.</p>
  </div>

  <form action="/buscar" method="get" role="search" data-formulario-busqueda>
    <div class="buscador__campo">
      <label class="solo-lectores" for="q">Qué buscas</label>
      <input type="search" id="q" name="q" value="<?= atributo($f['q']) ?>"
             placeholder="Título, contenido, autor o transcripción" autocomplete="off">
      <button class="boton boton--rojo" type="submit">Buscar</button>
    </div>

    <div class="buscador">
      <aside class="filtros">
        <h2 style="font-size:var(--t-base);margin:0">Filtros</h2>

        <div class="filtros__grupo">
          <label for="categoria">Categoría</label>
          <select id="categoria" name="categoria">
            <option value="">Todas</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= atributo($c['slug']) ?>" <?= $f['categoria'] === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="tipo">Tipo editorial</label>
          <select id="tipo" name="tipo">
            <option value="">Todos</option>
            <?php foreach (\App\Models\Article::EDITORIAL_TYPES as $clave => $nombre): ?>
              <option value="<?= atributo($clave) ?>" <?= $f['tipo'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="autor">Autor</label>
          <select id="autor" name="autor">
            <option value="">Todos</option>
            <?php foreach ($autores as $a): ?>
              <option value="<?= atributo($a['slug']) ?>" <?= $f['autor'] === $a['slug'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="tema">Tema</label>
          <select id="tema" name="tema">
            <option value="">Todos</option>
            <?php foreach ($temas as $t): ?>
              <option value="<?= atributo($t['slug']) ?>" <?= $f['tema'] === $t['slug'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="etiqueta">Etiqueta</label>
          <select id="etiqueta" name="etiqueta">
            <option value="">Todas</option>
            <?php foreach ($etiquetas as $t): ?>
              <option value="<?= atributo($t['slug']) ?>" <?= $f['etiqueta'] === $t['slug'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label>Rango de fechas</label>
          <div class="filtros__par">
            <input type="date" name="desde" value="<?= atributo($f['desde']) ?>" aria-label="Desde">
            <input type="date" name="hasta" value="<?= atributo($f['hasta']) ?>" aria-label="Hasta">
          </div>
        </div>

        <div class="filtros__grupo">
          <label for="video">Con video</label>
          <select id="video" name="video">
            <option value="">Indiferente</option>
            <option value="si" <?= $f['video'] === 'si' ? 'selected' : '' ?>>Solo con video</option>
            <option value="no" <?= $f['video'] === 'no' ? 'selected' : '' ?>>Solo sin video</option>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="live">Live relacionado</label>
          <select id="live" name="live">
            <option value="">Cualquiera</option>
            <?php foreach ($lives as $l): ?>
              <option value="<?= atributo($l['slug']) ?>" <?= $f['live'] === $l['slug'] ? 'selected' : '' ?>><?= e($l['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filtros__grupo">
          <label for="orden">Ordenar por</label>
          <select id="orden" name="orden">
            <?php foreach (\App\Services\SearchService::ORDERS as $clave => $nombre): ?>
              <option value="<?= atributo($clave) ?>" <?= $f['order'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button class="boton" type="submit">Aplicar filtros</button>
        <a class="boton boton--linea" href="/buscar">Limpiar</a>
      </aside>

      <div>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
          <?= (int) $total ?> <?= $total === 1 ? 'resultado' : 'resultados' ?>
          <?php if ($f['q'] !== ''): ?> para &laquo;<?= e($f['q']) ?>&raquo;<?php endif; ?>
          · el archivo incluye las piezas archivadas
        </p>

        <?php if ($resultados === []): ?>
          <div class="vacio">
            <h2>Sin resultados</h2>
            <p>Prueba con menos palabras, quita algún filtro o revisa el <a href="/archivo">archivo cronológico</a>.</p>
          </div>
        <?php else: ?>
          <?php foreach ($resultados as $r): ?>
            <article class="resultado">
              <?php if (!empty($r['hero_path'])): ?>
                <div class="resultado__miniatura">
                  <img src="<?= atributo($r['hero_path']) ?>" alt="<?= atributo($r['hero_media_alt'] ?? '') ?>" loading="lazy" decoding="async">
                </div>
              <?php else: ?>
                <div></div>
              <?php endif; ?>

              <div class="resultado__cuerpo">
                <div class="resultado__meta">
                  <?= etiquetaTipo((string) $r['tipo']) ?>
                  <?= selloEstado((string) $r['status']) ?>
                  <?= selloDemo($r['is_demo'] ?? 0) ?>
                  <?php if (!empty($r['category_name'])): ?><span><?= e($r['category_name']) ?></span><?php endif; ?>
                  <time datetime="<?= atributo(fechaIso($r['published_at'])) ?>"><?= e(fechaLarga($r['published_at'])) ?></time>
                  <?php if (!empty($r['video_duration'])): ?>
                    <span>Video <?= e(duracion((int) $r['video_duration'])) ?></span>
                  <?php endif; ?>
                </div>

                <h2 class="resultado__titulo"><a href="/noticia/<?= atributo($r['slug']) ?>"><?= e($r['title']) ?></a></h2>
                <p class="resultado__fragmento"><?= $r['fragmento'] ?></p>

                <?php if (!empty($r['coincide_transcripcion'])): ?>
                  <p class="resultado__transcripcion">La coincidencia está en la transcripción del video</p>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>

          <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>
