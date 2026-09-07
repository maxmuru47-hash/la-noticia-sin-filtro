<?php
declare(strict_types=1);

/**
 * Front controller unico. Todas las peticiones entran por aqui.
 *
 * La web publica se renderiza en el servidor: si JavaScript falla, la
 * portada, la noticia, el archivo y el buscador siguen funcionando.
 */

// rutas.php dice dónde está el resto del código. Es el único archivo que
// hay que tocar si el proyecto vive fuera de la carpeta pública.
require __DIR__ . '/rutas.php';

$config = require $rutaBootstrap;

use App\Controllers\ApiController;
use App\Controllers\ArchiveController;
use App\Controllers\ArticleController;
use App\Controllers\DossierController;
use App\Controllers\FeedController;
use App\Controllers\HomeController;
use App\Controllers\LiveController;
use App\Controllers\PageController;
use App\Controllers\SearchController;
use App\Controllers\TaxonomyController;
use App\Controllers\VideoController;
use App\Controllers\Admin;
use App\Middleware\Auth;
use App\Models\Article;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\View;

Auth::startSession($config['session']);

$request = new Request();
$router  = new Router();

// ---------------------------------------------------------------------
// RUTAS PUBLICAS
// ---------------------------------------------------------------------

$home     = new HomeController($config);
$article  = new ArticleController($config);
$archive  = new ArchiveController($config);
$search   = new SearchController($config);
$taxonomy = new TaxonomyController($config);
$video    = new VideoController($config);
$live     = new LiveController($config);
$dossier  = new DossierController($config);
$page     = new PageController($config);
$feed     = new FeedController($config);
$api      = new ApiController($config);

$router->get('/', [$home, 'index']);

// URL canonica permanente de cada pieza.
$router->get('/noticia/{slug:[a-z0-9\-]+}', [$article, 'show']);

$router->get('/archivo', [$archive, 'index']);
$router->get('/archivo/{anio:\d{4}}', [$archive, 'index']);
$router->get('/archivo/{anio:\d{4}}/{mes:\d{1,2}}', [$archive, 'index']);
$router->get('/archivo/{anio:\d{4}}/{mes:\d{1,2}}/{dia:\d{1,2}}', [$archive, 'index']);

$router->get('/buscar', [$search, 'index']);

$router->get('/categoria/{slug:[a-z0-9\-]+}', [$taxonomy, 'category']);
$router->get('/etiqueta/{slug:[a-z0-9\-]+}', [$taxonomy, 'tag']);
$router->get('/tema/{slug:[a-z0-9\-]+}', [$taxonomy, 'topic']);
$router->get('/tipo/{tipo:[a-z]+}', [$taxonomy, 'type']);
$router->get('/autor/{slug:[a-z0-9\-]+}', [$taxonomy, 'author']);

$router->get('/videos', [$video, 'index']);
$router->get('/video/{slug:[a-z0-9\-]+}', [$video, 'show']);

$router->get('/lives', [$live, 'index']);
$router->get('/live/{slug:[a-z0-9\-]+}', [$live, 'show']);

$router->get('/expedientes', [$dossier, 'index']);
$router->get('/expediente/{slug:[a-z0-9\-]+}', [$dossier, 'show']);

$router->get('/transparencia/{pagina:[a-z0-9\-]+}', [$page, 'show']);

$router->get('/sitemap.xml', [$feed, 'sitemapIndex']);
$router->get('/sitemap-contenido.xml', [$feed, 'sitemapContent']);
$router->get('/sitemap-noticias.xml', [$feed, 'sitemapNews']);
$router->get('/sitemap-videos.xml', [$feed, 'sitemapVideos']);
$router->get('/sitemap-secciones.xml', [$feed, 'sitemapSections']);
$router->get('/rss.xml', [$feed, 'rss']);
$router->get('/robots.txt', [$feed, 'robots']);

// API minima.
$router->post('/api/pulso/{id:\d+}', [$api, 'vote']);
$router->post('/api/pregunta', [$api, 'question']);
$router->post('/api/recordatorio/{id:\d+}', [$api, 'reminder']);
$router->post('/api/evento', [$api, 'event']);
$router->get('/api/buscar', [$api, 'suggest']);
$router->post('/api/asistente', [$api, 'assistant']);

// ---------------------------------------------------------------------
// RUTAS DEL PANEL
// ---------------------------------------------------------------------

