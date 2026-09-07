<?php
declare(strict_types=1);

/**
 * PRUEBAS DE LA PLATAFORMA
 *
 *   php tests/ejecutar.php
 *
 * Cubre lo que se puede comprobar sin navegador: las reglas de
 * permanencia, la sanitización, los permisos, el buscador, las fechas y
 * los formatos de salida. Las pruebas de interfaz, teclado y rendimiento
 * están en CHECKLIST_DE_LANZAMIENTO.md, porque exigen ojos humanos.
 *
 * Se ejecuta contra la base de datos configurada en .env, y limpia
 * detrás de sí todo lo que crea.
 */

require __DIR__ . '/marco.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Middleware\Auth;
use App\Models\Article;
use App\Models\Poll;
use App\Services\ArticleService;
use App\Services\BlockRenderer;
use App\Services\FeedService;
use App\Services\SearchService;
use App\Services\SeoService;
use App\Support\Database;
use App\Support\Dates;
use App\Support\Html;
use App\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit("Estas pruebas solo se ejecutan desde la línea de comandos.\n");
}

$config = require __DIR__ . '/../config/config.php';
$creadas = [];

// Sesión falsa: los servicios registran auditoría con el usuario actual.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['usuario_id'] = (int) (Database::value('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);

$sufijo = substr(bin2hex(random_bytes(4)), 0, 8);

// =====================================================================
Pruebas::grupo('1 · Slug y dirección permanente');
// =====================================================================

$slugA = ArticleService::generateSlug('Prueba de permanencia ' . $sufijo);
Pruebas::afirmar(
    preg_match('/^[a-z0-9\-]+$/', $slugA) === 1,
    'El slug generado solo tiene minúsculas, números y guiones'
);

Pruebas::iguales(
    'noticia-con-nes-y-acentos',
    Str::slug('Noticia con ñes y acentos'),
    'La ñ y los acentos se transliteran, no se pierden'
);

$idA = ArticleService::create([
    'title'          => 'Prueba de permanencia ' . $sufijo,
    'summary'        => 'Pieza creada por las pruebas automáticas.',
    'editorial_type' => 'noticia',
    'body_blocks'    => json_encode([['tipo' => 'parrafo', 'texto' => 'Cuerpo de prueba con la palabra clave zafiroprueba.']], JSON_UNESCAPED_UNICODE),
]);
$creadas[] = $idA;

$piezaA = Article::findById($idA);
Pruebas::iguales('borrador', $piezaA['status'], 'Una pieza nueva nace como borrador, nunca publicada');
Pruebas::afirmar($piezaA['uuid'] !== null && strlen((string) $piezaA['uuid']) === 36, 'La pieza recibe un identificador inmutable');

$reservado = Database::value('SELECT article_id FROM reserved_slugs WHERE slug = :s', ['s' => $piezaA['slug']]);
Pruebas::iguales($idA, (int) $reservado, 'El slug queda reservado desde el borrador');

// =====================================================================
Pruebas::grupo('2 · Publicar y actualizar sin cambiar la URL');
// =====================================================================

ArticleService::publish($idA);
$piezaA = Article::findById($idA);
$slugOriginal = (string) $piezaA['slug'];

Pruebas::iguales('publicada', $piezaA['status'], 'La pieza queda publicada');
Pruebas::afirmar($piezaA['published_at'] !== null, 'La fecha de publicación se registra');

$publicadaEn = $piezaA['published_at'];

ArticleService::update($idA, ['title' => 'TÍTULO COMPLETAMENTE DISTINTO ' . $sufijo], 'Cambio de título en pruebas');
$piezaA = Article::findById($idA);

Pruebas::iguales($slugOriginal, (string) $piezaA['slug'], 'Cambiar el título NO cambia la URL publicada');
Pruebas::iguales('actualizada', $piezaA['status'], 'Editar una pieza publicada la marca como actualizada');
Pruebas::afirmar($piezaA['updated_content_at'] !== null, 'Se registra la hora de la última actualización');

ArticleService::publish($idA);
$piezaA = Article::findById($idA);
Pruebas::iguales($publicadaEn, $piezaA['published_at'], 'Volver a publicar NO sobrescribe la fecha original');

$revisiones = Article::revisions($idA);
Pruebas::afirmar(count($revisiones) >= 3, 'Cada cambio deja una versión en el historial', 'versiones: ' . count($revisiones));

// =====================================================================
Pruebas::grupo('3 · Archivar no es eliminar');
// =====================================================================

Database::insert('homepage_slots', ['zone' => 'esencial', 'position' => 9, 'article_id' => $idA]);
ArticleService::archive($idA, 'Prueba automática');
$piezaA = Article::findById($idA);

Pruebas::iguales('archivada', $piezaA['status'], 'La pieza queda archivada');
Pruebas::afirmar($piezaA['deleted_at'] === null, 'Archivar NO marca la pieza como eliminada');
Pruebas::iguales(0, (int) Database::value('SELECT COUNT(*) FROM homepage_slots WHERE article_id = :id', ['id' => $idA]),
    'Archivar la retira de la portada');
Pruebas::afirmar(Article::isPubliclyVisible($piezaA), 'Una pieza archivada sigue siendo visible en su URL permanente');

$enIndice = Database::value('SELECT id FROM search_index WHERE entity_type = "articulo" AND entity_id = :id', ['id' => $idA]);
Pruebas::afirmar($enIndice !== null, 'Una pieza archivada SIGUE en el índice del buscador');

$busqueda = SearchService::search(['q' => 'zafiroprueba'], 10, 0);
$encontrada = false;
foreach ($busqueda['items'] as $item) {
    if ((int) $item['article_id'] === $idA) { $encontrada = true; }
}
Pruebas::afirmar($encontrada, 'El buscador encuentra la pieza archivada por el texto de su cuerpo');

