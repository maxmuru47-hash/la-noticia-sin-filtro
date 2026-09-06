<?php
/** @var array $config */
$rutaActual = $rutaActual ?? '/';

$enlaces = [
    ['ruta' => '/',              'texto' => 'Ahora'],
    ['ruta' => '/expedientes',   'texto' => 'Entender'],
    ['ruta' => '/tipo/opinion',  'texto' => 'Debatir'],
    ['ruta' => '/lives',         'texto' => 'Lives'],
    ['ruta' => '/videos',        'texto' => 'Videos'],
    ['ruta' => '/archivo',       'texto' => 'Archivo'],
    ['ruta' => '/transparencia/politica-editorial', 'texto' => 'Transparencia'],
];
?>
<header class="cabecera">
  <div class="contenedor cabecera__barra">
    <a class="marca" href="/">
      <span class="marca__nombre">La Noticia</span>
      <span class="marca__origen"><?= e($config['brand']['name']) ?></span>
    </a>

    <button class="menu-boton" type="button" data-menu-boton aria-expanded="false" aria-controls="navegacion-principal">
      <span class="solo-lectores">Abrir menú</span>
      <span aria-hidden="true">☰</span>
    </button>

    <nav class="navegacion" id="navegacion-principal" data-navegacion aria-label="Navegación principal">
      <?php foreach ($enlaces as $enlace): ?>
        <a class="navegacion__enlace" href="<?= atributo($enlace['ruta']) ?>"
           <?= $rutaActual === $enlace['ruta'] ? 'aria-current="page"' : '' ?>><?= e($enlace['texto']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="cabecera__buscar">
      <form class="buscar-mini" action="/buscar" method="get" role="search">
        <label class="solo-lectores" for="buscar-cabecera">Buscar en el archivo</label>
        <input type="search" id="buscar-cabecera" name="q" placeholder="Buscar en el archivo" autocomplete="off">
        <button type="submit"><span class="solo-lectores">Buscar</span><span aria-hidden="true">⌕</span></button>
      </form>
    </div>
  </div>
</header>
