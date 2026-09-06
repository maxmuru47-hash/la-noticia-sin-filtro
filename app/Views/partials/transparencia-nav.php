<nav class="archivo-nav" aria-label="Páginas de transparencia">
  <?php foreach ($paginas as $clave => $nombre): ?>
    <a href="/transparencia/<?= atributo($clave) ?>" <?= $paginaActual === $clave ? 'aria-current="page"' : '' ?>><?= e($nombre) ?></a>
  <?php endforeach; ?>
</nav>