$adminAuth      = new Admin\AuthController($config);
$adminDashboard = new Admin\DashboardController($config);
$adminArticles  = new Admin\ArticleController($config);
$adminMedia     = new Admin\MediaController($config);
$adminVideos    = new Admin\VideoController($config);
$adminLives     = new Admin\LiveController($config);
$adminTaxonomy  = new Admin\TaxonomyController($config);
$adminUsers     = new Admin\UserController($config);
$adminModeration = new Admin\ModerationController($config);
$adminRedirects = new Admin\RedirectController($config);
$adminHomepage  = new Admin\HomepageController($config);
$adminAudit     = new Admin\AuditController($config);
$adminEditorial = new Admin\EditorialController($config);
$adminPerfil    = new Admin\PerfilController($config);
$adminRapido    = new Admin\QuickController();

$router->get('/panel/entrar',  [$adminAuth, 'showLogin']);
$router->post('/panel/entrar', [$adminAuth, 'login']);
$router->post('/panel/salir',  [$adminAuth, 'logout']);
$router->get('/panel/clave',   [$adminPerfil, 'mostrarClave']);
$router->post('/panel/clave',  [$adminPerfil, 'cambiarClave']);

$router->get('/panel', [$adminDashboard, 'index']);

$router->get('/panel/rapido',  [$adminRapido, 'form']);
$router->post('/panel/rapido', [$adminRapido, 'store']);

$router->get('/panel/noticias',                    [$adminArticles, 'index']);
$router->get('/panel/noticias/nueva',              [$adminArticles, 'create']);
$router->post('/panel/noticias/nueva',             [$adminArticles, 'store']);
$router->get('/panel/noticias/{id:\d+}',           [$adminArticles, 'edit']);
$router->post('/panel/noticias/{id:\d+}',          [$adminArticles, 'update']);
$router->post('/panel/noticias/{id:\d+}/publicar', [$adminArticles, 'publish']);
$router->post('/panel/noticias/{id:\d+}/programar', [$adminArticles, 'schedule']);
$router->post('/panel/noticias/{id:\d+}/archivar', [$adminArticles, 'archive']);
$router->post('/panel/noticias/{id:\d+}/desarchivar', [$adminArticles, 'unarchive']);
$router->post('/panel/noticias/{id:\d+}/retirar',  [$adminArticles, 'withdraw']);
$router->post('/panel/noticias/{id:\d+}/corregir', [$adminArticles, 'correct']);
$router->post('/panel/noticias/{id:\d+}/url',      [$adminArticles, 'changeSlug']);
$router->post('/panel/noticias/{id:\d+}/eliminar', [$adminArticles, 'destroy']);
$router->post('/panel/noticias/{id:\d+}/video',    [$adminArticles, 'attachVideo']);
$router->post('/panel/noticias/{id:\d+}/video/quitar', [$adminArticles, 'detachVideo']);
$router->post('/panel/noticias/{id:\d+}/live',     [$adminArticles, 'attachLive']);
$router->post('/panel/noticias/{id:\d+}/autoguardar', [$adminArticles, 'autosave']);

// Expediente de la pieza: lo que la convierte en una Noticia Viva.
$router->post('/panel/noticias/{id:\d+}/pulso',              [$adminEditorial, 'guardarPulso']);
$router->post('/panel/noticias/{id:\d+}/fuente',             [$adminEditorial, 'adjuntarFuente']);
$router->post('/panel/noticias/{id:\d+}/fuente/quitar',      [$adminEditorial, 'quitarFuente']);
$router->post('/panel/noticias/{id:\d+}/cronologia',         [$adminEditorial, 'anadirEvento']);
$router->post('/panel/noticias/{id:\d+}/cronologia/quitar',  [$adminEditorial, 'quitarEvento']);
$router->post('/panel/noticias/{id:\d+}/actor',              [$adminEditorial, 'adjuntarActor']);
$router->post('/panel/noticias/{id:\d+}/actor/quitar',       [$adminEditorial, 'quitarActor']);
$router->post('/panel/noticias/{id:\d+}/impacto',            [$adminEditorial, 'anadirImpacto']);
$router->post('/panel/noticias/{id:\d+}/impacto/quitar',     [$adminEditorial, 'quitarImpacto']);
$router->post('/panel/noticias/{id:\d+}/herencia',           [$adminEditorial, 'fijarHerencia']);
$router->post('/panel/noticias/{id:\d+}/relacionada',        [$adminEditorial, 'relacionar']);
$router->post('/panel/noticias/{id:\d+}/relacionada/quitar', [$adminEditorial, 'desrelacionar']);
$router->post('/panel/noticias/{id:\d+}/version/restaurar',  [$adminEditorial, 'restaurarVersion']);