$enListados = Article::published(['limit' => 200]);
$apareceEnListado = false;
foreach ($enListados as $item) {
    if ((int) $item['id'] === $idA) { $apareceEnListado = true; }
}
Pruebas::afirmar(!$apareceEnListado, 'Una pieza archivada NO aparece en los listados de portada');

$enArchivo = Article::published(['limit' => 200, 'statuses' => Article::ARCHIVED_STATUSES]);
$apareceEnArchivo = false;
foreach ($enArchivo as $item) {
    if ((int) $item['id'] === $idA) { $apareceEnArchivo = true; }
}
Pruebas::afirmar($apareceEnArchivo, 'Una pieza archivada SÍ aparece en el archivo cronológico');

ArticleService::unarchive($idA);
Pruebas::iguales('actualizada', (string) Article::findById($idA)['status'], 'Se puede devolver una pieza a los listados');

// =====================================================================
Pruebas::grupo('4 · Cambio de URL, redirección 301 y slug quemado');
// =====================================================================

$slugAnterior = (string) Article::findById($idA)['slug'];
$slugNuevo    = ArticleService::changeSlug($idA, 'direccion-nueva-de-prueba-' . $sufijo, 'Prueba automática');

Pruebas::afirmar($slugNuevo !== $slugAnterior, 'La dirección cambió');

$redireccion = Database::first('SELECT * FROM redirects WHERE from_path = :p', ['p' => '/noticia/' . $slugAnterior]);
Pruebas::afirmar($redireccion !== null, 'La dirección anterior quedó con redirección registrada');
Pruebas::iguales(301, (int) ($redireccion['status_code'] ?? 0), 'La redirección es permanente (301)');
Pruebas::iguales('/noticia/' . $slugNuevo, (string) ($redireccion['to_path'] ?? ''), 'La redirección apunta a la dirección nueva');

$quemado = Database::value('SELECT article_id FROM reserved_slugs WHERE slug = :s', ['s' => $slugAnterior]);
Pruebas::afirmar($quemado !== null, 'La dirección anterior queda reservada para siempre');

$idB = ArticleService::create(['title' => 'Otra pieza que intenta robar la URL', 'slug' => $slugAnterior]);
$creadas[] = $idB;
Pruebas::afirmar(
    Article::findById($idB)['slug'] !== $slugAnterior,
    'Otra pieza NO puede reutilizar una dirección quemada'
);

// =====================================================================
Pruebas::grupo('5 · Correcciones y retiro');
// =====================================================================

ArticleService::addCorrection($idA, 'correccion', 'Motivo de la corrección de prueba', 'Detalle del cambio.');
$correcciones = Article::corrections($idA);

Pruebas::afirmar(count($correcciones) >= 1, 'La corrección queda registrada');
Pruebas::afirmar(!empty($correcciones[0]['corrected_at']), 'La corrección lleva fecha');
Pruebas::afirmar(!empty($correcciones[0]['reason']), 'La corrección lleva motivo');

ArticleService::withdraw($idB, 'Motivo público del retiro de prueba.');
$piezaB = Article::findById($idB);
Pruebas::iguales('retirada', (string) $piezaB['status'], 'La pieza queda retirada');
Pruebas::afirmar(!empty($piezaB['withdrawn_reason']), 'El retiro conserva el motivo público');
Pruebas::afirmar(Article::isPubliclyVisible($piezaB) === ($piezaB['published_at'] !== null),
    'La página retirada permanece accesible si llegó a publicarse');

// =====================================================================
Pruebas::grupo('6 · Sanitización de HTML peligroso');
// =====================================================================

$ataques = [
    '<script>alert(1)</script>Texto'                              => 'script',
    '<img src=x onerror="alert(1)">'                              => 'onerror',
    '<a href="javascript:alert(1)">Enlace</a>'                    => 'javascript:',
    '<a href="JaVaScRiPt:alert(1)">Enlace</a>'                    => 'javascript',
    '<iframe src="https://malo.example"></iframe>'                => 'iframe',
    '<div style="position:fixed;top:0">Capa</div>'                => 'style=',
    '<svg onload="alert(1)"></svg>'                               => 'onload',
    '<form action="/robar"><input name="clave"></form>'           => '<form',
    '<object data="malo.swf"></object>'                           => '<object',
    '<a href="vbscript:msgbox(1)">v</a>'                          => 'vbscript',
    '<body onload=alert(1)>'                                      => 'onload',
    '<a href="data:text/html;base64,PHNjcmlwdD4=">d</a>'          => 'data:text/html',
];

foreach ($ataques as $entrada => $prohibido) {
    $limpio = Html::sanitize($entrada);
    Pruebas::noContiene($prohibido, $limpio, 'Se elimina: ' . substr($entrada, 0, 46));
}

$permitido = Html::sanitize('<p>Texto con <strong>negrita</strong>, <em>cursiva</em> y <a href="https://ejemplo.com">un enlace</a>.</p>');
Pruebas::contiene('<strong>', $permitido, 'La negrita legítima se conserva');
Pruebas::contiene('<em>', $permitido, 'La cursiva legítima se conserva');
Pruebas::contiene('href="https://ejemplo.com"', $permitido, 'El enlace https legítimo se conserva');
Pruebas::contiene('rel="noopener noreferrer"', $permitido, 'Los enlaces externos salen con rel de seguridad');

$imagen = Html::sanitize('<img src="/uploads/foto.jpg">');
Pruebas::contiene('loading="lazy"', $imagen, 'Las imágenes salen con carga diferida');
Pruebas::contiene('alt=', $imagen, 'Las imágenes salen siempre con atributo alt');

