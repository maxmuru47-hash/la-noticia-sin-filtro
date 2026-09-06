<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Article;
use App\Models\Taxonomy;
use App\Support\Dates;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Archivo cronologico navegable por ano, mes y dia.
 *
 * INCLUYE las piezas archivadas: ese es exactamente el punto de tener un
 * archivo. Una noticia archivada debe poder encontrarse anos despues.
 */
final class ArchiveController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request, array $params = []): void
    {
        $year  = isset($params['anio']) ? (int) $params['anio'] : null;
        $month = isset($params['mes']) ? (int) $params['mes'] : null;
        $day   = isset($params['dia']) ? (int) $params['dia'] : null;

        if ($year !== null && ($year < 2000 || $year > (int) gmdate('Y') + 1)) {
            $year = null;
        }
        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }
        if ($day !== null && ($day < 1 || $day > 31)) {
            $day = null;
        }

        $perPage = $this->config['pagination']['archive'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $filtros = [
            'statuses' => Article::ARCHIVED_STATUSES,
            'year'     => $year,
            'month'    => $month,
            'day'      => $day,
        ];

        $total     = Article::countPublished($filtros);
        $paginator = new Paginator($total, $perPage, $page, $this->rutaActual($year, $month, $day), []);
        $piezas    = Article::published(array_merge($filtros, [
            'limit'  => $perPage,
            'offset' => $paginator->offset(),
        ]));

        $titulo = 'Archivo';
        if ($year !== null) {
            $titulo = 'Archivo de ' . ($day !== null ? $day . ' de ' : '')
                . ($month !== null ? Dates::monthName($month) . ' de ' : '') . $year;
        }

        Response::securityHeaders();
        Response::html(View::render('archive', [
            'titulo'       => $titulo . ' · ' . $this->config['app']['name'],
            'descripcion'  => 'Archivo permanente de La Noticia SIN FILTRO. Ninguna pieza publicada se elimina.',
            'rutaCanonica' => $this->rutaActual($year, $month, $day),
            'encabezado'   => $titulo,
            'anio'         => $year,
            'mes'          => $month,
            'dia'          => $day,
            'piezas'       => $piezas,
            'paginador'    => $paginator,
            'anios'        => Article::archiveYears(),
            'meses'        => $year !== null ? Article::archiveMonths($year) : [],
            'categorias'   => Taxonomy::categoriesWithCounts(),
        ]));
    }

    private function rutaActual(?int $year, ?int $month, ?int $day): string
    {
        $ruta = '/archivo';
        if ($year !== null) {
            $ruta .= '/' . $year;
            if ($month !== null) {
                $ruta .= '/' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
                if ($day !== null) {
                    $ruta .= '/' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                }
            }
        }
        return $ruta;
    }
}
