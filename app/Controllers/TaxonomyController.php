<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Article;
use App\Models\Taxonomy;
use App\Services\SeoService;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Categorias, etiquetas, temas, autores y tipo editorial. */
final class TaxonomyController
{
    public function __construct(private readonly array $config)
    {
    }

    public function category(Request $request, array $params): void
    {
        $categoria = Taxonomy::categoryBySlug((string) $params['slug']);
        if ($categoria === null) {
            $this->notFound();
            return;
        }

        $this->listado($request, [
            'clave'       => 'category_slug',
            'valor'       => $categoria['slug'],
            'encabezado'  => $categoria['name'],
            'bajada'      => $categoria['question'] ?? $categoria['description'],
            'ruta'        => '/categoria/' . $categoria['slug'],
            'tipoListado' => 'Categoría',
        ]);
    }

    public function tag(Request $request, array $params): void
    {
        $tag = Taxonomy::tagBySlug((string) $params['slug']);
        if ($tag === null) {
            $this->notFound();
            return;
        }

        $this->listado($request, [
            'clave'       => 'tag_slug',
            'valor'       => $tag['slug'],
            'encabezado'  => $tag['name'],
            'bajada'      => null,
            'ruta'        => '/etiqueta/' . $tag['slug'],
            'tipoListado' => 'Etiqueta',
        ]);
    }

    public function topic(Request $request, array $params): void
    {
        $topic = Taxonomy::topicBySlug((string) $params['slug']);
        if ($topic === null) {
            $this->notFound();
            return;
        }

        $this->listado($request, [
            'clave'       => 'topic_slug',
            'valor'       => $topic['slug'],
            'encabezado'  => $topic['name'],
            'bajada'      => $topic['summary'],
            'ruta'        => '/tema/' . $topic['slug'],
            'tipoListado' => 'Tema',
        ]);
    }

    public function type(Request $request, array $params): void
    {
        $tipo = (string) $params['tipo'];
        if (!array_key_exists($tipo, Article::EDITORIAL_TYPES)) {
            $this->notFound();
            return;
        }

        $this->listado($request, [
            'clave'       => 'editorial_type',
            'valor'       => $tipo,
            'encabezado'  => Article::EDITORIAL_TYPES[$tipo],
            'bajada'      => $this->descripcionTipo($tipo),
            'ruta'        => '/tipo/' . $tipo,
            'tipoListado' => 'Tipo editorial',
        ]);
    }

    /** Pagina de autor, obligatoria para la confianza y para Google News. */
    public function author(Request $request, array $params): void
    {
        $autor = Taxonomy::authorBySlug((string) $params['slug']);
        if ($autor === null) {
            $this->notFound();
            return;
        }

        $perPage   = $this->config['pagination']['archive'];
        $page      = Paginator::resolvePage($request->input('pagina'));
        $filtros   = ['author_slug' => $autor['slug'], 'statuses' => Article::ARCHIVED_STATUSES];
        $total     = Article::countPublished($filtros);
        $paginator = new Paginator($total, $perPage, $page, '/autor/' . $autor['slug'], []);

        Response::securityHeaders();
        Response::html(View::render('author', [
            'titulo'       => $autor['name'] . ' · ' . $this->config['app']['name'],
            'descripcion'  => $autor['bio'] ? \App\Support\Str::excerpt((string) $autor['bio'], 200) : 'Piezas firmadas por ' . $autor['name'] . '.',
            'rutaCanonica' => '/autor/' . $autor['slug'],
            'autor'        => $autor,
            'piezas'       => Article::published(array_merge($filtros, ['limit' => $perPage, 'offset' => $paginator->offset()])),
            'total'        => $total,
            'paginador'    => $paginator,
            'jsonLd'       => [SeoService::person($autor, $this->config)],
        ]));
    }

    private function listado(Request $request, array $contexto): void
    {
        $perPage = $this->config['pagination']['archive'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $filtros = [
            $contexto['clave'] => $contexto['valor'],
            'statuses'         => Article::ARCHIVED_STATUSES,
        ];

        $total     = Article::countPublished($filtros);
        $paginator = new Paginator($total, $perPage, $page, $contexto['ruta'], []);

        Response::securityHeaders();
        Response::html(View::render('taxonomy', [
            'titulo'       => $contexto['encabezado'] . ' · ' . $this->config['app']['name'],
            'descripcion'  => $contexto['bajada'] ?: ('Todas las piezas de ' . $contexto['encabezado'] . '.'),
            'rutaCanonica' => $contexto['ruta'],
            'encabezado'   => $contexto['encabezado'],
            'bajada'       => $contexto['bajada'],
            'tipoListado'  => $contexto['tipoListado'],
            'piezas'       => Article::published(array_merge($filtros, ['limit' => $perPage, 'offset' => $paginator->offset()])),
            'total'        => $total,
            'paginador'    => $paginator,
            'categorias'   => Taxonomy::categoriesWithCounts(),
        ]));
    }

    private function descripcionTipo(string $tipo): string
    {
        return match ($tipo) {
            'noticia'      => 'Qué ocurrió, con lo confirmado separado de lo probable.',
            'analisis'     => 'Qué significa. Análisis identificado como tal, nunca mezclado con el hecho.',
            'opinion'      => 'Postura firmada. Opinión, no reporte.',
            'explicador'   => 'Contenido duradero que explica un asunto desde su base.',
            'verificacion' => 'Comprobación de una afirmación concreta, con fuentes abiertas.',
            default        => '',
        };
    }

    private function notFound(): void
    {
        Response::securityHeaders();
        Response::html(View::render('errors/404', [
            'titulo'      => 'Página no encontrada · ' . $this->config['app']['name'],
            'noindex'     => true,
            'sugerencias' => Article::published(['limit' => 4]),
        ]), 404);
    }
}