// El cuerpo por bloques también pasa por el sanitizador.
//
// Se comprueba el ÁRBOL resultante, no la cadena de texto: una carga
// peligrosa escapada aparece como texto literal (&lt;img onerror=...&gt;)
// y es inofensiva, así que buscar la subcadena daría un falso positivo.
$cuerpoPeligroso = BlockRenderer::render([
    ['tipo' => 'parrafo', 'texto' => '<script>alert("bloque")</script>Contenido legítimo'],
    ['tipo' => 'cita', 'texto' => '<img src=x onerror=alert(1)>', 'autor' => '<b>Autor</b>'],
    ['tipo' => 'lista', 'items' => ['<iframe src="https://malo.example"></iframe>', 'Elemento sano']],
    ['tipo' => 'imagen', 'ruta' => 'javascript:alert(1)', 'alt' => 'x'],
    ['tipo' => 'fuente', 'texto' => 'Enlace', 'url' => 'javascript:alert(1)'],
]);

$nodosPeligrosos = elementosPeligrosos($cuerpoPeligroso);
Pruebas::afirmar($nodosPeligrosos === [], 'Ningún bloque produce elementos ni atributos ejecutables',
    implode(', ', $nodosPeligrosos));
Pruebas::contiene('Contenido legítimo', $cuerpoPeligroso, 'El texto legítimo del bloque sobrevive');
Pruebas::contiene('Elemento sano', $cuerpoPeligroso, 'Los elementos sanos de una lista sobreviven');
Pruebas::noContiene('href="javascript', $cuerpoPeligroso, 'Un enlace con esquema javascript pierde su destino');

/**
 * Devuelve los elementos y atributos ejecutables presentes en el árbol
 * HTML resultante. Un array vacío significa que la salida es inerte.
 *
 * @return array<int,string>
 */
function elementosPeligrosos(string $html): array
{
    $doc = new DOMDocument();
    $previo = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previo);

    $prohibidos = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'meta', 'svg'];
    $hallazgos  = [];

    foreach ((new DOMXPath($doc))->query('//*') as $elemento) {
        if (!$elemento instanceof DOMElement) {
            continue;
        }
        if (in_array(strtolower($elemento->nodeName), $prohibidos, true)) {
            $hallazgos[] = 'elemento <' . $elemento->nodeName . '>';
        }
        foreach ($elemento->attributes as $atributo) {
            $nombre = strtolower($atributo->nodeName);
            if (str_starts_with($nombre, 'on') || $nombre === 'style') {
                $hallazgos[] = $elemento->nodeName . '@' . $nombre;
            }
            if (in_array($nombre, ['href', 'src'], true)
                && preg_match('/^\s*(javascript|vbscript|data)\s*:/i', (string) $atributo->nodeValue) === 1) {
                $hallazgos[] = $elemento->nodeName . '@' . $nombre . ' con esquema peligroso';
            }
        }
    }

    return $hallazgos;
}

// =====================================================================
Pruebas::grupo('7 · Permisos por capacidad');
// =====================================================================

$roles = [];
foreach (Database::all('SELECT slug, capabilities FROM roles') as $rol) {
    $roles[$rol['slug']] = json_decode((string) $rol['capabilities'], true) ?: [];
}

Pruebas::afirmar(!in_array(Auth::CAP_ARTICLE_PUBLISH, $roles['autor'] ?? [], true),
    'El rol autor NO puede publicar');
Pruebas::afirmar(!in_array(Auth::CAP_ARTICLE_EDIT_ANY, $roles['autor'] ?? [], true),
    'El rol autor NO puede editar piezas ajenas');
Pruebas::afirmar(in_array(Auth::CAP_ARTICLE_PUBLISH, $roles['editor'] ?? [], true),
    'El rol editor SÍ puede publicar');
Pruebas::afirmar(!in_array(Auth::CAP_ARTICLE_DESTROY, $roles['editor'] ?? [], true),
    'El rol editor NO puede eliminar definitivamente');
Pruebas::afirmar(!in_array(Auth::CAP_ARTICLE_DESTROY, $roles['administrador'] ?? [], true),
    'Ni siquiera el administrador puede eliminar definitivamente');
Pruebas::afirmar(in_array('*', $roles['superadministrador'] ?? [], true),
    'Solo el rol superior tiene todas las capacidades');
Pruebas::afirmar(!in_array(Auth::CAP_USER_MANAGE, $roles['editor'] ?? [], true),
    'El rol editor NO gestiona usuarios');

// =====================================================================
Pruebas::grupo('8 · Eliminación definitiva bajo llave');
// =====================================================================

$idC = ArticleService::create(['title' => 'Pieza para probar la eliminación ' . $sufijo]);
$creadas[] = $idC;
$slugC = (string) Article::findById($idC)['slug'];

$errorConfirmacion = '';
try {
    ArticleService::destroy($idC, 'Motivo', 'confirmacion-equivocada');
} catch (\Throwable $e) {
    $errorConfirmacion = $e->getMessage();
}
Pruebas::contiene('confirmación', $errorConfirmacion, 'Sin la confirmación exacta, la eliminación se rechaza');

$errorMotivo = '';
try {
    ArticleService::destroy($idC, '', $slugC);
} catch (\Throwable $e) {
    $errorMotivo = $e->getMessage();
}
Pruebas::contiene('motivo', $errorMotivo, 'Sin motivo registrado, la eliminación se rechaza');

Pruebas::afirmar(Article::findById($idC) !== null, 'La pieza sigue existiendo tras los intentos fallidos');

// =====================================================================
Pruebas::grupo('9 · Buscador');
// =====================================================================

$resultado = SearchService::search(['q' => 'zafiroprueba'], 10, 0);
Pruebas::afirmar($resultado['total'] >= 1, 'La búsqueda por contenido devuelve resultados');
Pruebas::afirmar(in_array($resultado['modo'], ['fulltext', 'like'], true), 'El buscador usa FULLTEXT o su respaldo LIKE');

$corto = SearchService::search(['q' => 'IA'], 10, 0);
Pruebas::afirmar(is_array($corto['items']), 'Una consulta de dos letras no rompe el buscador');

