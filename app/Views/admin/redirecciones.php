<div class="panel__cabeza"><h1>Redirecciones</h1></div>

<p class="aviso">
  Cuando una dirección publicada cambia, la anterior redirige de forma permanente y queda
  <strong>reservada para siempre</strong>: nunca servirá a otra pieza.
</p>

<div class="panel__caja">
  <h2>Crear redirección</h2>
  <form class="formulario" method="post" action="/panel/redirecciones">
    <?= \App\Support\Csrf::field() ?>
    <div class="campo">
      <label for="desde">Desde (ruta interna)</label>
      <input type="text" id="desde" name="desde" placeholder="/noticia/direccion-antigua" required>
    </div>
    <div class="campo">
      <label for="hacia">Hacia</label>
      <input type="text" id="hacia" name="hacia" placeholder="/noticia/direccion-nueva" required>
      <span class="campo__ayuda">Una ruta interna, o una URL https completa.</span>
    </div>
    <div class="campo">
      <label for="codigo">Código</label>
      <select id="codigo" name="codigo">
        <option value="301">301 · Permanente (recomendado)</option>
        <option value="308">308 · Permanente conservando el método</option>
        <option value="302">302 · Temporal</option>
      </select>
    </div>
    <div class="campo">
      <label for="motivo">Motivo</label>
      <input type="text" id="motivo" name="motivo" maxlength="255">
    </div>
    <button class="boton boton--rojo" type="submit">Guardar</button>
  </form>
</div>

<div class="panel__caja">
  <h2>Redirecciones activas</h2>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Desde</th><th>Hacia</th><th>Código</th><th>Usos</th><th>Motivo</th><th>Creada</th></tr></thead>
      <tbody>
      <?php foreach ($redirecciones as $r): ?>
        <tr>
          <td><code><?= e($r['from_path']) ?></code></td>
          <td><code><?= e($r['to_path']) ?></code></td>
          <td class="numero"><?= (int) $r['status_code'] ?></td>
          <td class="numero"><?= (int) $r['hits'] ?></td>
          <td><?= e($r['reason'] ?? '—') ?></td>
          <td class="numero"><?= e(fechaLarga($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($redirecciones === []): ?><tr><td colspan="6">Todavía no hay redirecciones.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel__caja">
  <h2>Direcciones reservadas</h2>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
    Cada dirección que alguna vez perteneció a una pieza queda quemada. El sistema no permite reutilizarla.
  </p>
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Dirección</th><th>Pieza</th><th>Dirección actual</th><th>Reservada</th></tr></thead>
      <tbody>
      <?php foreach ($reservados as $rs): ?>
        <tr>
          <td><code>/noticia/<?= e($rs['slug']) ?></code></td>
          <td><?= e($rs['title'] ?? '—') ?></td>
          <td><code><?= e($rs['slug_actual'] ?? '—') ?></code></td>
          <td class="numero"><?= e(fechaLarga($rs['reserved_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
