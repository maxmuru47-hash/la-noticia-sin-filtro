<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <h2>Escríbenos</h2>
    <?php if (!empty($config['brand']['email'])): ?>
      <p>Correo editorial: <a href="mailto:<?= atributo($config['brand']['email']) ?>"><?= e($config['brand']['email']) ?></a></p>
    <?php else: ?>
      <p class="aviso">El correo editorial todavía no está configurado. Se define en el archivo <code>.env</code>
        con la variable <code>EDITORIAL_EMAIL</code>, y aparecerá aquí automáticamente.</p>
    <?php endif; ?>

    <p>También puedes escribirnos por nuestras cuentas oficiales:</p>
    <ul>
      <li>Instagram del medio: <a href="https://www.instagram.com/<?= atributo($config['brand']['instagram']) ?>" rel="noopener noreferrer me" target="_blank">@<?= e($config['brand']['instagram']) ?></a></li>
      <li>TikTok: <a href="https://www.tiktok.com/@<?= atributo($config['brand']['tiktok']) ?>" rel="noopener noreferrer me" target="_blank">@<?= e($config['brand']['tiktok']) ?></a></li>
      <li>Instagram de Max: <a href="https://www.instagram.com/<?= atributo($config['brand']['instagram_personal']) ?>" rel="noopener noreferrer me" target="_blank">@<?= e($config['brand']['instagram_personal']) ?></a></li>
    </ul>

    <h2>Derecho a réplica</h2>
    <p>Si te mencionamos en una pieza y consideras que la información es incorrecta o incompleta,
      tienes derecho a respondernos. Nos comprometemos a:</p>
    <ul>
      <li>Responder a toda solicitud de réplica.</li>
      <li>Publicar la corrección en la misma pieza cuando el reclamo tenga fundamento,
        con su fecha y su motivo visibles.</li>
      <li>Explicar por qué, si decidimos no corregir.</li>
    </ul>

    <h2>Proponer un tema</h2>
    <p>La agenda de este medio se alimenta de las preguntas de su audiencia.
      Puedes dejar la tuya al final de cualquier pieza o en la página de cualquier <a href="/lives">Live</a>.</p>
  </div>
</div>