$paginaUno = SearchService::search([], 2, 0);
$paginaDos = SearchService::search([], 2, 2);
Pruebas::afirmar(count($paginaUno['items']) <= 2, 'El buscador respeta el límite por página');
Pruebas::afirmar(
    $paginaUno['items'] === [] || $paginaDos['items'] === [] ||
    $paginaUno['items'][0]['id'] !== $paginaDos['items'][0]['id'],
    'La paginación devuelve resultados distintos en cada página'
);

$conFiltro = SearchService::search(['tipo' => 'noticia'], 20, 0);
$soloNoticias = true;
foreach ($conFiltro['items'] as $item) {
    if ($item['editorial_type'] !== 'noticia') { $soloNoticias = false; }
}
Pruebas::afirmar($soloNoticias, 'El filtro por tipo editorial se respeta');

$inyeccion = SearchService::search(['q' => '" OR 1=1 -- '], 10, 0);
Pruebas::afirmar(is_array($inyeccion['items']), 'Una consulta con sintaxis de inyección no rompe nada');

// Coincidencia en transcripcion de video, que es la prueba clave: lo dicho
// en camara tiene que poder encontrarse aunque no este escrito en el texto.
//
// La prueba se fabrica su propio video. Antes buscaba una palabra que solo
// existia si alguien habia ejecutado el sembrador de demostracion: cuando esa
// pieza no estaba, la prueba fallaba y acusaba al buscador de algo que no
// habia hecho.
$palabraDicha = 'perlacontinental' . $sufijo;

$idVideo = Database::insert('videos', [
    'uuid'              => App\Support\Str::uuid(),
    'title'             => 'Video de prueba ' . $sufijo,
    'slug'              => 'video-de-prueba-' . $sufijo,
    'video_type'        => 'clip',
    'provider'          => 'propio',
    'transcript_status' => 'revisada',
    'status'            => 'publicado',
    'published_at'      => gmdate('Y-m-d H:i:s'),
]);

Database::insert('video_transcripts', [
    'video_id' => $idVideo,
    'language' => 'es',
    'content'  => 'Esto es lo que se dijo en camara y no esta escrito en el texto: ' . $palabraDicha . '.',
]);

$idConVideo = ArticleService::create([
    'title'       => 'Pieza con video ' . $sufijo,
    'body_blocks' => json_encode([['tipo' => 'parrafo', 'texto' => 'El cuerpo no menciona esa palabra.']], JSON_UNESCAPED_UNICODE),
]);
$creadas[] = $idConVideo;

ArticleService::attachVideo($idConVideo, $idVideo, 'principal');
ArticleService::publish($idConVideo);
SearchService::reindexArticle($idConVideo);

$porTranscripcion = SearchService::search(['q' => $palabraDicha], 10, 0);
Pruebas::afirmar($porTranscripcion['total'] >= 1,
    'El buscador encuentra piezas por lo que se dijo en un video');

Database::run('DELETE FROM video_transcripts WHERE video_id = :id', ['id' => $idVideo]);
Database::run('DELETE FROM article_video_relations WHERE video_id = :id', ['id' => $idVideo]);
Database::run('DELETE FROM videos WHERE id = :id', ['id' => $idVideo]);

// =====================================================================
Pruebas::grupo('10 · Fechas y zona horaria');
// =====================================================================

Pruebas::iguales('America/Caracas', Dates::displayTimezone(), 'La zona editorial es la de Venezuela');

$utc = '2026-09-05 23:30:00';
$local = Dates::local($utc);
Pruebas::iguales('19:30', $local?->format('H:i'), 'UTC se convierte correctamente a hora de Venezuela');
Pruebas::contiene('septiembre', Dates::long($utc), 'Las fechas se muestran en español');
Pruebas::iguales($utc, Dates::toUtc('2026-09-05T19:30'), 'La hora escrita en el panel se guarda en UTC');

// =====================================================================
Pruebas::grupo('11 · SEO y formatos de salida');
// =====================================================================

$pieza = Article::findBySlug($slugNuevo);
$jsonLd = SeoService::article($pieza, $config, ['tags' => [], 'corrections' => []]);

Pruebas::afirmar(isset($jsonLd['@type']), 'El JSON-LD declara un tipo');
Pruebas::afirmar(in_array($jsonLd['@type'], ['NewsArticle', 'Article', 'OpinionNewsArticle', 'AnalysisNewsArticle'], true),
    'El tipo del JSON-LD corresponde al tipo editorial');
Pruebas::afirmar(isset($jsonLd['datePublished']), 'El JSON-LD incluye fecha de publicación');
Pruebas::afirmar(
    str_starts_with((string) ($jsonLd['url'] ?? ''), $config['app']['url']),
    'La URL canónica del JSON-LD es absoluta y usa APP_URL',
    'url: ' . ($jsonLd['url'] ?? 'ninguna')
);

$rss = FeedService::rss($config['app']['url'], $config);
Pruebas::contiene('<rss version="2.0"', $rss, 'El RSS es válido en su cabecera');
Pruebas::afirmar(simplexml_load_string($rss) !== false, 'El RSS se parsea sin errores de XML');

$sitemap = FeedService::sitemapContent($config['app']['url']);
Pruebas::afirmar(simplexml_load_string($sitemap) !== false, 'El sitemap de contenido es XML válido');
Pruebas::contiene('/noticia/', $sitemap, 'El sitemap incluye las piezas');

$noticias = FeedService::sitemapNews($config['app']['url'], $config['app']['name']);
Pruebas::afirmar(simplexml_load_string($noticias) !== false, 'El sitemap de noticias es XML válido');

$videos = FeedService::sitemapVideos($config['app']['url']);
Pruebas::afirmar(simplexml_load_string($videos) !== false, 'El sitemap de videos es XML válido');

