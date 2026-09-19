<div class="contenedor seccion">
  <div class="lectura">
    <h1><?= e($encabezado) ?></h1>
    <p class="aviso <?= $ok ? '' : 'aviso--error' ?>"><?= e($mensaje) ?></p>
    <p><a class="boton" href="/">Volver a la portada</a></p>
  </div>
</div>
