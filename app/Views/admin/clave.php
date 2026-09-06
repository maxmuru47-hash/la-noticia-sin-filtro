<?php
/** @var bool $obligado */
$caja = $obligado ? 'entrar__caja' : 'panel__caja';
?>
<div class="<?= atributo($caja) ?>" <?= $obligado ? '' : 'style="max-width:34rem"' ?>>
  <?php if ($obligado): ?>
    <p class="entrar__marca">La Noticia SIN FILTRO</p>
    <h1 style="font-size:var(--t-lg);margin-top:var(--e3)">Cambia tu contraseña antes de seguir</h1>
    <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">
      Entraste con una contraseña provisional. Elige una tuya para continuar.
    </p>
  <?php else: ?>
    <h1 style="font-size:var(--t-xl);margin-top:0">Cambiar mi contraseña</h1>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <p class="aviso aviso--error"><?= e($error) ?></p>
  <?php endif; ?>

  <form class="formulario" method="post" action="/panel/clave" style="margin-top:var(--e4)">
    <?= \App\Support\Csrf::field() ?>

    <div class="campo">
      <label for="clave_actual">Contraseña actual</label>
      <input type="password" id="clave_actual" name="clave_actual" required autocomplete="current-password" autofocus>
    </div>

    <div class="campo">
      <label for="clave_nueva">Contraseña nueva</label>
      <input type="password" id="clave_nueva" name="clave_nueva" required minlength="<?= (int) $minimo ?>" autocomplete="new-password">
      <span class="campo__ayuda">
        Mínimo <?= (int) $minimo ?> caracteres. Una frase larga que solo tú puedas recordar es más segura
        que una palabra corta con símbolos raros.
      </span>
    </div>

    <div class="campo">
      <label for="clave_repetir">Repite la contraseña nueva</label>
      <input type="password" id="clave_repetir" name="clave_repetir" required minlength="<?= (int) $minimo ?>" autocomplete="new-password">
    </div>

    <button class="boton boton--rojo" type="submit" style="width:100%">Guardar contraseña</button>
  </form>

  <?php if ($obligado): ?>
    <form method="post" action="/panel/salir" style="margin-top:var(--e3)">
      <?= \App\Support\Csrf::field() ?>
      <button class="mini-boton" type="submit">Salir</button>
    </form>
  <?php endif; ?>
</div>