$robots = FeedService::robots($config['app']['url'], true);
Pruebas::contiene('Disallow: /panel', $robots, 'robots.txt bloquea el panel');
Pruebas::contiene('Sitemap:', $robots, 'robots.txt anuncia los sitemaps');

// El sitemap NO debe filtrar borradores.
$borradorId = ArticleService::create(['title' => 'Borrador que no debe salir ' . $sufijo]);
$creadas[] = $borradorId;
$slugBorrador = (string) Article::findById($borradorId)['slug'];
Pruebas::noContiene($slugBorrador, FeedService::sitemapContent($config['app']['url']),
    'Un borrador NUNCA aparece en el sitemap');
Pruebas::noContiene($slugBorrador, FeedService::rss($config['app']['url'], $config),
    'Un borrador NUNCA aparece en el RSS');

// =====================================================================
Pruebas::grupo('12 · Pulso: los resultados no se inventan');
// =====================================================================

$pollDemo = Database::first('SELECT id FROM polls WHERE is_demo = 1 LIMIT 1');
if ($pollDemo !== null) {
    $resultados = Poll::results((int) $pollDemo['id']);
    Pruebas::afirmar(isset($resultados['suficiente']), 'El pulso declara si tiene respuestas suficientes');
    Pruebas::afirmar($resultados['suficiente'] === false,
        'Sin votos reales, el pulso NO se presenta como resultado válido');
    Pruebas::iguales(0, $resultados['total_inicial'], 'Sin votos, el total es cero: no se fabrican cifras');

    $cambio = Poll::opinionShift((int) $pollDemo['id']);
    Pruebas::iguales(0.0, $cambio['porcentaje'], 'Sin votos, el cambio de opinión es cero');
}

// =====================================================================
Pruebas::grupo('13 · Programación de publicaciones');
// =====================================================================

$idD = ArticleService::create(['title' => 'Pieza programada de prueba ' . $sufijo]);
$creadas[] = $idD;

ArticleService::schedule($idD, gmdate('Y-m-d H:i:s', time() + 3600));
Pruebas::iguales('programada', (string) Article::findById($idD)['status'], 'La pieza queda programada');
Pruebas::afirmar(Article::findById($idD)['published_at'] === null, 'Una programada todavía no tiene fecha de publicación');

$vistaPublica = Article::published(['limit' => 500]);
$apareceProgramada = false;
foreach ($vistaPublica as $item) {
    if ((int) $item['id'] === $idD) { $apareceProgramada = true; }
}
Pruebas::afirmar(!$apareceProgramada, 'Una pieza programada NO es visible antes de su hora');

ArticleService::schedule($idD, gmdate('Y-m-d H:i:s', time() - 60));
$publicadas = ArticleService::publishDue();
Pruebas::afirmar($publicadas >= 1, 'El cron publica las piezas cuya hora ya llegó');
Pruebas::iguales('publicada', (string) Article::findById($idD)['status'], 'La pieza programada quedó publicada');

// =====================================================================
Pruebas::grupo('14 · Consultas preparadas en todo el código');
// =====================================================================

/*
 * Regla del proyecto: ninguna consulta concatena datos del usuario.
 *
 * Lo único que se permite concatenar dentro de una cadena SQL son:
 *   · fragmentos construidos por el propio código a partir de listas
 *     blancas ($where, $select, $order, $conditions, $placeholders...),
 *   · y valores numéricos con cast explícito a (int), que es lo que
 *     exige MySQL para LIMIT y OFFSET, donde no admite marcadores.
 *
 * Cualquier otra interpolación en una cadena SQL es un fallo.
 */

// Fragmentos que el propio código construye a partir de listas blancas,
// nunca a partir de datos del visitante.
$permitidos = [
    'where', 'select', 'order', 'conditions', 'placeholders', 'assignments',
    'table', 'columns', 'campos', 'sql', 'relevance',
];

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../app', RecursiveDirectoryIterator::SKIP_DOTS)
);

$sospechosos = [];

foreach ($archivos as $archivo) {
    if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
        continue;
    }

    $codigo = (string) file_get_contents($archivo->getPathname());

    // Se inspecciona SOLO el primer argumento de cada llamada a la capa
    // de datos: ahí es donde vive el SQL, y es lo único que importa.
    preg_match_all(
        '/Database::(?:run|all|first|value)\s*\(\s*(.+?)(?:,\s*\[|\)\s*(?:;|as\s|\)))/s',
        $codigo,
        $llamadas,
        PREG_SET_ORDER
    );

    foreach ($llamadas as $llamada) {
        $sql = $llamada[1];

        // Una cadena PHP entre comillas dobles con interpolación queda
        // prohibida sin excepción. Las comillas dobles que aparecen DENTRO
        // del SQL (por ejemplo IN ("publicada")) son de MySQL, no de PHP,
        // así que solo se mira si el argumento ARRANCA con comilla doble.
        if (preg_match('/^\s*"/', $sql) === 1 && preg_match('/\$\w/', $sql) === 1) {
            $sospechosos[] = $archivo->getFilename() . ' → interpolación en comillas dobles';
            continue;
        }

        preg_match_all('/\.\s*(\(int\)\s*)?\$(\w+)/', $sql, $partes, PREG_SET_ORDER);

        foreach ($partes as $parte) {
            $tieneCast = trim($parte[1] ?? '') !== '';
            $variable  = strtolower($parte[2]);

            // (int) es el único cast admitido, y solo porque MySQL no
            // acepta marcadores en LIMIT ni en OFFSET.
            if ($tieneCast || in_array($variable, $permitidos, true)) {
                continue;
            }

            $sospechosos[] = $archivo->getFilename() . ' → $' . $parte[2];
        }
    }
}

