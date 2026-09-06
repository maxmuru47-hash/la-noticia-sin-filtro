<?php /** @var array $config */ ?>
<footer class="pie">
  <div class="contenedor">
    <p class="pie__principio"><?= e($config['brand']['promise']) ?></p>

    <div class="pie__rejilla">
      <div>
        <h2 class="pie__titulo">Secciones</h2>
        <ul class="pie__lista">
          <li><a href="/">Portada</a></li>
          <li><a href="/archivo">Archivo permanente</a></li>
          <li><a href="/buscar">Buscador</a></li>
          <li><a href="/expedientes">Expedientes vivos</a></li>
          <li><a href="/lives">Lives</a></li>
          <li><a href="/videos">Videos</a></li>
        </ul>
      </div>

      <div>
        <h2 class="pie__titulo">Transparencia</h2>
        <ul class="pie__lista">
          <li><a href="/transparencia/quienes-somos">Quiénes somos</a></li>
          <li><a href="/transparencia/politica-editorial">Política editorial</a></li>
          <li><a href="/transparencia/metodologia">Metodología y fuentes</a></li>
          <li><a href="/transparencia/correcciones">Correcciones</a></li>
          <li><a href="/transparencia/uso-de-ia">Uso de inteligencia artificial</a></li>
          <li><a href="/transparencia/publicidad">Publicidad y patrocinios</a></li>
          <li><a href="/transparencia/privacidad">Privacidad y datos</a></li>
          <li><a href="/transparencia/contacto">Contacto y derecho a réplica</a></li>
        </ul>
      </div>

      <div>
        <h2 class="pie__titulo">Seguir</h2>
        <ul class="pie__lista">
          <li><a href="https://www.instagram.com/<?= atributo($config['brand']['instagram']) ?>" rel="noopener noreferrer me" target="_blank">Instagram · @<?= e($config['brand']['instagram']) ?></a></li>
          <li><a href="https://www.tiktok.com/@<?= atributo($config['brand']['tiktok']) ?>" rel="noopener noreferrer me" target="_blank">TikTok · @<?= e($config['brand']['tiktok']) ?></a></li>
          <li><a href="https://www.instagram.com/<?= atributo($config['brand']['instagram_personal']) ?>" rel="noopener noreferrer me" target="_blank">Max · @<?= e($config['brand']['instagram_personal']) ?></a></li>
          <li><a href="/rss.xml">RSS</a></li>
        </ul>
      </div>

      <div>
        <h2 class="pie__titulo">Principio</h2>
        <p>&laquo;<?= e($config['brand']['principle']) ?>&raquo;</p>
        <p><strong>Ninguna pieza publicada se elimina.</strong> El archivo es permanente y cada noticia conserva su dirección para siempre.</p>
      </div>
    </div>

    <div class="pie__legal">
      <p>&copy; <?= date('Y') ?> <?= e($config['brand']['name']) ?> · <?= e($config['app']['name']) ?></p>
      <p>Hecho en Venezuela.</p>
    </div>
  </div>
</footer>
