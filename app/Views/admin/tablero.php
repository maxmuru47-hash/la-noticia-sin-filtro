<div class="panel__cabeza">
  <h1>Tablero</h1>
  <a class="boton boton--rojo" href="/panel/rapido">Publicar rápido</a>
  <a class="boton boton--linea" href="/panel/noticias/nueva">Pieza completa</a>
</div>

<div class="panel__metricas">
  <div class="metrica"><p class="metrica__valor"><?= (int) ($conteos['publicadas'] ?? 0) ?></p><p class="metrica__etiqueta">Publicadas</p></div>
  <div class="metrica"><p class="metrica__valor"><?= (int) ($conteos['borradores'] ?? 0) ?></p><p class="metrica__etiqueta">Borradores</p></div>
  <div class="metrica"><p class="metrica__valor"><?= (int) ($conteos['revision'] ?? 0) ?></p><p class="metrica__etiqueta">En revisión</p></div>
  <div class="metrica"><p class="metrica__valor"><?= (int) ($conteos['programadas'] ?? 0) ?></p><p class="metrica__etiqueta">Programadas</p></div>
  <div class="metrica"><p class="metrica__valor"><?= (int) ($conteos['archivadas'] ?? 0) ?></p><p class="metrica__etiqueta">Archivadas</p></div>
  <div class="metrica <?= $pendientes > 0 ? 'metrica--alerta' : '' ?>"><p class="metrica__valor"><?= (int) $pendientes ?></p><p class="metrica__etiqueta">Preguntas por moderar</p></div>
</div>

<p class="aviso">
  <strong>Archivo permanente:</strong> <?= (int) ($conteos['total'] ?? 0) ?> piezas conservadas.
  Ninguna se elimina al cambiar la portada, al archivarla o al corregirla.
</p>

<div class="panel__caja">
  <h2>Últimas piezas tocadas</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Título</th><th>Estado</th><th>Autor</th><th>Modificada</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recientes as $pieza): ?>
        <tr>
          <td><a href="/panel/noticias/<?= (int) $pieza['id'] ?>"><?= e($pieza['title']) ?></a><br>
              <small style="color:var(--tinta-tenue)">/noticia/<?= e($pieza['slug']) ?></small></td>
          <td><?= selloEstado((string) $pieza['status']) ?: e($pieza['status']) ?></td>
          <td><?= e($pieza['author_name'] ?? '—') ?></td>
          <td class="numero"><?= e(\App\Support\Dates::relative($pieza['updated_at'])) ?></td>
          <td><?php if (in_array($pieza['status'], ['publicada','actualizada','archivada','retirada'], true)): ?>
            <a class="mini-boton" href="/noticia/<?= atributo($pieza['slug']) ?>" target="_blank" rel="noopener">Ver</a>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($recientes === []): ?><tr><td colspan="5">Todavía no hay piezas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($programadas !== []): ?>
<div class="panel__caja">
  <h2>Programadas</h2>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Se publican solas cuando llega su hora. También las publica <code>scripts/cron.php</code>.</p>
  <ul>
    <?php foreach ($programadas as $pieza): ?>
      <li><a href="/panel/noticias/<?= (int) $pieza['id'] ?>"><?= e($pieza['title']) ?></a> — <?= e(fechaHora($pieza['scheduled_for'])) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($proximoLive !== null): ?>
<div class="panel__caja">
  <h2>Próximo Live</h2>
  <p><a href="/panel/lives/<?= (int) $proximoLive['id'] ?>"><?= e($proximoLive['title']) ?></a> —
     <?= e(\App\Models\Live::STATUSES[$proximoLive['status']] ?? '') ?>
     <?php if (!empty($proximoLive['starts_at'])): ?> · <?= e(fechaHora($proximoLive['starts_at'])) ?><?php endif; ?></p>
</div>
<?php endif; ?>

<?php if (\App\Middleware\Auth::can(\App\Middleware\Auth::CAP_AUDIT_VIEW)): ?>
<div class="panel__caja">
  <h2>Últimas acciones registradas</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($auditoria as $registro): ?>
        <tr>
          <td class="numero"><?= e(\App\Support\Dates::relative($registro['created_at'])) ?></td>
          <td><?= e($registro['user_label'] ?? 'sistema') ?></td>
          <td><code><?= e($registro['action']) ?></code></td>
          <td><?= e($registro['summary'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin-top:var(--e2)"><a href="/panel/auditoria">Ver la auditoría completa</a></p>
</div>
<?php endif; ?>