Pruebas::afirmar(
    $sospechosos === [],
    'Ninguna consulta interpola datos sin preparar ni castear',
    implode(' | ', array_slice($sospechosos, 0, 5))
);

// Y la comprobación complementaria: PDO corre sin emulación, que es lo
// que hace que un marcador con nombre sea un marcador de verdad.
$emula = Database::pdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
Pruebas::afirmar($emula === false || $emula === 0,
    'PDO tiene la emulación de preparadas desactivada');

// =====================================================================
Pruebas::grupo('15 · Expediente: el Pulso no se puede falsear');
// =====================================================================

$idPulso = ArticleService::create(['title' => 'Pieza con pulso ' . $sufijo]);
$creadas[] = $idPulso;

$pollId = \App\Services\EditorialService::guardarPulso(
    $idPulso,
    '¿Esto se puede responder desde varios lados?',
    ['Sí', 'No', 'Depende'],
    null,
    true
);

Pruebas::afirmar($pollId > 0, 'Se puede crear un pulso desde el servicio');
Pruebas::iguales(3, count(Poll::options($pollId)), 'El pulso guarda sus tres opciones');

$errorPocas = '';
try {
    \App\Services\EditorialService::guardarPulso($idPulso, 'Con una sola opción', ['Única'], null, true);
} catch (\Throwable $e) {
    $errorPocas = $e->getMessage();
}
Pruebas::contiene('dos opciones', $errorPocas, 'Un pulso con una sola opción se rechaza');

$errorMuchas = '';
try {
    \App\Services\EditorialService::guardarPulso($idPulso, 'Demasiadas', ['a','b','c','d','e','f','g'], null, true);
} catch (\Throwable $e) {
    $errorMuchas = $e->getMessage();
}
Pruebas::contiene('seis', $errorMuchas, 'Un pulso con más de seis opciones se rechaza');

// La regla que de verdad importa: una opción votada no se puede borrar.
$opciones = Poll::options($pollId);
$conVoto  = (int) $opciones[2]['id'];

Database::insert('poll_responses', [
    'poll_id'       => $pollId,
    'option_id'     => $conVoto,
    'stage'         => 'inicial',
    'voter_hash'    => str_repeat('c', 64),
    'session_token' => str_repeat('d', 32),
]);

$errorVotada = '';
try {
    \App\Services\EditorialService::guardarPulso($idPulso, '¿Esto se puede responder desde varios lados?', ['Sí', 'No'], null, true);
} catch (\Throwable $e) {
    $errorVotada = $e->getMessage();
}

Pruebas::contiene('voto', $errorVotada, 'No se puede quitar una opción que ya tiene votos');
Pruebas::iguales(3, count(Poll::options($pollId)), 'La transacción se revierte entera: siguen las tres opciones');
Pruebas::iguales(1, (int) Database::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = :p', ['p' => $pollId]),
    'El voto registrado sobrevive al intento');

// Corregir una errata sí se permite: no cambia lo que votó nadie.
\App\Services\EditorialService::guardarPulso($idPulso, '¿Se puede responder desde varios lados?', ['Sí', 'No', 'Depende del caso'], null, true);
$corregidas = Poll::options($pollId);
Pruebas::iguales('Depende del caso', (string) $corregidas[2]['label'], 'Sí se puede corregir el texto de una opción votada');
Pruebas::iguales(1, (int) Database::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = :p', ['p' => $pollId]),
    'Corregir el texto no toca los votos');

// Y no existe ninguna vía desde el panel para escribir resultados.
$escribenVotos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app/Controllers', RecursiveDirectoryIterator::SKIP_DOTS)) as $archivo) {
    if ($archivo->isFile() && $archivo->getExtension() === 'php') {
        $codigo = (string) file_get_contents($archivo->getPathname());
        if (preg_match('/INSERT INTO poll_responses|UPDATE\s+poll_responses/i', $codigo) === 1
            && !str_contains($archivo->getPathname(), 'ApiController')) {
            $escribenVotos[] = $archivo->getFilename();
        }
    }
}
Pruebas::afirmar($escribenVotos === [],
    'Ningún controlador del panel escribe en poll_responses', implode(', ', $escribenVotos));

// =====================================================================
Pruebas::grupo('16 · Expediente: restaurar una versión no toca la URL');
// =====================================================================

$idRest = ArticleService::create([
    'title'   => 'Pieza para restaurar ' . $sufijo,
    'summary' => 'Resumen original.',
]);
$creadas[] = $idRest;

ArticleService::publish($idRest);
$antesDeTodo   = Article::findById($idRest);
$slugIntocable = (string) $antesDeTodo['slug'];
$fechaOriginal = $antesDeTodo['published_at'];

ArticleService::update($idRest, ['title' => 'Título cambiado ' . $sufijo, 'summary' => 'Resumen cambiado.'], 'Cambio');

$versiones = Article::revisions($idRest);
$primera   = (int) end($versiones)['revision_no'];

\App\Services\EditorialService::restaurarRevision($idRest, $primera);
$restaurada = Article::findById($idRest);

Pruebas::iguales($slugIntocable, (string) $restaurada['slug'], 'Restaurar NO cambia la dirección');
Pruebas::iguales($fechaOriginal, $restaurada['published_at'], 'Restaurar NO cambia la fecha de publicación');
Pruebas::afirmar($restaurada['deleted_at'] === null, 'Restaurar no revive ni borra nada');
Pruebas::afirmar(count(Article::revisions($idRest)) > count($versiones),
    'Restaurar deja su propia versión en el historial');

$errorVersion = '';
try {
    \App\Services\EditorialService::restaurarRevision($idRest, 99999);
} catch (\Throwable $e) {
    $errorVersion = $e->getMessage();
}
Pruebas::contiene('no existe', $errorVersion, 'Restaurar una versión inexistente se rechaza');

// =====================================================================
Pruebas::grupo('17 · Seguridad de la cuenta');
// =====================================================================

