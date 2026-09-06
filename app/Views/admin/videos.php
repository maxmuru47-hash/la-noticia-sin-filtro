<div class="panel__cabeza">
  <h1>Videos</h1>
  <a class="boton boton--rojo" href="/panel/videos/nuevo">Nuevo video</a>
</div>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Título</th><th>Tipo</th><th>Proveedor</th><th>Estado</th><th>Duración</th><th>Subtítulos</th><th>Transcripción</th></tr></thead>
      <tbody>
      <?php foreach ($videos as $v): ?>
        <tr>
          <td><a href="/panel/videos/<?= (int) $v['id'] ?>"><?= e($v['title']) ?></a> <?= selloDemo($v['is_demo'] ?? 0) ?></td>
          <td><?= e(\App\Models\Video::TYPES[$v['video_type']] ?? '') ?></td>
          <td><?= e($v['provider']) ?></td>
          <td><?= e($v['status']) ?></td>
          <td class="numero"><?= e(duracion($v['duration_seconds'] === null ? null : (int) $v['duration_seconds'])) ?: '—' ?></td>
          <td><?= !empty($v['captions_path']) ? 'Sí' : '—' ?></td>
          <td><?= e($v['transcript_status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($videos === []): ?><tr><td colspan="7">Todavía no hay videos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
</div>
