<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Article;
use App\Models\Live;
use App\Models\Taxonomy;
use App\Services\SearchService;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Buscador. La consulta se resuelve en el servidor y siempre pagina: no
 * se devuelve el archivo completo en una respuesta.
 */
final class SearchController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        $filtros = [
            'q'         => mb_substr($request->text('q'), 0, 200),
            'desde'     => $this->validDate($request->text('desde')),
            'hasta'     => $this->validDate($request->text('hasta')),
            'anio'      => $request->int('anio'),
            'mes'       => $request->int('mes'),
            'categoria' => $request->text('categoria'),
            'autor'     => $request->text('autor'),
            'tipo'      => array_key_exists($request->text('tipo'), Article::EDITORIAL_TYPES) ? $request->text('tipo') : '',
            'etiqueta'  => $request->text('etiqueta'),
            'tema'      => $request->text('tema'),
            'video'     => in_array($request->text('video'), ['si', 'no'], true) ? $request->text('video') : '',
            'live'      => $request->text('live'),
            'order'     => array_key_exists($request->text('orden'), SearchService::ORDERS) ? $request->text('orden') : 'relevancia',
        ];

        $perPage = $this->config['pagination']['search'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $queryParams = array_filter([
            'q'         => $filtros['q'],
            'desde'     => $filtros['desde'],
            'hasta'     => $filtros['hasta'],
            'categoria' => $filtros['categoria'],
            'autor'     => $filtros['autor'],
            'tipo'      => $filtros['tipo'],
            'etiqueta'  => $filtros['etiqueta'],
            'tema'      => $filtros['tema'],
            'video'     => $filtros['video'],
            'live'      => $filtros['live'],
            'orden'     => $filtros['order'] === 'relevancia' ? '' : $filtros['order'],
        ], static fn ($v): bool => $v !== '' && $v !== null);

        $offset    = ($page - 1) * $perPage;
        $resultado = SearchService::search($filtros, $perPage, $offset);
        $paginator = new Paginator($resultado['total'], $perPage, $page, '/buscar', $queryParams);

        Response::securityHeaders();
        Response::html(View::render('search', [
            'titulo'       => ($filtros['q'] !== '' ? 'Búsqueda: ' . $filtros['q'] : 'Buscador') . ' · ' . $this->config['app']['name'],
            'descripcion'  => 'Busca en todo el archivo permanente, incluidas las transcripciones de video.',
            'rutaCanonica' => '/buscar',
            'noindex'      => $filtros['q'] !== '',
            'filtros'      => $filtros,
            'resultados'   => $resultado['items'],
            'total'        => $resultado['total'],
            'modo'         => $resultado['modo'],
            'paginador'    => $paginator,
            'categorias'   => Taxonomy::categories(),
            'autores'      => Taxonomy::authors(),
            'temas'        => Taxonomy::topics(),
            'etiquetas'    => Taxonomy::tags(),
            'lives'        => Live::past(20),
            'anios'        => Article::archiveYears(),
        ]));
    }

    private function validDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
