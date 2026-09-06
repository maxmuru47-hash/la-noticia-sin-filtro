<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Video;
use App\Services\SeoService;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class VideoController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(Request $request): void
    {
        $tipo    = $request->text('tipo');
        $tipo    = array_key_exists($tipo, Video::TYPES) ? $tipo : null;
        $perPage = $this->config['pagination']['archive'];
        $page    = Paginator::resolvePage($request->input('pagina'));

        $total     = Video::countPublished($tipo);
        $paginator = new Paginator($total, $perPage, $page, '/videos', $tipo === null ? [] : ['tipo' => $tipo]);

        Response::securityHeaders();
        Response::html(View::render('videos', [
            'titulo'       => 'Videos · ' . $this->config['app']['name'],
            'descripcion'  => 'Resúmenes, explicaciones, entrevistas y grabaciones de Lives, con subtítulos y transcripción.',
            'rutaCanonica' => '/videos',
            'videos'       => Video::published($perPage, $paginator->offset(), $tipo),
            'tipoActivo'   => $tipo,
            'total'        => $total,
            'paginador'    => $paginator,
        ]));
    }

    public function show(Request $request, array $params): void
    {
        $video = Video::findBySlug((string) $params['slug']);

        if ($video === null || $video['status'] !== 'publicado') {
            Response::securityHeaders();
            Response::html(View::render('errors/404', [
                'titulo'      => 'Video no encontrado · ' . $this->config['app']['name'],
                'noindex'     => true,
                'sugerencias' => [],
            ]), 404);
            return;
        }

        $id         = (int) $video['id'];
        $transcript = Video::transcript($id);

        Response::securityHeaders();
        Response::html(View::render('video', [
            'titulo'       => $video['title'] . ' · ' . $this->config['app']['name'],
            'descripcion'  => \App\Support\Str::excerpt((string) ($video['description'] ?? $video['title']), 200),
            'rutaCanonica' => '/video/' . $video['slug'],
            'imagenSocial' => $video['poster_path'] ?: $video['thumbnail_path'],
            'tipoOg'       => 'video.other',
            'video'        => $video,
            'capitulos'    => Video::chapters($id),
            'transcripcion' => $transcript,
            'noticias'     => Video::articles($id),
            'jsonLd'       => [SeoService::video($video, $this->config, $transcript)],
        ]));
    }
}
