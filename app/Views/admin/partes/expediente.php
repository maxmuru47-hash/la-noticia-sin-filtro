<?php
/**
 * EXPEDIENTE DE LA PIEZA
 *
 * Todo lo que convierte una noticia en una Noticia Viva. Antes solo se
 * podia sembrar con un script; ahora se escribe aqui.
 */
use App\Support\Csrf;

$id  = (int) $articulo['id'];
$url = '/panel/noticias/' . $id;
?>

<h2 id="expediente" style="margin-top:var(--e6);padding-top:var(--e4);border-top:3px solid var(--tinta)">
  Expediente de la pieza
</h2>
<p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
  Lo que distingue una Noticia Viva de un artículo suelto. Nada de esto es obligatorio,
  pero cada parte que completes hace la pieza más útil y más difícil de discutir.
</p>

<div class="panel__caja">
  <h3 style="margin-top:0">El Pulso</h3>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    La misma pregunta antes y después de leer. Mide si la información cambió la postura de alguien.
    <strong>Los resultados se calculan de los votos y no se pueden editar desde ningún sitio.</strong>
  </p>

  <?php if ($pulso !== null && $pulsoResultados !== null): ?>
    <p class="aviso">
      Respuestas registradas: <strong><?= (int) $pulsoResultados['total_inicial'] ?></strong> antes de leer,
      <strong><?= (int) $pulsoResultados['total_informado'] ?></strong> después.
      <?php if (!$pulsoResultados['suficiente']): ?>
        Todavía son pocas para mostrar un resultado con sentido, así que la web no lo presenta como válido.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/pulso">
    <?= Csrf::field() ?>
    <div class="campo">
      <label for="pulso_pregunta">Pregunta</label>
      <input type="text" id="pulso_pregunta" name="pulso_pregunta" maxlength="500" required
             value="<?= atributo($pulso['question'] ?? '') ?>"
             placeholder="¿Crees que esta medida afectará más a los hogares o a los comercios?">
      <span class="campo__ayuda">Que se pueda responder honestamente desde varios lados. Una pregunta con una sola respuesta razonable no mide nada.</span>
    </div>

    <div class="campo">
      <label for="pulso_opciones">Opciones, una por línea</label>
      <textarea id="pulso_opciones" name="pulso_opciones" rows="5" required><?php
        foreach ($pulsoOpciones as $opcion) { echo e($opcion['label']) . "\n"; }
      ?></textarea>
      <span class="campo__ayuda">
        Entre dos y seis. Puedes corregir el texto de una opción existente, pero
        <strong>no se puede quitar una que ya tiene votos</strong>: falsearía el resultado.
      </span>
    </div>

    <div class="campo">
      <label for="pulso_metodologia">Nota de metodología</label>
      <textarea id="pulso_metodologia" name="pulso_metodologia" rows="2"><?= e($pulso['methodology_note'] ?? '') ?></textarea>
      <span class="campo__ayuda">Si la dejas vacía se publica la nota estándar: voto anónimo y no representativo.</span>
    </div>

    <div class="campo campo--casilla">
      <input type="checkbox" id="pulso_abierto" name="pulso_abierto" value="1" <?= ($pulso === null || (int) $pulso['is_open'] === 1) ? 'checked' : '' ?>>
      <label for="pulso_abierto">Abierto: la gente puede votar</label>
    </div>

    <button class="boton boton--rojo" type="submit"><?= $pulso === null ? 'Crear el pulso' : 'Guardar el pulso' ?></button>
  </form>
</div>

