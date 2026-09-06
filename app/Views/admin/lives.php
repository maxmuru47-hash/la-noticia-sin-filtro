<div class="panel__cabeza">
  <h1>Lives</h1>
  <a class="boton boton--rojo" href="/panel/lives/nuevo">Nuevo Live</a>
</div>

<p class="aviso">La fecha y el estado de cada Live tienen una <strong>sola fuente de verdad</strong>: esta ficha.
Se escribe en hora de Venezuela y se guarda en UTC.</p>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Título</th><th>Estado</th><th>Empieza</th><th>Grabación</th></tr></thead>
      <tbody>
      <?php foreach ($lives as $l): ?>
        <tr>
          <td><a href="/panel/lives/<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a> <?= selloDemo($l['is_demo'] ?? 0) ?></td>
          <td><?= e(\App\Models\Live::STATUSES[$l['status']] ?? '') ?></td>
          <td class="numero"><?= e($l['starts_at'] ? fechaHora($l['starts_at']) : '—') ?></td>
          <td><?= $l['recording_video_id'] ? 'Sí' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($lives === []): ?><tr><td colspan="4">Todavía no hay Lives.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
</div>
