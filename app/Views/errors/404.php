<div class="contenedor seccion">
  <div class="vacio">
    <h1>Esta dirección no existe</h1>
    <p>Si llegaste desde un enlace antiguo, la pieza puede haber cambiado de dirección.
       Todas las URL publicadas se conservan, así que probablemente la encuentres en el buscador.</p>
    <p style="margin-top:var(--e4)">
      <a class="boton boton--rojo" href="/buscar">Buscar en el archivo</a>
      <a class="boton boton--linea" href="/">Volver a la portada</a>
    </p>
  </div>

  <?php if (!empty($sugerencias)): ?>
    <div class="seccion__cabeza"><h2 class="seccion__titulo">Lo más reciente</h2></div>
    <div class="rejilla rejilla--4">
      <?php foreach ($sugerencias as $pieza): ?>
        <?= \App\Support\View::partial('partials/tarjeta', ['pieza' => $pieza]) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
