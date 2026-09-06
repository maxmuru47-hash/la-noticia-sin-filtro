<?php
use App\Middleware\Auth;
use App\Models\Article;
use App\Models\Video;

$a       = $articulo;
$esNueva = $a === null;
$accion  = $esNueva ? '/panel/noticias/nueva' : '/panel/noticias/' . (int) $a['id'];
$puedePublicar = Auth::can(Auth::CAP_ARTICLE_PUBLISH);
?>
<div class="panel__cabeza">
  <h1><?= $esNueva ? 'Nueva pieza' : 'Editar pieza' ?></h1>
  <div style="display:flex;gap:var(--e1);align-items:center">
    <span class="autoguardado" data-autoguardado></span>
    <?php if (!$esNueva && $a['published_at'] !== null): ?>
      <a class="boton boton--linea boton--pequeno" href="/noticia/<?= atributo($a['slug']) ?>" target="_blank" rel="noopener">Ver publicada</a>
    <?php elseif (!$esNueva): ?>
      <a class="boton boton--linea boton--pequeno" href="/noticia/<?= atributo($a['slug']) ?>" target="_blank" rel="noopener">Vista previa</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$esNueva): ?>
  <p class="aviso">
    <strong>URL permanente:</strong> <code>/noticia/<?= e($a['slug']) ?></code> ·
    Estado: <strong><?= e(Article::STATUSES[$a['status']] ?? '') ?></strong>
    <?php if ($a['published_at'] !== null): ?> · Publicada el <?= e(fechaHora($a['published_at'])) ?><?php endif; ?>
    <br><small>Cambiar el título no cambia esta dirección. Nunca.</small>
  </p>
<?php endif; ?>

