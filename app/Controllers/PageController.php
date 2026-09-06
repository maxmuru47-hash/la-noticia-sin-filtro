<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Taxonomy;
use App\Services\SeoService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Paginas de transparencia. El documento maestro las declara
 * obligatorias, y Google News las exige para reconocer un medio.
 */
final class PageController
{
    private const PAGES = [
        'quienes-somos'      => 'Quiénes somos y quién publica',
        'politica-editorial' => 'Política editorial y clasificación de contenidos',
        'metodologia'        => 'Metodología de fuentes y verificación',
        'correcciones'       => 'Política de correcciones y actualización',
        'publicidad'         => 'Publicidad, patrocinios y conflictos de interés',
        'privacidad'         => 'Privacidad, datos y personalización',
        'contacto'           => 'Contacto y derecho a réplica',
        'uso-de-ia'          => 'Uso de inteligencia artificial',
    ];

    public function __construct(private readonly array $config)
    {
    }

    public function show(Request $request, array $params): void
    {
        $pagina = (string) ($params['pagina'] ?? '');

        if (!array_key_exists($pagina, self::PAGES)) {
            Response::securityHeaders();
            Response::html(View::render('errors/404', [
                'titulo'      => 'Página no encontrada · ' . $this->config['app']['name'],
                'noindex'     => true,
                'sugerencias' => [],
            ]), 404);
            return;
        }

        $datos = [];
        if ($pagina === 'correcciones') {
            $datos['correcciones'] = Database::all(
                'SELECT ac.*, a.slug, a.title
                   FROM article_corrections ac
                   JOIN articles a ON a.id = ac.article_id
                  WHERE ac.is_public = 1 AND a.deleted_at IS NULL
                  ORDER BY ac.corrected_at DESC LIMIT 50'
            );
        }
        if ($pagina === 'quienes-somos') {
            $datos['autores'] = Taxonomy::authors();
        }

        Response::securityHeaders();
        Response::html(View::render('pages/' . $pagina, array_merge($datos, [
            'titulo'       => self::PAGES[$pagina] . ' · ' . $this->config['app']['name'],
            'descripcion'  => self::PAGES[$pagina] . ' de ' . $this->config['app']['name'] . '.',
            'rutaCanonica' => '/transparencia/' . $pagina,
            'encabezado'   => self::PAGES[$pagina],
            'paginaActual' => $pagina,
            'paginas'      => self::PAGES,
            'jsonLd'       => [SeoService::breadcrumbs([
                ['nombre' => 'Inicio', 'ruta' => '/'],
                ['nombre' => 'Transparencia', 'ruta' => '/transparencia/politica-editorial'],
                ['nombre' => self::PAGES[$pagina], 'ruta' => '/transparencia/' . $pagina],
            ], $this->config['app']['url'])],
        ])));
    }
}