<div class="panel__caja">
  <h3 style="margin-top:0">Fuentes</h3>

  <?php if ($fuentes !== []): ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Fuente</th><th>Tipo</th><th>Certeza</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($fuentes as $f): ?>
          <tr>
            <td><?= e($f['title']) ?>
              <?php if (!empty($f['url'])): ?><br><small><a href="<?= atributo($f['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e(extracto((string) $f['url'], 60)) ?></a></small><?php endif; ?>
            </td>
            <td><?= e($f['source_type']) ?></td>
            <td><span class="certeza certeza--<?= atributo($f['certainty']) ?>"><?= e($f['certainty']) ?></span></td>
            <td>
              <form method="post" action="<?= atributo($url) ?>/fuente/quitar">
                <?= Csrf::field() ?>
                <input type="hidden" name="fuente_id" value="<?= (int) $f['id'] ?>">
                <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p style="color:var(--tinta-tenue)">Sin fuentes adjuntas. Una pieza sin fuentes abiertas es una pieza que pide fe.</p>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/fuente" style="margin-top:var(--e3)">
    <?= Csrf::field() ?>
    <div class="campo">
      <label for="fuente_id">Del catálogo</label>
      <select id="fuente_id" name="fuente_id">
        <option value="0">— Crear una fuente nueva —</option>
        <?php foreach ($catalogoFuentes as $f): ?>
          <option value="<?= (int) $f['id'] ?>"><?= e($f['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <details>
      <summary style="cursor:pointer;font-size:var(--t-sm);min-height:44px;display:flex;align-items:center">Datos de una fuente nueva</summary>
      <div style="display:grid;gap:var(--e2);margin-top:var(--e2)">
        <div class="campo">
          <label for="fuente_titulo">Título</label>
          <input type="text" id="fuente_titulo" name="fuente_titulo" maxlength="255">
        </div>
        <div class="campo">
          <label for="fuente_editor">Quién la publica</label>
          <input type="text" id="fuente_editor" name="fuente_editor" maxlength="190">
        </div>
        <div class="campo">
          <label for="fuente_url">Enlace al documento original</label>
          <input type="url" id="fuente_url" name="fuente_url" placeholder="https://">
        </div>
        <div class="campo">
          <label for="fuente_tipo">Tipo</label>
          <select id="fuente_tipo" name="fuente_tipo">
            <?php foreach (['documento' => 'Documento', 'declaracion' => 'Declaración', 'medio' => 'Otro medio', 'dato' => 'Dato', 'entrevista' => 'Entrevista', 'otro' => 'Otro'] as $k => $v): ?>
              <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="fuente_fecha">Fecha del documento</label>
          <input type="date" id="fuente_fecha" name="fuente_fecha">
        </div>
      </div>
    </details>

    <div class="panel__filtros" style="margin-top:var(--e2)">
      <div class="campo">
        <label for="fuente_certeza">Nivel de certeza</label>
        <select id="fuente_certeza" name="fuente_certeza">
          <?php foreach ($certezas as $k => $v): ?>
            <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="max-width:6rem">
        <label for="fuente_orden">Orden</label>
        <input type="number" id="fuente_orden" name="fuente_orden" value="0" min="0" max="99">
      </div>
      <button class="boton boton--pequeno" type="submit">Adjuntar fuente</button>
    </div>
  </form>
</div>

<div class="panel__caja">
  <h3 style="margin-top:0">Cronología</h3>

  <?php if ($cronologia !== []): ?>
    <ol class="cronologia">
      <?php foreach ($cronologia as $ev): ?>
        <li>
          <p class="cronologia__fecha"><?= e(fechaLarga($ev['occurred_on'] . ' 12:00:00')) ?></p>
          <p class="cronologia__titulo"><?= e($ev['title']) ?></p>
          <?php if (!empty($ev['detail'])): ?><p style="margin:0"><?= e($ev['detail']) ?></p><?php endif; ?>
          <p style="margin:var(--e1) 0 0;display:flex;gap:var(--e2);align-items:center">
            <span class="certeza certeza--<?= atributo($ev['certainty']) ?>"><?= e($ev['certainty']) ?></span>
            <form method="post" action="<?= atributo($url) ?>/cronologia/quitar">
              <?= Csrf::field() ?>
              <input type="hidden" name="evento_id" value="<?= (int) $ev['id'] ?>">
              <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
            </form>
          </p>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php else: ?>
    <p style="color:var(--tinta-tenue)">Sin cronología. Es lo que convierte una noticia en un expediente que se puede seguir.</p>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/cronologia" style="margin-top:var(--e3)">
    <?= Csrf::field() ?>
    <div class="panel__filtros">
      <div class="campo">
        <label for="evento_fecha">Fecha</label>
        <input type="date" id="evento_fecha" name="evento_fecha" required>
      </div>
      <div class="campo" style="max-width:8rem">
        <label for="evento_hora">Hora (opcional)</label>
        <input type="time" id="evento_hora" name="evento_hora">
      </div>
      <div class="campo" style="flex:1;min-width:14rem">
        <label for="evento_titulo">Qué ocurrió</label>
        <input type="text" id="evento_titulo" name="evento_titulo" maxlength="255" required>
      </div>
    </div>
    <div class="campo">
      <label for="evento_detalle">Detalle</label>
      <textarea id="evento_detalle" name="evento_detalle" rows="2"></textarea>
    </div>
    <div class="panel__filtros">
      <div class="campo">
        <label for="evento_certeza">Certeza</label>
        <select id="evento_certeza" name="evento_certeza">
          <?php foreach ($certezas as $k => $v): ?>
            <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="evento_fuente">Fuente que lo respalda</label>
        <select id="evento_fuente" name="evento_fuente">
          <option value="">—</option>
          <?php foreach ($catalogoFuentes as $f): ?>
            <option value="<?= (int) $f['id'] ?>"><?= e($f['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="evento_expediente">Añadir también al expediente</label>
        <select id="evento_expediente" name="evento_expediente">
          <option value="">Solo a esta pieza</option>
          <?php foreach ($expedientes as $exp): ?>
            <option value="<?= (int) $exp['id'] ?>" <?= (int) ($articulo['dossier_id'] ?? 0) === (int) $exp['id'] ? 'selected' : '' ?>><?= e($exp['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="boton boton--pequeno" type="submit">Añadir evento</button>
    </div>
  </form>
</div>

<div class="panel__caja">
  <h3 style="margin-top:0">Quién es quién</h3>

  <?php if ($actores !== []): ?>
    <ul style="margin:0 0 var(--e3);padding-left:1.2rem">
      <?php foreach ($actores as $ac): ?>
        <li style="display:flex;gap:var(--e2);align-items:center;margin-bottom:var(--e1)">
          <span><strong><?= e($ac['name']) ?></strong><?php if (!empty($ac['role_note'])): ?> — <?= e($ac['role_note']) ?><?php endif; ?></span>
          <form method="post" action="<?= atributo($url) ?>/actor/quitar">
            <?= Csrf::field() ?>
            <input type="hidden" name="actor_id" value="<?= (int) $ac['id'] ?>">
            <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p style="color:var(--tinta-tenue)">Sin actores. Ayudan al lector que llega sin contexto.</p>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/actor">
    <?= Csrf::field() ?>
    <div class="panel__filtros">
      <div class="campo">
        <label for="actor_id">Del catálogo</label>
        <select id="actor_id" name="actor_id">
          <option value="0">— Crear uno nuevo —</option>
          <?php foreach ($catalogoActores as $ac): ?>
            <option value="<?= (int) $ac['id'] ?>"><?= e($ac['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="actor_nombre">Nombre (si es nuevo)</label>
        <input type="text" id="actor_nombre" name="actor_nombre" maxlength="190">
      </div>
      <div class="campo">
        <label for="actor_tipo">Tipo</label>
        <select id="actor_tipo" name="actor_tipo">
          <?php foreach (['persona' => 'Persona', 'institucion' => 'Institución', 'empresa' => 'Empresa', 'colectivo' => 'Colectivo', 'otro' => 'Otro'] as $k => $v): ?>
            <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="flex:1;min-width:12rem">
        <label for="actor_papel">Qué papel juega aquí</label>
        <input type="text" id="actor_papel" name="actor_papel" maxlength="255">
      </div>
      <button class="boton boton--pequeno" type="submit">Añadir</button>
    </div>
  </form>
</div>

<div class="panel__caja">
  <h3 style="margin-top:0">Qué significa para ti</h3>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    Aquí una noticia deja de ser información y empieza a ser útil. Una consecuencia concreta por perfil.
  </p>

  <?php if ($impactos !== []): ?>
    <div class="impactos" style="margin-bottom:var(--e3)">
      <?php foreach ($impactos as $im): ?>
        <div class="impacto">
          <p class="impacto__perfil"><?= e($perfilesImpacto[$im['profile']] ?? $im['profile']) ?></p>
          <p style="margin:0;font-weight:700"><?= e($im['headline']) ?></p>
          <?php if (!empty($im['detail'])): ?><p style="margin:0"><?= e($im['detail']) ?></p><?php endif; ?>
          <p style="margin:var(--e1) 0 0;display:flex;gap:var(--e2);align-items:center">
            <span class="certeza certeza--<?= atributo($im['certainty']) ?>"><?= e($im['certainty']) ?></span>
            <form method="post" action="<?= atributo($url) ?>/impacto/quitar">
              <?= Csrf::field() ?>
              <input type="hidden" name="impacto_id" value="<?= (int) $im['id'] ?>">
              <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
            </form>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/impacto">
    <?= Csrf::field() ?>
    <div class="panel__filtros">
      <div class="campo">
        <label for="impacto_perfil">Perfil</label>
        <select id="impacto_perfil" name="impacto_perfil">
          <?php foreach ($perfilesImpacto as $k => $v): ?>
            <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="flex:1;min-width:16rem">
        <label for="impacto_titular">Qué cambia, en una frase</label>
        <input type="text" id="impacto_titular" name="impacto_titular" maxlength="255" required>
      </div>
      <div class="campo">
        <label for="impacto_certeza">Certeza</label>
        <select id="impacto_certeza" name="impacto_certeza">
          <?php foreach ($certezas as $k => $v): ?>
            <option value="<?= atributo($k) ?>" <?= $k === 'probable' ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="campo">
      <label for="impacto_detalle">Detalle</label>
      <textarea id="impacto_detalle" name="impacto_detalle" rows="2"></textarea>
    </div>
    <button class="boton boton--pequeno" type="submit">Añadir consecuencia</button>
  </form>
</div>

<div class="panel__caja">
  <h3 style="margin-top:0">De dónde nació esta pieza</h3>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    La pregunta heredada. Así se construye memoria en lugar de acumular artículos sueltos.
  </p>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/herencia">
    <?= Csrf::field() ?>
    <div class="panel__filtros">
      <div class="campo" style="flex:1;min-width:14rem">
        <label for="herencia_pieza">Nació de esta pieza</label>
        <select id="herencia_pieza" name="herencia_pieza">
          <option value="">—</option>
          <?php foreach ($piezasDisponibles as $pz): ?>
            <option value="<?= (int) $pz['id'] ?>" <?= (int) ($herencia['parent_article_id'] ?? 0) === (int) $pz['id'] ? 'selected' : '' ?>><?= e(extracto((string) $pz['title'], 70)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="flex:1;min-width:14rem">
        <label for="herencia_pregunta">Y de esta duda de la audiencia</label>
        <select id="herencia_pregunta" name="herencia_pregunta">
          <option value="">—</option>
          <?php foreach ($preguntasDisponibles as $pq): ?>
            <option value="<?= (int) $pq['id'] ?>" <?= (int) ($herencia['question_id'] ?? 0) === (int) $pq['id'] ? 'selected' : '' ?>><?= e(extracto((string) $pq['body'], 70)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="boton boton--pequeno" type="submit">Guardar</button>
    </div>
  </form>

  <h3 style="margin-top:var(--e5)">Piezas relacionadas a mano</h3>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    La web ya relaciona por tema y expediente. Esto es para cuando quieres decirlo tú.
  </p>

  <?php if ($relacionadas !== []): ?>
    <ul style="margin:0 0 var(--e3);padding-left:1.2rem">
      <?php foreach ($relacionadas as $rel): ?>
        <li style="display:flex;gap:var(--e2);align-items:center;margin-bottom:var(--e1)">
          <span><em><?= e($tiposRelacion[$rel['relation']] ?? $rel['relation']) ?>:</em> <?= e($rel['title']) ?></span>
          <form method="post" action="<?= atributo($url) ?>/relacionada/quitar">
            <?= Csrf::field() ?>
            <input type="hidden" name="relacionada_id" value="<?= (int) $rel['id'] ?>">
            <button class="mini-boton mini-boton--peligro" type="submit">Quitar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form class="formulario" method="post" action="<?= atributo($url) ?>/relacionada">
    <?= Csrf::field() ?>
    <div class="panel__filtros">
      <div class="campo" style="flex:1;min-width:14rem">
        <label for="relacionada_id">Pieza</label>
        <select id="relacionada_id" name="relacionada_id" required>
          <?php foreach ($piezasDisponibles as $pz): ?>
            <option value="<?= (int) $pz['id'] ?>"><?= e(extracto((string) $pz['title'], 70)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="relacion">Cómo se relacionan</label>
        <select id="relacion" name="relacion">
          <?php foreach ($tiposRelacion as $k => $v): ?>
            <option value="<?= atributo($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="boton boton--pequeno" type="submit">Relacionar</button>
    </div>
  </form>
</div>
