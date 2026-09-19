<?php
/** @var string $contenido */
use App\Middleware\Auth;
$usuario = Auth::user();
$ruta    = parse_url($_SERVER['REQUEST_URI'] ?? '/panel', PHP_URL_PATH) ?: '/panel';
$flash   = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

$menu = [
    'Editorial' => [
        ['/panel',            'Tablero',   null],
        ['/panel/rapido',     'Publicar rápido', Auth::CAP_ARTICLE_CREATE],
        ['/panel/noticias',   'Noticias',  Auth::CAP_ARTICLE_CREATE],
        ['/panel/portada',    'Portada',   Auth::CAP_HOMEPAGE_MANAGE],
        ['/panel/taxonomias', 'Categorías y autores', Auth::CAP_TAXONOMY_MANAGE],
    ],
    'Multimedia' => [
        ['/panel/medios', 'Imágenes', Auth::CAP_MEDIA_MANAGE],
        ['/panel/videos', 'Videos',   Auth::CAP_VIDEO_MANAGE],
        ['/panel/lives',  'Lives',    Auth::CAP_LIVE_MANAGE],
    ],
    'Comunidad' => [
        ['/panel/moderacion', 'Moderación', Auth::CAP_MODERATE],
        ['/panel/metricas',   'Métricas',   Auth::CAP_METRICS_VIEW],
    ],
    'Sistema' => [
        ['/panel/redirecciones', 'Redirecciones', Auth::CAP_REDIRECT_MANAGE],
        ['/panel/usuarios',      'Usuarios',      Auth::CAP_USER_MANAGE],
        ['/panel/auditoria',     'Auditoría',     Auth::CAP_AUDIT_VIEW],
    ],
];
?>
<!DOCTYPE html>
<html lang="es-VE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo ?? 'Panel') ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/assets/images/icono.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/sistema.css">
<link rel="stylesheet" href="/assets/css/componentes.css">
<link rel="stylesheet" href="/assets/css/panel.css">
</head>
<body>
<a class="saltar" href="#panel-contenido">Saltar al contenido</a>

<div class="panel">
  <nav class="panel__lateral" aria-label="Menú del panel">
    <a class="panel__marca" href="/panel">La Noticia<small>Panel editorial</small></a>

    <?php foreach ($menu as $grupo => $entradas): ?>
      <?php
      $visibles = array_filter($entradas, static fn (array $e): bool => $e[2] === null || Auth::can($e[2]));
      if ($visibles === []) { continue; }
      ?>
      <p class="panel__menu-grupo"><?= e($grupo) ?></p>
      <ul class="panel__menu">
        <?php foreach ($visibles as $entrada): ?>
          <li><a href="<?= atributo($entrada[0]) ?>" <?= $ruta === $entrada[0] || ($entrada[0] !== '/panel' && str_starts_with($ruta, $entrada[0])) ? 'aria-current="page"' : '' ?>><?= e($entrada[1]) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>

    <div class="panel__usuario">
      <strong><?= e($usuario['display_name'] ?? '') ?></strong>
      <span><?= e($usuario['role_name'] ?? '') ?></span>
      <p style="margin-top:var(--e2)">
        <a href="/panel/clave">Cambiar mi contraseña</a><br>
        <a href="/" target="_blank" rel="noopener">Ver el sitio</a>
      </p>
      <form method="post" action="/panel/salir">
        <?= \App\Support\Csrf::field() ?>
        <button class="mini-boton" type="submit">Cerrar sesión</button>
      </form>
    </div>
  </nav>

  <main class="panel__principal" id="panel-contenido">
    <?php if ($flash !== null): ?>
      <p class="aviso aviso--<?= $flash['tipo'] === 'error' ? 'error' : 'ok' ?>"><?= e($flash['mensaje']) ?></p>
    <?php endif; ?>
    <?= $contenido ?>
  </main>
</div>

<script src="/assets/js/panel.js" defer></script>
</body>
</html>
