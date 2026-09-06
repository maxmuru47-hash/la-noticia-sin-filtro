<div class="panel__cabeza"><h1>Usuarios y roles</h1></div>

<div class="editor">
  <div>
    <div class="panel__caja">
      <h2>Cuentas</h2>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Activo</th><th>Último acceso</th></tr></thead>
          <tbody>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td><?= e($u['display_name']) ?></td>
              <td><?= e($u['email']) ?></td>
              <td><?= e($u['role_name']) ?></td>
              <td><?= $u['is_active'] ? 'Sí' : 'No' ?></td>
              <td class="numero"><?= e($u['last_login_at'] ? fechaHora($u['last_login_at']) : 'nunca') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel__caja">
      <h2>Roles y capacidades</h2>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Rol</th><th>Nivel</th><th>Capacidades</th></tr></thead>
          <tbody>
          <?php foreach ($roles as $rol): ?>
            <tr>
              <td><strong><?= e($rol['name']) ?></strong><br><small style="color:var(--tinta-tenue)"><?= e($rol['description'] ?? '') ?></small></td>
              <td class="numero"><?= (int) $rol['rank'] ?></td>
              <td><small><?php
                $caps = json_decode((string) $rol['capabilities'], true) ?: [];
                echo e(in_array('*', $caps, true) ? 'Todas las capacidades' : implode(', ', $caps));
              ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside class="editor__lateral">
    <div class="panel__caja">
      <h2>Crear cuenta</h2>
      <form class="formulario" method="post" action="/panel/usuarios">
        <?= \App\Support\Csrf::field() ?>
        <div class="campo">
          <label for="nombre">Nombre</label>
          <input type="text" id="nombre" name="nombre" required maxlength="120">
        </div>
        <div class="campo">
          <label for="correo">Correo</label>
          <input type="email" id="correo" name="correo" required maxlength="190">
        </div>
        <div class="campo">
          <label for="clave">Contraseña provisional</label>
          <input type="text" id="clave" name="clave" required minlength="12" autocomplete="new-password">
          <span class="campo__ayuda">Mínimo 12 caracteres. La persona deberá cambiarla al entrar.</span>
        </div>
        <div class="campo">
          <label for="rol">Rol</label>
          <select id="rol" name="rol" required>
            <?php foreach ($roles as $rol): ?>
              <option value="<?= (int) $rol['id'] ?>"><?= e($rol['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="campo__ayuda">No puedes crear una cuenta con más permisos que la tuya.</span>
        </div>
        <div class="campo campo--casilla">
          <input type="checkbox" id="crear_autor" name="crear_autor" value="1" checked>
          <label for="crear_autor">Crear también su firma pública de autor</label>
        </div>
        <button class="boton boton--rojo" type="submit">Crear cuenta</button>
      </form>
    </div>
  </aside>
</div>
