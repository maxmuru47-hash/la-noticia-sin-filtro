<div class="contenedor seccion">
  <?= \App\Support\View::partial('partials/transparencia-nav', ['paginas' => $paginas, 'paginaActual' => $paginaActual]) ?>
  <div class="lectura" style="margin-top:var(--e5)">
    <h1><?= e($encabezado) ?></h1>
    <p style="font-size:var(--t-lg)">La Noticia SIN FILTRO es el medio digital del ecosistema
      <strong><?= e($config['brand']['name']) ?></strong>, dirigido por Max González desde Venezuela.</p>

    <h2>Quién publica</h2>
    <p>El responsable editorial de todo lo que se publica aquí es Max González. La dirección editorial define
      las preguntas que perseguimos, firma su opinión cuando opina, y responde por los errores cuando los hay.</p>

    <h2>Qué somos y qué no</h2>
    <p>Somos un medio de comprensión y conversación. No competimos por publicar primero: intentamos que
      cada tema se entienda mejor después de leerlo.</p>
    <ul>
      <li>No somos un periódico generalista que copie todas las noticias del día.</li>
      <li>No somos una colección de titulares diseñados solo para conseguir clics.</li>
      <li>No somos una red social abierta sin moderación ni criterio editorial.</li>
      <li>No somos un blog personal donde opinión y hechos aparezcan mezclados.</li>
    </ul>

    <h2>Equipo editorial</h2>
    <?php if (!empty($autores)): ?>
      <ul class="fuentes">
        <?php foreach ($autores as $autor): ?>
          <li>
            <strong><a href="/autor/<?= atributo($autor['slug']) ?>"><?= e($autor['name']) ?></a></strong>
            <?php if (!empty($autor['role_title'])): ?><br><?= e($autor['role_title']) ?><?php endif; ?>
            <?php if (!empty($autor['bio'])): ?><br><span style="color:var(--tinta-tenue)"><?= e(extracto((string) $autor['bio'], 200)) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Roles</h2>
    <ul>
      <li><strong>Dirección editorial.</strong> Define preguntas, firma su postura y conduce la conversación.</li>
      <li><strong>Edición responsable.</strong> Verifica, clasifica, corrige y protege los estándares.</li>
      <li><strong>Producción multimedia.</strong> Convierte piezas en video, clips y Lives.</li>
      <li><strong>Moderación.</strong> Filtra preguntas y contribuciones de la comunidad.</li>
    </ul>

    <h2>Contacto</h2>
    <p>Escríbenos por <a href="/transparencia/contacto">la página de contacto</a>. Toda persona mencionada
      tiene derecho a réplica.</p>
  </div>
</div>