<form method="post" action="<?= atributo($accion) ?>" data-editor <?= $esNueva ? '' : 'data-articulo-id="' . (int) $a['id'] . '"' ?>>
  <?= \App\Support\Csrf::field() ?>
  <input type="hidden" name="bloques_json" data-bloques-json value="">

  <div class="editor">
    <div>
      <div class="panel__caja">
        <div class="campo">
          <label for="titulo">Título</label>
          <input type="text" id="titulo" name="titulo" required maxlength="255" value="<?= atributo($a['title'] ?? '') ?>">
        </div>

        <?php if ($esNueva): ?>
          <div class="campo">
            <label for="slug_inicial">Dirección deseada (opcional)</label>
            <input type="text" id="slug_inicial" name="slug_inicial" maxlength="190" placeholder="se genera desde el título">
            <span class="campo__ayuda">Esta será la URL permanente. Piénsala bien: cambiarla después exige un motivo y deja una redirección.</span>
          </div>
        <?php endif; ?>

        <div class="campo">
          <label for="pregunta_principal">Pregunta principal</label>
          <input type="text" id="pregunta_principal" name="pregunta_principal" maxlength="255" value="<?= atributo($a['lead_question'] ?? '') ?>">
          <span class="campo__ayuda">La pregunta que esta pieza responde. Es lo primero que ve el lector.</span>
        </div>

        <div class="campo">
          <label for="subtitulo">Subtítulo</label>
          <input type="text" id="subtitulo" name="subtitulo" maxlength="500" value="<?= atributo($a['subtitle'] ?? '') ?>">
        </div>

        <div class="campo">
          <label for="resumen">Resumen (bajada)</label>
          <textarea id="resumen" name="resumen"><?= e($a['summary'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="panel__caja">
        <h2>Qué sabemos y qué no</h2>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Esta separación es lo que protege la confianza. Si un dato no está confirmado, va en Probable.</p>
        <div class="campo">
          <label for="confirmado">Confirmado</label>
          <textarea id="confirmado" name="confirmado"><?= e($a['facts_confirmed'] ?? '') ?></textarea>
        </div>
        <div class="campo">
          <label for="probable">Probable</label>
          <textarea id="probable" name="probable"><?= e($a['facts_probable'] ?? '') ?></textarea>
        </div>
        <div class="campo">
          <label for="desconocido">Todavía desconocido</label>
          <textarea id="desconocido" name="desconocido"><?= e($a['facts_unknown'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="panel__caja">
        <h2>Cuerpo por bloques</h2>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
          El HTML se sanea al guardar: se conservan negrita, cursiva, enlaces y listas; se elimina cualquier script,
          estilo, iframe o atributo de evento.
        </p>

        <div class="bloques" data-bloques></div>

        <div style="display:flex;gap:var(--e1);flex-wrap:wrap;margin-top:var(--e3)">
          <?php foreach ($tiposBloque as $clave => $nombre): ?>
            <button class="mini-boton" type="button" data-agregar-bloque="<?= atributo($clave) ?>">+ <?= e($nombre) ?></button>
          <?php endforeach; ?>
        </div>

        <template data-plantilla-bloques>
          <?= json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        </template>
      </div>

      <div class="panel__caja">
        <h2>Las dos miradas más fuertes</h2>
        <div class="campo">
          <label for="perspectiva_a_titulo">Perspectiva A · título</label>
          <input type="text" id="perspectiva_a_titulo" name="perspectiva_a_titulo" maxlength="190" value="<?= atributo($a['perspective_a_title'] ?? '') ?>">
        </div>
        <div class="campo">
          <label for="perspectiva_a_cuerpo">Perspectiva A · mejor argumento</label>
          <textarea id="perspectiva_a_cuerpo" name="perspectiva_a_cuerpo"><?= e($a['perspective_a_body'] ?? '') ?></textarea>
        </div>
        <div class="campo">
          <label for="perspectiva_b_titulo">Perspectiva B · título</label>
          <input type="text" id="perspectiva_b_titulo" name="perspectiva_b_titulo" maxlength="190" value="<?= atributo($a['perspective_b_title'] ?? '') ?>">
        </div>
        <div class="campo">
          <label for="perspectiva_b_cuerpo">Perspectiva B · mejor argumento</label>
          <textarea id="perspectiva_b_cuerpo" name="perspectiva_b_cuerpo"><?= e($a['perspective_b_body'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="panel__caja">
        <h2>Opinión de Max</h2>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Se publica dentro de una franja roja rotulada como opinión firmada. Nunca se confunde con el hecho.</p>
        <div class="campo">
          <label for="opinion_max">Texto de la opinión</label>
          <textarea id="opinion_max" name="opinion_max"><?= e($a['max_opinion'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="panel__caja">
        <h2>Modos de profundidad</h2>
        <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Los tres modos viven en la MISMA dirección. Nunca se duplica la URL.</p>
        <div class="campo">
          <label for="resumen_60s">60 segundos</label>
          <textarea id="resumen_60s" name="resumen_60s"><?= e($a['summary_60s'] ?? '') ?></textarea>
        </div>
        <div class="campo">
          <label for="resumen_5min">5 minutos</label>
          <textarea id="resumen_5min" name="resumen_5min"><?= e($a['summary_5min'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="panel__caja">
        <h2>La pregunta que queda abierta</h2>
        <div class="campo">
          <label for="pregunta_viva">Pregunta viva</label>
          <input type="text" id="pregunta_viva" name="pregunta_viva" maxlength="500" value="<?= atributo($a['legacy_question'] ?? '') ?>">
          <span class="campo__ayuda">La próxima pieza sobre este tema partirá de aquí.</span>
        </div>
      </div>

      <div class="panel__caja">
        <h2>SEO y redes</h2>
        <div class="campo">
          <label for="seo_titulo">Título para buscadores</label>
          <input type="text" id="seo_titulo" name="seo_titulo" maxlength="255" value="<?= atributo($a['seo_title'] ?? '') ?>">
        </div>
        <div class="campo">
          <label for="seo_descripcion">Descripción para buscadores</label>
          <textarea id="seo_descripcion" name="seo_descripcion" maxlength="500"><?= e($a['seo_description'] ?? '') ?></textarea>
        </div>
        <div class="campo">
          <label for="social_titulo">Título para redes</label>
          <input type="text" id="social_titulo" name="social_titulo" maxlength="255" value="<?= atributo($a['social_title'] ?? '') ?>">
        </div>
        <div class="campo">
          <label for="social_descripcion">Descripción para redes</label>
          <textarea id="social_descripcion" name="social_descripcion" maxlength="500"><?= e($a['social_description'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <aside class="editor__lateral">
      <div class="panel__caja">
        <button class="boton boton--rojo" type="submit" style="width:100%">Guardar cambios</button>
        <p style="font-size:var(--t-sm);color:var(--tinta-tenue);margin-top:var(--e2)">
          Guardar no publica. Publicar es una acción aparte.
        </p>

        <div class="campo">
          <label for="nota_cambio">Nota de este cambio (opcional)</label>
          <input type="text" id="nota_cambio" name="nota_cambio" maxlength="500">
        </div>
      </div>

      <div class="panel__caja">
        <h2>Clasificación</h2>
        <div class="campo">
          <label for="tipo_editorial">Tipo editorial</label>
          <select id="tipo_editorial" name="tipo_editorial">
            <?php foreach (Article::EDITORIAL_TYPES as $clave => $nombre): ?>
              <option value="<?= atributo($clave) ?>" <?= ($a['editorial_type'] ?? 'noticia') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="categoria">Categoría</label>
          <select id="categoria" name="categoria">
            <option value="">Sin categoría</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) ($a['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="autor">Autor (firma)</label>
          <select id="autor" name="autor">
            <option value="">Sin firma</option>
            <?php foreach ($autores as $au): ?>
              <option value="<?= (int) $au['id'] ?>" <?= (int) ($a['author_id'] ?? 0) === (int) $au['id'] ? 'selected' : '' ?>><?= e($au['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="editor">Editor responsable</label>
          <select id="editor" name="editor">
            <option value="">Sin editor</option>
            <?php foreach ($autores as $au): ?>
              <option value="<?= (int) $au['id'] ?>" <?= (int) ($a['editor_id'] ?? 0) === (int) $au['id'] ? 'selected' : '' ?>><?= e($au['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="expediente">Expediente</label>
          <select id="expediente" name="expediente">
            <option value="">Sin expediente</option>
            <?php foreach ($expedientes as $exp): ?>
              <option value="<?= (int) $exp['id'] ?>" <?= (int) ($a['dossier_id'] ?? 0) === (int) $exp['id'] ? 'selected' : '' ?>><?= e($exp['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="etiquetas">Etiquetas (separadas por coma)</label>
          <input type="text" id="etiquetas" name="etiquetas" value="<?= atributo(implode(', ', array_column($etiquetas, 'name'))) ?>">
        </div>
        <div class="campo">
          <label for="temas">Temas (separados por coma)</label>
          <input type="text" id="temas" name="temas" value="<?= atributo(implode(', ', array_column($temas, 'name'))) ?>">
        </div>
        <div class="campo campo--casilla">
          <input type="checkbox" id="es_demo" name="es_demo" value="1" <?= !empty($a['is_demo']) ? 'checked' : '' ?>>
          <label for="es_demo">Contenido de demostración (se rotula visiblemente en la página)</label>
        </div>
      </div>

      <div class="panel__caja">
        <h2>Imagen principal</h2>
        <div class="campo">
          <label for="imagen_principal">Elegir de la biblioteca</label>
          <select id="imagen_principal" name="imagen_principal">
            <option value="">Sin imagen</option>
            <?php foreach ($medios as $m): ?>
              <option value="<?= (int) $m['id'] ?>" <?= (int) ($a['hero_media_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['original_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo__ayuda"><a href="/panel/medios" target="_blank" rel="noopener">Subir imágenes</a></span>
        </div>
        <div class="campo">
          <label for="imagen_alt">Texto alternativo</label>
          <input type="text" id="imagen_alt" name="imagen_alt" maxlength="255" value="<?= atributo($a['hero_alt'] ?? '') ?>">
        </div>
      </div>
    </aside>
  </div>
</form>

<?php if (!$esNueva): ?>
  <?php $id = (int) $a['id']; ?>

  <div class="panel__caja">
    <h2>Publicación</h2>
    <div style="display:flex;gap:var(--e2);flex-wrap:wrap;align-items:end">
      <?php if ($puedePublicar): ?>
        <form method="post" action="/panel/noticias/<?= $id ?>/publicar">
          <?= \App\Support\Csrf::field() ?>
          <button class="boton boton--rojo" type="submit">
            <?= $a['published_at'] === null ? 'Publicar ahora' : 'Volver a publicar' ?>
          </button>
        </form>

        <form method="post" action="/panel/noticias/<?= $id ?>/programar" style="display:flex;gap:var(--e1);align-items:end">
          <?= \App\Support\Csrf::field() ?>
          <div class="campo">
            <label for="programar_para">Programar para (hora de Venezuela)</label>
            <input type="datetime-local" id="programar_para" name="programar_para"
                   value="<?= atributo(\App\Support\Dates::toLocalInput($a['scheduled_for'])) ?>">
          </div>
          <button class="boton" type="submit">Programar</button>
        </form>
      <?php else: ?>
        <p>Tu rol no publica. Guarda y avisa a un editor.</p>
      <?php endif; ?>
    </div>
  </div>

  <?= \App\Support\View::partial('admin/partes/expediente', [
      'articulo'         => $a,
      'pulso'            => $pulso,
      'pulsoOpciones'    => $pulsoOpciones,
      'pulsoResultados'  => $pulsoResultados,
      'fuentes'          => $fuentes,
      'catalogoFuentes'  => $catalogoFuentes,
      'cronologia'       => $cronologia,
      'actores'          => $actores,
      'catalogoActores'  => $catalogoActores,
      'impactos'         => $impactos,
      'herencia'         => $herencia,
      'relacionadas'     => $relacionadas,
      'piezasDisponibles' => $piezasDisponibles,
      'preguntasDisponibles' => $preguntasDisponibles,
      'expedientes'      => $expedientes,
      'perfilesImpacto'  => $perfilesImpacto,
      'certezas'         => $certezas,
      'tiposRelacion'    => $tiposRelacion,
  ]) ?>

  <?php if (Auth::can(Auth::CAP_ARTICLE_ARCHIVE)): ?>
  <div class="panel__caja">
    <h2>Archivo</h2>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      <strong>Archivar no es eliminar.</strong> La pieza sale de portada y de los listados, pero conserva su dirección
      y sigue apareciendo en el buscador, en el archivo cronológico y en sus categorías.
    </p>
    <?php if ($a['status'] === 'archivada'): ?>
      <form method="post" action="/panel/noticias/<?= $id ?>/desarchivar">
        <?= \App\Support\Csrf::field() ?>
        <button class="boton boton--linea" type="submit">Devolver a los listados</button>
      </form>
    <?php else: ?>
      <form method="post" action="/panel/noticias/<?= $id ?>/archivar" style="display:flex;gap:var(--e1);align-items:end">
        <?= \App\Support\Csrf::field() ?>
        <div class="campo" style="flex:1">
          <label for="motivo_archivo">Motivo (opcional, queda en auditoría)</label>
          <input type="text" id="motivo_archivo" name="motivo" maxlength="500">
        </div>
        <button class="boton boton--linea" type="submit">Archivar</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel__caja">
    <h2>Correcciones</h2>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Toda corrección relevante se publica con su fecha y su motivo. Corregir no resta autoridad.</p>

    <form method="post" action="/panel/noticias/<?= $id ?>/corregir" class="formulario">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo">
        <label for="tipo_correccion">Tipo</label>
        <select id="tipo_correccion" name="tipo_correccion">
          <option value="actualizacion">Actualización</option>
          <option value="correccion">Corrección</option>
          <option value="aclaracion">Aclaración</option>
        </select>
      </div>
      <div class="campo">
        <label for="motivo_correccion">Motivo (visible al público)</label>
        <input type="text" id="motivo_correccion" name="motivo" maxlength="500" required>
      </div>
      <div class="campo">
        <label for="detalle_correccion">Qué cambió exactamente</label>
        <textarea id="detalle_correccion" name="detalle"></textarea>
      </div>
      <div class="campo campo--casilla">
        <input type="checkbox" id="correccion_privada" name="privada" value="1">
        <label for="correccion_privada">Solo interna (no se muestra en la pieza)</label>
      </div>
      <button class="boton" type="submit">Registrar corrección</button>
    </form>

    <?php if ($correcciones !== []): ?>
      <h3 style="margin-top:var(--e4)">Historial publicado</h3>
      <ul class="correcciones">
        <?php foreach ($correcciones as $c): ?>
          <li class="correccion">
            <p class="correccion__fecha"><?= e(fechaHora($c['corrected_at'])) ?> · <?= e($c['kind']) ?></p>
            <p style="margin:0"><strong><?= e($c['reason']) ?></strong></p>
            <?php if (!empty($c['detail'])): ?><p style="margin:0"><?= e($c['detail']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="panel__caja">
    <h2>Video y Live</h2>

    <form method="post" action="/panel/noticias/<?= $id ?>/video" style="display:flex;gap:var(--e1);align-items:end;flex-wrap:wrap">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo" style="flex:1;min-width:12rem">
        <label for="video_id">Relacionar video</label>
        <select id="video_id" name="video_id">
          <?php foreach ($videosDisponibles as $v): ?>
            <option value="<?= (int) $v['id'] ?>"><?= e($v['title']) ?> (<?= e($v['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="rol_video">Función editorial</label>
        <select id="rol_video" name="rol">
          <?php foreach (Video::ROLES as $clave => $nombre): ?>
            <option value="<?= atributo($clave) ?>"><?= e($nombre) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="max-width:6rem">
        <label for="orden_video">Orden</label>
        <input type="number" id="orden_video" name="orden" value="0" min="0" max="99">
      </div>
      <button class="boton boton--pequeno" type="submit">Relacionar</button>
    </form>

    <?php if ($videosLigados !== []): ?>
      <ul style="margin-top:var(--e3)">
        <?php foreach ($videosLigados as $v): ?>
          <li style="display:flex;gap:var(--e2);align-items:center;margin-bottom:var(--e1)">
            <span><?= e($v['title']) ?> — <em><?= e(Video::ROLES[$v['editorial_role']] ?? $v['editorial_role']) ?></em></span>
            <form method="post" action="/panel/noticias/<?= $id ?>/video/quitar">
              <?= \App\Support\Csrf::field() ?>
              <input type="hidden" name="video_id" value="<?= (int) $v['id'] ?>">
              <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" action="/panel/noticias/<?= $id ?>/live" style="display:flex;gap:var(--e1);align-items:end;flex-wrap:wrap;margin-top:var(--e3)">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo" style="flex:1;min-width:12rem">
        <label for="live_id">Relacionar Live</label>
        <select id="live_id" name="live_id">
          <?php foreach ($livesDisponibles as $l): ?>
            <option value="<?= (int) $l['id'] ?>"><?= e($l['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="momento">Momento</label>
        <select id="momento" name="momento">
          <option value="antes">Antes del programa</option>
          <option value="despues">Después del programa</option>
        </select>
      </div>
      <button class="boton boton--pequeno" type="submit">Relacionar</button>
    </form>

    <?php if ($livesLigados !== []): ?>
      <ul style="margin-top:var(--e2)">
        <?php foreach ($livesLigados as $l): ?>
          <li><?= e($l['title']) ?> — <?= e($l['moment']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if ($revisiones !== []): ?>
  <div class="panel__caja">
    <h2>Historial de versiones</h2>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      Restaurar devuelve solo el <strong>texto</strong> de una versión anterior. No cambia el estado,
      ni la fecha de publicación, ni la dirección. Y deja su propia versión en el historial,
      así que tampoco se pierde lo que había antes de restaurar.
    </p>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Versión</th><th>Cuándo</th><th>Quién</th><th>Estado</th><th>Nota</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($revisiones as $r): ?>
          <tr>
            <td class="numero">#<?= (int) $r['revision_no'] ?></td>
            <td class="numero"><?= e(fechaHora($r['created_at'])) ?></td>
            <td><?= e($r['user_name'] ?? 'sistema') ?></td>
            <td><?= e($r['status'] ?? '') ?></td>
            <td><?= e($r['change_note'] ?? '') ?></td>
            <td>
              <?php if (Auth::can(Auth::CAP_ARTICLE_EDIT_ANY)): ?>
                <form method="post" action="/panel/noticias/<?= $id ?>/version/restaurar">
                  <?= \App\Support\Csrf::field() ?>
                  <input type="hidden" name="revision" value="<?= (int) $r['revision_no'] ?>">
                  <button class="mini-boton" type="submit">Restaurar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (Auth::can(Auth::CAP_REDIRECT_MANAGE)): ?>
  <div class="panel__caja panel__caja--peligro">
    <h2>Cambiar la dirección</h2>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      Solo por excepción. La dirección anterior quedará redirigida de forma permanente (301) y reservada para siempre:
      nunca servirá a otra pieza.
    </p>
    <form method="post" action="/panel/noticias/<?= $id ?>/url" class="formulario">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo">
        <label for="slug_nuevo">Nueva dirección</label>
        <input type="text" id="slug_nuevo" name="slug" maxlength="190" value="<?= atributo($a['slug']) ?>" required>
      </div>
      <div class="campo">
        <label for="motivo_url">Motivo (obligatorio)</label>
        <input type="text" id="motivo_url" name="motivo" maxlength="255" required>
      </div>
      <button class="boton boton--peligro" type="submit">Cambiar la dirección</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (Auth::can(Auth::CAP_ARTICLE_WITHDRAW)): ?>
  <div class="panel__caja panel__caja--peligro">
    <h2>Retirar la pieza</h2>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      Medida excepcional. La página permanece publicada y muestra el motivo del retiro,
      porque retirar no es borrar el registro de lo que se publicó.
    </p>
    <form method="post" action="/panel/noticias/<?= $id ?>/retirar" class="formulario">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo">
        <label for="motivo_retiro">Motivo público (obligatorio)</label>
        <textarea id="motivo_retiro" name="motivo" required></textarea>
      </div>
      <div class="campo campo--casilla">
        <input type="checkbox" id="noindex_retiro" name="noindex" value="1">
        <label for="noindex_retiro">Además, pedir a los buscadores que no la indexen (solo por prohibición legal)</label>
      </div>
      <button class="boton boton--peligro" type="submit">Retirar</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (Auth::can(Auth::CAP_ARTICLE_DESTROY)): ?>
  <div class="panel__caja panel__caja--peligro">
    <h2>Eliminación definitiva</h2>
    <p class="aviso aviso--error">
      <strong>Esto no se puede deshacer.</strong> Solo el rol superior puede hacerlo, exige escribir la dirección exacta
      de la pieza, y queda registrado en auditoría con una copia completa del contenido.
      La dirección nunca se reutilizará. En operación normal, archiva en lugar de eliminar.
    </p>
    <form method="post" action="/panel/noticias/<?= $id ?>/eliminar" class="formulario">
      <?= \App\Support\Csrf::field() ?>
      <div class="campo">
        <label for="motivo_eliminar">Motivo (obligatorio)</label>
        <input type="text" id="motivo_eliminar" name="motivo" maxlength="500" required>
      </div>
      <div class="campo">
        <label for="confirmacion">Escribe <code><?= e($a['slug']) ?></code> para confirmar</label>
        <input type="text" id="confirmacion" name="confirmacion" required autocomplete="off">
      </div>
      <button class="boton boton--peligro" type="submit">Eliminar definitivamente</button>
    </form>
  </div>
  <?php endif; ?>
<?php endif; ?>
