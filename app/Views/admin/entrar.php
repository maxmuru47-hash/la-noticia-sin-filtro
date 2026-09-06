<div class="entrar__caja">
  <p class="entrar__marca">La Noticia SIN FILTRO</p>
  <p style="color:var(--tinta-tenue);font-size:var(--t-sm)">Panel editorial</p>

  <?php if (!empty($error)): ?>
    <p class="aviso aviso--error"><?= e($error) ?></p>
  <?php endif; ?>

  <form class="formulario" method="post" action="/panel/entrar" style="margin-top:var(--e4)">
    <?= \App\Support\Csrf::field() ?>
    <input type="hidden" name="destino" value="<?= atributo($destino) ?>">

    <div class="campo">
      <label for="correo">Correo</label>
      <input type="email" id="correo" name="correo" required autocomplete="username" autofocus>
    </div>

    <div class="campo">
      <label for="clave">Contraseña</label>
      <input type="password" id="clave" name="clave" required autocomplete="current-password">
    </div>

    <button class="boton boton--rojo" type="submit" style="width:100%">Entrar</button>
  </form>
</div>
