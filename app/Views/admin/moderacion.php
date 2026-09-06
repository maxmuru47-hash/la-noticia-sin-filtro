<div class="panel__cabeza"><h1>Moderación</h1></div>

<nav class="archivo-nav">
  <?php foreach (array_merge(['todas' => 'Todas'], \App\Models\Question::STATUSES) as $clave => $nombre): ?>
    <a href="/panel/moderacion?estado=<?= atributo($clave) ?>" <?= $estado === $clave ? 'aria-current="page"' : '' ?>><?= e($nombre) ?></a>
  <?php endforeach; ?>
</nav>

<div class="panel__caja">
  <h2>Preguntas de la comunidad</h2>
  <?php if ($preguntas === []): ?>
    <p>No hay preguntas con ese estado.</p>
  <?php else: ?>
    <?php foreach ($preguntas as $p): ?>
      <div style="border-bottom:1px solid var(--borde);padding:var(--e3) 0">
        <p style="margin:0;font-weight:700">&laquo;<?= e($p['body']) ?>&raquo;</p>
        <p style="margin:0;font-size:var(--t-sm);color:var(--tinta-tenue)">
          <?= e($p['author_name'] ?: 'Anónimo') ?> ·
          <?= e(fechaHora($p['created_at'])) ?> ·
          Estado: <?= e(\App\Models\Question::STATUSES[$p['status']] ?? '') ?>
          <?php if (!empty($p['article_title'])): ?> · sobre <a href="/panel/noticias/#"><?= e($p['article_title']) ?></a><?php endif; ?>
          <?php if (!empty($p['contact_email'])): ?> · correo con consentimiento<?php endif; ?>
        </p>

        <form method="post" action="/panel/moderacion/<?= (int) $p['id'] ?>" class="panel__filtros" style="margin-top:var(--e2)">
          <?= \App\Support\Csrf::field() ?>
          <input type="hidden" name="volver_a" value="<?= atributo($estado) ?>">
          <div class="campo">
            <label for="estado-<?= (int) $p['id'] ?>">Nuevo estado</label>
            <select id="estado-<?= (int) $p['id'] ?>" name="estado">
              <?php foreach (\App\Models\Question::STATUSES as $clave => $nombre): ?>
                <option value="<?= atributo($clave) ?>" <?= $p['status'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="campo">
            <label for="live-<?= (int) $p['id'] ?>">Llevar al Live</label>
            <select id="live-<?= (int) $p['id'] ?>" name="live">
              <option value="">—</option>
              <?php foreach ($lives as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= (int) ($p['live_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="campo" style="flex:1;min-width:14rem">
            <label for="respuesta-<?= (int) $p['id'] ?>">Respuesta pública</label>
            <input type="text" id="respuesta-<?= (int) $p['id'] ?>" name="respuesta" value="<?= atributo($p['answer'] ?? '') ?>">
          </div>
          <button class="boton boton--pequeno" type="submit">Guardar</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
  <?php endif; ?>
</div>

<div class="panel__caja">
  <h2>Pulsos</h2>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    Los resultados se calculan directamente de los votos y <strong>nunca se editan</strong>.
    Aquí solo se ve cuántas respuestas hay.
  </p>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Pregunta</th><th>Pieza</th><th>Respuestas</th><th>Abierto</th></tr></thead>
      <tbody>
      <?php foreach ($pulsos as $pulso): ?>
        <tr>
          <td><?= e($pulso['question']) ?></td>
          <td><?= e($pulso['article_title'] ?? '—') ?></td>
          <td class="numero"><?= (int) $pulso['respuestas'] ?></td>
          <td><?= $pulso['is_open'] ? 'Sí' : 'No' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($pulsos === []): ?><tr><td colspan="4">Todavía no hay pulsos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