// La sal del Pulso no puede quedar vacía: sin ella la huella es adivinable.
Pruebas::afirmar(strlen((string) $config['app']['key']) >= 32,
    'APP_KEY tiene al menos 32 caracteres');

$arranque = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php');
Pruebas::contiene("strlen((string) \$config['app']['key']) < 32", $arranque,
    'El arranque se niega a funcionar sin APP_KEY');

$auth = (string) file_get_contents(__DIR__ . '/../app/Middleware/Auth.php');
Pruebas::contiene('must_change_password', $auth,
    'La sesión comprueba si la contraseña es provisional');
Pruebas::contiene('MINUTOS_INACTIVIDAD', $auth,
    'La sesión se cierra sola por inactividad');

$perfil = (string) file_get_contents(__DIR__ . '/../app/Controllers/Admin/PerfilController.php');
Pruebas::contiene('password_verify($actual', $perfil,
    'Cambiar la contraseña exige la contraseña actual, aunque la sesión esté abierta');
Pruebas::contiene('session_regenerate_id(true)', $perfil,
    'Cambiar la contraseña invalida cualquier sesión robada');
Pruebas::contiene('PASSWORD_ARGON2ID', $perfil,
    'La contraseña nueva se guarda con Argon2id');

// Nadie puede crear una cuenta con más permisos que la suya.
$usuarios = (string) file_get_contents(__DIR__ . '/../app/Controllers/Admin/UserController.php');
Pruebas::contiene('más permisos que la tuya', $usuarios,
    'No se puede crear una cuenta por encima del propio rol');

// Y ninguna contraseña viaja en el repositorio.
$fugas = [];
foreach (['app', 'config', 'public', 'scripts', 'database'] as $carpeta) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $carpeta, RecursiveDirectoryIterator::SKIP_DOTS)) as $archivo) {
        if (!$archivo->isFile() || !in_array($archivo->getExtension(), ['php', 'sql', 'sh'], true)) {
            continue;
        }
        $codigo = (string) file_get_contents($archivo->getPathname());
        if (preg_match('/(DB_PASSWORD|password)\s*=\s*[\x27"][^\x27"$\s]{6,}[\x27"]/i', $codigo, $m) === 1
            && !str_contains($m[0], 'desarrollo_local')
            && !str_contains(strtolower($m[0]), 'password_hash')) {
            $fugas[] = $archivo->getFilename() . ': ' . substr($m[0], 0, 40);
        }
    }
}
Pruebas::afirmar($fugas === [], 'Ninguna contraseña embebida en el código', implode(' | ', $fugas));


// =====================================================================
Pruebas::grupo('18 · Las tarjetas nunca quedan sin enlace de sección');
// =====================================================================

// Una tarjeta muestra el nombre de la sección y lo enlaza. Si una consulta
// trae el nombre pero olvida la dirección, la plantilla intentaba construir
// un enlace vacío. Cualquier consulta que pida uno debe pedir el otro.
$fuentes = [];
foreach (['app/Models', 'app/Services'] as $carpeta) {
    foreach (glob(dirname(__DIR__) . '/' . $carpeta . '/*.php') as $archivo) {
        $fuentes[$archivo] = (string) file_get_contents($archivo);
    }
}

$incompletas = [];
foreach ($fuentes as $archivo => $codigo) {
    // Cada consulta se mira entera, desde SELECT hasta el cierre de la cadena.
    if (preg_match_all("/'SELECT.*?'/s", $codigo, $coincidencias) === false) {
        continue;
    }
    foreach ($coincidencias[0] as $consulta) {
        $tieneNombre = str_contains($consulta, 'AS category_name');
        $tieneRuta   = str_contains($consulta, 'AS category_slug');
        if ($tieneNombre && !$tieneRuta) {
            $incompletas[] = basename($archivo);
        }
    }
}

Pruebas::afirmar($incompletas === [],
    'Toda consulta que pide el nombre de la sección pide también su dirección'
    . ($incompletas === [] ? '' : ' (falta en: ' . implode(', ', array_unique($incompletas)) . ')'));

// Y aunque faltara, la plantilla no debe romperse: sin dirección, texto plano.
$plantilla = (string) file_get_contents(dirname(__DIR__) . '/app/Views/partials/tarjeta.php');
Pruebas::afirmar(str_contains($plantilla, 'empty($pieza[\'category_slug\'])'),
    'La tarjeta comprueba la dirección antes de construir el enlace');

// =====================================================================
Pruebas::grupo('19 · El JSON de buscadores no puede romper la página');
// =====================================================================

// Ese JSON viaja dentro de un <script>. Un titular que contenga la cadena de
// cierre de esa etiqueta cerraría el bloque, y lo que viniera detrás sería
// HTML vivo en todas las páginas donde aparezca ese titular.
$tituloTrampa = 'Fuga </script><img src=x onerror=alert(1)> fin';
$json = App\Services\SeoService::jsonLd(['headline' => $tituloTrampa]);

Pruebas::afirmar(!str_contains($json, '</script'),
    'La etiqueta de cierre no sale nunca literal');
Pruebas::afirmar(!str_contains($json, '<') && !str_contains($json, '>'),
    'Ningún signo de menor o mayor sale sin escapar');

// Escapar no puede costar exactitud: lo que lee un buscador debe ser el
// titular tal cual se escribió.
$vuelta = json_decode($json, true);
Pruebas::afirmar(($vuelta['headline'] ?? null) === $tituloTrampa,
    'El buscador sigue leyendo el titular exacto');

// =====================================================================
Pruebas::grupo('20 · El asistente no inventa');
// =====================================================================

use App\Services\AssistantService;

