<?php
/**
 * @var string      $contenido
 * @var array       $config
 * @var string|null $titulo
 */
$appUrl       = $config['app']['url'];
$rutaCanonica = $rutaCanonica ?? '/';
$canonica     = rtrim($appUrl, '/') . ($rutaCanonica === '/' ? '/' : $rutaCanonica);
$descripcion  = $descripcion ?? $config['brand']['promise'];
$imagenSocial = isset($imagenSocial) && $imagenSocial ? \App\Services\SeoService::absoluteUrl((string) $imagenSocial, $appUrl) : null;
$jsonLd       = $jsonLd ?? [];
$noindex      = $noindex ?? false;
?>
<!DOCTYPE html>
<html lang="es-VE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($titulo ?? $config['app']['name']) ?></title>
<meta name="description" content="<?= atributo(extracto($descripcion, 300)) ?>">
<?php if ($noindex): ?>
<meta name="robots" content="noindex, follow">
<?php else: ?>
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<?php endif; ?>
<link rel="canonical" href="<?= atributo($canonica) ?>">
<meta name="theme-color" content="#000000">

<meta property="og:type" content="<?= atributo($tipoOg ?? 'website') ?>">
<meta property="og:site_name" content="<?= atributo($config['app']['name']) ?>">
<meta property="og:locale" content="es_VE">
<meta property="og:title" content="<?= atributo($titulo ?? $config['app']['name']) ?>">
<meta property="og:description" content="<?= atributo(extracto($descripcion, 300)) ?>">
<meta property="og:url" content="<?= atributo($canonica) ?>">
<?php if ($imagenSocial !== null): ?>
<meta property="og:image" content="<?= atributo($imagenSocial) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<meta name="twitter:title" content="<?= atributo($titulo ?? $config['app']['name']) ?>">
<meta name="twitter:description" content="<?= atributo(extracto($descripcion, 200)) ?>">

<link rel="alternate" type="application/rss+xml" title="<?= atributo($config['app']['name']) ?>" href="<?= atributo($appUrl) ?>/rss.xml">
<link rel="icon" href="/assets/images/icono.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/sistema.css">
<link rel="stylesheet" href="/assets/css/componentes.css">
<?php foreach ($jsonLd as $bloque): ?>
<script type="application/ld+json"><?= \App\Services\SeoService::jsonLd($bloque) ?></script>
<?php endforeach; ?>
</head>
<body>
<a class="saltar" href="#contenido">Saltar al contenido</a>

<?php if (!empty($conBarraProgreso)): ?>
<div class="barra-progreso" aria-hidden="true"><div class="barra-progreso__relleno" data-progreso></div></div>
<?php endif; ?>

<?= \App\Support\View::partial('partials/cabecera', ['config' => $config, 'rutaActual' => $rutaCanonica]) ?>

<main id="contenido">
<?= $contenido ?>
</main>

<?= \App\Support\View::partial('partials/pie', ['config' => $config]) ?>

<?php if (!empty($conHero)): ?>
<script src="/assets/js/hero.js" defer></script>
<?php endif; ?>
<script src="/assets/js/sitio.js" defer></script>
</body>
</html>
