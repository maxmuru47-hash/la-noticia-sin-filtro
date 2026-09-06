<div class="panel__cabeza"><h1>Categorías, temas, autores y fuentes</h1></div>

<div class="editor">
  <div>
    <div class="panel__caja">
      <h2>Categorías</h2>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Nombre</th><th>Pregunta que responde</th><th>Piezas</th></tr></thead>
          <tbody>
          <?php foreach ($categorias as $c): ?>
            <tr><td><a href="/categoria/<?= atributo($c['slug']) ?>" target="_blank" rel="noopener"><?= e($c['name']) ?></a></td>
                <td><?= e($c['question'] ?? '—') ?></td>
                <td class="numero"><?= (int) $c['total'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel__caja">
      <h2>Expedientes</h2>
      <ul>
        <?php foreach ($expedientes as $exp): ?>
          <li><a href="/expediente/<?= atributo($exp['slug']) ?>" target="_blank" rel="noopener"><?= e($exp['title']) ?></a> — <?= e($exp['status']) ?></li>
        <?php endforeach; ?>
        <?php if ($expedientes === []): ?><li>Todavía no hay expedientes.</li><?php endif; ?>
      </ul>
    </div>

    <div class="panel__caja">
      <h2>Autores</h2>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Nombre</th><th>Cargo</th><th>Activo</th></tr></thead>
          <tbody>
          <?php foreach ($autores as $au): ?>
            <tr><td><a href="/autor/<?= atributo($au['slug']) ?>" target="_blank" rel="noopener"><?= e($au['name']) ?></a></td>
                <td><?= e($au['role_title'] ?? '—') ?></td>
                <td><?= $au['is_active'] ? 'Sí' : 'No' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel__caja">
      <h2>Fuentes</h2>
      <ul>
        <?php foreach ($fuentes as $f): ?>
          <li><?= e($f['title']) ?><?php if (!empty($f['publisher'])): ?> — <?= e($f['publisher']) ?><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if ($fuentes === []): ?><li>Todavía no hay fuentes registradas.</li><?php endif; ?>
      </ul>
    </div>

    <div class="panel__caja">
      <h2>Temas y etiquetas</h2>
      <p><strong>Temas:</strong>
        <?php foreach ($temas as $t): ?><a href="/tema/<?= atributo($t['slug']) ?>" target="_blank" rel="noopener"><?= e($t['name']) ?></a> · <?php endforeach; ?>
        <?php if ($temas === []): ?>ninguno<?php endif; ?>
      </p>
      <p><strong>Etiquetas:</strong>
        <?php foreach ($etiquetas as $t): ?><a href="/etiqueta/<?= atributo($t['slug']) ?>" target="_blank" rel="noopener"><?= e($t['name']) ?></a> · <?php endforeach; ?>
        <?php if ($etiquetas === []): ?>ninguna<?php endif; ?>
      </p>
    </div>
  </div>

  <aside class="editor__lateral">
    <div class="panel__caja">
      <h2>Añadir</h2>
      <form class="formulario" method="post" action="/panel/taxonomias">
        <?= \App\Support\Csrf::field() ?>
        <div class="campo">
          <label for="entidad">Qué quieres crear</label>
          <select id="entidad" name="entidad">
            <option value="categoria">Categoría</option>
            <option value="tema">Tema</option>
            <option value="etiqueta">Etiqueta</option>
            <option value="autor">Autor</option>
            <option value="fuente">Fuente</option>
            <option value="expediente">Expediente</option>
          </select>
        </div>
        <div class="campo">
          <label for="nombre">Nombre</label>
          <input type="text" id="nombre" name="nombre" required maxlength="190">
        </div>
        <div class="campo">
          <label for="pregunta">Pregunta que responde (categoría o expediente)</label>
          <input type="text" id="pregunta" name="pregunta" maxlength="190">
        </div>
        <div class="campo">
          <label for="descripcion">Descripción</label>
          <textarea id="descripcion" name="descripcion" rows="3"></textarea>
        </div>
        <div class="campo">
          <label for="cargo">Cargo (autor)</label>
          <input type="text" id="cargo" name="cargo" maxlength="120">
        </div>
        <div class="campo">
          <label for="url">URL (fuente)</label>
          <input type="url" id="url" name="url">
        </div>
        <button class="boton boton--rojo" type="submit">Guardar</button>
      </form>
    </div>
  </aside>
</div>
