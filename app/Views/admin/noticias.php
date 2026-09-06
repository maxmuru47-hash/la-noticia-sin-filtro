<div class="panel__cabeza">
  <h1>Noticias</h1>
  <a class="boton boton--rojo" href="/panel/noticias/nueva">Nueva pieza</a>
</div>

<form class="panel__filtros" method="get" action="/panel/noticias">
  <div class="campo">
    <label for="q">Buscar</label>
    <input type="search" id="q" name="q" value="<?= atributo($filtros['q']) ?>">
  </div>
  <div class="campo">
    <label for="estado">Estado</label>
    <select id="estado" name="estado">
      <option value="">Todos</option>
      <?php foreach (\App\Models\Article::STATUSES as $clave => $nombre): ?>
        <option value="<?= atributo($clave) ?>" <?= $filtros['status'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="tipo">Tipo</label>
    <select id="tipo" name="tipo">
      <option value="">Todos</option>
      <?php foreach (\App\Models\Article::EDITORIAL_TYPES as $clave => $nombre): ?>
        <option value="<?= atributo($clave) ?>" <?= $filtros['editorial_type'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="autor">Autor</label>
    <select id="autor" name="autor">
      <option value="">Todos</option>
      <?php foreach ($autores as $autor): ?>
        <option value="<?= (int) $autor['id'] ?>" <?= (int) $filtros['author_id'] === (int) $autor['id'] ? 'selected' : '' ?>><?= e($autor['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="boton boton--pequeno" type="submit">Filtrar</button>
  <a class="boton boton--linea boton--pequeno" href="/panel/noticias">Limpiar</a>
</form>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Título</th><th>Tipo</th><th>Estado</th><th>Autor</th><th>Publicada</th><th>Vistas</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($piezas as $pieza): ?>
        <tr>
          <td>
            <a href="/panel/noticias/<?= (int) $pieza['id'] ?>"><?= e($pieza['title']) ?></a>
            <?= selloDemo($pieza['is_demo'] ?? 0) ?>
            <br><small style="color:var(--tinta-tenue)">/noticia/<?= e($pieza['slug']) ?></small>
          </td>
          <td><?= e(\App\Models\Article::EDITORIAL_TYPES[$pieza['editorial_type']] ?? '') ?></td>
          <td><?= e(\App\Models\Article::STATUSES[$pieza['status']] ?? '') ?></td>
          <td><?= e($pieza['author_name'] ?? '—') ?></td>
          <td class="numero"><?= e($pieza['published_at'] ? fechaLarga($pieza['published_at']) : '—') ?></td>
          <td class="numero"><?= (int) $pieza['view_count'] ?></td>
          <td><?php if ($pieza['published_at'] !== null): ?>
            <a class="mini-boton" href="/noticia/<?= atributo($pieza['slug']) ?>" target="_blank" rel="noopener">Ver</a>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($piezas === []): ?><tr><td colspan="7">No hay piezas con esos filtros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
</div>
