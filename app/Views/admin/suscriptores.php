<div class="panel__cabeza">
  <h1>Suscriptores</h1>
</div>

<p class="aviso">Esta lista es el único público que no depende de ningún algoritmo. Son datos
personales: no se exportan a servicios de terceros sin decirlo, y cada fila guarda el texto
exacto que esa persona aceptó.</p>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Estado</th><th>Cuántos</th></tr></thead>
      <tbody>
        <tr><td>Confirmados · reciben correo</td><td class="numero"><strong><?= (int) $resumen['confirmados'] ?></strong></td></tr>
        <tr><td>Pendientes de confirmar</td><td class="numero"><?= (int) $resumen['pendientes'] ?></td></tr>
        <tr><td>Bajas</td><td class="numero"><?= (int) $resumen['bajas'] ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Correo</th><th>Quiere recibir</th><th>Se apuntó</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($suscriptores as $s): ?>
        <tr>
          <td><?= e($s['email']) ?></td>
          <td><?= e(str_replace(',', ' · ', (string) $s['purpose'])) ?></td>
          <td class="numero"><?= e(fechaLarga($s['consented_at'])) ?></td>
          <td>
            <?php if ($s['unsubscribed_at'] !== null): ?>Baja
            <?php elseif ($s['confirmed_at'] !== null): ?>Confirmado
            <?php else: ?>Pendiente<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($suscriptores === []): ?>
        <tr><td colspan="4">Todavía no hay nadie apuntado. El formulario sale al final de cada pieza publicada.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
</div>