// Lo primero que tiene que hacer bien un asistente de un medio es callarse
// cuando no sabe. Rellenar el hueco con algo que suene bien es desinformar
// con la cara de la casa.
$nada = AssistantService::responder('unicornios en la superficie de marte');
Pruebas::afirmar($nada['piezas'] === [],
    'Sin nada publicado, no ofrece ninguna pieza');
Pruebas::contiene('No encuentro nada publicado', $nada['respuesta'],
    'Sin nada publicado, lo dice en vez de improvisar');

// Las respuestas fijas no dependen de tildes ni de signos: quien pregunta
// no deberia tener que acertar con la ortografia.
foreach (['¿Qué es el Pulso?', 'que es el pulso', 'PULSO'] as $forma) {
    $r = AssistantService::responder($forma);
    Pruebas::contiene('Pulso', $r['respuesta'], 'Responde sobre el Pulso escrito «' . $forma . '»');
}

// Nunca devuelve lo que escribio la persona: si lo repitiera, seria una via
// para colar codigo en la pagina de quien pregunta.
$veneno = AssistantService::responder('<script>alert(1)</script> <img src=x onerror=alert(2)>');
Pruebas::afirmar(!str_contains($veneno['respuesta'], '<script') && !str_contains($veneno['respuesta'], 'onerror'),
    'La respuesta nunca repite lo que escribió la persona');

// Una pregunta larguisima se recorta antes de llegar a la base.
$larga = AssistantService::responder(str_repeat('electricidad ', 200));
Pruebas::afirmar(is_array($larga['piezas']),
    'Una pregunta desmedida se atiende sin romperse');

// El limite por rafaga deja conversar y frena el machaque.
$_SESSION = [];
$bloqueado = false;
for ($i = 0; $i < 8; $i++) {
    $bloqueado = $bloqueado || App\Middleware\RateLimit::burst('prueba_asistente', 8, 30);
}
Pruebas::afirmar($bloqueado === false, 'Ocho preguntas seguidas pasan sin estorbo');
Pruebas::afirmar(App\Middleware\RateLimit::burst('prueba_asistente', 8, 30) === true,
    'La novena se frena');

// =====================================================================
Pruebas::grupo('21 · La portada generada no cambia sola');
// =====================================================================

use App\Support\Portada;

// Si la composicion cambiara en cada visita, el sitio pareceria inestable
// y quien lo lee dudaria tambien de lo demas.
$slug = 'aunque-cobres-en-dolares-este-ano-perdiste';
$primera = Portada::variante($slug);
for ($i = 0; $i < 20; $i++) {
    if (Portada::variante($slug) !== $primera) {
        $primera = -1;
        break;
    }
}
Pruebas::afirmar($primera > 0, 'La misma pieza recibe siempre la misma portada');

// Y el reparto entre composiciones tiene que ser parejo, o la portada se
// veria repetitiva. Se mide sobre una muestra grande a proposito: con seis
// nombres sueltos, que caigan tres en la misma es simple azar, y una prueba
// que falla por azar no vale para nada.
$reparto = array_fill(1, Portada::VARIANTES, 0);
for ($i = 0; $i < 600; $i++) {
    $reparto[Portada::variante('pieza-de-prueba-numero-' . $i . '-con-titular-largo')]++;
}
$menor = min($reparto);
$mayor = max($reparto);

Pruebas::afirmar($menor > 0, 'Ninguna composición se queda sin usar');
Pruebas::afirmar($mayor <= $menor * 2,
    'El reparto entre composiciones es parejo (de ' . $menor . ' a ' . $mayor . ' sobre 600)');

// Toda variante tiene que existir en la hoja de estilos.
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/componentes.css');
$faltan = [];
for ($v = 1; $v <= Portada::VARIANTES; $v++) {
    if (!str_contains($css, '.portada--' . $v . '::before')) {
        $faltan[] = $v;
    }
}
Pruebas::afirmar($faltan === [],
    'Cada composicion posible esta definida en el diseño'
    . ($faltan === [] ? '' : ' (faltan: ' . implode(', ', $faltan) . ')'));

// Una seccion larga y una corta no pueden ir al mismo cuerpo de letra.
Pruebas::iguales('g', Portada::escala('VENEZUELA'),      'Una sección corta va en cuerpo grande');
Pruebas::iguales('m', Portada::escala('EMPRENDIMIENTO'), 'Una sección media baja de cuerpo');
Pruebas::iguales('p', Portada::escala('SOCIEDAD Y FAMILIA'), 'Una sección larga baja aún más');

foreach (['g', 'm', 'p'] as $escala) {
    Pruebas::afirmar(str_contains($css, '.portada__rotulo--' . $escala . ' {'),
        'El diseño define el cuerpo «' . $escala . '»');
}

// =====================================================================
// LIMPIEZA
// =====================================================================

Database::run('SET FOREIGN_KEY_CHECKS = 0');
foreach ($creadas as $id) {
    Database::run('DELETE FROM poll_responses WHERE poll_id IN (SELECT id FROM polls WHERE article_id = :id)', ['id' => $id]);
    Database::run('DELETE FROM polls WHERE article_id = :id', ['id' => $id]);
    Database::run('DELETE FROM search_index WHERE entity_type = "articulo" AND entity_id = :id', ['id' => $id]);
    Database::run('DELETE FROM articles WHERE id = :id', ['id' => $id]);
    Database::run('DELETE FROM reserved_slugs WHERE article_id = :id', ['id' => $id]);
    Database::run('DELETE FROM redirects WHERE to_path LIKE :p', ['p' => '%' . $sufijo . '%']);
}
Database::run('DELETE FROM redirects WHERE reason = "Prueba automática"');
Database::run('DELETE FROM audit_logs WHERE summary LIKE :p', ['p' => '%' . $sufijo . '%']);
Database::run('SET FOREIGN_KEY_CHECKS = 1');

echo "\n  Limpieza: se eliminaron " . count($creadas) . " piezas de prueba.\n";

exit(Pruebas::resumen());