$router->get('/panel/medios',       [$adminMedia, 'index']);
$router->post('/panel/medios',      [$adminMedia, 'store']);

$router->get('/panel/videos',              [$adminVideos, 'index']);
$router->get('/panel/videos/nuevo',        [$adminVideos, 'create']);
$router->post('/panel/videos/nuevo',       [$adminVideos, 'store']);
$router->get('/panel/videos/{id:\d+}',     [$adminVideos, 'edit']);
$router->post('/panel/videos/{id:\d+}',    [$adminVideos, 'update']);

$router->get('/panel/lives',           [$adminLives, 'index']);
$router->get('/panel/lives/nuevo',     [$adminLives, 'create']);
$router->post('/panel/lives/nuevo',    [$adminLives, 'store']);
$router->get('/panel/lives/{id:\d+}',  [$adminLives, 'edit']);
$router->post('/panel/lives/{id:\d+}', [$adminLives, 'update']);

$router->get('/panel/taxonomias',   [$adminTaxonomy, 'index']);
$router->post('/panel/taxonomias',  [$adminTaxonomy, 'store']);

$router->get('/panel/portada',      [$adminHomepage, 'index']);
$router->post('/panel/portada',     [$adminHomepage, 'update']);

$router->get('/panel/moderacion',   [$adminModeration, 'index']);
$router->post('/panel/moderacion/{id:\d+}', [$adminModeration, 'moderate']);

$router->get('/panel/redirecciones',  [$adminRedirects, 'index']);
$router->post('/panel/redirecciones', [$adminRedirects, 'store']);

$router->get('/panel/usuarios',   [$adminUsers, 'index']);
$router->post('/panel/usuarios',  [$adminUsers, 'store']);

$router->get('/panel/auditoria',  [$adminAudit, 'index']);
$router->get('/panel/metricas',   [$adminDashboard, 'metrics']);

// ---------------------------------------------------------------------
// DESPACHO
// ---------------------------------------------------------------------

$path = $request->path;

// Publicacion automatica de piezas programadas. Se intenta una vez por
// minuto como maximo, y el cron real hace lo mismo sin depender de las
// visitas (ver scripts/cron.php).
publicarProgramadasSiToca();

$match = $router->match($request->method, $path);

if ($match !== null) {
    [$handler, $params] = [$match['handler'], $match['params']];
    if (is_array($handler)) {
        [$controller, $method] = $handler;
        $controller->{$method}($request, $params);
    } else {
        $handler($request, $params);
    }
    exit;
}

// Redireccion permanente registrada para una URL antigua.
$redirect = Database::first('SELECT * FROM redirects WHERE from_path = :path LIMIT 1', ['path' => $path]);
if ($redirect !== null) {
    Database::run('UPDATE redirects SET hits = hits + 1 WHERE id = :id', ['id' => (int) $redirect['id']]);
    Response::redirect((string) $redirect['to_path'], (int) $redirect['status_code']);
}

// La ruta existe con otro metodo: no es un 404, es un 405.
if ($router->pathExists($path)) {
    Response::securityHeaders();
    http_response_code(405);
    header('Allow: GET, POST');
    Response::html(View::render('errors/404', [
        'titulo'      => 'Método no permitido · ' . $config['app']['name'],
        'noindex'     => true,
        'sugerencias' => [],
    ]), 405);
    exit;
}

Response::securityHeaders();
Response::html(View::render('errors/404', [
    'titulo'      => 'Página no encontrada · ' . $config['app']['name'],
    'descripcion' => 'La dirección solicitada no existe en La Noticia SIN FILTRO.',
    'noindex'     => true,
    'sugerencias' => Article::published(['limit' => 4]),
]), 404);

/**
 * Publica las piezas programadas cuya hora ya llego, sin castigar cada
 * peticion: como maximo una comprobacion por minuto.
 */
function publicarProgramadasSiToca(): void
{
    $marca = LNSF_ROOT . '/storage/logs/.ultima-publicacion';

    if (is_file($marca) && (time() - (int) @filemtime($marca)) < 60) {
        return;
    }

    @touch($marca);

    try {
        \App\Services\ArticleService::publishDue();
    } catch (\Throwable $e) {
        \App\Support\Logger::warning('No se pudieron publicar las piezas programadas', ['mensaje' => $e->getMessage()]);
    }
}
