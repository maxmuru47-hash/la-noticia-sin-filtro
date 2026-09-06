<div class="panel__cabeza"><h1>Auditoría</h1></div>

<p class="aviso">Toda acción administrativa queda registrada, y el registro sobrevive a la cuenta que la ejecutó.</p>

<form class="panel__filtros" method="get" action="/panel/auditoria">
  <div class="campo">
    <label for="accion">Acción</label>
    <select id="accion" name="accion">
      <option value="">Todas</option>
      <?php foreach ($acciones as $a): ?>
        <option value="<?= atributo($a['action']) ?>" <?= $filtros['action'] === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="usuario">Usuario</label>
    <select id="usuario" name="usuario">
      <option value="">Todos</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= (int) $filtros['user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['display_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="boton boton--pequeno" type="submit">Filtrar</button>
  <a class="boton boton--linea boton--pequeno" href="/panel/auditoria">Limpiar</a>
</form>

<div class="panel__caja">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Entidad</th><th>Detalle</th><th>Cambios</th></tr></thead>
      <tbody>
      <?php foreach ($registros as $r): ?>
        <tr>
          <td class="numero"><?= e(fechaHora($r['created_at'])) ?></td>
          <td><?= e($r['user_label'] ?? 'sistema') ?></td>
          <td><code><?= e($r['action']) ?></code></td>
          <td><?= e($r['entity_type'] ?? '') ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></td>
          <td><?= e($r['summary'] ?? '') ?></td>
          <td><?php if (!empty($r['changes'])): ?>
            <details><summary style="cursor:pointer">Ver</summary>
              <pre style="white-space:pre-wrap;font-size:var(--t-xs);max-width:28rem;overflow-x:auto"><?= e(substr((string) $r['changes'], 0, 1500)) ?></pre>
            </details>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($registros === []): ?><tr><td colspan="6">Sin registros con esos filtros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \App\Support\View::partial('partials/paginacion', ['paginador' => $paginador]) ?>
</div>
